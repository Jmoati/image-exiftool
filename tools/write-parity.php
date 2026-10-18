<?php

/*
 * Write parity: makes the same changes with Perl ExifTool and with the PHP port, then compares
 * what they wrote: the files (byte for byte, then their tags with `exiftool -j -a -u -G1` when
 * the bytes differ), their names, permissions and (for FileModifyDate cases) modification
 * times, and the result of WriteInfo with its warnings and errors.
 *
 *   php tools/write-parity.php [--case=TEXT] [--cli] [--keep] [--exiftool=DIR] [-v]
 *   php tools/write-parity.php --sweep='ARGS' --ext=jpg,tif [...]
 *
 *   --case=TEXT     only the cases whose source or arguments contain TEXT
 *   --sweep=ARGS    instead of the cases below, apply these exiftool arguments (separated by
 *                   spaces, quotes allowed) to every file of t/images with an extension of --ext,
 *                   writing out.<ext> (eg. --sweep='-Comment=x' --ext=jpg)
 *   --cli           run the real command-line tools (perl exiftool / php bin/exiftool) instead
 *                   of the API runners (for when bin/exiftool parses the write arguments)
 *   --keep          keep the work directories (var/write-parity/) of the identical cases too
 *   --exiftool=DIR  Perl ExifTool (default ~/exiftool)
 *   -v              print the output of the runners
 *
 * A case is a source file of ~/exiftool/t/images (or the absolute path of an input generated in
 * var/write-parity-inputs) and a list of exiftool arguments (see $cases
 * below: add cases there, in the section of their format). For each tool the source is copied
 * into an empty work directory (var/write-parity/<case>/perl or /php) and the tool runs there
 * with the arguments followed by the name of the copy: `-o NAME` writes a new file, no -o
 * edits the copy in place (FileName, Directory... then rename or move it).
 *
 * The API runners (Runner() below and its Perl twin tools/write-parity.pl) understand the
 * exiftool arguments -TAG[+-^]=[VALUE], -TAG<=DATAFILE, -o FILE, -m, -n, -v[NUM], -api OPT[=VAL],
 * -use MWG and -overwrite_original, and set the new values like the exiftool script does. They also take
 * -_memin, -_memout, -_handle, -_handleout and -_raf, to pass the files to WriteInfo as data in
 * memory, as file handles or as a RandomAccess object (API-only cases, skipped with --cli). A case
 * with a null source creates its output from scratch.
 * Each case prints "ok" (identical), "MSG" (same files, but the output of the runners differs:
 * results of SetNewValue/WriteInfo, warnings, errors) or "DIFF" (the files differ).
 * Exit status: 0 when every case gives the same result.
 */

$root = dirname(__DIR__);

// PHP runner, run in the work directory: php tools/write-parity.php --run ARGS... FILE
if (($argv[1] ?? null) === '--run') {
    require $root.'/vendor/autoload.php';
    exit(Runner(array_slice($argv, 2)));
}

$opt = getopt('v', ['case:', 'cli', 'keep', 'exiftool:', 'sweep:', 'ext:'], $rest);
$etDir = rtrim($opt['exiftool'] ?? getenv('HOME').'/exiftool', '/');
$images = "$etDir/t/images";
// large data written as a block (more than one JPEG segment)
$inputs = "$root/var/write-parity-inputs";
@mkdir($inputs, 0777, true);
file_put_contents("$inputs/big.exif", file_get_contents("$images/ExifTool.tif").str_repeat("\0MM\0\x2a", 30000));
file_put_contents("$inputs/big.icc", file_get_contents("$images/ICC_Profile.icc").str_repeat("\0", 150000));
file_put_contents("$inputs/big.xmp", str_replace('</x:xmpmeta>', str_repeat(' ', 70000).'</x:xmpmeta>',
    file_get_contents("$images/XMP.xmp")));
// a JPEG with an APP12 Ducky segment (none in t/images): Quality, Comment and Copyright blocks
$utf16 = static fn (string $s) => pack('N', strlen($s)).implode('', array_map(static fn ($c) => "\0$c", str_split($s)));
$ducky = 'Ducky'.pack('nnN', 1, 4, 75).pack('nn', 2, 30).$utf16('Ducky comment').pack('nn', 3, 20).$utf16('(c) Phil')."\0\0";
file_put_contents("$inputs/ducky.jpg", "\xff\xd8\xff\xec".pack('n', strlen($ducky) + 2).$ducky.substr(file_get_contents("$images/Writer.jpg"), 2));

