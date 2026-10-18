<?php

//------------------------------------------------------------------------------
// File:         MIE.pm
//
// Description:  Read/write MIE meta information
//
// Revisions:    11/18/2005 - P. Harvey Created
//
// PHP port of Image::ExifTool::MIE 1.57 (reading only). Tag tables are generated into
// tables/MIE.php, the file lexicals ($hasZlib, %mieFormat, %mieMap...) into Vars\MIE.
// Not ported (writing): UpdateMieMap, MIEGroupFormat, WriteMIEGroup and the OutFile
// branches of ProcessMIE.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\MIE as Vars;

final class MIE
{
    public static $VERSION = '1.57';

    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckMIE(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = $tagInfo->Writable ?: $tagInfo->Table['WRITABLE'];
        $err = null;

        if (!$format or Perl::str($format) === '1') return 'No writable format';
        // handle units if supported by this tag
        $ulist = $tagInfo->Units;
        if ($ulist and preg_match('/(.*)\((.*)\)$/', Perl::str($valPtr), $m)) {
            [$val, $units] = [$m[1], $m[2]];
            $units = array_values(@preg_grep(ExifTool::Rx("^$units\$", 'i'), $ulist) ?: [])[0] ?? null;
            if ($units === null) return 'Allowed units: (' . implode('|', $ulist) . ')';
            $err = ExifTool::CheckValue($val, $format, $tagInfo->Count);
            // add units back onto value
            if (!$err) $valPtr = Perl::str($val) . "($units)";
        } elseif (!preg_match('/^(utf|string|undef)/', $format) and preg_match('/\)$/', Perl::str($valPtr))) {
            return 'Units not supported';
        } else {
            if ($format === 'string' and $et->OPTIONS['Charset'] !== 'UTF8' and
                preg_match('/[\x80-\xff]/', Perl::str($valPtr)))
            {
                // convert from Charset to UTF-8
                $valPtr = $et->Encode($valPtr, 'UTF8');
            }
            $err = ExifTool::CheckValue($valPtr, $format, $tagInfo->Count);
        }
        return $err;
    }

    //------------------------------------------------------------------------------
    // Get localized version of tagInfo hash
    // Inputs: 0) tagInfo hash ref, 1) locale code (eg. "en_CA")
    // Returns: new tagInfo hash ref, or undef if invalid
    public static function GetLangInfo($tagInfo, $langCode)
    {
        // check for properly formatted language code
        if (!preg_match('/^[a-z]{2}([-_])[A-Z]{2}$/', Perl::str($langCode), $m)) return null;
        // use '_' as a separator, but recognize '_' or '-'
        if ($m[1] === '-') $langCode = strtr($langCode, '-', '_');
        // can only set locale on string types
        if ($tagInfo->Writable and Perl::str($tagInfo->Writable) !== 'string') return null;
        return ExifTool::GetLangInfo($tagInfo, $langCode);
    }

    //------------------------------------------------------------------------------
    // return true if we have Zlib::Compress
    // Inputs: 0) ExifTool object ref, 1) verb for what you want to do with the info
    // Returns: 1 if Zlib available, 0 otherwise
    public static function HasZlib(ExifTool $et, $verb)
    {
        if (Vars::$hasZlib === null) {
            Vars::$hasZlib = function_exists('inflate_init') ? 1 : 0;   // (Perl: require Compress::Zlib)
            if (!Vars::$hasZlib) {
                Vars::$hasZlib = 0;
                $et->Warn("Install Compress::Zlib to $verb compressed information");
            }
        }
        return Vars::$hasZlib;
    }

