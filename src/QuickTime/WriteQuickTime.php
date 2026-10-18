<?php

//------------------------------------------------------------------------------
// File:         WriteQuickTime.pl
//
// Description:  Write XMP to QuickTime (MOV and MP4) files
//
// Revisions:    2013-10-29 - P. Harvey Created
//
// PHP port (package Image::ExifTool::QuickTime), mixed into class Image\ExifTool\QuickTime:
// only the CHECK_PROC used when setting new values (CheckQTValue) is ported so far
// (GetLangInfo is in src/QuickTime.php).
//------------------------------------------------------------------------------

namespace Image\ExifTool\QuickTime;

use Image\ExifTool\ExifTool;
use Image\ExifTool\TagInfo;

trait WriteQuickTime
{
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
