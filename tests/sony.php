<?php

// Replays ExifTool's t/Sony.t through the PHP API and compares with its expected output
// (t/Sony_N.out). Run: php tests/sony.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'Sony';

// test 2: Extract information from Sony.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Sony.jpg'), ['Unknown' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['FlashFired', 'true'],
        ['ISO', null],
    ], $testname, 3);
}, $skip);

// test 4: Test Sony decryption
runTest($testname, 4, function () {
    $data = pack('N', 0x34a290d3);
    Image\ExifTool\Sony::Decrypt($data, 0, 4, 0x12345678);
    $got = unpack('N', $data)[1];

    return $got == 0x20677968;
}, $skip, true);

// test 5: Extract information from a PMP image
runTest($testname, 5, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Sony.pmp'));

    return check($et, $info, $testname, 5);
}, $skip);

exit(testsDone());
