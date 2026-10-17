<?php

//------------------------------------------------------------------------------
// File:         MOI.pm
//
// Description:  Read MOI meta information
//
// Revisions:    2014/12/15 - P. Harvey Created
//
// References:   1) https://en.wikipedia.org/wiki/MOI_(file_format)
//
// PHP port of Image::ExifTool::MOI 1.02 (reading only). Tag tables are generated
// into tables/MOI.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MOI
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Validate and process a MOI file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid MOI file
    public static function ProcessMOI(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        // read enough to allow skipping over run-in if it exists
        if (!($raf->Read($buff, 256) == 256 and preg_match('/^V6/', $buff))) return 0;
        if (isset($et->VALUE['FileSize'])) {
            $size = Perl::unpack('x2N', $buff)[0];
            if ($size != $et->VALUE['FileSize']) return 0;
        }
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MOI::Main');
        $subdirInfo = ['DataPt' => $buff];
        return $et->ProcessBinaryData($subdirInfo, $tagTablePtr);
    }
}
