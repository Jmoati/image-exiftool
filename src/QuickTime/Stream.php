<?php

//------------------------------------------------------------------------------
// File:         QuickTimeStream.pl
//
// Description:  Extract embedded information from QuickTime media data
//
// Revisions:    2018-01-03 - P. Harvey Created
//
// Notes:        Set API "Debug" option to generate GPSType tag
//
// References:   1) https://developer.apple.com/library/content/documentation/QuickTime/QTFF/QTFFChap3/qtff3.html#//apple_ref/doc/uid/TP40000939-CH205-SW130
//               2) http://sergei.nz/files/nvtk_mp42gpx.py
//               3) https://forum.flitsservice.nl/dashcam-info/dod-ls460w-gps-data-uit-mov-bestand-lezen-t87926.html
//               4) https://developers.google.com/streetview/publish/camm-spec
//               5) https://sergei.nz/extracting-gps-data-from-viofo-a119-and-other-novatek-powered-cameras/
//               6) Thomas Allen https://github.com/exiftool/exiftool/pull/62
//
// PHP port: the subs of QuickTimeStream.pl (package Image::ExifTool::QuickTime), mixed into
// class Image\ExifTool\QuickTime. Tag tables (%Image::ExifTool::QuickTime::Stream, camm0-7...)
// and file lexicals (%qtFmt, @dateMax, $knotsToKph, %insvDataLen...) are generated into
// tables/QuickTime.php and Vars\QuickTime.
//------------------------------------------------------------------------------

namespace Image\ExifTool\QuickTime;

use Image\ExifTool\ExifTool;
use Image\ExifTool\GoPro;
use Image\ExifTool\H264;
use Image\ExifTool\Import;
use Image\ExifTool\LigoGPS;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars;
use Image\ExifTool\XMP;

trait Stream
{
    /** (PHP: Perl "@list" interpolation, numbers stringified the Perl way) */
    private static function ListStr(array $list): string
    {
        return implode(' ', array_map(Perl::str(...), $list));
    }

    /** (PHP: pack('C*', map { $_ ^ $key } unpack('C*', $str))) */
    private static function XorBytes(string $str, int $key): string
    {
        return $str ^ str_repeat(chr($key), strlen($str));
    }

    //------------------------------------------------------------------------------
    // Convert unsigned 32-bit integer to signed
    // Inputs: <none> (uses value in $_)
    // Returns: signed integer
    // (PHP: takes the value as argument)
    public static function SignedInt32($v)
    {
        return $v < 0x80000000 ? $v : $v - 4294967296;
    }

    //------------------------------------------------------------------------------
    // Save information from keys in OtherSampleDesc directory for processing timed metadata
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // (ref "Timed Metadata Media" here:
    //  https://developer.apple.com/library/content/documentation/QuickTime/QTFF/QTFFChap3/qtff3.html)
    public static function SaveMetaKeys(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = strlen($dataPt);
        if (!($dirLen > 8)) return 0;
        $pos = 0;
        $verbose = $et->OPTIONS['Verbose'];
        $oldIndent = $et->INDENT;
        if (!$et->ee) $et->ee = [];

        if ($verbose) $et->VerboseDir($dirInfo['DirName'] ?? null, null, $dirLen);

        // loop through metadata key table
        while ($pos + 8 < $dirLen) {
            $size = ExifTool::Get32u($dataPt, $pos);
            $id = substr($dataPt, $pos + 4, 4);
            $end = $pos + $size;
            if ($end > $dirLen) $end = $dirLen;
            $pos += 8;
            $tagID = $format = $pid = null;
            if ($verbose) {
                $pid = self::PrintableTagID($id, 1);
                $et->VPrint(0, "$oldIndent+ [Metadata Key entry, Local ID=$pid, $size bytes]\n");
                $et->INDENT .= '| ';
            }

            while ($pos + 4 < $end) {
                $len = Perl::unpack("x{$pos}N", $dataPt)[0];
                if ($len < 8 or $pos + $len > $end) break;
                $tag = substr($dataPt, $pos + 4, 4);
                $pos += 8;
                $len -= 8;
                $base = ($dirInfo['Base'] ?? 0) + $pos;
                $val = substr($dataPt, $pos, $len);
                $pos += $len;
                $str = null;
                if ($tag === 'keyd') {
                    $tagID = preg_replace('/^(mdta|fiel)com\.apple\.quicktime\./', '', $val, 1);
                    if (!$tagID) $tagID = "Tag_$val";
                    if ($verbose) $str = preg_replace('/(.{4})/', '$1 ', $val, 1);
                } elseif ($tag === 'dtyp') {
                    if (strlen($val) < 4) continue;
                    if (strlen($val) >= 4) {
                        $ns = Perl::unpack('N', $val)[0];
                        if ($ns == 0) {
                            if (strlen($val) < 8) {
                                $et->Warn('Short dtyp data');
                                continue;
                            }
                            $str = Perl::unpack('x4N', $val)[0];
                            $format = (Vars\QuickTime::$qtFmt[$str] ?? null) ?: 'undef';
                        } elseif ($ns == 1) {
                            $str = substr($val, 4);
                            $format = 'undef';
                        } else {
                            $format = 'undef';
                        }
                        if ($verbose and $str !== null) $str .= " ($format)";
                    }
                } else {
                    $et->HandleTag($tagTbl, $tag, null,
                        DataPt: $val,
                        Base: $base,
                    );
                    continue;
                }
                if ($verbose > 1) {
                    if ($str !== null) {
                        $str = Perl::tr(Perl::str($str), "\x00-\x1f\x7f-\xff", '.')[0];
                        $str = " = $str";
                    } else {
                        $str = '';
                    }
                    $et->VPrint(1, $et->INDENT . "- Tag '" . self::PrintableTagID($tag, 2) . "' ($len bytes)$str\n");
                    $et->VerboseDump($val, Base: $base);
                }
            }
            if ($tagID !== null and $format !== null) {
                if ($verbose) {
                    $t2 = self::PrintableTagID($tagID);
                    $et->VPrint(0, "{$et->INDENT}Added Local ID $pid = $t2 ($format)\n");
                }
                $et->ee['keys'][$id] = ['TagID' => $tagID, 'Format' => $format];
            }
            $et->INDENT = $oldIndent;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // We found some tags for this sample, so set document number and save timing information
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) sample time, 3) sample duration
    public static function FoundSomething(ExifTool $et, TagTable $tagTbl, $time = null, $dur = null)
    {
        $et->DOC_NUM = ++$et->DOC_COUNT;
        if ($time !== null) $et->HandleTag($tagTbl, 'SampleTime', $time);
        if ($dur !== null) $et->HandleTag($tagTbl, 'SampleDuration', $dur);
    }

    //------------------------------------------------------------------------------
    // Approximate GPSDateTime value from sample time and CreateDate
    // Inputs: 0) ExifTool ref, 1) tag table ptr, 2) sample time (s)
    //         3) true if CreateDate is UTC
    // Notes: Uses ExifTool CreateDateAtEnd as flag to subtract video duration
    public static function SetGPSDateTime(ExifTool $et, TagTable $tagTbl, $sampleTime, $isUTC = null)
    {
        $value = $et->VALUE;
        if ($sampleTime !== null and !empty($value['CreateDate'])) {
            $sampleTime += Perl::num($value['CreateDate']); // adjust sample time to seconds since the epoch
            if ($et->CreateDateAtEnd) {         // adjust if CreateDate is at end of video
                if (!(!empty($value['TimeScale']) and !empty($value['Duration']))) return;
                $sampleTime -= Perl::num($value['Duration']) / Perl::num($value['TimeScale']);
                $et->Warn('Approximating GPSDateTime as CreateDate - Duration + SampleTime', 1);
            } else {
                $et->Warn('Approximating GPSDateTime as CreateDate + SampleTime', 1);
            }
            $utc = $et->Options('QuickTimeUTC');
            if ($utc === null) $utc = $isUTC;   // (allow QuickTimeUTC=0 to override $isUTC default)
            if (!$utc) {
                $tzOff = $et->tzOff;    // use previously calculated offset
                if ($tzOff === null) {
                    // adjust to UTC, assuming time is local
                    $tm = ExifTool::PerlLocaltime($value['CreateDate']);
                    $gm = ExifTool::PerlGmtime($value['CreateDate']);
                    $tzOff = $et->tzOff = ExifTool::GetTimeZone($tm, $gm) * 60;
                }
                $sampleTime -= $tzOff;  // shift from local time to UTC
            }
            $et->SET_GROUP0 = 'Composite';
            $et->HandleTag($tagTbl, 'GPSDateTime', ExifTool::ConvertUnixTime($sampleTime, 0, 3) . 'Z');
            $et->SET_GROUP0 = null;
        }
    }

