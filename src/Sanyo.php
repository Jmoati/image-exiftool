<?php

//------------------------------------------------------------------------------
// File:         Sanyo.pm
//
// Description:  Sanyo EXIF maker notes tags
//
// Revisions:    04/06/2004  - P. Harvey Created
//
// Reference:    http://www.exif.org/makernotes/SanyoMakerNote.html
//
// PHP port of Image::ExifTool::Sanyo 1.16 (reading only). Tag tables are generated into
// tables/Sanyo.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Sanyo
{
    public static $VERSION = '1.16';

    //------------------------------------------------------------------------------
    // Patch incorrect offsets in J1, J2, J4, S1, S3 and S4 maker notes
    // Inputs: 0) valuePtr, 1) end of previous value, 2) value size, 3) tag ID, 4) write flag
    // (PHP: valuePtr and valEnd are updated by reference, like Perl's $_[0] and $_[1])
    public static function FixOffsets(&$valuePtr, &$valEnd, $size, $tagID, $wFlag = null)
    {
        // ignore existing offsets and calculate reasonable values instead
        if ($tagID == 0x100) {
            // just ignore the SanyoThumbnail when writing (pointer is garbage)
            if ($wFlag) $valuePtr = null;
        } else {
            $valuePtr = $valEnd;    // set value pointer to next logical location
            if ($size & 0x01) ++$size;
            $valEnd += $size;       // update end-of-value pointer
        }
    }
}
