<?php

//------------------------------------------------------------------------------
// File:         Samsung.pm
//
// Description:  Samsung EXIF maker notes tags
//
// Revisions:    2010/03/01 - P. Harvey Created
//
// References:   1) Tae-Sun Park private communication
//               2) http://www.cybercom.net/~dcoffin/dcraw/
//               3) Pascal de Bruijn private communication (NX100)
//               4) Jaroslav Stepanek via rt.cpan.org
//               5) Nick Livchits private communication
//               6) Sreerag Raghavan private communication (SM-C200)
//               IB) Iliah Borg private communication (LibRaw)
//               NJ) Niels Kristian Bech Jensen private communication
//
// PHP port of Image::ExifTool::Samsung 1.62 (reading only: WriteSTMN and the writing
// branches of ProcessSamsung are not ported). Tag tables are generated into
// tables/Samsung.php, %formatMinMax and %samsungLensTypes into Vars\Samsung.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\Samsung as Vars;

final class Samsung
{
    public static $VERSION = '1.62';

    //------------------------------------------------------------------------------
    // Encrypt/Decrypt NX10 information
    // Inputs: 0) ExifTool ref, 1) value as a string of integers,
    //         2) tagInfo hash ref, 3-N) encryption salt values
    // Returns: encrypted/decrypted value
    // Notes:
    // 1) The encryption salt starts with '-' to reverse the encryption algorithm
    // 2) Additional salt values are provided when tag stores multiple arrays
    //    (in which case the first value of the tag gives the array length)
    public static function Crypt(ExifTool $et, $val, ?TagInfo $tagInfo, ...$salt)
    {
        $key = $et->EncryptionKey;
        if ($key === null) return null;
        $format = ($tagInfo?->Writable) ?: ($tagInfo?->Format);
        if (!$format) return null;
        if (empty(Vars::$formatMinMax[$format])) return null;
        [$min, $max] = Vars::$formatMinMax[$format];
        $a = Perl::split(' ', $val);
        $newSalt = (count($salt) > 1) ? 1 : 0;  // skip length entry if this is an array
        $sign = $saltVal = $start = null;
        for ($i = $newSalt; $i < count($a); ++$i) {
            if ($i == $newSalt) {
                $start = $i;
                $saltVal = Perl::str(array_shift($salt));
                $sign = str_starts_with($saltVal, '-') ? -1 : 1;
                if ($sign < 0) $saltVal = substr($saltVal, 1);
                if ($salt) $newSalt += Perl::num($a[0]);
            }
            $a[$i] = Perl::num($a[$i]) + $sign * Perl::num($key[Perl::mod(Perl::num($saltVal) + $i - $start, count($key))] ?? 0);
            // handle integer wrap-around
            if ($sign > 0) {
                if ($a[$i] > $max) $a[$i] -= $max - $min + 1;
            } else {
                if ($a[$i] < $min) $a[$i] += $max - $min + 1;
            }
        }
        return implode(' ', array_map(Perl::str(...), $a));
    }

