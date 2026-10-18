<?php

// Replays ExifTool's t/GE.t through the PHP API and compares with its expected output
// (t/GE_N.out). Run: php tests/ge.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'GE';

// test 2: Extract information from GE.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('GE.jpg'), ['Unknown' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('GEModel', 'test model');
    $testfile = "t/{$testname}_3_failed.jpg";
    @unlink($testfile);
    $et->WriteInfo("$t/images/GE.jpg", $testfile);
    $info = $et->ImageInfo($testfile, ['Duplicates' => 1, 'Unknown' => 1]);
    if (check($et, $info, $testname, 3)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
