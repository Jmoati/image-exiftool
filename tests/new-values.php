<?php

// Compares the new values of the PHP port with Perl ExifTool (Writer.pl part 1: SetNewValue,
// GetNewValue, SetNewValuesFromFile, Save/RestoreNewValues, IsOverwriting, InverseDateTime,
// ReverseLookup, EncodeBits, CheckValue...).  Each case runs a list of operations on a new
// ExifTool object, with Perl (tests/new-values.pl) and with PHP (this script with --php, in a
// child process so its warnings can be told apart), then compares the results, the
// warnings, the verbose output and the new values left in the object.
// A case that differs and involves untranslated Perl code ("not ported") is counted apart.
// Run: php tests/new-values.php [-v] [--images] [name filter] [~/exiftool]
// (--images also copies all the tags of each test image with SetNewValuesFromFile)

require __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '4G');

use Image\ExifTool\ExifTool;
use Image\ExifTool\NewValue;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;

$verbose = false;
$filter = null;
$exiftool = getenv('HOME') . '/exiftool';
$args = array_slice($argv, 1);
if (($args[0] ?? '') === '--php') {
    runPhp();
    exit(0);
}
$images = false;
foreach ($args as $a) {
    if ($a === '--images') {
        $images = true;     // also copy all tags of each test image
    } elseif ($a === '-v') {
        $verbose = true;
    } elseif (is_dir($a)) {
        $exiftool = rtrim($a, '/');
    } else {
        $filter = $a;
    }
}

//------------------------------------------------------------------------------
// the cases: [name, op...] where op is [what, args...] (see runOp)
$img = "$exiftool/t/images";
$hex = static fn (string $bytes) => ['__hex' => bin2hex($bytes)];
$cases = [];
$c = static function (string $name, array ...$ops) use (&$cases) {
    $cases[] = ['name' => $name, 'ops' => $ops];
};

// simple tags
$c('artist', ['set', 'Artist', 'Phil'], ['get', 'Artist'], ['get', 'EXIF:Artist'], ['get', 'IFD0:Artist'], ['get', 'XMP:Artist']);
$c('title', ['set', 'Title', 'T'], ['get', 'Title'], ['get', 'XMP:Title'], ['get', 'XMP-dc:Title'], ['get', 'QuickTime:Title']);
$c('xmp title', ['set', 'XMP:Title', 'x'], ['get', 'Title']);
$c('xmp-dc title', ['set', 'XMP-dc:Title', 'x'], ['set', 'xmp-DC:title', 'y'], ['get', 'Title']);
$c('copyright', ['set', 'Copyright', '(c) me'], ['get', 'Copyright'], ['get', 'EXIF:Copyright']);
$c('user comment', ['set', 'UserComment', 'hello'], ['get', 'UserComment'], ['set', 'EXIF:UserComment', '']);
$c('image description', ['set', 'ImageDescription', "a\nb"], ['set', 'Make', 'Canon'], ['set', 'Model', 'X'], ['set', 'Software', 'S']);
$c('orientation', ['set', 'Orientation', 'Rotate 90 CW'], ['get', 'Orientation']);
$c('orientation #', ['set', 'Orientation#', '6'], ['get', 'Orientation']);
$c('orientation valueconv', ['set', 'Orientation', 3, ['Type' => 'ValueConv']], ['set', 'Orientation', 9, ['Type' => 'ValueConv']]);
$c('orientation raw', ['set', 'Orientation', 3, ['Type' => 'Raw']], ['set', 'Orientation', 'x', ['Type' => 'Raw']]);
$c('orientation bad', ['set', 'Orientation', 'foo']);
$c('orientation lc', ['set', 'Orientation', 'rotate 180']);
$c('orientation start', ['set', 'Orientation', 'Rotate 2']);
$c('orientation multi', ['set', 'Orientation', 'Mirror']);
$c('orientation unknown', ['set', 'Orientation', 'Unknown (9)'], ['set', 'IFD1:Orientation', 'Unknown (0x2)']);
$c('exposure', ['set', 'ExposureTime', '1/125'], ['set', 'FNumber', '5.6'], ['set', 'ISO', '200'], ['set', 'ISO', 'abc'],
    ['set', 'FocalLength', '50 mm'], ['set', 'ExposureCompensation', '-1/3'], ['set', 'ExposureProgram', 'Manual'],
    ['set', 'MeteringMode', 'Spot'], ['set', 'WhiteBalance', 'Manual'], ['set', 'ApertureValue', '4'], ['set', 'ShutterSpeedValue', '1/60']);
$c('flash', ['set', 'Flash', 'Fired'], ['get', 'Flash'], ['set', 'Flash', 'On, Fired'], ['get', 'Flash'], ['set', 'Flash', 'No Flash'], ['get', 'Flash'],
    ['set', 'Flash', 'Fired, Return detected'], ['set', 'Flash', '0x10'], ['set', 'Flash', 'Unknown (0x41)']);
$c('resolution', ['set', 'XResolution', '300'], ['set', 'ResolutionUnit', 'inches'], ['set', 'YResolution', 'x'], ['set', 'IFD1:XResolution', 72]);
$c('dates', ['set', 'DateTimeOriginal', '2020:01:02 03:04:05'], ['set', 'CreateDate', '2020:01:02 03:04:05.123'],
    ['set', 'ModifyDate', '2020:01:02 03:04:05+02:00'], ['get', 'DateTimeOriginal'], ['get', 'CreateDate'], ['get', 'ModifyDate']);
$c('bad dates', ['set', 'DateTimeOriginal', 'garbage'], ['set', 'DateTimeOriginal', '2020:13:01 00:00:00'],
    ['set', 'DateTimeOriginal', '2020:01:32 00:00:00'], ['set', 'DateTimeOriginal', '2020:01:02'], ['set', 'DateTimeOriginal', '2020:01:02 25:00:00']);
$c('alldates', ['set', 'AllDates', '2021:03:04 05:06:07'], ['get', 'CreateDate'], ['get', 'ModifyDate'], ['get', 'DateTimeOriginal']);
$c('offset time', ['set', 'OffsetTime', '+02:00'], ['set', 'OffsetTimeOriginal', '-5'], ['set', 'OffsetTimeDigitized', 'garbage']);
$c('gps', ['set', 'GPSLatitude', '45.5'], ['set', 'GPSLatitudeRef', 'N'], ['set', 'GPSLongitude', '122.3'], ['set', 'GPSLongitudeRef', 'West'],
    ['set', 'GPSAltitude', '100 m'], ['set', 'GPSAltitudeRef', 'Above Sea Level'], ['set', 'GPSDateStamp', '2020:01:02'],
    ['set', 'GPSTimeStamp', '12:34:56'], ['get', 'GPSLatitude'], ['get', 'GPSLongitudeRef'], ['get', 'GPSTimeStamp']);
$c('gps dms', ['set', 'GPSLatitude', "45 deg 30' 15.5\" N"], ['set', 'GPS:GPSLongitude', '-122.5'], ['set', 'GPSLatitude', 'x']);
$c('gps position', ['set', 'GPSPosition', '45.5 N, 122.3 W'], ['get', 'GPSLatitude'], ['get', 'GPSLongitude'], ['get', 'GPSLatitudeRef']);
$c('subsec composite', ['set', 'SubSecDateTimeOriginal', '2020:01:02 03:04:05.123+02:00'], ['get', 'DateTimeOriginal'],
    ['get', 'SubSecTimeOriginal'], ['get', 'OffsetTimeOriginal']);

// lists
$c('xmp list', ['set', 'XMP:Subject', 'a'], ['set', 'XMP:Subject', ['b', 'c'], ['AddValue' => 1]], ['get', 'XMP:Subject']);
$c('keywords list', ['set', 'Keywords', ['a', 'b', 'c']], ['get', 'Keywords'], ['get', 'IPTC:Keywords'], ['get', 'XMP:Keywords']);
$c('keywords add del', ['set', 'Keywords', 'a'], ['set', 'Keywords', 'b', ['AddValue' => 1]], ['set', 'Keywords', 'a', ['DelValue' => 1]],
    ['get', 'Keywords'], ['over', 'Keywords', 'a'], ['over', 'Keywords', 'x']);
