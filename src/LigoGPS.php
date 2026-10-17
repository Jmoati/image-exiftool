<?php

//------------------------------------------------------------------------------
// File:         LigoGPS.pm
//
// Description:  Read LIGOGPSINFO timed GPS records
//
// Revisions:    2024-12-30 - P. Harvey Created
//
// PHP port of Image::ExifTool::LigoGPS 1.06 ($knotsToKph is in Vars\LigoGPS).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class LigoGPS
{
    public static $VERSION = '1.06';

    //------------------------------------------------------------------------------
    // Clean up cipher variables and print warning if deciphering was unsuccessful
    // Inputs: 0) ExifTool ref
    public static function CleanupCipher(ExifTool $et)
    {
        if ($et->LigoCipher and !empty($et->LigoCipher['next'])) {
            $et->Warn('Not enough GPS points to determine cipher for decoding LIGOGPSINFO');
        }
        $et->LigoCipher = null;
    }

    //------------------------------------------------------------------------------
    // Un-do LIGOGPS fuzzing
    // Inputs: 0) fuzzed latitude, 1) fuzzed longitude, 2) scale factor
    // Returns: 0) latitude, 1) longitude
    public static function UnfuzzLigoGPS($lat, $lon, $scl)
    {
        [$lat, $lon] = [Perl::num($lat), Perl::num($lon)];
        $lat2 = Perl::int($lat / 10) * 10;
        $lon2 = Perl::int($lon / 10) * 10;
        return [$lat2 + ($lon - $lon2) * $scl, $lon2 + ($lat - $lat2) * $scl];
    }

    //------------------------------------------------------------------------------
    // Decrypt LIGOGPSINFO record (starting with "####")
    // Inputs: 0) encrypted GPS record incuding 8-byte header
    // Returns: decrypted record including 4-byte uint32 header, or undef on error
    public static function DecryptLigoGPS($str)
    {
        $num = Perl::unpack('x4V', $str)[0] ?? null;
        if ($num < 4) return null;
        if ($num > 0x84) $num = 0x84;   // (be safe)
        $in = Perl::unpack("x8C$num", $str);
        $out = [];
        while ($in) {
            $b = array_shift($in);  // get next byte in data
            // upper 3 bits steer the decryption for this round
            $steeringBits = $b & 0xe0;
            if ($steeringBits >= 0xc0) {
                if (count($in) < 4) return null;    // next 4 bytes are encrypted data
                array_push($out, (array_shift($in) | $b & 0x01) ^ 0x20,
                                 (array_shift($in) | $b & 0x02) ^ 0x20,
                                 (array_shift($in) | $b & 0x0c) ^ 0x20,
                                  array_shift($in) ^ 0x20 | $b & 0x30);
            } elseif ($steeringBits >= 0x40) {
                if (count($in) < 3) return null;    // next 3 bytes are encrypted data
                if ($steeringBits == 0x40) {
                    array_push($out, 0x20,
                                     (array_shift($in) | $b & 0x01) ^ 0x20,
                                     (array_shift($in) | $b & 0x06) ^ 0x20,
                                     (array_shift($in) | $b & 0x18) ^ 0x20);
                } elseif ($steeringBits == 0x60) {
                    array_push($out, (array_shift($in) | $b & 0x03) ^ 0x20,
                                     0x20,
                                     (array_shift($in) | $b & 0x04) ^ 0x20,
                                     (array_shift($in) | $b & 0x18) ^ 0x20);
                } elseif ($steeringBits == 0x80) {
                    array_push($out, (array_shift($in) | $b & 0x03) ^ 0x20,
                                     (array_shift($in) | $b & 0x0c) ^ 0x20,
                                     0x20,
                                     (array_shift($in) | $b & 0x10) ^ 0x20);
                } else {
                    array_push($out, (array_shift($in) | $b & 0x01) ^ 0x20,
                                     (array_shift($in) | $b & 0x06) ^ 0x20,
                                     (array_shift($in) | $b & 0x18) ^ 0x20,
                                     0x20);
                }
            } elseif ($steeringBits == 0x00) {
                if (count($in) < 1) return null;    // next byte is encrypted data
                $out[] = array_shift($in) | $b & 0x13;
            } else {
                return null;    // (shouldn't happen)
            }
        }
        return Perl::pack('C*', ...$out);
    }

    //------------------------------------------------------------------------------
    // Determine correct ordering of enciphered digits (unit digits of seconds)
    // Inputs: 0) starting character code, 1) lookup for next character(s) in sequence
    //         2) i/o list of ordered characters, 3) hash of used characters
    // Returns: true if a consistent ordering was found
    // - loops through all possible orders based on $next sequence until a complete
    //   cycle is established
    // - this complexity is necessary because GPS may skip some seconds
    public static function OrderCipherDigits($ch, array $next, array &$order, ?array $did = null)
    {
        if ($did === null) $did = [];
        while (!empty($next[$ch])) {
            if (count($order) < 10) {
                if (!empty($did[$ch])) break;
            } else {
                // success if we have cycled through all 10 digits and back to the first
                if (count($order) == 10 and $ch === $order[0]) return 1;
                break;
            }
            $order[] = $ch;
            $did[$ch] = 1;
            // continue with next character if there is only one possibility
            if (count($next[$ch]) == 1) {
                $ch = $next[$ch][0];
                continue;
            }
            // otherwise, test all possibilities
            $n = count($order) - 1;
            foreach ($next[$ch] as $c) {
                // (PHP: $did is passed by value, a copy of the used-character lookup)
                if (self::OrderCipherDigits($c, $next, $order, $did)) return 1;
                array_splice($order, $n + 1);   // restore order and try next possibility
            }
            break;
        }
        return 0; // failure
    }

    //------------------------------------------------------------------------------
    // Decipher and parse LIGOGPSINFO record (starting with "####")
    // Inputs: 0) ExifTool ref, 1) enciphered string, 2) tag table ref
    //         3) true if GPS coordinates don't need de-fuzzing
    // Returns: true if this looked like an enciphered string
    // Notes: handles contained tags, but may defer handling until full cipher is known
    public static function DecipherLigoGPS(ExifTool $et, $str, TagTable $tagTbl, $noFuzz = null)
    {
        // (enciphered characters must be in the range 0x30-0x5f ('0' - '_'))
        if (!preg_match('~^####.{4}([0-_])[0-_]{3}/[0-_]{2}/[0-_]{2} ..([0-_])..([0-_]).([0-_]) ~s', $str, $m)) return null;
        if ($m[2] !== $m[3]) return null;   // (colons in time string must be the same)

        if (!$et->LigoCipher) {
            $et->LigoCipher = ['cache' => [], 'next' => []];
            $et->AddCleanup(self::CleanupCipher(...));
        }
        $cipherInfo = &$et->LigoCipher;     // (Perl hash ref)
        $decipher = $cipherInfo['decipher'] ?? null;

        // determine the cipher code table based on the advancing 1's digit of seconds
        if (!$decipher) {
            $cipherInfo['cache'][] = $str;  // cache records until we can decipher them
            $next = &$cipherInfo['next'];
            [$millennium, $colon, $ch2] = [$m[1], $m[2], $m[4]];
            // determine the cipher lookup table
            // (only characters in range 0x30-0x5f are encrypted)
            $ch1 = $cipherInfo['ch1'] ?? null;
            $cipherInfo['ch1'] = $ch2;
            if ($ch1 === null or $ch1 === $ch2) return 1;   // ignore duplicate sequential digits
            if (!empty($next[$ch1])) {
                if (in_array($ch2, $next[$ch1], true)) return 1;    // don't add twice
                $next[$ch1][] = $ch2;
            } else {
                $next[$ch1] = [$ch2];
            }
            // must wait until the lookup contains all 10 digits
            if (count($next) < 10) return 1;
            // protect against trying to decipher bad data
            if (count($next) > 10) {
                $cipherInfo['next'] = [];
                return 1;
            }
            $order = [];
            $two = null;
            if (!self::OrderCipherDigits($ch1, $next, $order)) return 1;
            // get index of enciphered "2" in ordered array
            foreach (range(0, 9) as $k) { if ($order[$k] === $millennium) { $two = $k; break; } }
            if ($two === null) {
                $et->Warn('Problem deciphering LIGOGPSINFO');
                return 1;
            }
            unset($next);
            unset($cipherInfo['next']);     // all done with 'next' lookup
            $decipher = [$colon => ':'];    // (':' is the time separator)
            foreach (range(0, 9) as $k) {
                $ch = $order[($k + $two - 2 + 10) % 10];
                $decipher[$ch] = chr($k + 0x30);
            }
            // may also know the lat/lon quadrant from the signs of the coordinates
            // (PHP: $colon is interpolated unquoted, as in Perl)
            if (preg_match("/ ([0-_])$colon(-?).*? ([0-_])$colon(-?)/", $str, $mm)) {
                $decipher[$mm[1]] = $mm[2] ? 'S' : 'N';
                $decipher[$mm[3]] = $mm[4] ? 'W' : 'E';
                if (!($mm[2] or $mm[4])) {
                    [$ns, $ew] = [$mm[1], $mm[3]];
                    if ($et->OPTIONS['GPSQuadrant'] and preg_match('/^([NS])([EW])$/i', $et->OPTIONS['GPSQuadrant'], $q)) {
                        $decipher[$ns] = strtoupper($q[1]);
                        $decipher[$ew] = strtoupper($q[2]);
                    } else {
                        $et->Warn('May need to set API GPSQuadrant option (eg. "NW")');
                    }
                }
            }
            // fill in unknown entries with '?' (only chars 0x30-0x5f are enciphered)
            foreach (range(0x30, 0x5f) as $c) { if (!isset($decipher[chr($c)])) $decipher[chr($c)] = '?'; }
            $cipherInfo['decipher'] = $decipher;
            $str = array_shift($cipherInfo['cache']);   // start deciphering at oldest cache entry
        }

        // apply reverse cipher and extract GPS information
        do {
            $pre = substr($str, 4, 4);  // save second 4 bytes of header
            $str = preg_replace('/\0+$/', '', substr($str, 8), 1);  // remove 8-byte header and null padding
            $str = strtr($str, $decipher);  // decipher (only chars 0x30-0x5f are in the table)
            if ($et->OPTIONS['Verbose'] > 1) {
                $et->VPrint(1, "{$et->INDENT}(Deciphered: " . bin2hex(substr($pre, 0, 4)) . " $str)\n");
            }
            // add back leading 4 bytes (int16u counter plus 2 unknown bytes), and parse
            self::ParseLigoGPS($et, "$pre$str", $tagTbl, $noFuzz);
        } while ($str = array_shift($cipherInfo['cache']));

        return 1;
    }

    //------------------------------------------------------------------------------
    // Parse decrypted/deciphered (but not defuzzed) LIGOGPSINFO record
    // (record starts with 4-byte int32u counter followed by date/time, etc)
    // Inputs: 0) ExifTool ref, 1) GPS string, 2) tag table ref,
    //         3) flags: 0x01=not fuzzed, 0x02=spd in km/h
    // Returns: nothing
    public static function ParseLigoGPS(ExifTool $et, $str, TagTable $tagTbl, $flags = null)
    {
        // example string input
        // "....2022/09/19 12:45:24 N:31.285065 W:124.759483 46.93 km/h x:-0.000 y:-0.000 z:-0.000"
        if (!preg_match('/^.{4}(\S+ \S+)\s+([NS?]):(-?)([.\d]+)\s+([EW?]):(-?)([\.\d]+)\s+([.\d]+)/s', $str, $m)) {
            $et->Warn('LIGOGPSINFO format error');
            return;
        }
        if (!$flags) $flags = 0;
        [$time, $latRef, $latNeg, $lat, $lonRef, $lonNeg, $lon, $spd] = [$m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $m[8]];
        $gpsScl = [1 => 1.524855137, 2 => 1.456027985, 3 => 1.15368];
        $spdScl = $flags & 0x01 ? ($flags & 0x02 ? 1 : Vars\LigoGPS::$knotsToKph) : 1.85407333;
        $et->DOC_NUM = ++$et->DOC_COUNT;
        $time = strtr($time, '/', ':');
        // convert from DDMM.MMMMMM to DD.DDDDDD if necessary
        // (speed wasn't scaled in my 1 sample with this format)
        if (preg_match('/^\d{3}/', $lat)) {
            QuickTime::ConvertLatLon($lat, $lon);
            $spdScl = 1;
        }
        if (!($flags & 0x01)) { // unfuzz the coordinates if necessary
            $scl = ($et->OPTIONS['LigoGPSScale'] ?? null) ?: ($et->LigoGPSScale ?: 1);
            if (isset($gpsScl[Perl::str($scl)])) $scl = $gpsScl[Perl::str($scl)];
            [$lat, $lon] = self::UnfuzzLigoGPS($lat, $lon, $scl);
        }
        // a final sanity check
        if (Perl::num($lat) > 90 or Perl::num($lon) > 180) {
            $et->Warn('LIGOGPSINFO coordinates out of range');
            return;
        }
        $et->SET_GROUP1 = 'LIGO';
        $et->HandleTag($tagTbl, 'GPSDateTime', $time);
        // (ignore N/S/E/W if coordinate is signed)
        $et->HandleTag($tagTbl, 'GPSLatitude', Perl::num($lat) * (($latNeg or $latRef === 'S') ? -1 : 1));
        $et->HandleTag($tagTbl, 'GPSLongitude', Perl::num($lon) * (($lonNeg or $lonRef === 'W') ? -1 : 1));
        $et->HandleTag($tagTbl, 'GPSSpeed', Perl::num($spd) * $spdScl);
        if (preg_match('/\bA:(\S+)/', $str, $m)) $et->HandleTag($tagTbl, 'GPSTrack', $m[1]);
        if (preg_match('/\bH:(\S+)/', $str, $m)) $et->HandleTag($tagTbl, 'GPSAltitude', $m[1]);
        if (preg_match('/\bM:(\S+)/', $str, $m)) $et->HandleTag($tagTbl, 'MagneticVariation', $m[1]);
        // (have a sample where tab is used to separate acc components)
        if (preg_match('/x:(\S+)\sy:(\S+)\sz:(\S+)/', $str, $m)) $et->HandleTag($tagTbl, 'Accelerometer', "$m[1] $m[2] $m[3]");
        $et->SET_GROUP1 = null;
    }

    //------------------------------------------------------------------------------
    // Process GKU dashcam trailer containing JSON-format LigoGPS
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessGKU(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = Perl::unpack('V', $dataPt)[0] ?? 0;
        if ($pos + 13 > strlen($dataPt) or substr($dataPt, $pos, 13) !== 'LIGOGPSINFO {') return 0;
        $dirInfo['PHP_pos'] = $pos;     // (Perl: pos($$dataPt) = $pos)
        return self::ProcessLigoJSON($et, $dirInfo, $tagTbl);
    }

    //------------------------------------------------------------------------------
    // Process LIGOGPSINFO data (non-JSON format)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    //         3) 1=LIGOGPS lat/lon/spd weren't fuzzed
    // Returns: 1 on success
    // Notes:  The directory data should start with the string "LIGOGPSINFO\0"
    public static function ProcessLigoGPS(ExifTool $et, array &$dirInfo, TagTable $tagTbl, $noFuzz = null)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = (($dirInfo['DirStart'] ?? null) ?: 0) + 0x14;
        if ($pos > strlen($dataPt)) return null;
        $cipherInfo = $et->LigoCipher !== null;   // (PHP: Perl captures the hash ref here, once)
        $dirName = ($dirInfo['DirName'] ?? null) ?: 'LigoGPS';
        if (empty($dirInfo['DirID'])) $et->PATH[] = $dirName;
        // not fuzzed if header =~ /LIGOGPSINFO\0\0\0\0[\x01\x14]/ (\x01=BlueSkySeaDV688)
        if (preg_match('/^\0\0\0[\x01\x14]/', substr($dataPt, $pos - 8, 4))) $noFuzz = 1;
        $et->VerboseDir($dirName);
        for (; $pos + 0x84 <= strlen($dataPt); $pos += 0x84) {
            $dat = substr($dataPt, $pos, 0x84);
            if (!preg_match('/^####/', $dat)) {
                if (!preg_match('~^.{4}\d{4}/\d{2}/\d{2} ~s', $dat)) continue;   // (have seen blank records filled with zeros, so keep trying)
                // non-encrypted format written by Redtiger F9 4K
                $dat = preg_replace('/\0+$/', '', $dat, 1);     // remove trailing nulls
                self::ParseLigoGPS($et, $dat, $tagTbl, 0x03);
                continue;
            }
            // decipher if we already know the encryption
            if ($cipherInfo and !empty($et->LigoCipher['decipher']) and self::DecipherLigoGPS($et, $dat, $tagTbl, $noFuzz)) continue;
            $str = self::DecryptLigoGPS($dat);
            if ($str === null) {
                self::DecipherLigoGPS($et, $dat, $tagTbl, $noFuzz);     // try to decipher
                continue;
            }
            if ($et->OPTIONS['Verbose'] > 1) $et->VPrint(1, "{$et->INDENT}(Decrypted: ", Perl::unpack('V', $str)[0], ' ', substr($str, 4), ")\n");
            self::ParseLigoGPS($et, $str, $tagTbl, $noFuzz);
        }
        if (empty($dirInfo['DirID'])) array_pop($et->PATH);
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process LIGOGPSINFO JSON-format GPS (Yada RoadCam Pro 4K BT58189)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Sample data (chained 512-byte records starting like this):
    // 0000: 4c 49 47 4f 47 50 53 49 4e 46 4f 20 7b 22 48 6f [LIGOGPSINFO {"Ho]
    // 0010: 75 72 22 3a 20 22 32 33 22 2c 20 22 4d 69 6e 75 [ur": "23", "Minu]
    // 0020: 74 65 22 3a 20 22 31 30 22 2c 20 22 53 65 63 6f [te": "10", "Seco]
    // 0030: 6e 64 22 3a 20 22 32 32 22 2c 20 22 59 65 61 72 [nd": "22", "Year]
    // 0040: 22 3a 20 22 32 30 32 33 22 2c 20 22 4d 6f 6e 74 [": "2023", "Mont]
    // 0050: 68 22 3a 20 22 31 32 22 2c 20 22 44 61 79 22 3a [h": "12", "Day":]
    // 0060: 20 22 32 38 22 2c 20 22 73 74 61 74 75 73 22 3a [ "28", "status":]
    public static function ProcessLigoJSON(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $p = $dirInfo['PHP_pos'] ?? 0;  // (pos($$dataPt), set by ProcessGKU)
        $knotsToKph = Vars\LigoGPS::$knotsToKph;
        $et->VerboseDir('LIGO_JSON', null, strlen($dataPt) - $p);
        $et->SET_GROUP1 = 'LIGO';
        while (preg_match('/LIGOGPSINFO (\{.*?\})/', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
            $p = $m[0][1] + strlen($m[0][0]);
            $json = $m[1][0];
            $dbase = [];
            Import::ReadJSON(new ScalarRef($json), $dbase);
            $info = $dbase['*'] ?? null;
            if (!$info) continue;
            // my sample contains the following JSON fields (in this order):
            // Hour Minute Second Year Month Day (GPS UTC time)
            // status NS EW Latitude Longitude Speed (speed in knots)
            // GsensorX GsensorY GsensorZ (units? - only seen "000" for all)
            // MHour MMinute MSecond MYear MMonth MDay (local dashcam clock time)
            // OLatitude OLongitude (? same values as Latitude/Longitude)
            if (!(isset($info['status']) and $info['status'] === 'A')) continue;   // only read if GPS is active
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $num = 0;
            foreach (['Year', 'Month', 'Day', 'Hour', 'Minute', 'Second'] as $k) { if (isset($info[$k])) ++$num; }
            if ($num == 6) {
                // this is the GPS time in UTC
                $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2dZ', $info['Year'], $info['Month'], $info['Day'], $info['Hour'], $info['Minute'], $info['Second']);
                $et->HandleTag($tagTbl, 'GPSDateTime', $time);
            }
            if (!empty($info['Latitude']) and !empty($info['Longitude'])) {
                $lat = $info['Latitude'];
                if (!empty($info['NS']) and $info['NS'] === 'S') $lat = -Perl::num($lat);
                $lon = $info['Longitude'];
                if (!empty($info['EW']) and $info['EW'] === 'W') $lon = -Perl::num($lon);
                $et->HandleTag($tagTbl, 'GPSLatitude', $lat);
                $et->HandleTag($tagTbl, 'GPSLongitude', $lon);
            }
            if (isset($info['Speed'])) $et->HandleTag($tagTbl, 'GPSSpeed', Perl::num($info['Speed']) * $knotsToKph);
            if (isset($info['GsensorX']) and isset($info['GsensorY']) and isset($info['GsensorZ'])) {
                // (don't know conversion factor for accel data, so leave it raw for now)
                $et->HandleTag($tagTbl, 'Accelerometer', Perl::str($info['GsensorX']) . ' ' . Perl::str($info['GsensorY']) . ' ' . Perl::str($info['GsensorZ']));
            }
            $num = 0;
            foreach (['MYear', 'MMonth', 'MDay', 'MHour', 'MMinute', 'MSecond'] as $k) { if (isset($info[$k])) ++$num; }
            if ($num == 6) {
                // this is the dashcam clock time (local time zone)
                $time = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d', $info['MYear'], $info['MMonth'], $info['MDay'], $info['MHour'], $info['MMinute'], $info['MSecond']);
                $et->HandleTag($tagTbl, 'DateTimeOriginal', $time);
            }
            if (isset($info['OLatitude']) and isset($info['OLongitude'])) {
                $lat = $info['OLatitude'];
                if (!empty($info['NS']) and $info['NS'] === 'S') $lat = -Perl::num($lat);
                $lon = $info['OLongitude'];
                if (!empty($info['EW']) and $info['EW'] === 'W') $lon = -Perl::num($lon);
                $et->HandleTag($tagTbl, 'GPSLatitude2', $lat);
                $et->HandleTag($tagTbl, 'GPSLongitude2', $lon);
            }
            if (!$et->Options('ExtractEmbedded')) {
                $et->Warn('Use the ExtractEmbedded option to extract all timed GPS', 3);
                break;
            }
        }
        $et->DOC_NUM = null;
        $et->SET_GROUP1 = null;
        return 1;
    }
}
