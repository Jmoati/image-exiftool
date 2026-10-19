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
// PHP port of Image::ExifTool::PhaseOne 1.12 (HtmlDump is not ported since HTML_DUMP is never set). Tag tables are generated into
// tables/PhaseOne.php, @formatName into Vars\PhaseOne.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\PhaseOne as Vars;

final class PhaseOne
{
    public static $VERSION = '1.12';

    //------------------------------------------------------------------------------
    // Write PhaseOne maker notes (both types of PhaseOne IFD)
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: data block or undef on error
    public static function WritePhaseOne(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;      // allow dummy access to autoload this package

        // nothing to do if we aren't changing any PhaseOne tags
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);
        if (!($newTags or $et->DropTags or ($et->EDIT_DIRS['PhaseOne'] ?? null))) return null;

        $dataPt = $dirInfo['DataPt'];
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: ($dirInfo['DataLen'] ?? null) - $dirStart;
        $dirName = $dirInfo['DirName'] ?? null;
        $verbose = $et->Options('Verbose');

        if ($dirLen < 12) return null;
        if (!($tagTablePtr['VARS'] and !empty($tagTablePtr['VARS']['ENTRY_SIZE']))) {
            $et->Warn("No ENTRY_SIZE for {$tagTablePtr['TABLE_NAME']}");
            return null;
        }
        $entrySize = $tagTablePtr['VARS']['ENTRY_SIZE'];
        $ifdType = $tagTablePtr['TAG_PREFIX'] ?: 'PhaseOne';
        $hdr = substr($dataPt, $dirStart, 12);
        if ($entrySize == 16) {
            if (!preg_match('/^(IIII.waR|MMMMRaw.)/s', $hdr)) return null;
        } elseif (!preg_match('/^(IIII\x01\0\0\0|MMMM\0\0\0\x01)/s', $hdr)) {
            $et->Warn("Unrecognized $ifdType directory version");
            return null;
        }
        ExifTool::SetByteOrder(substr($hdr, 0, 2));
        // get offset to start of PhaseOne directory
        $ifdStart = ExifTool::Get32u($hdr, 8);
        if ($ifdStart + 8 > $dirLen) return null;
        // initialize output directory buffer with (fixed) number of entries plus 4-byte padding
        $dirBuff = substr($dataPt, $dirStart + $ifdStart, 8);
        // get number of entries in PhaseOne directory
        $numEntries = ExifTool::Get32u($dirBuff, 0);
        $ifdEnd = $ifdStart + 8 + $entrySize * $numEntries;
        if ($numEntries < 2 or $numEntries > 300 or $ifdEnd > $dirLen) return null;
        $hdrBuff = $hdr;
        $valBuff = '';   // buffer for value data
        $fixup = new Fixup();
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + $ifdStart + 8 + $entrySize * $index;
            $tagID = ExifTool::Get32u($dataPt, $entry);
            $size = ExifTool::Get32u($dataPt, $entry + $entrySize - 8);
            if ($entrySize == 16) {
                $formatSize = ExifTool::Get32u($dataPt, $entry + 4);
                $formatStr = Vars::$formatName[$formatSize] ?? null;
                if (!$formatStr) {
                    $et->Warn("Possibly invalid $ifdType IFD entry $index", 1);
                    unset($newTags[$tagID]);   // make sure we don't try to change this one
                }
            } else {
                // (no format code for SensorCalibration IFD entries)
                $formatSize = 1;
                $formatStr = 'undef';
            }
            $valuePtr = $entry + $entrySize - 4;
            if ($size > 4) {
                if ($size > 0x7fffffff) {
                    $et->Error("Invalid size for $ifdType IFD entry $index", 1);
                    return null;
                }
                $valuePtr = ExifTool::Get32u($dataPt, $valuePtr);
                if ($valuePtr + $size > $dirLen) {
                    $et->Error(Perl::sprintf("Invalid offset 0x%.4x for $ifdType IFD entry $index", $valuePtr), 1);
                    return null;
                }
                $valuePtr += $dirStart;
            }
            $value = substr($dataPt, $valuePtr, $size);
            $tagInfo = ($newTags[$tagID] ?? null) ?: $tagTablePtr[$tagID];
            if ($tagInfo and !($tagInfo instanceof TagInfo)) $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            if ($newTags[$tagID] ?? null) {
                if ($tagInfo->Format) $formatStr = $tagInfo->Format;
                $count = Perl::int($size / ExifTool::FormatSize($formatStr));
                $val = ExifTool::ReadValue($value, 0, $formatStr, $count, $size);
                $nvHash = $et->GetNewValueHash($tagInfo);
                if ($et->IsOverwriting($nvHash, $val)) {
                    $newVal = $et->GetNewValue($nvHash);
                    // allow count to change for string and undef types only
                    if ($formatStr === 'string' or $formatStr === 'undef') $count = null;
                    $newValue = ExifTool::WriteValue($newVal, $formatStr, $count);
                    if ($newValue !== null) {
                        $value = $newValue;
                        $size = strlen($newValue);
                        $et->VerboseValue("- $dirName:{$tagInfo->Name}", $val);
                        $et->VerboseValue("+ $dirName:{$tagInfo->Name}", $newVal);
                        ++$et->CHANGED;
                    }
                }
            } elseif ($tagInfo and $tagInfo->SubDirectory) {
                $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                $subdirInfo = [
                    'DirName' => $tagInfo->Name,
                    'DataPt'  => $value,
                    'DataLen' => strlen($value),
                ];
                $newValue = $et->WriteDirectory($subdirInfo, $subTable);
                if ($newValue !== null and strlen($newValue)) {
                    $value = $newValue;
                    $size = strlen($newValue);
                }
            } elseif ($et->DropTags and (($tagInfo and $tagInfo->Drop) or $size > 8192)) {
                // decrease the number of entries in the directory
                ExifTool::Set32u(ExifTool::Get32u($dirBuff, 0) - 1, $dirBuff, 0);
                continue;   // drop this tag
            }
            // add the tagID, possibly format size, and size to this directory entry
            $dirBuff .= substr($dataPt, $entry, $entrySize - 8) . ExifTool::Set32u($size);

