<?php

//------------------------------------------------------------------------------
// File:         ASF.pm
//
// Description:  Read ASF/WMA/WMV meta information
//
// Revisions:    12/23/2005 - P. Harvey Created
//
// References:   1) http://www.microsoft.com/windows/windowsmedia/format/asfspec.aspx
//               2) http://www.adobe.com/devnet/xmp/pdfs/XMPSpecificationPart3.pdf (Oct 2008)
//
// PHP port of Image::ExifTool::ASF 1.27 (reading only). Tag tables and file lexicals
// (%streamType...) are generated into tables/ASF.php and Vars\ASF.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class ASF
{
    public static $VERSION = '1.27';

    //------------------------------------------------------------------------------
    // Generate GUID from 16 bytes of binary data
    // Inputs: 0) data
    // Returns: GUID
    public static function GetGUID($val)
    {
        // must do some byte swapping
        if (strlen(Perl::str($val)) != 16) return $val;
        $buff = bin2hex(Perl::pack('NnnNN', ...Perl::unpack('VvvNN', $val)));
        $buff = preg_replace('/(.{8})(.{4})(.{4})(.{4})/', '$1-$2-$3-$4-', $buff, 1);
        return strtoupper($buff);
    }

    //------------------------------------------------------------------------------
    // Process ASF content description
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessContentDescription(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        if ($dirLen < 10) return 0;
        $lens = Perl::unpack('v5', $dataPt);   // (Perl @len)
        $pos = 10;
        foreach ([0, 1, 2, 3, 4] as $tag) {
            $len = array_shift($lens);
            if (!$len) continue;
            if ($pos + $len > $dirLen) return 0;
            $val = $et->Decode(substr($dataPt, $pos, $len), 'UTF16', 'II');
            $et->HandleTag($tagTablePtr, $tag, $val);
            $pos += $len;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process ASF content branding
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessContentBranding(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        if ($dirLen < 40) return 0;
        // decode banner image type
        $et->HandleTag($tagTablePtr, 0, Perl::unpack('V', $dataPt)[0] ?? null);
        // decode banner image, banner URL and copyright URL
        $pos = 4;
        foreach ([1, 2, 3] as $tag) {
            if ($pos + 4 > $dirLen) return 0;
            $size = Perl::unpack("x{$pos}V", $dataPt)[0] ?? null;
            $pos += 4;
            if (!$size) continue;
            if ($pos + $size > $dirLen) return 0;
            $val = substr($dataPt, $pos, $size);
            $et->HandleTag($tagTablePtr, $tag, $val);
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read ASF value
    // Inputs: 0) ExifTool object ref, 1) data reference, 2) value offset,
    //         3) format number, 4) size
    // Returns: converted value
    public static function ReadASF(ExifTool $et, $dataPt, $pos, $format, $size)
    {
        $vals = [];
        if ($format == 0) { // unicode string
            $vals[0] = $et->Decode(substr($dataPt, $pos, $size), 'UTF16', 'II');
        } elseif ($format == 2) { // 4-byte boolean
            $vals = ExifTool::ReadValueList($dataPt, $pos, 'int32u', null, $size);
            foreach ($vals as &$v) {
                $v = $v ? 'True' : 'False';
            }
            unset($v);
        } elseif ($format == 3) { // int32u
            $vals = ExifTool::ReadValueList($dataPt, $pos, 'int32u', null, $size);
        } elseif ($format == 4) { // int64u
            $vals = ExifTool::ReadValueList($dataPt, $pos, 'int64u', null, $size);
        } elseif ($format == 5) { // int16u
            $vals = ExifTool::ReadValueList($dataPt, $pos, 'int16u', null, $size);
        } else { // any other format (including 1, byte array): return raw data
            $vals[0] = substr($dataPt, $pos, $size);
        }
        return implode(' ', array_map(Perl::str(...), $vals));
    }

    //------------------------------------------------------------------------------
    // Process extended content description
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessExtendedContentDescription(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        if ($dirLen < 2) return 0;
        $count = ExifTool::Get16u($dataPt, 0);
        $et->VerboseDir($dirInfo, $count);
        $pos = 2;
        for ($i = 0; $i < $count; ++$i) {
            if ($pos + 6 > $dirLen) return 0;
            $nameLen = Perl::unpack("x{$pos}v", $dataPt)[0] ?? null;
            $pos += 2;
            if ($pos + $nameLen + 4 > $dirLen) return 0;
            $tag = ExifTool::DecodeStatic(null, substr($dataPt, $pos, $nameLen), 'UTF16', 'II', 'Latin');
            $tag = preg_replace('{^WM/}', '', $tag, 1); // remove leading "WM/"
            $pos += $nameLen;
            [$dType, $dLen] = Perl::unpack("x{$pos}v2", $dataPt);
            $pos += 4;
            if ($pos + $dLen > $dirLen) return 0;
            $val = self::ReadASF($et, $dataPt, $pos, $dType, $dLen);
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt : $dataPt,
                Start  : $pos,
                Size   : $dLen,
            );
            $pos += $dLen;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process ASF metadata library (similar to ProcessExtendedContentDescription above)
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessMetadata(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        if ($dirLen < 2) return 0;
        $count = ExifTool::Get16u($dataPt, 0);
        $et->VerboseDir($dirInfo, $count);
        $pos = 2;
        for ($i = 0; $i < $count; ++$i) {
            if ($pos + 12 > $dirLen) return 0;
            [$index, $stream, $nameLen, $dType, $dLen] = Perl::unpack("x{$pos}v4V", $dataPt);
            $pos += 12;
            if ($pos + $nameLen + $dLen > $dirLen) return 0;
            $tag = ExifTool::DecodeStatic(null, substr($dataPt, $pos, $nameLen), 'UTF16', 'II', 'Latin');
            $tag = preg_replace('{^WM/}', '', $tag, 1); // remove leading "WM/"
            $pos += $nameLen;
            $val = self::ReadASF($et, $dataPt, $pos, $dType, $dLen);
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt : $dataPt,
                Start  : $pos,
                Size   : $dLen,
            );
            $pos += $dLen;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process WM/Picture preview
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessPicture(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        if (!($dirLen > 9)) return 0;
        // extract picture type and length
        [$type, $picLen] = Perl::unpack("x{$dirStart}CV", $dataPt);
        $et->VerboseDir('Picture');
        $et->HandleTag($tagTablePtr, 0, $type);
        // extract mime type and description strings (null-terminated unicode strings)
        $n = $dirLen - 5 - $picLen;
        if ($n & 0x01 or $n < 4) return 0;
        $str = substr($dataPt, $dirStart + 5, $n);
        if (preg_match('/^((?:..)*?)\0\0((?:..)*?)\0\0/s', $str, $m)) {
            [$mime, $desc] = [$m[1], $m[2]];
            $et->HandleTag($tagTablePtr, 1, $et->Decode($mime, 'UTF16', 'II'));
            if (strlen($desc)) $et->HandleTag($tagTablePtr, 2, $et->Decode($desc, 'UTF16', 'II'));
        }
        $et->HandleTag($tagTablePtr, 3, substr($dataPt, $dirStart + 5 + $n, $picLen));
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process codec list
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table reference
    // Returns: 1 on success
    public static function ProcessCodecList(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        if ($dirLen < 20) return 0;
        $count = ExifTool::Get32u($dataPt, 16);
        $et->VerboseDir($dirInfo, $count);
        $pos = 20;
        $codecType = [1 => 'Video', 2 => 'Audio'];
        for ($i = 0; $i < $count; ++$i) {
            if ($pos + 8 > $dirLen) return 0;
            $type = (($codecType[ExifTool::Get16u($dataPt, $pos)] ?? null) ?: 'Other') . 'Codec';
            // stupid Windows programmers: these lengths are in characters (others are in bytes)
            $nameLen = ExifTool::Get16u($dataPt, $pos + 2) * 2;
            $pos += 4;
            if ($pos + $nameLen + 2 > $dirLen) return 0;
            $name = $et->Decode(substr($dataPt, $pos, $nameLen), 'UTF16', 'II');
            $et->HandleTag($tagTablePtr, "{$type}Name", $name);
            $descLen = ExifTool::Get16u($dataPt, $pos + $nameLen) * 2;
            $pos += $nameLen + 2;
            if ($pos + $descLen + 2 > $dirLen) return 0;
            $desc = $et->Decode(substr($dataPt, $pos, $descLen), 'UTF16', 'II');
            $et->HandleTag($tagTablePtr, "{$type}Description", $desc);
            $infoLen = ExifTool::Get16u($dataPt, $pos + $descLen);
            $pos += $descLen + 2 + $infoLen;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a ASF file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success, 0 if this wasn't a valid ASF file
    public static function ProcessASF(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $rtnVal = 0;
        $pos = 0;
        $buff = $err = null;
        $parentTable = $childEnd = [];

        for (;;) {
            if ($raf->Read($buff, 24) != 24) break;
            $pos += 24;
            $tag = self::GetGUID(substr($buff, 0, 16));
            if (!$tagTablePtr) {
                // verify this is a valid ASF file
                if ($tag !== '75B22630-668E-11CF-A6D9-00AA0062CE6C') break;
                $fileType = $et->FILE_EXT;
                if (!($fileType and preg_match('/^(ASF|WMV|WMA|DIVX)$/', $fileType))) $fileType = 'ASF';
                $et->SetFileType($fileType);
                ExifTool::SetByteOrder('II');
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ASF::Main');
                $rtnVal = 1;
            }
            $size = ExifTool::Get64u($buff, 16) - 24;
            if ($size < 0) {
                $err = 'Invalid ASF object size';
                break;
            }
            if ($size > 0x7fffffff) {
                if ($size > 0x7fffffff * 4294967296) {
                    $err = 'Invalid ASF object size';
                } elseif ($et->Options('LargeFileSupport')) {
                    if (Perl::str($et->Options('LargeFileSupport')) === '2') {
                        $et->Warn('Skipping large ASF object (LargeFileSupport is 2)');
                    }
                    if ($raf->Seek($size, 1)) {
                        $et->VPrint(0, '  Skipped large ASF object (' . Perl::str($size) . " bytes)\n");
                        $pos += $size;
                        continue;
                    }
                    $err = 'Error seeking past large ASF object';
                } else {
                    $err = 'Large ASF objects not supported (LargeFileSupport not set)';
                }
                break;
            }
            // go back to parent tag table if done with previous children
            if ($childEnd and $pos >= $childEnd[count($childEnd) - 1]) {
                array_pop($childEnd);
                $tagTablePtr = array_pop($parentTable);
                $et->INDENT = substr($et->INDENT, 0, -2);
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($verbose) $et->VerboseInfo($tag, $tagInfo);
            if ($tagInfo) {
                $subdir = $tagInfo->SubDirectory;
                if ($subdir) {
                    $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                    if (Perl::str($subTable['PROCESS_PROC']) === self::class . '::ProcessASF') {
                        if (isset($subdir['Size'])) {
                            $s = $subdir['Size'];
                            if ($verbose > 2) {
                                if ($raf->Read($buff, $s) != $s) { $err = 'Truncated file'; break; }
                                $et->VerboseDump($buff);
                            } elseif (!$raf->Seek($s, 1)) {
                                $err = 'Seek error';
                                break;
                            }
                            // continue processing linearly using subTable
                            $parentTable[] = $tagTablePtr;
                            $childEnd[] = $pos + $size;
                            $tagTablePtr = $subTable;
                            $pos += $subdir['Size'];
                            if ($verbose) {
                                $et->INDENT .= '| ';
                                $et->VerboseDir($tagInfo->Name);
                            }
                            continue;
                        }
                    } elseif ($raf->Read($buff, $size) == $size) {
                        $subdirInfo = [     // (Perl %subdirInfo)
                            'DataPt' => $buff,
                            'DirStart' => 0,
                            'DirLen' => $size,
                            'DirName' => $tagInfo->Name,
                        ];
                        if ($verbose > 2) $et->VerboseDump($buff);
                        if (!$et->ProcessDirectory($subdirInfo, $subTable, $subdir['ProcessProc'] ?? null)) {
                            $et->Warn("Error processing {$tagInfo->Name} directory");
                        }
                        $pos += $size;
                        continue;
                    } else {
                        $err = 'Unexpected end of file';
                        break;
                    }
                }
            }
            if ($verbose > 2) {
                if ($raf->Read($buff, $size) != $size) { $err = 'Truncated file'; break; }
                $et->VerboseDump($buff);
            } elseif (!$raf->Seek($size, 1)) { // skip the block
                $err = 'Seek error';
                break;
            }
            $pos += $size;
        }
        if ($err) $et->Warn($err);
        return $rtnVal;
    }
}
