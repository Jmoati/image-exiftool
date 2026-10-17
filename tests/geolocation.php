<?php

// Replays the reading tests of ExifTool's t/Geolocation.t (2, 6, 7 and 8) through the PHP API and
// compares with their expected output (t/Geolocation_N.out). Run: php tests/geolocation.php [~/exiftool]

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Geolocation;
use Image\ExifTool\ScalarRef;

$t = testDir();
$fail = 0;
// test 2: Geolocation option
$et = new ExifTool();
$et->Options('Geolocation', 1);
$et->Options('GeolocMinPop', 5000);
$fail += !check($et, $et->ImageInfo("$t/images/GPS.jpg", 'Geolocation*'), 'Geolocation', 2);
// test 6: the two nearest cities, from a dummy file in memory, avoiding PPLX features
$et = new ExifTool();
$et->Options('Geolocation', '48.1375,11.5755,num=2');
$et->Options('GeolocFeature', '-PPLX');
$fail += !check($et, $et->ImageInfo(new ScalarRef(''), 'geolocation*'), 'Geolocation', 6);
// tests 7-8: regular expression, then a user-defined entry found by one of its alternate names
$et = new ExifTool();
Geolocation::AddEntry('Sinemorets', 'burgas', 'Obshtina Tsarevo', 'BG', '', 'Europe/Sofia', '', 400, 42.06115, 27.97833, 'abcde,fghij,klmno');
$et->Options('Geolocation', '1,2,co/Canada/');
$fail += !check($et, $et->ImageInfo(new ScalarRef(''), 'geolocation*'), 'Geolocation', 7);
$et->Options('Geolocation', 'fghij');
$fail += !check($et, $et->ImageInfo(new ScalarRef(''), 'geolocation*'), 'Geolocation', 8);
echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
