<?php

// Runs Perl exiftool and bin/exiftool with the same -if / -p / -list commands and write commands, and
// compares their stdout and exit status. Run: php tests/cli-compat.php [~/exiftool]

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
    // geolocation (without a file when the option holds a position)
    ['-api', 'geolocation', '-api', 'geolocminpop=5000', '-geolocation*', 'GPS.jpg'],
    ['-api', 'geolocation=$city,$country,Kingston', '-a', '-G1', 'GPS.jpg', 'Canon.jpg'],
    ['-api', 'geolocation=48.1375,11.5755,num=2', '-api', 'geolocfeature=-PPLX', '-a'],
    ['-j', '-api', 'geolocation=45.5 S 70.1 W', '-api', 'geolocmaxdist=500'],
    ['-api', 'geolocation=/^Paris \d+/i,-sr/x/,num=3', '-m', '-a', '-n'],
    ['-api', 'geolocation=saint-germain-en-laye,num=3', '-a', '-api', 'duplicates'],
    ['-api', 'geolocation=nosuchcity'],
    ['-listgeo', '-sort', '-api', 'geolocminpop=2e6', '-api', 'geolocfeature=-PPLA'],
    // languages (descriptions, PrintConv values, lang-alt tags, alignment of wide characters)
    ['-lang'], ['-lang', 'zz', 'Canon.jpg'], ['-lang', 'fr', 'Canon.jpg'],
    ['-lang', 'de', '-j', '-a', 'Canon.jpg', 'Nikon.jpg'], ['-lang', 'ja', '-a', '-G1', 'XMP5.xmp'],
    ['-lang', 'ru', '-t', '-flash', '-exposureprogram', 'Canon.jpg'],
];
// writing: run in var/cli-compat/<tool>, on fresh copies of the test images named in the arguments
// (so that nothing is ever written into t/images); the written file goes to stdout with -o -
$writeCases = [
    ['-o', '-', '-Comment=cli-compat', '-XMP:Title=x', '-IPTC:Keywords=y', 'Writer.jpg'],
    ['-o', '-', '-all=', '-Comment=only', 'Nikon.jpg'],
    ['-o', '-', '-tagsFromFile', 'Canon.jpg', '-Make', '-Model>Artist', '-Comment<$Make $ISO', 'Writer.jpg'],
    ['-o', '-', '-n', '-Orientation=6', '-ExifIFD:ISO#=800', '-sep', ';', '-XMP:Subject=a;b', 'Canon.jpg'],
    ['-o', '-.exv', 'Canon.jpg'], ['-o', '-.xmp', 'XMP.jpg'],
    ['-if', '$make eq "Canon"', '-o', '-', '-Comment=if', 'Canon.jpg'],
    ['-Comment=x', 'NoSuchFile.jpg'], ['-o', '-', '-Comment=x', 'BMP.bmp'],
    ['-tagsFromFile', 'NoSuchFile.jpg', '-o', '-', 'Canon.jpg'],
    ['-wm', 'x', '-Comment=x', 'Canon.jpg'], ['-delete_original', '-Comment=x', 'Canon.jpg'],
    ['-overwrite_original_in_place', '-o', 'x.jpg', '-Comment=x', 'Canon.jpg'],
    ['-Comment=in place', 'Writer.jpg'], ['-overwrite_original', '-all=', 'Canon.jpg'], ['-NoSuchTag=x', 'Canon.jpg'],
    ['-csv=NoSuchFile.csv', 'Canon.jpg'], ['-Comment=x', '-execute', '-restore_original', '-common_args', 'Writer.jpg'],
    ['-CompositeImageExposureTimes=1 2 3 4 5 6 7 8 inf 10', 'Writer.jpg'],     // (dies while writing)
];
$fail = 0;
foreach ([...$cases, ...$writeCases] as $i => $args) {
    $run = function (array $cmd, string $tool) use ($args, $img, $i, $cases) {
        $dir = $img;
        if ($i >= count($cases)) {
            $dir = __DIR__."/../var/cli-compat/".getmypid()."/$tool";  // (a directory per run)
            @mkdir($dir, 0777, true);
            array_map('unlink', array_filter(glob("$dir/*"), 'is_file'));
            foreach ($args as $a) {
                if (is_file("$img/$a")) {
                    copy("$img/$a", "$dir/$a");
                }
            }
        }
        $p = proc_open([...$cmd, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($p), $out];
    };
    $perl = $run(['perl', "$et/exiftool"], 'perl');
    $php = $run([PHP_BINARY, __DIR__.'/../bin/exiftool'], 'php');
    $ok = $perl === $php;
    $fail += $ok ? 0 : 1;
    echo ($ok ? 'ok   ' : 'FAIL ').implode(' ', $args)."\n";
}
// remove the copies of this run
$runDir = __DIR__.'/../var/cli-compat/'.getmypid();
array_map('unlink', array_filter(glob("$runDir/*/*"), 'is_file'));
array_map('rmdir', glob("$runDir/*", GLOB_ONLYDIR));
@rmdir($runDir);
echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
