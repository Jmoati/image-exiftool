<?php

// Replays ExifTool's t/Kodak.t through the PHP API and compares with its expected output
// (t/Kodak_N.out). Run: php tests/kodak.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Kodak';

// test 2: Extract information from Kodak.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Kodak.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['YearCreated', '2005', 'Group' => 'Kodak'],
        ['MonthDayCreated', '03:31', 'Group' => 'Kodak'],
        ['DigitalZoom', '2'],
    ], $testname, 3);
}, $skip);

exit(testsDone());
