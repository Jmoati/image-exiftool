<?php

//------------------------------------------------------------------------------
// File:         WriteIPTC.pl
//
// Description:  Write IPTC meta information
//
// Revisions:    12/15/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::IPTC), mixed into class Image\ExifTool\IPTC: only the
// CHECK_PROC used when setting new values (CheckIPTC) is ported so far.
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
}
