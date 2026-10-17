<?php

//------------------------------------------------------------------------------
// File:         Other.pm
//
// Description:  Read meta information from other uncommon formats
//
// Revisions:    2021/07/16 - P. Harvey Created
//
// References:   1) PFM - http://www.pauldebevec.com/Research/HDR/PFM/
//
// PHP port of Image::ExifTool::Other 1.00 (reading only). Tag tables are generated
// into tables/Other.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Other
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Extract information from a Portable FloatMap image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PFM file
    public static function ProcessPFM2(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        if (!($raf->Read($buff, 256) and preg_match('/^(P[Ff])\x0a(\d+) (\d+)\x0a([-+0-9.]+)\x0a/', $buff, $m))) return 0;
        $et->SetFileType('PFM', 'image/x-pfm');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Other::PFM');
        $et->HandleTag($tagTablePtr, 'ColorSpace', $m[1]);
        $et->HandleTag($tagTablePtr, 'ImageWidth', $m[2]);
        $et->HandleTag($tagTablePtr, 'ImageHeight', $m[3]);
        $et->HandleTag($tagTablePtr, 'ByteOrder', $m[4]);
        // hack to set proper file description (extension is the same for Printer Font Metrics files)
        ExifTool::$static_vars['OverrideFileDescription']['PFM'] = 'Portable FloatMap';
        return 1;
    }
}
