<?php

// Replays ExifTool's t/PhotoMechanic.t through the PHP API and compares with its expected output
// (t/PhotoMechanic_N.out). Run: php tests/photomechanic.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
    3 => 'XMP writer',
];
$testname = 'PhotoMechanic';

// test 2: Extract information from PhotoMechanic.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PhotoMechanic.jpg'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test writing some information
runTest($testname, 3, function () use ($testname) {
    $writeInfo = [
        ['Rotation', 90],
        ['Keywords', 'PhotoMechanic'],
    ];

    return writeCheck($writeInfo, $testname, 3, null, 1);
}, $skip);

exit(testsDone());
