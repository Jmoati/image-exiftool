<?php

//------------------------------------------------------------------------------
// File:         KyoceraRaw.pm
//
// Description:  Read Kyocera RAW meta information
//
// Revisions:    02/17/2006 - P. Harvey Created
//
// References:   1) http://www.cybercom.net/~dcoffin/dcraw/
//
// PHP port of Image::ExifTool::KyoceraRaw 1.03. Tag tables are generated into
// tables/KyoceraRaw.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class KyoceraRaw
{
    public static $VERSION = '1.03';

    // utility to reverse order of characters in a string
    public static function ReverseString($val)
    {
        return strrev(Perl::str($val));
    }

    //------------------------------------------------------------------------------
    // Extract information from Kyocera RAW image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a valid Kyocera RAW image
    public static function ProcessRAW(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $size = 156; // size of header
        $buff = null;

        if ($raf->Read($buff, $size) != $size) return 0;
        // validate Make string ('KYOCERA' reversed)
        if (substr($buff, 0x19, 7) !== 'ARECOYK') return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $subdirInfo = [     // (Perl: my %dirInfo)
            'DataPt' => $buff,
            'DataPos' => 0,
            'DataLen' => $size,
            'DirStart' => 0,
            'DirLen' => $size,
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::KyoceraRaw::Main');
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);
        return 1;
    }
}
