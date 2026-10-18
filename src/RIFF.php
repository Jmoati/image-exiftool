<?php

//------------------------------------------------------------------------------
// File:         RIFF.pm
//
// Description:  Read RIFF/AVI/WAV meta information
//
// Revisions:    09/14/2005 - P. Harvey Created
//               06/28/2017 - PH Added MBWF/RF64 support
//
// References:   1) http://www.exif.org/Exif2-2.PDF
//               2) http://www.vlsi.fi/datasheets/vs1011.pdf
//               3) http://www.music-center.com.br/spec_rif.htm
//               4) http://www.codeproject.com/audio/wavefiles.asp
//               5) http://msdn.microsoft.com/archive/en-us/directx9_c/directx/htm/avirifffilereference.asp
//               6) http://research.microsoft.com/invisible/tests/riff.h.htm
//               7) http://www.onicos.com/staff/iz/formats/wav.html
//               8) http://graphics.cs.uni-sb.de/NMM/dist-0.9.1/Docs/Doxygen/html/mmreg_8h-source.html
//               9) http://developers.videolan.org/vlc/vlc/doc/doxygen/html/codecs_8h-source.html
//              10) http://wiki.multimedia.cx/index.php?title=TwoCC
//              11) Andreas Winter (SCLive) private communication
//              12) http://abcavi.kibi.ru/infotags.htm
//              13) http://tech.ebu.ch/docs/tech/tech3285.pdf
//              14) https://developers.google.com/speed/webp/docs/riff_container
//              15) https://tech.ebu.ch/docs/tech/tech3306-2009.pdf
//              16) https://sites.google.com/site/musicgapi/technical-documents/wav-file-format
//
// PHP port of Image::ExifTool::RIFF 1.73 (the writer, WriteRIFF.pl, is the trait
// RIFF\WriteRIFF). Tag tables and file lexicals
// (%isImageData, %riffType, %riffMimeType, %monthNum...) are generated into tables/RIFF.php
// and Vars\RIFF.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class RIFF
{
    use RIFF\WriteRIFF;

    public static $VERSION = '1.73';

    //------------------------------------------------------------------------------
    // Convert RIFF date to EXIF format
    public static function ConvertRIFFDate($val)
    {
        $part = Perl::split(' ', $val);
        $mon = null;
        if (count($part) >= 5 and $mon = (Vars\RIFF::$monthNum[ucfirst(strtolower($part[1]))] ?? null)) {
            // the standard AVI date format (eg. "Mon Mar 10 15:04:43 2003")
            $val = Perl::sprintf('%.4d:%.2d:%.2d %s', $part[4],
                                 $mon, $part[2], $part[3]);
        } elseif (preg_match('{(\d{4})/\s*(\d+)/\s*(\d+)/?\s+(\d+):\s*(\d+)\s*(P?)}', Perl::str($val), $m)) {
            // but the Casio QV-3EX writes dates like "2001/ 1/27  1:42PM",
            // and the Casio EX-Z30 writes "2005/11/28/ 09:19"... doh!
            $val = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:00', $m[1], $m[2], $m[3], Perl::num($m[4]) + ($m[6] ? 12 : 0), $m[5]);
        } elseif (preg_match('{(\d{4})[-/](\d+)[-/](\d+)\s+(\d+:\d+:\d+)}', Perl::str($val), $m)) {
            // the Konica KD500Z writes "2002-12-16  15:35:01\0\0"
            $val = "$m[1]:$m[2]:$m[3] $m[4]";
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Print time
    // Inputs: 0) time in seconds
    // Returns: time string
    public static function ConvertTimecode($val)
    {
        $val = Perl::num($val);
        $hr = Perl::int($val / 3600);
        $val -= $hr * 3600;
        $min = Perl::int($val / 60);
        $val -= $min * 60;
        $ss = Perl::sprintf('%05.2f', $val);
        if ($ss >= 60) {    // handle round-off problems
            $ss = '00.00';
            if (++$min >= 60) {
                $min -= 60;
                ++$hr;
            }
        }
        return Perl::sprintf('%d:%.2d:%s', $hr, $min, $ss);
    }

    //------------------------------------------------------------------------------
    // Calculate duration of RIFF
    // Inputs: 0) ExifTool ref, 1/2) RIFF:FrameRate/Count, 2/3) VideoFrameRate/Count
    // Returns: Duration in seconds or undef
    // Notes: Sums duration of all sub-documents (concatenated AVI files)
    public static function CalcDuration(ExifTool $et, ...$val)
    {
        $totalDuration = 0;
        $subDoc = 0;
        $keyList = [];
        for (;;) {
            // this is annoying.  Apparently (although I couldn't verify this), FrameCount
            // in the RIFF header includes multiple video tracks if they exist (eg. with the
            // FujiFilm REAL 3D AVI's), but the video stream information isn't reliable for
            // some cameras (eg. Olympus FE models), so use the video stream information
            // only if the RIFF header duration is 2 to 3 times longer
            // (also see https://github.com/exiftool/exiftool/issues/371)
            $dur1 = null;
            if ($val[0] ?? null) $dur1 = Perl::num($val[1] ?? null) / Perl::num($val[0]);
            if (($val[2] ?? null) and ($val[3] ?? null)) {
                $dur2 = Perl::num($val[3]) / Perl::num($val[2]);
                $rat = Perl::num($dur1) / $dur2;
                if ($rat > 1.9 and $rat < 3.1) $dur1 = $dur2;
            }
            if ($dur1 !== null) $totalDuration += $dur1;
            if (!($subDoc++ < $et->DOC_COUNT)) break;
            // get tag values for next sub-document
            $tags = ['FrameRate', 'FrameCount', 'VideoFrameRate', 'VideoFrameCount'];
            $rawValue = $et->VALUE;
            for ($i = 0; $i < count($tags); ++$i) {
                if ($subDoc == 1) {
                    // generate list of available keys for each tag
                    $keys = $keyList[$i] = [];
                    for ($j = 0; ; ++$j) {
                        $key = $tags[$i];
                        if ($j) $key .= " ($j)";
                        if (!isset($rawValue[$key])) break;
                        $keys[] = $key;
                    }
                    $keyList[$i] = $keys;
                } else {
                    $keys = $keyList[$i];
                }
                // find key for tag in this sub-document
                $grp = "Doc$subDoc";
                if ($i < 2) $grp .= ':RIFF';    // (tags 0 and 1 also in RIFF group)
                $key = $et->GroupMatches($grp, $keys);
                $val[$i] = $key ? $rawValue[$key] : null;
            }
            if (!(isset($val[0]) and isset($val[1]))) break;    // (Require'd tags)
        }
        return $totalDuration;
    }

    //------------------------------------------------------------------------------
    // Process stream data
    // Inputs: 0) ExifTool object ref, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessStreamData(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $size = $dirInfo['DirLen'];
        if ($size < 4) return 0;
        if ($et->Options('Verbose')) {
            $et->VerboseDir($dirInfo['DirName'] ?? null, 0, $size);
        }
        $tag = substr($dataPt, $start, 4);
        $tagInfo = $et->GetTagInfo($tagTbl, $tag);
        if (!$tagInfo) {
            $tagInfo = $et->GetTagInfo($tagTbl, 'unknown');
            if (!$tagInfo) return 1;
        }
        $subdir = $tagInfo->SubDirectory;
        if ($tagInfo->SubDirectory) {
            $offset = ($subdir['Start'] ?? null) ?: 0;
            $baseShift = ($dirInfo['DataPos'] ?? 0) + $dirInfo['DirStart'] + $offset;
            $subdirInfo = [
                'DataPt'  => $dataPt,
                'DataPos' => ($dirInfo['DataPos'] ?? 0) - $baseShift,
                'Base'    => (($dirInfo['Base'] ?? null) ?: 0) + $baseShift,
                'DataLen' => $dirInfo['DataLen'] ?? null,
                'DirStart' => $dirInfo['DirStart'] + $offset,
                'DirLen'  => $dirInfo['DirLen'] - $offset,
                'DirName' => $subdir['DirName'] ?? null,
                'Parent'  => $dirInfo['DirName'] ?? null,
            ];
            if (!$offset) {
                // allow processing of 2nd directory at the same address
                $addr = $subdirInfo['DirStart'] + $subdirInfo['DataPos'] + $subdirInfo['Base'];
                unset($et->PROCESSED[Perl::str($addr)]);
            }
            // (we could set FIRST_EXIF_POS to $subdirInfo{Base} here to make
            //  htmlDump offsets relative to EXIF base if we wanted...)
            $subTable = ExifTool::GetTagTable($subdir['TagTable']);
            $et->ProcessDirectory($subdirInfo, $subTable);
        } else {
            $et->HandleTag($tagTbl, $tag, null,
                DataPt  : $dataPt,
                DataPos : $dirInfo['DataPos'] ?? null,
                Start   : $start,
                Size    : $size,
                TagInfo : $tagInfo,
            );
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Make tag information hash for unknown tag
    // Inputs: 0) Tag table ref, 1) tag ID
    public static function MakeTagInfo(TagTable $tagTbl, $tag)
    {
        $n = 0;
        $name = preg_replace_callback('/([\x00-\x1f\x7f-\xff])/', static fn ($m) => 'x' . bin2hex($m[1]), $tag, -1, $n);
        // print in hex if tag is numerical
        if ($n > 2) $name = Perl::sprintf('0x%.4x', Perl::unpack('N', $tag)[0] ?? null);
        ExifTool::AddTagToTable($tagTbl, $tag, [
            'Name' => "Unknown_$name",
            'Description' => "Unknown $name",
            'Unknown' => 1,
            'Binary' => 1,
        ]);
    }

    //------------------------------------------------------------------------------
    // Process RIFF chunks
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessChunks(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $size = $dirInfo['DirLen'];
        $end = $start + $size;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $unknown = $et->Options('Unknown');
        $charset = $et->Options('CharsetRIFF');

        if (!$charset) {
            if ($et->CodePage) {
                $charset = $et->CodePage;
            } elseif ($charset !== null and Perl::str($charset) === '0') {
                $charset = 'Latin';
            }
        }

        if ($verbose) $et->VerboseDir($dirInfo['DirName'] ?? null, 0, $size);

        while ($start + 8 < $end) {
            $tag = substr($dataPt, $start, 4);
            $len = ExifTool::Get32u($dataPt, $start + 4);
            $start += 8;
            if ($start + $len > $end) {
                $et->Warn("Bad $tag chunk");
                return 0;
            }
            if ($tag === 'LIST' and $len >= 4) {
                $tag .= '_' . substr($dataPt, $start, 4);
                $len -= 4;
                $start += 4;
            }
            $tagInfo = $et->GetTagInfo($tagTbl, $tag);
            $baseShift = 0;
            $val = null;
            if ($tagInfo) {
                if ($tagInfo->SubDirectory) {
                    // adjust base if necessary (needed for Ricoh maker notes)
                    $newBase = $tagInfo->SubDirectory['Base'] ?? null;
                    if ($newBase !== null) {
                        // different than your average Base eval...
                        // here we use an absolute $start address
                        $start += $base;
                        //### eval Base ($start)
                        $newBase = Exif::EvalCode($newBase, $start);
                        $baseShift = Perl::num($newBase) - $base;
                        $start -= $base;
                    }
                } elseif (!$tagInfo->Binary) {
                    $format = $tagInfo->Format ?: $tagTbl['FORMAT'];
                    if ($format and $format === 'string') {
                        $val = substr($dataPt, $start, $len);
                        $val = preg_replace('/\0+$/', '', $val, 1);   // remove trailing nulls from strings
                        // decode if necessary
                        if ($charset) $val = $et->Decode($val, $charset);
                    }
                }
            } elseif ($verbose or $unknown) {
                self::MakeTagInfo($tagTbl, $tag);
            }
            $et->HandleTag($tagTbl, $tag, $val,
                DataPt  : $dataPt,
                DataPos : ($dirInfo['DataPos'] ?? 0) - $baseShift,
                Start   : $start,
                Size    : $len,
                Base    : $base + $baseShift,
                Addr    : $base + $baseShift + $start,
            );
            if ($len & 0x01) ++$len;    // must account for padding if odd number of bytes
            $start += $len;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process BikeBro SGLT chunk (accelerometer data) (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessSGLT(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);
        $ee = $et->Options('ExtractEmbedded');
        // example accelerometer record:
        // 0           1  2  3           4  5           6  7
        // 00 00 00 24 02 00 00 01 17 04 00 00 00 00 00 00 00 00 9b 02
        // frame------ ?? Xs X---------- Ys Y---------- Zs Z----------
        $et->SET_GROUP0 = $et->SET_GROUP1 = 'RIFF';
        for ($pos = 0; $pos <= $dataLen - 20; $pos += 20) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $buff = substr($dataPt, $pos);
            $a = Perl::unpack('NCCNCNCN', $buff);
            $acc = [$a[3] * ($a[2] ? -1 : 1) / 1e5, $a[5] * ($a[4] ? -1 : 1) / 1e5, $a[7] * ($a[6] ? -1 : 1) / 1e5];
            $et->HandleTag($tagTbl, 'FrameNumber', $a[0]);
            $et->HandleTag($tagTbl, 'Accelerometer', implode(' ', array_map(Perl::str(...), $acc)));
            if (!$ee) {
                $et->Warn('Use ExtractEmbedded option to extract all accelerometer data', 3);
                break;
            }
        }
        $et->SET_GROUP0 = null;
        $et->SET_GROUP1 = null;
        $et->DOC_NUM = 0;
        return 0;
    }

    //------------------------------------------------------------------------------
    // Process BikeBro SLLT chunk (GPS information) (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessSLLT(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);
        $ee = $et->Options('ExtractEmbedded');
        // example GPS record:
        // 0           1  2     3           4     5           6     7     8  9  10 11    12 13 14 15
        // 00 00 00 17 01 00 00 03 fa 21 ec 00 35 01 6e c0 06 00 08 00 62 10 0b 1b 07 e2 03 0e 57 4e
        // frame------ ?? lonDD lonDDDDDDDD latDD latDDDDDDDD alt-- spd-- hr mn sc yr--- mn dy EW NS
        $et->SET_GROUP0 = $et->SET_GROUP1 = 'RIFF';
        for ($pos = 0; $pos <= $dataLen - 30; $pos += 30) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $buff = substr($dataPt, $pos);
            $a = Perl::unpack('NCnNnNnnCCCnCCaa', $buff);
            // - is $a[1] perhaps GPSStatus? (only seen 1, or perhaps record type 1=GPS, 2=acc?)
            $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', $a[11], $a[12], $a[13], $a[8], $a[9], $a[10]);
            $et->HandleTag($tagTbl, 'FrameNumber', $a[0]);
            $et->HandleTag($tagTbl, 'GPSDateTime', $time);
            $et->HandleTag($tagTbl, 'GPSLatitude', ($a[4] + $a[5] / 1e8) * ($a[15] === 'S' ? -1 : 1));
            $et->HandleTag($tagTbl, 'GPSLongitude', ($a[2] + $a[3] / 1e8) * ($a[14] === 'W' ? -1 : 1));
            $et->HandleTag($tagTbl, 'GPSAltitude', $a[6]);
            $et->HandleTag($tagTbl, 'GPSSpeed', $a[7]);
            $et->HandleTag($tagTbl, 'GPSSpeedRef', 'K');
            if (!$ee) {
                $et->Warn('Use ExtractEmbedded option to extract timed GPS', 3);
                break;
            }
        }
        $et->SET_GROUP0 = null;
        $et->SET_GROUP1 = null;
        $et->DOC_NUM = 0;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Lucas streaming GPS information (Lucas LK-7900 Ace) (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessLucas(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);

        if (!$et->Options('ExtractEmbedded')) {
            $et->Warn('Use ExtractEmbedded option to extract timed GPS', 3);
            return 1;
        }
        $recLen = [     // record lengths (not including 4-byte ID)
            '0GDA' => 24,
            '0GPS' => 48,
        ];
        $date = $time = $lat = $lon = $alt = $spd = $sat = $dop = $ew = $ns = null;
        $et->SET_GROUP0 = $et->SET_GROUP1 = 'RIFF';
        $dpos = 0;  // (pos $$dataPt)
        while (preg_match('/(0GDA|0GPS)/', $dataPt, $m, PREG_OFFSET_CAPTURE, $dpos)) {
            [$rec, $pos] = [$m[1][0], $m[0][1] + 4];
            if ($pos + $recLen[$rec] > $dataLen) {
                $et->Warn("Truncated $rec record");
                break;
            }
            $et->DOC_NUM = ++$et->DOC_COUNT;
            // records start with int64u sample date/time in ms since 1970
            $et->HandleTag($tagTbl, 'SampleDateTime', ExifTool::Get64u($dataPt, $pos) / 1000);
            if ($rec === '0GPS') {
                $len = ExifTool::Get32u($dataPt, $pos + 8);
                $endPos = $pos + $recLen[$rec] + $len;
                if ($endPos > $dataLen) {
                    $et->Warn('Truncated 0GPS record');
                    break;
                }
                $buff = substr($dataPt, $pos + $recLen[$rec], $len);
                $bpos = 0;  // (pos $buff)
                while (preg_match('/\$(GC|GA),(\d+),/', $buff, $m, PREG_OFFSET_CAPTURE, $bpos)) {
                    $p = $bpos = $m[0][1] + strlen($m[0][0]);
                    $time = $m[2][0];
                    if ($m[1][0] === 'GC') {
                        //     time   date   dist    ? sat dop alt  A
                        // $GC,052350,180914,0000955,1,08,1.1,0017,,A*45\x0d\x0a\0
                        if (preg_match('/\G(\d+),\d*,\d*,(\d+),([-\d.]+),(\d+),\d*,A/', $buff, $m, 0, $bpos)) {
                            [$date, $sat, $dop, $alt] = [$m[1], $m[2], $m[3], $m[4]];
                            $bpos += strlen($m[0]);
                        } else {
                            $bpos = 0;  // (a failed m//g resets pos)
                        }
                    } else {
                        //     time   A lat       lon        spd N W
                        // $GA,052351,A,0949.6626,07635.4439,049,N,E,*4C\x0d\x0a\0
                        if (preg_match('/\GA,([\d.]+),([\d.]+),(\d+),([NS]),([EW])/', $buff, $m, 0, $bpos)) {
                            [$lat, $lon, $spd, $ns, $ew] = [$m[1], $m[2], $m[3], $m[4], $m[5]];
                            $bpos += strlen($m[0]);
                            // lat/long are in DDDMM.MMMM format
                            $deg = Perl::int(Perl::num($lat) / 100);
                            $lat = $deg + (Perl::num($lat) - $deg * 100) / 60;
                            $deg = Perl::int(Perl::num($lon) / 100);
                            $lon = $deg + (Perl::num($lon) - $deg * 100) / 60;
                            if ($ns === 'S') $lat *= -1;
                            if ($ew === 'W') $lon *= -1;
                        } else {
                            $bpos = 0;  // (a failed m//g resets pos)
                        }
                    }
                    // look ahead to next NMEA-like sentence, and store the fix
                    // now only if the next sentence is not at the same time
                    if (!preg_match('/\$(GC|GA),' . $time . ',/', $buff, $m, 0, $bpos)) {
                        $dpos = $endPos;
                        if (!preg_match('/\$(GC|GA),(\d+)/', $dataPt, $m) or $m[1] !== $time) {
                            $time = preg_replace('/(\d{2})(\d{2})(\d{2})/', '$1:$2:$3Z', $time, 1);
                            if ($date) {
                                $date = preg_replace('/(\d{2})(\d{2})(\d{2})/', '20$3:$2:$1', $date, 1);
                                $et->HandleTag($tagTbl, 'GPSDateTime', "$date $time");
                            } else {
                                $et->HandleTag($tagTbl, 'GPSTimeStamp', $time);
                            }
                            if ($lat !== null) {
                                $et->HandleTag($tagTbl, 'GPSLatitude', $lat);
                                $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
                                $et->HandleTag($tagTbl, 'GPSSpeed', $spd);
                            }
                            if ($alt !== null) {
                                $et->HandleTag($tagTbl, 'GPSAltitude', $alt);
                                $et->HandleTag($tagTbl, 'GPSSatellites', $sat);
                                $et->HandleTag($tagTbl, 'GPSDOP', $dop);
                            }
                            $lat = null;
                            $alt = null;
                        }
                    }
                    $bpos = $p;
                }
                $pos += $len;
            } else { // this is an accelerometer (0GDA) record
                // record has 4 more int32s values (the last is always 57 or 58 --
                // maybe related to sample time in ms? -- not extracted)
                $acc = Perl::unpack('x' . ($pos + 8) . 'V3', $dataPt);
                // change to signed integer and divide by 256
                foreach ($acc as &$v) {
                    if ($v >= 0x80000000) $v = $v - 4294967296;
                    $v /= 256;
                }
                unset($v);
                $et->HandleTag($tagTbl, 'Accelerometer', implode(' ', array_map(Perl::str(...), $acc)));
            }
            $dpos = $pos + $recLen[$rec];
        }
        $et->SET_GROUP0 = null;
        $et->SET_GROUP1 = null;
        $et->DOC_NUM = 0;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a RIFF file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid RIFF file
    public static function ProcessRIFF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $type = $mime = $err = $rf64 = $moviEnd = null;
        $verbose = $et->Options('Verbose');
        $unknown = $et->Options('Unknown');
        $validate = $et->Options('Validate');
        $ee = $et->Options('ExtractEmbedded');
        $hash = $et->ImageDataHash;
        $base = 0;

        // verify this is a valid RIFF file
        if ($raf->Read($buff, 12) != 12) return 0;
        if (preg_match('/^(RIFF|RF64)....(.{4})/s', $buff, $m)) {
            $type = Vars\RIFF::$riffType[$m[2]] ?? null;
            if ($m[1] === 'RF64') $rf64 = 1;
        } else {
            // minimal support for a few obscure lossless audio formats...
            if (!(preg_match('/^(LA0[234]|OFR |LPAC|wvpk)/', $buff, $m) and $raf->Read($buf2, 1024))) return 0;
            $type = Vars\RIFF::$riffType[$m[1]] ?? null;
            $buff .= $buf2;
            if (!(preg_match('/WAVE(.{4})?(junk|fmt )/s', $buff, $m, PREG_OFFSET_CAPTURE) and
                $raf->Seek($m[0][1] + strlen($m[0][0]) - 4, 0))) return 0;
            $base = $m[0][1] + strlen($m[0][0]) - 16;
        }
        if ($et->Options('FastScan')) $raf->NoBuffer = true;    // disable buffering in FastScan mode
        if ($type) $mime = Vars\RIFF::$riffMimeType[$type] ?? null;
        $et->SetFileType($type, $mime);
        if ($rf64 and ($et->VALUE['FileType'] ?? null)) $et->VALUE['FileType'] .= ' (RF64)';
        $et->RIFFStreamType = '';      // initialize stream type
        $et->RIFFStreamCodec = [];     // initialize codec array
        ExifTool::SetByteOrder('II');
        $riffEnd = ExifTool::Get32u($buff, 4) + 8;
        $riffEnd += $riffEnd & 0x01;    // (account for padding)
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::RIFF::Main');
        $pos = 12;
//
// Read chunks in RIFF image
//
        for (;;) {
            if ($err) {
                if (!$moviEnd) break;
                // we arrived here because there was a problem parsing the movie data
                // so seek to the end to continue processing
                if ($moviEnd > 0x7fffffff) {
                    if (!$et->Options('LargeFileSupport')) {
                        $et->Warn('Possibly corrupt LIST_movi data');
                        $et->Warn('Stopped parsing at large LIST_movi chunk (LargeFileSupport not set)');
                        $err = null;
                        break;
                    }
                    if (Perl::str($et->Options('LargeFileSupport')) === '2') {
                        $et->Warn('Processing large chunk (LargeFileSupport is 2)');
                    }
                }
                if ($validate) {
                    // (must actually try to read something after seeking to detect error)
                    if (!($raf->Seek($moviEnd - 1, 0) and $raf->Read($buff, 1) == 1)) break;
                } else {
                    if (!$raf->Seek($moviEnd, 0)) break;
                }
                $pos = $moviEnd;
                $et->Warn('Possibly corrupt LIST_movi data');
                $err = null;
                $moviEnd = null;
            }
            if ($moviEnd) {
                if ($pos > $moviEnd) { $err = 1; continue; }    // error if we parsed past the end of the movie data
                if ($pos == $moviEnd) $moviEnd = null;          // parsed all movie data?
            }
            $num = $raf->Read($buff, 8);
            if ($num < 8) {
                if ($moviEnd) { $err = 1; continue; }
                if ($num) $err = 1;
                if ($validate and $pos != $riffEnd) $et->Warn('Incorrect RIFF chunk size' . " $pos vs. $riffEnd");
                break;
            }
            $pos += 8;
            [$tag, $len] = Perl::unpack('a4V', $buff);
            // tweak WEBP type if this is an extended WebP
            if ($tag === 'VP8X' and $type === 'WEBP') $et->OverrideFileType('Extended WEBP', null, 'webp');
            // special case: construct new tag name from specific LIST type
            if ($tag === 'LIST') {
                if ($raf->Read($buff, 4) != 4) { $err = 1; continue; }
                $pos += 4;
                $tag .= "_$buff";
                $len -= 4;  // already read 4 bytes (the LIST type)
            } elseif ($tag === 'data') {
                if ($len == 0xffffffff and $et->DataSize64) $len = $et->DataSize64;
                $et->RIFFDataLen = (($et->RIFFDataLen) ?: 0) + $len;
            }
            $et->VPrint(0, "RIFF '{$tag}' chunk ($len bytes of data):\n");
            if ($len <= 0) {
                if ($moviEnd) { $err = 1; continue; }
                if ($len < 0) {
                    $et->Warn('Invalid chunk length');
                } elseif ($tag === "\0\0\0\0") {
                    // avoid reading through corupted files filled with nulls because it takes forever
                    $et->Warn('Encountered empty null chunk. Processing aborted');
                } else {
                    continue;
                }
                break;
            }
            // stop when we hit the audio data or AVI index or AVI movie data
            // --> no more because Adobe Bridge stores XMP after this!!
            // (so now we only do this on the FastScan option)
            if ($et->Options('FastScan') and ($tag === 'data' or $tag === 'idx1' or
                ($tag === 'LIST_movi' and !$ee)))
            {
                $et->VPrint(0, "(end of parsing)\n");
                break;
            }
            // RIFF chunks are padded to an even number of bytes
            $len2 = $len + ($len & 0x01);
            // change name of stream txts data depending on the Codec
            if ($ee and preg_match('/^(\d{2})tx$/', $tag, $m)) {
                $tag = 'tx_' . (($et->RIFFStreamCodec[(int) $m[1]] ?? null) ?: 'Unknown');
                if ($tagTbl[$tag] === null) $tag = 'tx_Unknown';
                $et->DOC_NUM = ++$et->DOC_COUNT;
            }
            $tagInfo = $tagTbl[$tag];
            // (in LIST_movi chunk: ##db = uncompressed DIB, ##dc = compressed DIB, ##wb = audio data)
            if ($tagInfo or (($verbose or $unknown) and !preg_match('/^(data|idx1|LIST_movi|RIFF|\d{2}(db|dc|wb))$/', $tag))) {
                if (!($raf->Read($buff, $len2) >= $len)) { $err = 1; continue; }
                if (strlen($buff) != $len2) $et->Warn("No padding on odd-sized $tag chunk");
                if ($hash and !empty(Vars\RIFF::$isImageData[$tag])) {
                    hash_update($hash, $buff);
                    $et->VPrint(0, "{$et->INDENT}(ImageDataHash: '{$tag}' chunk, $len2 bytes)\n");
                }
                $setGroups = null;
                if ($tagInfo instanceof TagInfo and $tagInfo->SetGroups) {
                    $setGroups = $et->SET_GROUP0 = $et->SET_GROUP1 = $tagInfo->SetGroups;
                }
                if (!$tagInfo and ($verbose or $unknown)) self::MakeTagInfo($tagTbl, $tag);
                $et->HandleTag($tagTbl, $tag, $buff,
                    DataPt  : $buff,
                    DataPos : 0,   // (relative to Base)
                    Start   : 0,
                    Size    : $len,
                    Base    : $pos + $base,
                );
                if ($setGroups) {
                    $et->SET_GROUP0 = null;
                    $et->SET_GROUP1 = null;
                }
                if ($ee) $et->DOC_NUM = null;
            } elseif ($tag === 'RIFF') {
                if ($validate and $pos - 8 != $riffEnd) $et->Warn('Incorrect RIFF chunk size');
                $riffEnd += $len2 + 8;
                // don't read into RIFF chunk (eg. concatenated video file)
                if ($raf->Read($buff, 4) != 4) { $err = 1; continue; }  // (skip RIFF type word)
                $pos += 4;
                // extract information from remaining file as an embedded file
                $et->DOC_NUM = ++$et->DOC_COUNT;
                continue; // (must not increment $pos)
            } else {
                $rewind = null;
                // do hash if required
                if ($hash and !empty(Vars\RIFF::$isImageData[$tag])) {
                    $rewind = $raf->Tell();
                    $et->ImageDataHash($raf, $len2, "'{$tag}' chunk");
                }
                if ($tag === 'LIST_movi' and $ee) {
                    if ($rewind and !$raf->Seek($rewind, 0)) { $err = 1; continue; }
                    // save end-of-movie offset so we can seek there if we get errors parsing the movie data
                    $moviEnd = $raf->Tell() + $len2;
                    continue; // parse into movi chunk
                } elseif (!$rewind) {
                    if ($len > 0x7fffffff) {
                        if (!$et->Options('LargeFileSupport')) {
                            $tag = preg_replace_callback('/([\0-\x1f\x7f-\xff])/', static fn ($m) => Perl::sprintf('\\x%.2x', ord($m[1])), $tag);
                            $et->Warn("Stopped parsing at large $tag chunk (LargeFileSupport not set)");
                            break;
                        }
                        if (Perl::str($et->Options('LargeFileSupport')) === '2') {
                            $et->Warn('Processing large chunk (LargeFileSupport is 2)');
                        }
                    }
                    if ($validate and $len2) {
                        // (must actually try to read something after seeking to detect error)
                        if (!($raf->Seek($len2 - 1, 1) and $raf->Read($buff, 1) == 1)) { $err = 1; continue; }
                    } else {
                        if (!$raf->Seek($len2, 1)) { $err = 1; continue; }
                    }
                }
            }
            $pos += $len2;
        }
        $et->DOC_NUM = null;
        if ($err) $et->Warn('Error reading RIFF file (corrupted?)');
        return 1;
    }
}
