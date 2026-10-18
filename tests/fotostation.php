<?php

// Replays ExifTool's t/FotoStation.t through the PHP API and compares with its expected output
// (t/FotoStation_N.out). Run: php tests/fotostation.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$testname = 'FotoStation';

// test 2: Extract information from FotoStation.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('FotoStation.jpg'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test writing some information
runTest($testname, 3, function () use ($testname) {
    $writeInfo = [
        ['Rotation', 0],
        ['Keywords', 'FotoStation'],
    ];

    return writeCheck($writeInfo, $testname, 3, null, 1);
}, $skip);

exit(testsDone());
