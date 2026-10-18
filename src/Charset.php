<?php

//------------------------------------------------------------------------------
// File:         Charset.pm
//
// Description:  ExifTool character encoding routines
//
// Revisions:    2009/08/28 - P. Harvey created
//               2010/01/20 - P. Harvey complete re-write
//               2010/07/16 - P. Harvey added UTF-16 support
//
// Notes:        Charset lookups are generated using my convertCharset script
//               (PHP port: the lookups are generated in tables/vars/Charset/<Name>.php)
//------------------------------------------------------------------------------

namespace Image\ExifTool;

/**
 * Port of Image::ExifTool::Charset.
 */
final class Charset
{
    public static $VERSION = '1.11';

    /** character set tables we've loaded */
    private static array $charsetTable = [];

    /** lookup for converting Unicode to 1-byte character sets (built when needed) */
    private static array $unicode2byte = [];

    // bit flags for all supported character sets
    // (this number must be correct because it dictates the decoding algorithm!)
    //   0x001 = character set requires a translation module
    //   0x002 = inverse conversion not yet supported by Recompose()
    //   0x080 = some characters with codepoints in the range 0x00-0x7f are remapped
    //   0x100 = 1-byte fixed-width characters
    //   0x200 = 2-byte fixed-width characters
    //   0x400 = 4-byte fixed-width characters
    //   0x800 = 1- and 2-byte variable-width characters, or 1-byte
    //           fixed-width characters that map into multiple codepoints
    // Note: In its public interface, ExifTool can currently only support type 0x101
    //       and lower character sets because strings are only converted if they
    //       contain characters above 0x7f and there is no provision for specifying
    //       the byte order for input/output values
    public static array $csType = [
        'UTF8'         => 0x100,
        'ASCII'        => 0x100, // (treated like UTF8)
        'Arabic'       => 0x101,
        'Baltic'       => 0x101,
        'Cyrillic'     => 0x101,
        'Greek'        => 0x101,
        'Hebrew'       => 0x101,
        'Latin'        => 0x101,
        'Latin2'       => 0x101,
        'DOSLatinUS'   => 0x101,
        'DOSLatin1'    => 0x101,
        'DOSCyrillic'  => 0x101,
        'MacCroatian'  => 0x101,
        'MacCyrillic'  => 0x101,
        'MacGreek'     => 0x101,
        'MacIceland'   => 0x101,
        'MacLatin2'    => 0x101,
        'MacRoman'     => 0x101,
        'MacRomanian'  => 0x101,
        'MacTurkish'   => 0x101,
        'Thai'         => 0x101,
        'Turkish'      => 0x101,
        'Vietnam'      => 0x101,
        'MacArabic'    => 0x103, // (directional characters not supported)
        'PDFDoc'       => 0x181,
        'Unicode'      => 0x200, // (UCS2)
        'UCS2'         => 0x200,
        'UTF16'        => 0x200, // (UCS2 with surrogate pairs added)
        'Symbol'       => 0x201,
        'JIS'          => 0x201,
        'UCS4'         => 0x400, // (UTF32)
        'MacChineseCN' => 0x803,
        'MacChineseTW' => 0x803,
        'MacHebrew'    => 0x803, // (directional characters not supported)
        'MacKorean'    => 0x803,
        'MacRSymbol'   => 0x803,
        'MacThai'      => 0x803,
        'MacJapanese'  => 0x883,
        'ShiftJIS'     => 0x883,
    ];

    //------------------------------------------------------------------------------
    // Load character set module
    // Inputs: 0) Module name
    // Returns: Reference to lookup hash, or undef on error
    public static function LoadCharset($charset): ?array
    {
        $conv = self::$charsetTable[$charset] ?? null;
        if ($conv === null) {
            // load translation module
            $module = 'Image\\ExifTool\\Vars\\Charset\\' . $charset;
            if (preg_match('/^\w+$/', (string) $charset) && class_exists($module)) {
                $conv = self::$charsetTable[$charset] = $module::$map;
            }
        }
        return $conv;
    }

