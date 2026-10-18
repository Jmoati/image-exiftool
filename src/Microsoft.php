<?php

//------------------------------------------------------------------------------
// File:         Microsoft.pm
//
// Description:  Definitions for custom Microsoft tags
//
// Revisions:    2010/10/01 - P. Harvey Created
//               2011/10/05 - PH Added ProcessXtra()
//               2021/02/23 - PH Added abiltity to write Xtra tags
//
// References:   1) http://research.microsoft.com/en-us/um/redmond/groups/ivm/hdview/hdmetadataspec.htm
//
// PHP port of Image::ExifTool::Microsoft 1.24. Tag tables are generated into
// tables/Microsoft.php, %codePage into Vars\Microsoft.
// ReadXtraValue is wantarray-dependent: it returns the list-context result (WriteXtra takes
// the list of values from it, the scalar-context result).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Microsoft
{
    public static $VERSION = '1.24';

    //------------------------------------------------------------------------------
    // check new value for Xtra tag
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string, or undef on success
    public static function CheckXtra(ExifTool $et, TagInfo $tagInfo, &$valPt, $convType = null)
    {
        $format = $tagInfo->Writable;
        if (!$format) return 'Unknown format';
        if (preg_match('/^int/', Perl::str($format))) {
            if (!ExifTool::IsInt($valPt)) return 'Not an integer';
        } elseif ($format !== 'Unicode') {
            $vals = [$valPt];
            if (!strlen(self::WriteXtraValue($et, $tagInfo, $vals))) return 'Invalid format';
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Decode value(s) in Microsoft Xtra tag
    // Inputs: 0) ExifTool object ref, 1) value data
    // Returns: Scalar context: decoded value, List context: 0) decoded value, 1) format string
    public static function ReadXtraValue(ExifTool $et, $data): array
    {
        $format = null;
        $vals = [];

        if (strlen($data) < 10) return [null, null];

        // (version flags according to the reference, but looks more like a count - PH)
        $count = ExifTool::Get32u($data, 0);
        // point to start of first value (after 4-byte count, 4-byte length and 2-byte type)
        $valPos = 10;
        for ($i = 0; ;) {
            // (stored value includes size of $valLen and $valType, so subtract 6)
            $valLen = ExifTool::Get32u($data, $valPos - 6) - 6;
            if ($valPos + $valLen > strlen($data)) break;
            $valType = ExifTool::Get16u($data, $valPos - 2);
            $val = substr($data, $valPos, $valLen);
            // Note: all dumb Microsoft values are little-endian inside a big-endian-format file
            ExifTool::SetByteOrder('II');
            if ($valType == 8) {
                $format = 'Unicode';
                $val = $et->Decode($val, 'UTF16');
            } elseif ($valType == 19 and $valLen == 8) {
                $format = 'int64u';
                $val = ExifTool::Get64u($val, 0);
            } elseif ($valType == 21 and $valLen == 8) {
                $format = 'date';
                $val = ExifTool::Get64u($val, 0);
                // convert time from 100 ns intervals since Jan 1, 1601
                if ($val) $val = Perl::num($val) * 1e-7 - 11644473600;
                // (the Nikon S100 uses UTC timezone, same as ASF - PH)
                $val = ExifTool::ConvertUnixTime($val, 1);
            } elseif ($valType == 72 and $valLen == 16) {
                $format = 'GUID';
                $val = strtoupper(Perl::unpack('H*', Perl::pack('NnnNN', ...Perl::unpack('VvvNN', $val)))[0]);
                $val = preg_replace('/(.{8})(.{4})(.{4})(.{4})/', '$1-$2-$3-$4-', $val, 1);
            } elseif ($valType == 65 and $valLen > 4) { //PH (empirical)
                $format = 'variant';
                ExifTool::RequireModule('Image::ExifTool::FlashPix');
                $vPos = 0;  // (necessary because ReadFPXValue updates this)
                // read entry as a VT_VARIANT (use FlashPix module for this)
                $val = FlashPix::ReadFPXValue($et, $val, $vPos,
                       FlashPix::VT_VARIANT, $valLen, 1);
            } else {
                $format = "Unknown($valType)";
            }
            ExifTool::SetByteOrder('MM');   // back to native QuickTime byte ordering
            $vals[] = $val;
            if (++$i >= $count) break;
            $valPos += $valLen + 6;     // step to next value
            if ($valPos > strlen($data)) break;
        }
        return [$vals, $format];
    }

    //------------------------------------------------------------------------------
    // Write a Microsoft Xtra value
    // Inputs: 0) ExifTool object ref, 1) tagInfo ref, 2) reference to list of values
    // Returns: new value binary data (or empty string)
    public static function WriteXtraValue(ExifTool $et, TagInfo $tagInfo, array $vals)
    {
        $format = $tagInfo->Writable;
        $buff = '';
        $count = 0;
        foreach ($vals as $val) {
            ExifTool::SetByteOrder('II');
            $type = $dat = null;
            if ($format === 'Unicode') {
                $dat = $et->Encode($val, 'UTF16', 'II') . "\0\0";  // (must be null terminated)
                $type = 8;
            } elseif ($format === 'int64u') {
                if (ExifTool::IsInt($val)) {
                    $dat = ExifTool::Set64u($val);
                    $type = 19;
                }
            } elseif ($format === 'date') {
                $dat = ExifTool::GetUnixTime($val, 1);   // (convert to UTC, NC)
                if ($dat) {
                    // 100ns intervals since Jan 1, 1601
                    $dat = ExifTool::Set64u(($dat + 11644473600) * 1e7);
                    $type = 21;
                }
            } elseif ($format === 'vt_filetime') { // 'date' value inside a VT_VARIANT
                $dat = ExifTool::GetUnixTime($val);  // (leave as local time, NC)
                if ($dat) {
                    // 100ns intervals since Jan 1, 1601
                    $dat = ExifTool::Set32u(64) . ExifTool::Set64u(($dat + 11644473600) * 1e7);
                    $type = 65;
                }
            } elseif ($format === 'GUID') {
                $dat = str_replace('-', '', Perl::str($val));
                if (strlen($dat) == 32) {
                    $dat = Perl::pack('VvvNN', ...Perl::unpack('NnnNN', Perl::pack('H*', $dat)));
                    $type = 72;
                }
            } else {
                $et->Warn("Error converting value for Microsoft:{$tagInfo->Name}");
            }
            ExifTool::SetByteOrder('MM');
            if ($type !== null) {
                ++$count;
                $buff .= ExifTool::Set32u(strlen($dat) + 6) . ExifTool::Set16u($type) . $dat;
            }
        }
        return $count ? ExifTool::Set32u($count) . $buff : '';
    }

    //------------------------------------------------------------------------------
    // Add new values to list
    // Inputs: 0) ExifTool ref, 1) new value list ref, 2) nvHash ref
    // Returns: true if something was added
    public static function AddNewValues(ExifTool $et, array &$vals, NewValue $nvHash)
    {
        $newVals = $et->GetNewValueList($nvHash);
        if (!$newVals) return null;
        if ($et->OPTIONS['Verbose'] > 1) {
            foreach ($newVals as $v) $et->VPrint(1, "  + Microsoft:{$nvHash->TagInfo->Name} = " . Perl::str($v) . "\n");
        }
        array_push($vals, ...$newVals);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Write tags to a Microsoft Xtra MP4 atom
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: Microsoft Xtra data block (may be empty if no Xtra data) or undef on error
    public static function WriteXtra(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;      // allow dummy access

        $delGroup = ($et->DEL_GROUP and ($et->DEL_GROUP['Microsoft'] ?? null));
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);

        if (!($delGroup or $newTags)) return null;  // don't rewrite if nothing to do

        $dataPt = Perl::str($dirInfo['DataPt']);
        $dataLen = strlen($dataPt);
        $newData = '';
        $pos = 0;
        $err = $changed = $tag = null;
        $done = [];

        if ($delGroup) {
            if ($dataLen) $changed = 1;
            $dataPt = '';
            $dataLen = 0;
        }
        for (;;) {
            if ($pos + 4 > $dataLen) break;
            $size = ExifTool::Get32u($dataPt, $pos); // (includes $size word)
            if ($size < 8 or $pos + $size > $dataLen) {
                $err = 1;
                break;
            }
            $tagLen = ExifTool::Get32u($dataPt, $pos + 4);
            if ($tagLen + 18 > $size) {
                $err = 1;
                break;
            }
            $tag = substr($dataPt, $pos + 8, $tagLen);
            $newVals = [];
            while ($newTags[$tag] ?? null) {
                $nvHash = $et->GetNewValueHash($newTags[$tag]);
                if ($nvHash?->CreateOnly) {
                    unset($newTags[$tag]);  // don't edit this tag
                    break;
                }
                $valPos = $pos + 8 + $tagLen;
                $valLen = $size - 8 - $tagLen;
                // (scalar-context ReadXtraValue: the list of values)
                $vals = self::ReadXtraValue($et, substr($dataPt, $valPos, $valLen))[0] ?? [];
                foreach ($vals as $val) {
                    $overwrite = $et->IsOverwriting($nvHash, $val);
                    if (!$overwrite) {
                        $newVals[] = $val;
                        continue;
                    }
                    $et->VPrint(1, "  - Microsoft:{$newTags[$tag]->Name} = " . Perl::str($val) . "\n");
                    if ($done[$tag] ?? null) continue;
                    $done[$tag] = 1;
                    self::AddNewValues($et, $newVals, $nvHash);
                }
                // add to the end of the list if this was a List-type tag and we didn't delete anything
                if (!($done[$tag] ?? null) and $newTags[$tag]->List) {
                    if (!self::AddNewValues($et, $newVals, $nvHash)) break;
                    $done[$tag] = 1;
                }
                break;   // (it was a cheap goto)
            }
            if ($done[$tag] ?? null) {
                $changed = 1;
                // write changed values
                $buff = self::WriteXtraValue($et, $newTags[$tag], $newVals);
                if (strlen($buff)) {
                    $newData .= ExifTool::Set32u(8 + strlen($tag) + strlen($buff)) . ExifTool::Set32u(strlen($tag)) . $tag . $buff;
                }
            } else {
                // nothing changed; just copy over
                $newData .= substr($dataPt, $pos, $size);
            }
            $pos += $size;  // step to next entry
        }
        if ($err) {
            $et->Warn('Microsoft Xtra format error');
            return null;
        }
        // add any new tags
        $keys = array_map('strval', array_keys($newTags));
        sort($keys, SORT_STRING);
        foreach ($keys as $tag) {
            if ($done[$tag] ?? null) continue;
            $nvHash = $et->GetNewValueHash($newTags[$tag]);
            if (!($nvHash?->IsCreating and !$nvHash->EditOnly)) continue;
            $newVals = [];
            if (!self::AddNewValues($et, $newVals, $nvHash)) continue;
            $buff = self::WriteXtraValue($et, $newTags[$tag], $newVals);
            if (strlen($buff)) {
                $newData .= ExifTool::Set32u(8 + strlen($tag) + strlen($buff)) . ExifTool::Set32u(strlen($tag)) . $tag . $buff;
                $changed = 1;
            }
        }
        if ($changed) {
            ++$et->CHANGED;
        } else {
            $newData = null;
        }
        return $newData;
    }

    //------------------------------------------------------------------------------
    // Extract information from Xtra MP4 atom
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Reference: http://code.google.com/p/mp4v2/ [since removed from trunk]
    public static function ProcessXtra(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = ($dirInfo['Base'] ?? null) ?: 0;
        $dataLen = $dirInfo['DataLen'] ?? null;
        $pos = 0;
        $et->VerboseDir('Xtra', 0, $dataLen);
        for (;;) {
            if ($pos + 4 > $dataLen) break;
            $size = ExifTool::Get32u($dataPt, $pos);  // (includes $size word)
            if ($size < 8 or $pos + $size > $dataLen) break;
            $tagLen = ExifTool::Get32u($dataPt, $pos + 4);
            if ($tagLen + 18 > $size) break;
            $valLen = $size - 8 - $tagLen;
            if ($tagLen > 0 and $valLen > 0) {
                $tag = substr($dataPt, $pos + 8, $tagLen);
                $valPos = $pos + 8 + $tagLen;
                [$val, $format] = self::ReadXtraValue($et, substr($dataPt, $valPos, $valLen));
                if ($val === null) break;
                if (count($val) == 1) $val = $val[0];
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if (!$tagInfo) {
                    // generate tag information for unrecognized tags
                    $name = $tag;
                    $name = preg_replace('{^WM/}', '', $name, 1);
                  // $name =~ tr/-_A-Za-z0-9//dc;
                    if (preg_match('/^[-\w]+$/', $name)) {
                        $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => ucfirst($name)]);
                        $et->VPrint(0, $et->INDENT, "[adding Microsoft:$tag]\n");
                    }
                }
                $count = is_array($val) ? count($val) : 1;
                $et->HandleTag($tagTablePtr, $tag, $val,
                    TagInfo: $tagInfo,
                    DataPt: $dataPt,
                    DataPos: $dataPos,
                    Start: $valPos,
                    Size: $valLen,
                    Format: $format,
                    Extra: " count=$count",
                );
            }
            $pos += $size;  // step to next entry
        }
        return 1;
    }
}
