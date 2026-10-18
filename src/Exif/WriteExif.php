<?php

//------------------------------------------------------------------------------
// File:         WriteExif.pl
//
// Description:  Write EXIF meta information
//
// Revisions:    12/13/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::Exif), mixed into class Image\ExifTool\Exif. The
// file-scope lexicals %crossDelete and %mandatory are the constants CROSS_DELETE and MANDATORY.
//
// PHP notes:
// - WriteExif() keeps a reference to the caller's dirInfo ($origDirInfo) like Perl, and works on
//   a copy of it once an IFD is loaded from file. $$origDirInfo{ImageData} = \@imageData is a
//   PHP reference to the local list, so the caller sees the blocks added after the assignment.
// - $newValuePt (a reference to $newValue or $oldValue in Perl) is a PHP reference, rebound for
//   each entry; $valueDataPt is a copy of the data it points to.
//------------------------------------------------------------------------------

namespace Image\ExifTool\Exif;

use Image\ExifTool\Canon;
use Image\ExifTool\ExifTool;
use Image\ExifTool\Fixup;
use Image\ExifTool\MakerNotes;
use Image\ExifTool\PanasonicRaw;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\Sony;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars\Exif as Vars;

trait WriteExif
{
    // some information may be stored in different IFD's with the same meaning.
    // Use this lookup to decide when we should delete information that is stored
    // in another IFD when we write it to the preferred IFD.
    /** my %crossDelete */
    private const array CROSS_DELETE = [
        'ExifIFD' => 'IFD0',
        'IFD0'    => 'ExifIFD',
    ];

    // mandatory tag default values
    /** my %mandatory */
    private const array MANDATORY = [
        'IFD0' => [
          // (optional as of 3.0)  0x011a => 72,       # XResolution
          // (optional as of 3.0)  0x011b => 72,       # YResolution
          // (optional as of 3.0)  0x0128 => 2,        # ResolutionUnit (inches)
            0x0213 => 1,        // YCbCrPositioning (centered)
          // 0x8769 => ????,     # ExifOffset
        ],
        'IFD1' => [
            0x0103 => 6,        // Compression (JPEG)
            0x011a => 72,       // XResolution
            0x011b => 72,       // YResolution
            0x0128 => 2,        // ResolutionUnit (inches)
        ],
        'ExifIFD' => [
            0x9000 => '0232',   // ExifVersion
            0x9101 => '1 2 3 0',// ComponentsConfiguration
          // (optional as of 3.0)  0xa000 => '0100',   # FlashpixVersion
            0xa001 => 0xffff,   // ColorSpace (uncalibrated)
          // 0xa002 => ????,     # ExifImageWidth
          // 0xa003 => ????,     # ExifImageHeight
        ],
        'GPS' => [
            0x0000 => '2 3 0 0',// GPSVersionID
        ],
        'InteropIFD' => [
            0x0002 => '0100',   // InteropVersion (from the DCF spec, not the EXIF spec)
        ],
    ];

    //------------------------------------------------------------------------------
    // Inverse print conversion for OffsetTime tags
    // Inputs: 0) input time zone or date/time value, 1) ExifTool ref
    // Returns: Time zone string for writing to EXIF
    public static function InverseOffsetTime($val, $et)
    {
        $val = Perl::str($val);
        if (strtolower($val) === 'now') $val = Perl::str($et->TimeNow());
        if (preg_match('/Z$/', $val)) return '+00:00';
        if (preg_match('/([-+])(\d{1,2}):?(\d{2})/', $val, $m)) return Perl::sprintf('%s%.2d:%.2d', $m[1], $m[2], $m[3]);
        return null;
    }

    //------------------------------------------------------------------------------
    // Inverse print conversion for LensInfo
    // Inputs: 0) lens info string
    // Returns: PrintConvInv of string
    public static function ConvertLensInfo($val)
    {
        $a = self::GetLensInfo($val, 1); // (allow unknown "?" values)
        return $a ? implode(' ', $a) : $val;
    }

    //------------------------------------------------------------------------------
    // Get binary CFA Pattern from a text string
    // Inputs: Print-converted CFA pattern (eg. '[Blue,Green][Green,Red]')
    // Returns: CFA pattern as a string of numbers
    public static function GetCFAPattern($val)
    {
        $rows = Perl::split('/\]\s*\[/', $val);
        if (!$rows) { ExifTool::PerlWarn("Rows not properly bracketed by '[]'\n"); return null; }
        $cols = Perl::split('/,/', $rows[0]);
        if (!$cols) { ExifTool::PerlWarn("Colors not separated by ','\n"); return null; }
        $ny = count($cols);
        $a = [count($rows), count($cols)];
        $cfaLookup = ['red' => 0, 'green' => 1, 'blue' => 2, 'cyan' => 3, 'magenta' => 4, 'yellow' => 5, 'white' => 6];
        foreach ($rows as $row) {
            $cols = Perl::split('/,/', $row);
            if (count($cols) != $ny) { ExifTool::PerlWarn("Inconsistent number of colors in each row\n"); return null; }
            foreach ($cols as $_) {
                $_ = str_replace([' ', ']', '['], '', $_);    // remove remaining brackets and any spaces
                $c = $cfaLookup[strtolower($_)] ?? null;
                if ($c === null) { ExifTool::PerlWarn("Unknown color '{$_}'\n"); return null; }
                $a[] = $c;
            }
        }
        return implode(' ', $a);
    }

    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckExif(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = ($tagInfo->Format ?: $tagInfo->Writable) ?: $tagInfo->Table['WRITABLE'];
        if (!$format or Perl::str($format) === '1') {
            if (($tagInfo->Groups[0] ?? null) === 'MakerNotes') {
                return null;    // OK to have no format for makernotes
            } else {
                return 'No writable format';
            }
        }
        return ExifTool::CheckValue($valPtr, $format, $tagInfo->Count);
    }

    //------------------------------------------------------------------------------
    // encode exif ASCII/Unicode text from UTF8 or Latin
    // Inputs: 0) ExifTool ref, 1) text string
    // Returns: encoded string
    // Note: MUST be called Raw conversion time so the EXIF byte order is known!
    public static function EncodeExifText(ExifTool $et, $val)
    {
        $val = Perl::str($val);
        // does the string contain special characters?
        if (preg_match('/[\x80-\xff]/', $val)) {
            $order = $et->GetNewValue('ExifUnicodeByteOrder');
            return "UNICODE\0" . $et->Encode($val, 'UTF16', $order);
        } else {
            return "ASCII\0\0\0$val";
        }
    }

    //------------------------------------------------------------------------------
    // rebuild maker notes to properly contain all value data
    // (some manufacturers put value data outside maker notes!!)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: new maker note data (and creates MAKER_NOTE_FIXUP), or undef on error
    public static function RebuildMakerNotes(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dirStart = $dirInfo['DirStart'] ?? null;
        $dirLen = $dirInfo['DirLen'] ?? null;
        $dataPt = $dirInfo['DataPt'] ?? null;
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $rtnValue = null;
        $subdirInfo = $dirInfo;

        unset($et->MAKER_NOTE_FIXUP);

        // don't need to rebuild text, BinaryData or PreviewImage maker notes
        $tagInfo = $dirInfo['TagInfo'] ?? null;
        $subdir = $tagInfo->SubDirectory;
        $proc = (($subdir['ProcessProc'] ?? null) ?: $tagTablePtr['PROCESS_PROC']) ?: self::class . '::ProcessExif';
        if (($proc !== self::class . '::ProcessExif' and preg_match('/Text/', Perl::str($tagInfo->Name))) or
             $proc === ExifTool::class . '::ProcessBinaryData' or
            ($tagInfo->PossiblePreview and $dirLen > 6 and
             substr(Perl::str($dataPt), $dirStart, 3) === "\xff\xd8\xff"))
        {
            return substr(Perl::str($dataPt), $dirStart, $dirLen);
        }
        $saveOrder = ExifTool::GetByteOrder();
        $loc = MakerNotes::LocateIFD($et, $subdirInfo);
        if ($loc !== null) {
            $makerFixup = $subdirInfo['Fixup'] = new Fixup();
            // create new exiftool object to rewrite the directory without changing it
            $newTool = new ExifTool();
            $newTool->Options(
                'IgnoreMinorErrors', $et->OPTIONS['IgnoreMinorErrors'] ?? null,
                'FixBase',           $et->OPTIONS['FixBase'] ?? null,
            );
            $newTool->Init();   // must do this before calling WriteDirectory()!
            // don't copy over preview image
            $newTool->SetNewValue('PreviewImage', '');
            // copy all transient members over in case they are used for writing
            // (Make, Model, etc)
            foreach (get_object_vars($et) as $key => $value) {
                if (preg_match('/[a-z]/', (string) $key)) $newTool->{$key} = $value;
            }
            // fix base offsets if specified
            $newTool->Options('FixBase', $et->Options('FixBase'));
            // set GENERATE_PREVIEW_INFO flag so PREVIEW_INFO will be generated
            $newTool->GENERATE_PREVIEW_INFO = 1;
            // drop any large tags
            $newTool->DropTags = 1;
            // initialize other necessary data members
            $newTool->FILE_TYPE = $et->FILE_TYPE;
            $newTool->TIFF_TYPE = $et->TIFF_TYPE;
            // rewrite maker notes
            $rtnValue = $newTool->WriteDirectory($subdirInfo, $tagTablePtr);
            if ($rtnValue !== null and strlen($rtnValue)) {
                // add the dummy/empty preview image if necessary
                if ($newTool->PREVIEW_INFO) {
                    $makerFixup->SetMarkerPointers($rtnValue, 'PreviewImage', strlen($rtnValue));
                    $rtnValue .= $newTool->PREVIEW_INFO['Data'];
                    unset($newTool->PREVIEW_INFO);
                }
                // add makernote header
                if ($loc) {
                    $hdr = substr($dataPt, $dirStart, $loc);
                    // special case: convert Pentax/Samsung DNG maker notes to JPEG style
                    // (in JPEG, Pentax makernotes are absolute and start with "AOC\0" for some
                    // models, but in DNG images they are stored in tag 0xc634 of IFD0 and
                    // start with either "PENTAX \0" or "SAMSUNG\0")
                    if (($dirInfo['Parent'] ?? null) === 'IFD0' and preg_match('/^(PENTAX |SAMSUNG)\0/', $hdr)) {
                        // convert to JPEG-style AOC maker notes if used by this model
                        // (Note: this expression also appears in Exif.pm)
                        if (preg_match('/\b(K(-[57mrx]|(10|20|100|110|200)D|2000)|GX(10|20))\b/', Perl::str($et->Model))) {
                            $hdr = preg_replace('/^(PENTAX |SAMSUNG)\0/', "AOC\0", $hdr, 1);
                            // save fixup because AOC maker notes have absolute offsets
                            $et->MAKER_NOTE_FIXUP = $makerFixup;
                        }
                    }
                    $rtnValue = $hdr . $rtnValue;
                    // adjust fixup for shift in start position
                    $makerFixup->Start += strlen($hdr);
                }
                // shift offsets according to original position of maker notes,
                // and relative to the makernotes Base
                $makerFixup->Shift += $dataPos + $dirStart +
                                      ($dirInfo['Base'] ?? 0) - $subdirInfo['Base'];
                // repair incorrect offsets if offsets were fixed
                $makerFixup->Shift += ($subdirInfo['FixedBy'] ?? null) ?: 0;
                // fix up pointers to the specified offset
                $makerFixup->ApplyFixup($rtnValue);
                // save fixup information unless offsets were relative
                if (!($subdirInfo['Relative'] ?? null)) {
                    // set shift so offsets are all relative to start of maker notes
                    $makerFixup->Shift -= $dataPos + $dirStart;
                    $et->MAKER_NOTE_FIXUP = $makerFixup;    // save fixup for later
                }
            }
        }
        ExifTool::SetByteOrder($saveOrder);

        return $rtnValue;
    }

