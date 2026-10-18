<?php

// Checks the ports of t/TestLib.pm's nearEnough() and of Fixup.pm against Perl ExifTool:
// the same line pairs and the same fixup operations are run by both.
// Run: php tests/write-lib.php [~/exiftool]

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Fixup;

$etDir = dirname(testDir());
$fail = 0;

// run a Perl script in ~/exiftool with its lib, return its output
function perl(string $etDir, string $script, string $input = ''): string
{
    $proc = proc_open(['perl', '-Ilib', '-e', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $etDir);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);

    return $out;
}

//------------------------------------------------------------------------------
// nearEnough()
$pairs = [
    ["[File, System, Time] FileModifyDate - File Modification Date/Time: 2020:07:29 09:04:26-04:00\n",
     "[File, System, Time] FileModifyDate - File Modification Date/Time: 2026:10:18 10:00:00+02:00\n"],
    ["FilePermissions: -rw-r--r--\n", "FilePermissions: -rw-rw-rw-\n"],
    ["[ExifTool, ExifTool, ExifTool] ExifToolVersion - ExifTool Version Number: 12.03\n",
     "[ExifTool, ExifTool, ExifTool] ExifToolVersion - ExifTool Version Number: ".ExifTool::$VERSION."\n"],
    ["x: 1.23456 mm\n", "x: 1.23457 mm\n"],
    ["x: 1.2345\n", "x: 1.2399\n"],
    ["x: 123456789.5\n", "x: 123456789.6\n"],
    ["x: 0.5\n", "x: 0\n"],
    ["x: 2005:01:01 00:00:00-05:00\n", "x: 2005:01:01 05:00:00Z\n"],
    ["x: 2005:01:01 00:00:00-05:00\n", "x: 2005:01:01 00:00:00+01:00\n"],
    ["x: 10:20:30.5\n", "x: 10:20:30.50001\n"],
    ["x: 1.5e+10\n", "x: 1.50000001e+10\n"],
    ["x: 3.4.# 5.#e+##\n", "x: 3.4.### 5.###e+##\n"],
    ["x: 'abc 1.0\n", "x: 'abc 1.00001\n"],
    ["x: a long value [...]\n", "x: a long value that [...]\n"],
    ["x: 1.5[snip]\n", "x: 1.6[snip]\n"],
    ["x: a b c\n", "x: a b\n"],
    ["x: 1/64\n", "x: 1/65\n"],
];
$php = array_map(static fn ($p) => nearEnough($p[0], $p[1]) ? 1 : 0, $pairs);
$out = perl($etDir, 'use JSON::PP; require "./t/TestLib.pm"; my $p = decode_json(join "", <STDIN>);'.
    ' print join(",", map { t::TestLib::nearEnough($$_[0], $$_[1]) ? 1 : 0 } @$p), "\n";',
    json_encode($pairs));
$ok = implode(',', $php) === trim($out);
echo ($ok ? 'ok   ' : 'FAIL ')."nearEnough\n";
if (!$ok) {
    echo "     perl: ".trim($out)."\n     php:  ".implode(',', $php)."\n";
    ++$fail;
}

//------------------------------------------------------------------------------
// Fixup: pointers of both byte orders, 16-bit, with markers, in nested fixups
$fx = static function (): string {
    $data = str_repeat("\0", 48);
    ExifTool::SetByteOrder('MM');
    ExifTool::Set32u(10, $data, 0);
    ExifTool::Set32u(20, $data, 4);
    ExifTool::Set16u(30, $data, 8);
    $fixup = new Fixup();
    $fixup->AddFixup(0);
    $fixup->AddFixup(4, 'PreviewImage');
    $fixup->AddFixup(8, null, 'int16u');
    ExifTool::SetByteOrder('II');
    ExifTool::Set32u(40, $data, 20);
    ExifTool::Set32u(50, $data, 24);
    $sub = new Fixup();
    $sub->AddFixup(4);
    $sub->AddFixup(8, 'PreviewImage');
    $sub->Start = 16;
    $sub->Shift = 3;
    $fixup->AddFixup($sub);
    $clone = $fixup->Clone();
    $fixup->Shift += 100;
    $fixup->ApplyFixup($data);
    // (sorted: the pointers of different byte orders come in Perl hash order)
    $p = $fixup->GetMarkerPointersList($data, 'PreviewImage');
    sort($p);
    $out = bin2hex($data).' '.implode(',', $p);
    $fixup->SetMarkerPointers($data, 'PreviewImage', 777);
    $out .= ' '.bin2hex($data).' '.$fixup->IsEmpty().$fixup->HasMarker('PreviewImage').$fixup->HasMarker('X');
    $clone->Start += 2;
    $data2 = str_repeat("\0", 50);
    $clone->ApplyFixup($data2);
    ExifTool::SetByteOrder('MM');

    return $out.' '.bin2hex($data2)."\n";
};
$out = perl($etDir, <<<'PERL'
    use Image::ExifTool qw(SetByteOrder Set32u Set16u);
    use Image::ExifTool::Fixup;
    my $data = "\0" x 48;
    SetByteOrder('MM');
    Set32u(10, \$data, 0);
    Set32u(20, \$data, 4);
    Set16u(30, \$data, 8);
    my $fixup = Image::ExifTool::Fixup->new;
    $fixup->AddFixup(0);
    $fixup->AddFixup(4, 'PreviewImage');
    $fixup->AddFixup(8, undef, 'int16u');
    SetByteOrder('II');
    Set32u(40, \$data, 20);
    Set32u(50, \$data, 24);
    my $sub = Image::ExifTool::Fixup->new;
    $sub->AddFixup(4);
    $sub->AddFixup(8, 'PreviewImage');
    $sub->{Start} = 16;
    $sub->{Shift} = 3;
    $fixup->AddFixup($sub);
    my $clone = $fixup->Clone();
    $fixup->{Shift} += 100;
    $fixup->ApplyFixup(\$data);
    my @p = sort { $a <=> $b } $fixup->GetMarkerPointers(\$data, 'PreviewImage');
    my $out = unpack('H*', $data) . ' ' . join(',', @p);
    $fixup->SetMarkerPointers(\$data, 'PreviewImage', 777);
    $out .= ' ' . unpack('H*', $data) . ' ' . $fixup->IsEmpty() . $fixup->HasMarker('PreviewImage') . $fixup->HasMarker('X');
    $clone->{Start} += 2;
    my $data2 = "\0" x 50;
    $clone->ApplyFixup(\$data2);
    print $out, ' ', unpack('H*', $data2), "\n";
    PERL);
$php = $fx();
$ok = $php === $out;
echo ($ok ? 'ok   ' : 'FAIL ')."Fixup\n";
if (!$ok) {
    echo "     perl: $out     php:  $php";
    ++$fail;
}

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
