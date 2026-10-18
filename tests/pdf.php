<?php

// Replays ExifTool's t/PDF.t through the PHP API and compares with its expected output
// (t/PDF_N.out). Run: php tests/pdf.php [~/exiftool] [--all]

require __DIR__.'/TestLib.php';

use Image\ExifTool\AES;
use Image\ExifTool\ExifTool;
use Image\ExifTool\PDF;
use Image\ExifTool\Vars;

$skip = [];
$t = testDir();
$testname = 'PDF';

// PDF decryption test (see CryptTest() of t/PDF.t)
function cryptTest(array $cryptInfo, bool $encrypt = false): bool
{
    $et = new ExifTool();
    $et->Options('Password', $cryptInfo['_password'] ?? null);
    $err = PDF::DecryptInit($et, $cryptInfo, $cryptInfo['_id']);
    if (!$err) {
        $data = $cryptInfo[$encrypt ? '_plaintext' : '_ciphertext'];
        PDF::Crypt($data, $cryptInfo['_ref'] ?? '1 0 R', $encrypt ? 1 : null);
        $err = Vars\PDF::$cryptInfo['_error'] ?? null;
        if (!$err and $data !== $cryptInfo[$encrypt ? '_ciphertext' : '_plaintext']) {
            $err = "returned wrong value:\n    ".bin2hex($data);
        }
    }
    if ($err) {
        fwrite(STDERR, "\n  $err\n");
    }

    return !$err;
}

// test 2: Extract information from PDF.pdf
runTest($testname, 2, function () use ($testname) {
    $et = new ExifTool();
    $info = $et->ImageInfo(testImage('PDF.pdf'));

    return check($et, $info, $testname, 2);
}, $skip);

// test 3: Test Standard PDF decryption
runTest($testname, 3, fn () => cryptTest([
    'Filter' => '/Standard',
    'P' => -60,
    'V' => 1,
    'R' => 0,
    'O' => '<2055c756c72e1ad702608e8196acad447ad32d17cff583235f6dd15fed7dab67>',
    'U' => '<7150bd1da9d292af3627fca6a8dde1d696e25312041aed09059f9daee04353ae>',
    '_id' => hex2bin('12116a1a124ae4cd8179e8978f6ac88b'),
    '_ref' => '4 0 R',
    '_ciphertext' => pack('N', 0x34a290d3),
    '_plaintext' => pack('N', 0x5924d335),
]), $skip);

// tests 4-21: Test writing, deleting and reverting two different files
$edits = [[     // (on file containing both PDF Info and XMP)
    [   // test 4: write PDF and XMP information
        ['PDF:Creator', 'me'],
        ['XMP:Creator', 'you'],
        ['AllDates', '2:30', 'Shift' => -1],
    ], [ // test 5: delete all PDF
        ['PDF:all'],
    ], [ // test 6: write some XMP
        ['XMP:Author', 'them'],
    ], [ // test 7: create new PDF
        ['PDF:Keywords', 'one'],
        ['PDF:Keywords', 'two'],
        ['AppleKeywords', 'three'],
        ['AppleKeywords', 'four'],
    ], [ // test 8: delete all XMP
        ['XMP:all'],
    ], [ // test 9: write some PDF
        ['PDF:Keywords', 'another one', 'AddValue' => 1],
        ['AppleKeywords', 'three', 'DelValue' => 1],
    ], [ // test 10: create new XMP
        ['XMP:Author', 'us'],
    ], [ // test 11: write some PDF
        ['PDF:Keywords', 'two', 'DelValue' => 1],
        ['AppleKeywords', 'five', 'AddValue' => 1],
    ], [ // test 12: delete re-added XMP
        ['XMP:all'],
    ],
], [            // (on file without PDF Info or XMP)
    [   // test 14: create new XMP
        ['XMP:Author', 'him'],
    ], [ // test 15: create new PDF
        ['PDF:Author', 'her'],
    ], [ // test 16: delete XMP and PDF
        ['XMP:all'],
        ['PDF:all'],
    ], [ // test 17: delete XMP and PDF again
        ['XMP:all'],
        ['PDF:all'],
    ], [ // test 18: create new PDF
        ['PDF:Author', 'it'],
    ], [ // test 19: create new XMP
        ['XMP:Author', 'thing'],
    ], [ // test 20: delete all
        ['all'],
    ],
]];
$testnum = 3;
foreach ([0, 1] as $testSet) {
    $testfile = "$t/images/".($testSet ? 'PDF2.pdf' : 'PDF.pdf');
    $testfile1 = $testfile;
    $lastOK = false;
    $et = new ExifTool();
    $et->Options('PrintConv', 0);
    foreach ($edits[$testSet] as $edit) {
        ++$testnum;
        $num = $testnum;
        runTest($testname, $num, function () use ($et, $edit, &$testfile1, &$lastOK, $testname, $num) {
            $et->SetNewValue();
            foreach ($edit as $args) {
                $tag = array_shift($args);
                $val = array_key_exists(0, $args) ? array_shift($args) : null;
                $et->SetNewValue($tag, $val, ...$args);
            }
            $testfile2 = "t/{$testname}_{$num}_failed.pdf";
            @unlink($testfile2);
            $et->WriteInfo($testfile1, $testfile2);
            $info = $et->ImageInfo($testfile2, 'Filesize', 'PDF:all', 'XMP:Creator', 'XMP:Author', 'AllDates');
            $ok = check($et, $info, $testname, $num);
            // erase source file if previous test was OK
            if ($lastOK and str_starts_with($testfile1, 't/')) {
                @unlink($testfile1);
            }
            $lastOK = $ok;
            $testfile1 = $testfile2;    // use this file for the next test

            return $ok;
        }, $skip);
    }
    // revert all edits and compare with original file
    ++$testnum;
    $num = $testnum;
    runTest($testname, $num, function () use ($et, &$testfile1, $lastOK, $testfile, $testname, $num) {
        $et->SetNewValue('PDF-update:all');
        $testfile2 = "t/{$testname}_{$num}_failed.pdf";
        @unlink($testfile2);
        $et->WriteInfo($testfile1, $testfile2);
        $ok = binaryCompare($testfile2, $testfile);
        if ($ok) {
            @unlink($testfile2);
        }
        if ($lastOK) {
            @unlink($testfile1);
        }

        return $ok;
    }, $skip);
}

