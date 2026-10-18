<?php

//------------------------------------------------------------------------------
// File:         CanonRaw.pm
//
// Description:  Read Canon RAW (CRW) meta information
//
// Revisions:    11/25/2003 - P. Harvey Created
//               12/02/2003 - P. Harvey Completely reworked and figured out many
//                            more tags
//
// References:   1) http://www.cybercom.net/~dcoffin/dcraw/
//               2) http://www.wonderland.org/crw/
//               3) http://xyrion.org/ciff/CIFFspecV1R04.pdf
//               4) Dave Nicholson private communication (PowerShot S30)
//
// PHP port of Image::ExifTool::CanonRaw 1.62. Tag tables are generated into
// tables/CanonRaw.php, %crwTagFormat into Vars\CanonRaw. WriteCanonRaw.pl is the trait
// CanonRaw\WriteCanonRaw.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class CanonRaw
{
    use CanonRaw\WriteCanonRaw;

    public static $VERSION = '1.62';

    /** Perl RawConv '\$val' (one closure for the process: the core caches closure kinds by object id) */
    private static ?\Closure $binaryRawConv = null;

    //------------------------------------------------------------------------------
    // Process Raw file directory
    // Inputs: 0) ExifTool object reference
    //         1) directory information reference, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessCanonRaw(ExifTool $et, array &$dirInfo, TagTable $rawTagTable)
    {
        $blockStart = $dirInfo['DirStart'];
        $blockSize = $dirInfo['DirLen'];
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) return 0;
        $buff = null;
        $verbose = $et->Options('Verbose');
        $buildMakerNotes = $et->Options('MakerNotes');

        // 4 bytes at end of block give directory position within block
        if (!$raf->Seek($blockStart + $blockSize - 4, 0)) return 0;
        if ($raf->Read($buff, 4) != 4) return 0;
        $dirOffset = ExifTool::Get32u($buff, 0) + $blockStart;
        // avoid infinite recursion
        $et->ProcessedCanonRaw or $et->ProcessedCanonRaw = [];
        if (!empty($et->ProcessedCanonRaw[$dirOffset])) {
            $et->Warn('Not processing double-referenced ' . Perl::str($dirInfo['DirName'] ?? null) . ' directory');
            return 0;
        }
        $et->ProcessedCanonRaw[$dirOffset] = 1;
        if (!$raf->Seek($dirOffset, 0)) return 0;
        if ($raf->Read($buff, 2) != 2) return 0;
        $entries = ExifTool::Get16u($buff, 0);         // get number of entries in directory
        // read the directory (10 bytes per entry)
        if ($raf->Read($buff, 10 * $entries) != 10 * $entries) return 0;

        if ($verbose) $et->VerboseDir('CIFF', $entries);
        for ($index = 0; $index < $entries; ++$index) {
            $pt = 10 * $index;
            $tag = ExifTool::Get16u($buff, $pt);
            $size = ExifTool::Get32u($buff, $pt + 2);
            $valuePtr = ExifTool::Get32u($buff, $pt + 6);
            $ptr = $valuePtr + $blockStart;  // all pointers relative to block start
            if ($tag & 0x8000) {
                $et->Warn('Bad CRW directory entry');
                return 1;
            }
            $tagID = $tag & 0x3fff;          // get tag ID
            $tagType = ($tag >> 8) & 0x38;   // get tag type
            $valueInDir = ($tag & 0x4000);   // flag for value in directory
            $tagInfo = $et->GetTagInfo($rawTagTable, $tagID);
            if (($tagType == 0x28 or $tagType == 0x30) and !$valueInDir) {
                // this type of tag specifies a raw subdirectory
                $name = null;
                if ($tagInfo) $name = $tagInfo->Name;
                $name or $name = Perl::sprintf('CanonRaw_0x%.4x', $tag);
                $subdirInfo = [
                    'DirName'  => $name,
                    'DataLen'  => 0,
                    'DirStart' => $ptr,
                    'DirLen'   => $size,
                    'Nesting'  => Perl::num($dirInfo['Nesting'] ?? 0) + 1,
                    'RAF'      => $raf,
                    'Parent'   => $dirInfo['DirName'] ?? null,
                ];
                if ($verbose) {
                    $fakeInfo = new TagInfo(['Name' => $name, 'SubDirectory' => []]);
                    $et->VerboseInfo($tagID, $fakeInfo,
                        Index: $index,
                        Size: $size,
                        Start: $ptr,
                    );
                }
                $et->ProcessDirectory($subdirInfo, $rawTagTable);
                continue;
            }
            $valueDataPos = $count = $subdir = null;
            $format = Vars\CanonRaw::$crwTagFormat[$tagType] ?? null;
            if ($tagInfo) {
                $subdir = $tagInfo->SubDirectory;
                if ($tagInfo->Format) $format = $tagInfo->Format;
                $count = $tagInfo->Count;
            }
            // get value data
            $value = $delRawConv = null;
            if ($valueInDir) {  // is the value data in the directory?
                // this type of tag stores the value in the 'size' and 'ptr' fields
                $valueDataPos = $dirOffset + $pt + 4; // (remember, +2 for the entry count)
                $size = 8;
                $value = substr($buff, $pt + 2, $size);
                // set count to 1 by default for normal values in directory
                if ($count === null and $format and $format !== 'string' and !$subdir) $count = 1;
            } else {
                $valueDataPos = $ptr;
                // do hash of image data if requested
                if ($et->ImageDataHash and $tagID == 0x2005) {
                    if ($raf->Seek($ptr, 0)) $et->ImageDataHash($raf, $size, 'raw');
                }
                if ($size <= 512 or ($verbose > 2 and $size <= 65536)
                    or ($tagInfo and ($tagInfo->SubDirectory
                    or preg_grep('/^' . $tagInfo->Name . '$/i', $et->GetRequestedTags()))))
                {
                    // read value if size is small or specifically requested
                    // or if this is a SubDirectory
                    if (!($raf->Seek($ptr, 0) and $raf->Read($value, $size) == $size)) {
                        $et->Warn(Perl::sprintf('Error reading %d bytes from 0x%x', $size, $ptr));
                        continue;
                    }
                } else {
                    $value = "Binary data $size bytes";
                    if ($tagInfo) {
                        if ($et->Options('Binary') or $verbose) {
                            // read the value anyway
                            if (!($raf->Seek($ptr, 0) and $raf->Read($value, $size) == $size)) {
                                $et->Warn(Perl::sprintf('Error reading %d bytes from 0x%x', $size, $ptr));
                                continue;
                            }
                        }
                        // force this to be a binary (scalar reference)
                        $tagInfo->RawConv = self::$binaryRawConv ??= static fn ($val) => new ScalarRef($val);
                        $delRawConv = 1;
                    }
                    $size = strlen($value);
                    $format = null;
                }
            }
            // set count from tagInfo count if necessary
            if ($format and !$count) {
                // set count according to format and size
                $fnum = Vars\Exif::$formatNumber[$format];
                $fsiz = Vars\Exif::$formatSize[$fnum];
                $count = Perl::int($size / $fsiz);
            }
            if ($verbose) {
                $val = $value;
                if ($format) $val = ExifTool::ReadValue($val, 0, $format, $count, $size);
                $et->VerboseInfo($tagID, $tagInfo,
                    Table: $rawTagTable,
                    Index: $index,
                    Value: $val,
                    DataPt: $value,
                    DataPos: $valueDataPos,
                    Size: $size,
                    Format: $format,
                    Count: $count,
                );
            }
            if ($buildMakerNotes) {
                // build maker notes information if requested
                self::BuildMakerNotes($et, $tagID, $tagInfo, $value, $format, $count);
            }
            if ($tagInfo === null) continue;

            if ($subdir) {
                $name = $tagInfo->Name;
                $newTagTable = null;
                if (!empty($subdir['TagTable'])) {
                    $newTagTable = ExifTool::GetTagTable($subdir['TagTable']);
                    if (!$newTagTable) {
                        ExifTool::PerlWarn("Unknown tag table {$subdir['TagTable']}\n");
                        continue;
                    }
                } else {
                    ExifTool::PerlWarn("Must specify TagTable for SubDirectory $name\n");
                    continue;
                }
                $subdirStart = 0;
                //### eval Start ()
                if (!empty($subdir['Start'])) $subdirStart = Perl::num(Exif::EvalCode($subdir['Start']));
                $dirData = $value;
                $subdirInfo = [
                    'Name'     => $name,
                    'DataPt'   => $dirData,
                    'DataLen'  => $size,
                    'DataPos'  => $valueDataPos,
                    'DirStart' => $subdirStart,
                    'DirLen'   => $size - $subdirStart,
                    'Nesting'  => Perl::num($dirInfo['Nesting'] ?? 0) + 1,
                    'RAF'      => $raf,
                    'DirName'  => $name,
                    'Parent'   => $dirInfo['DirName'] ?? null,
                ];
                //### eval Validate ($dirData, $subdirStart, $size)
                if (isset($subdir['Validate']) and !Exif::EvalCode($subdir['Validate'], null, $dirData, $subdirStart, $size, null)) {
                    $et->Warn("Invalid $name data");
                } else {
                    $et->ProcessDirectory($subdirInfo, $newTagTable, $subdir['ProcessProc'] ?? null);
                }
            } else {
                // convert to specified format if necessary
                if ($format) $value = ExifTool::ReadValue($value, 0, $format, $count, $size);
                // save the information
                $et->FoundTag($tagInfo, $value);
                if ($delRawConv) unset($tagInfo->RawConv);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // get information from raw file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a valid Canon RAW file
    public static function ProcessCRW(ExifTool $et, array &$dirInfo)
    {
        $buff = $sig = null;
        $raf = $dirInfo['RAF'];
        $buildMakerNotes = $et->Options('MakerNotes');

        if ($raf->Read($buff, 2) != 2) return 0;
        if (!ExifTool::SetByteOrder($buff)) return 0;
        if ($raf->Read($buff, 4) != 4) return 0;
        if ($raf->Read($sig, 8) != 8) return 0;         // get file signature
        if (!preg_match('/^HEAP(CCDR|JPGM)/', $sig)) return 0;  // validate signature
        $hlen = ExifTool::Get32u($buff, 0);

        if (!$raf->Seek(0, 2)) return 0;                // seek to end of file
        $filesize = $raf->Tell();
        if (!$filesize) return 0;

        // initialize maker note data if building maker notes
        if ($buildMakerNotes) self::InitMakerNotes($et);

        // set the FileType tag unless already done (eg. APP0 CIFF record in JPEG image)
        $et->SetFileType();

        // build directory information for main raw directory
        $rawDirInfo = [     // (Perl: my %dirInfo)
            'DataLen'  => 0,
            'DirStart' => $hlen,
            'DirLen'   => $filesize - $hlen,
            'Nesting'  => 0,
            'RAF'      => $raf,
            'Parent'   => 'CRW',
        ];

        // process the raw directory
        $rawTagTable = ExifTool::GetTagTable('Image::ExifTool::CanonRaw::Main');
        $oldIndent = $et->INDENT;
        $et->INDENT .= '| ';
        if (!self::ProcessCanonRaw($et, $rawDirInfo, $rawTagTable)) {
            $et->Warn('CRW file format error');
        }
        $et->INDENT = $oldIndent;

        // finish building maker notes if necessary
        if ($buildMakerNotes) self::SaveMakerNotes($et);

        // process trailers if they exist in CRW file (not in CIFF information!)
        if ($et->FILE_TYPE === 'CRW') {
            $trailInfo = $et->IdentifyTrailer($raf);
            if ($trailInfo) $et->ProcessTrailers($trailInfo);
        }

        return 1;
    }
}
