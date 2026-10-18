<?php

// Replays ExifTool's t/XMP.t through the PHP API and compares with its expected output
// (t/XMP_N.out). Run: php tests/xmp.php [~/exiftool] [--all]
//
// The tests that need writers not ported yet are listed in $skip (with what they need): they are
// skipped unless --all is given.

use Image\ExifTool\ExifTool;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagTable;

require __DIR__.'/TestLib.php';

// definitions for user-defined tag test (#26)
// (must be set before the XMP tables are loaded, like Perl does at compile time)
ExifTool::$UserDefined = [
    'Image::ExifTool::XMP::Main' => [
        'myXMPns' => [
            'SubDirectory' => [
                'TagTable' => 'Image::ExifTool::UserDefined::myXMPns',
                // (see the definition of this table below)
            ],
        ],
    ],
];
// (PHP: %Image::ExifTool::UserDefined::myXMPns is a Perl package hash: the port has no API to
//  define one, so it is set in the package tables of ExifTool)
$pkgTables = new ReflectionProperty(ExifTool::class, 'pkgTables');
$pkgTables->setValue(null, $pkgTables->getValue() + ['Image::ExifTool::UserDefined::myXMPns' => new TagTable([
    'GROUPS' => [0 => 'XMP', 1 => 'XMP-myXMPns'],
    'NAMESPACE' => ['myXMPns' => 'http://ns.exiftool.org/t/XMP.t'],
    'WRITABLE' => 'string',
    'ATestTag' => ['List' => 'Bag', 'Resource' => 1],
    'BTestTag' => [
        'Struct' => [
            'TYPE' => 'myXMPns:SomeFunnyType',
            'Field1' => ['Writable' => 'lang-alt', 'List' => 'Bag'],
        ],
    ],
    'BTestTagField1' => ['Name' => 'Renamed', 'Flat' => 1],
])]);

$skip = [
    3 => 'EXIF writer (XMP.jpg has EXIF, all tags are rewritten)',
    19 => 'EXIF writer',
    20 => 'EXIF writer',
    46 => 'advanced formatting expressions (Perl code)',
];
$t = testDir();
$testname = 'XMP';

// test 2: Extract information from XMP.jpg
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP.jpg'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test rewriting everything with slightly different values
runTest($testname, 3, function () use ($testname) {
    $et = new ExifTool();
    $et->Options('Duplicates', 1, 'Binary', 1, 'ListJoin', null);
    $info = $et->ImageInfo(testImage('XMP.jpg'));
    foreach (array_keys($info) as $tag) {
        $group = $et->GetGroup($tag, 0);
        $val = $info[$tag];
        if (is_array($val)) {
            $val[] = 'v2';
        } elseif ($val instanceof ScalarRef) {
            $val = 'v2';
        } elseif (preg_match('/^\d+(\.\d*)?$/', (string) $val, $m)) {
            // (add extra .001 to avoid problem with aperture of 4.85
            //  getting rounded to 4.8 or 4.9 and causing failed tests)
            $val += ($val / 10) + 1.001;
            if (empty($m[1])) {
                $val = (int) $val;
            }
        } else {
            $val .= '-v2';
        }
        // eat return values so warning don't get printed
        $et->SetNewValueList($tag, $val, Group: $group, Replace: 1);
    }
    // also try writing a few specific tags
    $et->SetNewValue('CreatorCountry', 'Canada');
    $et->SetNewValue('CodedCharacterSet', 'UTF8', Protected: 1);
    $image = new ScalarRef(null);
    $ok = writeInfo($et, testImage('XMP.jpg'), $image);
    $et2 = new ExifTool();
    $et2->Options('Duplicates', 1);
    $info = $et2->ImageInfo($image);
    if (check($et2, $info, $testname, 3) && $ok) {
        return true;
    }
    file_put_contents("t/{$testname}_3_failed.jpg", $image->value);

    return false;
}, $skip);

// tests 4/5: Test extracting then reading XMP data as a block
$xmpBlock = null;
runTest($testname, 4, function () use (&$xmpBlock) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP.jpg'), 'XMP');
    $xmpBlock = $info['XMP'] ?? null;

    return (bool) $xmpBlock;
}, $skip, true);
runTest($testname, 5, function () use (&$xmpBlock, $testname) {
    if (!$xmpBlock) {
        return false;
    }
    $et = new ExifTool();
    $info = $et->ImageInfo($xmpBlock);

    return check($et, $info, $testname, 5);
}, $skip);