$c('keywords replace', ['set', 'Keywords', 'a'], ['set', 'Keywords', 'b', ['Replace' => 1]], ['set', 'Keywords', 'c', ['Replace' => 0]], ['get', 'Keywords']);
$c('listsplit', ['opt', 'ListSplit', ',\s*'], ['set', 'XMP:Subject', 'a, b,c'], ['get', 'XMP:Subject'], ['set', 'Artist', 'x, y']);
$c('list to non-list', ['set', 'Artist', ['a', 'b']], ['get', 'Artist'], ['set', 'XMP:Subject', [['x' => 1], 'b']]);
$c('nodups', ['opt', 'NoDups', 1], ['set', 'Keywords', ['a', 'b', 'a']], ['get', 'Keywords'], ['get', 'Keywords']);
$c('autosplit', ['set', 'XMP-exif:ISO', '100, 200'], ['get', 'XMP-exif:ISO'], ['set', 'XMP-tiff:YCbCrSubSampling', 'YCbCr4:2:2 (2 1)'],
    ['set', 'XMP-exif:ComponentsConfiguration', 'Y, Cb, Cr, -']);

// XMP
$c('xmp rating', ['set', 'XMP:Rating', '5'], ['set', 'XMP:Rating', 'x'], ['set', 'XMP:Rating', '0x10']);
$c('xmp boolean', ['set', 'XMP-xmpRights:Marked', 'True'], ['get', 'XMP-xmpRights:Marked'], ['set', 'XMP-xmpRights:Marked', 'false'],
    ['get', 'XMP-xmpRights:Marked'], ['set', 'XMP-xmpRights:Marked', 'false', ['Type' => 'ValueConv']], ['get', 'XMP-xmpRights:Marked'],
    ['set', 'XMP-xmpRights:Marked', 'yes'], ['set', 'XMP-xmpRights:Marked', 'no']);
$c('xmp date', ['set', 'XMP-xmp:CreateDate', '2020:01:02 03:04:05'], ['get', 'XMP-xmp:CreateDate'], ['set', 'XMP-xmp:ModifyDate', '2020:01:02'],
    ['get', 'XMP-xmp:ModifyDate'], ['set', 'XMP-xmp:MetadataDate', '12:00'], ['get', 'XMP-xmp:MetadataDate'], ['set', 'XMP-exif:DateTimeOriginal', 'bad'],
    ['set', 'XMP-photoshop:DateCreated', '2020:01:02 03:04:05+01:00'], ['get', 'XMP-photoshop:DateCreated']);
$c('xmp lang-alt', ['set', 'XMP-dc:Title-fr', 'Bonjour'], ['set', 'XMP-dc:Title-x-default', 'Hi'], ['set', 'XMP-dc:Description-en-US', 'x'],
    ['set', 'XMP-dc:Title-de_DE', 'Hallo'], ['set', 'Title-ZZ-bad1234567', 'x'], ['set', 'XMP-dc:Creator-fr', 'x'], ['set', 'Artist-en', 'x'],
    ['get', 'Title-fr'], ['get', 'XMP-dc:Title-x-default']);
$c('xmp rational', ['set', 'XMP-exif:FNumber', '5.6'], ['get', 'XMP-exif:FNumber'], ['set', 'XMP-exif:ExposureTime', '1/250'],
    ['get', 'XMP-exif:ExposureTime'], ['set', 'XMP-exif:FocalLength', 'abc'], ['set', 'XMP-exif:GPSLatitude', '45.5'], ['get', 'XMP-exif:GPSLatitude']);
$c('xmp flattened', ['set', 'XMP-iptcCore:CreatorWorkEmail', 'a@b.c'], ['get', 'CreatorWorkEmail'], ['set', 'XMP:CreatorCity', 'Paris'],
    ['get', 'CreatorCity'], ['set', 'XMP:LocationShownCity', 'X']);
$c('xmp struct', ['set', 'XMP-iptcCore:CreatorContactInfo', ['CiAdrCity' => 'Paris']], ['set', 'XMP:CreatorContactInfo', '{CiAdrCity=Paris}']);
$c('xmp misc', ['set', 'XMP:Label', 'red'], ['set', 'XMP-photoshop:Urgency', '3'], ['set', 'XMP-tiff:Orientation', 'Rotate 180'],
    ['set', 'XMP-xmp:Rating', '4'], ['set', 'XMP:Creator', 'me'], ['get', 'XMP:Creator'], ['set', 'XMP-pdf:Keywords', 'k']);

// IPTC
$c('iptc', ['set', 'IPTC:ObjectName', 'obj'], ['set', 'IPTC:Keywords', 'k'], ['set', 'IPTC:Keywords', str_repeat('x', 70), ['AddValue' => 1]],
    ['get', 'IPTC:Keywords'], ['set', 'IPTC:By-line', 'me'], ['set', 'IPTC:Province-State', 'P']);
$c('iptc dates', ['set', 'IPTC:DateCreated', '2020:01:02'], ['get', 'IPTC:DateCreated'], ['set', 'IPTC:TimeCreated', '12:34:56+01:00'],
    ['get', 'IPTC:TimeCreated'], ['set', 'IPTC:DigitalCreationDate', 'x']);
$c('iptc numbers', ['set', 'IPTC:Urgency', '5'], ['set', 'IPTC:Urgency', 'x'], ['set', 'IPTC:ApplicationRecordVersion', '4'],
    ['set', 'IPTC:ApplicationRecordVersion', '70000'], ['set', 'IPTC:CodedCharacterSet', 'UTF8'], ['get', 'IPTC:CodedCharacterSet']);
$c('iptc minor', ['opt', 'IgnoreMinorErrors', 1], ['set', 'IPTC:Keywords', str_repeat('y', 70)], ['set', 'IPTC:CountryCode', 'F']);

// other groups
$c('comment', ['set', 'Comment', 'c'], ['get', 'Comment'], ['set', 'File:Comment', 'x'], ['get', 'File:Comment'], ['set', 'JFIF:XResolution', '72']);
$c('file tags', ['set', 'FileModifyDate', '2020:01:02 03:04:05'], ['set', 'FileName', 'new.jpg'], ['set', 'Directory', 'dir'],
    ['set', 'FilePermissions', 'rw-r--r--'], ['get', 'FileName'], ['count'], ['set', 'FileName', null], ['set', 'FileSize', '10']);
$c('canon', ['set', 'Canon:OwnerName', 'me'], ['get', 'Canon:OwnerName'], ['set', 'Canon:CanonImageType', 'x'], ['set', 'Canon:MacroMode', 'Macro'],
    ['get', 'Canon:MacroMode'], ['set', 'Canon:Quality', 'Fine'], ['set', 'Canon:FocalLength', '50'], ['set', 'MakerNotes:OwnerName', 'x']);
$c('canon delete', ['set', 'Canon:OwnerName', null], ['get', 'Canon:OwnerName'], ['set', 'Canon:MacroMode', null], ['set', 'Canon:MacroMode', null, ['IgnorePermanent' => 1]]);
$c('nikon', ['set', 'Nikon:Quality', 'Fine'], ['set', 'Nikon:WhiteBalance', 'Auto'], ['set', 'Nikon:ISOSelection', 'x'], ['set', 'Nikon:ColorMode', 'Color']);
$c('quicktime', ['set', 'QuickTime:Title', 'q'], ['set', 'QuickTime:Artist', 'a'], ['set', 'Keys:Title', 'k'], ['set', 'ItemList:Title', 'i'],
    ['set', 'UserData:Title', 'u'], ['set', 'QuickTime:CreateDate', '2020:01:02 03:04:05'], ['set', 'QuickTime:GPSCoordinates', '45.5 N, 122.3 W'],
    ['set', 'Rotation', '90'], ['set', 'Track1:HandlerDescription', 'h'], ['get', 'QuickTime:Title'], ['get', 'Keys:Title']);
