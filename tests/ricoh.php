<?php

// Replays ExifTool's t/Ricoh.t through the PHP API and compares with its expected output
// (t/Ricoh_N.out). Run: php tests/ricoh.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Ricoh';

// test 2: Extract information from Ricoh.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Ricoh.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['Ricoh:Sharpness', 'Soft'],
    ], $testname, 3);
}, $skip);

// test 4: Test extracting Ricoh APP5 RMETA information
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Ricoh2.jpg'));

    return check($et, $info, $testname, 4);
}, $skip);

exit(testsDone());
