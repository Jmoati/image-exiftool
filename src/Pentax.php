<?php

//------------------------------------------------------------------------------
// File:         Pentax.pm
//
// Description:  Pentax/Asahi EXIF maker notes tags
//
// Revisions:    11/25/2003 - P. Harvey Created
//               02/10/2004 - P. Harvey Completely re-done
//               02/16/2004 - W. Smith Updated (see ref 3)
//               11/10/2004 - P. Harvey Added support for Asahi cameras
//               01/10/2005 - P. Harvey Added LensType with values from ref 4
//               03/30/2005 - P. Harvey Added new tags from ref 5
//               10/04/2005 - P. Harvey Added MOV tags
//               10/22/2007 - P. Harvey Got my new K10D! (more new tags to decode)
//               11/03/2010 - P. Harvey Got my new K-5! (a gold mine of new tags to discover!)
//
// References:   (see Pentax.pm)
//
// PHP port of Image::ExifTool::Pentax 3.63 (reading only: the inverse branch of PrintFilter
// and PentaxEvInv are not ported). Tag tables are generated into tables/Pentax.php,
// %filterSettings, @k3iiiAF and %pentaxLensTypes into Vars\Pentax.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\Pentax as Vars;

final class Pentax
{
    public static $VERSION = '3.63';

    //------------------------------------------------------------------------------
    // Convert filter settings (ref PH, K-5)
    // Inputs: 0) value to convert, 1) flag for inverse conversion, 2) lookup table
    // Returns: converted value
    public static function PrintFilter($val, $inv, $conv)
    {
        $cval = [];

        if (!$inv) {
            // forward conversion (reading):
            $vals = Perl::split(' ', $val);
            $t = array_shift($vals);
            $cval[] = ($conv[Perl::str($t)] ?? null) ?: 'Unknown (' . Perl::str($t) . ')';
            while ($vals) {
                $t = array_shift($vals);
                $v = array_shift($vals);
                if (!$t) continue;
                if ($v === null) break;
                $c = Vars::$filterSettings[$t] ?? null;
                if ($c) {
                    $c1 = $c[1];
                    if (is_array($c1)) {
                        $v = ($c1[$v] ?? null) ?: "Unknown($v)";
                    } elseif ($v) {
                        $v = Perl::sprintf($c1, $v);
                    }
                    $cval[] = "$c[0]=$v";
                } else {
                    $cval[] = "Unknown($t)=$v";
                }
            }
            return $cval ? $cval : null;
        }
        // (reverse conversion (writing) not ported)
        return null;
    }

    //------------------------------------------------------------------------------
    // Decode AF bit mask (ref 29)
    // Inputs: 0) raw binary value, 1) number of AF points, 2) number of bits per AF point,
    //         3) bit mask, 4) bit value (undef for any bit set)
    public static function DecodeAFPoints($val, $num, $bits, $mask, $bitVal = null)
    {
        $bytes = Perl::split(' ', $val);
        if (!$bytes) return '(none)';
        $i = 1;  // (starts at AF point number 1)
        $shift = 8 - $bits;
        $byte = Perl::int(array_shift($bytes));
        $bitList = [];
        for (;;) {
            if ($bitVal) {
                if ((($byte >> $shift) & $mask) == $bitVal) $bitList[] = $i;
            } else {
                if (($byte >> $shift) & $mask) $bitList[] = $i;
            }
            if (++$i > $num) break;
            $shift -= $bits;
            if ($shift < 0) {
                if (!$bytes) break;
                $byte = Perl::int(array_shift($bytes));
                $shift += 8;
            }
        }
        return implode(',', $bitList);
    }

    //------------------------------------------------------------------------------
    // Print K-3III AF Point names (ref PH)
    // Inputs: 0) value, 1) ExifTool ref, 2) optional value to match
    public static function AFPointNamesK3III($val, $et = null, $match = null)
    {
        $a = Perl::split(' ', $val);
        $pts = [];
        foreach ($a as $i => $v) {
            if ($match ? Perl::num($v) == Perl::num($match) : $v) {
                $pts[] = (Vars::$k3iiiAF[$i] ?? null) ?: "Unknown($i)";
            }
        }
        if (!$pts) return '(none)';
        sort($pts, SORT_STRING);
        return implode(',', $pts);
    }