$c('png pdf', ['set', 'PNG:Title', 'p'], ['set', 'PNG:Comment', 'c'], ['set', 'PNG:Title-fr', 'f'], ['set', 'PDF:Title', 'p'], ['set', 'PDF:Author', 'a'],
    ['set', 'PDF:Keywords', ['a', 'b']], ['get', 'PDF:Keywords']);
$c('photoshop', ['set', 'Photoshop:XResolution', '300'], ['set', 'Photoshop:URL', 'http://x'], ['set', 'Photoshop:IPTCDigest', 'old', ['Protected' => 1, 'DelValue' => 1]],
    ['set', 'Photoshop:IPTCDigest', 'new', ['Protected' => 1]], ['get', 'Photoshop:IPTCDigest']);
$c('icc mie', ['set', 'ICC_Profile:ProfileDescription', 'd'], ['set', 'ICC_Profile:ProfileCopyright', 'c'], ['set', 'MIE:Comment', 'm'],
    ['set', 'MIE-Doc:Title', 't'], ['set', 'MIE1:Title', 'u'], ['set', 'MIE:Comment-fr_FR', 'f']);

// group specifications
$c('group families', ['set', '1IFD0:Artist', 'a'], ['set', '0EXIF:Artist', 'b'], ['set', 'IFD1:Artist', 'c'], ['set', 'ExifIFD:Artist', 'd'],
    ['set', 'GPS:Artist', 'e'], ['set', '2Author:Artist', 'f'], ['set', 'Author:Artist', 'g'], ['set', '3Main:Artist', 'h'], ['set', 'EXIF:IFD0:Artist', 'i']);
$c('group ids', ['set', 'ID-315:Artist', 'a'], ['set', '7ID-0x013b:Artist', 'b'], ['set', '1ID-0x13b:Artist', 'c'], ['set', 'IFD0:ID-0x013b:Artist', 'd'],
    ['set', 'XMP-dc:ID-title:Title', 'e'], ['get', 'Artist']);
$c('group translate', ['set', 'MakerNotes:OwnerName', 'x'], ['set', 'CIFF:OwnerName', 'y'], ['set', 'File:Comment', 'z'], ['set', 'EXIF:OwnerName', 'w']);
$c('invalid names', ['set', 'Foo', 'x'], ['set', 'Foo$', 'x'], ['set', '$Artist', 'x'], ['set', 'Tag', 'x'], ['set', 'XMP:Foo', 'x'],
    ['set', 'XMP:Artist', 'x'], ['set', 'FileSize', 'x'], ['set', 'IPTC:FileSize', 'x'], ['set', 'Fo?', 'x'], ['set', 'XMP:Fo*o', 'x']);
$c('wildcards', ['set', 'GPSLat*', '45'], ['set', 'XMP-dc:Tit?e', 'x'], ['set', 'ExposureT*', '1/10'], ['nvlist']);
$c('delete tags', ['set', 'Artist', null], ['set', 'XMP:Subject', null], ['set', 'Keywords', null], ['set', 'Orientation', null],
    ['get', 'Artist'], ['count'], ['set', 'Artist', null, ['AddValue' => 1]], ['set', 'Artist', '', ['DelValue' => 1]]);
$c('delete all', ['set', '*', null], ['count'], ['set', 'Artist', 'x'], ['count']);
$c('delete groups', ['set', 'XMP:*', null], ['set', 'EXIF:all', null], ['set', 'XMP-dc:*', null], ['set', 'MIE1:*', null], ['set', 'XML-x:*', null], ['count']);
$c('delete bad group', ['set', 'Foo:*', null], ['set', 'Time:*', null], ['set', 'Author:all', null], ['set', '1IFD0:all', null]);
$c('delete exclude', ['set', '*', null], ['set', 'IFD0:*', null, ['Replace' => 2]], ['set', 'XMP-dc:*', null, ['Replace' => 2]],
    ['set', 'MakerNotes:all', null, ['Replace' => 2]], ['count']);
$c('delete exclude xmp', ['set', 'XMP:all', null], ['set', 'XMP-dc:all', null, ['Replace' => 2]], ['set', 'XMP:all', null, ['Replace' => 2]]);
$c('remove for group', ['opt', 'Verbose', 2], ['set', 'Artist', 'a'], ['set', 'XMP:Title', 't'], ['set', 'IPTC:Keywords', 'k'], ['set', 'EXIF:*', null],
    ['set', 'XMP:*', null], ['count']);
$c('delgroup tag', ['set', 'EXIF', null], ['set', 'XMP', null], ['set', 'IPTC', null, ['DelValue' => 1]], ['count']);
$c('delcheck', ['set', 'FileName', null], ['set', 'PreviewImage', null], ['set', 'FileNumber', null]);

// options
$c('shift', ['set', 'DateTimeOriginal', '1:00:00', ['Shift' => 1]], ['over', 'DateTimeOriginal', '2020:01:02 03:04:05'], ['get', 'DateTimeOriginal'],
    ['set', 'CreateDate', '0:0:1 0:0:0', ['Shift' => -1]], ['over', 'CreateDate', '2020:01:02 03:04:05']);
$c('shift twice', ['set', 'DateTimeOriginal', '1:2:3 4:5:6', ['Shift' => 1]], ['over', 'DateTimeOriginal', '2020:01:31 23:59:59'],
    ['get', 'DateTimeOriginal'], ['over', 'DateTimeOriginal', '2019:12:31 00:00:00'], ['get', 'DateTimeOriginal'],
    ['set', 'OffsetTime', '1:30', ['Shift' => -1]], ['over', 'OffsetTime', '+02:00'], ['get', 'OffsetTime'], ['over', 'OffsetTime', '-01:00']);
$c('over createonly', ['opt', 'WriteMode', 'cg'], ['set', 'Artist', 'a'], ['over', 'Artist', 'old'], ['over', 'Artist', ''], ['set', 'XMP:Subject', 'k']);
$c('shift add del', ['set', 'DateTimeOriginal', '1', ['Shift' => 0, 'AddValue' => 1]], ['over', 'DateTimeOriginal', '2020:01:02 03:04:05.5'],
    ['set', 'ModifyDate', '0:30', ['Shift' => 0, 'DelValue' => 1]], ['over', 'ModifyDate', '2020:01:02 03:04:05+02:00']);
$c('shift errors', ['set', 'Artist', '1', ['Shift' => 1]], ['set', 'DateTimeOriginal', 'abc', ['Shift' => 1]], ['set', 'Keywords', 'x', ['Shift' => 1]],
    ['set', 'DateTimeOriginal', '', ['Shift' => 1]], ['set', 'Artist', 'x', ['Shift' => 0, 'DelValue' => 1]]);
$c('shift number', ['set', 'ISO', '10', ['Shift' => 1]], ['over', 'ISO', '100'], ['get', 'ISO'], ['set', 'FocalLength', 'x', ['Shift' => 1]],
    ['set', 'ExposureCompensation', '0.5', ['Shift' => -1]], ['over', 'ExposureCompensation', '1/3'], ['get', 'ExposureCompensation']);
$c('conditional', ['set', 'Artist', 'old', ['DelValue' => 1]], ['set', 'Artist', 'new'], ['over', 'Artist', 'old'], ['over', 'Artist', 'other'],
    ['over', 'Artist', null], ['get', 'Artist']);
$c('addvalue', ['set', 'Artist', 'x', ['AddValue' => 1]], ['set', 'Artist', 'y', ['AddValue' => 2]], ['get', 'Artist']);
$c('writemode c', ['opt', 'WriteMode', 'c'], ['set', 'Artist', 'a'], ['set', 'XMP:Title', 't'], ['set', 'Keywords', 'k'], ['over', 'Artist', 'z']);
$c('writemode w', ['opt', 'WriteMode', 'w'], ['set', 'Artist', 'a'], ['set', 'Keywords', 'k']);
$c('writemode wc', ['opt', 'WriteMode', 'wc'], ['set', 'Artist', 'a'], ['set', 'XMP:Title', 't']);
$c('editonly', ['set', 'Artist', 'a', ['EditOnly' => 1]], ['set', 'Title', 't', ['EditGroup' => 1]], ['set', 'Keywords', 'k', ['EditOnly' => 1, 'AddValue' => 1]]);
$c('protected', ['set', 'YCbCrPositioning', 'Centered'], ['set', 'YCbCrPositioning', 'Centered', ['Protected' => 1]],
    ['set', 'ThumbnailOffset', 10], ['set', 'ThumbnailOffset', 10, ['Protected' => 3]], ['set', 'ExifByteOrder', 'Little-endian (Intel, II)'],
    ['set', 'ExifVersion', '0232'], ['set', 'ExifVersion', '0232', ['Protected' => 1]]);
