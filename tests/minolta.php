<?php

// Replays ExifTool's t/Minolta.t through the PHP API and compares with its expected output
// (t/Minolta_N.out). Run: php tests/minolta.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Minolta';

// test 2: Extract information from Minolta.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Minolta.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['Caption-Abstract', 'A new caption/abstract'],
        ['MinoltaDate', '2005:01:16'],
    ], $testname, 3);
}, $skip);

// test 4: Write rewriting MRW image
runTest($testname, 4, function () use ($t, $testname) {
    return writeCheck([
        ['FocusMode', 'MF'],
        ['LastFileNumber', '123'],
    ], $testname, 4, "$t/images/Minolta.mrw");
}, $skip);

exit(testsDone());
