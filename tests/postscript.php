<?php

// Replays ExifTool's t/PostScript.t through the PHP API and compares with its expected output
// (t/PostScript_N.out). Run: php tests/postscript.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'PostScript';

// test 2: Extract information from PostScript.eps
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PostScript.eps'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write EPS information (and test ExtractEmbedded option)
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/IPTC.jpg", '*:*');
    $et->SetNewValuesFromFile("$t/images/XMP.jpg", '*:*');
    $et->SetNewValue('Title', 'new title');
    $et->SetNewValue('Copyright', 'my copyright');
    $et->SetNewValue('Creator', 'phil made it', Replace: 1);
    $testfile = "t/{$testname}_3_failed.eps";
    @unlink($testfile);
    $et->WriteInfo(testImage('PostScript.eps'), $testfile);
    $info = $et->ImageInfo($testfile, ['ExtractEmbedded' => 1]);
    if (!check($et, $info, $testname, 3, 3, 3)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

exit(testsDone());