$c('replace 2', ['set', 'Artist', 'a'], ['set', 'Artist', null, ['Replace' => 2]], ['count'], ['set', 'GPSPosition', '1 N, 2 E'],
    ['set', 'GPSPosition', null, ['Replace' => 2]], ['count']);
$c('save restore', ['set', 'Artist', 'a'], ['save'], ['set', 'Artist', 'b'], ['set', 'XMP:Title', 't'], ['set', '*', null], ['restore'],
    ['get', 'Artist'], ['get', 'XMP:Title'], ['count']);
$c('save restore lists', ['set', 'Keywords', 'a'], ['save'], ['set', 'Keywords', 'b', ['AddValue' => 1]], ['get', 'Keywords'], ['restore'], ['get', 'Keywords']);
$c('protect saved', ['set', 'Artist', 'a'], ['save'], ['set', 'Artist', 'b', ['ProtectSaved' => 0]], ['get', 'Artist'], ['set', 'Keywords', 'k'],
    ['save'], ['set', 'Keywords', 'l', ['ProtectSaved' => 1, 'Replace' => 0]], ['get', 'Keywords'], ['set', 'Artist', 'c', ['ProtectSaved' => 1]], ['get', 'Artist']);
$c('printconv off', ['opt', 'PrintConv', 0], ['set', 'Orientation', 6], ['set', 'Orientation', 'Horizontal (normal)'], ['set', 'ExposureTime', '0.004'], ['get', 'ExposureTime']);
$c('escape', ['opt', 'Escape', 'XML'], ['set', 'Artist', 'a &amp; b &lt;c&gt;'], ['get', 'Artist'], ['opt', 'Escape', 'HTML'], ['set', 'Copyright', '&copy; x'], ['get', 'Copyright']);
$c('charset', ['opt', 'Charset', 'Latin'], ['set', 'XMP:Title', $hex("caf\xe9")], ['get', 'XMP:Title'], ['set', 'Artist', $hex("caf\xe9")], ['get', 'Artist']);
$c('bad utf8', ['set', 'XMP:Title', $hex("a\xc3(b\x01c")], ['get', 'XMP:Title']);
$c('scalar refs', ['set', 'Artist', ['__sref' => 'ref']], ['get', 'Artist'], ['set', 'ThumbnailImage', ['__sref' => $hex("\xff\xd8\xff\xd9")]], ['get', 'ThumbnailImage'],
    ['set', 'ThumbnailImage', 'not an image'], ['set', 'PreviewImage', ['__sref' => $hex("\xff\xd8\xff\xd9")]]);
$c('verbose', ['opt', 'Verbose', 3], ['set', 'Artist', 'a'], ['set', 'Keywords', 'k', ['AddValue' => 1]], ['set', 'Keywords', 'k', ['DelValue' => 1]],
    ['set', 'XMP:Title', null], ['set', 'Foo', 'x'], ['set', 'Orientation', 'bad'], ['set', 'GPSPosition', '1 N, 2 E'], ['set', 'Canon:OwnerName', null],
    ['set', 'Artist', 'b', ['EditGroup' => 1]], ['set', 'YCbCrPositioning', 'Centered']);
$c('verbose writemode', ['opt', 'Verbose', 3], ['opt', 'WriteMode', 'c'], ['set', 'Artist', 'a'], ['set', 'Title', 't'], ['set', 'XMP-xmp:Rating', 1, ['Shift' => 1]]);
$c('scalar context', ['sset', 'Artist', 'a'], ['sset', 'Foo', 'x'], ['sset', 'Orientation', 'bad'], ['sset', 'Bar:*', null], ['sset', 'Keywords', ['a', 'b']]);

// SetNewValuesFromFile
$c('from all', ['from', "$img/Canon.jpg"], ['count']);
$c('from tags', ['from', "$img/Canon.jpg", 'Artist', 'Make', 'Model', 'Orientation'], ['get', 'Make'], ['get', 'Model'], ['get', 'Orientation']);
$c('from exif', ['from', "$img/Canon.jpg", 'EXIF:*'], ['count']);
$c('from xmp', ['from', "$img/XMP.jpg", 'XMP:all'], ['count']);
$c('from iptc', ['from', "$img/IPTC.jpg", 'IPTC:Keywords', 'XMP:Subject<IPTC:Keywords'], ['get', 'IPTC:Keywords'], ['get', 'XMP:Subject']);
$c('from redirect', ['from', "$img/Canon.jpg", 'Comment<Model', 'XMP:Title<EXIF:Make', 'Artist>XMP:Creator', '+Keywords<Make', 'Keywords+<Model'],
    ['get', 'Comment'], ['get', 'XMP:Title'], ['get', 'Keywords']);
$c('from expr', ['from', "$img/Canon.jpg", 'Comment<$Make $Model', 'XMP:Title<${Model} - $Foo', 'Artist<Make$'], ['get', 'Comment'], ['get', 'XMP:Title']);
$c('from assign', ['from', "$img/Canon.jpg", 'Comment=Hello World', 'Artist^=', 'Copyright=', 'XMP:Title= x'], ['get', 'Comment'], ['get', 'Artist'], ['get', 'XMP:Title']);
$c('from exclude', ['from', "$img/Canon.jpg", '-Make', '-EXIF:Model'], ['count'], ['get', 'Make'], ['get', 'Model']);
$c('from exclude 2', ['from', "$img/Canon.jpg", 'EXIF:*', '-Make'], ['get', 'Make'], ['get', 'Model']);
$c('from groups', ['from', "$img/ExifTool.jpg", '*:*'], ['count']);
$c('from all all', ['from', "$img/ExifTool.jpg", 'all:all', '-XMP:all'], ['count']);
$c('from wildcard', ['from', "$img/Canon.jpg", 'Date*', '*Date'], ['count'], ['nvlist']);
$c('from dst group', ['from', "$img/ExifTool.jpg", 'XMP:*>XMP-dc:*', 'IPTC:*>1:*'], ['count']);
$c('from options', ['from', "$img/Canon.jpg", ['Type' => 'ValueConv'], 'Orientation', 'ExposureTime'], ['get', 'Orientation'], ['get', 'ExposureTime']);
$c('from #', ['from', "$img/Canon.jpg", 'Orientation#', 'XMP:Orientation<Orientation#'], ['get', 'Orientation'], ['get', 'XMP:Orientation']);
$c('from replace', ['set', 'Keywords', 'x'], ['from', "$img/IPTC.jpg", ['Replace' => 0], 'Keywords'], ['get', 'Keywords']);
$c('from id', ['from', "$img/Canon.jpg", 'ID-0x010f:all', '7ID-0x0110>Artist'], ['get', 'Make'], ['get', 'Artist']);
$c('from bad', ['from', "$img/Canon.jpg", 'Comment<Foo Bar', 'Foo$<Make', '-Make>Artist'], ['count']);
$c('from missing', ['from', "$img/NoSuchFile.jpg", 'Artist'], ['from', "$img/NoSuchFile.jpg"]);
$c('from nikon', ['from', "$img/Nikon.jpg", 'MakerNotes:all'], ['count']);
$c('from xmp jpg', ['from', "$img/XMP.jpg"], ['count']);
$c('from object', ['fromet', "$img/Canon.jpg", 'Make', 'XMP:Title<Model', 'EXIF:*'], ['count'], ['get', 'XMP:Title']);
$c('from object all', ['fromet', "$img/ExifTool.jpg"], ['count']);
$c('from verbose', ['opt', 'Verbose', 3], ['from', "$img/Canon.jpg", 'Make', 'XMP:Title<Model', '-Model']);
$c('from alt file', ['alt', 'File1', "$img/Nikon.jpg"], ['from', "$img/Canon.jpg", 'File1:Make>Artist', 'Model', 'File1:Model>XMP:Title'],
    ['get', 'Artist'], ['get', 'Model'], ['get', 'XMP:Title']);
