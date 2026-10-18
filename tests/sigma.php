<?php

// Replays ExifTool's t/Sigma.t through the PHP API and compares with its expected output
// (t/Sigma_N.out). Run: php tests/sigma.php [~/exiftool] [--all]

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Sigma';

// test 2: Extract information from Sigma.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Sigma.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($testname) {
    return writeCheck([
        ['IPTCPixelWidth', 200],
        ['Sharpness', 2, 'Group' => 'MakerNotes'],
    ], $testname, 3);
}, $skip);

// test 4: Test reading X3F image
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Sigma.x3f'));

    return check($et, $info, $testname, 4);
}, $skip);

// test 5: Test writing X3F image
runTest($testname, 5, function () use ($t, $testname) {
    return writeCheck([
        ['Artist', 'Phil Harvey'],
        ['XMP:Title', 'A title'],
        ['Keywords', ['one', 'two']],
    ], $testname, 5, "$t/images/SigmaDP2.x3f");
}, $skip);

exit(testsDone());
