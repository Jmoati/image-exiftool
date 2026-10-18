<?php

// Replays ExifTool's t/Canon.t through the PHP API and compares with its expected output
// (t/Canon_N.out). Run: php tests/canon.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Canon';

// test 2: Extract information from Canon1DmkIII.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Canon1DmkIII.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write CanonCustom2 information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['ISOSpeedRange', 'Enable; 1600; 200'],
        ['TimerLength', 'Enable; 6 sec: 5; 16 sec: 20; After release: 6'],
    ], $testname, 3, "$t/images/Canon1DmkIII.jpg", ['ISOSpeedRange', 'TimerLength', 'OriginalDecisionData', 'Warning']);
}, $skip);

exit(testsDone());
