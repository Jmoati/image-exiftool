<?php

//------------------------------------------------------------------------------
// File:         MPC.pm
//
// Description:  Read Musepack audio meta information
//
// Revisions:    11/14/2006 - P. Harvey Created
//
// References:   1) http://www.musepack.net/
//
// PHP port of Image::ExifTool::MPC 1.01 (reading only). Tag tables are generated
// into tables/MPC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MPC
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Extract information from an MPC file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // - Just looks for MPC trailer if FileType is already set
    // Returns: 1 on success, 0 if this wasn't a valid MPC file
    public static function ProcessMPC(ExifTool $et, array &$dirInfo)
    {
        // must first check for leading ID3 information
        if (!$et->DoneID3) {
            ExifTool::RequireModule('Image::ExifTool::ID3');
            if (ID3::ProcessID3($et, $dirInfo)) return 1;
        }
        $raf = $dirInfo['RAF'];
        $buff = null;

        // check MPC signature
        if (!($raf->Read($buff, 32) == 32 and preg_match('/^MP\+(.)/s', $buff, $m))) return 0;
        $vers = ord($m[1]) & 0x0f;
        $et->SetFileType();

        // extract audio information (currently only from version 7 MPC files)
        if ($vers == 0x07) {
            ExifTool::SetByteOrder('II');
            $pos = $raf->Tell() - 32;
            if ($et->Options('Verbose')) {
                $et->VPrint(0, "MPC Header (32 bytes):\n");
                $et->VerboseDump($buff, DataPos: $pos);
            }
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MPC::Main');
            $dirInfo2 = ['DataPt' => $buff, 'DataPos' => $pos];  // (Perl my %dirInfo)
            $et->ProcessDirectory($dirInfo2, $tagTablePtr);
        } else {
            $et->Warn('Audio info currently not extracted from this version MPC file');
        }

        // process APE trailer if it exists
        ExifTool::RequireModule('Image::ExifTool::APE');
        APE::ProcessAPE($et, $dirInfo);

        return 1;
    }
}
