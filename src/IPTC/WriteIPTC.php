<?php

//------------------------------------------------------------------------------
// File:         WriteIPTC.pl
//
// Description:  Write IPTC meta information
//
// Revisions:    12/15/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::IPTC), mixed into class Image\ExifTool\IPTC: only the
// subs used when setting new values (CheckIPTC, IptcDate, IptcTime, InverseDateOrTime,
// InvConvertPictureNumber) are ported so far.
//------------------------------------------------------------------------------

namespace Image\ExifTool\IPTC;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\TagInfo;

trait WriteIPTC
{
    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckIPTC(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = Perl::str(($tagInfo->Format ?: $tagInfo->Table['FORMAT']) ?: '');
        if (preg_match('/^int(\d+)/', $format, $m)) {
            $bytes = Perl::int(Perl::num($m[1] ?: 0) / 8);
            if ($bytes != 1 and $bytes != 2 and $bytes != 4) {
                return "Can't write $bytes-byte integer";
            }
            $val = $valPtr;
            if (!ExifTool::IsInt($val)) {
                if (!ExifTool::IsHex($val)) return 'Not an integer';
                $val = $valPtr = Perl::hex($val);
            }
            $val = (int) Perl::num($val);
            for ($n = 0; $n < $bytes; ++$n) $val >>= 8;
            if ($val) return "Value too large for $bytes-byte format";
        } elseif (preg_match('/^(string|digits|undef)\[?(\d+),?(\d*)\]?$/', $format, $m)) {
            [$fmt, $minlen, $maxlen] = [$m[1], $m[2], $m[3]];
            $len = strlen(Perl::str($valPtr));
            if ($fmt === 'digits') {
                if (!preg_match('/^\d*$/', Perl::str($valPtr))) return 'Non-numeric characters in value';
                if ($len < $minlen and $len) {
                    // left pad with zeros if necessary
                    $valPtr = str_repeat('0', $minlen - $len) . $valPtr;
                    $len = $minlen;
                }
            }
            if ($fmt !== 'string') {    // (must truncate strings later, after recoding)
                if (!$maxlen) $maxlen = $minlen;
                if ($len < $minlen) {
                    if (!$et->OPTIONS['IgnoreMinorErrors']) {
                        return "[Minor] String too short (minlen is $minlen)";
                    }
                    $et->CHECK_WARN = "String too short for IPTC:{$tagInfo->Name} (written anyway)";
                } elseif ($len > $maxlen and !$et->OPTIONS['IgnoreMinorErrors']) {
                    $et->CHECK_WARN = "[Minor] IPTC:{$tagInfo->Name} exceeds length limit (truncated)";
                    $valPtr = substr(Perl::str($valPtr), 0, (int) $maxlen);
                }
            }
        } else {
            return "Bad IPTC Format ($format)";
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // generate IPTC-format date
    // Inputs: 0) EXIF-format date string (YYYY:mm:dd) or date/time string
    // Returns: IPTC-format date string (YYYYmmdd), or undef and issue warning on error
    public static function IptcDate($val)
    {
        $re = '{^.*?(\d{4})[-:/.]?(\d{2})[-:/.]?(\d{2}).*}s';
        if (preg_match($re, Perl::str($val))) {
            $val = preg_replace($re, '$1$2$3', Perl::str($val), 1);
        } else {
            ExifTool::PerlWarn("Invalid date format (use YYYY:mm:dd)\n");
            $val = null;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // generate IPTC-format time
    // Inputs: 0) EXIF-format time string (HH:MM:SS[+/-HH:MM]) or date/time string
    // Returns: IPTC-format time string (HHMMSS+HHMM), or undef and issue warning on error
    public static function IptcTime($val)
    {
        if (preg_match('/(.*?)\b(\d{1,2})(:?)(\d{2})(:?)(\d{2})(\S*)\s*$/s', Perl::str($val), $m) and ($m[3] or !$m[5])) {
            $val = Perl::sprintf('%.2d%.2d%.2d', $m[2], $m[4], $m[6]);
            [$date, $tz] = [$m[1], $m[7]];
            if (preg_match('/([+-]\d{1,2}):?(\d{2})/', $tz, $mm)) {
                $tz = Perl::sprintf('%+.2d%.2d', $mm[1], $mm[2]);
            } elseif (preg_match('/Z/i', $tz)) {
                $tz = '+0000';  // UTC
            } else {
                // use local system timezone by default
                if ($date and preg_match('/^(\d{4}):(\d{2}):(\d{2})\s*$/', $date, $mm)) {
                    // we were given a date too, so determine the local timezone
                    // offset at the specified date/time
                    $d = [$mm[3], $mm[2] - 1, $mm[1]];
                    preg_match('/(\d{2})(\d{2})(\d{2})/', $val, $mm);
                    $tm = [$mm[3], $mm[2], $mm[1], ...$d];
                    $time = ExifTool::TimeLocal(...$tm);
                } else {
                    // it is difficult to get the proper local timezone offset for this
                    // time because the date tag is written separately.  (The offset may be
                    // different on a different date due to daylight savings time.)  In this
                    // case the best we can do easily is to use the current timezone offset.
                    $time = time();
                    $tm = ExifTool::PerlLocaltime($time);
                }
                $tz = str_replace(':', '', ExifTool::TimeZoneString($tm, $time));
            }
            $val .= $tz;
        } else {
            ExifTool::PerlWarn("Invalid time format (use HH:MM:SS[+/-HH:MM])\n");
            $val = null;    // time format error
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Inverse print conversion for IPTC date or time value
    // Inputs: 0) ExifTool ref, 1) IPTC date or 'now'
    // Returns: IPTC date
    public static function InverseDateOrTime(ExifTool $et, $val)
    {
        if (strtolower(Perl::str($val)) === 'now') return $et->TimeNow();
        return $val;
    }

    //------------------------------------------------------------------------------
    // Convert picture number
    // Inputs: 0) value
    // Returns: Converted value
    public static function InvConvertPictureNumber($val)
    {
        $val = preg_replace('/\(.*\)/', '', Perl::str($val));     // remove manufacturer description
        $val = str_replace(':', '', $val);                          // remove date separators
        $val = preg_replace('/[^0-9]/', ' ', $val);                  // turn remaining non-numbers to spaces
        $vals = Perl::split(' ', $val);
        if (count($vals) >= 4) {
            $val = Perl::pack('nNA8n', ...$vals);
        } elseif (preg_match('/unknown/i', $val)) {
            $val = str_repeat("\0", 16);
        } else {
            $val = null;
        }
        return $val;
    }
}
