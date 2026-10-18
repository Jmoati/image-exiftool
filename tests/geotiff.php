<?php

// Replays ExifTool's t/GeoTiff.t through the PHP API and compares with its expected output
// (t/GeoTiff_N.out). Run: php tests/geotiff.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'GeoTiff';

// test 2: Extract information from GeoTiff.tif
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('GeoTiff.tif'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['ResolutionUnit', 'cm'],
    ], $testname, 3, "$t/images/GeoTiff.tif");
}, $skip);

// test 4: Copy GeoTiff information
runTest($testname, 4, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_4_failed.out";
    @unlink($testfile);
    $et->SetNewValuesFromFile("$t/images/GeoTiff.tif", 'GeoTiff*');
    $ok = writeInfo($et, "$t/images/ExifTool.tif", $testfile);
    $info = $et->ImageInfo($testfile, 'GeoTiff:*');
    if (check($et, $info, $testname, 4) and $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
