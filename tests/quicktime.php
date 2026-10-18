<?php

// Replays ExifTool's t/QuickTime.t through the PHP API and compares with its expected output
// (t/QuickTime_N.out). Run: php tests/quicktime.php [~/exiftool] [--all]
// (tests needing writers not ported yet are in $skip: --all runs them anyway)
// Like in t/QuickTime.t, a test may rely on the file written by a previous one.

require __DIR__.'/TestLib.php';

use Image\ExifTool\ExifTool;

$skip = [];
$t = testDir();
$testname = 'QuickTime';

// tests 2-3: Extract information from QuickTime.mov and QuickTime.m4a
foreach (['mov' => 2, 'm4a' => 3] as $ext => $testnum) {
    runTest($testname, $testnum, function () use ($testname, $testnum, $ext) {
        $et = new ExifTool();
        $info = $et->ImageInfo(testImage("QuickTime.$ext"));

        return check($et, $info, $testname, $testnum);
    }, $skip);
}

// tests 4-5: Try writing XMP to the different file formats
$et = new ExifTool();
$et->Options('SavePath', 1); // to save group 5 names
$et->SetNewValue('XMP:Title', 'x');
$et->SetNewValue('TrackCreateDate', '2000:01:02 03:04:05');
$et->SetNewValue('Track1:TrackModifyDate', '2013:11:04 10:32:15');
foreach (['mov' => 4, 'm4a' => 5] as $ext => $testnum) {
    runTest($testname, $testnum, function () use ($et, $t, $testname, $testnum, $ext) {
        $testfile = "t/{$testname}_{$testnum}_failed.$ext";
        @unlink($testfile);
        $et->WriteInfo("$t/images/QuickTime.$ext", $testfile);
        $info = $et->ImageInfo($testfile, 'title', 'time:all');
        if (check($et, $info, $testname, $testnum, null, 5)) {
            @unlink($testfile);

            return true;
        }

        return false;
    }, $skip);
}

