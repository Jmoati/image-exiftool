<?php

//------------------------------------------------------------------------------
// File:         ID3.pm
//
// Description:  Read ID3 and Lyrics3 meta information
//
// Revisions:    09/12/2005 - P. Harvey Created
//               09/08/2020 - PH Added Lyrics3 support
//
// References:   1) http://www.id3.org/ (now https://id3.org)
//               2) http://www.mp3-tech.org/
//               3) http://www.fortunecity.com/underworld/sonic/3/id3tag.html
//               4) https://id3.org/Lyrics3
//
// PHP port of Image::ExifTool::ID3 1.65 (reading only). Tag tables and file lexicals
// (@audioFormats, %audioModule, %genre, %otherTable, %userTagName) are generated into
// tables/ID3.php and Vars\ID3. GetGenreID (used only by a QuickTime PrintConvInv) is
// write-only and not ported.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class ID3
{
    public static $VERSION = '1.65';

    //------------------------------------------------------------------------------
    // Make tag name for user-defined tag
    // Inputs: 0) User defined tag description
    // Returns: Tag name
    public static function MakeTagName($name)
    {
        $name = Perl::str($name);
        if (!empty(Vars\ID3::$userTagName[$name])) return Vars\ID3::$userTagName[$name];
        if (!preg_match('/[a-z]/', $name)) $name = ucfirst(strtolower($name));  // convert all uppercase to mixed case
        $name = preg_replace_callback('/([a-z])[_ ]([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name);
        return ExifTool::MakeTagName($name);
    }

    //------------------------------------------------------------------------------
    // Convert ID3v1 text to exiftool character set
    // Inputs: 0) ExifTool object ref, 1) text string
    // Returns: converted text
    public static function ConvertID3v1Text(ExifTool $et, $val)
    {
        return $et->Decode($val, $et->Options('CharsetID3'));
    }

    //------------------------------------------------------------------------------
    // Re-format time stamp in synchronized lyrics
    // Inputs: 0) synchronized lyrics entry (eg. "[84.030]Da do do do")
    // Returns: entry with formatted timestamp (eg. "[01:24.03]Da do do do")
    public static function ConvertTimeStamp($val)
    {
        $val = Perl::str($val);
        // do nothing if this isn't a time stamp (frame count doesn't contain a decimal)
        if (!preg_match('/^\[(\d+\.\d+)\]/', $val, $m)) return $val;
        $time = Perl::num($m[1]);
        // print hours only if more than 60 minutes
        $h = Perl::int($time / 3600);
        if ($h) {
            $time -= $h * 3600;
            $h = "$h:";
        } else {
            $h = '';
        }
        $m2 = Perl::int($time / 60);    // (Perl $m)
        $s = $time - $m2 * 60;
        $ss = Perl::sprintf('%05.2f', $s);
        if (Perl::num($ss) >= 60) {
            $ss = '00.00';
            if (++$m2 >= 60) {
                $m2 -= 60;
                $h = Perl::num($h) + 1;     // (Perl ++$h)
            }
        }
        return Perl::sprintf('[%s%.2d:%s]', $h, $m2, $ss) . substr($val, strlen($m[0]));
    }

    //------------------------------------------------------------------------------
    // Process ID3 synchronized lyrics/text
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    public static function ProcessSynText(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];

        $et->VerboseDir('SynLyrics', 0, strlen($dataPt));
        if (!(strlen($dataPt) > 6)) return null;

        [$enc, $lang, $timeCode, $type] = Perl::unpack('Ca3CC', $dataPt);
        $lang = strtolower($lang);
        if (!preg_match('/^[a-z]{3}$/', $lang) or $lang === 'eng') $lang = null;
        $pos = 6;   // (Perl pos($$dataPt))
        if ($enc == 1 or $enc == 2) {
            if (!preg_match('/\G(..)*?\0\0/s', $dataPt, $m, 0, $pos)) return null;
            $pos += strlen($m[0]);
            $termLen = 2;
            $pat = '/\G(?:..)*?\0\0(....)/s';
        } else {
            if (!preg_match('/\0/', $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) return null;
            $pos = $m[0][1] + 1;
            $termLen = 1;
            $pat = '/\0(....)/s';
        }
        $desc = substr($dataPt, 6, $pos - 6 - $termLen);
        $desc = self::DecodeString($et, $desc, $enc);

        $tagInfo = $et->GetTagInfo($tagTablePtr, 'desc');
        if ($lang) $tagInfo = ExifTool::GetLangInfo($tagInfo, $lang);
        $et->HandleTag($tagTablePtr, 'type', $type);
        $et->HandleTag($tagTablePtr, 'desc', $desc, TagInfo: $tagInfo);
        $tagInfo = $et->GetTagInfo($tagTablePtr, 'text');
        if ($lang) $tagInfo = ExifTool::GetLangInfo($tagInfo, $lang);

        for (;;) {
            $start = $pos;  // (Perl my $pos)
            if (!preg_match($pat, $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) break;
            $pos = $m[0][1] + strlen($m[0][0]);
            $time = Perl::unpack('N', $m[1][0])[0];
            $text = substr($dataPt, $start, $pos - $start - 4 - $termLen);
            $text = self::DecodeString($et, $text, $enc);
            if ($timeCode == 2) { // time in ms
                $timeStr = Perl::sprintf('%.3f', $time / 1000);
            } else {              // time in MPEG frames
                $timeStr = Perl::sprintf('%.4d', $time);
                if ($timeCode != 1) $timeStr .= '?';
            }
            $et->HandleTag($tagTablePtr, 'text', "[$timeStr]$text", TagInfo: $tagInfo);
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Process ID3 PRIV data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    public static function ProcessPrivate(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $et->VerboseDir('PRIV', 0, strlen($dataPt));
        if (preg_match('/^(.*?)\0/s', $dataPt, $m)) {
            $tag = $m[1];
            $start = strlen($tag) + 1;
        } else {
            $tag = '';
            $start = 0;
        }
        if (!isset($tagTablePtr[$tag])) {
            $tag = Perl::tr($tag, '/ ', '_', 'd')[0];  // translate '/' to '_' and remove spaces
            if (!preg_match('/^[-\w]{1,24}$/', $tag)) $tag = 'private';
            if (!isset($tagTablePtr[$tag])) {
                ExifTool::AddTagToTable($tagTablePtr, $tag,
                    ['Name' => ucfirst($tag), 'Binary' => 1]);
            }
        }
        $key = $et->HandleTag($tagTablePtr, $tag, null,
            Size  : strlen($dataPt) - $start,
            Start : $start,
            DataPt : $dataPt,
        );
        // set group1 name
        if ($key) $et->SetGroup($key, $et->ID3_Ver);
        return null;
    }

    //------------------------------------------------------------------------------
    // Print ID3v2 Genre
    // Inputs: TCON or TCO frame data
    // Returns: Content type with decoded genre numbers
    public static function PrintGenre($val)
    {
        $val = Perl::str($val);
        $genre = &Vars\ID3::$genre;
        // make sure that %genre has an entry for all numbers we are interested in
        // (genre numbers are in brackets for ID3v2.2 and v2.3)
        preg_match_all('/\((\d+)\)/', $val, $mm);
        foreach ($mm[1] as $g) {
            if (empty($genre[$g])) $genre[$g] = "Unknown ($g)";
        }
        // (genre numbers are separated by nulls in ID3v2.4,
        //  but nulls are converted to '/' by DecodeString())
        preg_match_all('/(?:^|\/)(\d+)(\/|$)/', $val, $mm);
        foreach ($mm[1] as $g) {
            if (empty($genre[$g])) $genre[$g] = "Unknown ($g)";
        }
        $val = preg_replace_callback('/\((\d+)\)/', static fn ($m) => '(' . ($genre[$m[1]] ?? '') . ')', $val);
        $val = preg_replace_callback('/(^|\/)(\d+)(?=\/|$)/', static fn ($m) => $m[1] . ($genre[$m[2]] ?? ''), $val);
        $val = preg_replace('/^\(([^)]+)\)\1?$/', '$1', $val, 1); // clean up by removing brackets and duplicates
        return $val;
    }

    //------------------------------------------------------------------------------
    // Decode ID3 string
    // Inputs: 0) ExifTool object reference
    //         1) string beginning with encoding byte unless specified as argument
    //         2) optional encoding (0=ISO-8859-1, 1=UTF-16 BOM, 2=UTF-16BE, 3=UTF-8)
    // Returns: Decoded string in scalar context, or list of strings in list context
    //          (PHP: the scalar-context result; see DecodeStringList for the list)
    public static function DecodeString(ExifTool $et, $val, $enc = null)
    {
        $vals = self::DecodeStringList($et, $val, $enc);
        return is_array($vals) ? implode('/', $vals) : $vals;
    }

    //------------------------------------------------------------------------------
    // (PHP) DecodeString in list context: list of decoded strings (or a single string for
    // an empty value or an unknown encoding, as Perl returns before building the list)
    public static function DecodeStringList(ExifTool $et, $val, $enc = null)
    {
        $val = Perl::str($val);
        if (!strlen($val)) return [''];
        if ($enc === null) {
            $enc = ord($val[0]);
            $val = substr($val, 1); // remove encoding byte
        }
        $vals = [];
        if ($enc == 0 or $enc == 3) { // ISO 8859-1 or UTF-8
            $val = preg_replace('/\0+$/', '', $val, 1);   // remove any null padding
            // (must split before converting because conversion routines truncate at null)
            $vals = Perl::split('/\0/', $val);
            foreach ($vals as &$v) {
                $v = $et->Decode($v, $enc ? 'UTF8' : 'Latin');
            }
            unset($v);
        } elseif ($enc == 1 or $enc == 2) {  // UTF-16 with BOM, or UTF-16BE
            $bom = "\xfe\xff";
            $order = ["\xfe\xff" => 'MM', "\xff\xfe" => 'II'];
            for (;;) {
                // split string at null terminators on word boundaries
                if (preg_match('/((..)*?)\0\0/s', $val, $m, PREG_OFFSET_CAPTURE)) {
                    $v = $m[1][0];
                    $val = substr($val, 0, $m[0][1]) . substr($val, $m[0][1] + strlen($m[0][0]));
                } else {
                    if (!(strlen($val) > 1)) break;
                    $v = $val;
                    $val = '';
                }
                if (preg_match('/^(\xfe\xff|\xff\xfe)/', $v, $m)) {
                    $v = substr($v, 2);
                    $bom = $m[1];
                }
                $vals[] = $et->Decode($v, 'UTF16', $order[$bom]);
            }
        } else {
            $val = preg_replace('/\0+$/', '', $val, 1);
            return ["<Unknown encoding $enc> $val"];
        }
        return $vals;
    }

    //------------------------------------------------------------------------------
    // Convert sync-safe integer to a number we can use
    // Inputs: 0) int32u sync-safe value
    // Returns: actual number or undef on invalid value
    public static function UnSyncSafe($val)
    {
        $val = Perl::int($val);
        if ($val & 0x80808080) return null;
        return ($val & 0x0000007f) |
              (($val & 0x00007f00) >> 1) |
              (($val & 0x007f0000) >> 2) |
              (($val & 0x7f000000) >> 3);
    }

    //------------------------------------------------------------------------------
    // Process ID3v2 information
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    public static function ProcessID3v2(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt  = $dirInfo['DataPt'];
        $offset  = $dirInfo['DirStart'];
        $size    = $dirInfo['DirLen'];
        $vers    = $dirInfo['Version'];
        $verbose = $et->Options('Verbose');
        $len = 0;   // frame data length
        $otherTable = null;

        $et->VerboseDir($tagTablePtr['GROUPS'][1], 0, $size);
        $et->VerboseDump($dataPt, Len: $size, Start: $offset);

        for (;; $offset += $len) {
            $id = $flags = $hi = null;
            if ($vers < 0x0300) {
                // version 2.2 frame header is 6 bytes
                if ($offset + 6 > $size) break;
                [$id, $hi, $len] = Perl::unpack("x{$offset}a3Cn", $dataPt);
                if ($id === "\0\0\0") break;
                $len += $hi << 16;
                $offset += 6;
            } else {
                // version 2.3/2.4 frame header is 10 bytes
                if ($offset + 10 > $size) break;
                [$id, $len, $flags] = Perl::unpack("x{$offset}a4Nn", $dataPt);
                if ($id === "\0\0\0\0") break;
                $offset += 10;
                // length is a "sync-safe" integer by the ID3v2.4 specification, but
                // reportedly some versions of iTunes write this as a normal integer
                // (ref http://www.id3.org/iTunes)
                while ($vers >= 0x0400 and $len > 0x7f and !($len & 0x80808080)) {
                    $oldLen = $len;
                    $len = self::UnSyncSafe($len);
                    if ($len === null or $offset + $len + 10 > $size) {
                        if ($offset + $len == $size) {
                            $et->Warn('Missing ID3 terminating frame', 1);
                        } else {
                            $et->Warn('Invalid ID3 frame size');
                        }
                        break;
                    }
                    // check next ID to see if it makes sense
                    $nextID = substr($dataPt, $offset + $len, 4);
                    if (isset($tagTablePtr[$nextID])) break;
                    // try again with the incorrect length word (patch for iTunes bug)
                    if ($offset + $oldLen + 10 > $size) break;
                    $nextID = substr($dataPt, $offset + $len, 4);
                    if (isset($tagTablePtr[$nextID])) $len = $oldLen;
                    break; // yes, "while" was really a "goto" in disguise
                }
            }
            if ($offset + $len > $size) break;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $id);
            if (!$tagInfo) {
                if (!$otherTable and isset(Vars\ID3::$otherTable[$tagTablePtr['TABLE_NAME']])) {
                    $otherTable = ExifTool::GetTagTable(Vars\ID3::$otherTable[$tagTablePtr['TABLE_NAME']]);
                }
                if ($otherTable) $tagInfo = $et->GetTagInfo($otherTable, $id);
                if ($tagInfo) {
                    $et->Warn("Frame '{$id}' is not valid for this ID3 version", 1);
                } else {
                    if (!($verbose or $et->Options('Unknown'))) continue;
                    $id = preg_replace('/[^-A-Za-z0-9_]/', '', $id);
                    if (!strlen($id)) $id = 'unknown';
                    if (!isset($tagTablePtr[$id])) {
                        $tagInfo = ['Name' => "ID3_$id", 'Binary' => 1];
                        $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $id, $tagInfo);
                    }
                }
            }
            // decode v2.3 and v2.4 flags
            $flagsH = $extra = [];  // (Perl %flags, %extra)
            if ($flags) {
                if ($vers < 0x0400) {
                    // version 2.3 flags
                    if ($flags & 0x80) $flagsH['Compress'] = 1;
                    if ($flags & 0x40) $flagsH['Encrypt']  = 1;
                    if ($flags & 0x20) $flagsH['GroupID']  = 1;
                } else {
                    // version 2.4 flags
                    if ($flags & 0x40) $flagsH['GroupID']  = 1;
                    if ($flags & 0x08) $flagsH['Compress'] = 1;
                    if ($flags & 0x04) $flagsH['Encrypt']  = 1;
                    if ($flags & 0x02) $flagsH['Unsync']   = 1;
                    if ($flags & 0x01) $flagsH['DataLen']  = 1;
                }
            }
            if (!empty($flagsH['Encrypt'])) {
                $et->Warn('Encrypted frames currently not supported');
                continue;
            }
            // extract the value
            $val = substr($dataPt, $offset, $len);

            // reverse the unsynchronization
            if (!empty($flagsH['Unsync'])) $val = str_replace("\xff\x00", "\xff", $val);

            // read grouping identity
            if (!empty($flagsH['GroupID'])) {
                if (!(strlen($val) >= 1)) { $et->Warn("Short $id frame"); continue; }
                $val = substr($val, 1); // (ignore it)
            }
            // read data length
            $dataLen = null;
            if (!empty($flagsH['DataLen']) or !empty($flagsH['Compress'])) {
                if (!(strlen($val) >= 4)) { $et->Warn("Short $id frame"); continue; }
                $dataLen = Perl::unpack('N', $val)[0];   // save the data length word
                $val = substr($val, 4);
            }
            // uncompress data
            if (!empty($flagsH['Compress'])) {
                if (function_exists('inflate_init')) {
                    // (Perl: Compress::Zlib::inflateInit() and inflate(), zlib format)
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    $buff = $inflate ? @inflate_add($inflate, $val, ZLIB_SYNC_FLUSH) : false;
                    if ($inflate and $buff !== false and inflate_get_status($inflate) == ZLIB_STREAM_END) {
                        $val = $buff;
                    } else {
                        $et->Warn("Error inflating $id frame");
                        continue;
                    }
                } else {
                    $et->Warn('Install Compress::Zlib to decode compressed frames');
                    continue;
                }
            }
            // validate data length
            if ($dataLen !== null) {
                $dataLen = self::UnSyncSafe($dataLen);
                if ($dataLen === null) { $et->Warn("Invalid length for $id frame"); continue; }
                if ($dataLen != strlen($val)) { $et->Warn("Wrong length for $id frame"); continue; }
            }
            if (!$tagInfo) {
                if (!$verbose) continue;
                if ($flagsH) {
                    $k = array_keys($flagsH);
                    sort($k, SORT_STRING);
                    $extra['Extra'] = ', Flags=' . implode(',', $k);
                }
                $et->VerboseInfo($id, $tagInfo,
                    ...$extra,  // (PHP: unpacking must come before the named arguments)
                    Table   : $tagTablePtr,
                    Value   : $val,
                    DataPt  : $dataPt,
                    DataPos : $dirInfo['DataPos'] ?? null,
                    Size    : $len,
                    Start   : $offset,
                );
                continue;
            }
//
// decode data in this frame (it is bad form to hard-code these, but the ID3 frame formats
// are so variable that it would be more work to define format types for each of them)
//
            $lang = null;
            $valLen = strlen($val);     // actual value length (after decompression, etc)
            if (preg_match('/^(TXX|TXXX)$/', $id)) {
                // two encoded strings separated by a null
                $vals = self::DecodeStringList($et, $val);
                foreach ([0, 1] as $_) { if (!isset($vals[$_])) $vals[$_] = ''; }
                if (strlen($vals[0])) {
                    $id .= "_$vals[0]";
                    $tagInfo = $tagTablePtr[$id] ?: ExifTool::AddTagToTable($tagTablePtr, $id, self::MakeTagName($vals[0]));
                }
                $val = $vals[1];
            } elseif (preg_match('/^T/', $id) or preg_match('/^(IPL|IPLS|GP1|MVI|MVN)$/', $id)) {
                $val = self::DecodeString($et, $val);
            } elseif (preg_match('/^(WXX|WXXX)$/', $id)) {
                // one encoded string and one Latin string separated by a null
                $enc = Perl::unpack('C', $val)[0] ?? null;
                $tag = $url = null;
                if ($enc == 1 or $enc == 2) {
                    if (preg_match('/^(.(?:..)*?)\0\0(.*)/s', $val, $m)) [$tag, $url] = [$m[1], $m[2]];
                } else {
                    if (preg_match('/^(..*?)\0(.*)/s', $val, $m)) [$tag, $url] = [$m[1], $m[2]];
                }
                if (!($tag !== null and $url !== null)) {
                    $et->Warn("Invalid $id frame value");
                    continue;
                }
                $tag = self::DecodeString($et, $tag);
                if (strlen($tag)) {
                    $id .= "_$tag";
                    if (!preg_match('/url/i', $tag)) $tag .= '_URL';
                    $tagInfo = $tagTablePtr[$id] ?: ExifTool::AddTagToTable($tagTablePtr, $id, self::MakeTagName($tag));
                }
                $url = preg_replace('/\0.*/s', '', $url, 1);
                $val = $et->Decode($url, 'Latin');
            } elseif (preg_match('/^W/', $id)) {
                $val = preg_replace('/\0.*/s', '', $val, 1);  // truncate at null
            } elseif (preg_match('/^(COM|COMM|ULT|USLT)$/', $id)) {
                if (!($valLen > 4)) { $et->Warn("Short $id frame"); continue; }
                $lang = substr($val, 1, 3);
                $vals = self::DecodeStringList($et, substr($val, 4), ExifTool::Get8u($val, 0));
                foreach ([0, 1] as $_) { if (!isset($vals[$_])) $vals[$_] = ''; }
                $val = strlen($vals[0]) ? "($vals[0]) $vals[1]" : $vals[1];
            } elseif ($id === 'USER') {
                if (!($valLen > 4)) { $et->Warn("Short $id frame"); continue; }
                $lang = substr($val, 1, 3);
                $val = self::DecodeString($et, substr($val, 4), ExifTool::Get8u($val, 0));
            } elseif (preg_match('/^(CNT|PCNT)$/', $id)) {
                if (!($valLen >= 4)) { $et->Warn("Short $id frame"); continue; }
                $xtra = Perl::unpack('NC*', $val);
                $cnt = array_shift($xtra);
                foreach ($xtra as $_) $cnt = ($cnt << 8) + $_;
                $val = $cnt;
            } elseif (preg_match('/^(PIC|APIC)$/', $id)) {
                if (!($valLen >= 4)) { $et->Warn("Short $id frame"); continue; }
                $enc = Perl::unpack('C', $val)[0];
                if ($enc == 1 or $enc == 2) {
                    $hdr = ($id === 'PIC') ? '.(...)(.)((?:..)*?)\0\0' : '.(.*?)\0(.)((?:..)*?)\0\0';
                } else {
                    $hdr = ($id === 'PIC') ? '.(...)(.)(.*?)\0'        : '.(.*?)\0(.)(.*?)\0';
                }
                // remove header (encoding, image format or MIME type, picture type, description)
                if (!preg_match("/^$hdr/s", $val, $m)) { $et->Warn("Invalid $id frame"); continue; }
                $val = substr($val, strlen($m[0]));
                $attrs = [$m[1], ord($m[2]), ...self::DecodeStringList($et, $m[3], $enc)];
                $i = 1;
                foreach ($attrs as $attr) {
                    // must store descriptions even if they are empty to maintain
                    // sync between copy numbers when multiple images
                    $et->HandleTag($tagTablePtr, "$id-$i", $attr);
                    ++$i;
                }
            } elseif ($id === 'POP' or $id === 'POPM') {
                // _email, 00, rating(1), counter(4-N)
                $email = $dat = null;
                if (preg_match('/^([^\0]*)\0(.*)$/s', $val, $m)) [$email, $dat] = [$m[1], $m[2]];
                if (!($dat !== null and strlen($dat))) {
                    $et->Warn("Invalid $id frame");
                    continue;
                }
                $xtra = Perl::unpack('C*', $dat);
                $rating = array_shift($xtra);
                $cnt = 0;
                foreach ($xtra as $_) $cnt = ($cnt << 8) + $_;
                $val = "$email $rating $cnt";
            } elseif ($id === 'OWNE') {
                // enc(1), _price, 00, _date(8), Seller
                $strs = self::DecodeStringList($et, $val);
                if (!empty($strs[1])) $strs[1] = preg_replace('/^(\d{4})(\d{2})(\d{2})/s', '$1:$2:$3 ', $strs[1], 1); // format date
                $val = implode(' ', $strs);
            } elseif ($id === 'RVA' or $id === 'RVAD') {
                $dat = Perl::unpack('C*', $val);
                $flag = array_shift($dat);
                $bits = array_shift($dat);
                if (!$bits) { $et->Warn("Short $id frame"); continue; }
                $bytes = Perl::int(($bits + 7) / 8);
                $parse = [['Right',0,2,0x01],['Left',1,3,0x02],['Back-right',4,6,0x04],
                          ['Back-left',5,7,0x08],['Center',8,9,0x10],['Bass',10,11,0x20]];
                $val = '';
                while ($parse) {
                    $elem = array_shift($parse);
                    $j = $elem[2] * $bytes;
                    if (count($dat) < $j + $bytes) break;
                    $i = $elem[1] * $bytes;
                    if ($val) $val .= ', ';
                    for ($rel = 0, $pk = 0, $b = 0; $b < $bytes; ++$b) {
                        $rel = $rel * 256 + $dat[$i + $b];
                        $pk  = $pk  * 256 + $dat[$j + $b]; // (peak - not used in printout)
                    }
                    if (!($flag & $elem[3])) $rel = -$rel;
                    $val .= Perl::sprintf('%+.1f%% %s', 100 * $rel / ((1 << $bits) - 1), $elem[0]);
                }
            } elseif ($id === 'RVA2') {
                // (Perl: this block declares its own "my $id", here $id2)
                [$pos, $id2] = preg_match('/^([^\0]*)\0/s', $val, $m) ? [strlen($m[1]) + 1, $m[1]] : [1, ''];
                $vals = [];
                while ($pos + 4 <= $valLen) {
                    $type = ExifTool::Get8u($val, $pos);
                    $str = ([
                        0 => 'Other',
                        1 => 'Master',
                        2 => 'Front-right',
                        3 => 'Front-left',
                        4 => 'Back-right',
                        5 => 'Back-left',
                        6 => 'Front-centre',
                        7 => 'Back-centre',
                        8 => 'Subwoofer',
                    ][$type] ?? null) ?: "Unknown($type)";
                    $db = ExifTool::Get16s($val, $pos + 1) / 512;
                    // convert dB to percent as displayed by iTunes 10.5
                    // (not sure why I need to divide by 20 instead of 10 as expected - PH)
                    $vals[] = Perl::sprintf('%+.1f%% %s', 10 ** ($db / 20 + 2) - 100, $str);
                    // step to next channel (ignoring peak volume)
                    $pos += 4 + Perl::int((ExifTool::Get8u($val, $pos + 3) + 7) / 8);
                }
                $val = implode(', ', $vals);
                if ($id2) $val .= " ($id2)";
            } elseif ($id === 'PRIV') {
                // save version number to set group1 name for tag later
                $et->ID3_Ver = $tagTablePtr['GROUPS'][1];
                $et->HandleTag($tagTablePtr, $id, $val);
                continue;
            } elseif ($tagInfo->Format or $tagInfo->SubDirectory) {
                $et->HandleTag($tagTablePtr, $id, null, DataPt: $val);
                continue;
            } elseif ($id === 'GRP1' or $id === 'MVNM' or $id === 'MVIN') {
                $val = preg_replace('/(^\0+|\0+$)/', '', $val);   // (PH guess)
            } elseif (!$tagInfo->Binary) {
                $et->Warn("Don't know how to handle $id frame");
                continue;
            }
            if ($lang and preg_match('/^[a-z]{3}$/i', $lang) and $lang !== 'eng') {
                $tagInfo = ExifTool::GetLangInfo($tagInfo, strtolower($lang));
            }
            if ($flagsH) {
                $k = array_keys($flagsH);
                sort($k, SORT_STRING);
                $extra['Extra'] = ', Flags=' . implode(',', $k);
            }
            $et->HandleTag($tagTablePtr, $id, $val,
                ...$extra,  // (PHP: unpacking must come before the named arguments)
                TagInfo : $tagInfo,
                DataPt  : $dataPt,
                DataPos : $dirInfo['DataPos'] ?? null,
                Size    : $len,
                Start   : $offset,
            );
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Extract ID3 information from an audio file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this file didn't contain ID3 information
    // - also processes audio data if any ID3 information was found
    // - sets ExifTool DoneID3 to 1 when called, or to trailer size if an ID3v1 trailer exists
    public static function ProcessID3(ExifTool $et, array &$dirInfo)
    {
        if ($et->DoneID3) return 0;  // avoid infinite recursion
        $et->DoneID3 = 1;

        // allow this to be called with either RAF or DataPt
        $raf = ($dirInfo['RAF'] ?? null) ?: new RandomAccess(Perl::str($dirInfo['DataPt'] ?? ''));
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $buff = $hBuff = $tBuff = $eBuff = $tagTablePtr = null;
        $id3Header = $id3Trailer = [];
        $rtnVal = 0;
        $hdrEnd = 0;
        $id3Len = 0;

        // read first 3 bytes of file
        $raf->Seek(0, 0);
        if (!($raf->Read($buff, 3) == 3)) return 0;
//
// identify ID3v2 header
//
        while (str_starts_with($buff, 'ID3')) {
            $rtnVal = 1;
            if (!($raf->Read($hBuff, 7) == 7)) { $et->Warn('Short ID3 header'); break; }
            [$vers, $flags, $size] = Perl::unpack('nCN', $hBuff);
            $size = self::UnSyncSafe($size);
            if ($size === null) { $et->Warn('Invalid ID3 header'); break; }
            $verStr = Perl::sprintf('2.%d.%d', $vers >> 8, $vers & 0xff);
            if ($vers >= 0x0500) {
                $et->Warn("Unsupported ID3 version: $verStr");
                break;
            }
            if (!($raf->Read($hBuff, $size) == $size)) {
                $et->Warn('Truncated ID3 data');
                break;
            }
            // this flag only indicates use of unsynchronized frames in ID3v2.4
            if ($flags & 0x80 and $vers < 0x0400) {
                // reverse the unsynchronization
                $hBuff = str_replace("\xff\x00", "\xff", $hBuff);
            }
            $pos = 10;
            if ($flags & 0x40) {
                // skip the extended header
                if (!($size >= 4)) { $et->Warn('Bad ID3 extended header'); break; }
                $len = self::UnSyncSafe(Perl::unpack('N', $hBuff)[0]);
                if ($len > strlen($hBuff)) {
                    $et->Warn('Truncated ID3 extended header');
                    break;
                }
                $hBuff = substr($hBuff, (int) $len);
                $pos += $len;
            }
            if ($flags & 0x10) {
                // ignore v2.4 footer (10 bytes long)
                $raf->Seek(10, 1);
            }
            $id3Header = [
                'DataPt'   => $hBuff,
                'DataPos'  => $dataPos + $pos,
                'DirStart' => 0,
                'DirLen'   => strlen($hBuff),
                'Version'  => $vers,
                'DirName'  => "ID3v$verStr",
            ];
            $id3Len += strlen($hBuff) + 10;
            if ($vers >= 0x0400) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ID3::v2_4');
            } elseif ($vers >= 0x0300) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ID3::v2_3');
            } else {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ID3::v2_2');
            }
            $hdrEnd = $raf->Tell();
            break;
        }
//
// read ID3v1 trailer if it exists
//
        $trailSize = 0;
        if ($raf->Seek(-128, 2) and $raf->Read($tBuff, 128) == 128 and str_starts_with($tBuff, 'TAG')) {
            $trailSize = 128;
            $id3Trailer = [
                'DataPt'   => $tBuff,
                'DataPos'  => $dataPos + $raf->Tell() - 128,
                'DirStart' => 0,
                'DirLen'   => strlen($tBuff),
            ];
            $id3Len += strlen($tBuff);
            $rtnVal = 1;
            // load 'Enhanced TAG' information if available
            $eSize = 227;   // size of ID3 Enhanced TAG info
            if ($raf->Seek(-$trailSize - $eSize, 2) and $raf->Read($eBuff, $eSize) == $eSize and str_starts_with($eBuff, 'TAG')) {
                $id3Trailer['EnhancedTAG'] = $eBuff;
                $trailSize += $eSize;
            }
            $et->DoneID3 = $trailSize; // save trailer size
        }
//
// read Lyrics3 trailer if it exists
//
        if ($raf->Seek(-$trailSize - 15, 2) and $raf->Read($buff, 15) == 15 and preg_match('/^(.{6})LYRICS(END|200)$/', $buff, $m)) {
            $ver = $m[2];   // Lyrics3 version ('END' for version 1)
            $len = ($ver === 'END') ? 5100 : Perl::num($m[1]) + 15; // max Lyrics3 length
            $tbl = ExifTool::GetTagTable('Image::ExifTool::ID3::Lyrics3');
            if ($len > $raf->Tell()) $len = $raf->Tell();
            if ($raf->Seek(-$len, 1) and $raf->Read($buff, $len) == $len and ($p = strpos($buff, 'LYRICSBEGIN')) !== false) {
                $pos = $p + 11;
                $gpos = $pos;   // (Perl pos($buff))
                $et->DoneID3 = $trailSize + $len - $pos + 11;  // update trailer length
                $oldIndent = $et->INDENT;
                $et->INDENT .= '| ';
                if ($et->Options('Verbose')) {
                    $et->VPrint(0, "Lyrics3:\n");
                    $et->VerboseDir('Lyrics3', null, $len);
                    if ($pos > 11) {
                        $buff = substr($buff, $pos - 11);
                        $pos = 11;
                        $gpos = 0;  // (assigning $buff resets its pos())
                    }
                    $et->VerboseDump($buff);
                }
                if ($ver === 'END') {
                    // Lyrics3 v1.00
                    $val = substr($buff, $pos, $len - $pos - 9);
                    $et->HandleTag($tbl, 'LYR', $et->Decode($val, 'Latin'));
                } else {
                    // Lyrics3 v2.00
                    for (;;) {
                        // (note: the size field is 5 digits,, not 6 as per the documentation)
                        if (!preg_match('/\G(.{3})(\d{5})/', $buff, $m, 0, $gpos)) break;
                        [$tag, $size] = [$m[1], Perl::num($m[2])];
                        $pos += 8;
                        if ($pos + $size > strlen($buff)) break;
                        if (!isset($tbl[$tag])) {
                            ExifTool::AddTagToTable($tbl, $tag, ['Name' => ExifTool::MakeTagName("Lyrics3_$tag")]);
                        }
                        $et->HandleTag($tbl, $tag, $et->Decode(substr($buff, $pos, $size), 'Latin'));
                        $pos += $size;
                        $gpos = $pos;
                    }
                    if ($pos != strlen($buff) - 15) $et->Warn('Malformed Lyrics3 v2.00 block');
                }
                $et->INDENT = $oldIndent;
            } else {
                $et->Warn('Error reading Lyrics3 trailer');
            }
        }
//
// process the information
//
        if ($rtnVal) {
            // first process audio data if it exists
            if (!empty($dirInfo['RAF'])) {
                $oldType = $et->FILE_TYPE;  // save file type
                // check current file type first
                $types = preg_grep('/^' . $oldType . '$/', Vars\ID3::$audioFormats);
                array_push($types, ...preg_grep('/^' . $oldType . '$/', Vars\ID3::$audioFormats, PREG_GREP_INVERT));
                foreach ($types as $type) {
                    // seek to end of ID3 header
                    $raf->Seek($hdrEnd, 0);
                    // set type for this file if we are successful
                    $et->FILE_TYPE = $type;
                    $module = (Vars\ID3::$audioModule[$type] ?? null) ?: $type;
                    if (!ExifTool::RequireModule("Image::ExifTool::$module")) continue;
                    $func = ["Image\\ExifTool\\$module", "Process$type"];
                    // process the file
                    if ($func($et, $dirInfo)) break;
                }
                $et->FILE_TYPE = $oldType;      // restore original file type
            }
            // set file type to MP3 if we didn't find audio data
            $et->SetFileType('MP3');
            // record the size of the ID3 metadata
            $et->FoundTag('ID3Size', $id3Len);
            // process ID3v2 header if it exists
            if ($id3Header) {
                $et->VPrint(0, "{$id3Header['DirName']}:\n");
                $et->ProcessDirectory($id3Header, $tagTablePtr);
            }
            // process ID3v1 trailer if it exists
            if ($id3Trailer) {
                $et->VPrint(0, "ID3v1:\n");
                ExifTool::SetByteOrder('MM');
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ID3::v1');
                $et->ProcessDirectory($id3Trailer, $tagTablePtr);
                // process "Enhanced TAG" information if available
                if (!empty($id3Trailer['EnhancedTAG'])) {
                    $et->VPrint(0, "ID3v1 Enhanced TAG:\n");
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ID3::v1_Enh');
                    $id3Trailer['DataPt'] = $id3Trailer['EnhancedTAG'];
                    $id3Trailer['DataPos'] -= 227; // (227 = length of Enhanced TAG block)
                    $id3Trailer['DirLen'] = 227;
                    $et->ProcessDirectory($id3Trailer, $tagTablePtr);
                }
            }
        }
        // return file pointer to start of file to read audio data if necessary
        $raf->Seek(0, 0);
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process ID3 directory
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) dummy tag table ref
    public static function ProcessID3Dir(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $et->VerboseDir('ID3', null, strlen(Perl::str($dirInfo['DataPt'] ?? '')));
        return self::ProcessID3($et, $dirInfo);
    }

    //------------------------------------------------------------------------------
    // Process ID3 General Encapsulated Object
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessGEOB(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $et->VerboseDir('GEOB', null, strlen($dirInfo['DataPt']));
        $dataPt = $dirInfo['DataPt'];
        $len = strlen($dataPt);
        if (!($len >= 4)) { $et->Warn('Short GEOB frame'); return 0; }
        $enc = Perl::unpack('C', $dataPt)[0];
        if ($enc == 1 or $enc == 2) {
            $hdr = '.(.*?)\0((?:..)*?)\0\0((?:..)*?)\0\0';
        } else {
            $hdr = '.(.*?)\0(.*?)\0(.*?)\0';
        }
        // remove header (encoding, mime, filename, description)
        if (!preg_match("/^$hdr/s", $dataPt, $m)) { $et->Warn('Invalid GEOB frame'); return 0; }
        $dataPt = substr($dataPt, strlen($m[0]));
        [$mime, $file, $desc] = [$m[1], ...self::DecodeStringList($et, $m[2], $enc),
            ...self::DecodeStringList($et, $m[3], $enc)] + [null, null, null];
        if (strlen($mime)) $et->HandleTag($tagTablePtr, 'GEOB-Mime', $mime);
        if (strlen(Perl::str($file))) $et->HandleTag($tagTablePtr, 'GEOB-File', $file);
        if (strlen(Perl::str($desc))) $et->HandleTag($tagTablePtr, 'GEOB-Desc', $desc);
        if (isset($tagTablePtr[$mime])) {
            $et->HandleTag($tagTablePtr, $mime, null,
                DataPt : $dataPt,
                Start  : 0,
                Size   : strlen($dataPt),
            );
        } else {
            $et->HandleTag($tagTablePtr, 'GEOB-Data', new ScalarRef($dataPt));
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract ID3 information from an MP3 audio file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid MP3 file
    public static function ProcessMP3(ExifTool $et, array &$dirInfo)
    {
        $rtnVal = 0;

        // must first check for leading/trailing ID3 information
        // (and process the rest of the file if found)
        if (!$et->DoneID3) {
            $rtnVal = self::ProcessID3($et, $dirInfo);
        }

        // check for MPEG A/V data if not already processed above
        if (!$rtnVal) {
            $raf = $dirInfo['RAF'];
            $buff = null;
//
// extract information from first audio/video frame headers
// (if found in the first $scanLen bytes)
//
            // scan further into a file that should be an MP3
            $scanLen = ($et->FILE_EXT and $et->FILE_EXT === 'MP3') ? 8192 : 256;
            if ($raf->Read($buff, $scanLen)) {
                ExifTool::RequireModule('Image::ExifTool::MPEG');
                if (preg_match('/\0\0\x01(\xb3|\xc0)/', $buff)) {
                    // look for A/V headers in first 64kB
                    $buf2 = null;
                    if ($raf->Read($buf2, 0x10000 - $scanLen)) $buff .= $buf2;
                    if (MPEG::ParseMPEGAudioVideo($et, $buff)) $rtnVal = 1;
                } else {
                    // look for audio frame sync in first $scanLen bytes
                    // (set MP3 flag to 1 so this will fail unless layer 3 audio)
                    $ext = $et->FILE_EXT ?: '';
                    $mp3 = ($ext === 'MUS') ? 0 : 1;   // MUS files are MP2
                    if (MPEG::ParseMPEGAudio($et, $buff, $mp3)) $rtnVal = 1;
                }
            }
        }

        // check for an APE trailer if this was a valid A/V file and we haven't already done it
        if ($rtnVal and !$et->DoneAPE) {
            ExifTool::RequireModule('Image::ExifTool::APE');
            APE::ProcessAPE($et, $dirInfo);
        }
        return $rtnVal;
    }
}
