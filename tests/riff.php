<?php

// Replays ExifTool's t/RIFF.t through the PHP API and compares with its expected output
// (t/RIFF_N.out). Run: php tests/riff.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'RIFF';

// tests 2-4: Extract information from RIFF.wav, RIFF.avi and RIFF.webp
foreach (['wav', 'avi', 'webp'] as $i => $ext) {
    runTest($testname, 2 + $i, function () use ($testname, $ext, $i) {
        $et = new ExifTool();
        $info = $et->ImageInfo(testImage("RIFF.$ext"));

        return check($et, $info, $testname, 2 + $i);
    }, $skip);
}

// test 5: Edit EXIF and XMP
$testfile = "t/{$testname}_5_failed.webp";
runTest($testname, 5, function () use ($t, $testname, $testfile) {
    $et = new ExifTool();
    $et->SetNewValue('exif:usercomment', 'test comment');
    $et->SetNewValue('xmp:description', 'test description');
    @unlink($testfile);
    writeInfo($et, "$t/images/RIFF.webp", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 5)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

// test 6: Delete all metadata from a WebP file
$testfile = "t/{$testname}_6_failed.webp";
runTest($testname, 6, function () use ($t, $testname, $testfile) {
    $et = new ExifTool();
    $et->SetNewValue('all');
    @unlink($testfile);
    writeInfo($et, "$t/images/RIFF.webp", $testfile);
    $info = $et->ImageInfo($testfile);

    return check($et, $info, $testname, 6);
}, $skip);

// test 7: Add back WebP information
runTest($testname, 7, function () use ($testname, $testfile) {
    $et = new ExifTool();
    $et->SetNewValue('exif:usercomment', 'test comment 2');
    $et->SetNewValue('xmp:description', 'test description 2');
    $testfile2 = "t/{$testname}_7_failed.webp";
    @unlink($testfile2);
    writeInfo($et, $testfile, $testfile2);
    $info = $et->ImageInfo($testfile2);
    if (!check($et, $info, $testname, 7)) {
        return false;
    }
    @unlink($testfile);
    @unlink($testfile2);

    return true;
}, $skip);

exit(testsDone());