            // pad value to an even 4-byte boundary just in case
            if ($size & 0x03 or !$size) $value .= str_repeat("\0", 4 - ($size & 0x03));
            if ($size <= 4) {
                // store value in place of the IFD value pointer (already padded to 4 bytes)
                $dirBuff .= $value;
            } elseif ($tagInfo and $tagInfo->PutFirst) {
                // store value immediately after header
                $dirBuff .= ExifTool::Set32u(strlen($hdrBuff));
                $hdrBuff .= $value;
            } else {
                // store value at end of value buffer
                $fixup->AddFixup(strlen($dirBuff));
                $dirBuff .= ExifTool::Set32u(strlen($valBuff));
                $valBuff .= $value;
            }
        }
        // apply necessary fixup to offsets in PhaseOne directory
        $fixup->Shift = strlen($hdrBuff);
        $fixup->ApplyFixup($dirBuff);
        // set pointer to PhaseOneIFD in header
        ExifTool::Set32u(strlen($hdrBuff) + strlen($valBuff), $hdrBuff, 8);
        return $hdrBuff . $valBuff . $dirBuff;
    }

    //------------------------------------------------------------------------------
    // Do HTML dump of an IFD entry
    // Inputs: 0) ExifTool ref, 1) tag table ref, 3) tag ID, 4) tag value,
    //         5) IFD entry offset, 6) IFD entry size, 7) parameter hash
    private static function HtmlDump(ExifTool $et, TagTable $tagTablePtr, $tagID, $value, $entry, $entryLen, array $parms): void
    {
        [$dirName, $index, $formatStr, $dataPos, $base, $size, $valuePtr] =
            [$parms['DirName'], $parms['Index'], $parms['Format'], $parms['DataPos'], $parms['Base'] ?? null, $parms['Size'], $parms['Start']];
        $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
        $subdir = null;
        $count = ($parms['Count'] ?? null) ?: $size;
        $base ??= 0;
        if ($tagInfo) {
            $tagName = $tagInfo->Name;
            $subdir = $tagInfo->SubDirectory;
            if ($tagInfo->Format) {
                $formatStr = $tagInfo->Format;
                $count = $size / ExifTool::FormatSize($formatStr);
            }
        } else {
            $tagName = Perl::sprintf('Tag 0x%.4x', $tagID);
        }
        $dname = Perl::sprintf("{$dirName}-%.2d", $index);
        // build our tool tip
        $fstr = "{$formatStr}[$count]";
        $tip = Perl::sprintf("Tag ID: 0x%.4x\n", $tagID)."Format: $fstr\nSize: $size bytes\n";
        if ($size > 4) {
            $tip .= Perl::sprintf("Value offset: 0x%.4x\n", $valuePtr - $base);
            $tip .= Perl::sprintf("Actual offset: 0x%.4x\n", $valuePtr + $dataPos);
            $tip .= Perl::sprintf("Offset base: 0x%.4x\n", $dataPos + $base);
            $colName = "<span class=F>$tagName</span>";
        } else {
            $colName = $tagName;
        }
        if (!\is_array($value) && !\is_object($value)) {
            $value = Perl::str($value);
            $tval = \strlen($value) > 32 ? substr($value, 0, 28).'[...]' : $value;
            $tval = preg_replace('/[\x00-\x1f\x7f-\xff]/', '.', $tval);
            $tip .= "Value: $tval";
        }
        $et->HDump($entry + $dataPos, $entryLen, "$dname $colName", $tip, 1);
        if ($size > 4) {
            $dumpPos = $valuePtr + $dataPos;
            // add value data block
            $et->HDump($dumpPos, $size, "$tagName value", 'SAME', $subdir ? 0x04 : 0);
        }
    }

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
            if ($htmlDump) {
                self::HtmlDump($et, $tagTablePtr, $tagID, $value, $entry, $entrySize, $parms + ['Base' => $dirStart]);
            }
            $et->HandleTag($tagTablePtr, $tagID, $value, ...$parms);
        }
        return 1;
    }
}
