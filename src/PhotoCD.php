<?php

//------------------------------------------------------------------------------
// File:         PhotoCD.pm
//
// Description:  Read Kodak Photo CD Image Pac (PCD) metadata
//
// Revisions:    2012/05/07 - P. Harvey Created
//
// References:   1) http://pcdtojpeg.sourceforge.net/
//
// PHP port of Image::ExifTool::PhotoCD 1.03 (reading only). Tag tables are generated
// into tables/PhotoCD.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PhotoCD
{
    public static $VERSION = '1.03';

    //------------------------------------------------------------------------------
    // Read information in a PhotoCD image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PhotoCD image
    public static function ProcessPCD(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        if (!($raf->Seek(2048, 0) and
              $raf->Read($buff, 2048) == 2048 and
              preg_match('/^PCD_IPI/', $buff))) return 0;
        ExifTool::SetByteOrder('MM');
        $et->SetFileType();
        $subdirInfo = [     // (Perl %dirInfo)
            'DirName' => 'PhotoCD',
            'DataPt' => $buff,
            'DataPos' => 4096,
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PhotoCD::Main');
        return $et->ProcessBinaryData($subdirInfo, $tagTablePtr);
    }
}