    //------------------------------------------------------------------------------
    // Does an array contain valid UTF-16 characters?
    // Inputs: 0) array reference to list of UCS-2 values
    // Returns: 0=invalid UTF-16, 1=valid UTF-16 with no surrogates, 2=valid UTF-16 with surrogates
    public static function IsUTF16(array $uni): int
    {
        $surrogate = null;
        foreach ($uni as $c) {
            $hiBits = ($c & 0xfc00);
            if ($hiBits == 0xfc00) {
                // check for invalid values in UTF-16
                if ($c == 0xffff or $c == 0xfffe or ($c >= 0xfdd0 and $c <= 0xfdef)) return 0;
            } elseif ($surrogate) {
                if ($hiBits != 0xdc00) return 0;
                $surrogate = 0;
            } else {
                if ($hiBits == 0xdc00) return 0;
                if ($hiBits == 0xd800) $surrogate = 1;
            }
        }
        if ($surrogate === null) return 1;
        if (!$surrogate) return 2;
        return 0;
    }

    //------------------------------------------------------------------------------
    // Decompose string with specified encoding into an array of integer code points
    // Inputs: 0) ExifTool object ref (or undef), 1) string, 2) character set name,
    //         3) optional byte order ('II','MM','Unknown' or undef to use ExifTool ordering)
    // Returns: Reference to array of Unicode values
    // Notes: Accepts any type of character set
    // - byte order only used for fixed-width 2-byte and 4-byte character sets
    // - byte order mark observed and then removed with UCS2 and UCS4
    // - no warnings are issued if ExifTool object is not provided
    // - sets ExifTool WrongByteOrder flag if byte order is Unknown and current order is wrong
    public static function Decompose(?ExifTool $et, $val, $charset, $byteOrder = null): array
    {
        $val = Perl::str($val);
        $type = self::$csType[$charset] ?? 0;
        $uni = [];
        $conv = null;

        if ($type & 0x001) {
            $conv = self::LoadCharset($charset);
            if (!$conv) {
                // (shouldn't happen)
                if ($et) $et->Warn("Invalid character set $charset");
                return $uni;    // error!
            }
        } elseif ($type == 0x100) {
            // convert ASCII and UTF8 (treat ASCII as UTF8)
            // handle warnings from malformed UTF-8
            // (Perl's unpack('C0U*') warns under -w, and the local $SIG{'__WARN__'} = \&SetWarning
            // leaves the last warning in $evalWarning, where an enclosing eval site finds it)
            ExifTool::$evalWarning = null;
            $uni = self::UnpackU($val, warning: $warning);
            if ($warning !== null) ExifTool::$evalWarning = $warning;
            // issue warning if we had errors
            if (ExifTool::$evalWarning and $et and !$et->WarnBadUTF8) {
                $et->Warn('Malformed UTF-8 character(s)');
                $et->WarnBadUTF8 = 1;
            }
            return $uni;        // all done!
        }
        if ($type & 0x100) {        // 1-byte fixed-width characters
            $uni = $val === '' ? [] : array_values(unpack('C*', $val));
            foreach ($uni as &$c) {
                if (isset($conv[$c])) $c = $conv[$c];
            }
            unset($c);
        } elseif ($type & 0x600) {  // 2-byte or 4-byte fixed-width characters
            $unknown = null;
            if (!$byteOrder) {
                $byteOrder = ExifTool::GetByteOrder();
            } elseif ($byteOrder === 'Unknown') {
                $byteOrder = ExifTool::GetByteOrder();
                $unknown = 1;
            }
            $fmt = $byteOrder === 'MM' ? 'n' : 'v';
            if ($type & 0x400) {    // 4-byte
                $fmt = strtoupper($fmt); // unpack as 'N*' or 'V*'
                // honour BOM if it exists
                if (preg_match('/^(\0\0\xfe\xff|\xff\xfe\0\0)/', $val, $m)) {
                    $val = substr($val, 4);
                    $fmt = $m[1] === "\0\0\xfe\xff" ? 'N' : 'V';
                }
                $unknown = null; // (byte order logic applies to 2-byte only)
            } elseif (preg_match('/^(\xfe\xff|\xff\xfe)/', $val, $m)) {
                $val = substr($val, 2);
                $fmt = $m[1] === "\xfe\xff" ? 'n' : 'v';
                $unknown = null;
            }
            // convert from UCS2 or UCS4
            $uni = self::UnpackFixed($fmt, $val);

            if (!$conv) {
                // no translation necessary
                if ($unknown) {
                    // check the byte order
                    $bh = $bl = [];
                    $zh = $zl = 0;
                    foreach ($uni as $c) {
                        $bh[$c >> 8] = 1;
                        $bl[$c & 0xff] = 1;
                        if (!($c & 0xff00)) ++$zh;
                        if (!($c & 0x00ff)) ++$zl;
                    }
                    // count the number of unique values in the hi and lo bytes
                    [$nh, $nl] = [count($bh), count($bl)];
                    // the byte with the greater number of unique values should be
                    // the low-order byte, otherwise the byte which is zero more
                    // often is likely the high-order byte
                    if ($nh > $nl or ($nh == $nl and $zl > $zh)) {
                        // we guessed wrong, so decode using the other byte order
                        $fmt = strtr($fmt, 'nvNV', 'vnVN');
                        $uni = self::UnpackFixed($fmt, $val);
                        $et->WrongByteOrder = 1;
                    }
                }
                // handle surrogate pairs of UTF-16
                if ($charset === 'UTF16') {
                    for ($i = 0; $i < count($uni) - 1; ++$i) {
                        if (!(($uni[$i] & 0xfc00) == 0xd800 and ($uni[$i + 1] & 0xfc00) == 0xdc00)) continue;
                        $cp = 0x10000 + (($uni[$i] & 0x3ff) << 10) + ($uni[$i + 1] & 0x3ff);
                        array_splice($uni, $i, 2, [$cp]);
                    }
                }
            } elseif ($unknown) {
                // count encoding errors as we do the translation
                $e1 = 0;
                foreach ($uni as &$c) {
                    if (isset($conv[$c])) {
                        $c = $conv[$c];
                        continue;
                    }
                    ++$e1;
                }
                unset($c);
                // try the other byte order if we had any errors
                if ($e1) {
                    $fmt = $byteOrder === 'MM' ? 'v' : 'n'; // (reversed)
                    $try = self::UnpackFixed($fmt, $val);
                    $e2 = 0;
                    foreach ($try as &$c) {
                        if (isset($conv[$c])) {
                            $c = $conv[$c];
                            continue;
                        }
                        ++$e2;
                    }
                    unset($c);
                    // use this byte order if there are fewer errors
                    if ($e2 < $e1) {
                        $et->WrongByteOrder = 1;
                        return $try;
                    }
                }
            } else {
                // translate any characters found in the lookup
                foreach ($uni as &$c) {
                    if (isset($conv[$c])) $c = $conv[$c];
                }
                unset($c);
            }
        } else {                    // variable-width characters
            // unpack into bytes
            $bytes = $val === '' ? [] : array_values(unpack('C*', $val));
            $n = count($bytes);
            for ($i = 0; $i < $n; ) {
                $ch = $bytes[$i++];
                $cv = $conv[$ch] ?? null;
                // pass straight through if no translation
                if (!$cv) {
                    $uni[] = $ch;
                    continue;
                }
                // byte translates into single Unicode character
                if (!is_array($cv)) {
                    $uni[] = $cv;
                    continue;
                }
                // byte maps into multiple Unicode characters
                if (array_is_list($cv)) {
                    array_push($uni, ...$cv);
                    continue;
                }
                // handle 2-byte character codes
                if ($i < $n) {
                    $ch = $bytes[$i++];
                    if (!empty($cv[$ch])) {
                        $cv = $cv[$ch];
                        if (!is_array($cv)) {
                            $uni[] = $cv;
                            continue;
                        }
                        array_push($uni, ...$cv);   // multiple Unicode characters
                    } else {
                        $uni[] = ord('?');  // encoding error
                        --$i;               // (unshift @bytes, $ch)
                    }
                } else {
                    $uni[] = ord('?');      // encoding error
                }
            }
        }
        return $uni;
    }

