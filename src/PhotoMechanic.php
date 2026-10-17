<?php

//------------------------------------------------------------------------------
// File:         PhotoMechanic.pm
//
// Description:  Read/write Camera Bits Photo Mechanic information
//
// Revisions:    10/28/2006 - P. Harvey Created
//
// PHP port of Image::ExifTool::PhotoMechanic 1.08 (reading only). Tag tables are
// generated into tables/PhotoMechanic.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PhotoMechanic
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Read/write PhotoMechanic information in a file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this file didn't contain PhotoMechanic information
    // - updates DataPos to point to start of PhotoMechanic information
    // - updates DirLen to trailer length
    public static function ProcessPhotoMechanic(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $rtnVal = 0;
        $buff = $footer = null;

        for (;;) {
            // read and validate trailer footer (last 12 bytes)
            if (!($raf->Seek(-12 - $offset, 2) and $raf->Read($footer, 12) == 12)) break;
            if (!preg_match('/cbipcbbl$/', $footer)) break;
            $size = Perl::unpack('N', $footer)[0];

            if ($size & 0x80000000 or !$raf->Seek(-$size - 12, 1)) {
                $et->Warn('Bad PhotoMechanic trailer');
                break;
            }
            if ($raf->Read($buff, $size) != $size) {
                $et->Warn('Error reading PhotoMechanic trailer');
                break;
            }
            $rtnVal = 1;    // we read the trailer successfully

            // set variables returned in dirInfo hash
            $dirInfo['DataPos'] = $raf->Tell() - $size;
            $dirInfo['DirLen'] = $size + 12;

            $subdirInfo = [     // (Perl %dirInfo)
                'DataPt' => $buff,
                'DataPos' => $dirInfo['DataPos'],
                'DirStart' => 0,
                'DirLen' => $size,
                'Parent' => 'PhotoMechanic',
            ];
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PhotoMechanic::Main');
            // extract trailer information
            if ($verbose or $et->HTML_DUMP) $et->DumpTrailer($dirInfo);
            $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            // (writing not ported)
            break;
        }
        return $rtnVal;
    }
}
