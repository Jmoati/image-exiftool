<?php

//------------------------------------------------------------------------------
// File:         HP.pm
//
// Description:  Hewlett-Packard maker notes tags
//
// Revisions:    2007-05-03 - P. Harvey Created
//
// PHP port of Image::ExifTool::HP 1.04. Tag tables are generated into tables/HP.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class HP
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Process HP APP6 TDHD metadata (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessTDHD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = $dirInfo['DirStart'];
        $dirEnd = $pos + $dirInfo['DirLen'];
        $unknown = $et->Options('Unknown') ?: $et->Options('Verbose');
        $et->VerboseDir('TDHD', null, $dirInfo['DirLen']);
        ExifTool::SetByteOrder('II');
        while ($pos + 12 < $dirEnd) {
            $tag = substr($dataPt, $pos, 4);
            $type = ExifTool::Get32u($dataPt, $pos + 4);
            $size = ExifTool::Get32u($dataPt, $pos + 8);
            $pos += 12;
            if ($size < 0 or $pos + $size > $dirEnd) break;
            if ($type == 0x10001) {
                // this is a subdirectory containing more tags
                $subdirInfo = [     // (Perl: my %dirInfo)
                    'DataPt'   => $dataPt,
                    'DataPos'  => $dataPos,
                    'DirStart' => $pos,
                    'DirLen'   => $size,
                ];
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            } else {
                if (!$tagTablePtr[$tag] and $unknown) {
                    $name = $tag;
                    $name = preg_replace('/[^-_A-Za-z0-9]/', '', $name);    // remove invalid characters
                    $tagInfo = [
                        'Name' => "HP_TDHD_$name",
                        'Unknown' => 1,
                    ];
                    // guess format based on data size
                    if ($size == 1) {
                        $tagInfo['Format'] = 'int8u';
                    } elseif ($size == 2) {
                        $tagInfo['Format'] = 'int16u';
                    } elseif ($size == 4) {
                        $tagInfo['Format'] = 'int32s';
                    } elseif ($size > 80) {
                        $tagInfo['Binary'] = 1;
                    }
                    ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
                $et->HandleTag($tagTablePtr, $tag, null,
                    DataPt: $dataPt,
                    DataPos: $dataPos,
                    Start: $pos,
                    Size: $size,
                );
            }
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process HP maker notes
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessHP(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = $dirInfo['DataLen'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: $dataLen - $dirStart;

        // look for known text-type tags
        if ($dirStart or $dirLen != strlen($dataPt)) {
            $dataPt = substr($dataPt, $dirStart, $dirLen);
        }
        // brute-force scan for PreviewImage
        if ($tagTablePtr['PreviewImage'] and preg_match('/(\xff\xd8\xff\xdb.*\xff\xd9)/s', $dataPt, $m, PREG_OFFSET_CAPTURE)) {
            $et->HandleTag($tagTablePtr, 'PreviewImage', $m[1][0]);
            // truncate preview to speed subsequent tag scans
            // (pos($$dataPt) - length($1) is the start of the match)
            $dataPt = substr($dataPt, 0, $m[1][1]);
        }
        // scan for other tag ID's
        $tagIDs = ExifTool::TagTableKeys($tagTablePtr);
        sort($tagIDs, SORT_STRING);
        foreach ($tagIDs as $tagID) {
            if (Perl::str($tagID) === 'PreviewImage') continue;
            if (!preg_match("/$tagID:\\s*([\\x20-\\x7e]+)/i", $dataPt, $m)) continue;
            $et->HandleTag($tagTablePtr, $tagID, $m[1]);
        }
        return 1;
    }
}
