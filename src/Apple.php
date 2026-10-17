<?php

//------------------------------------------------------------------------------
// File:         Apple.pm
//
// Description:  Apple EXIF maker notes tags
//
// Revisions:    2013-09-13 - P. Harvey Created
//
// References:   1) http://www.photoinvestigator.co/blog/the-mystery-of-maker-apple-metadata/
//               2) Frank Rupprecht private communication
//
// PHP port of Image::ExifTool::Apple 1.15. Tag tables are generated into tables/Apple.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Apple
{
    public static $VERSION = '1.15';

    //------------------------------------------------------------------------------
    // Convert from binary PLIST format to a tag value we can use
    // Inputs: 0) binary plist data, 1) ExifTool ref
    // Returns: converted value
    public static function ConvertPLIST($val, ExifTool $et)
    {
        $dirInfo = ['DataPt' => $val, 'NoVerboseDir' => 1];
        $oldOrder = ExifTool::GetByteOrder();
        ExifTool::RequireModule('Image::ExifTool::PLIST');
        PLIST::ProcessBinaryPLIST($et, $dirInfo);
        $val = $dirInfo['Value'] ?? null;
        if (PLIST::IsHash($val) and !$et->Options('Struct')) {
            // (an empty dict is an \ArrayObject, which SerializeStruct() doesn't know)
            $val = $val instanceof \ArrayObject ? '{}' : XMP::SerializeStruct($et, $val);
        }
        // (Perl: $et->SetByteOrder($oldOrder), a method call passing the ExifTool object as
        //  the byte order, which SetByteOrder() ignores: the byte order stays 'MM')
        return $val;
    }
}
