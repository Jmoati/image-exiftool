<?php

// Replays ExifTool's t/MWG.t through the PHP API and compares with its expected output
// (t/MWG_N.out). Run: php tests/mwg.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\MWG;

$skip = [
    3 => 'EXIF writer',
    4 => 'EXIF writer',
];
$t = testDir();
$testname = 'MWG';

// test 1: Load the module(s)
MWG::Load();

// test 2: Extract MWG information from test image
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $et->Options('Duplicates', 0);
    $info = $et->ImageInfo(testImage('MWG.jpg'), 'MWG:*');

    return check($et, $info, $testname, 2);
}, $skip);

// tests 3-4: Write some MWG tags
$et = new ExifTool();
$et->SetNewValue('MWG:DateTimeOriginal', '2009:10:25 15:13:44.567-04:00');
$et->SetNewValue('MWG:Creator', 'Creator One');
$et->SetNewValue('MWG:Creator', 'Creator Two');
$et->SetNewValue('MWG:City', 'Some city');
$tags = [
    'EXIF:DateTimeOriginal', 'EXIF:SubSecTimeOriginal',
    'IPTC:DateCreated', 'IPTC:TimeCreated', 'XMP-photoshop:DateCreated',
    'EXIF:Artist', 'IPTC:By-line', 'XMP-dc:Creator', 'IPTC:City',
    'XMP-photoshop:City', 'XMP-iptcExt:LocationShownCity',
];
foreach (['MWG.jpg' => 3, 'Writer.jpg' => 4] as $src => $testnum) {
    runTest($testname, $testnum, function () use ($et, $t, $testname, $testnum, $src, $tags) {
        $testfile = "t/{$testname}_{$testnum}_failed.jpg";
        @unlink($testfile);
        $et->WriteInfo("$t/images/$src", $testfile);
        $info = $et->GetInfo('Warning');
        if ($info['Warning'] ?? null) {
            fwrite(STDERR, "\n    Warning: {$info['Warning']}\n");

            return false;
        }
        $info = $et->ImageInfo($testfile, ...$tags);
        if (check($et, $info, $testname, $testnum)) {
            @unlink($testfile);

            return true;
        }

        return false;
    }, $skip);
}

// test 5: Extract IPTC information from non-standard image while in strict MWG mode
runTest($testname, 5, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('ExifTool.jpg'), 'IPTC:*', 'Warning');

    return check($et, $info, $testname, 5);
}, $skip);

// test 6: Copy a tag with MWG feature active
runTest($testname, 6, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/MWG.jpg", 'Creator');
    $testfile = "t/{$testname}_6_failed.xmp";
    @unlink($testfile);
    $et->WriteInfo(null, $testfile);
    $info = $et->ImageInfo($testfile, 'Creator');
    if (check($et, $info, $testname, 6)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 7: Extract MWG information from ExifTool.jpg
runTest($testname, 7, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('ExifTool.jpg'), 'MWG:*');

    return check($et, $info, $testname, 7);
}, $skip);

exit(testsDone());
