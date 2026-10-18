<?php

// Replays ExifTool's t/Jpeg2000.t through the PHP API and compares with its expected output
// (t/Jpeg2000_N.out). Run: php tests/jpeg2000.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Jpeg2000';

// test 2: Extract information from Jpeg2000.jp2
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Jpeg2000.jp2'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    $writeInfo = [
        ['IPTC:Keywords', 'test keyword'],
        ['XMP:City', 'a city'],
        ['EXIF:ImageDescription', 'a description'],
        ['XML', '<test>Yippee</test>', 'Protected' => 1],
        ['Jpeg2000:ColorSpace', 'Grayscale', 'Protected' => 1],
        ['ColorSpecPrecedence', 1, 'Protected' => 1],
    ];

    return writeCheck($writeInfo, $testname, 3, "$t/images/Jpeg2000.jp2");
}, $skip);

// test 4: Extract information from Jpeg2000.j2c
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Jpeg2000.j2c'));

    return check($et, $info, $testname, 4);
}, $skip);

// test 5: Extract XML as a block from JP2 image
runTest($testname, 5, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Jpeg2000.jp2'), 'xml');

    return check($et, $info, $testname, 5);
}, $skip);

exit(testsDone());