$c('bitmask', ['set', 'Canon:FlashBits', 'E-TTL, Built-in'], ['get', 'Canon:FlashBits'], ['set', 'Canon:FlashBits', '(none)'],
    ['set', 'Canon:FlashBits', 'E-TTL, Foo'], ['set', 'Canon:FlashBits', 'Foo'], ['set', 'Canon:FlashBits', '[3], Manual']);
$c('conv list', ['set', 'Canon:PictureStylePC', 'Standard; Portrait; Landscape'], ['get', 'Canon:PictureStylePC'],
    ['set', 'CanonCustom:ShutterSpeedRange', 'Enable; 1/8000; 30'], ['set', 'Nikon:AFFineTuneAdj', '+2']);
$c('lang', ['opt', 'Lang', 'de'], ['set', 'Orientation', 'Horizontal gespiegelt'], ['get', 'Orientation'], ['opt', 'Lang', 'fr'],
    ['set', 'Flash', 'Flash non d' . "\xc3\xa9" . 'clench' . "\xc3\xa9" . ', mode auto'], ['get', 'Flash'], ['set', 'Flash', 'Fired']);
$c('raw conv', ['set', 'Canon:CameraISO', '100', ['DelValue' => 1]], ['set', 'Canon:CameraISO', '200'], ['over', 'Canon:CameraISO', 17],
    ['over', 'Canon:CameraISO', 0x7fff], ['over', 'Canon:CameraISO', 18]);
// (not QuickTime:CreateDate: its ValueConv warns under -w without a file read, FileType being undef)
$c('shift valueconv', ['set', 'XMP-xmp:CreateDate', '1:0:0', ['Shift' => 1]], ['over', 'XMP-xmp:CreateDate', '2020-01-02T03:04:05+01:00'],
    ['get', 'XMP-xmp:CreateDate'], ['set', 'GPSAltitude', '10', ['Shift' => 1]], ['over', 'GPSAltitude', '100'], ['get', 'GPSAltitude'],
    ['set', 'GPSTimeStamp', '0:30:0', ['Shift' => -1]], ['over', 'GPSTimeStamp', '12 0 0'], ['get', 'GPSTimeStamp']);
$c('structure to non-struct', ['set', 'Artist', ['a' => 1]], ['set', 'XMP:Title', ['x' => 'y']], ['set', 'Keywords', [['a' => 1]]]);

// IsOverwriting, GetGeolocateTags, IsWritable...
$c('over dates', ['set', 'DateTimeOriginal', '2020:01:02 03:04:05', ['DelValue' => 1]], ['set', 'DateTimeOriginal', '2021:01:01 00:00:00'],
    ['over', 'DateTimeOriginal', '2020:01:02 03:04:05'], ['over', 'DateTimeOriginal', '2020:01:02 03:04:06']);
$c('writable', ['writable', 'Artist'], ['writable', 'Foo'], ['writable', 'XMP:Title'], ['writable', 'FileSize'], ['writable', 'ImageWidth'],
    ['writable', 'Keywords'], ['writable', 'CreatorCity'], ['writable', 'Composite:GPSPosition']);
$c('geolocate', ['geo', null, 0], ['geo', 'xmp', 1], ['geo', 'exif', null], ['geo', 'keys:quicktime', 0], ['geo', 'xmp-iptcext:iptc', 1], ['geo', 'quicktime', 1]);
$c('alternate', ['alt', 'File1', 'x.jpg'], ['alt', 'file2', null], ['alt', 'Foo', 'x'], ['alt', 'FILE3', '$Directory/x.jpg']);
$c('groups', ['groups'], ['groups', 'XMP', 'EXIF'], ['set', 'Title', 't'], ['get', 'Title']);
$c('nvlist', ['set', 'Artist', 'a'], ['set', 'Title', 't'], ['set', 'Keywords', 'k'], ['nvlist']);
$c('apply shift', ['set', 'DateTimeOriginal', '2020:01:02 03:04:05'], ['shift', 'DateTimeOriginal', 'Time', '+1:00:00', '2020:01:02 03:04:05'],
    ['shift', 'DateTimeOriginal', 'Time', '-1:0:0 0:0:0', '2020:03:01 00:00:00'], ['shift', 'DateTimeOriginal', 'Time', 'x', '2020:01:01 00:00:00'],
    ['set', 'ISO', '100'], ['shift', 'ISO', 'Number', '+5', '100']);

// InverseDateTime
$c('inverse date', ['idt', '2020:01:02 03:04:05', null, null], ['idt', '2020-01-02T03:04:05Z', 1, null], ['idt', '2020:01:02 03:04:05+05:30', 0, null],
    ['idt', '2020:1:2 3:4:5', null, null], ['idt', '2020', null, 1], ['idt', '2020:01', null, 1], ['idt', 'garbage', null, null],
    ['idt', '2020:13:02 00:00:00', null, null], ['idt', '2020:01:32 00:00:00', null, null], ['idt', '2020:01:02 25:00:00', null, null],
    ['idt', '2020:01:02 03:60:00', null, null], ['idt', '2020:01:02 03:04:05.678-0300', 1, null], ['idt', '2020:01:02 03:04:05.678', 0, null],
    ['idt', '2020:01:02 03:04', null, 1], ['idt', '2020:01:02 03:04:05 DST', null, null], ['idt', '2020:01:02 03:04:05+1:00 DST', null, null]);
$c('inverse date tz', ['idt', '2020:07:02 03:04:05', 1, null], ['idt', '2020:01:02 03:04:05', 1, null], ['idt', '2020:01:02', 1, 1]);
$c('inverse date fmt', ['opt', 'DateFormat', '%Y-%m-%d %H:%M:%S'], ['idt', '2020-01-02 03:04:05', null, null], ['idt', '2020-01-02 03:04:05+02:00', null, null],
    ['idt', '2020/01/02', null, null], ['idt', '2020-02-30 03:04:05', null, null], ['idt', '2020-01-02 03:04:05 extra', null, null]);
$c('inverse date fmt 2', ['opt', 'DateFormat', '%d/%m/%Y'], ['idt', '02/01/2020', null, null], ['idt', '02/01/2020', null, 1],
    ['opt', 'DateFormat', '%Y%m%d'], ['idt', '20200102', null, null], ['opt', 'DateFormat', '%s'], ['idt', '1577934245', null, null],
    ['opt', 'DateFormat', '%Y-%m-%dT%H:%M:%S%z'], ['idt', '2020-01-02T03:04:05+0200', null, null],
    ['opt', 'DateFormat', '%Y-%m-%d %H:%M:%S.%f'], ['idt', '2020-01-02 03:04:05.123', null, null],
    ['opt', 'DateFormat', '%b %d %Y'], ['idt', 'Jan 02 2020', null, null], ['opt', 'DateFormat', '%B %e, %Y %I:%M %p'], ['idt', 'March  5, 2021 07:15 PM', null, null],
    ['opt', 'DateFormat', '%a %d %b %Y %T'], ['idt', 'Thu 02 Jan 2020 03:04:05', null, null], ['opt', 'DateFormat', '%y-%m-%d'], ['idt', '68-01-02', null, null],
    ['opt', 'DateFormat', '%F %R'], ['idt', '2020-01-02 03:04', null, null], ['opt', 'DateFormat', '%Y %j'], ['idt', '2020 060', null, null]);
$c('inverse date fmt strict', ['opt', 'DateFormat', '%Y-%m-%d'], ['opt', 'StrictDate', 1], ['idt', 'nothing', null, null],
    ['opt', 'StrictDate', 0], ['idt', 'nothing', null, null], ['set', 'DateTimeOriginal', '2020-01-02'], ['set', 'DateTimeOriginal', 'x']);
