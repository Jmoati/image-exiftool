<?php

//------------------------------------------------------------------------------
// File:         Font.pm
//
// Description:  Read meta information from font files
//
// Revisions:    2010/01/15 - P. Harvey Created
//
// References:   1) http://developer.apple.com/textfonts/TTRefMan/RM06/Chap6.html
//               2) http://www.microsoft.com/typography/otspec/otff.htm
//               3) http://partners.adobe.com/public/developer/opentype/index_font_file.html
//               4) http://partners.adobe.com/public/developer/en/font/5178.PFM.pdf
//               5) http://opensource.adobe.com/svn/opensource/flex/sdk/trunk/modules/compiler/src/java/flex2/compiler/util/MimeMappings.java
//               6) http://www.adobe.com/devnet/font/pdfs/5004.AFM_Spec.pdf
//               7) https://www.w3.org/TR/WOFF/
//               8) https://www.w3.org/TR/WOFF2/
//
// PHP port of Image::ExifTool::Font 1.13. Tag tables are generated into tables/Font.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\Font as Vars;

final class Font
{
    public static $VERSION = '1.13';

    //------------------------------------------------------------------------------
    // Read information from a TrueType font collection (TTC) (refs 2,3)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid TrueType font collection
    public static function ProcessTTC(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if ($raf->Read($buff, 12) != 12) return 0;
        if (!preg_match('/^ttcf\x00[\x01\x02]\x00\x00/', $buff)) return 0;
        ExifTool::SetByteOrder('MM');
        $num = ExifTool::Get32u($buff, 8);
        // might as well put a limit on the number of fonts we will parse (< 256)
        if (!($num < 0x100 and $raf->Read($buff, $num * 4) == $num * 4)) return 0;
        $et->SetFileType('TTC');
        if ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3) return 1;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Font::Main');
        $et->HandleTag($tagTablePtr, 'numfonts', $num);
        // loop through all fonts in the collection
        for ($i = 0; $i < $num; ++$i) {
            $n = $i + 1;
            $et->VPrint(0, "Font $n:\n");
            $et->SET_GROUP1 = "+$n";
            $offset = ExifTool::Get32u($buff, $i * 4);
            if (!$raf->Seek($offset, 0)) break;
            if (!self::ProcessOTF($et, $dirInfo)) break;
        }
        $et->SET_GROUP1 = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process an OTF tag table entry (refs 1,2)
    // Inputs: 0) ExifTool ref, 1) entry index, 2) tag, 3) data ref,
    //         4) offset if uncompressed, 5) true to skip because data is transformed
    // Returns: undef on success, 0 to stop processing table, or error string otherwise
    public static function ProcessTableEntry(ExifTool $et, $idx, $tag, $dataPt, $offset = null, $transformed = null)
    {
        $verbose = $et->Options('Verbose');
        $size = strlen($dataPt);

        $offset or $offset = 0;
        if ($verbose) {
            $tag = preg_replace_callback('/([\x00-\x1f\x7f-\xff])/', static fn ($m) => Perl::sprintf('\x%.2x', ord($m[1])), $tag);
            $str = Perl::sprintf("%s%d) Tag '%s' (offset 0x%.4x, %d bytes)\n",
                                 $et->INDENT, $idx, $tag, $offset, $size);
            $et->VPrint(0, $str);
            if ($verbose > 2) $et->VerboseDump($dataPt, Addr: $offset);
            if (empty(Vars::$processTag[$tag])) return null;
        }
        if ($transformed or $size < 8) return null;
        if ($tag !== 'name') {
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Font::Main');
            $et->HandleTag($tagTablePtr, $tag, null, DataPt: $dataPt, Size: strlen($dataPt));
            return null;
        }
        // process the 'name' tag
        $entries = ExifTool::Get16u($dataPt, 2);
        $recEnd = 6 + $entries * 12;
        if ($recEnd > $size) {
            $et->Warn('Truncated name record');
            return 0;
        }
        $strStart = ExifTool::Get16u($dataPt, 4);
        if ($strStart < $recEnd or $strStart > $size) {
            $et->Warn('Invalid string offset');
            return 0;
        }
        // parse language-tag record (in format 1 Naming table only) (ref 2)
        $langTag = [];
        if (ExifTool::Get16u($dataPt, 0) == 1 and $recEnd + 2 <= $size) {
            $langTags = ExifTool::Get16u($dataPt, $recEnd);
            if ($langTags and $recEnd + 2 + $langTags * 4 < $size) {
                for ($i = 0; $i < $langTags; ++$i) {
                    $pt = $recEnd + 2 + $i * 4;
                    $langLen = ExifTool::Get16u($dataPt, $pt);
                    // make sure the language string length is reasonable (UTF-16BE)
                    if ($langLen == 0 or $langLen & 0x01 or $langLen > 40) break;
                    $langPt = ExifTool::Get16u($dataPt, $pt + 2) + $strStart;
                    if ($langPt + $langLen > $size) break;
                    $lang = substr($dataPt, $langPt, $langLen);
                    $lang = $et->Decode($lang, 'UCS2', 'MM', 'UTF8');
                    $lang = preg_replace('/[^-_a-zA-Z0-9]/', '', $lang);  // remove naughty characters
                    $langTag[$i + 0x8000] = $lang;
                }
            }
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Font::Name');
        $oldIndent = $et->INDENT;
        $et->INDENT .= '| ';
        if ($verbose) $et->VerboseDir('Name', $entries);
        for ($i = 0; $i < $entries; ++$i) {
            $pt = 6 + $i * 12;
            $platform = ExifTool::Get16u($dataPt, $pt);
            $encoding = ExifTool::Get16u($dataPt, $pt + 2);
            $langID   = ExifTool::Get16u($dataPt, $pt + 4);
            $nameID   = ExifTool::Get16u($dataPt, $pt + 6);
            $strLen   = ExifTool::Get16u($dataPt, $pt + 8);
            $strPt    = ExifTool::Get16u($dataPt, $pt + 10) + $strStart;
            if ($strPt + $strLen <= $size) {
                $val = substr($dataPt, $strPt, $strLen);
                $lang = $charset = $extra = null;
                $sys = Vars::$ttPlatform[$platform] ?? null;
                // translate from specified encoding
                if ($sys) {
                    $lang = (Vars::$ttLang[$sys][$langID] ?? null) ?: ($langTag[$langID] ?? null);
                    $charset = Vars::$ttCharset[$sys][$encoding] ?? null;
                    if (!$charset) {
                        if ($charset === null and !$et->FontWarn) {
                            $et->Warn("Unknown $sys character set ($encoding)");
                            $et->FontWarn = 1;
                        }
                    } else {
                        // translate to ExifTool character set
                        $val = $et->Decode($val, $charset);
                    }
                } else {
                    $et->Warn("Unknown platform ($platform) for name $nameID");
                }
                // get the tagInfo for our specific language (use 'en' for default)
                $tagInfo = $et->GetTagInfo($tagTablePtr, $nameID);
                if ($tagInfo and $lang and $lang !== 'en') {
                    $langInfo = ExifTool::GetLangInfo($tagInfo, $lang);
                    if ($langInfo) $tagInfo = $langInfo;
                }
                if ($verbose) {
                    if ($langID > 0x400) $langID = Perl::sprintf('0x%x', $langID);
                    $extra = ", Plat=$platform/" . ($sys ?: 'Unknown') . ', ' .
                             "Enc=$encoding/" . ($charset ?: 'Unknown') . ', ' .
                             "Lang=$langID/" . ($lang ?: 'Unknown');
                }
                $et->HandleTag($tagTablePtr, $nameID, $val,
                    TagInfo: $tagInfo,
                    DataPt:  $dataPt,
                    DataPos: $offset,
                    Start:   $strPt,
                    Size:    $strLen,
                    Index:   $i,
                    Extra:   $extra,
                );
            }
        }
        $et->INDENT = $oldIndent;
        return $verbose ? null : 0;
    }

    //------------------------------------------------------------------------------
    // Read information from a TrueType font file (OTF or TTF) (refs 1,2)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid TrueType font file
    public static function ProcessOTF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $tbl = $buff = null;
        $base = ($dirInfo['Base'] ?? null) ?: 0;

        if ($raf->Read($buff, 12) != 12) return 0;
        if (!preg_match('/^(\x00\x01\x00\x00|OTTO|true|typ1|\xa5(kbd|lst))[\x00\x01]/', $buff, $m)) return 0;

        $et->SetFileType($m[1] === 'OTTO' ? 'OTF' : 'TTF');
        if ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3) return 1;
        ExifTool::SetByteOrder('MM');
        $numTables = ExifTool::Get16u($buff, 4);
        if (!($numTables > 0 and $numTables < 0x200)) return 0;
        $len = $numTables * 16;
        if ($raf->Read($tbl, $len) != $len) return 0;