// test 6: Test copying information to a new XMP data file
runTest($testname, 6, function () use ($testname) {
    $et = new ExifTool();
    $et->SetNewValuesFromFile(testImage('XMP.jpg'));
    $testfile = "t/{$testname}_6_failed.xmp";
    @unlink($testfile);
    $ok = writeInfo($et, null, $testfile);
    $info = $et->ImageInfo($testfile);
    if (check($et, $info, $testname, 6) && $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 7: Test rewriting CS2 XMP information
runTest($testname, 7, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_7_failed.xmp";
    @unlink($testfile);
    $et->SetNewValue('Label', 'Blue');
    $et->SetNewValue('Rating', 3);
    $et->SetNewValue('Subject', 'char test: & > < \' "', AddValue: 1);
    $et->SetNewValue('Rights', "\xc2\xa9 Copyright Someone Else");
    $ok = writeInfo($et, testImage('XMP.xmp'), $testfile);

    return testCompare("$t/XMP_7.out", $testfile, 7) && $ok;
}, $skip, true);

// test 8-11: Test reading/writing XMP with blank nodes and some problems that need correcting
$testnum = 7;
foreach (['XMP2.xmp', 'XMP3.xmp'] as $file) {
    $et = new ExifTool();
    runTest($testname, ++$testnum, function () use ($et, $file, $testname, $testnum) {
        $info = $et->ImageInfo(testImage($file), ['Duplicates' => 1]);

        return check($et, $info, $testname, $testnum);
    }, $skip);
    runTest($testname, ++$testnum, function () use ($et, $file, $t, $testname, $testnum) {
        $testfile = "t/{$testname}_{$testnum}_failed.xmp";
        @unlink($testfile);
        $et->SetNewValue('XMP:Creator', 'Phil', AddValue: 1);
        $et->SetNewValue('manifestplacedXResolution', 1);
        $et->SetNewValue('attributionname', 'something else');
        $et->WriteInfo(testImage($file), $testfile);
        $err = $et->GetValue('Error');
        if ($err) {
            fwrite(STDERR, "\n  $err\n");
        }

        return testCompare("$t/XMP_$testnum.out", $testfile, $testnum);
    }, $skip, true);
}

// tests 12-17: Test writing/deleting XMP alternate languages
$writeList = [
    [['Rights-x-default', "\xc2\xa9 Copyright Another One"]],   // should overwrite x-default only
    [['Rights-de-DE', "\xc2\xa9 Urheberrecht Phil Harvey"]],    // should create de-DE only
    [['Rights-x-default', null]],   // should delete x-default only
    [['Rights-fr', null]],          // should delete fr only
    [['Title-fr', 'Test fr title']],// should not create x-default
    [['Title-fr', 'Test fr title'],
     ['Title-x-default', 'dTitle']],// should create x-default before fr
];
$testnum = 11;
foreach ($writeList as $writeListRef) {
    runTest($testname, ++$testnum, function () use ($writeListRef, $testname, $testnum) {
        $testfile = "t/{$testname}_{$testnum}_failed.xmp";
        @unlink($testfile);

        return writeCheck($writeListRef, $testname, $testnum, testImage('XMP.xmp'), ['XMP-dc:*']);
    }, $skip);
}

// test 18: Delete some family 1 XMP groups
runTest($testname, 18, function () use ($testname) {
    $writeInfo = [
        ['xmp-xmpmm:all', null],
        ['XMP-PHOTOSHOP:all', null],
    ];

    return writeCheck($writeInfo, $testname, 18, testImage('XMP.jpg'), ['XMP:all']);
}, $skip);

// test 19-20: Copy from XMP to EXIF with and without PrintConv enabled
$et = new ExifTool();
for ($testnum = 19; $testnum <= 20; ++$testnum) {
    runTest($testname, $testnum, function () use ($et, $testname, $testnum) {
        $testfile = "t/{$testname}_{$testnum}_failed.jpg";
        @unlink($testfile);
        $et->SetNewValue();
        $et->SetNewValuesFromFile(testImage('XMP.xmp'), 'XMP:all>EXIF:all');
        $ok = writeInfo($et, testImage('Writer.jpg'), $testfile);
        $info = $et->ImageInfo($testfile, 'EXIF:all');
        if (check($et, $info, $testname, $testnum) && $ok) {
            @unlink($testfile);

            return true;
        }

        return false;
    }, $skip);
    $et->Options('PrintConv', 0);
}

// test 21-22: Copy from EXIF to XMP with and without PrintConv enabled
$et = new ExifTool();
for ($testnum = 21; $testnum <= 22; ++$testnum) {
    runTest($testname, $testnum, function () use ($et, $testname, $testnum) {
        $testfile = "t/{$testname}_{$testnum}_failed.xmp";
        @unlink($testfile);
        $et->SetNewValue();
        $et->SetNewValuesFromFile(testImage('Canon.jpg'), 'EXIF:* > XMP:*');
        $ok = writeInfo($et, null, $testfile);
        $info = $et->ImageInfo($testfile, 'XMP:*');
        if (check($et, $info, $testname, $testnum) && $ok) {
            @unlink($testfile);

            return true;
        }

        return false;
    }, $skip);
    $et->Options('PrintConv', 0);
}

// test 23: Delete all tags except two specific XMP family 1 groups
runTest($testname, 23, function () use ($testname) {
    $writeInfo = [
        ['all', null],
        ['xmp-dc:all', null, 'Replace' => 2],
        ['xmp-xmprights:all', null, 'Replace' => 2],
    ];

    return writeCheck($writeInfo, $testname, 23, testImage('XMP.jpg'), ['XMP:all'], null, 1);
}, $skip);

// test 24: Delete all tags except XMP
runTest($testname, 24, function () use ($testname) {
    $writeInfo = [
        ['all', null],
        ['xmp:all', null, 'Replace' => 2],
    ];

    return writeCheck($writeInfo, $testname, 24, testImage('XMP.jpg'), ['-file:all'], null, 1);
}, $skip);

// test 25: Extract information from SVG image
runTest($testname, 25, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP.svg'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 25);
}, $skip);

// test 26: Test creating a variety of XMP information
//          (including x:xmptk, rdf:about and rdf:resource attributes)
runTest($testname, 26, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_26_failed.xmp";
    @unlink($testfile);
    $writeInfo = [
        ['XMP-x:XMPToolkit', "What's this?", 'Protected' => 1],
        ['XMP-rdf:About', "http://www.exiftool.org/t/$testname.t#26", 'Protected' => 1],
        ['XMP:ImageType', 'Video'],
        ['LicenseeImageNotes-en', 'english notes'],
        ['LicenseeImageNotes-de', 'deutsche anmerkungen'],
        ['LicenseeImageNotes', 'default notes'],
        ['LicenseeName', 'Phil'],
        ['CopyrightStatus', 'public'],
        ['Custom1-en', 'a'],
        ['Custom1-en', 'b'],
        ['ATestTag', "http://www.exiftool.org/t/$testname.t#26-one"],
        ['ATestTag', "http://www.exiftool.org/t/$testname.t#26-two"],
    ];
    foreach ($writeInfo as $args) {
        $et->SetNewValue(...$args);
    }
    $ok = writeInfo($et, null, $testfile);

    return testCompare("$t/XMP_26.out", $testfile, 26) && $ok;
}, $skip, true);

