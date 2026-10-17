<?php

//------------------------------------------------------------------------------
// File:         DJI.pm
//
// Description:  DJI Phantom maker notes tags
//
// Revisions:    2016-07-25 - P. Harvey Created
//               2017-06-23 - PH Added XMP tags
//               2024-12-04 - PH Added protobuf tags
//
// PHP port of Image::ExifTool::DJI 1.19. Tag tables are generated into tables/DJI.php,
// %knownProtocol into Vars\DJI.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class DJI
{
    public static $VERSION = '1.19';

    //------------------------------------------------------------------------------
    // Process DJI beauty settings (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessSettings(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $et->VerboseDir($dirInfo['DirName'] ?? null, null, strlen($dirInfo['DataPt']));
        foreach (Perl::split('/;/', $dirInfo['DataPt']) as $item) {
            // (limit 3: Perl's implicit limit when splitting into 2 variables)
            [$tag, $val] = array_pad(Perl::split('/=/', $item, 3), 2, null);
            if ($tag === null or $val === null) continue;
            $et->HandleTag($tagTbl, $tag, $val, MakeTagInfo: 1);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process DJI info (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessDJIInfo(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        if ($dirStart) {
            $buff = substr($dataPt, $dirStart, $dirLen);
            $dataPt = $buff;
        }
        $et->VerboseDir('DJIInfo', null, strlen($dataPt));
        $p = 0;     // (pos($$dataPt))
        while (preg_match('/\G\[(.*?)\](?=(\[|$))/s', $dataPt, $m, 0, $p)) {
            $p += strlen($m[0]);
            [$tag, $val] = array_pad(Perl::split('/:/', $m[1], 2), 2, null);
            if ($tag === null or $val === null) continue;
            if (preg_match('/^([\x20-\x7e]+)\0*$/', $val, $mm)) {
                $val = $mm[1];
            } else {
                $buff = $val;
                $val = new ScalarRef($buff);
            }
            $et->HandleTag($tagTbl, $tag, $val, MakeTagInfo: 1);
        }
        return 1;
    }
}
