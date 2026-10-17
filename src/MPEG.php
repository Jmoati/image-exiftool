<?php

//------------------------------------------------------------------------------
// File:         MPEG.pm
//
// Description:  Read MPEG-1 and MPEG-2 meta information
//
// Revisions:    05/11/2006 - P. Harvey Created
//
// References:   1) http://www.mp3-tech.org/
//               2) http://www.getid3.org/
//               3) http://dvd.sourceforge.net/dvdinfo/dvdmpeg.html
//               4) http://ffmpeg.org/
//               5) http://sourceforge.net/projects/mediainfo/
//
// PHP port of Image::ExifTool::MPEG 1.17 (reading only). Tag tables are generated
// into tables/MPEG.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MPEG
{
    public static $VERSION = '1.17';

    //------------------------------------------------------------------------------
    // Process information in an MPEG audio or video frame header
    // Inputs: 0) ExifTool object ref, 1) tag table ref, 2-N) list of 32-bit data words
    public static function ProcessFrameHeader(ExifTool $et, TagTable $tagTablePtr, ...$data)
    {
        $keys = $tagTablePtr->keys();
        sort($keys, SORT_STRING);
        foreach ($keys as $tag) {
            if (!preg_match('/^Bit(\d{2})-?(\d{2})?/', (string) $tag, $m)) continue;
            [$b1, $b2] = [(int) $m[1], (int) (($m[2] ?? null) ?: $m[1])];
            $index = Perl::int($b1 / 32);
            $word = $data[$index] ?? 0;
            $mask = 0;
            for ($_ = 0; $_ <= $b2 - $b1; ++$_) {
                $mask += (1 << $_);
            }
            $val = ($word >> (31 + 32*$index - $b2)) & $mask;
            $et->HandleTag($tagTablePtr, $tag, $val);
        }
    }

    //------------------------------------------------------------------------------
    // Read MPEG audio frame header
    // Inputs: 0) ExifTool object reference, 1) Reference to audio data
    //         2) flag set if we are trying to recognized MP3 file only
    // Returns: 1 on success, 0 if no audio header was found
    public static function ParseMPEGAudio(ExifTool $et, $buffPt, $mp3 = null)
    {
        $word = $pos = null;
        $ext = $et->FILE_EXT ?: '';

        $p = 0;     // (pos($$buffPt))
        for (;;) {
            // find frame sync
            if (!preg_match('/(\xff.{3})/s', $buffPt, $m, PREG_OFFSET_CAPTURE, $p)) return 0;
            $p = $m[0][1] + 4;
            $word = Perl::unpack('N', $m[1][0])[0] ?? null;    // get audio frame header word
            if (($word & 0xffe00000) != 0xffe00000) {
                $p = $p - 2;    // next possible location for frame sync
                continue;
            }
            // validate header as much as possible
            if (($word & 0x180000) == 0x080000 or   // 01 is a reserved version ID
                ($word & 0x060000) == 0x000000 or   // 00 is a reserved layer description
                ($word & 0x00f000) == 0x000000 or   // 0000 is the "free" bitrate index
                ($word & 0x00f000) == 0x00f000 or   // 1111 is a bad bitrate index
                ($word & 0x000c00) == 0x000c00 or   // 11 is a reserved sampling frequency
                ($word & 0x000003) == 0x000002 or   // 10 is a reserved emphasis
                (($mp3 and ($word & 0x060000) != 0x020000))) // must be layer 3 for MP3
            {
                // give up easily unless this really should be an MP3 file
                if ($ext !== 'MP3') return 0;
                $p = $p - 1;
                continue;
            }
            $pos = $p;
            break;
        }
        // set file type if not done already
        $et->SetFileType();

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MPEG::Audio');
        self::ProcessFrameHeader($et, $tagTablePtr, $word);

        // extract the VBR information (ref MP3::Info)
        [$v, $m] = [$et->MPEG_Vers, $et->MPEG_Mode];
        while ($v !== null and $m !== null) {
            $len = strlen($buffPt);
            $pos += $v == 3 ? ($m == 3 ? 17 : 32) : ($m == 3 ?  9 : 17);
            if ($pos + 8 > $len) break;
            $buff = substr($buffPt, $pos, 8);
            if (!preg_match('/^(Xing|Info)/', $buff)) break;
            $xingTable = ExifTool::GetTagTable('Image::ExifTool::MPEG::Xing');
            $vbrScale = null;
            $flags = Perl::unpack('x4N', $buff)[0] ?? null;
            $isVBR = !preg_match('/^Info/', $buff);     // Info frame is not VBR (ref 5)
            $pos += 8;
            if ($flags & 0x01) {    // VBRFrames
                if ($pos + 4 > $len) break;
                if ($isVBR) $et->HandleTag($xingTable, 1, Perl::unpack("x{$pos}N", $buffPt)[0] ?? null);
                $pos += 4;
            }
            if ($flags & 0x02) {    // VBRBytes
                if ($pos + 4 > $len) break;
                if ($isVBR) $et->HandleTag($xingTable, 2, Perl::unpack("x{$pos}N", $buffPt)[0] ?? null);
                $pos += 4;
            }
            if ($flags & 0x04) {    // VBR_TOC
                if ($pos + 100 > $len) break;
                // (ignore toc for now)
                $pos += 100;
            }
            if ($flags & 0x08) {    // VBRScale
                if ($pos + 4 > $len) break;
                $vbrScale = Perl::unpack("x{$pos}N", $buffPt)[0] ?? null;
                if ($isVBR) $et->HandleTag($xingTable, 3, $vbrScale);
                $pos += 4;
            }
            // process Lame header (ref 5)
            if ($flags & 0x10) {    // Lame
                if ($pos + 348 > $len) break;
            } elseif ($pos + 4 <= $len) {
                $lib = substr($buffPt, $pos, 4);
                if (!($lib === 'LAME' or $lib === 'GOGO')) {
                    // attempt to identify other encoders
                    if (Perl::index($buffPt, 'RCA mp3PRO Encoder') >= 0) {
                        $lib = 'RCA mp3PRO';
                    } elseif (($n = Perl::index($buffPt, 'THOMSON mp3PRO Encoder')) >= 0) {
                        $lib = 'Thomson mp3PRO';
                        $n += 22;
                        if (strlen($buffPt) - $n >= 6) $lib .= ' ' . substr($buffPt, $n, 6);
                    } elseif (Perl::index($buffPt, 'MPGE') >= 0) {
                        $lib = 'Gogo (<3.0)';
                    } else {
                        break;
                    }
                    $et->HandleTag($xingTable, 4, $lib);
                    break;
                }
            }
            $lameLen = $len - $pos;
            if ($lameLen < 9) break;
            $enc = substr($buffPt, $pos, 9);
            if (strcmp($enc, 'LAME3.90') >= 0) {
                $et->HandleTag($xingTable, 4, $enc);
                if (Perl::num($vbrScale) <= 100) {
                    $et->HandleTag($xingTable, 5, Perl::int((100 - Perl::num($vbrScale)) / 10));
                    $et->HandleTag($xingTable, 6, Perl::mod(100 - Perl::num($vbrScale), 10));
                }
                $dirInfo = [
                    'DataPt'   => $buffPt,
                    'DirStart' => $pos,
                    'DirLen'   => strlen($buffPt) - $pos,
                ];
                $subTablePtr = ExifTool::GetTagTable('Image::ExifTool::MPEG::Lame');
                $et->ProcessDirectory($dirInfo, $subTablePtr);
            } else {
                $et->HandleTag($xingTable, 4, substr($buffPt, $pos, 20));
            }
            break;  // (didn't want to loop anyway)
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Read MPEG video frame header
    // Inputs: 0) ExifTool object reference, 1) Reference to video data
    // Returns: 1 on success, 0 if no video header was found
    public static function ProcessMPEGVideo(ExifTool $et, $buffPt)
    {
        if (!(strlen($buffPt) >= 4)) return 0;
        [$w1, $w2] = Perl::unpack('N2', $buffPt) + [null, null];
        // validate as much as possible
        if (($w1 & 0x000000f0) == 0x00000000 or     // 0000 is a forbidden aspect ratio
            ($w1 & 0x000000f0) == 0x000000f0 or     // 1111 is a reserved aspect ratio
            ($w1 & 0x0000000f) == 0 or              // frame rate must be 1-8
            ($w1 & 0x0000000f) > 8)
        {
            return 0;
        }
        // set file type if not done already
        if (!$et->FileType) $et->SetFileType('MPEG');

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MPEG::Video');
        self::ProcessFrameHeader($et, $tagTablePtr, $w1, $w2);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read MPEG audio and video frame headers
    // Inputs: 0) ExifTool object reference, 1) Reference to audio/video data
    // Returns: 1 on success, 0 if no video header was found
    // To Do: Properly parse MPEG streams:
    //   0xb7 - sequence end
    //   0xb9 - end code
    //   0xba - pack start code
    //   0xbb - system header
    //   0xbc - program map <-- should parse this
    //   0xbd - private stream 1 --> for VOB, this contains sub-streams:
    //           0x20-0x3f - pictures
    //           0x80-0x87 - audio (AC3,DTS,SDDS)
    //           0xa0-0xa7 - audio (LPCM)
    //   0xbe - padding
    //   0xbf - private stream 2
    //   0xc0-0xdf - audio stream
    //   0xe0-0xef - video stream
    public static function ParseMPEGAudioVideo(ExifTool $et, $buffPt)
    {
        $found = [];
        $didHdr = null;
        $rtnVal = 0;
        $proc = ['audio' => self::ParseMPEGAudio(...), 'video' => self::ProcessMPEGVideo(...)];

        unset($et->AudioBitrate);
        unset($et->VideoBitrate);

        $p = 0;     // (pos($$buffPt))
        while (preg_match('/\0\0\x01(\xb3|\xc0)/', $buffPt, $m, PREG_OFFSET_CAPTURE, $p)) {
            $p = $m[0][1] + 4;
            $type = $m[1][0] === "\xb3" ? 'video' : 'audio';
            if (!$didHdr) {
                // make sure we didn't miss an audio frame sync before this (eg. MP3 file)
                // (the last byte of the 4-byte MP3 audio frame header word may be zero,
                // but the 2nd last must be non-zero, so we need only check to pos-3)
                $buff = substr($buffPt, 0, $p - 3);
                if (self::ParseMPEGAudio($et, $buff)) $found['audio'] = 1;
                $didHdr = 1;
            }
            if (!empty($found[$type])) continue;
            $len = strlen($buffPt) - $p;
            if ($len < 4) break;
            if ($len > 256) $len = 256;
            $dat = substr($buffPt, $p, $len);
            // process MPEG audio or video
            if ($proc[$type]($et, $dat)) {
                $rtnVal = 1;
                $found[$type] = 1;
                // done if we found audio and video
                if (count($found) == 2) break;
            }
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Read information from an MPEG file
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid MPEG file
    public static function ProcessMPEG(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if ($raf->Read($buff, 4) != 4) return 0;
        if (!preg_match('/^\0\0\x01[\xb0-\xbf]/', $buff)) return 0;
        $et->SetFileType();

        $raf->Seek(0, 0);
        if (!$raf->Read($buff, 65536*4)) return 0;

        return self::ParseMPEGAudioVideo($et, $buff);
    }
}
