<?php

// Replays ExifTool's t/Geotag.t through the PHP API and compares with its expected output
// (t/Geotag_N.out). Run: php tests/geotag.php [~/exiftool] [--all]

use Image\ExifTool\ExifTool;

require __DIR__.'/TestLib.php';

// must create user-defined tags before loading ExifTool tables (used in test 8)
ExifTool::$UserDefined = [
    'Image::ExifTool::GPS::Main' => [
        0xD000 => ['Name' => 'GPSPitch', 'Writable' => 'rational64s'],
        0xD001 => ['Name' => 'GPSRoll', 'Writable' => 'rational64s'],
    ],
];

$skip = [];
$t = testDir();
$testname = 'Geotag';
$testTags = ['Error', 'Warning', 'GPS:*', 'XMP:*'];
$img = "$t/images";
$testfile2 = "t/{$testname}_2_failed.jpg";

// test 2: Geotag from GPX track log
runTest($testname, 2, function () use ($testname, $testfile2, $testTags, $img) {
    $et = new ExifTool();
    @unlink($testfile2);
    $et->SetNewValue('Geotag', "$img/Geotag.gpx");
    $et->SetNewValue('Geotime', '2003:05:24 17:09:31Z');
    $et->WriteInfo("$img/Writer.jpg", $testfile2);
    $info = $et->ImageInfo($testfile2, ...$testTags);

    return check($et, $info, $testname, 2);
}, $skip);