// test 22: Delete all tags
runTest($testname, 22, function () use ($t, $testname) {
    $et = new ExifTool();
    $testfile = "t/{$testname}_22_failed.pdf";
    @unlink($testfile);
    $et->Options('IgnoreMinorErrors', 1);
    $et->SetNewValue('all', null);
    $ok = writeInfo($et, "$t/images/PDF.pdf", $testfile);
    $et->Options('IgnoreMinorErrors', 0);
    $info = $et->ImageInfo($testfile, 'pdf:all', 'xmp:all', ['Duplicates' => 1, 'Unknown' => 1]);
    if ($ok and check($et, $info, $testname, 22)) {
        @unlink($testfile);

        return true;
    }

    return false;
}, $skip);

// test 23: Test AES decryption alone
runTest($testname, 23, function () {
    $data = hex2bin('6fdc3ca684348bc8f31379aa46455d7b60c0989e027c1d82e746f136d6e95b7485735793ff64310e5b9e367dcc26f564');
    $err = AES::Crypt($data, '11223344556677889900112233445566');
    if ($err) {
        fwrite(STDERR, "\n  $err\n");

        return false;
    }
    if ($data !== 'ExifTool AES Test') {
        fwrite(STDERR, "\n  Incorrect result from AES decryption:\n    ".bin2hex($data)."\n");

        return false;
    }

    return true;
}, $skip);

// tests 24-26: Test AESV2 and AESV3 decryption
$aes = fn (string $ver, int $v, int $len, int $p, string $cfm, int $cfLen, array $x) => array_merge([
    'Filter' => '/Standard', 'V' => $v, 'R' => $v, 'P' => $p, 'Length' => $len,
    'StmF' => '/StdCF', 'StrF' => '/StdCF',
    'CF' => ['StdCF' => ['AuthEvent' => '/DocOpen', 'CFM' => $cfm, 'Length' => $cfLen]],
], $x);
$encrypt = [
    $aes('', 4, 128, -1340, '/AESV2', 16, [
        'EncryptMetadata' => 'false',
        'O' => '<181ee8e93a99fa1c2a534dd68a5ab07c54268cfe8fbf28c468316b6f732674c1>',
        'U' => '<a3525aef4143f4419c78b109317f0e5200000000000000000000000000000000>',
        '_ref' => '4 0 R',
        '_id' => hex2bin('d0a736f05faf64c6b52dea82a2ad53e0'),
        '_plaintext' => 'This was a test',
        '_ciphertext' => hex2bin('a86b5e00d9c7e4455cf5d8cedf195c2060e1467ea6d698876a77e9a66cb7867c'),
    ]),
    $aes('', 5, 256, -1028, '/AESV3', 32, [
        'Perms' => '<014ee28fe2b91e2198a593b7c3b22f50>',
        'O' => '<83e5edfcdecbe2ebe6d519dbafe80fd453028dda119eb76d0216e1344392320d60e1467ea6d698876a77e9a66cb7867c>',
        'U' => '<e5e7ade8aebdc9413a0fd176efc4081bdbad3b16a67ece7a01fadb24010a003ea86b5e00d9c7e4455cf5d8cedf195c20>',
        'OE' => '<a29f37f1f085b575d9016daad05ca466dd073ba5d067cc7ffa8ef7d1605e460e>',
        'UE' => '<47ea891b25af77aaceccf8f2fdeff0c09e9d0f67275f059dbfabbb18fcbf848d>',
        '_ref' => 'dummy',
        '_id' => hex2bin('618cb5be1d82fceea9a501b62d408296'),
        '_plaintext' => 'This was a test',
        '_ciphertext' => hex2bin('e90756e8fd60fb7390c34d931e3e3d61898cd133e613e8cf86cd40f7b207a62d'),
    ]),
    $aes('', 5, 256, -1028, '/AESV3', 32, [
        'Perms' => '<014ee28fe2b91e2198a593b7c3b22f50>',
        'O' => '<31eefe924a298d8bf19eafc9be6abdfa65a97478f94e907dccff5ac000b83fa521167b86cf70bf77d4a054bc9a59573d>',
        'U' => '<6525a788c2ebf27baa45f526bcdb9d2f96c3c67ae1c62324135cac0b823451ba9ad8edb68d167d2d8370d799c41d17d7>',
        'OE' => '<ea58e3c731999cdc0f8a395c7391836c2b2db0b4ac86439b3fe5692ddc71671a>',
        'UE' => '<f6818f43e176dfe8912f62717032169cf48854f540f7b7641be363ef50371f07>',
        '_ref' => 'dummy',
        '_id' => hex2bin('b5f9d17b07152a45bc0a939727c389ad'),
        '_plaintext' => 'This was a test',
        '_password' => 'ExifTool',
        '_ciphertext' => hex2bin('8bb3565d8c4b9df8cc350954d9f91a46aa47e40eeb5a0cff559acd5ec3e94244'),
    ]),
];
foreach ($encrypt as $i => $cryptInfo) {
    runTest($testname, 24 + $i, fn () => cryptTest($cryptInfo), $skip);
}

exit(testsDone());