// test 27: Extract information from exiftool RDF/XML output file
// (file created by application with this command line:
//  "exiftool -X -D t/images/Nikon.jpg > t/image/XMP.xml")
runTest($testname, 27, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP.xml'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 27);
}, $skip);

// test 28: Write information to exiftool RDF/XML output file
runTest($testname, 28, function () use ($testname) {
    $writeInfo = [
        ['all', null],
        ['ifd0:all', null, 'Replace' => 2],
        ['XML-file:all', null, 'Replace' => 2],
        ['author', 'Phil'],
    ];

    return writeCheck($writeInfo, $testname, 28, testImage('XMP.xml'));
}, $skip);

// test 29: Rewrite extended XMP segment
runTest($testname, 29, function () use ($testname) {
    $writeInfo = [['author', 'Test']];

    return writeCheck($writeInfo, $testname, 29, testImage('ExtendedXMP.jpg'));
}, $skip);

// test 30: Test mass copy with deletion of specific XMP family 1 groups in shorthand format
runTest($testname, 30, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('XMPShorthand', 1);
    $testfile = "t/{$testname}_30_failed.out";
    @unlink($testfile);
    $et->SetNewValuesFromFile(testImage('XMP.jpg'));
    $et->SetNewValue('xmp-exif:all');
    $et->SetNewValue('XMP-TIFF:*');
    $et->WriteInfo(null, $testfile, 'XMP');  //(also test output file type option)

    return testCompare("$t/XMP_30.out", $testfile, 30);
}, $skip, true);

