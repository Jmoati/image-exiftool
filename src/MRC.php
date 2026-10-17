<?php

//------------------------------------------------------------------------------
// File:         MRC.pm
//
// Description:  Read MRC (Medical Research Council) image files
//
// Revisions:    2021-04-21 - P. Harvey Created
//
// References:   1) https://www.ccpem.ac.uk/mrc_format/mrc2014.php
//               2) http://legacy.ccp4.ac.uk/html/library.html
//               3) https://github.com/ccpem/mrcfile/blob/master/mrcfile/dtypes.py
//
// Notes:        The header is basically identical to the older CCP4 file format
//
// PHP port of Image::ExifTool::MRC 1.00 (reading only). Tag tables are generated
// into tables/MRC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MRC
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Extract metadata from a MRC image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid MRC file
    public static function ProcessMRC(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tagTablePtr = $i = null;

        // verify this is a valid MRC file
        if ($raf->Read($buff, 1024) != 1024) return 0;
        // validate axes, "MAP" file type and machine stamp
        if (!preg_match('/^.{64}[\x01\x02\x03]\0\0\0[\x01\x02\x03]\0\0\0[\x01\x02\x03]\0\0\0.{132}MAP[\0 ](\x44\x44|\x44\x41|\x11\x11)\0\0/s', $buff)) return 0;

        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt' => $buff,
            'DirStart' => 0,
            'DirLen' => strlen($buff),
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MRC::Main');
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        // (I don't have any samples with extended headers for testing, so these are not yet decoded)
        if ($et->ExtendedHeaderSize and preg_match('/^FEI[12]/', Perl::str($et->ExtendedHeaderType))) {
            if (!($raf->Read($buff, 4) == 4 and $raf->Seek(-4, 1))) { // read metadata size
                $et->Warn('Error reading extended header');
                return 1;
            }
            $size = ExifTool::Get32u($buff, 0);
            if ($size * $et->ImageDepth > $et->ExtendedHeaderSize) {
                $et->Warn('Corrupted extended header');
                return 1;
            }
            $subdirInfo['DirLen'] = $size;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MRC::FEI12');
            for ($i = 0; ;) {
                $subdirInfo['DataPos'] = $raf->Tell();
                if ($raf->Read($buff, $size) != $size) {
                    $et->Warn("Error reading extended header $i");
                    break;
                }
                $subdirInfo['DataPt'] = $buff;  // (Perl DataPt references $buff)
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
                if (++$i >= $et->ImageDepth) break;
                if (!$et->OPTIONS['ExtractEmbedded']) {
                    $et->Warn('Use the ExtractEmbedded option to read metadata for all frames', 3);
                    break;
                }
                $et->DOC_NUM = ++$et->DOC_COUNT;
            }
            $et->DOC_NUM = null;
        }

        return 1;
    }
}
