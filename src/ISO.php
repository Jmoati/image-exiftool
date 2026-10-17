<?php

//------------------------------------------------------------------------------
// File:         ISO.pm
//
// Description:  Read information from ISO 9660 disk images
//
// Revisions:    2016-04-07 - P. Harvey created
//
// References:   1) http://wiki.osdev.org/ISO_9660
//
// PHP port of Image::ExifTool::ISO 1.01. Tag tables are generated into tables/ISO.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\ISO as Vars;

final class ISO
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Extract information from an ISO 9660 disk image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid ISO 9660 image
    public static function ProcessISO(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tagTablePtr = null;

        // verify this is a valid ISO file
        if (!$raf->Seek(32768, 0)) return 0;

        while ($raf->Read($buff, 2048) == 2048) {
            if (!preg_match('/^[\x00-\x03\xff]CD001/', $buff)) break;
            if (!$tagTablePtr) {
                $et->SetFileType(); // set the FileType tag
                ExifTool::SetByteOrder('II'); // read little-endian values only
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ISO::Main');
            }
            $type = ord($buff[0]);
            $et->VPrint(0, "Volume descriptor type $type (" . (Vars::$volumeDescriptorType[$type] ?? '') . ")\n");
            if ($type == 255) break;    // stop at terminator
            if (!$tagTablePtr[$type]) continue;
            $subTablePtr = ExifTool::GetTagTable($tagTablePtr[$type]->SubDirectory['TagTable']);
            $subdir = [
                'DataPt'   => $buff,
                'DataPos'  => $raf->Tell() - 2048,
                'DirStart' => 0,
                'DirLen'   => strlen($buff),
            ];
            $et->ProcessDirectory($subdir, $subTablePtr);
        }
        return $tagTablePtr ? 1 : 0;
    }
}