    //------------------------------------------------------------------------------
    // ReadValue() with added support for UTF formats (utf8, utf16 and utf32)
    // Inputs: 0) data reference, 1) value offset, 2) format string,
    //         3) number of values (or undef to use all data)
    //         4) valid data length relative to offset, 5) returned rational ref
    // Returns: converted value, or undefined if data isn't there
    //          or list of values in list context
    // Notes: all string formats are converted to UTF8
    public static function ReadMIEValue($dataPt, $offset, $format, $count, $size, &$ratPt = null)
    {
        $val = null;
        if (preg_match('/^(utf(8|16|32)|string)/', $format, $m)) {
            if ($m[1] === 'utf8' or $m[1] === 'string') {
                // read the 8-bit string
                $val = substr($dataPt, $offset, $size);
                // (as of ExifTool 7.62, leave string values unconverted)
            } else {
                // convert to UTF8
                if (ExifTool::GetByteOrder() === 'MM') {
                    $fmt = ($m[1] === 'utf16') ? 'n' : 'N';
                } else {
                    $fmt = ($m[1] === 'utf16') ? 'v' : 'V';
                }
                $unpk = Perl::unpack("x{$offset}{$fmt}{$size}", $dataPt);
                $val = Perl::pack('C0U*', ...$unpk);
            }
            // truncate at null unless this is a list
            // (strings shouldn't have a null, but just in case)
            if (!str_ends_with($format, '_list')) $val = preg_replace('/\0.*/s', '', $val);
        } else {
            if ($format === 'free') $format = 'undef';  // read 'free' as 'undef'
            return ExifTool::ReadValue($dataPt, $offset, $format, $count, $size, $ratPt);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Process MIE directory
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ref
    // Returns: undef on success, or error message if there was a problem
    // Notes: file pointer is positioned at the MIE end on entry
    public static function ProcessMIEGroup(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $notUTF8 = (Perl::str($et->OPTIONS['Charset']) !== 'UTF8');
        $msg = $buff = $ok = $oldIndent = $mime = null;
        $lastTag = '';

        // get group 1 names: $grp doesn't have numbers (eg. 'MIE-Doc'),
        // and $grp1 does (eg. 'MIE1-Doc1')
        $cnt = &$et->MIE_COUNT;
        $grp1 = Perl::str($tagTablePtr['GROUPS'][1] ?? null);
        $n = ($cnt['MIE-Main'] ?? null) ?: 0;
        if ($grp1 === 'MIE-Main') {
            $cnt[$grp1] = ++$n;
            if ($n > 1) $grp1 = preg_replace('/MIE-/', "MIE$n-", $grp1, 1);
        } else {
            if ($n > 1) $grp1 = preg_replace('/MIE-/', "MIE$n-", $grp1, 1);
            $cnt[$grp1] = (($cnt[$grp1] ?? null) ?: 0) + 1;
            if ($cnt[$grp1] > 1) $grp1 .= $cnt[$grp1];
        }
        unset($cnt);
        // set group1 name for all tags extracted from this group
        $et->SET_GROUP1 = $grp1;

        if ($verbose) {
            $oldIndent = $et->INDENT;
            $et->INDENT .= '| ';
            $et->VerboseDir($grp1);
        }
        $wasCompressed = $dirInfo['WasCompressed'] ?? null;

        // process all MIE elements
        for (;;) {
            if ($raf->Read($buff, 4) != 4) break;
            [$sync, $format, $tagLen, $valLen] = Perl::unpack('aC3', $buff);
            if ($sync !== '~') {
                $msg = 'Invalid sync byte';
                break;
            }

            // read tag name
            $tag = $units = null;
            if ($tagLen) {
                if ($raf->Read($tag, $tagLen) != $tagLen) break;
                if (strcmp($tag, $lastTag) < 0) $et->Warn("MIE tag '{$tag}' out of sequence");
                $lastTag = $tag;
                // separate units from tag name if they exist
                if (preg_match('/\((.*)\)$/', $tag, $m, PREG_OFFSET_CAPTURE)) {
                    $units = $m[1][0];
                    $tag = substr_replace($tag, '', $m[0][1], strlen($m[0][0]));
                }
            } else {
                $tag = '';
            }

            // get multi-byte value length if necessary
            if ($valLen > 252) {
                $n = 1 << (256 - $valLen);
                if ($raf->Read($buff, $n) != $n) break;
                $fmt = 'int' . ($n * 8) . 'u';
                $valLen = ExifTool::ReadValue($buff, 0, $fmt, 1, $n);
                if ($valLen > 0x7fffffff) {
                    $msg = "Can't read $tag (DataLength > 2GB not yet supported)";
                    break;
                }
            }

            // all done if we reached the group terminator
            if (!$tagLen) {
                // skip over terminator data block
                if (!($valLen and !$raf->Seek($valLen, 1))) $ok = 1;
                break;
            }

            // get tag information hash unless this is free space
            $tagInfo = $value = null;
            while ($format != 0x80) {
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if ($tagInfo) break;
                // extract tags with locale code
                if (preg_match('/\W/', $tag)) {
                    if (preg_match('/^(\w+)-([a-z]{2}_[A-Z]{2})$/', $tag, $m)) {
                        [$baseTag, $langCode] = [$m[1], $m[2]];
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $baseTag);
                        if ($tagInfo) $tagInfo = self::GetLangInfo($tagInfo, $langCode);
                        if ($tagInfo) break;
                    } else {
                        $et->Warn('Invalid MIE tag name');
                        break;
                    }
                }
                // extract unknown tags if specified
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, [
                    'Name' => $tag,
                    'Writable' => 0,
                    'PrintConv' => 'Image\ExifTool\ExifTool::LimitLongValues',
                ]);
                break;
            }

            // read value and uncompress if necessary
            $formatStr = (Vars::$mieFormat[$format & 0xfb] ?? null) ?: 'undef';
            if ($tagInfo or ($formatStr === 'MIE' and $format & 0x04)) {
                if ($raf->Read($value, $valLen) != $valLen) break;
                if ($format & 0x04) {
                    if ($verbose) {
                        fwrite($out, "{$et->INDENT}[Tag '{$tag}' $valLen bytes compressed]\n");
                    }
                    if (!self::HasZlib($et, 'decode')) continue;
                    // (Perl: Compress::Zlib::inflateInit() and inflate())
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    $v2 = $inflate ? @inflate_add($inflate, $value, ZLIB_SYNC_FLUSH) : false;
                    if (!($inflate and $v2 !== false and inflate_get_status($inflate) == ZLIB_STREAM_END)) {
                        $et->Warn("Error inflating $tag");
                        continue;
                    }
                    $value = $v2;
                    $valLen = strlen($value);
                    $wasCompressed = 1;
                }
            }

            // process this tag
            if ($formatStr === 'MIE') {
                // process MIE directory
                $subTablePtr = $dirName = null;
                if ($tagInfo and $tagInfo->SubDirectory) {
                    $dirName = $tagInfo->SubDirectory['DirName'] ?? null;
                    $subTable = $tagInfo->SubDirectory['TagTable'] ?? null;
                    $subTablePtr = $subTable ? ExifTool::GetTagTable($subTable) : $tagTablePtr;
                } else {
                    $subTablePtr = ExifTool::GetTagTable('Image::ExifTool::MIE::Unknown');
                }
                if ($verbose) {
                    $order = ', byte order ' . ExifTool::GetByteOrder();
                    $et->VerboseInfo($tag, $tagInfo, Size: $valLen, Extra: $order);
                }
                $subdirInfo = [
                    'DirName' => $dirName ?: $tag,
                    'RAF'     => $raf,
                    'Parent'  => $dirInfo['DirName'] ?? null,
                    'WasCompressed' => $wasCompressed,
                ];
                // read from uncompressed data instead if necessary
                if ($valLen) $subdirInfo['RAF'] = new RandomAccess(Perl::str($value));

                $oldOrder = ExifTool::GetByteOrder();
                ExifTool::SetByteOrder($format & 0x08 ? 'II' : 'MM');
                $msg = self::ProcessMIEGroup($et, $subdirInfo, $subTablePtr);
                ExifTool::SetByteOrder($oldOrder);
                $et->SET_GROUP1 = $grp1;    // restore this group1 name
                if ($msg) break;
            } else {
                // process MIE data format types
                if ($tagInfo) {
                    $rational = $binVal = null;
                    // extract tag value
                    $val = self::ReadMIEValue($value, 0, $formatStr, null, $valLen, $rational);
                    if ($et->OPTIONS['SaveBin']) $binVal = substr($value, 0, $valLen);
                    if ($val === null) {
                        $et->Warn("Error reading $tag value");
                        $val = '<err>';
                    }
                    // save type or mime type
                    if ($tag === '0Type' or $tag === '2MIME') $mime = $val;
                    if ($verbose) {
                        $count = null;
                        $s = ExifTool::FormatSize($formatStr);
                        if ($s and !preg_match('/^(utf|string|undef)/', $formatStr)) {
                            $count = $valLen / $s;
                        }
                        $et->VerboseInfo($lastTag, $tagInfo,
                            DataPt: $value,
                            DataPos: $wasCompressed ? null : $raf->Tell() - $valLen,
                            Size: $valLen,
                            Format: $formatStr,
                            Value: $val,
                            Count: $count,
                        );
                    }
                    if ($tagInfo->SubDirectory) {
                        $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                        $subdirInfo = [
                            'DirName' => $tagInfo->Name,
                            'DataPt'  => $value,
                            'DataLen' => $valLen,
                            'DirStart' => 0,
                            'DirLen'  => $valLen,
                            'Parent'  => $dirInfo['DirName'] ?? null,
                            'WasCompressed' => $wasCompressed,
                        ];
                        // set DataPos and Base for uncompressed information only
                        if (!$wasCompressed) {
                            $subdirInfo['DataPos'] = 0; // (relative to Base)
                            $subdirInfo['Base']    = $raf->Tell() - $valLen;
                        }
                        // reset PROCESSED lookup for each MIE directory
                        // (there is no possibility of double-processing a MIE directory)
                        $et->PROCESSED = [];
                        $processProc = $tagInfo->SubDirectory['ProcessProc'] ?? null;
                        $et->SET_GROUP1 = null;
                        $et->NO_LIST = null;
                        $et->ProcessDirectory($subdirInfo, $subTablePtr, $processProc);
                        $et->SET_GROUP1 = $grp1;
                        $et->NO_LIST = 1;
                    } else {
                        // convert to specified character set if necessary
                        if ($notUTF8 and preg_match('/^(utf|string)/', $formatStr)) {
                            $val = $et->Decode($val, 'UTF8');
                        }
                        if (str_ends_with($formatStr, '_list')) {
                            // split list value into separate strings
                            $val = Perl::split('/\0/', $val);
                        }
                        if ($units !== null) {
                            if (is_array($val)) $val = implode(' ', $val);  // convert string list to number list
                            // add units to value if specified
                            $val = Perl::str($val) . "($units)";
                        }
                        $key = $et->FoundTag($tagInfo, $val);
                        if ($key !== null) {
                            if ($rational !== null) $et->TAG_EXTRA[$key]['Rational'] = $rational;
                            if ($binVal !== null) $et->TAG_EXTRA[$key]['BinVal'] = $binVal;
                            if ($et->OPTIONS['SaveFormat']) $et->TAG_EXTRA[$key]['G6'] = $formatStr;
                        }
                    }
                } else {
                    // skip over unknown information or free bytes
                    if (!$raf->Seek($valLen, 1)) {
                        $msg = 'Seek error';
                        break;
                    }
                    if ($verbose) $et->VerboseInfo($tag, null, Size: $valLen);
                }
            }
        }
        // modify MIME type if necessary
        if ($mime and empty($dirInfo['Parent'])) $et->ModifyMimeType($mime);

        if (!($ok or $msg)) $msg = 'Unexpected end of file';
        if ($verbose) $et->INDENT = $oldIndent;
        return $msg;
    }