// cases: [source file in ~/exiftool/t/images, [exiftool arguments]]
$cases = [
    // JPEG: COM segment (WriteJPEG)
    ['Canon.jpg', ['-Comment=New comment in JPG file', '-o', 'out.jpg']],
    ['Writer.jpg', ['-Comment=New comment', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-Comment=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-Comment=Replaced comment', '-o', 'out.jpg']],
    ['GPS.jpg', ['-Comment=a much longer comment '.str_repeat('0123456789', 50), '-o', 'out.jpg']],
    // JPEG: deleting groups and segments
    ['ExifTool.jpg', ['-all=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-all=', '-o', 'out.jpg']],
    ['Nikon.jpg', ['-all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-trailer:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-APP6:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-JFIF:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-Photoshop:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-ICC_Profile:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-XMP:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-EXIF:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-File:all=', '-o', 'out.jpg']],
    ['Ricoh.jpg', ['-MPF:all=', '-o', 'out.jpg']],
    // JPEG: trailers
    ['FotoStation.jpg', ['-Comment=x', '-o', 'out.jpg']],
    ['PhotoMechanic.jpg', ['-Comment=x', '-o', 'out.jpg']],
    ['FotoStation.jpg', ['-FotoStation:all=', '-o', 'out.jpg']],
    ['FotoStation.jpg', ['-FotoStation:Rotation=0', '-o', 'out.jpg']],
    ['FotoStation.jpg', ['-v3', '-FotoStation:CropLeft=10%', '-FotoStation:CropRotation=12', '-o', 'out.jpg']],
    ['FotoStation.jpg', ['-n', '-FotoStation:CropLeft=0.25', '-FotoStation:Rotation=270', '-o', 'out.jpg']],
    ['PhotoMechanic.jpg', ['-PhotoMechanic:all=', '-o', 'out.jpg']],
    ['ExifTool.jpg', ['-Trailer=', '-o', 'out.jpg']],
    ['Canon.jpg', ["-Trailer<=$images/ICC_Profile.icc", '-o', 'out.jpg']],
    ['FotoStation.jpg', ['-v2', "-Trailer<=$images/ICC_Profile.icc", '-o', 'out.jpg']],
    ['Canon.jpg', ["-CanonVRD<=$images/CanonVRD.vrd", '-o', 'out.jpg']],
    ['PhotoMechanic.jpg', ['-v2', "-CanonVRD<=$images/CanonVRD.vrd", '-o', 'out.jpg']],
    ['AFCP.jpg', ['-Comment=x', '-o', 'out.jpg']],
    ['AFCP.jpg', ['-AFCP:all=', '-o', 'out.jpg']],
    ['AFCP.jpg', ['-v3', '-Comment=x', '-o', 'out.jpg']],
    // file system tags (in place: no -o)
    ['Writer.jpg', ['-FileName=renamed.jpg']],
    ['Writer.jpg', ['-Directory=sub/dir']],
    ['Writer.jpg', ['-FileName=sub/renamed.jpg']],
    ['Writer.jpg', ['-FileName=writer.JPG']],
    ['Writer.jpg', ['-FileName=Writer.jpg']],
    ['Writer.jpg', ['-FileName=.']],
    ['Writer.jpg', ['-FileName=']],
    ['Writer.jpg', ['-HardLink=link.jpg']],
    ['Writer.jpg', ['-SymLink=sym.jpg']],
    ['Writer.jpg', ['-TestName=sub/test.jpg']],
    ['Writer.jpg', ['-Comment=x', '-HardLink=link.jpg']],
    ['Writer.jpg', ['-FileModifyDate=2001:02:03 04:05:06']],
    ['Writer.jpg', ['-FileModifyDate+=1:2:3 4:05:06']],
    ['Writer.jpg', ['-FileModifyDate-=0:0:1 0:00:00', '-Comment=shifted']],
    ['Writer.jpg', ['-FileModifyDate+=10', '-Comment=x', '-o', 'out.jpg']],
    ['Writer.jpg', ['-FilePermissions=0600']],
    ['Writer.jpg', ['-Comment=in place']],
    ['Writer.jpg', ['-Comment=new name', '-FileName=other.jpg']],
    ['Writer.jpg', ['-Comment=x', '-FileModifyDate=2002:03:04 05:06:07', '-o', 'out.jpg']],
    // JPEG: segments written by the ported writers (JFIF: WriteBinaryData, Adobe: block)
    ['Canon.jpg', ['-JFIF:XResolution=300', '-o', 'out.jpg']],
    ['Pentax.jpg', ['-JFIF:YResolution=123', '-o', 'out.jpg']],
    ['Pentax.jpg', ['-JFIF:all=', '-JFIF:XResolution=72', '-o', 'out.jpg']],
    ['Canon.jpg', ['-Adobe=', '-o', 'out.jpg']],
    // JPEG: segments written as a block (-TAG<=FILE)
    ['Canon.jpg', ["-ICC_Profile<=$images/ICC_Profile.icc", '-o', 'out.jpg']],
    ['XMP.jpg', ["-ICC_Profile<=$images/ICC_Profile.icc", '-o', 'out.jpg']],
    ['XMP.jpg', ['-v3', "-ICC_Profile<=$images/ICC_Profile.icc", '-o', 'out.jpg']],
    ['Writer.jpg', ["-XMP<=$images/XMP.xmp", '-o', 'out.jpg']],
    ['XMP.jpg', ["-XMP<=$images/XMP2.xmp", '-o', 'out.jpg']],
    ['Writer.jpg', ["-EXIF<=$images/ExifTool.tif", '-o', 'out.jpg']],
    ['Canon.jpg', ["-EXIF<=$images/ExifTool.tif", '-o', 'out.jpg']],
    ['Canon.jpg', ['-EXIF=', '-o', 'out.jpg']],
    ['Writer.jpg', ["-EXIF<=$inputs/big.exif", '-o', 'out.jpg']],
    ['Writer.jpg', ['-api', 'NoMultiExif', "-EXIF<=$inputs/big.exif", '-o', 'out.jpg']],
    ['Writer.jpg', ["-ICC_Profile<=$inputs/big.icc", '-o', 'out.jpg']],
    ['Writer.jpg', ["-XMP<=$inputs/big.xmp", '-o', 'out.jpg']],
    ['Writer.jpg', ['-Comment='.str_repeat('0123456789', 7000), '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP=', '-ICC_Profile=', '-o', 'out.jpg']],
    // JPEG: nothing changed (WriteInfo returns 2)
    ['Writer.jpg', ['-Comment=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-XMP:all=', '-o', 'out.jpg']],
    // verbose output
    ['Canon.jpg', ['-v3', '-Comment=verbose', '-o', 'out.jpg']],
    ['Nikon.jpg', ['-v2', '-all=', '-o', 'out.jpg']],
    ['Writer.jpg', ['-v2', '-FileName=renamed.jpg', '-Comment=x']],
    ['Pentax.jpg', ['-v2', '-JFIF:XResolution=96', '-o', 'out.jpg']],
    // EXV and EXIF files
    [null, ['-Comment=new EXV', '-o', 'out.exv']],
    [null, ["-EXIF<=$images/ExifTool.tif", '-o', 'out.exif']],
    [null, ["-EXIF<=$images/ExifTool.tif", '-Comment=x', '-o', 'out.exv']],
    // files in memory, file handles, RandomAccess (API only)
    ['Canon.jpg', ['-_memin', '-_memout', '-Comment=in memory', '-o', 'out.jpg']],
    ['Canon.jpg', ['-_memin', '-Comment=memory in place']],
    ['Canon.jpg', ['-_memout', '-Comment=memory out', '-o', 'out.jpg']],
    ['Writer.jpg', ['-_handle', '-Comment=handle in place']],
    ['Canon.jpg', ['-_handle', '-Comment=']],
    ['Canon.jpg', ['-_raf', '-Comment=from RAF', '-o', 'out.jpg']],
    ['Canon.jpg', ['-_raf', '-Comment=RAF in place']],
    ['Canon.jpg', ['-_handleout', '-Comment=to a handle', '-o', 'out.jpg']],
    ['Canon.jpg', ['-_handle', '-_handleout', '-Comment=handles', '-o', 'out.jpg']],
    // errors
    ['Writer.jpg', ['-Comment=x', '-o', 'Writer.jpg']],
    ['Writer.jpg', ['-Comment=x', '-o', 'nodir/out.jpg']],
    ['BMP.bmp', ['-Comment=x', '-o', 'out.bmp']],
    [null, ['-Comment=x', '-o', 'out.jpg']],
    [null, ['-Comment=x', '-o', 'out.xyz']],
    ['Unknown.jpg', ['-Comment=x', '-o', 'out.jpg']],
    ['Writer.jpg', ['-Comment=x', '-o', 'out.tif']],
    ['PNG.png', ['-Comment=x', '-o', 'out.png']],
    // IPTC (WriteIPTC)
    ['Writer.jpg', ['-IPTC:Keywords=one', '-IPTC:Keywords=two', '-IPTC:By-line=Phil', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:Keywords+=added', '-IPTC:Headline=New headline', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:Keywords-=Test', '-IPTC:Keywords+=One', '-IPTC:Keywords+=Two', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:Keywords=', '-IPTC:Caption-Abstract=', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:all=', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:all=', '-IPTC:Keywords=only', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-v2', ...array_map(static fn ($t) => "-IPTC:$t=", ['Caption-Abstract', 'Writer-Editor', 'Headline',
        'SpecialInstructions', 'By-line', 'By-lineTitle', 'Credit', 'ObjectName', 'DateCreated', 'City', 'Province-State',
        'Country-PrimaryLocationName', 'OriginalTransmissionReference', 'Category', 'SupplementalCategories', 'Keywords',
        'CopyrightNotice', 'Urgency', 'Source']), '-o', 'out.jpg']],     // (deleting all but mandatory tags)
    ['IPTC.jpg', ['-IPTC:ApplicationRecordVersion=', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-v3', '-IPTC:CodedCharacterSet=UTF8', "-IPTC:City=Z\xc3\xbcrich", '-o', 'out.jpg']],
    ['Writer.jpg', ['-IPTC:CodedCharacterSet=ESC % G', "-IPTC:Credit=\xc2\xa9 me", '-o', 'out.jpg']],
    ['Writer.jpg', ['-IPTC:CodedCharacterSet=bad', '-IPTC:Credit=x', '-o', 'out.jpg']],
    ['Writer.jpg', ['-api', 'CharsetIPTC=Cyrillic', "-IPTC:City=\xd0\x9c\xd0\xbe\xd1\x81\xd0\xba\xd0\xb2\xd0\xb0", '-o', 'out.jpg']],
    ['Writer.jpg', ['-IPTC:Headline='.str_repeat('x', 300), '-o', 'out.jpg']],
    ['Writer.jpg', ['-m', '-IPTC:Headline='.str_repeat('x', 300), '-o', 'out.jpg']],
    ['Writer.jpg', ['-IPTC:DateCreated=2024:01:02', '-IPTC:TimeCreated=12:34:56+02:00', '-IPTC:DigitalCreationTime=10:20:30', '-o', 'out.jpg']],
    ['Writer.jpg', ['-IPTC:Urgency=3', '-IPTC:ObjectPreviewFileFormat=1', '-IPTC:ProgramVersion=1.0', '-o', 'out.jpg']],
    ['Writer.jpg', ["-IPTC:ObjectPreviewData<=$inputs/big.exif", '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:Keywords=x', '-Photoshop:IPTCDigest=new', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-IPTC:Keywords=x', '-Photoshop:IPTCDigest-=old', '-Photoshop:IPTCDigest=new', '-o', 'out.jpg']],
    ['GPS.jpg', ['-IPTC:Keywords+=more', '-o', 'out.jpg']],
    ['AFCP.jpg', ['-IPTC:Keywords+=afcp', '-o', 'out.jpg']],
    ['FotoStation.jpg', ['-IPTC:Keywords=fs', '-IPTC:Caption-Abstract=', '-o', 'out.jpg']],
    ['PhotoMechanic.jpg', ['-IPTC:Keywords+=x', '-o', 'out.jpg']],
    ['PhotoMechanic.jpg', ['-PhotoMechanic:Rotation=90', '-PhotoMechanic:ColorClass=3', '-o', 'out.jpg']],
    ['ExifTool.tif', ['-IPTC:Keywords=tiff', '-o', 'out.tif']],
    // Photoshop resources (WritePhotoshop)
    ['IPTC.jpg', ['-Photoshop:XResolution=300', '-Photoshop:DisplayedUnitsY=cm', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-Photoshop:CopyrightFlag=True', '-Photoshop:URL=https://exiftool.org/', '-o', 'out.jpg']],
    ['Writer.jpg', ['-Photoshop:CopyrightFlag=True', '-Photoshop:XResolution=72', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-Photoshop:IPTCDigest=new', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-Photoshop:IPTCDigest=', '-o', 'out.jpg']],
    ['IPTC.jpg', ['-v3', '-Photoshop:PhotoshopQuality=10', '-o', 'out.jpg']],
    ['IPTC.jpg', ["-Photoshop:PhotoshopThumbnail<=$images/Writer.jpg", '-o', 'out.jpg']],
    // PSD (ProcessPSD)
    ['Photoshop.psd', ['-Photoshop:XResolution=150', '-o', 'out.psd']],
    ['Photoshop.psd', ['-Photoshop:CopyrightFlag=True', '-IPTC:Keywords+=psd', '-o', 'out.psd']],
    ['Photoshop.psd', ['-IPTC:all=', '-o', 'out.psd']],
    ['Photoshop.psd', ['-Photoshop:all=', '-o', 'out.psd']],
    ['Photoshop.psd', ['-v3', '-IPTC:By-line=Phil', '-o', 'out.psd']],
    // Ducky (WriteDucky)
    ['Writer.jpg', ['-Ducky:Quality=80', '-Ducky:Comment=new comment', '-o', 'out.jpg']],
    ["$inputs/ducky.jpg", ['-Ducky:Quality=90', '-o', 'out.jpg']],
    ["$inputs/ducky.jpg", ['-Ducky:Comment=', '-Ducky:Copyright=(c) Someone', '-o', 'out.jpg']],
    ["$inputs/ducky.jpg", ['-Ducky:all=', '-o', 'out.jpg']],
    ["$inputs/ducky.jpg", ['-v3', '-Ducky:Quality=10', '-o', 'out.jpg']],
    // ICC_Profile files (WriteICC)
    ['ICC_Profile.icc', ["-ICC_Profile<=$images/ICC_Profile.icc", '-o', 'out.icc']],
    [null, ["-ICC_Profile<=$images/ICC_Profile.icc", '-o', 'out.icc']],
    ['ICC_Profile.icc', ['-ICC_Profile=', '-o', 'out.icc']],
    ['ICC_Profile.icc', ['-ProfileDescription=x', '-o', 'out.icc']],
    ['Writer.jpg', ["-ICC_Profile<=$images/ICC_Profile.icc", '-o', 'out.icc']],
    // MWG composite tags (also need the EXIF and XMP writers)
    ['IPTC.jpg', ['-use', 'MWG', '-MWG:Keywords+=mwg', '-o', 'out.jpg']],
    ['MWG.jpg', ['-MWG:Creator=Creator One', '-MWG:City=Some city', '-o', 'out.jpg']],
    ['Writer.jpg', ['-MWG:DateTimeOriginal=2009:10:25 15:13:44.567-04:00', '-o', 'out.jpg']],
    // XMP in JPEG (WriteXMP)
    ['Writer.jpg', ['-XMP:Title=New title', '-XMP:Subject=one', '-XMP:Subject=two', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP:Title=Changed', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-dc:Subject+=added', '-XMP-dc:Subject-=Test', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP:Rating=5', '-XMP:Label=Red', '-XMP:CreateDate=2024:01:02 03:04:05+01:00', '-o', 'out.jpg']],
    ['XMP.jpg', ['-v3', '-XMP:Title=verbose', '-XMP-dc:Subject=', '-o', 'out.jpg']],
    ['XMP.jpg', ['-api', 'Compact=Shorthand', '-XMP:Title=short', '-o', 'out.jpg']],
    ['XMP.jpg', ['-api', 'Compact=NoPadding,NoIndent,NoNewline', '-XMP:Title=compact', '-o', 'out.jpg']],
    ['XMP.jpg', ['-api', 'Compact=OneDesc', '-XMP-exif:ISO=100', '-XMP:Title=one desc', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-x:XMPToolkit=my toolkit', '-XMP-rdf:About=uuid:123', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-x:XMPToolkit=', '-XMP:Title=no toolkit', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-photoshop:all=', '-o', 'out.jpg']],
    ['XMP.jpg', ['-v2', '-XMP-xmpRights:all=', '-XMP-xmpBJ:all=', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP:Title=', '-XMP:Description=', '-XMP:Rights=', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-photoshop:DateCreated+=1:0:0', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP:Title-=nosuchvalue', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-xmpRights:WebStatement=not a valid URI', '-XMP-cc:License=https://creativecommons.org/', '-o', 'out.jpg']],
    ['Writer.jpg', ['-XMP-exif:FNumber=2.8', '-XMP-exif:ISO=100', '-XMP-tiff:Orientation=Rotate 90 CW', '-XMP-xmpRights:Marked=True',
        '-XMP-exif:DateTimeOriginal=2020:02:03 04:05:06', '-XMP-exif:ExposureTime=1/125', '-o', 'out.jpg']],
    ['Writer.jpg', ['-api', 'Charset=Latin', "-XMP:Title=\xe9t\xe9", '-o', 'out.jpg']],
    ['Writer.jpg', ["-XMP:Title=\x01bad\xff utf8", '-o', 'out.jpg']],
    ['PhotoMechanic.jpg', ['-XMP:Title=pm', '-XMP-photomech:ColorClass=2', '-o', 'out.jpg']],
    ['ExtendedXMP.jpg', ['-XMP:Title=ext', '-o', 'out.jpg']],
    ['ExtendedXMP.jpg', ['-XMP-xmpNote:all=', '-XMP:Creator=x', '-o', 'out.jpg']],
    ['Writer.jpg', ['-XMP-photoshop:History='.str_repeat('history ', 9000), '-XMP:Title=big', '-o', 'out.jpg']],
    ['Writer.jpg', ['-XMP-xmp:Thumbnails={Format=JPEG,Image='.str_repeat('A', 70000).'}', '-XMP:Title=x', '-o', 'out.jpg']],
    ['Writer.jpg', ['-api', 'Compact=Shorthand', '-XMP-photoshop:History='.str_repeat('h ', 40000), '-o', 'out.jpg']],
    ['XMP.jpg', ['-ForceWrite=XMP', '-o', 'out.jpg']],
    // XMP sidecar files (WriteXMP without a tag table)
    [null, ['-XMP:Title=sidecar', '-XMP:Creator=Phil', '-o', 'out.xmp']],
    [null, ['-XMP-x:XMPToolkit=', '-XMP:Title=no toolkit', '-o', 'out.xmp']],
    ['XMP.xmp', ['-XMP:Title=edited', '-o', 'out.xmp']],
    ['XMP.xmp', ['-XMP:Title=in place']],
    ['XMP.xmp', ['-XMP-exif:all=', '-XMP-tiff:all=', '-o', 'out.xmp']],
    ['XMP.xmp', ['-XMP:Label=Blue', '-XMP:Rating=3', '-XMP:Subject+=char test: & > < \' "', '-o', 'out.xmp']],
    ['XMP2.xmp', ['-XMP:Creator+=Phil', '-XMP:manifestplacedXResolution=1', '-o', 'out.xmp']],
    ['XMP3.xmp', ['-XMP:attributionname=something else', '-o', 'out.xmp']],
    ['XMP4.xmp', ['-XMP:Title=x', '-o', 'out.xmp']],
    ['XMP6.xmp', ['-XMP-dc:Subject=changed', '-o', 'out.xmp']],
    ['XMP.xml', ['-XMP:all=', '-XMP-XML-File:all=', '-XMP:Author=Phil', '-o', 'out.xml']],
    ['XMP.xml', ['-XML-File:all=', '-o', 'out.xml']],
    ['XMP.xmp', ["-XMP<=$images/XMP2.xmp", '-o', 'out.xmp']],
    ['XMP.svg', ['-XMP:Title=x', '-o', 'out.svg']],
    ['XMP.inx', ['-XMP:Title=x', '-o', 'out.inx']],
    ['PLUS.xmp', ['-XMP-plus:LicenseeName=Phil', '-o', 'out.xmp']],
    ['XMP.xmp', ['-v2', '-XMP:Title=v2', '-o', 'out.xmp']],
    // XMP lang-alt and list tags
    [null, ['-XMP-dc:Title-fr=Titre', '-XMP-dc:Title=Title', '-XMP-dc:Title-de=Titel', '-o', 'out.xmp']],
    ['XMP.xmp', ['-XMP-dc:Rights-de-DE=Recht', '-o', 'out.xmp']],
    ['XMP.xmp', ['-XMP-dc:Rights-x-default=', '-o', 'out.xmp']],
    ['XMP.xmp', ['-XMP-dc:Rights=', '-o', 'out.xmp']],
    ['XMP.jpg', ['-XMP-dc:Title-fr=fr', '-XMP-dc:Title-x-default=default', '-o', 'out.jpg']],
    ['XMP.jpg', ['-XMP-dc:Title-de-=', '-XMP-dc:Title-de=A', '-o', 'out.jpg']],
    ['XMP9.xmp', ['-XMP-plus:Custom1+=new', '-XMP-plus:Custom1-fr+=nouveau', '-o', 'out.xmp']],
    ['XMP9.xmp', ['-XMP-plus:Custom1-=cu2', '-XMP-plus:Custom1+=x', '-o', 'out.xmp']],
    ['XMP9.xmp', ['-XMP-plus:Custom1-de=', '-o', 'out.xmp']],
    ['XMP.jpg', ['-XMP-photoshop:SupplementalCategories+=more', '-XMP-dc:Creator+=Another', '-o', 'out.jpg']],
    // XMP structures
    [null, ['-XMP-iptcExt:LocationShown={City=Paris,CountryName=France}', '-o', 'out.xmp']],
    [null, ['-XMP-iptcExt:LocationShown=[{City=Paris},{City=Lyon,ProvinceState=Rhone}]', '-o', 'out.xmp']],
    [null, ['-XMP-mwg-rs:RegionInfo={AppliedToDimensions={W=640,H=480,Unit=pixel},RegionList=[{Area={X=0.5,Y=0.5,W=0.1,H=0.1,Unit=normalized},Name=Face,Type=Face}]}', '-o', 'out.xmp']],
    [null, ['-XMP-mwg-kw:HierarchicalKeywords={Keyword=A,Children=[{Keyword=B},{Keyword=C,Children={Keyword=D}}]}', '-o', 'out.xmp']],
    [null, ['-XMP-plus:Licensee={LicenseeName=Phil,LicenseeID=123}', '-XMP-plus:ImageCreator={ImageCreatorName=Me}', '-o', 'out.xmp']],
    [null, ['-XMP:Flash={}', '-XMP-mwg-rs:RegionInfo={RegionList=[,]}', '-o', 'out.xmp']],
    [null, ['-XMP-exif:Flash={Fired=True,Return=no,mode#=2}', '-o', 'out.xmp']],
    [null, ["-XMP-iptcExt:ArtworkOrObject={AOTitle=test,aotitle-de=pr\xc3\xbcfung,AOTitle_FR=\xc3\xa9preuve}", '-o', 'out.xmp']],
    [null, ['-api', 'StructFormat=JSON', '-XMP-iptcExt:LocationShown={"City":"Paris","CountryCode":"FR"}', '-o', 'out.xmp']],
    [null, ['-XMP-iptcExt:LocationShown={City=Paris,NoSuchField=x}', '-o', 'out.xmp']],
    [null, ['-XMP-iptcExt:LocationShown={City=Paris', '-o', 'out.xmp']],
    [null, ['-XMP-iptcExt:ImageRegion={Flash={Fired=True,Return#=3}}', '-XMP-iptcExt:ImageRegionCtypeIdentifier=x', '-o', 'out.xmp']],
    [null, ['-XMP-mwg-rs:RegionExtensions={XMP-exif:FlashReturn=not,Flash={Mode#=1},UsageTerms-fr=libre}', '-o', 'out.xmp']],
    ['XMP7.xmp', ['-XMP-iptcExt:LocationShownCity=Lyon', '-o', 'out.xmp']],
    ['XMP7.xmp', ['-XMP-iptcExt:LocationShownCity-=Paris', '-XMP-iptcExt:LocationShownCity+=Nice', '-o', 'out.xmp']],
    ['XMP7.xmp', ['-XMP-iptcExt:LocationShown-={City=Paris,CountryCode=FR}', '-o', 'out.xmp']],
    ['XMP7.xmp', ['-XMP-iptcExt:LocationShown+={City=Rome,CountryCode=IT}', '-o', 'out.xmp']],
    ['XMP7.xmp', ['-XMP-iptcExt:LocationShown=', '-o', 'out.xmp']],
    ['XMP8.xmp', ['-XMP-iptcExt:AboutCvTermName-=two', '-XMP-iptcExt:AboutCvTermName+=deux', '-o', 'out.xmp']],
    ['XMP5.xmp', ['-XMP-mwg-kw:HierarchicalKeywords=', '-o', 'out.xmp']],
    ['XMP5.xmp', ['-XMP-exif:FlashMode=1', '-XMP-mwg-rs:RegionName=Changed', '-o', 'out.xmp']],
    ['XMP5.xmp', ['-v2', '-XMP-plus:Licensee=', '-o', 'out.xmp']],
    ['XMP4.xmp', ['-XMP-xmpMM:History+={Action=edited,When=2020:01:02 03:04:05}', '-o', 'out.xmp']],
    ['XMP5.xmp', ['-api', 'Compact=Shorthand', '-XMP:Title=x', '-o', 'out.xmp']],
    ['XMP4.xmp', ['-api', 'Compact=Shorthand,NoIndent', '-XMP:Title=x', '-o', 'out.xmp']],
    [null, ['-api', 'Compact=Shorthand', '-XMP-mwg-rs:RegionInfo={RegionList=[{Area={X=1,Y=2},Name=n}]}', '-o', 'out.xmp']],
    // XMP: invalid values
    [null, ['-XMP-exif:Flash=[{Fired=True}]', '-XMP-iptcExt:LocationShown=abc', '-XMP:Title=x', '-o', 'out.xmp']],
    [null, ['-XMP-exif:ISO=abc', '-XMP-xmp:CreateDate=bad date', '-XMP-dc:Title-zz-ZZ-xyz=x', '-o', 'out.xmp']],
    ['XMP.jpg', ['-n', '-XMP-tiff:Orientation=6', '-XMP-exif:Flash=1', '-o', 'out.jpg']],
    // XMP in TIFF and PSD (also need the EXIF writer for TIFF)
    ['ExifTool.tif', ['-XMP:Title=tiff', '-o', 'out.tif']],
    ['ExifTool.tif', ['-XMP:all=', '-o', 'out.tif']],
    ['Photoshop.psd', ['-XMP:Title=psd', '-XMP:Subject+=psd', '-o', 'out.psd']],
    ['Photoshop.psd', ['-XMP:all=', '-o', 'out.psd']],
    // EXIF in JPEG (WriteExif): tags of various formats
    ['Canon.jpg', ['-Artist=me', '-Copyright=(c) 2026', '-o', 'out.jpg']],
    ['Canon.jpg', ['-ExposureTime=1/250', '-FNumber=5.6', '-ISO=800', '-FocalLength=35', '-o', 'out.jpg']],
    ['GPS.jpg', ['-DateTimeOriginal=2021:02:03 04:05:06', '-OffsetTimeOriginal=+02:00', '-SubSecTimeOriginal=123', '-o', 'out.jpg']],
    ['GPS.jpg', ["-UserComment=caf\xc3\xa9", '-XPTitle=windows title', '-XPKeywords=a;b', '-o', 'out.jpg']],
    ['GPS.jpg', ['-LensInfo=18-55mm f/3.5-5.6', '-LensModel=EF-S18-55mm', '-CFAPattern=[Red,Green][Green,Blue]', '-o', 'out.jpg']],
    ['GPS.jpg', ['-Contrast=High', '-Saturation=Low', '-Sharpness=Soft', '-ExposureCompensation=-1/3', '-o', 'out.jpg']],
    ['GPS.jpg', ['-n', '-Orientation=6', '-Flash=16', '-ResolutionUnit=3', '-o', 'out.jpg']],
    ['GPS.jpg', ['-GPSLatitude=12.5', '-GPSLatitudeRef=S', '-GPSAltitude=100', '-GPSAltitudeRef=Below Sea Level', '-o', 'out.jpg']],
    ['GPS.jpg', ['-GPSTimeStamp=2007:03:02 18:46:10.55-05:30', '-GPSDateStamp=2007:03:02', '-o', 'out.jpg']],
    ['GPS.jpg', ['-ExposureTime=abc', '-ISO=-5', '-Orientation=sideways', '-Artist=ok', '-o', 'out.jpg']],
    ['Canon.jpg', ['-DateTimeOriginal+=1:30:00', '-CreateDate-=0:0:1 0:0:0', '-o', 'out.jpg']],
    ['Canon.jpg', ['-v3', '-Artist=verbose', '-o', 'out.jpg']],
    // EXIF in JPEG: creating EXIF, IFD0, ExifIFD, GPS, InteropIFD and IFD1 from scratch
    ['Writer.jpg', ['-Artist=new EXIF', '-o', 'out.jpg']],
    ['Writer.jpg', ['-ExifIFD:ISO=100', '-GPSLatitude=1.5', '-GPSLatitudeRef=N', '-InteropIndex=R98', '-o', 'out.jpg']],
    ['Writer.jpg', ['-IFD1:XResolution=300', '-o', 'out.jpg']],
    ['Writer.jpg', ["-ThumbnailImage<=$images/Writer.jpg", '-o', 'out.jpg']],
    ['Writer.jpg', ['-ExifByteOrder=Little-endian', '-Artist=little', '-o', 'out.jpg']],
    ['Writer.jpg', ['-api', 'ByteOrder=II', '-Make=x', '-Model=y', '-o', 'out.jpg']],
    ['Writer.jpg', ['-api', 'NoMandatory', '-ExifIFD:ISO=200', '-o', 'out.jpg']],
    ['Writer.jpg', ['-v3', '-GPSAltitude=10', '-o', 'out.jpg']],
    // EXIF in JPEG: deleting
    ['Canon.jpg', ['-ExifIFD:all=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-IFD0:all=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-IFD1:all=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-ThumbnailImage=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-MakerNotes:all=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-InteropIFD:all=', '-o', 'out.jpg']],
    ['GPS.jpg', ['-GPS:all=', '-o', 'out.jpg']],
    ['GPS.jpg', ['-Model=', '-Make=', '-DateTimeOriginal=', '-o', 'out.jpg']],
    ['Canon.jpg', ['-EXIF:all=', '-Artist=only artist', '-o', 'out.jpg']],
    ['FLIR.jpg', ['-ThumbnailImage=', '-o', 'out.jpg']],
    ['FLIR.jpg', ["-ThumbnailImage<=$images/Writer.jpg", '-o', 'out.jpg']],
    // EXIF in JPEG with maker notes (relocated and fixed up)
    ['Canon.jpg', ['-OwnerName=Phil', '-CanonImageType=new type', '-o', 'out.jpg']],
    ['Nikon.jpg', ['-Creator=Phil', '-ImageAdjustment=Yes, lots of it', '-o', 'out.jpg']],
    ['NikonD70.jpg', ['-Artist=me', '-WhiteBalanceFineTune=2', '-o', 'out.jpg']],
    ['NikonD2Hs.jpg', ['-Artist=me', '-o', 'out.jpg']],
    ['Olympus.jpg', ['-Macro=On', '-o', 'out.jpg']],
    ['OlympusE1.jpg', ['-LensSerialNumber=012345678', '-CoringFilter=0', '-o', 'out.jpg']],
    ['Olympus2.jpg', ['-FocusDistance=100', '-Macro=On', '-o', 'out.jpg']],
    ['Pentax.jpg', ['-ThumbnailImage=', '-MakerNotes:WhiteBalance=Tungsten', '-FocalLength=22', '-MaxAperture=2.0', '-o', 'out.jpg']],
    ['Sony.jpg', ['-FlashFired=true', '-ISO=', '-o', 'out.jpg']],
    ['Casio.jpg', ['-MaxApertureValue=4', '-FocusMode=Macro', '-o', 'out.jpg']],
    ['Casio2.jpg', ['-XResolution=300', '-ObjectDistance=3.5', '-o', 'out.jpg']],
    ['FujiFilm.jpg', ['-CreateDate=2005:01:06 11:51:09', '-MakerNotes:WhiteBalance=day white', '-o', 'out.jpg']],
    ['Kodak.jpg', ['-Kodak:YearCreated=2005', '-Kodak:MonthDayCreated=03:31', '-DigitalZoom=2', '-o', 'out.jpg']],
    ['Panasonic.jpg', ['-ShootingMode=Panning', '-o', 'out.jpg']],
    ['Ricoh.jpg', ['-Ricoh:Sharpness=Soft', '-o', 'out.jpg']],
    ['Sanyo.jpg', ['-SceneCaptureType=night', '-MakerNotes:FlashMode=force', '-o', 'out.jpg']],
    ['Minolta.jpg', ['-MinoltaDate=2005:01:16', '-o', 'out.jpg']],
    ['GE.jpg', ['-GEModel=test model', '-o', 'out.jpg']],
    ['Unknown.jpg', ['-FocalLength=200', '-o', 'out.jpg']],
    ['Sigma.jpg', ['-Artist=me', '-o', 'out.jpg']],
    ['JVC.jpg', ['-Artist=me', '-o', 'out.jpg']],
    ['Apple.jpg', ['-Artist=me', '-o', 'out.jpg']],
    ['GoPro.jpg', ['-Artist=me', '-o', 'out.jpg']],
    ['Motorola.jpg', ['-Artist=me', '-o', 'out.jpg']],
    ['Nikon.jpg', ['-v3', '-Creator=verbose', '-o', 'out.jpg']],
    // EXIF in TIFF-based files
    ['ExifTool.tif', ['-Artist=me', '-XResolution=300', '-o', 'out.tif']],
    ['ExifTool.tif', ['-IFD1:all=', '-o', 'out.tif']],
    ['ExifTool.tif', ['-ExifIFD:all=', '-GPS:all=', '-o', 'out.tif']],
    ['ExifTool.tif', ['-GPSLatitude=45', '-GPSLatitudeRef=N', '-ExifIFD:ISO=400', '-o', 'out.tif']],
    ['ExifTool.tif', ['-v3', '-Artist=tiff verbose', '-o', 'out.tif']],
    ['GeoTiff.tif', ['-ResolutionUnit=cm', '-o', 'out.tif']],
    ['BigTIFF.btf', ['-Artist=me', '-o', 'out.btf']],
    ['DNG.dng', ['-Artist=me', '-DateTimeOriginal=2020:01:01 00:00:00', '-o', 'out.dng']],
    ['Nikon.nef', ['-Artist=me', '-ISO=200', '-o', 'out.nef']],
    ['Nikon.nef', ['-IFD0:Copyright=nef', '-SubIFD:all=', '-o', 'out.nef']],
    ['KyoceraRaw.raw', ['-Artist=me', '-o', 'out.raw']],
    ['Photoshop.psd', ['-EXIF:Artist=psd', '-o', 'out.psd']],
    ['QuickTime.heic', ['-EXIF:Artist=heic', '-o', 'out.heic']],
    [null, ['-ExifByteOrder=Little-endian', '-Artist=me', '-GPSLatitude=1', '-o', 'out.exif']],
    [null, ['-ExifByteOrder=Big-endian', '-Artist=me', '-ExifIFD:ISO=100', '-o', 'out.exif']],
];
if (isset($opt['sweep'])) {
    $exts = array_map('strtolower', explode(',', $opt['ext'] ?? 'jpg'));
    $cases = [];
    foreach (glob("$images/*") as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $cases[] = [basename($file), [...str_getcsv($opt['sweep'], ' ', '"', ''), '-o', "out.$ext"]];
        }
    }
}

$verbose = isset($opt['v']);
$work = "$root/var/write-parity";
$perlEnv = ['PERL_HASH_SEED' => '0', 'PERL_PERTURB_KEYS' => '0'];   // deterministic reference
if (isset($opt['cli'])) {
    $tools = [
        'perl' => ['perl', "$etDir/exiftool"],
        'php' => [PHP_BINARY, "$root/bin/exiftool"],
    ];
} else {
    $tools = [
        'perl' => ['perl', "$root/tools/write-parity.pl", "$etDir/lib"],
        'php' => [PHP_BINARY, __FILE__, '--run'],
    ];
}

$total = $same = $msgOnly = 0;
foreach ($cases as $i => [$src, $args]) {
    $name = ($src ?? '(none)').' '.implode(' ', $args);
    if (isset($opt['case']) && !str_contains($name, $opt['case'])) {
        continue;
    }
    if (isset($opt['cli']) && preg_grep('/^-_/', $args)) {
        echo "skip $name (API only)\n";
        continue;
    }
    ++$total;
    $caseDir = sprintf('%s/%03d', $work, $i);
    $results = [];
    foreach ($tools as $tool => $cmd) {
        $dir = "$caseDir/$tool";
        EmptyDir($dir);
        $file = [];
        if (null !== $src) {
            $file = [basename($src)];
            copy(str_starts_with($src, '/') ? $src : "$etDir/t/images/$src", "$dir/$file[0]");
            chmod("$dir/$file[0]", 0644);
            touch("$dir/$file[0]", 1_000_000_000);  // (fixed time: FileModifyDate shifts)
        }
        [$status, $out] = Run([...$cmd, ...$args, ...$file], $dir, 'perl' === $tool ? $perlEnv : []);
        $results[$tool] = ['status' => $status, 'out' => $out, 'files' => ListFiles($dir)];
    }
    [$msgDiffs, $fileDiffs] = Compare($results, "$caseDir/perl", "$caseDir/php", $args, $etDir, $perlEnv);
    if ($msgDiffs || $fileDiffs) {
        echo $fileDiffs ? 'DIFF' : 'MSG ', " $name\n";
        foreach ([...$msgDiffs, ...$fileDiffs] as $d) {
            echo "     $d\n";
        }
        if (!$fileDiffs) {
            ++$msgOnly;
        }
    } else {
        ++$same;
        echo "ok   $name\n";
        if (!isset($opt['keep'])) {
            EmptyDir($caseDir, true);
        }
    }
    if ($verbose) {
        foreach ($results as $tool => $r) {
            echo "     [$tool] ".str_replace("\n", "\n     [$tool] ", rtrim($r['out']))."\n";
        }
    }
}
echo "$same/$total cases identical, $msgOnly with the same files but different messages (MSG), ",
    $total - $same - $msgOnly, " with different files (DIFF)\n";
exit($same === $total ? 0 : 1);

//------------------------------------------------------------------------------

/**
 * The differences between the results of the two tools: [differences of their output (results
 * of the calls, warnings, errors), differences of the files they wrote], empty when identical
 */
function Compare(array $results, string $perlDir, string $phpDir, array $args, string $etDir, array $perlEnv): array
{
    $msgs = $diffs = [];
    [$perl, $php] = [$results['perl'], $results['php']];
    if ($perl['out'] !== $php['out']) {
        $a = explode("\n", rtrim($perl['out']));
        $b = explode("\n", rtrim($php['out']));
        foreach (array_diff($a, $b) as $line) {
            $msgs[] = "perl: $line";
        }
        foreach (array_diff($b, $a) as $line) {
            $msgs[] = "php:  $line";
        }
    }
    if ($perl['status'] !== $php['status']) {
        $msgs[] = "exit status: perl {$perl['status']}, php {$php['status']}";
    }
    $names = array_unique([...array_keys($perl['files']), ...array_keys($php['files'])]);
    sort($names);
    $checkTime = (bool) preg_grep('/FileModifyDate/i', $args);
    foreach ($names as $name) {
        $a = $perl['files'][$name] ?? null;
        $b = $php['files'][$name] ?? null;
        if (!$a || !$b) {
            $diffs[] = "$name: only written by ".($a ? 'perl' : 'php');
            continue;
        }
        if ($a['link'] !== $b['link']) {
            $diffs[] = "$name: link perl {$a['link']}, php {$b['link']}";
        }
        if ($a['mode'] !== $b['mode']) {
            $diffs[] = sprintf('%s: permissions perl %o, php %o', $name, $a['mode'], $b['mode']);
        }
        if ($checkTime && $a['mtime'] !== $b['mtime']) {
            $diffs[] = "$name: modification time perl {$a['mtime']}, php {$b['mtime']}";
        }
        if ($a['md5'] === $b['md5']) {
            continue;
        }
        $diffs[] = "$name: contents differ (perl {$a['size']} bytes, php {$b['size']} bytes)";
        // compare the tags of both files
        $tags = [];
        foreach (['perl' => $perlDir, 'php' => $phpDir] as $tool => $dir) {
            [, $json] = Run(['perl', "$etDir/exiftool", '-j', '-a', '-u', '-G1', $name], $dir, $perlEnv);
            $tags[$tool] = json_decode($json, true)[0] ?? [];
            foreach (array_keys($tags[$tool]) as $key) {
                if ('SourceFile' === $key || str_starts_with($key, 'System:')) {
                    unset($tags[$tool][$key]);
                }
            }
        }
        foreach (array_unique([...array_keys($tags['perl']), ...array_keys($tags['php'])]) as $key) {
            $va = json_encode($tags['perl'][$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $vb = json_encode($tags['php'][$key] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($va !== $vb) {
                $diffs[] = "  $key: perl $va, php $vb";
            }
        }
    }

    return [$msgs, $diffs];
}

/** run a command in a directory: [exit status, stdout + stderr] */
function Run(array $cmd, string $cwd, array $env = []): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env ? $env + getenv() : null);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    return [$status, $out.$err];
}

/** files under a directory: relative name => [size, md5, mode, mtime, symbolic link target] */
function ListFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $path => $info) {
        if ($info->isFile()) {
            $files[substr($path, strlen($dir) + 1)] = [
                'size' => $info->getSize(),
                'md5' => md5_file($path),
                'mode' => $info->getPerms() & 0777,
                'mtime' => $info->getMTime(),
                'link' => $info->isLink() ? readlink($path) : null,
            ];
        }
    }
    ksort($files);

    return $files;
}

/** create an empty directory (under var/write-parity only), or remove it */
function EmptyDir(string $dir, bool $remove = false): void
{
    if (!str_contains($dir, '/var/write-parity/')) {
        throw new RuntimeException("refusing to empty $dir");
    }
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $path => $info) {
            $info->isDir() && !$info->isLink() ? rmdir($path) : unlink($path);
        }
        if ($remove) {
            rmdir($dir);
        }
    }
    if (!$remove && !is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

/**
 * The PHP runner: applies the exiftool write arguments through the API (SetNewValue +
 * WriteInfo) like the exiftool script does, and prints the result of every call.
 * Keep in sync with tools/write-parity.pl.
 */
function Runner(array $argv): int
{
    $et = new Image\ExifTool\ExifTool();
    $outfile = null;
    $files = $newValues = $api = [];
    while ($argv) {
        $arg = array_shift($argv);
        if ('-o' === $arg) {
            $outfile = array_shift($argv);
        } elseif ('-m' === $arg) {
            $et->Options('IgnoreMinorErrors', 1);
        } elseif ('-n' === $arg) {
            $et->Options('PrintConv', 0);
        } elseif (preg_match('/^-v(\d*)$/', $arg, $m)) {
            $et->Options('Verbose', strlen($m[1]) ? (int) $m[1] : 1);
        } elseif ('-api' === $arg) {
            $parts = explode('=', (string) array_shift($argv), 2);
            $et->Options($parts[0], $parts[1] ?? 1);
        } elseif ('-overwrite_original' === $arg) {
            // (the API never keeps a backup)
        } elseif ('-use' === $arg) {
            $useMWG = 'mwg' === strtolower((string) array_shift($argv));
        } elseif (preg_match('/^-_(memin|memout|handle|handleout|raf)$/', $arg, $m)) {
            $api[$m[1]] = 1;    // (not exiftool options: how the files are passed to WriteInfo)
        } elseif (preg_match('/^-(.*=.*)$/s', $arg, $m)) {
            $newValues[] = $m[1];
        } else {
            $files[] = $arg;
        }
    }
    // load the MWG module if used (-use MWG or MWG: tags), like the exiftool script
    if (($useMWG ?? false) || preg_grep('/^([-_0-9A-Z]+:)*1?mwg:/i', $newValues)) {
        Image\ExifTool\MWG::Load();
        if (null === $et->Options('CharsetEXIF')) {
            $et->Options('CharsetEXIF', 'UTF8');
        }
    }
    // set the new values like the exiftool script does
    $addDelOpt = ['+' => 'AddValue', '-' => 'DelValue', "\xe2\x88\x92" => 'DelValue'];
    foreach ($newValues as $nv) {
        if (!preg_match('/(.*?)=(.*)/s', $nv, $m)) {
            continue;
        }
        [$tag, $newVal] = [$m[1], $m[2]];
        $tag = preg_replace('/\ball\b/i', '*', $tag);   // replace 'all' with '*' in tag names
        $tag = preg_replace('/\^([-+]*)$/', '$1', $tag, 1, $n);
        if (!$n && '' === $newVal) {
            $newVal = null;     // undefined to delete tag
        }
        $opts = ['Shift' => 0];     // shift values if possible instead of adding/deleting
        // allow writing of 'Unsafe' tags unless specified by wildcard
        if (!preg_match('/[?*]/', $tag)) {
            $opts['Protected'] = 1;
        }
        if (str_contains($tag, '<') && null !== $newVal) {
            $tag = preg_replace('/</', '', $tag, 1);
            // read file data into $newVal (-TAG<=FILE)
            $data = @file_get_contents($newVal);
            if (false === $data) {
                echo "Error opening file $newVal\n";
                continue;
            }
            $newVal = $data;
        }
        if (preg_match('/([-+]|\xe2\x88\x92)$/', $tag, $m)) {
            $tag = substr($tag, 0, -strlen($m[1]));
            $opts[$addDelOpt[$m[1]]] = 1;   // set AddValue or DelValue option
            // set $newVal to '' if deleting nothing
            if ('-' === $m[1] && null === $newVal) {
                $newVal = '';
            }
        }
        [$rtn, $wrn] = $et->SetNewValueList($tag, $newVal, ...$opts) + [0, null];
        echo "SetNewValue $tag: $rtn", ($wrn ? " ($wrn)" : ''), "\n";
    }
    // pass the files as the API options ask
    [$src, $dst, $fh] = [$files[0] ?? null, $outfile, null];
    if (isset($api['memin']) || isset($api['raf'])) {
        $src = new Image\ExifTool\ScalarRef(file_get_contents($src));
        if (isset($api['raf'])) {
            $src = new Image\ExifTool\File\RandomAccess($src->value);
        }
    } elseif (isset($api['handle'])) {
        $src = $fh = fopen($src, 'r+b');
    }
    $outFh = null;
    if (isset($api['memout'])) {
        $dst = new Image\ExifTool\ScalarRef(null);
    } elseif (isset($api['handleout'])) {
        $dst = $outFh = fopen($outfile, 'wb');
    }
    $rtn = $et->WriteInfo($src, $dst);
    echo "WriteInfo: $rtn\n";
    if (isset($api['memout']) && null !== $dst->value && null !== $outfile) {
        file_put_contents($outfile, $dst->value);
    } elseif (isset($api['memin']) && null === $outfile) {
        file_put_contents($files[0], $src->value);
    }
    if ($fh) {
        fclose($fh);
    }
    if ($outFh) {
        fclose($outFh);
    }
    $info = $et->GetInfo('Warning', 'Error');
    $keys = array_map('strval', array_keys($info));
    sort($keys, SORT_STRING);
    foreach ($keys as $key) {
        echo "$key: ", Image\ExifTool\Perl::str($info[$key]), "\n";
    }

    return 0;
}