    //------------------------------------------------------------------------------
    // Convert array of code point integers into a string with specified encoding
    // Inputs: 0) ExifTool ref (or undef), 1) unicode character array ref,
    //         2) character set (note: not all types are supported)
    //         3) byte order ('MM' or 'II', multi-byte sets only, defaults to current byte order)
    // Returns: converted string (truncated at null character if it exists), empty on error
    // Notes: converts elements of input character array to new code points
    // - ExifTool ref may be undef provided $charset is defined
    public static function Recompose(?ExifTool $et, array $uni, $charset = null, $byteOrder = null): string
    {
        $conv = $inv = null;
        if (!$charset) $charset = $et->OPTIONS['Charset'];
        $csType = self::$csType[$charset] ?? 0;
        if ($csType == 0x100) {     // UTF8 (also treat ASCII as UTF8)
            // (Perl: pack('C0U*', @$uni))
            $outVal = self::PackU($uni);
            $z = strpos($outVal, "\0");     // truncate at null terminator
            return $z === false ? $outVal : substr($outVal, 0, $z);
        }
        // get references to forward and inverse lookup tables
        if ($csType & 0x801) {
            $conv = self::LoadCharset($charset);
            if (!$conv) {
                if ($et) $et->Warn("Missing charset $charset");
                return '';
            }
            $inv = self::$unicode2byte[$charset] ?? null;
            // generate inverse lookup if necessary
            if (!$inv) {
                if (!$csType or $csType & 0x802) {
                    if ($et) $et->Warn("Invalid destination charset $charset");
                    return '';
                }
                // prepare table to convert from Unicode to 1-byte characters
                $inv = [];
                foreach ($conv as $char => $code) {
                    if (!is_array($code)) $inv[$code] = $char;
                }
                self::$unicode2byte[$charset] = $inv;
            }
        }
        if ($csType & 0x100) {      // 1-byte fixed-width
            // convert to specified character set
            foreach ($uni as &$c) {
                if ($c < 0x80) continue;
                if (!empty($inv[$c])) {
                    $c = $inv[$c];
                    continue;
                }
                // our tables omit 1-byte characters with the same values as Unicode,
                // so pass them straight through after making sure there isn't a
                // different character with this byte value
                if ($c < 0x100 and empty($conv[$c])) continue;
                $c = ord('?');  // set invalid characters to '?'
                if ($et and !$et->EncodingError) {
                    $et->Warn("Some character(s) could not be encoded in $charset");
                    $et->EncodingError = 1;
                }
            }
            unset($c);
            // repack as an 8-bit string and truncate at null
            $outVal = '';
            foreach ($uni as $c) $outVal .= chr($c & 0xff);
            $z = strpos($outVal, "\0");
            if ($z !== false) $outVal = substr($outVal, 0, $z);
        } else {                    // 2-byte and 4-byte fixed-width
            // convert if required
            if ($inv) {
                foreach ($uni as &$c) {
                    if (!empty($inv[$c])) $c = $inv[$c];
                }
                unset($c);
            }
            // generate surrogate pairs of UTF-16
            if ($charset === 'UTF16') {
                for ($i = 0; $i < count($uni); ++$i) {
                    if (!($uni[$i] >= 0x10000 and $uni[$i] < 0x10ffff)) continue;
                    $t = $uni[$i] - 0x10000;
                    $w1 = 0xd800 + (($t >> 10) & 0x3ff);
                    $w2 = 0xdc00 + ($t & 0x3ff);
                    array_splice($uni, $i, 1, [$w1, $w2]);
                    ++$i;   // skip surrogate pair
                }
            }
            // pack as 2- or 4-byte integer in specified byte order
            if (!$byteOrder) $byteOrder = ExifTool::GetByteOrder();
            $fmt = $byteOrder === 'MM' ? 'n' : 'v';
            if ($csType & 0x400) $fmt = strtoupper($fmt);
            $mask = ($csType & 0x400) ? 0xffffffff : 0xffff;
            $outVal = '';
            foreach ($uni as $c) $outVal .= pack($fmt, ((int) $c) & $mask);
        }
        return $outVal;
    }

