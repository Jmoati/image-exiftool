<?php

// Replays ExifTool's t/PPM.t through the PHP API and compares with its expected output
// (t/PPM_N.out). Run: php tests/ppm.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\ScalarRef;

$skip = [];
$t = testDir();
$testname = 'PPM';

// test 2: Extract information from PPM.bmp
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PPM.ppm'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write new comments to PPM in memory
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('Comment', 'A new comment');
    $image = new ScalarRef(null);
    $et->WriteInfo("$t/images/PPM.ppm", $image);
    $et->Options('Unknown', 1, 'Binary', 0, 'ListJoin', ', ');
    $info = $et->ImageInfo($image);

    return check($et, $info, $testname, 3);
}, $skip);

exit(testsDone());
