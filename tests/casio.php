<?php

// Replays ExifTool's t/Casio.t through the PHP API and compares with its expected output
// (t/Casio_N.out). Run: php tests/casio.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Casio';

// tests 2-4: Extract information from Casio images
foreach (['Casio.jpg', 'Casio2.jpg', 'CasioQVCI.jpg'] as $i => $file) {
    runTest($testname, 2 + $i, function () use ($testname, $file, $i) {
        $et = new ExifTool();
        $info = $et->ImageInfo(testImage($file));

        return check($et, $info, $testname, 2 + $i);
    }, $skip);
}

// test 5: Write some new information
runTest($testname, 5, function () use ($t, $testname) {
    return writeCheck([
        ['MaxApertureValue', 4],
        ['FocusMode', 'Macro'],
    ], $testname, 5);
}, $skip);

// test 6: Write some new information in type 2 file
runTest($testname, 6, function () use ($t, $testname) {
    return writeCheck([
        ['XResolution', 300],
        ['YResolution', 300],
        ['ObjectDistance', '3.5'],
    ], $testname, 6, "$t/images/Casio2.jpg");
}, $skip);

exit(testsDone());
