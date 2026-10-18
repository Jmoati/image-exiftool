<?php

// Replays ExifTool's t/Writer.t through the PHP API and compares with its expected output
// (t/Writer_N.out). Run: php tests/writer.php [~/exiftool] [--all]
//
// The tests that need writers not ported yet are listed in $skip (with what they need): they are
// skipped unless --all is given. Remove a test from $skip when its writers are ported.
// Like in t/Writer.t, a test may rely on the file written by a previous one.

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;

$skip = [
    15 => 'advanced formatting expressions (comment<${make;tr{ ,.}{_};...})',
    27 => 'MIE writer (trailer of ExifTool.jpg)',
    41 => 'CanonRaw and MIE writers',
    51 => 'MIE and CanonVRD writers (trailers of ExifTool.jpg)',
];
$runAll = in_array('--all', $argv, true);
$t = testDir();
$testname = 'Writer';
$fail = $skipped = 0;

// run a test unless it is skipped; $test returns true when it passed (check() prints its result)
function test(int $testnum, callable $test, bool $print = false): void
{
    global $skip, $runAll, $fail, $skipped, $testname;
    if (isset($skip[$testnum]) && !$runAll) {
        echo "skip $testname $testnum (needs {$skip[$testnum]})\n";
        ++$skipped;

        return;
    }
    try {
        $ok = $test();
    } catch (Throwable $e) {
        fwrite(STDERR, "  $testname $testnum: ".get_class($e).': '.$e->getMessage()."\n");
        $ok = false;
        $print = true;
    }
    if ($print) {
        echo ($ok ? 'ok   ' : 'FAIL ')."$testname $testnum\n";
    }
    if (!$ok) {
        ++$fail;
    }
}

// tests 2/3: Test writing new comment to JPEG file and removing it again
$et = new ExifTool();
$testfile1 = "t/{$testname}_2_failed.jpg";
test(2, function () use ($et, $t, $testname, $testfile1) {
    @unlink($testfile1);
    $et->SetNewValue('Comment', 'New comment in JPG file');
    writeInfo($et, "$t/images/Canon.jpg", $testfile1);
    $info = (new ExifTool())->ImageInfo($testfile1);

    return check($info, $testname, 2);
});
test(3, function () use ($et, $t, $testname, $testfile1) {
    $testfile2 = "t/{$testname}_3_failed.jpg";
    @unlink($testfile2);
    $et->SetNewValue('Comment');
    writeInfo($et, $testfile1, $testfile2);
    if (!binaryCompare($testfile2, "$t/images/Canon.jpg")) {
        return false;
    }
    @unlink($testfile1);
    @unlink($testfile2);

    return true;
}, true);

// tests 4/5: Test editing a TIFF in memory then changing it back again
$newtiff = new ScalarRef(null);
$et = new ExifTool();
test(4, function () use ($et, $t, $testname, $newtiff) {
    $et->Options('Duplicates', 1, 'Unknown', 1);
    $et->SetNewValue('Headline', 'A different headline');
    $et->SetNewValue('ImageDescription', 'Modified TIFF');
    $et->SetNewValue('Keywords', 'another keyword', AddValue: 1);
    $et->SetNewValue('xmp:SupplementalCategories', 'new XMP info');
    writeInfo($et, "$t/images/ExifTool.tif", $newtiff);
    $info = $et->ImageInfo($newtiff);
    if (check($et, $info, $testname, 4)) {
        return true;
    }
    file_put_contents("t/{$testname}_4_failed.tif", $newtiff->value);

    return false;
});
test(5, function () use ($et, $t, $testname, $newtiff) {
    $newtiff2 = new ScalarRef(null);
    $et->SetNewValue();     // clear all the changes
    $et->SetNewValue('Headline', 'headline');
    $et->SetNewValue('ImageDescription', 'The picture caption');
    $et->SetNewValue('Keywords', 'another keyword', DelValue: 1);
    $et->SetNewValue('SupplementalCategories');
    writeInfo($et, $newtiff, $newtiff2);
    $testfile = "t/{$testname}_5_failed.tif";
    file_put_contents($testfile, $newtiff2->value);
    if (!binaryCompare($testfile, "$t/images/ExifTool.tif")) {
        return false;
    }
    @unlink($testfile);

    return true;
}, true);

