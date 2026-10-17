<?php

//------------------------------------------------------------------------------
// File:         Matroska.pm
//
// Description:  Read meta information from Matroska multimedia files
//
// Revisions:    05/26/2010 - P. Harvey Created
//
// References:   1) http://www.matroska.org/technical/specs/index.html
//               2) https://www.matroska.org/technical/tagging.html
//
// PHP port of Image::ExifTool::Matroska 1.20 (reading only). Tag tables are generated
// into tables/Matroska.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Matroska
{
    public static $VERSION = '1.20';

    //------------------------------------------------------------------------------
    // Handle MKV SimpleTag structure
    // Inputs: 0) ExifTool ref, 1) structure ref, 2) parent tag ID, 3) parent tag Name,
    //         4) language code, 5) country code
    // (PHP: a structure is an \ArrayObject so it can be shared like the Perl hash ref)
    public static function HandleStruct(ExifTool $et, \ArrayObject $struct, $pid = null, $pname = null, $lang = null, $ctry = null)
    {
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::Matroska::StdTag');
        $tag = Perl::str($struct['TagName'] ?? null);
        $tagInfo = $tagTbl[$tag];
        // create tag if necessary
        if (!$tagInfo instanceof TagInfo) {
            $name = ucfirst(strtolower($tag));
            $name = preg_replace('/[^0-9a-zA-Z_]/', '', $name);
            $name = preg_replace_callback('/_([a-z])/', static fn ($m) => strtoupper($m[1]), $name);
            if (strlen($name) < 2) $name = "Tag_$name";
            $et->VPrint(0, "  [adding $tag = $name]\n");
            $tagInfo = ExifTool::AddTagToTable($tagTbl, $tag, ['Name' => $name]);
        }
        $id = $nm = null;
        if ($pid) {
            $id = "$pid/$tag";
            $nm = "$pname/{$tagInfo->Name}";
            if (!isset($tagTbl[$id])) {
                $copy = $tagInfo->toArray();
                $copy['Name'] = $nm;
                $et->VPrint(0, "  [adding $id = $nm]\n");
                $tagInfo = ExifTool::AddTagToTable($tagTbl, $id, $copy);
            }
        } else {
            [$id, $nm] = [$tag, $tagInfo->Name];
        }
        if (isset($struct['TagString']) or isset($struct['TagBinary'])) {
            $val = isset($struct['TagString']) ? $struct['TagString'] : new ScalarRef($struct['TagBinary']);
            $lang = (($struct['TagLanguageBCP47'] ?? null) ?: ($struct['TagLanguage'] ?? null)) ?: $lang;
            // (Note: not currently handling TagDefault attribute)
            $code = $lang;
            if ($ctry) $code = $lang ? Perl::str($lang) . '-' . Perl::str($ctry) : 'eng-' . Perl::str($ctry); // ('eng' is default lang)
            if ($code) {
                $tagInfo = ExifTool::GetLangInfo($tagInfo, $code);
                $et->HandleTag($tagTbl, $tagInfo->TagID, $val);
            } else {
                $et->HandleTag($tagTbl, $id, $val);
            }
            // COUNTRY is handled as an attribute for contained tags
            if ($tag === 'COUNTRY') {
                $ctry = $val;
                [$id, $nm] = [$pid, $pname];
            }
        }
        if (!empty($struct['struct'])) {
            // step into each contained structure
            foreach ($struct['struct'] as $_) self::HandleStruct($et, $_, $id, $nm, $lang, $ctry);
        }
    }

    //------------------------------------------------------------------------------
    // Get variable-length Matroska integer
    // Inputs: 0) data buffer, 1) position in data
    // Returns: integer value and updates position, -1 for unknown/reserved value,
    //          or undef if no data left
    // Notes: Increments position pointer
    public static function GetVInt($buff, &$pos)
    {
        if ($pos >= strlen($buff)) return null;
        $val = ord($buff[$pos++]);
        $num = 0;
        if (!$val) {
            if ($pos >= strlen($buff)) return null;
            $val = ord($buff[$pos++]);
            if (!$val) return null;     // can't be this large!
            $num += 7;  // 7 more bytes to read (we just read one)
        }
        $mask = 0x7f;
        while ($val == ($val & $mask)) {
            $mask >>= 1;
            ++$num;
        }
        $val = ($val & $mask);
        $unknown = ($val == $mask);
        if ($pos + $num > strlen($buff)) return null;
        while ($num) {
            $b = ord($buff[$pos++]);
            if ($b != 0xff) $unknown = 0;
            $val = $val * 256 + $b;
            --$num;
        }
        return $unknown ? -1 : $val;
    }

    //------------------------------------------------------------------------------
    // Read information from a Matroska multimedia file (MKV, MKA, MKS)
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid Matroska file
    public static function ProcessMKV(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $trackIndent = $struct = null;
        $dirEnd = $trackNum = $seekInfo = $seek = [];

        if ($raf->Read($buff, 4) != 4) return 0;
        if (!preg_match('/^\x1a\x45\xdf\xa3/', $buff)) return 0;

        // read in 64kB blocks (already read 4 bytes)
        if (!$raf->Read($buff, 65532)) return 0;
        $dataLen = strlen($buff);
        [$pos, $dataPos] = [0, 4];

        // verify header length
        $hlen = self::GetVInt($buff, $pos);
        if (!($hlen and $hlen > 0)) return 0;
        if ($pos + $hlen > $dataLen) {
            $et->Warn('Truncated Matroska header');
            return 1;
        }
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Matroska::Main');

        // set flag to process entire file (otherwise we stop at the first Cluster)
        $verbose = $et->Options('Verbose');
        $processAll = ($verbose or $et->Options('Unknown') > 1) ? 2 : 0;
        if ($et->Options('ExtractEmbedded')) ++$processAll;
        // store Track types reference (PHP: %trackTypes is read back through the
        // ExifTool member, because the TrackType RawConv writes $$self{TrackTypes})
        $et->TrackTypes = [];
        $trackTypes = &$et->TrackTypes;
        $et->SeekHeadOffset = 0;
        $oldIndent = $et->INDENT;
        $chapterNum = 0;
        $dirName = 'MKV';

        // loop over all Matroska elements
        for (;;) {
            while ($dirEnd) {
                $last = count($dirEnd) - 1;
                if ($pos + $dataPos >= $dirEnd[$last][0]) {
                    if ($dirEnd[$last][1] === 'Seek') {
                        // save seek info
                        if (isset($seekInfo['ID']) and isset($seekInfo['Position'])) {
                            $seekTag = $tagTablePtr[Perl::str($seekInfo['ID'])];
                            if ($seekTag instanceof TagInfo and $seekTag->Name) {
                                $seek[$seekTag->Name] = $seekInfo['Position'] + $et->SeekHeadOffset;
                            }
                        }
                        $seekInfo = [];
                    }
                    array_pop($dirEnd);
                    if ($struct) {
                        $last = count($dirEnd) - 1;
                        if ($dirEnd and $dirEnd[$last][2]) {
                            // save this nested structure
                            if (empty($dirEnd[$last][2]['struct'])) $dirEnd[$last][2]['struct'] = [];
                            $dirEnd[$last][2]['struct'][] = $struct;
                            $struct = $dirEnd[$last][2];
                        } else {
                            // handle completed structures now
                            self::HandleStruct($et, $struct);
                            $struct = null;
                        }
                    }
                    $dirName = $dirEnd ? $dirEnd[count($dirEnd) - 1][1] : 'MKV';
                    // use INDENT to decide whether or not we are done this Track element
                    if ($trackIndent and $trackIndent === $et->INDENT) $et->SET_GROUP1 = null;
                    $et->INDENT = substr($et->INDENT, 0, -2);
                    array_pop($et->PATH);
                } else {
                    $dirName = $dirEnd[$last][1];
                    break;
                }
            }
            // read more if we are getting close to the end of our buffer
            // (24 more bytes should be enough to read this element header)
            if ($pos + 24 > $dataLen and $raf->Read($buf2, 65536)) {
                $buff = substr($buff, $pos) . $buf2;
                $buf2 = null;
                $dataPos += $pos;
                $dataLen = strlen($buff);
                $pos = 0;
            }
            $tag = self::GetVInt($buff, $pos);
            if (!($tag !== null and $tag >= 0)) break;
            if ($tag == 0x14d9b74) $et->SeekHeadOffset = $pos;  // save offset of seek head
            $size = self::GetVInt($buff, $pos);
            if ($size === null) break;
            $unknownSize = $seekInfoOnly = $tagName = null;
            if ($size < 0) {
                $unknownSize = 1;
                $size = 1e20;
            }
            if ($dirEnd and $pos + $dataPos + $size > $dirEnd[count($dirEnd) - 1][0]) {
                $et->Warn("Invalid or corrupted {$dirEnd[count($dirEnd) - 1][1]} master element");
                $pos = $dirEnd[count($dirEnd) - 1][0] - $dataPos;
                if ($pos < 0 or $pos > $dataLen) {
                    $buff = '';
                    $dataPos += $pos;
                    $dataLen = 0;
                    $pos = 0;
                    if (!$raf->Seek($dataPos, 0)) break;
                }
                continue;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo and $tagTablePtr[$tag] instanceof TagInfo and $tagTablePtr[$tag]->SeekInfo) {
                $tagInfo = $tagTablePtr[$tag];
                $seekInfoOnly = 1;
            }
            if ($tagInfo) {
                $tagName = $tagInfo->Name;
                if ($tagInfo->SubDirectory and !$tagInfo->NotEBML) {
                    // stop processing at first cluster unless we are using -v -U or -ee
                    // or there are Tags after this
                    if ($tagName === 'Cluster' and $processAll < 2) {
                        // jump to Tags if possible
                        if (!$processAll) {
                            if (!empty($seek['Tags']) and $seek['Tags'] > $pos + $dataPos and $raf->Seek($seek['Tags'], 0)) {
                                $buff = '';
                                $dataPos = $seek['Tags'];
                                $pos = $dataLen = 0;
                                continue;
                            }
                            break;
                        }
                        $tagInfo = null; // just skip the Cluster when -ee is used
                    } else {
                        // just fall through into the contained EBML elements
                        $et->INDENT .= '| ';
                        $dirName = $tagName;
                        $et->VerboseDir($dirName, null, $size);
                        $et->PATH[] = $dirName;
                        $dirEnd[] = [$pos + $dataPos + $size, $dirName, $struct];
                        if ($dirName === 'SimpleTag') $struct = new \ArrayObject();  // keep track of SimpleTag elements
                        // set Chapter# and Info family 1 group names
                        if ($tagName === 'ChapterAtom') {
                            $et->SET_GROUP1 = 'Chapter' . (++$chapterNum);
                            $trackIndent = $et->INDENT;
                        } elseif ($tagName === 'Info' and !$et->SET_GROUP1) {
                            $et->SET_GROUP1 = 'Info';
                            $trackIndent = $et->INDENT;
                        }
                        continue;
                    }
                }
            } elseif ($verbose) {
                $et->VPrint(0, Perl::sprintf("{$et->INDENT}- Tag 0x%x (Unknown, %d bytes)\n", $tag, $size));
            }
            if ($unknownSize) break;
            if ($pos + $size > $dataLen) {
                // how much more do we need to read?
                $more = $pos + $size - $dataLen;
                // just skip unknown and large data blocks
                if (!$tagInfo or $more > 10000000) {
                    // don't try to skip very large blocks unless LargeFileSupport is enabled
                    if ($more >= 0x80000000) {
                        if (!$et->Options('LargeFileSupport')) break;
                        if (Perl::str($et->Options('LargeFileSupport')) === '2') {
                            $et->Warn('Processing large block (LargeFileSupport is 2)');
                        }
                    }
                    if (!$raf->Seek($more, 1)) break;
                    $buff = '';
                    $dataPos += $dataLen + $more;
                    $dataLen = 0;
                    $pos = 0;
                    continue;
                } else {
                    // read data in multiples of 64kB
                    $more = (Perl::int($more / 65536) + 1) * 65536;
                    if ($raf->Read($buf2, $more)) {
                        $buff = substr($buff, $pos) . $buf2;
                        $buf2 = null;
                        $dataPos += $pos;
                        $dataLen = strlen($buff);
                        $pos = 0;
                    }
                    if ($pos + $size > $dataLen) break;
                }
            }
            if (!$tagInfo) {
                // ignore the element
                $pos += $size;
                continue;
            }
            $val = null;
            if ($tagInfo->Format) {
                $fmt = $tagInfo->Format;
                if ($fmt === 'string' or $fmt === 'utf8') {
                    $val = preg_replace('/\0.*/s', '', substr($buff, $pos, $size));
                    if ($fmt === 'utf8') $val = $et->Decode($val, 'UTF8');
                } elseif ($fmt === 'float') {
                    if ($size == 4) {
                        $val = ExifTool::GetFloat($buff, $pos);
                    } elseif ($size == 8) {
                        $val = ExifTool::GetDouble($buff, $pos);
                    } else {
                        $et->Warn("Illegal float size ($size)");
                    }
                } else {
                    $vals = Perl::unpack("x{$pos}C$size", $buff);
                    $val = 0;
                    if ($fmt === 'signed' or $fmt === 'date') {
                        $over = 1;
                        foreach ($vals as $_) {
                            $val = $val * 256 + $_;
                            $over *= 256;
                        }
                        // interpret negative numbers
                        if (($vals[0] ?? 0) & 0x80) $val -= $over;
                        // convert dates (nanoseconds since 2001:01:01)
                        if ($fmt === 'date') {
                            $t = $val / 1e9;
                            // (8 leap days between 1970 and 2001)
                            $t += (((2001-1970)*365+8)*24*3600);
                            $val = ExifTool::ConvertUnixTime($t, null, -9) . 'Z';
                        }
                    } else { // must be unsigned
                        foreach ($vals as $_) $val = $val * 256 + $_;
                    }
                }
                if ($tagName === 'TrackNumber') {
                    // set Track# family 1 group name for tags directly in the track
                    $et->SET_GROUP1 = 'Track' . Perl::str($val);
                    $trackIndent = $et->INDENT;
                } elseif ($tagName === 'TrackUID' and $et->SET_GROUP1) {
                    // save the Track# group associated with this TrackUID
                    $trackNum[Perl::str($val)] = $et->SET_GROUP1;
                } elseif ($tagName === 'TagTrackUID' and !empty($trackNum[Perl::str($val)])) {
                    // set Track# group for associated SimpleTags tags
                    $et->SET_GROUP1 = $trackNum[Perl::str($val)];
                    // we're already one deeper than the level where we want to
                    // reset the group name, so trigger at one indent level higher
                    $trackIndent = substr($et->INDENT, 0, -2);
                }
            }
            $parms = [
                'DataPt'  => $buff,
                'DataPos' => $dataPos,
                'Start'   => $pos,
                'Size'    => $size,
            ];
            if ($tagInfo->NoSave or $struct) {
                if ($verbose) $et->VerboseInfo($tag, $tagInfo, ...(['Value' => $val] + $parms));
                if ($struct) $struct[$tagName] = $val;
            } elseif ($tagInfo->SeekInfo) {
                $p = $pos;
                if ($val === null) $val = self::GetVInt($buff, $p);
                $seekInfo[$tagInfo->SeekInfo] = $val;
                if (!$seekInfoOnly) $et->HandleTag($tagTablePtr, $tag, $val, ...$parms);
            } else {
                $et->HandleTag($tagTablePtr, $tag, $val, ...$parms);
            }
            $pos += $size;  // step to next element
        }
        $et->INDENT = $oldIndent;
        $et->SET_GROUP1 = null;
        // override file type if necessary based on existing track types
        if (!(!empty($trackTypes[0x01]) or !empty($trackTypes[0x03]))) {   // video or complex?
            if (!empty($trackTypes[0x02])) {                        // audio?
                $et->OverrideFileType('MKA');
            } elseif (!empty($trackTypes[0x11])) {                  // subtitle?
                $et->OverrideFileType('MKS');
            }
        }
        return 1;
    }
}
