<?php

//------------------------------------------------------------------------------
// File:         WriteCanonRaw.pl
//
// Description:  Write Canon RAW (CRW and CR2) meta information
//
// Revisions:    01/25/2005 - P. Harvey Created
//               09/16/2010 - PH Added ability to write XMP in CRW images
//
// PHP port (package Image::ExifTool::CanonRaw), mixed into class Image\ExifTool\CanonRaw. The
// file-scope lexicals %crwMap, %mapRawTag and %mapRotation are the constants CRW_MAP,
// MAP_RAW_TAG and MAP_ROTATION.
//------------------------------------------------------------------------------

namespace Image\ExifTool\CanonRaw;

use Image\ExifTool\CanonVRD;
use Image\ExifTool\Exif;
use Image\ExifTool\ExifTool;
use Image\ExifTool\Fixup;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars;

trait WriteCanonRaw
{
    // map for adding directories to CRW
    private const array CRW_MAP = [
        'XMP'      => 'CanonVRD',
        'CanonVRD' => 'Trailer',
    ];

    // mappings to from RAW tagID to MakerNotes tagID
    // (Note: upper two bits of RawTagID are zero)
    private const array MAP_RAW_TAG = [
      // RawTagID => Canon TagID
        0x080b => 0x07, // CanonFirmwareVersion
        0x0810 => 0x09, // OwnerName
        0x0815 => 0x06, // CanonImageType
        0x1028 => 0x03, // (unknown if no tag name specified)
        0x1029 => 0x02, // FocalLength
        0x102a => 0x04, // CanonShotInfo
        0x102d => 0x01, // CanonCameraSettings
        0x1033 => 0x0f, // CanonCustomFunctions (only verified for 10D)
        0x1038 => 0x12, // CanonAFInfo
        0x1039 => 0x13,
        0x1093 => 0x93,
        0x10a8 => 0xa8,
        0x10a9 => 0xa9, // WhiteBalanceTable
        0x10aa => 0xaa,
        0x10ae => 0xae, // ColorTemperature
        0x10b4 => 0xb4, // ColorSpace
        0x10b5 => 0xb5,
        0x10c0 => 0xc0,
        0x10c1 => 0xc1,
        0x180b => 0x0c, // SerialNumber
        0x1817 => 0x08, // FileNumber
        0x1834 => 0x10,
        0x183b => 0x15,
    ];
    // translation from Rotation to Orientation values
    private const array MAP_ROTATION = [
        0 => 1,
        90 => 6,
        180 => 3,
        270 => 8,
    ];


    //------------------------------------------------------------------------------
    // Initialize buffers for building MakerNotes from RAW data
    // Inputs: 0) ExifTool object reference
    public static function InitMakerNotes(ExifTool $et)
    {
        $et->MAKER_NOTE_INFO = [
            'Entries' => [],            // directory entries keyed by tagID
            'ValBuff' => "\0\0\0\0",    // value data buffer (start with zero nextIFD pointer)
            'FixupTags' => [],          // flags for tags with data in value buffer
        ];
    }

    //------------------------------------------------------------------------------
    // Build maker notes from CanonRaw information
    // Inputs: 0) ExifTool object reference, 1) raw tag ID, 2) reference to tagInfo
    //         3) reference to value, 4) format name, 5) count
    // Notes: This will build the directory in the order the tags are found in the CRW
    // file, which isn't sequential (but Canon's version isn't sequential either...)
    public static function BuildMakerNotes(ExifTool $et, $rawTag, $tagInfo, $valuePt, $formName, $count)
    {
        $tagID = self::MAP_RAW_TAG[$rawTag] ?? null;
        if (!$tagID) return;
        if (!$formName) {
            ExifTool::PerlWarn(Perl::sprintf("No format for tag 0x%x!\n", $rawTag));
            return;
        }
        // special case: ignore user comment because it gets saved in EXIF
        // (and has the same raw tagID as CanonFileDescription)
        if ($tagInfo and $tagInfo->Name === 'UserComment') return;
        $format = Vars\Exif::$formatNumber[$formName];
        $fsiz = Vars\Exif::$formatSize[$format];
        $valuePt = Perl::str($valuePt);
        $size = strlen($valuePt);
        if ($count and $size != $count * $fsiz) {
            if ($size < $count * $fsiz) {
                ExifTool::PerlWarn(Perl::sprintf("Value too short for raw tag 0x%x\n", $rawTag));
                return;
            }
            // shorten value appropriately
            $size = $count * $fsiz;
            $value = substr($valuePt, 0, $size);
        } else {
            $count = $size / $fsiz;
            $value = $valuePt;
        }
        if ($size > 4) {
            $len = strlen($et->MAKER_NOTE_INFO['ValBuff']);
            $offsetVal = ExifTool::Set32u($len);
            $et->MAKER_NOTE_INFO['ValBuff'] .= $value;
            // pad to an even number of bytes
            if ($size & 0x01) $et->MAKER_NOTE_INFO['ValBuff'] .= "\0";
            // set flag indicating that this tag needs a fixup
            $et->MAKER_NOTE_INFO['FixupTags'][$tagID] = 1;
        } else {
            $offsetVal = $value;
            if ($size < 4) $offsetVal .= str_repeat("\0", 4 - $size);
        }
        $et->MAKER_NOTE_INFO['Entries'][$tagID] = ExifTool::Set16u($tagID) . ExifTool::Set16u($format) .
                                                  ExifTool::Set32u($count) . $offsetVal;
    }

