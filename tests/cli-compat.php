<?php

// Runs Perl exiftool and bin/exiftool with the same -if / -p / -list commands and compares their
// stdout and exit status. Run: php tests/cli-compat.php [~/exiftool]

$et = rtrim($argv[1] ?? getenv('HOME').'/exiftool', '/');
$img = "$et/t/images";
$cases = [
    ['-if', '$model =~ /REBEL$$/i and $make eq "Canon"', '-model', 'Canon.jpg', 'Nikon.jpg'],
    ['-if', 'not $gps:all', '-if', '$exif:make ne "Nikon" or ${ifd0:model} =~ m{rebel}i', '-model', 'Canon.jpg', 'Nikon.jpg'],
    ['-if', '$model =~ /REBEL$/', '-model', 'Canon.jpg'],              // ("$/" is a newline: pattern not terminated)
    ['-if', '$exposuretime < 0.01', 'Canon.jpg', 'Nikon.jpg'],          // (non-numeric "1/213" fails the condition)
    ['-if', '$nosuch eq "x" or defined $nosuch', 'Canon.jpg'],         // (undefined value fails the condition)
    ['-if', '!($iso == 100) && $filesize# >= -1', '-iso', 'Canon.jpg', 'Nikon.jpg'],
    ['-p', '$filename: $model ${make} $$ ${exif:iso} ${model;}', 'Canon.jpg', 'Nikon.jpg'],
    ['-ee', '-p', '#[SECT]$directory', '-p', '#[IF]$imagewidth', '-p', '$imagewidth $doc1:imagewidth', 'ICO.ico', 'MRC.mrc'],
    ['-listf'], ['-listd'], ['-listg2'], ['-list', '-IFD0:all'],
];
$fail = 0;
foreach ($cases as $args) {
    $run = function (array $cmd) use ($args, $img) {
        $p = proc_open([...$cmd, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $img);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($p), $out];
    };
    $perl = $run(['perl', "$et/exiftool"]);
    $php = $run([PHP_BINARY, __DIR__.'/../bin/exiftool']);
    $ok = $perl === $php;
    $fail += $ok ? 0 : 1;
    echo ($ok ? 'ok   ' : 'FAIL ').implode(' ', $args)."\n";
}
echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
