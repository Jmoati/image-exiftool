<?php

// Replays ExifTool's t/FLIF.t through the PHP API and compares with its expected output
// (t/FLIF_N.out). Run: php tests/flif.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'FLIF';

$checkTags = ['Artist', 'Creator', 'XResolution', 'ProfileCMMType', 'XMP'];

// test 2: Extract information from FLIF.flif
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('FLIF.flif'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Edit FLIF information
runTest($testname, 3, function () use ($t, $testname, $checkTags) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/XMP.jpg", 'ICC_Profile');
    $et->SetNewValue('EXIF:XResolution', 234);
    $et->SetNewValue('XMP:Creator', 'just me');
    $testfile = "t/{$testname}_3_failed.flif";
    @unlink($testfile);
    $et->WriteInfo("$t/images/FLIF.flif", $testfile);
    $info = $et->ImageInfo($testfile, ...$checkTags);
    if (check($et, $info, $testname, 3)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 4: Delete FLIF information
$testfile = null;
runTest($testname, 4, function () use ($t, $testname, &$testfile) {
    $et = new ExifTool();
    $et->SetNewValue('ICC_Profile', null, Protected: 1);
    $et->SetNewValue('EXIF', null, Protected: 1);
    $et->SetNewValue('XMP:all', null);
    $testfile = "t/{$testname}_4_failed.flif";
    @unlink($testfile);
    $et->WriteInfo("$t/images/FLIF.flif", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 4)) {
        $testfile = null;

        return false;
    }

    return true;
}, $skip);

// test 5: Add back FLIF information
runTest($testname, 5, function () use ($t, $testname, $checkTags, $testfile) {
    if (null === $testfile) {
        echo "skip $testname 5 (requires test 4 pass)\n";

        return true;
    }
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/Photoshop.psd", 'ICC_Profile');
    $et->SetNewValue('EXIF:XResolution', 123);
    $et->SetNewValue('XMP:Creator', 'me again');
    $testfile2 = "t/{$testname}_5_failed.flif";
    @unlink($testfile2);
    $et->WriteInfo($testfile, $testfile2);
    $info = $et->ImageInfo($testfile2, ...$checkTags);
    if (check($et, $info, $testname, 5)) {
        @unlink($testfile);
        @unlink($testfile2);

        return true;
    }

    return false;
}, $skip);

// test 6: Delete all then add back in one step
runTest($testname, 6, function () use ($t, $testname, $checkTags) {
    $et = new ExifTool();
    $et->SetNewValue('all', null);
    $et->SetNewValuesFromFile("$t/images/Photoshop.psd", 'ICC_Profile');
    $et->SetNewValue('EXIF:XResolution', 456);
    $et->SetNewValue('XMP:Creator', 'me too');
    $testfile = "t/{$testname}_6_failed.flif";
    @unlink($testfile);
    $et->WriteInfo("$t/images/FLIF.flif", $testfile);
    $info = $et->ImageInfo($testfile, ...$checkTags);
    if (check($et, $info, $testname, 6)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
