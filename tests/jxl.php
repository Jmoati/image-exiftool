<?php

// Replays ExifTool's t/JXL.t through the PHP API and compares with its expected output
// (t/JXL_N.out). Run: php tests/jxl.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'JXL';

// test 2: Extract information from JXL.jxl
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('JXL.jxl'), '-system:all');

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Write some new information
runTest($testname, 3, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_3_failed.jxl";
    @unlink($testfile);
    $writeInfo = [
        ['XMP:Subject', 'test subject'],
        ['EXIF:Artist', 'test artist'],
    ];
    $et->Options('IgnoreMinorErrors', 1);
    foreach ($writeInfo as $args) {
        $et->SetNewValue(...$args);
    }
    $ok = writeInfo($et, "$t/images/JXL.jxl", $testfile, null, 1);
    $info = $et->ImageInfo($testfile, '-system:all');
    if (check($et, $info, $testname, 3) and $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 4: Read compressed metadata and ImageSize from partial JXL codestream
runTest($testname, 4, function () use ($testname) {
    // (Perl: requires IO::Uncompress::Brotli version 0.004 or later)
    if (!function_exists('brotli_uncompress')) {
        echo "skip $testname 4 (requires the brotli extension)\n";

        return true;
    }
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('JXL2.jxl'), 'imagesize', 'exif:all', 'xmp:all');

    return check($et, $info, $testname, 4);
}, $skip);

exit(testsDone());
