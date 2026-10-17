<?php

// Compares Image\ExifTool\Perl helpers with real Perl on a set of cases. Run: php tests/perl-compat.php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Image\ExifTool\Perl;

$cases = [
    // [perl expression, php closure]
    ['sprintf("%.1f", 2.25)', fn () => Perl::sprintf('%.1f', 2.25)],
    ['sprintf("%.2g", 0.000123)', fn () => Perl::sprintf('%.2g', 0.000123)],
    ['sprintf("%g", 1e15)', fn () => Perl::sprintf('%g', 1e15)],
    ['sprintf("%g", 123456)', fn () => Perl::sprintf('%g', 123456)],
    ['sprintf("%g", 1234567)', fn () => Perl::sprintf('%g', 1234567)],
    ['sprintf("%.3g", 99.95)', fn () => Perl::sprintf('%.3g', 99.95)],
    ['sprintf("%e", 1200)', fn () => Perl::sprintf('%e', 1200)],
    ['sprintf("%.2E", -0.000456)', fn () => Perl::sprintf('%.2E', -0.000456)],
    ['sprintf("%+.1f", 3)', fn () => Perl::sprintf('%+.1f', 3)],
    ['sprintf("%+.2f", -0.004)', fn () => Perl::sprintf('%+.2f', -0.004)],
    ['sprintf("%5.1f|%-6d|%06.2f", 3.14159, 42, -1.5)', fn () => Perl::sprintf('%5.1f|%-6d|%06.2f', 3.14159, 42, -1.5)],
    ['sprintf("%d", "12abc")', fn () => Perl::sprintf('%d', '12abc')],
    ['sprintf("%d", -3.9)', fn () => Perl::sprintf('%d', -3.9)],
    ['sprintf("%x %X %.4x %#x %o %b", 255, 255, 10, 255, 8, 5)', fn () => Perl::sprintf('%x %X %.4x %#x %o %b', 255, 255, 10, 255, 8, 5)],
    ['sprintf("%s %s %s", 1/3, 1e15, 0.1+0.2)', fn () => Perl::sprintf('%s %s %s', 1 / 3, 1e15, 0.1 + 0.2)],
    ['sprintf("%3d %4d", split(" ", " 1 22 333"))', fn () => Perl::sprintf('%3d %4d', ...Perl::split(' ', ' 1 22 333'))],
    ['sprintf("%2\$s-%1\$s", "a", "b")', fn () => Perl::sprintf('%2$s-%1$s', 'a', 'b')],
    ['sprintf("%*d|%-*d", 5, 42, 4, 7)', fn () => Perl::sprintf('%*d|%-*d', 5, 42, 4, 7)],
    ['sprintf("%.3s|%5s|%-5s|", "abcdef", "ab", "ab")', fn () => Perl::sprintf('%.3s|%5s|%-5s|', 'abcdef', 'ab', 'ab')],
    ['sprintf("%vd", "1.22.333")', fn () => Perl::sprintf('%vd', '1.22.333')],
    ['sprintf("%.0f %.0f %.0f", 0.5, 1.5, 2.5)', fn () => Perl::sprintf('%.0f %.0f %.0f', 0.5, 1.5, 2.5)],
    ['sprintf("%c%c", 65, 0xe9)', fn () => Perl::sprintf('%c%c', 65, 0xE9)],
    ['sprintf("%.15g", 0.1)', fn () => Perl::sprintf('%.15g', 0.1)],
    ['sprintf("%5.2f%%", 12.345)', fn () => Perl::sprintf('%5.2f%%', 12.345)],
    ['sprintf("%.1f|%e|%g|%.2f|%d", 0, 0, 0, -0.0, 0)', fn () => Perl::sprintf('%.1f|%e|%g|%.2f|%d', 0, 0, 0, -0.0, 0)],
    ['sprintf("%.1f|%+.1f|%g", 0.0, 0.0, -1e-320)', fn () => Perl::sprintf('%.1f|%+.1f|%g', 0.0, 0.0, -1e-320)],
    ['"" . 0.0 . "|" . (0 * -1.5)', fn () => Perl::str(0.0).'|'.Perl::str(0 * -1.5)],
    ['join("|", map { my $f = $_; map { sprintf($f, $_) } "inf", "-inf", "nan" } "%d", "%.3d", "%5d", "%-5d", "%05d", "%x", "%+d", "% d", "%f", "%05.1f", "%+f", "% f", "%-6e", "%08g")', fn () => implode('|', array_merge(...array_map(fn ($f) => array_map(fn ($v) => Perl::sprintf($f, $v), ['inf', '-inf', 'nan']), ['%d', '%.3d', '%5d', '%-5d', '%05d', '%x', '%+d', '% d', '%f', '%05.1f', '%+f', '% f', '%-6e', '%08g'])))],
    ['sprintf("%.1f", "inf")', fn () => Perl::sprintf('%.1f', 'inf')],
    ['"" . (1/3)', fn () => Perl::str(1 / 3)],
    ['"" . 1e15', fn () => Perl::str(1e15)],
    ['"" . 1e21', fn () => Perl::str(1e21)],
    ['"" . 2.5e-5', fn () => Perl::str(2.5e-5)],
    ['"" . 0.0001', fn () => Perl::str(0.0001)],
    ['"" . (10/4)', fn () => Perl::str(10 / 4)],
    ['"" . (6/3)', fn () => Perl::str(6 / 3)],
    ['"" . 123456789012345678', fn () => Perl::str(123456789012345678)],
    ['"" . 1234567890123456789012', fn () => Perl::str(1234567890123456789012)],
    ['0 + "12abc"', fn () => Perl::num('12abc')],
    ['0 + " -3.5e2xyz"', fn () => Perl::num(' -3.5e2xyz')],
    ['0 + "abc"', fn () => Perl::num('abc')],
    ['0 + ".5"', fn () => Perl::num('.5')],
    ['int(-3.7) . "," . int("7.9z")', fn () => Perl::int(-3.7).','.Perl::int('7.9z')],
    ['(-7 % 3) . "," . (7 % -3) . "," . (-7 % -3)', fn () => Perl::mod(-7, 3).','.Perl::mod(7, -3).','.Perl::mod(-7, -3)],
    ['oct("0x1f") . "," . oct("755") . "," . oct("0b101") . "," . hex("ff")', fn () => Perl::oct('0x1f').','.Perl::oct('755').','.Perl::oct('0b101').','.Perl::hex('ff')],
    ['join("|", split(/,/, "a,b,,c,,"))', fn () => implode('|', Perl::split('/,/', 'a,b,,c,,'))],
    ['join("|", split(" ", "  a b\tc  "))', fn () => implode('|', Perl::split(' ', "  a b\tc  "))],
    ['join("|", split(//, "abc"))', fn () => implode('|', Perl::split('//', 'abc'))],
    ['join("|", split(/(-)/, "a-b-c"))', fn () => implode('|', Perl::split('/(-)/', 'a-b-c'))],
    ['join("|", split(/,/, "a,b,c,d", 2))', fn () => implode('|', Perl::split('/,/', 'a,b,c,d', 2))],
    ['join("|", split(/,/, ",a,b"))', fn () => implode('|', Perl::split('/,/', ',a,b'))],
    ['join("|", split(/\s*;\s*/, "x ; y;z"))', fn () => implode('|', Perl::split('/\s*;\s*/', 'x ; y;z'))],
    ['join("|", split(/,/, "a,b,,", -1))', fn () => implode('|', Perl::split('/,/', 'a,b,,', -1))],
    ['join("|", unpack("nNvV", "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c"))', fn () => implode('|', Perl::unpack('nNvV', "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c"))],
    ['join("|", unpack("x2 n2 a3 A4 Z*", "\0\0\0\x01\0\x02abcde \0fg\0h"))', fn () => implode('|', Perl::unpack('x2 n2 a3 A4 Z*', "\0\0\0\x01\0\x02abcde \0fg\0h"))],
    ['join("|", unpack("H*", "\x12\xab"), unpack("h*", "\x12\xab"), unpack("B8", "\xa5"), unpack("b8", "\xa5"))', fn () => implode('|', [...Perl::unpack('H*', "\x12\xab"), ...Perl::unpack('h*', "\x12\xab"), ...Perl::unpack('B8', "\xa5"), ...Perl::unpack('b8', "\xa5")])],
    ['join("|", unpack("c C s< S> l> L<", "\xff\xff\xfe\xff\x01\x02\xff\xff\xff\xfe\x01\0\0\0"))', fn () => implode('|', Perl::unpack('c C s< S> l> L<', "\xff\xff\xfe\xff\x01\x02\xff\xff\xff\xfe\x01\0\0\0"))],
    ['join("|", unpack("(H2)3", "\x01\x02\x03\x04"))', fn () => implode('|', Perl::unpack('(H2)3', "\x01\x02\x03\x04"))],
    ['join("|", map {"[$_]"} unpack("(H2)3", "\x01"), unpack("(n)3", "\x00\x01"), unpack("(a2)3", "x"), unpack("(H2)*", "\x01\x02"))', fn () => implode('|', array_map(fn ($v) => "[$v]", [...Perl::unpack('(H2)3', "\x01"), ...Perl::unpack('(n)3', "\x00\x01"), ...Perl::unpack('(a2)3', 'x'), ...Perl::unpack('(H2)*', "\x01\x02")]))],
    ['join("|", unpack("n/a*", "\0\x03abcdef"))', fn () => implode('|', Perl::unpack('n/a*', "\0\x03abcdef"))],
    ['join("|", map { eval { unpack($_, "ab"); "ok" } || "died" } "x4n", "x2n", "\\@5", "X3", "\\@2n")', fn () => implode('|', array_map(function ($t) { try { Perl::unpack($t, 'ab'); return 'ok'; } catch (\RuntimeException) { return 'died'; } }, ['x4n', 'x2n', '@5', 'X3', '@2n']))],
    ['join("|", unpack("N", "ab"))', fn () => implode('|', Perl::unpack('N', 'ab'))],
    ['join("|", unpack("n!v!N!", "\xff\xfe\xfe\xff\xff\xff\xff\xfe"))', fn () => implode('|', Perl::unpack('n!v!N!', "\xff\xfe\xfe\xff\xff\xff\xff\xfe"))],
    ['join("|", unpack("f< d>", pack("f<", 1.5) . pack("d>", -2.25)))', fn () => implode('|', Perl::unpack('f< d>', Perl::pack('f<', 1.5).Perl::pack('d>', -2.25)))],
    ['join("|", unpack("Q> q<", "\xff\xff\xff\xff\xff\xff\xff\xff\xfe\xff\xff\xff\xff\xff\xff\xff"))', fn () => implode('|', Perl::unpack('Q> q<', "\xff\xff\xff\xff\xff\xff\xff\xff\xfe\xff\xff\xff\xff\xff\xff\xff"))],
    ['join("|", unpack("U0U*", "\xc3\xa9t\xc3\xa9"))', fn () => implode('|', Perl::unpack('U0U*', "\xc3\xa9t\xc3\xa9"))],
    ['join("|", unpack("C0U*", "\xc3\xa9t\xe2\x82\xac"))', fn () => implode('|', Perl::unpack('C0U*', "\xc3\xa9t\xe2\x82\xac"))],
    ['unpack("H*", pack("C0U*", 233, 0x20ac, 65))', fn () => bin2hex(Perl::pack('C0U*', 233, 0x20AC, 65))],
    ['unpack("H*", pack("nNvVCa3A3Z3H4", 258, 16909060, 258, 16909060, 65, "ab", "cd", "efgh", "beef"))', fn () => bin2hex(Perl::pack('nNvVCa3A3Z3H4', 258, 16909060, 258, 16909060, 65, 'ab', 'cd', 'efgh', 'beef'))],
    ['join("|", map { unpack("H*", pack($$_[0], $$_[1])) } ["H*","zz12g."],["h*","zz12g."],["H4","a"],["H3","abcd"],["h3","abcd"],["H*","1"],["H2","A"])', fn () => implode('|', array_map(fn ($t) => bin2hex(Perl::pack($t[0], $t[1])), [['H*', 'zz12g.'], ['h*', 'zz12g.'], ['H4', 'a'], ['H3', 'abcd'], ['h3', 'abcd'], ['H*', '1'], ['H2', 'A']]))],
    ['do { my $s = "hello world"; (my $n = $s) =~ tr/a-y/b-z/; $n }', fn () => Perl::tr('hello world', 'a-y', 'b-z')[0]],
    ['do { my $s = "a.b.c"; my $c = ($s =~ tr/.//); $c }', fn () => Perl::tr('a.b.c', '.')[1]],
    ['do { my $s = "aabbcc"; $s =~ tr/a-z//s; $s }', fn () => Perl::tr('aabbcc', 'a-z', '', 's')[0]],
    ['do { my $s = "x\0y\0"; $s =~ tr/\0//d; $s }', fn () => Perl::tr("x\0y\0", '\0', '', 'd')[0]],
    ['do { my $s = "ab12cd"; $s =~ tr/0-9//cd; $s }', fn () => Perl::tr('ab12cd', '0-9', '', 'cd')[0]],
    ['do { my $s = "a b"; $s =~ tr/ /./; $s }', fn () => Perl::tr('a b', ' ', '.')[0]],
    ['index("hello", "l") . "," . index("hello", "z") . "," . rindex("hello", "l") . "," . rindex("hello", "l", 2)', fn () => Perl::index('hello', 'l').','.Perl::index('hello', 'z').','.Perl::rindex('hello', 'l').','.Perl::rindex('hello', 'l', 2)],
];

$perl = "use strict; no warnings; binmode STDOUT;\n";
foreach ($cases as $i => [$expr]) {
    $perl .= "print unpack('H*', scalar(".$expr.")), \"\\n\";\n";
}
$file = tempnam(sys_get_temp_dir(), 'pc');
file_put_contents($file, $perl);
exec('perl '.escapeshellarg($file), $lines, $status);
unlink($file);
if (0 !== $status || \count($lines) !== \count($cases)) {
    fwrite(STDERR, "perl failed ($status)\n");
    exit(1);
}
$fail = 0;
foreach ($cases as $i => [$expr, $php]) {
    $expected = (string) hex2bin($lines[$i]);
    try {
        $got = Perl::str($php());
    } catch (\Throwable $e) {
        $got = get_class($e).': '.$e->getMessage();
    }
    if ($got !== $expected) {
        ++$fail;
        echo "FAIL $expr\n  perl: ".json_encode($expected, JSON_INVALID_UTF8_SUBSTITUTE)."\n  php:  ".json_encode($got, JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }
}
echo \count($cases) - $fail, '/', \count($cases), " ok\n";
exit($fail ? 1 : 0);
