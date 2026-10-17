<?php

//------------------------------------------------------------------------------
// File:         MISB.pm
//
// Description:  Read Motion Industry Standards Board metadata
//
// Revisions:    2022/10/08 - P. Harvey Created
//
// References: 1) https://dokumen.tips/documents/nato-standardization-agreement-stanag-4609-ed-3.html
//             2) https://upload.wikimedia.org/wikipedia/commons/1/19/MISB_Standard_0601.pdf
//             3) https://dokumen.tips/documents/misb-st-010211-standard-security-metadata-universal-standard-describes-the-use.html
//
// PHP port of Image::ExifTool::MISB 1.00. Tag tables are generated into tables/MISB.php,
// %defaultFormat into Vars\MISB.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MISB
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Process KLV (Key-Length-Value) metadata
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 if anything was extracted
    public static function ProcessKLV(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirEnd = $dirStart + $dirInfo['DirLen'];
        $rtnVal = 0;

        $et->VerboseDir($dirInfo['DirName'] ?? null, null, $dirInfo['DirLen']);

        // loop through KLV packets
        for ($pos = $dirStart; $pos < $dirEnd - 1; ) {
            $tag = ExifTool::Get8u($dataPt, $pos++);
            $len = ExifTool::Get8u($dataPt, $pos++);
            if ($len & 0x80) {
                $n = $len & 0x7f;
                if ($pos + $n > $dirEnd) break;
                $len = 0;
                foreach (range(1, $n) as $k) $len = $len * 256 + ExifTool::Get8u($dataPt, $pos++);
            }
            if ($pos + $len > $dirEnd) break;
            // best guess at decoding the value
            $val = null;
            $tagInfo = $tagTablePtr[$tag] ?: [];
            $format = (is_object($tagInfo) ? $tagInfo->Format : null) ?: (Vars\MISB::$defaultFormat[$len] ?? null);
            if ($format) {
                $val = ExifTool::ReadValue($dataPt, $pos, $format, null, $len);
            } else {
                // treat as string or binary data
                $val = substr($dataPt, $pos, $len);
                if (!preg_match('/^[\t\n\r\x20-\x7e]*$/', $val)) {
                    // (sic: Perl stores a reference to $val itself, not to the $dat copy)
                    $dat = $val;
                    $val = new ScalarRef(null);
                    $val->value = $val;
                }
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt: $dataPt,
                Start: $pos,
                Size: $len,
            );
            $rtnVal = 1;
            $pos += $len;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Parse MISB metadata
    // Inputs: 0) ExifTool ref, 1) data ref, 2) tag table ref
    // Returns: 1 if something was extracted, 0 otherwise
    public static function ParseMISB(ExifTool $et, $dataPt, TagTable $tagTablePtr)
    {
        $end = strlen($dataPt);
        $rtnVal = 0;
        $unknown = $et->OPTIONS['Unknown'];
        $verbose = $et->OPTIONS['Verbose'];

        // increment document number in case we find any tags
        $et->DOC_NUM = ++$et->DOC_COUNT;
        $et->INDENT .= '| ';

        // skip the 5-byte header (ref 1 pg. 68)
        //   0 - int8u: metadata service ID (0x00)
        //   1 - int8u: sequence number (increments each packet)
        //   2 - int8u: 0x0f (bits: cell fragmentation 00, decoder config 0, random access 0, reserved 1111)
        //   3-4 - int16u: data length (ie. packet length - 5)
        for ($pos = 5; $pos + 16 < $end; ) {
            $key = bin2hex(substr($dataPt, $pos, 16));
            $pos += 16;
            $len = ExifTool::Get8u($dataPt, $pos);
            ++$pos;
            if ($len & 0x80) { // is this a BER long form integer? (Basic Encoding Rules)
                $n = $len & 0x7f;
                $len = 0;
                if ($pos + $n > $end) return $rtnVal;
                foreach (range(1, $n) as $k) $len = $len * 256 + ExifTool::Get8u($dataPt, $pos++);
            }
            $tagInfo = $tagTablePtr[$key];
            if (!$tagInfo) {
                if ($verbose or $unknown) {
                    // (assume this is a data set, but it maybe be a simple tag)
                    $tagInfo = ['Name' => "MISB_$key", 'SubDirectory' => ['TagTable' => 'Image::ExifTool::MISB::Unknown']];
                    $et->VPrint(0, "  [adding {$tagInfo['Name']}]\n");
                    $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $key, $tagInfo);
                } else {
                    // skip this record
                    $pos += $len;
                    continue;
                }
            }
            if ($pos + $len > $end) {
                $len = $end - $pos;
                $et->VPrint(0, "{$et->INDENT}(truncated record, only $len bytes available)\n");
            }
            $dir = $tagInfo->SubDirectory;
            if (!empty($dir['ByteOrder'])) ExifTool::SetByteOrder($dir['ByteOrder']);
            $dirInfo = [
                'DataPt'   => $dataPt,
                'DirStart' => $pos,
                'DirLen'   => $len,
                'DirName'  => $tagInfo->Name,
            ];
            $subTable = ExifTool::GetTagTable($dir['TagTable'] ?? null);
            if ($subTable and self::ProcessKLV($et, $dirInfo, $subTable)) $rtnVal = 1;
            ExifTool::SetByteOrder('MM');
            $pos += $len;
        }
        $et->INDENT = substr($et->INDENT, 0, -2);
        $et->DOC_NUM = null;
        if (!$rtnVal) --$et->DOC_COUNT;
        return $rtnVal;
    }
}
