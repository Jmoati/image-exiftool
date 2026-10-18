<?php

// Replays ExifTool's t/GPS.t through the PHP API and compares with its expected output
// (t/GPS_N.out). Run: php tests/gps.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'GPS';

// test 2: Extract information from GPS.jpg with specified coordinate format
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $et->Options('CoordFormat', '%d degrees %.2f minutes');
    $info = $et->ImageInfo(testImage('GPS.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['GPSLatitude', "12 deg 21' 23.345"],
        ['GPSLatitudeRef', 'south'],
        ['GPSTimeStamp', '2007:03:02 18:46:10.55-05:30'],
        ['GPSDateStamp', '2007:03:02 18:46:10.55-05:30'],
    ], $testname, 3);
}, $skip);

exit(testsDone());
