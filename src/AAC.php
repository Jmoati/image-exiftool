<?php

//------------------------------------------------------------------------------
// File:         AAC.pm
//
// Description:  Read AAC audio files
//
// Revisions:    2023-12-29 - P. Harvey Created
//
// PHP port of Image::ExifTool::AAC 1.00 (reading only). Tag tables and file lexicals
// (%convSampleRate) are generated into tables/AAC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class AAC
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Read information from an AAC file
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid AAC file
    public static function ProcessAAC(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = null;

// format of frame header (7 bytes):
// SSSS SSSS SSSS ILLC PPRR RRpC CCoh csff ffff ffff fffb bbbb bbbb bbNN
// 1111 1111 1111 0001 0110 0000 0100 0000 0000 0101 0101 1111 1111 1100 (eg.)
//  S = sync word                o = original/copy
//  I = ID                       h = home
//  L = layer (00)               c = copyright ID
//  C = CRC absent               s = copyright start
//  P = profile object type      f = frame length
//  R = sampling rate index      b = buffer fullness
//  p = private                  N = number of raw data blocks in frame
//  C = channel configuration

        if ($raf->Read($buff, 7) != 7) return 0;
        if (!preg_match('/^\xff[\xf0\xf1]/', $buff)) return 0;
        $t = Perl::unpack('NnC', $buff);
        if ((($t[0] >> 16) & 0x03) == 3) return 0;     // (reserved profile type)
        if ((($t[0] >> 12) & 0x0f) > 12) return 0;     // validate sampling frequency index
        $len = (($t[0] << 11) & 0x1800) | (($t[1] >> 5) & 0x07ff);
        if ($len < 7) return 0;

        $et->SetFileType();

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::AAC::Main');
        $subdirInfo = ['DataPt' => $buff];  // (Perl anonymous hash)
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        // read the first frame data to check for a filler with the encoder name
        while ($len > 8 and $raf->Read($buff, $len - 7) == $len - 7) {
            $noCRC = ($t[0] & 0x00010000);
            $blocks = ($t[2] & 0x03);
            $pos = 0;
            if (!$noCRC) $pos += 2 + 2 * $blocks;
            if ($pos + 2 > strlen($buff)) break;
            $tmp = Perl::unpack("x{$pos}n", $buff)[0];
            $id = $tmp >> 13;
            // read filler payload
            if ($id == 6) {
                $cnt = ($tmp >> 9) & 0x0f;
                ++$pos;
                if ($cnt == 15) {
                    $cnt += (($tmp >> 1) & 0xff) - 1;
                    ++$pos;
                }
                if ($pos + $cnt <= strlen($buff)) {
                    $dat = substr($buff, $pos, $cnt);
                    $dat = preg_replace('/^\0+/', '', $dat, 1);
                    $dat = preg_replace('/\0+$/', '', $dat, 1);
                    if (preg_match('/^[\x20-\x7e]+$/', $dat)) $et->HandleTag($tagTablePtr, 'Encoder', $dat);
                }
            }
            break;
        }

        return 1;
    }
}
