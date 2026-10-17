<?php

//------------------------------------------------------------------------------
// File:         TNEF.pm
//
// Description:  Read TNEF meta information
//
// Revisions:    2025-07-08 - P. Harvey Created
//
// References:   1) https://officeprotocoldoc.z19.web.core.windows.net/files/MS-OXTNEF/%5bMS-OXTNEF%5d.pdf
//               2) https://officeprotocoldoc.z19.web.core.windows.net/files/MS-OXCMSG/%5bMS-OXCMSG%5d.pdf
//               3) https://msopenspecs.azureedge.net/files/MS-OXPROPS/%5bMS-OXPROPS%5d.pdf
//               4) https://officeprotocoldoc.z19.web.core.windows.net/files/MS-OXCDATA/%5bMS-OXCDATA%5d.pdf
//               5) https://github.com/echo-devim/pyjacktrick/blob/main/mapi_constants.py
//
// PHP port of Image::ExifTool::TNEF 1.00 (reading only). Tag tables and file lexicals
// (%propType, %fmtSize) are generated into tables/TNEF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class TNEF
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Decompress RTF text (ref https://metacpan.org/pod/Mail::Exchange::Message)
    // Inputs: 0) ExifTool ref, 1) compressed RTF
    // Returns: Decompressed RTF or '' on error
    public static function DecompressRTF(ExifTool $et, $cdat)
    {
        if (!(strlen($cdat) > 16)) return '';
        $comp = Perl::unpack('x8V', $cdat)[0];

        if ($comp == 0x414c454D) {
            return substr($cdat, 16);
        } elseif ($comp != 0x75465a4c) {
            $et->Warn(Perl::sprintf('Unknown RTF compression 0x%x', $comp));
            return '';
        }
        $dict = '{\rtf1\ansi\mac\deff0\deftab720{\fonttbl;}' .
                '{\f0\fnil \froman \fswiss \fmodern ' .
                '\fscript \fdecor MS Sans SerifSymbolArialTimes' .
                ' New RomanCourier{\colortbl\red0\green0\blue0' .
                "\r\n" . '\par \pard\plain\f0\fs20\b\i\u\tab\tx';
        $cpos = 16;
        $clen = strlen($cdat);
        $dpos = strlen($dict);
        $rtnVal = '';
        while ($cpos < $clen) {
            $control = ord($cdat[$cpos++]);
            for ($i = 0; $i < 8 && $cpos < $clen; ++$i) {
                if ($control & (1 << $i)) {
                    if ($cpos + 2 > $clen) return $rtnVal;
                    $ref = Perl::unpack('n', substr($cdat, $cpos, 2))[0];
                    $cpos += 2;
                    $off = $ref >> 4;
                    $len = ($ref & 0x0f) + 2;
                    if ($off == $dpos % 4096 or $off % 4096 >= strlen($dict)) return $rtnVal;
                    for ($j = 0; $j < $len; ++$j) {
                        $ch = $dict[$off++ % 4096];
                        $dict[$dpos++ % 4096] = $ch;
                        $rtnVal .= $ch;
                    }
                } else {
                    $ch = $cdat[$cpos++];
                    $dict[$dpos++ % 4096] = $ch;
                    $rtnVal .= $ch;
                }
            }
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process TNEF message properties
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessProps(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = strlen($dataPt);
        if (!($dirLen > 4)) return 0;
        $entries = Perl::unpack('V', $dataPt)[0];
        $et->VerboseDir('TNEF Properties', $entries);
        $pos = 4;
        for ($i = 0; $i < $entries; ++$i) {
            if ($pos + 4 > $dirLen) break;
            $type = ExifTool::Get16u($dataPt, $pos);
            $tag  = ExifTool::Get16u($dataPt, $pos + 2);
            $pos += 4;
            // handle named properties (bit 0x8000 set)
            if ($tag & 0x8000) {
                if ($pos + 24 > $dirLen) break;
                $uid = ASF::GetGUID(substr($dataPt, $pos, 16));
                $uid = preg_replace('/-0000-0000-C000-000000000046$/', '', $uid);  // remove common suffix
                $idtype = ExifTool::Get32u($dataPt, $pos + 16);
                $num = ExifTool::Get32u($dataPt, $pos + 20);
                $pos += 24;
                if ($idtype == 0) {      // number
                    $tag = $uid . Perl::sprintf('_%.8x', $num);
                } elseif ($idtype == 1) { // string
                    if ($pos + $num > $dirLen or $num < 2) break;
                    // decode string (ignoring null terminator)
                    $name = $et->Decode(substr($dataPt, $pos, $num - 2), 'UTF16');
                    $tag = "{$uid}_$name";
                    if (!$tagTbl[$tag]) {
                        ExifTool::AddTagToTable($tagTbl, $tag, [
                            'Name' => ExifTool::MakeTagName($name),
                        ]);
                    }
                    $pos += ($num + 3) & 0xfffffffc;    // (padded to an even 4 bytes)
                } else {
                    break; // error
                }
            }
            $count = 1;
            $multi = $fmt = null;
            if ($type & 0x1000) {
                $multi = 1;
                $type &= 0x0fff;
                if ($pos + 4 > $dirLen) break;
                $count = ExifTool::Get32u($dataPt, $pos);
                $pos += 4;
            }
            $fmt = Vars\TNEF::$propType[$type] ?? null;
            if (!$fmt) break;
            while ($count) {
                $size = Vars\TNEF::$fmtSize[$fmt] ?? null;
                $val = null;
                if (!$size) {
                    if (preg_match('/(\d+)/', $fmt, $m)) {
                        $size = $count * $m[1] / 8;
                    } elseif ($fmt === 'null') {
                        $val = ''; // ($size is already 0)
                    } else {
                        // skip 1 count for "special case" stupidity
                        if (!$multi) $pos += 4;
                        if ($pos + 4 > $dirLen) break;
                        $size = ExifTool::Get32u($dataPt, $pos);
                        $pos += 4;
                        if ($pos + $size > $dirLen) break;
                        $val = substr($dataPt, $pos, $size);
                    }
                }
                if ($val === null) {
                    $val = ExifTool::ReadValue($dataPt, $pos, $fmt, $count, $size);
                    if ($type == 0x06 or $type == 0x07 or $type == 0x0b or $type == 0x40) {
                        $a = Perl::split(' ', $val);
                        if ($type == 0x06) {        // currency
                            foreach ($a as &$_) $_ = Perl::num($_) / 10000;
                            unset($_);
                        } elseif ($type == 0x07) {   // OLE date
                            // convert time from days since Dec 30, 1899
                            foreach ($a as &$_) {
                                if (Perl::num($_) != 0) $_ = (Perl::num($_) - 25569) * 24 * 3600;
                                $_ = ExifTool::ConvertUnixTime($_);
                            }
                            unset($_);
                        } elseif ($type == 0x0b) {   // boolean
                            foreach ($a as &$_) $_ = $_ ? 'True' : 'False';
                            unset($_);
                        } elseif ($type == 0x40) {   // SYSTIME
                            // convert time from 100-ns intervals since Jan 1, 1601
                            foreach ($a as &$_) $_ = ExifTool::ConvertUnixTime(Perl::num($_) / 1e7 - 11644473600, 1);
                            unset($_);
                        }
                        $val = count($a) > 1 ? $a : ($a[0] ?? null);
                    }
                    $count = 1; // (read them all already)
                } elseif ($fmt === 'GUID') {
                    $val = ASF::GetGUID($val);
                } elseif ($fmt === 'Unicode') {
                    $val = preg_replace('/\0+$/', '', $et->Decode($val, 'UTF16'));
                } elseif ($fmt === 'string') {
                    $val = preg_replace('/\0+$/', '', $val);
                    // convert from specified code page if supported
                    if ($et->Charset) $val = $et->Decode($val, $et->Charset);
                } elseif ($fmt === 'undef' and strlen($val)) {
                    $val = new ScalarRef($val);  // change to a binary data reference
                }
                $et->HandleTag($tagTbl, $tag, $val,
                    DataPt:  $dataPt,
                    DataPos: $dataPos,
                    Start:   $pos,
                    Size:    $size,
                    Format:  Perl::sprintf('%s, type 0x%.2x', $fmt, $type),
                    Index:   $i,
                );
                $pos += ((int)$size + 3) & 0xfffffffc;
                --$count;
            }
        }
        if ($i != $entries) $et->Warn('Error parsing message properties');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract EXIF information from a TNEF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid TNEF file
    public static function ProcessTNEF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // verify this is a valid TNEF file (read TNEFHeader and TNEFVersion)
        if (!($raf->Read($buff, 0x15) == 0x15 and $raf->Seek(6, 0))) return 0;
        if (!preg_match('/^\x78\x9f\x3e\x22..\x01\x06\x90\x08\0/s', $buff)) return 0;
        $et->SetFileType('TNEF');
        ExifTool::SetByteOrder('II');
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::TNEF::Main');
        // read through the attributes
        while ($raf->Read($buff, 9) == 9) {
            // (ignore the attrLevel byte: 1 for message, 2 for attachment)
            [$tag, $len] = Perl::unpack('x1VV', $buff);
            // increment document number for each attachment
            if ($tag == 0x069002) $et->DOC_NUM = ++$et->DOC_COUNT;
            if ($raf->Read($buff, $len) != $len) break;
            $tagInfo = $tagTbl[$tag];
            $val = $fmt = null;
            if ($tagInfo and $tagInfo->Format) {
                $fmt = $tagInfo->Format;
                if ($fmt === 'date' and strlen($buff) >= 12) {
                    $date = Perl::unpack('v6', $buff);
                    $val = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d', ...$date);
                }
            } else {
                $val = $buff;
            }
            $et->HandleTag($tagTbl, $tag, $val,
                DataPt:  $buff,
                DataPos: $raf->Tell() - $len,
                Format:  $fmt,
            );
            if ($tag == 0x069005) $et->DOC_NUM = null; // end of attachment
            $raf->Seek(2, 1);  // skip checksum
        }
        $et->DOC_NUM = null;
        return 1;
    }
}