    //------------------------------------------------------------------------------
    // Handle tags that we found in the subtitle 'text'
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) hash of tag names/values
    public static function HandleTextTags(ExifTool $et, TagTable $tagTbl, array &$tags)
    {
        unset($tags['done']);
        if (!empty($tags['GPSDateTime'])) unset($tags['GPSTimeStamp']);
        $keys = array_keys($tags);
        sort($keys, SORT_STRING);
        foreach ($keys as $tag) {
            $et->HandleTag($tagTbl, $tag, $tags[$tag]);
        }
        $et->UnknownTextCount = 0;
        $tags = [];     // clear the hash
    }

    //------------------------------------------------------------------------------
    // Handle new time in NMEA stream and store queued tags if necessary
    // Inputs: 0) ExifTool ref, 1) time string, 2) tag table ref, 3) tags hash
    public static function HandleNewTime(ExifTool $et, $time, TagTable $tagTbl, array &$tags)
    {
        if ($et->LastTime) {
            if ($et->LastTime === $time) {
                // combine with the previous NMEA sentence
                $et->DOC_NUM = $et->LastDoc;
            } elseif ($tags) {
                // handle existing tags and start a new document
                // (see https://exiftool.org/forum/index.php?msg=75422)
                self::HandleTextTags($et, $tagTbl, $tags);
                // increment document number and update document count if necessary
                if ($et->DOC_COUNT < ++$et->DOC_NUM) $et->DOC_COUNT = $et->DOC_NUM;
            }
        }
        $et->LastTime = $time;
        $et->LastDoc = $et->DOC_NUM;
    }

    //------------------------------------------------------------------------------
    // Process subtitle 'text'
    // Inputs: 0) ExifTool ref, 1) data ref or dirInfo ref, 2) tag table ref
    //         3) flag set if text was already stored
    public static function Process_text(ExifTool $et, $dataPt, TagTable $tagTbl, $handled = null)
    {
        $tags = [];

        if ($et->NoMoreTextDecoding) return;

        if (is_array($dataPt)) {
            $dirName = $dataPt['DirName'] ?? null;
            $dataPt = $dataPt['DataPt'];
            $et->VerboseDir($dirName, null, strlen($dataPt));
        }
        $knotsToKph = Vars\QuickTime::$knotsToKph;

        preg_match_all('/\$(\w+)([^\$\0]*)/', $dataPt, $matches, PREG_SET_ORDER);
        foreach ($matches as $mt) {
            [$tag, $dat] = [$mt[1], $mt[2]];
            if (preg_match('/^[A-Z]{2}RMC$/', $tag)) {
                if (!preg_match('/^,(\d{2})(\d{2})(\d+(?:\.\d*)),A?,(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/', $dat, $m)) {
                    if (!$handled) $tags['Text'] = isset($tags['Text']) ? $tags['Text'] . "\$$tag$dat" : "\$$tag$dat";
                    if (preg_match('/^,\d+\.?\d*,V,/', $dat)) $et->UnknownTextCount = 0; // (allow any number of void fixes)
                    continue;
                }
                $time = "$m[1]:$m[2]:$m[3]";
                self::HandleNewTime($et, $time, $tagTbl, $tags);
                $year = $m[14] + ($m[14] >= 70 ? 1900 : 2000);
                $date = Perl::sprintf('%.4d:%.2d:%.2d', $year, $m[13], $m[12]);
                $et->LastDate = $date;
                $tags['GPSDateTime'] = "$date {$time}Z";
                $tags['GPSLatitude'] = ((Perl::num($m[4]) ?: 0) + $m[5] / 60) * ($m[6] === 'N' ? 1 : -1);
                $tags['GPSLongitude'] = ((Perl::num($m[7]) ?: 0) + $m[8] / 60) * ($m[9] === 'E' ? 1 : -1);
                if (strlen($m[10])) $tags['GPSSpeed'] = Perl::num($m[10]) * $knotsToKph;
                if (strlen($m[11])) $tags['GPSTrack'] = $m[11];
            } elseif (preg_match('/^[A-Z]{2}GGA$/', $tag) and preg_match('/^,(\d{2})(\d{2})(\d+(?:\.\d*)?),(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),[1-6]?,(\d+)?,(\.\d+|\d+\.?\d*)?,(-?\d+\.?\d*)?,M?/s', $dat, $m, PREG_UNMATCHED_AS_NULL)) {
                $time = "$m[1]:$m[2]:$m[3]";
                self::HandleNewTime($et, $time, $tagTbl, $tags);
                $tags['GPSTimeStamp'] = $time;
                $tags['GPSLatitude'] = ((Perl::num($m[4]) ?: 0) + $m[5] / 60) * ($m[6] === 'N' ? 1 : -1);
                $tags['GPSLongitude'] = ((Perl::num($m[7]) ?: 0) + $m[8] / 60) * ($m[9] === 'E' ? 1 : -1);
                if ($m[10] !== null) $tags['GPSSatellites'] = $m[10];
                if ($m[11] !== null) $tags['GPSDOP'] = $m[11];
                if ($m[12] !== null) $tags['GPSAltitude'] = $m[12];
            // ($G and $GS are ref https://exiftool.org/forum/index.php?topic=13115.msg71743#msg71743)
            } elseif ($tag === 'G' and preg_match('/:(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})-([NS])(\d+\.\d+)-([EW])(\d+\.\d+)-S(\d+)/', $dat, $m)) {
                $tags['GPSDateTime'] = "$m[1]:$m[2]:$m[3] $m[4]";
                $tags['GPSLatitude'] = $m[6] * ($m[5] === 'S' ? -1 : 1);
                $tags['GPSLongitude'] = $m[8] * ($m[7] === 'W' ? -1 : 1);
                $tags['GPSSpeed'] = $m[9];
            } elseif ($tag === 'GS' and preg_match('/:([-+]?\d+),([-+]?\d+),([-+]?\d+)/', $dat, $m)) {
                // scale and re-arrange to match gsensor output from Win app (forum11665)
                $acc = [($m[2] + 2432) / 1000, ($m[3] + 361) / 1000, ($m[1] - 3708) / 1000];
                $tags['Accelerometer'] = self::ListStr($acc);
            } elseif ($tag === 'BEGINGSENSOR' and preg_match('/^:([-+]\d+\.\d+):([-+]\d+\.\d+):([-+]\d+\.\d+)/', $dat, $m)) {
                $tags['Accelerometer'] = "$m[1] $m[2] $m[3]";
            } elseif ($tag === 'TIME' and preg_match('/^:(\d+)/', $dat, $m)) {
                $tags['TimeCode'] = $m[1] / ($et->MediaTS ?: 1);
            } elseif ($tag === 'BEGIN') {
                if (strlen($dat)) $tags['Text'] = $dat;
                $tags['done'] = 1;
            } elseif ($tag !== 'END' and !$handled) {
                $tags['Text'] = isset($tags['Text']) ? $tags['Text'] . "\$$tag$dat" : "\$$tag$dat";
            }
        }
        if ($tags) {
            if (empty($tags['Accelerometer'])) { // (probably unnecessary test)
                // check for NextBase 622GW accelerometer data
                // Example data (leading 2-byte length word has been stripped by ProcessSamples):
                // 0000: 00 00 00 00 32 30 32 32 30 39 30 35 31 36 34 30 [....202209051640]
                // 0010: 33 33 00 00 29 00 ba ff 48 ff 18 00 f2 07 5a ff [33..)...H.....Z.]
                // 0020: 64 ff e8 ff 58 ff e8 ff c1 07 43 ff 41 ff d2 ff [d...X.....C.A...]
                // 0030: 58 ff ea ff dc 07 50 ff 30 ff e0 ff 72 ff d8 ff [X.....P.0...r...]
                // 0040: f5 07 51 ff 16 ff dc ff 6a ff ca ff 33 08 45 ff [..Q.....j...3.E.]
                if (preg_match('/^\0{4}(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\0\0.{2}/s', $dataPt, $m)) {
                    $tags['DateTimeStamp'] = "$m[1]:$m[2]:$m[2] $m[4]:$m[5]:$m[6]";
                    $num = Perl::unpack('x20v', $dataPt)[0];   // number of accelerometer readings
                    if ($num and $num * 12 + 22 < strlen($dataPt)) {
                        $num *= 6;
                        $acc = Perl::unpack("x22v$num", $dataPt);
                        foreach ($acc as &$v) { if ($v >= 0x8000) $v = $v - 0x10000; }
                        unset($v);
                        $tags['AccelerometerData'] = self::ListStr($acc);
                    }
                }
            }
            if (!empty($tags['GPSTimeStamp']) and empty($tags['GPSDateTime']) and $et->LastDate) {
                // hack to fill in missing date for NextBase 662GW
                // (note: this doesn't necessarily handle day rollover properly)
                $tags['GPSDateTime'] = "{$et->LastDate} {$tags['GPSTimeStamp']}Z";
            }
            self::HandleTextTags($et, $tagTbl, $tags);
            return;
        }
        // check for enciphered binary GPS data
        // BlueSkySea:
        //   0000: 00 00 aa aa aa aa 54 54 98 9a 9b 93 9a 92 98 9a [......TT........]
        //   0010: 9a 9d 9f 9b 9f 9d aa aa aa aa aa aa aa aa aa aa [................]
        //   0020: aa aa aa aa aa a9 e4 9e 92 9f 9b 9f 92 9d 99 ef [................]
        //   0030: 9a 9a 98 9b 93 9d 9d 9c 93 aa aa aa aa aa 9a 99 [................]
        //   0040: 9b aa aa aa aa aa aa aa aa aa aa aa aa aa aa aa [................]
        //   [...]
        //  decrypted:
        //   0000: aa aa 00 00 00 00 fe fe 32 30 31 39 30 38 32 30 [........20190820]
        //   0010: 30 37 35 31 35 37 00 00 00 00 00 00 00 00 00 00 [075157..........]
        //   0020: 00 00 00 00 00 03 4e 34 38 35 31 35 38 37 33 45 [......N48515873E]
        //   0030: 30 30 32 31 39 37 37 36 39 00 00 00 00 00 30 33 [002197769.....03]
        //   0040: 31 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [1...............]
        //   [...]
        // Ambarella A12:
        //   0000: 00 00 f2 e1 f0 ee 54 54 98 9a 9b 93 9b 9b 9b 9c [......TT........]
        //   0010: 9b 9a 9a 93 9a 9b a6 9a 9b 9b 93 9b 9a 9b 9c 9a [................]
        //   0020: 9d 9a 92 9f 93 a9 e4 9f 9f 9e 9f 9b 9b 9c 9d ef [................]
        //   0030: 9a 99 9d 9e 99 9a 9a 9e 9b 81 9a 9b 9f 9d 9a 9a [................]
        //   0040: 9a 87 9a 9a 9a 87 9a 98 99 87 9a 9a 99 87 9a 9a [................]
        //   [...]
        //  decrypted:
        //   0000: aa aa 58 4b 5a 44 fe fe 32 30 31 39 31 31 31 36 [..XKZD..20191116]
        //   0010: 31 30 30 39 30 31 0c 30 31 31 39 31 30 31 36 30 [100901.011910160]
        //   0020: 37 30 38 35 39 03 4e 35 35 34 35 31 31 36 37 45 [70859.N55451167E]
        //   0030: 30 33 37 34 33 30 30 34 31 2b 30 31 35 37 30 30 [037430041+015700]
        //   0040: 30 2d 30 30 30 2d 30 32 33 2d 30 30 33 2d 30 30 [0-000-023-003-00]
        //   [...]
        //   0100: aa 55 57 ed ed 45 58 54 44 00 01 30 30 30 30 31 [.UW..EXTD..00001]
        //   0110: 31 30 38 30 30 30 58 00 58 00 58 00 58 00 58 00 [108000X.X.X.X.X.]
        //   0120: 58 00 58 00 58 00 58 00 00 00 00 00 00 00 00 00 [X.X.X.X.........]
        //   0130: 00 00 00 00 00 00 00                            [.......]
        if (preg_match('/^\0\0(..\xaa\xaa|\xf2\xe1\xf0\xee)/s', $dataPt) and strlen($dataPt) >= 282) {
            $val = self::XorBytes(substr($dataPt, 8, 14), 0xaa);
            if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $val, $m)) {
                $tags['GPSDateTime'] = "$m[1]:$m[2]:$m[3] $m[4]:$m[5]:$m[6]";
                $val = self::XorBytes(substr($dataPt, 38, 9), 0xaa);
                if (preg_match('/^([NS])(\d{2})(\d+$)$/', $val, $m)) {
                    $tags['GPSLatitude'] = ($m[2] + $m[3] / 600000) * ($m[1] === 'S' ? -1 : 1);
                }
                $val = self::XorBytes(substr($dataPt, 47, 10), 0xaa);
                if (preg_match('/^([EW])(\d{3})(\d+$)$/', $val, $m)) {
                    $tags['GPSLongitude'] = ($m[2] + $m[3] / 600000) * ($m[1] === 'W' ? -1 : 1);
                }
                $val = self::XorBytes(substr($dataPt, 0x39, 5), 0xaa);
                if (preg_match('/^[-+]\d+$/', $val)) $tags['GPSAltitude'] = Perl::num($val);
                $val = self::XorBytes(substr($dataPt, 0x3e, 3), 0xaa);
                if (preg_match('/^\d+$/', $val)) $tags['GPSSpeed'] = Perl::num($val);
                if (preg_match('/^\0\0..\xaa\xaa/s', $dataPt)) { // (BlueSkySea)
                    $val = self::XorBytes(substr($dataPt, 0xad, 12), 0xaa);
                    // the first X,Y,Z accelerometer readings from the AccelerometerData
                    if (preg_match('/^([-+]\d{3})([-+]\d{3})([-+]\d{3})$/', $val, $m)) {
                        $tags['Accelerometer'] = "$m[1] $m[2] $m[3]";
                        $val = self::XorBytes(substr($dataPt, 0xba, 96), 0xaa);
                        $order = ExifTool::GetByteOrder();
                        ExifTool::SetByteOrder('II');
                        $val = ExifTool::ReadValue($val, 0, 'float');
                        ExifTool::SetByteOrder($order);
                        $tags['AccelerometerData'] = $val;
                    }
                } else { // (Ambarella)
                    $acc = [];
                    $val = self::XorBytes(substr($dataPt, 0x41, 195), 0xaa);
                    $p = 0;     // (pos($val))
                    while (preg_match('/\G([-+]\d{3})([-+]\d{3})([-+]\d{3})/', $val, $m, 0, $p)) {
                        array_push($acc, $m[1], $m[2], $m[3]);
                        $p += strlen($m[0]);
                    }
                    if ($acc) $tags['Accelerometer'] = self::ListStr($acc);
                }
            }
            if ($tags) self::HandleTextTags($et, $tagTbl, $tags);
            return;
        }

        // check for DJI telemetry data, eg:
        // "F/3.5, SS 1000, ISO 100, EV 0, GPS (8.6499, 53.1665, 18), D 24.26m,
        //  H 6.00m, H.S 2.10m/s, V.S 0.00m/s \n"
        if (preg_match('/GPS \(([-+]?\d*\.\d+),\s*([-+]?\d*\.\d+)/', $dataPt, $m)) {
            $et->CreateDateAtEnd = 1;  // set flag indicating the file creation date is at the end
            $tags['GPSLatitude'] = $m[2];
            $tags['GPSLongitude'] = $m[1];
            if (preg_match('/,\s*H\s+([-+]?\d+\.?\d*)m/', $dataPt, $m)) $tags['GPSAltitude'] = $m[1];
            if (preg_match('/,\s*H.S\s+([-+]?\d+\.?\d*)/', $dataPt, $m)) $tags['GPSSpeed'] = $m[1] * Vars\QuickTime::$mpsToKph;
            if (preg_match('/,\s*D\s+(\d+\.?\d*)m/', $dataPt, $m)) $tags['Distance'] = $m[1] * Vars\QuickTime::$mpsToKph;
            if (preg_match('/,\s*V.S\s+([-+]?\d+\.?\d*)/', $dataPt, $m)) $tags['VerticalSpeed'] = $m[1];
            if (preg_match('/\bF\/(\d+\.?\d*)/', $dataPt, $m)) $tags['FNumber'] = $m[1];
            if (preg_match('/\bSS\s+(\d+\.?\d*)/', $dataPt, $m)) $tags['ExposureTime'] = 1 / Perl::num($m[1]);
            if (preg_match('/\bEV\s+([-+]?\d+\.?\d*)(\/\d+)?/', $dataPt, $m)) $tags['ExposureCompensation'] = ($m[1] / Perl::num(($m[2] ?? null) ?: 1));
            if (preg_match('/\bISO\s+(\d+\.?\d*)/', $dataPt, $m)) $tags['ISO'] = $m[1];
            self::HandleTextTags($et, $tagTbl, $tags);
            return;
        }

        // check for Mini 0806 dashcam GPS, eg:
        // "A,270519,201555.000,3356.8925,N,08420.2071,W,000.0,331.0M,+01.84,-09.80,-00.61;\n"
        if (preg_match('/^A,(\d{2})(\d{2})(\d{2}),(\d{2})(\d{2})(\d{2}(\.\d+)?)/', $dataPt, $m)) {
            $tags['GPSDateTime'] = "20$m[3]:$m[2]:$m[1] $m[4]:$m[5]:{$m[6]}Z";
            if (preg_match('/^A,.*?,.*?,(\d{2})(\d+\.\d+),([NS])/', $dataPt, $m)) {
                $tags['GPSLatitude'] = ($m[1] + $m[2] / 60) * ($m[3] === 'S' ? -1 : 1);
            }
            if (preg_match('/^A,.*?,.*?,.*?,.*?,(\d{3})(\d+\.\d+),([EW])/', $dataPt, $m)) {
                $tags['GPSLongitude'] = ($m[1] + $m[2] / 60) * ($m[3] === 'W' ? -1 : 1);
            }
            $a = Perl::split('/,/', $dataPt);
            if (!empty($a[8]) and preg_match('/M$/', $a[8])) $tags['GPSAltitude'] = $a[8] = preg_replace('/M$/', '', $a[8], 1);
            if (!empty($a[7]) and preg_match('/^\d+\.\d+$/', $a[7])) $tags['GPSSpeed'] = $a[7]; // (NC)
            if (!empty($a[11]) and preg_match('/;\s*$/', $a[11])) {
                $a[11] = preg_replace('/;\s*$/', '', $a[11], 1);
                $tags['Accelerometer'] = "$a[9] $a[10] $a[11]";
            }
            self::HandleTextTags($et, $tagTbl, $tags);
            return;
        }

        // check for Roadhawk dashcam text
        // ".;;;;D?JL;6+;;;D;R?;4;;;;DBB;;O;;;=D;L;;HO71G>F;-?=J-F:FNJJ;DPP-JF3F;;PL=DBRLBF0F;=?DNF-RD-PF;N;?=JF;;?D=F:*6F~"
        // decoded:
        // "X0000.2340Y-000.0720Z0000.9900G0001.0400$GPRMC,082138,A,5330.6683,N,00641.9749,W,012.5,87.86,050213,002.1,A"
        // (note: "002.1" is magnetic variation and is not decoded; it should have ",E" or ",W" afterward for direction)
        if (preg_match('/\*[0-9A-F]{2}~$/', $dataPt)) {
            // (ref https://reverseengineering.stackexchange.com/questions/11582/how-to-reverse-engineer-dash-cam-metadata)
            $decode = '-I8XQWRVNZOYPUTA0B1C2SJ9K.L,M$D3E4F5G6H7';
            $buff = substr($dataPt, 0, -4);
            for ($i = 0; $i < strlen($buff); ++$i) {
                $n = ord($buff[$i]) - 43;
                if ($n >= 0 and $n < strlen($decode)) $buff[$i] = $decode[$n];
            }
            if (preg_match('/X(.*?)Y(.*?)Z(.*?)G(.*?)\$/', $buff, $m)) {
                // yup. the decoding worked out
                $tags['Accelerometer'] = "$m[1] $m[2] $m[3] $m[4]";
                $dataPt = $buff;    // (process GPRMC below)
            }
        }

        // check for Thinkware format (and other NMEA RMC), eg:
        // "gsensori,4,512,-67,-12,100;GNRMC,161313.00,A,4529.87489,N,07337.01215,W,6.225,35.34,310819,,,A*52..;
        //  CAR,0,0,0,0.0,0,0,0,0,0,0,0,0"
        if (preg_match('/[A-Z]{2}RMC,(\d{2})(\d{2})(\d+(\.\d*)?),A?,(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/', $dataPt, $m) and
            // do some basic sanity checks on the date
            $m[13] <= 31 and $m[14] <= 12 and $m[15] <= 99)
        {
            $year = $m[15] + ($m[15] >= 70 ? 1900 : 2000);
            $tags['GPSDateTime'] = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', $year, $m[14], $m[13], $m[1], $m[2], $m[3]);
            $tags['GPSLatitude'] = ((Perl::num($m[5]) ?: 0) + $m[6] / 60) * ($m[7] === 'N' ? 1 : -1);
            $tags['GPSLongitude'] = ((Perl::num($m[8]) ?: 0) + $m[9] / 60) * ($m[10] === 'E' ? 1 : -1);
            if (strlen($m[11])) $tags['GPSSpeed'] = Perl::num($m[11]) * $knotsToKph;
            if (strlen($m[12])) $tags['GPSTrack'] = $m[12];
        }
        if (preg_match('/\bgsensori,(.*?)(;|$)/', $dataPt, $m)) $tags['GSensor'] = $m[1];
        if (preg_match('/\bCAR,(.*?)(;|$)/', $dataPt, $m)) $tags['Car'] = $m[1];

        if ($tags) {
            self::HandleTextTags($et, $tagTbl, $tags);
        } else {
            $et->UnknownTextCount = ($et->UnknownTextCount ?: 0) + 1;
            // give up trying to decode useful information if we haven't found anything for a while
            if ($et->UnknownTextCount > 100) $et->NoMoreTextDecoding = 1;
        }
    }

    //------------------------------------------------------------------------------
    // Extract embedded metadata from media samples
    // Inputs: 0) ExifTool ref
    // Notes: Also accesses ExifTool RAF*, SET_GROUP1, HandlerType, MetaFormat,
    //        ee*, and avcC elements (* = must exist)
    // - may be called either due to ExtractEmbedded option, or ImageDataHash requested
    // - hash includes only video and audio data
    public static function ProcessSamples(ExifTool $et)
    {
        [$raf, $ee] = [$et->RAF, $et->ee];
        $i = $pos = $hdrLen = $hdrFmt = $oldIndent = $hash = null;
        $timeArr = $durArr = [];    // (@time, @dur)
        $mdatOffset = $mdatSize = null; // (for range-checking samples when hash is done)

        if (!$ee) return;
        $et->ee = null;     // use only once

        $eeOpt = $et->Options('ExtractEmbedded') ?: 0;
        $type = $et->HandlerType ?: '';
        if ($type === 'vide') {
            // only process specific types of video streams
            $hash = $et->ImageDataHash;
            // only process specific video types if ExtractEmbedded was used
            // (otherwise we are only here to calculate the audio/video hash)
            if ($eeOpt) {
                if (!empty($ee['avcC'])) $type = 'avcC';
                elseif (!empty($ee['JPEG'])) $type = 'JPEG';
                elseif (!$hash) return;
            }
        } elseif ($type === 'soun') {
            $hash = $et->ImageDataHash;
            if (!$hash) return;
        } else {
            if (!$eeOpt) return;    // (don't do hash on other types)
        }

        $hashSize = 0;
        [$start, $size] = [$ee['start'] ?? null, $ee['size'] ?? null];
        //
        // determine sample start offsets from chunk offsets (stco) and sample-to-chunk table (stsc),
        // and sample time/duration from time-to-sample (stts)
        //
        if (!($start !== null and $size !== null)) {
            if ($size === null) return;
            [$stco, $stsc, $stts] = [$ee['stco'] ?? null, $ee['stsc'] ?? null, $ee['stts'] ?? null];
            if (!($stco !== null and $stsc !== null and $stsc)) return;
            $start = [];
            [$nextChunk, $iChunk] = [0, 1];
            $startChunk = $samplesPerChunk = $descIdx = $timeCount = $timeDelta = $time = null;
            if ($stts !== null and count($stts) > 1) {
                $time = 0;
                $timeCount = array_shift($stts);
                $timeDelta = array_shift($stts);
            }
            $ts = $et->MediaTS ?: 1;
            $chunkSizes = [];   // total size of each chunk (@chunkSize)
            foreach ($stco as $chunkStart) {
                if ($iChunk >= $nextChunk and $stsc) {
                    [$startChunk, $samplesPerChunk, $descIdx] = array_shift($stsc);
                    if ($stsc) $nextChunk = $stsc[0][0];
                }
                if (count($size) < count($start) + $samplesPerChunk) {
                    $et->Warn('Sample size error');
                    break;
                }
                if (!($chunkStart !== null and strlen(Perl::str($chunkStart)))) break;
                $sampleStart = $chunkStart;
                $chunkSize = 0;
                for ($i = 0; ; ) {      // Sample:
                    $start[] = $sampleStart;
                    if ($time !== null) {
                        while (!$timeCount) {
                            if (count($stts) < 2) {
                                $time = null;
                                break 2;    // last Sample
                            }
                            $timeCount = array_shift($stts);
                            $timeDelta = array_shift($stts);
                        }
                        $timeArr[] = $time / $ts;
                        $durArr[] = $timeDelta / $ts;
                        $time += $timeDelta;
                        --$timeCount;
                    }
                    // (eventually should use the description indices: $descIdx)
                    $chunkSize += $size[count($start) - 1];
                    if (++$i >= $samplesPerChunk) break;
                    $sampleStart += $size[count($start) - 1];
                }
                $chunkSizes[] = $chunkSize;
                ++$iChunk;
            }
            if (count($start) != count($size)) {
                $et->Warn('Incorrect sample start/size count');
                return;
            }
            // process as chunks if we are only interested in calculating hash
            if ($type === 'soun' or $type === 'vide') {
                $start = $stco;
                $size = $chunkSizes;
            }
        }
        //
        // extract and parse the sample data
        //
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
        $verbose = $et->Options('Verbose');
        $metaFormat = $et->MetaFormat ?: '';
        $tell = $raf->Tell();

        if ($verbose) {
            $et->VPrint(0, "---- Extract Embedded ----\n");
            $oldIndent = $et->INDENT;
            $et->INDENT = '';
        }
        if ($hash) {
            $mdatSize = $et->MediaDataSize;
            if ($mdatSize !== null) $mdatOffset = $et->MediaDataOffset;
        }
        // get required information from avcC box if parsing video data
        if ($type === 'avcC') {
            $hdrLen = (ExifTool::Get8u($ee['avcC'], 4) & 0x03) + 1;
            $hdrFmt = ($hdrLen == 4 ? 'N' : ($hdrLen == 2 ? 'n' : 'C'));
            // (require Image::ExifTool::H264: autoloaded)
        }

        // loop through all samples
        for ($i = 0; $i < count($start) and $i < count($size); ++$i) {

            // initialize our flags for setting GPSDateTime
            $et->FoundGPSLatitude = null;
            $et->FoundGPSDateTime = null;

            // range check the sample data for hash if necessary
            $sz = $size[$i];    // (Perl: my $size)
            if ($mdatOffset !== null) {
                if ($start[$i] < $mdatOffset) {
                    $et->Warn("Sample $i for '{$type}' data is before start of mdat");
                } elseif ($start[$i] + $sz > $mdatOffset + $mdatSize) {
                    $et->Warn("Sample $i for '{$type}' data runs off end of mdat");
                    $sz = $mdatOffset + $mdatSize - $start[$i];
                    if ($sz < 0) $sz = 0;
                }
            }
            // read the sample data
            if (!$raf->Seek($start[$i], 0)) {
                $et->Warn("Seek error in $type data");
                continue;
            }
            $buff = null;
            $n = $raf->Read($buff, $sz);
            if ($n != $sz) {
                $et->Warn("Error reading $type data");
                if (!$n) continue;
                $sz = $n;
            }
            if ($hash) {
                hash_update($hash, $buff);
                $hashSize += strlen($buff);
            }
            if ($type === 'avcC') {
                if (strlen($buff) <= $hdrLen) continue;
                // scan through all NAL units and send them to ParseH264Video()
                for ($pos = 0; ; ) {
                    $len = Perl::unpack("x$pos$hdrFmt", $buff)[0];
                    if ($pos + $hdrLen + $len > strlen($buff)) break;
                    $tmp = "\0\0\0\x01" . substr($buff, $pos + $hdrLen, $len);
                    H264::ParseH264Video($et, $tmp);
                    $pos += $hdrLen + $len;
                    if ($pos + $hdrLen >= strlen($buff)) break;
                }
                if ($et->GotNAL06 and $eeOpt < 3) break;
                continue;
            }
            if ($verbose > 1) {
                $hdr = $et->SET_GROUP1 ? "{$et->SET_GROUP1} Type='{$type}' Format='{$metaFormat}'" : "Type='{$type}'";
                $et->VPrint(1, "{$hdr}, Sample " . ($i + 1) . ' of ' . count($start) . " ($sz bytes)\n");
                $et->VerboseDump($buff, Addr: $start[$i]);
            }
            if ($type === 'text' or
                // (PNDM is normally 'text', but was sbtl/tx3g in concatenated Garmin sample output_3videos.mp4)
                ($type === 'sbtl' and $metaFormat === 'tx3g' and preg_match('/^..PNDM/s', $buff)))
            {

                $handled = null;
                self::FoundSomething($et, $tagTbl, $timeArr[$i] ?? null, $durArr[$i] ?? null);
                if (!preg_match('/^\$BEGIN/', $buff)) {
                    // remove ending "encd" box if it exists
                    $buff = preg_replace('/\0\0\0\x0cencd\0\0\x01\0$/', '', $buff, 1, $cnt);
                    if ($cnt) $sz -= 12;
                    // cameras such as the CanonPowerShotN100 store ASCII time codes with a
                    // leading 2-byte integer giving the length of the string
                    // (and chapter names start with a 2-byte integer too)
                    if ($sz >= 2 and Perl::unpack('n', $buff)[0] == $sz - 2) {
                        if ($sz == 2) continue;
                        $buff = substr($buff, 2);
                    }
                    $val = null;
                    // check for encrypted GPS text as written by E-PRANCE B47FS camera
                    if (preg_match('/^\0/', $buff) and preg_match('/\x0a$/', $buff) and strlen($buff) > 5) {
                        // decode simple ASCII difference cipher,
                        // based on known value of 4th-last char = '*'
                        $dif = ord('*') - ord(substr($buff, -4, 1));
                        $tmp = '';
                        foreach (Perl::unpack('C*', substr($buff, 1, -1)) as $c) $tmp .= chr(($c + $dif) & 0xff);
                        if ($verbose > 2) {
                            $et->VPrint(0, "[decrypted text]\n");
                            $et->VerboseDump($tmp);
                        }
                        if (preg_match('/^(.*?)(\$[A-Z]{2}RMC.*)/s', $tmp, $m)) {
                            [$val, $buff] = [$m[1], $m[2]];
                            $val = strtr($val, "\t", ' ');
                            if (strlen($val)) $et->HandleTag($tagTbl, 'RawGSensor', $val);
                        }
                    } elseif (preg_match('/^(\0.{3})?PNDM/s', $buff, $m)) {
                        // Garmin Dashcam format (actually binary, not text)
                        $n = ($m[1] ?? null) ? 4 : 0;   // skip leading 4-byte size word if it exists
                        if (strlen($buff) < 20 + $n) continue;
                        $et->HandleTag($tagTbl, 'GPSLatitude', ExifTool::Get32s($buff, 12 + $n) * 180 / 0x80000000);
                        $et->HandleTag($tagTbl, 'GPSLongitude', ExifTool::Get32s($buff, 16 + $n) * 180 / 0x80000000);
                        $et->HandleTag($tagTbl, 'GPSSpeed', ExifTool::Get16u($buff, 8 + $n) * Vars\QuickTime::$mphToKph);
                        self::SetGPSDateTime($et, $tagTbl, $timeArr[$i] ?? null, 1);
                        continue;   // all done (don't store/process as text)
                    }
                    if (!($val !== null or preg_match('/\0[^\0]/', $buff))) {
                        // just store any other plain text
                        $et->HandleTag($tagTbl, 'Text', $buff);
                        $handled = 1;
                    }
                }
                self::Process_text($et, $buff, $tagTbl, $handled);

            } elseif (!empty(Vars\QuickTime::$processByMetaFormat[$type])) {

                if ($tagTbl[$metaFormat]) {
                    $tagInfo = $et->GetTagInfo($tagTbl, $metaFormat, $buff);
                    if ($tagInfo and (!$tagInfo->Unknown or $et->OPTIONS['Unknown'] >= $tagInfo->Unknown)) {
                        self::FoundSomething($et, $tagTbl, $timeArr[$i] ?? null, $durArr[$i] ?? null);
                        $et->ee = $ee;  // need ee information for 'keys'
                        $et->HandleTag($tagTbl, $metaFormat, null,
                            DataPt: $buff,
                            Base: $start[$i],   // (Base must be set for CR3 files)
                            TagInfo: $tagInfo,
                        );
                        $et->ee = null;
                        // synthesize GPSDateTime if necessary for djmd metadata
                        if ($metaFormat === 'djmd') {
                            if ($et->GPSLatitude !== null and $et->GPSLongitude !== null and !$et->GPSDateTime) {
                                self::SetGPSDateTime($et, $tagTbl, $timeArr[$i] ?? null, 1); // (NC)
                            }
                            $et->GPSLatitude = null;
                            $et->GPSLongitude = null;
                            $et->GPSDateTime = null;
                        }
                    } elseif ($metaFormat === 'camm' and preg_match('/^X/', $buff)) {
                        // seen 'camm' metadata in this format (X/Y/Z acceleration and G force? + GPRMC + ?)
                        // "X0000.0000Y0000.0000Z0000.0000G0000.0000$GPRMC,000125,V,,,,,000.0,,280908,002.1,N*71~, 794021  \x0a"
                        self::FoundSomething($et, $tagTbl, $timeArr[$i] ?? null, $durArr[$i] ?? null);
                        if (preg_match('/X(.*?)Y(.*?)Z(.*?)G(.*?)\$/', $buff, $m)) $et->HandleTag($tagTbl, 'Accelerometer', "$m[1] $m[2] $m[3] $m[4]");
                        self::Process_text($et, $buff, $tagTbl);
                    }
                } elseif ($verbose) {
                    $et->VPrint(0, "Unknown $type format ($metaFormat)");
                }

            } elseif ($type === 'gps ') {   // (ie. GPSDataList tag)

                if (preg_match('/^....freeGPS /s', $buff)) {
                    // parse freeGPS data unless done already in brute-force scan
                    // (some videos don't reference all freeGPS info from 'gps ' table, eg. INNOV,
                    //  and some videos don't put 'gps ' data in mdat, eg XGODY 12" 4K Dashcam)
                    if ($et->FoundGPSByScan) break;
                    // decode "freeGPS " data (Novatek and others)
                    $di = [
                        'DataPt' => $buff,
                        'DataPos' => $start[$i],
                        'SampleTime' => $timeArr[$i] ?? null,
                        'SampleDuration' => $durArr[$i] ?? null,
                    ];
                    self::ProcessFreeGPS($et, $di, $tagTbl);
                }

            } elseif ($tagTbl[$type]) {

                $tagInfo = $et->GetTagInfo($tagTbl, $type, $buff);
                if ($tagInfo) {
                    self::FoundSomething($et, $tagTbl, $timeArr[$i] ?? null, $durArr[$i] ?? null);
                    $et->HandleTag($tagTbl, $type, null,
                        DataPt: $buff,
                        Base: $start[$i],   // (Base must be set for CR3 files)
                        TagInfo: $tagInfo,
                    );
                }
            }
            // generate approximate GPSDateTime if necessary
            if ($et->FoundGPSLatitude and !$et->FoundGPSDateTime) self::SetGPSDateTime($et, $tagTbl, $timeArr[$i] ?? null);
        }
        if ($verbose) {
            $str = $type === 'soun' ? 'Audio' : 'Video';
            if ($hashSize) $et->VPrint(0, "{$et->INDENT}(ImageDataHash: $hashSize bytes of $str data)\n");
            $et->INDENT = $oldIndent;
            $et->VPrint(0, "--------------------------\n");
        }
        // clean up
        $raf->Seek($tell, 0);   // restore original file position
        $et->DOC_NUM = null;
        $et->HandlerType = '';
    }

    //------------------------------------------------------------------------------
    // Convert latitude/longitude from DDDMM.MMMM format to decimal degrees
    // Inputs: 0) latitude, 1) longitude
    // Returns: lat/lon are changed in place
    // (note: this method works fine for negative coordinates)
    public static function ConvertLatLon(&$lat, &$lon)
    {
        $deg = Perl::int(Perl::num($lat) / 100);    // latitude
        $lat = $deg + (Perl::num($lat) - $deg * 100) / 60;
        $deg = Perl::int(Perl::num($lon) / 100);    // longitude
        $lon = $deg + (Perl::num($lon) - $deg * 100) / 60;
    }

    //------------------------------------------------------------------------------
    // Decrypt Lucky data
    // Inputs: 0) string to decrypt, 1) encryption key
    // Returns: decrypted string
    public static function DecryptLucky($str, $key)
    {
        $strA = Perl::unpack('C*', $str);   // (@str)
        $keyA = Perl::unpack('C*', $key);   // (@key)
        $enc = range(0, 255);
        [$i, $j, $k] = [0, 0, 0];
        do {
            $j = ($j + $enc[$i] + $keyA[$i % strlen($key)]) & 0xff;
            [$enc[$i], $enc[$j]] = [$enc[$j], $enc[$i]];
        } while (++$i < 256);
        [$i, $j, $k] = [0, 0, 0];
        do {
            $j = ($j + 1) & 0xff;
            $k = ($k + $enc[$j]) & 0xff;
            [$enc[$j], $enc[$k]] = [$enc[$k], $enc[$j]];
            $strA[$i] = ($strA[$i] ?? 0) ^ $enc[($enc[$j] + $enc[$k]) & 0xff];
        } while (++$i < count($strA));
        return Perl::pack('C*', ...$strA);
    }

    //------------------------------------------------------------------------------
    // Process "freeGPS " data blocks
    // Inputs: 0) ExifTool ref, 1) dirInfo ref {DataPt,SampleTime,SampleDuration}, 2) tagTable ref
    // Returns: 1 on success (or 0 on unrecognized or "measurement-void" GPS data)
    // Notes:
    public static function ProcessFreeGPS(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = &$dirInfo['DataPt'];  // (Perl modifies the data in place)
        $dirLen = strlen($dataPt);
        $yr = $mon = $day = $hr = $min = $sec = $ss = $stat = $lbl = $ddd = $done = null;
        $lat = $latRef = $lon = $lonRef = $spd = $trk = $alt = null;
        $acc = $xtra = [];
        $knotsToKph = Vars\QuickTime::$knotsToKph;
        $mpsToKph = Vars\QuickTime::$mpsToKph;

        if ($dirLen < 82) return 0;

        $debug = $et->Options('Debug');
        $oldOrder = ExifTool::GetByteOrder();
        ExifTool::SetByteOrder('II');
        $et->FoundEmbedded = 1;

        if (substr($dataPt, 18, 8) === "\xaa\xaa\xf2\xe1\xf0\xee\x54\x54") {

            if ($debug) $et->FoundTag('GPSType', 1);
            // (this is very similar to the encrypted text format)
            // decode encrypted ASCII-based GPS (DashCam Azdome GS63H, ref 5)
            // header looks like this in my sample:
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 05 01 00 00 [....freeGPS ....]
            //  0010: 01 03 aa aa f2 e1 f0 ee 54 54 98 9a 9b 92 9a 93 [........TT......]
            //  0020: 98 9e 98 98 9e 93 98 92 a6 9f 9f 9c 9d ed fa 8a [................]
            // decrypted (from byte 18):
            //  0000: 00 00 58 4b 5a 44 fe fe 32 30 31 38 30 39 32 34 [..XKZD..20180924]
            //  0010: 32 32 34 39 32 38 0c 35 35 36 37 47 50 20 20 20 [224928.5567GP   ]
            //  0020: 00 00 00 00 00 03 4e 34 30 34 36 34 33 35 30 57 [......N40464350W]
            //  0030: 30 30 37 30 34 30 33 30 38 30 30 30 30 30 30 30 [0070403080000000]
            //  0040: 37 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [7...............]
            //  [...]
            //  00a0: 00 00 00 00 00 00 00 00 00 00 00 00 00 2b 30 39 [.............+09]
            //  00b0: 33 2d 30 30 33 2d 30 30 35 00 00 00 00 00 00 00 [3-003-005.......]
            // header looks like this for EEEkit gps:
            //  0000: 00 00 04 00 66 72 65 65 47 50 53 20 f0 03 00 00 [....freeGPS ....]
            //  0010: 01 03 aa aa f2 e1 f0 ee 54 54 98 9a 98 9a 9a 9f [........TT......]
            //  0020: 9b 93 9b 9c 98 99 99 9f a6 9a 9a 98 9a 9a 9f 9b [................]
            //  0030: 93 9b 9c 98 99 99 9c a9 e4 99 9d 9e 9f 98 9e 9b [................]
            //  0040: 9c fd 9b 98 98 98 9f 9f 9a 9a 93 81 9a 9b 9d 9f [................]
            // decrypted (from byte 18):
            //  0000: 00 00 58 4b 5a 44 fe fe 32 30 32 30 30 35 31 39 [..XKZD..20200519]
            //  0010: 31 36 32 33 33 35 0c 30 30 32 30 30 35 31 39 31 [162335.002005191]
            //  0020: 36 32 33 33 36 03 4e 33 37 34 35 32 34 31 36 57 [62336.N37452416W]
            //  0030: 31 32 32 32 35 35 30 30 39 2b 30 31 37 35 30 31 [122255009+017501]
            //  0040: 31 2b 30 31 34 2b 30 30 32 2b 30 32 36 2b 30 31 [1+014+002+026+01]
            $n = $dirLen - 18;
            if ($n > 0x101) $n = 0x101;
            $buf2 = self::XorBytes(substr($dataPt, 18, $n), 0xaa);
            if ($et->Options('Verbose') > 1) {
                $et->VPrint(1, '[decrypted freeGPS data]');
                $et->VerboseDump($buf2);
            }
            // (extract longitude as 9 digits, not 8, ref PH)
            if (preg_match('/^.{8}(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2}).(.{15})([NS])(\d{8})([EW])(\d{9})(\d{8})?/s', $buf2, $m, PREG_UNMATCHED_AS_NULL)) {
                [$yr, $mon, $day, $hr, $min, $sec, $lbl, $latRef, $lat, $lonRef, $lon, $spd] = [$m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $m[8], $m[9] / 1e4, $m[10], $m[11] / 1e4, $m[12]];
                if ($spd !== null) { // (Azdome)
                    $spd = Perl::num($spd);     // remove leading 0's
                } elseif (preg_match('/^.{57}([-+]\d{4})(\d{3})/s', $buf2, $m)) { // (EEEkit)
                    // $alt = $1 + 0;  (doesn't look right for my sample, but the Ambarella A12 text has this)
                    $spd = Perl::num($m[2]);
                }
            }
            // extract accelerometer data (ref PH)
            if (preg_match('/^.{65}(([-+]\d{3})([-+]\d{3})([-+]\d{3})([-+]\d{3})*)/s', $buf2, $m)) {
                $s = $m[1];     // ($_)
                $acc = [$m[2] / 100, $m[3] / 100, $m[4] / 100];
                $s = preg_replace('/([-+])/', ' $1', $s);
                $s = preg_replace('/^ /', '', $s, 1);
                array_push($xtra, 'AccelerometerData', $s);
            } elseif (preg_match('/^.{173}([-+]\d{3})([-+]\d{3})([-+]\d{3})/s', $buf2, $m)) { // (Azdome)
                // (Adzome may contain acc and date/time/label even if GPS doesn't exist)
                $acc = [$m[1] / 100, $m[2] / 100, $m[3] / 100];
                if ($yr === null and preg_match('/^.{8}(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2}).(.{15})/s', $buf2, $m)) {
                    [$yr, $mon, $day, $hr, $min, $sec, $lbl] = [$m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7]];
                }
            }
            if ($lbl !== null) {
                $lbl = preg_replace('/\0.*/s', '', $lbl, 1);
                $lbl = preg_replace('/\s+$/', '', $lbl, 1);     // truncate at null and remove trailing spaces
                if (strlen($lbl)) array_push($xtra, 'UserLabel', $lbl);
            }

        } elseif (preg_match('/^.{52}(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/s', $dataPt, $m)) {

            if ($debug) $et->FoundTag('GPSType', 2);
            // decode NMEA-format GPS data (Nextbase 512GW dashcam, ref PH)
            // header looks like this in my sample:
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 40 01 00 00 [....freeGPS @...]
            //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0030: 00 00 00 00 32 30 31 38 30 39 31 39 31 30 30 39 [....201809191009]
            //  0040: 35 39 00 00 1c 01 00 00 06 00 00 00 ef ff ff ff [59..............]
            //  0050: 20 24 47 50 52 4d 43 2c 30 38 30 39 35 31 2e 30 [ $GPRMC,080951.0]
            //  0060: 30 30 2c 41 2c 35 32 30 37 2e 39 30 39 37 2c 4e [00,A,5207.9097,N]
            //  0070: 2c 30 30 35 30 35 2e 35 31 37 35 2c 45 2c 35 35 [,00505.5175,E,55]
            //  0080: 2e 31 31 2c 31 32 35 2e 35 38 2c 31 39 30 39 31 [.11,125.58,19091]
            //  0090: 38 2c 2c 2c 41 2a 35 39 0d 0a 00 00 00 00 00 00 [8,,,A*59........]
            array_push($xtra, 'CameraDateTime', "$m[1]:$m[2]:$m[3] $m[4]:$m[5]:$m[6]");
            if (preg_match('/\$[A-Z]{2}RMC,(\d{2})(\d{2})(\d+(\.\d*)?),A?,(\d+\.\d+),([NS]),(\d+\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/s', $dataPt, $m)) {
                [$lat, $latRef, $lon, $lonRef] = [$m[5], $m[6], $m[7], $m[8]];
                $yr = $m[13] + ($m[13] >= 70 ? 1900 : 2000);
                [$mon, $day, $hr, $min, $sec] = [$m[12], $m[11], $m[1], $m[2], $m[3]];
                if (strlen($m[9])) $spd = Perl::num($m[9]) * $knotsToKph;
                if (strlen($m[10])) $trk = $m[10];
            }
            if (preg_match('/\$[A-Z]{2}GGA,(\d{2})(\d{2})(\d+(\.\d*)?),(\d+\.\d+),([NS]),(\d+\.\d+),([EW]),[1-6]?,(\d+)?,(\.\d+|\d+\.?\d*)?,(-?\d+\.?\d*)?,M?/s', $dataPt, $m, PREG_UNMATCHED_AS_NULL)) {
                if ($yr === null) [$hr, $min, $sec, $lat, $latRef, $lon, $lonRef] = [$m[1], $m[2], $m[3], $m[5], $m[6], $m[7], $m[8]];
                $alt = $m[11];
                array_unshift($xtra, 'GPSSatellites', $m[9]);
                array_unshift($xtra, 'GPSDOP', $m[10]);
            }
            if ($lat !== null) {
                // extract accelerometer readings if GPS was valid
                // and change to signed integer and divide by 256
                $acc = array_map(static fn ($v) => self::SignedInt32($v) / 256, Perl::unpack('x68V3', $dataPt));
            }

        } elseif (preg_match('/^(.{37}|.{85})\0\0\0A([NS])([EW])\0/s', $dataPt, $m)) {

            if (strlen($m[1]) == 85) {
                // Kenwood DRV-A510W (header is 48 bytes longer)
                // 0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
                // 0010: 4d 4e 3a 44 52 56 2d 41 35 31 30 57 40 56 31 2e [MN:DRV-A510W@V1.]
                // 0020: 37 5f 42 44 5a 49 43 5a 5f 43 00 00 00 00 00 00 [7_BDZICZ_C......]
                // 0030: 00 00 00 00 00 00 00 00 3a 3a 73 74 61 72 74 40 [........::start@]
                // 0040: 0c 00 00 00 10 00 00 00 16 00 00 00 ea 07 00 00 [................]
                // 0050: 02 00 00 00 1c 00 00 00 41 4e 57 00 55 e7 a5 45 [........ANW.U..E]
                // 0060: d7 84 52 43 52 b8 d8 41 48 21 9a 43 f9 ff ff ff [..RCR..AH!.C....]
                // 0070: ff ff ff ff 01 00 00 00 00 00 00 00 00 00 00 00 [................]
                $dataPt = substr($dataPt, 48);
            }
            [$latRef, $lonRef] = [$m[2], $m[3]];
            [$hr, $min, $sec, $yr, $mon, $day] = array_pad(Perl::unpack('x16V6', $dataPt), 6, null);
            // test for base64-encoded and encrypted lucky gps strings
            $notEnc = $notStr = $lt = $ln = null;
            if (strlen($dataPt) < 0x78) {
                $notEnc = $notStr = 1;
            } else {
                $lt = substr($dataPt, 0x2c, 20);    // latitude
                $ln = substr($dataPt, 0x40, 20);    // longitude
                foreach ([$lt, $ln] as $s) { if (!preg_match('/^[A-Za-z0-9+\/]{8,20}={0,2}\0*$/', $s)) { $notEnc = 1; break; } }
                foreach ([$lt, $ln] as $s) { if (!preg_match('/^\d{1,5}\.\d+\0*$/', $s)) { $notStr = 1; break; } }
            }
            if ($notEnc and $notStr) {

                if ($debug) $et->FoundTag('GPSType', 3);
                // decode freeGPS from ViofoA119v3 dashcam (similar to Novatek GPS format)
                // 0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
                // 0010: 05 00 00 00 2f 00 00 00 03 00 00 00 13 00 00 00 [..../...........]
                // 0020: 09 00 00 00 1b 00 00 00 41 4e 57 00 25 d1 99 45 [........ANW.%..E]
                // 0030: f1 47 40 46 66 66 d2 41 85 eb 83 41 00 00 00 00 [.G@Fff.A...A....]
                if ($yr >= 2000) {
                    // Kenwood dashcam sometimes stores absolute year and local time
                    // (but sometimes year since 2000 and UTC time in same video!)
                    $time = ExifTool::TimeLocal($sec, $min, $hr, $day, $mon - 1, $yr);
                    [$sec, $min, $hr, $day, $mon, $yr] = ExifTool::PerlGmtime($time);
                    $yr += 1900;
                    ++$mon;
                    $et->Warn('Converting GPSDateTime to UTC based on local time zone', 1);
                }
                $lat = ExifTool::GetFloat($dataPt, 0x2c);
                $lon = ExifTool::GetFloat($dataPt, 0x30);
                $spd = ExifTool::GetFloat($dataPt, 0x34) * $knotsToKph;
                $trk = ExifTool::GetFloat($dataPt, 0x38);
                // (may be all zeros or int16u counting from 1 to 6 if not valid)
                $tmp = substr($dataPt, 60, 12);
                if ($tmp !== "\0\0\0\0\0\0\0\0\0\0\0\0" and $tmp !== "\x01\0\x02\0\x03\0\x04\0\x05\0\x06\0") {
                    $acc = array_map(static fn ($v) => self::SignedInt32($v) / 256, Perl::unpack('V3', $tmp));
                }

            } else {

                if ($debug) $et->FoundTag('GPSType', 4);
                // decode freeGPS from E-ACE B44 dashcam
                // 0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
                // 0010: 08 00 00 00 22 00 00 00 01 00 00 00 18 00 00 00 [...."...........]
                // 0020: 08 00 00 00 10 00 00 00 41 4e 45 00 67 4e 69 69 [........ANE.gNii]
                // 0030: 5a 38 4a 54 74 48 63 61 36 74 77 3d 00 00 00 00 [Z8JTtHca6tw=....]
                // 0040: 68 74 75 69 5a 4d 4a 53 73 58 55 58 37 4e 6f 3d [htuiZMJSsXUX7No=]
                // 0050: 00 00 00 00 64 3b ac 41 e1 3a 1d 43 2b 01 00 00 [....d;.A.:.C+...]
                // 0060: fd ff ff ff 43 00 00 00 32 4a 37 31 50 70 55 48 [....C...2J71PpUH]
                // 0070: 37 69 68 66 00 00 00 00 00 00 00 00 00 00 00 00 [7ihf............]
                // (16-byte string at 0x68 is base64 encoded and encrypted 'luckychip' string)
                $spd = ExifTool::GetFloat($dataPt, 0x54) * $knotsToKph;
                $trk = ExifTool::GetFloat($dataPt, 0x58);
                $acc = array_map(self::SignedInt32(...), Perl::unpack('x92V3', $dataPt));
                // (accelerometer scaling is roughly 1G=250-300, but it varies depending on the axis,
                //  so leave the values as raw.  The axes are positive acceleration up,left,forward)
                if ($notEnc) { // (not encrypted)
                    $lat = preg_replace('/\0+$/', '', $lt, 1);
                    $lon = preg_replace('/\0+$/', '', $ln, 1);
                } else {
                    // decode base64 strings
                    $lt = XMP::DecodeBase64($lt)->value;
                    $ln = XMP::DecodeBase64($ln)->value;
                    // try various keys to decrypt lat/lon
                    [$i, $ch, $key] = [0, 'a', Vars\QuickTime::$luckyKeys[0]];
                    for (; $i < 20; ++$i) {
                        if ($i) {
                            $key = str_replace('#', $ch, Vars\QuickTime::$luckyKeys[1]);
                            $ch = chr(ord($ch) + 1);
                        }
                        $lat = self::DecryptLucky($lt, $key);
                        if (!preg_match('/^\d{1,4}\.\d+$/', $lat)) { $lat = null; continue; }
                        $lon = self::DecryptLucky($ln, $key);
                        if (!preg_match('/^\d{1,5}\.\d+$/', $lon)) { $lon = null; continue; }
                        break;
                    }
                    if (!$lon) $et->Warn('Unknown encryption for latitude/longitude');
                }
            }

        } elseif (preg_match('/^(.{16}|.{48}|.{80})LIGOGPSINFO\0/s', $dataPt, $m) and strlen($dataPt) >= strlen($m[1]) + 0x84) {

            if ($debug) $et->FoundTag('GPSType', 5);
            $pos = strlen($m[1]);
            // iiway s1 dual dash cam - offset 16, encrypted and fuzzed with scale 1
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
            //  0010: 4c 49 47 4f 47 50 53 49 4e 46 4f 00 00 00 00 05 [LIGOGPSINFO.....]
            //  0020: 0a 00 00 00 23 23 23 23 6a 00 00 00 c0 20 20 20 [....####j....   ]
            //  0030: 20 f0 12 10 12 22 e1 0e 10 12 2f 90 10 13 02 f2 [ ...."..../.....]
            // XGODY 12" 4K Dashcam - offset 16, encrypted and fuzzed with scale 1
            //  0000: 00 00 00 a8 66 72 65 65 47 50 53 20 98 00 00 00 [....freeGPS ....]
            //  0010: 4c 49 47 4f 47 50 53 49 4e 46 4f 00 00 00 00 05 [LIGOGPSINFO.....]
            //  0020: cd 61 00 00 23 23 23 23 6d 00 00 00 c1 ec 41 20 [.a..####m.....A ]
            //  0030: 20 f0 12 10 12 24 e5 0e 10 11 2f 92 10 12 00 f6 [ ....$..../.....]
            // ABASK A8 4K Dashcam - offset 16, encrypted and fuzzed with scale 3
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
            //  0010: 4c 49 47 4f 47 50 53 49 4e 46 4f 00 00 00 00 05 [LIGOGPSINFO.....]
            //  0020: 00 00 00 00 23 23 23 23 69 00 00 00 c0 20 20 20 [....####i....   ]
            //  0030: 20 f0 12 10 12 23 e5 0e 10 12 2f 99 10 11 02 f2 [ ....#..../.....]
            // Unknown dashcam (forum16060) - offset 16, enciphered and fuzzed with scale 1
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 98 00 00 00 [..@.freeGPS ....]
            //  0010: 4c 49 47 4f 47 50 53 49 4e 46 4f 00 00 00 00 0d [LIGOGPSINFO.....]
            //  0020: 0a 00 00 00 23 23 23 23 3b 00 a0 34 46 44 46 31 [....####;..4FDF1]
            //  0030: 2f 44 39 2f 45 38 20 44 3d 4c 47 4a 4c 39 38 20 [/D9/E8 D=LGJL98 ]
            // Rexing dashcam V1GW-4K - offset 48, encrypted and fuzzed with scale 1
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
            //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0030: 4c 49 47 4f 47 50 53 49 4e 46 4f 00 00 00 00 05 [LIGOGPSINFO.....]
            //  0040: 01 00 00 00 23 23 23 23 73 00 00 00 c0 20 20 20 [....####s....   ]
            //  0050: 20 f0 12 10 12 23 e5 0e 10 12 2f 95 10 12 01 f3 [ ....#..../.....]
            // Kingslim D4 dashcam - offset 80, encrypted and fuzzed with scale 1
            //  0000: 0a 00 00 00 0b 00 00 00 07 00 00 00 e5 07 00 00 [................]
            //  0010: 06 00 00 00 03 00 00 00 41 4e 57 31 91 52 83 45 [........ANW1.R.E]
            //  0020: 15 70 fe c5 29 5c c3 41 ae c7 af 42 00 00 d1 be [.p..)\.A...B....]
            //  0030: 00 00 80 3b 00 00 2c 3e 00 00 00 00 00 00 00 00 [...;..,>........]
            //  0040: 00 00 00 00 00 00 00 00 00 00 00 00 26 26 26 26 [............&&&&]
            //  0050: 4c 49 47 4f 47 50 53 49 4e 46 4f 00 00 00 00 05 [LIGOGPSINFO.....]
            //  0060: 01 00 00 00 23 23 23 23 75 00 00 00 c0 22 20 20 [....####u...."  ]
            //  0070: 20 f0 12 10 12 21 e5 0e 10 12 2f 90 10 13 01 f2 [ ....!..../.....]
            $di = ['DataPt' => $dataPt, 'DirStart' => $pos, 'DirName' => "LigoGPS_$pos"];
            // (this is weak, but the only difference I could find between these 2 headers)
            // (NOTE: ../testpics/gps_video/forum16229.mp4 uses this word for a counter!)
            if ($pos == 16 and preg_match('/^.{12}\xf0\x03\0\0.{16}\0{4}/s', $dataPt)) $et->LigoGPSScale = 3;
            LigoGPS::ProcessLigoGPS($et, $di, $tagTbl);
            $done = 1;

            // also... when offset is 0x50 (Kingslim), the GPS also exists in this format:
            // ($latRef, $lonRef) = ($1, $2);
            // ($hr,$min,$sec,$yr,$mon,$day) = unpack("V6", $$dataPt);
            // # lat/lon aren't decoded properly, but spd,trk,acc are
            // $lat = GetFloat($dataPt, 0x1c);
            // $lon = GetFloat($dataPt, 0x20);
            // $et->VPrint(0, sprintf("Raw lat/lon = %.9f %.9f\n", $lat, $lon));
            // $et->Warn('GPSLatitude/Longitude encryption is not yet known, so these will be wrong');
            // $lat = abs $lat;
            // $lon = abs $lon;
            // $spd = GetFloat($dataPt, 0x24) * $knotsToKph; # (convert knots to km/h)
            // $trk = GetFloat($dataPt, 0x28);
            // $acc[0] = GetFloat($dataPt, 0x2c);
            // $acc[1] = GetFloat($dataPt, 0x30);
            // $acc[2] = GetFloat($dataPt, 0x34);

        } elseif (preg_match('/^.{60}A\0{3}.{4}([NS])\0{3}.{4}([EW])\0{3}/s', $dataPt, $m)) {

            if ($debug) $et->FoundTag('GPSType', 6);
            // decode freeGPS from Akaso dashcam
            // 0000: 00 00 80 00 66 72 65 65 47 50 53 20 60 00 00 00 [....freeGPS `...]
            // 0010: 78 2e 78 78 00 00 00 00 00 00 00 00 00 00 00 00 [x.xx............]
            // 0020: 30 30 30 30 30 00 00 00 00 00 00 00 00 00 00 00 [00000...........]
            // 0030: 12 00 00 00 2f 00 00 00 19 00 00 00 41 00 00 00 [..../.......A...]
            // 0040: 13 b3 ca 44 4e 00 00 00 29 92 fb 45 45 00 00 00 [...DN...)..EE...]
            // 0050: d9 ee b4 41 ec d1 d3 42 e4 07 00 00 01 00 00 00 [...A...B........]
            // 0060: 0c 00 00 00 01 00 00 00 05 00 00 00 00 00 00 00 [................]
            // (unknown dashcam, "Anticlock 2 2020_1125_1455_007.MOV"):
            // 0000: 00 00 80 00 66 72 65 65 47 50 53 20 68 00 00 00 [....freeGPS h...]
            // 0010: 32 30 31 33 30 33 32 35 41 00 00 00 00 00 00 00 [20130325A.......]
            // 0020: 41 70 72 20 20 36 20 32 30 31 36 2c 20 31 36 3a [Apr  6 2016, 16:]
            // 0030: 0e 00 00 00 38 00 00 00 22 00 00 00 41 00 00 00 [....8..."...A...]
            // 0040: 8a 63 24 45 53 00 00 00 9f e6 42 45 45 00 00 00 [.c$ES.....BEE...]
            // 0050: 59 c0 04 3f 52 b8 42 41 14 00 00 00 0b 00 00 00 [Y..?R.BA........]
            // 0060: 19 00 00 00 06 00 00 00 05 00 00 00 f6 ff ff ff [................]
            // 0070: 03 00 00 00 04 00 00 00 00 00 00 00 00 00 00 00 [................]
            [$latRef, $lonRef] = [$m[1], $m[2]];
            $a = array_pad(Perl::unpack('x48V3x28V6', $dataPt), 6, null);
            [$hr, $min, $sec, $yr, $mon, $day] = $a;
            $acc = array_slice($a, 6);
            $lat = ExifTool::GetFloat($dataPt, 0x40);
            $lon = ExifTool::GetFloat($dataPt, 0x48);
            $spd = ExifTool::GetFloat($dataPt, 0x50);
            $trk = ExifTool::GetFloat($dataPt, 0x54);
            if (substr($dataPt, 16, 4) === 'x.xx') {
                $trk += 180;    // (why is this off by 180?)
                if ($trk >= 360) $trk -= 360;
                $acc = [];
            } else {
                $acc = array_map(static fn ($v) => self::SignedInt32($v) / 1000, $acc); // (NC)
            }

        } elseif (preg_match('/^.{60}4W`b]S</s', $dataPt) and strlen($dataPt) >= 140) {

            if ($debug) $et->FoundTag('GPSType', 7);
            // 0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 01 00 00 [..@.freeGPS ....]
            // 0010: 5a 58 53 42 4e 58 59 53 00 00 00 00 00 00 00 00 [ZXSBNXYS........]
            // 0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            // 0030: 00 00 00 00 00 00 00 00 00 00 00 00 34 57 60 62 [............4W`b]
            // 0040: 5d 53 3c 41 44 45 41 41 42 3e 40 40 3c 51 3c 45 []S<ADEAAB>@@<Q<E]
            // 0050: 41 40 43 3e 41 47 49 48 44 3c 5e 3c 40 41 46 43 [A@C>AGIHD<^<@AFC]
            // 0060: 42 3e 49 49 40 42 45 3c 55 3c 45 47 3e 45 43 41 [B>II@BE<U<EG>ECA]
            // decipher $GPRMC by subtracting 16 from each character value
            $s = '';    // ($_)
            foreach (Perl::unpack('x60C80', $dataPt) as $c) $s .= chr($c >= 16 ? $c - 16 : 0);
            if (preg_match('/[A-Z]{2}RMC,(\d{2})(\d{2})(\d+(\.\d*)?),A?,(\d*?\d{1,2}\.\d+),([NS]),(\d*?\d{1,2}\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/', $s, $m)) {
                [$yr, $mon, $day, $hr, $min, $sec, $lat, $latRef, $lon, $lonRef] = [$m[13], $m[12], $m[11], $m[1], $m[2], $m[3], $m[5], $m[6], $m[7], $m[8]];
                $yr += ($yr >= 70 ? 1900 : 2000);
                if (strlen($m[9])) $spd = Perl::num($m[9]) * $knotsToKph;
                if (strlen($m[10])) $trk = $m[10];
            } else {
                $done = 1;
            }

        } elseif (preg_match('/^.{64}[\x01-\x0c]\0{3}[\x01-\x1f]\0{3}A[NS][EW]\0{5}/s', $dataPt)) {

            if ($debug) $et->FoundTag('GPSType', 8);
            // Akaso V1 dascham
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 78 00 00 00 [....freeGPS x...]
            //  0010: 59 6e 64 41 6b 61 73 6f 43 61 72 00 00 00 00 00 [YndAkasoCar.....]
            //  0020: 30 30 30 30 30 00 00 00 00 00 00 00 00 00 00 00 [00000...........]
            //  0030: 0e 00 00 00 27 00 00 00 2c 00 00 00 e3 07 00 00 [....'...,.......]
            //  0040: 05 00 00 00 1d 00 00 00 41 4e 45 00 00 00 00 00 [........ANE.....]
            //  0050: f1 4e 3e 3d 90 df ca 40 e3 50 bf 0b 0b 31 a0 40 [.N>=...@.P...1.@]
            //  0060: 4b dc c8 41 9a 79 a7 43 34 58 43 31 4f 37 31 35 [K..A.y.C4XC1O715]
            //  0070: 35 31 32 36 36 35 37 35 59 4e 44 53 0d e7 cc f9 [51266575YNDS....]
            //  0080: 00 00 00 00 05 00 00 00 00 00 00 00 00 00 00 00 [................]
            // Redtiger F7N dashcam
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 01 00 00 [..@.freeGPS ....]
            //  0010: 0a 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0020: 01 00 00 00 b0 56 50 01 7b 18 68 45 17 02 3f 46 [.....VP.{.hE..?F]
            //  0030: 13 00 00 00 01 00 00 00 06 00 00 00 15 00 00 00 [................]
            //  0040: 0c 00 00 00 1c 00 00 00 41 4e 57 00 00 00 00 00 [........ANW.....]
            //  0050: 80 d4 26 4e 36 11 b5 40 74 b5 15 7b cd 7b f3 40 [..&N6..@t..{.{.@]
            //  0060: 0a d7 a3 3d cd 4c 4e 43 38 34 37 41 45 48 31 36 [...=.LNC847AEH16]
            //  0070: 33 36 30 38 32 34 35 37 59 53 4b 4a 01 00 00 00 [36082457YSKJ....]
            //  0080: ec ff ff ff 00 00 00 00 0e 00 00 00 01 00 00 00 [................]
            //  0090: 0a 00 00 00 e5 07 00 00 0c 00 00 00 1c 00 00 00 [................]
            [$hr, $min, $sec, $yr, $mon, $day, $stat, $latRef, $lonRef] =
                array_pad(Perl::unpack('x48V6a1a1a1x1', $dataPt), 9, null);

            $et->Warn('GPSLatitude/Longitude encryption is not yet known, so these will be wrong');
            // (see https://exiftool.org/forum/index.php?topic=11320.0)

            $spd = ExifTool::GetFloat($dataPt, 0x60);
            $trk = ExifTool::GetFloat($dataPt, 0x64) + 180;     // (why is this off by 180?)
            $lat = ExifTool::GetDouble($dataPt, 0x50);  // latitude is here, but encrypted somehow
            $lon = ExifTool::GetDouble($dataPt, 0x58);  // longitude is here, but encrypted somehow
            $ddd = 1;   // don't convert until we know what the format is
            //my $serialNum = substr($$dataPt, 0x68, 20); # (confirmed)

        } elseif (preg_match('/^.{12}\xac\0\0\0.{44}(.{72})/s', $dataPt)) {

            if ($debug) $et->FoundTag('GPSType', 9);
            // EACHPAI dash cam
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 ac 00 00 00 [....freeGPS ....]
            //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0030: 00 00 00 00 00 00 00 00 00 00 00 00 34 57 60 62 [............4W`b]
            //  0040: 5d 53 3c 41 47 45 45 42 42 3e 40 40 40 3c 51 3c []S<AGEEBB>@@@<Q<]
            //  0050: 44 42 44 40 3e 48 46 43 45 3c 5e 3c 40 48 43 41 [DBD@>HFCE<^<@HCA]
            //  0060: 42 3e 46 42 47 48 3c 67 3c 40 3e 40 42 3c 43 3e [B>FBGH<g<@>@B<C>]
            //  0070: 43 41 3c 40 42 40 46 42 40 3c 3c 3c 51 3a 47 46 [CA<@B@FB@<<<Q:GF]
            //  0080: 00 2a 36 35 00 00 00 00 00 00 00 00 00 00 00 00 [.*65............]

            $et->Warn("Can't yet decrypt EACHPAI timed GPS", 1);
            // (see https://exiftool.org/forum/index.php?topic=5095.msg61266#msg61266)
            $done = 1;

          //  my $time = pack 'C*', map { $_ ^= 0 } unpack 'C*', $1;
          //  # bytes 7-12 are the timestamp in ASCII HHMMSS after xor-ing with 0x70
          //  substr($time,7,6) = pack 'C*', map { $_ ^= 0x70 } unpack 'C*', substr($time,7,6);
          //  # (other values are currently unknown)

        } elseif (preg_match('/^.{64}A([NS])([EW])\0/s', $dataPt, $m)) {

            if ($debug) $et->FoundTag('GPSType', 10);
            // Vantrue S1 dashcam
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 78 00 00 00 [....freeGPS x...]
            //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0030: 68 6f 72 73 6f 6e 74 65 63 68 00 00 00 00 00 00 [horsontech......]
            //  0040: 41 4e 45 00 15 00 00 00 07 00 00 00 02 00 00 00 [ANE.............]
            //  0050: 03 00 00 00 35 00 00 00 05 00 00 00 4f 74 4c 44 [....5.......OtLD]
            //  0060: e2 77 a0 45 89 c1 98 42 71 bd ac 42 02 ab 0d 43 [.w.E...Bq..B...C]
            //  0070: 05 00 00 00 7f 00 00 00 07 01 00 00 00 00 00 00 [................]
            [$latRef, $lonRef] = [$m[1], $m[2]];
            $a = array_pad(Perl::unpack('x68V6x20V3', $dataPt), 6, null);
            [$yr, $mon, $day, $hr, $min, $sec] = $a;
            $acc = array_slice($a, 6);
            if ($mon >= 1 and $mon <= 12 and $day >= 1 and $day <= 31) {
                // (not sure about acc scaling)
                $acc = array_map(static fn ($v) => self::SignedInt32($v) / 1000, $acc);
                $lon = ExifTool::GetFloat($dataPt, 0x5c);
                $lat = ExifTool::GetFloat($dataPt, 0x60);
                $spd = ExifTool::GetFloat($dataPt, 0x64) * $knotsToKph;
                $trk = ExifTool::GetFloat($dataPt, 0x68);
                $alt = ExifTool::GetFloat($dataPt, 0x6c);
            } else {
                $done = 1;
            }

        } elseif (substr($dataPt, 0x45, 3) === 'ATC') {

            if ($debug) $et->FoundTag('GPSType', 11);
            // header looks like this: (sample 1)
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 38 06 00 00 [....freeGPS 8...]
            //  0010: 49 51 53 32 30 31 33 30 33 30 36 42 00 00 00 00 [IQS20130306B....]
            //  0020: 4d 61 79 20 31 35 20 32 30 31 35 2c 20 31 39 3a [May 15 2015, 19:]
            // (sample 2)
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 4c 06 00 00 [....freeGPS L...]
            //  0010: 32 30 31 33 30 33 31 38 2e 30 31 00 00 00 00 00 [20130318.01.....]
            //  0020: 4d 61 72 20 31 38 20 32 30 31 33 2c 20 31 34 3a [Mar 18 2013, 14:]

            $recPos = $lastRecPos = $foundNew = null;
            $verbose = $et->Options('Verbose');
            $dataPos = $dirInfo['DataPos'] ?? null;
            if (!($et->FreeGPS2['Then'] ?? null)) $et->FreeGPS2['Then'] = array_fill(0, 6, 0);
            $then = $et->FreeGPS2['Then'];
            $dateMax = Vars\QuickTime::$dateMax;
            // Loop through records in the ATC-type GPS block until we find the most recent.
            // If we have already found one, then we only need to check the first record
            // (in case the buffer wrapped around), and the record after the position of
            // the last record we found, because the others will be old.  Odd, but this
            // is the way it is done...  I have only seen one new 52-byte record in the
            // entire 32 kB block, but the entire device ring buffer (containing 30
            // entries in my samples) is stored every time.  The code below allows for
            // the possibility of missing blocks and multiple new records in a single
            // block, but I have never seen this.  Note that there may be some earlier
            // GPS records at the end of the first block that we will miss decoding, but
            // these should (I believe) be before the start of the video
            for ($recPos = 0x30; $recPos + 52 < $dirLen; $recPos += 52) {   // ATCRec:

                $a = substr($dataPt, $recPos, 52);  // isolate a single record
                // decrypt record
                $aa = Perl::unpack('C*', $a);       // (@a)
                [$key1, $key2] = [$aa[0x14], $aa[0x1c]];
                foreach ([...range(0x00, 0x14), ...range(0x18, 0x1b)] as $k) $aa[$k] ^= $key1;
                foreach ([0x1c, ...range(0x20, 0x32)] as $k) $aa[$k] ^= $key2;
                $b = Perl::pack('C*', ...$aa);
                // unpack and validate date/time
                $now = Perl::unpack('x13C3x28vC2', $b);     // (H-1,M,S,Y,m,d)
                $now[0] = ($now[0] + 1) & 0xff;             // increment hour
                for ($i = 0; $i < count($dateMax); ++$i) {
                    if ($now[$i] <= $dateMax[$i]) continue;
                    $et->Warn('Invalid GPS date/time');
                    continue 2;     // ignore this record (next ATCRec)
                }
                // look for next ATC record in temporal sequence
                foreach ([3, 4, 5, 0, 1, 2] as $i) {
                    if ($now[$i] < $then[$i]) {
                        if ($foundNew) break 2;     // last ATCRec
                        break;
                    }
                    if ($now[$i] == $then[$i]) continue;
                    // we found a more recent record -- extract it and remember its location
                    if ($verbose) {
                        $et->VPrint(2, "  + [encrypted GPS record]\n");
                        $et->VerboseDump($a, DataPos: $dataPos + $recPos);
                        $et->VPrint(2, "  + [decrypted GPS record]\n");
                        $et->VerboseDump($b);
                        //my @v = unpack 'H8VVC4V!CA3V!CA3VvvV!vCCCCH4', $b;
                        //$et->VPrint(2, "  + [unpacked: @v]\n");
                        // values unpacked above (ref PH):
                        //  0) 0x00 4 bytes - byte 0=1, 1=counts to 255, 2=record index, 3=0 (ref 3)
                        //  1) 0x04 4 bytes - int32u: bits 0-4=day, 5-8=mon, 9-19=year (ref 3)
                        //  2) 0x08 4 bytes - int32u: bits 0-5=sec, 6-11=min, 12-16=hour (ref 3)
                        //  3) 0x0c 1 byte  - seen values of 0,1,2 - GPS status maybe?
                        //  4) 0x0d 1 byte  - hour minus 1
                        //  5) 0x0e 1 byte  - minute
                        //  6) 0x0f 1 byte  - second
                        //  7) 0x10 4 bytes - int32s latitude * 1e7
                        //  8) 0x14 1 byte  - always 0 (used for decryption)
                        //  9) 0x15 3 bytes - always "ATC"
                        // 10) 0x18 4 bytes - int32s longitude * 1e7
                        // 11) 0x1c 1 byte  - always 0 (used for decryption)
                        // 12) 0x1d 3 bytes - always "001"
                        // 13) 0x20 4 bytes - int32s speed * 100 (m/s)
                        // 14) 0x24 2 bytes - int16u heading * 100 (-180 to 180 deg)
                        // 15) 0x26 2 bytes - always zero
                        // 16) 0x28 4 bytes - int32s altitude * 1000 (ref 3)
                        // 17) 0x2c 2 bytes - int16u year
                        // 18) 0x2e 1 byte  - month
                        // 19) 0x2f 1 byte  - day
                        // 20) 0x30 1 byte  - unknown
                        // 21) 0x31 1 byte  - always zero
                        // 22) 0x32 2 bytes - checksum ?
                    }
                    $then = $et->FreeGPS2['Then'] = $now;
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    $trk = ExifTool::Get16s($b, 0x24) / 100;
                    if ($trk < 0) $trk += 360;
                    $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', $now[3], $now[4], $now[5], $now[0], $now[1], $now[2]);
                    $et->HandleTag($tagTbl, 'GPSDateTime', $time);
                    $et->HandleTag($tagTbl, 'GPSLatitude', ExifTool::Get32s($b, 0x10) / 1e7);
                    $et->HandleTag($tagTbl, 'GPSLongitude', ExifTool::Get32s($b, 0x18) / 1e7);
                    $et->HandleTag($tagTbl, 'GPSSpeed', ExifTool::Get32s($b, 0x20) / 100 * $mpsToKph);
                    $et->HandleTag($tagTbl, 'GPSTrack', $trk);
                    $et->HandleTag($tagTbl, 'GPSAltitude', ExifTool::Get32s($b, 0x28) / 1000);
                    $lastRecPos = $recPos;
                    $foundNew = 1;
                    // don't skip to location of previous recent record in ring buffer
                    // since we found a more recent record here
                    unset($et->FreeGPS2['RecentRecPos']);
                    break;
                }
                // skip older records
                $recentRecPos = $et->FreeGPS2['RecentRecPos'] ?? null;
                if ($recentRecPos and $recPos < $recentRecPos) $recPos = $recentRecPos;
            }
            // save position of most recent record (needed when parsing the next freeGPS block)
            $et->FreeGPS2['RecentRecPos'] = $lastRecPos;
            $done = 1;

        } elseif (preg_match('/^.{60}A\0.{10}([NS])\0.{14}([EW])\0/s', $dataPt, $m) and $dirLen >= 0x88) {

            if ($debug) $et->FoundTag('GPSType', 12);
            // header looks like this in my sample:
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 08 01 00 00 [....freeGPS ....]
            //  0010: 32 30 31 33 30 38 31 35 2e 30 31 00 00 00 00 00 [20130815.01.....]
            //  0020: 4a 75 6e 20 31 30 20 32 30 31 37 2c 20 31 34 3a [Jun 10 2017, 14:]

            // Type 2 (ref PH):
            // 0x30 - int32u hour
            // 0x34 - int32u minute
            // 0x38 - int32u second
            // 0x3c - int32u GPS status ('A' or 'V')
            // 0x40 - double latitude  (DDMM.MMMMMM)
            // 0x48 - int32u latitude ref  ('N' or 'S')
            // 0x50 - double longitude (DDMM.MMMMMM)
            // 0x58 - int32u longitude ref ('E' or 'W')
            // 0x60 - double speed (knots)
            // 0x68 - double heading (deg)
            // 0x70 - int32u year - 2000
            // 0x74 - int32u month
            // 0x78 - int32u day
            // 0x7c - int32s[3] accelerometer * 1000
            [$latRef, $lonRef] = [$m[1], $m[2]];
            $a = array_pad(Perl::unpack('x48V3x52V6', $dataPt), 6, null);
            [$hr, $min, $sec, $yr, $mon, $day] = $a;
            $acc = array_map(static fn ($v) => self::SignedInt32($v) / 1000, array_slice($a, 6));
            $lat = ExifTool::GetDouble($dataPt, 0x40);
            $lon = ExifTool::GetDouble($dataPt, 0x50);
            $spd = ExifTool::GetDouble($dataPt, 0x60) * $knotsToKph;
            $trk = ExifTool::GetDouble($dataPt, 0x68);

        } elseif (preg_match('/^.{16}A([NS])([EW])\0/s', $dataPt)) {

            if ($debug) $et->FoundTag('GPSType', 13);
            // INNOVV MP4 video (same format as INNOVV TS)
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
            //  0010: 41 4e 45 00 e4 56 96 45 86 b1 ca 44 5c 8f e2 40 [ANE..V.E...D\..@]
            //  0020: 33 33 58 43 c3 00 00 00 30 00 00 00 a0 fe ff ff [33XC....0.......]
            //  0030: 41 4e 45 00 e3 56 96 45 82 b1 ca 44 5c 8f fa 40 [ANE..V.E...D\..@]
            //  0040: c3 75 56 43 8c ff ff ff 8c 00 00 00 c3 fd ff ff [.uVC............]
            preg_match_all('/(A[NS][EW]\0.{28})/s', $dataPt, $mm);
            foreach ($mm[1] as $dat) {
                $lat = abs(ExifTool::GetFloat($dat, 4));    // (abs just to be safe)
                $lon = abs(ExifTool::GetFloat($dat, 8));    // (abs just to be safe)
                $spd = ExifTool::GetFloat($dat, 12) * $knotsToKph;
                $trk = ExifTool::GetFloat($dat, 16);
                $acc = array_map(self::SignedInt32(...), Perl::unpack('x20V3', $dat));
                self::ConvertLatLon($lat, $lon);
                $et->DOC_NUM = ++$et->DOC_COUNT;
                $et->HandleTag($tagTbl, 'GPSLatitude', $lat * (substr($dat, 1, 1) === 'S' ? -1 : 1));
                $et->HandleTag($tagTbl, 'GPSLongitude', $lon * (substr($dat, 2, 1) === 'W' ? -1 : 1));
                $et->HandleTag($tagTbl, 'GPSSpeed', $spd);
                $et->HandleTag($tagTbl, 'GPSTrack', $trk);
                $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr($acc));
            }
            $done = 1;

        } elseif (preg_match('/^.{20}[\0-\x18][\0-\x3b]{2}[\0-\x09]A([NS])([EW])/s', $dataPt)) {

            if ($debug) $et->FoundTag('GPSType', 14);
            // XBHT motorcycle dashcam Model XB702
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
            //  0010: 00 17 05 11 0d 25 18 00 41 4e 45 64 83 3f 00 00 [.....%..ANEd.?..]
            //  0020: 44 3d c5 02 48 6d ff 07 df 03 00 00 6b 00 00 00 [D=..Hm......k...]
            //  0030: 00 00 00 00 00 17 05 11 0d 25 18 01 41 4e 45 64 [.........%..ANEd]
            //  0040: 8b 3f 00 00 30 3d c5 02 50 6d ff 07 df 03 00 00 [.?..0=..Pm......]
            preg_match_all('/(.{7}[\0-\x09]A[NS][EW].{25})/s', $dataPt, $mm);
            foreach ($mm[1] as $dat) {
                [$yr, $mon, $day, $hr, $min, $sec, $ss, $latRef, $lonRef, $lat, $lon, $spd] =
                    Perl::unpack('xC7xCCx5VVx4v', $dat);
                $yr += 2000;
                $lat /= 1e4;
                $lon /= 1e4;
                self::ConvertLatLon($lat, $lon);
                $et->DOC_NUM = ++$et->DOC_COUNT;
                $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d.%d', $yr, $mon, $day, $hr, $min, $sec, $ss);
                $et->HandleTag($tagTbl, 'GPSDateTime', $time);
                // (sic: $latRef/$lonRef are unpacked as numbers, so these never match in Perl)
                $et->HandleTag($tagTbl, 'GPSLatitude', $lat * (Perl::str($latRef) === 'S' ? -1 : 1));
                $et->HandleTag($tagTbl, 'GPSLongitude', $lon * (Perl::str($lonRef) === 'W' ? -1 : 1));
                $et->HandleTag($tagTbl, 'GPSSpeed', $spd);
            }
            $done = 1;

        } elseif (preg_match('/^.{28}A.{11}([NS]).{15}([EW])/s', $dataPt, $m)) {

            if ($debug) $et->FoundTag('GPSType', 15);
            // Vantrue N4 dashcam
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 f0 03 00 00 [..@.freeGPS ....]
            //  0010: 0d 00 00 00 16 00 00 00 1e 00 00 00 41 00 00 00 [............A...]
            //  0020: 2c b7 b4 1a 5a 71 b2 40 4e 00 00 00 00 00 00 00 [,...Zq.@N.......]
            //  0030: fb ae 08 fe 77 f6 89 40 45 00 00 00 00 00 00 00 [....w..@E.......]
            //  0040: be 9f 1a 2f dd 84 36 40 5c 8f c2 f5 28 fc 68 40 [.../..6@\...(.h@]
            //  0050: 16 00 00 00 0c 00 00 00 0e 00 00 00 f2 fb ff ff [................]
            //  0060: 42 00 00 00 02 00 00 00 20 24 47 4e 52 4d 43 2c [B....... $GNRMC,]
            //  0070: 31 33 32 32 33 30 2e 30 30 30 2c 41 2c 34 37 32 [132230.000,A,472]
            //  0080: 31 2e 33 35 31 39 37 2c 4e 2c 30 30 38 33 30 2e [1.35197,N,00830.]
            //  0090: 38 30 38 35 39 2c 45 2c 32 32 2e 35 31 39 2c 31 [80859,E,22.519,1]
            //  00a0: 39 39 2e 38 38 2c 31 34 31 32 32 32 2c 2c 2c 41 [99.88,141222,,,A]
            //  00b0: 2a 37 35 0d 0a 00 00 00 00 00 00 00 00 00 00 00 [*75.............]
            [$latRef, $lonRef] = [$m[1], $m[2]];
            $a = array_pad(Perl::unpack('x16V3x52V3V3', $dataPt), 6, null);
            [$hr, $min, $sec, $yr, $mon, $day] = $a;
            $lat = abs(ExifTool::GetDouble($dataPt, 32));   // (abs just to be safe)
            $lon = abs(ExifTool::GetDouble($dataPt, 48));   // (abs just to be safe)
            $spd = ExifTool::GetDouble($dataPt, 64) * $knotsToKph;
            $trk = ExifTool::GetDouble($dataPt, 72);
            $acc = array_map(static fn ($v) => self::SignedInt32($v) / 1000, array_slice($a, 6)); // (NC)
            // (not necessary to read RMC sentence because we already have it all)

        } elseif (preg_match('/^.{72}A[NS][EW]\0/s', $dataPt)) {

            // decode binary GPS format (Viofo A119S, ref 2)
            // header looks like this in my sample:
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 4c 00 00 00 [....freeGPS L...]
            //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            //  0030: 10 00 00 00 2d 00 00 00 14 00 00 00 11 00 00 00 [....-...........]
            //  0040: 0c 00 00 00 1f 00 00 00 41 4e 45 00 5d 9a a9 45 [........ANE.]..E]
            //  0050: ab 1e e5 44 ec 51 f0 40 b8 5e a5 43 00 00 00 00 [...D.Q.@.^.C....]
            // (records are same structure as Type 3 Novatek GPS:)
            // Type 3 (Novatek GPS, ref 2):
            // 0x30 - int32u hour
            // 0x34 - int32u minute
            // 0x38 - int32u second
            // 0x3c - int32u year - 2000
            // 0x40 - int32u month
            // 0x44 - int32u day
            // 0x48 - int8u  GPS status ('A' or 'V')
            // 0x49 - int8u  latitude ref  ('N' or 'S')
            // 0x4a - int8u  longitude ref ('E' or 'W')
            // 0x4b - 0
            // 0x4c - float  latitude  (DDMM.MMMMMM)
            // 0x50 - float  longitude (DDMM.MMMMMM)
            // 0x54 - float  speed (knots)
            // 0x58 - float  heading (deg)
            // Type 3b, same as above for 0x30-0x4a (ref PH)
            // 0x4c - int32s latitude (decimal degrees * 1e7)
            // 0x50 - int32s longitude (decimal degrees * 1e7)
            // 0x54 - int32s speed (m/s * 100)
            // 0x58 - float  altitude (m * 1000, NC)
            [$hr, $min, $sec, $yr, $mon, $day, $stat, $latRef, $lonRef] =
                array_pad(Perl::unpack('x48V6a1a1a1x1V4', $dataPt), 9, null);
            if (substr($dataPt, 16, 3) === 'IQS') {
                if ($debug) $et->FoundTag('GPSType', 16);
                // IQS variant (ref PH)
                // header looks like this in my sample:
                //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 4c 00 00 00 [....freeGPS L...]
                //  0010: 49 51 53 5f 41 37 5f 32 30 31 35 30 34 31 37 00 [IQS_A7_20150417.]
                //  0020: 4d 61 72 20 32 39 20 32 30 31 37 2c 20 31 36 3a [Mar 29 2017, 16:]
                $ddd = 1;
                $lat = abs(ExifTool::Get32s($dataPt, 0x4c) / 1e7);
                $lon = abs(ExifTool::Get32s($dataPt, 0x50) / 1e7);
                $spd = ExifTool::Get32s($dataPt, 0x54) / 100 * $mpsToKph;
                $alt = ExifTool::GetFloat($dataPt, 0x58) / 1000;    // (NC)
            } else {
                $lat = ExifTool::GetFloat($dataPt, 0x4c);
                $lon = ExifTool::GetFloat($dataPt, 0x50);
                $spd = ExifTool::GetFloat($dataPt, 0x54) * $knotsToKph;
                $trk = ExifTool::GetFloat($dataPt, 0x58);   // (NC, may be GPSImageDirection)
                // Rexing V1-4k dashcam scales the lat/lon
                // (recognize this dashcam by the KodakVersion, "3.01.054" for my sample)
                //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 4c 00 00 00 [....freeGPS L...]
                //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                //  0030: 0e 00 00 00 22 00 00 00 28 00 00 00 14 00 00 00 [...."...(.......]
                //  0040: 02 00 00 00 16 00 00 00 41 4e 57 00 e9 7e 90 43 [........ANW..~.C]
                //  0050: 48 76 17 45 0c 02 48 42 14 6e 85 43 00 00 00 00 [Hv.E..HB.n.C....]
                if ($et->KodakVersion and $et->KodakVersion === '3.01.054') {
                    if ($debug) $et->FoundTag('GPSType', '17b');
                    $lat = ($lat - 187.982162849635) / 3;
                    $lon = ($lon - 2199.19873715495) / 2;
                    $ddd = 1;
                } elseif (ExifTool::Get32u($dataPt, 0) == 0x400000 and abs($lat) <= 90 and abs($lon) <= 180) {
                    if ($debug) $et->FoundTag('GPSType', '17c');
                    // Transcend Drive Body Camera 70
                    //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 4c 00 00 00 [..@.freeGPS L...]
                    //  0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                    //  0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
                    //  0030: 09 00 00 00 26 00 00 00 15 00 00 00 e9 07 00 00 [....&...........]
                    //  0040: 05 00 00 00 10 00 00 00 41 53 45 00 6c 59 ee 41 [........ASE.lY.A]
                    //  0050: 9f 1a f7 41 3c 6b 0f 41 9a 99 99 43 00 00 00 00 [...A<k.A...C....]
                    $ddd = 1;               // already in decimal degrees
                    $spd /= $knotsToKph;    // already in km/h
                } else {
                    if ($debug) $et->FoundTag('GPSType', 17);
                }
            }
            if ($dirLen >= 0xb0) {
                // lat/lon also stored as doubles by Transcend Driver Pro 230 (ref PH)
                [$lat2, $lon2] = [ExifTool::GetDouble($dataPt, 0x70), ExifTool::GetDouble($dataPt, 0x80)];
                // (0xa0 is altitude, don't know what 0x98 and 0xa8 are)
                if (abs($lat2 - $lat) < 0.001 and abs($lon2 - $lon) < 0.001) {
                    $lat = $lat2;
                    $lon = $lon2;
                    $alt = ExifTool::GetDouble($dataPt, 0xa0);
                }
            }

        } elseif (preg_match('<^.{23}(\d{4})/(\d{2})/(\d{2}) (\d{2}):(\d{2}):(\d{2}) [N|S]>s', $dataPt, $m)) {

            if ($debug) $et->FoundTag('GPSType', 18);
            // XGODY 12" 4K Dashcam
            //  0000: 00 00 00 a8 66 72 65 65 47 50 53 20 98 00 00 00 [....freeGPS ....]
            //  0010: 6e 6f 72 6d 61 6c 3a 32 30 32 34 2f 30 35 2f 32 [normal:2024/05/2]
            //  0020: 32 20 30 32 3a 35 34 3a 32 39 20 4e 3a 34 32 2e [2 02:54:29 N:42.]
            //  0030: 33 38 32 34 37 30 20 57 3a 38 33 2e 33 38 39 35 [382470 W:83.3895]
            //  0040: 37 30 20 35 33 2e 36 20 6b 6d 2f 68 20 78 3a 2d [70 53.6 km/h x:-]
            //  0050: 30 2e 30 32 20 79 3a 30 2e 39 39 20 7a 3a 30 2e [0.02 y:0.99 z:0.]
            //  0060: 31 30 20 41 3a 32 36 39 2e 32 20 48 3a 32 34 35 [10 A:269.2 H:245]
            //  0070: 2e 35 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [.5..............]
            [$yr, $mon, $day, $hr, $min, $sec] = [$m[1], $m[2], $m[3], $m[4], $m[5], $m[6]];
            $dataPt = preg_replace('/\0+$/', '', $dataPt, 1);   // remove trailing nulls
            $a = Perl::split(' ', substr($dataPt, 43));
            $ddd = 1;
            foreach ($a as $s) {
                if (!preg_match('/^([A-Z]):([-+]?\d+(\.\d+)?)$/i', $s, $m)) {
                    // (the "km/h" after spd is display units? because the value is stored in knots)
                    if ($lon !== null and $spd === null and preg_match('/^\d+\.\d+$/', $s)) $spd = $s * $knotsToKph;
                    continue;
                }
                if ($m[1] === 'N' or $m[1] === 'S') { $lat = $m[2]; $latRef = $m[1]; continue; }
                if ($m[1] === 'E' or $m[1] === 'W') { $lon = $m[2]; $lonRef = $m[1]; continue; }
                if ($m[1] === 'x' or $m[1] === 'y' or $m[1] === 'z') { $acc[] = $m[2]; continue; }
                if ($m[1] === 'A') { $trk = $m[2]; continue; }  // (verified, but why 'A'?)
                // seen 'H' - one might expect altitude ('H'eight), but it doesn't fit
                // the sample data, so save all other information as an "Unknown_X" tag
                if (!$tagTbl[$m[1]]) ExifTool::AddTagToTable($tagTbl, $m[1], ['Name' => "Unknown_$m[1]", 'Unknown' => 1]);
                array_push($xtra, $m[1], $m[2]);
            }

        } elseif (preg_match('/^.{30}A.{20}VV/', $dataPt)) {

            if ($debug) $et->FoundTag('GPSType', 19);
            // 70mai A810 dashcam (note: no timestamps in the samples I have)
            //  0000: 00 00 40 00 66 72 65 65 47 50 53 20 ed 01 00 00 [..@.freeGPS ....]
            //  0010: 03 00 ed 01 00 00 00 0f 00 00 70 08 00 00 41 66 [..........p...Af]
            //  0020: 13 7d 1e 3c 11 dc 03 5d 01 00 00 01 00 00 00 23 [.}.<...].......#]
            //  0030: 00 00 00 56 56 00 00 00 00 00 00 00 00 00 00 00 [...VV...........]
            //  0040: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
            ExifTool::SetByteOrder('II');
            self::SetGPSDateTime($et, $tagTbl, $dirInfo['SampleTime'] ?? null);
            $lat = ExifTool::Get32s($dataPt, 31) / 1e5;
            $lon = ExifTool::Get32s($dataPt, 35) / 1e5;
            $spd = ExifTool::Get32s($dataPt, 43);   // (seems to be km/h but not confirmed)
            // offset 475 - int16u=N string[N] - some sort of settings?:
            // eg. "\x15\x00{pA:V,rA:V,sF:0,tF:2}"

        } else {

            if ($debug) $et->FoundTag('GPSType', 20);
            // (look for binary GPS as stored by Nextbase 512G, ref PH)
            //  0000: 00 00 80 00 66 72 65 65 47 50 53 20 78 01 00 00 [....freeGPS x...]
            //  0010: 78 2e 78 78 00 00 00 00 00 00 00 00 00 00 00 00 [x.xx............]
            //  0020: 30 30 30 30 30 00 00 00 00 00 00 00 00 00 00 00 [00000...........]
            //  0030: 24 53 02 79 d4 85 07 e2 0a 08 06 2a 01 d1 02 20 [$S.y.......*... ]
            //  0040: 14 98 ff ff 21 67 97 10 00 00 00 00 00 00 00 00 [....!g..........]
            //  0050: 24 53 02 a2 d4 42 07 e2 0a 08 06 2a 01 d2 02 20 [$S...B.....*... ]
            //  0060: 14 98 e3 ff 21 67 3b 10 00 00 00 00 00 00 00 00 [....!g;.........]
            // 32-byte record structure (big endian!):
            // 0x30 - int16u unknown (seen: 0x24 0x53 = "$S")
            // 0x32 - int16u speed (m/s * 100)
            // 0x34 - int16s heading (deg * 100) (or GPSImgDirection?)
            // 0x36 - int16u year
            // 0x38 - int8u  month
            // 0x39 - int8u  day
            // 0x3a - int8u  hour
            // 0x3b - int8u  min
            // 0x3c - int16u sec * 10
            // 0x3e - int8u  unknown (seen: 2)
            // 0x3f - int32s latitude (decimal degrees * 1e7)
            // 0x43 - int32s longitude (decimal degrees * 1e7)
            // 0x47 - int8u  unknown (seen: 16)
            // 0x48-0x4f -   all zero
            for ($pos = 0x32; ; ) {
                [$spd, $trk, $yr, $mon, $day, $hr, $min, $sec, $lat, $lon] =
                    array_pad(Perl::unpack("x{$pos}nnnCCCCnx1NN", $dataPt), 10, null);
                // validate record using date/time
                if ($yr < 2000 or $yr > 2200 or
                    $mon < 1 or $mon > 12 or
                    $day < 1 or $day > 31 or
                    $hr > 59 or $min > 59 or $sec > 600) break;
                // change lat/lon to signed integer and divide by 1e7
                [$lat, $lon] = array_map(static fn ($v) => self::SignedInt32($v) / 1e7, [$lat, $lon]);
                if ($trk >= 0x8000) $trk -= 0x10000;    // make it signed
                $trk /= 100;
                if ($trk < 0) $trk += 360;
                $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%04.1fZ', $yr, $mon, $day, $hr, $min, $sec / 10);
                $et->DOC_NUM = ++$et->DOC_COUNT;
                $et->HandleTag($tagTbl, 'GPSDateTime', $time);
                $et->HandleTag($tagTbl, 'GPSLatitude', $lat);
                $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
                $et->HandleTag($tagTbl, 'GPSSpeed', $spd / 100 * $mpsToKph);
                $et->HandleTag($tagTbl, 'GPSTrack', $trk);
                // (sic: Perl parses "last if $pos += 0x20 > length($$dataPt) - 0x1e" as
                //  $pos += (0x20 > ...), so only the first record is decoded)
                if ($pos += (0x20 > strlen($dataPt) - 0x1e ? 1 : 0)) break;
            }
            $done = 1;
        }
        ExifTool::SetByteOrder($oldOrder);
        if ($done) return $et->DOC_NUM ? 1 : 0;
        if ($yr !== null and ($mon < 1 or $mon > 12)) return 0;     // quick sanity check
        //
        // save tag values extracted by above code
        //
        self::FoundSomething($et, $tagTbl, $dirInfo['SampleTime'] ?? null, $dirInfo['SampleDuration'] ?? null);
        if ($sec !== null and !preg_match('/^\d{2}/', Perl::str($sec))) $sec = '0' . Perl::str($sec); // pad integer part of seconds to 2 digits
        if ($yr !== null) {
            if ($yr < 2000) $yr += 2000;
            $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%sZ', $yr, $mon, $day, $hr, $min, $sec);
            $et->HandleTag($tagTbl, 'GPSDateTime', $time);
        } elseif ($hr !== null) {
            $time = Perl::sprintf('%.2d:%.2d:%sZ', $hr, $min, $sec);
            $et->HandleTag($tagTbl, 'GPSTimeStamp', $time);
        }
        if ($lat !== null and $lon !== null) {
            // lat/long are in DDDMM.MMMM format unless $ddd is set
            if (!$ddd) self::ConvertLatLon($lat, $lon);
            $et->HandleTag($tagTbl, 'GPSLatitude', Perl::num($lat) * (($latRef and $latRef === 'S') ? -1 : 1));
            $et->HandleTag($tagTbl, 'GPSLongitude', Perl::num($lon) * (($lonRef and $lonRef === 'W') ? -1 : 1));
        }
        if ($alt !== null) $et->HandleTag($tagTbl, 'GPSAltitude', $alt);
        if ($spd !== null) $et->HandleTag($tagTbl, 'GPSSpeed', $spd);
        if ($trk !== null) $et->HandleTag($tagTbl, 'GPSTrack', $trk);
        while ($xtra) {
            $tag = array_shift($xtra);
            $et->HandleTag($tagTbl, $tag, array_shift($xtra));
        }
        if ($acc) $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr($acc));
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract embedded information referenced from a track
    // Inputs: 0) ExifTool ref, 1) tag name, 2) data ref
    public static function ParseTag(ExifTool $et, $tag, $dataPt)
    {
        $dataLen = strlen($dataPt);

        if ($tag === 'stsz' or ($tag === 'stz2' and $dataLen > 12)) {
            // read the sample sizes
            [$sz, $num] = array_pad(Perl::unpack('x4N2', $dataPt), 2, null);
            $size = [];
            if ($tag === 'stsz') {
                if ($sz == 0) {
                    $size = ExifTool::ReadValueList($dataPt, 12, 'int32u', $num, $dataLen - 12);
                } else {
                    $size = $num > 0 ? array_fill(0, $num, $sz) : [];
                }
            } else {
                $sz &= 0xff;
                if ($sz == 4) {
                    $tmp = ExifTool::ReadValueList($dataPt, 12, 'int8u', Perl::int(($num + 1) / 2), $dataLen - 12);
                    foreach ($tmp as $v) {
                        $size[] = $v >> 4;
                        $size[] = $v & 0xff;
                    }
                } elseif ($sz == 8 || $sz == 16) {
                    $size = ExifTool::ReadValueList($dataPt, 12, "int{$sz}u", $num, $dataLen - 12);
                }
            }
            $et->ee['size'] = $size;
        } elseif ($tag === 'stco' or ($tag === 'co64' and $dataLen > 8)) {
            // read the chunk offsets
            $num = Perl::unpack('x4N', $dataPt)[0] ?? null;
            $et->ee['stco'] = ExifTool::ReadValueList($dataPt, 8, $tag === 'stco' ? 'int32u' : 'int64u', $num, $dataLen - 8);
        } elseif ($tag === 'stsc' and $dataLen > 8) {
            // read the sample-to-chunk box
            $num = Perl::unpack('x4N', $dataPt)[0];
            if ($dataLen >= 8 + $num * 12) {
                $stsc = [];
                for ($i = 0; $i < $num; ++$i) {
                    // list of (first-chunk, samples-per-chunk, sample-description-index)
                    $stsc[] = Perl::unpack('x' . (8 + $i * 12) . 'N3', $dataPt);
                }
                $et->ee['stsc'] = $stsc;
            }
        } elseif ($tag === 'stts' and $dataLen > 8) {
            // read the time-to-sample box
            $num = Perl::unpack('x4N', $dataPt)[0];
            if ($dataLen >= 8 + $num * 8) {
                $et->ee['stts'] = Perl::unpack('x8N' . ($num * 2), $dataPt);
            }
        } elseif ($tag === 'avcC') {
            // read the AVC compressor configuration
            if ($dataLen >= 7) $et->ee['avcC'] = $dataPt;   // (minimum length is 7)
        } elseif ($tag === 'JPEG') {
            $et->ee['JPEG'] = $dataPt;
        } elseif ($tag === 'gps ' and $dataLen > 8) {
            // decode Novatek 'gps ' box (ref 2)
            $num = ExifTool::Get32u($dataPt, 4);
            if ($num * 8 + 8 > $dataLen) $num = Perl::int(($dataLen - 8) / 8);
            $start = $size = [];
            for ($i = 0; $i < $num; ++$i) {
                $start[] = ExifTool::Get32u($dataPt, 8 + $i * 8);
                $size[] = ExifTool::Get32u($dataPt, 12 + $i * 8);
            }
            $et->ee['start'] = $start;
            $et->ee['size'] = $size;
            $et->HandlerType = $tag;    // fake handler type
            self::ProcessSamples($et);  // we have all we need to process sample data now
        } elseif ($tag === 'GPS ') {
            $pos = 0;
            $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
            ExifTool::SetByteOrder('II');
            while ($pos + 36 < $dataLen) {
                $dat = substr($dataPt, $pos, 36);
                if ($dat === str_repeat("\x0", 36)) break;
                $a = Perl::unpack('VVVVaVaV', $dat);
                $et->DOC_NUM = ++$et->DOC_COUNT;
                // 0=1, 1=1, 2=secs, 3=?
                self::SetGPSDateTime($et, $tagTbl, $a[2]);
                $lat = $a[5] / 1e3;
                $lon = $a[7] / 1e3;
                self::ConvertLatLon($lat, $lon);
                if ($a[4] === 'S') $lat = -abs($lat);
                if ($a[6] === 'W') $lon = -abs($lon);
                $et->HandleTag($tagTbl, 'GPSLatitude', $lat);
                $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
                $et->HandleTag($tagTbl, 'GPSSpeed', $a[3] / 1e3);
                $pos += 36;
            }
            ExifTool::SetByteOrder('MM');
            $et->DOC_NUM = null;
        }
    }

    //------------------------------------------------------------------------------
    // Process Yuneec 'tx3g' and Autel sbtl metadata (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_tx3g(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if (strlen($dataPt) < 2) return 0;
        $et->VerboseDir('tx3g', null, strlen($dataPt) - 2);
        $text = substr($dataPt, 2);     // remove 2-byte length word
        $et->HandleTag($tagTablePtr, 'Text', $text);
        if (preg_match('/^HOME\(/', $text)) {
            // --- sample text from Autel Evo II drone ---
            // HOME(W: 109.318642, N: 40.769371) 2023-09-12 10:28:07
            // GPS(W: 109.339287, N: 40.768574, 2371.76m)
            // HDR ISO:100 SHUTTER:1000 EV:-0.7 F-NUM:1.8
            // F.PRY (1.0\xc2\xb0, -3.7\xc2\xb0, -59.0\xc2\xb0), G.PRY (-51.1\xc2\xb0, 0.0\xc2\xb0, -58.9\xc2\xb0)
            foreach (Perl::split('/\x0a/', $text) as $line) {
                if (preg_match('/^HOME\(([EW]):\s*(\d+\.\d+),\s*([NS]):\s*(\d+\.\d+)\)\s*(.*)/', $line, $m)) {
                    [$lon, $lat, $time] = [$m[2], $m[4], $m[5]];
                    if ($m[1] === 'W') $lon = -$lon;
                    if ($m[3] === 'S') $lat = -$lat;
                    $time = strtr($time, '-', ':');     // (likely local time zone, but not confirmed)
                    $et->HandleTag($tagTablePtr, 'GPSDateTime', $time);
                    $et->HandleTag($tagTablePtr, 'HomeLat', $lat);
                    $et->HandleTag($tagTablePtr, 'HomeLon', $lon);
                } elseif (preg_match('/^GPS\(([EW]):\s*(\d+\.\d+),\s*([NS]):\s*(\d+\.\d+),\s*(.*)m/', $line, $m)) {
                    [$lon, $lat, $alt] = [$m[2], $m[4], $m[5]];
                    if ($m[1] === 'W') $lon = -$lon;
                    if ($m[3] === 'S') $lat = -$lat;
                    $et->HandleTag($tagTablePtr, 'Lat', $lat);
                    $et->HandleTag($tagTablePtr, 'Lon', $lon);
                    $et->HandleTag($tagTablePtr, 'Alt', $alt);
                } elseif (preg_match('/^F\.PRY\s*\((-?[\d.]+)\xc2\xb0,\s*(-?[\d.]+)\xc2\xb0,\s*(-?[\d.]+)\xc2\xb0/', $line, $m)) {
                    $et->HandleTag($tagTablePtr, 'Yaw', $m[1]);
                    $et->HandleTag($tagTablePtr, 'Pitch', $m[2]);
                    $et->HandleTag($tagTablePtr, 'Roll', $m[3]);
                    if (preg_match('/G\.PRY\s*\((-?[\d.]+)\xc2\xb0,\s*(-?[\d.]+)\xc2\xb0,\s*(-?[\d.]+)\xc2\xb0/', $line, $m)) {
                        $et->HandleTag($tagTablePtr, 'GimYaw', $m[1]);
                        $et->HandleTag($tagTablePtr, 'GimPitch', $m[2]);
                        $et->HandleTag($tagTablePtr, 'GimRoll', $m[3]);
                    }
                } else {
                    preg_match_all('/([-\w]+):([^:]*[^:\s])(\s|$)/s', $line, $mm, PREG_SET_ORDER);
                    foreach ($mm as $m) $et->HandleTag($tagTablePtr, $m[1], $m[2]);
                }
            }
        } elseif (preg_match('/^\w{3} (\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2}) ?([-+])(\d{2}):?(\d{2})$/s', $text, $m)) {
            $et->HandleTag($tagTablePtr, 'DateTime', "$m[1]:$m[2]:$m[3] $m[4]$m[5]$m[6]:$m[7]");
        } else {
            preg_match_all('/(\w+):([^:]*[^:\s])(\s|$)/s', $text, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) $et->HandleTag($tagTablePtr, $m[1], $m[2]);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process QuickTime 'mebx' timed metadata
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // - uses tag ID keys stored in the ExifTool ee data member by a previous call to SaveMetaKeys
    public static function Process_mebx(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $ee = $et->ee;
        if (!$ee) return 0;
        if (empty($ee['keys'])) return 0;
        $dataPt = $dirInfo['DataPt'];

        // parse using information from 'keys' table (eg. Apple iPhone7+ hevc 'Core Media Data Handler')
        $et->VerboseDir('mebx', null, strlen($dataPt));
        $len = null;
        for ($pos = 0; $pos + 8 < strlen($dataPt); $pos += $len) {
            $len = ExifTool::Get32u($dataPt, $pos);
            if ($len < 8 or $pos + $len > strlen($dataPt)) break;
            $id = substr($dataPt, $pos + 4, 4);
            $info = $ee['keys'][$id] ?? null;
            if ($info) {
                $tag = $info['TagID'];
                if (!$tagTbl[$tag]) {
                    if (!preg_match('/^[-\w.]+$/', $tag)) continue;
                    // create info for tags with reasonable id's
                    $name = preg_replace_callback('/[-.](.)/', static fn ($m) => strtoupper($m[1]), $tag);
                    ExifTool::AddTagToTable($tagTbl, $tag, ['Name' => ucfirst($name)]);
                }
                $val = ExifTool::ReadValue($dataPt, $pos + 8, $info['Format'], null, $len - 8);
                $et->HandleTag($tagTbl, $tag, $val,
                    DataPt: $dataPt,
                    Base: $dirInfo['Base'] ?? null,
                    Start: $pos + 8,
                    Size: $len - 8,
                );
            } else {
                $et->Warn('No key information for mebx ID ' . self::PrintableTagID($id, 1));
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process QuickTime '3gf' timed metadata (ref PH, Pittasoft Blackvue dashcam)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_3gf(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $recLen = 10;   // 10-byte record length
        $et->VerboseDir('3gf', null, $dirLen);
        if ($dirLen > $recLen and !$et->Options('ExtractEmbedded')) {
            $dirLen = $recLen;
            self::EEWarn($et);
        }
        for ($pos = 0; $pos + $recLen <= $dirLen; $pos += $recLen) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $tc = ExifTool::Get32u($dataPt, $pos);
            if ($tc == 0xffffffff) break;
            [$x, $y, $z] = [ExifTool::Get16s($dataPt, $pos + 4) / 10, ExifTool::Get16s($dataPt, $pos + 6) / 10, ExifTool::Get16s($dataPt, $pos + 8) / 10];
            $et->HandleTag($tagTbl, 'TimeCode', $tc / 1000);
            $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr([$x, $y, $z]));
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process DuDuBell M1 dashcam / VSYS M6L 'gps0' atom (ref PH)
    // (Lamax S9 dual dashcam also uses 'gps0' atom, but encrypted text format)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_gps0(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $et->VerboseDir('gps0', null, $dirLen);
        // check for encrypted format written by Lamax S9 dual dashcam
        // (similar to Ambarella A12, but in multiple 311-byte records)
        if (preg_match('/^.{2}\xf2\xe1\xf0\xeeTT\x98/s', $dataPt)) {
            $recLen = 311;
            for ($pos = 0; $pos + $recLen <= $dirLen; $pos += $recLen) {
                $dat = substr($dataPt, $pos, $recLen);
                if (!preg_match('/^.{2}\xf2\xe1\xf0\xeeTT\x98/s', $dat)) break;
                $et->DOC_NUM = ++$et->DOC_COUNT;
                self::Process_text($et, $dat, $tagTbl);
                $pos += $recLen;    // (sic: incremented twice in Perl too)
            }
            $et->DOC_NUM = null;
            return 1;
        }
        $recLen = 32;   // 32-byte record length
        ExifTool::SetByteOrder('II');
        if ($dirLen > $recLen and !$et->Options('ExtractEmbedded')) {
            $dirLen = $recLen;
            self::EEWarn($et);
        }
        for ($pos = 0; $pos + $recLen <= $dirLen; $pos += $recLen) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            // lat/long are in DDDMM.MMMM format
            $lat = ExifTool::GetDouble($dataPt, $pos);
            $lon = ExifTool::GetDouble($dataPt, $pos + 8);
            if (abs($lat) > 9000 or abs($lon) > 18000) continue;
            self::ConvertLatLon($lat, $lon);
            $a = Perl::unpack('C*', substr($dataPt, $pos + 22, 6));    // unpack date/time
            $a[0] += 2000;
            $et->HandleTag($tagTbl, 'GPSDateTime', Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', ...$a));
            $et->HandleTag($tagTbl, 'GPSLatitude', $lat);
            $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
            $et->HandleTag($tagTbl, 'GPSSpeed', ExifTool::Get16u($dataPt, $pos + 0x14));
            $et->HandleTag($tagTbl, 'GPSTrack', ExifTool::Get8u($dataPt, $pos + 0x1c) * 2);     // (NC)
            $et->HandleTag($tagTbl, 'GPSAltitude', ExifTool::Get32s($dataPt, $pos + 0x10));
            // yet to be decoded:
            // 0x1d - int8u[3] seen: "1 1 0"
        }
        $et->DOC_NUM = null;
        ExifTool::SetByteOrder('MM');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process DuDuBell M1 dashcam / VSYS M6L 'gsen' atom (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_gsen(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $recLen = 3;    // 3-byte record length
        $et->VerboseDir('gsen', null, $dirLen);
        if ($dirLen > $recLen and !$et->Options('ExtractEmbedded')) {
            $dirLen = $recLen;
            self::EEWarn($et);
        }
        for ($pos = 0; $pos + $recLen <= $dirLen; $pos += $recLen) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $acc = array_map(static fn ($v) => $v / 16, Perl::unpack("x{$pos}c3", $dataPt));
            $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr($acc));
            // (there are no associated timestamps, but these are sampled at 5 Hz in my test video)
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process 'gdat' atom Base64-encoded JSON-format timed GPS used by Nextbase software (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_gdat(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        if (!$et->OPTIONS['ExtractEmbedded']) {
            $et->Warn('Use the ExtractEmbedded option to extract timed GPSData', 3);
            return 0;
        }
        $dataPt = $dirInfo['DataPt'];
        $dataPt = XMP::DecodeBase64($dataPt);
        $dbase = [];
        Import::ReadJSON($dataPt, $dbase);
        $info = $dbase['*'] ?? null;
        if (!$info) return 0;
        if (!empty($info['cameraModel'])) $et->HandleTag($tagTbl, 'CameraModel', $info['cameraModel']);
        $gps = $info['gpsData'] ?? null;
        if (!$gps) return 0;
        if (!(is_array($gps) and array_is_list($gps))) return 0;
        foreach ($gps as $fix) {
            if (!(is_array($fix) and !array_is_list($fix) and !empty($fix['gpsStatus']) and $fix['gpsStatus'] === 'A')) continue;
            $et->DOC_NUM = ++$et->DOC_COUNT;
            if (!empty($fix['datetime'])) {
                $fix['datetime'] = strtr(Perl::str($fix['datetime']), '-T', ': ');
                $et->HandleTag($tagTbl, 'GPSDateTime', $fix['datetime']);
            }
            if (isset($fix['lat'])) $et->HandleTag($tagTbl, 'GPSLatitude', $fix['lat']);
            if (isset($fix['lon'])) $et->HandleTag($tagTbl, 'GPSLongitude', $fix['lon']);
            if (isset($fix['speed'])) $et->HandleTag($tagTbl, 'GPSSpeed', Perl::num($fix['speed']) * Vars\QuickTime::$mphToKph);
            if (isset($fix['bearing'])) $et->HandleTag($tagTbl, 'GPSTrack', $fix['bearing']);
            if (isset($fix['xAcc']) and isset($fix['yAcc']) and isset($fix['zAcc'])) {
                $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr([$fix['xAcc'], $fix['yAcc'], $fix['zAcc']]));
            }
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract GPS from Nextbase 'nbmt' atom
    // Inputs: 0) ExifTool ref, 1) data ref or dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_nbmt(ExifTool $et, $dataPt, TagTable $tagTbl)
    {
        if ($et->OPTIONS['ExtractEmbedded']) {
            $et->DOC_NUM = $et->DOC_COUNT + 1;
            $et->UnknownTextCount = null;
            $et->NoMoreTextDecoding = null;
            $et->SET_GROUP1 = 'Nextbase';
            self::Process_text($et, $dataPt, $tagTbl, 1);
            $et->SET_GROUP1 = null;
            $et->UnknownTextCount = null;
            $et->NoMoreTextDecoding = null;
            $et->DOC_NUM = null;
        } else {
            $et->Warn('Use the ExtractEmbedded option to extract timed GPSData', 3);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Kenwood drv-a301w dashcam 'udta' atom (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Sample data:
    // 0000: 56 49 44 45 4f 55 55 55 55 55 55 55 55 55 55 55 [VIDEOUUUUUUUUUUU]
    // 0010: 55 55 55 55 55 55 55 55 55 55 55 fe fe 32 30 32 [UUUUUUUUUUU..202]
    // 0020: 33 30 31 30 37 31 31 31 39 31 34 2e 32 30 32 33 [30107111914.2023]
    // 0030: 30 31 30 37 31 31 31 39 31 35 03 4e 34 37 33 37 [0107111915.N4737]
    // 0040: 37 30 35 33 57 31 32 32 30 39 39 30 31 34 2b 30 [7053W122099014+0]
    // 0050: 30 35 38 30 30 30 2b 30 30 36 2b 30 30 39 2b 30 [058000+006+009+0]
    // 0060: 30 34 2b 30 30 32 2b 30 30 39 2b 30 30 35 2b 30 [04+002+009+005+0]
    public static function ProcessKenwood(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        preg_match_all('/\xfe\xfe([^\xfe]+)/', $dataPt, $matches);
        foreach ($matches[1] as $dat) {
            // (PHP: $p is pos($dat) for the \G matches)
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})./s', $dat, $m)) continue;
            $p = strlen($m[0]);
            $time = "$m[1]:$m[2]:$m[3] $m[4]:$m[5]:$m[6]";  // (likely local time zone, but not confirmed)
            // ignore second date (what is this for?)
            if (!preg_match('/\G(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})./s', $dat, $m, 0, $p)) continue;
            $p += strlen($m[0]);
            if (!preg_match('/\G([NS])(\d+)([EW])(\d+)/', $dat, $m, 0, $p)) continue;
            $p += strlen($m[0]);
            [$lat, $lon] = [$m[2] / 1e4, $m[4] / 1e4];
            self::ConvertLatLon($lat, $lon);
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $et->HandleTag($tagTbl, 'GPSDateTime', $time);
            $et->HandleTag($tagTbl, 'GPSLatitude', $lat * ($m[1] === 'S' ? -1 : 1));
            $et->HandleTag($tagTbl, 'GPSLongitude', $lon * ($m[3] === 'W' ? -1 : 1));
            if (!preg_match('/\G([-+]\d{4})(\d+)/', $dat, $m, 0, $p)) continue;
            $p += strlen($m[0]);
            $et->HandleTag($tagTbl, 'GPSAltitude', Perl::num($m[1])); // (NC, educated guess)
            $et->HandleTag($tagTbl, 'GPSSpeed', $m[2]);     // (km/h)
            $acc = [];
            while (preg_match('/\G([-+]\d+)([-+]\d+)([-+]\d+)/', $dat, $m, 0, $p)) {
                $p += strlen($m[0]);
                array_push($acc, $m[1] / 1000, $m[2] / 1000, $m[3] / 1000);
            }
            if ($acc) $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr($acc));
            if (!$et->Options('ExtractEmbedded')) {
                $et->Warn('Use the ExtractEmbedded option to extract all timed GPS', 3);
                break;
            }
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process RIFF-format trailer written by Auto-Vox dashcam (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Note: This trailer is basically RIFF chunks added to a QuickTime-format file (augh!),
    //       but there are differences in the record formats so we can't just call
    //       ProcessRIFF to process the gps0 and gsen atoms using the routines above
    public static function ProcessRIFFTrailer(ExifTool $et, array &$dirInfo, ?TagTable $tagTbl)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $pos = null;
        ExifTool::SetByteOrder('II');
        for (;;) {
            if ($raf->Read($buff, 8) != 8) break;
            [$tag, $len] = Perl::unpack('a4V', $buff);
            if ($tag === "\0\0\0\0") break;
            if (!(preg_match('/^[\w ]{4}/', $tag) and $len < 0x2000000)) {
                $et->Warn('Bad RIFF trailer');
                break;
            }
            if ($raf->Read($buff, $len) != $len) {
                $et->Warn("Truncated $tag record in RIFF trailer");
                break;
            }
            if ($verbose) {
                $et->VPrint(0, "  - RIFF trailer '{$tag}' ($len bytes)\n");
                if ($verbose > 2) $et->VerboseDump($buff, Addr: $raf->Tell() - $len);
                $et->INDENT .= '| ';
                if (preg_match('/^(gps0|gsen)$/', $tag)) $et->VerboseDir($tag, null, $len);
            }
            if ($tag === 'gps0') {
                // (similar to record decoded in Process_gps0, but with some differences)
                // 0000: 41 49 54 47 74 46 94 f6 c6 c5 b4 40 34 a2 b4 37 [AITGtF.....@4..7]
                // 0010: f8 7b 8a 40 ff ff 00 00 38 00 77 0a 1a 0c 12 28 [.{.@....8.w....(]
                // 0020: 8d 01 02 40 29 07 00 00                         [...@)...]
                // 0x00 - undef[4] 'AITG'
                // 0x04 - double   latitude  (always positive)
                // 0x0c - double   longitude (always positive)
                // 0x14 - ?        seen hex "ff ff 00 00" (altitude in Process_gps0 record below)
                // 0x18 - int16u   speed in knots (different than km/hr in Process_gps0)
                // 0x1a - int8u[6] yr-1900,mon,day,hr,min,sec (different than -2000 in Process_gps0)
                // 0x20 - int8u    direction in degrees / 2
                // 0x21 - int8u    guessing that this is 1=N, 2=S - PH
                // 0x22 - int8u    guessing that this is 1=E, 2=W - PH
                // 0x23 - ?        seen hex "40"
                // 0x24 - in32u    time since start of video (ms)
                $recLen = 0x28;
                for ($pos = 0; $pos + $recLen < $len; $pos += $recLen) {
                    if (substr($buff, $pos, 4) !== 'AITG') {
                        $et->Warn('Unrecognized gps0 record');
                        break;
                    }
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    // lat/long are in DDDMM.MMMM format
                    $lat = ExifTool::GetDouble($buff, $pos + 4);
                    $lon = ExifTool::GetDouble($buff, $pos + 12);
                    if ((abs($lat) > 9000 or abs($lon) > 18000) and $et->Warn('Bad gps0 record')) break;
                    self::ConvertLatLon($lat, $lon);
                    if (ExifTool::Get8u($buff, $pos + 0x21) == 2) $lat = -$lat;     // wild guess
                    if (ExifTool::Get8u($buff, $pos + 0x22) == 2) $lon = -$lon;     // wild guess
                    $a = Perl::unpack('C*', substr($buff, $pos + 26, 6));  // unpack date/time
                    $a[0] += 1900;  // (different than Proces_gps0)
                    $et->HandleTag($tagTbl, 'SampleTime', ExifTool::Get32u($buff, $pos + 0x24) / 1000);
                    $et->HandleTag($tagTbl, 'GPSDateTime', Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', ...$a));
                    $et->HandleTag($tagTbl, 'GPSLatitude', $lat);
                    $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
                    $et->HandleTag($tagTbl, 'GPSSpeed', ExifTool::Get16u($buff, $pos + 0x18) * Vars\QuickTime::$knotsToKph);
                    $et->HandleTag($tagTbl, 'GPSTrack', ExifTool::Get8u($buff, $pos + 0x20) * 2);
                }
            } elseif ($tag === 'gsen') {
                // (similar to record decoded in Process_gsen)
                // 0000: 41 49 54 53 1a 0d 05 ff c8 00 00 00 [AITS........]
                // 0x00 - undef[4] 'AITS'
                // 0x04 - int8s[3] accelerometer readings
                // 0x07 - ?        seen hex "ff"
                // 0x08 - in32u    time since start of video (ms)
                $recLen = 0x0c;
                for ($pos = 0; $pos + $recLen < $len; $pos += $recLen) {
                    if (substr($buff, $pos, 4) !== 'AITS') {
                        $et->Warn('Unrecognized gsen record');
                        break;
                    }
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    $acc = array_map(static fn ($v) => $v / 24, Perl::unpack('x' . ($pos + 4) . 'c3', $buff));
                    $et->HandleTag($tagTbl, 'SampleTime', ExifTool::Get32u($buff, $pos + 8) / 1000);
                    // 0=+Up, 1=+Right, 3=+Forward (calibration of 24 counts/g is a wild guess - PH)
                    $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr($acc));
                }
            }
            // also seen, but not decoded:
            // gpsa (8 bytes): hex "01 20 00 00 08 03 02 08 "
            // gsea (20 bytes): all zeros
            if ($verbose) $et->INDENT = substr($et->INDENT, 0, -2);
        }
        $et->DOC_NUM = null;
        ExifTool::SetByteOrder('MM');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Kenwood Dashcam trailer (forum16229)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Sample data (chained 512-byte records starting like this):
    // 0000:  43 43 43 43 43 43 43 43 43 43 43 43 43 43 47 50 [CCCCCCCCCCCCCCGP]
    // 0010:  53 44 41 54 41 2d 2d 32 30 32 34 30 37 31 31 31 [SDATA--202407111]
    // 0020:  32 30 34 31 32 4e 35 30 2e 36 31 32 33 38 36 30 [20412N50.6123860]
    // 0030:  36 37 37 45 38 2e 37 30 32 37 31 38 30 39 38 39 [677E8.7027180989]
    // 0040:  35 33 33 2e 30 30 30 30 30 30 30 30 30 30 30 30 [533.000000000000]
    // 0050:  2e 30 30 30 30 30 30 30 30 30 30 30 30 30 2e 30 [.0000000000000.0]
    // 0060:  31 39 39 39 39 39 39 39 35 35 33 2d 30 2e 30 39 [19999999553-0.09]
    // 0070:  30 30 30 30 30 30 33 35 37 2d 30 2e 31 34 30 30 [000000357-0.1400]
    // 0080:  30 30 30 30 30 35 39 47 50 53 44 41 54 41 2d 2d [0000059GPSDATA--]
    public static function ProcessKenwoodTrailer(ExifTool $et, array &$dirInfo, ?TagTable $tagTbl)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        // current file position is 8 bytes into the 14 C's, so test the next 6:
        if (!($raf->Read($buff, 14) and $buff === 'CCCCCCCCCCCCCC')) return 0;
        $et->VerboseDir('Kenwood trailer', null, null);
        if (!$et->OPTIONS['ExtractEmbedded']) {
            $et->Warn('Use the ExtractEmbedded option to extract timed GPSData from Kenwood trailer', 3);
            return 1;
        }
        while ($raf->Read($buff, 121) and preg_match('/^GPSDATA--(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $buff, $m)) {
            self::FoundSomething($et, $tagTbl);
            $et->HandleTag($tagTbl, 'GPSDateTime', "$m[1]:$m[2]:$m[3] $m[4]:$m[5]:$m[6]");
            $i = 9 + 14;
            $acc = [];
            foreach (['GPSLatitude', 'GPSLongitude', 'GPSSpeed', 'unk', 'acc', 'acc', 'acc'] as $tag) {
                $val = substr($buff, $i, 14);
                $i += 14;
                if ($tag === 'unk') continue;
                $hemi = null;
                if (preg_match('/^([NSEW])/', $val, $mm)) {
                    $hemi = $mm[1];
                    $val = substr($val, 1);
                }
                if (!preg_match('/^[-+]?\d+\.\d+$/', $val)) continue;
                if ($tag === 'acc') {
                    $acc[] = $val;
                    continue;
                }
                if ($hemi and ($hemi === 'S' or $hemi === 'W')) $val = -$val;
                $et->HandleTag($tagTbl, $tag, $val);
            }
            if (count($acc) == 3) $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr($acc));
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process 'gps ' atom containing NMEA from Pittasoft Blackvue dashcam (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNMEA(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $rtnVal = null;
        $fix = [];
        $knotsToKph = Vars\QuickTime::$knotsToKph;
        $p = 0;     // (pos($$dataPt))
        // parse only RMC and GGA sentence [with leading timecode] for now
        for (;;) {
            $tc = $type = $tim = null;
            if (preg_match('/(?:\[(\d+)\])?\$[A-Z]{2}(RMC|GGA),(\d{2}\d{2}\d+(\.\d*)?),/', $dataPt, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $p)) {
                [$tc, $type, $tim] = [$m[1][0], $m[2][0], $m[3][0]];
                $p = $m[0][1] + strlen($m[0][0]);
            } else {
                $p = 0;
            }
            // write out last fix now if complete
            // (use the GPS timestamps because they may be different for the same timecode)
            if (!empty($fix['tim']) and (!$tim or Perl::num($fix['tim']) != Perl::num($tim))) {
                if (!empty($fix['dat']) and isset($fix['lat']) and isset($fix['lon'])) {
                    $sampleTime = null;
                    if (!empty($fix['tc']) and $et->StartTime) $sampleTime = ($fix['tc'] - $et->StartTime) / 1000;
                    self::FoundSomething($et, $tagTbl, $sampleTime);
                    $et->HandleTag($tagTbl, 'GPSDateTime', $fix['dat']);
                    $et->HandleTag($tagTbl, 'GPSLatitude', $fix['lat']);
                    $et->HandleTag($tagTbl, 'GPSLongitude', $fix['lon']);
                    if (isset($fix['spd'])) $et->HandleTag($tagTbl, 'GPSSpeed', Perl::num($fix['spd']) * $knotsToKph);
                    if (isset($fix['trk'])) $et->HandleTag($tagTbl, 'GPSTrack', $fix['trk']);
                    if (isset($fix['alt'])) $et->HandleTag($tagTbl, 'GPSAltitude', $fix['alt']);
                    if (isset($fix['nsats'])) $et->HandleTag($tagTbl, 'GPSSatellites', Perl::num($fix['nsats']));
                    if (isset($fix['hdop'])) $et->HandleTag($tagTbl, 'GPSDOP', $fix['hdop']);
                }
                $fix = [];
            }
            $fix['tim'] = $tim;
            if (!$tim) break;
            $pos = $p;
            $p = $pos - strlen($tim) - 1;   // rewind to re-parse time
            // (parsing of NMEA strings copied from Geotag.pm)
            if ($type === 'RMC' and
                preg_match('/\G(\d{2})(\d{2})(\d+(\.\d*)?),A?,(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/', $dataPt, $m, 0, $p))
            {
                $p += strlen($m[0]);
                $year = $m[15] + ($m[15] >= 70 ? 1900 : 2000);
                $fix['tc'] = $tc;   // use timecode of RMC sentence
                $fix['dat'] = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%sZ', $year, $m[14], $m[13], $m[1], $m[2], $m[3]);
                $fix['lat'] = ((Perl::num($m[5]) ?: 0) + $m[6] / 60) * ($m[7] === 'N' ? 1 : -1);
                $fix['lon'] = ((Perl::num($m[8]) ?: 0) + $m[9] / 60) * ($m[10] === 'E' ? 1 : -1);
                if (strlen($m[11])) $fix['spd'] = $m[11];
                if (strlen($m[12])) $fix['trk'] = $m[12];
            } elseif ($type === 'GGA' and
                preg_match('/\G(\d{2})(\d{2})(\d+(\.\d*)?),(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),[1-6]?,(\d+)?,(\.\d+|\d+\.?\d*)?,(-?\d+\.?\d*)?,M?/', $dataPt, $m, PREG_UNMATCHED_AS_NULL, $p))
            {
                $p += strlen($m[0]);
                $fix['lat'] = ((Perl::num($m[5]) ?: 0) + $m[6] / 60) * ($m[7] === 'N' ? 1 : -1);
                $fix['lon'] = ((Perl::num($m[8]) ?: 0) + $m[9] / 60) * ($m[10] === 'E' ? 1 : -1);
                [$fix['nsats'], $fix['hdop'], $fix['alt']] = [$m[11], $m[12], $m[13]];
            } else {
                $p = $pos;  // continue searching from our last match
            }
        }
        $et->DOC_NUM = null;
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process 'gps ' or 'udat' atom possibly containing NMEA (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessGPSLog(ExifTool $et, array &$dirInfo, ?TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $rtnVal = null;

        // try NMEA format first
        if (self::ProcessNMEA($et, $dirInfo, $tagTbl)) return 1;

        // DENVER ACG-8050WMK2 format looks like this:
        // 210318073213[1][N][52200970][E][006362321][+00152][100][00140][C000000]+000+000+000+000+000+000+000+000+000+000+000+000+000+000+000+000+000+000
        // YYMMDDHHMMSS A? NS lat       EW lon         alt    kph  dir    kCal    accel
        preg_match_all('/\b(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\[1\]\[([NS])\]\[(\d{8})\]\[([EW])\]\[(\d{9})\]\[([-+]?\d*)\]\[(\d*)\]\[(\d*)\]\[C?(\d*)\](([-+]\d{3})+)/', $dataPt, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $lat = substr($m[8], 0, 2) + substr($m[8], 2) / 600000;
            $lon = substr($m[10], 0, 3) + substr($m[10], 3) / 600000;
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $et->HandleTag($tagTbl, 'GPSDateTime', "20$m[1]:$m[2]:$m[3] $m[4]:$m[5]:{$m[6]}Z");
            $et->HandleTag($tagTbl, 'GPSLatitude', $lat * ($m[7] === 'S' ? -1 : 1));
            $et->HandleTag($tagTbl, 'GPSLongitude', $lon * ($m[9] === 'W' ? -1 : 1));
            if (strlen($m[11])) $et->HandleTag($tagTbl, 'GPSAltitude', Perl::num($m[11]) / 10);
            if (strlen($m[12])) $et->HandleTag($tagTbl, 'GPSSpeed', Perl::num($m[12]));
            if (strlen($m[13])) $et->HandleTag($tagTbl, 'GPSTrack', Perl::num($m[13]));
            if (strlen($m[14])) $et->HandleTag($tagTbl, 'KiloCalories', $m[14] / 10);
            if (strlen($m[15])) $et->HandleTag($tagTbl, 'Accelerometer', $m[15]);
            $rtnVal = 1;
        }
        $et->DOC_NUM = null;
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process TomTom Bandit Action Cam TTAD atom (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessTTAD(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $pos = 76;
        $ttLen = Vars\QuickTime::$ttLen;    // lengths of known TomTom records

        if ($dirLen < $pos) return 0;

        $et->VerboseDir('TTAD', null, $dirLen);
        ExifTool::SetByteOrder('II');

        $eeOpt = $et->Options('ExtractEmbedded');
        $unknown = $et->Options('Unknown');
        $found = 0;
        $sampleTime = 0;
        $resync = 1;
        $skipped = 0;
        $warned = null;

        while ($pos < $dirLen) {
            // get next record type
            $type = ExifTool::Get8u($dataPt, $pos++);
            // resync if necessary by skipping data until next timecode record
            if ($resync and $type != 0xff) {
                if (++$skipped > 0x100) {
                    $et->Warn('Unrecognized or bad TTAD data', 1);
                    break;
                }
                continue;
            }
            if (empty($ttLen[$type])) {
                // skip unknown records
                if (!$warned) $et->Warn("Unknown TTAD record type $type", 1);
                $resync = $warned = 1;
                ++$skipped;
                continue;
            }
            if ($pos + $ttLen[$type] > $dirLen) break;
            if ($type == 0xff) {    // timecode?
                $tm = ExifTool::Get32u($dataPt, $pos);
                // validate timecode if skipping unknown data
                if ($resync) {
                    if ($tm < $sampleTime or $tm > $sampleTime + 250) {
                        ++$skipped;
                        continue;
                    }
                    $resync = null;
                    $skipped = 0;
                }
                $pos += $ttLen[$type];
                $sampleTime = $tm;
                continue;
            }
            if (!$eeOpt) {
                // only extract one of each type without -ee option
                if ($found & (1 << $type)) {
                    $pos += $ttLen[$type];
                    continue;
                }
                $found |= (1 << $type);
            }
            if ($type == 0 or $type == 3) {
                // (these are both just educated guesses - PH)
                self::FoundSomething($et, $tagTbl, $sampleTime / 1000);
                $a = array_map(static fn ($k) => ExifTool::Get32s($dataPt, $pos + 4 * $k) / 1000, [0, 1, 2]);
                $et->HandleTag($tagTbl, $type ? 'Accelerometer' : 'AngularVelocity', self::ListStr($a));
            } elseif ($type == 5) {
                // example records unpacked with 'dVddddVddddv*'
                // datetime                 ? spd  ele    lat        lon       ? trk   ?     ?      ?      ? ? ? ?     ? ?
                // 2019:03:05 07:52:58.999Z 3 0.02 242    48.0254203 7.8497567 0 45.69 13.34 17.218 17.218 0 0 0 32760 5 0
                // 2019:03:05 07:52:59.999Z 3 0.14 242    48.0254203 7.8497567 0 45.7  12.96 15.662 15.662 0 0 0 32760 5 0
                // 2019:03:05 07:53:00.999Z 3 0.67 243.78 48.0254584 7.8497907 0 50.93  9.16 10.84  10.84  0 0 0 32760 5 0
                // (I think "5" may be the number of satellites.  seen: 5,6,7 - PH)
                self::FoundSomething($et, $tagTbl, $sampleTime / 1000);
                $t = ExifTool::GetDouble($dataPt, $pos);
                $et->HandleTag($tagTbl, 'GPSDateTime', ExifTool::ConvertUnixTime($t, null, 3) . 'Z');
                $et->HandleTag($tagTbl, 'GPSLatitude', ExifTool::GetDouble($dataPt, $pos + 0x1c));
                $et->HandleTag($tagTbl, 'GPSLongitude', ExifTool::GetDouble($dataPt, $pos + 0x24));
                $et->HandleTag($tagTbl, 'GPSAltitude', ExifTool::GetDouble($dataPt, $pos + 0x14));
                $et->HandleTag($tagTbl, 'GPSSpeed', ExifTool::GetDouble($dataPt, $pos + 0x0c) * Vars\QuickTime::$mpsToKph);
                $et->HandleTag($tagTbl, 'GPSTrack', ExifTool::GetDouble($dataPt, $pos + 0x30));
                if ($unknown) {
                    $a = array_map(static fn ($k) => ExifTool::GetDouble($dataPt, $pos + 0x38 + 8 * $k), [0, 1, 2]);
                    $et->HandleTag($tagTbl, 'Unknown03', self::ListStr($a));
                }
            } elseif ($type < 3) {
                // as yet unknown:
                // 1 - int32s[1]? (values around 98k)
                // 2 - int32s[3] (values like "806 8124 4323" -- probably something * 1000 again)
                if ($unknown) {
                    self::FoundSomething($et, $tagTbl, $sampleTime / 1000);
                    $n = $type == 1 ? 0 : 2;
                    $a = array_map(static fn ($k) => ExifTool::Get32s($dataPt, $pos + 4 * $k), range(0, $n));
                    $et->HandleTag($tagTbl, "Unknown0$type", self::ListStr($a));
                }
            } else {
                $et->Warn("Unknown TTAD record type $type", 1);
            }
            // without -ee, stop after we find types 0,3,5 (ie. bitmask 0x29)
            if (!($eeOpt or ($found & 0x29) != 0x29)) {
                self::EEWarn($et);
                break;
            }
            $pos += $ttLen[$type];
        }
        ExifTool::SetByteOrder('MM');
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from Insta360 trailer (INSV, INSP and MP4 files) or 'inst' box (ref PH)
    // Inputs: 0) ExifTool ref, 1) Optional dirInfo ref for returning trailer info
    // (dirInfo has Offset from end of trailer to end of file or DirEnd absolute end of trailer)
    // Returns: true on success
    // Notes: There looks to be some useful information by telemetry-parser, but
    //        the code is cryptic:  https://github.com/AdrianEddy/telemetry-parser
    public static function ProcessInsta360(ExifTool $et, ?array &$dirInfo = null)
    {
        $raf = $et->RAF;
        $offset = $dirInfo ? (($dirInfo['Offset'] ?? null) ?: 0) : 0;
        $buff = $dirTable = $dirTablePos = null;
        $mpsToKph = Vars\QuickTime::$mpsToKph;

        if ($dirInfo and !empty($dirInfo['DirEnd'])) {
            $raf->Seek(0, 2);
            $offset = $raf->Tell() - $dirInfo['DirEnd'];
        }
        if (!($raf->Seek(-78 - $offset, 2) and $raf->Read($buff, 78) == 78 and
            substr($buff, -32) === '8db42d694ccc418790edff439fe026bf')) return 0;   // check magic number

        $verbose = $et->Options('Verbose');
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
        $trailEnd = $raf->Tell();
        $trailerLen = Perl::unpack('x38V', $buff)[0];
        if ($trailerLen > $trailEnd) {
            $et->Warn('Bad Insta360 trailer size');
            return 0;
        }
        if ($dirInfo) {
            $dirInfo['DirLen'] = $trailerLen;
            $dirInfo['DataPos'] = $trailEnd - $trailerLen;
            if ($dirInfo['OutFile'] ?? null) {
                if ($et->DEL_GROUP['Insta360'] ?? null) {
                    ++$et->CHANGED;
                    return 1;
                // just copy the trailer when writing
                } elseif ($trailerLen > $trailEnd or !$raf->Seek($dirInfo['DataPos'], 0) or
                         $raf->Read($dirInfo['OutFile']->value, $trailerLen) != $trailerLen)
                {
                    return 0;
                } else {
                    return 1;
                }
            }
            if ($verbose or $et->HTML_DUMP) $et->DumpTrailer($dirInfo);
        }
        if (!$et->Options('ExtractEmbedded')) {
            // can arrive here when reading Insta360 trailer on JPEG image (INSP file)
            $et->Warn('Use ExtractEmbedded option to extract timed metadata from Insta360 trailer', 3);
            return 1;
        }

        $unknown = $et->Options('Unknown');
        // position relative to end of trailer (avoids using large offsets for files > 2 GB)
        $epos = -78;
        $i = $p = null;
        $et->SET_GROUP0 = 'Trailer';
        $et->SET_GROUP1 = 'Insta360';
        ExifTool::SetByteOrder('II');
        // loop through all records in the trailer, from last to first
        for (;;) {
            [$id, $len] = array_pad(Perl::unpack('vV', $buff), 2, null);
            if (($epos -= $len) + $trailerLen < 0) break;
            if (!$raf->Seek($epos - $offset, 2)) break;
            if ($verbose) {
                $et->VPrint(0, Perl::sprintf("Insta360 Record 0x%x (offset 0x%x, %d bytes):\n", $id, $trailEnd + $epos, $len));
            }
            // there are 2 types of record 0x300:
            // 1. 56 byte records
            // 0000: 4a f7 02 00 00 00 00 00 00 00 00 00 00 1e e7 3f [J..............?]
            // 0010: 00 00 00 00 00 b2 ef bf 00 00 00 00 00 70 c1 bf [.............p..]
            // 0020: 00 00 00 e0 91 5c 8c bf 00 00 00 20 8f ff 87 bf [.....\..... ....]
            // 0030: 00 00 00 00 88 7f c9 bf
            // 2. 20 byte records
            // 0000: c1 d8 d9 0b 00 00 00 00 f5 83 14 80 df 7f fe 7f [................]
            // 0010: fe 7f 01 80
            $dlen = Vars\QuickTime::$insvDataLen[$id] ?? null;
            if ($dlen !== null and !$dlen) {
                if ($id == 0x300) {
                    if ($len % 20 and !($len % 56)) {
                        $dlen = 56;
                    } elseif ($len % 56 and !($len % 20)) {
                        $dlen = 20;
                    } else {
                        if ($raf->Read($buff, 20) == 20) {
                            if (substr($buff, 16, 3) === "\0\0\0") {
                                $dlen = 56;
                            } else {
                                $dlen = 20;
                            }
                        }
                        if (!$raf->Seek($epos - $offset, 2)) break;
                    }
                } elseif ($id == 0x200) {
                    $dlen = $len;
                }
            }
            // limit the number of records we read if necessary
            $limit = Vars\QuickTime::$insvLimit[$id] ?? null;
            if ($dlen and $limit and $len > $limit[1] * $dlen and
                $et->Warn("Insta360 $limit[0] data is huge. Processing only the first $limit[1] records", 2))
            {
                $len = $limit[1] * $dlen;
            }
            if ($raf->Read($buff, $len) != $len) break;
            if ($verbose > 2) $et->VerboseDump($buff);
            if ($dlen) {
                if ($len % $dlen and $id != 0x700) { // (have seen one 0x700 record which was expected format but not multiple of 53 bytes)
                    $et->Warn(Perl::sprintf('Unexpected Insta360 record 0x%x length', $id));
                } elseif ($id == 0x200) {
                    // there are 4 types of record 0x200
                    // 1. JPEG preview (starts with ff d8 ff e1)
                    // 2. TIFF preview (starts with 01 00 00 00, then record length)
                    // 3. Unknown 1 (starts with 00 00 00 01)
                    // 4. Unknown 2 (starts with 00 00 01 34)
                    if (preg_match('/^\xff\xd8\xff/', $buff)) {
                        $et->FoundTag('PreviewImage', $buff);
                    } elseif (preg_match('/^\x01\0\0\0(.{4})\x01/s', $buff, $m) and Perl::unpack('V', $m[1])[0] == $dlen) {
                        [$w, $h] = Perl::unpack('x16V2', $buff);
                        // build the TIFF image (could the 1 at byte 9 be the SamplesPerPixel?)
                        $hdr = ExifTool::MakeTiffHeader($w, $h, 1, 8);
                        $et->FoundTag('PreviewTIFF', $hdr . substr($buff, 40));
                    }
                } elseif ($id == 0x300) {
                    for ($p = 0; $p < $len; $p += $dlen) {
                        $et->DOC_NUM = ++$et->DOC_COUNT;
                        if ($dlen == 56) {
                            $a = array_map(static fn ($k) => ExifTool::GetDouble($buff, $p + 8 * $k), range(1, 6));
                        } else {
                            $a = array_map(static fn ($v) => ($v - 0x8000) / 1000, Perl::unpack("x{$p}x8v6", $buff));
                        }
                        $et->HandleTag($tagTbl, 'TimeCode', Perl::sprintf('%.3f', ExifTool::Get64u($buff, $p) / 1000));
                        $et->HandleTag($tagTbl, 'Accelerometer', self::ListStr(array_slice($a, 0, 3)));    // (NC)
                        $et->HandleTag($tagTbl, 'AngularVelocity', self::ListStr(array_slice($a, 3, 3)));  // (NC)
                    }
                } elseif ($id == 0x400) {
                    for ($p = 0; $p < $len; $p += $dlen) {
                        $et->DOC_NUM = ++$et->DOC_COUNT;
                        $et->HandleTag($tagTbl, 'TimeCode', Perl::sprintf('%.3f', ExifTool::Get64u($buff, $p) / 1000));
                        $et->HandleTag($tagTbl, 'ExposureTime', ExifTool::GetDouble($buff, $p + 8)); //6
                    }
                } elseif ($id == 0x600) { //6
                    for ($p = 0; $p < $len; $p += $dlen) {
                        $et->DOC_NUM = ++$et->DOC_COUNT;
                        $et->HandleTag($tagTbl, 'VideoTimeStamp', Perl::sprintf('%.3f', ExifTool::Get64u($buff, $p) / 1000));
                    }
                } elseif ($id == 0x700) {
                    for ($p = 0; $p + $dlen <= $len; $p += $dlen) {
                        $tmp = substr($buff, $p, $dlen);
                        $a = Perl::unpack('VVvaa8aa8aa8a8a8', $tmp);
                        if (!(($a[5] === 'N' or $a[5] === 'S') and // (quick validation)
                              ($a[7] === 'E' or $a[7] === 'W' or
                               // (odd, but I've seen "O" instead of "W".  Perhaps
                               //  when the language is french? ie. "Ouest"?)
                               $a[7] === 'O')))
                        {
                            if ($a[3] === 'V') continue;    // void fixes don't have N/S E/W
                            $et->Warn('Unrecognized INSV GPS format');
                            break;
                        }
                        if ($a[3] !== 'A') continue;    // (ignore void fixes)
                        $et->DOC_NUM = ++$et->DOC_COUNT;
                        foreach ([4, 6, 8, 9, 10] as $k) $a[$k] = ExifTool::GetDouble($a[$k], 0);
                        if ($a[5] === 'S') $a[4] = -abs($a[4]);     // (abs just in case it was already signed)
                        if ($a[7] !== 'E') $a[6] = -abs($a[6]);
                        $ms = '';
                        if ($a[2]) $ms = preg_replace('/0+$/', '', Perl::sprintf('.%.3d', $a[2]), 1);
                        $et->HandleTag($tagTbl, 'GPSDateTime', ExifTool::ConvertUnixTime($a[0]) . $ms . 'Z');
                        $et->HandleTag($tagTbl, 'GPSLatitude', $a[4]);
                        $et->HandleTag($tagTbl, 'GPSLongitude', $a[6]);
                        $et->HandleTag($tagTbl, 'GPSSpeed', $a[8] * $mpsToKph);
                        $et->HandleTag($tagTbl, 'GPSTrack', $a[9]);
                        $et->HandleTag($tagTbl, 'GPSAltitude', $a[10]);
                        if ($unknown) $et->HandleTag($tagTbl, 'Unknown02', $a[1]);
                    }
                }
            } elseif ($id == 0x101) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::QuickTime::INSV_MakerNotes');
                for ($i = 0, $p = 0; $i < 4; ++$i) {
                    if ($p + 2 > $len) break;
                    [$t, $n] = Perl::unpack("x{$p}CC", $buff);
                    if ($p + 2 + $n > $len) break;
                    $val = substr($buff, $p + 2, $n);
                    $et->HandleTag($tagTablePtr, $t, $val);
                    $p += 2 + $n;
                }
            } elseif ($id == 0x0) {
                if (!$len) break;
                // example directory table for record locations from Insta360AcePro MP4 video:
                //  vv vv                         - record ID
                //        vv vv vv vv             - record size
                //                    vv vv vv vv - offset from start of footer
                //  00 00 00 00 00 00 00 00 00 00
                //  01 01 82 04 00 00 1b 45 62 00
                //  02 00 28 46 05 00 ed fe 5c 00
                //  03 00 40 aa 24 00 ed fe 34 00
                //  04 00 00 c1 01 00 ed fe 30 00
                //  [...]
                if ($dirTable === null) {
                    $dirTable = $buff;
                    $dirTablePos = 0;
                }
            }
            // step through directory table instead of sequential scanning if possible
            if ($dirTable !== null) {
                $epos = null;
                for (;;) {
                    if ($dirTablePos + 10 > strlen($dirTable)) break;
                    [$id, $siz, $off] = Perl::unpack("x{$dirTablePos}vVV", $dirTable);
                    $dirTablePos += 10;
                    if ($id and $siz and $off + $siz < $trailerLen) {
                        $epos = $off + $siz - $trailerLen;
                        break;
                    }
                }
                if ($epos === null) break;
            } else {
                if (($epos -= 6) + $trailerLen < 0) break;  // step back to previous record
            }
            if (!$raf->Seek($epos - $offset, 2)) break;     // seek to start of next footer
            if ($raf->Read($buff, 6) != 6) break;           // read footer
        }
        $et->DOC_NUM = null;
        ExifTool::SetByteOrder('MM');
        $et->SET_GROUP0 = null;
        $et->SET_GROUP1 = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process CAMM metadata (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCAMM(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $end = $pos + (($dirInfo['DirLen'] ?? null) ?: strlen($dataPt) - $pos);
        // camm record size for each type, including 4-byte header
        $size = [1 => 12, 2 => 16, 3 => 16, 4 => 16, 5 => 28, 6 => 60, 7 => 16];
        $rtnVal = 0;
        while ($pos + 4 < $end) {
            $type = ExifTool::Get16u($dataPt, $pos + 2);
            $sz = $size[$type] ?? null;     // (Perl: my $size)
            if (!$sz) {
                $et->Warn("Unknown camm record type $type");
                break;
            }
            if ($pos + $sz > $end) {
                $et->Warn("Truncated camm record $type");
                break;
            }
            $tbl = ExifTool::GetTagTable("Image::ExifTool::QuickTime::camm$type");
            $dirInfo['DirStart'] = $pos;
            $dirInfo['DirLen'] = $sz;
            if ($et->ProcessBinaryData($dirInfo, $tbl)) $rtnVal = 1;
            // not sure if this is according to specification, but I have seen multiple
            // camm records all in a single sample, so step forward to process the next one
            $pos += $sz;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process Garmin GPS 'uuid' atom (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Note: This format is used by the Garmin DriveAssist 51, but the DriveAssist 50
    //       uses a completely different format.  :(
    public static function ProcessGarminGPS(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);
        $pos = 33;
        $epoch = (66 * 365 + 17) * 24 * 3600;   // time is relative to Jan 1, 1904
        $scl = 180 / (32768 * 65536);           // scaling factor for lat/lon
        $et->VerboseDir('GarminGPS');
        $et->SET_GROUP1 = 'Garmin';
        while ($pos + 20 <= $dataLen) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $time = ExifTool::ConvertUnixTime(ExifTool::Get32u($dataPt, $pos) - $epoch) . 'Z';
            $lat = ExifTool::Get32s($dataPt, $pos + 12);
            $lon = ExifTool::Get32s($dataPt, $pos + 16);
            $spd = ExifTool::Get16u($dataPt, $pos + 4);     // (in mph)
            $et->HandleTag($tagTbl, 'GPSDateTime', $time);
            // skip bad GPS fixes
            if ($lat != -2147483648 or $lon != -2147483648) {
                $et->HandleTag($tagTbl, 'GPSLatitude', $lat * $scl);
                $et->HandleTag($tagTbl, 'GPSLongitude', $lon * $scl);
                $et->HandleTag($tagTbl, 'GPSSpeed', $spd);
                $et->HandleTag($tagTbl, 'GPSSpeedRef', 'M');
            }
            $pos += 20;
        }
        $et->DOC_NUM = null;
        $et->SET_GROUP1 = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process 360Fly 'uuid' atom containing sensor data
    // (ref https://github.com/JamesHeinrich/getID3/blob/master/getid3/module.audio-video.quicktime.php)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process360Fly(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);
        $pos = 16;
        $lastTime = -1;
        $streamTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
        while ($pos + 32 <= $dataLen) {
            $type = ord(substr($dataPt, $pos, 1));
            $time = ExifTool::Get64u($dataPt, $pos + 2);    // (only valid for some types)
            if ($tagTbl[$type]) {
                if ($time != $lastTime) {
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    $lastTime = $time;
                }
            }
            $et->HandleTag($tagTbl, $type, null, DataPt: $dataPt, Start: $pos, Size: 32);
            // synthesize GPSDateTime from the timestamp for GPS records
            if ($type == 5) self::SetGPSDateTime($et, $streamTbl, $time / 1e6);
            $pos += 32;
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process GPS from Vantrue N2S dashcam
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessFMAS(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        if (!(preg_match('/^FMAS\0\0\0\0.{72}SAMM.{36}A/s', $dataPt) and strlen($dataPt) >= 160)) return 0;
        $et->VerboseDir('FMAS', null, strlen($dataPt));
        // 0000: 46 4d 41 53 00 00 00 00 00 00 00 00 00 00 00 00 [FMAS............]
        // 0010: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
        // 0020: 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
        // 0030: 02 08 01 08 06 08 02 04 07 02 06 00 00 00 00 00 [................]
        // 0040: 00 00 00 00 00 00 00 00 4f 46 4e 49 4d 4d 41 53 [........OFNIMMAS]
        // 0050: 53 41 4d 4d 01 00 00 00 00 00 00 00 00 00 00 00 [SAMM............]
        // 0060: e5 07 09 18 08 00 22 00 02 00 00 00 a1 82 8a bf [......".........]
        // 0070: 89 23 8e bd 0b 2c 30 bc 41 57 4e 51 16 00 a1 01 [.#...,0.AWNQ....]
        // 0080: 29 26 27 0c 4b 00 49 00 00 00 00 00 00 00 00 00 [)&'.K.I.........]
        // 0090: 00 00 00 00 00 00 00 00 00 52 00 00 00 00 00 00 [.........R......]
        $a = Perl::unpack('x96vCCCCCCx16AAACCCvCCvvv', $dataPt);
        ExifTool::SetByteOrder('II');
        $acc = ExifTool::ReadValue($dataPt, 0x6c, 'float', 3);  // (looks like Z comes first in my sample)
        $lon = $a[10] + ($a[11] + $a[13] / 6000) / 60;  // (why zero byte at $a[12]?)
        $lat = $a[14] + ($a[15] + $a[16] / 6000) / 60;
        $et->HandleTag($tagTbl, 'GPSDateTime', Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d', ...array_slice($a, 0, 6)));
        $et->HandleTag($tagTbl, 'GPSLatitude', $lat * ($a[9] === 'S' ? -1 : 1));
        $et->HandleTag($tagTbl, 'GPSLongitude', $lon * ($a[8] === 'W' ? -1 : 1));
        $et->HandleTag($tagTbl, 'GPSSpeed', $a[17] * Vars\QuickTime::$mphToKph);   // convert mph -> kph
        $et->HandleTag($tagTbl, 'GPSTrack', $a[18]);
        $et->HandleTag($tagTbl, 'Accelerometer', $acc);
        ExifTool::SetByteOrder('MM');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process GPS from Wolfbox G900 Dashcam and Redtiger F9 4K
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessWolfbox(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        if (strlen($dataPt) < 0xf8) return 0;
        $et->VerboseDir('Wolfbox', null, strlen($dataPt));
        // 0000: 65 00 00 00 00 00 00 00 31 01 01 00 e3 ff 00 00 [e.......1.......]
        // 0010: 04 00 00 00 10 00 00 00 2a 00 00 00 00 00 00 00 [........*.......]
        // 0020: 01 00 00 00 00 00 00 00 8b 33 ff 51 00 00 00 00 [.........3.Q....]
        // 0030: a0 86 01 00 00 00 00 00 4d 5e 07 fa ff ff ff ff [........M^......]
        // 0040: a0 86 01 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
        // 0050: 64 00 00 00 00 00 00 00 90 21 00 00 00 00 00 00 [d........!......]
        // 0060: 64 00 00 00 00 00 00 00 18 00 00 00 03 00 00 00 [d...............]
        // 0070: e8 07 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
        // 0080: 00 00 00 00 00 00 00 00 30 30 30 30 30 30 30 30 [........00000000]
        // 0090: 30 30 30 30 30 30 30 30 48 59 54 48 00 00 00 00 [00000000HYTH....]
        // 00a0: 0c 00 00 00 10 00 00 00 2a 00 00 00 00 00 00 00 [........*.......]
        // 00b0: 4f 3f 0c 1f 00 00 00 00 a0 86 01 00 00 00 00 00 [O?..............]
        // 00c0: 7f cf 2d ff ff ff ff ff a0 86 01 00 00 00 00 00 [..-.............]
        // 00d0: 01 00 00 00 08 00 00 00 0a 00 00 00 00 00 00 00 [................]
        // 00e0: 0a 00 00 00 00 00 00 00 e8 03 00 00 00 00 00 00 [................]
        // 00f0: 0a 00 00 00 00 00 00 00 4d 00 00 00 00 00 00 00 [........M.......]
        // lat/lon at 0xb0/0xc0 and 0x128/0x138
        // h/m/s at 0x10 and 0xa0 and 0x148 (the first imprinted on the video, and
        // the latter 2 presumed UTC, but there is a 1 second offset for the Redtiger)
        // spd at 0x48, dir at 0x58, alt at 0xe8
        // Redtiger F9 4K Dual Front and Rear Mini Dash Cam
        // 0000: 01 00 00 00 00 00 00 00 f4 ff 5d fe 24 00 00 00 [..........].$...]
        // 0010: 10 00 00 00 2d 00 00 00 25 00 00 00 00 00 00 00 [....-...%.......]
        // 0020: 01 00 00 00 00 00 00 00 44 eb 8f 00 00 00 00 00 [........D.......]
        // 0030: 10 27 00 00 00 00 00 00 1b 94 8a 04 00 00 00 00 [.'..............]
        // 0040: 10 27 00 00 00 00 00 00 8c 69 00 00 00 00 00 00 [.'.......i......]
        // 0050: e8 03 00 00 00 00 00 00 ba 47 00 00 00 00 00 00 [.........G......]
        // 0060: 64 00 00 00 00 00 00 00 19 00 00 00 05 00 00 00 [d...............]
        // 0070: e9 07 00 00 00 00 00 00 00 00 00 00 00 00 00 00 [................]
        // 0080: 00 00 00 00 00 00 00 00 30 30 30 30 30 30 30 30 [........00000000]
        // 0090: 30 30 30 30 30 30 30 30 58 58 58 58 00 00 00 00 [00000000XXXX....]
        // 00a0: 08 00 00 00 2d 00 00 00 24 00 00 00 00 00 00 00 [....-...$.......]
        // 00b0: 90 eb 8f 00 00 00 00 00 10 27 00 00 00 00 00 00 [.........'......]
        // 00c0: 20 94 8a 04 00 00 00 00 10 27 00 00 00 00 00 00 [ ........'......]
        // 00d0: 01 00 00 00 11 00 00 00 40 00 00 00 00 00 00 00 [........@.......]
        // 00e0: 64 00 00 00 00 00 00 00 8a 00 00 00 00 00 00 00 [d...............]
        // 00f0: 0a 00 00 00 00 00 00 00 4d 00 00 00 00 00 00 00 [........M.......]
        ExifTool::SetByteOrder('II');
        [$d, $mo, $yr, $h, $mi, $s] = Perl::unpack('x104V3x44V3', $dataPt);
        $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', $yr, $mo, $d, $h, $mi, $s);
        $a = [];
        //             0=spd 1=dir 2=lat 3=lon 4=alt
        foreach ([0x48, 0x58, 0xb0, 0xc0, 0xe8] as $pos) {
            $val = ExifTool::Get64s($dataPt, $pos);
            $scl = ExifTool::Get64s($dataPt, $pos + 8);
            $a[] = $val / ($scl ?: 1);
        }
        self::ConvertLatLon($a[2], $a[3]);
        $et->HandleTag($tagTbl, 'GPSDateTime', $time);
        $et->HandleTag($tagTbl, 'GPSLatitude', $a[2]);
        $et->HandleTag($tagTbl, 'GPSLongitude', $a[3]);
        $et->HandleTag($tagTbl, 'GPSSpeed', $a[0] * Vars\QuickTime::$knotsToKph);
        $et->HandleTag($tagTbl, 'GPSTrack', $a[1]);
        $et->HandleTag($tagTbl, 'GPSAltitude', $a[4]);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Scan media data for "freeGPS" and GoPro metadata if not found already (ref PH)
    // Inputs: 0) ExifTool ref
    public static function ScanMediaData(ExifTool $et)
    {
        $raf = $et->RAF;
        if (!$raf) return;
        $tagTbl = $verbose = $buff = $dataLen = $found = null;

        // don't rescan for freeGPS if we already found embedded metadata
        $dataPos = $et->MediaDataOffset;
        if ($et->FoundEmbedded or !$dataPos) return;

        [$pos, $buf2] = [0, ''];
        $ee = $et->Options('ExtractEmbedded');
        $gpsBlockSize = Vars\QuickTime::$gpsBlockSize;
        if ($ee > 2) { // scan entire file from start of mdat if ExtractEmbedded > 2
            $raf->Seek(0, 2);
            $dataLen = $raf->Tell() - $et->MediaDataOffset;
        } else {
            $dataLen = $et->MediaDataSize;
        }
        if (!($dataLen and $raf->Seek($dataPos))) return;

        // loop through 'mdat' media data looking for GPS information
        while ($dataLen) {
            $n = $gpsBlockSize;
            if ($n + $pos > $dataLen) $n = $dataLen - $pos;
            if (!($n > strlen($buf2) and $raf->Read($buff, $n - strlen($buf2)))) break;
            if (strlen($buf2)) $buff = $buf2 . $buff;
            // look for "freeGPS " or GoPro record
            // (freeGPS found on an absolute 0x8000-byte boundary in all of my samples,
            //  but allow for any alignment when searching)
            if (!preg_match('/(\0..\0freeGPS |GP\x06\0\0)/s', $buff, $m, PREG_OFFSET_CAPTURE)) {
                $buf2 = substr($buff, -12);
                $pos += strlen($buff) - 12;
                // in all of my samples the first freeGPS block is within 2 MB of the start
                // of the mdat, so limit the scan to the first 20 MB to be fast and safe
                if ($found or $pos < 20e6 or $ee > 1) continue;
                break;
            } elseif ($m[1][0] === "GP\x06\0\0") { // (GoPro GPS record header)
                // (found in Chigee Aio-5 Lite and some Insta360 videos)
                $buffPos = $m[0][1] + strlen($m[0][0]);     // (pos($buff))
                $filePos = $raf->Tell();
                $start = $filePos - strlen($buff) + $buffPos - strlen($m[1][0]);
                if (!$raf->Seek($start)) break;
                if ($found === null) {
                    $et->VPrint(0, "---- Extract Embedded ----\n");
                    $et->INDENT .= '| ';
                    $found = 0;
                }
                $maxLen = $dataLen - ($start - $et->MediaDataOffset);
                $et->VPrint(0, Perl::sprintf("Unreferenced GoPro record at 0x%x\n", $filePos));
                $di = ['RAF' => $raf, 'DirLen' => $maxLen];
                $size = GoPro::ProcessGP6($et, $di);
                if ($size) {
                    if (!$found) {
                        // scan entire file if we found a valid GoPro record
                        // (some records may exist in trailer)
                        if ($raf->Seek(0, 2)) $dataLen = $raf->Tell() - $et->MediaDataOffset;
                        $found = 2;
                    }
                    if (!$raf->Seek($start + $size)) break;
                    $pos = $start + $size - $et->MediaDataOffset;
                    $buf2 = '';
                } else {
                    // (could have been a random match -- continue with search)
                    if (!$raf->Seek($filePos)) break;
                    $buf2 = substr($buff, $buffPos);
                    $pos += $buffPos;
                }
                continue;
            }
            if (strlen($buff) < $gpsBlockSize) break;
            if (!$tagTbl) {
                // initialize variables for extracting metadata from this block
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                $verbose = $et->OPTIONS['Verbose'];
                $et->VPrint(0, "---- Extract Embedded ----\n");
                $et->INDENT .= '| ';
                $found = 1;
            }
            $bpos = $m[0][1] + strlen($m[0][0]);    // (pos($buff))
            if ($bpos > 12) {
                $pos += $bpos - 12;
                $buff = substr($buff, $bpos - 12);
            }
            // make sure we have the full freeGPS record
            $len = Perl::unpack('N', $buff)[0];
            if ($len < 12) {
                $len = 12;
            } else {
                $more = $len - strlen($buff);
                if ($more > 0) {
                    if ($raf->Read($buf2, $more) != $more) break;
                    $buff .= $buf2;
                }
                if ($verbose) {
                    $et->VerboseDir('GPS', null, $len);
                    $et->VerboseDump($buff, DataPos: $pos + $dataPos);
                }
                $dirInfo = ['DataPt' => $buff, 'DataPos' => $pos + $dataPos, 'DirLen' => $len];
                self::ProcessFreeGPS($et, $dirInfo, $tagTbl);
                $buff = $dirInfo['DataPt'];     // (Perl: the data may be modified in place)
                $et->FoundGPSByScan = 1;
            }
            $pos += $len;
            $buf2 = substr($buff, $len);
        }
        if ($found) {
            $et->DOC_NUM = null;    // reset DOC_NUM after extracting embedded metadata
            $et->VPrint(0, "--------------------------\n");
            $et->INDENT = substr($et->INDENT, 0, -2);
        }
    }
}
