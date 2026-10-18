<?php

// Replays ExifTool's t/Panasonic.t through the PHP API and compares with its expected output
// (t/Panasonic_N.out). Run: php tests/panasonic.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Panasonic';

// test 2: Extract information from Panasonic.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Panasonic.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['Keywords', 'cool'],
        ['ShootingMode', 'Panning'],
    ], $testname, 3);
}, $skip);

// test 4: Extract information from RW2 image
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Panasonic.rw2'));

    return check($et, $info, $testname, 4);
}, $skip);

// test 5: Write to RW2 image
runTest($testname, 5, function () use ($t, $testname) {
    return writeCheck([
        ['XMP:Title', 'new title'],
        ['IPTC:Keywords', 'a keyword'],
        ['ModifyDate', '2009:03:25 12:11:46'],
    ], $testname, 5, "$t/images/Panasonic.rw2");
}, $skip);

exit(testsDone());