    //------------------------------------------------------------------------------
    // Finish building and save MakerNotes
    // Inputs: 0) ExifTool object reference
    public static function SaveMakerNotes(ExifTool $et)
    {
        // save maker notes
        $makerInfo = $et->MAKER_NOTE_INFO;
        $et->MAKER_NOTE_INFO = null;
        $dirEntries = $makerInfo['Entries'];
        $numEntries = count($dirEntries);
        $fixup = new Fixup();
        if (!$numEntries) return;
        // build the MakerNotes directory
        $makerNotes = ExifTool::Set16u($numEntries);
        // write the entries in proper tag order (even though Canon doesn't do this...)
        $tagIDs = array_keys($dirEntries);
        sort($tagIDs);
        foreach ($tagIDs as $tagID) {
            $makerNotes .= $dirEntries[$tagID];
            if (!($makerInfo['FixupTags'][$tagID] ?? null)) continue;
            // add fixup for this pointer
            $fixup->AddFixup(strlen($makerNotes) - 4);
        }
        // save position of maker notes for pointer fixups
        $fixup->Shift += strlen($makerNotes);
        $et->MAKER_NOTE_BYTE_ORDER = ExifTool::GetByteOrder();
        // add value data
        $makerNotes .= $makerInfo['ValBuff'];
        // get MakerNotes tag info
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
        $tagInfo = $et->GetTagInfo($tagTablePtr, 0x927c, $makerNotes);
        // save the MakerNotes
        $key = $et->FoundTag($tagInfo, $makerNotes);
        $et->TAG_EXTRA[$key]['Fixup'] = $fixup;
        // also generate Orientation tag since Rotation isn't transferred from RAW info
        $rotation = $et->GetValue('Rotation', 'ValueConv');
        if ($rotation !== null and isset(self::MAP_ROTATION[Perl::str($rotation)])) {
            $tagInfo = $et->GetTagInfo($tagTablePtr, 0x112);
            $et->FoundTag($tagInfo, self::MAP_ROTATION[Perl::str($rotation)]);
        }
    }

