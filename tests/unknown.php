<?php

// Replays ExifTool's t/Unknown.t through the PHP API and compares with its expected output
// (t/Unknown_N.out). Run: php tests/unknown.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Unknown';

// test 2: Extract information from Unknown.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Unknown.jpg'), ['Unknown' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['FocalLength', 200],
    ], $testname, 3);
}, $skip);

exit(testsDone());
