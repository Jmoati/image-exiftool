<?php

//------------------------------------------------------------------------------
// File:         Lytro.pm
//
// Description:  Read Lytro LFP files
//
// Revisions:    2014-07-17 - P. Harvey Created
//
// References:   1) http://optics.miloush.net/lytro/TheFileFormat.aspx
//
// PHP port of Image::ExifTool::Lytro 1.04. Tag tables are generated into tables/Lytro.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Lytro
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Extract tags from a parsed JSON hash
    // Inputs: 0) ExifTool ref, 1) tag hash ref, 2) base tag name
    public static function ExtractTags(ExifTool $et, $meta, $parent)
    {
        if (!(is_array($meta) and !array_is_list($meta))) {
            $et->Warn('Invalid LFP metadata');
            return;
        }
        $name = $tagTablePtr = null;
        foreach (ExifTool::OrderedKeys($meta) as $key) {
            $tag = $parent . ucfirst(Perl::str($key));
            $item = $meta[$key] ?? null;
            $isList = (is_array($item) and array_is_list($item));
            foreach ($isList ? $item : [$item] as $val) {
                if (is_array($val) and !array_is_list($val)) {
                    self::ExtractTags($et, $val, $tag);
                    continue;
                }
                $tagTablePtr or $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Lytro::Main');
                if (!$tagTablePtr[$tag]) {
                    $name = preg_replace_callback('/[^-_a-zA-Z0-9](.?)/', static fn ($m) => strtoupper($m[1]), $tag);
                    $name = preg_replace('/ParametersVendorContentComLytroTags/', '', $name, 1);
                    $tagInfo = [];
                    $stripped = preg_replace('/^Devices/', '', $name);
                    if ($stripped === $name) {
                        $tagInfo['Groups'] = [2 => 'Image'];
                    }
                    $name = $stripped;
                    if ($isList) $tagInfo['List'] = 1;
                    $tagInfo['Name'] = $name;
                    $str = $tag === $name ? '' : " as $name";
                    $et->VPrint(0, "  [adding $tag$str]\n");
                    ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
                $et->HandleTag($tagTablePtr, $tag, $val);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Process segments from a Lytro LFP image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid Lytro image
    public static function ProcessLFP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $id = null;

        // validate the Lytro file header
        if (!($raf->Read($buff, 16) == 16 and preg_match('/^\x89LFP\x0d\x0a\x1a\x0a/', $buff))) return 0;
        $et->SetFileType();   // set the FileType tag
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Lytro::Main');
        while ($raf->Read($buff, 16) == 16) {
            if (!preg_match('/^\x89LF/', $buff)) {
                $et->Warn('LFP format error');
                break;
            }
            $size = ExifTool::Get32u($buff, 12);
            if ($size & 0x80000000) {
                $et->Warn('Invalid LFP segment size');
                break;
            }
            if ($raf->Read($id, 80) != 80) {   // ignore the sha1
                $et->Warn('Truncated LFP segment');
                break;
            }
            if ($verbose) {
                $id = preg_replace('/\0.*/s', '', $id);
                $et->VPrint(0, substr($buff, 1, 3), " segment ($size bytes, $id)\n");
            }
            if ($size > 20000000) {
                if (!$raf->Seek($size, 1)) {
                    $et->Warn('Seek error in LFP file');
                    break;
                }
            } else {
                if ($raf->Read($buff, $size) != $size) {
                    $et->Warn('Truncated LFP data');
                    break;
                }
                $et->VerboseDump($buff, Addr: $raf->Tell() - $size);
                if (preg_match('/^\{\s+"/', $buff)) { // JSON metadata?
                    $et->HandleTag($tagTablePtr, 'JSONMetadata', $buff);
                    $bpos = 0;  // (Perl: pos($buff) = 0)
                    $meta = Import::ReadJSONObject(null, $buff, $bpos);
                    self::ExtractTags($et, $meta, '');
                } elseif (preg_match('/^\xff\xd8\xff/', $buff)) { // embedded JPEG image?
                    $et->HandleTag($tagTablePtr, 'EmbeddedImage', $buff);
                }
            }
            // skip padding if necessary
            $pad = 16 - ($size % 16);
            if ($pad != 16) $raf->Seek($pad, 1);
        }
        return 1;
    }
}