// test 6/7: Test rewriting a JPEG file then changing it back again
$et = new ExifTool();
$testfile1 = "t/{$testname}_6_failed.jpg";
test(6, function () use ($et, $t, $testname, $testfile1) {
    $et->Options('Duplicates', 1, 'Unknown', 1);
    @unlink($testfile1);
    $et->SetNewValue('DateTimeOriginal', '2005:01:01 00:00:00', Group: 'IFD0');
    $et->SetNewValue('Contrast', '+2', Group: 'XMP');
    $et->SetNewValue('ExposureCompensation', 999, Group: 'EXIF');
    $et->SetNewValue('LightSource', 'cloud');
    $et->SetNewValue('EXIF:Flash', '0x1', Type: 'ValueConv');
    $et->SetNewValue('Orientation#', 3);
    $et->SetNewValue('FocalPlaneResolutionUnit', 'mm');
    $et->SetNewValue('Category', 'IPTC test');
    $et->SetNewValue('Description', 'New description');
    $et->SetNewValue('TimeCodes', '02:53:49:07 2009-11-19T12:38:35:21-03:00');
    writeInfo($et, "$t/images/Canon.jpg", $testfile1, null, 1);
    $info = $et->ImageInfo($testfile1);

    return check($et, $info, $testname, 6);
});
test(7, function () use ($et, $testname, $testfile1) {
    $et->SetNewValue();
    $et->SetNewValue('DateTimeOriginal', '2003:12:04 06:46:52');
    $et->SetNewValue('Contrast', null, Group: 'XMP');
    $et->SetNewValue('ExposureCompensation', 0, Group: 'EXIF');
    $et->SetNewValue('LightSource');
    $et->SetNewValue('EXIF:Flash', '0x0', Type: 'ValueConv');
    $et->SetNewValue('Orientation#', 1);
    $et->SetNewValue('FocalPlaneResolutionUnit', 'in');
    $et->SetNewValue('Category');
    $et->SetNewValue('Description');
    $et->SetNewValue('TimeCodes');
    $image = new ScalarRef(null);
    writeInfo($et, $testfile1, $image);
    $et->Options('Composite', 0);
    $info = $et->ImageInfo($image, '-filesize');
    $testfile2 = "t/{$testname}_7_failed.jpg";
    if (check($et, $info, $testname, 7)) {
        @unlink($testfile1);
        @unlink($testfile2);

        return true;
    }
    file_put_contents($testfile2, $image->value);   // save bad file

    return false;
});

// test 8: Test rewriting everything in a JPEG file
test(8, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('Duplicates', 1, 'Binary', 1, 'ListJoin', null);
    $info = $et->ImageInfo("$t/images/Canon.jpg");
    foreach (array_keys($info) as $tag) {
        $group = $et->GetGroup($tag, 0);
        // eat return values so warnings don't get printed
        $et->SetNewValueList($tag, $info[$tag], Group: $group);
    }
    $image = new ScalarRef(null);
    writeInfo($et, "$t/images/Canon.jpg", $image);
    // (must drop Composite tags because their order may change)
    $et->Options('Unknown', 1, 'Binary', 0, 'ListJoin', ', ', 'Composite', 0);
    // (must ignore filesize because it changes as null padding is discarded)
    $info = $et->ImageInfo($image, '-filesize');
    $testfile = "t/{$testname}_8_failed.jpg";
    if (check($et, $info, $testname, 8, 7)) {
        @unlink($testfile);

        return true;
    }
    file_put_contents($testfile, $image->value);    // save bad file

    return false;
});