// tests 3-5: Geotag tests using Magellan track log
$et = new ExifTool();
runTest($testname, 3, function () use ($et, $testname, $testTags, $img) {
    // geotag to XMP
    $testfile = "t/{$testname}_3_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('Geotag', "$img/Geotag.log");
    $et->SetNewValue('XMP:Geotime', '2009:04:03 06:11:30-05:00');
    $et->WriteInfo("$img/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, ...$testTags);
    if (!check($et, $info, $testname, 3, 3)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);
runTest($testname, 4, function () use ($et, $testname, $testTags, $testfile2) {
    // point too far outside track
    $testfile = "t/{$testname}_4_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('Geotime', '2009:04:03 08:00:00-05:00');
    $et->WriteInfo($testfile2, $testfile);
    $info = $et->ImageInfo($testfile, ...$testTags);
    if (!check($et, $info, $testname, 4, 2)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);
runTest($testname, 5, function () use ($et, $testname, $testTags, $testfile2) {
    // delete geotags
    $testfile5 = "t/{$testname}_5_failed.jpg";
    @unlink($testfile5);
    $et->SetNewValue('Geotime', null);
    $et->WriteInfo($testfile2, $testfile5);
    $info = $et->ImageInfo($testfile5, 'Filename', ...$testTags);
    if (!check($et, $info, $testname, 5)) {
        return false;
    }
    @unlink($testfile2);
    @unlink($testfile5);

    return true;
}, $skip);

// test 6: Geotag from Garmin XML track log and test Geosync too
runTest($testname, 6, function () use ($testname, $testTags, $img) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_6_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('Geosync', '1:30');
    $et->SetNewValue('Geotag', "$img/Geotag.xml");
    $et->SetNewValuesFromFile("$img/Panasonic.jpg", 'Geotime<${DateTimeOriginal}+02:00');
    $et->WriteInfo("$img/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, ...$testTags);
    if (!check($et, $info, $testname, 6)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

// test 7: Geotag from IGC log with time drift correction
runTest($testname, 7, function () use ($testname, $img) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_7_failed.jpg";
    $txtfile = "t/{$testname}_7.failed";
    @unlink($testfile);
    $fh = fopen($txtfile, 'w');
    $et->Options('Verbose', 2);
    $et->Options('TextOut', $fh);
    $et->SetNewValue('Geosync', '2010:01:05 07:00:00Z@2001:08:01 12:00:00-02:00');
    $et->SetNewValue('Geosync', '2010:01:05 09:01:00Z@2001:08:01 14:00:00-02:00');
    // (the verbose output shows the track log name, so use the relative name like Perl: var/t/images)
    @mkdir('t/images');
    foreach (['Geotag.igc', 'Writer.jpg', 'Nikon.jpg'] as $f) {
        copy("$img/$f", "t/images/$f");
    }
    $et->SetNewValue('Geotag', 't/images/Geotag.igc');
    $et->SetNewValuesFromFile('t/images/Nikon.jpg', 'Geotime<${DateTimeOriginal}-02:00');
    $et->WriteInfo('t/images/Writer.jpg', $testfile);
    fclose($fh);
    if (!testCompare(testDir().'/Geotag_7.out', $txtfile, 7)) {
        return false;
    }
    @unlink($testfile);
    @unlink($txtfile);

    return true;
}, $skip);

// test 8:  Geotag with attitude information from PTNTHPR sentence
// test 9:  Geotag from KML track log (obtained from Google Location),
// test 10: Geotag from Bramor gEO log
$dat = [
    8 => ['Geotag2.log', '2010:04:24 06:27:30-05:00'],
    9 => ['Geotag.kml', '2013:11:13 09:04:31Z'],
    10 => ['Geotag3.log', '2014:04:21 07:06:42Z'],
];
$et = new ExifTool();
foreach ($dat as $n => [$file, $geotime]) {
    runTest($testname, $n, function () use ($et, $n, $file, $geotime, $testname, $testTags, $img) {
        $testfile = "t/{$testname}_{$n}_failed.jpg";
        @unlink($testfile);
        $et->SetNewValue('Geotag', "$img/$file");
        $et->SetNewValue('Geotime', $geotime);
        $et->WriteInfo("$img/Writer.jpg", $testfile);
        $info = $et->ImageInfo($testfile, ...$testTags);
        if (!check($et, $info, $testname, $n)) {
            return false;
        }
        @unlink($testfile);

        return true;
    }, $skip);
}

// test 11: Geotag date/time only with drift correction
runTest($testname, 11, function () use ($testname, $testTags, $img) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_11_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('Geotag', 'DATETIMEONLY');
    $et->SetNewValue('Geosync', '2009:01:01 01:00:00Z@2009:01:01 01:00:00Z');
    $et->SetNewValue('Geosync', '2011:01:01 02:00:00Z@2011:01:01 01:00:00Z');
    $et->SetNewValue('Geotime', '2010:01:01 01:00:00Z');
    $et->WriteInfo("$img/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, ...$testTags);
    if (!check($et, $info, $testname, 11)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

// test 12: Geotag from DJI CSV log file
runTest($testname, 12, function () use ($testname, $img) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_12_failed.jpg";
    @unlink($testfile);
    // (Note: the date/time must exist in the file name to determine the track start time)
    $et->SetNewValue('Geotag', "$img/Geotag_DJI_2020-12-02_[07-50-31].csv");
    $et->SetNewValue('Geotime', '2020:12:02 07:50:35.3');
    $et->WriteInfo("$img/Writer.jpg", $testfile);
    // (ignore GPSDate/TimeStamp because they will depend on the system time zone)
    $info = $et->ImageInfo($testfile, 'gps:all', '-gpstimestamp', '-gpsdatestamp');
    if (!check($et, $info, $testname, 12)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

// test 13: Geotag from Garmin FIT file
runTest($testname, 13, function () use ($testname, $testTags, $img) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_13_failed.jpg";
    @unlink($testfile);
    $et->SetNewValue('Geotag', "$img/Garmin.fit");
    $et->SetNewValue('Geotime', '2026:04:01 10:00:44.5-04:00');
    $et->WriteInfo("$img/Writer.jpg", $testfile);
    $info = $et->ImageInfo($testfile, ...$testTags);
    if (!check($et, $info, $testname, 13)) {
        return false;
    }
    @unlink($testfile);

    return true;
}, $skip);

exit(testsDone());