// test 31: Extract structured information
runTest($testname, 31, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP4.xmp'), ['Struct' => 1]);

    return check($et, $info, $testname, 31);
}, $skip);

// tests 32-34: Conditionally add XMP lang-alt tag
$et = new ExifTool();
$testfile32 = "t/{$testname}_32_failed.jpg";
// write title only if it doesn't exist
runTest($testname, 32, function () use ($et, $testname, $testfile32) {
    @unlink($testfile32);
    $et->SetNewValue('XMP-dc:Title-de', '', DelValue: 1);
    $et->SetNewValue('XMP-dc:Title-de', 'A');
    $ok = writeInfo($et, testImage('Writer.jpg'), $testfile32);
    $info = $et->ImageInfo($testfile32, 'XMP:*');

    return check($et, $info, $testname, 32) && $ok;
}, $skip);
// try again when title already exists
runTest($testname, 33, function () use ($et, $testname, $testfile32) {
    $testfile2 = "t/{$testname}_33_failed.jpg";
    @unlink($testfile2);
    $et->SetNewValue('XMP-dc:Title-de', 'B');
    $et->WriteInfo($testfile32, $testfile2);
    $info = $et->ImageInfo($testfile2, 'XMP:*');
    if (check($et, $info, $testname, 33, 32)) {
        @unlink($testfile2);

        return true;
    }

    return false;
}, $skip);
// one final time replacing an existing title
runTest($testname, 34, function () use ($et, $testname, $testfile32) {
    $testfile2 = "t/{$testname}_34_failed.jpg";
    @unlink($testfile2);
    $et->SetNewValue('XMP-dc:Title-de', 'A', DelValue: 1);
    $et->SetNewValue('XMP-dc:Title-de', 'C');
    $ok = writeInfo($et, $testfile32, $testfile2);
    $info = $et->ImageInfo($testfile2, 'XMP:*');
    if (check($et, $info, $testname, 34) && $ok) {
        @unlink($testfile32);
        @unlink($testfile2);

        return true;
    }

    return false;
}, $skip);

// test 35: Test various features of writing structured information
runTest($testname, 35, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_35_failed.xmp";
    @unlink($testfile);
    $writeInfo = [
        // write as flattened string
        ['HierarchicalKeywords', '{keyWORD=A-1,childREN={keyword=A-2}}'],
        // write as HASH reference
        ['HierarchicalKeywords', [['kEyWoRd' => 'B-1', 'cHiLdReN' => ['keyword' => 'B-2']], ['keyword' => 'C-1']]],
        // write a type'd structure
        ['licensee', ['licenseename' => 'Phil']],
        // write a region, including a 'seeAlso' resource
        ['RegionList', [
            'Area' => ['X' => 0, 'Y' => 0, 'W' => 8, 'H' => 8],
            'Name' => 'Region 1',
            'type' => 'Face',
            'seeAlso' => 'plus:Licensee',
        ]],
        // write alternate language structure elements
        ['ArtworkOrObject', "{AOTitle=test,aotitle-de=pr\xc3\xbcfung,AOTitle_FR=\xc3\xa9preuve}"],
        // disable print conversion for a single structure element
        ['XMP:Flash', '{Return=no,mode#=2}'],
        // write a complex user-defined lang-alt structure
        ['BTestTag', "{Field1-en-CA=[eh?],Field1-en-US=[huh?,groovy],Field1-fr=[,ing\xc3\xa9nieux]}"],
        // write some dynamic structure elements
        ['RegionList', ['Extensions' => [
            // may mix-and-match flattened and structured tags when writing!...
            'XMP-exif:FlashReturn' => 'not',    // flattened tag with group name
            'Flash' => ['Mode#' => 1],          // structured tag with disabled conversion
            'UsageTerms-fr' => 'libre',         // lang-alt tag
            'ArtworkTitle-de' => "verf\xc3\xa4nglich", // renamed lang-alt tag in a list
            'Renamed' => 'this is wild',        // user-defined renamed flattened tag with TYPE
        ]]],
    ];
    foreach ($writeInfo as $args) {
        $et->SetNewValue(...$args);
    }
    $ok = writeInfo($et, null, $testfile);

    return testCompare("$t/images/XMP5.xmp", $testfile, 35) && $ok;
}, $skip, true);

