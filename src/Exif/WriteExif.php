<?php

//------------------------------------------------------------------------------
// File:         WriteExif.pl
//
// Description:  Write EXIF meta information
//
// Revisions:    12/13/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::Exif), mixed into class Image\ExifTool\Exif: only the
// subs used when setting new values (InverseOffsetTime, CheckExif, EncodeExifText) are ported
// so far.
//------------------------------------------------------------------------------

namespace Image\ExifTool\Exif;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\TagInfo;

trait WriteExif
{
    //------------------------------------------------------------------------------
    // Inverse print conversion for OffsetTime tags
    // Inputs: 0) input time zone or date/time value, 1) ExifTool ref
    // Returns: Time zone string for writing to EXIF
    public static function InverseOffsetTime($val, ExifTool $et)
    {
        if (strtolower(Perl::str($val)) === 'now') $val = $et->TimeNow();
        $val = Perl::str($val);
        if (preg_match('/Z$/', $val)) return '+00:00';
        if (preg_match('/([-+])(\d{1,2}):?(\d{2})/', $val, $m)) return Perl::sprintf('%s%.2d:%.2d', $m[1], $m[2], $m[3]);
        return null;
    }

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

    //------------------------------------------------------------------------------
    // encode exif ASCII/Unicode text from UTF8 or Latin
    // Inputs: 0) ExifTool ref, 1) text string
    // Returns: encoded string
    // Note: MUST be called Raw conversion time so the EXIF byte order is known!
    public static function EncodeExifText(ExifTool $et, $val)
    {
        // does the string contain special characters?
        if (preg_match('/[\x80-\xff]/', Perl::str($val))) {
            $order = $et->GetNewValue('ExifUnicodeByteOrder');
            return "UNICODE\0" . $et->Encode($val, 'UTF16', $order);
        } else {
            return "ASCII\0\0\0" . Perl::str($val);
        }
    }
}
