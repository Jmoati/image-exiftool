<?php

// Replays ExifTool's t/CanonVRD.t through the PHP API and compares with its expected output
// (t/CanonVRD_N.out). Run: php tests/canonvrd.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)
// Like in t/CanonVRD.t, a test may rely on the file written by a previous one.

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
    5 => 'CanonRaw (CIFF in APP0) and MIE writers (ExifTool.jpg)',
    6 => 'CanonRaw writer (WriteCR2)',
    7 => 'CanonRaw writer (WriteCRW)',
    9 => 'CanonRaw (CIFF in APP0) and MIE writers (ExifTool.jpg)',
    16 => 'CanonRaw (CIFF in APP0) and MIE writers (ExifTool.jpg)',
    17 => 'CanonRaw writer (WriteCR2)',
    18 => 'CanonRaw writer (WriteCRW)',
    23 => 'relies on test 17',
];
$t = testDir();
$testname = 'CanonVRD';

// short list of tags to check in tests
$checkTags = ['FileSize', 'Warning', 'VRDVersion', 'VRDOffset'];
$checkDR4 = ['FileSize', 'Warning', 'GammaBlackPoint', 'RedHSL', 'GreenHSL', 'SharpnessAdjOn'];

// test 2: Extract information from CanonVRD.vrd
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('CanonVRD.vrd'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test writing some information
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/ExifTool.jpg");
    $et->SetNewValue('xmp:*');
    $testfile = "t/{$testname}_3_failed.vrd";
    @unlink($testfile);
    $et->WriteInfo("$t/images/CanonVRD.vrd", $testfile);
    $info = $et->ImageInfo($testfile);
    if (check($et, $info, $testname, 3)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// tests 4-8: Write CanonVRD as a block to various images
$et = new ExifTool();
$et->SetNewValuesFromFile("$t/images/CanonVRD.vrd", 'CanonVRD');
$et->Options('PrintConv', 0);
foreach (['Writer.jpg' => 4, 'ExifTool.jpg' => 5, 'CanonRaw.cr2' => 6, 'CanonRaw.crw' => 7, 'CanonVRD.vrd' => 8] as $file => $testnum) {
    runTest($testname, $testnum, function () use ($et, $t, $testname, $testnum, $file, $checkTags) {
        if ('CanonVRD.vrd' === $file) {
            $et->SetNewValuesFromFile("$t/images/ExifTool.jpg", 'CanonVRD');
        }
        $ext = preg_replace('/^\w+/', '', $file);
        $testfile = "t/{$testname}_{$testnum}_failed$ext";
        @unlink($testfile);
        $et->WriteInfo("$t/images/$file", $testfile);
        $info = $et->ImageInfo($testfile, ...$checkTags);
        if (check($et, $info, $testname, $testnum)) {
            @unlink($testfile);

            return true;
        }

        return false;
    }, $skip);
}

// test 9: Delete VRD as a block
runTest($testname, 9, function () use ($t, $testname, $checkTags) {
    $et = new ExifTool();
    $et->SetNewValue('CanonVRD', null, Protected: 1);
    $testfile = "t/{$testname}_9_failed.jpg";
    @unlink($testfile);
    $et->WriteInfo("$t/images/ExifTool.jpg", $testfile);
    $et->Options('PrintConv', 0);
    $info = $et->ImageInfo($testfile, ...$checkTags);
    if (check($et, $info, $testname, 9)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 10: Create a VRD file from scratch
runTest($testname, 10, function () use ($t, $testname, $checkTags) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/ExifTool.jpg", 'CanonVRD');
    $et->Options('PrintConv', 0);
    $testfile = "t/{$testname}_10_failed.vrd";
    @unlink($testfile);
    $et->WriteInfo(null, $testfile);
    $info = $et->ImageInfo($testfile, ...$checkTags);
    if (check($et, $info, $testname, 10, 8)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 11-12: Add XMP to a VRD file
$et = new ExifTool();
$et->SetNewValue('XMP:Title', 'XMP in VRD test');
foreach ([11 => "$t/images/CanonVRD.vrd", 12 => null] as $testnum => $srcfile) {
    runTest($testname, $testnum, function () use ($et, $testname, $testnum, $srcfile) {
        $testfile = "t/{$testname}_{$testnum}_failed.vrd";
        @unlink($testfile);
        $et->WriteInfo($srcfile, $testfile);
        $info = $et->ImageInfo($testfile);
        if (check($et, $info, $testname, $testnum)) {
            @unlink($testfile);

            return true;
        }

        return false;
    }, $skip);
}

// test 13: Extract information from CanonVRD.dr4
runTest($testname, 13, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('CanonVRD.dr4'));

    return check($et, $info, $testname, 13);
}, $skip);

// test 14: Test writing to DR4
runTest($testname, 14, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('PrintConv', 0);
    $et->SetNewValue('CropX', 123);
    $et->SetNewValue('SharpnessAdjOn', 0);
    $et->SetNewValue('RedHSL', '-4.3 1.2 3.8');
    $et->SetNewValue('CanonVRD:GammaBlackPoint', '1.234');
    $testfile = "t/{$testname}_14_failed.dr4";
    @unlink($testfile);
    $et->WriteInfo("$t/images/CanonVRD.dr4", $testfile);
    $info = $et->ImageInfo($testfile, '-filename');

    return check($et, $info, $testname, 14);
}, $skip);

// tests 15-21: Write CanonDR4 as a block to various images
$et = new ExifTool();
$srcfile14 = "t/{$testname}_14_failed.dr4";
$et->SetNewValuesFromFile($srcfile14, 'CanonDR4');
$et->Options('PrintConv', 0);
$files = ['Writer.jpg', 'ExifTool.jpg', 'CanonRaw.cr2', 'CanonRaw.crw', 'CanonVRD.vrd', 'CanonVRD.dr4', 'CanonRaw.cr3'];
foreach ($files as $i => $file) {
    $testnum = 15 + $i;
    runTest($testname, $testnum, function () use ($et, $t, $testname, $testnum, $file, $checkDR4, $srcfile14) {
        $ext = preg_replace('/^\w+/', '', $file);
        $testfile = "t/{$testname}_{$testnum}_failed$ext";
        @unlink($testfile);
        $et->WriteInfo("$t/images/$file", $testfile);
        $info = $et->ImageInfo($testfile, ...$checkDR4);
        if (check($et, $info, $testname, $testnum)) {
            if (!in_array($testnum, [15, 17, 21], true)) @unlink($testfile);
            if (20 === $testnum) @unlink($srcfile14);

            return true;
        }

        return false;
    }, $skip);
}

// test 22: Delete DR4(VRD) as a block
runTest($testname, 22, function () use ($testname, $checkDR4) {
    $et = new ExifTool();
    $srcfile = "t/{$testname}_15_failed.jpg";
    $et->SetNewValue('CanonDR4', null, Protected: 1);
    $testfile = "t/{$testname}_22_failed.jpg";
    @unlink($testfile);
    $et->WriteInfo($srcfile, $testfile);
    $et->Options('PrintConv', 0);
    $info = $et->ImageInfo($testfile, ...$checkDR4);
    if (check($et, $info, $testname, 22)) {
        @unlink($testfile);
        @unlink($srcfile);

        return true;
    }

    return false;
}, $skip);

// test 23: Create a DR4 file from scratch
runTest($testname, 23, function () use ($testname) {
    $et = new ExifTool();
    $et->Options('PrintConv', 0);
    $srcfile = "t/{$testname}_17_failed.cr2";
    $et->SetNewValuesFromFile($srcfile, 'CanonDR4');
    $testfile = "t/{$testname}_23_failed.dr4";
    @unlink($testfile);
    $et->WriteInfo(null, $testfile);
    $info = $et->ImageInfo($testfile, '-filename');
    if (check($et, $info, $testname, 23, 14)) {
        @unlink($testfile);
        @unlink($srcfile);

        return true;
    }

    return false;
}, $skip);

// test 24: Edit DR4 information in CR3 image
runTest($testname, 24, function () use ($testname, $checkDR4) {
    $srcfile = "t/{$testname}_21_failed.cr3";
    $ok = writeCheck([['CanonVRD:GammaBlackPoint', 1.5]], $testname, 24, $srcfile, $checkDR4);
    @unlink($srcfile);

    return $ok;
}, $skip);

exit(testsDone());