// test 6: Write video rotation
runTest($testname, 6, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->SetNewValue('Rotation', '270', Protected: 1);
    $testfile = "t/{$testname}_6_failed.mov";
    @unlink($testfile);
    $et->WriteInfo("$t/images/QuickTime.mov", $testfile);
    $info = $et->ImageInfo($testfile, 'Rotation', 'MatrixStructure');
    if (check($et, $info, $testname, 6)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 7: Add a bunch of new tags and delete one
runTest($testname, 7, function () use ($t, $testname) {
    $writeInfo = [
        ['QuickTime:Artist', 'me'],
        ['QuickTime:Model', 'model'],
        ['UserData:Genre', 'rock'],
        ['UserData:Album', 'albumA'],
        ['ItemList:Album', 'albumB'],
        ['ItemList:ID-albm:Album', 'albumC'],
        ['QuickTime:Comment-fra-FR', 'fr comment'],
        ['Keys:Director', 'director'],
        ['Keys:CameraDirection', '90'],
        ['Keys:Album', null],
    ];
    $extract = ['ItemList:all', 'UserData:all', 'Keys:all'];

    return writeCheck($writeInfo, $testname, 7, "$t/images/QuickTime.mov", $extract);
}, $skip);

// test 8-9: Delete everything then add back some tags in one step
foreach (['mov' => 8, 'm4a' => 9] as $ext => $testnum) {
    runTest($testname, $testnum, function () use ($t, $testname, $testnum, $ext) {
        $writeInfo = [
            ['all', null],
            ['artist', 'me'],
            ['keys:director', 'dir'],
            ['userdata:arranger', 'arr'],
        ];
        $extract = ['QuickTime:all', 'XMP:all'];

        return writeCheck($writeInfo, $testname, $testnum, "$t/images/QuickTime.$ext", $extract);
    }, $skip);
}

// test 10: Delete everything then add back some tags in two steps
$testfile10 = "t/{$testname}_10a_failed.mov";
runTest($testname, 10, function () use ($t, $testname, $testfile10) {
    @unlink($testfile10);
    $et = new ExifTool();
    $et->Options('QuickTimeHandler', 1);
    $et->SetNewValue('all', null);
    writeInfo($et, "$t/images/QuickTime.mov", $testfile10);
    $writeInfo = [
        ['quicktime:artist', 'me'],
        ['keys:director', 'dir'],
        ['userdata:arranger', 'arr'],
    ];
    $extract = ['QuickTime:all', 'XMP:all', '-Track1:all', '-Track2:all'];

    return writeCheck($writeInfo, $testname, 10, $testfile10, $extract);
}, $skip);

// tests 11-13: HEIC write tests
$et = new ExifTool();
$testfile11 = "t/{$testname}_11_failed.heic";
$testfile12 = "t/{$testname}_12_failed.heic";
runTest($testname, 11, function () use ($et, $t, $testname, $testfile11) {
    @unlink($testfile11);
    $et->Options('Composite', 0);
    $et->SetNewValue('XMP-dc:Title', 'a title');
    writeInfo($et, "$t/images/QuickTime.heic", $testfile11);
    $info = $et->ImageInfo($testfile11, '-file:all');

    return check($et, $info, $testname, 11);
}, $skip);
runTest($testname, 12, function () use ($et, $testname, $testfile11, $testfile12) {
    @unlink($testfile12);
    $et->SetNewValue();
    $et->SetNewValue('XMP:all', null);
    $et->SetNewValue('EXIF:Artist', 'an artist');
    writeInfo($et, $testfile11, $testfile12);
    $info = $et->ImageInfo($testfile12, '-file:all');
    if (check($et, $info, $testname, 12)) {
        @unlink($testfile11);

        return true;
    }

    return false;
}, $skip);
runTest($testname, 13, function () use ($et, $testname, $testfile12) {
    $testfile = "t/{$testname}_13_failed.heic";
    @unlink($testfile);
    $et->SetNewValue();
    $et->SetNewValue('EXIF:all', null);
    $et->SetNewValue('EXIF:UserComment', 'a comment');
    $et->SetNewValue('XMP:Subject', 'a subject');
    $et->SetNewValue('XMP:Subject', 'another subject');
    writeInfo($et, $testfile12, $testfile);
    $info = $et->ImageInfo($testfile, '-file:all');
    if (check($et, $info, $testname, 13)) {
        @unlink($testfile);
        @unlink($testfile12);

        return true;
    }

    return false;
}, $skip);

// test 14: Delete everything then add back a tag without specifying the group
runTest($testname, 14, function () use ($testname, $testfile10) {
    $writeInfo = [['publisher', 'pub']];
    $extract = ['QuickTime:all', 'XMP:all', '-Track1:all', '-Track2:all'];
    if (writeCheck($writeInfo, $testname, 14, $testfile10, $extract)) {
        @unlink($testfile10);

        return true;
    }

    return false;
}, $skip);

// test 15: Test WriteMode option with QuickTime tags
runTest($testname, 15, function () use ($t, $testname) {
    $et = new ExifTool();
    $et->Options('WriteMode', 'c');
    $et->SetNewValue('ItemList:Composer', 'WRONG');
    $et->SetNewValue('ItemList:Author', 'aut');
    $et->SetNewValue('Keys:Artist', 'WRONG');
    $et->SetNewValue('UserData:Artist', 'art');
    $testfile = "t/{$testname}_15_failed.m4a";
    @unlink($testfile);
    $et->WriteInfo("$t/images/QuickTime.m4a", $testfile);
    $info = $et->ImageInfo($testfile, 'ItemList:all', 'Keys:all', 'UserData:all');
    if (check($et, $info, $testname, 15)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 16: Write some Microsoft Xtra tags
runTest($testname, 16, function () use ($t, $testname) {
    $writeInfo = [
        ['Microsoft:Director', 'dir1'],
        ['Microsoft:Director', 'dir2'],
        ['Microsoft:SharedUserRating', 75],
    ];

    return writeCheck($writeInfo, $testname, 16, "$t/images/QuickTime.mov", ['Microsoft:all']);
}, $skip);

// test 17: Write some 3gp tags
runTest($testname, 17, function () use ($t, $testname) {
    $writeInfo = [
        ['UserData:LocationInformation', 'test comment role=Shooting lat=1.2 lon=-2.3 alt=100 body=earth notes=a note'],
        ['UserData:Rating', 'entity=ABCD criteria=1234 a rating'],
    ];

    return writeCheck($writeInfo, $testname, 17, "$t/images/QuickTime.mov", 1);
}, $skip);

// test 18-20: Write to audio and video tracks, clear the data and write again
$writeInfo18 = [
    ['VideoKeys:LensModel', 'test lens'],
    ['AudioKeys:Treble', '50'],
];
$writeMore = [
    ['Keys:Title', 'Keys'],
    ['ItemList:Title', 'ItemList'],
    ['UserData:Title', 'UserData'],
];
$tags = ['keys:all', 'audiokeys:all', 'videokeys:all', 'itemlist:all', 'userdata:all'];
$et = new ExifTool();
$testfile18 = "t/{$testname}_18_failed.mov";
$testfile19 = "t/{$testname}_19_failed.mov";
runTest($testname, 18, function () use ($et, $t, $testname, $testfile18, $writeInfo18, $tags) {
    foreach ($writeInfo18 as $args) $et->SetNewValue(...$args);
    @unlink($testfile18);
    $ok = writeInfo($et, "$t/images/QuickTime.mov", $testfile18);
    if ($ok) {
        $info = $et->ImageInfo($testfile18, ['Duplicates' => 1, 'Unknown' => 1], ...$tags);
        $ok = check($et, $info, $testname, 18);
    }

    return $ok;
}, $skip);
runTest($testname, 19, function () use ($et, $testname, $testfile18, $testfile19, $tags) {
    $et->SetNewValue();
    $et->SetNewValue('all', null);   // delete all
    @unlink($testfile19);
    $ok = writeInfo($et, $testfile18, $testfile19);
    if ($ok) {
        $info = $et->ImageInfo($testfile19, ['Duplicates' => 1, 'Unknown' => 1], ...$tags);
        $ok = check($et, $info, $testname, 19);
    }
    if ($ok) @unlink($testfile18);

    return $ok;
}, $skip);
runTest($testname, 20, function () use ($et, $testname, $testfile19, $writeInfo18, $writeMore, $tags) {
    $testfile3 = "t/{$testname}_20_failed.mov";
    $et->SetNewValue();
    foreach ([...$writeInfo18, ...$writeMore] as $args) $et->SetNewValue(...$args);
    @unlink($testfile3);
    $ok = writeInfo($et, $testfile19, $testfile3);
    if ($ok) {
        $info = $et->ImageInfo($testfile3, ['Duplicates' => 1, 'Unknown' => 1], ...$tags);
        $ok = check($et, $info, $testname, 20);
    }
    if ($ok) {
        @unlink($testfile19);
        @unlink($testfile3);
    }

    return $ok;
}, $skip);

// test 21-22: Write and delete alternate language tags
$et = new ExifTool();
$testfile21 = "t/{$testname}_21_failed.mov";
runTest($testname, 21, function () use ($et, $t, $testname, $testfile21) {
    $et->SetNewValue('Keys:Title-eng-ca', 'test');
    $et->SetNewValue('ItemList:all', null);
    $et->SetNewValue('ItemList:Title-eng-ca', 'test');
    $et->SetNewValue('ItemList:Title', 'test');
    @unlink($testfile21);
    $ok = writeInfo($et, "$t/images/QuickTime.mov", $testfile21);
    if ($ok) {
        $info = $et->ImageInfo($testfile21, ['Duplicates' => 1, 'Unknown' => 1], 'Keys:all', 'ItemList:all');
        $ok = check($et, $info, $testname, 21);
    }

    return $ok;
}, $skip);
runTest($testname, 22, function () use ($et, $testname, $testfile21) {
    $testfile2 = "t/{$testname}_22_failed.mov";
    $et->SetNewValue();
    $et->SetNewValue('Keys:Title-eng-ca', null);
    $et->SetNewValue('ItemList:Title-eng-ca', null);
    @unlink($testfile2);
    $ok = writeInfo($et, $testfile21, $testfile2);
    if ($ok) {
        $info = $et->ImageInfo($testfile2, ['Duplicates' => 1, 'Unknown' => 1], 'Keys:all', 'ItemList:all');
        $ok = check($et, $info, $testname, 22);
    }
    if ($ok) {
        @unlink($testfile21);
        @unlink($testfile2);
    }

    return $ok;
}, $skip);

exit(testsDone());
