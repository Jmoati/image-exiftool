<?php

//------------------------------------------------------------------------------
// File:         GIMP.pm
//
// Description:  Read meta information from GIMP XCF images
//
// Revisions:    2010/10/05 - P. Harvey Created
//               2018/08/21 - PH Updated to current XCF specification (v013)
//
// References:   1) GIMP source code
//               2) https://gitlab.gnome.org/GNOME/gimp/blob/master/devel-docs/xcf.txt
//
// PHP port of Image::ExifTool::GIMP 1.03 (reading only). Tag tables are generated
// into tables/GIMP.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GIMP
{
    public static $VERSION = '1.03';

    //------------------------------------------------------------------------------
    // Read information in a GIMP XCF parasite data (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessParasites(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $unknown = $et->Options('Unknown') ?: $et->Options('Verbose');
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $end = strlen($dataPt);
        $et->VerboseDir('Parasites', null, $end);
        for (;;) {
            if ($pos + 4 > $end) break;
            $size = ExifTool::Get32u($dataPt, $pos);    // length of tag string
            $pos += 4;
            if ($pos + $size + 8 > $end) break;
            $tag = substr($dataPt, $pos, $size);
            $pos += $size;
            $tag = preg_replace('/\0.*/s', '', $tag);   // trim at null terminator
            // my $flags = Get32u($dataPt, $pos);  (ignore flags)
            $size = ExifTool::Get32u($dataPt, $pos + 4);    // length of data
            $pos += 8;
            if ($pos + $size > $end) break;
            if (!$tagTablePtr[$tag] and $unknown) {
                $name = $tag;
                $name = preg_replace('/[^-_A-Za-z0-9]/', '', $name);
                $name = preg_replace('/^gimp-/', '', $name);
                if (!strlen($name)) continue;
                $name = ucfirst($name);
                $name = preg_replace_callback('/([a-z])-([a-z])/', static fn ($m) => $m[1] . ucfirst($m[2]), $name);
                if (!(strlen($name) > 1)) $name = "GIMP-$name";
                ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name, 'Unknown' => 1]);
            }
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt: $dataPt,
                Start:  $pos,
                Size:   $size,
            );
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information in a GIMP XCF document
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid XCF file
    public static function ProcessXCF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if ($raf->Read($buff, 26) != 26) return 0;
        if (!preg_match('/^gimp xcf /', $buff)) return 0;

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::GIMP::Main');
        $verbose = $et->Options('Verbose');
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');

        // process the XCF header
        $et->HandleTag($tagTablePtr, 'header', $buff);

        // skip over precision for XCV version 4 or later
        if (preg_match('/^v0*(\d+)/', Perl::str($et->XCFVersion), $m) and $m[1] >= 4) $raf->Seek(4, 1);

        // loop through image properties
        for (;;) {
            if ($raf->Read($buff, 8) != 8) break;
            $tag = ExifTool::Get32u($buff, 0);
            if (!$tag) break;
            $size = ExifTool::Get32u($buff, 4);
            if ($verbose) $et->VPrint(0, "XCF property $tag ($size bytes):\n");
            if (!$tagTablePtr[$tag]) {
                $raf->Seek($size, 1);
                continue;
            }
            if ($raf->Read($buff, $size) != $size) break;
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt:  $buff,
                DataPos: $raf->Tell() - $size,
                Size:    $size,
            );
        }
        return 1;
    }
}
