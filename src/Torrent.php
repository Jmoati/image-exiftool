<?php

//------------------------------------------------------------------------------
// File:         Torrent.pm
//
// Description:  Read information from BitTorrent file
//
// Revisions:    2013/08/27 - P. Harvey Created
//
// References:   1) https://wiki.theory.org/BitTorrentSpecification
//
// PHP port of Image::ExifTool::Torrent 1.08. Tag tables are generated into tables/Torrent.php.
// Bencode dictionaries are PHP arrays with string keys, lists are PHP lists (ponytail: a
// dictionary whose keys are exactly "0", "1"... reads as a list; wrap dictionaries in an
// object if that ever matters), binary byte strings are ScalarRef's.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Torrent
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Read 64kB more data into buffer
    // Inputs: 0) RAF ref, 1) buffer ref
    //         (PHP: 2) pos($$dataPt), updated by reference)
    // Returns: number of bytes read
    // Notes: Sets BencodeEOF element of RAF on end of file
    public static function ReadMore(RandomAccess $raf, &$dataPt, &$pos)
    {
        $buf2 = null;
        $n = $raf->Read($buf2, 65536);
        if ($n != 65536) $raf->BencodeEOF = 1;
        if ($n) {
            $dataPt = substr($dataPt, (int) $pos) . $buf2;
            $pos = 0;   // (the assignment resets pos)
        }
        return $n;
    }

    //------------------------------------------------------------------------------
    // Read bencoded value
    // Inputs: 0) ExifTool ref, 1) input file, 2) buffer (pos must be set to current position)
    //         (PHP: 3) pos($$dataPt), updated by reference, null when undefined)
    // Returns: HASH ref, ARRAY ref, SCALAR ref, SCALAR, or undef on error or end of data
    // Notes: Sets BencodeError element of RAF on any error
    public static function ReadBencode(ExifTool $et, RandomAccess $raf, &$dataPt, &$pos)
    {
        // read more if necessary (keep a minimum of 64 bytes in the buffer)
        if ($pos === null) return null;
        $remaining = strlen($dataPt) - $pos;
        if ($remaining < 64 and !$raf->BencodeEOF) self::ReadMore($raf, $dataPt, $pos);

        // read next token
        if ($pos >= strlen($dataPt)) {
            $pos = null;    // (a failed m//g resets pos)
            return null;
        }
        $tok = $dataPt[$pos++];

        $val = null;
        if ($tok === 'i') {      // integer
            if (!preg_match('/\G(-?\d+)e/', $dataPt, $m, 0, $pos)) {
                $pos = null;
                return $val;
            }
            $pos += strlen($m[0]);
            $val = $m[1];
        } elseif ($tok === 'd') { // dictionary
            $val = [];
            for (;;) {
                $k = self::ReadBencode($et, $raf, $dataPt, $pos);
                if ($k === null) break;
                // the key must be a byte string
                if (is_array($k) or $k instanceof ScalarRef) {
                    if (!$k instanceof ScalarRef) {
                        $raf->BencodeError = 'Bad dictionary key';
                        break;
                    }
                    $k = $k->value;
                }
                $v = self::ReadBencode($et, $raf, $dataPt, $pos);
                if ($v === null) break;
                $val[$k] = $v;
            }
        } elseif ($tok === 'l') { // list
            $val = [];
            for (;;) {
                $v = self::ReadBencode($et, $raf, $dataPt, $pos);
                if ($v === null) break;
                $val[] = $v;
            }
        } elseif ($tok === 'e') { // end of dictionary or list
            // return undef (no error)
        } else {
            $isStr = false;
            if (ctype_digit($tok)) {
                if (preg_match('/\G(\d*):/', $dataPt, $m, 0, $pos)) {
                    $pos += strlen($m[0]);
                    $isStr = true;
                } else {
                    $pos = null;
                }
            }
            if ($isStr) { // byte string
                $len = $tok . $m[1];
                $more = $len - (strlen($dataPt) - $pos);
                $value = null;
                if ($more <= 0) {
                    $value = substr($dataPt, $pos, (int) $len);
                    $pos = $pos + $len;
                } elseif ($more > 10000000) {
                    // just skip over really long values
                    if ($raf->Seek($more, 1)) $val = new ScalarRef("(Binary data $len bytes)");
                } else {
                    // need to read more from file
                    $buff = null;
                    $n = $raf->Read($buff, $more);
                    if ($n == $more) {
                        $value = substr($dataPt, $pos) . $buff;
                        $dataPt = '';
                        $pos = 0;
                    }
                }
                if ($value !== null) {
                    // return as binary data unless it is a reasonable-length ASCII string
                    if (strlen($value) > 256) {
                        $val = new ScalarRef($value);
                    } elseif (preg_match('/[^\t\x20-\x7e]/', $value)) {
                        if (ExifTool::IsUTF8($value) >= 0) {
                            $val = $et->Decode($value, 'UTF8');
                        } else {
                            $val = new ScalarRef($value);
                        }
                    } else {
                        $val = $value;
                    }
                } elseif ($val === null) {
                    $raf->BencodeError = 'Truncated byte string';
                }
            } else {
                $raf->BencodeError = 'Bad format';
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Extract tags from dictionary hash
    // Inputs: 0) ExifTool ref, 1) dictionary hash reference, 2) tag table ref,
    //         3) parent hash ID, 4) parent hash name, 5-N) list indices
    // Returns: number of tags extracted
    public static function ExtractTags(ExifTool $et, array $hashPtr, TagTable $tagTablePtr, $baseID = null, $baseName = null, ...$index)
    {
        $count = 0;
        $tags = array_map('strval', array_keys($hashPtr));
        sort($tags, SORT_STRING);
        foreach ($tags as $tag) {
            $val = $hashPtr[$tag];
            $i = null;
            $more = [];
            for (; $val !== null; $val = array_shift($more)) {
                $id = $baseID !== null ? "$baseID/$tag" : $tag;
                if (!$tagTablePtr[$id]) {
                    $name = ucfirst($tag);
                    // capitalize all words in tag name and remove illegal characters
                    $name = preg_replace_callback('/[^-_a-zA-Z0-9]+(.?)/', static fn ($m) => strtoupper($m[1]), $name);
                    if (strlen($name) < 2 or !preg_match('/^[A-Z]/', $name)) $name = "Tag$name";
                    if ($baseName !== null) $name = $baseName . $name; // add base name if necessary
                    ExifTool::AddTagToTable($tagTablePtr, $id, ['Name' => $name]);
                    $et->VPrint(0, "  [adding $id '{$name}']\n");
                }
                $tagInfo = $et->GetTagInfo($tagTablePtr, $id);
                if (!$tagInfo) continue;
                if (is_array($val) and array_is_list($val)) {
                    if ($tagInfo->JoinPath) {
                        $val = implode('/', array_map(static fn ($v) => (is_array($v) or $v instanceof ScalarRef) ? '(Binary data)' : $v, $val));
                    } else {
                        if (!$val) continue;    // ignore empty arrays
                        array_push($more, ...$val);
                        if (is_array($more[0]) and array_is_list($more[0])) continue;  // continue expanding nested lists
                        $val = array_shift($more);
                        if (!$i) {
                            $i = 0;
                            $index[] = $i;
                        }
                    }
                }
                if ($i !== null) $index[count($index) - 1] = ++$i;
                if ($index) {
                    $id .= implode('_', $index);    // add instance number(s) to tag ID
                    if (!$tagTablePtr[$id]) {
                        $name = $tagInfo->Name;
                        // embed indices at position of '1' in tag name
                        $n = substr_count($name, '1');
                        $name = strtr($name, '1', '#');
                        for ($j = 0; $j < $n; ++$j) {
                            $idx = ($index[$j] ?? null) ?: '';
                            $name = preg_replace('/#/', (string) $idx, $name, 1);
                        }
                        // put remaining indices at end of tag name
                        for (; $j < count($index); ++$j) {
                            if (preg_match('/\d$/', $name)) $name .= '_';
                            $name .= $index[$j];
                        }
                        $copy = clone $tagInfo;
                        $copy->Name = $name;
                        ExifTool::AddTagToTable($tagTablePtr, $id, $copy);
                    }
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $id);
                    if (!$tagInfo) continue;
                }
                if (is_array($val) and !array_is_list($val)) {
                    if ($et->Options('Struct') and $tagInfo and $tagInfo->Name === 'Info') {
                        $et->FoundTag($tagInfo, $val);
                        ++$count;
                        continue;
                    }
                    // extract tags from this dictionary
                    $rootID = $rootName = null;
                    if ($tagInfo->SubDirectory) {
                        $table = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    } else {
                        $table = $tagTablePtr;
                        // use hash ID and Name as base for contained tags to avoid conflicts
                        $rootID = $id;
                        $rootName = $tagInfo->Name;
                    }
                    $count += self::ExtractTags($et, $val, $table, $rootID, $rootName, ...$index);
                } else {
                    // handle this simple tag value
                    $et->HandleTag($tagTablePtr, $id, $val);
                    ++$count;
                }
            }
            if ($i !== null) array_pop($index);
        }
        return $count;
    }

    //------------------------------------------------------------------------------
    // Process BitTorrent file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference (with RAF set)
    // Returns: 1 on success, 0 if this wasn't a valid BitTorrent file
    public static function ProcessTorrent(ExifTool $et, array &$dirInfo)
    {
        $success = 0;
        $raf = $dirInfo['RAF'];
        $buff = '';
        $pos = 0;
        $dict = self::ReadBencode($et, $raf, $buff, $pos);
        $err = $raf->BencodeError;
        if ($err) $et->Warn("Bencode error: $err");
        if (is_array($dict) and !array_is_list($dict) and (!empty($dict['announce']) or !empty($dict['created by']) or !empty($dict['info']))) {
            $et->SetFileType();
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Torrent::Main');
            if (self::ExtractTags($et, $dict, $tagTablePtr)) $success = 1;
        }
        return $success;
    }
}
