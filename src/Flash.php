<?php

//------------------------------------------------------------------------------
// File:         Flash.pm
//
// Description:  Read Shockwave Flash meta information
//
// Revisions:    05/16/2006 - P. Harvey Created
//               06/07/2007 - PH Added support for FLV (Flash Video) files
//               10/23/2008 - PH Added support for XMP in FLV and SWF
//
// References:   1) http://www.the-labs.com/MacromediaFlash/SWF-Spec/SWFfileformat.html
//               2) http://sswf.sourceforge.net/SWFalexref.html
//               3) http://osflash.org/flv/
//               4) http://www.irisa.fr/texmex/people/dufouil/ffmpegdoxy/flv_8h.html
//               5) http://www.adobe.com/devnet/xmp/pdfs/XMPSpecificationPart3.pdf (Oct 2008)
//               6) http://www.adobe.com/devnet/swf/pdf/swf_file_format_spec_v9.pdf
//               7) http://help.adobe.com/en_US/FlashMediaServer/3.5_Deving/WS5b3ccc516d4fbf351e63e3d11a0773d56e-7ff6.html
//               8) http://www.adobe.com/devnet/flv/pdf/video_file_format_spec_v10.pdf
//
// Notes:        I'll add AMF3 support if someone sends me a FLV with AMF3 data
//
// PHP port of Image::ExifTool::Flash 1.13 (reading only). Tag tables and file lexicals
// (%processMetaPacket, @amfType, %isStruct) are generated into tables/Flash.php and
// Vars\Flash.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Flash
{
    public static $VERSION = '1.13';

    //------------------------------------------------------------------------------
    // Process Flash Video AMF Meta packet (ref 3)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    //         3) Set to extract single type/value only
    // Returns: 1 on success, (or type/value if extracting single value)
    //          (PHP: the [type, value] pair when extracting a single value)
    // Notes: Updates DataPos in dirInfo if extracting single value
    public static function ProcessMeta(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr, $single = null)
    {
        $amfType = Vars\Flash::$amfType;
        $isStruct = Vars\Flash::$isStruct;
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: strlen($dataPt);
        $pos = ($dirInfo['Pos'] ?? null) ?: 0;
        $type = $val = null;

        if (!$single) $et->VerboseDir('Meta');

        for ($rec = 0; ; ++$rec) {    // (Perl label Record:)
            if ($pos >= $dirLen) break;
            $type = ord(substr($dataPt, $pos, 1));
            ++$pos;
            if ($type == 0x00 or $type == 0x0b) {   // double or date
                if ($pos + 8 > $dirLen) break;
                $val = ExifTool::GetDouble($dataPt, $pos);
                $pos += 8;
                if ($type == 0x0b) {    // date
                    $val /= 1000;       // convert to seconds
                    // get time zone
                    if ($pos + 2 > $dirLen) break;
                    $tz = ExifTool::Get16s($dataPt, $pos);
                    $pos += 2;
                    // construct date/time string
                    $val = ExifTool::ConvertUnixTime($val, 0, 6);
                    // add timezone
                    if ($tz < 0) {
                        $val .= '-';
                        $tz *= -1;
                    } else {
                        $val .= '+';
                    }
                    $val .= Perl::sprintf('%.2d:%.2d', Perl::int($tz / 60), Perl::mod($tz, 60));
                }
            } elseif ($type == 0x01) {   // boolean
                if ($pos + 1 > $dirLen) break;
                $val = ExifTool::Get8u($dataPt, $pos);
                if ($val < 2) $val = [0 => 'No', 1 => 'Yes'][$val];
                ++$pos;
            } elseif ($type == 0x02) {   // string
                if ($pos + 2 > $dirLen) break;
                $len = ExifTool::Get16u($dataPt, $pos);
                if ($pos + 2 + $len > $dirLen) break;
                $val = substr($dataPt, $pos + 2, $len);
                $pos += 2 + $len;
            } elseif (!empty($isStruct[$type])) {   // object, mixed array or typed object
                $et->VPrint(1, "  + [{$amfType[$type]}]\n");
                $getName = null;
                $val = '';  // dummy value
                if ($type == 0x08) {        // mixed array
                    // skip last array index for mixed array
                    if ($pos + 4 > $dirLen) break;
                    $pos += 4;
                } elseif ($type == 0x10) {   // typed object
                    $getName = 1;
                }
                for (;;) {
                    // get tag ID (or typed object name)
                    if ($pos + 2 > $dirLen) break 2;    // (last Record)
                    $len = ExifTool::Get16u($dataPt, $pos);
                    if ($pos + 2 + $len > $dirLen) {
                        $et->Warn("Truncated {$amfType[$type]} record");
                        break 2;    // (last Record)
                    }
                    $tag = substr($dataPt, $pos + 2, $len);
                    $pos += 2 + $len;
                    // first string of a typed object is the object name
                    if ($getName) {
                        $et->VPrint(1, "  | (object name '{$tag}')\n");
                        $getName = null;
                        continue; // (ignore name for now)
                    }
                    $subTablePtr = $tagTablePtr;
                    $tagInfo = $subTablePtr[$tag];
                    // switch to subdirectory table if necessary
                    if ($tagInfo instanceof TagInfo and $tagInfo->SubDirectory) {
                        $subTable = $tagInfo->SubDirectory['TagTable'];
                        // descend into Flash SubDirectory
                        if (preg_match('/^Image::ExifTool::Flash::/', $subTable)) {
                            $tag = $tagInfo->Name; // use our name for the tag
                            $subTablePtr = ExifTool::GetTagTable($subTable);
                        }
                    }
                    // get object value
                    $valPos = $pos + 1;
                    $dirInfo['Pos'] = $pos;
                    $structName = $dirInfo['StructName'] ?? null;
                    // add structure name to start of tag name
                    if ($structName !== null) $tag = $structName . ucfirst($tag);
                    $dirInfo['StructName'] = $tag;       // set new structure name
                    [$t, $v] = self::ProcessMeta($et, $dirInfo, $subTablePtr, 1);
                    $dirInfo['StructName'] = $structName;// restore original structure name
                    $pos = $dirInfo['Pos'];  // update to new position in packet
                    // all done if this value contained tags
                    if (!($t !== null and $v !== null)) break 2;    // (last Record)
                    if (!empty($isStruct[$t])) continue;  // already handled tags in sub-structures
                    if (is_array($v) and !$v) continue; // ignore empty arrays
                    if ($t == 0x09) break; // (end of object)
                    if (!$subTablePtr[$tag] and preg_match('/^\w+$/', $tag)) {
                        ExifTool::AddTagToTable($subTablePtr, $tag, ['Name' => ucfirst($tag)]);
                        $et->VPrint(1, "  | (adding $tag)\n");
                    }
                    $et->HandleTag($subTablePtr, $tag, $v,
                        DataPt  : $dataPt,
                        DataPos : $dataPos,
                        Start   : $valPos,
                        Size    : $pos - $valPos,
                        Format  : ($amfType[$t] ?? null) ?: Perl::sprintf('0x%x', $t),
                    );
                }
          // } elseif ($type == 0x04) {   // movie clip (not supported)
            } elseif ($type == 0x05 or $type == 0x06 or $type == 0x09 or $type == 0x0d) {
                // null, undefined, dirLen of object, or unsupported
                $val = '';
            } elseif ($type == 0x07) {   // reference
                if ($pos + 2 > $dirLen) break;
                $val = ExifTool::Get16u($dataPt, $pos);
                $pos += 2;
            } elseif ($type == 0x0a) {   // array
                if ($pos + 4 > $dirLen) break;
                $num = ExifTool::Get32u($dataPt, $pos);
                $dirInfo['Pos'] = $pos + 4;
                $vals = [];
                // add array index to compound tag name
                $structName = $dirInfo['StructName'] ?? null;
                for ($i = 0; $i < $num; ++$i) {
                    if ($structName !== null) $dirInfo['StructName'] = $structName . $i;
                    [$t, $v] = self::ProcessMeta($et, $dirInfo, $tagTablePtr, 1);
                    if ($v === null) break 2;   // (last Record)
                    // save value unless contained in a sub-structure
                    if (empty($isStruct[Perl::str($t)])) $vals[] = $v;
                }
                $dirInfo['StructName'] = $structName;
                $pos = $dirInfo['Pos'];
                $val = $vals;
            } elseif ($type == 0x0c or $type == 0x0f) {  // long string or XML
                if ($pos + 4 > $dirLen) break;
                $len = ExifTool::Get32u($dataPt, $pos);
                if ($pos + 4 + $len > $dirLen) break;
                $val = substr($dataPt, $pos + 4, $len);
                $pos += 4 + $len;
          // } elseif ($type == 0x0e) {   // record set (not supported)
          // } elseif ($type == 0x11) {   // AMF3 data (can't add support for this without a test sample)
            } else {
                $t = ($amfType[$type] ?? null) ?: Perl::sprintf('type 0x%x', $type);
                $et->Warn("AMF $t record not yet supported");
                $type = null;   // (so we don't print another warning)
                break;          // can't continue
            }
            if ($single) break;        // all done if extracting single value
            if (empty($isStruct[$type])) {
                // only process certain Meta packets
                if ($type == 0x02 and !$rec) {
                    $verb = !empty(Vars\Flash::$processMetaPacket[$val]) ? 'processing' : 'ignoring';
                    $et->VPrint(0, "  | ($verb $val information)\n");
                    if (empty(Vars\Flash::$processMetaPacket[$val])) break;
                } else {
                    // give verbose indication if we ignore a lone value
                    $t = ($amfType[$type] ?? null) ?: Perl::sprintf('type 0x%x', $type);
                    $et->VPrint(1, "  | (ignored lone $t value '" . Perl::str($val) . "')\n");
                }
            }
        }
        if ($val === null and $type !== null) {
            $et->Warn(Perl::sprintf('Truncated AMF record 0x%x', $type));
        }
        if (!$single) return 1;    // all done
        $dirInfo['Pos'] = $pos;      // update position
        return [$type, $val];        // return single type/value pair
    }

    //------------------------------------------------------------------------------
    // Read information frame a Flash Video file
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid Flash Video file
    public static function ProcessFLV(ExifTool $et, array &$dirInfo)
    {
        $verbose = $et->Options('Verbose');
        $raf = $dirInfo['RAF'];
        $buff = null;

        if ($raf->Read($buff, 9) != 9) return 0;
        if (!preg_match('/^FLV\x01/', $buff)) return 0;
        ExifTool::SetByteOrder('MM');
        $et->SetFileType();
        [$flags, $offset] = Perl::unpack('x4CN', $buff);
        if ($offset > 9 and !$raf->Seek($offset - 9, 1)) return 1;
        $flags &= 0x05; // only look for audio/video
        $found = 0;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Flash::FLV');
        for (;;) {
            if ($raf->Read($buff, 15) != 15) break;
            $len = Perl::unpack('x4N', $buff)[0] ?? null;
            $type = $len >> 24;
            $len &= 0x00ffffff;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $type);
            if ($verbose > 1) {
                $name = $tagInfo ? $tagInfo->Name : "type $type";
                $et->VPrint(1, "FLV $name packet, len $len\n");
            }
            $buff = null;
            if ($tagInfo and $tagInfo->SubDirectory) {
                $mask = $tagInfo->BitMask;
                if ($mask) {
                    // handle audio or video packet
                    if (!($found & $mask)) {
                        $found |= $mask;
                        $flags &= ~$mask;
                        if ($len >= 1 and $raf->Read($buff, 1) == 1) {
                            $len -= 1;
                        } else {
                            $et->Warn("Bad {$tagInfo->Name} packet");
                            break;
                        }
                    }
                } elseif ($raf->Read($buff, $len) == $len) {
                    $len = 0;
                } else {
                    $et->Warn('Truncated Meta packet');
                    break;
                }
            }
            if ($buff !== null) {
                $et->HandleTag($tagTablePtr, $type, null,
                    DataPt  : $buff,
                    DataPos : $raf->Tell() - strlen($buff),
                );
            }
            if (!$flags) break;
            if ($len and !$raf->Seek($len, 1)) break;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Found a Flash tag
    // Inputs: 0) ExifTool object ref, 1) tag name, 2) tag value
    public static function FoundFlashTag(ExifTool $et, $tag, $val)
    {
        $et->HandleTag(ExifTool::GetTagTable('Image::ExifTool::Flash::Main'), $tag, $val);
    }

    //------------------------------------------------------------------------------
    // Read data from possibly compressed file
    // Inputs: 0) RAF reference, 1) data buffer, 2) bytes to read, 2) compressed flag
    // Returns: number of bytes read (may be greater than requested bytes if compressed)
    // - concatenates data to current buffer
    // - updates compressed flag with reference to inflate object for future calls
    //   (or sets to error message and returns zero on error)
    //   (PHP: the inflate object is an \InflateContext)
    public static function ReadCompressed(RandomAccess $raf, &$data, $len, &$inflate)
    {
        $buff = null;
        if (!$raf->Read($buff, $len)) {
            $inflate = 'Error reading file';
            return 0;
        }
        // uncompress if necessary
        if ($inflate) {
            if (!$inflate instanceof \InflateContext) {
                if (!function_exists('inflate_init')) {
                    $inflate = 'Install Compress::Zlib to extract compressed information';
                    return 0;
                }
                $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
                if (!$ctx) {
                    $inflate = 'Error initializing inflate for Flash data';
                    return 0;
                }
                $inflate = $ctx;   // pass inflate object back to caller
            }
            $tmp = $buff;
            $buff = '';
            // read 64 more bytes at a time and inflate until we get enough uncompressed data
            for (;;) {
                $dat = @inflate_add($inflate, $tmp, ZLIB_SYNC_FLUSH);
                $stat = $dat === false ? false : inflate_get_status($inflate);
                if ($stat === ZLIB_STREAM_END or $stat === ZLIB_OK or $stat === ZLIB_BUF_ERROR) {
                    $buff .= $dat;  // add inflated data to buffer
                    if (strlen($buff) >= $len or $stat === ZLIB_STREAM_END) break;
                    if (!$raf->Read($tmp, 64)) break;    // must read a bit more data
                } else {
                    $buff = '';
                    break;
                }
            }
            if (!strlen($buff)) $inflate = 'Error inflating compressed Flash data';
        }
        $data = $data !== null ? $data . $buff : $buff;
        return strlen($buff);
    }

    //------------------------------------------------------------------------------
    // Read information frame a Flash file
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid Flash file
    public static function ProcessSWF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $hasMeta = null;

        if ($raf->Read($buff, 8) != 8) return 0;
        if (!preg_match('/^(F|C)WS([^\0])/', $buff, $m)) return 0;
        [$compressed, $vers] = [$m[1] === 'C' ? 1 : 0, ord($m[2])];

        ExifTool::SetByteOrder('II');
        $et->SetFileType();
        ExifTool::GetTagTable('Image::ExifTool::Flash::Main');  // make sure table is initialized

        self::FoundFlashTag($et, 'FlashVersion', $vers);
        self::FoundFlashTag($et, 'Compressed', $compressed);

        // read the next 64 bytes of the file (and inflate if necessary)
        $buff = '';
        if (!self::ReadCompressed($raf, $buff, 64, $compressed)) {
            if ($compressed) $et->Warn($compressed);
            return 1;
        }

        // unpack elements of bit-packed Flash Rect structure
        $nBits = (Perl::unpack('C', $buff)[0] ?? 0) >> 3;    // bits in x1,x2,y1,y2 elements
        $totBits = 5 + $nBits * 4;           // total bits in Rect structure
        $nBytes = Perl::int(($totBits + 7) / 8);   // byte length of Rect structure
        if (strlen($buff) < $nBytes + 4) {
            $et->Warn('Truncated Flash file');
            return 1;
        }
        $bits = Perl::unpack("B$totBits", $buff)[0] ?? null;
        // isolate Rect elements and convert from ASCII bit strings to integers
        $vals = Perl::unpack('x5' . str_repeat("a$nBits", 4), $bits);
        // (do conversion the hard way because oct("0b$val") requires Perl 5.6)
        foreach ($vals as &$v) {
            $v = Perl::unpack('N', Perl::pack('B32', str_repeat('0', 32 - strlen($v)) . $v))[0];
        }
        unset($v);

        // calculate and store ImageWidth/Height
        self::FoundFlashTag($et, 'ImageWidth', ($vals[1] - $vals[0]) / 20);
        self::FoundFlashTag($et, 'ImageHeight', ($vals[3] - $vals[2]) / 20);

        // get frame rate and count
        $vals = Perl::unpack("x{$nBytes}v2", $buff);
        self::FoundFlashTag($et, 'FrameRate', $vals[0] / 256);
        self::FoundFlashTag($et, 'FrameCount', $vals[1]);
        if ($vals[0]) self::FoundFlashTag($et, 'Duration', $vals[1] * 256 / $vals[0]);

        // scan through the tags to find FlashAttributes and XMP
        $buff = substr($buff, $nBytes + 4);
        for (;;) {
            $buffLen = strlen($buff);
            if ($buffLen < 2) break;
            $code = ExifTool::Get16u($buff, 0);
            $pos = 2;
            $tag = $code >> 6;
            $size = $code & 0x3f;
            $et->VPrint(1, "SWF tag $tag ($size bytes):\n");
            if (!($tag == 69 or $tag == 77 or $hasMeta)) break;
            // read enough to get a complete short record
            if ($pos + $size > $buffLen) {
                // (read 2 extra bytes if available to get next tag word)
                if (!self::ReadCompressed($raf, $buff, $size + 2, $compressed)) {
                    if ($compressed) $et->Warn($compressed);
                    return 1;
                }
                $buffLen = strlen($buff);
                if ($pos + $size > $buffLen) break;
            }
            // read extended record if necessary
            if ($size == 0x3f) {
                if ($pos + 4 > $buffLen) break;
                $size = ExifTool::Get32u($buff, $pos);
                $pos += 4;
                if ($size > 1000000) break; // don't read anything huge
                if ($pos + $size > $buffLen) {
                    if (!self::ReadCompressed($raf, $buff, $size + 2, $compressed)) {
                        if ($compressed) $et->Warn($compressed);
                        return 1;
                    }
                    $buffLen = strlen($buff);
                    if ($pos + $size > $buffLen) break;
                }
                $et->VPrint(1, "  [extended size $size bytes]\n");
            }
            if ($tag == 69) {       // FlashAttributes
                if (!$size) break;
                $flags = ExifTool::Get8u($buff, $pos);
                self::FoundFlashTag($et, $tag, $flags);
                if (!($flags & 0x10)) break;  // only continue if we have metadata (XMP)
                $hasMeta = 1;
            } elseif ($tag == 77) {  // Metadata
                $val = substr($buff, $pos, $size);
                self::FoundFlashTag($et, $tag, $val);
                break;
            }
            if ($pos + 2 > $buffLen) break;
            $buff = substr($buff, $pos);    // remove everything before the next tag
        }
        return 1;
    }
}
