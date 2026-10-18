<?php

// Replays ExifTool's t/InDesign.t through the PHP API and compares with its expected output
// (t/InDesign_N.out). Run: php tests/indesign.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'InDesign';

// test 2: Extract information from InDesign.indd
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('InDesign.indd'));

    return check($et, $info, $testname, 2);
}, $skip);

// tests 3-4: Write some XMP tags then delete all XMP (writes empty XMP record)
$et = new ExifTool();
$testfile = "t/{$testname}_3_failed.indd";
$not = false;
runTest($testname, 3, function () use ($t, $testname, $et, $testfile, &$not) {
    $et->SetNewValue('Author', 'Phil Harvey');
    $et->SetNewValue('ImageDescription', 'A description');
    @unlink($testfile);
    $et->WriteInfo("$t/images/InDesign.indd", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 3)) {
        $not = true;

        return false;
    }

    return true;
}, $skip);

runTest($testname, 4, function () use ($testname, $et, $testfile, &$not) {
    $et->Options('PrintConv', 0);
    $et->SetNewValue();
    $et->SetNewValue('XMP:*');
    $testfile2 = "t/{$testname}_4_failed.indd";
    @unlink($testfile2);
    $et->WriteInfo($testfile, $testfile2);
    $info = $et->ImageInfo($testfile2);
    if (!check($et, $info, $testname, 4)) {
        return false;
    }
    if (!$not) {
        @unlink($testfile);
    }
    @unlink($testfile2);

    return true;
}, $skip);

exit(testsDone());
