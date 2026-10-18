<?php

// Replays ExifTool's t/AFCP.t through the PHP API and compares with its expected output
// (t/AFCP_N.out). Run: php tests/afcp.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
];
$t = testDir();
$testname = 'AFCP';

// test 2: Extract information from AFCP.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('AFCP.jpg'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test writing a bunch of information
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/IPTC.jpg");
    $testfile = "t/{$testname}_3_failed.jpg";
    @unlink($testfile);
    $et->WriteInfo("$t/images/AFCP.jpg", $testfile);
    $info = $et->ImageInfo($testfile, ['Group1' => 'IPTC2']);
    if (check($et, $info, $testname, 3)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
