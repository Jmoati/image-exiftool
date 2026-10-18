<?php

// Replays ExifTool's t/Sanyo.t through the PHP API and compares with its expected output
// (t/Sanyo_N.out). Run: php tests/sanyo.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Sanyo';

// test 2: Extract information from Sanyo.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Sanyo.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['SceneCaptureType', 'night'],
        ['FlashMode', 'force', 'Group' => 'MakerNotes'],
    ], $testname, 3);
}, $skip);

exit(testsDone());
