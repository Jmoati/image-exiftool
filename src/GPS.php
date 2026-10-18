<?php

//------------------------------------------------------------------------------
// File:         GPS.pm
//
// Description:  EXIF GPS meta information tags
//
// Revisions:    12/09/2003  - P. Harvey Created
//
// PHP port of Image::ExifTool::GPS 1.58 (reading only). Tag tables are generated
// into tables/GPS.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GPS
{
    //------------------------------------------------------------------------------
    // Convert GPS timestamp value
    // Inputs: 0) raw timestamp value string
    // Returns: EXIF-formatted time string
    public static function ConvertTimeStamp($val)
    {
        [$h, $m, $s] = Perl::split(' ', $val) + [null, null, null];
        $f = (Perl::num($h ?: 0) * 60 + Perl::num($m ?: 0)) * 60 + Perl::num($s ?: 0);
        $h = Perl::int($f / 3600); $f -= $h * 3600;
        $m = Perl::int($f / 60);   $f -= $m * 60;
        $ss = Perl::sprintf('%012.9f', $f);
        if (Perl::num($ss) >= 60) {
            $ss = '00';
            if (++$m >= 60) {
                $m -= 60;
                ++$h;
            }
        } else {
            $ss = preg_replace('/\.?0+$/', '', $ss, 1);  // trim trailing zeros + decimal
        }
        return Perl::sprintf('%.2d:%.2d:%s', $h, $m, $ss);
    }

    //------------------------------------------------------------------------------
    // Print GPS timestamp
    // Inputs: 0) EXIF-formatted time string
    // Returns: time rounded to the nearest microsecond
    public static function PrintTimeStamp($val)
    {
        if (!preg_match('/:(\d{2}\.\d+)$/', Perl::str($val), $m)) return $val;
        $val = preg_replace('/:(\d{2}\.\d+)$/', '', $val, 1);
        $s = Perl::str(Perl::int(Perl::num($m[1]) * 1000000 + 0.5) / 1000000);
        if (Perl::num($s) < 10) $s = "0$s";
        return "{$val}:$s";
    }

    //------------------------------------------------------------------------------
    // Convert degrees to DMS, or whatever the current settings are
    // Inputs: 0) ExifTool reference, 1) Value in degrees,
    //         2) format code (0=no format, 1=CoordFormat, 2=XMP format, 3=signed unformatted)
    //         3) 'N' or 'E' if sign is significant and N/S/E/W should be added
    // Returns: DMS string
    public static function ToDMS(ExifTool $et, $val, $doPrintConv = null, $ref = null)
    {
        $fmt = $num = $sign = $minus = $rtnVal = $neg = null;
        $fmtList = [];   // (Perl @fmt)

        if (Perl::str($val) === '') {
            // don't convert an empty value
            if ($doPrintConv and Perl::str($doPrintConv) === '1') return $val;  // avoid hiding existing tag when extracting
            return null; // avoid writing empty value
        }
        // (PHP: $numVal is the number Perl caches in $val at its first numeric use, which warns
        // under -w for a string that isn't a number)
        if ($ref) {
            $numVal = Perl::numw($val, 'numeric lt (<)', '$val');
            if ($numVal < 0) {
                // (a string that isn't a number is negated as a string, "-1/3" giving "+1/3", which
                // keeps the number of the original value)
                $val = Perl::neg($val, '$val', true);
                if (!is_string($val)) $numVal = $val;
                $ref = ['N' => 'S', 'E' => 'W'][$ref] ?? null;
                $sign = '-';
                $minus = '-';
            } else {
                $sign = '+';
                $minus = '';
            }
            if (!($doPrintConv and Perl::str($doPrintConv) === '2')) $ref = " $ref";
        } else {
            if ($doPrintConv and Perl::str($doPrintConv) === '3') {
                $val = Perl::numw($val, 'numeric lt (<)', '$val');
                if ($val < 0) $neg = 1;
                $doPrintConv = 0;
            } else {
                $val = Perl::numw($val, 'abs', '$val');
            }
            $numVal = $val = abs($val);
            $ref = '';
        }
        if ($doPrintConv) {
            if (Perl::str($doPrintConv) === '1') {
                $fmt = $et->Options('CoordFormat');
                if (!$fmt) {
                    $fmt = '%d deg %d\' %.2f"' . $ref;
                } elseif ($ref) {
                    // use signed value instead of reference direction if specified
                    $fmt = preg_replace('/%\+/', "$sign%", $fmt, -1, $n);
                    if (!$n) {
                        $fmt = preg_replace('/%-/', "$minus%", $fmt, -1, $n);
                        if (!$n) $fmt .= $ref;
                    }
                } else {
                    $fmt = preg_replace('/%\+/', '%', $fmt);  // don't know sign, so don't print it
                }
            } else {
                $fmt = "%d,%.8f$ref";   // use XMP format with 8 decimal minutes
            }
            // count (and capture) the format specifiers (max 3)
            preg_match_all('/(%(%|[^%]*?[diouxXDOUeEfFgGcs]))/', $fmt, $mm);
            foreach ($mm[1] as $_) {
                if ($_ === '%%') continue;
                $fmtList[] = $_;
                if (count($fmtList) >= 3) break;
            }
            $num = count($fmtList);
        } else {
            $num = 3;
        }
        $c = [];  // coordinates (D) or (D,M) or (D,M,S)
        $c[0] = $val;
        if ($num > 1) {
            $c[0] = Perl::int($numVal);
            $c[1] = ($numVal - $c[0]) * 60;
            if ($num > 2) {
                $c[1] = Perl::int($c[1]);
                $c[2] = ($numVal - $c[0] - $c[1] / 60) * 3600;
            }
            // handle round-off errors to ensure minutes and seconds are
            // less than 60 (eg. convert "72 59 60.00" to "73 0 0.00")
            $last = count($c) - 1;
            $c[$last] = $doPrintConv ? Perl::sprintf($fmtList[count($fmtList) - 1], $c[$last]) : Perl::str($c[$last]);
            if (Perl::num($c[$last]) >= 60) {
                $c[$last] = Perl::num($c[$last]) - 60;
                if (($c[$last - 1] += 1) >= 60 and $num > 2) {
                    $c[$last - 1] -= 60;
                    $c[$last - 2] += 1;
                }
            }
        }
        if ($doPrintConv) {
            $rtnVal = Perl::sprintf($fmt, ...$c);
            // trim trailing zeros in XMP
            if (Perl::str($doPrintConv) === '2') $rtnVal = preg_replace("/(\\d)0+$ref$/", '${1}' . $ref, $rtnVal, 1);
        } else {
            if ($neg) {
                foreach ($c as &$_) $_ = Perl::num($_) * -1;
                unset($_);
            }
            $rtnVal = implode(' ', array_map(Perl::str(...), $c)) . $ref;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Convert to decimal degrees
    // Inputs: 0) a string containing 1-3 decimal numbers and any amount of other garbage
    //         1) true if value should be negative if coordinate ends in 'S' or 'W',
    //         2) 'lat' or 'lon' to extract lat or lon from GPSCoordinates string
    // Returns: Coordinate in degrees, or '' on error
    public static function ToDegrees($val, $doSign = null, $coord = null)
    {
        $val = Perl::str($val);
        if (preg_match('/\b(inf|undef)\b/', $val)) return ''; // ignore invalid values
        // use only lat or lon part of combined GPSCoordinates inputs
        if ($coord and ($coord === 'lat' or $coord === 'lon') and
            // (two formatted coordinate values with cardinal directions, separated by a comma)
            preg_match('/^(.*(?:N(?:orth)?|S(?:outh)?)),\s*(.*(?:E(?:ast)?|W(?:est)?))$/i', $val, $m))
        {
            $val = $coord === 'lat' ? $m[1] : $m[2];
        }
        // extract decimal or floating point values out of any other garbage
        preg_match_all('/((?:[+-]?)(?=\d|\.\d)\d*(?:\.\d*)?(?:[Ee][+-]\d+)?)/', $val, $mm);
        [$d, $m, $s] = $mm[1] + [null, null, null];
        if ($d === null) return '';
        $deg = Perl::num($d) + (Perl::num($m ?: 0) + Perl::num($s ?: 0) / 60) / 60;
        // make negative if S or W coordinate
        if ($doSign ? preg_match('/[^A-Z](S(outh)?|W(est)?)\s*$/i', $val) : $deg < 0) $deg = -$deg;
        return $deg;
    }
}
