<?php

//------------------------------------------------------------------------------
// File:         PhaseOne.pm
//
// Description:  Phase One maker notes tags
//
// Revisions:    2013-02-17 - P. Harvey Created
//
// References:   1) http://www.cybercom.net/~dcoffin/dcraw/
//
// PHP port of Image::ExifTool::PhaseOne 1.12 (reading only: WritePhaseOne is not ported,
// nor HtmlDump since HTML_DUMP is never set). Tag tables are generated into
// tables/PhaseOne.php, @formatName into Vars\PhaseOne.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\PhaseOne as Vars;

final class PhaseOne
{
    public static $VERSION = '1.12';

    //------------------------------------------------------------------------------
    // Read Phase One maker notes
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: This routine processes both the main PhaseOne IFD type (with 16 bytes
    //        per entry), and the SensorCalibration IFD type (12 bytes per entry)
    public static function ProcessPhaseOne(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = (($dirInfo['DataPos'] ?? null) ?: 0) + (($dirInfo['Base'] ?? null) ?: 0);
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: ($dirInfo['DataLen'] ?? null) - $dirStart;
        $binary = $et->Options('Binary');
        $verbose = $et->Options('Verbose');
        $hash = $et->ImageDataHash;
        $htmlDump = $et->HTML_DUMP;

        if ($dirLen < 12) return 0;
        if (!($tagTablePtr['VARS'] and !empty($tagTablePtr['VARS']['ENTRY_SIZE']))) {
            $et->Warn("No ENTRY_SIZE for {$tagTablePtr['TABLE_NAME']}");
            return null;
        }
        $entrySize = $tagTablePtr['VARS']['ENTRY_SIZE'];
        $ifdType = $tagTablePtr['TAG_PREFIX'] ?: 'PhaseOne';

        $hdr = substr($dataPt, $dirStart, 12);
        if ($entrySize == 16) {
            if (!preg_match('/^(IIII.waR|MMMMRaw.)/s', $hdr)) return 0;
        } elseif (!preg_match('/^(IIII\x01\0\0\0|MMMM\0\0\0\x01)/s', $hdr)) {
            $et->Warn("Unrecognized $ifdType directory version");
            return 0;
        }
        ExifTool::SetByteOrder(substr($hdr, 0, 2));
        // get offset to start of PhaseOne directory
        $ifdStart = ExifTool::Get32u($hdr, 8);
        if ($ifdStart + 8 > $dirLen) return 0;
        // get number of entries in PhaseOne directory
        $numEntries = ExifTool::Get32u($dataPt, $dirStart + $ifdStart);
        $ifdEnd = $ifdStart + 8 + $entrySize * $numEntries;
        if ($numEntries < 2 or $numEntries > 300 or $ifdEnd > $dirLen) return 0;
        $et->VerboseDir($ifdType, $numEntries);
        if ($htmlDump) {
            $et->HDump($dirStart + $dataPos, 8, "$ifdType header");
            $et->HDump($dirStart + $dataPos + 8, 4, "$ifdType IFD offset");
            $et->HDump($dirStart + $dataPos + $ifdStart, 4, "$ifdType entries",
                       "Entry count: $numEntries");
            $et->HDump($dirStart + $dataPos + $ifdStart + 4, 4, '[unused]');
        }
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + $ifdStart + 8 + $entrySize * $index;
            $tagID = ExifTool::Get32u($dataPt, $entry);
            $size = ExifTool::Get32u($dataPt, $entry + $entrySize - 8);
            $valuePtr = $entry + $entrySize - 4;
            $formatSize = $formatStr = $value = null;
            if ($entrySize == 16) {
                // (format code only for the 16-byte IFD entry)
                $formatSize = ExifTool::Get32u($dataPt, $entry + 4);
                $formatStr = Vars::$formatName[$formatSize] ?? null;
                if (!$formatStr) {
                    $et->Warn("Unrecognized $ifdType format size $formatSize", 1);
                    $formatSize = 1;
                    $formatStr = 'undef';
                }
            } elseif ($size % 4) {
                $formatSize = 1;
                $formatStr = 'undef';
            } else {
                $formatSize = 4;
                $formatStr = 'int32s';
            }
            if ($size > 4) {
                if ($size > 0x7fffffff) {
                    $et->Warn("Invalid size for $ifdType IFD entry $index");
                    return 0;
                }
                $valuePtr = ExifTool::Get32u($dataPt, $valuePtr);
                if ($valuePtr + $size > $dirLen) {
                    $et->Warn(Perl::sprintf("Invalid offset 0x%.4x for $ifdType IFD entry $index", $valuePtr));
                    return 0;
                }
                $valuePtr += $dirStart;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            if ($tagInfo) {
                if ($tagInfo->Format) $formatStr = $tagInfo->Format;
            } else {
                if (!($verbose or $htmlDump)) continue;
            }
            $count = Perl::int($size / ExifTool::FormatSize($formatStr));
            if ($count > 100000 and !$binary) {
                $value = new ScalarRef("Binary data $size bytes");
            } else {
                $value = ExifTool::ReadValue($dataPt, $valuePtr, $formatStr, $count, $size);
                // try to distinguish between the various format types
                if ($formatStr === 'int32s') {
                    $val = Perl::split(' ', $value)[0] ?? null;
                    if ($val !== null) {
                        // get floating point exponent (has bias of 127)
                        $exp = (Perl::int($val) & 0x7f800000) >> 23;
                        if ($exp > 120 and $exp < 140) {
                            $formatStr = 'float';
                            $value = ExifTool::ReadValue($dataPt, $valuePtr, $formatStr, $count, $size);
                        }
                    }
                }
            }
            if ($hash and $tagInfo and $tagInfo->IsImageData) {
                [$pos, $len] = [$valuePtr, $size];
                while ($len) {
                    $n = $len > 65536 ? 65536 : $len;
                    $tmp = substr($dataPt, $pos, $n);
                    hash_update($hash, $tmp);
                    $len -= $n;
                    $pos += $n;
                }
                $et->VPrint(0, "{$et->INDENT}(ImageDataHash: $size bytes of PhaseOne:{$tagInfo->Name})\n");
            }
            $parms = [
                'DirName' => $ifdType,
                'Index'   => $index,
                'DataPt'  => $dataPt,
                'DataPos' => $dataPos,
                'Size'    => $size,
                'Start'   => $valuePtr,
                'Format'  => $formatStr,
                'Count'   => $count,
            ];
            // (HtmlDump($et, $tagTablePtr, $tagID, $value, $entry, $entrySize, %parms, Base => $dirStart)
            //  is not ported: HTML_DUMP is never set)
            $et->HandleTag($tagTablePtr, $tagID, $value, ...$parms);
        }
        return 1;
    }
}