$c('inverse date write', ['opt', 'DateFormat', '%d.%m.%Y %H:%M'], ['set', 'DateTimeOriginal', '02.01.2020 03:04'], ['get', 'DateTimeOriginal'],
    ['set', 'XMP:CreateDate', '02.01.2020 03:04'], ['get', 'XMP:CreateDate'], ['set', 'ModifyDate', 'bad'], ['set', 'IPTC:DateCreated', '02.01.2020 00:00']);

// ReverseLookup, EncodeBits, CheckValue
$lk = ['1' => 'On', '0' => 'Off', '2' => 'On (Auto)', '3' => 'Auto'];    // (not a list, so it is a JSON object)
$c('reverse lookup', ['rev', 'On', $lk], ['rev', 'on', $lk], ['rev', 'O', $lk], ['rev', 'uto', $lk], ['rev', 'Unknown (5)', $lk],
    ['rev', 'Unknown (0x1f)', $lk], ['rev', 'zzz', $lk], ['rev', 'Off ', $lk], ['rev', 'On (', $lk], ['rev', null, $lk], ['rev', 'Notes', ['0' => 'x', 'Notes' => 'Notes']]);
$bl = ['1' => 'Bit 1', '0' => 'Bit 0', '2' => 'Bit 2'];
$c('encode bits', ['bits', 'Bit 0, Bit 2', $bl, null, null], ['bits', '(none)', $bl, null, null], ['bits', '[5]', $bl, null, null],
    ['bits', 'Bit 9', $bl, null, null], ['bits', 'Bit 0, Bit 9', $bl, null, null], ['bits', '0,3', null, null, null], ['bits', '40', null, null, null],
    ['bits', 'x', null, null, null], ['bits', '9, 1', null, 8, 16], ['bits', '17', null, 8, 16], ['bits', '', null, null, null]);
$c('check value', ['check', '5', 'int8u', null], ['check', '256', 'int8u', null], ['check', '-1', 'int8u', null], ['check', '0x10', 'int16u', null],
    ['check', '1.6', 'int16s', null], ['check', '-2.5', 'int16s', null], ['check', '1.5 2', 'int16u', 2], ['check', '1 2 3', 'int16u', 2], ['check', '1', 'int16u', 2],
    ['check', 'abc', 'string', 5], ['check', 'abcde', 'string', 5], ['check', 'abc', 'undef', 2], ['check', 'ab', 'undef', 4], ['check', '1.5', 'rational64u', null],
    ['check', '-1.5', 'rational64u', null], ['check', '1/3', 'rational64s', null], ['check', '-1/3', 'rational64u', null], ['check', 'inf', 'rational64s', null],
    ['check', 'x', 'float', null], ['check', '1,5', 'float', null], ['check', '1 2', 'int16u', -1], ['check', '', 'int16u', -1], ['check', '1', 'int8', null],
    ['check', '4294967296', 'int32u', null], ['check', '4277071597', 'int16u', null], ['check', '1 2', 'int16u', '$val{0}'], ['check', 'x', 'string', null]);

// global changes last (MWG composite tags, user-defined tags)
$c('mwg', ['require', 'Image::ExifTool::MWG'], ['set', 'MWG:Description', 'd'], ['set', 'MWG:Keywords', ['a', 'b']], ['set', 'MWG:DateTimeOriginal', '2020:01:02 03:04:05'],
    ['set', 'MWG:Creator', 'me'], ['get', 'XMP-dc:Description'], ['get', 'IPTC:Keywords']);
$c('user defined', ['udt', 'Image::ExifTool::Exif::Main', ['53248' => ['Name' => 'MyNewTag', 'Writable' => 'int16u']]], ['set', 'MyNewTag', '5'],
    ['get', 'MyNewTag'], ['writable', 'MyNewTag'], ['set', 'MyNewTag', 'x']);
$c('user defined struct', ['udt', 'Image::ExifTool::XMP::xmp', ['MyStruct' => ['List' => 'Bag', 'Struct' => [
        'STRUCT_NAME' => 'MyStruct', 'Field1' => new \stdClass(), 'Field2' => ['Writable' => 'integer'],
        'Sub' => ['Struct' => ['Deep' => new \stdClass()]]]]]],   // (stdClass: an empty hash in the JSON)
    ['set', 'MyStructField1', 'x'], ['get', 'MyStructField1'], ['set', 'XMP:MyStructField2', 'y'], ['set', 'MyStructField2', '3'],
    ['set', 'MyStructSubDeep', 'd'], ['writable', 'MyStructSubDeep'], ['nvlist']);

if ($images) {
    foreach (glob("$img/*") as $file) {
        if (is_file($file)) $c('image ' . basename($file), ['from', $file], ['count']);
    }
}
if ($filter !== null) {
    $cases = array_values(array_filter($cases, static fn ($c) => str_contains($c['name'], $filter)));
}

//------------------------------------------------------------------------------
// run both sides
@mkdir(__DIR__ . '/../var', 0777, true);
$casesFile = __DIR__ . '/../var/new-values-cases.json';
file_put_contents($casesFile, json_encode($cases));
// (perl -w like the exiftool script: the translated table code raises the warnings of -w)
$perl = run('PERL_HASH_SEED=0 PERL_PERTURB_KEYS=0 perl -w -I ' . escapeshellarg("$exiftool/lib") . ' ' .
    escapeshellarg(__DIR__ . '/new-values.pl') . ' < ' . escapeshellarg($casesFile), $perlErr);
$php = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --php < ' . escapeshellarg($casesFile), $phpErr);
$perlOut = json_decode($perl, true);
$phpOut = json_decode($php, true);
if (!is_array($perlOut) or !is_array($phpOut)) {
    echo "FAILED to run the cases\n--- perl:\n", substr($perl, 0, 2000), "\n", $perlErr, "\n--- php:\n", substr($php, 0, 2000), "\n", substr($phpErr, 0, 4000), "\n";
    exit(1);
}
// split the PHP warnings by case
$phpWarn = [];
foreach (explode("\x02", $phpErr) as $chunk) {
    if (preg_match('/^(\d+)\n/', $chunk, $m)) $phpWarn[(int) $m[1]] = substr($chunk, strlen($m[0]));
}

$same = $notPorted = $diff = 0;
$notPortedNames = $diffNames = $npReasons = [];
foreach ($cases as $i => $case) {
    $p = keyed($perlOut[$i] ?? null);
    $q = $phpOut[$i] ?? null;
    if ($q !== null) $q['stderr'] = esc($phpWarn[$i] ?? '');
    $q = keyed($q);
    if ($p === $q) {
        ++$same;
        continue;
    }
    // new values that differ because of Perl code not ported yet
    $reasons = [];
    foreach (['nv', 'save'] as $k) {
        foreach (array_unique([...array_keys($p[$k] ?? []), ...array_keys($q[$k] ?? [])]) as $key) {
            if (($p[$k][$key] ?? null) === ($q[$k][$key] ?? null)) continue;
            if (!preg_match('/^(.+):([^:]+):([^:#]*)#\d+@/', $key, $m)) continue;
            $why = notPortedReason($m[1], $m[2]);
            if ($why !== null) $reasons[$key] = $why;
        }
    }
    $out = strtolower(json_encode($q));
    if (str_contains($out, 'not ported') or str_contains($out, 'call to undefined')) $reasons['(output)'] = 'not ported';
    if ($reasons) {
        // compare what is left without them (results of set/from and counts depend on them)
        // (tags named by the not-ported errors of the PHP side)
        preg_match_all('/(?:not ported|Call to undefined)[^"]*? (?:in|for) (?:[-\w]+:)?([-\w]+)(?: \(\w+\))?"/i', json_encode($q), $mm);
        $p2 = residual($p, $reasons, $case['ops'], $mm[1]);
        $q2 = residual($q, $reasons, $case['ops'], $mm[1]);
        if ($p2 === $q2) {
            ++$notPorted;
            $notPortedNames[] = $case['name'];
            foreach ($reasons as $key => $why) $npReasons[preg_replace('/#\d+@.*/', '', $key)] = $why;
            if ($verbose) {
                echo "NOT PORTED: {$case['name']}\n";
                showDiff($p, $q, '  ');
            }
            continue;
        }
        echo 'DIFF (besides not ported ' . implode(', ', array_unique($reasons)) . "): {$case['name']}\n";
        showDiff($p2, $q2, '  ');
    } else {
        echo "DIFF: {$case['name']}\n";
        showDiff($p, $q, '  ');
    }
    ++$diff;
    $diffNames[] = $case['name'];
}
$total = count($cases);
$ops = array_sum(array_map(static fn ($c) => count($c['ops']), $cases));
echo "$total cases ($ops operations): $same identical, $notPorted differ only by not-ported code, $diff real differences\n";
if ($notPortedNames) echo 'not ported: ', implode(', ', $notPortedNames), "\n";
if ($npReasons and $verbose) {
    ksort($npReasons);
    foreach ($npReasons as $key => $why) echo "  $key: $why\n";
}
if ($diffNames) echo 'differences: ', implode(', ', $diffNames), "\n";
exit($diff ? 1 : 0);

