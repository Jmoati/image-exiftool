<?php

// Replays ExifTool's t/GIF.t through the PHP API and compares with its expected output
// (t/GIF_N.out). Run: php tests/gif.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\ScalarRef;

$skip = [];
$t = testDir();
$testname = 'GIF';
$gifImage = new ScalarRef(file_get_contents("$t/images/GIF.gif"));

// test 2: GIF file using data in memory
runTest($testname, 2, function () use ($testname, $gifImage) {
    $info = (new ExifTool())->ImageInfo($gifImage);

    return check($info, $testname, 2);
}, $skip);

// tests 3-5: Test adding/editing/deleting various types of metadata for GIF images in memory
$et = new ExifTool();
$image1 = new ScalarRef(null);
$image2 = new ScalarRef(null);
runTest($testname, 3, function () use ($testname, $gifImage, $et, $image1) {
    $et->SetNewValue('Comment', 'a new comment');
    $et->SetNewValue('City', 'Kingston');
    $et->WriteInfo($gifImage, $image1);
    $info = (new ExifTool())->ImageInfo($image1);

    return check($info, $testname, 3);
}, $skip);

runTest($testname, 4, function () use ($testname, $et, $image1, $image2) {
    $et->SetNewValue();         // clear previous new values
    $et->SetNewValue('all');    // delete everything...
    // add back some XMP tags
    $et->SetNewValue('Comment', 'x');
    $et->SetNewValue('Subject', ['one', 'two', 'three']);
    $et->SetNewValue('Country', 'Canada');
    $et->WriteInfo($image1, $image2);
    $info = (new ExifTool())->ImageInfo($image2);

    return check($info, $testname, 4);
}, $skip);

runTest($testname, 5, function () use ($testname, $gifImage, $et, $image2) {
    $info = (new ExifTool())->ImageInfo($gifImage, 'Comment', 'XMP', 'ICC_Profile');
    $et->SetNewValue();         // clear previous new values
    $et->SetNewValue('Comment', $info['Comment']);
    $et->SetNewValue('XMP', $info['XMP'], Protected: 1);
    $et->SetNewValue('ICC_Profile', $info['ICC_Profile'], Protected: 1);
    $image3 = new ScalarRef(null);
    $et->WriteInfo($image2, $image3);
    $testfile = "t/{$testname}_5_failed.gif";
    if ($image3->value === $gifImage->value) {
        @unlink($testfile);

        return true;
    }
    file_put_contents($testfile, $image3->value);   // save the bad image

    return false;
}, $skip, true);

exit(testsDone());
