<?php

//------------------------------------------------------------------------------
// File:         WriteExif.pl
//
// Description:  Write EXIF meta information
//
// Revisions:    12/13/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::Exif), mixed into class Image\ExifTool\Exif: only the
// CHECK_PROC used when setting new values (CheckExif) is ported so far.
//------------------------------------------------------------------------------

namespace Image\ExifTool\Exif;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\TagInfo;

trait WriteExif
{
    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckExif(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = ($tagInfo->Format ?: $tagInfo->Writable) ?: $tagInfo->Table['WRITABLE'];
        if (!$format or Perl::str($format) === '1') {
            if (($tagInfo->Groups[0] ?? null) === 'MakerNotes') {
                return null;    // OK to have no format for makernotes
            } else {
                return 'No writable format';
            }
        }
        return ExifTool::CheckValue($valPtr, $format, $tagInfo->Count);
    }
}
