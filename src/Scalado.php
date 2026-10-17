<?php

//------------------------------------------------------------------------------
// File:         Scalado.pm
//
// Description:  Read APP4 SCALADO metadata
//
// Revisions:    2013-09-13 - P. Harvey Created
//
// PHP port of Image::ExifTool::Scalado 1.01 (reading only). Tag tables are generated
// into tables/Scalado.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Scalado
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Extract information from the JPEG APP4 SCALADO segment
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessScalado(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $pos = 0;
        $end = strlen($dataPt);
        $unknown = $et->Options('Unknown');

        $et->VerboseDir('APP4 SCALADO', null, $end);
        ExifTool::SetByteOrder('MM');

        for (;;) {
            if ($pos + 12 > $end) break;
            $tag = substr($dataPt, $pos, 4);
            $ver = ExifTool::Get32u($dataPt, $pos + 4); // (looks like a version for some tags)
            if (!$tagTablePtr[$tag] and $unknown) {
                $name = $tag;
                $name = preg_replace('/[^-A-Za-z0-9_]/', '', $name);
                if (!strlen($name)) break;  // stop if tag is garbage
                ExifTool::AddTagToTable($tagTablePtr, $tag, [
                    'Name' => "Scalado_$name",
                    'Description' => "Scalado $name",
                    'Unknown' => 1,
                ]);
            }
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt:  $dataPt,
                Start:   $pos + 8,
                Size:    4,
                Extra:   ", ver $ver",
            );
            if ($tag === 'SPMO') {
                $val = ExifTool::Get32u($dataPt, $pos + 8);
                if ($ver < 5) { // (I don't have samples for version 3 or 4, so I'm not sure about these)
                    $end -= $val;     // SPMO gives trailer data length
                } else {
                    $end = $val + 12; // SPMO gives length of Scalado directory (excepting this entry)
                }
            }
            $pos += 12;
        }
        return 1;
    }
}
