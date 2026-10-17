<?php

//------------------------------------------------------------------------------
// File:         APP12.pm
//
// Description:  Read APP12 meta information
//
// Revisions:    10/18/2005 - P. Harvey Created
//
// References:   1) Heinrich Giesen private communication
//
// PHP port of Image::ExifTool::APP12 1.15 (reading only). Tag tables are generated
// into tables/APP12.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class APP12
{
    public static $VERSION = '1.15';

    //------------------------------------------------------------------------------
    // Process APP12 Ducky segment (ref PH)
    // Inputs: 0) ExifTool object reference, 1) Directory information ref, 2) tag table ref
    // Returns: 1 on success, 0 if this wasn't a recognized Ducky segment
    // Notes: This segment has the following format:
    //   1) 5 bytes: "Ducky"
    //   2) multiple data blocks (all integers are big endian):
    //      a) 2 bytes: block type (0=end, 1=Quality, 2=Comment, 3=Copyright)
    //      b) 2 bytes: block length (N)
    //      c) N bytes: block data
    public static function ProcessDucky(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $dirEnd = $pos + $dirInfo['DirLen'];
        ExifTool::SetByteOrder('MM');
        // process all data blocks in Ducky segment
        for (;;) {
            if ($pos + 4 > $dirEnd) break;
            $tag = ExifTool::Get16u($dataPt, $pos);
            $len = ExifTool::Get16u($dataPt, $pos + 2);
            $pos += 4;
            if ($pos + $len > $dirEnd) {
                $et->Warn('Invalid Ducky block length');
                break;
            }
            $val = substr($dataPt, $pos, $len);
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt: $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Start: $pos,
                Size: $len,
            );
            $pos += $len;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process APP12 Picture Info segment (ref PH)
    // Inputs: 0) ExifTool object reference, 1) Directory information ref, 2) tag table ref
    // Returns: 1 on success, 0 if this wasn't a recognized APP12
    public static function ProcessAPP12(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        if ($dirLen != $dirStart + strlen($dataPt)) {
            $buff = substr($dataPt, $dirStart, $dirLen);
            $dataPt = $buff;
        }
        $verbose = $et->Options('Verbose');
        $success = 0;
        $section = '';
        $pos = 0;   // (pos($$dataPt) = 0)

        // this regular expression is a bit complex, but basically we are looking for
        // section headers (eg. "[Camera Info]") and tag/value pairs (eg. "tag=value",
        // where "value" may contain white space), separated by spaces or CR/LF.
        // (APP12 uses CR/LF, but Olympus TextualInfo is similar and uses spaces)
        while (preg_match('/(\[.*?\]|[\w#-]+=[\x20-\x7e]+?(?=\s*([\n\r\0]|[\w#-]+=|\[|$)))/', $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $token = $m[1][0];
            // was this a section name?
            if (preg_match('/^\[(.*)\]/', $token, $mm)) {
                if ($verbose) $et->VerboseDir($mm[1]);
                $section = preg_match('/\[(\S+) ?Info\]/i', $token, $mm) ? $mm[1] : '';
                $success = 1;
                continue;
            }
            if ($verbose and !$success) $et->VerboseDir($dirInfo['DirName'] ?? null);
            $success = 1;
            preg_match('/(\S+)=(.+)/', $token, $mm);
            [$tag, $val] = [$mm[1], $mm[2]];
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($verbose) $et->VerboseInfo($tag, $tagInfo, Value: $val);
            if (!$tagInfo) {
                // add new tag to table
                $tagInfo = new TagInfo(['Name' => ucfirst($tag)]);
                // put in Camera group if information in "Camera" section
                if (preg_match('/camera/i', $section)) $tagInfo->Groups = [2 => 'Camera'];
                $et->VPrint(0, $et->INDENT, "[adding APP12:{$tagInfo->Name}]\n");
                ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            $et->FoundTag($tagInfo, $val);
        }
        return $success;
    }
}
