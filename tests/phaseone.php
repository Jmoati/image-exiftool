<?php

// Replays ExifTool's t/PhaseOne.t through the PHP API and compares with its expected output
// (t/PhaseOne_N.out). Run: php tests/phaseone.php [~/exiftool] [--all]

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'PhaseOne';

// test 2: Extract information from PhaseOne.iiq
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PhaseOne.iiq'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['SerialNumber', '1234'],
    ], $testname, 3, "$t/images/PhaseOne.iiq");
}, $skip);

exit(testsDone());