    //------------------------------------------------------------------------------
    // Sort IFD directory entries
    // Inputs: 0) data reference, 1) directory start, 2) number of entries,
    //         3) flag to treat 0 as a valid tag ID (as opposed to an empty IFD entry)
    public static function SortIFD(&$dataPt, $dirStart, $numEntries, $allowZero = null): void
    {
        $entries = [];
        // split the directory into separate entries
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + 2 + 12 * $index;
            $tagID = ExifTool::Get16u($dataPt, $entry);
            $entryData = substr($dataPt, $entry, 12);
            // silly software can pad directories with zero entries -- put these at the end
            if (!($tagID or $index == 0 or $allowZero)) $tagID = 0x10000;
            // add new entry (allow for duplicate tag ID's, which shouldn't normally happen)
            if (isset($entries[$tagID])) {
                $entries[$tagID] .= $entryData;
            } else {
                $entries[$tagID] = $entryData;
            }
        }
        // sort the directory entries
        ksort($entries, SORT_NUMERIC);
        // generate the sorted IFD
        $newDir = implode('', $entries);
        // replace original directory with new, sorted one
        $dataPt = substr_replace($dataPt, $newDir, $dirStart + 2, 12 * $numEntries);
    }

    //------------------------------------------------------------------------------
    // Validate IFD entries (strict validation to test possible chained IFD's)
    // Inputs: 0) dirInfo ref (must have RAF set), 1) optional DirStart
    // Returns: true if IFD looks OK
    public static function ValidateIFD(array &$dirInfo, $dirStart = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) return 0;
        $base = $dirInfo['Base'] ?? null;
        if ($dirStart === null) $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $offset = $dirStart + (($dirInfo['DataPos'] ?? null) ?: 0);
        $buff = null;
        if (!($raf->Seek($offset + $base, 0) and $raf->Read($buff, 2) == 2)) return 0;
        $numEntries = ExifTool::Get16u($buff, 0);
        if (!($numEntries > 1 and $numEntries < 64)) return 0;
        $len = 12 * $numEntries;
        if ($raf->Read($buff, $len) != $len) return 0;
        $lastID = -1;
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = 12 * $index;
            $tagID = ExifTool::Get16u($buff, $entry);
            if (!($tagID > $lastID or ($dirInfo['AllowOutOfOrderTags'] ?? null))) return 0;
            $format = ExifTool::Get16u($buff, $entry + 2);
            if (!($format > 0 and $format <= 13)) return 0;
            $count = ExifTool::Get32u($buff, $entry + 4);
            if (!($count > 0)) return 0;
            $lastID = $tagID;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Get sorted list of offsets used in IFD
    // Inputs: 0) data ref, 1) directory start, 2) dataPos, 3) IFD entries, 4) tag table ref
    // Returns: 0) sorted list of offsets (only offsets after the end of the IFD)
    //          1) hash of list indices keyed by offset value
    // Notes: This is used in a patch to fix the count for tags in Kodak SubIFD3
    public static function GetOffList($dataPt, $dirStart, $dataPos, $numEntries, TagTable $tagTablePtr)
    {
        $ifdEnd = $dirStart + 2 + 12 * $numEntries + $dataPos;
        $offHash = [];
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + 2 + 12 * $index;
            $format = ExifTool::Get16u($dataPt, $entry + 2);
            if ($format < 1 or $format > 13) continue;
            $count = ExifTool::Get16u($dataPt, $entry + 4);
            $size = Vars::$formatSize[$format] * $count;
            if ($size <= 4) {
                $tagID = ExifTool::Get16u($dataPt, $entry);
                if (!($tagTablePtr[$tagID] instanceof TagInfo and $tagTablePtr[$tagID]->FixCount)) continue;
            }
            $offset = ExifTool::Get16u($dataPt, $entry + 8);
            if ($offset >= $ifdEnd) $offHash[$offset] = 1;
        }
        // set offset hash values to indices in list
        $offList = array_keys($offHash);
        sort($offList, SORT_STRING);
        $index = 0;
        foreach ($offList as $offset) {
            $offHash[$offset] = $index++;
        }
        return [$offList, $offHash];
    }

    //------------------------------------------------------------------------------
    // Update TIFF_END member if defined
    // Inputs: 0) ExifTool ref, 1) end of valid TIFF data
    public static function UpdateTiffEnd(ExifTool $et, $end): void
    {
        if ($et->TIFF_END !== null and
            $et->TIFF_END < $end)
        {
            $et->TIFF_END = $end;
        }
    }

    //------------------------------------------------------------------------------
    // Validate image data size
    // Inputs: 0) ExifTool ref, 1) validate info hash ref,
    //         2) flag to issue error (ie. we're writing)
    // - issues warning or error if problems found
    public static function ValidateImageData(ExifTool $et, array $vInfo, $dirName, $errFlag = null)
    {
        // determine the expected size of the image data for an uncompressed image
        // (0x102 BitsPerSample, 0x103 Compression and 0x115 SamplesPerPixel
        //  all default to a value of 1 if they don't exist)
        if ((!isset($vInfo[0x103]) or Perl::str($vInfo[0x103]) === '1') and
            ($vInfo[0x100] ?? null) and ($vInfo[0x101] ?? null) and (($vInfo[0x117] ?? null) or ($vInfo[0x145] ?? null)))
        {
            $samplesPerPix = ($vInfo[0x115] ?? null) ?: 1;
            $bitsPerSample = ($vInfo[0x102] ?? null) ? Perl::split(' ', $vInfo[0x102]) :
                array_fill(0, max(0, Perl::int($samplesPerPix)), 1);
            $byteCountInfo = ($vInfo[0x117] ?? null) ?: $vInfo[0x145];
            $byteCounts = $byteCountInfo[1];
            $totalBytes = 0;
            foreach (Perl::split(' ', $byteCounts) as $_) $totalBytes += Perl::num($_);
            $minor = null;
            if ($et->DOC_NUM or $et->FILE_TYPE !== 'TIFF') $minor = 1;
            if (count($bitsPerSample) != $samplesPerPix) {
                if (!($et->FILE_TYPE === 'EPS' and count($bitsPerSample) == 1)) {
                    // (just a warning for this problem)
                    $s = Perl::str($samplesPerPix) === '1' ? '' : 's';
                    $et->Warn("$dirName BitsPerSample should have $samplesPerPix value$s", $minor);
                }
                while (count($bitsPerSample) < $samplesPerPix) $bitsPerSample[] = $bitsPerSample[0];
                foreach ($bitsPerSample as $_) {
                    if (Perl::str($_) !== Perl::str($bitsPerSample[0])) $et->Warn("$dirName BitsPerSample values are different", $minor);
                    if (Perl::num($_) < 1 or Perl::num($_) > 32) $et->Warn("Invalid $dirName BitsPerSample value", $minor);
                }
            }
            $bitsPerPixel = 0;
            foreach ($bitsPerSample as $_) $bitsPerPixel += Perl::num($_);
            $expectedBytes = Perl::int((Perl::num($vInfo[0x100]) * Perl::num($vInfo[0x101]) * $bitsPerPixel + 7) / 8);
            if ($expectedBytes != $totalBytes and
                // (this problem seems normal for certain types of RAW files...)
                !preg_match('/^(K25|KDC|MEF|ORF|SRF)$/', Perl::str($et->TIFF_TYPE)))
            {
                $adj = $minor = null;
                if ($expectedBytes > $totalBytes) {
                    $adj = 'Under'; // undersized is a bigger problem because we may lose data
                    if (!$errFlag) $minor = 0;
                } else {
                    $adj = 'Over';
                    $minor = 1;
                }
                $msg = "{$adj}sized $dirName {$byteCountInfo[0]->Name} ($totalBytes bytes, but expected $expectedBytes)";
                if ($minor === null) {
                    // this is a serious error if we are writing the file and there
                    // is a chance that we may not copy all of the image data
                    // (but make it minor to allow the file to be written anyway)
                    $et->Error($msg, 1);
                } else {
                    $et->Warn($msg, $minor);
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Add specified image data to ImageDataHash hash
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) lookup for [tagInfo,value] based on tagID
    public static function AddImageDataHash(ExifTool $et, array &$dirInfo, array $offsetInfo)
    {
        $verbose = $et->Options('Verbose');
        $raf = $dirInfo['RAF'] ?? null;

        $tagIDs = array_keys($offsetInfo);
        sort($tagIDs, SORT_STRING);
        foreach ($tagIDs as $tagID) {
            if (!is_array($offsetInfo[$tagID])) continue; // ignore scalar tag values used for Validate
            $tagInfo = $offsetInfo[$tagID][0];
            if (!$tagInfo->IsImageData) continue;     // only consider image data
            $sizeID = $tagInfo->OffsetPair;
            $sizes = [];
            if ($tagInfo->NotRealPair) {
                $sizes = [999999999];     // (Panasonic hack: raw data runs to end of file)
            } elseif ($sizeID and ($offsetInfo[$sizeID] ?? null)) {
                $sizes = Perl::split(' ', $offsetInfo[$sizeID][1]);
            } else {
                continue;
            }
            $offsets = Perl::split(' ', $offsetInfo[$tagID][1]);
            if ($tagInfo->NotRealPair) $sizes[0] = 999999999;
            $total = 0;
            foreach ($offsets as $offset) {
                $size = array_shift($sizes);
                if (!(preg_match('/^\d+$/', $offset) and $size and preg_match('/^\d+$/', $size) and $size)) continue;
                if (!$raf->Seek($offset, 0)) continue; // (offset is absolute)
                $total += $et->ImageDataHash($raf, $size);
            }
            if ($verbose) {
                $name = ($dirInfo['DirName'] ?? '') . ":{$tagInfo->Name}";
                $name = preg_replace('/Offsets?|Start$/', '', $name, 1);
                $et->VPrint(0, "{$et->INDENT}(ImageDataHash: $total bytes of $name data)\n");
            }
        }
    }

    //------------------------------------------------------------------------------
    // Handle error while writing EXIF
    // Inputs: 0) ExifTool ref, 1) error string, 2) tag table ref
    // Returns: undef on fatal error, or '' if minor error is ignored
    public static function ExifErr(ExifTool $et, $errStr, TagTable $tagTablePtr)
    {
        // MakerNote errors are minor by default
        $minor = ($tagTablePtr['GROUPS'][0] === 'MakerNotes' or $et->FILE_TYPE === 'MOV') ? 1 : 0;
        if ($tagTablePtr['VARS'] and ($tagTablePtr['VARS']['MINOR_ERRORS'] ?? null)) {
            if ($minor and $et->Warn("$errStr. IFD dropped.")) return '';
            $minor = 1;
        }
        if ($et->Error($errStr, $minor)) return null;
        return '';
    }

    //------------------------------------------------------------------------------
    // Read/Write IFD with TIFF-like header (used by DNG 1.2)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: Reading: 1 on success, otherwise returns 0 and sets a Warning
    //          Writing: new data block or undef on error
    public static function ProcessTiffIFD(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;    // allow dummy access
        $raf = $dirInfo['RAF'] ?? null;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $dirName = $dirInfo['DirName'] ?? null;
        $magic = ($dirInfo['Subdir']['Magic'] ?? null) ?: 0x002a;
        $buff = null;

        // structured with a TIFF-like header and relative offsets
        if (!($raf->Seek($base, 0) and $raf->Read($buff, 8) == 8)) return 0;
        if (!(ExifTool::SetByteOrder(substr($buff, 0, 2)) and ExifTool::Get16u($buff, 2) == $magic)) {
            $msg = "Invalid $dirName header";
            if ($dirInfo['IsWriting'] ?? null) {
                $et->Error($msg);
                return null;
            } else {
                $et->Warn($msg);
                return 0;
            }
        }
        $offset = ExifTool::Get32u($buff, 4);
        $tiffDirInfo = [   // (Perl %dirInfo)
            'DirName'  => $dirInfo['DirName'] ?? null,
            'Parent'   => $dirInfo['Parent'] ?? null,
            'Base'     => $base,
            'DataPt'   => $buff,
            'DataLen'  => strlen($buff),
            'DataPos'  => 0,
            'DirStart' => $offset,
            'DirLen'   => strlen($buff) - $offset,
            'RAF'      => $raf,
            'NewDataPos' => 8,
        ];
        if ($dirInfo['IsWriting'] ?? null) {
            // rewrite the Camera Profile IFD
            $newDir = self::WriteExif($et, $tiffDirInfo, $tagTablePtr);
            // don't add header if error writing directory ($newDir is undef)
            // or if directory is being deleted ($newDir is empty)
            if (!$newDir) return $newDir;
            // return directory with TIFF-like header
            return ExifTool::GetByteOrder() . ExifTool::Set16u($magic) . ExifTool::Set32u(8) . $newDir;
        }
        if ($et->HTML_DUMP) {
            $tip = Perl::sprintf("Byte order: %s endian\nIdentifier: 0x%.4x\n%s offset: 0x%.4x",
                              (ExifTool::GetByteOrder() === 'II') ? 'Little' : 'Big', $magic, $dirName, $offset);
            $et->HDump($base, 8, "$dirName header", $tip, 0);
        }
        return self::ProcessExif($et, $tiffDirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Write EXIF directory
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: Exif data block (may be empty if no Exif data) or undef on error
    // Notes: Increments ExifTool CHANGED flag for each tag changed.  Also updates
    //        TIFF_END if defined with location of end of original TIFF image.
    // Returns IFD data in the following order:
    //   1. IFD0 directory followed by its data
    //   2. SubIFD directory followed by its data, thumbnail and image
    //   3. GlobalParameters, EXIF, GPS, Interop IFD's each with their data
    //   4. IFD1,IFD2,... directories each followed by their data
    //   5. Thumbnail and/or image data for each IFD, with IFD0 image last
    public static function WriteExif(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;      // allow dummy access to autoload this package
        $origDirInfo = &$dirInfo; // save original dirInfo
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt === null) {
            $emptyData = '';
            $dataPt = $emptyData;
        }
        $dataPt = Perl::str($dataPt);
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dataLen = ($dirInfo['DataLen'] ?? null) ?: strlen($dataPt);
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: ($dataLen - $dirStart);
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $firstBase = $base;
        $raf = $dirInfo['RAF'] ?? null;
        $dirName = ($dirInfo['DirName'] ?? null) ?: 'unknown';
        $fixup = ($dirInfo['Fixup'] ?? null) ?: new Fixup();
        $imageDataFlag = ($dirInfo['ImageData'] ?? null) ?: '';
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $noMandatory = $et->Options('NoMandatory');
        $nextIfdPos = $inMakerNotes = null;
        $offsetData = [];
        $offsetInfoList = [];   // (Perl @offsetInfo)
        $validateInfo = $xDelete = [];
        $strEnc = null;
        $deleteAll = 0;
        $newData = '';   // initialize buffer to receive new directory data
        $imageData = [];      // image data blocks to copy later if requested
        $name = $dirInfo['Name'] ?? null;
        if (!($name and $dirName === 'MakerNotes' and !preg_match('/^MakerNote/', $name))) $name = $dirName;

        // save byte order of existing EXIF
        if ($dirName === 'IFD0' or $dirName === 'ExifIFD') $et->SaveExifByteOrder = ExifTool::GetByteOrder();

        // set encoding for strings
        if ($tagTablePtr['GROUPS'][0] === 'EXIF') $strEnc = $et->Options('CharsetEXIF');

        // allow multiple IFD's in IFD0-IFD1-IFD2... chain
        if (preg_match('/^(IFD0|SubIFD)$/', $dirName) and !isset($dirInfo['Multi'])) $dirInfo['Multi'] = 1;
        if ($tagTablePtr['GROUPS'][0] === 'MakerNotes') $inMakerNotes = 1;
        $ifd = null;

        //~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
        // loop through each IFD
        //
        for ($ifd = 0; ; ++$ifd) {  // loop through multiple IFD's

            // make sure that Compression and SubfileType are defined for this IFD (for Condition's)
            $et->Compression = $et->SubfileType = '';

            // save pointer to start of this IFD within the newData
            $newStart = strlen($newData);
            $subdirs = [];    // list of subdirectory data and tag table pointers
            // determine if directory is contained within our data
            $mustRead = null;
            if ($dirStart < 0 or $dirStart > $dataLen - 2) {
                $mustRead = 1;
            } elseif ($dirLen >= 2) {
                $len = 2 + 12 * ExifTool::Get16u($dataPt, $dirStart);
                if ($dirStart + $len > $dataLen) $mustRead = 1;
            }
            // read IFD from file if necessary
            if ($mustRead) {
                if ($raf) {
                    // read the count of entries in this IFD
                    $offset = $dirStart + $dataPos;
                    $buff = $buf2 = null;
                    if (!($raf->Seek($offset + $base, 0) and $raf->Read($buff, 2) == 2)) {
                        return self::ExifErr($et, "Bad IFD or truncated file in $name", $tagTablePtr);
                    }
                    $len = 12 * ExifTool::Get16u($buff, 0);
                    // (also read next IFD pointer if available)
                    if (!($raf->Read($buf2, $len + 4) >= $len)) {
                        return self::ExifErr($et, "Error reading $name", $tagTablePtr);
                    }
                    $buff .= $buf2;
                    // make copy of dirInfo since we're going to modify it
                    $newDirInfo = $dirInfo;
                    unset($dirInfo);
                    $dirInfo = $newDirInfo;
                    // update directory parameters for the newly loaded IFD
                    $dataPt = $dirInfo['DataPt'] = $buff;
                    $dirStart = $dirInfo['DirStart'] = 0;
                    $dataPos = $dirInfo['DataPos'] = $offset;
                    $dataLen = $dirInfo['DataLen'] = strlen($buff);
                    $dirLen = $dirInfo['DirLen'] = $dataLen;
                    // only account for nextIFD pointer if we are going to use it
                    if ($dataLen == $len + 6 and (($dirInfo['Multi'] ?? null) or preg_match('/\0{4}$/', $buff))) $len += 4;
                    self::UpdateTiffEnd($et, $offset + $base + 2 + $len);
                } elseif ($dirLen and $dirStart + 4 >= $dataLen) {
                    // error if we can't load IFD (unless we are creating
                    // from scratch, in which case dirLen will be zero)
                    $str = $et->Options('IgnoreMinorErrors') ? 'Deleted bad' : 'Bad';
                    $et->Error("$str $name directory", 1);
                }
            }
            $index = $dirEnd = $numEntries = $unsorted = null;
            $hasOldID = [];
            if ($dirStart + 4 < $dataLen) {
                $numEntries = ExifTool::Get16u($dataPt, $dirStart);
                $dirEnd = $dirStart + 2 + 12 * $numEntries;
                if ($dirEnd > $dataLen) {
                    $n = Perl::int(($dataLen - $dirStart - 2) / 12);
                    $rtn = self::ExifErr($et, "Truncated $name directory", $tagTablePtr);
                    if (!($n and $rtn !== null)) return null;
                    $numEntries = $n;   // continue processing the entries we have
                }
                // create lookup for existing tag ID's and determine if directory is sorted
                $lastID = -1;
                for ($index = 0; $index < $numEntries; ++$index) {
                    $tagID = ExifTool::Get16u($dataPt, $dirStart + 2 + 12 * $index);
                    $hasOldID[$tagID] = 1;
                    // check for proper sequence (but ignore null entries at end)
                    if ($tagID < $lastID and ($tagID or $tagTablePtr[0])) $unsorted = 1;
                    $lastID = $tagID;
                }
                // sort entries if out-of-order (but not in maker notes IFDs or RAW files)
                if ($unsorted and !($inMakerNotes or $et->IsRawType())) {
                    self::SortIFD($dataPt, $dirStart, $numEntries, $tagTablePtr[0]);
                    $dirInfo['DataPt'] = $dataPt;   // (same buffer in Perl)
                    $et->Warn("Entries in $name were out of sequence. Fixed.", 1);
                    $unsorted = 0;
                }
            } else {
                $numEntries = 0;
                $dirEnd = $dirStart;
            }

            // loop through new values and accumulate all information for this IFD
            $set = $mayDelete = $hasNewID = [];
            $tagInfo = null;
            $wrongDir = self::CROSS_DELETE[$dirName] ?? null;
            $newTagInfo = $et->GetNewTagInfoList($tagTablePtr);
            foreach ($newTagInfo as $tagInfo) {
                $tagID = $tagInfo->TagID;
                $hasNewID[$tagID] = 1;
                // must evaluate Condition later when we have all DataMember's available
                $set[$tagID] = (is_array($tagTablePtr[$tagID]) or $tagInfo->Condition) ? '' : $tagInfo;
            }

            // fix base offsets (some cameras incorrectly write maker notes in IFD0)
            if ($dirName === 'MakerNotes' and preg_match('/^(ExifIFD|IFD0)$/', Perl::str($dirInfo['Parent'] ?? null)) and
                !preg_match('/^(ARW|SR2)$/', Perl::str($et->TIFF_TYPE)) and !$et->LeicaTrailerPos and
                MakerNotes::FixBase($et, $dirInfo))
            {
                // update local variables from fixed values
                $base = $dirInfo['Base'];
                $dataPos = $dirInfo['DataPos'];
                // changed if ForceWrite tag was was set to "FixBase"
                if ($et->FORCE_WRITE['FixBase'] ?? null) ++$et->CHANGED;
                if ($et->TIFF_TYPE === 'SRW' and $et->Make === 'SAMSUNG' and $et->Model === 'EK-GN120') {
                    $et->Error('EK-GN120 SRW files are too buggy to write');
                }
            }

            // initialize variables to handle mandatory tags
            $mandatory = $allMandatory = $addMandatory = null;
            if (!$noMandatory) $mandatory = self::MANDATORY[$dirName] ?? null;
            if ($mandatory) {
                // use X/Y resolution values from JFIF if available
                if ($dirName === 'IFD0' and $et->JFIFYResolution !== null) {
                    $ifd0Vals = $mandatory;
                    $ifd0Vals[0x011a] = $et->JFIFXResolution;
                    $ifd0Vals[0x011b] = $et->JFIFYResolution;
                    $ifd0Vals[0x0128] = Perl::num($et->JFIFResolutionUnit) + 1;
                    $mandatory = $ifd0Vals;
                }
                $allMandatory = $addMandatory = 0; // initialize to zero
                // add mandatory tags if creating a new directory
                if (!$numEntries) {
                    foreach (array_keys($mandatory) as $_) {
                        if (!isset($set[$_])) $set[$_] = $tagTablePtr[$_];
                    }
                }
            } else {
                $deleteAll = null;   // don't remove directory (no mandatory entries)
            }
            $addDirs = null;
            $newTags = [];
            if ($inMakerNotes) {
                $addDirs = [];     // can't currently add new directories in MakerNotes
                // allow non-permanent makernotes tags to be added
                // (note: we may get into trouble if there are too many of these
                //  because we allow out-of-order tags in MakerNote IFD's but our
                //  logic to add new tags relies on ordered entries)
                foreach (array_keys($set) as $_) {
                    if (!$set[$_]) continue;
                    $perm = $set[$_]->Permanent;
                    if ($perm !== null and !$perm) $newTags[] = $_;
                }
                if (count($newTags) > 1) sort($newTags, SORT_NUMERIC);
            } else {
                // get a hash of directories we will be writing in this one
                $addDirs = $et->GetAddDirHash($tagTablePtr, $dirName);
                // make a union of tags & dirs (can set whole dirs, like MakerNotes)
                $allTags = $set + $addDirs;
                // make sorted list of new tags to be added
                $newTags = array_keys($allTags);
                sort($newTags, SORT_NUMERIC);
            }
            $dirBuff = '';   // buffer for directory data
            $valBuff = '';   // buffer for value data
            $valFixups = [];      // list of fixups for offsets in valBuff
            // fixup for offsets in dirBuff
            $dirFixup = new Fixup();
            $entryBasedFixup = null;
            $lastTagID = -1;
            $oldInfo = $oldFormat = $oldFormName = $oldCount = $oldSize = $oldValue = $oldImageData = null;
            $readFormat = $readFormName = $readCount = null; // format for reading old value(s)
            $entry = $valueDataPt = $valueDataPos = $valueDataLen = $valuePtr = $valEnd = null;
            $offList = $offHash = $ignoreCount = $fixCount = null;
            $oldID = -1;
            $newID = -1;

            // patch for Canon EOS 40D firmware 1.0.4 bug (incorrect directory counts)
            if ($inMakerNotes and $et->Model === 'Canon EOS 40D') {
                $fmt = ExifTool::Get16u($dataPt, $dirStart + 2 + 12 * ($numEntries - 1) + 2);
                if ($fmt < 1 or $fmt > 13) {
                    // adjust the number of directory entries
                    --$numEntries;
                    $dirEnd -= 12;
                    $ignoreCount = 1;
                }
            }
            //..............................................................................
            // loop through entries in new directory
            //
            $index = 0;
            for (;;) {   // Entry:

                if ($oldID !== null and $oldID == $newID) {
                    //
                    // read next entry from existing directory
                    //
                    if ($index < $numEntries) {
                        $entry = $dirStart + 2 + 12 * $index;
                        $oldID = ExifTool::Get16u($dataPt, $entry);
                        $readFormat = $oldFormat = ExifTool::Get16u($dataPt, $entry + 2);
                        $readCount = $oldCount = ExifTool::Get32u($dataPt, $entry + 4);
                        $oldImageData = null;
                        if (($oldFormat < 1 or $oldFormat > 13) and $oldFormat != 129 and !($oldFormat == 16 and $et->Make === 'Apple' and $inMakerNotes)) {
                            $msg = "Bad format ($oldFormat) for $name entry $index";
                            // patch to preserve invalid directory entries in SubIFD3 of
                            // various Kodak Z-series cameras (Z812, Z1085IS, Z1275)
                            // and some Sony cameras such as the DSC-P10
                            if ($dirName === 'MakerNotes' and ((preg_match('/KODAK/i', Perl::str($et->Make)) and
                                ($dirInfo['Name'] ?? null) and $dirInfo['Name'] === 'SubIFD3') or
                                ($numEntries == 12 and $et->Make === 'SONY' and $index >= 8)))
                            {
                                $dirBuff .= substr($dataPt, $entry, 12);
                                ++$index;
                                $newID = $oldID;    // we wrote this
                                $et->Warn($msg, 1);
                                continue;
                            }
                            // don't write out null directory entry
                            if ($oldFormat == 0 and $index and $oldCount == 0) {
                                $ignoreCount = ($ignoreCount ?: 0) + 1;
                                // must keep same directory size to avoid messing up our fixed offsets
                                if ($dirInfo['FixBase'] ?? null) $dirBuff .= str_repeat("\0", 12);
                                ++$index;
                                $newID = $oldID;    // pretend we wrote this
                                continue;
                            }
                            return self::ExifErr($et, $msg, $tagTablePtr);
                        }
                        $readFormName = $oldFormName = Vars::$formatName[$oldFormat] ?? null;
                        $valueDataPt = $dataPt;
                        $valueDataPos = $dataPos;
                        $valueDataLen = $dataLen;
                        $valuePtr = $entry + 8;
                        // try direct method first for speed
                        $oldInfo = $tagTablePtr[$oldID];
                        if (!$oldInfo instanceof TagInfo or $oldInfo->Condition) {
                            // must get unknown tags too
                            // (necessary so we don't miss a tag we want to Drop)
                            $unk = $et->Options('Unknown', 1);
                            $oldInfo = $et->GetTagInfo($tagTablePtr, $oldID);
                            $et->Options('Unknown', $unk);
                        }
                        // patch incorrect count in Kodak SubIFD3 tags
                        if ($oldCount < 2 and $oldInfo and $oldInfo->FixCount) {
                            if ($offList === null) [$offList, $offHash] = self::GetOffList($dataPt, $dirStart, $dataPos,
                                                                                         $numEntries, $tagTablePtr);
                            $i = $offHash[Perl::str(ExifTool::Get32u($dataPt, $valuePtr))] ?? null;
                            if ($i !== null and $i < count($offList) - 1) {
                                $oldCount = Perl::int(($offList[$i + 1] - $offList[$i]) / Vars::$formatSize[$oldFormat]);
                                if ($oldCount != $readCount) $fixCount = ($fixCount ?: 0) + 1;
                            }
                        }
                        $oldSize = $oldCount * Vars::$formatSize[$oldFormat];
                        $readFromFile = null;
                        if ($oldSize > 4) {
                            $valuePtr = ExifTool::Get32u($dataPt, $valuePtr);
                            // fix valuePtr if necessary
                            if ($dirInfo['FixOffsets'] ?? null) {
                                if (!$valEnd) $valEnd = $dataPos + $dirStart + 2 + 12 * $numEntries + 4;
                                [$tagID, $size, $wFlag] = [$oldID, $oldSize, 1];
                                //#### eval FixOffsets ($valuePtr, $valEnd, $size, $tagID, $wFlag)
                                try {
                                    ($dirInfo['FixOffsets'])($valuePtr, $valEnd, $size, $tagID, $wFlag);
                                } catch (\Throwable) {
                                }
                                if ($valuePtr === null) {
                                    if (!$et->DropTags) {
                                        $tagStr = $oldInfo ? $oldInfo->Name : Perl::sprintf('tag 0x%.4x', $oldID);
                                        if ($et->Error("Bad $name offset for $tagStr", $inMakerNotes)) return null;
                                    }
                                    ++$index;  $oldID = $newID;  continue;  // drop this tag
                                }
                            }
                            // offset shouldn't point into TIFF or IFD header
                            $suspect = ($valuePtr < 8);
                            // convert offset to pointer in $$dataPt
                            if (($dirInfo['EntryBased'] ?? null) or ($tagTablePtr[$oldID] instanceof TagInfo and
                                $tagTablePtr[$oldID]->EntryBased))
                            {
                                $valuePtr += $entry;
                            } else {
                                $valuePtr -= $dataPos;
                            }
                            // value shouldn't overlap our directory
                            if ($valuePtr < $dirEnd and $valuePtr + $oldSize > $dirStart) $suspect = 1;
                            // get value by seeking in file if we are allowed
                            if ($valuePtr < 0 or $valuePtr + $oldSize > $dataLen) {
                                $pos = $tagStr = $invalidPreview = $tmpInfo = $leicaTrailer = null;
                                if ($oldInfo) {
                                    $tagStr = $oldInfo->Name;
                                    $leicaTrailer = $oldInfo->LeicaTrailer;
                                } elseif ($oldInfo !== null) {
                                    $tmpInfo = $et->GetTagInfo($tagTablePtr, $oldID, '', $oldFormName, $oldCount);
                                    if ($tmpInfo) {
                                        $tagStr = $tmpInfo->Name;
                                        $leicaTrailer = $tmpInfo->LeicaTrailer;
                                    }
                                }
                                if (!$tagStr) $tagStr = Perl::sprintf('tag 0x%.4x', $oldID);
                                // allow PreviewImage to run outside EXIF segment in JPEG images
                                if (!$raf) {
                                    if ($tagStr === 'PreviewImage') {
                                        $raf = $et->RAF;
                                        if ($raf) {
                                            $pos = $raf->Tell();
                                            if ($oldInfo and $oldInfo->ChangeBase) {
                                                // adjust base offset for this tag only
                                                //#### eval ChangeBase ($dirStart,$dataPos)
                                                $newBase = self::EvalCode($oldInfo->ChangeBase, $dirStart, $dataPos);
                                                $valuePtr += Perl::num($newBase);
                                            }
                                        } else {
                                            $invalidPreview = 1;
                                        }
                                    } elseif ($leicaTrailer) {
                                        // save information about Leica makernote trailer
                                        $et->LeicaTrailer = [
                                            'TagInfo' => $oldInfo ?: $tmpInfo,
                                            'Offset'  => $base + $valuePtr + $dataPos,
                                            'Size'    => $oldSize,
                                            'Fixup'   => new Fixup(),
                                        ];
                                        $invalidPreview = 2;
                                        // remove SubDirectory to prevent processing (for now)
                                        $copy = clone ($oldInfo ?: $tmpInfo);
                                        unset($copy->SubDirectory, $copy->MakerNotes);
                                        $oldInfo = $copy;
                                    }
                                }
                                if ($oldSize > self::BINARY_DATA_LIMIT() and !empty($origDirInfo['ImageData']) and
                                    ($oldInfo === null or ($oldInfo and
                                    (!$oldInfo->SubDirectory or $oldInfo->ReadFromRAF))))
                                {
                                    // copy huge data blocks later instead of loading into memory
                                    $oldValue = ''; // dummy empty value
                                    // copy this value later unless writing a new value
                                    if (!isset($set[$oldID])) {
                                        $pad = $oldSize & 0x01 ? 1 : 0;
                                        // save block information to copy later (set directory offset later)
                                        $oldImageData = [$base + $valuePtr + $dataPos, $oldSize, $pad];
                                    }
                                } elseif ($raf) {
                                    $success = ($raf->Seek($base + $valuePtr + $dataPos, 0) and
                                                $raf->Read($oldValue, $oldSize) == $oldSize);
                                    if ($pos !== null) {
                                        $raf->Seek($pos, 0);
                                        $raf = null;
                                        // (sony A700 has 32-byte header on PreviewImage)
                                        if (!($success and preg_match('/^(\xff\xd8\xff|(.|.{33})\xd8\xff\xdb)/s', Perl::str($oldValue)))) {
                                            $invalidPreview = 1;
                                            $success = 1;   // continue writing directory anyway
                                        }
                                    }
                                    if (!$success) {
                                        $wrn = Perl::sprintf("Error reading value for $name entry $index, ID 0x%.4x", $oldID);
                                        $truncOK = null;
                                        if ($oldInfo and !$oldInfo->Unknown) {
                                            $wrn .= " {$oldInfo->Name}";
                                            $truncOK = $oldInfo->TruncateOK;
                                        }
                                        if ($et->Error($wrn, $inMakerNotes ?: $truncOK)) return null;
                                        if (!$truncOK) {
                                            ++$index;  $oldID = $newID;  continue;  // drop this tag
                                        }
                                    }
                                } elseif (!$invalidPreview) {
                                    if ($et->Error("Bad $name offset for $tagStr", $inMakerNotes)) return null;
                                    ++$index;  $oldID = $newID;  continue;  // drop this tag
                                }
                                if ($invalidPreview) {
                                    // set value for invalid preview
                                    if ($et->FILE_TYPE === 'JPEG') {
                                        // define dummy value for preview (or Leica MakerNote) to write later
                                        // (value must be larger than 4 bytes to generate PREVIEW_INFO,
                                        // and an even number of bytes so it won't be padded)
                                        $oldValue = 'LOAD_PREVIEW';
                                    } else {
                                        $oldValue = 'none';
                                        $oldSize = strlen($oldValue);
                                    }
                                    $valuePtr = 0;
                                } else {
                                    self::UpdateTiffEnd($et, $base + $valuePtr + $dataPos + $oldSize);
                                }
                                // update pointers for value just read from file
                                $valueDataPt = Perl::str($oldValue);
                                $valueDataPos = $valuePtr + $dataPos;
                                $valueDataLen = $oldSize;
                                $valuePtr = 0;
                                $readFromFile = 1;
                            }
                            if ($suspect) {
                                $tagStr = $oldInfo ? $oldInfo->Name : Perl::sprintf('tag 0x%.4x', $oldID);
                                $str = "Suspicious $name offset for $tagStr";
                                if ($inMakerNotes) {
                                    $et->Warn($str, 1);
                                } else {
                                    if ($et->Error($str, 1)) return null;
                                }
                            }
                        }
                        // read value if we haven't already
                        if (!$readFromFile) $oldValue = substr($valueDataPt, $valuePtr, $oldSize);
                        // get tagInfo using value if necessary
                        if ($oldInfo !== null and !$oldInfo) {
                            $unk = $et->Options('Unknown', 1);
                            $oldInfo = $et->GetTagInfo($tagTablePtr, $oldID, $oldValue, $oldFormName, $oldCount);
                            $et->Options('Unknown', $unk);
                            // now that we have the value, we can resolve the Condition to finally
                            // determine whether we want to delete this tag or not
                            if (($mayDelete[$oldID] ?? null) and $oldInfo and (!$newTags or $newTags[0] != $oldID)) {
                                $nvHash = $et->GetNewValueHash($oldInfo, $dirName);
                                if (!$nvHash and $wrongDir) {
                                    // delete from wrong directory if necessary
                                    $nvHash = $et->GetNewValueHash($oldInfo, $wrongDir);
                                    if ($nvHash) $xDelete[$oldID] = 1;
                                }
                                if ($nvHash) {
                                    // we want to delete this tag after all, so insert it into our list
                                    $set[$oldID] = $oldInfo;
                                    array_unshift($newTags, $oldID);
                                }
                            }
                        }
                        // make sure we are handling the 'ifd' format properly
                        if (($oldFormat == 13 or $oldFormat == 18) and
                            (!$oldInfo or !$oldInfo->SubIFD))
                        {
                            $str = Perl::sprintf('%s tag 0x%.4x IFD format not handled', $name, $oldID);
                            $et->Error($str, $inMakerNotes);
                        }
                        // override format we use to read the value if specified
                        if ($oldInfo) {
                            // check for tags which must be integers
                            if (($oldInfo->IsOffset or $oldInfo->SubIFD) and
                                !(Vars::$intFormat[Perl::str($oldFormName)] ?? null))
                            {
                                $et->Error("Invalid format ($oldFormName) for $name {$oldInfo->Name}", $inMakerNotes);
                                ++$index;  $oldID = $newID;  continue;  // drop this tag
                            }
                            if ($oldInfo->Drop and $et->DropTags and
                                ($oldInfo->Drop == 1 or $oldInfo->Drop < $oldSize))
                            {
                                ++$index;  $oldID = $newID;  continue;  // drop this tag
                            }
                            if ($oldInfo->Format) {
                                $readFormName = $oldInfo->Format;
                                $readFormat = Vars::$formatNumber[Perl::str($readFormName)] ?? null;
                                if (!$readFormat) {
                                    // we aren't reading in a standard EXIF format, so rewrite in old format
                                    $readFormName = $oldFormName;
                                    $readFormat = $oldFormat;
                                }
                                if ($oldInfo->FixedSize) {
                                    if ($oldInfo->FixedSize) $oldSize = $oldInfo->FixedSize;
                                    $oldValue = substr($valueDataPt, $valuePtr, $oldSize);
                                    if ($readFromFile) $valueDataPt = $oldValue;   // (Perl: $valueDataPt = \$oldValue)
                                }
                                // adjust number of items to read if format size changed
                                $readCount = $oldSize / Vars::$formatSize[(int) $readFormat];
                            }
                        }
                        if ($oldID <= $lastTagID and !($inMakerNotes or $et->IsRawType())) {
                            $str = $oldInfo ? "{$oldInfo->Name} tag" : Perl::sprintf('tag 0x%x', $oldID);
                            if ($oldID == $lastTagID) {
                                $et->Warn("Duplicate $str in $name");
                                // put this tag back into the newTags list if necessary
                                if (isset($set[$oldID])) array_unshift($newTags, $oldID);
                            } else {
                                $et->Warn(ucfirst($str) . " out of sequence in $name");
                            }
                        }
                        $lastTagID = $oldID;
                        ++$index;               // increment index for next time
                    } else {
                        $oldID = null;          // no more existing entries
                    }
                }
                //
                // write out all new tags, up to and including this one
                //
                $newID = $newTags[0] ?? null;
                $isNew = null;  // -1=tag is old, 0=tag same as existing, 1=tag is new
                if ($oldID === null) {
                    if ($newID === null) break;
                    $isNew = 1;
                } elseif ($newID === null) {
                    // maker notes will have no new tags defined
                    if (isset($set[$oldID])) {
                        $newID = $oldID;
                        $isNew = 0;
                    } else {
                        $isNew = -1;
                    }
                } else {
                    $isNew = $oldID <=> $newID;
                    // special logic needed if directory has out-of-order entries
                    if ($unsorted and $isNew) {
                        if ($isNew > 0 and ($hasOldID[$newID] ?? null)) {
                            // we wanted to create the new tag, but an old tag
                            // does exist with this ID, so defer writing the new tag
                            $isNew = -1;
                        }
                        if ($isNew < 0 and ($hasNewID[$oldID] ?? null)) {
                            // we wanted to write the old tag, but we have
                            // a new tag with this ID, so move it up in the order
                            $tmpTags = [$oldID];
                            foreach ($newTags as $_) if ($_ != $oldID) $tmpTags[] = $_;
                            $newTags = $tmpTags;
                            $newID = $oldID;
                            $isNew = 0;
                        }
                    }
                }
                $newInfo = $oldInfo;
                $newFormat = $oldFormat;
                $newFormName = $oldFormName;
                $newCount = $oldCount;
                $ifdFormName = null;
                // (Perl: "my $newValue" is a new variable for each entry)
                unset($newValuePt, $newValue);
                $newValue = null;
                if ($isNew >= 0) {
                    $newValuePt = &$newValue;
                } else {
                    $newValuePt = &$oldValue;
                }
                $isOverwriting = null;

                if ($isNew >= 0) {
                    // add, edit or delete this tag
                    array_shift($newTags); // remove from list
                    $curInfo = $set[$newID] ?? null;
                    // don't allow MakerNotes to be added to ExifIFD of CR3 file
                    if ($newID == 0x927c and $isNew > 0 and $et->FileType === 'CR3') continue;
                    if (!($curInfo or ($addDirs[$newID] ?? null))) {
                        // we can finally get the specific tagInfo reference for this tag
                        // (because we can now evaluate the Condition statement since all
                        //  DataMember's have been obtained for tags up to this one)
                        $curInfo = $et->GetTagInfo($tagTablePtr, $newID);
                        if ($curInfo !== null and !$curInfo) {
                            // need value to evaluate the condition
                            // (tricky because we need the tagInfo ref to get the value,
                            //  so we must loop through all new tagInfo's...)
                            foreach ($newTagInfo as $tagInfo) {
                                if ($tagInfo->TagID != $newID) continue;
                                $val = $et->GetNewValue($tagInfo);
                                if ($val === null) { $mayDelete[$newID] = 1; continue; }
                                // must convert to binary for evaluating in Condition
                                $fmt = $tagInfo->Format ?: $tagInfo->Writable;
                                if ($fmt) {
                                    $val = ExifTool::WriteValue($val, $fmt, $tagInfo->Count);
                                    if ($val === null) { $mayDelete[$newID] = 1; continue; }
                                }
                                $curInfo = $et->GetTagInfo($tagTablePtr, $newID, $val, $oldFormName, $oldCount);
                                if ($curInfo) {
                                    if ($curInfo === $tagInfo) break;
                                    $curInfo = null;
                                }
                            }
                            // may want to delete this, but we need to see the old value first
                            if (!$curInfo) $mayDelete[$newID] = 1;
                        }
                        // don't set this tag unless valid for the current condition
                        if ($curInfo and ($et->NEW_VALUE[spl_object_id($curInfo)] ?? null)) {
                            $set[$newID] = $curInfo;
                        } else {
                            if ($isNew > 0) continue;
                            $isNew = -1;
                            $curInfo = null;
                        }
                    }
                    if ($curInfo) {
                        if ($curInfo->WriteCondition) {
                            $self = $et;   // set $self to be used in eval
                            //#### eval WriteCondition ($self)
                            $wc = $curInfo->WriteCondition;
                            $evalErr = null;
                            try {
                                $ok = $wc instanceof \Closure ? $wc($self) : $wc;
                            } catch (\Throwable $e) {
                                $ok = null;
                                $evalErr = $e->getMessage();
                            }
                            if (!$ok) {
                                if ($evalErr) ExifTool::PerlWarn($evalErr);
                                goto NoWrite;   // GOTO !
                            }
                        }
                        $nvHash = null;
                        if ($isNew >= 0) $nvHash = $et->GetNewValueHash($curInfo, $dirName);
                        if (!($nvHash or (isset($mandatory[$newID]) and !$noMandatory))) {
                            if (!$wrongDir) goto NoWrite;  // GOTO !
                            // delete stuff from the wrong directory if setting somewhere else
                            $nvHash = $et->GetNewValueHash($curInfo, $wrongDir);
                            // don't cross delete if not overwriting
                            if (!$et->IsOverwriting($nvHash)) goto NoWrite;    // GOTO !
                            // don't cross delete if specifically deleting from the other directory
                            // (Note: don't call GetValue() here because it shouldn't be called
                            //  if IsOverwriting returns < 0 -- eg. when shifting)
                            if ($nvHash->Value === null and $nvHash->WantGroup and
                                    strtolower(Perl::str($nvHash->WantGroup)) === strtolower($wrongDir))
                            {
                                goto NoWrite;   // GOTO !
                            } else {
                                // remove this tag if found in this IFD
                                $xDelete[$newID] = 1;
                            }
                        }
                    } elseif (!($addDirs[$newID] ?? null)) {
NoWrite:                if ($isNew > 0) continue;
                        unset($set[$newID]);
                        $isNew = -1;
                    }
                    if ($set[$newID] ?? null) {
                        //
                        // set the new tag value (or 'next' if deleting tag)
                        //
                        $newInfo = $set[$newID];
                        $newCount = $newInfo->Count;
                        $val = $newVal = $n = null;
                        $nvHash = $et->GetNewValueHash($newInfo, $dirName);
                        if ($isNew > 0) {
                            // don't create new entry unless requested
                            if ($nvHash) {
                                if (!$nvHash->IsCreating) continue;
                                if ($newInfo->IsOverwriting) {
                                    $proc = $newInfo->IsOverwriting;
                                    $isOverwriting = $proc($et, $nvHash, $val, $newVal);
                                } else {
                                    $isOverwriting = $et->IsOverwriting($nvHash);
                                }
                            } else {
                                if ($xDelete[$newID] ?? null) continue;       // don't create if cross deleting
                                $newVal = $mandatory[$newID] ?? null;  // get value for mandatory tag
                                $isOverwriting = 1;
                            }
                            // convert using new format
                            if ($newInfo->Format) {
                                $newFormName = $newInfo->Format;
                                // use Writable flag to specify IFD format code
                                $ifdFormName = $newInfo->Writable;
                            } else {
                                $newFormName = $newInfo->Writable;
                                if (!$newFormName) {
                                    ExifTool::PerlWarn("No format for $name {$newInfo->Name}\n");
                                    continue;
                                }
                            }
                            $newFormat = Vars::$formatNumber[Perl::str($newFormName)] ?? null;
                        } elseif ($nvHash or ($xDelete[$newID] ?? null)) {
                            if (!$nvHash) {
                                $nvHash = $et->GetNewValueHash($newInfo, $wrongDir);
                            }
                            // read value
                            if (strlen(Perl::str($oldValue)) >= $oldSize) {
                                $val = ExifTool::ReadValue($oldValue, 0, $readFormName, $readCount, $oldSize);
                            } else {
                                $val = '';
                            }
                            // determine write format (by default, use 'Writable' format)
                            $writable = $newInfo->Writable;
                            // (or use existing format if 'Writable' not specified)
                            if (!($writable and Perl::str($writable) !== '1')) $writable = $oldFormName;
                            // (and override write format with 'Format' if specified)
                            $writeForm = $newInfo->Format ?: $writable;
                            if (Perl::str($writeForm) !== Perl::str($newFormName)) {
                                // write in specified format
                                $newFormName = $writeForm;
                                $newFormat = Vars::$formatNumber[Perl::str($newFormName)] ?? null;
                                // use different IFD format code if necessary
                                if ($inMakerNotes) {
                                    // always preserve IFD format in maker notes
                                    $ifdFormName = $oldFormName;
                                } elseif (Perl::str($writable) !== Perl::str($newFormName)) {
                                    // use specified IFD format
                                    $ifdFormName = $writable;
                                }
                            }
                            if ($inMakerNotes and $readFormName !== 'string' and $readFormName !== 'undef') {
                                // keep same size in maker notes unless string or binary
                                $newCount = $oldCount * Vars::$formatSize[$oldFormat] / (Vars::$formatSize[(int) $newFormat] ?? null);
                            }
                            if ($newInfo->IsOverwriting) {
                                $proc = $newInfo->IsOverwriting;
                                $isOverwriting = $proc($et, $nvHash, $val, $newVal);
                            } else {
                                $isOverwriting = $et->IsOverwriting($nvHash, $val);
                            }
                        }
                        if ($isOverwriting) {
                            if ($newVal === null) $newVal = $et->GetNewValue($nvHash);
                            // value undefined if deleting this tag
                            // (also delete tag if cross-deleting and this isn't a date/time shift)
                            if ($newVal === null or (($xDelete[$newID] ?? null) and $nvHash?->Shift === null)) {
                                if ($newVal === null and $newInfo->RawConvInv and $nvHash->Value !== null) {
                                    // error in RawConvInv, so rewrite existing tag
                                    goto NoOverwrite; // GOTO!
                                }
                                if (!$isNew) {
                                    ++$et->CHANGED;
                                    $et->VerboseValue("- $dirName:{$newInfo->Name}", $val);
                                }
                                continue;
                            }
                            if ($newCount and $newCount < 0) {
                                // set count to number of values if variable
                                $vals = Perl::split(' ', $newVal);
                                $newCount = count($vals);
                            }
                            // convert to binary format
                            $newValue = ExifTool::WriteValue($newVal, $newFormName, $newCount);
                            if ($newValue === null) {
                                $et->Warn("Invalid value for $dirName:{$newInfo->Name}");
                                goto NoOverwrite; // GOTO!
                            }
                            if (strlen($newValue)) {
                                // limit maximum value length in JPEG images
                                // (max segment size is 65533 bytes and the min EXIF size is 96 incl an additional IFD entry)
                                if ($et->FILE_TYPE === 'JPEG' and strlen($newValue) > 65436 and
                                    $newInfo->Name !== 'PreviewImage')
                                {
                                    $tagName = $newInfo->MakerNotes ? 'MakerNotes' : $newInfo->Name;
                                    $et->Warn("Writing large value for $tagName", 1);
                                }
                                // re-code if necessary
                                if ($newFormName === 'utf8') {
                                    $newValue = $et->Encode($newValue, 'UTF8');
                                } elseif ($strEnc and $newFormName === 'string') {
                                    $newValue = $et->Encode($newValue, $strEnc);
                                }
                            } else {
                                $et->Warn("Can't write zero length {$newInfo->Name} in {$tagTablePtr['GROUPS'][1]}");
                                goto NoOverwrite; // GOTO!
                            }
                            if ($isNew >= 0) {
                                $newCount = strlen($newValue) / (Vars::$formatSize[(int) $newFormat] ?? null);
                                ++$et->CHANGED;
                                if ($allMandatory !== null) {
                                    // not all mandatory if we are writing any tag specifically
                                    if ($nvHash) {
                                        $allMandatory = null;
                                        $deleteAll = null;
                                    } else {
                                        ++$addMandatory;    // count mandatory tags that we added
                                    }
                                }
                                if ($verbose > 1) {
                                    if (!$isNew) $et->VerboseValue("- $dirName:{$newInfo->Name}", $val);
                                    if ($newInfo->OffsetPair and Perl::str($newVal) === '4277010157') { // (0xfeedfeed)
                                        fwrite($et->OPTIONS['TextOut'], "    + $dirName:{$newInfo->Name} = <tbd>\n");
                                    } else {
                                        $str = $nvHash ? '' : ' (mandatory)';
                                        $et->VerboseValue("+ $dirName:{$newInfo->Name}", $newVal, $str);
                                    }
                                }
                            }
                        } else {
NoOverwrite:                if ($isNew > 0) continue;
                            $isNew = -1;        // rewrite existing tag
                        }
                        // set format for EXIF IFD if different than conversion format
                        if ($ifdFormName) {
                            $newFormName = $ifdFormName;
                            $newFormat = Vars::$formatNumber[Perl::str($newFormName)] ?? null;
                        }

                    } elseif ($isNew > 0) {
                        //
                        // create new subdirectory
                        //
                        // newInfo may not be defined if we try to add a mandatory tag
                        // to a directory that doesn't support it (eg. IFD1 in RW2 images)
                        $newInfo = $addDirs[$newID] ?? null;
                        if (!$newInfo) continue;
                        // make sure we don't try to generate a new MakerNotes directory
                        // or a SubIFD
                        if ($newInfo->MakerNotes or $newInfo->Name === 'SubIFD') continue;
                        $subTable = null;
                        if ($newInfo->SubDirectory['TagTable'] ?? null) {
                            $subTable = ExifTool::GetTagTable($newInfo->SubDirectory['TagTable']);
                        } else {
                            $subTable = $tagTablePtr;
                        }
                        // create empty source directory
                        $sourceDir = [
                            'Parent' => $dirName,
                            'Fixup' => new Fixup(),
                        ];
                        if ($newInfo->SubIFD) $sourceDir['DirName'] = $newInfo->Groups[1] ?? null;
                        $newValue = $et->WriteDirectory($sourceDir, $subTable);
                        // only add new directory if it isn't empty
                        if (!($newValue !== null and strlen($newValue))) continue;
                        // set the fixup start location
                        if ($newInfo->SubIFD) {
                            // subdirectory is referenced by an offset in value buffer
                            $subdir = $newValue;
                            $newValue = ExifTool::Set32u(0xfeedf00d);
                            $subdirs[] = [
                                'DataPt' => $subdir,
                                'Table' => $subTable,
                                'Fixup' => $sourceDir['Fixup'] ?? null,
                                'Offset' => strlen($dirBuff) + 8,
                                'Where' => 'dirBuff',
                            ];
                            $newFormName = 'int32u';
                            $newFormat = Vars::$formatNumber[Perl::str($newFormName)];
                        } else {
                            // subdirectory goes directly into value buffer
                            $sourceDir['Fixup'] ??= new Fixup();
                            $sourceDir['Fixup']->Start += strlen($valBuff);
                            // use Writable to set format, otherwise 'undef'
                            $newFormName = $newInfo->Writable;
                            if (!($newFormName and (Vars::$formatNumber[Perl::str($newFormName)] ?? null))) {
                                $newFormName = 'undef';
                            }
                            $newFormat = Vars::$formatNumber[Perl::str($newFormName)];
                            $valFixups[] = $sourceDir['Fixup'];
                        }
                    } elseif ($newInfo instanceof TagInfo and $newInfo->Format and $newInfo->Writable and
                              Perl::str($newInfo->Writable) !== '1')
                    {
                        // use specified write format
                        $newFormName = $newInfo->Writable;
                        $newFormat = Vars::$formatNumber[Perl::str($newFormName)] ?? null;
                    } elseif (($addDirs[$newID] ?? null) and $newInfo !== $addDirs[$newID]) {
                        // this can happen if we are trying to add a directory that doesn't exist
                        // in this type of file (eg. try adding a SubIFD tag to an A100 image)
                        $isNew = -1;
                    }
                }
                if ($isNew < 0) {
                    // just rewrite existing tag
                    $newID = $oldID;
                    $newValue = $oldValue;
                    $newFormat = $oldFormat; // (just in case it changed)
                    $newFormName = $oldFormName;
                    // set offset of this entry in the directory so we can update the pointer
                    // and save block information to copy this large block later
                    if ($oldImageData) {
                        $oldImageData[3] = $newStart + strlen($dirBuff) + 2;
                        $imageData[] = $oldImageData;
                        $origDirInfo['ImageData'] = &$imageData;
                    }
                }
                if ($newInfo) {
                    //
                    // load necessary data for this tag (thumbnail image, etc)
                    //
                    if ($newInfo->DataTag and $isNew >= 0) {
                        $dataTag = $newInfo->DataTag;
                        // load data for this tag
                        if (!(isset($offsetData[$dataTag]) or $dataTag === 'LeicaTrailer')) {
                            // prefer tag from Composite table if it exists (otherwise
                            // PreviewImage data would be taken from Extra tag)
                            $compInfo = ExifTool::GetCompositeTagInfo($dataTag);
                            $offsetData[$dataTag] = $et->GetNewValue($compInfo ?: $dataTag);
                            $err = null;
                            if (isset($offsetData[$dataTag])) {
                                $len = strlen($offsetData[$dataTag]);
                                if ($dataTag === 'PreviewImage') {
                                    // must set DEL_PREVIEW flag now if preview fit into IFD
                                    if ($len <= 4) $et->DEL_PREVIEW = 1;
                                }
                            } else {
                                $err = "$dataTag not found";
                            }
                            if ($err) {
                                if ($newInfo->IsOffset) $et->Warn($err);
                                unset($set[$newID]);    // remove from list of tags we are setting
                                continue;
                            }
                        }
                    }
                    //
                    // write maker notes
                    //
                    if ($newInfo->MakerNotes) {
                        // don't write new makernotes if we are deleting this group
                        if (($et->DEL_GROUP['MakerNotes'] ?? null) and
                           ($et->DEL_GROUP['MakerNotes'] != 2 or $isNew <= 0))
                        {
                            if ($et->IsRawType() and !($et->IsRawType() == 2 and $dirName === 'ExifIFD')) {
                                $et->Warn("Can't delete MakerNotes from {$et->FileType}", 1);
                            } else {
                                if ($isNew <= 0) {
                                    ++$et->CHANGED;
                                    if ($verbose) fwrite($out, "  Deleting MakerNotes\n");
                                }
                                continue;
                            }
                        }
                        $saveOrder = ExifTool::GetByteOrder();
                        if ($isNew >= 0 and isset($set[$newID])) {
                            // we are writing a whole new maker note block
                            // --> add fixup information if necessary
                            $nvHash = $et->GetNewValueHash($newInfo, $dirName);
                            if ($nvHash and $nvHash->MAKER_NOTE_FIXUP) {
                                // must clone fixup because we will be shifting it
                                $makerFixup = $nvHash->MAKER_NOTE_FIXUP->Clone();
                                $valLen = strlen($valBuff);
                                $makerFixup->Start += $valLen;
                                $valFixups[] = $makerFixup;
                            }
                        } else {
                            // update maker notes if possible
                            $subdirInfo = [
                                'Base'     => $base,
                                'DataPt'   => $valueDataPt,
                                'DataPos'  => $valueDataPos,
                                'DataLen'  => $valueDataLen,
                                'DirStart' => $valuePtr,
                                'DirLen'   => $oldSize,
                                'DirName'  => 'MakerNotes',
                                'Name'     => $newInfo->Name,
                                'Parent'   => $dirName,
                                'TagInfo'  => $newInfo,
                                'RAF'      => $raf,
                            ];
                            $subTable = $subdir = $loc = $writeProc = $notIFD = null;
                            if ($newInfo->SubDirectory) {
                                $sub = $newInfo->SubDirectory;
                                if ($sub['FixBase'] ?? null) $subdirInfo['FixBase'] = 1;
                                $subdirInfo['FixOffsets'] = $sub['FixOffsets'] ?? null;
                                $subdirInfo['EntryBased'] = $sub['EntryBased'] ?? null;
                                if (isset($sub['Base'])) $subdirInfo['NoFixBase'] = 1;
                                $subdirInfo['AutoFix'] = $sub['AutoFix'] ?? null;
                                if ($sub['ByteOrder'] ?? null) ExifTool::SetByteOrder($sub['ByteOrder']);
                            }
                            // get the proper tag table for these maker notes
                            if ($oldInfo and $oldInfo->SubDirectory) {
                                $subTable = $oldInfo->SubDirectory['TagTable'] ?? null;
                                if ($subTable) $subTable = ExifTool::GetTagTable($subTable);
                                $writeProc = $oldInfo->SubDirectory['WriteProc'] ?? null;
                                $notIFD = $oldInfo->NotIFD;
                            } else {
                                $et->Warn('Internal problem getting maker notes tag table');
                            }
                            if ($subTable and !$writeProc) $writeProc = $subTable['WRITE_PROC'];
                            if (!$subTable) $subTable = $tagTablePtr;
                            if ($writeProc and
                                $writeProc === MakerNotes::class . '::WriteUnknownOrPreview' and
                                preg_match('/^\xff\xd8\xff/', Perl::str($oldValue)))
                            {
                                $loc = 0;
                            } elseif (!$notIFD) {
                                // look for IFD-style maker notes
                                $loc = MakerNotes::LocateIFD($et, $subdirInfo);
                            }
                            if ($loc !== null) {
                                // we need fixup data for this subdirectory
                                $subdirInfo['Fixup'] = new Fixup();
                                // rewrite maker notes
                                $changed = $et->CHANGED;
                                $subdir = $et->WriteDirectory($subdirInfo, $subTable, $writeProc);
                                if ($changed == $et->CHANGED and $subdirInfo['Fixup']->IsEmpty()) {
                                    // return original data if nothing changed and no fixups
                                    $subdir = null;
                                }
                            } elseif ($subTable['PROCESS_PROC'] and
                                     $subTable['PROCESS_PROC'] === ExifTool::class . '::ProcessBinaryData')
                            {
                                $sub = $oldInfo->SubDirectory;
                                if (isset($sub['Start'])) {
                                    //#### eval Start ($valuePtr)
                                    $start = Perl::num(self::EvalCode($sub['Start'], $valuePtr, null, $dirStart, $dataPt));
                                    $loc = $start - $valuePtr;
                                    $subdirInfo['DirStart'] = $start;
                                    $subdirInfo['DirLen'] -= $loc;
                                } else {
                                    $loc = 0;
                                }
                                // rewrite maker notes
                                $subdir = $et->WriteDirectory($subdirInfo, $subTable);
                            } elseif ($notIFD) {
                                if ($writeProc) {
                                    $loc = 0;
                                    $subdir = $et->WriteDirectory($subdirInfo, $subTable);
                                }
                            } else {
                                $msg = 'Maker notes could not be parsed';
                                if ($et->FILE_TYPE === 'JPEG') {
                                    $et->Warn($msg, 1);
                                } else {
                                    $et->Error($msg, 1);
                                }
                            }
                            if ($subdir !== null) {
                                if (!strlen($subdir)) {
                                    ExifTool::SetByteOrder($saveOrder);
                                    continue;
                                }
                                $valLen = strlen($valBuff);
                                // restore existing header and substitute the new
                                // maker notes for the old value
                                $newValue = substr(Perl::str($oldValue), 0, $loc) . $subdir;
                                $makerFixup = $subdirInfo['Fixup'] ?? new Fixup();  // (Perl autovivifies an empty one)
                                $previewInfo = $et->PREVIEW_INFO;
                                if ($subdirInfo['Relative'] ?? null) {
                                    // apply a one-time fixup to $loc since offsets are relative
                                    $makerFixup->Start += $loc;
                                    // shift all offsets to be relative to new base
                                    $baseShift = $valueDataPos + $valuePtr + $base - $subdirInfo['Base'];
                                    $makerFixup->Shift += $baseShift;
                                    $makerFixup->ApplyFixup($newValue);
                                    if ($previewInfo) {
                                        // remove all but PreviewImage fixup (since others shouldn't change)
                                        foreach (array_keys($makerFixup->Pointers ?? []) as $_) {
                                            if (!preg_match('/_PreviewImage$/', (string) $_)) unset($makerFixup->Pointers[$_]);
                                        }
                                        // zero pointer so we can see how it gets shifted later
                                        $makerFixup->SetMarkerPointers($newValue, 'PreviewImage', 0);
                                        // set the pointer to the start of the EXIF information
                                        // add preview image fixup to list of value fixups
                                        $makerFixup->Start += $valLen;
                                        $valFixups[] = $makerFixup;
                                        $et->PREVIEW_INFO['BaseShift'] = $baseShift;
                                        $et->PREVIEW_INFO['Relative'] = 1;
                                    }
                                // don't shift anything if relative flag set to zero (Pentax patch)
                                } elseif (!isset($subdirInfo['Relative'])) {
                                    // shift offset base if shifted in the original image or if FixBase
                                    // was used, but be careful of automatic FixBase with negative shifts
                                    // since they may lead to negative (invalid) offsets (casio_edit_problem.jpg)
                                    $baseShift = $base - $subdirInfo['Base'];
                                    if ($subdirInfo['AutoFix'] ?? null) {
                                        $baseShift = 0;
                                    } elseif (($subdirInfo['FixBase'] ?? null) and $baseShift < 0 and
                                        // allow negative base shift if offsets are bigger (PentaxOptioWP.jpg)
                                        (!($subdirInfo['MinOffset'] ?? null) or $subdirInfo['MinOffset'] + $baseShift < 0))
                                    {
                                        $fixBase = $et->Options('FixBase');
                                        if ($fixBase === null) {
                                            $str = $et->Options('IgnoreMinorErrors') ? 'ignored' : 'fix or ignore?';
                                            $et->Error("MakerNotes offsets may be incorrect ($str)", 1);
                                        } elseif (Perl::str($fixBase) === '') {
                                            $et->Warn('Fixed incorrect MakerNotes offsets');
                                            $baseShift = 0;
                                        }
                                    }
                                    $makerFixup->Start += $valLen + $loc;
                                    $makerFixup->Shift += $baseShift;
                                    // permanently fix makernote offset errors
                                    $makerFixup->Shift += ($subdirInfo['FixedBy'] ?? null) ?: 0;
                                    $valFixups[] = $makerFixup;
                                    if ($previewInfo and !($previewInfo['NoBaseShift'] ?? null)) {
                                        $et->PREVIEW_INFO['BaseShift'] = $baseShift;
                                    }
                                }
                                $newValuePt = &$newValue;   // write new value
                            }
                        }
                        ExifTool::SetByteOrder($saveOrder);

                    // process existing subdirectory unless we are overwriting it entirely
                    } elseif ($newInfo->SubDirectory and $isNew <= 0 and !$isOverwriting
                        // don't edit directory if Writable is set to 0
                        and ($newInfo->Writable === null or $newInfo->Writable) and
                        !$newInfo->ReadFromRAF)
                    {

                        $subdir = $newInfo->SubDirectory;
                        if ($newInfo->SubIFD) {
                            //
                            // rewrite existing sub IFD's
                            //
                            $subTable = $tagTablePtr;
                            if ($subdir['TagTable'] ?? null) {
                                $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                            }
                            // determine directory name for this IFD
                            $subdirName = ($newInfo->Groups[1] ?? null) ?: $newInfo->Name;
                            // all makernotes directory names must be 'MakerNotes'
                            if ($subTable['GROUPS'][0] === 'MakerNotes') $subdirName = 'MakerNotes';
                            // must handle sub-IFD's specially since the values
                            // are actually offsets to subdirectories
                            if (!$readCount) {   // can't have zero count
                                if ($et->Error("$name entry $index has zero count", 2)) return null;
                                continue;
                            }
                            $writeCount = 0;
                            $newValue = '';    // reset value because we regenerate it below
                            for ($i = 0; $i < $readCount; ++$i) {
                                $off = $i * Vars::$formatSize[(int) $readFormat];
                                $val = ExifTool::ReadValue($valueDataPt, $valuePtr + $off,
                                                           $readFormName, 1, $oldSize - $off);
                                $subdirStart = Perl::num($val) - $dataPos;
                                $subdirBase = $base;
                                $hdrLen = null;
                                if (isset($subdir['Start'])) {
                                    //#### eval Start ($val)
                                    $subNewStart = self::EvalCode($subdir['Start'], $valuePtr, $val, $dirStart, $dataPt);
                                    if (!ExifTool::IsInt($subNewStart)) {
                                        $et->Error("Bad subdirectory start for {$newInfo->Name}");
                                        continue;
                                    }
                                    $subNewStart -= $dataPos;
                                    $hdrLen = $subNewStart - $subdirStart;
                                    $subdirStart = $subNewStart;
                                }
                                if ($subdir['Base'] ?? null) {
                                    $start = $subdirStart + $dataPos;
                                    //#### eval Base ($start,$base)
                                    $subdirBase += Perl::num(self::EvalCode($subdir['Base'], $start, $base));
                                }
                                // add IFD number if more than one
                                if ($i) $subdirName = preg_replace('/\d*$/', (string) $i, $subdirName, 1);
                                $subdirInfo = [
                                    'Base'     => $subdirBase,
                                    'DataPt'   => $dataPt,
                                    'DataPos'  => $dataPos - $subdirBase + $base,
                                    'DataLen'  => $dataLen,
                                    'DirStart' => $subdirStart,
                                    'DirName'  => $subdirName,
                                    'Name'     => $newInfo->Name,
                                    'TagInfo'  => $newInfo,
                                    'Parent'   => $dirName,
                                    'Fixup'    => new Fixup(),
                                    'RAF'      => $raf,
                                    'Subdir'   => $subdir,
                                    // set ImageData only for 1st level SubIFD's
                                    'ImageData'=> $imageDataFlag === 'Main' ? 'SubIFD' : null,
                                ];
                                // pass on header pointer only for certain sub IFD's
                                if ($newInfo->SubIFD == 2) $subdirInfo['HeaderPtr'] = $dirInfo['HeaderPtr'] ?? null;
                                if ($subdir['RelativeBase'] ?? null) {
                                    // apply one-time fixup if offsets are relative (Sony IDC hack)
                                    unset($subdirInfo['Fixup'], $subdirInfo['ImageData']);
                                }
                                // is the subdirectory outside our current data?
                                if ($subdirStart < 0 or $subdirStart + 2 > $dataLen) {
                                    if ($raf) {
                                        // reset SubDirectory buffer (we will load it later)
                                        $buff = '';
                                        $subdirInfo['DataPt'] = $buff;
                                        $subdirInfo['DataLen'] = 0;
                                    } else {
                                        $err = ["Can't read $subdirName data", $inMakerNotes];
                                        if ($subTable['VARS'] and ($subTable['VARS']['MINOR_ERRORS'] ?? null)) {
                                            $et->Warn($err[0] . '. Ignored.');
                                        } elseif ($et->Error(...$err)) {
                                            return null;
                                        }
                                        continue 2; // next Entry: don't write this directory
                                    }
                                }
                                $subdirData = $et->WriteDirectory($subdirInfo, $subTable, $subdir['WriteProc'] ?? null);
                                if ($subdirData === null) {
                                    // WriteDirectory should have issued an error, but check just in case
                                    if (!($et->VALUE['Error'] ?? null)) $et->Error("Error writing $subdirName");
                                    return null;
                                }
                                // add back original header if necessary (eg. Ricoh GR)
                                if ($hdrLen and $hdrLen > 0 and $subdirStart <= $dataLen) {
                                    $subdirData = substr($dataPt, $subdirStart - $hdrLen, $hdrLen) . $subdirData;
                                    $subdirInfo['Fixup'] ??= new Fixup();   // (Perl autovivifies it)
                                    $subdirInfo['Fixup']->Start += $hdrLen;
                                }
                                if (!strlen($subdirData)) {
                                    if (!$inMakerNotes) continue;
                                    // don't delete MakerNote Sub-IFD's, write empty IFD instead
                                    $subdirData = str_repeat("\0", 6);
                                    // reset SubIFD ImageData and Fixup just to be safe
                                    unset($subdirInfo['ImageData'], $subdirInfo['Fixup']);
                                }
                                // handle data blocks that we will transfer later
                                if (is_array($subdirInfo['ImageData'] ?? null)) {
                                    array_push($imageData, ...$subdirInfo['ImageData']);
                                    $origDirInfo['ImageData'] = &$imageData;
                                }
                                // temporarily set value to subdirectory index
                                // (will set to actual offset later when we know what it is)
                                $newValue .= ExifTool::Set32u(0xfeedf00d);
                                $offset = $where = null;
                                if ($readCount > 1) {
                                    $offset = strlen($valBuff) + $i * 4;
                                    $where = 'valBuff';
                                } else {
                                    $offset = strlen($dirBuff) + 8;
                                    $where = 'dirBuff';
                                }
                                // add to list of subdirectories we will append later
                                $subdirs[] = [
                                    'DataPt'    => $subdirData,
                                    'Table'     => $subTable,
                                    'Fixup'     => $subdirInfo['Fixup'] ?? null,
                                    'Offset'    => $offset,
                                    'Where'     => $where,
                                    'ImageData' => $subdirInfo['ImageData'] ?? null,
                                ];
                                ++$writeCount;  // count number of subdirs written
                            }
                            if (!strlen($newValue)) continue;
                            // must change location of subdir offset if we deleted
                            // a directory and only one remains
                            if ($writeCount < $readCount and $writeCount == 1) {
                                $subdirs[count($subdirs) - 1]['Where'] = 'dirBuff';
                                $subdirs[count($subdirs) - 1]['Offset'] = strlen($dirBuff) + 8;
                            }
                            // set new format to int32u for IFD
                            $newFormName = $newInfo->FixFormat ?: 'int32u';
                            $newFormat = Vars::$formatNumber[Perl::str($newFormName)] ?? null;
                            $newValuePt = &$newValue;

                        } elseif ((!isset($subdir['Start']) or
                                  self::CodeMatches($subdir['Start'], '/\$valuePtr/')) and
                                  ($subdir['TagTable'] ?? null))
                        {
                            //
                            // rewrite other existing subdirectories ('$valuePtr' type only)
                            //
                            // set subdirectory Start and Base
                            $subdirStart = $valuePtr;
                            if ($subdir['Start'] ?? null) {
                                //#### eval Start ($valuePtr)
                                $subdirStart = Perl::num(self::EvalCode($subdir['Start'], $valuePtr, null, $dirStart, $dataPt));
                                // must adjust directory size if start changed
                                $oldSize -= $subdirStart - $valuePtr;
                            }
                            $subdirBase = $base;
                            if ($subdir['Base'] ?? null) {
                                $start = $subdirStart + $valueDataPos;
                                //#### eval Base ($start,$base)
                                $subdirBase += Perl::num(self::EvalCode($subdir['Base'], $start, $base));
                            }
                            $subFixup = new Fixup();
                            $subdirInfo = [
                                'Base'     => $subdirBase,
                                'DataPt'   => $valueDataPt,
                                'DataPos'  => $valueDataPos - $subdirBase + $base,
                                'DataLen'  => $valueDataLen,
                                'DirStart' => $subdirStart,
                                'DirName'  => $subdir['DirName'] ?? null,
                                'DirLen'   => $oldSize,
                                'Parent'   => $dirName,
                                'Fixup'    => $subFixup,
                                'RAF'      => $raf,
                                'TagInfo'  => $newInfo,
                            ];
                            if (!$oldSize) {
                                // replace with dummy data if empty to prevent WriteDirectory
                                // routines from accessing data they shouldn't
                                $tmp = '';
                                $subdirInfo['DataPt'] = $tmp;
                                $subdirInfo['DataLen'] = 0;
                                $subdirInfo['DirStart'] = 0;
                                $subdirInfo['DataPos'] += $subdirStart;
                            }
                            $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                            $oldOrder = ExifTool::GetByteOrder();
                            if ($subdir['ByteOrder'] ?? null) ExifTool::SetByteOrder($subdir['ByteOrder']);
                            $newValue = $et->WriteDirectory($subdirInfo, $subTable, $subdir['WriteProc'] ?? null);
                            ExifTool::SetByteOrder($oldOrder);
                            if ($newValue !== null) {
                                $hdrLen = $subdirStart - $valuePtr;
                                if ($hdrLen) {
                                    $newValue = substr($valueDataPt, $valuePtr, $hdrLen) . $newValue;
                                    $subFixup->Start += $hdrLen;
                                }
                                $newValuePt = &$newValue;
                            } else {
                                $newValuePt = &$oldValue;
                            }
                            if (!strlen(Perl::str($newValuePt))) {
                                // don't delete a previously empty makernote directory
                                if ($oldSize or !$inMakerNotes) continue;
                            }
                            if ($subFixup->Pointers and $subdirInfo['Base'] == $base) {
                                $subFixup->Start += strlen($valBuff);
                                $valFixups[] = $subFixup;
                            } else {
                                // apply fixup in case we added a header ($hdrLen above)
                                $subFixup->ApplyFixup($newValue);
                            }
                        }

                    } elseif ($newInfo->OffsetPair) {
                        //
                        // keep track of offsets
                        //
                        $dataTag = $newInfo->DataTag ?: '';
                        if ($dataTag === 'CanonVRD') {
                            // must decide now if we will write CanonVRD information
                            $hasVRD = null;
                            $vrdInfo = ExifTool::PackageTable('Image::ExifTool::Extra')['CanonVRD'];
                            if ($vrdInfo instanceof TagInfo and ($et->NEW_VALUE[spl_object_id($vrdInfo)] ?? null)) {
                                // adding or deleting as a block
                                $hasVRD = $et->GetNewValue('CanonVRD') ? 1 : 0;
                            } elseif (($et->DEL_GROUP['CanonVRD'] ?? null) or
                                     ($et->DEL_GROUP['Trailer'] ?? null))
                            {
                                $hasVRD = 0;    // deleting as a group
                            } else {
                                $hasVRD = (Perl::str($newValuePt) !== "\0\0\0\0");
                            }
                            if ($hasVRD) {
                                // add a fixup, and set this offset later
                                $dirFixup->AddFixup(strlen($dirBuff) + 8, $dataTag);
                            } else {
                                // there is (or will soon be) no VRD information, so set pointer to zero
                                $newValue = str_repeat("\0", strlen(Perl::str($newValuePt)));
                                $newValuePt = &$newValue;
                            }
                        } elseif ($dataTag === 'OriginalDecisionData') {
                            // handle Canon OriginalDecisionData (no associated length tag)
                            // - I'm going out of my way here to preserve data which is
                            //   invalidated anyway by our edits
                            $odd = null;
                            $oddInfo = ExifTool::GetCompositeTagInfo('OriginalDecisionData');
                            if ($oddInfo and ($et->NEW_VALUE[spl_object_id($oddInfo)] ?? null)) {
                                $odd = $et->GetNewValue($dataTag);
                                if ($verbose > 1) {
                                    if (Perl::str($newValuePt) !== "\0\0\0\0") fwrite($out, "    - $dirName:$dataTag\n");
                                    if ($odd) fwrite($out, "    + $dirName:$dataTag\n");
                                }
                                ++$et->CHANGED;
                            } elseif (Perl::str($newValuePt) !== "\0\0\0\0") {
                                if (strlen(Perl::str($newValuePt)) == 4) {
                                    ExifTool::RequireModule('Image::ExifTool::Canon');
                                    $offset = ExifTool::Get32u($newValuePt, 0);
                                    // absolute offset in JPEG images only
                                    if ($et->FILE_TYPE !== 'JPEG') $offset += $base;
                                    $odd = Canon::ReadODD($et, $offset);
                                    if ($odd instanceof ScalarRef) $odd = $odd->value;
                                } else {
                                    $et->Error("Invalid {$newInfo->Name}", 1);
                                }
                            }
                            if ($odd) {
                                $newOffset = strlen($valBuff);
                                // (ODD offset is absolute in JPEG, so add base offset!)
                                if ($et->FILE_TYPE === 'JPEG') $newOffset += $base;
                                $newValue = ExifTool::Set32u($newOffset);
                                $dirFixup->AddFixup(strlen($dirBuff) + 8, $dataTag);
                                $valBuff .= $odd;   // add original decision data
                            } else {
                                $newValue = "\0\0\0\0";
                            }
                            $newValuePt = &$newValue;
                        } else {
                            // (Perl: my $offsetInfo = $offsetInfo[$ifd])
                            // save original values (for updating TIFF_END later)
                            $vals = [];
                            if ($isNew <= 0) {
                                $oldOrder = ExifTool::GetByteOrder();
                                // Minolta A200 stores these in the wrong byte order!
                                if ($newInfo->ByteOrder) ExifTool::SetByteOrder($newInfo->ByteOrder);
                                $vals = ExifTool::ReadValueList($oldValue, 0, $readFormName, $readCount, $oldSize);
                                ExifTool::SetByteOrder($oldOrder);
                                if (!$newInfo->IsOffset) $validateInfo[$newID] = [$newInfo, implode(' ', array_map(Perl::str(...), $vals))];
                            }
                            // only support int32 pointers (for now)
                            if ((Vars::$formatSize[(int) $newFormat] ?? null) != 4 and $newInfo->IsOffset) {
                                if ($isNew > 0) { ExifTool::PerlWarn('Internal error (Offset not int32)'); return null; }
                                if ($newCount != $readCount) { ExifTool::PerlWarn('Wrong count!'); return null; }
                                // change to int32
                                $newFormName = 'int32u';
                                $newFormat = Vars::$formatNumber[Perl::str($newFormName)];
                                $newValue = ExifTool::WriteValue(implode(' ', array_map(Perl::str(...), $vals)), $newFormName, $newCount);
                                if ($newValue === null) {
                                    ExifTool::PerlWarn("Internal error writing offsets for {$newInfo->Name}\n");
                                    return null;
                                }
                                $newValuePt = &$newValue;
                            }
                            // save location of valuePtr in new directory
                            // (notice we add 10 instead of 8 for valuePtr because
                            // we will put a 2-byte count at start of directory later)
                            $ptr = $newStart + strlen($dirBuff) + 10;
                            if (!$newCount) $newCount = 1; // make sure count is set for offsetInfo
                            // save value pointer and value count for each tag
                            $offsetInfoList[$ifd][$newID] = [$newInfo, $ptr, $newCount, $vals, $newFormat];
                        }

                    } elseif ($newInfo->DataMember) {

                        // save any necessary data members (Make, Model, etc)
                        $formatStr = $newFormName;
                        $count = $newCount;
                        // change to specified format if necessary
                        if ($newInfo->Format and $newInfo->Format !== $formatStr) {
                            $formatStr = $newInfo->Format;
                            $format = Vars::$formatNumber[Perl::str($formatStr)] ?? null;
                            // adjust number of items for new format size
                            if ($format) $count = Perl::int(strlen(Perl::str($newValuePt)) / Vars::$formatSize[$format]);
                        }
                        $val = ExifTool::ReadValue($newValuePt, 0, $formatStr, $count, strlen(Perl::str($newValuePt)));
                        $conv = $newInfo->RawConv;
                        if ($conv) {
                            // let the RawConv store the (possibly converted) data member
                            if (self::IsCodeConv($conv)) {
                                $conv($val, $et);
                            } else {
                                $priority = null;
                                $grps = [];
                                [$self, $tag, $tagInfo] = [$et, $newInfo->Name, $newInfo];
                                //#### eval RawConv ($self, $val, $tag, $tagInfo, $priority, @grps)
                                try {
                                    $conv($val, $self, $tag, $tagInfo, $priority, $grps, []);
                                } catch (\Throwable) {
                                }
                            }
                        } else {
                            $et->{$newInfo->DataMember} = $val;
                        }
                    }
                }
                //
                // write out the directory entry
                //
                $newSize = strlen(Perl::str($newValuePt));
                $fsize = Vars::$formatSize[(int) $newFormat] ?? null;
                $offsetVal = null;
                // set proper count
                if (!($oldInfo and $oldInfo->FixedSize)) $newCount = Perl::int(($newSize + $fsize - 1) / $fsize);
                if ((Vars::$saveForValidate[$newID] ?? null) and $tagTablePtr === ExifTool::GetTagTable('Image::ExifTool::Exif::Main')) {
                    $vals = ExifTool::ReadValueList($newValue, 0, $newFormName, $newCount, $newSize);
                    $validateInfo[$newID] = implode(' ', array_map(Perl::str(...), $vals));
                }
                if ($newSize > 4) {
                    // zero-pad to an even number of bytes (required by EXIF standard)
                    // and make sure we are a multiple of the format size
                    while ($newSize & 0x01 or $newSize < $newCount * $fsize) {
                        $newValuePt .= "\0";
                        ++$newSize;
                    }
                    $entryBased = null;
                    if (($dirInfo['EntryBased'] ?? null) or ($newInfo and $newInfo->EntryBased)) {
                        $entryBased = 1;
                        $offsetVal = ExifTool::Set32u(strlen($valBuff) - strlen($dirBuff));
                    } else {
                        $offsetVal = ExifTool::Set32u(strlen($valBuff));
                    }
                    $dataTag = $putFirst = null;
                    if ($newInfo) [$dataTag, $putFirst] = [$newInfo->DataTag, $newInfo->PutFirst];
                    if ($dataTag) {
                        if ($dataTag === 'PreviewImage' and ($et->FILE_TYPE === 'JPEG' or
                            $et->GENERATE_PREVIEW_INFO))
                        {
                            // hold onto the PreviewImage until we can determine if it fits
                            if (!$et->PREVIEW_INFO) $et->PREVIEW_INFO = [
                                'Data' => $newValuePt,
                                'Fixup' => new Fixup(),
                            ];
                            if ($newInfo->ChangeBase) $et->PREVIEW_INFO['ChangeBase'] = 1;
                            if ($newInfo->IsOffset and Perl::str($newInfo->IsOffset) === '2') {
                                $et->PREVIEW_INFO['NoBaseShift'] = 1;
                            }
                            // use original preview size if we will attempt to load it later
                            if ($newValuePt === 'LOAD_PREVIEW') $newCount = $oldCount;
                            $newValuePt = '';
                        } elseif ($dataTag === 'LeicaTrailer' and $et->LeicaTrailer) {
                            $newValuePt = '';
                        }
                    }
                    if ($putFirst and ($dirInfo['HeaderPtr'] ?? null)) {
                        $hdrPtr = $dirInfo['HeaderPtr'];
                        // place this value immediately after the TIFF header (eg. IIQ maker notes)
                        $offsetVal = ExifTool::Set32u(strlen($hdrPtr->value));
                        $hdrPtr->value .= $newValuePt;
                    } else {
                        $valBuff .= $newValuePt;       // add value data to buffer
                        // must save a fixup pointer for every pointer in the directory
                        if ($entryBased) {
                            if (!$entryBasedFixup) $entryBasedFixup = new Fixup();
                            $entryBasedFixup->AddFixup(strlen($dirBuff) + 8, $dataTag);
                        } else {
                            $dirFixup->AddFixup(strlen($dirBuff) + 8, $dataTag);
                        }
                    }
                } else {
                    $offsetVal = Perl::str($newValuePt);    // save value in offset if 4 bytes or less
                    // must pad value with zeros if less than 4 bytes
                    if ($newSize < 4) $offsetVal .= str_repeat("\0", 4 - $newSize);
                }
                // write the directory entry
                $dirBuff .= ExifTool::Set16u($newID) . ExifTool::Set16u($newFormat) .
                            ExifTool::Set32u($newCount) . $offsetVal;
                // update flag to keep track of mandatory tags
                while ($allMandatory !== null) {
                    if (isset($mandatory[$newID])) {
                        // values must correspond to mandatory values
                        $form = ($newInfo instanceof TagInfo ? $newInfo->Format : null) ?: $newFormName;
                        $mandVal = ExifTool::WriteValue($mandatory[$newID], $form, $newCount);
                        if ($mandVal !== null and $mandVal === Perl::str($newValuePt)) {
                            ++$allMandatory;        // count mandatory tags
                            break;
                        }
                    }
                    $deleteAll = null;
                    $allMandatory = null;
                }
            }
            unset($newValuePt);
            if ($validateInfo) {
                self::ValidateImageData($et, $validateInfo, $dirName, 1);
                $validateInfo = [];
            }
            if ($ignoreCount) {
                $y = $ignoreCount > 1 ? 'ies' : 'y';
                $verb = ($dirInfo['FixBase'] ?? null) ? 'Ignored' : 'Removed';
                $et->Warn("$verb $ignoreCount invalid entr$y from $name", 1);
            }
            if ($fixCount) {
                $s = $fixCount > 1 ? 's' : '';
                $et->Warn("Fixed invalid count$s for $fixCount $name tag$s", 1);
            }
            //..............................................................................
            // write directory counts and nextIFD pointer and add value data to end of IFD
            //
            // determine now if there is or will be another IFD after this one
            $nextIfdOffset = null;
            if ($dirEnd + 4 <= $dataLen) {
                $nextIfdOffset = ExifTool::Get32u($dataPt, $dirEnd);
            } else {
                $nextIfdOffset = 0;
            }
            $isNextIFD = (($dirInfo['Multi'] ?? null) and ($nextIfdOffset or
                            // account for the case where we will create the next IFD
                            // (IFD1 only, but not in TIFF-format images)
                            ($dirName === 'IFD0' and ($et->ADD_DIRS['IFD1'] ?? null) and
                             $et->FILE_TYPE !== 'TIFF')));
            // calculate number of entries in new directory
            $newEntries = intdiv(strlen($dirBuff), 12);
            // delete entire directory if we deleted a tag and only mandatory tags remain or we
            // attempted to create a directory with only mandatory tags and there is no nextIFD
            if ($allMandatory and !$isNextIFD and ($newEntries < $numEntries or $numEntries == 0)) {
                $newEntries = 0;
                $dirBuff = '';
                $valBuff = '';
                $dirFixup = null;   // no fixups in this directory
                if ($deleteAll !== null) ++$deleteAll;
                if ($verbose > 1) fwrite($out, "    - $allMandatory mandatory tag(s)\n");
                $et->CHANGED -= $addMandatory;    // didn't change these after all
            }
            if ($ifd and !$newEntries) {
                if ($verbose) fwrite($out, "  Deleting IFD1\n");
                break;   // don't write IFD1 if empty
            }
            // apply one-time fixup for entry-based offsets
            if ($entryBasedFixup) {
                $entryBasedFixup->Shift = strlen($dirBuff) + 4;
                $entryBasedFixup->ApplyFixup($dirBuff);
                $entryBasedFixup = null;
            }
            // initialize next IFD pointer to zero
            $nextIFD = ExifTool::Set32u(0);
            // some cameras use a different amount of padding after the makernote IFD
            if ($dirName === 'MakerNotes' and preg_match('/^(ExifIFD|IFD0)$/', Perl::str($dirInfo['Parent'] ?? null))) {
                $mno = MakerNotes::GetMakerNoteOffset($et);
                [$rel, $pad] = [$mno[0], $mno[1] ?? null];
                if ($pad !== null and ($pad == 0 or ($pad > 4 and $pad <= 32))) $nextIFD = str_repeat("\0", $pad);
            }
            // add directory entry count to start of IFD and next IFD pointer to end
            $newData .= ExifTool::Set16u($newEntries) . $dirBuff . $nextIFD;
            // get position of value data in newData
            $valPos = strlen($newData);
            // go back now and set next IFD pointer if this isn't the first IFD
            if ($nextIfdPos) {
                // set offset to next IFD
                ExifTool::Set32u($newStart, $newData, $nextIfdPos);
                $fixup->AddFixup($nextIfdPos, 'NextIFD');    // add fixup for this offset in newData
            }
            // remember position of 'next IFD' pointer so we can set it next time around
            $nextIfdPos = strlen($nextIFD) ? $valPos - strlen($nextIFD) : null;
            // add value data after IFD
            $newData .= $valBuff;
            //
            // add any subdirectories, adding fixup information
            //
            if ($subdirs) {
                foreach ($subdirs as $subdir) {
                    $len = strlen($newData);         // position of subdirectory in data
                    $subdirFixup = $subdir['Fixup'];
                    if ($subdirFixup) {
                        $subdirFixup->Start += $len;
                        $fixup->AddFixup($subdirFixup);
                    }
                    $subImageData = $subdir['ImageData'] ?? null;   // (Perl my $imageData)
                    $blockSize = 0;
                    // must also update start position for ImageData fixups
                    if (is_array($subImageData)) {
                        foreach ($subImageData as $blockInfo) {
                            [$pos, $size, $pad, $entry, $subFix] = $blockInfo + [null, null, null, null, null];
                            if ($subFix) {
                                $subFix->Start += $len;
                                // save expected image data offset for calculating shift later
                                $subFix->BlockLen = strlen($subdir['DataPt']) + $blockSize;
                            }
                            $blockSize += $size + $pad;
                        }
                    }
                    $newData .= $subdir['DataPt'];    // add subdirectory to our data
                    // set the pointer
                    $offset = $subdir['Offset'];
                    // if offset is in valBuff, it was added to the end of dirBuff
                    // (plus 4 bytes for nextIFD pointer)
                    if ($subdir['Where'] === 'valBuff') $offset += strlen($dirBuff) + 4;
                    $offset += $newStart + 2;           // get offset in newData
                    // check to be sure we got the right offset
                    if (ExifTool::Get32u($newData, $offset) != 0xfeedf00d) {
                        $et->Error("Internal error while rewriting $name");
                        return null;
                    }
                    // set the offset to the subdirectory data
                    ExifTool::Set32u($len, $newData, $offset);
                    $fixup->AddFixup($offset);  // add fixup for this offset in newData
                }
            }
            // add fixup for all offsets in directory according to value data position
            // (which is at the end of this directory)
            if ($dirFixup) {
                $dirFixup->Start = $newStart + 2;
                $dirFixup->Shift = $valPos - $dirFixup->Start;
                $fixup->AddFixup($dirFixup);
            }
            // add valueData fixups, adjusting for position of value data
            foreach ($valFixups as $valFixup) {
                $valFixup->Start += $valPos;
                $fixup->AddFixup($valFixup);
            }
            // stop if no next IFD pointer
            if (!$isNextIFD) break;   // stop unless scanning for multiple IFD's
            if ($nextIfdOffset) {
                // continue with next IFD
                $dirStart = $nextIfdOffset - $dataPos;
            } else {
                // create IFD1 if necessary
                if ($verbose) fwrite($out, "  Creating IFD1\n");
                $ifd1 = str_repeat("\0", 2);  // empty IFD1 data (zero entry count)
                $dataPt = $ifd1;
                $dirStart = 0;
                $dirLen = $dataLen = 2;
            }
            // increment IFD name
            $ifdNum = 0;
            if (preg_match('/(\d+)$/', $dirName, $m)) {
                $ifdNum = $m[1];
                $dirName = substr($dirName, 0, -strlen($m[1]));
            }
            $dirName .= $ifdNum + 1;
            $name = preg_replace('/\d+$/', '', $name, 1);
            $name .= $ifdNum + 1;
            $et->DIR_NAME = $et->PATH[array_key_last($et->PATH)] = $dirName;
            if (!$nextIfdOffset) continue;

            // guard against writing the same directory twice
            $addr = $nextIfdOffset + $base;
            if ($et->PROCESSED[$addr] ?? null) {
                $et->Error("$name pointer references previous {$et->PROCESSED[$addr]} directory", 1);
                break;
            }
            $et->PROCESSED[$addr] = $name;

            if ($dirName === 'SubIFD1' and !self::ValidateIFD($dirInfo, $dirStart)) {
                if ($et->TIFF_TYPE === 'TIFF') {
                    $et->Error('Ignored bad IFD linked from SubIFD', 1);
                } elseif ($verbose) {
                    $et->Warn('Ignored bad IFD linked from SubIFD');
                }
                break;   // don't write bad IFD
            }
            if ($et->DEL_GROUP[$dirName] ?? null) {
                if ($verbose) fwrite($out, "  Deleting $dirName\n");
                if ($raf) $et->Error("Deleting $dirName also deletes subsequent" .
                                     " IFD's and possibly image data", 1);
                ++$et->CHANGED;
                if ($et->DEL_GROUP[$dirName] == 2 and
                    ($et->ADD_DIRS[$dirName] ?? null))
                {
                    $emptyIFD = str_repeat("\0", 2);    // start with empty IFD
                    $dataPt = $emptyIFD;
                    $dirStart = 0;
                    $dirLen = $dataLen = 2;
                } else {
                    break;   // don't write this IFD (or any subsequent IFD)
                }
            } else {
                if ($verbose) fwrite($out, "  Rewriting $name\n");
            }
        }
        //~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

        // do our fixups now so we can more easily calculate offsets below
        $fixup->ApplyFixup($newData);
        // write Sony HiddenData now if this is an ARW file
        if ($et->HiddenData and !($dirInfo['Fixup'] ?? null) and $et->FILE_TYPE === 'TIFF') {
            $fixup->SetMarkerPointers($newData, 'HiddenData', strlen($newData));
            $hbuf = null;
            $hd = $et->HiddenData;
            if ($raf->Seek($hd['Offset'], 0) and $raf->Read($hbuf, $hd['Size']) == $hd['Size'] and
                preg_match('/^\x55\x26\x11\x05\0/', $hbuf))
            {
                $newData .= $hbuf;
            } else {
                $et->Error('Error copying hidden data', 1);
            }
        }
        //
        // determine total block size for deferred data
        //
        $numBlocks = count($imageData);  // save this so we scan only existing blocks later
        $blockSize = 0;  // total size of blocks to copy later
        foreach ($imageData as $blockInfo) {
            [$pos, $size, $pad] = $blockInfo;
            $blockSize += $size + $pad;
        }
        //
        // copy over image data for IFD's, starting with the last IFD first
        //
        if ($offsetInfoList) {
            $ttwLen = null;     // length of MRW TTW segment
            $writeLater = []; // write image data last
            for ($ifd = max(array_keys($offsetInfoList)); $ifd >= -1; --$ifd) {
                // build list of offsets to process
                $offsetList = [];
                if ($ifd >= 0) {
                    $dirName = ($dirInfo['DirName'] ?? null) ?: 'unknown';
                    if ($ifd) {
                        $dirName = preg_replace('/\d+$/', '', $dirName, 1);
                        $dirName .= $ifd;
                    }
                    $offsetInfo = $offsetInfoList[$ifd] ?? null;
                    if (!$offsetInfo) continue;
                    if (($offsetInfo[0x111] ?? null) and ($offsetInfo[0x144] ?? null)) {
                        // SubIFD may contain double-referenced data as both strips and tiles
                        // for Sony ARW files when SonyRawFileType is "Lossless Compressed RAW 2"
                        if ($dirName === 'SubIFD' and $et->TIFF_TYPE === 'ARW' and
                            ($offsetInfo[0x117] ?? null) and ($offsetInfo[0x145] ?? null) and
                            $offsetInfo[0x111][2] == 1) // (must be a single strip or the tile offsets could get out of sync)
                        {
                            // check the start offsets to see if they are the same
                            if (($offsetInfo[0x111][3][0] ?? null) == ($offsetInfo[0x144][3][0] ?? null)) {
                                // some Sony ARW images contain double-referenced raw data stored as both strips
                                // and tiles.  Copy the data using only the strip tags, but store the TileOffets
                                // information for updating later (see PanasonicRaw:PatchRawDataOffset for a
                                // description of offsetInfo elements)
                                $offsetInfo[0x111][5] = $offsetInfo[0x144]; // hack to save TileOffsets
                                // delete tile information from offsetInfo because we will copy as strips
                                unset($offsetInfo[0x144], $offsetInfo[0x145]);
                            }
                        } else {
                            $et->Error("TIFF $dirName contains both strip and tile data");
                        }
                    }
                    // patch Panasonic RAW/RW2 StripOffsets/StripByteCounts if necessary
                    $stripOffsets = $offsetInfo[0x111] ?? null;
                    $rawDataOffset = $offsetInfo[0x118] ?? null;
                    if ($stripOffsets and $stripOffsets[0]->PanasonicHack or
                        $rawDataOffset and $rawDataOffset[0]->PanasonicHack)
                    {
                        ExifTool::RequireModule('Image::ExifTool::PanasonicRaw');
                        if (method_exists(PanasonicRaw::class, 'PatchRawDataOffset')) {
                            $err = PanasonicRaw::PatchRawDataOffset($offsetInfo, $raf, $ifd);
                        } else {
                            $err = 'Not ported: Image\ExifTool\PanasonicRaw::PatchRawDataOffset';
                        }
                        if ($err) $et->Error($err);
                    }
                    // loop through all tags in reverse numerical order so we save thumbnail
                    // data before main image data if both exist in the same IFD
                    $tagIDs = array_keys($offsetInfo);
                    rsort($tagIDs, SORT_NUMERIC);
                    foreach ($tagIDs as $tagID) {
                        $tagInfo = $offsetInfo[$tagID][0];
                        if (!$tagInfo->IsOffset) continue; // handle byte counts with offsets
                        $sizeInfo = $offsetInfo[Perl::str($tagInfo->OffsetPair)] ?? null;
                        if (!$sizeInfo) { $et->Error("No size tag for $dirName:{$tagInfo->Name}"); continue; }
                        $dataTag = $tagInfo->DataTag;
                        // write TIFF image data (strips or tiles) later if requested
                        if ($raf and isset($origDirInfo['ImageData']) and
                            ($tagID == 0x111 or $tagID == 0x144 or
                              // also defer writing of other big data such as JpgFromRaw in NEF
                              (($sizeInfo[3][0] ?? null) and
                               // (calculate approximate combined size of all blocks)
                               $sizeInfo[3][0] * count($sizeInfo[3]) > 1000000)) and
                            // but don't defer writing if replacing with new value
                            ($dataTag === null or !isset($offsetData[$dataTag])))
                        {
                            $writeLater[] = [$offsetInfo[$tagID], $sizeInfo];
                        } else {
                            $offsetList[] = [$offsetInfo[$tagID], $sizeInfo];
                        }
                    }
                } else {
                    if (!$writeLater) break;
                    // finally, copy all deferred data
                    $offsetList = $writeLater;
                }
                foreach ($offsetList as $offsetPair) {
                    [$tagInfo, $offsets, $count, $oldOffset] = $offsetPair[0];
                    [$cntInfo, $byteCounts, $count2, $oldSize, $format] = $offsetPair[1];
                    // must be the same number of offset and byte count values
                    if ($count != $count2) {
                        $et->Error("Offsets/ByteCounts disagree on count for {$tagInfo->Name}");
                        return null;
                    }
                    $formatStr = Vars::$formatName[$format];
                    // follow pointer to value data if necessary
                    if ($count > 1) $offsets = ExifTool::Get32u($newData, $offsets);
                    $n = $count * Vars::$formatSize[$format];
                    if ($n > 4) $byteCounts = ExifTool::Get32u($newData, $byteCounts);
                    if ($byteCounts < 0 or $byteCounts + $n > strlen($newData)) {
                        $et->Error("Error reading {$tagInfo->Name} byte counts");
                        return null;
                    }
                    // get offset base and data pos (abnormal for some preview images)
                    $dbase = $dpos = $wrongBase = $subIfdDataFixup = null;
                    if (Perl::str($tagInfo->IsOffset) === '2') {
                        $dbase = $firstBase;
                        $dpos = $dataPos + $base - $firstBase;
                    } else {
                        $dbase = $base;
                        $dpos = $dataPos;
                    }
                    // use different base if necessary for some offsets (Minolta A200)
                    if ($tagInfo->WrongBase) {
                        $self = $et;
                        //#### eval WrongBase ($self)
                        $wrongBase = Perl::num(self::EvalCode($tagInfo->WrongBase, $self) ?: 0);
                        $dbase += $wrongBase;
                        $dpos -= $wrongBase;
                    } else {
                        $wrongBase = 0;
                    }
                    $oldOrder = ExifTool::GetByteOrder();
                    $dataTag = $tagInfo->DataTag;
                    // use different byte order for values of this offset pair if required (Minolta A200)
                    if ($tagInfo->ByteOrder) ExifTool::SetByteOrder($tagInfo->ByteOrder);
                    // transfer the data referenced by all offsets of this tag
                    for ($n = 0; $n < $count; ++$n) {
                        $oldEnd = $size = null;
                        if ($oldOffset and $oldSize) {
                            // calculate end offset of this block
                            $oldEnd = Perl::num($oldOffset[$n] ?? null) + Perl::num($oldSize[$n] ?? null);
                            // update TIFF_END as if we read this data from file
                            self::UpdateTiffEnd($et, $oldEnd + $dbase);
                        }
                        $offsetPos = $offsets + $n * 4;
                        $byteCountPos = $byteCounts + $n * Vars::$formatSize[$format];
                        if ($tagInfo->PanasonicHack) {
                            // use actual raw data length (may be different than StripByteCounts!)
                            $size = $oldSize[$n] ?? null;
                        } else {
                            // use size of new data
                            $size = ExifTool::ReadValue($newData, $byteCountPos, $formatStr, 1, 4);
                        }
                        $offset = $oldOffset[$n] ?? null;
                        if ($offset !== null) {
                            $offset -= $dpos;
                        } elseif ($size != 0xfeedfeed) {
                            $et->Error('Internal error (no offset)');
                            return null;
                        }
                        $newOffset = strlen($newData) - $wrongBase;
                        $buff = null;
                        // look for 'feed' code to use our new data
                        if ($size == 0xfeedfeed) {
                            if ($dataTag === null) {
                                $et->Error("No DataTag defined for {$tagInfo->Name}");
                                return null;
                            }
                            if (!isset($offsetData[$dataTag])) {
                                $et->Error("Internal error (no $dataTag)");
                                return null;
                            }
                            if ($count > 1) {
                                $et->Error("Can't modify {$tagInfo->Name} with count $count");
                                return null;
                            }
                            $buff = $offsetData[$dataTag];
                            if (Vars::$formatSize[$format] != 4) {
                                $et->Error("{$cntInfo->Name} is not int32");
                                return null;
                            }
                            // set the data size
                            $size = strlen($buff);
                            ExifTool::Set32u($size, $newData, $byteCountPos);
                        } elseif ($ifd < 0) {
                            // hack for fixed-offset data (Panasonic GH6)
                            if ($offsetPair[0][6] ?? null) {
                                if ($count > 1) {
                                    $et->Error("Can't handle fixed offsets with count > 1");
                                } else {
                                    $fixedOffset = ExifTool::Get32u($newData, $offsets);
                                    $padToFixedOffset = $fixedOffset - ($newOffset + $dpos);
                                    // account for blocks that come before this (Pansonic DC-S1RM2, forum17319)
                                    foreach ($imageData as $_) $padToFixedOffset -= $_[1] + $_[2];
                                    if ($padToFixedOffset < 0) {
                                        $et->Error('Metadata too large to fit before fixed-offset image data');
                                    } else {
                                        // add necessary padding before raw data
                                        $imageData[] = [$offset + $dbase + $dpos, 0, $padToFixedOffset];
                                        $newOffset += $padToFixedOffset;
                                        $et->Warn("Adding $padToFixedOffset bytes of padding before fixed-offset image data", 1);
                                    }
                                }
                            }
                            // pad if necessary (but don't pad contiguous image blocks)
                            $pad = 0;
                            if (($blockSize + $size) & 0x01 and ($n + 1 >= $count or
                                      !$oldEnd or $oldEnd != Perl::num($oldOffset[$n + 1] ?? null))) ++$pad;
                            // preserve original image padding if specified
                            if (($origDirInfo['PreserveImagePadding'] ?? null) and $n + 1 < $count and
                                $oldEnd and Perl::num($oldOffset[$n + 1] ?? null) > $oldEnd)
                            {
                                $pad = $oldOffset[$n + 1] - $oldEnd;
                            }
                            // copy data later
                            $imageData[] = [$offset + $dbase + $dpos, $size, $pad];
                            $newOffset += $blockSize;   // data comes after other deferred data
                            // create fixup for SubIFD ImageData
                            if ($imageDataFlag === 'SubIFD' and !$subIfdDataFixup) {
                                $subIfdDataFixup = new Fixup();
                                $imageData[count($imageData) - 1][4] = $subIfdDataFixup;
                            }
                            $size += $pad; // account for pad byte if necessary
                            // return ImageData list
                            $origDirInfo['ImageData'] = &$imageData;
                        } elseif ($offset >= 0 and $offset + $size <= $dataLen) {
                            // take data from old dir data buffer
                            $buff = substr($dataPt, $offset, $size);
                        } elseif ($et->TIFF_TYPE === 'MRW') {
                            // TTW segment must be an even 4 bytes long, so pad now if necessary
                            $len = strlen($newData);    // (Perl: my $n)
                            $buff = ($len & 0x03) ? str_repeat("\0", 4 - ($len & 0x03)) : '';
                            $size = strlen($buff);
                            // data exists after MRW TTW segment
                            if ($ttwLen === null) $ttwLen = strlen($newData) + $size;
                            $newOffset = $offset + $dpos + $ttwLen - $dataLen;
                        } elseif ($raf and $raf->Seek($offset + $dbase + $dpos, 0) and
                                 $raf->Read($buff, $size) == $size)
                        {
                            // (data was read OK)
                            // patch incorrect ThumbnailOffset in Sony A100 1.00 ARW images
                            if ($et->TIFF_TYPE === 'ARW' and $tagInfo->Name === 'ThumbnailOffset' and
                                $et->Model === 'DSLR-A100' and !preg_match('/^\xff\xd8\xff/', $buff))
                            {
                                $pos = $offset + $dbase + $dpos;
                                $try = null;
                                if ($pos < 0x10000 and $raf->Seek($pos + 0x10000, 0) and
                                    $raf->Read($try, $size) == $size and preg_match('/^\xff\xd8\xff/', $try))
                                {
                                    $buff = $try;
                                    $et->Warn('Adjusted incorrect A100 ThumbnailOffset', 1);
                                } else {
                                    $et->Error('Invalid ThumbnailImage');
                                }
                            }
                        } elseif ($tagInfo->Name === 'ThumbnailOffset' and $offset >= 0 and $offset < $dataLen) {
                            // Grrr.  The Canon 350D writes the thumbnail with an incorrect byte count
                            $diff = $offset + $size - $dataLen;
                            $et->Warn("ThumbnailImage runs outside EXIF data by $diff bytes (truncated)", 1);
                            // set the size to the available data
                            $size -= $diff;
                            if (!ExifTool::WriteValue($size, $formatStr, 1, $newData, $byteCountPos)) {
                                ExifTool::PerlWarn('Internal error writing thumbnail size');
                            }
                            // get the truncated image
                            $buff = substr($dataPt, $offset, $size);
                        } elseif ($tagInfo->Name === 'PreviewImageStart' and $et->FILE_TYPE === 'JPEG') {
                            // try to load the preview image using the specified offset
                            $buff = null;
                            $r = $et->RAF;
                            if ($r and !$raf) {
                                $tell = $r->Tell();
                                // read and validate
                                if (!($r->Seek($offset + $base + $dataPos, 0) and
                                      $r->Read($buff, $size) == $size and
                                      preg_match('/^.\xd8\xff[\xc4\xdb\xe0-\xef]/s', $buff))) $buff = null;
                                if (!$r->Seek($tell, 0)) {
                                    $et->Error('Seek error');
                                    return null;
                                }
                            }
                            // set flag if we must load PreviewImage
                            if ($buff === null) $buff = 'LOAD_PREVIEW';
                        } else {
                            $dataName = $dataTag ?: $tagInfo->Name;
                            if ($et->Error("Error reading $dataName data in $name", $inMakerNotes)) return null;
                            $buff = '';
                        }
                        if ($tagInfo->Name === 'PreviewImageStart') {
                            if ($et->FILE_TYPE === 'JPEG' and !$tagInfo->MakerPreview) {
                                if ($size) {
                                    // hold onto the PreviewImage until we can determine if it fits
                                    if (!$et->PREVIEW_INFO) $et->PREVIEW_INFO = [
                                        'Data' => $buff,
                                        'Fixup' => new Fixup(),
                                    ];
                                    if ($tagInfo->IsOffset and Perl::str($tagInfo->IsOffset) === '2') {
                                        $et->PREVIEW_INFO['NoBaseShift'] = 1;
                                    }
                                    if ($offset >= 0 and $offset + $size <= $dataLen) {
                                        // set flag indicating this preview wasn't in a trailer
                                        $et->PREVIEW_INFO['WasContained'] = 1;
                                    }
                                }
                                $buff = '';
                            } elseif ($et->TIFF_TYPE === 'ARW' and $et->Model === 'DSLR-A100') {
                                // the A100 double-references the same preview, so ignore the
                                // second one (the offset and size will be patched later)
                                if ($et->A100PreviewLength) continue;
                                if ($buff !== null) $et->A100PreviewLength = strlen($buff);
                            }
                        }
                        // update offset accordingly and add to end of new data
                        ExifTool::Set32u($newOffset, $newData, $offsetPos);
                        // add a pointer to fix up this offset value (marked with DataTag name)
                        $fixup->AddFixup($offsetPos, $dataTag);
                        // also add to subIfdDataFixup if necessary
                        if ($subIfdDataFixup) $subIfdDataFixup->AddFixup($offsetPos, $dataTag);
                        // must also (sometimes) update StripOffsets in Panasonic RW2 images
                        // and TileOffsets in Sony ARW images
                        $otherPos = $offsetPair[0][5] ?? null;
                        if ($otherPos) {
                            if ($tagInfo->PanasonicHack) {
                                ExifTool::Set32u($newOffset, $newData, $otherPos);
                                $fixup->AddFixup($otherPos, $dataTag);
                            } elseif (is_array($otherPos)) {
                                // the image data was copied as one large strip, and is double-referenced
                                // as tile data, so all we need to do now is properly update the tile offsets
                                $oldRawDataOffset = $offsetPair[0][3][0];
                                $tileCount = $otherPos[2];  // (Perl: my $count)
                                // point to offsets in value data if more than one pointer
                                if ($tileCount > 1) $otherPos[1] = ExifTool::Get32u($newData, $otherPos[1]);
                                for ($i = 0; $i < $tileCount; ++$i) {
                                    $oldTileOffset = $otherPos[3][$i];
                                    $ptrPos = $otherPos[1] + 4 * $i;
                                    ExifTool::Set32u($newOffset + $oldTileOffset - $oldRawDataOffset, $newData, $ptrPos);
                                    $fixup->AddFixup($ptrPos, $dataTag);
                                    if ($subIfdDataFixup) $subIfdDataFixup->AddFixup($ptrPos, $dataTag);
                                }
                            }
                        }
                        if ($ifd >= 0) {
                            // buff length must be even (Note: may have changed since $size was set)
                            if (strlen(Perl::str($buff)) & 0x01) $buff .= "\0";
                            $newData .= $buff;      // add this strip to the data
                        } else {
                            $blockSize += $size;    // keep track of total size
                        }
                    }
                    ExifTool::SetByteOrder($oldOrder);
                }
            }
            // verify that nothing else got written after determining TTW length
            if ($ttwLen !== null and $ttwLen != strlen($newData)) {
                $et->Error('Internal error writing MRW TTW');
            }
        }
        //
        // set offsets and generate fixups for tag values which were too large for memory
        //
        $blockSize = 0;
        foreach ($imageData as $bi => $blockInfo) {
            [$pos, $size, $pad, $entry, $subFix] = $blockInfo + [null, null, null, null, null];
            if ($entry !== null) {
                $format = ExifTool::Get16u($newData, $entry + 2);
                if ($format < 1 or $format > 13) {
                    $et->Error('Internal error copying huge value');
                    break;
                } else {
                    // set count and offset in directory entry
                    ExifTool::Set32u($size / Vars::$formatSize[$format], $newData, $entry + 4);
                    ExifTool::Set32u(strlen($newData) + $blockSize, $newData, $entry + 8);
                    $fixup->AddFixup($entry + 8);
                    // create special fixup for SubIFD data
                    if ($imageDataFlag === 'SubIFD') {
                        $subIfdDataFixup = new Fixup();
                        $subIfdDataFixup->AddFixup($entry + 8);
                        // save fixup in imageData list
                        $imageData[$bi][4] = $subIfdDataFixup;
                    }
                    // must reset entry pointer so we don't use it again in a parent IFD!
                    $imageData[$bi][3] = null;
                }
            }
            // apply additional shift required for contained SubIFD image data offsets
            if ($subFix and $subFix->BlockLen !== null and $numBlocks > 0) {
                // our offset expects the data at the end of the SubIFD block (BlockLen + Start),
                // but it will actually be at length($newData) + $blockSize.  So adjust
                // accordingly (and subtract an extra Start because this shift is applied later)
                $subFix->Shift += strlen($newData) - $subFix->BlockLen - 2 * $subFix->Start + $blockSize;
                $subFix->ApplyFixup($newData);
            }
            $blockSize += $size + $pad;
            --$numBlocks;
        }
        //
        // apply final shift to new data position if this is the top level IFD
        //
        if (!($dirInfo['Fixup'] ?? null)) {
            $hdrPtr = $dirInfo['HeaderPtr'] ?? null;
            $newDataPos = $hdrPtr ? strlen($hdrPtr->value) : (($dirInfo['NewDataPos'] ?? null) ?: 0);
            // adjust CanonVRD offset to point to end of regular TIFF if necessary
            // (NOTE: This will be incorrect if multiple trailers exist,
            //  but it is unlikely that it could ever be correct in this case anyway.
            //  Also, this doesn't work for JPEG images (but CanonDPP doesn't set
            //  this when editing JPEG images anyway))
            $fixup->SetMarkerPointers($newData, 'CanonVRD', strlen($newData) + $blockSize);
            if ($newDataPos) {
                $fixup->Shift += $newDataPos;
                $fixup->ApplyFixup($newData);
            }
            // save fixup for adjusting Leica trailer and Sony HiddenData offsets if necessary
            if ($et->LeicaTrailer) $et->LeicaTrailer['Fixup']->AddFixup($fixup);
            if ($et->HiddenData) $et->HiddenData['Fixup']->AddFixup($fixup);
            // save fixup for PreviewImage in JPEG file if necessary
            $previewInfo = $et->PREVIEW_INFO;
            if ($previewInfo) {
                $pt = Perl::str($previewInfo['Data'] ?? null); // image data or 'LOAD_PREVIEW' flag
                // now that we know the size of the EXIF data, first test to see if our new image fits
                // inside the EXIF segment (remember about the TIFF and EXIF headers: 8+6 bytes)
                if (($pt !== 'LOAD_PREVIEW' and strlen($pt) + strlen($newData) + 14 <= 0xfffd and
                    !($previewInfo['IsTrailer'] ?? null)) or
                    ($previewInfo['IsShort'] ?? null)) // must fit in this segment if using short pointers
                {
                    // It fits! (or must exist in EXIF segment), so fixup the
                    // PreviewImage pointers and stuff the preview image in here
                    $newPos = strlen($newData) + $newDataPos;
                    $newPos += (($previewInfo['BaseShift'] ?? null) ?: 0);
                    if ($previewInfo['Relative'] ?? null) {
                        // calculate our base by looking at how far the pointer got shifted
                        $newPos -= ($fixup->GetMarkerPointers($newData, 'PreviewImage') ?: 0);
                    }
                    $fixup->SetMarkerPointers($newData, 'PreviewImage', $newPos);
                    $newData .= $pt;
                    // set flag to delete old preview unless it was contained in the EXIF
                    if (!($et->PREVIEW_INFO['WasContained'] ?? null)) $et->DEL_PREVIEW = 1;
                    unset($et->PREVIEW_INFO);   // done with our preview data
                } else {
                    // Doesn't fit, or we still don't know, so save fixup information
                    // and put the preview at the end of the file
                    if (!($et->PREVIEW_INFO['Fixup'] ?? null)) $et->PREVIEW_INFO['Fixup'] = new Fixup();
                    $et->PREVIEW_INFO['Fixup']->AddFixup($fixup);
                }
            } elseif ($newData !== null and $deleteAll) {
                $newData = '';  // delete both IFD0 and IFD1 since only mandatory tags remain
            } elseif ($et->A100PreviewLength) {
                // save preview image start for patching A100 quirks later
                $et->A100PreviewStart = $fixup->GetMarkerPointers($newData, 'PreviewImage');
            }
            // save location of last IFD for use in Canon RAW header
            if ($newDataPos == 16) {
                $ifdPos = $fixup->GetMarkerPointersList($newData, 'NextIFD');
                $origDirInfo['LastIFD'] = array_pop($ifdPos);
            }
            // recrypt SR2 SubIFD data if necessary
            $key = $et->SR2SubIFDKey;
            if ($key) {
                $start = $fixup->GetMarkerPointers($newData, 'SR2SubIFDOffset');
                $len = $et->SR2SubIFDLength;
                // (must subtract 8 for size of TIFF header)
                if ($start and $start - 8 + $len <= strlen($newData)) {
                    ExifTool::RequireModule('Image::ExifTool::Sony');
                    Sony::Decrypt($newData, $start - 8, $len, $key);
                }
            }
        }
        // return empty string if no entries in directory
        // (could be up to 10 bytes and still be empty)
        if ($newData !== null and strlen($newData) < 12) $newData = '';

        // set changed if ForceWrite tag was set to "EXIF"
        if ($newData !== null and strlen($newData) and ($et->FORCE_WRITE['EXIF'] ?? null)) ++$et->CHANGED;

        return $newData;    // return our directory data
    }

    //------------------------------------------------------------------------------
    // (PHP helpers)

    /** Perl: ref $conv eq 'CODE' (a sub; a string expression has the 7-argument closure signature) */
    private static function IsCodeConv($conv): bool
    {
        if (!$conv instanceof \Closure) return is_string($conv) or is_array($conv);
        return (new \ReflectionFunction($conv))->getNumberOfParameters() !== 7;
    }

    /** Perl: $code =~ /re/ on the source of a table expression (PHP: the body of its closure) */
    private static function CodeMatches($code, string $re): bool
    {
        if (!$code instanceof \Closure) return (bool) preg_match($re, Perl::str($code));
        static $sources = null;
        $sources ??= new \WeakMap();
        if (!isset($sources[$code])) {
            $r = new \ReflectionFunction($code);
            $file = $r->getFileName();
            $lines = (is_string($file) and is_file($file)) ? file($file) : [];
            $src = implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
            // (only the body counts, not the parameter list)
            $sources[$code] = preg_replace('/^.*?function\s*\([^)]*\)/s', '', $src);
        }
        return (bool) preg_match($re, $sources[$code]);
    }
}