    //------------------------------------------------------------------------------
    // PHP helpers (Perl's unpack('n*'/'v*'/'N*'/'V*'), unpack('C0U*') and pack('C0U*'))

    /** @return list<int> */
    private static function UnpackFixed(string $fmt, string $val): array
    {
        $size = ($fmt === 'n' or $fmt === 'v') ? 2 : 4;
        $len = strlen($val) - strlen($val) % $size;
        return $len ? array_values(unpack("$fmt*", substr($val, 0, $len))) : [];
    }

    /**
     * Decode UTF-8 like Perl's unpack('C0U*'): a malformed sequence gives a 0 (and sets $bad, and
     * $warning to the last "Malformed UTF-8 character" warning that Perl gives under -w, without
     * the " at FILE line N." suffix).
     *
     * @return list<int>
     */
    public static function UnpackU(string $val, bool &$bad = false, ?string &$warning = null): array
    {
        static $min = [2 => 0x80, 3 => 0x800, 4 => 0x10000, 5 => 0x200000, 6 => 0x4000000, 7 => 0x80000000, 13 => 0x1000000000];
        // (bytes as Perl shows them in its warnings)
        $hex = static fn (string $bytes) => implode('', array_map(static fn ($c) => sprintf('\\x%02x', $c), unpack('C*', $bytes)));
        $mal = 'Malformed UTF-8 character: ';
        $uni = [];
        $len = strlen($val);
        for ($i = 0; $i < $len; ) {
            $ch = ord($val[$i]);
            if ($ch < 0x80) {
                $uni[] = $ch;
                ++$i;
                continue;
            }
            if ($ch < 0xc0) {           // unexpected continuation byte
                $uni[] = 0;
                $bad = true;
                $warning = $mal . $hex($val[$i]) . sprintf(' (unexpected continuation byte 0x%02x, with no preceding start byte) in unpack', $ch);
                ++$i;
                continue;
            }
            $n = match (true) {
                $ch < 0xe0 => 2, $ch < 0xf0 => 3, $ch < 0xf8 => 4, $ch < 0xfc => 5, $ch < 0xfe => 6, $ch == 0xfe => 7, default => 13,
            };
            // count the continuation bytes which follow
            for ($j = 1; $j < $n and $i + $j < $len and (ord($val[$i + $j]) & 0xc0) == 0x80; ++$j);
            if ($j < $n) {    // too short, or unexpected non-continuation byte
                $uni[] = 0;
                $bad = true;
                // (Perl warns for each problem: too short, non-continuation, then overlong start)
                $avail = min($len - $i, $n);
                $seq = $mal . $hex(substr($val, $i, $avail));
                $start = substr($val, $i, $j);
                $s1 = $j > 1 ? ord($start[1]) : 0x100;
                if ($ch == 0xc0 or $ch == 0xc1 or ($ch == 0xe0 and $s1 < 0xa0) or ($ch == 0xf0 and $s1 < 0x90) or
                    ($ch == 0xf8 and $s1 < 0x88) or ($ch == 0xfc and $s1 < 0x84) or ($ch == 0xfe and $s1 < 0x82) or
                    str_starts_with($start, "\xff\x80\x80\x80\x80\x80\x80"))
                {
                    $warning = "$seq (any UTF-8 sequence that starts with \"" . $hex($start) .
                        '" is overlong which can and should be represented with a different, shorter sequence) in unpack';
                } elseif ($i + $j < $len) {
                    $warning = sprintf('%s (unexpected non-continuation byte 0x%02x, %s after start byte 0x%02x; need %d bytes, got %d) in unpack',
                        $seq, ord($val[$i + $j]), $j == 1 ? 'immediately' : "$j bytes", $ch, $n, $j);
                } else {
                    $warning = "$seq (too short; $avail byte" . ($avail == 1 ? '' : 's') . " available, need $n) in unpack";
                }
                $i += $j;
                continue;
            }
            if ($n == 13) {
                // (13-byte sequence: 72 bits, of which Perl allows 63)
                if ((ord($val[$i + 1]) & 0x3f) or (ord($val[$i + 2]) & 0x38)) {
                    $uni[] = 0;
                    $bad = true;
                    $warning = $mal . $hex(substr($val, $i, $n)) . ' (overflows) in unpack';
                    $i += $n;
                    continue;
                }
                $cp = 0;
                $j = 2;
            } else {
                $cp = $n == 7 ? 0 : $ch & (0x7f >> $n);
                $j = 1;
            }
            for (; $j < $n; ++$j) {
                $cp = ($cp << 6) | (ord($val[$i + $j]) & 0x3f);
            }
            if ($cp < $min[$n]) {       // overlong
                $bad = true;
                $warning = $mal . $hex(substr($val, $i, $n)) . ' (overlong; instead use ' . $hex(self::PackU([$cp])) .
                    ' to represent ' . ($cp > 0x10ffff ? '0x' : 'U+') . sprintf($cp < 256 ? '%02X' : '%04X', $cp) . ') in unpack';
                $cp = 0;
            }
            $uni[] = $cp;
            $i += $n;
        }
        return $uni;
    }

