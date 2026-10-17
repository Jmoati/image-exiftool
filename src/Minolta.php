<?php

//------------------------------------------------------------------------------
// File:         Minolta.pm
//
// Description:  Minolta EXIF maker notes tags
//
// Revisions:    04/06/2004 - P. Harvey Created
//               09/09/2005 - P. Harvey Added ability to write MRW files
//
// References:   (see Minolta.pm)
//
// PHP port of Image::ExifTool::Minolta 2.89 (reading only). Tag tables are generated into
// tables/Minolta.php, the lens/color/scene lookups and %minoltaWhiteBalance into Vars\Minolta.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Minolta
{
    public static $VERSION = '2.89';

    //------------------------------------------------------------------------------
    // PrintConv for Minolta white balance
    public static function ConvertWhiteBalance($val)
    {
        $minoltaWhiteBalance = Vars\Minolta::$minoltaWhiteBalance;   // (basic Minolta white balance lookup)
        $printConv = $minoltaWhiteBalance[Perl::str($val)] ?? null;
        if ($printConv === null) {
            if (Perl::int($val) & 0xffff0000) {
                // the A2 values can be shifted by +- 3 settings, where
                // each setting adds or subtracts 0x0010000 (ref 2)
                $type = (Perl::int($val) & 0xff000000) + 0x800000;
                if (!empty($minoltaWhiteBalance[$type])) {
                    $printConv = $minoltaWhiteBalance[$type] .
                                 Perl::sprintf('%+.8g', (Perl::num($val) - $type) / 0x10000);
                } else {
                    $printConv = Perl::sprintf('Unknown (0x%x)', $val);
                }
            } else {
                $printConv = Perl::sprintf('Unknown (' . Perl::str($val) . ')');
            }
        }
        return $printConv;
    }
}
