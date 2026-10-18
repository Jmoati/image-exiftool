<?php

// Replays ExifTool's t/Pentax.t through the PHP API and compares with its expected output
// (t/Pentax_N.out). Run: php tests/pentax.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Pentax';

// test 2: Extract information from Pentax.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Pentax.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['ThumbnailImage'],     // delete thumbnail image
        ['WhiteBalance', 'Tungsten', 'Group' => 'MakerNotes'],
        ['FocalLength', 22],
        ['MaxAperture', 2.0],
    ], $testname, 3);
}, $skip);

// test 4: Extract information from a Pentax AVI video
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Pentax.avi'));

    return check($et, $info, $testname, 4);
}, $skip);

exit(testsDone());
