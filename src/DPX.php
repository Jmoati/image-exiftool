<?php

//------------------------------------------------------------------------------
// File:         DPX.pm
//
// Description:  Read DPX meta information
//
// Revisions:    2013-09-19 - P. Harvey created
//
// References:   1) http://www.cineon.com/ff_draft.php
//               2) Harry Mallon private communication
//
// PHP port of Image::ExifTool::DPX 1.07 (reading only). Tag tables are generated
// into tables/DPX.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class DPX
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Extract EXIF information from a DPX image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid DPX file
    public static function ProcessDPX(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // verify this is a valid DPX file
        if ($raf->Read($buff, 2080) != 2080) return 0;
        if (!preg_match('/^(SDPX|XPDS)/', $buff, $m)) return 0;
        ExifTool::SetByteOrder($m[1] === 'SDPX' ? 'MM' : 'II');
        $et->SetFileType();   // set the FileType tag
        $hdrLen = ExifTool::Get32u($buff, 24) + ExifTool::Get32u($buff, 28);
        if ($hdrLen != 2048) $et->Warn("Unexpected DPX header length ($hdrLen)");
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt' => $buff,
            'DirStart' => 0,
            'DirLen' => strlen($buff),
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::DPX::Main');
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        return 1;
    }
}