        $verbose = $et->Options('Verbose');

        for ($pos = 0, $i = 0; $pos < $len; $pos += 16, ++$i) {
            // look for tags to process
            $tag = substr($tbl, $pos, 4);
            if (!(!empty(Vars::$processTag[$tag]) or $verbose)) continue;
            $offset = ExifTool::Get32u($tbl, $pos + 8);
            $size   = ExifTool::Get32u($tbl, $pos + 12);
            if (!($raf->Seek($offset + $base, 0) and $raf->Read($buff, $size) == $size)) {
                $et->Warn("Error reading '{$tag}' data");
                continue;
            }
            $err = self::ProcessTableEntry($et, $i, $tag, $buff, $offset);
            if ($err) {
                $et->Warn($err);
                break;
            }
            if ($err !== null) break;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information from an Adobe Font Metrics file (AFM, ACFM, AMFM) (ref 6)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a recognized AFM-type file
    public static function ProcessAFM(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $comment = null;

        ExifTool::RequireModule('Image::ExifTool::PostScript');
        $oldRS = Perl::$RS;     // (Perl: local $/)
        try {
            Perl::$RS = PostScript::GetInputRecordSeparator($raf);
            $raf->ReadLine($buff);
            if (!preg_match('/^Start(Comp|Master)?FontMetrics\s+\d+/', Perl::str($buff), $m)) return 0;
            $ftyp = !empty($m[1]) ? ($m[1] === 'Comp' ? 'ACFM' : 'AMFM') : 'AFM';
            $et->SetFileType($ftyp, 'application/x-font-afm');
            if ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3) return 1;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Font::AFM');

            for (;;) {
                if (!$raf->ReadLine($buff)) break;
                if ($comment !== null and !preg_match('/^Comment\s/', $buff)) {
                    $et->FoundTag('Comment', $comment);
                    $comment = null;
                }
                if (!preg_match('/^(\w+)\s+(.*?)[\x0d\x0a]/', $buff, $m)) continue;
                [$tag, $val] = [$m[1], $m[2]];
                if ($tag === 'Comment' and preg_match('/^(Creation Date):\s+(.*)/', $val, $m)) {
                    [$tag, $val] = [$m[1], $m[2]];
                }
                $val = preg_replace('/^\((.*)\)$/', '$1', $val, 1);    // (some values may be in brackets)
                if ($tag === 'Comment') {
                    // concatinate all comments into a single value
                    $comment = $comment !== null ? "$comment\n$val" : $val;
                    continue;
                }
                if (!$et->HandleTag($tagTablePtr, $tag, $val)) {
                    // end parsing if we start any subsection
                    if (str_starts_with($tag, 'Start') and $tag !== 'StartDirection') break;
                }
            }
        } finally {
            Perl::$RS = $oldRS;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read WOFF2 255UInt16 integer (ref 8)
    // Inputs: 0) raf ref
    // Returns: value, or undef on error
    public static function Read255UInt16(RandomAccess $raf)
    {
        $buff = null;
        if (!$raf->Read($buff, 1)) return null;
        $val = ord($buff);
        if ($val == 253) {
            if ($raf->Read($buff, 2) != 2) return null;
            $val = Perl::unpack('n', $buff)[0];
        } elseif ($val == 254) {
            if (!$raf->Read($buff, 1)) return null;
            $val = ord($buff) + 253 * 2;
        } elseif ($val == 255) {
            if (!$raf->Read($buff, 1)) return null;
            $val = ord($buff) + 253;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Read WOFF2 UIntBase128 integer (ref 8)
    // Inputs: 0) raf ref
    // Returns: value, or undef on error
    public static function ReadUIntBase128(RandomAccess $raf)
    {
        $buff = null;
        $val = 0;
        foreach (range(0, 4) as $n) {
            if (!$raf->Read($buff, 1)) return null;
            $byte = ord($buff);
            if (!$n and $byte == 0x80) return null;
            if ($val & 0xfe000000) return null;
            $val = ($val << 7) | ($byte & 0x7f);
            if (!($byte & 0x80)) return $val;
        }
        return null;    // can't be longer than 5 bytes
    }

    //------------------------------------------------------------------------------
    // Uncompress data
    // Inputs: 0) ExifTool ref, 1) data ref
    // Returns: true on success
    public static function Uncompress(ExifTool $et, &$dataPt)
    {
        if (!function_exists('inflate_init')) {
            $et->Warn('Install Compress::Zlib to read compressed metadata');
            return 0;
        }
        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        $out = $inflate ? @inflate_add($inflate, Perl::str($dataPt), ZLIB_SYNC_FLUSH) : false;
        if ($out !== false) $dataPt = $out;
        if (!($inflate and $out !== false and inflate_get_status($inflate) == ZLIB_STREAM_END)) {
            $et->Warn('Error uncompressing metadata');
            return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Brotli uncompress data
    // Inputs: 0) ExifTool ref, 1) data ref
    // Returns: true on success
    public static function Unbrotli(ExifTool $et, &$dataPt)
    {
        // (PHP: the brotli PECL extension replaces IO::Uncompress::Brotli)
        if (!function_exists('brotli_uncompress')) {
            $et->Warn('Install IO::Compress::Brotli to decode Brotli-compressed metadata');
            return 0;
        }
        $out = @brotli_uncompress(Perl::str($dataPt), 100000000);
        if ($out === false) {
            $et->Warn('Error decoding metadata');
            $et->Warn('Try updating to IO::Uncompress::Brotli 0.004 or later');
            return 0;
        }
        $dataPt = $out;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information from WOFF/WOFF2 font files
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a recognized WOFF file
    public static function ProcessWOFF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tbl = null;
        if (!($raf->Seek(0, 0) and $raf->Read($buff, 48) == 48)) return 0;
        if (!preg_match('/^(wOF[F2])/', $buff, $m)) return 0;
        [$type, $off] = $m[1] === 'wOFF' ? ['woff', 20] : ['woff2', 24];
        $et->SetFileType(strtoupper($type), "font/$type");
        ExifTool::SetByteOrder('MM');
        $flavor = substr($buff, 4, 4);
        $numTables = ExifTool::Get16u($buff, 12);
        [$vh, $vl, $metaPos, $metaLen] = Perl::unpack("x{$off}nnNN", $buff);
        $verbose = $et->Options('Verbose');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Font::Main');
        $et->HandleTag($tagTablePtr, 'WOFFVersion', "$vh.$vl");
        //
        // read font table
        //
        if ($type === 'woff') {
            if (!($raf->Seek($off + 24, 0) and $raf->Read($tbl, $numTables * 20) == $numTables * 20)) {
                $et->Warn('Error reading font table');
                return 1;
            }
            for ($i = 0; $i < $numTables; ++$i) {
                $pt = $i * 20;
                [$tag, $pos, $compLen, $len] = Perl::unpack("x{$pt}a4N3", $tbl);
                if (!(!empty(Vars::$processTag[$tag]) or $verbose)) continue;
                if (!($raf->Seek($pos, 0) and $raf->Read($buff, $compLen) == $compLen)) {
                    $et->Warn('Bad font table entry');
                    return 1;
                }
                $dataPos = null;
                if ($compLen == $len) {
                    $dataPos = $pos;
                } else {
                    if (!self::Uncompress($et, $buff)) continue;
                }
                $err = self::ProcessTableEntry($et, $i, $tag, $buff, $dataPos);
                if ($err) {
                    $et->Warn($err);
                    return 1;
                }
                if ($err !== null) break;
            }
        } else { // WOFF2
            $compSize = ExifTool::Get32u($buff, 20);
            $err = null;
            $entry = [];
            if (!$raf->Seek($off + 24, 0)) {
                $et->Warn('Error seeking to font table');
                return 1;
            }
            for ($i = 0; $i < $numTables; ++$i) {
                if (!$raf->Read($buff, 1)) {
                    $err = 1;
                    break;
                }
                $flags = ord($buff);
                $tag = Vars::$knownTags[$flags & 0x3f] ?? null;
                if (!$tag and $raf->Read($tag, 4) != 4) {
                    $err = 1;
                    break;
                }
                $len = self::ReadUIntBase128($raf);
                if ($len === null) {
                    $err = 1;
                    break;
                }
                $transformed = null;
                if (($tag === 'glyf' or $tag === 'loca') xor (bool) ($flags & 0xc0)) {
                    // a non-null transform was used
                    $len = self::ReadUIntBase128($raf);
                    $transformed = 1;
                }
                // save information about this entry for later
                $entry[] = [$i, $tag, $len, $transformed];
            }
            if ($err) {
                $et->Warn('Error reading font table');
                return 1;
            }
            // skip the collection table if necessary
            if ($flavor === 'ttcf') {
                if (!$raf->Seek(4, 1)) {
                    $et->Warn('Seek error');
                    return 1;
                }
                $n = self::Read255UInt16($raf);
                if (!($n !== null and $raf->Seek(4, 1))) {
                    $et->Warn('Error reading collection table');
                    return 1;
                }
                // (Perl: "last" outside a loop exits the loop of the caller)
                if (!$raf->Seek(4, 1)) return 1;
                for ($i = 0; $i < $n; ++$i) {
                    if (self::Read255UInt16($raf) === null) {
                        $err = 1;
                        break;
                    }
                }
                if ($err) {
                    $et->Warn('Error reading collection directory');
                    return 1;
                }
            }
            if ($raf->Read($buff, $compSize) != $compSize) {
                $et->Warn('Error reading font data');
                return 1;
            }
            if (!self::Unbrotli($et, $buff)) return 1;
            // after all that exhausting and frankly unnecessary work (poor file design),
            // we finally have the uncompressed font data so we can process the table entries
            $pos = 0;
            foreach ($entry as [$i, $tag, $len, $transformed]) {
                if (!empty(Vars::$processTag[$tag]) or $verbose) {
                    $dat = substr($buff, $pos, (int) $len);
                    $err = self::ProcessTableEntry($et, $i, $tag, $dat, null, $transformed);
                    if ($err) {
                        $et->Warn($err);
                        return 1;
                    }
                    if ($err !== null) break;
                }
                $pos += $len;
            }
        }
        //
        // read compressed XML-format metadata (NC)
        //
        if ($metaLen) {
            if (!($raf->Seek($metaPos, 0) and $raf->Read($buff, $metaLen) == $metaLen)) {
                $et->Warn('Error reading metadata');
                return 1;
            }
            if ($type === 'woff') {
                if (!self::Uncompress($et, $buff)) return 1;
            } else { // WOFF2
                if (!self::Unbrotli($et, $buff)) return 1;
            }
            // (we don't properly support XML structures)
            $oldStruct = $et->Options('Struct');
            $et->Options('Struct', 0);
            $et->HandleTag($tagTablePtr, 'XML', $buff);
            $et->Options('Struct', $oldStruct);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information from various format font files
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a recognized Font file
    public static function ProcessFont(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $rtnVal = null;
        if (!($raf->Read($buff, 24) and $raf->Seek(0, 0))) return 0;
        if (preg_match('/^(\x00\x01\x00\x00|OTTO|true|typ1)[\x00\x01]/', $buff)) {        // OTF, TTF
            $rtnVal = self::ProcessOTF($et, $dirInfo);
        } elseif (preg_match('/^ttcf\x00[\x01\x02]\x00\x00/', $buff)) {                    // TTC
            $rtnVal = self::ProcessTTC($et, $dirInfo);
        } elseif (preg_match('/^Start(Comp|Master)?FontMetrics\s+\d+/s', $buff)) {       // AFM
            $rtnVal = self::ProcessAFM($et, $dirInfo);
        } elseif (preg_match('/^(.{6})?%!(PS-(AdobeFont-|Bitstream )|FontType1-)/s', $buff, $m)) {// PFA, PFB
            if (($m[1] ?? '') !== '' and $raf->Seek(6, 0)) $et->SetFileType('PFB');
            ExifTool::RequireModule('Image::ExifTool::PostScript');
            $rtnVal = PostScript::ProcessPS($et, $dirInfo);
        } elseif (preg_match('/^\x00[\x01\x02]/', $buff) and $raf->Seek(0, 2) and   // PFM
                 // validate file size
                 $raf->Tell() > 117 and $raf->Tell() == Perl::unpack('x2V', $buff)[0] and
                 // read PFM header
                 $raf->Seek(0, 0) and $raf->Read($buff, 117) == 117 and
                 // validate "DeviceType" string (must be "PostScript\0")
                 ExifTool::SetByteOrder('II') and $raf->Seek(ExifTool::Get32u($buff, 101), 0) and
                 // the DeviceType should be "PostScript\0", but FontForge
                 // incorrectly writes "Postscript\0", so ignore case
                 $raf->Read($buf2, 11) == 11 and strtolower($buf2) === "postscript\0")
        {
            $et->SetFileType('PFM');
            if ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3) return 1;
            ExifTool::SetByteOrder('II');
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Font::Main');
            // process the PFM header
            $et->HandleTag($tagTablePtr, 'PFM', $buff);
            // extract the font names
            $nameOff = ExifTool::Get32u($buff, 105);
            if ($raf->Seek($nameOff, 0) and $raf->Read($buff, 256) and
                preg_match('/^([\x20-\xff]+)\x00([\x20-\xff]+)\x00/', $buff, $m))
            {
                $et->HandleTag($tagTablePtr, 'fontname', $m[1]);
                $et->HandleTag($tagTablePtr, 'postfont', $m[2]);
            }
            $rtnVal = 1;
        } elseif (preg_match('/^(wOF[F2])/', $buff)) {
            $rtnVal = self::ProcessWOFF($et, $dirInfo);
        } else {
            $rtnVal = 0;
        }
        return $rtnVal;
    }
}
