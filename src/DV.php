<?php

//------------------------------------------------------------------------------
// File:         DV.pm
//
// Description:  Read DV meta information
//
// Revisions:    2010/12/24 - P. Harvey Created
//
// References:   1) http://www.ffmpeg.org/
//               2) http://dvswitch.alioth.debian.org/wiki/DV_format/
//
// PHP port of Image::ExifTool::DV 1.02 (reading only). Tag tables and file lexicals
// (@dvProfiles, @dvTags) are generated into tables/DV.php and Vars\DV.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class DV
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Read information in a DV file (ref 1)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid DV file
    public static function ProcessDV(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $start = $profile = null;

        if (!$raf->Read($buff, 12000)) return 0;
        if (preg_match('/\x1f\x07\0[\x3f\xbf]/s', $buff, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + 4 - 4;
        } else {
            $p = 0;     // (pos($buff))
            while (preg_match('/[\0\xff]\x3f\x07\0.{76}\xff\x3f\x07\x01/s', $buff, $m, PREG_OFFSET_CAPTURE, $p)) {
                $p = $m[0][1] + strlen($m[0][0]);
                if ($p - 163 < 0) continue;
                $start = $p - 163;
                break;
            }
            if ($start === null) return 0;
        }
        $len = strlen($buff);
        // must at least have a full DIF header
        if ($start + 80 * 6 > $len) return 0;

        $et->SetFileType();

        $pos = $start;
        $dsf = (ExifTool::Get8u($buff, $pos + 3) & 0x80) >> 7;
        $stype = ExifTool::Get8u($buff, $pos + 80*5 + 48 + 3) & 0x1f;

        // 576i50 25Mbps 4:1:1 is a special case
        // (PHP: $profile is a reference into @dvProfiles, as in Perl)
        if ($dsf == 1 && $stype == 0 && (ExifTool::Get8u($buff, 4) & 0x07)) {
            $profile = &Vars\DV::$dvProfiles[2];
        } else {
            foreach (Vars\DV::$dvProfiles as $i => $_) {
                if (!($dsf == ($_['DSF'] ?? null) and $stype == ($_['VideoSType'] ?? null))) continue;
                $profile = &Vars\DV::$dvProfiles[$i];
                break;
            }
            if (!$profile) {
                $et->Warn('Unrecognized DV profile');
                return 1;
            }
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::DV::Main');

        // calculate total bit rate and duration
        $byteRate = $profile['FrameSize'] * $profile['FrameRate'];
        $fileSize = $et->VALUE['FileSize'] ?? null;
        $profile['TotalBitrate'] = 8 * $byteRate;
        if ($fileSize !== null) $profile['Duration'] = $fileSize / $byteRate;

        // read DVPack metadata from the VAUX DIF's to extract video tags
        unset($profile['DateTimeOriginal']);
        unset($profile['AspectRatio']);
        unset($profile['VideoScanType']);
        $date = $time = $is16_9 = $interlace = null;
        for ($i = 1; $i < 6; ++$i) {
            $pos += 80;
            $type = ExifTool::Get8u($buff, $pos);
            if (($type & 0xf0) != 0x50) continue;   // look for VAUX types
            for ($j = 0; $j < 15; ++$j) {
                $p = $pos + $j * 5 + 3;
                $type = ExifTool::Get8u($buff, $p);
                if ($type == 0x61) { // video control
                    $apt = ExifTool::Get8u($buff, $start + 4) & 0x07;
                    $t = ExifTool::Get8u($buff, $p + 2);
                    $is16_9 = (($t & 0x07) == 0x02 or (!$apt and ($t & 0x07) == 0x07));
                    $interlace = ExifTool::Get8u($buff, $p + 3) & 0x10; // (ref 2)
                } elseif ($type == 0x62) { // date
                    // mask off unused bits
                    $d = Perl::unpack('C*', substr($buff, $p + 1, 4));
                    // (ignore timezone in byte 0 until we can test this properly - see ref 2)
                    $date = Perl::sprintf('%.2x:%.2x:%.2x', $d[3] ?? null, ($d[2] ?? 0) & 0x1f, ($d[1] ?? 0) & 0x3f);
                    if (preg_match('/[a-f]/', $date)) {
                        $date = null;   // invalid date
                    } else {
                        // add century (this will work until 2089)
                        $date = (strcmp($date, '9') < 0 ? '20' : '19') . $date;
                    }
                    $time = null;
                } elseif ($type == 0x63 and $date) { // time
                    // (ignore frames past second in byte 0 for now - see ref 2)
                    $val = ExifTool::Get32u($buff, $p + 1) & 0x007f7f3f;
                    $t = Perl::unpack('C*', substr($buff, $p + 1, 4));
                    $time = Perl::sprintf('%.2x:%.2x:%.2x', ($t[3] ?? 0) & 0x3f, ($t[2] ?? 0) & 0x7f, ($t[1] ?? 0) & 0x7f);
                    break;
                } else {
                    $time = null;   // must be consecutive
                }
            }
        }
        if ($date and $time) {
            $profile['DateTimeOriginal'] = "$date $time";
            if ($is16_9 !== null) {
                $profile['AspectRatio'] = $is16_9 ? '16:9' : '4:3';
                $profile['VideoScanType'] = $interlace ? 'Interlaced' : 'Progressive';
            }
        }

        // read audio tags if available
        unset($profile['AudioSampleRate']);
        unset($profile['AudioBitsPerSample']);
        unset($profile['AudioChannels']);
        $pos = $start + 80*6 + 80*16*3 + 3;
        if ($pos + 4 < $len and ExifTool::Get8u($buff, $pos) == 0x50) {
            $smpls = ExifTool::Get8u($buff, $pos + 1);
            $freq = (ExifTool::Get8u($buff, $pos + 4) >> 3) & 0x07;
            $stype = ExifTool::Get8u($buff, $pos + 3) & 0x1f;
            $quant = ExifTool::Get8u($buff, $pos + 4) & 0x07;
            if ($freq < 3) {
                $profile['AudioSampleRate'] = [0 => 48000, 1 => 44100, 2 => 32000][$freq];
            }
            if ($stype < 3) {
                if ($stype == 0 and $quant and $freq == 2) $stype = 2;
                $profile['AudioChannels'] = [0 => 2, 1 => 0, 2 => 4, 3 => 8][$stype];
            }
            $profile['AudioBitsPerSample'] = $quant ? 12 : 16;
        }

        // save our metadata
        foreach (Vars\DV::$dvTags as $tag) {
            if (!isset($profile[$tag])) continue;
            $et->HandleTag($tagTablePtr, $tag, $profile[$tag]);
        }

        return 1;
    }
}
