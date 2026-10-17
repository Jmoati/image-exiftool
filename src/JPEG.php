<?php

//------------------------------------------------------------------------------
// File:         JPEG.pm
//
// Description:  Definitions for uncommon JPEG segments
//
// Revisions:    10/06/2006 - P. Harvey Created
//
// PHP port of Image::ExifTool::JPEG 1.40 (reading only). Tag tables are generated
// into tables/JPEG.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class JPEG
{
    //------------------------------------------------------------------------------
    // Extract information from the JPEG APP0 Ocad segment
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessOcad(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $et->VerboseDir('APP0 Ocad', null, strlen($dataPt));
        $pos = 0;   // (pos $$dataPt)
        for (;;) {
            if (!preg_match('/\$(\w+):([^\0\$]+)/', $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) break;
            $pos = $m[0][1] + strlen($m[0][0]);
            [$tag, $val] = [$m[1][0], $m[2][0]];
            $val = preg_replace('/^\s+/', '', $val, 1);
            $val = preg_replace('/\s+$/', '', $val, 1);     // remove leading/trailing spaces
            if (!$tagTablePtr[$tag]) ExifTool::AddTagToTable($tagTablePtr, $tag);
            $et->HandleTag($tagTablePtr, $tag, $val);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from the JPEG APP0 Ocad segment
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessJPEG_HDR(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if (!preg_match('/~\0/', $dataPt, $m, PREG_OFFSET_CAPTURE)) {
            $et->Warn('Unrecognized JPEG-HDR format');
            return 0;
        }
        $pos = $m[0][1] + 2;    // (pos $$dataPt)
        $meta = substr($dataPt, 7, $pos - 9);
        $et->VerboseDir('APP11 JPEG-HDR', null, strlen($dataPt));
        preg_match_all('/(\w+)=([^,\s]*)/', $meta, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            [$tag, $val] = [$m[1], $m[2]];
            if (!$tagTablePtr[$tag]) ExifTool::AddTagToTable($tagTablePtr, $tag);
            $et->HandleTag($tagTablePtr, $tag, $val);
        }
        $et->HandleTag($tagTablePtr, 'RatioImage', substr($dataPt, $pos));
        return 1;
    }
}