//------------------------------------------------------------------------------
// helpers

/** normalize a case result: new value lists become maps keyed by Table:Tag:WriteGroup#Pos (and their order) */
function keyed($r)
{
    if (!is_array($r)) return $r;
    // (Perl removes the new values of a deleted group in hash order, the port in insertion order)
    if (isset($r['verbose'])) {
        $lines = explode('\x0a', $r['verbose']);
        for ($i = 0; $i < count($lines); ++$i) {
            for ($j = $i; $j < count($lines) and str_starts_with($lines[$j], 'Removed new value for '); ++$j);
            if ($j - $i > 1) {
                $run = array_slice($lines, $i, $j - $i);
                sort($run, SORT_STRING);
                array_splice($lines, $i, $j - $i, $run);
            }
            $i = max($i, $j);
        }
        $r['verbose'] = implode('\x0a', $lines);
    }
    foreach (['nv', 'save'] as $k) {
        $map = $seq = [];
        foreach ($r[$k] ?? [] as $d) {
            $key = "{$d['Table']}:{$d['Tag']}:" . ($d['WriteGroup'] ?? '') . "#{$d['Pos']}@{$d['ID']}";
            unset($d['Order'], $d['Head'], $d['Table'], $d['Tag'], $d['Pos'], $d['ID']);
            $map[$key] = $d;
            $seq[] = $key;
        }
        $r[$k] = $map;
        $r["{$k}Order"] = $seq;
    }
    return norm($r);
}

/** a case result without the new values affected by code not ported yet */
function residual(array $r, array $reasons, array $ops, array $tags = []): array
{
    // (names and family 0 groups of the tags concerned)
    $names = $groups = [];
    foreach ($tags as $t) $names[strtolower($t)] = 1;
    foreach (array_keys($reasons) as $key) {
        if (!preg_match('/^(.+):([^:]+):([^:#]*)#\d+@/', $key, $m)) continue;
        $names[strtolower($m[2])] = 1;
        $t = ExifTool::GetTagTable($m[1]);
        if ($t) $groups[$t['GROUPS'][0] ?? ''] = 1;
    }
    foreach (['nv', 'save'] as $k) {
        foreach (array_keys($reasons) as $key) unset($r[$k][$key]);
        $r["{$k}Order"] = array_values(array_filter($r["{$k}Order"], static fn ($key) => !isset($reasons[$key])));
        // (the groups where tags are created are shared by all the tags set at once)
        foreach ($r[$k] as &$d) {
            if (isset($d['CreateGroups'])) $d['CreateGroups'] = array_values(array_filter($d['CreateGroups'], static fn ($g) => !isset($groups[$g])));
        }
        unset($d);
    }
    unset($r['count']);
    $np = static fn ($w) => !preg_match('/not ported|call to undefined/i', Perl::str($w));
    $r['warn'] = array_filter($r['warn'] ?? [], $np);
    $r['stderr'] = implode("\n", array_filter(explode('\x0a', $r['stderr'] ?? ''), $np));
    foreach ($ops as $n => $op) {
        if (in_array($op[0], ['set', 'sset', 'from', 'fromet', 'count'], true)) {
            unset($r['res'][$n]);
        } elseif (in_array($op[0], ['get', 'over', 'shift'], true) and isset($names[strtolower(preg_replace('/.*:/', '', $op[1]))])) {
            unset($r['res'][$n]);
        } elseif ($op[0] === 'nvlist' and is_array($r['res'][$n] ?? null)) {
            $r['res'][$n] = array_values(array_filter($r['res'][$n], static fn ($t) => !isset($names[strtolower($t)])));
        }
    }
    return $r;
}

/** why the tag of a table is not handled like Perl yet (Perl code not ported), or null */
function notPortedReason(string $table, string $tag): ?string
{
    $t = ExifTool::GetTagTable($table);
    if (!$t) return null;
    $infos = [];
    // (flattened XMP tags are generated on demand)
    if (($t['GROUPS'][0] ?? null) === 'XMP' or $t['NAMESPACE']) \Image\ExifTool\XMP::AddFlattenedTags($t);
    foreach ([$tag, preg_replace('/-.*/', '', $tag)] as $name) {
        foreach (ExifTool::TagTableKeys($t) as $id) {
            foreach (ExifTool::GetTagInfoList($t, $id) as $ti) {
                if ($ti->Name === $name) $infos[] = $ti;
            }
        }
        if ($infos) break;
    }
    // a closure not translated yet, a named sub not ported yet, or a closure calling one
    $isStub = static function ($c): bool {
        if (is_string($c)) return !is_callable($c);
        if (!$c instanceof \Closure) return false;
        $r = new \ReflectionFunction($c);
        if ($r->isVariadic() and $r->getNumberOfParameters() === 1 and $r->getParameters()[0]->getName() === 'args') return true;
        $lines = file($r->getFileName());
        $src = implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
        preg_match_all('/(?<![\\\\\w$>])(?:\\\\?Image\\\\ExifTool\\\\)?(\w+)::(\w+)\s*\(/', $src, $m, PREG_SET_ORDER);
        foreach ($m as [, $class, $method]) {
            if (!method_exists("Image\\ExifTool\\$class", $method)) return true;
        }
        preg_match_all('/\$(?:self|et)->(\w+)\s*\(/', $src, $m);
        foreach ($m[1] as $method) {
            if (!method_exists(ExifTool::class, $method)) return true;
        }
        return false;
    };
    foreach ($infos as $ti) {
        $check = $t['CHECK_PROC'];
        if ($check and !is_callable($check)) return 'CHECK_PROC ' . $check;
        if ($ti->Struct) return 'Struct';
        // (the MakerNotes block read with the MakerNotes option is rebuilt by WriteExif.pl)
        if ($ti->MakerNotes) return 'RebuildMakerNotes';
        foreach (['PrintConvInv', 'ValueConvInv', 'RawConvInv', 'WriteCheck', 'DelCheck', 'PrintConv', 'ValueConv', 'RawConv'] as $k) {
            $v = $ti->$k;
            $list = $v instanceof \Image\ExifTool\ConvList ? $v->list : [$v];
            foreach ($list as $x) {
                if ($isStub($x) or (is_array($x) and $isStub($x['OTHER'] ?? null))) return $k;
            }
        }
        foreach ($ti->WriteAlso ?: [] as $w) {
            if ($isStub($w)) return 'WriteAlso';
        }
    }
    return null;
}

function run(string $cmd, &$stderr): string
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
}

/** escape the bytes of a string so the JSON stays plain ASCII (same as tests/new-values.pl) */
function esc(string $s): string
{
    return preg_replace_callback('/[\\\\\x00-\x1f\x7f-\xff]/', static fn ($m) => sprintf('\\x%02x', ord($m[0])), $s);
}

/** serialize a value (same as tests/new-values.pl) */
function ser($v)
{
    if ($v === null) return null;
    if ($v instanceof ScalarRef) return ['SCALAR' => ser($v->value)];
    if ($v instanceof \ArrayObject) $v = $v->getArrayCopy();
    if (is_array($v)) {
        if (array_is_list($v)) return array_map('ser', $v);
        $o = [];
        foreach ($v as $k => $x) $o[esc((string) $k)] = ser($x);
        return $o;
    }
    if (is_object($v)) return 'REF(' . get_class($v) . ')';
    $s = Perl::str($v);
    return strlen($s) > 300 ? 'LONG:' . strlen($s) . ':' . md5($s) : esc($s);
}