    //------------------------------------------------------------------------------
    // Process Samsung MP4 INFO data
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessINFO(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $len = $dirInfo['DirLen'];
        $end = $pos + $len;
        $et->VerboseDir('INFO', null, $len);
        while ($pos + 8 <= $end) {
            $tag = substr($dataPt, $pos, 4);
            $val = ExifTool::Get32u($dataPt, $pos + 4);
            if (!$tagTablePtr[$tag]) {
                $name = preg_replace('/[^-_0-9a-zA-Z]/', '', "Samsung_INFO_$tag");
                if ($name) ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
            }
            $et->HandleTag($tagTablePtr, $tag, $val);
            $pos += 8;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read Samsung Meta Info from trailer
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: true on success
    public static function ProcessSamsungMeta(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dirName = $dirInfo['DirName'];
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $end = $dirInfo['DirLen'] + $pos;
        if (!($pos + 8 <= $end and substr($dataPt, $pos, 4) === 'DOFS')) {
            $et->Warn("Unrecognized $dirName data", 1);
            return 0;
        }
        $ver = ExifTool::Get32u($dataPt, $pos + 4);
        if ($ver == 3) {
            if (!($pos + 18 <= $end and ExifTool::Get32u($dataPt, $pos + 12) == $dirInfo['DirLen'])) {
                $et->Warn("Unrecognized $dirName version $ver data");
                return 0;
            }
            $num = ExifTool::Get16u($dataPt, $pos + 16);
            $et->VerboseDir("$dirName version $ver", $num);
            $pos += 18;
            $val = null;
            for ($i = 0; $i < $num; ++$i) {
                if ($pos + 2 > $end) break;
                $n = ExifTool::Get8u($dataPt, $pos + 1);
                $pos += 2;
                if ($pos + $n + 2 > $end) break;
                $tag = substr($dataPt, $pos, $n);
                $len = ExifTool::Get16u($dataPt, $pos + $n);
                $pos += $n + 2;
                if ($pos + $len > $end) break;
                if ($len == 4) {
                    $val = ExifTool::Get32u($dataPt, $pos);
                } else {
                    $tmp = substr($dataPt, $pos, $len);
                    // (Perl stores \$pos here, a reference to the variable, not to $tmp)
                    $val = new ScalarRef(null);
                    $val->value = &$pos;
                }
                $et->HandleTag($tagTablePtr, $tag, $val);
                $pos += $len;
            }
            if ($i < $num) $et->Warn("Unexpected end of $dirName version $ver $i $num data");
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: true on success
    public static function ProcessSamsungIFD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $len = $dirInfo['DataLen'];
        $pos = $dirInfo['DirStart'];
        if (!($pos + 4 < $len)) return 0;
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $buff = substr($dataPt, $pos, 4);
        // this is not an IFD for many models
        // (the string "Park Byeongchan" is often found here)
        if (!preg_match('/^([^\0])\0\0\0/s', $buff, $m)) return 0;
        $buff = $m[1] . "\0" . $m[1] . "\0" . substr($buff, 4);
        $numEntries = ord($m[1]);
        if ($et->HTML_DUMP) {
            $pt = $dirInfo['DirStart'] + $dirInfo['DataPos'] + $dirInfo['Base'];
            $et->HDump($pt - 44, 44, 'MakerNotes header', 'Samsung');
            $et->HDump($pt, 4, 'MakerNotes entries', "Format: int32u\nEntry count: $numEntries");
            $dirInfo['NoDumpEntryCount'] = 1;
        }
        $dirInfo['DataPt'] = substr_replace($dataPt, $buff, $pos, 4);   // insert bogus 2-byte entry count
        // offset base is at end of IFD
        $shift = $dirInfo['DirStart'] + 4 + $numEntries * 12 + 4;
        $dirInfo['Base'] = (($dirInfo['Base'] ?? 0) ?: 0) + $shift;
        $dirInfo['DataPos'] = (($dirInfo['DataPos'] ?? 0) ?: 0) - $shift;
        $dirInfo['DirStart'] += 2;      // start at bogus entry count
        $dirInfo['ZeroOffsetOK'] = 1;   // disable check for zero offset
        $et->NO_UNKNOWN = null;   // (set for BinaryData, but not for EXIF IFD's)
        $rtn = Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
        $dirInfo['DataPt'] = $dataPt;   // remove bogus count
        return $rtn;
    }

    //------------------------------------------------------------------------------
    // Read/write Samsung trailer (ie. "Sound & Shot" written by Galaxy Tab S (SM-T805))
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 not valid Samsung trailer, or -1 error writing
    // - updates DataPos to point to start of Samsung trailer
    // - updates DirLen to existing trailer length
    // (PHP: reading only, the OutFile branches are not ported)
    public static function ProcessSamsung(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $unknown = $et->Options('Unknown');
        $buff = $buf2 = $index = null;

        if (!$raf) {
            $raf = new RandomAccess(($dirInfo['DataPt'] ?? null) instanceof ScalarRef ? $dirInfo['DataPt']->value : (string) $dirInfo['DataPt']);
            $et->VerboseDir('SamsungTrailer');
        }
        if (!($raf->Seek(-6 - $offset, 2) and $raf->Read($buff, 6) == 6 and
              ($buff === 'QDIOBS' or $buff === "\0\0SEFT")))
        {
            return 0;
        }
        $endPos = $raf->Tell();
        if ($buff === 'QDIOBS' and !$raf->Seek(-2, 1)) return 0;    // rewind to before 'BS'
        $blockEnd = $raf->Tell();
        ExifTool::SetByteOrder('II');

        // read blocks backward until we find the SEFH/SEFT block
        // (the only other block I have seen is QDIO/QDIO)
        for (;;) {     // SamBlock
            if (!($raf->Seek($blockEnd - 8, 0) and $raf->Read($buff, 8) == 8)) break;
            $type = substr($buff, 4);
            if (!preg_match('/^\w+$/', $type)) break;
            $len = ExifTool::Get32u($buff, 0);
            if (!($len < 0x10000 and $len >= 4 and $len + 8 < $blockEnd)) break;
            if (!($raf->Seek(-8 - $len, 1) and $raf->Read($buff, $len) == $len)) break;
            $blockEnd -= $len + 8;
            if ($type !== 'SEFT') {     // look for directory block (ends with "SEFT")
                // (the QDIO block is only needed when writing)
                continue;
            }
            // validate SEFH header
            if (!(str_starts_with($buff, 'SEFH') and $len >= 12)) {
                // tolerate extra junk written by Samsung Gallery
                if (!preg_match('/\0\0SEFT/', $buff, $m, PREG_OFFSET_CAPTURE)) break;
                $et->Warn('Trailer likely corrupted by Samsung Gallery');
                $blockEnd += $m[0][1] + strlen($m[0][0]);
                continue;
            }
            $dirPos = $raf->Tell() - $len;
            // my $ver = Get32u(\$buff, 0x04);  # version (=101)
            $count = ExifTool::Get32u($buff, 0x08);
            if (12 + 12 * $count > $len) break;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Samsung::Trailer');

            // scan ahead quickly to look for the block where the data comes first
            // (have only seen this to be the first in the directory, but just in case)
            $firstBlock = 0;
            for ($index = 0; $index < $count; ++$index) {
                $entry = 12 + 12 * $index;
                $noff = ExifTool::Get32u($buff, $entry + 4);    // negative offset
                if ($firstBlock < $noff) $firstBlock = $noff;
            }
            // save trailer position and length
            $dataPos = $dirInfo['DataPos'] = $dirPos - $firstBlock;
            $dirLen = $dirInfo['DirLen'] = $endPos - $dataPos;
            if (($verbose or $et->HTML_DUMP) and !empty($dirInfo['RAF'])) {
                $et->DumpTrailer($dirInfo);
                if ($et->HTML_DUMP) return 1;
            }
            // read through the SEFH/SEFT directory entries
            for ($index = 0; $index < $count; ++$index) {
                $entry = 12 + 12 * $index;
                // first 2 bytes always 0 (may be part of block type)
                $type = ExifTool::Get16u($buff, $entry + 2);    // block type
                $noff = ExifTool::Get32u($buff, $entry + 4);    // negative offset
                $size = ExifTool::Get32u($buff, $entry + 8);    // block size
                if ($noff > $dirPos or $size > $noff or $size < 8) break 2;     // (last SamBlock)
                if ($firstBlock < $noff) $firstBlock = $noff;
                if (!($raf->Seek($dirPos - $noff, 0) and $raf->Read($buf2, $size) == $size)) break;
                // (could validate the first 4 bytes of the block because they
                // are the same as the first 4 bytes of the directory entry)
                $len = ExifTool::Get32u($buf2, 4);
                if ($len + 8 > $size) break;
                $tag = Perl::sprintf('0x%.4x', $type);
                // add unknown tags if necessary
                if (!$tagTablePtr[$tag]) {
                    if (!($unknown or $verbose)) continue;
                    $tagInfo = [
                        'Name'        => "SamsungTrailer_$tag",
                        'Description' => "Samsung Trailer $tag",
                        'Unknown'     => 1,
                        'Binary'      => 1,
                    ];
                    ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
                if (!$tagTablePtr["$tag-name"]) {
                    $tagInfo2 = [
                        'Name'        => "SamsungTrailer_{$tag}Name",
                        'Description' => "Samsung Trailer $tag Name",
                        'Unknown'     => 1,
                    ];
                    ExifTool::AddTagToTable($tagTablePtr, "$tag-name", $tagInfo2);
                }
                // set SamsungTagName ExifTool member for use in tag Condition
                $et->SamsungTagName = substr($buf2, 8, $len);
                // extract tag name and value
                $et->HandleTag($tagTablePtr, "$tag-name", null,
                    DataPt:  $buf2,
                    DataPos: $dirPos - $noff,
                    Start:   8,
                    Size:    $len,
                );
                $et->HandleTag($tagTablePtr, $tag, null,
                    DataPt:  $buf2,
                    DataPos: $dirPos - $noff,
                    Start:   8 + $len,
                    Size:    $size - (8 + $len),
                );
                unset($et->SamsungTagName);
            }
            return 1;
        }
        $et->Warn('Error processing Samsung trailer', 1);
        return 0;
    }
}