    //------------------------------------------------------------------------------
    // Read/write a MIE file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid MIE file, or -1 on write error
    // - process as a trailer if "Trailer" flag set in dirInfo
    public static function ProcessMIE(?ExifTool $et, array &$dirInfo)
    {
        if ($et === null) return 1;
        $raf = $dirInfo['RAF'];
        $buff = $err = $msg = $pos = $end = null;
        $numDocs = 0;
//
// process as a trailer (from end of file) if specified
//
        if (!empty($dirInfo['Trailer'])) {
            $offset = ($dirInfo['Offset'] ?? null) ?: 0;    // offset from end of file
            if (!$raf->Seek(-10 - $offset, 2)) return 0;
            for (;;) {
                // read and validate last 10 bytes
                if ($raf->Read($buff, 10) != 10) break;
                if (!(preg_match('/~\0\0\x06.{4}(\x10|\x18)(\x04)$/s', $buff, $m) or
                      preg_match('/(\x10|\x18)(\x08)$/s', $buff, $m))) break;
                ExifTool::SetByteOrder($m[1] === "\x10" ? 'MM' : 'II');
                $len = ($m[2] === "\x04") ? ExifTool::Get32u($buff, 4) : ExifTool::Get64u($buff, 0);
                $curPos = $raf->Tell();
                if (!$curPos) break;
                if ($len < 12 or $len > $curPos) break;
                // validate element header if 8-byte offset was used
                if ($m[2] === "\x08") {
                    if ($len < 14) break;
                    if (!($raf->Seek($curPos - 14, 0) and $raf->Read($buff, 4))) break;
                    if ($buff !== "~\0\0\x0a") break;
                }
                // looks like a good group, so remember start position
                $pos = $curPos - $len;
                if (!$end) $end = $curPos;
                // seek to 10 bytes from end of previous group
                if (!$raf->Seek($pos - 10, 0)) break;
            }
            // seek to start of first MIE group
            if (!($pos !== null and $raf->Seek($pos, 0))) return 0;
            // update DataPos and DirLen for ProcessTrailers()
            $dirInfo['DataPos'] = $pos;
            $dirInfo['DirLen'] = $end - $pos;
            if (($dirInfo['OutFile'] ?? null) and ($et->DEL_GROUP['MIE'] ?? null)) {
                // delete the trailer
                $et->VPrint(0, "  Deleting MIE trailer\n");
                ++$et->CHANGED;
                return 1;
            } elseif ($et->Options('Verbose') or $et->HTML_DUMP) {
                $et->DumpTrailer($dirInfo);
            }
        }
        // (PHP: writing MIE is not ported yet)
        if ($dirInfo['OutFile'] ?? null) {
            $et->Error('Not ported: writing MIE', 1);
            return 0;
        }
//
// loop through all documents in MIE file
//
        for (;;) {
            // look for "0MIE" group element
            $num = $raf->Read($buff, 8);
            if ($num == 8) {
                // verify file identifier
                if (preg_match('/^~(\x10|\x18)\x04(.)0MIE/s', $buff, $m)) {
                    ExifTool::SetByteOrder($m[1] === "\x10" ? 'MM' : 'II');
                    $len = ord($m[2]);
                    // skip extended DataLength if it exists
                    if ($len > 252 and !$raf->Seek(1 << (256 - $len), 1)) {
                        $msg = 'Seek error';
                        break;
                    }
                } else {
                    if (!$numDocs) return 0;    // not a MIE file
                    if (str_starts_with($buff, '~')) {
                        $msg = 'Non-standard file-level MIE element';
                    } else {
                        $msg = 'Invalid MIE file-level data';
                    }
                }
            } elseif ($numDocs) {
                if (!$num) break;   // OK, all done with file
                $msg = 'Truncated MIE element header';
            } else {
                // (OutFile: creating a MIE file from scratch is not ported)
                return 0;
            }
            if ($msg) {
                if (!empty($dirInfo['Trailer'])) break; // allow other trailers after MIE
                $et->Warn($msg);
                break;
            }
            // this is a new MIE document -- increment document count
            if (!$numDocs) {
                // this is a valid MIE file (unless a trailer on another file)
                $et->SetFileType();
                $et->NO_LIST = 1;   // handle lists ourself
                $et->MIE_COUNT = [];
                Vars::$hasZlib = null;
            }
            ++$numDocs;

            // process the MIE groups recursively, beginning with the main MIE group
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MIE::Main');

            $subdirInfo = [
                'DirName' => 'MIE',
                'RAF' => $raf,
                'OutFile' => null,
                // don't define Parent so WriteMIEGroup() writes extended terminator
            ];
            $msg = self::ProcessMIEGroup($et, $subdirInfo, $tagTablePtr);
            if ($msg) {
                $et->Warn($msg);
                break;
            }
        }
        $et->NO_LIST = null;
        $et->MIE_COUNT = null;
        $et->SET_GROUP1 = null;
        return $err ? -1 : 1;
    }
}
