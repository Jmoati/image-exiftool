<?php

// Replays ExifTool's t/DNG.t through the PHP API and compares with its expected output
// (t/DNG_N.out). Run: php tests/dng.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
    3 => 'DNG writer (WriteAdobeStuff: maker notes in the DNGPrivateData)',
];
$t = testDir();
$testname = 'DNG';

// test 2: Extract information from DNG.dng
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('DNG.dng'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test writing maker notes information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['OwnerName', 'Just Me'],
        ['OriginalDecisionData', "\xff\xff\xff\xff\x03\0\0\0\0\0\0\0\0\0\0\0\x08\0\0\0Test", 'Protected' => 1],
    ], $testname, 3, "$t/images/DNG.dng", ['OwnerName', 'OriginalDecisionData', 'Warning', 'Error']);
}, $skip);

exit(testsDone());
