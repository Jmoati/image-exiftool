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
    // alternate files
    ['-file1', 'Nikon.jpg', '-p', '$model $file1:model $file1:filename', 'Canon.jpg'],
    ['-file1', '%f.jpg', '-file2', '@', '-if', '$file1:make eq "Canon"', '-p', '$file1:model|$file2:model|${file1:iso}', 'Canon.jpg', 'Nikon.jpg'],
    ['-file1', 'Nikon.jpg', '-G1', '-a', '-s', '-File1:Model', '-model', '-File1:ISO', 'Canon.jpg'],
    ['-file1', 'NoSuch.jpg', '-p', '$model $file1:model', 'Canon.jpg'], ['-file1'],
    ['-X', 'Canon.jpg', 'XMP.jpg'], ['-X', '-l', '-struct', '-G', 'XMP.jpg'], ['-X', '-s', '-H', '-t', 'Nikon.jpg'],
    ['-h', 'Canon.jpg', 'Nikon.jpg'], ['-h', '-a', '-G', '-H', 'XMP.jpg'], ['-h', '-s', 'Canon.jpg'],
    ['-htmlDump', 'Canon.jpg'], ['-htmlDump0', 'Nikon.jpg'], ['-h', '-v', 'PhaseOne.iiq'], ['-htmlDump', 'PhaseOne.iiq'],
    ['-listx', '-lang', 'fr', '-f', '-s'],
    ['-lang', 'ru', '-t', '-flash', '-exposureprogram', 'Canon.jpg'],
];
// writing: run in var/cli-compat/<tool>, on fresh copies of the test images named in the arguments
// (so that nothing is ever written into t/images); the written file goes to stdout with -o -
$writeCases = [
    ['-o', '-', '-Comment=cli-compat', '-XMP:Title=x', '-IPTC:Keywords=y', 'Writer.jpg'],
    ['-o', '-', '-all=', '-Comment=only', 'Nikon.jpg'],
    ['-o', '-', '-tagsFromFile', 'Canon.jpg', '-Make', '-Model>Artist', '-Comment<$Make $ISO', 'Writer.jpg'],
    ['-o', '-', '-n', '-Orientation=6', '-ExifIFD:ISO#=800', '-sep', ';', '-XMP:Subject=a;b', 'Canon.jpg'],
    ['-file1', 'Canon.jpg', '-o', '-', '-Comment<$file1:Model $make', '-tagsFromFile', '@', '-Artist<$file1:make', 'Nikon.jpg'],
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
// -config: the Perl twin of tests/config/example.config.php is written under var/ ('@CONFIG@' is the config file)
$perlConfig = <<<'PERL'
%Image::ExifTool::UserDefined::Shortcuts = (
    MyShortcut => ['exif:createdate','exposuretime','aperture'],
    MyAlias => 'FocalLengthIn35mmFormat',
);
%Image::ExifTool::UserDefined = (
    'Image::ExifTool::Exif::Main' => {
        0xd000 => { Name => 'NewEXIFTag', Writable => 'int16u', WriteGroup => 'IFD0' },
    },
    'Image::ExifTool::GPS::Main' => {
        0xd000 => { Name => 'GPSPitch', Writable => 'rational64s' },
        0xd001 => { Name => 'GPSRoll', Writable => 'rational64s' },
    },
    'Image::ExifTool::IPTC::ApplicationRecord' => {
        160 => { Name => 'NewIPTCTag', Format => 'string[0,16]' },
    },
    'Image::ExifTool::XMP::xmp' => {
        NewXMPxmpTag => { Groups => { 2 => 'Author' } },
    },
    'Image::ExifTool::XMP::Main' => {
        xxx => { SubDirectory => { TagTable => 'Image::ExifTool::UserDefined::xxx' } },
    },
    'Image::ExifTool::Composite' => {
        FileExtension => { Require => 'FileName', ValueConv => '$val=~/\.([^.]*)$/; $1' },
        CircleOfConfusion => {
            Require => 'ScaleFactor35efl',
            Groups => { 2 => 'Camera' },
            ValueConv => 'sqrt(24*24+36*36) / ($val * 1750)',
            PrintConv => 'sprintf("%.3f mm",$val)',
        },
    },
);
%Image::ExifTool::UserDefined::xxx = (
    GROUPS        => { 0 => 'XMP', 1 => 'XMP-xxx', 2 => 'Image' },
    NAMESPACE     => { 'xxx' => 'http://ns.myname.com/xxx/1.0/' },
    WRITABLE      => 'string',
    NewXMPxxxTag1 => { Writable => 'lang-alt' },
    NewXMPxxxTag2 => { Groups => { 2 => 'Author' } },
    NewXMPxxxTag3 => { List => 'Bag' },
);
require Image::ExifTool::XMP;
Image::ExifTool::XMP::RegisterNamespace(\%Image::ExifTool::UserDefined::xxx);
%Image::ExifTool::UserDefined::Options = (Duplicates => 1, Composite => 1);
@Image::ExifTool::UserDefined::Lenses = ('Sigma AF 10-20mm F4-5.6 EX DC', 'Tokina AF193-2 19-35mm f/3.5-4.5');
1;
PERL;
@mkdir(__DIR__.'/../var/cli-compat', 0777, true);
file_put_contents($perlCfg = realpath(__DIR__.'/../var/cli-compat').'/example.config', $perlConfig);
$phpCfg = realpath(__DIR__.'/config').'/example.config.php';
$cfgCases = [
    ['-config', '@CONFIG@', '-o', '-', '-NewEXIFTag=7', '-GPSPitch=1.5', '-NewIPTCTag=abc', '-XMP-xxx:NewXMPxxxTag3=a', '-XMP-xxx:NewXMPxxxTag3=b',
     '-NewXMPxmpTag=z', '-XMP-xxx:NewXMPxxxTag1-fr=bonjour', 'Writer.jpg'],
    ['-config', '@CONFIG@', '-a', '-G1', '-MyShortcut', '-MyAlias', '-FileExtension', '-CircleOfConfusion', '-NewEXIFTag', 'Canon.jpg'],
    ['-config', '@CONFIG@', '-config', '', '-MyShortcut', 'Canon.jpg'],
    ['-config', '', '-MyShortcut', 'Canon.jpg'],
    ['-config', '@CONFIG@', '-list', '-Exif:all'],
    ['-config', '@CONFIG@', '-list', '-xmp-xxx:all'],
    ['-config', '@CONFIG@', '-overwrite_original', '-NewEXIFTag=7', '-GPSPitch=1.5', '-NewIPTCTag=abc', '-XMP-xxx:NewXMPxxxTag3=a', '-XMP-xxx:NewXMPxxxTag1-fr=bonjour',
     '-NewXMPxmpTag=z', 'Writer.jpg', '-execute', '-a', '-G1', '-s', '-NewEXIFTag', '-GPSPitch', '-NewIPTCTag', '-XMP-xxx:all', '-NewXMPxmpTag', '-FileExtension', 'Writer.jpg'],
    ['-config', '@CONFIG@', '-a', '-G1', '-s', '-XMP-xxx:all', '-NewIPTCTag', '-GPSPitch', 'Canon.jpg'],
    ['-config', '/nonexistent/file.config', '-model', 'Canon.jpg'],
];
$fail = 0;
foreach ([...$cases, ...$writeCases, ...$cfgCases] as $i => $args) {
    $run = function (array $cmd, string $tool) use ($args, $img, $i, $cases, $perlCfg, $phpCfg) {
        $args = str_replace('@CONFIG@', 'perl' === $tool ? $perlCfg : $phpCfg, $args);
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