    /** Encode code points as UTF-8 like Perl's pack('C0U*') (surrogates and large values allowed) */
    public static function PackU(array $uni): string
    {
        $out = '';
        foreach ($uni as $ch) {
            $ch = (int) $ch;
            if ($ch < 0x80) {
                $out .= chr($ch & 0xff);
            } elseif ($ch < 0x800) {
                $out .= chr(0xc0 | ($ch >> 6)) . chr(0x80 | ($ch & 0x3f));
            } elseif ($ch < 0x10000) {
                $out .= chr(0xe0 | ($ch >> 12)) . chr(0x80 | (($ch >> 6) & 0x3f)) . chr(0x80 | ($ch & 0x3f));
            } elseif ($ch < 0x200000) {
                $out .= chr(0xf0 | ($ch >> 18)) . chr(0x80 | (($ch >> 12) & 0x3f)) .
                        chr(0x80 | (($ch >> 6) & 0x3f)) . chr(0x80 | ($ch & 0x3f));
            } elseif ($ch < 0x4000000) {
                $out .= chr(0xf8 | ($ch >> 24)) . chr(0x80 | (($ch >> 18) & 0x3f)) . chr(0x80 | (($ch >> 12) & 0x3f)) .
                        chr(0x80 | (($ch >> 6) & 0x3f)) . chr(0x80 | ($ch & 0x3f));
            } elseif ($ch < 0x80000000) {
                $out .= chr(0xfc | (($ch >> 30) & 0x01)) . chr(0x80 | (($ch >> 24) & 0x3f)) . chr(0x80 | (($ch >> 18) & 0x3f)) .
                        chr(0x80 | (($ch >> 12) & 0x3f)) . chr(0x80 | (($ch >> 6) & 0x3f)) . chr(0x80 | ($ch & 0x3f));
            } else {
                // (Perl's extended UTF-8: 0xfe and 6 continuation bytes for 36 bits)
                $out .= "\xfe";
                for ($sh = 30; $sh >= 0; $sh -= 6) $out .= chr(0x80 | (($ch >> $sh) & 0x3f));
            }
        }
        return $out;
    }
}
