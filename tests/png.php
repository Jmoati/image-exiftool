<?php

// Replays ExifTool's t/PNG.t through the PHP API and compares with its expected output
// (t/PNG_N.out). Run: php tests/png.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\ScalarRef;

$skip = [];
$t = testDir();
$testname = 'PNG';

// test 2: Extract information from PNG.png
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PNG.png'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write a bunch of new information to a PNG in memory
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/IPTC.jpg");
    $et->SetNewValuesFromFile("$t/images/XMP.jpg");
    $et->SetNewValue('PNG:Comment');    // and delete a tag
    $et->SetNewValue('PixelsPerUnitX', 1234);
    $image = new ScalarRef(null);
    $et->WriteInfo("$t/images/PNG.png", $image);
    // must ignore FileSize because size is variable (depends on Zlib availability)
    $info = $et->ImageInfo($image, '-filesize');
    $testfile = "t/{$testname}_3_failed.png";
    if (check($et, $info, $testname, 3)) {
        @unlink($testfile);     // erase results of any bad test

        return true;
    }
    file_put_contents($testfile, $image->value);    // save the bad image

    return false;
}, $skip);

// test 4: Test group delete, alternate languages and special characters
runTest($testname, 4, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('Charset', 'Latin');
    $et->SetNewValue('PNG:*');
    $et->SetNewValue('XMP:*');
    $et->SetNewValue('PNG:Comment-fr', "Commentaire fran\xe7aise");
    $et->SetNewValue('PNG:Copyright', "\xa9 2010 Phil Harvey");
    $et->SetNewValue('XMP:Description-bar', "A Br\xfcn is a Gst\xf6");
    $testfile = "t/{$testname}_4_failed.png";
    @unlink($testfile);
    $et->WriteInfo("$t/images/PNG.png", $testfile);
    $et->Options('Charset', 'UTF8');
    $info = $et->ImageInfo($testfile, 'PNG:*', 'XMP:*');
    if (check($et, $info, $testname, 4)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 5: Try moving XMP from after IDAT to before
runTest($testname, 5, function () use ($testname) {
    $et = new ExifTool();
    $image = new ScalarRef(null);
    // delete all XMP then copy back again (should move to before IDAT)
    $et->SetNewValue();
    $txtfile = "t/{$testname}_5.failed";
    $fh = fopen($txtfile, 'w');
    $et->Options('Verbose', 2);
    $et->Options('TextOut', $fh);
    $et->SetNewValue('xmp:all');
    $src = testImage('PNG.png');
    $et->SetNewValuesFromFile($src, 'all:all<xmp:all');
    $et->WriteInfo($src, $image);
    fclose($fh);
    $ok = testCompare(testDir()."/{$testname}_5.out", $txtfile, 5);
    echo ($ok ? 'ok   ' : 'FAIL ')."$testname 5\n";

    return $ok;
}, $skip);

// test 6: Write EXIF
runTest($testname, 6, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('EXIF:Artist', 'me');
    $testfile = "t/{$testname}_6_failed.png";
    @unlink($testfile);
    $et->WriteInfo("$t/images/PNG.png", $testfile);
    $et->Options('Charset', 'UTF8');
    $info = $et->ImageInfo($testfile, 'EXIF:*');
    if (check($et, $info, $testname, 6)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 7: Write ICC_Profile with a name
runTest($testname, 7, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/ICC_Profile.icc", 'ICC_Profile');
    $et->SetNewValue('PNG:ProfileName', 'Adobe RGB (1998)');
    $testfile = "t/{$testname}_7_failed.png";
    @unlink($testfile);
    $et->WriteInfo("$t/images/PNG.png", $testfile);
    $info = $et->ImageInfo($testfile, 'ProfileName', 'ProfileCMMType');
    if (check($et, $info, $testname, 7)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
