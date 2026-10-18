<?php

// Replays ExifTool's t/MIE.t through the PHP API and compares with its expected output
// (t/MIE_N.out). Run: php tests/mie.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'MIE';

// test 2: Extract information from MIE.mie
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('MIE.mie'), '-filename', '-directory');

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write MIE information (also test Escape option when writing)
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('IgnoreMinorErrors', 1);   // to copy invalid thumbnail
    $et->SetNewValuesFromFile("$t/images/Nikon.jpg", '*:*');
    $et->SetNewValue('EXIF:XResolution', 200);
    $et->SetNewValue('MIE:FNumber', 11);
    $et->SetNewValue('XMP:Creator', 'phil');
    $et->SetNewValue('IPTC:Keywords', 'cool');
    $et->SetNewValue('MIE:GPSLongitude', -1.5);
    $et->Options('Escape', 'HTML');
    $et->SetNewValue('MIE:PhoneNumber', 'k&uuml;hl');
    $et->Options('Escape', null);
    $testfile = "t/{$testname}_3_failed.mie";
    @unlink($testfile);
    $et->WriteInfo("$t/images/MIE.mie", $testfile);
    $info = $et->ImageInfo($testfile);
    if (check($et, $info, $testname, 3)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 4: Create a MIE file from scratch (also test Escape option when copying)
runTest($testname, 4, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('IgnoreMinorErrors', 1);   // to copy invalid thumbnail
    $et->Options('Escape', 'HTML');
    $et->SetNewValuesFromFile("$t/images/MIE.mie");
    $testfile = "t/{$testname}_4_failed.mie";
    @unlink($testfile);
    $et->WriteInfo(null, $testfile);
    $et->Options('Escape', null);   // reset Escape option
    $info = $et->ImageInfo($testfile, '-filename', '-directory');
    if (check($et, $info, $testname, 4, 2)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// tests 5-6: Test reading different Charsets
foreach (['Latin', 'Cyrillic'] as $i => $charset) {
    $testnum = 5 + $i;
    runTest($testname, $testnum, function () use ($testname, $testnum, $charset) {
        $et = new ExifTool();
        $et->Options('Charset', $charset);
        $info = $et->ImageInfo(testImage('MIE.mie'), 'comment-ru_ru');

        return check($et, $info, $testname, $testnum);
    }, $skip);
}

exit(testsDone());