/** decode a value of the cases ({__hex} = bytes, {__sref} = scalar reference) */
function val($v)
{
    if (is_array($v)) {
        if (array_key_exists('__hex', $v)) return hex2bin($v['__hex']);
        if (array_key_exists('__sref', $v)) return new ScalarRef(val($v['__sref']));
        return array_map('val', $v);
    }
    return $v;
}

/** sort the keys of the hashes (JSON objects) for comparison */
function norm($v)
{
    if (!is_array($v)) return $v;
    $v = array_map('norm', $v);
    if (!array_is_list($v)) ksort($v, SORT_STRING);
    return $v;
}

function showDiff($a, $b, string $path): void
{
    static $shown = 0;
    if ($a === $b) return;
    if (is_array($a) and is_array($b) and (!array_is_list($a) or !array_is_list($b) or count($a) === count($b))) {
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
            showDiff($a[$k] ?? null, $b[$k] ?? null, "$path$k.");
        }
        return;
    }
    if (++$shown > 400) return;
    if (is_array($a) and is_array($b) and array_is_list($a) and array_is_list($b)) {
        // lists of different lengths: show where they start to differ
        for ($i = 0; ($a[$i] ?? null) === ($b[$i] ?? null) and $i < count($a); ++$i);
        echo "    $path (" . count($a) . ' vs ' . count($b) . " items, first difference at $i)\n      perl: ",
            json_encode(array_slice($a, $i, 3), JSON_UNESCAPED_SLASHES), "\n      php:  ", json_encode(array_slice($b, $i, 3), JSON_UNESCAPED_SLASHES), "\n";
        return;
    }
    echo "    $path\n      perl: ", json_encode($a, JSON_UNESCAPED_SLASHES), "\n      php:  ", json_encode($b, JSON_UNESCAPED_SLASHES), "\n";
}

/** dump the new value hashes of a NEW_VALUE or SAVE_NEW_VALUE hash (same as tests/new-values.pl) */
function dumpNV($hash): array
{
    $nv = [];
    foreach ($hash ?: [] as $head) {
        $pos = 0;
        for ($nvHash = $head; $nvHash; $nvHash = $nvHash->Next) {
            $ti = $nvHash->TagInfo;
            $d = ['Tag' => $ti->Name, 'ID' => esc(Perl::str($ti->TagID) . ($ti->Index !== null ? '.' . Perl::str($ti->Index) : '')), 'Table' => $ti->Table['TABLE_NAME'], 'Pos' => $pos++, 'Head' => $head->Order];
            foreach (['WriteGroup', 'WantGroup', 'IsCreating', 'CreateOnly', 'EditOnly', 'NoReplace', 'AddBefore', 'Shift', 'Save', 'Order'] as $k) {
                if ($nvHash->$k !== null) $d[$k] = ser($nvHash->$k);
            }
            if ($nvHash->Value !== null) $d['Value'] = ser($nvHash->Value);
            if ($nvHash->DelValue !== null) $d['DelValue'] = ser($nvHash->DelValue);
            if ($nvHash->CreateGroups) {
                $g = array_map('strval', array_keys($nvHash->CreateGroups->getArrayCopy()));
                sort($g, SORT_STRING);
                $d['CreateGroups'] = $g;
            }
            $nv[] = $d;
        }
    }
    usort($nv, static fn ($a, $b) => [(int) $a['Order'], $a['Tag']] <=> [(int) $b['Order'], $b['Tag']]);
    return $nv;
}

/** the PHP side, run in a child process: cases on stdin, results on stdout, warnings on stderr */
function runPhp(): void
{
    ini_set('display_errors', 'stderr');
    set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
        if (!(error_reporting() & $no)) return false;   // (suppressed with @)
        fwrite(STDERR, "PHP: $str (" . basename($file) . ":$line)\n");
        return true;
    });
    $cases = json_decode(stream_get_contents(STDIN), true);
    $out = [];
    foreach ($cases as $i => $case) {
        fwrite(STDERR, "\x02$i\n");
        $et = new ExifTool();
        $vfh = fopen('php://memory', 'w+');
        $et->Options('TextOut', $vfh);
        $res = [];
        foreach ($case['ops'] as $op) {
            try {
                $r = runOp($et, $op);
            } catch (\Throwable $e) {
                $r = 'EXCEPTION ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            $res[] = ser($r);
        }
        $warn = [];
        foreach ($et->VALUE as $k => $v) {
            if (str_starts_with((string) $k, 'Warning')) $warn[esc((string) $k)] = ser($v);
        }
        rewind($vfh);
        $del = [];
        foreach ($et->DEL_GROUP ?: [] as $k => $v) $del[esc((string) $k)] = $v;
        $out[] = [
            'res' => $res,
            'nv' => dumpNV($et->NEW_VALUE),
            'save' => dumpNV($et->SAVE_NEW_VALUE),
            'del' => $del,
            'count' => $et->CountNewValuesList(),
            'warn' => $warn,
            'stderr' => '',
            'verbose' => esc(stream_get_contents($vfh)),
        ];
    }
    echo json_encode($out);
}

function runOp(ExifTool $et, array $op)
{
    $a = $op;
    $what = array_shift($a);
    switch ($what) {
        case 'set':
            return $et->SetNewValueList($a[0], val($a[1]), ...val($a[2] ?? []));
        case 'sset':
            return $et->SetNewValue($a[0], val($a[1]), ...val($a[2] ?? []));
        case 'opt':
            $et->Options(...val($a));
            return 1;
        case 'get':
            $nvHash = null;
            $s = $et->GetNewValue($a[0]);
            $l = $et->GetNewValueList($a[0], $nvHash);
            return [$s, $l, $nvHash ? $nvHash->WriteGroup : null];
        case 'count':
            return $et->CountNewValuesList();
        case 'save':
            return $et->SaveNewValues();
        case 'restore':
            $et->RestoreNewValues();
            return 1;
        case 'from':
            return $et->SetNewValuesFromFile(...val($a));
        case 'over':
            $nvHash = null;
            $et->GetNewValue($a[0], $nvHash);
            $o = $et->IsOverwriting($nvHash, val($a[1]));
            return [$o, $et->GetNewValueList($nvHash)];
        case 'writable':
            return ExifTool::IsWritable($a[0]);
        case 'geo':
            return $et->GetGeolocateTags($a[0], $a[1]);
        case 'idt':
            return $et->InverseDateTime($a[0], $a[1], $a[2]);
        case 'rev':
            return ExifTool::ReverseLookupList($a[0], val($a[1]));
        case 'bits':
            return ExifTool::EncodeBitsList($a[0], val($a[1]), $a[2], $a[3]);
        case 'check':
            $val = val($a[0]);
            $err = ExifTool::CheckValue($val, $a[1], $a[2]);
            return [$err, $val];
        case 'alt':
            return $et->SetAlternateFile($a[0], $a[1]);
        case 'groups':
            if ($a) $et->SetNewGroups(...$a);
            return $et->GetNewGroups();
        case 'nvlist':
            $l = array_map(static fn ($t) => $t->Name, $et->GetNewTagInfoList());
            sort($l, SORT_STRING);
            return $l;
        case 'shift':
            $nvHash = null;
            $et->GetNewValue($a[0], $nvHash);
            $err = $et->ApplyShift($a[1], $a[2], $a[3], $nvHash);
            return [$err, $et->GetNewValueList($nvHash)];
        case 'fromet':  // (from an ExifTool object which read the file)
            $src = new ExifTool();
            $src->ImageInfo($a[0]);
            return $et->SetNewValuesFromFile($src, ...val(array_slice($a, 1)));
        case 'require':
            ExifTool::RequireModule($a[0]);
            return 1;
        case 'udt':
            return ExifTool::AddUserDefinedTags($a[0], val($a[1]));
    }
    throw new \LogicException("unknown op $what");
}
