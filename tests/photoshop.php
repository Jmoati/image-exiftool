<?php

// Replays ExifTool's t/Photoshop.t through the PHP API and compares with its expected output
// (t/Photoshop_N.out). Run: php tests/photoshop.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
    3 => 'EXIF writer (XResolution)',
];
$t = testDir();
$testname = 'Photoshop';

// test 2: Extract information from Photoshop.psd
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Photoshop.psd'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    $writeInfo = [
        ['XResolution', '120'],
        ['YResolution', '120'],
        ['Creator', 'Phil Harvey'],
        ['By-Line', 'Phil Again'],
        ['PhotoMechanic:Tagged', 'No'],
    ];

    return writeCheck($writeInfo, $testname, 3, "$t/images/Photoshop.psd");
}, $skip);

exit(testsDone());
