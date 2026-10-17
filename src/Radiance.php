<?php

//------------------------------------------------------------------------------
// File:         Radiance.pm
//
// Description:  Read Radiance RGBE HDR meta information
//
// Revisions:    2011/12/10 - P. Harvey Created
//
// References:   1) http://www.graphics.cornell.edu/online/formats/rgbe/
//               2) http://radsite.lbl.gov/radiance/refer/filefmts.pdf
//
// PHP port of Image::ExifTool::Radiance 1.02 (reading only). Tag tables are generated
// into tables/Radiance.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Radiance
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Extract information from a Radiance HDR file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid RGBE image
    public static function ProcessHDR(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        $saveRS = Perl::$RS;
        Perl::$RS = "\x0a";     // set newline character for reading
        try {
            // verify this is a valid RIFF file
            if (!($raf->ReadLine($buff) and preg_match('/^#\?(RADIANCE|RGBE)\x0a/s', $buff))) return 0;
            $et->SetFileType();
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Radiance::Main');

            while ($raf->ReadLine($buff)) {
                Perl::chomp($buff);
                if (!(strlen($buff) > 0 and strlen($buff) < 4096)) break;
                $buff = preg_replace('/^#\s*/', '', $buff, 1, $n);
                if ($n) {
                    if (strlen($buff)) $et->HandleTag($tagTablePtr, '_comment', $buff);
                    continue;
                }
                if (!preg_match('/^(.*)?\s*=\s*(.*)/', $buff, $m)) {
                    if (strlen($buff)) $et->HandleTag($tagTablePtr, '_command', $buff);
                    continue;
                }
                // use lower-case tag names to avoid conflicts with reserved tag table entries
                [$tag, $val] = [strtolower($m[1]), $m[2]];
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if (!$tagInfo) {
                    $name = $tag;
                    $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);
                    if (!(strlen($name) > 1)) continue;
                    $name = ucfirst($name);
                    $tagInfo = ['Name' => $name];
                    $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
                $et->FoundTag($tagInfo, $val);
            }
            // get image dimensions
            if ($raf->ReadLine($buff) and preg_match('/([-+][XY])\s*(\d+)\s*([-+][XY])\s*(\d+)/', $buff, $m)) {
                $et->HandleTag($tagTablePtr, '_orient', "$m[1] $m[3]");
                $et->FoundTag('ImageHeight', $m[2]);
                $et->FoundTag('ImageWidth', $m[4]);
            }
            return 1;
        } finally {
            Perl::$RS = $saveRS;
        }
    }
}
