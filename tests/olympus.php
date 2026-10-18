<?php

// Replays ExifTool's t/Olympus.t through the PHP API and compares with its expected output
// (t/Olympus_N.out). Run: php tests/olympus.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Olympus';

// test 2: Extract information from Olympus.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Olympus.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['Software', 'ExifTool', 'Group' => 'XMP'],
        ['Macro', 'On'],
    ], $testname, 3);
}, $skip);

// test 4: Extract information from OlympusE1.jpg
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('OlympusE1.jpg'));

    return check($et, $info, $testname, 4);
}, $skip);

// test 5: Rewrite Olympus E1 image
runTest($testname, 5, function () use ($t, $testname) {
    return writeCheck([
        ['LensSerialNumber', '012345678'],
        ['CoringFilter', 0],
    ], $testname, 5, "$t/images/OlympusE1.jpg");
}, $skip);

// test 6: Test reading Olympus type 2 maker notes
runTest($testname, 6, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Olympus2.jpg'));

    return check($et, $info, $testname, 6);
}, $skip);

// test 7: Rewrite type 2 maker notes
runTest($testname, 7, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_7_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('FocusDistance', 100);
    $et->SetNewValue('Macro', 'On');
    $et->WriteInfo("$t/images/Olympus2.jpg", $testfile);
    if (testVerbose($testname, 7, $testfile, 2)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 8: Extract information from Olympus.dss
runTest($testname, 8, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Olympus.dss'));

    return check($et, $info, $testname, 8);
}, $skip);

exit(testsDone());
