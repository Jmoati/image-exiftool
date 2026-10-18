<?php

//------------------------------------------------------------------------------
// File:         WritePDF.pl
//
// Description:  Write PDF meta information
//
// Revisions:    12/08/2007 - P. Harvey Created
//
// PHP port (package Image::ExifTool::PDF), mixed into class Image\ExifTool\PDF: only the
// CHECK_PROC used when setting new values (CheckPDF) is ported so far.
//------------------------------------------------------------------------------

namespace Image\ExifTool\PDF;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\TagInfo;

trait WritePDF
{
    //------------------------------------------------------------------------------
    // Validate raw PDF values for writing (string date integer real boolean name)
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckPDF(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = Perl::str($tagInfo->Writable ?: $tagInfo->Table['WRITABLE']);
        $val = Perl::str($valPtr);
        if (!$format) {
            return 'No writable format';
        } elseif ($format === 'string') {
            // (encode later because list-type string tags need to be encoded as a unit)
        } elseif ($format === 'date') {
            // be flexible about this for now
            if (!preg_match('/^\d{4}/', $val)) return 'Bad date format';
        } elseif ($format === 'integer') {
            if (!ExifTool::IsInt($valPtr)) return 'Not an integer';
        } elseif ($format === 'real') {
            if (!preg_match('/^[+-]?(?=\d|\.\d)\d*(\.\d*)?$/', $val)) return 'Not a real number';
        } elseif ($format === 'boolean') {
            $valPtr = ($valPtr and !preg_match('/^f/i', $val)) ? 'true' : 'false';
        } elseif ($format === 'name') {
            if (str_contains($val, "\0")) return 'Invalid PDF name';
        } else {
            return "Invalid PDF format '{$format}'";
        }
        return null;    // value is OK
    }
}
