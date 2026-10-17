<?php

//------------------------------------------------------------------------------
// File:         PNG.pm
//
// Description:  Read and write PNG meta information
//
// Revisions:    06/10/2005 - P. Harvey Created
//               06/23/2005 - P. Harvey Added MNG and JNG support
//               09/16/2005 - P. Harvey Added write support
//
// References:   1) http://www.libpng.org/pub/png/spec/1.2/
//               2) http://www.faqs.org/docs/png/
//               3) http://www.libpng.org/pub/mng/
//               4) http://www.libpng.org/pub/png/spec/register/
//               5) ftp://ftp.simplesystems.org/pub/png/documents/pngext-1.4.0-pdg.html
//               6) ftp://ftp.simplesystems.org/pub/png/documents/pngext-1.5.0.html
//
// Notes:        Writing meta information in PNG images is a pain in the butt
//               for a number of reasons:  One biggie is that you have to
//               decompress then decode the ASCII/hex profile information before
//               you can edit it, then you have to ASCII/hex-encode, recompress
//               and calculate a CRC before you can write it out again.  gaaaak.
//
// PHP port of Image::ExifTool::PNG 1.71 (reading only). Tag tables are generated
// into tables/PNG.php, module variables into tables/vars/PNG.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PNG
{
    public static $VERSION = '1.71';

    // CRC lookup table (my @crcTable in WritePNG.pl)
    private static array $crcTable = [];

    //------------------------------------------------------------------------------
    // Calculate CRC or update running CRC (ref 1)
    // Inputs: 0) data reference, 1) running crc to update (undef initially)
    //         2) data position (undef for 0), 3) data length (undef for all data),
    // Returns: updated CRC
    // (from WritePNG.pl, used when reading to check the CRC in Verbose and Validate modes)
    public static function CalculateCRC($dataPt, $crc = null, $pos = null, $len = null)
    {
        if ($crc === null) $crc = 0;
        if ($pos === null) $pos = 0;
        if ($len === null) $len = strlen($dataPt) - $pos;
        $crc ^= 0xffffffff;         // undo 1's complement
        // build lookup table unless done already
        if (!self::$crcTable) {
            for ($n = 0; $n < 256; ++$n) {
                for ($k = 0, $c = $n; $k < 8; ++$k) {
                    $c = ($c & 1) ? 0xedb88320 ^ ($c >> 1) : $c >> 1;
                }
                self::$crcTable[$n] = $c;
            }
        }
        // calculate the CRC
        foreach (unpack('C*', substr($dataPt, $pos, $len)) as $_) {
            $crc = self::$crcTable[($crc ^ $_) & 0xff] ^ ($crc >> 8);
        }
        return $crc ^ 0xffffffff;   // return 1's complement
    }

    //------------------------------------------------------------------------------
    // (PHP) Compress::Zlib's inflate() removes the input it consumed from its argument, so
    // after a failed inflate Perl keeps only the unconsumed bytes of the value: find them by
    // feeding the data byte by byte until zlib reports the error (all is consumed otherwise)
    private static function UnconsumedInput($val)
    {
        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        $len = strlen($val);
        for ($i = 0; $i < $len; ) {
            $ok = @inflate_add($inflate, $val[$i++], ZLIB_SYNC_FLUSH);
            if ($ok === false or inflate_get_status($inflate) === ZLIB_STREAM_END) break;
        }
        return substr($val, $i);
    }

    //------------------------------------------------------------------------------
    // Get standard case for language code (this routine copied from XMP.pm)
    // Inputs: 0) Language code
    // Returns: Language code in standard case
    public static function StandardLangCase($lang)
    {
        $lang = Perl::str($lang);
        // make 2nd subtag uppercase only if it is 2 letters
        if (preg_match('/^([a-z]{2,3}|[xi])(-[a-z]{2})\b(.*)/i', $lang, $m)) {
            return strtolower($m[1]) . strtoupper($m[2]) . strtolower($m[3]);
        }
        return strtolower($lang);
    }

    //------------------------------------------------------------------------------
    // Convert date from PNG to EXIF format
    // Inputs: 0) Date/time in PNG format, 1) ExifTool ref
    // Returns: EXIF formatted date/time string
    public static function ConvertPNGDate($val, $et)
    {
        // standard format is like "Mon, 1 Jan 2018 12:10:22 EST" (RFC-1123 section 5.2.14)
        while (preg_match('/(\d+)\s*(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s*(\d+)\s+(\d+):(\d{2})(:\d{2})?\s*(\S*)/i', Perl::str($val), $m)) {
            [, $day, $mon, $yr, $hr, $min, $sec, $tz] = $m;
            if ($yr < 100) $yr += $yr > 70 ? 1900 : 2000;   // boost year to 4 digits if necessary
            $mon = Vars\PNG::$monthNum[ucfirst(strtolower($mon))] ?? null;
            if (!$mon) return $val;
            if (!$tz) {
                $tz = '';
            } elseif (!empty(Vars\PNG::$tzConv[strtoupper($tz)])) {
                $tz = Vars\PNG::$tzConv[strtoupper($tz)];
            } elseif (preg_match('/^([-+]\d+):?(\d{2})/', $tz, $mm)) {
                $tz = $mm[1] . ':' . $mm[2];
            } else {
                break;      // (non-standard date)
            }
            return Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d%s%s', $yr, $mon, $day, $hr, $min, $sec ?: ':00', $tz);
        }
        if (($et->Options('StrictDate') and !$et->TAGS_FROM_FILE) or $et->Options('Validate')) {
            $et->Warn('Non standard PNG date/time format', 1);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Get localized version of tagInfo hash
    // Inputs: 0) tagInfo hash ref, 1) language code (eg. "x-default")
    // Returns: new tagInfo hash ref, or undef if invalid
    public static function GetLangInfo(TagInfo $tagInfo, $lang)
    {
        $lang = str_replace('_', '-', Perl::str($lang));    // RFC 3066 specifies '-' as a separator
        // no alternate languages for XMP or raw profile directories
        if ($tagInfo->SubDirectory) return null;
        // language code must normalized for use in tag ID
        return ExifTool::GetLangInfo($tagInfo, self::StandardLangCase($lang));
    }

    //------------------------------------------------------------------------------
    // Found a PNG tag -- extract info from subdirectory or decompress data if necessary
    // Inputs: 0) ExifTool object reference, 1) Pointer to tag table,
    //         2) Tag ID, 3) Tag value, 4) [optional] compressed data flag:
    //            0=not compressed, 1=unknown compression, 2-N=compression with type N-2
    //         5) optional output buffer ref, 6) character encoding (tEXt/zTXt/iTXt only)
    //         6) optional language code
    // Returns: 1 on success
    // (PHP: reading only, $outBuff is always undef)
    public static function FoundPNG(ExifTool $et, TagTable $tagTablePtr, $tag, $val, $compressed = null, $outBuff = null, $enc = null, $lang = null)
    {
        if ($val === null) return 0;
        $verbose = $et->Options('Verbose');
        $id = $tag;     // generate tag ID which includes language code
        if ($lang) {
            // case of language code must be normalized since they are case insensitive
            $lang = self::StandardLangCase($lang);
            $id .= '-' . $lang;
        }
        $tagInfo = $et->GetTagInfo($tagTablePtr, $id) ?:
                   // (some software forgets to capitalize first letter)
                   $et->GetTagInfo($tagTablePtr, ucfirst($id));
        // create alternate language tag if necessary
        if (!$tagInfo and $lang) {
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag) ?:
                       $et->GetTagInfo($tagTablePtr, ucfirst($tag));
            if ($tagInfo) $tagInfo = self::GetLangInfo($tagInfo, $lang);
        }
//
// uncompress data if necessary
//
        $wasCompressed = $deflateErr = null;
        if ($compressed and $compressed > 1) {
            if ($compressed == 2) { // Inflate/Deflate compression
                if (function_exists('inflate_init')) {
                    $v2 = $stat = null;
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    if ($inflate) {
                        $v2 = @inflate_add($inflate, $val, ZLIB_SYNC_FLUSH);
                        $stat = inflate_get_status($inflate);
                    }
                    if ($inflate and $v2 !== false and $stat === ZLIB_STREAM_END) {
                        $val = $v2;
                        $compressed = 0;
                        $wasCompressed = 1;
                    } else {
                        $deflateErr = "Error inflating $tag";
                        $val = self::UnconsumedInput($val);
                    }
                } elseif (!Vars\PNG::$noCompressLib) {
                    $deflateErr = 'Install Compress::Zlib to read compressed information';
                } else {
                    $deflateErr = '';   // flag deflate error but no warning
                }
            } else {
                $compressed -= 2;
                $deflateErr = "Unknown compression method $compressed for $tag";
            }
            if ($compressed and $verbose and $tagInfo and $tagInfo->SubDirectory) {
                $et->VerboseDir("Unable to decompress {$tagInfo->Name}", 0, strlen($val));
            }
            // issue warning if relevant
            if ($deflateErr and !$outBuff) {
                $et->Warn($deflateErr);
                if (preg_match('/^Install/', $deflateErr)) Vars\PNG::$noCompressLib = 1;
            }
        }
        // translate character encoding if necessary (tEXt/zTXt/iTXt string values only)
        if ($enc and !$compressed and !($tagInfo and $tagInfo->SubDirectory)) {
            $val = $et->Decode($val, $enc);
        }
//
// extract information from subdirectory if available
//
        if ($tagInfo) {
            $tagName = $tagInfo->Name;
            $processed = null;
            if ($tagInfo->SubDirectory) {
                if ($et->OPTIONS['Validate'] and $tagInfo->NonStandard) {
                    $et->Warn("Non-standard {$tagInfo->NonStandard} in PNG $tag chunk", 1);
                }
                $subdir = $tagInfo->SubDirectory;
                $dirName = ($subdir['DirName'] ?? null) ?: $tagName;
                if (!$compressed) {
                    $len = strlen($val);
                    if ($verbose and $et->INDENT !== '  ') {
                        if ($wasCompressed and $verbose > 2) {
                            $name = $tagName;
                            if ($wasCompressed) $name = "Decompressed $name";
                            $et->VerboseDir($name, 0, $len);
                            $et->VerboseDump($val);
                        }
                        // don't indent next directory (since it is really the same data)
                        $et->INDENT = preg_replace('/..$/', '', $et->INDENT, 1);
                    }
                    $processProc = $subdir['ProcessProc'] ?? null;
                    $subTable = ExifTool::GetTagTable($subdir['TagTable'] ?? null);
                    // (writing not ported)
                    $subdirInfo = [
                        'DataPt'   => $val,
                        'DirStart' => 0,
                        'DataLen'  => $len,
                        'DirLen'   => $len,
                        'DirName'  => $dirName,
                        'TagInfo'  => $tagInfo,
                        'ReadOnly' => 1, // (used only by WriteXMP)
                        'OutBuff'  => $outBuff,
                        'IgnoreProp' => $subdir['IgnoreProp'] ?? null, // (XML hack for meTa chunk)
                    ];
                    // no need to re-decompress if already done
                    if ($wasCompressed and $processProc and $processProc === 'Image\ExifTool\PNG::ProcessPNG_Compressed') $processProc = null;
                    $processed = $et->ProcessDirectory($subdirInfo, $subTable, $processProc);
                    $compressed = 1;    // pretend this is compressed since it is binary data
                }
            }
            if ($processed) return 1;
        } else {
            $name = preg_replace_callback('/\s+(.)/', static fn ($m) => ucfirst($m[1]), Perl::str($tag));   // remove white space from tag name
            $tagInfo = new TagInfo(['Name' => $name]);
            if ($lang) $tagInfo->LangCode = $lang;
            // make unknown profiles binary data type
            if (preg_match('/^Raw profile type /', Perl::str($tag))) $tagInfo->Binary = 1;
            if ($verbose) $et->VPrint(0, "  [adding $tag]\n");
            ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
        }
//
// store this tag information
//
        if ($verbose) {
            // temporarily remove subdirectory so it isn't printed in verbose information
            // since we aren't decoding it anyway;
            $subdir = $tagInfo->SubDirectory;
            unset($tagInfo->SubDirectory);
            $et->VerboseInfo($tag, $tagInfo,
                Table:  $tagTablePtr,
                DataPt: $val,
            );
            if ($subdir) $tagInfo->SubDirectory = $subdir;
        }
        // set the RawConv dynamically depending on whether this is binary or not
        $delRawConv = null;
        if ($compressed and $tagInfo->ValueConv === null) {
            $tagInfo->RawConv = static fn ($val) => new ScalarRef($val);    // ('\$val')
            $delRawConv = 1;
        }
        $et->FoundTag($tagInfo, $val);
        if ($delRawConv) unset($tagInfo->RawConv);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process encoded PNG profile information
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) Pointer to tag table
    // Returns: 1 on success
    public static function ProcessProfile(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $tagInfo = $dirInfo['TagInfo'];
        $tagName = $tagInfo->Name;

        // ImageMagick 5.3.6 writes profiles with the following headers:
        // "\nICC Profile\n", "\nIPTC profile\n", "\n\xaa\x01{generic prof\n"
        // and "\ngeneric profile\n"
        if (!preg_match('/^\n(.*?)\n\s*(\d+)\n(.*)/s', $dataPt, $m)) return 0;
        [$profileType, $len] = [$m[1], $m[2]];
        // data is encoded in hex, so change back to binary
        $buff = Perl::pack('H*', implode('', Perl::split(' ', $m[3])));
        $actualLen = strlen($buff);
        if (Perl::str($len) !== Perl::str($actualLen)) {
            $et->Warn("$tagName is wrong size (should be $len bytes but is $actualLen)");
            $len = $actualLen;
        }
        $verbose = $et->Options('Verbose');
        if ($verbose) {
            if ($verbose > 2) {
                $et->VerboseDir("Decoded $tagName", 0, $len);
                $et->VerboseDump($buff);
            }
            // don't indent next directory (since it is really the same data)
            $et->INDENT = preg_replace('/..$/', '', $et->INDENT, 1);
        }
        $subdirInfo = [     // (Perl %dirInfo)
            'Parent'   => 'PNG',
            'DataPt'   => $buff,
            'DataLen'  => $len,
            'DirStart' => 0,
            'DirLen'   => $len,
            'Base'     => 0,
        ];
        $et->PROCESSED = [];    // reset processed directory offsets
        $processed = 0;
        $exifTable = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');

        if ($tagTablePtr !== $exifTable) {
            // this is unfortunate, but the "IPTC" profile may be stored as either
            // IPTC IIM or a Photoshop IRB resource, so we must test for this
            if ($tagName === 'IPTC_Profile' and preg_match('/^\x1c/', $buff)) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::IPTC::Main');
            }
            // process non-EXIF and non-APP1 profile as-is
            $processed = $et->ProcessDirectory($subdirInfo, $tagTablePtr);
        } elseif (preg_match('#^' . Vars\ExifTool::$exifAPP1hdr . '#', $buff)) {
            // APP1 EXIF information
            $hdrLen = strlen(Vars\ExifTool::$exifAPP1hdr);
            $subdirInfo['DirStart'] += $hdrLen;
            $subdirInfo['DirLen'] -= $hdrLen;
            $processed = $et->ProcessTIFF($subdirInfo);
        } elseif (preg_match('#^' . Vars\ExifTool::$xmpAPP1hdr . '#', $buff)) {
            // APP1 XMP information
            $hdrLen = strlen(Vars\ExifTool::$xmpAPP1hdr);
            $xmpTable = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
            $subdirInfo['DirStart'] += $hdrLen;
            $subdirInfo['DirLen'] -= $hdrLen;
            $processed = $et->ProcessDirectory($subdirInfo, $xmpTable);
        } elseif (preg_match('/^(MM\0\x2a|II\x2a\0)/', $buff)) {
            // TIFF information
            $processed = $et->ProcessTIFF($subdirInfo);
        } else {
            $profName = preg_replace('/[\x00-\x1f\x7f-\xff]/', '.', $profileType);
            $et->Warn("Unknown raw profile '{$profName}'");
        }
        // (writing not ported)
        return $processed;
    }

    //------------------------------------------------------------------------------
    // Process PNG compressed zTXt or iCCP chunk
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) Pointer to tag table
    // Returns: 1 on success
    // Notes: writes new chunk data to ${$$dirInfo{OutBuff}} if writing tag
    public static function ProcessPNG_Compressed(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        [$tag, $val] = Perl::split('/\0/', $dirInfo['DataPt'], 2) + [null, null];
        if ($val === null) return 0;
        // set compressed to 2 + compression method to decompress the data
        $compressed = 2 + (Perl::unpack('C', $val)[0] ?? 0);
        $val = substr($val, 1);     // remove compression method byte
        $outBuff = $dirInfo['OutBuff'] ?? null;
        $tagInfo = $dirInfo['TagInfo'] ?? null;
        // use the PNG chunk tag instead of the embedded tag name for iCCP chunks
        if ($tagInfo and $tagInfo->Name === 'ICC_Profile') {
            $et->VerboseDir('iCCP');
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PNG::Main');
            if (strlen($tag) and !$outBuff) self::FoundPNG($et, $tagTablePtr, 'iCCP-name', $tag);
            $success = self::FoundPNG($et, $tagTablePtr, 'iCCP', $val, $compressed, $outBuff);
        } else {
            $success = self::FoundPNG($et, $tagTablePtr, $tag, $val, $compressed, $outBuff, 'Latin');
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Process PNG tEXt chunk
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) Pointer to tag table
    // Returns: 1 on success
    // Notes: writes new chunk data to ${$$dirInfo{OutBuff}} if writing tag
    public static function ProcessPNG_tEXt(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        [$tag, $val] = Perl::split('/\0/', $dirInfo['DataPt'], 2) + [null, null];
        $outBuff = $dirInfo['OutBuff'] ?? null;
        if ($et->OPTIONS['Verbose']) $et->INDENT = substr($et->INDENT, 0, -2);
        return self::FoundPNG($et, $tagTablePtr, $tag, $val, null, $outBuff, 'Latin');
    }

    //------------------------------------------------------------------------------
    // Process PNG iTXt chunk
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) Pointer to tag table
    // Returns: 1 on success
    // Notes: writes new chunk data to ${$$dirInfo{OutBuff}} if writing tag
    public static function ProcessPNG_iTXt(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        [$tag, $dat] = Perl::split('/\0/', $dirInfo['DataPt'], 2) + [null, null];
        if (!($dat !== null and strlen($dat) >= 4)) return 0;
        [$compressed, $meth] = Perl::unpack('CC', $dat);
        [$lang, $trans, $val] = Perl::split('/\0/', substr($dat, 2), 3) + [null, null, null];
        // set compressed flag so we will decompress it in FoundPNG()
        if ($compressed) $compressed = 2 + $meth;
        $outBuff = $dirInfo['OutBuff'] ?? null;
        if ($et->OPTIONS['Verbose']) $et->INDENT = substr($et->INDENT, 0, -2);
        return self::FoundPNG($et, $tagTablePtr, $tag, $val, $compressed, $outBuff, 'UTF8', $lang);
    }

    //------------------------------------------------------------------------------
    // Process PNG eXIf/zXIf chunk
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) Pointer to tag table
    // Returns: 1 on success
    // Notes: writes new chunk data to ${$$dirInfo{OutBuff}} if writing tag
    public static function ProcessPNG_eXIf(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $outBuff = $dirInfo['OutBuff'] ?? null;
        $tagInfo = $dirInfo['TagInfo'];
        $tag = $tagInfo->TagID;
        $type = null;

        if (preg_match('/^Exif\0\0/', $dirInfo['DataPt'])) {
            $et->Warn('Improper "Exif00" header in EXIF chunk');
            $dirInfo['DataPt'] = substr($dirInfo['DataPt'], 6);
            $dirInfo['DataLen'] = strlen($dirInfo['DataPt']);
            if ($dirInfo['DirLen']) $dirInfo['DirLen'] -= 6;
        }
        $dataPt = $dirInfo['DataPt'];
        if (preg_match('/^(\0|II|MM)/', $dataPt, $m)) {
            $type = $m[1];
        } else {
            $et->Warn("Invalid $tag chunk");
            return 0;
        }
        if ($type === "\0") {   // is this compressed EXIF?
            $buf = substr($dataPt, 5);
            // go around again to uncompress the data
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PNG::Main');
            return self::FoundPNG($et, $tagTablePtr, $tagInfo->TagID, $buf, 2, $outBuff);
        }
        // (writing not ported)
        return $et->ProcessTIFF($dirInfo);
    }

    //------------------------------------------------------------------------------
    // Extract meta information from a PNG image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PNG image, or -1 on write error
    public static function ProcessPNG(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $datChunk = '';
        $datCount = 0;
        $datBytes = 0;
        $fastScan = $et->Options('FastScan');
        $hash = $et->ImageDataHash;
        $n = $sig = $hbuf = $dbuf = $cbuf = null;
        $wasHdr = $wasEnd = $wasDat = $doTxt = $wasTrailer = null;
        $outBuff = null;

        // check to be sure this is a valid PNG/MNG/JNG image
        if (!($raf->Read($sig, 8) == 8 and isset(Vars\PNG::$pngLookup[$sig]))) return 0;

        // (writing not ported)
        // disable buffering in FastScan mode
        if ($fastScan) $raf->NoBuffer = true;
        [$fileType, $hdrChunk, $endChunk] = Vars\PNG::$pngLookup[$sig];
        $et->SetFileType($fileType);    // set the FileType tag
        ExifTool::SetByteOrder('MM');   // PNG files are big-endian
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PNG::Main');
        $mngTablePtr = null;
        if ($fileType !== 'PNG') {
            $mngTablePtr = ExifTool::GetTagTable('Image::ExifTool::MNG::Main');
        }
        $verbose = $et->Options('Verbose');
        $validate = $et->Options('Validate');
        $out = $et->Options('TextOut');

        // process the PNG/MNG/JNG chunks
        Vars\PNG::$noCompressLib = null;
        for (;;) {
            $n = $raf->Read($hbuf, 8);  // read chunk header

            if ($wasEnd) {
                if (!$n) break;     // stop now if normal end of PNG
                $et->Warn("Trailer data after $fileType $endChunk chunk", 1);
                $wasTrailer = 1;
                if ($n < 8) break;
                $et->SET_GROUP1 = 'Trailer';
            } elseif ($n != 8) {
                if (!$wasEnd) $et->Warn("Truncated $fileType image");
                break;
            }
            [$len, $chunk] = Perl::unpack('Na4', $hbuf);
            if ($len > 0x7fffffff) {
                if (!$wasEnd) $et->Warn("Invalid $fileType chunk size");
                break;
            }
            if ($verbose) {
                if ($doTxt) fwrite($out, "  Moving $chunk from after IDAT ($len bytes)\n");
                // don't dump image data chunks in verbose mode (only give count instead)
                if ($datCount and $chunk !== $datChunk) {
                    $s = $datCount > 1 ? 's' : '';
                    fwrite($out, "$fileType $datChunk ($datCount chunk$s, total $datBytes bytes)\n");
                    if ($hash) fwrite($out, "{$et->INDENT}(ImageDataHash: $datBytes bytes of $datChunk data)\n");
                    $datCount = $datBytes = 0;
                }
            }
            if (!$wasHdr) {
                if ($chunk === $hdrChunk) {
                    $wasHdr = 1;
                } elseif ($hdrChunk === 'IHDR' and $chunk === 'CgBI') {
                    $et->Warn('Non-standard PNG image (Apple iPhone format)');
                } else {
                    $et->Warn("$fileType image did not start with $hdrChunk");
                }
            }
            if (!empty(Vars\PNG::$isDatChunk[$chunk])) {
                if ($fastScan and $fastScan >= 2) {
                    $et->VPrint(0, "End processing at $chunk chunk due to FastScan=$fastScan setting");
                    break;
                }
                $datChunk = $chunk;
                $datCount++;
                $datBytes += $len;
                $wasDat = $chunk;
            } else {
                $datChunk = '';
            }
            if ($chunk === $endChunk) {
                // read CRC
                if ($raf->Read($cbuf, 4) != 4) {
                    if (!$wasEnd) $et->Warn("Truncated $fileType $endChunk chunk");
                    break;
                }
                if ($verbose) fwrite($out, "$fileType $chunk (end of image)\n");
                $wasEnd = 1;
                continue;
            }
            if ($datChunk) {
                $chunkSizeLimit = 10000000;     // largest chunk to read into memory
                // skip over data chunks if possible/necessary
                if (!$validate or $len > $chunkSizeLimit) {
                    if ($hash) {
                        $et->ImageDataHash($raf, $len);
                        if ($raf->Read($cbuf, 4) != 4) {
                            $et->Warn('Truncated data');
                            break;
                        }
                    } else {
                        if (!$raf->Seek($len + 4, 1)) {
                            $et->Warn('Seek error');
                            break;
                        }
                    }
                    continue;
                }
            } elseif ($wasDat and !empty(Vars\PNG::$isTxtChunk[$chunk])) {
                $msg = 'may be ignored by some readers';
                $et->Warn("Text/EXIF chunk(s) found after {$et->FileType} $wasDat ($msg)", 1);
            }
            // read chunk data and CRC
            if (!($raf->Read($dbuf, $len) == $len and $raf->Read($cbuf, 4) == 4)) {
                if (!$wasEnd) $et->Warn("Corrupted $fileType image");
                break;
            }
            if ($hash and $datChunk) hash_update($hash, $dbuf);     // add to hash if necessary
            if ($verbose or $validate) {
                // check CRC when in verbose mode (since we don't care about speed)
                $crc = self::CalculateCRC($hbuf, null, 4);
                $crc = self::CalculateCRC($dbuf, $crc);
                if ($crc != Perl::unpack('N', $cbuf)[0]) {
                    $msg = "Bad CRC for $chunk chunk";
                    $et->Warn($msg);
                }
                if ($datChunk) continue;
                if ($verbose) {
                    fwrite($out, "$fileType $chunk ($len bytes):\n");
                    if ($verbose > 2) $et->VerboseDump($dbuf, Addr: $raf->Tell() - $len - 4);
                }
            }
            // translate case of chunk names that have changed since the first implementation
            if (!isset($tagTablePtr[$chunk]) and isset(Vars\PNG::$stdCase[strtolower($chunk)])) {
                $stdChunk = Vars\PNG::$stdCase[strtolower($chunk)];
                $et->Warn("$chunk chunk should be $stdChunk", 1);
                $chunk = $stdChunk;
            }
            // only extract information from chunks in our tables
            if (isset($tagTablePtr[$chunk])) {
                self::FoundPNG($et, $tagTablePtr, $chunk, $dbuf, null, $outBuff);
            } elseif ($mngTablePtr and isset($mngTablePtr[$chunk])) {
                self::FoundPNG($et, $mngTablePtr, $chunk, $dbuf, null, $outBuff);
            }
        }
        $et->SET_GROUP1 = null;
        // read Samsung trailer if it exists
        if ($wasTrailer and $raf->Seek(-8, 2) and
            $raf->Read($dbuf, 8) and preg_match('/\0\0(QDIOBS|SEFT)$/', $dbuf))    // (have only seen SEFT type)
        {
            ExifTool::RequireModule('Image::ExifTool::Samsung');
            $samsungInfo = ['DirName' => 'Samsung', 'RAF' => $raf];
            Samsung::ProcessSamsung($et, $samsungInfo);
        }
        return 1;   // this was a valid PNG/MNG/JNG image
    }
}
