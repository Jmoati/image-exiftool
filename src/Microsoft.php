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
// PHP port of Image::ExifTool::Microsoft 1.24 (reading only). Tag tables are generated into
// tables/Microsoft.php, %codePage into Vars\Microsoft. Not ported (writing): CheckXtra,
// WriteXtraValue, AddNewValues and WriteXtra.
// ReadXtraValue is wantarray-dependent: it returns the list-context result (its only caller).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Microsoft
{
    public static $VERSION = '1.24';

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
