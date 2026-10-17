<?php

//------------------------------------------------------------------------------
// File:         CanonVRD.pm
//
// Description:  Read/write Canon VRD and DR4 information
//
// Revisions:    2006/10/30 - P. Harvey Created
//               2007/10/23 - PH Added new VRD 3.0 tags
//               2008/08/29 - PH Added new VRD 3.4 tags
//               2008/12/02 - PH Added new VRD 3.5 tags
//               2010/06/18 - PH Support variable-length CustomPictureStyle data
//               2010/09/14 - PH Added r/w support for XMP in VRD
//               2015/05/16 - PH Added DR4 support (DPP 4.1.50.0)
//               2018/03/13 - PH Update to DPP 4.8.20
//
// References:   1) Bogdan private communication (Canon DPP v3.4.1.1)
//               2) Gert Kello private communication (DPP 3.8)
//
// PHP port of Image::ExifTool::CanonVRD 1.41 (reading only). Tag tables are generated into
// tables/CanonVRD.php, the file lexicals (%vrdFormat...) into Vars\CanonVRD. Not ported
// (writing): ToneCurvePrintInv, WrapDR4, WriteCanonVRD, WriteCanonDR4 and the OutFile /
// IsWriting branches of ProcessEditData, ProcessDR4, ProcessVRD and ProcessCanonVRD.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class CanonVRD
{
    public static $VERSION = '1.41';

    //------------------------------------------------------------------------------
    // sort DR4 tag ID's for the documentation
    public static function SortDR4($a, $b)
    {
        $aHex = $aDec = $bHex = $bDec = null;
        if (preg_match('/^(0x[0-9a-f]+)?\.?(\d*?)$/', Perl::str($a), $m, PREG_UNMATCHED_AS_NULL)) [$aHex, $aDec] = [$m[1], $m[2]];
        if (preg_match('/^(0x[0-9a-f]+)?\.?(\d*?)$/', Perl::str($b), $m, PREG_UNMATCHED_AS_NULL)) [$bHex, $bDec] = [$m[1], $m[2]];
        if ($aHex) {
            if ($bDec === null) return 1;   // $b is 'header';
            if ($bHex) return (Perl::hex($aHex) <=> Perl::hex($bHex)) ?: (Perl::num($aDec) <=> Perl::num($bDec));
            return (Perl::hex($aHex) <=> Perl::num($bDec)) ?: 1;
        } elseif ($bHex) {
            if ($aDec === null) return -1;
            return (Perl::num($aDec) <=> Perl::hex($bHex)) ?: -1;
        } else {
            if ($bDec === null) return 1;
            if ($aDec === null) return -1;
            return Perl::num($aDec) <=> Perl::num($bDec);
        }
    }

    //------------------------------------------------------------------------------
    // Tone curve print conversion
    public static function ToneCurvePrint($val)
    {
        $vals = Perl::split(' ', $val);
        if (count($vals) != 21) return $val;
        $n = Perl::num(array_shift($vals));
        if (!($n >= 2 and $n <= 10)) return $val;
        $val = '';
        while ($n--) {
            if ($val) $val .= ' ';
            $val .= '(' . array_shift($vals) . ',' . array_shift($vals) . ')';
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Read/Write VRD edit data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: Reading: 1 on success; Writing: modified edit data, or undef if nothing changed
    public static function ProcessEditData(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $pos = $dirInfo['DirStart'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');

        $et->VerboseDir('VRD Edit Data', 0, $dirLen);

        $dirEnd = $pos + $dirLen;

        // loop through all records in the edit data
        $recLen = $err = null;
        for ($recNum = 0; ; ++$recNum, $pos += $recLen) {
            if ($pos + 4 > $dirEnd) {
                if ($pos == $dirEnd) break;     // all done if we arrived at end
                $recLen = 0;    // just reset record size (will exit loop on test below)
            } else {
                $recLen = ExifTool::Get32u($dataPt, $pos);
                // (DR4 has a null terminator)
                if ($recLen == 0 and $pos + 4 == $dirEnd) break;
            }
            $pos += 4;          // move to start of record
            if ($pos + $recLen > $dirEnd) {
                $et->Warn('Possibly corrupt CanonVRD Edit record');
                $err = 1;
                break;
            }
            if ($verbose > 1) {
                fwrite($out, Perl::sprintf("{$et->INDENT}CanonVRD Edit record ($recLen bytes at offset 0x%x)\n",
                       $pos + Perl::num($dataPos)));
                if ($recNum) $et->VerboseDump($dataPt, Len: $recLen, Start: $pos, Addr: $pos + Perl::num($dataPos));
            }

            // our edit information is the 0th record, so don't process the others
            if ($recNum) continue;

            // process VRD edit information
            $subTablePtr = $tagTablePtr;
            $subdirInfo = [
                'DataPt'   => $dataPt,
                'DataPos'  => $dataPos,
                'DirStart' => $pos,
                'DirLen'   => $recLen,
                'OutFile'  => null,
            ];
            $subStart = 0;
            // loop through various sections of the VRD edit data
            for ($index = 0; ; ++$index) {
                $tagInfo = $subTablePtr[$index];
                if (!$tagInfo) break;
                $maxLen = $recLen - $subStart;
                if ($tagInfo->Size) {
                    $subLen = $tagInfo->Size;
                } elseif ($tagInfo->Size !== null) {
                    // get size from int32u at $subStart
                    if (!($subStart + 4 <= $recLen)) break;
                    $subLen = ExifTool::Get32u($dataPt, $subStart + $pos);
                    $subStart += 4; // skip the length word
                } else {
                    $subLen = $maxLen;
                }
                if ($subLen > $maxLen) $subLen = $maxLen;
                if ($subLen) {
                    $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                    $subName = $tagInfo->Name;
                    $subdirInfo['DirStart'] = $subStart + $pos;
                    $subdirInfo['DirLen'] = $subLen;
                    $subdirInfo['DirName'] = $subName;
                    $et->VPrint(0, "{$et->INDENT}$subName (SubDirectory) -->\n");
                    $et->VerboseDump($dataPt,
                        Start: $pos + $subStart,
                        Addr: Perl::num($dataPos) + $pos + $subStart,
                        Len: $subLen,
                    );
                    // extract tags from this section of the VRD edit information
                    $et->ProcessDirectory($subdirInfo, $subTable);
                }
                // next section starts at the end of this one
                $subStart += $subLen;
            }
        }
        return $err ? 0 : 1;
    }

    //------------------------------------------------------------------------------
    // Process VRD IHL data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessIHL(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dirEnd = $pos + $dirLen;

        $et->VerboseDir('VRD IHL', 0, $dirLen);

        ExifTool::SetByteOrder('II'); // (make up your mind, Canon!)
        while ($pos + 48 <= $dirEnd) {
            $hdr = substr($dataPt, $pos, 48);
            if (!preg_match('/^IHL Created Optional Item Data\0\0/', $hdr)) {
                $et->Warn('Possibly corrupted VRD IHL data');
                break;
            }
            $tag  = ExifTool::Get32u($dataPt, $pos + 36);
            $size = ExifTool::Get32u($dataPt, $pos + 40); // size of data in IHL record
            $next = ExifTool::Get32u($dataPt, $pos + 44); // size of complete IHL record
            if ($size > $next or $pos + 48 + $next > $dirEnd) {
                $et->Warn(Perl::sprintf('Bad size for VRD IHL tag 0x%.4x', $tag));
                break;
            }
            $pos += 48;
            $et->HandleTag($tagTablePtr, $tag, substr($dataPt, $pos, $size),
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $pos,
                Size: $size,
            );
            $pos += $next;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process VRD IHL EXIF data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessIHLExif(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $et->DOC_NUM = 1;
        // the IHL-edited maker notes may look messed up, but the offsets should be OK
        $oldFix = $et->Options('FixBase', 0);
        $rtnVal = $et->ProcessTIFF($dirInfo, $tagTablePtr);
        $et->Options('FixBase', $oldFix);
        $et->DOC_NUM = null;
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Read/Write DPP version 4 edit data or DR4 file
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns:
    //   Reading from memory (not RAF and not IsWriting): 1 on success
    //   Editing from memory (not RAF and IsWriting): modified edit data, or undef if nothing changed
    //   Reading file (RAF and not OutFile): 1 if a valid DR4 file, 0 if not
    //   Writing file (RAF and OutFile): 1 if valid DR4 file, 0 if not, -1 on write error
    // (serves me right for not having a consistent interface for the various modes of operation)
    public static function ProcessDR4(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        $raf = $dirInfo['RAF'] ?? null;
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $unknown = $et->Options('Unknown');
        $pos = $dirLen = $numEntries = $err = null;

        if ($dataPt !== null) {
            $dataPt = Perl::str($dataPt);
            $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
            $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $pos);
        } else {
            // load DR4 file into memory
            $buff = null;
            if (!($raf->Read($buff, 8) == 8 and preg_match('/^IIII[\x04|\x05]\0\x04\0/', $buff))) return 0;
            $et->SetFileType();
            if (!$raf->Seek(0, 2)) return $err = 1;
            $dirLen = $raf->Tell();
            if (!$raf->Seek(0, 0)) return $err = 1;
            if ($raf->Read($buff, $dirLen) != $dirLen) $err = 1;
            if ($err) {
                $et->Warn('Error reading DR4 file');
                return 1;
            }
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::CanonVRD::DR4');
            $dataPt = $buff;
            $pos = 0;
        }
        $dirEnd = $pos + $dirLen;

        if (($et->TAGS_FROM_FILE and empty($et->EXCL_TAG_LOOKUP['canondr4'])) or
            !empty($et->REQ_TAG_LOOKUP['canondr4']))
        {
            // extract CanonDR4 block if copying tags, or if requested
            $et->FoundTag('CanonDR4', substr($dataPt, $pos, $dirLen));
        }

        // version 4 header is 32 bytes (int32u[8])
        if ($dirLen < 32) {
            $err = 1;
        } else {
            ExifTool::SetByteOrder(substr($dataPt, $pos, 2)) or $err = 1;
            // process the DR4 header
            $hdrInfo = [
                'DataPt'   => $dataPt,
                'DirStart' => $pos,
                'DirLen'   => 32,
                'DirName'  => 'DR4Header',
            ];
            $hdrTable = ExifTool::GetTagTable('Image::ExifTool::CanonVRD::DR4Header');
            $et->VerboseDir('DR4Header', null, 32);
            $et->ProcessDirectory($hdrInfo, $hdrTable);
            // number of entries in the DR4 directory
            $numEntries = ExifTool::Get32u($dataPt, $pos + 28);
            if ($dirLen < 36 + 28 * $numEntries) $err = 1;
        }
        if ($err) {
            $et->Warn('Invalid DR4 directory');
            return 0;
        }

        $et->VerboseDir('DR4', $numEntries, $dirLen);

        for ($index = 0; $index < $numEntries; ++$index) {
            $val = null;
            $flg = [];
            $entry = $pos + 36 + 28 * $index;
            if ($entry + 28 > $dirEnd) break;
            $tag = ExifTool::Get32u($dataPt, $entry);
            $fmt = ExifTool::Get32u($dataPt, $entry + 4);
            $flg[0] = ExifTool::Get32u($dataPt, $entry + 8);
            $flg[1] = ExifTool::Get32u($dataPt, $entry + 12);
            $flg[2] = ExifTool::Get32u($dataPt, $entry + 16);
            $off = ExifTool::Get32u($dataPt, $entry + 20) + $pos;
            $len = ExifTool::Get32u($dataPt, $entry + 24);
            if ($off + $len >= $dirEnd) continue;
            $format = Vars\CanonVRD::$vrdFormat[$fmt] ?? null;
            if (!$format) {
                $val = bin2hex(substr($dataPt, $off, $len));
                $format = 'undef';
            } elseif ($format === 'double' and $len == 8) {
                // avoid teeny weeny values
                $val = ExifTool::ReadValue($dataPt, $off, $format, null, $len);
                if (abs(Perl::num($val)) < 1e-100) $val = 0;
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $off,
                Size: $len,
                Index: $index,
                Format: $format,
                // $flg[0] is on/off flag
                // $flg[1] "is default" flag?
                // $flg[2] changed to 0 when some unsharp mask settings were changed
                Extra: ", fmt=$fmt flags=" . implode(',', $flg),
            );
            foreach ([0, 1, 2] as $i) {
                $flagID = Perl::sprintf('0x%x.%d', $tag, $i);
                if ($tagTablePtr[$flagID]) $et->HandleTag($tagTablePtr, $flagID, $flg[$i]);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read/write Canon VRD file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a Canon VRD file, 0 otherwise, -1 on write error
    public static function ProcessVRD(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        $num = $raf->Read($buff, 0x1c);

        if ($num != 0x1c) return 0;
        if (!preg_match('/^CANON OPTIONAL DATA\0/', $buff)) return 0;
        $et->SetFileType();
        $dirInfo['DirName'] = 'CanonVRD';    // set directory name for verbose output
        $result = self::ProcessCanonVRD($et, $dirInfo);
        if ($result < 0) return $result;
        $result or $et->Warn('Format error in VRD file');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read/write CanonVRD information (from VRD file or VRD trailer)
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 not valid VRD, or -1 error writing
    // - updates DataPos to point to start of CanonVRD information
    // - updates DirLen to existing trailer length
    public static function ProcessCanonVRD(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;
        $dataPt = $dirInfo['DataPt'] ?? null;
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $buff = $err = $blockLen = $blockType = $fromFile = null;
        //
        // The CanonVRD trailer has a 0x1c-byte header and a 0x40-byte footer,
        // each beginning with "CANON OPTIONAL DATA\0" and containing an int32u
        // giving the size of the contained data (at byte 0x18 and 0x14 respectively)
        //
        if ($raf) {
            $fromFile = 1;
        } else {
            if ($dataPt === null) return 1;   // (creating a blank VRD: writing only)
            $dataPt = Perl::str($dataPt);
            $raf = new RandomAccess($dataPt);
        }
        // read and validate the footer
        if (!$raf->Seek(-0x40 - $offset, 2)) return 0;
        if ($raf->Read($buff, 0x40) != 0x40) return 0;
        if (!preg_match('/^CANON OPTIONAL DATA\0(.{4})/s', $buff, $m)) return 0;
        $dirLen = Perl::unpack('N', $m[1])[0] + 0x5c;  // size including header+footer

        // read and validate the header
        if (!($dirLen < 0x80000000 and
              $raf->Seek(-$dirLen, 1) and
              $raf->Read($buff, 0x1c) == 0x1c and
              preg_match('/^CANON OPTIONAL DATA\0/', $buff) and
              $raf->Seek(-0x1c, 1)))
        {
            $et->Warn('Bad CanonVRD trailer');
            return 0;
        }
        // set variables returned in dirInfo hash
        $dirInfo['DataPos'] = $raf->Tell();
        $dirInfo['DirLen'] = $dirLen;

        if ($fromFile or ($dirInfo['DirStart'] ?? null)) {
            if ($dataPt === null) $dataPt = &$buff;
            // read VRD data into memory if necessary
            if ($raf->Read($dataPt, $dirLen) != $dirLen) {
                $et->Warn('Error reading CanonVRD data');
                return 0;
            }
        }
        $vrdType = 'VRD';

        if ($verbose or $et->HTML_DUMP) {
            if ($dirInfo['RAF'] ?? null) $et->DumpTrailer($dirInfo);
        }

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::CanonVRD::Main');

        // validate VRD trailer and get position and length of edit record
        ExifTool::SetByteOrder('MM'); // VRD header/footer is big-endian
        $pos = 0x1c;     // start at end of header

        // loop through the VRD blocks
        for (;;) {
            $end = $dirLen - 0x40;  // end of last VRD block (and start of footer)
            if ($pos + 8 > $end) {
                if ($pos == $end) break;
                $blockLen = $end;   // mark as invalid
            } else {
                $blockType = ExifTool::Get32u($dataPt, $pos);
                $blockLen = ExifTool::Get32u($dataPt, $pos + 4);
            }
            if ($blockType == 0xffff00f7) $vrdType = 'DR4';
            $pos += 8;  // move to start of block
            if ($pos + $blockLen > $end) {
                $et->Warn('Possibly corrupt CanonVRD block');
                break;
            }
            if ($verbose > 1) {
                fwrite($out, Perl::sprintf("  CanonVRD block 0x%.8x ($blockLen bytes at offset 0x%x)\n",
                    $blockType, $pos + $dirInfo['DataPos']));
                $et->VerboseDump($dataPt, Len: $blockLen, Start: $pos, Addr: $pos + $dirInfo['DataPos']);
            }
            $tagInfo = $tagTablePtr[Perl::str($blockType)];
            if (!$tagInfo) {
                if (!$et->Options('Unknown')) {
                    $pos += $blockLen;  // step to next block
                    continue;
                }
                $name = Perl::sprintf('CanonVRD_0x%.8x', $blockType);
                $desc = strtr($name, '_', ' ');
                $tagInfo = new TagInfo([
                    'Name'        => $name,
                    'Description' => $desc,
                    'Binary'      => 1,
                ]);
                ExifTool::AddTagToTable($tagTablePtr, $blockType, $tagInfo);
            }
            if ($tagInfo->SubDirectory) {
                $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                $subdirInfo = [
                    'DataPt'   => $dataPt,
                    'DataLen'  => strlen($dataPt),
                    'DataPos'  => $dirInfo['DataPos'],
                    'DirStart' => $pos,
                    'DirLen'   => $blockLen,
                    'DirName'  => $tagInfo->Name,
                    'Parent'   => 'CanonVRD',
                    'OutFile'  => null,
                ];
                // extract as a block if requested
                $et->ProcessDirectory($subdirInfo, $subTablePtr);
            } else {
                $et->HandleTag($tagTablePtr, $blockType, substr($dataPt, $pos, $blockLen));
            }
            $pos += $blockLen;  // step to next block
        }
        if ($vrdType === 'VRD' and (($et->TAGS_FROM_FILE and empty($et->EXCL_TAG_LOOKUP['canonvrd'])) or
            !empty($et->REQ_TAG_LOOKUP['canonvrd'])))
        {
            // extract CanonVRD block if copying tags, or if requested (and not DR4 info)
            $et->FoundTag('CanonVRD', $buff);
        }
        $buff = null;
        return $err ? -1 : 1;
    }
}
