<?php

// Replays ExifTool's t/Lang.t through the PHP API and compares with its expected output
// (t/Lang_N.out). Run: php tests/lang.php [~/exiftool]

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Vars\ExifTool as ExifToolVars;

$t = testDir();
$fail = 0;
$testnum = 0;
// test 1: localized language description for a lang-alt tag
++$testnum;
$et = new ExifTool();
$et->Options('Lang', 'de');
$fail += !check($et, $et->ImageInfo("$t/images/MIE.mie", 'Comment-fr_FR'), 'Lang', $testnum);
// tests 2-N: all languages
$et = new ExifTool();
foreach (ExifToolVars::$langs as $lang) {
    if ('en' === $lang) {
        continue;   // skip english
    }
    ++$testnum;
    $et->Options('Lang', $lang);
    if ($et->Options('Lang') === $lang) {
        $fail += !check($et, $et->ImageInfo("$t/images/FujiFilm.jpg", 'Exif:All'), 'Lang', $testnum);
    } else {
        echo "FAIL Lang $testnum (error loading language $lang)\n";
        ++$fail;
    }
}
echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
