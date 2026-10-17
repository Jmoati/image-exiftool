<?php

//------------------------------------------------------------------------------
// File:         WavPack.pm
//
// Description:  Read metadata from WavPack audio files
//
// Revisions:    2025-09-24 - P. Harvey Created
//
// References:   1) https://www.wavpack.com/WavPack5FileFormat.pdf
//
// PHP port of Image::ExifTool::WavPack 1.00 (reading only). Tag tables are generated
// into tables/WavPack.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class WavPack
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Extract metadata from a WavPack file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid WavPack file
    public static function ProcessWV(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // verify this is a valid WavPack file
        if ($raf->Read($buff, 32) != 32) return 0;
        if (!preg_match('/^wvpk.{4}[\x02\x10]\x04/s', $buff)) return 0;
        $et->SetFileType();
        $dirInfo2 = [   // (Perl my %dirInfo)
            'DataPt' => $buff,
            'DirStart' => 0,
            'DirLen' => strlen($buff),
        ];
        $et->ProcessBinaryData($dirInfo2, ExifTool::GetTagTable('Image::ExifTool::WavPack::Main'));
        // historically, the RIFF module has handled RIFF-type WavPack files
        $raf->Seek(0, 0);
        $et->PATH[] = 'RIFF'; // update metadata path
        ExifTool::RequireModule('Image::ExifTool::RIFF');
        RIFF::ProcessRIFF($et, $dirInfo);
        // also look for ID3 and APE trailers (ProcessAPE also checks for ID3)
        $et->PATH[count($et->PATH) - 1] = 'APE';
        ExifTool::RequireModule('Image::ExifTool::APE');
        APE::ProcessAPE($et, $dirInfo);
        array_pop($et->PATH);
        return 1;
    }
}
