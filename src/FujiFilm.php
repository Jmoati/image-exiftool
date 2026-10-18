<?php

//------------------------------------------------------------------------------
// File:         FujiFilm.pm
//
// Description:  Read/write FujiFilm maker notes and RAF images
//
// Revisions:    11/25/2003 - P. Harvey Created
//               11/14/2007 - PH Added ability to write RAF images
//
// References:   1) http://park2.wakwak.com/~tsuruzoh/Computer/Digicams/exif-e.html
//               2) http://homepage3.nifty.com/kamisaka/makernote/makernote_fuji.htm (2007/09/11)
//               3) Michael Meissner private communication
//               4) Paul Samuelson private communication (S5)
//               5) http://www.cybercom.net/~dcoffin/dcraw/
//               6) http://forums.dpreview.com/forums/readflat.asp?forum=1012&thread=31350384
//                  and http://forum.photome.de/viewtopic.php?f=2&t=353&p=742#p740
//               7) Kai Lappalainen private communication
//               8) https://exiftool.org/forum/index.php/topic,5223.0.html
//               9) Zilvinas Brobliauskas private communication
//               10) Albert Shan private communication
//               11) https://exiftool.org/forum/index.php/topic,8377.0.html
//               12) https://exiftool.org/forum/index.php/topic,9607.0.html
//               13) https://exiftool.org/forum/index.php/topic=10481.0.html
//               IB) Iliah Borg private communication (LibRaw)
//               JD) Jens Duttke private communication
//
// PHP port of Image::ExifTool::FujiFilm 2.02 (reading only: WriteRAF is not ported).
// Tag tables are generated into tables/FujiFilm.php, %testedRAF into Vars\FujiFilm.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class FujiFilm
{
    public static $VERSION = '2.02';

    //------------------------------------------------------------------------------
    // decode information from FujiFilm face recognition information
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1
    public static function ProcessFaceRec(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = ($dirInfo['DataPos'] ?? null) + (($dirInfo['Base'] ?? null) ?: 0);
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $pos = $dirStart;
        $end = $dirStart + $dirLen;
        $et->VerboseDir('FaceRecInfo');
        for ($i = 1; ; ++$i) {
            if ($pos + 8 > $end) break;
            $off = ExifTool::Get32u($dataPt, $pos) + $dirStart;
            $len = ExifTool::Get32u($dataPt, $pos + 4);
            if ($len == 0 or $off > $end or $off + $len > $end or $len < 62) break;
            // values observed for each offset (always zero if not listed):
            // 0=5; 3=1; 4=4; 6=1; 10-13=numbers(constant for a given registered face)
            // 15=16; 16=3; 18=1; 22=nameLen; 26=1; 27=16; 28=7; 30-33=nameLen(int32u)
            // 34-37=nameOffset(int32u); 38=32; 39=16; 40=4; 42=1; 46=0,2,4,8(category)
            // 50=33; 51=16; 52=7; 54-57=dateLen(int32u); 58-61=dateOffset(int32u)
            $n = ExifTool::Get32u($dataPt, $off + 30);
            $p = ExifTool::Get32u($dataPt, $off + 34) + $dirStart;
            if ($p < $dirStart or $p + $n > $end) break;
            $val = substr($dataPt, $p, $n);
            $et->HandleTag($tagTablePtr, "Face{$i}Name", $val,
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $p,
                Size: $n,
            );
            $n = ExifTool::Get32u($dataPt, $off + 54);
            $p = ExifTool::Get32u($dataPt, $off + 58) + $dirStart;
            if ($p < $dirStart or $p + $n > $end) break;
            $val = substr($dataPt, $p, $n);
            $val = preg_replace('/(\d{4})(\d{2})(\d{2})/', '$1:$2:$2', $val, 1);
            $et->HandleTag($tagTablePtr, "Face{$i}Birthday", $val,
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $p,
                Size: $n,
            );
            $et->HandleTag($tagTablePtr, "Face{$i}Category", null,
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $off + 46,
                Size: 1,
            );
            $pos += 8;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // get information from FujiFilm RAF directory
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 if this was a valid FujiFilm directory
    public static function ProcessFujiDir(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $raf = $dirInfo['RAF'];
        $offset = $dirInfo['DirStart'];
        if (!$raf->Seek($offset, 0)) return 0;
        $buff = null;
        if (!$raf->Read($buff, 4)) return 0;
        $entries = Perl::unpack('N', $buff)[0];
        if (!($entries < 256)) return 0;
        $et->VerboseDir('Fuji', $entries);
        ExifTool::SetByteOrder('MM');
        $pos = $offset + 4;
        for ($index = 0; $index < $entries; ++$index) {
            if (!$raf->Read($buff, 4)) return 0;
            $pos += 4;
            [$tag, $len] = Perl::unpack('nn', $buff);
            $val = $vbuf = null;
            if (!$raf->Read($vbuf, $len)) return 0;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo and $tagInfo->Format) {
                $val = ExifTool::ReadValue($vbuf, 0, $tagInfo->Format, $tagInfo->Count, $len);
                // (Perl "next" skips the $pos increment too)
                if ($val === null) continue;
            } elseif ($len == 4) {
                // interpret unknown 4-byte values as int32u
                $val = ExifTool::Get32u($vbuf, 0);
            } else {
                // treat other unknown values as binary data
                $val = new ScalarRef($vbuf);
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                Index: $index,
                DataPt: $vbuf,
                DataPos: $pos,
                Size: $len,
                TagInfo: $tagInfo,
            );
            $pos += $len;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // get information from FujiFilm M-RAW header
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 if this was a valid M-RAW header
    public static function ProcessMRAW(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if ($et->DOC_NUM) return 1;
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dataLen = strlen($dataPt);
        if ($dataLen < 44) {
            $et->Warn('Short M-RAW header');
            return 0;
        }
        if (!preg_match('/^FUJIFILMM-RAW  /', $dataPt)) {
            $et->Warn('Bad M-RAW header');
            return 0;
        }
        $ver = substr($dataPt, 16, 4);
        $et->VerboseDir("M-RAW $ver", null, $dataLen);
        ExifTool::SetByteOrder('MM');
        $size = ExifTool::Get16u($dataPt, 40); // (these are just a guess - PH)
        $num = ExifTool::Get16u($dataPt, 42);
        $pos = 44;
        for ($n = 0; ; ++$n) {
            $end = $pos + 16 + $size;
            if ($end > $dataLen) break;
            $rafStart = ExifTool::Get64u($dataPt, $pos);
            $rafLen = ExifTool::Get64u($dataPt, $pos + 8);
            $pos += 16;  // skip offset/size fields
            if ($pos > 60) $et->DOC_NUM = ++$et->DOC_COUNT;
            $et->VPrint(0, "{$et->INDENT}(Raw image $n parameters: $size bytes, $num entries)\n");
            for ($i = 0; $i < $num; ++$i) {
                if ($pos + 4 > $end) break;
                $tag = ExifTool::Get16u($dataPt, $pos);
                $size2 = ExifTool::Get16u($dataPt, $pos + 2);    // (Perl: inner "my $size")
                $pos += 4;
                if ($pos + $size2 > $end) break;
                $et->HandleTag($tagTablePtr, $tag, null,
                    DataPt: $dataPt,
                    DataPos: $dataPos,
                    Start: $pos,
                    Size: $size2,
                );
                $pos += $size2;
            }
            if ($rafStart and $et->Options('ExtractEmbedded')) {
                if ($et->Options('Verbose')) {
                    $msg = Perl::sprintf("{$et->INDENT}(RAW image $n data: Start=0x%x, Length=0x%x)\n", $rafStart, $rafLen);
                    $et->VPrint(0, $msg);
                }
                $raf = $et->RAF;
                $tell = $raf->Tell();
                $order = ExifTool::GetByteOrder();
                $fujiWidth = $et->FujiWidth;
                if (!$raf->Seek($rafStart, 0)) continue;
                $rafDirInfo = ['RAF' => $raf, 'Base' => $rafStart];
                self::ProcessRAF($et, $rafDirInfo);
                $et->FujiWidth = $fujiWidth;
                ExifTool::SetByteOrder($order);
                $raf->Seek($tell, 0);
            }
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // write information to FujiFilm RAW file (RAF)
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid RAF file, or -1 on write error
    public static function WriteRAF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $hdr = $jpeg = $outJpeg = $err = $buff = null;

        if ($raf->Read($hdr, 0x94) != 0x94) return 0;
        if (!preg_match('/^FUJIFILM/', $hdr)) return 0;
        $ver = substr($hdr, 0x3c, 4);
        if (!(preg_match('/^\d{4}$/', $ver) or (Vars\FujiFilm::$testedRAF[$ver] ?? null))) return 0;

        // get position and size of M-RAW header
        [$mpos, $mlen] = Perl::unpack('x72NN', $hdr);
        // get the position and size of embedded JPEG
        [$jpos, $jlen] = Perl::unpack('x84NN', $hdr);
        // check to be sure the JPEG starts in the expected location
        if (($mpos > 0x94 or $jpos > 0x94 + $mlen) or $jpos < 0x68 or $jpos & 0x03) {
            $et->Error("Unsupported or corrupted RAF image (version $ver)");
            return 1;
        }
        // read the embedded JPEG
        if (!($raf->Seek($jpos, 0) and $raf->Read($jpeg, $jlen) == $jlen)) {
            $et->Error('Error reading RAF meta information');
            return 1;
        }
        if ($mpos) {
            if ($mlen != 0x11c) {
                $et->Error('Unsupported M-RAW header (please submit sample for testing)');
                return 1;
            }
            // read M-RAW header and add to file header
            $mraw = null;
            if (!($raf->Seek($mpos, 0) and $raf->Read($mraw, $mlen) == $mlen)) {
                $et->Error('Error reading M-RAW header');
                return 1;
            }
            $hdr .= $mraw;
            // verify that the 1st raw image offset is zero, and that the 1st raw image
            // length is the same as the 2nd raw image offset
            if (!(substr($hdr, 0xc0, 8) === "\0\0\0\0\0\0\0\0" and
                  substr($hdr, 0xc8, 8) === substr($hdr, 0x110, 8)))
            {
                $et->Error('Unexpected layout of M-RAW header');
                return 1;
            }
        }
        // use same write directories as JPEG
        $et->InitWriteDirs('JPEG');
        // rewrite the embedded JPEG in memory
        $outJpegRef = new ScalarRef(null);
        $jpegInfo = [
            'Parent'  => 'RAF',
            'RAF'     => new RandomAccess($jpeg),
            'OutFile' => $outJpegRef,
        ];
        $et->FILE_TYPE = 'JPEG';
        $success = $et->WriteJPEG($jpegInfo);
        $et->FILE_TYPE = 'RAF';
        $outJpeg = $outJpegRef->value;
        if (!($success and $outJpeg)) {
            $et->Error('Invalid RAF format');
            return 1;
        }
        if ($success < 0) return -1;

        // rewrite the RAF image
        ExifTool::SetByteOrder('MM');
        $jpegLen = strlen($outJpeg);
        // pad JPEG to an even 4 bytes (ALWAYS use padding as Fuji does)
        $pad = str_repeat("\0", 4 - ($jpegLen % 4));
        // update JPEG size in header (size without padding)
        ExifTool::Set32u(strlen($outJpeg), $hdr, 0x58);
        // get pointer to start of the next RAF block
        $nextPtr = ExifTool::Get32u($hdr, 0x5c);
        // determine the length of padding at the end of the original JPEG
        $oldPadLen = $nextPtr - ($jpos + $jlen);
        if ($oldPadLen) {
            if ($oldPadLen > 1000000 or $oldPadLen < 0 or
                !$raf->Seek($jpos + $jlen, 0) or
                $raf->Read($buff, $oldPadLen) != $oldPadLen)
            {
                $et->Error('Bad RAF pointer at 0x5c');
                return 1;
            }
            // make sure padding is only zero bytes (can be >100k for HS10)
            // (have seen non-null padding in X-Pro1)
            if (preg_match('/[^\0]/', $buff)) {
                if ($et->Error('Non-null bytes found in padding', 2)) return 1;
            }
        }
        // calculate offset difference due to change in JPEG size
        $ptrDiff = strlen($outJpeg) + strlen($pad) - ($jlen + $oldPadLen);
        // update necessary pointers in header (0xcc and higher in M-RAW header)
        foreach ([0x5c, 0x64, 0x78, 0x80, 0xcc, 0x114, 0x164] as $offset) {
            if ($offset >= $jpos) break;   // some versions have a short header
            $oldPtr = ExifTool::Get32u($hdr, $offset);
            if (!$oldPtr) continue;        // don't update if pointer is zero
            $newPtr = $oldPtr + $ptrDiff;
            if ($newPtr < 0 or $newPtr > 0xffffffff) {
                if ($offset < 0xcc) {
                    $et->Error('Invalid offset in RAF header');
                    return 1;
                }
                // assume values at 0xcc and greater are 8-byte integers (NC)
                // and adjust high word if necessary
                $high = ExifTool::Get32u($hdr, $offset - 4);
                if ($newPtr < 0) {
                    $high -= 1;
                    $newPtr += 0xffffffff + 1;
                    if ($high < 0) {
                        $et->Error('RAF header offset error');
                        return 1;
                    }
                } else {
                    $high += 1;
                    $newPtr -= 0xffffffff + 1;
                }
                ExifTool::Set32u($high, $hdr, $offset - 4);
            }
            ExifTool::Set32u($newPtr, $hdr, $offset);
        }
        // write the new header
        $outfile = $dirInfo['OutFile'];
        if (!ExifTool::Write($outfile, substr($hdr, 0, $jpos))) $err = 1;
        // write the updated JPEG plus padding
        if (!ExifTool::Write($outfile, $outJpeg, $pad)) $err = 1;
        // copy over the rest of the RAF image
        if (!$raf->Seek($nextPtr, 0)) {
            $et->Error('Error reading RAF image');
            return 1;
        }
        while ($raf->Read($buff, 65536)) {
            if (!ExifTool::Write($outfile, $buff)) {
                $err = 1;
                break;
            }
        }
        return $err ? -1 : 1;
    }

    //------------------------------------------------------------------------------
    // get information from FujiFilm RAW file (RAF)
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a valid RAF file
    public static function ProcessRAF(ExifTool $et, array &$dirInfo)
    {
        $buff = $jpeg = $warn = null;

        $raf = $dirInfo['RAF'];
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        if ($raf->Read($buff, 0x70) != 0x70) return 0;
        if (!preg_match('/^FUJIFILM/', $buff)) return 0;
        // get position and size of M-RAW header and jpeg preview
        [$mpos, $mlen] = Perl::unpack('x72NN', $buff);
        [$jpos, $jlen] = Perl::unpack('x84NN', $buff);
        if ($jpos & 0x8000) return 0;
        if ($jpos) {
            if (!$raf->Seek($jpos + $base, 0)) return 0;
            if ($raf->Read($jpeg, $jlen) != $jlen) return 0;
        }
        ExifTool::SetByteOrder('MM');
        if (!$et->DOC_NUM) $et->SetFileType();
        $tbl = ExifTool::GetTagTable('Image::ExifTool::FujiFilm::RAFHeader');
        $hdrDirInfo = ['DataPt' => $buff, 'DirName' => 'RAFHeader', 'Base' => $base];
        $et->ProcessDirectory($hdrDirInfo, $tbl);

        // extract information from embedded JPEG
        $subdirInfo = [     // (Perl: my %dirInfo)
            'Parent' => 'RAF',
            'RAF'    => new RandomAccess($jpeg ?? ''),
        ];
        if ($jpos) {
            $et->BASE += $jpos + $base;
            $ok = $et->ProcessJPEG($subdirInfo);
            $et->BASE -= $jpos + $base;
            if ($ok) $et->FoundTag('PreviewImage', new ScalarRef($jpeg));
        }
        // extract information from Fuji RAF and TIFF directories
        [$rafNum, $ifdNum] = ['', ''];
        foreach ([0x48, 0x5c, 0x64, 0x78, 0x80] as $offset) {
            if ($jpos and $offset >= $jpos) break;
            if (!($raf->Seek($offset + $base, 0) and $raf->Read($buff, 8))) {
                $warn = 1;
                break;
            }
            [$start, $len] = Perl::unpack('N2', $buff);
            if (!$start) continue;
            $start += $base;
            if ($offset == 0x64 or $offset == 0x80) {
                // parse FujiIFD directory
                $subdirInfo = [
                    'RAF'  => $raf,
                    'Base' => $start,
                ];
                $et->SET_GROUP1 = "FujiIFD$ifdNum";
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FujiFilm::IFD');
                // this is TIFF-format data only for some models, so no warning if it fails
                if (!$et->ProcessTIFF($subdirInfo, $tagTablePtr)) {
                    // do hash of image data if necessary
                    if ($et->ImageDataHash and $raf->Seek($start, 0)) $et->ImageDataHash($raf, $len, 'raw');
                }
                $et->SET_GROUP1 = null;
                $ifdNum = ($ifdNum ?: 1) + 1;
            } elseif ($offset == 0x48) {
                $et->VALUE['FileType'] .= ' (M-RAW)';
                if ($raf->Seek($start, 0) and $raf->Read($buff, $mlen) == $mlen) {
                    $tbl = ExifTool::GetTagTable('Image::ExifTool::FujiFilm::MRAW');
                    $mrawDirInfo = ['DataPt' => $buff, 'DataPos' => $start, 'DirName' => 'M-RAW'];
                    $et->ProcessDirectory($mrawDirInfo, $tbl);
                } else {
                    $et->Warn('Error reading M-RAW header');
                }
            } else {
                // parse RAF directory
                $subdirInfo = [
                    'RAF'      => $raf,
                    'DirStart' => $start,
                ];
                $et->SET_GROUP1 = "RAF$rafNum";
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FujiFilm::RAF');
                if ($et->ProcessDirectory($subdirInfo, $tagTablePtr)) {
                    $rafNum = ($rafNum ?: 1) + 1;
                } else {
                    $warn = 1;
                }
                $et->SET_GROUP1 = null;
            }
        }
        if ($warn) $et->Warn('Possibly corrupt RAF information');

        return 1;
    }
}
