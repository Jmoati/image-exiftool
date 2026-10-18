<?php

//------------------------------------------------------------------------------
// File:         Sony.pm
//
// Description:  Sony EXIF Maker Notes tags
//
// Revisions:    04/06/2004  - P. Harvey Created
//
// References:   (see Sony.pm)
//
// PHP port of Image::ExifTool::Sony 3.87. Tag tables are generated into
// tables/Sony.php, %sonyLensTypes (already filled in from the Minolta LensType's, which is
// what SortLensTypes was for), %sonyLensTypes2 and @lensFeatures into Vars\Sony.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Sony
{
    public static $VERSION = '3.87';

    // enciphered values of bytes 0x02-0xf7 (Decipher)
    private const CIPHER = "\x08\x1b\x40\x7d\xd8\x5e\x0e\xe7\x04V\xea\xcd\x05\x8ap\xb6i\x88\x200\xbe\xd7\x81\xbb\x92\x0c\x28\xecl\xa0\x95Q\xd3\x2f\x5dj\x5c9\x07\xc5\x87L\x1a\xf0\xe2\xef\x24y\x02\xb7\xac\xe0\x60\x2bG\xba\x91\xcbu\x8e\x233\xc4\xe3\x96\xdc\xc2N\x7fb\xf6OeE\xeet\xcf\x138KRST\x5bn\x93\xd02\xb1aAW\xa9D\x27X\xdd\xc3\x10\xbc\xdbs\x83\x181\xd4\x15\xe5_\x7bF\xbf\xf3\xe8\xa4\x2d\x82\xb0\xbd\xaf\x8cZ\x1f\xda\x9fmJ\x3cIw\xccU\x11\x06\x3a\xb3\x7e\x9a\x14\xe4\x25\xc8\xe1v\x86\x1e\x3d\xe96\x1c\xa1\xd2\xb5P\xa2\xb8\x98H\xc7\x29f\x8b\x9e\xa5\xa6\xa7\xae\xc1\xe6\x2a\x85\x0b\xb4\x94\xaa\x03\x97z\xab7\x1dc\x165\xc6\xd6k\x84\x2eh\x3f\xb2\xce\x99\x19MB\xf7\x80\xd5\x0a\x17\x09\xdf\xadr4\xf2\xc0\x9d\x8f\x9c\xca\x26\xa8dY\x8d\x0d\xd1\xedg\x3ex\x22\x3b\xc9\xd9q\x90C\x89o\xf4\x2c\x0f\xa3\xf5\x12\xeb\x9b\x21\x7c\xb9\xde\xf1";
    // bytes 0x02-0xf7 (the tr range \x02-\xf7)
    private static ?string $plain = null;

    //------------------------------------------------------------------------------
    // Read HiddenData from JPEG trailer
    // Inputs: 0) ExifTool ref, 1) HiddenDataOffset (abs), 2) HiddenDataLength
    // Returns: HiddenData reference, or undef on error
    // --> updates $hdOff upon return if it was incorrect
    public static function ReadHiddenData(ExifTool $et, &$hdOff, $hdLen)
    {
        $raf = $et->RAF;
        $buff = $pos = null;
        $hdLen = Perl::num($hdLen);
        if (!($raf->Seek(Perl::num($hdOff), 0) and $raf->Read($buff, $hdLen) == $hdLen and
            preg_match('/^\x55\x26\x11\x05\0/', $buff)))
        {
            // search the first 4096 bytes of the trailer to find the HiddenData
            if (!($et->TrailerStart and $raf->Seek(Perl::num($et->TrailerStart), 0) and
                $raf->Read($buff, 4096) and preg_match('/\x55\x26\x11\x05\0/', $buff, $m, PREG_OFFSET_CAPTURE) and
                ($pos = Perl::num($et->TrailerStart) + $m[0][1]) and $raf->Seek($pos, 0) and   // (pos($buff)-5)
                $raf->Read($buff, $hdLen) == $hdLen))
            {
                $et->Warn('Error reading HiddenData', 1);
                return null;
            }
            $hdOff = $pos;  // return fixed offset
            if ($et->Options('Validate') or $et->IsWriting) $et->Warn('Fixed incorrect HiddenDataOffset', 1);
        }
        return new ScalarRef($buff);
    }

    //------------------------------------------------------------------------------
    // Process "SONY PIC\0" maker notes (DSC-H200/J10/W370/W510, MHS-TS20, ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1
    public static function ProcessSonyPIC(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $len = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $start);
        $data = substr($dataPt, $start, $len);

        // H200 panorama images have an IFD at offset 12 (non-panoramas have 0's here)
        // - assume other images could too, but do a bit of validation to check
        // - MHS-TS20 images have some other data here
        if ($len >= 26) {
            $count = ExifTool::Get16u($dataPt, $start + 12);
            if ($count > 256) {
                ExifTool::ToggleByteOrder();
                $count = ExifTool::Get16u($dataPt, $start + 12);
            }
            if ($count and $count < 256) {
                $format = ExifTool::Get16u($dataPt, $start + 16);
                if ($format >= 1 and $format <= 10) {
                    $dirInfo['DirStart'] = $start + 12;
                    $dirInfo['DirLen'] = $len - 12;
                    $sonyTable = ExifTool::GetTagTable('Image::ExifTool::Sony::Main');
                    Exif::ProcessExif($et, $dirInfo, $sonyTable);
                }
            }
        }
        // Do a brute force search for text data:
        // For the DSC-J10/W370/W510 the first text block is at offset 0x1ec and
        // starts with "BarCode:".  For the H200 it is at 0x1f0 and starts with "BC:".
        // For the TS20 it is at 0x5b and starts with "V400 AELOG\nbarcode:".
        // The second text block starts with "AFLOG" (Auto-Focus log) and is at
        // 0x600 for all models, except for the TS20 it is at 0x45b.
        $i = 0;
        preg_match_all('/(\w[\x09\x0a\x0d\x20-\x7e]+)/s', $data, $matches);
        foreach ($matches[1] as $text) {
            if (!(strlen($text) > 32)) continue;
            [$tag, $val] = ['TextInfo' . (++$i), $text];
            if (!$tagTablePtr[$tag]) ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $tag, 'Binary' => 1]);
            $et->HandleTag($tagTablePtr, $tag, $val);
            // extract interesting tags separately (might want to speed this up)
            $keys = $tagTablePtr->keys();
            usort($keys, static fn ($a, $b) => strcmp(strtolower((string) $a), strtolower((string) $b)));
            foreach ($keys as $tag) {
                if (!(preg_match('/:$/', (string) $tag) and preg_match("/\\b$tag\\s*([^\\s;,:]+)/", $val, $m))) continue;
                $et->HandleTag($tagTablePtr, $tag, $m[1]);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // MeterInfo value conversions
    // Inputs: 0) value
    // Returns: converted value
    public static function ConvMeter1($val)
    {
        if (strlen(Perl::str($val)) != 90) return new ScalarRef($val);
        $a = Perl::unpack('SLLSLLSLLSLLSLLSLLSLLSLLSLL', $val);
        return implode(' ', $a);
    }

    public static function ConvMeter2($val)
    {
        if (strlen(Perl::str($val)) != 110) return new ScalarRef($val);
        $a = Perl::unpack('SLLSLLSLLSLLSLLSLLSLLSLLSLLSLLSLL', $val);
        return implode(' ', $a);
    }

    //------------------------------------------------------------------------------
    // LensSpec value conversions
    // Inputs: 0) value
    // Returns: converted value
    // Notes: unpacks in format compatible with LensInfo, with extra flags bytes at start and end
    public static function ConvLensSpec($val)
    {
        if (strlen(Perl::str($val)) != 8) return new ScalarRef($val);
        $a = Perl::unpack('H2H4H4H2H2H2', $val);
        $a[1] = Perl::num($a[1]);  $a[2] = Perl::num($a[2]);  // remove leading zeros from focal lengths
        foreach ([3, 4] as $i) {    // convert hex digits (ie. "b0" = f11)
            $a[$i] = preg_replace_callback('/([a-f])/', static fn ($m) => (string) hexdec($m[1]), $a[$i], 1);
        }
        $a[3] = Perl::num($a[3]) / 10; $a[4] = Perl::num($a[4]) / 10;   // divide f-numbers by 10
        return implode(' ', array_map(Perl::str(...), $a));
    }
    public static function ConvInvLensSpec($val)
    {
        $a = Perl::split(' ', $val);
        if (count($a) != 6) return $val;
        // f-numbers are multiplied by 10
        $a[3] = Perl::numw($a[3], 'multiplication (*)') * 10; $a[4] = Perl::numw($a[4], 'multiplication (*)') * 10;
        foreach ([3, 4] as $i) {
            $a[$i] = preg_replace_callback('/^(\d{2})0$/', static fn ($m) => Perl::sprintf('%x0', $m[1]), Perl::str($a[$i]));
        }
        foreach ($a as &$_) $_ = Perl::hexw($_);    // convert from hex
        unset($_);
        return Perl::packw('CnnCCC', ...$a);
    }

    //------------------------------------------------------------------------------
    // Print Sony LensSpec value
    // Inputs: 0) LensSpec numerical value
    // Returns: converted LensSpec string (eg. "DT 18-55mm F3.5-5.6 SAM")
    // Refs: http://equational.org/importphotos/alphalensinfo.html
    //       http://www.dyxum.com/dforum/the-lens-information-different-from-lensid_topic37682.html
    // (@lensFeatures: lens features in the order they are added to the LensSpec string, in
    //  Vars\Sony::$lensFeatures: [ Mask, { Bits => Name }, Prefix flag ], where the high byte of
    //  Mask/Bits represents byte 0 of LensSpec and the low byte is byte 7)
    public static function PrintLensSpec($val)
    {
        $val = Perl::str($val);
        $rtnVal = $f1 = $sf = $lf = $sa = $la = $f2 = null;
        // 0=flags1, 1=short focal, 2=long focal, 3=max aperture at short focal,
        // 4=max aperture at long focal, 5=flags2
        $a = Perl::split(' ', $val);
        if (count($a) == 2) {   // LensSpecFeatures patch
            [$f1, $f2] = $a;
            $rtnVal = '';
        } elseif (count($a) >= 6) {
            [$f1, $sf, $lf, $sa, $la, $f2] = $a;
            // crude validation of focal length and aperture values
            if (Perl::num($sf) != 0 and Perl::num($sa) != 0 and (Perl::num($lf) == 0 or Perl::num($lf) >= Perl::num($sf)) and
                (Perl::num($la) == 0 or Perl::num($la) >= Perl::num($sa)))
            {
                // use focal and aperture range if this is a zoom lens
                if (Perl::num($lf) != Perl::num($sf) and Perl::num($lf) != 0) $sf .= '-' . $lf;
                if (Perl::num($sa) != Perl::num($la) and Perl::num($la) != 0) $sa .= '-' . $la;
                $rtnVal = "{$sf}mm F$sa";   // heart of LensSpec is a LensInfo string
            }
        }
        if ($rtnVal !== null) {
            // loop through available lens features
            $flags = Perl::hex($f1 . $f2);
            foreach (Vars\Sony::$lensFeatures as $feature) {
                $bits = $feature[0] & $flags;
                if (!($bits or !empty($feature[1][$bits]))) continue;
                // add feature name as a prefix or suffix to the LensSpec
                $str = ($feature[1][$bits] ?? null) ?: Perl::sprintf('Unknown(%.4x)', $bits);
                $rtnVal = $rtnVal ? (!empty($feature[2]) ? "$str $rtnVal" : "$rtnVal $str") : $str;
            }
        } else {
            $rtnVal = "Unknown ($val)";
        }
        return $rtnVal;
    }
    // inverse conversion
    public static function PrintInvLensSpec($val, $self = null, $features = null)
    {
        $val = Perl::str($val);
        if (preg_match('/Unknown \((.*)\)/i', $val, $m)) return $m[1];
        [$sf, $lf, $sa, $la] = Exif::GetLensInfo($val) + [null, null, null, null];
        if ($features) {
            $str = '';
        } elseif ($sf) {
            // fixed focal length and aperture have zero for 2nd number
            if (Perl::num($lf) == Perl::num($sf)) $lf = 0;
            if (Perl::num($la) == Perl::num($sa)) $la = 0;
            $str = " $sf $lf $sa $la";
        } else {
            return null;
        }
        $flags = 0;
        foreach (Vars\Sony::$lensFeatures as $feature) {
            foreach ($feature[1] as $bits => $name) {
                // set corresponding flag bits for each feature name found
                if (preg_match("/\\b$name\\b/i", $val)) $flags |= $bits;
            }
        }
        return Perl::sprintf("%.2x$str %.2x", $flags >> 8, $flags & 0xff);
    }

    //------------------------------------------------------------------------------
    // Read/Write MoreInfo information (tag 0x0020, count 20480)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success when reading, or new directory when writing (IsWriting set)
    public static function ProcessMoreInfo(?ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if (!$et) return 1;     // allow dummy access to write routine
        $dataPt = Perl::str($dirInfo['DataPt']);
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: strlen($dataPt);
        $isWriting = $dirInfo['IsWriting'] ?? null;
        $rtnVal = $isWriting ? null : 0;
        if ($dirLen < 4) return $rtnVal;

        $num = ExifTool::Get16u($dataPt, $start);      // number of entries
        $len = ExifTool::Get16u($dataPt, $start + 2);  // total data length

        if ($dirLen < 4 + $num * 4) {
            $et->Warn('Truncated MoreInfo data', 1);
            return $rtnVal;
        }
        if ($num > 50) {
            $et->Warn('Possibly corrupted MoreInfo data', 1);
            return $rtnVal;
        }

        if (!$isWriting) $et->VerboseDir('MoreInfo', $num, $len);

        if ($len > $dirLen) {
            $et->Warn('MoreInfo data length too large', 1);
            $len = $dirLen;
        }
        // loop through the MoreInfo index section to get the block offsets and tag ID's
        // (in case they are out of order, even though this may never happen)
        $offset = $tagID = $blockSize = [];
        for ($i = 0; $i < $num; ++$i) {
            $entry = $start + 4 + $i * 4;
            $tagID[] = ExifTool::Get16u($dataPt, $entry);
            $offset[] = ExifTool::Get16u($dataPt, $entry + 2);
            if ($offset[$i] > $len and $offset[$i] <= $dirLen) {
                $et->Warn('MoreInfo data length too small', 1);
                $len = $dirLen;
            }
        }
        // generate a lookup table of block sizes
        $sorted = $offset;
        sort($sorted);
        $sorted[] = 0xffff;     // (simplifies logic in loop below)
        for ($i = 0; $i < $num; ++$i) {
            $off = $sorted[$i];     // (Perl: my $offset)
            $size = $sorted[$i + 1] - $off;
            // note that block size will be negative for blocks with starting
            // offsets greater than $dirLen, but we will ignore these below
            if ($size > $len - $off) $size = $len - $off;
            // (if blockSize is already defined for this offset, then there
            //  are 2 blocks with the same starting offset and the existing
            //  size must be zero.  Since we can't know which block is
            //  actually non-zero size, the reasonable thing to do is
            //  assume that both have a size of zero)
            if (!isset($blockSize[$off])) $blockSize[$off] = $size;
        }
        // initialize successful return value
        $rtnVal = $isWriting ? substr($dataPt, $start, $dirLen) : 1;
        // now process each block
        $unknown = $et->OPTIONS['Unknown'];
        for ($i = 0; $i < $num; ++$i) {
            if ($offset[$i] > $dirLen) continue;    // ignore bad offsets
            $tag = $tagID[$i];
            if ($isWriting) {
                // write new tags
                $tagInfo = $tagTablePtr[$tag];
                if (!($tagInfo instanceof TagInfo and $tagInfo->SubDirectory)) continue;
                $off = $offset[$i];     // (Perl: my $offset)
                $size = $blockSize[$off];
                if (!$size) continue;   // ignore zero-length blocks
                $subdirInfo = [
                    'DirName'  => $tagInfo->Name,
                    'Parent'   => $dirInfo['DirName'] ?? null,
                    'DataPt'   => $rtnVal,
                    'DirStart' => $off,
                    'DirLen'   => $size,
                ];
                $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                $val = $et->WriteDirectory($subdirInfo, $subTable);
                // update this block in the returned MoreInfo data
                if ($val !== null) $rtnVal = substr_replace($rtnVal, $val, $off, $size);
                continue;
            }
            // generate binary tables for unknown tags if -U option used
            if (!isset($tagTablePtr[$tag]) and $unknown > 1) {
                $name = Perl::sprintf('MoreInfo%.4x', $tag);
                $table = "Image::ExifTool::Sony::$name";
                // (Perl: %$table = (...), set up by GetTagTable() when the subdirectory is
                //  processed; GetTagTable() only knows the generated tables, so do it here)
                $newTable = new TagTable([
                    'PROCESS_PROC' => 'Image\ExifTool\ExifTool::ProcessBinaryData',
                    'FIRST_ENTRY' => 0,
                    'GROUPS' => [0 => 'MakerNotes', 1 => 'Sony', 2 => 'Image'],
                ]);
                $newTable['TABLE_NAME'] = $table;
                $newTable['SHORT_NAME'] = "Sony::$name";
                $newTable['TAG_PREFIX'] = "Sony_$name";
                ExifTool::SetupTagTable($newTable);
                ExifTool::$tableOrder[] = $table;
                ExifTool::$allTables[$table] = $newTable;
                $tagInfo = [
                    'Name' => $name,
                    'SubDirectory' => ['TagTable' => $table],
                    // tag must not be deleted because new table will be created
                    //  but the old table was already loaded
                    'NeverDelete' => 1,
                ];
                ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            $et->HandleTag($tagTablePtr, $tag, null,
                Index: $i,
                DataPt: $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Start: $start + $offset[$i],
                Size: $blockSize[$offset[$i]],
            );
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Read Sony DSC-F1 PMP file
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref
    // Returns: 1 on success when reading, 0 if this isn't a valid PMP file
    public static function ProcessPMP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        if ($raf->Read($buff, 128) != 128) return 0;
        // validate header length (124 bytes)
        if (!preg_match('/^.{8}\0{3}\x7c.{112}\xff\xd8\xff\xdb$/s', $buff)) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $et->FoundTag('Make', 'Sony');
        $et->FoundTag('Model', 'DSC-F1');
        // extract information from 124-byte header
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Sony::PMP');
        $pmpDirInfo = ['DataPt' => $buff, 'DirName' => 'PMP'];   // (Perl: my %dirInfo)
        $et->ProcessDirectory($pmpDirInfo, $tagTablePtr);
        // process JPEG image
        $raf->Seek(124, 0);
        $dirInfo['Base'] = 124;
        $et->ProcessJPEG($dirInfo);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Set the ARW file type and decide between SubIFD and A100DataOffset
    // Inputs: 0) ExifTool object ref, 1) reference to tag 0x14a raw data
    // Returns: true if tag 0x14a is a SubIFD, false otherwise
    public static function SetARW(ExifTool $et, $valPt)
    {
        // assume ARW for now -- SR2's get identified when FileFormat is parsed
        $et->OverrideFileType($et->TIFF_TYPE = 'ARW');

        // this should always be a SubIFD for models other than the A100
        if (!(Perl::str($et->Model) === 'DSLR-A100' and strlen(Perl::str($valPt)) == 4)) return 1;

        // for the A100, IFD0 tag 0x14a is either a pointer to the raw data if this is
        // an original image, or a SubIFD offset if the image was edited by Sony IDC,
        // so assume it points to the raw data if it isn't a valid IFD (this assumption
        // will be checked later when we try to parse the SR2Private directory)
        $subdir = [
            'DirStart' => ExifTool::Get32u($valPt, 0),
            'Base'     => 0,
            'RAF'      => $et->RAF,
            'AllowOutOfOrderTags' => 1, // doh!
        ];
        return Exif::ValidateIFD($subdir);
    }

    //------------------------------------------------------------------------------
    // Finish writing ARW image, patching necessary Sony quirks, etc
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) EXIF data ref, 3) image data reference
    // Returns: undef on success, error string otherwise
    // Notes: (it turns that all of this is for the A100 only)
    public static function FinishARW(ExifTool $et, array &$dirInfo, &$dataPt, $imageData)
    {
        // pre-scan IFD0 to get IFD entry offsets for each tag
        $dataLen = strlen($dataPt);
        if ($dataLen < 2) return 'Truncated IFD0';
        $n = ExifTool::Get16u($dataPt, 0);
        if ($dataLen < 2 + 12 * $n) return 'Truncated IFD0';
        $entry = [];
        $pad = $dataOffset = null;
        for ($i = 0; $i < $n; ++$i) {
            $e = 2 + $i * 12;
            $entry[ExifTool::Get16u($dataPt, $e)] = $e;
        }
        // fix up SR2Private offset and A100DataOffset (A100 only)
        if (($entry[0xc634] ?? null) and $et->MRWDirData) {
            if ($et->Model !== 'DSLR-A100') return 'Unexpected MRW block';
            if (!(($entry[0x14a] ?? null) and $et->A100DataOffset)) return 'Missing A100DataOffset';
            // account for total length of image data
            $totalLen = 8 + $dataLen;
            if (is_array($imageData)) {
                foreach ($imageData as $dataBlock) {
                    [$pos, $size, $blockPad] = $dataBlock;
                    $totalLen += $size + $blockPad;
                }
            }
            // align MRW block on an even 4-byte boundary
            $remain = $totalLen & 0x03;
            if ($remain) {
                $pad = 4 - $remain;
                $totalLen += $pad;
            }
            // set offset for the MRW directory data
            ExifTool::Set32u($totalLen, $dataPt, $entry[0xc634] + 8);
            // also pad MRWDirData data to an even 4 bytes (just to be safe)
            $remain = strlen($et->MRWDirData) & 0x03;
            if ($remain) $et->MRWDirData .= str_repeat("\0", 4 - $remain);
            $totalLen += strlen($et->MRWDirData);
            // fix up A100DataOffset
            $dataOffset = $et->A100DataOffset;
            ExifTool::Set32u($totalLen, $dataPt, $entry[0x14a] + 8);
        }
        // patch double-referenced and incorrectly-sized A100 PreviewImage
        if (($entry[0x201] ?? null) and $et->A100PreviewStart and
            ($entry[0x202] ?? null) and $et->A100PreviewLength)
        {
            ExifTool::Set32u($et->A100PreviewStart, $dataPt, $entry[0x201] + 8);
            ExifTool::Set32u($et->A100PreviewLength, $dataPt, $entry[0x202] + 8);
        }
        // write TIFF IFD structure
        $outfile = $dirInfo['OutFile'];
        $header = ExifTool::GetByteOrder() . ExifTool::Set16u(0x2a) . ExifTool::Set32u(8);
        if (!ExifTool::Write($outfile, $header, $dataPt)) return 'Error writing';
        // copy over image data
        if (is_array($imageData)) {
            if (!$et->CopyImageData($imageData, $outfile)) return 'Error copying image data';
        }
        // write MRW data if necessary
        if ($et->MRWDirData) {
            if ($pad) ExifTool::Write($outfile, str_repeat("\0", $pad));   // write padding if necessary
            ExifTool::Write($outfile, $et->MRWDirData);
            unset($et->MRWDirData);
            // set TIFF_END to copy over the MRW image data
            if ($dataOffset) $et->TIFF_END = $dataOffset;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Decrypt/Encrypt Sony data (ref 1) (reversible encryption)
    // Inputs: 0) data reference, 1) start offset, 2) data length, 3) decryption key
    // Returns: nothing (original data buffer is updated with decrypted data)
    // Notes: data length should be a multiple of 4
    public static function Decrypt(&$dataPt, $start, $len, $key): void
    {
        $pad = [];
        $words = Perl::int(Perl::num($len) / 4);
        $key = Perl::int($key);

        for ($i = 0; $i < 4; ++$i) {
            $lo = ($key & 0xffff) * 0x0edd + 1;
            $hi = ($key >> 16) * 0x0edd + ($key & 0xffff) * 0x02e9 + ($lo >> 16);
            $pad[$i] = $key = (($hi & 0xffff) << 16) + ($lo & 0xffff);
        }
        $pad[3] = ($pad[3] << 1 | ($pad[0] ^ $pad[2]) >> 31) & 0xffffffff;
        for ($i = 4; $i < 0x7f; ++$i) {
            $pad[$i] = (($pad[$i - 4] ^ $pad[$i - 2]) << 1 |
                        ($pad[$i - 3] ^ $pad[$i - 1]) >> 31) & 0xffffffff;
        }
        $data = Perl::unpack("x$start N$words", $dataPt);
        for ($i = 0x7f, $j = 0; $j < $words; ++$i, ++$j) {
            // (words past the end of the data autovivify, so the data may grow, as in Perl)
            $data[$j] = ($data[$j] ?? 0) ^ ($pad[$i & 0x7f] = $pad[($i + 1) & 0x7f] ^ $pad[($i + 65) & 0x7f]);
        }
        $dataPt = substr_replace($dataPt, Perl::pack('N*', ...$data), $start, $words * 4);
    }

    //------------------------------------------------------------------------------
    // Decipher/encipher Sony tag 0x2010, 0x900b, 0x9050 and 0x940x data (ref PH)
    // Inputs: 0) data reference, 1) true to encipher the data
    public static function Decipher(&$dataPt, $encipher = null): void
    {
        // This is a simple substitution cipher, so use a hardcoded translation table for speed.
        // The formula is: $c = ($b*$b*$b) % 249, where $c is the enciphered data byte
        // (note that bytes with values 249-255 are not translated, and 0-1, 82-84,
        //  165-167 and 248 have the same enciphered value)
        self::$plain ??= implode('', array_map('chr', range(0x02, 0xf7)));
        if ($encipher) {    // encipher
            $dataPt = strtr(Perl::str($dataPt), self::$plain, self::CIPHER);
        } else {            // decipher
            $dataPt = strtr(Perl::str($dataPt), self::CIPHER, self::$plain);
        }
    }

    //------------------------------------------------------------------------------
    // Process Sony 0x94xx cipherdata directory
    // Inputs: 0) ExifTool object ref, 1) directory information ref, 2) tag table ref
    // Returns: 1 on success
    // Notes:
    // 1) dirInfo may contain VarFormatData (reference to empty list) to return
    //    details about any variable-length-format tags in the table (used when writing)
    // 2) A bug in ExifTool 9.04-9.10 could have double-enciphered these blocks
    public static function ProcessEnciphered(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        $data = substr($dataPt, $dirStart, $dirLen);
        self::Decipher($data);
        if ($et->DoubleCipher) {
            self::Decipher($data);
            $et->Warn('Some Sony metadata is double-enciphered. Write any tag to fix', 1);
        }
        // (built after deciphering: Perl's DataPt is a reference to $data)
        $subdirInfo = [
            ...$dirInfo,
            'DataPt' => $data,
            'DataPos' => ($dirInfo['DataPos'] ?? 0) + $dirStart,
            'DirStart' => 0,
        ];
        if ($et->Options('Verbose') > 2) {
            $tagInfo = ($dirInfo['TagInfo'] ?? null) ?: new TagInfo(['Name' => 'data']);
            $str = $et->DoubleCipher ? 'ouble-d' : '';
            $et->VerboseDir("D{$str}eciphered {$tagInfo->Name}");
            $et->VerboseDump($data,
                Prefix: $et->INDENT . '  ',
                DataPos: ($dirInfo['DirStart'] ?? 0) + ($dirInfo['DataPos'] ?? 0) + (($dirInfo['Base'] ?? null) ?: 0),
            );
        }
        return $et->ProcessBinaryData($subdirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Write Sony 0x94xx cipherdata directory
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: cipherdata block or undefined on error
    public static function WriteEnciphered(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;
        $dataPt = Perl::str($dirInfo['DataPt']);
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        $data = substr($dataPt, $dirStart, $dirLen);
        $changed = $et->CHANGED;
        self::Decipher($data);
        // fix double-enciphered data (due to bug in ExifTool 9.04-9.10)
        if ($et->DoubleCipher) {
            self::Decipher($data);
            ++$et->CHANGED;
            $et->Warn('Fixed double-enciphered Sony metadata', 1);
        }
        $subdirInfo = [
            ...$dirInfo,
            'DataPt' => $data,
            'DataPos' => ($dirInfo['DataPos'] ?? 0) + $dirStart,
            'DirStart' => 0,
        ];
        $data = ExifTool::WriteBinaryData($et, $subdirInfo, $tagTablePtr);
        if ($changed == $et->CHANGED) {
            // nothing changed, so recover original data
            $data = substr($dataPt, $dirStart, $dirLen);
        } elseif ($data !== null) {
            self::Decipher($data, 1);     // re-encipher
        }
        return $data;
    }

    //------------------------------------------------------------------------------
    // Process "rtmd" timed metadata embedded in Sony MP4 videos (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_rtmd(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $dataPos = (($dirInfo['DataPos'] ?? null) ?: 0) + (($dirInfo['Base'] ?? null) ?: 0);
        $end = strlen($dataPt);
        if ($end < 2) return 0;
        $et->VerboseDir('Sony rtmd', null, $end);
        // Note:  The 0x1c-byte header contains some as-yet unextracted information:
        //  offset 0x0e: int8u some minutes
        //         0x0f: int8u some seconds
        //         0x11: int8u frame number? (0-24 or 0-25)
        $pos = ExifTool::Get16u($dataPt, 0);    // get header length (= 0x1c)
        while ($pos + 4 < $end) {
            $tag = ExifTool::Get16u($dataPt, $pos);
            if ($tag == 0) break;
            $len = ExifTool::Get16u($dataPt, $pos + 2);
            if ($tag == 0x060e) {
                $len = 0x10;    // (unknown 16 bytes starting with 0x060e2b340253)
            } else {
                $pos += 4;      // skip tag id/size
                if ($tag == 0x8300) continue;   // descend into contents of 0x8300 (container)
            }
            if ($pos + $len > $end) break;
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $pos,
                Size: $len,
            );
            $pos += $len;       // step to next tag
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process SRF maker notes
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // (PHP: returns null, like Perl whose sub ends with the loop)
    public static function ProcessSRF(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $start = $dirInfo['DirStart'] ?? null;
        $verbose = $et->Options('Verbose');

        // process IFD chain
        $success = null;
        for ($ifd = 0; ;) {
            // switch tag table for SRF2-5 and SRF6
            if ($ifd == 2) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Sony::SRF2');
            } elseif ($ifd == 6) {
                // SRF6 uses standard EXIF tags
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
            }
            $srf = $dirInfo['DirName'] = "SRF$ifd";
            $et->SET_GROUP1 = $srf;
            $success = Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
            $et->SET_GROUP1 = null;
            if (!$success) break;
//
// get pointer to next IFD
//
            $count = ExifTool::Get16u($dataPt, $dirInfo['DirStart']);
            $dirEnd = $dirInfo['DirStart'] + 2 + $count * 12;
            if ($dirEnd + 4 > strlen($dataPt)) break;
            $nextIFD = ExifTool::Get32u($dataPt, $dirEnd);
            if (!$nextIFD) break;
            $nextIFD -= (($dirInfo['DataPos'] ?? null) ?: 0);  // adjust for position of makernotes data
            $dirInfo['DirStart'] = $nextIFD;
//
// decrypt next IFD data if necessary
//
            ++$ifd;
            $key = $len = null;
            if ($ifd == 1) {
                // get the key to decrypt IFD1
                $cp = $start + 0x8ddc;   // why?
                if ($cp + 1 > strlen($dataPt)) break;
                $ip = $cp + 4 * Perl::unpack("x$cp C", $dataPt)[0];
                if ($ip + 4 > strlen($dataPt)) break;
                $key = Perl::unpack("x$ip N", $dataPt)[0];
                $len = $cp + $nextIFD;  // decrypt up to $cp
            } elseif ($ifd == 2) {
                // get the key to decrypt IFD2
                $key = $et->SRF2Key;
                $len = strlen($dataPt) - $nextIFD;  // decrypt rest of maker notes
            } else {
                continue;   // no decryption needed
            }
            // decrypt data
            if ($key !== null) {
                // (Perl decrypts the caller's buffer in place: update DataPt unless
                //  ProcessExif replaced it by data read from the file)
                $same = Perl::str($dirInfo['DataPt']) === $dataPt;
                self::Decrypt($dataPt, $nextIFD, $len, $key);
                if ($same) $dirInfo['DataPt'] = $dataPt;
            }
            if (!($verbose > 2)) continue;
            // display decrypted data in verbose mode
            $et->VerboseDir("Decrypted SRF$ifd", 0, $nextIFD + $len);
            $et->VerboseDump($dataPt,
                Prefix: "{$et->INDENT}  ",
                Start: $nextIFD,
                DataPos: $dirInfo['DataPos'] ?? null,
            );
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Write SR2 IFD and its encrypted subdirectories
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: SR2 directory or undef on error
    public static function WriteSR2(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;      // allow dummy access
        $buff = new ScalarRef('');
        $dirInfo['OutFile'] = $buff;
        return self::ProcessSR2($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Read/Write SR2 IFD and its encrypted subdirectories
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success when reading, or SR2 directory or undef when writing
    public static function ProcessSR2(?ExifTool $et, ?array &$dirInfo, ?TagTable $tagTablePtr)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $dataPt = $dirInfo['DataPt'] ?? null;
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $dataLen = ($dirInfo['DataLen'] ?? null) ?: strlen(Perl::str($dataPt));
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $outfile = $dirInfo['OutFile'] ?? null;

        // clear SR2 member variables to be safe
        unset($et->SR2SubIFDOffset, $et->SR2SubIFDLength, $et->SR2SubIFDKey);

        // make sure we have the first 4 bytes available to test directory type
        $buff = null;
        if ($dataLen < 4 and $raf) {
            $pos = $dataPos + (($dirInfo['DirStart'] ?? null) ?: 0) + $base;
            if ($raf->Seek($pos, 0) and $raf->Read($buff, 4) == 4) {
                $dataPt = $buff;
                $dirInfo['DataPt'] = null;  // must load data from file
                $raf->Seek($pos, 0);
            }
        }
        // this may either be a normal IFD, or a MRW data block
        // (only original ARW images from the A100 use the MRW block)
        if ($dataPt !== null and preg_match('/^\0MR[IM]/', $dataPt)) {
            $err = $srfPos = $srfLen = null;
            $dataOffset = $et->A100DataOffset;
            if ($dataOffset) {
                // save information about the RAW data trailer so it will be preserved
                $et->KnownTrailer = ['Name' => 'A100 RAW Data', 'Start' => $dataOffset];
            } else {
                $err = 'A100DataOffset tag is missing from A100 ARW image';
            }
            if (!$raf) $err = 'Unrecognized SR2 structure';
            if (!$err) {
                $srfPos = $raf->Tell();
                $srfLen = $dataOffset - $srfPos;
                if (!($srfLen > 0 and $raf->Read($buff, $srfLen) == $srfLen)) {
                    $err = 'Error reading MRW directory';
                }
            }
            if ($err) {
                if ($outfile) {
                    $et->Error($err);
                    return null;
                }
                $et->Warn($err);
                return 0;
            }
            $mrwDirInfo = ['DataPt' => $buff];  // (Perl: my %dirInfo)
            ExifTool::RequireModule('Image::ExifTool::MinoltaRaw');
            if ($outfile) {
                // save MRW data to be written last
                $et->MRWDirData = MinoltaRaw::WriteMRW($et, $mrwDirInfo);
                return $et->MRWDirData ? "\0\0\0\0\0\0" : null;
            } else {
                if ($et->HTML_DUMP) {
                    $et->HDump($srfPos, $srfLen, '[A100 SRF Data]');
                }
                return MinoltaRaw::ProcessMRW($et, $mrwDirInfo);
            }
        } elseif ($et->A100DataOffset) {
            $err = 'Unexpected A100DataOffset tag';
            if ($outfile) {
                $et->Error($err);
                return null;
            }
            $et->Warn($err);
            return 0;
        }
        $verbose = $et->Options('Verbose');
        if ($outfile) {
            $result = Exif::WriteExif($et, $dirInfo, $tagTablePtr);
            if (!$result) return null;
            $outfile->value .= $result;
        } else {
            $result = Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
        }
        if (!($result and $et->SR2SubIFDOffset)) return $result;
        // only take first offset value if more than one!
        $offsets = Perl::split(' ', $et->SR2SubIFDOffset);
        $offset = Perl::num(array_shift($offsets));
        $length = Perl::num($et->SR2SubIFDLength);
        $key = $et->SR2SubIFDKey;
        $subifdPos = [];
        if ($offset and $length and $key !== null) {
            $buff = null;
            // read encrypted SR2SubIFD from file
            if (($raf and $raf->Seek($offset + $base, 0) and
                    $raf->Read($buff, $length) == $length) or
                // or read from data (when processing Adobe DNGPrivateData)
                ($offset - $dataPos >= 0 and $offset - $dataPos + $length < $dataLen and
                    ($buff = substr(Perl::str($dataPt), $offset - $dataPos, $length))))
            {
                self::Decrypt($buff, 0, $length, $key);
                // display decrypted data in verbose mode
                if ($verbose > 2 and !$outfile) {
                    $et->VerboseDir('Decrypted SR2SubIFD', 0, $length);
                    $et->VerboseDump($buff, Addr: $offset + $base);
                }
                $num = '';
                $dPos = $offset;
                for (;;) {
                    $subdirInfo = [
                        'Base' => $base,
                        'DataPt' => $buff,
                        'DataLen' => strlen($buff),
                        'DirStart' => $offset - $dPos,
                        'DirName' => "SR2SubIFD$num",
                        'DataPos' => $dPos,
                    ];
                    $subTable = ExifTool::GetTagTable('Image::ExifTool::Sony::SR2SubIFD');
                    if ($outfile) {
                        $fixup = new Fixup();
                        $subdirInfo['Fixup'] = $fixup;
                        $result = $et->WriteDirectory($subdirInfo, $subTable);
                        if (!$result) return null;
                        // save position of this SubIFD
                        $subifdPos[] = strlen($outfile->value);
                        // add this directory to the returned data
                        $fixup->Start += strlen($outfile->value);
                        $outfile->value .= $result;
                        $dirInfo['Fixup']->AddFixup($fixup);
                    } else {
                        $result = $et->ProcessDirectory($subdirInfo, $subTable);
                    }
                    if (!$offsets) break;
                    $offset = Perl::num(array_shift($offsets));
                    $num = ($num ?: 1) + 1;
                }

            } else {
                $et->Warn('Error reading SR2 data');
            }
        }
        if ($outfile and $subifdPos) {
            // the SR2SubIFD must be padded to a multiple of 4 bytes for the encryption
            $sr2Len = strlen($outfile->value) - $subifdPos[0];
            if ($sr2Len & 0x03) {
                $pad = 4 - ($sr2Len & 0x03);
                $sr2Len += $pad;
                $outfile->value .= str_repeat(' ', $pad);
            }
            // save the new SR2SubIFD Length and Key to be used later for encryption
            $et->SR2SubIFDLength = $sr2Len;
            $newKey = $et->VALUE['SR2SubIFDKey'] ?? null;
            if ($newKey !== null) $et->SR2SubIFDKey = $newKey;
            // update SubIFD pointers manually and add to fixup, and set SR2SubIFDLength
            $n = ExifTool::Get16u($outfile->value, 0);
            $found = [];
            for ($i = 0; $i < $n; ++$i) {
                $entry = 2 + 12 * $i;
                $tagID = ExifTool::Get16u($outfile->value, $entry);
                // only interested in SR2SubIFDOffset (0x7200) and SR2SubIFDLength (0x7201)
                if ($tagID != 0x7200 and $tagID != 0x7201) continue;
                $found[$tagID] = 1;
                $fmt = ExifTool::Get16u($outfile->value, $entry + 2);
                if ($fmt != 0x04) { // must be int32u
                    $et->Error("Unexpected format ($fmt) for SR2SubIFD tag");
                    return null;
                }
                if ($tagID == 0x7201) { // SR2SubIFDLength
                    ExifTool::Set32u($sr2Len, $outfile->value, $entry + 8);
                    continue;
                }
                $tag = 'SR2SubIFDOffset';
                $valuePtr = count($subifdPos) < 2 ? $entry + 8 : ExifTool::Get32u($outfile->value, $entry + 8);
                foreach ($subifdPos as $pos) {
                    ExifTool::Set32u($pos, $outfile->value, $valuePtr);
                    $dirInfo['Fixup']->AddFixup($valuePtr, $tag);
                    $tag = null;
                    $valuePtr += 4;
                }
            }
            if (!(($found[0x7200] ?? null) and ($found[0x7201] ?? null))) {
                $et->Error('Missing SR2SubIFD tag');
                return null;
            }
        }
        return $outfile ? $outfile->value : $result;
    }
}