    //------------------------------------------------------------------------------
    // Check CanonRaw information
    // Inputs: 0) ExifTool object reference, 1) tagInfo hash reference,
    //         2) raw value reference
    // Returns: error string or undef (and may change value) on success
    public static function CheckCanonRaw(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $tagName = $tagInfo->Name;
        if ($tagName === 'JpgFromRaw' or $tagName === 'ThumbnailImage') {
            if (!(str_starts_with(Perl::str($valPtr), "\xff\xd8") or $et->Options('IgnoreMinorErrors'))) {
                return '[Minor] Not a valid image';
            }
        } else {
            $format = $tagInfo->Format;
            $count = $tagInfo->Count;
            if (!$format) {
                $tagType = (Perl::int($tagInfo->TagID) >> 8) & 0x38;
                $format = Vars\CanonRaw::$crwTagFormat[$tagType] ?? null;
            }
            if ($format) return ExifTool::CheckValue($valPtr, $format, $count);
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Write CR2 file
    // Inputs: 0) ExifTool ref, 1) dirInfo reference (must have read first 16 bytes)
    //         2) tag table reference
    // Returns: true on success
    public static function WriteCR2(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt === null) return 0;
        $outfile = $dirInfo['OutFile'] ?? null;
        if (!$outfile) return 0;
        if (!($dirInfo['RAF'] ?? null)) return 0;

        // check CR2 signature
        if (!preg_match('/^.{8}CR\x02\0/s', $dataPt)) {
            $msg = $minor = null;
            if (preg_match('/^.{8}CR/s', $dataPt)) {
                $msg = 'Unsupported Canon RAW file. May cause problems if rewritten';
                $minor = 1;
            } elseif (preg_match('/^.{8}\xba\xb0\xac\xbb/s', $dataPt)) {
                $msg = 'Can not currently write Canon 1D RAW images';
            } else {
                $msg = 'Unrecognized Canon RAW file';
            }
            if ($et->Error($msg, $minor)) return 0;
        }

        // CR2 has a 16-byte header
        $dirInfo['NewDataPos'] = 16;
        $newData = $et->WriteDirectory($dirInfo, $tagTablePtr);
        if ($newData === null) return 0;
        if (!($dirInfo['LastIFD'] ?? null)) {
            $et->Error('CR2 image IFD may not be deleted');
            return 0;
        }

        if (strlen($newData)) {
            // build 16 byte header for Canon RAW file
            $header = substr($dataPt, 0, 16);
            // set IFD0 pointer (may not be 16 if edited by PhotoMechanic)
            ExifTool::Set32u(16, $header, 4);
            // last 4 bytes of header is pointer to last IFD
            ExifTool::Set32u($dirInfo['LastIFD'], $header, 12);
            if (!ExifTool::Write($outfile, $header, $newData)) return 0;
            $newData = null;    // free memory

            // copy over image data now if necessary
            if (is_array($dirInfo['ImageData'] ?? null)) {
                if (!$et->CopyImageData($dirInfo['ImageData'], $outfile)) return 0;
                unset($dirInfo['ImageData']);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Write CanonRaw (CRW) information
    // Inputs: 0) ExifTool object reference, 1) source dirInfo reference,
    //         2) tag table reference
    // Returns: true on success
    // Notes: Increments ExifTool CHANGED flag for each tag changed This routine is
    // different from all of the other write routines because Canon RAW files are
    // designed well!  So it isn't necessary to buffer the data in memory before
    // writing it out.  Therefore this routine doesn't return the directory data as
    // the rest of the Write routines do.  Instead, it writes to the dirInfo
    // OutFile on the fly --> much faster, efficient, and less demanding on memory!
    public static function WriteCanonRaw(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package
        $blockStart = $dirInfo['DirStart'];
        $blockSize = $dirInfo['DirLen'];
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) return 0;
        $outfile = $dirInfo['OutFile'] ?? null;
        if (!$outfile) return 0;
        $outPos = $dirInfo['OutPos'] ?? null;
        if (!$outPos) return 0;
        $outBase = $outPos;
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $buff = $tagInfo = null;

        // 4 bytes at end of block give directory position within block
        if (!$raf->Seek($blockStart + $blockSize - 4, 0)) return 0;
        if ($raf->Read($buff, 4) != 4) return 0;
        $dirOffset = ExifTool::Get32u($buff, 0) + $blockStart;
        // avoid infinite recursion
        $et->ProcessedCanonRaw or $et->ProcessedCanonRaw = [];
        if (!empty($et->ProcessedCanonRaw[$dirOffset])) {
            $et->Error('Double-referenced ' . Perl::str($dirInfo['DirName'] ?? null) . ' directory');
            return 0;
        }
        $et->ProcessedCanonRaw[$dirOffset] = 1;
        if (!$raf->Seek($dirOffset, 0)) return 0;
        if ($raf->Read($buff, 2) != 2) return 0;
        $entries = ExifTool::Get16u($buff, 0);     // get number of entries in directory
        // read the directory (10 bytes per entry)
        if ($raf->Read($buff, 10 * $entries) != 10 * $entries) return 0;
        $newDir = '';

        // get hash of new information keyed by tagID
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);

        // generate list of tags to add or delete (currently, we only allow JpgFromRaw
        // and ThumbnailImage, to be added or deleted from the root CanonRaw directory)
        // (PHP: in the order of the new values, where Perl takes the hash order)
        $addTags = [];
        $delTag = [];
        if ($dirInfo['Nesting'] == 0) {
            foreach (array_keys($newTags) as $tagID) {
                $permanent = $newTags[$tagID]->Permanent;
                if ($permanent !== null and !$permanent) $addTags[] = $tagID;
            }
        }

        for ($index = 0; ; ++$index) {
            $pt = $tag = $size = $valuePtr = $ptr = $value = null;
            if ($index < $entries) {
                $pt = 10 * $index;
                $tag = ExifTool::Get16u($buff, $pt);
                $size = ExifTool::Get32u($buff, $pt + 2);
                $valuePtr = ExifTool::Get32u($buff, $pt + 6);
                $ptr = $valuePtr + $blockStart;     // all pointers relative to block start
            }
            // add any required new tags
            // NOTE: can't currently add tags where value is stored in directory
            if ($addTags and ($tag === null or $tag >= $addTags[0])) {
                $addTag = array_shift($addTags);
                $tagInfo = $newTags[$addTag];
                $newVal = $et->GetNewValue($tagInfo);
                if ($newVal !== null) {
                    $newVal = Perl::str($newVal);
                    // pad value to an even length (Canon ImageBrowser and ZoomBrowser
                    // version 6.1.1 have problems with odd-sized embedded JPEG images
                    // even if the value is padded to maintain alignment, so do this
                    // before calculating the size for the directory entry)
                    if (strlen($newVal) & 0x01) $newVal .= "\0";
                    // add new directory entry
                    $newDir .= ExifTool::Set16u($addTag) . ExifTool::Set32u(strlen($newVal)) .
                               ExifTool::Set32u($outPos - $outBase);
                    // write new value data
                    if (!ExifTool::Write($outfile, $newVal)) return 0;
                    $outPos += strlen($newVal);     // update current position
                    if ($verbose > 1) fwrite($out, "    + CanonRaw:{$tagInfo->Name}\n");
                    ++$et->CHANGED;
                }
                // set flag to delete this tag if found later
                $delTag[$addTag] = 1;
            }
            if ($tag === null) break;           // all done if no more directory entries
            if ($tag & 0x8000) return 0;        // top bit should not be set
            $tagID = $tag & 0x3fff;             // get tag ID
            $tagType = ($tag >> 8) & 0x38;      // get tag type
            $valueInDir = ($tag & 0x4000);      // flag for value in directory

            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            $format = Vars\CanonRaw::$crwTagFormat[$tagType] ?? null;
            $count = $subdir = null;
            if ($tagInfo) {
                $subdir = $tagInfo->SubDirectory;
                if ($tagInfo->Format) $format = $tagInfo->Format;
                $count = $tagInfo->Count;
            }
            if ($valueInDir) {
                $size = 8;
                $value = substr($buff, $pt + 2, $size);
                // set count to 1 by default for normal values in directory
                // (PHP: an empty SubDirectory is an empty array, but a true hash ref in Perl)
                if ($count === null and $format and $format !== 'string' and $subdir === null) $count = 1;
            } else {
                if ($tagType == 0x28 or $tagType == 0x30) {
                    // this type of tag specifies a raw subdirectory
                    $name = null;
                    if ($tagInfo) $name = $tagInfo->Name;
                    $name or $name = Perl::sprintf('CanonRaw_0x%.4x', $tagID);
                    $subdirInfo = [
                        'DirName'  => $name,
                        'DataLen'  => 0,
                        'DirStart' => $ptr,
                        'DirLen'   => $size,
                        'Nesting'  => $dirInfo['Nesting'] + 1,
                        'RAF'      => $raf,
                        'Parent'   => $dirInfo['DirName'] ?? null,
                        'OutFile'  => $outfile,
                        'OutPos'   => $outPos,
                    ];
                    $result = $et->WriteDirectory($subdirInfo, $tagTablePtr);
                    if (!$result) return 0;
                    // set size and pointer for this new directory
                    $size = $subdirInfo['OutPos'] - $outPos;
                    $valuePtr = $outPos - $outBase;
                    $outPos = $subdirInfo['OutPos'];
                } else {
                    // verify that the value data is within this block
                    if (!($valuePtr + $size <= $blockSize)) return 0;
                    // read value from file
                    if (!$raf->Seek($ptr, 0)) return 0;
                    if ($raf->Read($value, $size) != $size) return 0;
                }
            }
            // set count from tagInfo count if necessary
            if ($format and !$count) {
                // set count according to format and size
                $fnum = Vars\Exif::$formatNumber[$format];
                $fsiz = Vars\Exif::$formatSize[$fnum];
                $count = Perl::int($size / $fsiz);
            }
            // edit subdirectory if necessary
            if ($tagInfo) {
                if ($subdir and !empty($subdir['TagTable'])) {
                    $name = $tagInfo->Name;
                    $newTagTable = ExifTool::GetTagTable($subdir['TagTable']);
                    if (!$newTagTable) return 0;
                    $subdirStart = 0;
                    //### eval Start ()
                    if (!empty($subdir['Start'])) $subdirStart = Perl::num(Exif::EvalCode($subdir['Start']));
                    $dirData = $value;
                    $subdirInfo = [
                        'Name'     => $name,
                        'DataPt'   => $dirData,
                        'DataLen'  => $size,
                        'DirStart' => $subdirStart,
                        'DirLen'   => $size - $subdirStart,
                        'Nesting'  => $dirInfo['Nesting'] + 1,
                        'RAF'      => $raf,
                        'Parent'   => $dirInfo['DirName'] ?? null,
                    ];
                    //### eval Validate ($dirData, $subdirStart, $size)
                    if (isset($subdir['Validate']) and !Exif::EvalCode($subdir['Validate'], null, $dirData, $subdirStart, $size, null)) {
                        $et->Warn("Invalid $name data");
                    } else {
                        $subdir = $et->WriteDirectory($subdirInfo, $newTagTable);
                        if ($subdir !== null and strlen($subdir)) {
                            if ($subdirStart) {
                                // add header before data directory
                                $value = substr($value, 0, $subdirStart) . $subdir;
                            } else {
                                $value = $subdir;
                            }
                        }
                    }
                } elseif ($newTags[$tagID] ?? null) {
                    if ($delTag[$tagID] ?? null) {
                        if ($verbose > 1) fwrite($out, "    - CanonRaw:{$tagInfo->Name}\n");
                        ++$et->CHANGED;
                        continue;   // next since we already added this tag
                    }
                    if ($format) {
                        $oldVal = ExifTool::ReadValue($value, 0, $format, $count, $size);
                    } else {
                        $oldVal = $value;
                    }
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    if ($et->IsOverwriting($nvHash, $oldVal)) {
                        $newVal = $et->GetNewValue($nvHash);
                        $verboseVal = null;
                        if ($verbose > 1) $verboseVal = $newVal;
                        // convert to specified format if necessary
                        if ($newVal !== null and $format) {
                            $newVal = ExifTool::WriteValue($newVal, $format, $count);
                        }
                        if ($newVal !== null) {
                            $value = $newVal;
                            ++$et->CHANGED;
                            $et->VerboseValue("- CanonRaw:{$tagInfo->Name}", $oldVal);
                            $et->VerboseValue("+ CanonRaw:{$tagInfo->Name}", $verboseVal);
                        }
                    }
                }
            }
            if ($valueInDir) {
                $value = Perl::str($value);
                $len = strlen($value);
                if ($len < 8) {
                    // pad with original garbage in case it contained something useful
                    $value .= substr($buff, $pt + 2 + 8 - $len, 8 - $len);
                } elseif ($len > 8) {   // this shouldn't happen
                    ExifTool::PerlWarn("Value too long! -- truncated\n");
                    $value = substr($value, 0, 8);
                }
                // create new directory entry
                $newDir .= ExifTool::Set16u($tag) . $value;
                continue;   // all done this entry
            }
            if ($value !== null) {
                $value = Perl::str($value);
                // don't allow value to change length unless Writable is 'resize'
                $writable = $tagInfo ? $tagInfo->Writable : null;
                $diff = strlen($value) - $size;
                if ($diff) {
                    if ($writable and $writable === 'resize') {
                        $size += $diff; // allow size to change
                    } elseif ($diff > 0) {
                        $value .= str_repeat("\0", $diff);
                    } else {
                        $value = substr($value, 0, $size);
                    }
                }
                // pad value if necessary to align on even-byte boundary (as per CIFF spec)
                if ($size & 0x01) $value .= "\0";
                $valuePtr = $outPos - $outBase;
                // write out value data
                if (!ExifTool::Write($outfile, $value)) return 0;
                $outPos += strlen($value);  // update current position in outfile
            }
            // create new directory entry
            $newDir .= ExifTool::Set16u($tag) . ExifTool::Set32u($size) . ExifTool::Set32u($valuePtr);
        }
        // add the directory counts and offset to the directory start,
        $entries = strlen($newDir) / 10;
        $newDir = ExifTool::Set16u($entries) . $newDir . ExifTool::Set32u($outPos - $outBase);
        // write directory data
        if (!ExifTool::Write($outfile, $newDir)) return 0;

        // update current output file position in dirInfo
        $dirInfo['OutPos'] = $outPos + strlen($newDir);
        // save outfile directory start (needed for rewriting VRD trailer)
        $dirInfo['OutDirStart'] = $outPos - $outBase;

        return 1;
    }

    //------------------------------------------------------------------------------
    // write Canon RAW (CRW) file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid CRW file,
    //          or -1 if a write error occurred
    public static function WriteCRW(ExifTool $et, array &$dirInfo)
    {
        $outfile = $dirInfo['OutFile'];
        $raf = $dirInfo['RAF'];
        $rtnVal = 0;
        $buff = $err = $sig = null;

        if ($raf->Read($buff, 2) != 2) return 0;
        if (!ExifTool::SetByteOrder($buff)) return 0;
        if ($raf->Read($buff, 4) != 4) return 0;
        if ($raf->Read($sig, 8) != 8) return 0;     // get file signature
        if (!preg_match('/^HEAP(CCDR|JPGM)/', $sig, $m)) return 0;  // validate signature
        $type = $m[1];
        $hlen = ExifTool::Get32u($buff, 0);     // get header length

        if ($et->DEL_GROUP['MakerNotes'] ?? null) {
            if ($type === 'CCDR') {
                $et->Error("Can't delete MakerNotes from CRW");
                return 0;
            } else {
                ++$et->CHANGED;
                return 1;
            }
        }
        // make XMP the preferred group for CRW files
        if ($et->FILE_TYPE === 'CRW') {
            $et->InitWriteDirs(self::CRW_MAP, 'XMP');
        }

        // write header
        if (!$raf->Seek(0, 0)) return 0;
        if ($raf->Read($buff, $hlen) != $hlen) return 0;
        if (!ExifTool::Write($outfile, $buff)) $err = 1;

        if (!$raf->Seek(0, 2)) return 0;    // seek to end of file
        $filesize = $raf->Tell();
        if (!$filesize) return 0;

        // build directory information for main raw directory
        $rawDirInfo = [     // (Perl: my %dirInfo)
            'DataLen'  => 0,
            'DirStart' => $hlen,
            'DirLen'   => $filesize - $hlen,
            'Nesting'  => 0,
            'RAF'      => $raf,
            'Parent'   => 'CRW',
            'OutFile'  => $outfile,
            'OutPos'   => $hlen,
        ];
        // process the raw directory
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::CanonRaw::Main');
        $success = $et->WriteDirectory($rawDirInfo, $tagTablePtr);

        $trailPt = null;
        while ($success) {
            // check to see if trailer(s) exist(s)
            $trailInfo = $et->IdentifyTrailer($raf);
            if (!$trailInfo) break;
            // rewrite the trailer(s)
            $trailInfo['OutFile'] = new ScalarRef('');
            $success = $et->ProcessTrailers($trailInfo);
            if (!$success) break;
            $trailPt = $trailInfo['OutFile'];
            // nothing to write if trailers were deleted
            if (strlen($trailPt->value) < 4) $trailPt = null;
            break;
        }
        if ($success) {
            // add CanonVRD trailer if writing as a block
            $trailPt = $et->AddNewTrailers($trailPt, 'CanonVRD');
            if (!$trailPt and ($et->ADD_DIRS['CanonVRD'] ?? null)) {
                // create CanonVRD from scratch if necessary
                $outbuff = new ScalarRef('');
                $saveOrder = ExifTool::GetByteOrder();
                ExifTool::RequireModule('Image::ExifTool::CanonVRD');
                $vrdInfo = ['OutFile' => $outbuff];
                if (CanonVRD::ProcessCanonVRD($et, $vrdInfo) > 0) {
                    $trailPt = $outbuff;
                }
                ExifTool::SetByteOrder($saveOrder);
            }
            // write trailer
            if ($trailPt) {
                // must append DirStart pointer to end of trailer
                $newDirStart = ExifTool::Set32u($rawDirInfo['OutDirStart']);
                $len = strlen($trailPt->value);
                $pad = ($len & 0x01) ? ' ' : '';    // add pad byte if necessary
                if (!ExifTool::Write($outfile, $pad, substr($trailPt->value, 0, $len - 4), $newDirStart)) $err = 1;
            }
            $rtnVal = $err ? -1 : 1;
        } else {
            $et->Error('Error rewriting CRW file');
        }
        return $rtnVal;
    }
}
