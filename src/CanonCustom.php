<?php

//------------------------------------------------------------------------------
// File:         CanonCustom.pm
//
// Description:  Read and write Canon Custom functions
//
// Revisions:    11/25/2003  - P. Harvey Created
//
// References:   1) http://park2.wakwak.com/~tsuruzoh/Computer/Digicams/exif-e.html
//               2) Christian Koller private communication (20D)
//               3) Rainer Honle private communication (5D)
//               4) David Pitcher private communication (1DmkIII firmware upgrade)
//
// PHP port of Image::ExifTool::CanonCustom 1.58. Tag tables are generated into
// tables/CanonCustom.php. In Write mode, ProcessCanonCustom2 edits the DataPt of dirInfo
// (Perl edits the buffer that DataPt refers to).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class CanonCustom
{
    public static $VERSION = '1.58';

    //------------------------------------------------------------------------------
    // Conversion routines
    // Inputs: 0) value to convert
    public static function ConvertPfn($val)
    {
        return $val ? (Perl::num($val) == 1 ? 'On' : 'On (' . Perl::str($val) . ')') : 'Off';
    }
    public static function ConvertPfnInv($val)
    {
        $val = Perl::str($val);
        if (preg_match('/(\d+)/', $val, $m)) return $m[1];
        if (preg_match('/on/i', $val)) return 1;
        if (preg_match('/off/i', $val)) return 0;
        return null;
    }

    //------------------------------------------------------------------------------
    // Read/Write Canon custom 2 directory (new for 1D Mark III)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCanonCustom2(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $offset = $dirInfo['DirStart'];
        $size = $dirInfo['DirLen'];
        $write = $dirInfo['Write'] ?? null;
        $verbose = $et->Options('Verbose');
        $newTags = null;

        if ($size < 2) return 0;
        // first entry in array must be the size
        $len = ExifTool::Get16u($dataPt, $offset);
        if (!($len == $size and $len >= 8)) {
            $et->Warn('Invalid CanonCustom2 data');
            return 0;
        }
        // get group count
        $count = ExifTool::Get32u($dataPt, $offset + 4);
        if ($write) {
            $newTags = $et->GetNewTagInfoHash($tagTablePtr);
            $et->VPrint(0, "  Rewriting CanonCustom2\n");
        } elseif ($verbose) {
            $et->VerboseDir('CanonCustom2', $count, $len);
        }
        $pos = $offset + 8;
        $end = $offset + $size;
        // loop through group records
        for (; $pos < $end; ) {
            if ($pos + 12 > $end) break;
            $recNum = ExifTool::Get32u($dataPt, $pos);
            $recLen = ExifTool::Get32u($dataPt, $pos + 4);
            $recCount = ExifTool::Get32u($dataPt, $pos + 8);
            if ($recLen < 8) break;     // must be at least 8 bytes for recNum and recLen
            $pos += 12;
            $recPos = $pos;
            $recEnd = $pos + $recLen - 8;
            if ($recEnd > $end) {
                $et->Warn("Corrupted CanonCustom2 group $recNum");
                return 0;
            }
            if ($verbose and !$write) {
                $et->VerboseDir("CanonCustom2 group $recNum", $recCount, $recLen);
            }
            $num = 0;
            for ($i = 0; $recPos + 8 < $recEnd; ++$i, $recPos += 4 * $num) {
                $tag = ExifTool::Get32u($dataPt, $recPos);
                $num = ExifTool::Get32u($dataPt, $recPos + 4);
                $nextRec = $recPos + 8 + $num * 4;
                if ($nextRec > $recEnd) break;
                // patch for EOS-1DXmkIII firmware 1.0.0 bug:
                if ($tag == 0x70c and $num == 0x66 and $nextRec + 8 < $recEnd) {
                    $tmp = ExifTool::Get32u($dataPt, $nextRec + 4);
                    if ($tmp == 0x70f) {
                        $et->Warn('Fixed incorrect CanonCustom tag 0x70c size', 1);
                        ++$num; // (count should be one greater)
                    }
                }
                $recPos += 8;
                $val = ExifTool::ReadValue($dataPt, $recPos, 'int32s', $num, $num * 4);
                if ($write) {
                    // write new value
                    $tagInfo = $newTags[$tag] ?? null;
                    if (!($newTags[$tag] ?? null)) continue;
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tag, $val, null, $num);
                    if (!$tagInfo) continue;
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    if (!$nvHash) continue;
                    if (!$et->IsOverwriting($nvHash, $val)) continue;
                    $newVal = $et->GetNewValue($nvHash);
                    if ($newVal === null) continue;    // can't delete from a custom table
                    ExifTool::WriteValue($newVal, 'int32s', $num, $dataPt, $recPos);
                    $dirInfo['DataPt'] = $dataPt;   // (PHP: the edited data for the caller)
                    $et->VerboseValue("- CanonCustom:{$tagInfo->Name}", $val);
                    $et->VerboseValue("+ CanonCustom:{$tagInfo->Name}", $newVal);
                    ++$et->CHANGED;
                } else {
                    // save extracted tag
                    $oldInfo = $tagTablePtr[$tag];
                    $et->HandleTag($tagTablePtr, $tag, $val,
                        Index: $i,
                        Format: 'int32u',
                        Count: $num,
                        Size: $num * 4,
                    );
                    $tagInfo = $tagTablePtr[$tag];
                    // generate properly formatted description if we just added the tag
                    if ($tagInfo instanceof TagInfo and !$oldInfo) {
                        $tagInfo->Description = strtr(Perl::str($tagInfo->Name), '_', ' ');
                        $tagInfo->Description = preg_replace('/CanonCustom Functions/', 'Canon Custom Functions ', $tagInfo->Description, 1);
                    }
                }
            }
            if ($i != $recCount) {
                $et->Warn('Missing ' . ($recCount - $i) . " entries in CanonCustom2 group $recNum directory", 1);
            }
            $pos = $recEnd;
        }
        if ($pos != $end) {
            // Note: a firmware bug in the EOS M5 and M6 stores an incorrect
            // size for the 2nd CanonCustom2 record, so this message is expected
            // for these models...
            $et->Warn('Possibly corrupted CanonCustom2 data');
            return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Write Canon custom 2 data
    // Inputs: 0) ExifTool object reference, 1) dirInfo hash ref, 2) tag table ref
    // Returns: New custom data block or undefined on error
    public static function WriteCanonCustom2(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;    // allow dummy access to autoload this package
        $dataPt = Perl::str($dirInfo['DataPt']);
        // edit a copy of the custom function 2 data
        $buff = substr($dataPt, $dirInfo['DirStart'], $dirInfo['DirLen']);
        $subDirInfo = [
            'DataPt'   => $buff,
            'DirStart' => 0,
            'DirLen'   => $dirInfo['DirLen'],
            'Write'    => 1,
        ];
        if (!self::ProcessCanonCustom2($et, $subDirInfo, $tagTablePtr)) return null;
        return $subDirInfo['DataPt'];
    }

    //------------------------------------------------------------------------------
    // Process Canon custom directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCanonCustom(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $offset = $dirInfo['DirStart'];
        $size = $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');

        // first entry in array must be the size
        $len = ExifTool::Get16u($dataPt, $offset);
        if (!($len == $size or (preg_match('/\bD60\b/', Perl::str($et->Model)) and $len + 2 == $size))) {
            $et->Warn('Invalid CanonCustom data');
            return 0;
        }
        if ($verbose) $et->VerboseDir('CanonCustom', $size / 2 - 1);
        for ($pos = 2; $pos < $size; $pos += 2) {
            // ($pos is position within custom directory)
            $val = ExifTool::Get16u($dataPt, $offset + $pos);
            $tag = ($val >> 8);
            $val = ($val & 0xff);
            $et->HandleTag($tagTablePtr, $tag, $val,
                Index: $pos / 2 - 1,
                Format: 'int8u',
                Count: 1,
                Size: 1,
            );
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Check new value for Canon custom data block
    // Inputs: 0) ExifTool object reference, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and may modify value) on success
    public static function CheckCanonCustom(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        return ExifTool::CheckValue($valPtr, 'int8u');
    }

    //------------------------------------------------------------------------------
    // Write Canon custom data
    // Inputs: 0) ExifTool object reference, 1) dirInfo hash ref, 2) tag table ref
    // Returns: New custom data block or undefined on error
    public static function WriteCanonCustom(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;    // allow dummy access to autoload this package
        $dataPt = Perl::str($dirInfo['DataPt']);
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: strlen($dataPt) - $dirStart;
        $dirName = $dirInfo['DirName'] ?? null;
        $newData = substr($dataPt, $dirStart, $dirLen);
        if (!$newData) return null;

        // first entry in array must be the size
        $len = ExifTool::Get16u($newData, 0);
        if (!($len == $dirLen or (preg_match('/\bD60\b/', Perl::str($et->Model)) and $len + 2 == $dirLen))) {
            $et->Warn('Invalid CanonCustom data');
            return null;
        }
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);
        for ($pos = 2; $pos < $dirLen; $pos += 2) {
            $val = ExifTool::Get16u($newData, $pos);
            $tag = ($val >> 8);
            $tagInfo = $newTags[$tag] ?? null;
            if (!$tagInfo) continue;
            $nvHash = $et->GetNewValueHash($tagInfo);
            $val = ($val & 0xff);
            if (!$et->IsOverwriting($nvHash, $val)) continue;
            $newVal = $et->GetNewValue($nvHash);
            if ($newVal === null) continue;    // can't delete from a custom table
            ExifTool::Set16u(((int) Perl::num($newVal) & 0xff) + ($tag << 8), $newData, $pos);
            $et->VerboseValue("- $dirName:{$tagInfo->Name}", $val);
            $et->VerboseValue("+ $dirName:{$tagInfo->Name}", $newVal);
            ++$et->CHANGED;
        }
        return $newData;
    }
}
