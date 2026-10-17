<?php

//------------------------------------------------------------------------------
// File:         M2TS.pm
//
// Description:  Read M2TS (AVCHD) meta information
//
// Revisions:    2009/07/03 - P. Harvey Created
//
// References:   1) http://neuron2.net/library/mpeg2/iso13818-1.pdf
//               2) http://www.blu-raydisc.com/Assets/Downloadablefile/BD-RE_Part3_V2.1_WhitePaper_080406-15271.pdf
//               3) http://www.videohelp.com/forum/archive/reading-avchd-playlist-files-bdmv-playlist-mpl-t358888.html
//               4) http://en.wikipedia.org/wiki/MPEG_transport_stream
//               5) http://www.dunod.com/documents/9782100493463/49346_DVB.pdf
//               6) http://trac.handbrake.fr/browser/trunk/libhb/stream.c
//               7) http://ieeexplore.ieee.org/stamp/stamp.jsp?arnumber=04560141
//               8) http://www.w6rz.net/xport.zip
//               9) https://en.wikipedia.org/wiki/Program-specific_information
//
// Notes:        Variable names containing underlines are the same as in ref 1.
//
// Glossary:     PES = Packetized Elementary Stream
//               PAT = Program Association Table
//               PMT = Program Map Table
//               PCR = Program Clock Reference
//               PID = Packet Identifier
//
// To Do:        - parse PCR to obtain average bitrates?
//
// PHP port of Image::ExifTool::M2TS 1.34 (reading only). Tag tables are generated
// into tables/M2TS.php, file lexicals (%streamType, %tableID, %noSyntax, $knotsToKph)
// into Vars\M2TS.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class M2TS
{
    public static $VERSION = '1.34';

    //------------------------------------------------------------------------------
    // Extract information from AC-3 audio stream
    // Inputs: 0) ExifTool ref, 1) data ref
    // Reference: http://www.atsc.org/standards/a_52b.pdf
    public static function ParseAC3Audio(ExifTool $et, $dataPt)
    {
        if (preg_match('/\x0b\x77..(.)/s', $dataPt, $m)) {
            $sampleRate = ord($m[1]) >> 6;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::M2TS::AC3');
            $et->HandleTag($tagTablePtr, 'AudioSampleRate', $sampleRate);
        }
    }

    //------------------------------------------------------------------------------
    // Extract information from AC-3 stream descriptor
    // Inputs: 0) ExifTool ref, 1) data ref
    // Reference: http://www.atsc.org/standards/a_52b.pdf
    // Note: This information is duplicated in the Audio stream, but it
    //       is somewhat easier to extract it from the descriptor instead
    public static function ParseAC3Descriptor(ExifTool $et, $dataPt)
    {
        if (strlen($dataPt) < 3) return;
        $v = Perl::unpack('C3', $dataPt);
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::M2TS::AC3');
        // $et->HandleTag($tagTablePtr, 'AudioSampleRate', $v[0] >> 5);
        $et->HandleTag($tagTablePtr, 'AudioBitrate', $v[1] >> 2);
        $et->HandleTag($tagTablePtr, 'SurroundMode', $v[1] & 0x03);
        $et->HandleTag($tagTablePtr, 'AudioChannels', ($v[2] >> 1) & 0x0f);
        // don't (yet) decode any more (language codes, etc)
    }

    //------------------------------------------------------------------------------
    // Parse PID stream data
    // Inputs: 0) ExifTool ref, 1) PID number, 2) PID type, 3) PID name, 4) data ref
    // Returns: 0=stream parsed OK,
    //          1=stream parsed but we want to parse more of these,
    //          -1=can't parse yet because we don't know the type
    public static function ParsePID(ExifTool $et, $pid, $type, $pidName, $dataPt)
    {
        // can't parse until we know the type (Program Map Table may be later in the stream)
        if ($type === null) return -1;
        $verbose = $et->Options('Verbose');
        if ($verbose > 1) {
            $out = $et->Options('TextOut');
            fwrite($out, Perl::sprintf("Parsing stream 0x%.4x (%s) %d bytes\n", $pid, $pidName, strlen($dataPt)));
            $et->VerboseDump($dataPt);
        }
        $more = 0;
        if ($type == 0x01 or $type == 0x02) {
            // MPEG-1/MPEG-2 Video
            ExifTool::RequireModule('Image::ExifTool::MPEG');
            MPEG::ParseMPEGAudioVideo($et, $dataPt);
        } elseif ($type == 0x03 or $type == 0x04) {
            // MPEG-1/MPEG-2 Audio
            ExifTool::RequireModule('Image::ExifTool::MPEG');
            MPEG::ParseMPEGAudio($et, $dataPt);
        } elseif ($type == 6 and $pid == 0x0300) {
            // LIGOGPSINFO
            // unknown dashcam (../testpics/gps_video/Wrong Way pass.ts, 160 bytes, not fuzzed)
            // Pruveeo D90 dashcam (../testpics/gps_video/20260216_101326F.ts, 200 bytes, fuzzed)
            if (preg_match('/^LIGOGPSINFO/s', $dataPt)) {
                $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $dirInfo = ['DataPt' => $dataPt, 'DirName' => 'Ligo0x0300'];
                // (module may not be ported yet)
                if (ExifTool::RequireModule('Image::ExifTool::LigoGPS') and method_exists(LigoGPS::class, 'ProcessLigoGPS')) {
                    LigoGPS::ProcessLigoGPS($et, $dirInfo, $tbl, strlen($dataPt) != 200);
                }
                $et->FoundGoodGPS = 1;
                $more = 1;
            } elseif (preg_match('#^Viidure(20\d{2})/(\d{2})/(\d{2}) (\d{2}:\d{2}:\d{2}) ([NS]):(\S+) ([EW]):(\S+) (\S+) km/h (\S+) (\S+) (\S+) x:(\S+) y:(\S+) z:(\S+)#s', $dataPt, $m)) {
                // (Innovv N2 dashcam)
                // 0000: 56 69 69 64 75 72 65 32 30 32 36 2f 30 34 2f 31 [Viidure2026/04/1]
                // 0010: 36 20 30 31 3a 30 31 3a 30 32 20 4e 3a 34 32 2e [6 01:01:02 N:42.]
                // 0020: 32 31 31 34 32 34 20 57 3a 38 38 2e 33 32 30 39 [211424 W:88.3209]
                // 0030: 37 35 20 38 39 2e 31 20 6b 6d 2f 68 20 32 36 37 [75 89.1 km/h 267]
                // 0040: 2e 33 38 20 32 33 37 2e 38 30 20 31 30 20 78 3a [.38 237.80 10 x:]
                // 0050: 2d 30 2e 30 30 31 20 79 3a 2d 30 2e 30 30 31 20 [-0.001 y:-0.001 ]
                // 0060: 7a 3a 2d 30 2e 30 30 31 00 00 00 00 00 00 00 00 [z:-0.001........]
                $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $et->DOC_NUM = ++$et->DOC_COUNT;
                $time = "$m[1]:$m[2]:$m[3] $m[4]Z";
                $et->HandleTag($tbl, 'GPSDateTime',  $time);
                $et->HandleTag($tbl, 'GPSLatitude',  Perl::num($m[6]) * (($m[5] === 'S') ? -1 : 1));
                $et->HandleTag($tbl, 'GPSLongitude', Perl::num($m[8]) * (($m[7] === 'W') ? -1 : 1));
                $et->HandleTag($tbl, 'GPSSpeed',     $m[9]);
                $et->HandleTag($tbl, 'GPSTrack',     $m[10]);
                $et->HandleTag($tbl, 'GPSAltitude',  $m[11]);
                // (not sure what $12 is -- was a constant "10" in my sample)
                $et->HandleTag($tbl, 'Accelerometer', "$m[13] $m[14] $m[15]");
                $et->FoundGoodGPS = 1;
                $more = 1;
                $et->DOC_NUM = null;
            }
        } elseif ($type == 0x1b) {
            // H.264 Video
            ExifTool::RequireModule('Image::ExifTool::H264');
            $more = H264::ParseH264Video($et, $dataPt);
            // force parsing additional H264 frames with ExtractEmbedded option
            if ($et->OPTIONS['ExtractEmbedded']) {
                $more = 1;
            } elseif (!$et->OPTIONS['Validate']) {
                $et->Warn('The ExtractEmbedded option may find more tags in the video data', 7);
            }
        } elseif ($type == 0x81 or $type == 0x87 or $type == 0x91) {
            // AC-3 audio
            self::ParseAC3Audio($et, $dataPt);
        } elseif ($type == 0x15) {
            // packetized metadata (look for MISB code starting after 5-byte header)
            if (preg_match('/^.{5}\x06\x0e\x2b\x34/s', $dataPt)) {
                // (module may not be ported yet)
                $more = (ExifTool::RequireModule('Image::ExifTool::MISB') and method_exists(MISB::class, 'ParseMISB')) ?
                    MISB::ParseMISB($et, $dataPt, ExifTool::GetTagTable('Image::ExifTool::MISB::Main')) : 0;
                if (!$et->OPTIONS['ExtractEmbedded']) {
                    $more = 0;  // extract from only the first packet unless ExtractEmbedded is used
                } elseif ($et->OPTIONS['ExtractEmbedded'] > 2) {
                    $more = 1;  // read past unknown 0x15 packets if ExtractEmbedded > 2
                }
            }
// still have a lot of questions about how to decode this...
// (see https://exiftool.org/forum/index.php?topic=16486 and ../testpics/gps_video/forum16486.ts)
//    } elseif ($type == 6) {
//        my @a = unpack('x17x2NNx2nx2nx2nx2Cx2a4x2a5x2Nx2Nx2nx2Nx2Nx2Nx2nx2nx2Nx2nx2n', $$dataPt . "        ");
//        my $hi = shift @a;
//        $a[0] = Image::ExifTool::ConvertUnixTime(($a[0] + $hi * 4294967296) * 1e-6, undef, 6);
//        print "@a\n";
//        $more = 1;
        } elseif ($type < 0) {
            if (preg_match('/^(.{164})?(.{24})A[NS][EW]/s', $dataPt, $m)) {
                // (Blueskysea B4K, Novatek NT96670)
                // 0000: 01 00 ff 00 30 31 32 33 34 35 37 38 61 62 63 64 [....01234578abcd]
                // 0010: 65 66 67 0a 00 00 00 00 00 00 00 00 00 00 00 00 [efg.............]
                // 0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                // 0030: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                // 0040: 00 00 00 00 30 31 32 33 34 35 37 38 71 77 65 72 [....01234578qwer]
                // 0050: 74 79 75 69 6f 70 0a 00 00 00 00 00 00 00 00 00 [tyuiop..........]
                // 0060: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                // 0070: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                // 0080: 00 00 00 00 63 38 61 61 32 35 63 66 34 35 65 65 [....c8aa25cf45ee]
                // 0090: 61 39 65 32 34 34 32 66 61 65 62 35 65 30 39 39 [a9e2442faeb5e099]
                // 00a0: 30 37 64 34 15 00 00 00 10 00 00 00 1b 00 00 00 [07d4............]
                // 00b0: 15 00 00 00 01 00 00 00 09 00 00 00 41 4e 57 00 [............ANW.]
                // 00c0: 82 9a 57 45 98 b2 00 46 66 66 e4 41 d7 e3 14 43 [..WE...Fff.A...C]
                // 00d0: 01 00 02 00 03 00 04 00 05 00 06 00             [............]
                // (Viofo A119V3)
                // 0000: 08 00 00 00 07 00 00 00 18 00 00 00 15 00 00 00 [................]
                // 0010: 03 00 00 00 0b 00 00 00 41 4e 45 00 01 f2 ac 45 [........ANE....E]
                // 0020: 2d 7f 6e 45 b8 1e 97 41 d7 23 46 43 00 00 00 00 [-.nE...A.#FC....]
                // pad with dummy header and parse with existing FreeGPS code (minimum 92 bytes)
                $dat = str_repeat("\0", 16) . substr($dataPt, strlen($m[1] ?: '')) . str_repeat("\0", 20);
                $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $dirInfo = ['DataPt' => $dat];
                QuickTime::ProcessFreeGPS($et, $dirInfo, $tbl);
                $more = 1;
            } elseif (preg_match('/^(V00|A([NS])([EW]))\0/s', $dataPt)) {
                // INNOVV TS video (same format as INNOVV MP4)
                ExifTool::SetByteOrder('II');
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $p = 0;     // (pos($$dataPt))
                while (preg_match('/((V00|A[NS][EW])\0.{28})/', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
                    $p = $m[0][1] + strlen($m[0][0]);
                    $dat = $m[1][0];
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    if ($m[2][0] !== 'V00') {
                        $lat = abs(ExifTool::GetFloat($dat, 4));   // (abs just to be safe)
                        $lon = abs(ExifTool::GetFloat($dat, 8));   // (abs just to be safe)
                        $spd = ExifTool::GetFloat($dat, 12) * Vars\M2TS::$knotsToKph;
                        $trk = ExifTool::GetFloat($dat, 16);
                        QuickTime::ConvertLatLon($lat, $lon);
                        $et->HandleTag($tagTbl, 'GPSLatitude',  abs($lat) * (substr($dat, 1, 1) === 'S' ? -1 : 1));
                        $et->HandleTag($tagTbl, 'GPSLongitude', abs($lon) * (substr($dat, 2, 1) === 'W' ? -1 : 1));
                        $et->HandleTag($tagTbl, 'GPSSpeed',     $spd);
                        $et->HandleTag($tagTbl, 'GPSSpeedRef',  'K');
                        $et->HandleTag($tagTbl, 'GPSTrack',     $trk);
                        $et->HandleTag($tagTbl, 'GPSTrackRef',  'T');
                    }
                    $acc = Perl::unpack('x20V3', $dat);
                    foreach ($acc as &$v) {
                        if ($v >= 0x80000000) $v = $v - 4294967296;
                    }
                    unset($v);
                    $et->HandleTag($tagTbl, 'Accelerometer', implode(' ', $acc));
                }
                ExifTool::SetByteOrder('MM');
                $et->FoundGoodGPS = 1;  // (necessary to skip over empty/unknown INNOV records)
                $more = 1;
            } elseif (preg_match('/^\$(GPSINFO|GSNRINFO),/', $dataPt)) {
                // $GPSINFO,0x0004,2021.08.09 13:27:36,2341.54561,12031.70135,8.0,51,153,0,0,\x0d
                // $GSNRINFO,0.01,0.04,0.25\0
                $dataPt = strtr($dataPt, "\x0d", "\x0a");
                $dataPt = str_replace("\0", '', $dataPt);
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $lines = Perl::split('/\x0a/', $dataPt);
                $line = $lastTime = null;
                foreach ($lines as $line) {
                    if (preg_match('/^\$GPSINFO/', $line)) {
                        $a = Perl::split('/,/', $lines[0]);
                        if (!(count($a) > 7)) continue;
                        // ignore duplicate fixes
                        if ($lastTime and $a[2] === $lastTime) continue;
                        $lastTime = $a[2];
                        $et->DOC_NUM = ++$et->DOC_COUNT;
                        $a[2] = strtr($a[2], '.', ':');
                        // (untested, and probably doesn't work for S/W hemispheres)
                        [$lat, $lon] = [$a[3], $a[4]];
                        QuickTime::ConvertLatLon($lat, $lon);
                        // $a[0] - flags? values: '0x0001','0x0004','0x0008','0x0010'
                        $et->HandleTag($tagTbl, 'GPSDateTime',  $a[2]);
                        $et->HandleTag($tagTbl, 'GPSLatitude',  $lat);
                        $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
                        $et->HandleTag($tagTbl, 'GPSSpeed',     $a[5]);
                        $et->HandleTag($tagTbl, 'GPSSpeedRef',  'K');
                        // $a[6] - values: 48-60
                        $et->HandleTag($tagTbl, 'GPSTrack',     $a[7]);
                        $et->HandleTag($tagTbl, 'GPSTrackRef',  'T');
                        // #a[8,9] - always 0
                    } elseif (preg_match('/^\$GSNRINFO/', $line)) {
                        $a = Perl::split('/,/', $line);
                        array_shift($a);
                        $et->HandleTag($tagTbl, 'Accelerometer', implode(' ', $a));
                    }
                }
                $more = 1;
            } elseif (preg_match('/\$GPRMC,/', $dataPt)) {
                // Jomise T860S-GM dashcam
                // $GPRMC,hhmmss.ss,A,ddmm.mmmmm,N,dddmm.mmmmm,W,spd-kts,dir-dg,DDMMYY,,M*cs - lat,lon,spd from video
                // $GPRMC,172255.00,A,:985.95194,N,17170.14674,W,029.678,170.68,240822,,,D*7B - N47.70428,W122.15338,35mph
                // $GPRMC,192643.00,A,:987.94979,N,17171.07268,W,010.059,079.61,111122,,,A*73 - N47.71862,W122.16437,12mph
                // $GPRMC,192743.00,A,:988.72110,N,17171.04873,W,017.477,001.03,111122,,,A*78 - N47.72421,W122.16408,20mph
                // $GPRMC,192844.00,A,:989.43771,N,17171.03538,W,016.889,001.20,111122,,,A*7B - N47.72932,W122.16393,19mph
                // $GPRMC,005241.00,A,:987.70873,N,17171.81293,W,000.284,354.78,141122,,,A*7F - N47.71687,W122.17318,0mph
                // $GPRMC,005341.00,A,:987.90851,N,17171.85380,W,000.080,349.36,141122,,,A*7C - N47.71832,W122.17367,0mph
                // $GPRMC,005441.00,A,:987.94538,N,17171.21783,W,029.686,091.09,141122,,,A*7A - N47.71859,W122.16630,35mph
                // $GPRMC,002816.00,A,6820.67273,N,13424.26599,W,000.045,000.00,261122,,,A*79 - N29.52096,W95.55953,0mph (seattle)
                // $GPRMC,035136.00,A,:981.47322,N,17170.14105,W,024.594,180.50,291122,,,D*79 - N47.67180,W122.15328,28mph
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $p = 0;     // (pos($$dataPt))
                for (;;) {
                    if (!preg_match('/\$[A-Z]{2}RMC,(\d{2})(\d{2})(\d+(\.\d*)?),A?,(.{2})(\d{2}\.\d+),([NS]),(.{3})(\d{2}\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/', $dataPt, $mm, PREG_OFFSET_CAPTURE, $p)) break;
                    $p = $mm[0][1] + strlen($mm[0][0]);
                    $m = array_column($mm, 0);
                    // do some basic sanity checks on the date
                    if (!($m[13] <= 31 and $m[14] <= 12 and $m[15] <= 99)) break;
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    $year = Perl::num($m[15]) + (Perl::num($m[15]) >= 70 ? 1900 : 2000);
                    $et->HandleTag($tagTbl, 'GPSDateTime', Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', $year, $m[14], $m[13], $m[1], $m[2], $m[3]));
                    //(not this simple)
                    //$et->HandleTag($tagTbl, GPSLatitude => (($5 || 0) + $6/60) * ($7 eq 'N' ? 1 : -1));
                    //$et->HandleTag($tagTbl, GPSLongitude => (($8 || 0) + $9/60) * ($10 eq 'E' ? 1 : -1));
                    if (strlen($m[11])) $et->HandleTag($tagTbl, 'GPSSpeed', Perl::num($m[11]) * Vars\M2TS::$knotsToKph);
                    if (strlen($m[12])) $et->HandleTag($tagTbl, 'GPSTrack', $m[12]);
                    // it looks like maybe the degrees are xor-ed with something,
                    // and the minutes have some scaling factor and offset?
                    // (the code below is approximately correct for my only sample)
                    $chars = Perl::unpack('C*', $m[5] . $m[8]);
                    $xor = [0x0e,0x0e,0x00,0x05,0x03]; // (empirical based on 1 sample; may be completely off base)
                    $bad = null;
                    foreach ($chars as &$c) {
                        $c ^= (int) array_shift($xor);
                        if ($c < 0x30 or $c > 0x39) $bad = 1;
                    }
                    unset($c);
                    if ($bad) {
                        $et->Warn('Error decrypting GPS degrees');
                    } else {
                        $la = Perl::pack('C*', $chars[0], $chars[1]);
                        $lo = Perl::pack('C*', $chars[2], $chars[3], $chars[4]);
                        $et->Warn('Decryption of this GPS is highly experimental. More testing samples are required');
                        $et->HandleTag($tagTbl, 'GPSLatitude',  (Perl::num($la ?: 0) + ((Perl::num($m[6])-85.95194)/2.43051724137931+42.2568)/60) * ($m[7] === 'N' ? 1 : -1));
                        $et->HandleTag($tagTbl, 'GPSLongitude', (Perl::num($lo ?: 0) + ((Perl::num($m[9])-70.14674)/1.460987654320988+9.2028)/60) * ($m[10] === 'E' ? 1 : -1));
                    }
                }
            } elseif (preg_match('/\$GSENSORD,\s*(\d+),\s*(\d+),\s*(\d+),/', $dataPt, $m)) {
                // Jomise T860S-GM dashcam
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $et->DOC_NUM = $et->DOC_COUNT;
                $et->HandleTag($tagTbl, 'Accelerometer', "$m[1] $m[2] $m[3]"); // (NC - values range from 0 to 6)
            } elseif (preg_match('/^.{44}A\0{3}.{4}([NS])\0{3}.{4}([EW])\0{3}/s', $dataPt, $m) and strlen($dataPt) >= 84) {
                //forum11320
                ExifTool::SetByteOrder('II');
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $lat = abs(ExifTool::GetFloat($dataPt, 48)); // (abs just to be safe)
                $lon = abs(ExifTool::GetFloat($dataPt, 56)); // (abs just to be safe)
                $spd = ExifTool::GetFloat($dataPt, 64);
                $trk = ExifTool::GetFloat($dataPt, 68);
                $et->Warn('GPSLatitude/Longitude encryption is not yet known, so these will be wrong');
                $et->DOC_NUM = ++$et->DOC_COUNT;
                $date = Perl::unpack('x32V3x28V3', $dataPt);
                $date[3] += 2000;
                $et->HandleTag($tagTbl, 'GPSDateTime',  Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d', $date[3], $date[4], $date[5], $date[0], $date[1], $date[2]));
                $et->HandleTag($tagTbl, 'GPSLatitude',  abs($lat) * ($m[1] === 'S' ? -1 : 1));
                $et->HandleTag($tagTbl, 'GPSLongitude', abs($lon) * ($m[2] === 'W' ? -1 : 1));
                $et->HandleTag($tagTbl, 'GPSSpeed',     $spd);
                $et->HandleTag($tagTbl, 'GPSSpeedRef',  'K');
                $et->HandleTag($tagTbl, 'GPSTrack',     $trk);
                $et->HandleTag($tagTbl, 'GPSTrackRef',  'T');
                ExifTool::SetByteOrder('MM');
                $more = 1;
            } elseif (strlen($dataPt) >= 64 and substr($dataPt, 32, 2) === '$S') {
                // DOD_LS600W.TS
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                // find the earliest sample time in the cyclical list
                [$n, $last] = [32, "\0"];
                for ($i = 32; $i < strlen($dataPt) - 32; $i += 32) {
                    if (substr($dataPt, $n, 2) !== '$S') break;
                    $dateTime = substr($dataPt, $i + 6, 8);
                    if (strcmp($last, $dateTime) > 0) {   // earliest sample if time goes backwards
                        $n = $i;
                        break;
                    }
                    $last = $dateTime;
                }
                for ($i = 32; $i < strlen($dataPt) - 32; $i += 32, $n += 32) {
                    if ($n > strlen($dataPt) - 32) $n = 32;
                    if (substr($dataPt, $n, 2) !== '$S') break;
                    $a = Perl::unpack("x{$n}nnnnCCCCnCNNC", $dataPt);
                    $a[8] /= 10;    // 1/10 sec
                    if ($a[2] & 0x8000) $a[2] += (36000 - 65536); // convert signed integer into range 0-36000
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    $et->HandleTag($tagTbl, 'GPSDateTime',  Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%04.1fZ', ...array_slice($a, 3, 6)));
                    $et->HandleTag($tagTbl, 'GPSLatitude',  $a[10] * 1e-7);
                    $et->HandleTag($tagTbl, 'GPSLongitude', $a[11] * 1e-7);
                    $et->HandleTag($tagTbl, 'GPSSpeed',     $a[1] * 0.036); // convert from metres per 100 s
                    $et->HandleTag($tagTbl, 'GPSTrack',     $a[2] / 100);
                }
                // Note: 10 bytes after last GPS record look like a single 3-axis accelerometer reading:
                // eg. fd ff 00 00 ff ff 00 00 01 00
                $et->FoundGoodGPS = 1; // so we skip over unrecognized packets
                $more = 1;
            } elseif (preg_match('/^skip.{4}LIGOGPSINFO\0/s', $dataPt)) {
                // (this record contains 2 copies of the same 'skip' atom in my sample --
                //  only extract data from the first one)
                $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $dirInfo = ['DataPt' => $dataPt, 'DirStart' => 8, 'DirName' => Perl::sprintf('Ligo0x%.4x', $pid)];
                // (module may not be ported yet)
                if (ExifTool::RequireModule('Image::ExifTool::LigoGPS') and method_exists(LigoGPS::class, 'ProcessLigoGPS')) {
                    LigoGPS::ProcessLigoGPS($et, $dirInfo, $tbl, 1);
                }
                $et->FoundGoodGPS = 1;
            } elseif ($et->FoundGoodGPS) {
                $more = 1;
            }
            $et->DOC_NUM = null;
        }
        return $more;
    }

    //------------------------------------------------------------------------------
    // Extract information from a M2TS file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid M2TS file
    public static function ProcessM2TS(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $j = $eof = $pLen = $readSize = null;
        $pmt = $pidType = $data = $sectLen = $packLen = $fromStart = [];
        $startTime = $endTime = $fwdTime = $backScan = $maxBack = null;
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');

        // read enough to guarantee 2 sync bytes
        if ($raf->Read($buff, 383) != 383) return 0;
        // test for magic number (sync byte is the only thing we can safely check)
        if (!preg_match('/^(.{0,190}?)\x47(.{187}|.{191})\x47/s', $buff, $m)) return 0;
        $tcLen = strlen($m[2]) - 187;   // (length of timecode = 0 or 4 bytes)
        $start = strlen($m[1]) - $tcLen;
        // we may need to try the validation twice to handle the edge case
        // where the first byte of a timecode is 0x47 and we were fooled
        // into thinking there was no timecode
        for (;;) {      // (Perl label "Try")
            if ($start < 0) $start += 192;  // (if all or part of first timecode was missing)
            $pLen = 188 + $tcLen;
            $readSize = 64 * $pLen;     // size of our read buffer
            $raf->Seek($start, 0);      // rewind to start
            if (!($raf->Read($buff, $readSize) >= $pLen * 4)) return 0;  // require at least 4 packets
            // validate the sync byte in the next 3 packets
            for ($j = 1; $j < 4; ++$j) {
                if (substr($buff, $tcLen + $pLen * $j, 1) === 'G') continue; // (0x47)
                if ($tcLen) return 0;
                $tcLen = 4;
                $start -= 4;
                continue 2;     // next Try
            }
            break;  // success!
        }
        // (use M2T instead of M2TS just as an indicator that there is no timecode)
        $et->SetFileType($tcLen ? 'M2TS' : 'M2T');
        if ($start) $et->Warn("File doesn't begin with the start of a packet");
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::M2TS::Main');

        // PID lookup strings (will add to this with entries from program map table)
        $pidName = [
            0 => 'Program Association Table',
            1 => 'Conditional Access Table',
            2 => 'Transport Stream Description Table',
            0x1fff => 'Null Packet',
        ];
        $didPID = [1 => 0, 2 => 0, 0x1fff => 0];
        $needPID = [0 => 1];        // lookup for stream PID's that we still need to parse
        // PID's that may contain GPS info
        $gpsPID = [
            0x0300 => 1,    // Novatek INNOVV, DOD_LS600W
            0x01e4 => 1,    // vsys a6l dashcam
            0x0e1b => 1,    // Jomise T860S-GM dashcam GPS
            0x0e1a => 1,    // Jomise T860S-GM dashcam accelerometer
        ];
        $pEnd = 0;

        // scan entire file for GPS programs if ExtractEmbedded option is 3 or higher
        // (some dashcams write these programs but don't include it in the PMT)
        if (($et->Options('ExtractEmbedded') ?: 0) > 2) {
            foreach (array_keys($gpsPID) as $_) {
                $needPID[$_] = 1;
                $pidType[$_] = -1;
                $pidName[$_] = 'unregistered dashcam GPS';
            }
        }

        // parse packets from MPEG-2 Transport Stream
        for (;;) {

            if (!$needPID) {
                if ($startTime === null) break;
                // reconfigure to seek backwards for last PCR
                if ($backScan === null) {
                    $saveTime = $endTime;
                    $endTime = null;
                    if ($et->Options('FastScan')) break;
                    if ($verbose) fwrite($out, "[Starting backscan for last PCR]\n");
                    // remember how far we got when reading forward through the file
                    $fwdPos = $raf->Tell() - strlen($buff) + $pEnd;
                    // determine the position of the last packet relative to the EOF
                    if (!$raf->Seek(0, 2)) break;
                    $fsize = $raf->Tell();
                    $backScan = Perl::int($fsize / $pLen) * $pLen - $fsize;
                    // set limit on how far back we will go
                    $maxBack = $fwdPos - $fsize;
                    // scan back a maximum of 512k (have seen last PCR at -276k)
                    $nMax = Perl::int(512000 / $pLen);  // max packets to backscan
                    if ($nMax < Perl::int(-$maxBack / $pLen)) {
                        $maxBack = $backScan - $nMax * $pLen;
                    } else {
                        // use this time if none found in all remaining packets
                        $fwdTime = $saveTime;
                    }
                    $pEnd = 0;
                }
            }
            $pos = null;
            // read more if necessary
            if ($backScan !== null) {
                if ($endTime !== null) break;
                $pos = $pEnd = $pEnd - 2 * $pLen;   // step back to previous packet
                if ($pos < 0) {
                    // read another buffer from end of file
                    if ($backScan <= $maxBack) break;
                    $buffLen = $backScan - $maxBack;
                    if ($buffLen > $readSize) $buffLen = $readSize;
                    $backScan -= $buffLen;
                    if (!$raf->Seek($backScan, 2)) break;
                    if ($raf->Read($buff, $buffLen) != $buffLen) break;
                    $pos = $pEnd = $buffLen - $pLen;
                }
            } else {
                $pos = $pEnd;
                if ($pos + $pLen > strlen($buff)) {
                    if (!($raf->Read($buff, $readSize) >= $pLen)) {
                        $eof = 1;
                        break;
                    }
                    $pos = $pEnd = 0;
                }
            }
            $pEnd += $pLen;
            // decode the packet prefix
            $pos += $tcLen;
            $prefix = unpack('N', $buff, $pos)[1];  // (use unpack instead of Get32u for speed)
            // validate sync byte
            if (!(($prefix & 0xff000000) == 0x47000000)) {
                if ($backScan === null) $et->Warn('M2TS synchronization error');
                break;
            }
          // $transport_error_indicator    = $prefix & 0x00800000;
            $payload_unit_start_indicator = $prefix & 0x00400000;
          // $transport_priority           = $prefix & 0x00200000;
            $pid                          =($prefix & 0x001fff00) >> 8; // packet ID
          // $transport_scrambling_control = $prefix & 0x000000c0;
            $adaptation_field_exists      = $prefix & 0x00000020;
            $payload_data_exists          = $prefix & 0x00000010;
          // $continuity_counter           = $prefix & 0x0000000f;
            if ($verbose > 1) {
                $i = ($raf->Tell() - strlen($buff) + $pEnd) / $pLen - 1;
                fwrite($out, 'Transport packet ' . Perl::str($i) . ":\n");
                $et->VerboseDump($buff, Len: $pLen, Addr: $i * $pLen, Start: $pos - $tcLen);
                $str = !empty($pidName[$pid]) ? " ($pidName[$pid])" : ' <not in Program Map Table!>';
                if ($pLen == 192) fwrite($out, Perl::sprintf("  Timecode:   0x%.4x\n", ExifTool::Get32u($buff, $pos - $tcLen)));
                fwrite($out, Perl::sprintf("  Packet ID:  0x%.4x$str\n", $pid));
                fwrite($out, Perl::sprintf("  Start Flag: %s\n", $payload_unit_start_indicator ? 'Yes' : 'No'));
            }

            $pos += 4;
            // handle adaptation field
            if ($adaptation_field_exists) {
                $len = ExifTool::Get8u($buff, $pos++);
                if ($pos + $len > $pEnd) {
                    $et->Warn('Invalid adaptation field length');
                    break;
                }
                // read PCR value for calculation of Duration
                if ($len > 6) {
                    $flags = ExifTool::Get8u($buff, $pos);
                    if ($flags & 0x10) { // PCR_flag
                        // combine 33-bit program_clock_reference_base and 9-bit extension
                        $pcrBase = ExifTool::Get32u($buff, $pos + 1);
                        $pcrExt  = ExifTool::Get16u($buff, $pos + 5);
                        // ignore separate programs (PID's) and store just the
                        // first and last timestamps found in the file (is this OK?)
                        $endTime = 300 * (2 * $pcrBase + ($pcrExt >> 15)) + ($pcrExt & 0x01ff);
                        if ($startTime === null) $startTime = $endTime;
                    }
                }
                $pos += $len;
            }

            // all done with this packet unless it carries a payload
            // or if we are just looking for the last timestamp
            if (!($payload_data_exists and $backScan === null)) continue;

           // decode payload data
            if ($pid == 0 or            // program association table
                isset($pmt[$pid]))      // program map table(s)
            {
                // must interpret pointer field if payload_unit_start_indicator is set
                $buf2 = null;
                if ($payload_unit_start_indicator) {
                    // skip to start of section
                    $pointer_field = ExifTool::Get8u($buff, $pos);
                    $pos += 1 + $pointer_field;
                    if ($pos >= $pEnd) {
                        $et->Warn('Bad pointer field');
                        break;
                    }
                    $buf2 = substr($buff, $pEnd - $pLen, $pLen);
                    $pos -= $pEnd - $pLen;
                } else {
                    // not the start of a section
                    if (empty($sectLen[$pid])) continue;
                    $more = $sectLen[$pid] - strlen($data[$pid] ?? '');
                    $size = $pLen - $pos;
                    if ($size > $more) $size = $more;
                    $data[$pid] = ($data[$pid] ?? '') . substr($buff, $pos, $size);
                    if ($size != $more) continue;
                    // we have the complete section now, so put into $buf2 for parsing
                    $buf2 = $data[$pid];
                    $pos = 0;
                    unset($data[$pid]);
                    unset($fromStart[$pid]);
                    unset($sectLen[$pid]);
                }
                $slen = strlen($buf2);  // section length
                if ($pos + 8 > $slen) {
                    $et->Warn('Truncated payload');
                    break;
                }
                // validate table ID
                $table_id = ExifTool::Get8u($buf2, $pos);
                $name = ((Vars\M2TS::$tableID[$table_id] ?? null) ?: Perl::sprintf('Unknown (0x%x)', $table_id)) . ' Table';
                $expectedID = $pid ? 0x02 : 0x00;
                if (!($table_id == $expectedID)) {
                    if ($verbose > 1) fwrite($out, "  (skipping $name)\n");
                    unset($needPID[$pid]);
                    $didPID[$pid] = 1;
                    continue;
                }
                // validate section syntax indicator for parsed tables (PAT, PMT)
                $section_syntax_indicator = ExifTool::Get8u($buf2, $pos + 1) & 0xc0;
                if ($section_syntax_indicator != 0x80) {
                    $et->Warn("Bad $name");
                    break;
                }
                $section_length = ExifTool::Get16u($buf2, $pos + 1) & 0x0fff;
                if ($section_length > 1021) {
                    $et->Warn("Invalid $name length");
                    break;
                }
                if ($slen < $section_length + 3) { // (3 bytes for table_id + section_length)
                    // must wait until we have the full section
                    $data[$pid] = substr($buf2, $pos);
                    $sectLen[$pid] = $section_length + 3;
                    continue;
                }
                $program_number = ExifTool::Get16u($buf2, $pos + 3);
                $section_number = ExifTool::Get8u($buf2, $pos + 6);
                $last_section_number = ExifTool::Get8u($buf2, $pos + 7);
                if ($verbose > 1) {
                    fwrite($out, "  $name length: $section_length\n");
                    if ($pid) fwrite($out, "  Program No: $program_number\n");
                    if (!$pid) fwrite($out, Perl::sprintf("  Stream ID:  0x%x\n", $program_number));
                    fwrite($out, "  Section No: $section_number\n");
                    fwrite($out, "  Last Sect.: $last_section_number\n");
                }
                $end = $pos + $section_length + 3 - 4; // (don't read 4-byte CRC)
                $pos += 8;
                if ($pid == 0) {
                    // decode PAT (Program Association Table)
                    while ($pos <= $end - 4) {
                        $program_number = ExifTool::Get16u($buf2, $pos);
                        $program_map_PID = ExifTool::Get16u($buf2, $pos + 2) & 0x1fff;
                        $pmt[$program_map_PID] = $program_number; // save our PMT PID's
                        $str = "Program $program_number Map";
                        $pidName[$program_map_PID] = $str;
                        if (empty($didPID[$program_map_PID])) $needPID[$program_map_PID] = 1;
                        if ($verbose) fwrite($out, Perl::sprintf("  PID(0x%.4x) --> $str\n", $program_map_PID));
                        $pos += 4;
                    }
                } else {
                    // decode PMT (Program Map Table)
                    if ($pos + 4 > $slen) {
                        $et->Warn('Truncated PMT');
                        break;
                    }
                    $pcr_pid = ExifTool::Get16u($buf2, $pos) & 0x1fff;
                    $program_info_length = ExifTool::Get16u($buf2, $pos + 2) & 0x0fff;
                    $str = "Program $program_number Clock Reference";
                    $pidName[$pcr_pid] = $str;
                    if ($verbose) fwrite($out, Perl::sprintf("  PID(0x%.4x) --> $str\n", $pcr_pid));
                    $pos += 4;
                    if ($pos + $program_info_length > $slen) {
                        $et->Warn('Truncated program info');
                        break;
                    }
                    // dump program information descriptors if verbose
                    if ($verbose > 1) { for ($j = 0; $j < $program_info_length - 2; ) {
                        $descriptor_tag = ExifTool::Get8u($buf2, $pos + $j);
                        $descriptor_length = ExifTool::Get8u($buf2, $pos + $j + 1);
                        $j += 2;
                        if ($j + $descriptor_length > $program_info_length) break;
                        $desc = substr($buf2, $pos + $j, $descriptor_length);
                        $j += $descriptor_length;
                        $desc = preg_replace_callback('/([\x00-\x1f\x7f-\xff])/', static fn ($mt) => Perl::sprintf('\\x%.2x', ord($mt[1])), $desc);
                        fwrite($out, Perl::sprintf("    Program Descriptor: Type=0x%.2x \"$desc\"\n", $descriptor_tag));
                    }}
                    $pos += $program_info_length; // skip descriptors (for now)
                    while ($pos <= $end - 5) {
                        $stream_type = ExifTool::Get8u($buf2, $pos);
                        $elementary_pid = ExifTool::Get16u($buf2, $pos + 1) & 0x1fff;
                        $es_info_length = ExifTool::Get16u($buf2, $pos + 3) & 0x0fff;
                        $str = Vars\M2TS::$streamType[$stream_type] ?? null;
                        if (!$str) $str = ($stream_type < 0x7f ? 'Reserved' : 'Private');
                        $str = Perl::sprintf('%s (0x%.2x)', $str, $stream_type);
                        $str = "Program $program_number $str";
                        if ($verbose) fwrite($out, Perl::sprintf("  PID(0x%.4x) --> $str\n", $elementary_pid));
                        if (preg_match('/(Audio|Video)/', $str, $m)) {
                            if (empty($pidName[$elementary_pid])) {
                                $et->HandleTag($tagTablePtr, $m[1] . 'StreamType', $stream_type);
                            }
                            // we want to parse all Audio and Video streams
                            if (empty($didPID[$elementary_pid])) $needPID[$elementary_pid] = 1;
                        }
                        // save PID type and name string
                        $pidName[$elementary_pid] = $str;
                        $pidType[$elementary_pid] = $stream_type;
                        $pos += 5;
                        if ($pos + $es_info_length > $slen) {
                            $et->Warn('Truncated ES info');
                            $pos = $end;
                            break;
                        }
                        // parse elementary stream descriptors
                        for ($j = 0; $j < $es_info_length - 2; ) {
                            $descriptor_tag = ExifTool::Get8u($buf2, $pos + $j);
                            $descriptor_length = ExifTool::Get8u($buf2, $pos + $j + 1);
                            $j += 2;
                            if ($j + $descriptor_length > $es_info_length) break;
                            $desc = substr($buf2, $pos + $j, $descriptor_length);
                            $j += $descriptor_length;
                            if ($verbose > 1) {
                                $dstr = $desc;
                                $dstr = preg_replace_callback('/([\x00-\x1f\x7f-\xff])/', static fn ($mt) => Perl::sprintf('\\x%.2x', ord($mt[1])), $dstr);
                                fwrite($out, Perl::sprintf("    ES Descriptor: Type=0x%.2x \"$dstr\"\n", $descriptor_tag));
                            }
                            // parse type-specific descriptor information (once)
                            if (empty($didPID[$pid])) {
                                if ($descriptor_tag == 0x81) {  // AC-3
                                    self::ParseAC3Descriptor($et, $desc);
                                }
                            }
                        }
                        $pos += $es_info_length;
                    }
                }
                // $pos = $end + 4; # skip CRC

            } elseif (!array_key_exists($pid, $didPID)) {

                // save data from the start of each elementary stream
                if ($payload_unit_start_indicator) {
                    if (isset($data[$pid])) {
                        // we must have a whole section, so parse now
                        $more = self::ParsePID($et, $pid, $pidType[$pid] ?? null, $pidName[$pid] ?? null, $data[$pid]);
                        // start fresh even if we couldn't process this PID yet
                        unset($data[$pid]);
                        unset($fromStart[$pid]);
                        if (!$more) {
                            unset($needPID[$pid]);
                            $didPID[$pid] = 1;
                            continue;
                        }
                        // set flag indicating we found this PID but we still want more
                        $needPID[$pid] = -1;
                    }
                    // check for a PES header
                    if ($pos + 6 > $pEnd) continue;
                    $start_code = ExifTool::Get32u($buff, $pos);
                    if (!(($start_code & 0xffffff00) == 0x00000100)) continue;
                    $stream_id = $start_code & 0xff;
                    $pes_packet_length = ExifTool::Get16u($buff, $pos + 4);
                    if ($verbose > 1) {
                        fwrite($out, Perl::sprintf("  Stream ID:  0x%.2x\n", $stream_id));
                        fwrite($out, "  Packet Len: $pes_packet_length\n");
                    }
                    $pos += 6;
                    if (empty(Vars\M2TS::$noSyntax[$stream_id])) {
                        if ($pos + 3 > $pEnd) continue;
                        // validate PES syntax
                        $syntax = ExifTool::Get8u($buff, $pos) & 0xc0;
                        if ($syntax != 0x80) {
                            $et->Warn('Bad PES syntax');
                            continue;
                        }
                        // skip PES header
                        $pes_header_data_length = ExifTool::Get8u($buff, $pos + 2);
                        $pos += 3 + $pes_header_data_length;
                        if ($pos >= $pEnd) continue;
                    }
                    $data[$pid] = substr($buff, $pos, $pEnd - $pos);
                    // set flag that we read this payload from the start
                    $fromStart[$pid] = 1;
                    // save the packet length
                    if ($pes_packet_length > 8) {
                        $packLen[$pid] = $pes_packet_length - 8; // (where are the 8 extra bytes? - PH)
                    } else {
                        unset($packLen[$pid]);
                    }
                } else {
                    if (!isset($data[$pid])) {
                        // (vsys a6l dashcam GPS record doesn't have a start indicator)
                        if (empty($gpsPID[$pid])) continue;
                        $data[$pid] = '';
                    }
                    // accumulate data for each elementary stream
                    $data[$pid] .= substr($buff, $pos, $pEnd - $pos);
                }
                // save only the first 256 bytes of most streams, except for
                // unknown, H.264 or metadata streams where we save up to 1 kB
                $saveLen = null;
                if (empty($pidType[$pid]) or $pidType[$pid] == 0x1b) {
                    $saveLen = 1024;
                } elseif ($pidType[$pid] == 0x15) {
                    // use 1024 or actual size of metadata packet if smaller
                    $saveLen = 1024;
                    if (isset($packLen[$pid]) and $saveLen > $packLen[$pid]) $saveLen = $packLen[$pid];
                } else {
                    $saveLen = 256;
                }
                if (strlen($data[$pid]) >= $saveLen) {
                    $more = self::ParsePID($et, $pid, $pidType[$pid] ?? null, $pidName[$pid] ?? null, $data[$pid]);
                    if ($more < 0) continue;  // wait for program map table (hopefully not too long)
                    // don't stop parsing if we weren't successful and may have missed the start
                    if (!$more and empty($fromStart[$pid])) $more = 1;
                    unset($data[$pid]);
                    unset($fromStart[$pid]);
                    if ($more) {
                        $needPID[$pid] = -1;
                        continue;   // parse more of these
                    }
                    unset($needPID[$pid]);
                    $didPID[$pid] = 1;
                }
                continue;
            }
            if (!empty($needPID[$pid])) {
                // we found and parsed a section with this PID, so
                // delete from the lookup of PID's we still need to parse
                unset($needPID[$pid]);
                $didPID[$pid] = 1;
            }
        }

        // calculate Duration if available
        if ($endTime === null) $endTime = $fwdTime;
        if ($startTime !== null and $endTime !== null) {
            if ($startTime > $endTime) $endTime += 0x80000000 * 1200; // handle 33-bit wrap
            $et->HandleTag($tagTablePtr, 'Duration', $endTime - $startTime);
        }

        if ($verbose) {
            $need = [];
            foreach (array_keys($needPID) as $_) {
                if ($needPID[$_] > 0) $need[] = Perl::sprintf('0x%.2x', $_);
            }
            if ($need) {
                sort($need, SORT_STRING);
                fwrite($out, 'End of file.  Missing PID(s): ' . implode(' ', $need) . "\n");
            } else {
                $what = $eof ? 'of file' : 'scan';
                fwrite($out, "End $what.  All PID's parsed.\n");
            }
        }

        // parse any remaining partial PID streams
        $pids = array_keys($data);
        sort($pids, SORT_STRING);
        foreach ($pids as $pid) {
            self::ParsePID($et, $pid, $pidType[$pid] ?? null, $pidName[$pid] ?? null, $data[$pid]);
            unset($data[$pid]);
        }

        // look for LIGOGPSINFO trailer
        if ($et->Options('ExtractEmbedded') and
            $raf->Seek(-8, 2) and $raf->Read($buff, 8) == 8 and
            preg_match('/^&&&&/', $buff))
        {
            $len = Perl::unpack('x4N', $buff)[0];
            if ($len < $raf->Tell() and $raf->Seek(-$len, 2) and $raf->Read($buff, $len) == $len) {
                $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $di = ['DataPt' => $buff, 'DirStart' => 8, 'DirName' => 'LigoTrailer'];  // (Perl %dirInfo)
                // (module may not be ported yet)
                if (ExifTool::RequireModule('Image::ExifTool::LigoGPS') and method_exists(LigoGPS::class, 'ProcessLigoGPS')) {
                    LigoGPS::ProcessLigoGPS($et, $di, $tbl);
                }
            }
        }

        return 1;
    }
}
