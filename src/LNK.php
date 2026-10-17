<?php

//------------------------------------------------------------------------------
// File:         LNK.pm
//
// Description:  Read meta information from MS Shell Link files
//
// Revisions:    2009/09/19 - P. Harvey Created
//               2025/10/20 - PH Added .URL file support
//
// References:   1) http://msdn.microsoft.com/en-us/library/dd871305(PROT.10).aspx ([MS-SHLLINK].pdf)
//               2) http://www.i2s-lab.com/Papers/The_Windows_Shortcut_File_Format.pdf
//               3) https://harfanglab.io/insidethelab/sadfuture-xdspy-latest-evolution/#tid_specifications_ignored
//               4) https://github.com/libyal/libfwsi/blob/main/documentation/Windows%20Shell%20Item%20format.asciidoc
//               5) https://github.com/EricZimmerman/Lnk/blob/master/Lnk/ShellItems/ShellBag0x00.cs
//
// PHP port of Image::ExifTool::LNK 1.18. Tag tables are generated into tables/LNK.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class LNK
{
    public static $VERSION = '1.18';

    //------------------------------------------------------------------------------
    // Get DOS date/time
    // Inputs: 0) date/time integer value (date in low word)
    // Returns: EXIF-format date/time string
    public static function DOSTime($val)
    {
        $val = Perl::int($val);
        return Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d',
           (($val >> 9)  & 0x7f) + 1980, // year
            ($val >> 5)  & 0x0f, // month
            ($val >> 0)  & 0x1f, // day
            ($val >> 27) & 0x1f, // hour
            ($val >> 21) & 0x3f, // minute
            ($val >> 15) & 0x3e  // second (2 sec resolution)
        );
    }

    //------------------------------------------------------------------------------
    // Extract null-terminated ASCII or Unicode string from buffer
    // Inputs: 0) buffer ref, 1) start position, 2) flag for unicode string
    // Return: string or undef if start position is outside bounds
    public static function GetString($dataPt, $pos, $unicode = null)
    {
        if ($pos >= strlen($dataPt)) return null;
        if (preg_match($unicode ? '/\G((?:..)*?)\x00\x00/s' : '/\G(.*?)\x00/s', $dataPt, $m, 0, $pos)) return $m[1];
        return substr($dataPt, $pos);
    }

    //------------------------------------------------------------------------------
    // Process item ID data (ref 4)
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessItemID(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $verbose = $et->Options('Verbose');
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);
        $pos = 0;
        $opts = [
            'DataPt'  => $dataPt,
            'DataPos' => $dirInfo['DataPos'] ?? null,
        ];
        $et->VerboseDir('ItemID', null, $dataLen);
        for (;;) {
            if ($pos + 2 > $dataLen) {
                $et->Warn('Missing item ID list terminator');
                break;
            }
            $size = ExifTool::Get16u($dataPt, $pos);
            if ($size == 0) {
                $more = $dataLen - $pos - 2;
                if ($more) $et->Warn("Unknown $more bytes after item ID list");
                break;
            } elseif ($size < 4) {
                $et->Warn('Invalid item ID entry size');
                break;
            }
            $tag = ExifTool::Get8u($dataPt, $pos + 2);  // get item ID
            if ($pos + $size > $dataLen) {
                $et->Warn(Perl::sprintf('Truncated item with ID 0x%.2x', $tag));
                $size = $dataLen - $pos;
            }
            // 0x20-0x2f, 0x30-0x3f and 0x40-0x4f are ID ranges for the same tags
            if (!$tagTablePtr[$tag]) {
                $lkup = [0x20 => 0x2e, 0x21 => 0x2f, 0x30 => 0x31, 0x40 => 0x40];
                $srcTag = ($lkup[$tag & 0x71] ?? null) ?: ($lkup[$tag & 0x70] ?? null);    // (keep bit 0 of MyComputer ID)
                // make a clone of the existing tag definition
                $src = $srcTag !== null ? $tagTablePtr[$srcTag] : null;
                if ($src instanceof TagInfo) {
                    $clone = clone $src;
                    $clone->TagID = $tag;
                    $tagTablePtr[$tag] = $clone;
                }
            }
            if (!$tagTablePtr[$tag]) {
                ExifTool::AddTagToTable($tagTablePtr, $tag, [
                    'Name' => Perl::sprintf('Item_%.2x', $tag),
                    'SubDirectory' => ['TagTable' => 'Image::ExifTool::LNK::UnknownData'],
                ]);
            }
            // isolate this itemID data
            $dat = substr($dataPt, $pos, $size);
            // look for 0xbeef extension blocks
            // (don't know what the first 2 bytes are, but I've seen 0x0003, 0x0007, 0x0008
            //  and 0x0009 for 0xbeef0004, and 0x0000 for 0xbeef0014 and 0xbeef0025)
            // List of observed 0xbeef blocks (0xbeef ID - ItemID):
            //  0xbeef0003 - 0xb1
            //  0xbeef0004 - 0x31,0x32
            //  0xbeef0014 - 0x61
            //  0xbeef0025 - 0x2e
            //  0xbeef0026 - 0x1f,0x2e
            $beefStart = null;
            if (preg_match('/.{5}\x00\xef\xbe/s', $dat, $m, PREG_OFFSET_CAPTURE)) {
                $off = $m[0][1];
                $off2 = ExifTool::Get16u($dat, strlen($dat) - 2);
                if ($off == $off2) {
                    $beefStart = $pos + $off;
                } else {
                    $et->Warn('Unexpected 0xbeef extension offset');
                }
            }
            $et->HandleTag($tagTablePtr, $tag, null, ...[...$opts,
                'Start' => $pos,
                'Size'  => $beefStart ? $beefStart - $pos : $size,
            ]);
            if ($verbose > 1 and $beefStart) {
                $len = $size - $beefStart + $pos;
                $et->VPrint(1, Perl::sprintf($et->INDENT . "- Tag 0x%.4x Extension (%d bytes)\n", $tag, $len));
                $et->VerboseDump($dataPt, ...[...$opts, 'Start' => $beefStart, 'Len' => $len]);
            }
            // process 0xbeef extension blocks
            while ($beefStart) {
                $end = $pos + $size; // end if itemID entry
                if ($beefStart + 8 > $end) {
                    if ($beefStart != $end) $et->Warn('Garbage data in 0xbeef extension');
                    break;
                }
                $len = ExifTool::Get16u($dataPt, $beefStart);
                $beefID = ExifTool::Get32u($dataPt, $beefStart + 4);
                if (($beefID & 0xffff0000) != 0xbeef0000) {
                    $et->Warn('Invalid 0xbeef extension');
                    break;
                }
                if (!($beefStart + $len <= $end)) {
                    $et->Warn('Truncated 0xbeef extension');
                    break;
                }
                if (!$tagTablePtr[$beefID]) {
                    ExifTool::AddTagToTable($tagTablePtr, $beefID, [
                        'Name' => Perl::sprintf('Unknown_beef%.4x', $beefID & 0xffff),
                        'Binary' => 1,
                    ]);
                }
                $et->HandleTag($tagTablePtr, $beefID, null, ...[...$opts,
                    'Start' => $beefStart,
                    'Size'  => $len,
                ]);
                if (!$len) break;   // (PHP: Perl loops forever on a zero-length extension)
                if ($len & 0x01) ++$len;    // assume they start on 2-byte boundaries (NC)
                $beefStart += $len;     // step to next extension block
            }
            $pos += $size;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Process URI 0xbeef0014 extension (ref 4)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessURI(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = $dirInfo['DirLen'];
        $end = $pos + $dirLen;
        if ($dirLen < 56) return 0;
        $et->VerboseDir('URI', null, $dirLen);
        // offset 8 - GUID df2fce13-25ec-45bb-9d4c-cecd47c2430c
        $num = ExifTool::Get32u($dataPt, $pos + 52);
        $pos += 56;
        for ($i = 0; $i < $num; ++$i) {
            if ($pos + 8 > $end) {
                $et->Warn('Truncated URI extension');
                break;
            }
            $tag = ExifTool::Get32u($dataPt, $pos);
            $size = ExifTool::Get32u($dataPt, $pos + 4);
            $pos += 8;
            if (!$size) continue;
            if ($pos + $size > $end) {
                $et->Warn('Truncated URI string');
                break;
            }
            // (many tags are untested -- some may not be Unicode strings?)
            $val = $et->Decode(substr($dataPt, $pos, $size), 'UTF16');
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt:  $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Start:   $pos,
                Size:    $size,
            );
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process link information data
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessLinkInfo(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = strlen($dataPt);
        if ($dataLen < 0x24) return 0;
        $hdrLen = ExifTool::Get32u($dataPt, 4);
        $lif = ExifTool::Get32u($dataPt, 8);    // link info flags
        $opts = [
            'DataPt'  => $dataPt,
            'DataPos' => $dirInfo['DataPos'] ?? null,
            'Size'    => 4, // (typical value size)
        ];
        $unicode = null;
        $et->VerboseDir('LinkInfo', null, $dataLen);
        if ($lif & 0x01) {
            // read Volume ID
            $off = ExifTool::Get32u($dataPt, 0x0c);
            if ($off and $off + 0x20 <= $dataLen) {
                // my $len = Get32u($dataPt, $off);
                $et->HandleTag($tagTablePtr, 'DriveType', null, ...[...$opts, 'Start' => $off + 4]);
                $et->HandleTag($tagTablePtr, 'DriveSerialNumber', null, ...[...$opts, 'Start' => $off + 8]);
                $pos = ExifTool::Get32u($dataPt, $off + 0x0c);
                if ($pos == 0x14) {
                    // use VolumeLabelOffsetUnicode instead
                    $pos = ExifTool::Get32u($dataPt, $off + 0x10);
                    $unicode = 1;
                }
                $pos += $off;
                $val = self::GetString($dataPt, $pos, $unicode);
                if ($val !== null) {
                    $size = strlen($val);
                    if ($unicode) $val = $et->Decode($val, 'UTF16');
                    $et->HandleTag($tagTablePtr, 'VolumeLabel', $val, ...[...$opts, 'Start' => $pos, 'Size' => $size]);
                }
            }
            // read local base path
            if ($hdrLen >= 0x24) {
                $pos = ExifTool::Get32u($dataPt, 0x1c);
                $unicode = 1;
            } else {
                $pos = ExifTool::Get32u($dataPt, 0x10);
                $unicode = null;
            }
            $val = self::GetString($dataPt, $pos, $unicode);
            if ($val !== null) {
                $size = strlen($val);
                if ($unicode) $val = $et->Decode($val, 'UTF16');
                $et->HandleTag($tagTablePtr, 'LocalBasePath', $val, ...[...$opts, 'Start' => $pos, 'Size' => $size]);
            }
        }
        if ($lif & 0x02) {
            // read common network relative link
            $off = ExifTool::Get32u($dataPt, 0x14);
            if ($off and $off + 0x14 <= $dataLen) {
                $siz = ExifTool::Get32u($dataPt, $off);
                if ($off + $siz > $dataLen) return 0;
                $pos = ExifTool::Get32u($dataPt, $off + 0x08);
                if ($pos > 0x14 and $siz >= 0x18) {
                    $pos = ExifTool::Get32u($dataPt, $off + 0x14);
                    $unicode = 1;
                } else {
                    $unicode = null;
                }
                $val = self::GetString($dataPt, $off + $pos, $unicode);
                if ($val !== null) {
                    $size = strlen($val);
                    if ($unicode) $val = $et->Decode($val, 'UTF16');
                    $et->HandleTag($tagTablePtr, 'NetName', $val, ...[...$opts, 'Start' => $pos, 'Size' => $size]);
                }
                $flg = ExifTool::Get32u($dataPt, $off + 0x04);
                if ($flg & 0x01) {
                    $pos = ExifTool::Get32u($dataPt, $off + 0x0c);
                    if ($pos > 0x14 and $siz >= 0x1c) {
                        $pos = ExifTool::Get32u($dataPt, $off + 0x18);
                        $unicode = 1;
                    } else {
                        $unicode = null;
                    }
                    $val = self::GetString($dataPt, $off + $pos, $unicode);
                    if ($val !== null) {
                        $size = strlen($val);
                        if ($unicode) $val = $et->Decode($val, 'UTF16');
                        $et->HandleTag($tagTablePtr, 'DeviceName', $val, ...[...$opts, 'Start' => $pos, 'Size' => $size]);
                    }
                }
                if ($flg & 0x02) {
                    $val = ExifTool::Get32u($dataPt, $off + 0x10);
                    $et->HandleTag($tagTablePtr, 'NetProviderType', $val, ...[...$opts, 'Start' => $off + 0x10]);
                }
            }
        }
        $off = ExifTool::Get32u($dataPt, 0x18);
        if ($off and $off < $dataLen) {
            $val = self::GetString($dataPt, $off);
            $et->HandleTag($tagTablePtr, 'CommonPathSuffix', $val, ...[...$opts, 'Start' => $off, 'Size' => strlen($val) + 1]);
        }
        if ($hdrLen >= 0x24) {
            $off = ExifTool::Get32u($dataPt, 0x20);
            if ($off and $off < $dataLen) {
                $val = self::GetString($dataPt, $off, 1);
                $et->HandleTag($tagTablePtr, 'CommonPathSuffixUnicode', $val, ...[...$opts, 'Start' => $off, 'Size' => strlen($val) + 1]);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a INI-format file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid INI file
    public static function ProcessINI(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        $oldRS = Perl::$RS;     // (Perl: local $/)
        try {
            Perl::$RS = "\x0d\x0a";
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::LNK::INI');
            while ($raf->ReadLine($buff)) {
                if (preg_match('/^\[(.*?)\]/', $buff, $m)) {
                    $et->VPrint(0, "$m[1] section:\n");
                } elseif (preg_match('/^\s*(\w+)=(.*)\x0d\x0a$/', $buff, $m)) {
                    $et->HandleTag($tagTablePtr, $m[1], $m[2], MakeTagInfo: 1);
                }
            }
        } finally {
            Perl::$RS = $oldRS;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a MS Shell Link (Windows shortcut) file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid LNK file
    public static function ProcessLNK(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $bad = $isUnicode = null;

        // read LNK file header
        if ($raf->Read($buff, 0x4c) != 0x4c) return 0;
        if (!preg_match('/^.{4}\x01\x14\x02\x00{5}\xc0\x00{6}\x46/s', $buff)) {
            // check for INI-format LNK file (eg. .URL file)
            if (!preg_match('/^\[[InternetShortcut\][\x0d\x0a]/', $buff)) return 0;
            if (!$raf->Seek(0, 0)) return 0;
            $et->SetFileType('URL', 'application/x-mswinurl');
            return self::ProcessINI($et, $dirInfo);
        }
        $len = Perl::unpack('V', $buff)[0];
        if (!($len >= 0x4c)) return 0;
        if ($len > 0x4c) {
            if ($raf->Read($buf2, $len - 0x4c) != $len - 0x4c) return 0;
            $buff .= $buf2;
        }
        $et->SetFileType();
        ExifTool::SetByteOrder('II');

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::LNK::Main');
        $subdir = [
            'DataPt' => $buff,
            'DataPos' => 0,
            'DataLen' => strlen($buff),
            'DirLen' => strlen($buff),
        ];
        $et->ProcessDirectory($subdir, $tagTablePtr);

        $flags = ExifTool::Get32u($buff, 0x14);

        // save Unicode flag for strings
        if ($flags & 0x80) $isUnicode = 1;

        // read link target ID list
        if ($flags & 0x01) {
            if ($raf->Read($buff, 2) != 2) {
                $et->Warn('Missing target ID list');
                return 1;
            }
            $len = Perl::unpack('v', $buff)[0];
            if ($raf->Read($buff, $len) != $len) {
                $et->Warn('Truncated target ID list');
                $len = strlen($buff);
            }
            $et->HandleTag($tagTablePtr, 0x10000, null,
                DataPt:  $buff,
                DataPos: $raf->Tell() - $len,
                Size:    $len,
            );
        }

        // read link information
        if ($flags & 0x02) {
            if ($raf->Read($buff, 4) != 4) {
                $et->Warn('Missing link information');
                return 1;
            }
            $len = Perl::unpack('V', $buff)[0];
            if ($len < 4) {
                $et->Warn('Invalid link information');
                return 1;
            }
            if ($raf->Read($buf2, $len - 4) != $len - 4) {
                $et->Warn('Truncated link information');
                $len = strlen($buf2);
            }
            $buff .= $buf2;
            $et->HandleTag($tagTablePtr, 0x20000, null,
                DataPt:  $buff,
                DataPos: $raf->Tell() - $len,
                Size:    $len,
            );
        }

        // read string data
        $strings = ['Description', 'RelativePath', 'WorkingDirectory',
                    'CommandLineArguments', 'IconFileName'];
        for ($i = 0; $i < count($strings); ++$i) {
            $val = $limit = null;
            $mask = 0x04 << $i;
            if (!($flags & $mask)) continue;
            if ($raf->Read($buff, 2) != 2) {
                $et->Warn("Invalid $strings[$i] string");
                return 1;
            }
            $pos = $raf->Tell();
            $len = Perl::unpack('v', $buff)[0];
            if (!$len) continue;
            // Windows doesn't follow their own specification and limits the length
            // for most of these strings (ref 3)
            if ($i != 3 and $len >= 260) {
                $limit = 1;
                if ($len > 260) {
                    $len = 260;
                    $et->Warn('LNK string data overrun! Possible security issue');
                }
            }
            // characters are 2 bytes if Unicode flag is set
            if ($isUnicode) $len *= 2;
            if (!$raf->Read($buff, $len)) {
                $et->Warn("Truncated $strings[$i] string");
                return 1;
            }
            // remove last character if string is at length limit (Windows treats this as a null)
            if ($limit) {
                $len -= $isUnicode ? 2 : 1;
                $buff = substr($buff, 0, $len);
            }
            if ($isUnicode) $val = $et->Decode($buff, 'UTF16');
            $et->HandleTag($tagTablePtr, 0x30000 | $mask, $val,
                DataPt:  $buff,
                DataPos: $pos,
                Size:    $len,
            );
        }

        // read extra data
        while ($raf->Read($buff, 4) == 4) {
            $len = Perl::unpack('V', $buff)[0];
            if ($len < 4) break;
            $len -= 4;
            if ($raf->Read($buf2, $len) != $len) {
                $et->Warn('Truncated extra data');
                break;
            }
            if (!($len > 4)) continue;
            $buff .= $buf2;
            $tag = ExifTool::Get32u($buff, 4);
            $tagInfo = $tagTablePtr[$tag];
            if (!($tagInfo instanceof TagInfo and $tagInfo->SubDirectory)) {
                $tagInfo = $tagTablePtr[0xa0000000];
            }
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt:  $buff,
                DataPos: $raf->Tell() - $len - 4,
                TagInfo: $tagInfo,
            );
        }
        // check for an overlay
        $pos = $raf->Tell();
        $len = 0;
        for (;;) {
            $i = $raf->Read($buff, 65536);
            if (!$i) break;
            $len += $i;
            if (!$bad and preg_match('/[^\x00]/', $buff)) $bad = 1;
        }
        if ($bad) {
            $et->Warn(Perl::sprintf('Unknown %d-byte overlay at offset 0x%x', $len, $pos));
        } elseif ($len) {
            $et->Warn("$len bytes of null padding at end of file");
        }
        return 1;
    }
}
