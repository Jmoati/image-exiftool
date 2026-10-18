<?php

// Replays ExifTool's t/FujiFilm.t through the PHP API and compares with its expected output
// (t/FujiFilm_N.out). Run: php tests/fujifilm.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'FujiFilm';

// test 2: Extract information from FujiFilm.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('FujiFilm.jpg'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    return writeCheck([
        ['CreateDate', '2005:01:06 11:51:09'],
        ['WhiteBalance', 'day white', 'Group' => 'MakerNotes'],
    ], $testname, 3);
}, $skip);

// test 4: Extract information from FujiFilm.raf
runTest($testname, 4, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('FujiFilm.raf'), '-filename', '-directory', '-filemodifydate', '-fileaccessdate',
        '-filecreatedate', '-fileinodechangedate', '-filepermissions', ['Duplicates' => 1]);

    return check($et, $info, $testname, 4);
}, $skip);

// tests 5-6: Write writing a RAF and changing it back again in memory
runTest($testname, 5, function () use ($t, $testname) {
    $et = new ExifTool();
    // set IgnoreMinorErrors option to allow invalid JpgFromRaw to be written
    $et->SetNewValue('UserComment', 'test comment');
    $testfile = "t/{$testname}_5_failed.raf";
    @unlink($testfile);
    $et->WriteInfo("$t/images/FujiFilm.raf", $testfile);
    $info = $et->ImageInfo($testfile, 'UserComment');
    $ok = check($et, $info, $testname, 5);
    // pad out comment to make image the same size as the original
    $et->SetNewValue('UserComment', str_repeat(' ', 248));
    $outfile = new Image\ExifTool\ScalarRef('');
    $et->WriteInfo($testfile, $outfile);
    $info = $et->ImageInfo($outfile, ['Duplicates' => 1]);
    if (check($et, $info, $testname, 6, 4) and $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