// tests 36-37: Test reading structures with and without the Struct option
for ($i = 0; $i < 2; ++$i) {
    runTest($testname, 36 + $i, function () use ($i, $testname) {
        $et = new ExifTool();
        $et->Options('Struct', 1 - $i);
        $et->Options('Escape', 'HTML');     // test escaping of structure fields too
        $info = $et->ImageInfo(testImage('XMP5.xmp'));

        return check($et, $info, $testname, 36 + $i);
    }, $skip);
}

// test 38: Copy complex structured information
runTest($testname, 38, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_38_failed.xmp";
    @unlink($testfile);
    $et->SetNewValuesFromFile(testImage('XMP5.xmp'), 'xmp:all');
    $ok = writeInfo($et, null, $testfile);

    return testCompare("$t/images/XMP5.xmp", $testfile, 38) && $ok;
}, $skip, true);

// test 39: Extract information from an INX file
runTest($testname, 39, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP.inx'), ['Duplicates' => 1]);

    return check($et, $info, $testname, 39);
}, $skip);

// test 40: Copy by flattened tag name and structure at the same time
runTest($testname, 40, function () use ($testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_40_failed.xmp";
    @unlink($testfile);
    $et->SetNewValuesFromFile(testImage('XMP5.xmp'), 'HierarchicalKeywords1', 'Licensee');
    $ok = writeInfo($et, null, $testfile);
    $info = $et->ImageInfo($testfile, 'XMP:*');
    if (check($et, $info, $testname, 40) && $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 41: Rest writing/reading all DarwinCore tags
runTest($testname, 41, function () use ($testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_41_failed.xmp";
    @unlink($testfile);
    $et->SetNewValue('xmp-dwc:*', 2013);
    $ok = writeInfo($et, null, $testfile);
    $info = $et->ImageInfo($testfile, ['Duplicates' => 1]);
    if (check($et, $info, $testname, 41) && $ok) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 42: Read extended XMP
runTest($testname, 42, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('ExtendedXMP.jpg'), 'xmp:all');

    return check($et, $info, $testname, 42);
}, $skip);

// test 43: Read XMP with unusual namespace prefixes
runTest($testname, 43, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('XMP6.xmp'), 'xmp:all');

    return check($et, $info, $testname, 43);
}, $skip);

// test 44: Write XMP with unusual namespace prefixes
runTest($testname, 44, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_44_failed.xmp";
    @unlink($testfile);
    $et->SetNewValue('xmp-dc:subject', 'changed');
    $et->WriteInfo(testImage('XMP6.xmp'), $testfile);
    $err = $et->GetValue('Error');
    if ($err) {
        fwrite(STDERR, "\n  $err\n");
    }

    return testCompare("$t/XMP_44.out", $testfile, 44);
}, $skip, true);

// test 45: Write empty structures
runTest($testname, 45, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_45_failed.xmp";
    @unlink($testfile);
    $et->SetNewValue('regioninfo', '{RegionList=[,]}');
    $et->SetNewValue('xmp:flash', '{}');
    $et->WriteInfo(null, $testfile);

    return testCompare("$t/XMP_45.out", $testfile, 45);
}, $skip, true);

