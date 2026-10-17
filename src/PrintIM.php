<?php

//------------------------------------------------------------------------------
// File:         PrintIM.pm
//
// Description:  Read PrintIM meta information
//
// Revisions:    04/07/2004  - P. Harvey Created
//
// PHP port of Image::ExifTool::PrintIM 1.07 (reading only). Tag tables are generated
// into tables/PrintIM.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PrintIM
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Process PrintIM IFD
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessPrintIM(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $offset = $dirInfo['DirStart'];
        $size = $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');

        if (!$size) {
            $et->Warn('Empty PrintIM data', 1);
            return 0;
        }
        if (!($size > 15)) {
            $et->Warn('Bad PrintIM data');
            return 0;
        }
        if (substr($dataPt, $offset, 7) !== 'PrintIM') {
            $et->Warn('Invalid PrintIM header');
            return 0;
        }
        // check size of PrintIM block
        $num = ExifTool::Get16u($dataPt, $offset + 14);
        if ($size < 16 + $num * 6) {
            // size is too big, maybe byte ordering is wrong
            ExifTool::ToggleByteOrder();
            $num = ExifTool::Get16u($dataPt, $offset + 14);
            if ($size < 16 + $num * 6) {
                $et->Warn('Bad PrintIM size');
                return 0;
            }
        }
        if ($verbose) $et->VerboseDir('PrintIM', $num);
        $et->HandleTag($tagTablePtr, 'PrintIMVersion', substr($dataPt, $offset + 8, 4),
            DataPt: $dataPt,
            Start:  $offset + 8,
            Size:   4,
        );
        for ($n = 0; $n < $num; ++$n) {
            $pos = $offset + 16 + $n * 6;
            $tag = ExifTool::Get16u($dataPt, $pos);
            $val = ExifTool::Get32u($dataPt, $pos + 2);
            $et->HandleTag($tagTablePtr, $tag, $val,
                Index:  $n,
                DataPt: $dataPt,
                Start:  $pos + 2,
                Size:   4,
            );
        }
        return 1;
    }
}
