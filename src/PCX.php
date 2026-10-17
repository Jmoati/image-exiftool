<?php

//------------------------------------------------------------------------------
// File:         PCX.pm
//
// Description:  Read metadata from PC Paintbrush files
//
// Revisions:    2018/12/12 - P. Harvey Created
//
// References:   1) http://qzx.com/pc-gpe/pcx.txt
//               2) https://www.fileformat.info/format/pcx/corion.htm
//
// PHP port of Image::ExifTool::PCX 1.00 (reading only). Tag tables are generated
// into tables/PCX.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PCX
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Read information in a PCX image
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid PCX file
    public static function ProcessPCX(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        if (!($raf->Read($buff, 0x50) == 0x50 and
              preg_match('/^\x0a[\0-\x05]\x01[\x01\x02\x04\x08].{64}[\0-\x02]/s', $buff))) return 0;
        ExifTool::SetByteOrder('II');
        $et->SetFileType();
        $subdirInfo = ['DirName' => 'PCX', 'DataPt' => $buff];   // (Perl %dirInfo)
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PCX::Main');
        return $et->ProcessBinaryData($subdirInfo, $tagTablePtr);
    }
}
