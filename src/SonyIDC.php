<?php

//------------------------------------------------------------------------------
// File:         SonyIDC.pm
//
// Description:  Read/write Sony IDC information
//
// Revisions:    2010/01/05 - P. Harvey Created
//
// PHP port of Image::ExifTool::SonyIDC 1.08 (reading only). Tag tables are generated into
// tables/SonyIDC.php (with the load-time "Permanent" flags already set).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class SonyIDC
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Extract all IDC preview images
    // Inputs: 0) ExifTool object ref
    // Returns: data for "IDCPreviewImage" tag (which I have never seen),
    //          or undef if there was no preview in the SonyIDC IFD
    public static function ExtractPreviews(ExifTool $et)
    {
        $i = 1;
        $xtra = ' (1)';
        $preview = null;
        // loop through all available IDC preview images in the order they were found
        for (;;) {
            $key = "IDCPreviewStart$xtra";
            if (($et->VALUE[$key] ?? null) === null) {
                if (!$xtra) break;
                $xtra = ''; // do the last tag extracted last
                continue;
            }
            // run through IDC preview images in the same order they were extracted
            $off = $et->GetValue($key, 'ValueConv');
            if (!$off) break;
            $len = $et->GetValue("IDCPreviewLength$xtra", 'ValueConv');
            if (!$len) break;
            // get stack version from number in group 1 name
            $grp1 = $et->GetGroup($key, 1);
            if (preg_match('/(\d+)$/', Perl::str($grp1), $m)) {
                $tag = "IDCPreviewImage$m[1]";
                $extra = ExifTool::GetTagTable('Image::ExifTool::Extra');
                if (!$extra[$tag]) {
                    ExifTool::AddTagToTable($extra, $tag, [
                        'Name' => $tag,
                        'Groups' => [0 => 'Composite', 1 => 'Composite', 2 => 'Preview'],
                    ]);
                }
                $val = Exif::ExtractImage($et, $off, $len, $tag);
                $et->FoundTag($tag, $val, ...$et->GetGroup($key));
            } else {
                $preview = Exif::ExtractImage($et, $off, $len, 'IDCPreviewImage');
            }
            // step to next set of tags unless we are done
            if (!$xtra) break;
            ++$i;
            $xtra = " ($i)";
        }
        return $preview;
    }
}
