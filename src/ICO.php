<?php

//------------------------------------------------------------------------------
// File:         ICO.pm
//
// Description:  Read Windows ICO and CUR files
//
// Revisions:    2020-10-18 - P. Harvey Created
//
// References:   1) https://docs.fileformat.com/image/ico/
//
// PHP port of Image::ExifTool::ICO 1.01 (reading only). Tag tables are generated
// into tables/ICO.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class ICO
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Process ICO/CUR file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid ICO/CUR file
    public static function ProcessICO(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        // verify this is a valid ICO/CUR file
        if ($raf->Read($buff, 6) != 6) return 0;
        if (!preg_match('/^\0\0([\x01\x02])\0[^0]\0/s', $buff, $m)) return 0;
        // (note: have seen cursor files in the wild with an 0x01 here,
        // but SetFileType will use the .cur extension to identify these)
        $et->SetFileType($m[1] === "\x01" ? 'ICO' : 'CUR');
        ExifTool::SetByteOrder('II');
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::ICO::Main');
        $num = ExifTool::Get16u($buff, 4);
        $et->HandleTag($tagTbl, 4, $num);
        for ($i = 0; $i < $num; ++$i) {
            if ($raf->Read($buff, 16) != 16) {
                $et->Warn('Truncated file');
                break;
            }
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $et->HandleTag($tagTbl, 6, $buff);
        }
        $et->DOC_NUM = null;
        return 1;
    }
}
