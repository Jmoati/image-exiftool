<?php

//------------------------------------------------------------------------------
// File:         JVC.pm
//
// Description:  JVC EXIF maker notes tags
//
// Revisions:    12/21/2005  - P. Harvey Created
//
// PHP port of Image::ExifTool::JVC 1.04. Tag tables are generated into tables/JVC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class JVC
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Process JVC text-based maker notes
    // Inputs: 0) ExifTool object reference
    //         1) Reference to directory information hash
    //         2) Pointer to tag table for this directory
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessJVCText(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dataLen = $dirInfo['DataLen'] ?? null;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: $dataLen - $dirStart;
        $verbose = $et->Options('Verbose');

        $data = substr($dataPt, $dirStart, $dirLen);
        // validate text maker notes
        if (!preg_match('/^VER:/', $data)) {
            $et->Warn('Bad JVC text maker notes');
            return 0;
        }
        preg_match_all('/([A-Z]+):(.{3,4})/s', $data, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            [, $tag, $val] = $m;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($verbose) {
                $et->VerboseInfo($tag, $tagInfo,
                    Table: $tagTablePtr,
                    Value: $val,
                );
            }
            if (!$tagInfo) {
                if (!$et->OPTIONS['Unknown']) continue;
                $tagInfo = [
                    'Name' => "JVC_Text_$tag",
                    'Unknown' => 1,
                    'PrintConv' => 'Image\ExifTool\ExifTool::LimitLongValues',
                ];
                // add tag information to table
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            $et->FoundTag($tagInfo, $val);
        }
        return 1;
    }
}
