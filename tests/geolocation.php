<?php

// Replays the reading tests of ExifTool's t/Geolocation.t (2, 6, 7 and 8) through the PHP API and
// compares with their expected output (t/Geolocation_N.out). Run: php tests/geolocation.php [~/exiftool]

require __DIR__.'/../vendor/autoload.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Geolocation;
use Image\ExifTool\ScalarRef;

$t = rtrim($argv[1] ?? getenv('HOME').'/exiftool', '/').'/t';
// (t/TestLib.pm check())
$check = function (ExifTool $et, array $info, int $num) use ($t): bool {
    $out = '';
    foreach ($et->GetTagList($info, 'Group0') as $tag) {
        $groups = implode(', ', array_slice($et->GetGroup($tag), 0, 3));
        $out .= "[$groups] {$et->GetTagID($tag)} - {$et->GetDescription($tag)}: {$info[$tag]}\n";
    }
    $ok = $out === file_get_contents("$t/Geolocation_$num.out");
    echo ($ok ? 'ok   ' : 'FAIL ')."test $num\n";

    return $ok;
};
$fail = 0;
// test 2: Geolocation option
$et = new ExifTool();
$et->Options('Geolocation', 1);
$et->Options('GeolocMinPop', 5000);
$fail += !$check($et, $et->ImageInfo("$t/images/GPS.jpg", 'Geolocation*'), 2);
// test 6: the two nearest cities, from a dummy file in memory, avoiding PPLX features
$et = new ExifTool();
$et->Options('Geolocation', '48.1375,11.5755,num=2');
$et->Options('GeolocFeature', '-PPLX');
$fail += !$check($et, $et->ImageInfo(new ScalarRef(''), 'geolocation*'), 6);
// tests 7-8: regular expression, then a user-defined entry found by one of its alternate names
$et = new ExifTool();
Geolocation::AddEntry('Sinemorets', 'burgas', 'Obshtina Tsarevo', 'BG', '', 'Europe/Sofia', '', 400, 42.06115, 27.97833, 'abcde,fghij,klmno');
$et->Options('Geolocation', '1,2,co/Canada/');
$fail += !$check($et, $et->ImageInfo(new ScalarRef(''), 'geolocation*'), 7);
$et->Options('Geolocation', 'fghij');
$fail += !$check($et, $et->ImageInfo(new ScalarRef(''), 'geolocation*'), 8);
echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
