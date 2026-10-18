<?php

// Replays ExifTool's t/IPTC.t through the PHP API and compares with its expected output
// (t/IPTC_N.out). Run: php tests/iptc.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;

$skip = [
    4 => 'XMP writer (CreatorCountry)',
];
$t = testDir();
$testname = 'IPTC';

// test 2: Extract information from IPTC.jpg
runTest($testname, 2, function () use ($t, $testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('IPTC.jpg'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test GetValue() in list context
runTest($testname, 3, function () use ($t) {
    $et = new ExifTool();
    $et->ExtractInfo("$t/images/IPTC.jpg", ['JoinLists' => 0]);
    $values = implode('-', $et->GetValueList('Keywords', 'ValueConv'));
    $expected = 'ExifTool-Test-IPTC';
    if ($values !== $expected) {
        fwrite(STDERR, "\n  Test 3 differs with \"$values\"\n");

        return false;
    }

    return true;
}, $skip, true);

// test 4: Test rewriting everything with slightly different values
runTest($testname, 4, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('Duplicates', 1, 'Binary', 1, 'ListJoin', null);
    $info = $et->ImageInfo("$t/images/IPTC.jpg");
    foreach ($info as $tag => $val) {
        $group = $et->GetGroup($tag, 0);
        if (is_array($val) && array_is_list($val)) {
            $val[] = 'v2';
        } elseif ($val instanceof ScalarRef) {
            $val = 'v2';
        } elseif (preg_match('/^\d+(\.\d*)?$/', Perl::str($val), $m)) {
            // (add extra .001 to avoid problem with aperture of 4.85
            //  getting rounded to 4.8 or 4.9 and causing failed tests)
            $val = Perl::num($val) + (Perl::num($val) / 10) + 1.001;
            if (!($m[1] ?? null)) {
                $val = Perl::int($val);
            }
            $val = Perl::str($val);
        } else {
            $val .= '-v2';
        }
        // eat return values so warnings don't get printed
        $et->SetNewValueList((string) $tag, $val, Group: $group, Replace: 1);
    }
    // also try writing a few specific tags
    $et->SetNewValue('CreatorCountry', 'Canada');
    $et->SetNewValue('CodedCharacterSet', 'UTF8', Protected: 1);
    $image = new ScalarRef(null);
    $ok = writeInfo($et, "$t/images/IPTC.jpg", $image, null, 1);
    $et2 = new ExifTool();
    $et2->Options('Duplicates', 1);
    $info = $et2->ImageInfo($image);
    $testfile = "t/{$testname}_4_failed.jpg";
    if (check($et2, $info, $testname, 4) && $ok) {
        @unlink($testfile);

        return true;
    }
    file_put_contents($testfile, $image->value);   // save bad file

    return false;
}, $skip);

// test 5: Test IPTC special characters
runTest($testname, 5, function () use ($t, $testname) {
    $writeInfo = [
        ['IPTC:CopyrightNotice', "\xc2\xa9 2008 Phil Harvey"],
    ];

    return writeCheck($writeInfo, $testname, 5, "$t/images/Writer.jpg", 1);
}, $skip);

// test 6: Write and read using different default IPTC encoding
runTest($testname, 6, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_6_failed.jpg";
    @unlink($testfile);
    $et->Options('Charset', 'Cyrillic');
    $et->SetNewValuesFromFile("$t/images/MIE.mie", 'Comment-ru_RU>Caption-Abstract');
    $et->Options('IPTCCharset', 'Cyrillic');
    $ok = writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $et->Options('Charset', 'UTF8');
    $info = $et->ImageInfo($testfile, 'IPTC:*');
    if (check($et, $info, $testname, 6) && $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 7: Replace an entry in a list
runTest($testname, 7, function () use ($t, $testname) {
    $writeInfo = [
        ['IPTC:Keywords', 'Test', 'DelValue' => 1],
        ['IPTC:Keywords', 'One'],
        ['IPTC:Keywords', 'Two'],
    ];

    return writeCheck($writeInfo, $testname, 7, "$t/images/IPTC.jpg", 1);
}, $skip);

// test 8: Write IPTC as a block
runTest($testname, 8, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_8_failed.jpg";
    @unlink($testfile);
    $et->SetNewValuesFromFile("$t/images/IPTC.jpg", 'IPTC');
    $ok = writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'IPTC:*');
    if (check($et, $info, $testname, 8) && $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
