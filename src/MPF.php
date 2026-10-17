<?php

//------------------------------------------------------------------------------
// File:         MPF.pm
//
// Description:  Read Multi-Picture Format information
//
// Revisions:    06/12/2009 - P. Harvey Created
//
// References:   1) http://www.cipa.jp/std/documents/e/DC-007_E.pdf
//
// PHP port of Image::ExifTool::MPF 1.17 (reading only). Tag tables are generated
// into tables/MPF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MPF
{
    public static $VERSION = '1.17';

    //------------------------------------------------------------------------------
    // Extract all MP images
    // Inputs: 0) ExifTool object ref
    // Returns: undef
    public static function ExtractMPImages(ExifTool $et)
    {
        $ee = $et->Options('ExtractEmbedded');
        $saveBinary = $et->Options('Binary');
        $didPreview = $xtra = null;

        for ($i = 1; $xtra or $xtra === null; ++$i) {
            // run through MP images in the same order they were extracted
            $xtra = isset($et->VALUE["MPImageStart ($i)"]) ? " ($i)" : '';
            $off = $et->GetValue("MPImageStart$xtra", 'ValueConv');
            $len = $et->GetValue("MPImageLength$xtra", 'ValueConv');
            if ($off and $len) {
                $type = $et->GetValue("MPImageType$xtra", 'ValueConv');
                $tag = "MPImage$i";
                // store first "Large Thumbnail" as a PreviewImage
                if (!$didPreview and $type and (Perl::num($type) & 0x0f0000) == 0x010000) {
                    $tag = 'PreviewImage';
                    $didPreview = 1;
                }
                if ($ee) $et->Options('Binary', 1);
                $val = Exif::ExtractImage($et, $off, $len, $tag);
                if ($ee) $et->Options('Binary', $saveBinary);
                if ($val === null) continue;
                $extra = ExifTool::GetTagTable('Image::ExifTool::Extra');
                if (!$extra[$tag]) {
                    ExifTool::AddTagToTable($extra, $tag, [
                        'Name' => $tag,
                        'Groups' => [0 => 'Composite', 1 => 'Composite', 2 => 'Preview'],
                    ]);
                }
                $key = $et->FoundTag($tag, $val, ...$et->GetGroup("MPImageStart$xtra"));
                // extract information from MP images if ExtractEmbedded option used
                if ($ee) {
                    $oldBase = $et->BASE;
                    $et->BASE = $off;
                    $et->DOC_NUM = $i;
                    $et->ExtractInfo($val, ['ReEntry' => 1]);
                    $et->DOC_NUM = null;
                    $et->BASE = $oldBase;
                }
            }
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Process MP Entry list
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessMPImageList(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $num = Perl::int($dirInfo['DirLen'] / 16);  // (16 bytes per MP Entry)
        $dirInfo['DirLen'] = 16;
        $success = null;
        $oldG1 = $et->SET_GROUP1;
        for ($i = 0; $i < $num; ++$i) {
            $et->SET_GROUP1 = '+' . ($i + 1);
            $success = $et->ProcessBinaryData($dirInfo, $tagTablePtr);
            $dirInfo['DirStart'] += 16;
        }
        $et->SET_GROUP1 = $oldG1;
        return $success;
    }
}
