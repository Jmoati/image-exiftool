<?php

declare(strict_types=1);

namespace Image\ExifTool;

/**
 * Perl built-ins whose PHP twins behave differently (see PORTING.md).
 */
final class Perl
{
    /** Perl's input record separator ($/), used by ReadLine() and chomp(); null = slurp mode */
    public static ?string $RS = "\n";

    /**
     * Perl stringification of a scalar (numbers use "%.15g", like Perl).
     */
    public static function str(mixed $v): string
    {
        if (\is_string($v)) {
            return $v;
        }
        if (\is_int($v)) {
            return (string) $v;
        }
        if (\is_float($v)) {
            if (is_nan($v)) {
                return 'NaN';
            }
            if (is_infinite($v)) {
                return $v < 0 ? '-Inf' : 'Inf';
            }
            if (0.0 === $v) {
                return '0';
            }

            return ($v < 0 ? '-' : '').self::fmtFloat(abs($v), 15, 'g', false);
        }
        if (null === $v || false === $v) {
            return '';
        }
        if (true === $v) {
            return '1';
        }
        if ($v instanceof ScalarRef) {
            return \sprintf('SCALAR(0x%x)', spl_object_id($v));
        }
        if (\is_array($v)) {
            return \sprintf('%s(0x%x)', array_is_list($v) ? 'ARRAY' : 'HASH', crc32(serialize(array_keys($v))));
        }
        if ($v instanceof \Stringable) {
            return (string) $v;
        }

        return \sprintf('%s(0x%x)', 'HASH', spl_object_id($v));
    }

    /**
     * Perl numification: leading number of a string ("12abc" => 12, "abc" => 0), never throws.
     */
    public static function num(mixed $v): int|float
    {
        if (\is_int($v) || \is_float($v)) {
            return $v;
        }
        if (null === $v || false === $v) {
            return 0;
        }
        if (true === $v) {
            return 1;
        }
        if (!\is_string($v)) {
            return $v instanceof ScalarRef ? spl_object_id($v) : 0;
        }
        if (!preg_match('/^\s*([-+]?)(?:(inf(?:inity)?|nan)|((?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?))/i', $v, $m)) {
            return 0;
        }
        if ('' !== $m[2]) {
            $n = 'n' === strtolower($m[2][0]) ? NAN : INF;

            return '-' === $m[1] ? -$n : $n;
        }
        $s = $m[1].$m[3];
        if (preg_match('/^[-+]?\d+$/', $s)) {
            $i = (int) $s;
            if ((string) $i === ltrim($s, '+') || ltrim(ltrim($s, '+-'), '0') === ltrim((string) abs($i), '0')) {
                return $i;
            }
        }

        return (float) $s;
    }

    /** Perl int(): truncate toward zero */
    public static function int(mixed $v): int|float
    {
        $n = self::num($v);
        if (\is_int($n)) {
            return $n;
        }
        if (is_nan($n) || is_infinite($n)) {
            return $n;
        }
        $t = $n < 0 ? ceil($n) : floor($n);

        return abs($t) < 9.2e18 ? (int) $t : $t;
    }

    /** Perl % operator: integer operands, result takes the sign of the right operand */
    public static function mod(mixed $a, mixed $b): int|float
    {
        $a = self::int($a);
        $b = self::int($b);
        if (0 == $b) {
            throw new \DivisionByZeroError('Illegal modulus zero');
        }
        if (\is_float($a) || \is_float($b)) {
            $r = fmod($a, $b);
        } else {
            $r = $a % $b;
        }
        if (0 != $r && (($r < 0) !== ($b < 0))) {
            $r += $b;
        }

        return $r;
    }

    /** Perl oct(): handles 0x, 0b, 0o prefixes */
    public static function oct(mixed $v): int|float
    {
        $s = strtolower(trim(str_replace('_', '', self::str($v))));
        if (preg_match('/^0?x([0-9a-f]*)/', $s, $m)) {
            return '' === $m[1] ? 0 : hexdec($m[1]);
        }
        if (preg_match('/^0?b([01]*)/', $s, $m)) {
            return '' === $m[1] ? 0 : bindec($m[1]);
        }
        if (preg_match('/^0?o?([0-7]*)/', $s, $m)) {
            return '' === $m[1] ? 0 : octdec($m[1]);
        }

        return 0;
    }

    /** Perl hex() */
    public static function hex(mixed $v): int|float
    {
        $s = preg_replace('/^0?x/i', '', trim(str_replace('_', '', self::str($v))));
        preg_match('/^[0-9a-fA-F]*/', $s, $m);

        return '' === $m[0] ? 0 : hexdec($m[0]);
    }

    /** Perl chr(): a byte below 256, else the UTF-8 encoded character */
    public static function chr(mixed $n): string
    {
        $n = (int) self::int($n);
        if ($n < 0) {
            $n = 0xFFFD;
        }

        return $n < 256 ? \chr($n) : (mb_chr($n, 'UTF-8') ?: "\u{FFFD}");
    }

