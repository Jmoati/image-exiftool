<?php

// Replays ExifTool's t/CanonRaw.t through the PHP API and compares with its expected output
// (t/CanonRaw_N.out). Run: php tests/canonraw.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
    4 => 'CanonRaw writer (WriteCRW)',
    6 => 'CanonRaw writer (WriteCR2)',
];
$t = testDir();
$testname = 'CanonRaw';

// test 2: Extract information from CRW
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('CanonRaw.crw'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Extract JpgFromRaw from CRW
runTest($testname, 3, function () {
    $et = new ExifTool();
    $et->Options('PrintConv', 0, 'IgnoreMinorErrors', 1);
    $info = $et->ImageInfo(testImage('CanonRaw.crw'), 'JpgFromRaw');

    return ($info['JpgFromRaw'] ?? null)?->value === '<Dummy JpgFromRaw image data>';
}, $skip, true);

// test 4: Write a whole pile of tags to a CRW
runTest($testname, 4, function () use ($t, $testname) {
    $et = new ExifTool();
    // set IgnoreMinorErrors option to allow invalid JpgFromRaw to be written
    $et->Options('IgnoreMinorErrors', 1);
    $et->SetNewValuesFromFile("$t/images/Canon.jpg");
    $et->SetNewValue('SerialNumber', 1234);
    $et->SetNewValue('OwnerName', 'Phil Harvey');
    $et->SetNewValue('JpgFromRaw', 'not a real image');
    $et->SetNewValue('ROMOperationMode', 'CDN');
    $et->SetNewValue('FocalPlaneXSize', '35 mm');
    $et->SetNewValue('FocalPlaneYSize', '24 mm');
    $testfile = "t/{$testname}_4_failed.crw";
    @unlink($testfile);
    $et->WriteInfo("$t/images/CanonRaw.crw", $testfile);
    $info = $et->ImageInfo($testfile);
    if (check($et, $info, $testname, 4)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 5: Test verbose output
runTest($testname, 5, function () use ($testname) {
    return testVerbose($testname, 5, testImage('CanonRaw.crw'), 1);
}, $skip);

// test 6: Write to CR2 file
runTest($testname, 6, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('Keywords', 'CR2 test');
    $et->SetNewValue('OwnerName', 'Phil Harvey');
    $et->SetNewValue('FocalPlaneXSize', '35mm');
    $testfile = "t/{$testname}_6_failed.cr2";
    @unlink($testfile);
    $et->WriteInfo("$t/images/CanonRaw.cr2", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 6)) {
        return false;
    }
    // make sure file suffix was copied properly
    $endStr = '<Dummy preview image data>Non-TIFF data test';
    $data = (string) @file_get_contents($testfile);
    $buff = substr($data, -strlen($endStr));
    if ($buff !== $endStr) {
        fwrite(STDERR, "\n  Test 6 failed to copy file suffix:\n    Test gave: '$buff'\n    Should be: '$endStr'\n");

        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

// test 7: Test copying all information from a CR2 image to a JPEG
runTest($testname, 7, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/CanonRaw.cr2");
    $testfile = "t/{$testname}_7_failed.jpg";
    @unlink($testfile);
    $et->WriteInfo("$t/images/Writer.jpg", $testfile);
    $et->Options('Unknown', 1);
    $info = $et->ImageInfo($testfile);
    if (check($et, $info, $testname, 7)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 8: Extract information from a CR3 image
runTest($testname, 8, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('CanonRaw.cr3'));

    return check($et, $info, $testname, 8);
}, $skip);

// test 9: Write to CR3 file
runTest($testname, 9, function () use ($t, $testname) {
    return writeCheck([
        ['Subject', 'CR3 test'],
        ['ExposureCompensation', -1.3],
    ], $testname, 9, "$t/images/CanonRaw.cr3");
}, $skip);

exit(testsDone());