// test 9: Test copying over information with SetNewValuesFromFile()
//         (including a transfer of the ICC_Profile record)
test(9, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/Canon.jpg");
    $et->SetNewValuesFromFile("$t/images/ExifTool.tif", 'ICC_Profile');
    $testfile = "t/{$testname}_9_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Nikon.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 9)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 10: Another SetNewValuesFromFile() test
test(10, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('IgnoreMinorErrors', 1);
    $et->SetNewValuesFromFile("$t/images/Pentax.jpg");
    $testfile = "t/{$testname}_10_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Canon.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 10)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 11/12: Try creating something from nothing and removing it again
//              (also test ListSplit and ListJoin options)
$et = new ExifTool();
$testfile1 = "t/{$testname}_11_failed.jpg";
$success11 = false;
test(11, function () use ($et, $t, $testname, $testfile1, &$success11) {
    $et->Options('ListSplit', ';\s*');
    $et->Options('ListJoin', ' <<separator>> ');
    $et->SetNewValue('DateTimeOriginal', '2005:01:19 13:37:22', Group: 'EXIF');
    $et->SetNewValue('FileVersion', 12, Group: 'IPTC');
    $et->SetNewValue('Contributor', 'Guess who', Group: 'XMP');
    $et->SetNewValue('GPSLatitude', "44 deg 14' 12.25\"", Group: 'GPS');
    $et->SetNewValue('Ducky:Quality', 50);
    $et->SetNewValue('Keywords', 'this; that');
    @unlink($testfile1);
    writeInfo($et, "$t/images/Writer.jpg", $testfile1);
    $info = $et->ImageInfo($testfile1);

    return $success11 = check($et, $info, $testname, 11);
});
test(12, function () use ($et, $t, $testname, $testfile1, &$success11) {
    $et->SetNewValue('DateTimeOriginal');
    $et->SetNewValue('FileVersion');
    $et->SetNewValue('Contributor');
    $et->SetNewValue('GPSLatitude');
    $et->SetNewValue('Ducky:Quality');
    $et->SetNewValue('Keywords');
    $testfile2 = "t/{$testname}_12_failed.jpg";
    @unlink($testfile2);
    writeInfo($et, $testfile1, $testfile2);
    if (!binaryCompare("$t/images/Writer.jpg", $testfile2)) {
        return false;
    }
    if ($success11) {
        @unlink($testfile1);
    }
    @unlink($testfile2);

    return true;
}, true);

// test 13: Copy tags from CRW file to JPG
test(13, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/CanonRaw.crw");
    $testfile = "t/{$testname}_13_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 13)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 14: Delete all information in a group
test(14, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('All', null, Group: 'MakerNotes');
    $testfile = "t/{$testname}_14_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Canon.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 14)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 15: Copy a specific set of tags
test(15, function () use ($t, $testname) {
    $et = new ExifTool();
    $copyTags = ['exififd:all', '-lightSource', 'ifd0:software'];
    // also test new regular expression feature (ExifTool 9.15)
    $copyTags[] = 'comment<${ make ; tr{ ,.}{_}; s{__}{_} } {cool, huh?}';
    $et->SetNewValuesFromFile("$t/images/Olympus.jpg", ...$copyTags);
    $testfile = "t/{$testname}_15_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Canon.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 15)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 16-18: Test SetNewValuesFromFile() order of operations
$argsList = [
    ['ifd0:xresolution>xmp:*', 'ifd1:xresolution>xmp:*'],
    ['ifd1:xresolution>xmp:*', 'ifd0:xresolution>xmp:*'],
    ['*:xresolution', '-ifd0:xresolution', 'xresolution>xmp:*'],
];
foreach ($argsList as $i => $args) {
    $testnum = 16 + $i;
    test($testnum, function () use ($t, $testname, $testnum, $args) {
        $et = new ExifTool();
        $et->SetNewValuesFromFile("$t/images/GPS.jpg", ...$args);
        $testfile = "t/{$testname}_{$testnum}_failed.jpg";
        @unlink($testfile);
        writeInfo($et, "$t/images/Writer.jpg", $testfile);
        $info = $et->ImageInfo($testfile, 'xresolution');
        if (!check($et, $info, $testname, $testnum)) {
            return false;
        }
        @unlink($testfile);

        return true;
    });
}

// test 19: Test SaveNewValues()/RestoreNewValues()
$testOK = false;
$testfile = "t/{$testname}_19_failed.jpg";
test(19, function () use ($t, $testname, &$testOK, $testfile) {
    $et = new ExifTool();
    $et->SetNewValue('ISO', 25);
    $et->SetNewValue('Sharpness', '+1');
    $et->SetNewValue('Artist', 'Phil', Group: 'IFD0');
    $et->SetNewValue('Artist', 'Harvey', Group: 'ExifIFD');
    $et->SetNewValue('DateTimeOriginal', '2006:03:27 16:25:00');
    $et->SetNewValue('Keywords', ['one', 'two']);
    $et->SaveNewValues();
    $et->SetNewValue('Artist', 'nobody');
    $et->SetNewValue('Keywords', 'three');
    $et->SetNewValuesFromFile("$t/images/FujiFilm.jpg");
    $et->RestoreNewValues();
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile);

    return $testOK = check($et, $info, $testname, 19);
});
if (isset($skip[19]) && !$runAll) {
    // (PHP: tests 20, 21 and 23 edit a copy of Writer.jpg instead of the file of test 19,
    //  and test 23 takes the DateTimeOriginal of Canon.jpg instead of 2006:03:27)
    copy("$t/images/Writer.jpg", $testfile);
    $testOK = true;
}

// test 20/21: Test edit in place (using the file from the last test)
$size = null;
test(20, function () use ($testfile, &$testOK, &$size) {
    if (!$testOK) {
        echo "     (relies on previous test)\n";

        return true;
    }
    $et = new ExifTool();
    $newComment = 'This is a new test comment';
    $et->SetNewValue('Comment', $newComment);
    $ok = writeInfo($et, $testfile);
    $info = $et->ImageInfo($testfile, 'Comment');
    if (($info['Comment'] ?? null) && $info['Comment'] === $newComment && $ok) {
        clearstatcache();
        $size = filesize($testfile);

        return true;
    }
    $testOK = false;

    return false;
}, true);
// test in-place edit of file passed by handle
test(21, function () use ($testfile, &$testOK, $size) {
    if (!$testOK) {
        echo "     (relies on previous test)\n";

        return true;
    }
    $et = new ExifTool();
    $shortComment = 'short comment';
    $et->SetNewValue('Comment', $shortComment);
    $fh = fopen($testfile, 'r+b');  // open test file for update
    writeInfo($et, $fh);
    fclose($fh);
    $info = $et->ImageInfo($testfile, 'Comment');
    if (($info['Comment'] ?? null) && $info['Comment'] === $shortComment) {
        clearstatcache();
        $newSize = filesize($testfile);
        if (!($newSize < $size)) {
            // test to see if the file got shorter as it should have
            $testOK = false;
            echo "     (skip: truncate() not supported on this system)\n";
        }

        return true;
    }
    $testOK = false;

    return false;
}, true);

// test 22: Test time shift feature
test(22, function () use ($t, $testname) {
    $writeInfo = [
        ['DateTimeOriginal', '1:2', 'Shift' => 1],
        ['ModifyDate', '2:1: 3:4', 'Shift' => 1],
        ['CreateDate', '200 0', 'Shift' => -1],
        ['DateCreated', '20:', 'Shift' => -1],
    ];

    return writeCheck($writeInfo, $testname, 22, "$t/images/XMP.jpg", 1);
});

// test 23: Test renaming a file from the value of DateTimeOriginal
test(23, function () use ($testname, &$testfile, &$testOK, $skip, $runAll) {
    if (!$testOK) {
        echo "     (relies on test 21)\n";

        return true;
    }
    // (PHP: the date of the copy of Writer.jpg, unless test 19 ran)
    $date = isset($skip[19]) && !$runAll ? '20031204' : '20060327';
    $newfile = "t/{$testname}_23_{$date}_failed.jpg";
    @unlink($newfile);
    $et = new ExifTool();
    $et->Options('DateFormat', "{$testname}_23_%Y%m%d_failed.jpg");
    // (PHP: from Canon.jpg when the file of test 19, Writer.jpg, has no DateTimeOriginal)
    $et->SetNewValuesFromFile(isset($skip[19]) && !$runAll ? testDir().'/images/Canon.jpg' : $testfile,
        'FileName<DateTimeOriginal');
    writeInfo($et, $testfile);
    if (file_exists($newfile) && !file_exists($testfile)) {
        $testfile = $newfile;

        return true;
    }
    $testOK = false;

    return false;
}, true);
if ($testOK) {
    @unlink($testfile);     // erase test file if all tests passed
}

// test 24: Test redirection with expressions
test(24, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/FujiFilm.jpg",
        'Comment<ISO=$ISO Aperture=${EXIF:fnumber} Exposure=${shutterspeed}'
    );
    $testfile = "t/{$testname}_24_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'Comment');
    if (!check($et, $info, $testname, 24)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 25/26: Test order of delete operations
for ($i = 0; $i < 2; ++$i) {
    $testnum = 25 + $i;
    test($testnum, function () use ($t, $testname, $testnum, $i) {
        $et = new ExifTool();
        $et->SetNewValuesFromFile("$t/images/Nikon.jpg", 'all:all', '-makernotes:all');
        if (1 == $i) {
            $et->SetNewValue('fnumber', 26);
        }
        $et->SetNewValue('exififd:all'); // delete all exifIFD
        if (0 == $i) {
            $et->SetNewValue('fnumber', 25);
        }
        $testfile = "t/{$testname}_{$testnum}_failed.jpg";
        @unlink($testfile);
        writeInfo($et, "$t/images/Canon.jpg", $testfile);
        $info = $et->ImageInfo($testfile);
        if (!check($et, $info, $testname, $testnum)) {
            return false;
        }
        @unlink($testfile);

        return true;
    });
}

// test 27: Check that mandatory EXIF resolution tags get taken from JFIF
test(27, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('exif:all');   // delete all EXIF
    $testfile = "t/{$testname}_27_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/ExifTool.jpg", $testfile);
    $et->SetNewValue();
    $et->SetNewValue('exif:datetimeoriginal', '2000:01:02 03:04:05');
    $ok = writeInfo($et, $testfile);
    $info = $et->ImageInfo($testfile, 'XResolution', 'YResolution', 'DateTimeOriginal');
    if (!(check($et, $info, $testname, 27) && $ok)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 28-30: Check cross delete behaviour when deleting tags
$et = new ExifTool();
if (!(isset($skip[28]) && !$runAll)) {
    $et->SetNewValue('IFD0:ISO', 100);
    $et->SetNewValue('ExifIFD:ISO', 200);
    writeInfo($et, "$t/images/Writer.jpg", 't/tmp.jpg');
}
foreach (['EXIF', 'IFD0', 'ExifIFD'] as $i => $group) {
    $testnum = 28 + $i;
    test($testnum, function () use ($et, $testname, $testnum, $group) {
        $et->SetNewValue();     // reset values
        $et->SetNewValue("$group:ISO");     // delete ISO from specific group
        $testfile = "t/{$testname}_{$testnum}_failed.jpg";
        @unlink($testfile);
        writeInfo($et, 't/tmp.jpg', $testfile);
        $info = $et->ImageInfo($testfile, 'FileName', 'ISO');
        if (!check($et, $info, $testname, $testnum)) {
            return false;
        }
        @unlink($testfile);

        return true;
    });
}
@unlink('t/tmp.jpg');

// test 31: Delete all but EXIF (excluding IFD1) and IPTC information
test(31, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('*');
    $et->SetNewValue('EXIF:*', null, Replace: 2);
    $et->SetNewValue('ifd1:all');
    $et->SetNewValue('IPTC:*', null, Replace: 2);
    $testfile = "t/{$testname}_31_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/ExifTool.jpg", $testfile, null, 1);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 31)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 32-33: Read/Write ICC Profile tags
$et = new ExifTool();
$hdr = "\0\0\0\x18ADBE\x02\x10\0\0mntrRGB XYZ ";
$tags = ['ICC_Profile', 'AsShotICCProfile', 'CurrentICCProfile'];
$srcfile = "t/{$testname}_32_failed.tif";
test(32, function () use ($et, $t, $testname, $hdr, $tags, $srcfile) {
    $et->Options('IgnoreMinorErrors', 1);
    $et->SetNewValue('AsShotICCProfile', $hdr.'<dummy>', Protected: 1);
    $et->SetNewValue('CurrentICCProfile', $hdr.'<dummy 2>', Protected: 1);
    @unlink($srcfile);
    writeInfo($et, "$t/images/ExifTool.tif", $srcfile);
    $info = $et->ImageInfo($srcfile, ...$tags);

    return check($et, $info, $testname, 32);
});
test(33, function () use ($et, $testname, $hdr, $tags, $srcfile) {
    $et->SetNewValue();
    $et->SetNewValue('ICC_Profile', $hdr.'<another dummy>', Protected: 1);
    $testfile = "t/{$testname}_33_failed.tif";
    @unlink($testfile);
    writeInfo($et, $srcfile, $testfile);
    $info = $et->ImageInfo($testfile, ...$tags);
    if (!check($et, $info, $testname, 33)) {
        return false;
    }
    @unlink($srcfile);
    @unlink($testfile);

    return true;
});

// test 34: copy list tag to list and non-list tags with different options
test(34, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('ListJoin', null);
    $et->SetNewValuesFromFile("$t/images/IPTC.jpg",
        ['Replace' => 1],
        'xmp:subject<filename',
        'xmp:subject<iptc:keywords',
        'comment<iptc:keywords',
        ['Replace' => 0],
        'xmp:HierarchicalSubject<filename',
        'xmp:HierarchicalSubject<iptc:keywords',
    );
    $testfile = "t/{$testname}_34_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'xmp:subject', 'comment', 'HierarchicalSubject');
    if (!check($et, $info, $testname, 34)) {
        return false;
    }
    // make sure it was an array reference
    $val = ($info['Subject'] ?? null) ?: '';
    $err = null;
    if (!(is_array($val) && array_is_list($val))) {
        $err = "Subject is not an ARRAY: '$val'";
    } elseif (3 != count($val)) {
        $err = "Subject does not contain 3 values: '".implode(', ', $val)."'";
    }
    if ($err) {
        fwrite(STDERR, "\n  $err\n");

        return false;
    }
    @unlink($testfile);

    return true;
});

// test 35: Add back all information after deleting everything
test(35, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('*');
    $et->SetNewValuesFromFile("$t/images/ExifTool.jpg", 'all:all', 'icc_profile', 'canonvrd');
    $testfile = "t/{$testname}_35_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/ExifTool.jpg", $testfile, null, 1);
    $et->Options('Composite', 0);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 35)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 36: Test adding and deleting from the same list
test(36, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('IPTC:Keywords', 'out', DelValue: 1);
    $et->SetNewValue('IPTC:Keywords', 'in', AddValue: 1);
    $testfile = "t/{$testname}_36_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'IPTC:all');
    if (!check($et, $info, $testname, 36)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 37-38: Create EXIF file from EXIF block and individual tags
for ($i = 0; $i < 2; ++$i) {
    $testnum = 37 + $i;
    test($testnum, function () use ($t, $testname, $testnum, $i) {
        $et = new ExifTool();
        $tags = [];
        if (0 == $i) {
            $et->SetNewValuesFromFile("$t/images/Sony.jpg", 'EXIF');
            $et->Options('PrintConv', 0);
            $tags = ['FileSize', 'Compression'];
        } else {
            $et->SetNewValuesFromFile("$t/images/Sony.jpg");
            $et->Options('PrintConv', 1, 'Unknown', 1);
        }
        $testfile = "t/{$testname}_{$testnum}_failed.exif";
        @unlink($testfile);
        writeInfo($et, null, $testfile);
        $info = $et->ImageInfo($testfile, ...$tags);
        if (!check($et, $info, $testname, $testnum)) {
            return false;
        }
        @unlink($testfile);

        return true;
    });
}

// tests 39-40: Test writing only if the tag didn't already exist
$writeInfo = [
    ['DateTimeOriginal', '', 'DelValue' => 1],
    ['DateTimeOriginal#', '1999:99:99 99:99:99'],
    ['XResolution', '', 'DelValue' => 1],
    ['XResolution', '123'],
    ['ResolutionUnit', '', 'DelValue' => 1],
    ['ResolutionUnit', 'cm'],
];
$check = ['FileName', 'DateTimeOriginal', 'XResolution', 'ResolutionUnit'];
test(39, fn () => writeCheck($writeInfo, $testname, 39, "$t/images/Writer.jpg", $check));
test(40, fn () => writeCheck($writeInfo, $testname, 40, "$t/images/Canon.jpg", $check, 1));

// test 41: Test writing Kodak APP3 and Canon CIFF Meta information
test(41, fn () => writeCheck([
    ['Meta:SerialNumber', '12345'],
    ['CIFF:OwnerName', 'CIFF Write Test'],
], $testname, 41, "$t/images/ExifTool.jpg", ['SerialNumber', 'OwnerName']));

// test 42: Test SetNewValuesFromFile with wildcards
test(42, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/ExifTool.jpg", 'ifd0:*<jfif:?resolution');
    $testfile = "t/{$testname}_42_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $et->Options('Composite', 0);
    $info = $et->ImageInfo($testfile, '-file:all');
    if (!check($et, $info, $testname, 42)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 43: Test increment feature EXIF
test(43, fn () => writeCheck([
    ['ExposureTime', '1.5', 'Shift' => 1],
    ['SerialNumber', '-9', 'Shift' => -1],  // (two negatives make a positive)
    ['MeteringMode', '1', 'Shift' => 0, 'AddValue' => 1],
], $testname, 43, "$t/images/Canon.jpg", 1));

// test 44: Test increment feature with XMP
test(44, fn () => writeCheck([
    ['XMP:ApertureValue', '-0.1', 'Shift' => 1],   // increment
    ['XMP:FNumber', '28/10', 'DelValue' => 1],     // conditional delete
    ['XMP:DateTimeOriginal', '3', 'Shift' => 0, 'AddValue' => 1],  // shift
], $testname, 44, "$t/images/XMP.xmp", 1));

// test 45: Test writing different EXIF string encoding
test(45, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('CharsetEXIF', 'Latin');
    $testfile = "t/{$testname}_45_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('Artist', "P\xc3\xaaro", Group: 'EXIF');
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $et->Options('CharsetEXIF', null);
    $info = $et->ImageInfo($testfile, 'artist');
    if (!check($et, $info, $testname, 45)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 46: Test writing with wildcards
test(46, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_46_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('A*', '7');
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 46)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 47: Test various WriteMode settings
test(47, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_47_failed.jpg";
    @unlink($testfile);                             // Should the tag be written?
    $et->Options('WriteMode', 'w');                   // --- write existing tags only:
    $et->SetNewValue('ISO', 150);                   // yes (already exists)
    $et->SetNewValue('ImageDescription', 'N');      // no  (doesn't already exist)
    $et->Options('WriteMode', 'c');                   // --- create new tags only:
    $et->SetNewValue('ApertureValue', 8.0);         // no  (already exists)
    $et->SetNewValue('UserComment', 'No');          // no  (exists, albeit empty)
    $et->SetNewValue('Artist', 'Phil');             // yes (doesn't already exist)
    $et->SetNewValue('XMP:Subject', 'No');          // no  (shouldn't create new group)
    $et->Options('WriteMode', 'cg');                  // --- also create new groups:
    $et->SetNewValue('IPTC:Keywords', 'Y');         // yes (should create new group)
    $et->Options('Composite', 0, 'FastScan', 2);
    writeInfo($et, "$t/images/Canon.jpg", $testfile);
    $info = $et->ImageInfo($testfile, '-time:all');
    if (!check($et, $info, $testname, 47)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 48-50: More WriteMode 'cg' tests, and test AddUserDefinedTags()
$et = new ExifTool();
$testfile48 = "t/{$testname}_48_failed.jpg";
test(48, function () use ($et, $t, $testname, $testfile48) {
    @unlink($testfile48);
    $et->Options('WriteMode', 'cg');
    $et->SetNewValue('XMP-dc:Title', 'A');
    $et->SetNewValue('XMP:Subject', 'A');
    $et->SetNewValue('XMP:LocationCreated', '{city=A}');
    $et->SetNewValue('XMP:Flash', '{fired=true}');
    $et->SetNewValue('IPTC:Keywords', 'A');
    $et->SetNewValue('IPTC:City', 'A');
    $et->SetNewValue('EXIF:Artist', 'A');
    writeInfo($et, "$t/images/Writer.jpg", $testfile48);
    $info = $et->ImageInfo($testfile48, '-time:all', '-filename');

    return check($et, $info, $testname, 48);
});
test(49, function () use ($et, $testname, $testfile48) {
    $testfile2 = "t/{$testname}_49_failed.jpg";
    @unlink($testfile2);
    $et->SetNewValue();
    $et->SetNewValue('XMP-dc:Title', 'B');
    $et->SetNewValue('XMP:Subject', 'B');
    $et->SetNewValue('XMP:LocationCreated', '{city=B}');
    $et->SetNewValue('XMP:Flash', '{fired=false}');
    $et->SetNewValue('IPTC:Keywords', 'B');
    $et->SetNewValue('IPTC:City', 'B');
    $et->SetNewValue('EXIF:Artist', 'B');
    if (writeInfo($et, $testfile48, $testfile2, 1)) {
        @unlink($testfile48);
        @unlink($testfile2);

        return true;
    }
    $info = $et->ImageInfo($testfile2, '-time:all', '-filename');
    check($et, $info, $testname, 49, 48);

    return false;
}, true);
test(50, function () use ($et, $testname) {
    $testfile = "t/{$testname}_50_failed.xmp";
    @unlink($testfile);
    // (Perl { }: an empty TagInfo, since an empty PHP array would be a list)
    ExifTool::AddUserDefinedTags('Image::ExifTool::XMP::dc', ['test' => new TagInfo()]);
    $et->SetNewValue();
    $et->SetNewValue('XMP-dc:Title', 'A');
    $et->SetNewValue('XMP-dc:Test', 'B');
    writeInfo($et, null, $testfile);
    $info = $et->ImageInfo($testfile, 'xmp:all');
    if (!check($et, $info, $testname, 50)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 51: Delete a unknown JPEG APP segment
test(51, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('APP6:*', null);
    $testfile = "t/{$testname}_51_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/ExifTool.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 51)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 52: Delete groups by family 2 group name
test(52, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('Image:*');
    $et->SetNewValue('Camera:*');
    $testfile = "t/{$testname}_52_failed.xmp";
    @unlink($testfile);
    writeInfo($et, "$t/images/XMP.xmp", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 52)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 53: Exclude groups when copying
test(53, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/Canon.jpg", '-Exif:All', '-Canon:All');
    $testfile = "t/{$testname}_53_failed.xmp";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile);
    if (!check($et, $info, $testname, 53)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 54: Specify multiple groups when copying, excluding a single tag
test(54, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile("$t/images/Canon.jpg", 'Exif:Time:All', '-createdate');
    $testfile = "t/{$testname}_54_failed.xmp";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'exif:*', '-image:all');
    if (!check($et, $info, $testname, 54)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// tests 55-56: Create and edit EXV file
$et = new ExifTool();
$testfile55 = "t/{$testname}_55_failed.exv";
test(55, function () use ($et, $testname, $testfile55) {
    $et->SetNewValue('Artist', 'me');
    $et->SetNewValue('Keywords', ['one', 'two']);
    @unlink($testfile55);
    writeInfo($et, null, $testfile55);
    $info = $et->ImageInfo($testfile55, 'exif:*', 'iptc:*', 'xmp:*');

    return check($et, $info, $testname, 55);
});
test(56, function () use ($et, $testname, $testfile55) {
    $et->SetNewValue();
    $et->SetNewValue('Artist');
    $et->SetNewValue('Title', 'Test');
    $testfile2 = "t/{$testname}_56_failed.exv";
    @unlink($testfile2);
    writeInfo($et, $testfile55, $testfile2);
    $info = $et->ImageInfo($testfile2, 'exif:*', 'iptc:*', 'xmp:*');
    if (!check($et, $info, $testname, 56)) {
        return false;
    }
    @unlink($testfile55);
    @unlink($testfile2);

    return true;
});

// test 57: Test setting Composite PreviewImage using various groups
test(57, function () {
    $ok = true;
    $et = new ExifTool();
    $et->Options('IgnoreMinorErrors', 1);
    $try = [
        'previewimage'              => 1,
        'composite:previewimage'    => 0,   // (shouldn't be in the Composite group)
        'composite:thumbnailimage'  => 0,
        'composite:jpgfromraw'      => 0,
        'composite:otherimage'      => 0,
        'ifd0:previewimage'         => 1,
        '0ifd0:previewimage'        => 0,
        '1ifd0:previewimage'        => 1,
        '2ifd0:previewimage'        => 0,
        'exif:previewimage'         => 1,
        '0exif:previewimage'        => 1,
        '1exif:previewimage'        => 0,
        '2exif:previewimage'        => 0,
        'preview:previewimage'      => 1,
        '0preview:previewimage'     => 0,
        '1preview:previewimage'     => 0,
        '2preview:previewimage'     => 1,
        'makernotes:previewimage'   => 1,
        '0makernotes:previewimage'  => 1,
        '1makernotes:previewimage'  => 0,
        '2makernotes:previewimage'  => 0,
        'xxx:previewimage'          => 0,
        '0xxx:previewimage'         => 0,
        '1xxx:previewimage'         => 0,
        '2xxx:previewimage'         => 0,
        'ifd0:exif:preview:previewimage'    => 1,
        '0ifd0:exif:preview:previewimage'   => 0,
        '1ifd0:exif:preview:previewimage'   => 1,
        '2ifd0:exif:preview:previewimage'   => 0,
        'ifd0:0exif:preview:previewimage'   => 1,
        'ifd0:1exif:preview:previewimage'   => 0,
        'ifd0:2exif:preview:previewimage'   => 0,
        'ifd0:exif:0preview:previewimage'   => 0,
        'ifd0:exif:1preview:previewimage'   => 0,
        'ifd0:exif:2preview:previewimage'   => 1,
        '1ifd0:0exif:2preview:previewimage' => 1,
        'exif:makernotes:previewimage'      => 1,   // (but wouldn't write anything)
    ];
    $keys = array_keys($try);
    sort($keys, SORT_STRING);
    foreach ($keys as $tag) {
        [$num] = $et->SetNewValueList($tag, 'test');
        if (!($num xor $try[$tag])) {
            continue;
        }
        fwrite(STDERR, "\nError setting $tag\n");
        $ok = false;
    }

    return $ok;
}, true);

// test 58: Set ICC_Profile from an external file
test(58, function () use ($t, $testname) {
    $buff = (string) file_get_contents("$t/images/ICC_Profile.icc", false, null, 0, 1000);

    return writeCheck([['ICC_Profile', new ScalarRef($buff), 'Protected' => 1]], $testname, 58);
});

// test 59: Test writing empty list elements
test(59, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('ListSplit', ',');
    $et->Options('ListJoin', ';');
    $et->SetNewValue('XMP-dc:Subject', ',a,,');
    $testfile = "t/{$testname}_59_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'xmp:all');
    if (!check($et, $info, $testname, 59)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 60: Test SetAlternateFile() feature with SetNewValuesFromFile()
test(60, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetAlternateFile('File1', "$t/images/Nikon.jpg");
    $et->SetAlternateFile('File2', "$t/images/FujiFilm.jpg");
    $et->SetNewValuesFromFile("$t/images/Canon.jpg",
        'subject<file1:make', '+subject<make', '+subject<$file2:make',
        'contributor<file1:make', 'contributor<make', 'contributor<$file2:make',
        '+xmp-dc:type<file1:me*',
    );
    $testfile = "t/{$testname}_60_failed.jpg";
    @unlink($testfile);
    writeInfo($et, "$t/images/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, 'subject', 'contributor', 'type');
    if (!check($et, $info, $testname, 60)) {
        return false;
    }
    @unlink($testfile);

    return true;
});

// test 61: Test the %f and %z date/time format codes for writing
test(61, function () use ($testname) {
    $et = new ExifTool();
    $et->Options('DateFormat', '%H:%M:%S%f%:z %m-%d-%Y');
    $et->SetNewValue('XMP:CreateDate', '09:11:12.345-07:00 01-02-2024');
    $testfile = "t/{$testname}_61_failed.xmp";
    @unlink($testfile);
    $success = writeInfo($et, null, $testfile);
    if ($success) {
        $et->Options('DateFormat', null);
        $info = $et->ImageInfo($testfile, '-system:all');
        $success = check($et, $info, $testname, 61);
    }
    if ($success) {
        @unlink($testfile);
    }

    return $success;
});

echo $fail ? "$fail failed" : 'all passed', $skipped ? " ($skipped skipped)" : '', "\n";
exit($fail ? 1 : 0);