    /** Perl index(): -1 when not found */
    public static function index(mixed $str, mixed $sub, int $pos = 0): int
    {
        $str = self::str($str);
        $sub = self::str($sub);
        $len = \strlen($str);
        if ($pos < 0) {
            $pos = 0;
        }
        if ($pos > $len) {
            $pos = $len;
        }
        $r = strpos($str, $sub, $pos);

        return false === $r ? -1 : $r;
    }

    /** Perl rindex(): -1 when not found, $pos is the highest starting position */
    public static function rindex(mixed $str, mixed $sub, ?int $pos = null): int
    {
        $str = self::str($str);
        $sub = self::str($sub);
        $len = \strlen($str);
        if (null === $pos || $pos > $len) {
            $pos = $len;
        }
        if ($pos < 0) {
            return '' === $sub ? 0 : -1;
        }
        $r = strrpos(substr($str, 0, $pos + \strlen($sub)), $sub);

        return false === $r ? -1 : $r;
    }

    /** Perl chomp() on a string: removes one trailing $/ */
    public static function chomp(?string &$s): int
    {
        $rs = self::$RS;
        if (null === $s || null === $rs || '' === $rs || !str_ends_with($s, $rs)) {
            return 0;
        }
        $s = substr($s, 0, -\strlen($rs));

        return \strlen($rs);
    }

