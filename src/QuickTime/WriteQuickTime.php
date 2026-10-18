<?php

//------------------------------------------------------------------------------
// File:         WriteQuickTime.pl
//
// Description:  Write XMP to QuickTime (MOV and MP4) files
//
// Revisions:    2013-10-29 - P. Harvey Created
//
// PHP port (package Image::ExifTool::QuickTime), mixed into class Image\ExifTool\QuickTime:
// only the subs used when setting new values (PrintInvGPSCoordinates, CheckQTValue) are ported
// so far (GetLangInfo is in src/QuickTime.php).
//------------------------------------------------------------------------------

namespace Image\ExifTool\QuickTime;

use Image\ExifTool\ExifTool;
use Image\ExifTool\GPS;
use Image\ExifTool\Perl;
use Image\ExifTool\TagInfo;

trait WriteQuickTime
{
    //------------------------------------------------------------------------------
    // Format GPSCoordinates for writing
    // Inputs: 0) PrintConv value
    // Returns: ValueConv value
    public static function PrintInvGPSCoordinates($val, $et = null)
    {
        $val = Perl::str($val);
        $v = Perl::split('/, */', $val);
        if (count($v) == 2 or count($v) == 3) {
            $below = (!empty($v[2]) and preg_match('/below/i', $v[2]));
            $v[0] = GPS::ToDegrees($v[0], 1);
            $v[1] = GPS::ToDegrees($v[1], 1);
            // (Perl::numw: the -w warning of an undefined value, this runs in the PrintConvInv eval)
            if (count($v) == 3) $v[2] = Perl::numw(ExifTool::ToFloat($v[2]), 'multiplication (*)') * ($below ? -1 : 1);
            return implode(' ', array_map(Perl::str(...), $v));
        }
        if (preg_match('/^([-+]?\d+(\.\d*)?)\s+([-+]?\d+(\.\d*)?)$/', $val)) return $val; // already 2 floats?
        if (preg_match('/^([-+]\d+(\.\d*)?){2,3}(CRS.*)?\/?$/', $val)) return $val; // already in ISO6709 format?
        return null;
    }

    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckQTValue(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = ($tagInfo->Format ?: $tagInfo->Writable) ?: $tagInfo->Table['FORMAT'];
        if (!$format) return null;
        return ExifTool::CheckValue($valPtr, $format, $tagInfo->Count);
    }
}
