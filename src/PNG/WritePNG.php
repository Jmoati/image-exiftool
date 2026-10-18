<?php

//------------------------------------------------------------------------------
// File:         WritePNG.pl
//
// Description:  Write PNG meta information
//
// Revisions:    09/16/2005 - P. Harvey Created
//
// References:   1) http://www.libpng.org/pub/png/spec/1.2/
//
// PHP port of the whole file (package Image::ExifTool::PNG), mixed into class PNG.
// Compress::Zlib is the zlib extension: deflateInit() is DeflateInit() below (same zlib
// parameters, so the compressed data is the same), inflate is in PNG::FoundPNG().
//------------------------------------------------------------------------------

namespace Image\ExifTool\PNG;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\Vars\PNG as Vars;
use Image\ExifTool\XMP;

trait WritePNG
{
    /** my @crcTable */
    private static array $crcTable = [];

    //------------------------------------------------------------------------------
    // Calculate CRC or update running CRC (ref 1)
    // Inputs: 0) data reference, 1) running crc to update (undef initially)
    //         2) data position (undef for 0), 3) data length (undef for all data),
    // Returns: updated CRC
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
        // (PHP: loop over the bytes instead of unpacking them into a list)
        for ($i = $pos, $end = $pos + $len; $i < $end; ++$i) {
            $crc = self::$crcTable[($crc ^ ord($dataPt[$i])) & 0xff] ^ ($crc >> 8);
        }
        return $crc ^ 0xffffffff;   // return 1's complement
    }

    //------------------------------------------------------------------------------
    // (PHP) Compress::Zlib::deflateInit(): zlib format with the default level, but a
    // MemLevel of 9 (MAX_MEM_LEVEL) instead of zlib's default 8 like Perl
    private static function DeflateInit()
    {
        return deflate_init(ZLIB_ENCODING_DEFLATE, ['memory' => 9]);
    }

    //------------------------------------------------------------------------------
    // Encode data in ASCII Hex
    // Inputs: 0) input data reference
    // Returns: Hex-encoded data (max 72 chars per line)
    public static function HexEncode($dataPt)
    {
        $len = strlen($dataPt);
        $hex = '';
        for ($pos = 0; $pos < $len; $pos += 36) {
            $n = $len - $pos;
            if ($n > 36) $n = 36;
            $hex .= bin2hex(substr($dataPt, $pos, $n)) . "\n";
        }
        return $hex;
    }

    //------------------------------------------------------------------------------
    // Write profile chunk (possibly compressed if Zlib is available)
    // Inputs: 0) outfile, 1) Raw profile type (SCALAR ref for iCCP name), 2) data ref
    //         3) profile header type (undef if not a text profile)
    // Returns: 1 on success
    public static function WriteProfile($outfile, $rawType, $dataPt, $profile = null)
    {
        $buff = $prefix = $chunk = $deflate = null;
        if (($rawType instanceof ScalarRef or $rawType !== Vars::$stdCase['exif']) and function_exists('deflate_init')) {
            $deflate = self::DeflateInit();
        }
        if ($profile === null) {
            // write ICC profile as compressed iCCP chunk if possible
            if ($rawType instanceof ScalarRef) {
                if (!$deflate) return 0;
                $chunk = 'iCCP';
                $prefix = "{$rawType->value}\0\0";
            } else {
                $chunk = $rawType;
                if ($rawType === Vars::$stdCase['zxif']) {
                    $prefix = "\0" . pack('N', strlen($dataPt));   // (proposed compressed EXIF)
                } else {
                    $prefix = '';   // standard EXIF
                }
            }
            if ($deflate) {
                $buff = deflate_add($deflate, $dataPt, ZLIB_NO_FLUSH);
                if ($buff === false) return 0;
                $buff .= deflate_add($deflate, '', ZLIB_FINISH);
                $dataPt = $buff;
            }
        } else {
            // write as ASCII-hex encoded profile in tEXt or zTXt chunk
            $txtHdr = Perl::sprintf("\n$profile profile\n%8d\n", strlen($dataPt));
            $buff = $txtHdr . self::HexEncode($dataPt);
            $chunk = 'tEXt';        // write as tEXt if deflate not available
            $prefix = "Raw profile type $rawType\0";
            $dataPt = $buff;
            // write profile as zTXt chunk if possible
            if ($deflate) {
                $buf2 = deflate_add($deflate, $buff, ZLIB_NO_FLUSH);
                if ($buf2 !== false) {
                    $buf2 .= deflate_add($deflate, '', ZLIB_FINISH);
                    $dataPt = $buf2;
                    $chunk = 'zTXt';
                    $prefix .= "\0";    // compression type byte (0=deflate)
                }
            }
        }
        $hdr = pack('Na4', strlen($prefix) + strlen($dataPt), $chunk) . $prefix;
        $crc = self::CalculateCRC($hdr, null, 4);
        $crc = self::CalculateCRC($dataPt, $crc);
        return ExifTool::Write($outfile, $hdr, $dataPt, pack('N', $crc));
    }

    //------------------------------------------------------------------------------
    // Add iCCP-related chunks to the PNG image if necessary (must come before PLTE and IDAT)
    // Inputs: 0) ExifTool object ref, 1) output file or scalar ref
    // Returns: true on success
    public static function Add_iCCP(ExifTool $et, $outfile)
    {
        if ($et->ADD_DIRS['ICC_Profile'] ?? null) {
            // write new ICC data
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ICC_Profile::Main');
            $dirInfo = ['Parent' => 'PNG', 'DirName' => 'ICC_Profile'];
            $buff = $et->WriteDirectory($dirInfo, $tagTablePtr);
            if ($buff !== null and strlen($buff)) {
                $profileName = $et->GetNewValue(ExifTool::GetTagTable('Image::ExifTool::PNG::Main')['iCCP-name']);
                if ($profileName === null) $profileName = 'icm';
                if (self::WriteProfile($outfile, new ScalarRef($profileName), $buff)) {
                    $et->VPrint(0, "Created ICC profile with name '{$profileName}'\n");
                    unset($et->ADD_DIRS['ICC_Profile']);    // don't add it again
                }
            }
        }
        // must also add sRGB and gAMA before PLTE and IDAT
        if ($et->ADD_PNG !== null) {
            $addBeforePLTE = [];
            foreach (['sRGB', 'gAMA'] as $tag) {
                if (empty($et->ADD_PNG[$tag])) continue;
                $addBeforePLTE[$tag] = $et->ADD_PNG[$tag];
                unset($et->ADD_PNG[$tag]);
            }
            if ($addBeforePLTE) {
                $save = $et->ADD_PNG;
                $et->ADD_PNG = $addBeforePLTE;
                self::AddChunks($et, $outfile);
                $et->ADD_PNG = $save;
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // This routine is called after we edit an existing directory
    // Inputs: 0) ExifTool ref, 1) dir name, 2) output data ref
    //         3) flag set if location is non-standard (to update, but not create from scratch)
    // - on return, $$outBuff is set to '' if the directory is to be deleted
    public static function DoneDir(ExifTool $et, $dir, ScalarRef $outBuff, $nonStandard = null): void
    {
        $saveDir = $dir;
        if ($dir === 'IFD0') $dir = 'EXIF';
        // don't add this directory again unless this is in a non-standard location
        if (!$nonStandard) {
            unset($et->ADD_DIRS[$dir]);
            if ($dir === 'EXIF') unset($et->ADD_DIRS['IFD0']);
        } elseif (($et->DEL_GROUP[$dir] ?? null) or ($et->DEL_GROUP[$saveDir] ?? null)) {
            $et->VPrint(0, "  Deleting non-standard $dir\n");
            $outBuff->value = '';
        }
    }

    //------------------------------------------------------------------------------
    // Generate tEXt, zTXt or iTXt data for writing
    // Inputs: 0) ExifTool ref, 1) tagID, 2) tagInfo ref, 3) value string, 4) language code
    // Returns: chunk data (not including 8-byte chunk header)
    // Notes: Sets ExifTool TextChunkType member to the type of chunk written
    public static function BuildTextChunk(ExifTool $et, $tag, TagInfo $tagInfo, $val, $lang)
    {
        $xtra = $compVal = $iTXt = $comp = null;
        $val = Perl::str($val);
        if ($tagInfo->SubDirectory) {
            if ($tagInfo->Name === 'XMP') {
                $iTXt = 2;      // write as iTXt but flag to avoid encoding
                // (never compress XMP)
            } else {
                $comp = 2;      // compress raw profile if possible
            }
        } else {
            // compress if specified
            if ($et->Options('Compress')) $comp = 1;
            if ($lang) {
                $iTXt = 1;      // write as iTXt if it has a language code
                $tag = preg_replace(ExifTool::Rx('-' . $lang . '$'), '', $tag, 1);   // remove language code from tagID
            } elseif ($et->OPTIONS['Charset'] !== 'Latin' and preg_match('/[\x80-\xff]/', $val)) {
                $iTXt = 1;      // write as iTXt if it contains non-Latin special characters
            } elseif ($tagInfo->iTXt) {
                $iTXt = 1;      // write as iTXt if specified in user-defined tag
            }
        }
        if ($comp) {
            $warn = null;
            if (function_exists('deflate_init')) {
                $deflate = self::DeflateInit();
                if ($deflate) {
                    $compVal = deflate_add($deflate, $val, ZLIB_NO_FLUSH);
                    if ($compVal === false) $compVal = null;
                }
                if ($compVal !== null) {
                    $compVal .= deflate_add($deflate, '', ZLIB_FINISH);
                    // only compress if it actually saves space
                    if (!(strlen($compVal) < strlen($val))) {
                        $compVal = null;
                        $warn = 'uncompressed data is smaller';
                    }
                } else {
                    $warn = 'deflate error';
                }
            } else {
                $warn = 'Compress::Zlib not available';
            }
            // warn if any user-specified compression fails
            if ($warn and $comp == 1) {
                $et->Warn("PNG:{$tagInfo->Name} not compressed ($warn)", 1);
            }
        }
        // decide whether to write as iTXt, zTXt or tEXt
        if ($iTXt) {
            $et->TextChunkType = 'iTXt';
            $xtra = ($compVal !== null ? "\x01\0" : "\0\0") . ($lang ?: '') . "\0\0";
            // iTXt is encoded as UTF-8 (but note that XMP is already UTF-8)
            if ($iTXt == 1) $val = $et->Encode($val, 'UTF8');
        } elseif ($compVal !== null) {
            $et->TextChunkType = 'zTXt';
            $xtra = "\0";
        } else {
            $et->TextChunkType = 'tEXt';
            $xtra = '';
        }
        return $tag . "\0" . $xtra . ($compVal !== null ? $compVal : $val);
    }

    //------------------------------------------------------------------------------
    // Add any outstanding new chunks to the PNG image
    // Inputs: 0) ExifTool object ref, 1) output file or scalar ref
    //         2-N) dirs to add (empty to add all except EXIF 'IFD0', including PNG tags)
    // Returns: true on success
    public static function AddChunks(ExifTool $et, $outfile, ...$add)
    {
        $addTags = $err = $tagTablePtr = $specified = null;

        if ($add) {
            $addTags = [];  // don't add any PNG tags
            $specified = 1;
        } else {
            $addTags = $et->ADD_PNG ?? [];  // add all PNG tags...
            $et->ADD_PNG = null;            // ...once
            // add all directories
            $add = array_map('strval', array_keys($et->ADD_DIRS ?? []));
            sort($add, SORT_STRING);
        }
        // write any outstanding PNG tags
        $tags = array_map('strval', array_keys($addTags));
        sort($tags, SORT_STRING);
        foreach ($tags as $tag) {
            $tagInfo = $addTags[$tag];
            if ($tagInfo->FakeTag) continue;    // (iCCP-name)
            $nvHash = $et->GetNewValueHash($tagInfo);
            // (native PNG information is always preferred, so don't rely on just IsCreating)
            if (!($nvHash?->IsCreating or $et->IsOverwriting($nvHash) > 0)) continue;
            $val = $et->GetNewValue($nvHash);
            if ($val !== null) {
                if ($nvHash->EditOnly) continue;
                $data = null;
                if ($tagInfo->Table === ExifTool::GetTagTable('Image::ExifTool::PNG::TextualData')) {
                    $data = self::BuildTextChunk($et, $tag, $tagInfo, $val, $tagInfo->LangCode);
                    $data = $et->TextChunkType . $data;
                    $et->TextChunkType = null;
                } else {
                    $data = $tag . Perl::str($val);
                }
                $hdr = pack('N', strlen($data) - 4);
                $cbuf = pack('N', self::CalculateCRC($data, null));
                if (!ExifTool::Write($outfile, $hdr, $data, $cbuf)) $err = 1;
                $et->VerboseValue("+ PNG:{$tagInfo->Name}", $val);
                ++$et->CHANGED;
            }
        }
        // create any necessary directories
        foreach ($add as $dir) {
            if (!($et->ADD_DIRS[$dir] ?? null)) continue;  // make sure we want to add it first
            $buff = null;
            $dirInfo = [
                'Parent' => 'PNG',
                'DirName' => $dir,
            ];
            if ($dir === 'IFD0') {
                if (!$specified) continue;  // wait until specifically asked to write EXIF 'IFD0'
                $chunk = Vars::$stdCase['exif'];
                // (zxIf was not adopted)
                //if ($et->Options('Compress')) {
                //    if (eval { require Compress::Zlib }) {
                //        $chunk = $stdCase{zxif};
                //    } else {
                //        $et->Warn("Creating uncompressed $stdCase{exif} chunk (Compress::Zlib not available)");
                //    }
                //}
                $et->VPrint(0, "Creating $chunk chunk:\n");
                $et->TIFF_TYPE = 'APP1';
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
                $buff = $et->WriteDirectory($dirInfo, $tagTablePtr, 'Image\ExifTool\ExifTool::WriteTIFF');
                if ($buff !== null and strlen($buff)) {
                    if (!self::WriteProfile($outfile, $chunk, $buff)) $err = 1;
                }
            } elseif ($dir === 'XMP') {
                $et->VPrint(0, "Creating XMP iTXt chunk:\n");
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
                $dirInfo['ReadOnly'] = 1;
                $buff = $et->WriteDirectory($dirInfo, $tagTablePtr);
                if ($buff !== null and strlen($buff) and
                    // the packet is read-only (because of CRC)
                    XMP::ValidateXMP($buff, 'r'))
                {
                    // (previously, XMP was created as a non-standard XMP profile chunk)
                    // $buff = $Image::ExifTool::xmpAPP1hdr . $buff;
                    // WriteProfile($outfile, 'APP1', \$buff, 'generic') or $err = 1;
                    // (but now write XMP iTXt chunk according to XMP specification)
                    $buff = "iTXtXML:com.adobe.xmp\0\0\0\0\0" . $buff;
                    $hdr = pack('N', strlen($buff) - 4);
                    $cbuf = pack('N', self::CalculateCRC($buff, null));
                    if (!ExifTool::Write($outfile, $hdr, $buff, $cbuf)) $err = 1;
                }
            } elseif ($dir === 'IPTC') {
                $et->Warn('Creating non-standard IPTC in PNG', 1);
                $et->VPrint(0, "Creating IPTC profile:\n");
                // write new IPTC data (stored in a Photoshop directory)
                $dirInfo['DirName'] = 'Photoshop';
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Main');
                $buff = $et->WriteDirectory($dirInfo, $tagTablePtr);
                if ($buff !== null and strlen($buff)) {
                    if (!self::WriteProfile($outfile, 'iptc', $buff, 'IPTC')) $err = 1;
                }
            } elseif ($dir === 'ICC_Profile') {
                $et->VPrint(0, "Creating ICC profile:\n");
                // write new ICC data (only done if we couldn't create iCCP chunk)
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ICC_Profile::Main');
                $buff = $et->WriteDirectory($dirInfo, $tagTablePtr);
                if ($buff !== null and strlen($buff)) {
                    if (!self::WriteProfile($outfile, 'icm', $buff, 'ICC')) $err = 1;
                    $et->Warn('Wrote ICC as a raw profile (no Compress::Zlib)');
                }
            } elseif ($dir === 'PNG-pHYs') {
                $et->VPrint(0, "Creating pHYs chunk (default 2834 pixels per meter):\n");
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PNG::PhysicalPixel');
                $blank = "\0\0\x0b\x12\0\0\x0b\x12\x01";   // 2834 pixels per meter (72 dpi)
                $dirInfo['DataPt'] = $blank;
                $buff = $et->WriteDirectory($dirInfo, $tagTablePtr);
                if ($buff !== null and strlen($buff)) {
                    $buff = 'pHYs' . $buff;     // CRC includes chunk name
                    $hdr = pack('N', strlen($buff) - 4);
                    $cbuf = pack('N', self::CalculateCRC($buff, null));
                    if (!ExifTool::Write($outfile, $hdr, $buff, $cbuf)) $err = 1;
                }
            } else {
                continue;
            }
            unset($et->ADD_DIRS[$dir]);     // don't add again
        }
        return !$err;
    }
}