    /**
     * Perl split(). $pattern is a PCRE with delimiters, or ' ' for awk mode.
     *
     * @return list<string>
     */
    public static function split(string $pattern, mixed $str, int $limit = 0): array
    {
        if (null === $str) {
            return [];
        }
        $str = self::str($str);
        if (' ' === $pattern) {
            $str = ltrim($str, " \t\n\r\f\v");
            $pattern = '/\s+/';
        }
        if ('' === $str) {
            return [];
        }
        $fields = [];
        $pos = 0;
        $start = 0;
        $len = \strlen($str);
        while ($pos <= $len) {
            if ($limit > 0 && \count($fields) >= $limit - 1) {
                break;
            }
            if (!preg_match($pattern, $str, $m, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $mStart = $m[0][1];
            $mLen = \strlen($m[0][0]);
            if (0 === $mLen) {
                // a zero-length match splits between characters, never at the start
                if ($mStart >= $len) {
                    break;
                }
                if ($mStart === $start) {
                    $mStart = $start + 1;
                    if (!preg_match($pattern, $str, $m, PREG_OFFSET_CAPTURE, $mStart) || $m[0][1] >= $len) {
                        break;
                    }
                    $mStart = $m[0][1];
                    $mLen = \strlen($m[0][0]);
                }
            }
            $fields[] = substr($str, $start, $mStart - $start);
            for ($i = 1, $n = \count($m); $i < $n; ++$i) {
                $fields[] = -1 === $m[$i][1] ? null : $m[$i][0];
            }
            $start = $mStart + $mLen;
            $pos = $mLen ? $start : $start;
            if (0 === $mLen && $pos === $mStart) {
                $pos = $start;
            }
        }
        $fields[] = substr($str, $start);
        if (0 === $limit) {
            while ($fields && (null === end($fields) || '' === end($fields))) {
                array_pop($fields);
            }
        }

        return $fields;
    }

    /**
     * Perl tr/// (ranges, \x escapes, flags c d s).
     *
     * @return array{string, int} translated string, number of matched characters
     */
    public static function tr(mixed $str, string $search, string $replace = '', string $flags = ''): array
    {
        $str = self::str($str);
        $from = self::trList($search);
        $to = self::trList($replace);
        $complement = str_contains($flags, 'c');
        $delete = str_contains($flags, 'd');
        $squeeze = str_contains($flags, 's');
        if ($complement) {
            $in = array_fill_keys($from, true);
            $from = [];
            for ($i = 0; $i < 256; ++$i) {
                if (!isset($in[\chr($i)])) {
                    $from[] = \chr($i);
                }
            }
        }
        if ([] === $to && !$delete) {
            $to = $from;
        } elseif (!$delete && \count($to) < \count($from)) {
            $to = array_pad($to, \count($from), end($to));
        }
        $map = [];
        foreach ($from as $i => $c) {
            if (!\array_key_exists($c, $map)) {
                $map[$c] = $to[$i] ?? null; // null = delete
            }
        }
        $out = '';
        $count = 0;
        $last = null;
        for ($i = 0, $n = \strlen($str); $i < $n; ++$i) {
            $c = $str[$i];
            if (!\array_key_exists($c, $map)) {
                $out .= $c;
                $last = null;
                continue;
            }
            ++$count;
            $r = $map[$c];
            if (null === $r) {
                continue;
            }
            if ($squeeze && $last === $r) {
                continue;
            }
            $out .= $r;
            $last = $r;
        }

        return [$out, $count];
    }

    /** @return list<string> */
    private static function trList(string $s): array
    {
        $chars = [];
        $n = \strlen($s);
        for ($i = 0; $i < $n; ++$i) {
            $c = $s[$i];
            if ('\\' === $c && $i + 1 < $n) {
                $e = $s[++$i];
                if ('x' === $e) {
                    if ('{' === ($s[$i + 1] ?? '')) {
                        $end = strpos($s, '}', $i);
                        $c = \chr(hexdec(substr($s, $i + 2, $end - $i - 2)) & 0xFF);
                        $i = $end;
                    } else {
                        preg_match('/^[0-9a-fA-F]{0,2}/', substr($s, $i + 1), $m);
                        $c = \chr('' === $m[0] ? 0 : hexdec($m[0]));
                        $i += \strlen($m[0]);
                    }
                } elseif (ctype_digit($e)) {
                    preg_match('/^[0-7]{1,3}/', substr($s, $i), $m);
                    $c = \chr(octdec($m[0]) & 0xFF);
                    $i += \strlen($m[0]) - 1;
                } else {
                    $c = match ($e) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'f' => "\f", 'e' => "\e", 'a' => "\x07", '0' => "\0",
                        default => $e,
                    };
                }
            }
            if ('-' === ($s[$i + 1] ?? '') && $i + 2 < $n) {
                // range: resolve the end character (may be escaped)
                $rest = self::trList(substr($s, $i + 2));
                $endChar = $rest[0];
                $consumed = self::trFirstLen(substr($s, $i + 2));
                for ($o = \ord($c), $e = \ord($endChar); $o <= $e; ++$o) {
                    $chars[] = \chr($o);
                }
                $i += 1 + $consumed;
                continue;
            }
            $chars[] = $c;
        }

        return $chars;
    }

    private static function trFirstLen(string $s): int
    {
        if ('\\' !== ($s[0] ?? '')) {
            return 1;
        }
        if (preg_match('/^\\\\(x\{[0-9a-fA-F]+\}|x[0-9a-fA-F]{0,2}|[0-7]{1,3}|.)/s', $s, $m)) {
            return \strlen($m[0]);
        }

        return 1;
    }

    /**
     * Perl sprintf().
     */
    public static function sprintf(mixed $format, mixed ...$args): string
    {
        $format = self::str($format);
        $out = '';
        $argi = 0;
        $len = \strlen($format);
        $i = 0;
        while ($i < $len) {
            $p = strpos($format, '%', $i);
            if (false === $p) {
                $out .= substr($format, $i);
                break;
            }
            $out .= substr($format, $i, $p - $i);
            if (!preg_match('/\G%(?:(\d+)\$)?([-+ 0#]*)(\*?v)?(\*(?:\d+\$)?|\d+)?(?:\.(\*(?:\d+\$)?|\d*))?(?:hh|h|ll|l|q|L|V|z|t|j)?([%csduoxXeEfFgGbBiDUOn])/A', $format, $m, PREG_UNMATCHED_AS_NULL, $p)) {
                // invalid conversion: output as-is (Perl does the same)
                $out .= '%';
                $i = $p + 1;
                continue;
            }
            $i = $p + \strlen($m[0]);
            $conv = $m[6];
            if ('%' === $conv) {
                $out .= '%';
                continue;
            }
            $flags = $m[2] ?? '';
            $width = $m[4] ?? '';
            $prec = $m[5];
            if ('' !== $width && '*' === $width[0]) {
                $w = (int) self::int('*' === $width ? ($args[$argi++] ?? 0) : ($args[(int) substr($width, 1) - 1] ?? 0));
                if ($w < 0) {
                    $flags .= '-';
                    $w = -$w;
                }
                $width = (string) $w;
            }
            if (null !== $prec) {
                if ('' === $prec) {
                    $prec = '0';
                } elseif ('*' === $prec[0]) {
                    $pv = (int) self::int('*' === $prec ? ($args[$argi++] ?? 0) : ($args[(int) substr($prec, 1) - 1] ?? 0));
                    $prec = $pv < 0 ? null : (string) $pv;
                }
            }
            $arg = null !== $m[1] ? ($args[(int) $m[1] - 1] ?? null) : ($args[$argi++] ?? null);
            if (null !== $m[3]) {
                // vector flag: format each character ordinal, joined by '.'
                $parts = [];
                $s = self::str($arg);
                for ($k = 0, $n = \strlen($s); $k < $n; ++$k) {
                    $parts[] = self::fmtOne($conv, \ord($s[$k]), '', '', null);
                }
                $out .= self::pad(implode('.', $parts), $flags, $width, false);
                continue;
            }
            $out .= self::fmtOne($conv, $arg, $flags, $width, $prec);
        }

        return $out;
    }

    private static function fmtOne(string $conv, mixed $arg, string $flags, string $width, ?string $prec): string
    {
        $plus = str_contains($flags, '+') ? '+' : (str_contains($flags, ' ') ? ' ' : '');
        switch ($conv) {
            case 'c':
                $n = self::num($arg);
                if (\is_float($n) && (is_nan($n) || is_infinite($n))) {
                    throw new \RuntimeException('Cannot printf '.(is_nan($n) ? 'NaN' : ($n < 0 ? '-Inf' : 'Inf'))." with 'c'");
                }

                return self::pad(self::chr($arg), $flags, $width, false);
            case 's':
                $s = self::str($arg);
                if (null !== $prec) {
                    $s = substr($s, 0, (int) $prec);
                }

                return self::pad($s, $flags, $width, false);
            case 'd':
            case 'i':
            case 'D':
                $n = self::int($arg);
                if (\is_float($n) && (is_nan($n) || is_infinite($n))) {
                    return self::infNan($n, $flags, $width);
                }
                $n = self::toIV($n);
                $s = PHP_INT_MIN === $n ? '9223372036854775808' : (string) abs($n);
                if (null !== $prec) {
                    $s = ('0' === $prec && 0 === $n) ? '' : str_pad($s, (int) $prec, '0', STR_PAD_LEFT);
                }

                return self::pad($s, $flags, $width, null === $prec, $n < 0 ? '-' : $plus);
            case 'u':
            case 'U':
            case 'o':
            case 'O':
            case 'x':
            case 'X':
            case 'b':
            case 'B':
                $n = self::int($arg);
                if (\is_float($n) && (is_nan($n) || is_infinite($n))) {
                    return self::infNan($n, $flags, $width);
                }
                $n = self::toIV($n);
                $s = match ($conv) {
                    'u', 'U' => \sprintf('%u', $n),
                    'o', 'O' => \sprintf('%o', $n),
                    'x' => \sprintf('%x', $n),
                    'X' => \sprintf('%X', $n),
                    default => \sprintf('%b', $n),
                };
                if (null !== $prec) {
                    $s = ('0' === $prec && 0 === $n) ? '' : str_pad($s, (int) $prec, '0', STR_PAD_LEFT);
                }
                $prefix = '';
                if (str_contains($flags, '#') && 0 !== $n) {
                    $prefix = match ($conv) {
                        'x' => '0x', 'X' => '0X', 'b' => '0b', 'B' => '0B', 'o', 'O' => '0' === ($s[0] ?? '') ? '' : '0', default => '',
                    };
                }

                return self::pad($s, $flags, $width, null === $prec, $prefix);
            case 'e':
            case 'E':
            case 'f':
            case 'F':
            case 'g':
            case 'G':
                $f = (float) self::num($arg);
                $negative = $f < 0 || (0.0 === $f && fdiv(1, $f) < 0);
                $sign = $negative ? '-' : $plus;
                if (is_nan($f) || is_infinite($f)) {
                    return self::infNan($f, $flags, $width);
                }
                $s = self::fmtFloat(abs($f), null === $prec ? 6 : (int) $prec, strtolower($conv), str_contains($flags, '#'));
                if (ctype_upper($conv)) {
                    $s = strtoupper($s);
                }

                return self::pad($s, $flags, $width, true, $sign);
        }

        return '';
    }

    /** Perl's output for Inf/NaN in any numeric conversion: no precision, '0' pads on the far left */
    private static function infNan(float $f, string $flags, string $width): string
    {
        $s = is_nan($f) ? 'NaN' : ($f < 0 ? '-Inf' : (str_contains($flags, '+') || str_contains($flags, ' ') ? '+Inf' : 'Inf'));
        $w = '' === $width ? 0 : (int) $width;
        if (\strlen($s) >= $w) {
            return $s;
        }
        if (str_contains($flags, '-')) {
            return str_pad($s, $w);
        }

        return str_repeat(str_contains($flags, '0') ? '0' : ' ', $w - \strlen($s)).$s;
    }

    /** cast a Perl numeric value to a 64-bit IV the way Perl's sprintf does */
    private static function toIV(int|float $n): int
    {
        if (\is_int($n)) {
            return $n;
        }
        if (is_nan($n)) {
            return 0;
        }
        if ($n >= 1.8446744073709552E19) {
            return -1;
        }
        if ($n > PHP_INT_MAX) {
            return (int) ($n - 1.8446744073709552E19);
        }
        if ($n <= -9.2233720368547758E18) {
            return PHP_INT_MIN;
        }

        return (int) $n;
    }

    /**
     * Format a non-negative finite float like C printf with conversion e, f or g.
     */
    private static function fmtFloat(float $f, int $prec, string $conv, bool $alt): string
    {
        if ('f' === $conv) {
            return \sprintf('%.'.$prec.'F', $f);
        }
        if ('e' === $conv) {
            return self::fmtExp($f, $prec, $alt);
        }
        // %g
        if (0 === $prec) {
            $prec = 1;
        }
        if (0.0 === $f) {
            $x = 0;
        } else {
            $e = \sprintf('%.'.($prec - 1).'e', $f);
            $x = (int) substr($e, strpos($e, 'e') + 1);
        }
        if ($x < -4 || $x >= $prec) {
            $s = self::fmtExp($f, $prec - 1, $alt);
            if (!$alt) {
                $s = (string) preg_replace('/\.?0+(?=e)/', '', $s);
            }
        } else {
            $s = \sprintf('%.'.max(0, $prec - 1 - $x).'F', $f);
            if (!$alt && str_contains($s, '.')) {
                $s = rtrim(rtrim($s, '0'), '.');
            }
        }

        return $s;
    }

    private static function fmtExp(float $f, int $prec, bool $alt): string
    {
        $s = \sprintf('%.'.$prec.'e', $f);
        $epos = strpos($s, 'e');
        $mant = substr($s, 0, $epos);
        $exp = (int) substr($s, $epos + 1);
        if ($alt && !str_contains($mant, '.')) {
            $mant .= '.';
        }

        return $mant.'e'.($exp < 0 ? '-' : '+').str_pad((string) abs($exp), 2, '0', STR_PAD_LEFT);
    }

    private static function pad(string $s, string $flags, string $width, bool $zeroOk, string $prefix = ''): string
    {
        $w = '' === $width ? 0 : (int) $width;
        $total = \strlen($prefix) + \strlen($s);
        if ($total >= $w) {
            return $prefix.$s;
        }
        if (str_contains($flags, '-')) {
            return $prefix.$s.str_repeat(' ', $w - $total);
        }
        if ($zeroOk && str_contains($flags, '0')) {
            return $prefix.str_repeat('0', $w - $total).$s;
        }

        return str_repeat(' ', $w - $total).$prefix.$s;
    }

    /**
     * Perl unpack() with Perl template syntax. Returns the list of values.
     *
     * @return list<mixed>
     */
    public static function unpack(string $template, mixed $data): array
    {
        $data = self::str($data);
        $pos = 0;
        $out = [];
        self::$unpackC0 = false;
        self::unpackGroup(self::parseTemplate($template), $data, $pos, 0, $out);

        return $out;
    }

    /**
     * @return list<array{0:string,1:int|string|null,2:string,3?:list<mixed>}> code, count ('*', int or null), modifiers, [group]
     */
    private static function parseTemplate(string $t): array
    {
        $items = [];
        $i = 0;
        $n = \strlen($t);
        while ($i < $n) {
            $c = $t[$i];
            if (ctype_space($c)) {
                ++$i;
                continue;
            }
            if ('#' === $c) {
                $e = strpos($t, "\n", $i);
                $i = false === $e ? $n : $e + 1;
                continue;
            }
            if ('(' === $c) {
                $depth = 1;
                $j = $i + 1;
                while ($j < $n && $depth) {
                    if ('(' === $t[$j]) {
                        ++$depth;
                    } elseif (')' === $t[$j]) {
                        --$depth;
                    }
                    ++$j;
                }
                $sub = self::parseTemplate(substr($t, $i + 1, $j - $i - 2));
                $i = $j;
                [$count, $mods, $i] = self::parseCount($t, $i);
                $items[] = ['(', $count ?? 1, $mods, $sub];
                continue;
            }
            ++$i;
            [$count, $mods, $i] = self::parseCount($t, $i);
            if ('/' === ($t[$i] ?? '')) {
                // length-prefixed item: this item gives the count of the next one
                ++$i;
                while ($i < $n && ctype_space($t[$i])) {
                    ++$i;
                }
                $next = $t[$i++];
                [$ncount, $nmods, $i] = self::parseCount($t, $i);
                $items[] = ['/', $count, $mods, [[$c, $count, $mods], [$next, $ncount, $nmods]]];
                continue;
            }
            $items[] = [$c, $count, $mods];
        }

        return $items;
    }

    /** @return array{0:int|string|null,1:string,2:int} */
    private static function parseCount(string $t, int $i): array
    {
        $mods = '';
        while (isset($t[$i]) && str_contains('<>!', $t[$i])) {
            $mods .= $t[$i++];
        }
        if ('*' === ($t[$i] ?? '')) {
            return ['*', $mods, $i + 1];
        }
        if ('[' === ($t[$i] ?? '')) {
            $e = strpos($t, ']', $i);
            $inner = substr($t, $i + 1, $e - $i - 1);

            return [ctype_digit($inner) ? (int) $inner : self::templateSize($inner), $mods, $e + 1];
        }
        if (preg_match('/\G\d+/', $t, $m, 0, $i)) {
            return [(int) $m[0], $mods, $i + \strlen($m[0])];
        }

        return [null, $mods, $i];
    }

    private static function templateSize(string $tmpl): int
    {
        return \strlen(self::pack($tmpl));
    }

    private const array SIZES = [
        'c' => 1, 'C' => 1, 'W' => 1, 's' => 2, 'S' => 2, 'n' => 2, 'v' => 2,
        'l' => 4, 'L' => 4, 'N' => 4, 'V' => 4, 'i' => 4, 'I' => 4, 'f' => 4,
        'q' => 8, 'Q' => 8, 'j' => 8, 'J' => 8, 'd' => 8, 'F' => 8,
    ];

    /** C0 mode of unpack: U decodes UTF-8 sequences (otherwise it returns bytes, as Perl does on byte strings) */
    private static bool $unpackC0 = false;

    /** @param list<array<int, mixed>> $items @param list<mixed> $out */
    private static function unpackGroup(array $items, string $data, int &$pos, int $groupStart, array &$out): void
    {
        $len = \strlen($data);
        foreach ($items as $item) {
            [$code, $count, $mods] = $item;
            switch ($code) {
                case '(':
                    // (a numeric count repeats the group even past the end of the data, like Perl)
                    $reps = '*' === $count ? PHP_INT_MAX : $count;
                    for ($r = 0; $r < $reps && ('*' !== $count || $pos < $len); ++$r) {
                        self::unpackGroup($item[3], $data, $pos, $pos, $out);
                    }
                    break;
                case '/':
                    $tmp = [];
                    self::unpackGroup([$item[3][0]], $data, $pos, $groupStart, $tmp);
                    $n = (int) ($tmp[0] ?? 0);
                    [$ncode, , $nmods] = $item[3][1];
                    self::unpackGroup([[$ncode, $n, $nmods]], $data, $pos, $groupStart, $out);
                    break;
                case 'a':
                case 'A':
                case 'Z':
                    $n = '*' === $count ? $len - $pos : ($count ?? 1);
                    $s = (string) substr($data, $pos, max(0, $n));
                    if ('Z' === $code) {
                        $z = strpos($s, "\0");
                        if (false !== $z) {
                            $pos += '*' === $count ? $z + 1 : $n;
                            $out[] = substr($s, 0, $z);
                            break;
                        }
                    } elseif ('A' === $code) {
                        $s = rtrim($s, " \0\t\n\r\f");
                    }
                    $pos += \strlen((string) substr($data, $pos, max(0, $n)));
                    $out[] = $s;
                    break;
                case 'b':
                case 'B':
                    $bits = '*' === $count ? ($len - $pos) * 8 : ($count ?? 1);
                    $nbytes = (int) ceil($bits / 8);
                    $chunk = (string) substr($data, $pos, $nbytes);
                    $s = '';
                    for ($k = 0, $kn = \strlen($chunk); $k < $kn; ++$k) {
                        $byte = str_pad(decbin(\ord($chunk[$k])), 8, '0', STR_PAD_LEFT);
                        $s .= 'b' === $code ? strrev($byte) : $byte;
                    }
                    $out[] = substr($s, 0, $bits);
                    $pos += \strlen($chunk);
                    break;
                case 'h':
                case 'H':
                    $nyb = '*' === $count ? ($len - $pos) * 2 : ($count ?? 1);
                    $nbytes = (int) ceil($nyb / 2);
                    $chunk = (string) substr($data, $pos, $nbytes);
                    $hex = bin2hex($chunk);
                    if ('h' === $code) {
                        $hex = implode('', array_map(strrev(...), str_split($hex, 2) ?: []));
                    }
                    $out[] = substr($hex, 0, $nyb);
                    $pos += \strlen($chunk);
                    break;
                case 'x':
                    $pos += '*' === $count ? 0 : ($count ?? 1);
                    if ($pos > $len) {
                        throw new \RuntimeException("'x' outside of string in unpack");
                    }
                    break;
                case 'X':
                    $pos -= '*' === $count ? 0 : ($count ?? 1);
                    if ($pos < 0) {
                        throw new \RuntimeException("'X' outside of string in unpack");
                    }
                    break;
                case '@':
                    $pos = $groupStart + ('*' === $count ? 0 : ($count ?? 0));
                    if ($pos > $len) {
                        throw new \RuntimeException("'@' outside of string in unpack");
                    }
                    break;
                case 'U':
                    if (0 === $count) {
                        self::$unpackC0 = false;
                        break;
                    }
                    $reps = '*' === $count ? PHP_INT_MAX : ($count ?? 1);
                    for ($r = 0; $r < $reps && $pos < $len; ++$r) {
                        $b = \ord($data[$pos]);
                        if (!self::$unpackC0) {
                            $out[] = $b;
                            ++$pos;
                            continue;
                        }
                        $n = $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4));
                        $ch = substr($data, $pos, $n);
                        $cp = mb_ord($ch, 'UTF-8');
                        $out[] = false === $cp ? $b : $cp;
                        $pos += false === $cp ? 1 : $n;
                    }
                    break;
                case 'w':
                    $reps = '*' === $count ? PHP_INT_MAX : ($count ?? 1);
                    for ($r = 0; $r < $reps && $pos < $len; ++$r) {
                        $v = 0;
                        do {
                            $b = \ord($data[$pos++]);
                            $v = ($v << 7) | ($b & 0x7F);
                        } while (($b & 0x80) && $pos < $len);
                        $out[] = $v;
                    }
                    break;
                case 'u':
                    $s = substr($data, $pos);
                    $res = '';
                    foreach (preg_split('/\n/', $s) ?: [] as $line) {
                        if ('' === $line) {
                            continue;
                        }
                        $n = (\ord($line[0]) - 32) & 0x3F;
                        if (0 === $n) {
                            break;
                        }
                        $bytes = '';
                        for ($k = 1; $k + 3 < \strlen($line) + 3 && \strlen($bytes) < $n; $k += 4) {
                            $q = [];
                            for ($j = 0; $j < 4; ++$j) {
                                $q[] = (\ord($line[$k + $j] ?? ' ') - 32) & 0x3F;
                            }
                            $bytes .= \chr(($q[0] << 2) | ($q[1] >> 4)).\chr((($q[1] & 0xF) << 4) | ($q[2] >> 2)).\chr((($q[2] & 3) << 6) | $q[3]);
                        }
                        $res .= substr($bytes, 0, $n);
                    }
                    $out[] = $res;
                    $pos = $len;
                    break;
                default:
                    if ('C' === $code && 0 === $count) {
                        self::$unpackC0 = true;
                        break;
                    }
                    $size = self::SIZES[$code] ?? null;
                    if (null === $size) {
                        throw new \InvalidArgumentException("unpack: unsupported template code '$code'");
                    }
                    $reps = '*' === $count ? intdiv(max(0, $len - $pos), $size) : ($count ?? 1);
                    for ($r = 0; $r < $reps; ++$r) {
                        if ($pos + $size > $len) {
                            break 2;
                        }
                        $out[] = self::unpackNum($code, $mods, substr($data, $pos, $size));
                        $pos += $size;
                    }
            }
        }
    }

    private static function unpackNum(string $code, string $mods, string $b): int|float|string
    {
        $big = str_contains($mods, '>');
        $little = str_contains($mods, '<');
        $signed = str_contains($mods, '!');
        switch ($code) {
            case 'c':
                $v = \ord($b);

                return $v > 127 ? $v - 256 : $v;
            case 'C':
            case 'W':
                return \ord($b);
            case 'n':
                $v = unpack('n', $b)[1];

                return $signed && $v > 0x7FFF ? $v - 0x10000 : $v;
            case 'v':
                $v = unpack('v', $b)[1];

                return $signed && $v > 0x7FFF ? $v - 0x10000 : $v;
            case 'N':
                $v = unpack('N', $b)[1];

                return $signed && $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
            case 'V':
                $v = unpack('V', $b)[1];

                return $signed && $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
        }
        if ($big) {
            $b = strrev($b); // native (little-endian) from here on
        } elseif (!$little && PHP_INT_SIZE && 1 !== unpack('S', "\x01\x00")[1]) {
            $b = strrev($b);
        }
        switch ($code) {
            case 's':
                $v = unpack('v', $b)[1];

                return $v > 0x7FFF ? $v - 0x10000 : $v;
            case 'S':
                return unpack('v', $b)[1];
            case 'l':
            case 'i':
                $v = unpack('V', $b)[1];

                return $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
            case 'L':
            case 'I':
                return unpack('V', $b)[1];
            case 'q':
            case 'j':
                return unpack('P', $b)[1];
            case 'Q':
            case 'J':
                $v = unpack('P', $b)[1];

                // above PHP_INT_MAX: keep the exact decimal digits, like Perl's UV
                return $v < 0 ? \sprintf('%u', $v) : $v;
            case 'f':
                return unpack('g', $b)[1];
            case 'd':
            case 'F':
                return unpack('e', $b)[1];
        }
        throw new \InvalidArgumentException("unpack: unsupported template code '$code'");
    }

    /**
     * Perl pack() with Perl template syntax.
     */
    public static function pack(string $template, mixed ...$args): string
    {
        $out = '';
        $ai = 0;
        self::packGroup(self::parseTemplate($template), $args, $ai, $out);

        return $out;
    }

    /** @param list<array<int, mixed>> $items @param list<mixed> $args */
    private static function packGroup(array $items, array $args, int &$ai, string &$out): void
    {
        foreach ($items as $item) {
            [$code, $count, $mods] = $item;
            switch ($code) {
                case '(':
                    $reps = '*' === $count ? PHP_INT_MAX : $count;
                    for ($r = 0; $r < $reps && $ai < \count($args); ++$r) {
                        self::packGroup($item[3], $args, $ai, $out);
                    }
                    break;
                case 'a':
                case 'A':
                case 'Z':
                    $s = self::str($args[$ai++] ?? '');
                    if ('*' === $count) {
                        $out .= $s.('Z' === $code ? "\0" : '');
                    } else {
                        $n = $count ?? 1;
                        $s = substr($s, 0, 'Z' === $code ? max(0, $n - 1) : $n);
                        $out .= str_pad($s, $n, 'A' === $code ? ' ' : "\0");
                    }
                    break;
                case 'H':
                case 'h':
                    // like Perl: every character gives a nybble (letters: (c+9)&15, others: c&15)
                    $s = self::str($args[$ai++] ?? '');
                    $n = '*' === $count ? \strlen($s) : ($count ?? 1);
                    $len = \strlen($s);
                    for ($k = 0; $k < $n; $k += 2) {
                        $hi = $k < $len ? self::nybble($s[$k]) : 0;
                        $lo = $k + 1 < $n && $k + 1 < $len ? self::nybble($s[$k + 1]) : 0;
                        $out .= \chr('H' === $code ? ($hi << 4) | $lo : ($lo << 4) | $hi);
                    }
                    break;
                case 'b':
                case 'B':
                    $s = self::str($args[$ai++] ?? '');
                    if ('*' !== $count) {
                        $s = substr(str_pad($s, $count ?? 1, '0'), 0, $count ?? 1);
                    }
                    foreach (str_split(str_pad($s, (int) ceil(\strlen($s) / 8) * 8, '0'), 8) ?: [] as $byte) {
                        $out .= \chr(bindec('b' === $code ? strrev($byte) : $byte));
                    }
                    break;
                case 'x':
                    $out .= str_repeat("\0", '*' === $count ? 0 : ($count ?? 1));
                    break;
                case 'X':
                    $out = substr($out, 0, max(0, \strlen($out) - ('*' === $count ? 0 : ($count ?? 1))));
                    break;
                case '@':
                    $n = '*' === $count ? \strlen($out) : ($count ?? 0);
                    $out = \strlen($out) > $n ? substr($out, 0, $n) : str_pad($out, $n, "\0");
                    break;
                case 'U':
                case 'W':
                    $reps = '*' === $count ? \count($args) - $ai : ($count ?? 1);
                    for ($r = 0; $r < $reps; ++$r) {
                        $cp = (int) self::int($args[$ai++] ?? 0);
                        $out .= 'U' === $code ? self::chr($cp >= 0x80 ? $cp : $cp) : \chr($cp & 0xFF);
                        if ('U' === $code && $cp >= 0x80 && $cp < 0x100) {
                            $out = substr($out, 0, -1).mb_chr($cp, 'UTF-8');
                        }
                    }
                    break;
                case 'w':
                    $reps = '*' === $count ? \count($args) - $ai : ($count ?? 1);
                    for ($r = 0; $r < $reps; ++$r) {
                        $v = (int) self::int($args[$ai++] ?? 0);
                        $s = \chr($v & 0x7F);
                        while ($v >>= 7) {
                            $s = \chr(0x80 | ($v & 0x7F)).$s;
                        }
                        $out .= $s;
                    }
                    break;
                default:
                    if (!isset(self::SIZES[$code])) {
                        throw new \InvalidArgumentException("pack: unsupported template code '$code'");
                    }
                    $reps = '*' === $count ? \count($args) - $ai : ($count ?? 1);
                    for ($r = 0; $r < $reps; ++$r) {
                        $out .= self::packNum($code, $mods, $args[$ai++] ?? 0);
                    }
            }
        }
    }

    private static function nybble(string $c): int
    {
        return ctype_alpha($c) ? (\ord($c) + 9) & 15 : \ord($c) & 15;
    }

    private static function packNum(string $code, string $mods, mixed $v): string
    {
        $big = str_contains($mods, '>');
        if (\in_array($code, ['f', 'd', 'F'], true)) {
            $f = (float) self::num($v);
            $s = 'f' === $code ? pack('g', $f) : pack('e', $f);

            return $big ? strrev($s) : $s;
        }
        $n = self::int($v);
        if (\is_float($n)) {
            $n = $n >= 9.2233720368547758E18 ? (int) ($n - 1.8446744073709552E19) : (int) $n;
        }
        $s = match ($code) {
            'c', 'C' => \chr($n & 0xFF),
            'n' => pack('n', $n & 0xFFFF),
            'N' => pack('N', $n & 0xFFFFFFFF),
            'v' => pack('v', $n & 0xFFFF),
            'V' => pack('V', $n & 0xFFFFFFFF),
            's', 'S' => pack('v', $n & 0xFFFF),
            'l', 'L', 'i', 'I' => pack('V', $n & 0xFFFFFFFF),
            'q', 'Q', 'j', 'J' => pack('P', $n),
            default => throw new \InvalidArgumentException("pack: unsupported template code '$code'"),
        };

        return $big && !\in_array($code, ['c', 'C', 'n', 'N', 'v', 'V'], true) ? strrev($s) : $s;
    }
}