// test 46: Test the advanced-formatting '@' feature and "All" in the source group
runTest($testname, 46, function () use ($testname) {
    $et = new ExifTool();
    $et->Options('ListSplit', ', ');
    $subj = 'subject<${subject@;/^Test/ ? $_=undef : s/Tool$//}';
    $keyw = 'creator<${Adobe:all:all@;s/n/N/;$_=undef if /Y/}';
    // (exclude Adobe tag because the Adobe segment would be extracted as a block when copying)
    $et->SetNewValuesFromFile(testImage('XMP.jpg'), $subj, '-adobe', $keyw);
    $testfile = "t/{$testname}_46_failed.xmp";
    @unlink($testfile);
    writeInfo($et, null, $testfile);
    $et->Options('ListSep', ' // ');
    $info = $et->ImageInfo($testfile, 'Subject', 'Creator');
    if (check($et, $info, $testname, 46)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// tests 47-49: Test replacing specific elements in list of structures
$et = new ExifTool();
$et->Options('ListSplit', ',');
$et->Options('Struct', 1);
runTest($testname, 47, function () use ($et, $testname) {
    $et->SetNewValue('LocationShownCity', 'Manchester,Lyon,Frankfurt');
    $testfile = "t/{$testname}_47_failed.xmp";
    @unlink($testfile);
    writeInfo($et, testImage('XMP7.xmp'), $testfile);
    $info = $et->ImageInfo($testfile, 'XMP:all');
    if (check($et, $info, $testname, 47)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);
runTest($testname, 48, function () use ($et, $testname) {
    $et->SetNewValue();
    $et->SetNewValue('LocationShownCity', 'London,Berlin', DelValue: 1);
    $et->SetNewValue('LocationShownCity', 'Oxford,Munich');
    $testfile = "t/{$testname}_48_failed.xmp";
    @unlink($testfile);
    writeInfo($et, testImage('XMP7.xmp'), $testfile);
    $info = $et->ImageInfo($testfile, 'XMP:all');
    if (check($et, $info, $testname, 48)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);
runTest($testname, 49, function () use ($et, $testname) {
    $et->SetNewValue();
    $et->SetNewValue('AboutCvTermName', 'one,three', DelValue: 1);
    $et->SetNewValue('AboutCvTermName', 'a,b,c');
    $testfile = "t/{$testname}_49_failed.xmp";
    @unlink($testfile);
    writeInfo($et, testImage('XMP8.xmp'), $testfile);
    $info = $et->ImageInfo($testfile, 'XMP:all');
    if (check($et, $info, $testname, 49)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 50-53: Test replacing/creating elements in nested lang-alt list
$et = new ExifTool();
runTest($testname, 50, function () use ($et, $t, $testname) {
    $et->SetNewValuesFromFile(testImage('XMP9.xmp'), '*:*');
    $testfile = "t/{$testname}_50_failed.xmp";
    @unlink($testfile);
    $et->WriteInfo(testImage('XMP9.xmp'), $testfile);

    return testCompare("$t/XMP_50.out", $testfile, 50);
}, $skip, true);
runTest($testname, 51, function () use ($et, $t, $testname) {
    $testfile = "t/{$testname}_51_failed.xmp";
    @unlink($testfile);
    $et->WriteInfo(null, $testfile);

    return testCompare("$t/XMP_50.out", $testfile, 51);
}, $skip, true);
runTest($testname, 52, function () use ($et, $t, $testname) {
    $testfile = "t/{$testname}_52_failed.xmp";
    @unlink($testfile);
    $et->SetNewValue();
    $et->SetNewValue('Custom1', 'test', DelValue: 1);
    $et->SetNewValue('Custom1', 'new');
    $et->WriteInfo(testImage('XMP9.xmp'), $testfile);

    return testCompare("$t/XMP_52.out", $testfile, 52);
}, $skip, true);
runTest($testname, 53, function () use ($et, $t, $testname) {
    $testfile = "t/{$testname}_53_failed.xmp";
    @unlink($testfile);
    $et->Options('ListSplit', ',');
    $et->SetNewValue();
    $et->SetNewValue('Custom1', 'a,b,c', AddValue: 1);
    $et->SetNewValue('Custom1-fr', 'a-fr,,c-fr,d-fr,,f-fr', AddValue: 1);
    $et->WriteInfo(testImage('XMP9.xmp'), $testfile);

    return testCompare("$t/XMP_53.out", $testfile, 53);
}, $skip, true);

// test 54: Write flattened tag and extension in a variable-namespace structure
runTest($testname, 54, function () use ($testname) {
    $testfile = "t/{$testname}_54_failed.xmp";
    @unlink($testfile);
    $et = new ExifTool();
    $et->SetNewValue('ImageRegionCtypeIdentifier', 'x');
    $et->SetNewValue('ImageRegion', '{Flash={Fired=True,Return#=3}}');
    $et->WriteInfo(null, $testfile);
    $et->Options('Struct', 1);
    $info = $et->ImageInfo($testfile, 'XMP-iptcExt:all');
    if (check($et, $info, $testname, 54)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

exit(testsDone());
