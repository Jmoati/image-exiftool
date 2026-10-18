<?php

// Replays ExifTool's t/Nikon.t through the PHP API and compares with its expected output
// (t/Nikon_N.out). Run: php tests/nikon.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [
    8 => 'NikonCapture writer (and IPTC in the NEF)',
];
$t = testDir();
$testname = 'Nikon';

// test 2: Extract information from Nikon.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Nikon.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['Creator', 'Phil'],
        ['ImageAdjustment', 'Yes, lots of it'],
    ], $testname, 3);
}, $skip);

// test 4: Test writing all D70 image information
runTest($testname, 4, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/NikonD70.jpg");
    $testfile = "t/{$testname}_4_failed.jpg";
    @unlink($testfile);
    $et->WriteInfo("$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (check($et, $info, $testname, 4)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 5: Extract information from a D2Hs image
runTest($testname, 5, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('NikonD2Hs.jpg'));

    return check($et, $info, $testname, 5);
}, $skip);

// test 6: Test Nikon decryption
runTest($testname, 6, function () {
    $data = pack('N', 0x34a290d3);
    $data = Image\ExifTool\Nikon::Decrypt($data, null, null, 0x12345678, 0x00000123);
    $got = unpack('N', $data)[1];

    return $got == 0xcae17d2f;
}, $skip, true);

// test 7: Test reading NEF image
runTest($testname, 7, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('Nikon.nef'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 7);
}, $skip);

// test 8: Test writing Nikon Capture information in NEF image
runTest($testname, 8, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('IgnoreMinorErrors', 1);
    $et->SetNewValue('PhotoEffects', 'Off');
    $et->SetNewValue('Caption-abstract', 'A new caption');
    $et->SetNewValue('VignetteControlIntensity', '70');
    $testfile = "t/{$testname}_8_failed.nef";
    @unlink($testfile);
    $et->WriteInfo("$t/images/Nikon.nef", $testfile);
    $info = $et->ImageInfo($testfile, 'PhotoEffects', 'Caption-abstract', 'VignetteControlIntensity');
    if (check($et, $info, $testname, 8)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