    //------------------------------------------------------------------------------
    // Print K-3III AF point values (ref PH)
    // Inputs: 0) value, 1) ExifTool ref
    // Notes: this is experimental and not well understood
    public static function AFPointValuesK3III($val, $et = null)
    {
        $a = Perl::split(' ', $val);
        $vals = [];
        $k3iiiAF = Vars::$k3iiiAF;
        // (I really don't understand why Pentax seemed to use 28 instead of 41 for
        // the first index of the 2nd value, because as it stands there is
        // overlap between the ranges of the 1st and 2nd values, and there is
        // no way to tell which is which for cases were multiple points have values)
        $n = count($a);
        for ($i = 0; $i < $n; ++$i) {
            if (!($a[$i] ?? null)) continue;
            $pt = ($k3iiiAF[$i] ?? null) ? $k3iiiAF[$i] . '=' : ($k3iiiAF[$i - 28] ?? '') . '=/';
            $vals[] = $pt . $a[$i];
            if (!($a[$i + 28] ?? null)) continue;
            $vals[count($vals) - 1] .= '/' . $a[$i + 28];
            $a[$i + 28] = null;
        }
        if (!$vals) return '(none)';
        sort($vals, SORT_STRING);
        return implode(',', $vals);
    }

    //------------------------------------------------------------------------------
    // Print K-3III AF point positions from tag 0x040c (ref KG)
    // Inputs: 0) raw value, 1) ExifTool ref
    public static function AFAreasK3III($val, $et = null)
    {
        if (!$val) return '(none)';
        $vals = Perl::split(' ', $val);
        // flags bits: [mask, value, description]
        $flags = [[0x10, 0x10, 'central'], [0x08, 0, 'peripheral'], [0x04, 0x04, 'in-focus']];
        $strs = [];
        for ($i = 0; $i + 7 <= count($vals); $i += 7) {
            $a = [];
            foreach ($flags as $_) {
                if ((Perl::int($vals[$i + 6]) & $_[0]) == $_[1]) $a[] = $_[2];
            }
            $strs[] = $vals[$i + 2] . ',' . $vals[$i + 3] . ($a ? '(' . implode(',', $a) . ')' : '');
        }
        return $strs;
    }

    //------------------------------------------------------------------------------
    // Convert Pentax hex-based EV (modulo 8) to real number
    // Inputs: 0) value to convert
    // eg) 0x00 -> 0
    //     0x03 -> 0.33333
    //     0x04 -> 0.5
    //     0x05 -> 0.66666
    //     0x08 -> 1   ...  etc
    public static function PentaxEv($val)
    {
        $val = Perl::num($val);
        if (Perl::int($val) & 0x01) {
            $sign = $val < 0 ? -1 : 1;
            $frac = Perl::int($val * $sign) & 0x07;
            if ($frac == 0x03) {
                $val += $sign * ( 8 / 3 - $frac);
            } elseif ($frac == 0x05) {
                $val += $sign * (16 / 3 - $frac);
            }
        }
        return $val / 8;
    }

    //------------------------------------------------------------------------------
    // Encrypt or decrypt Pentax ShutterCount (symmetrical encryption) - PH
    // Inputs: 0) shutter count value, 1) ExifTool object ref
    // Returns: Encrypted or decrypted ShutterCount
    public static function CryptShutterCount($val, ExifTool $et)
    {
        // Pentax Date and Time values are used in the encryption
        if (!($et->PentaxDate and $et->PentaxTime and
            strlen($et->PentaxDate) == 4 and strlen($et->PentaxTime) >= 3)) return null;
        // get Date and Time as integers (after padding Time with a null byte)
        $date = Perl::unpack('N', $et->PentaxDate)[0];
        $time = Perl::unpack('N', $et->PentaxTime . "\0")[0];
        return Perl::int($val) ^ $date ^ (0xffffffff - $time);
    }
}
