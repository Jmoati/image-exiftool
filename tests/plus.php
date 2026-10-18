<?php

// Replays ExifTool's t/PLUS.t through the PHP API and compares with its expected output
// (t/PLUS_N.out). Run: php tests/plus.php [~/exiftool] [--all]

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$testname = 'PLUS';

// test 2: Extract information from PLUS.xmp
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PLUS.xmp'), 'xmp:all');

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Copy PLUS information to a new file
runTest($testname, 3, function () use ($testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile(testImage('PLUS.xmp'), 'all:all');
    $testfile = "t/{$testname}_3_failed.xmp";
    @unlink($testfile);
    $ok = writeInfo($et, null, $testfile);
    $info = $et->ImageInfo($testfile, 'xmp:all');
    if (check($et, $info, $testname, 3, 2) && $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
