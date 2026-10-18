<?php

//------------------------------------------------------------------------------
// File:         WriteIPTC.pl
//
// Description:  Write IPTC meta information
//
// Revisions:    12/15/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::IPTC), mixed into class Image\ExifTool\IPTC.
// ConvertPictureNumber (also in WriteIPTC.pl) is in src/IPTC.php with %manufacturer.
//------------------------------------------------------------------------------

namespace Image\ExifTool\IPTC;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\Photoshop;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;
use Image\ExifTool\XMP;

trait WriteIPTC
{
    /** my %mandatory: mandatory IPTC tags for each record */
    private const array MANDATORY = [
        1 => [
            0 => 4,     // EnvelopeRecordVersion
        ],
        2 => [
            0 => 4,     // ApplicationRecordVersion
        ],
        3 => [
            0 => 4,     // NewsPhotoVersion
        ],
    ];

    /** my %iptcCharsetInv */
    private const array IPTC_CHARSET_INV = ['UTF8' => "\x1b%G", 'UTF-8' => "\x1b%G"];

    // ISO 2022 Character Coding Notes
    // -------------------------------
    // Character set designation: (0x1b I F, or 0x1b I I F)
    // Initial character 0x1b (ESC)
    // Intermediate character I:
    //   0x28 ('(') - G0, 94 chars
    //   0x29 (')') - G1, 94 chars
    //   0x2a ('*') - G2, 94 chars
    //   0x2b ('+') - G3, 94 chars
    //   0x2c (',') - G1, 96 chars
    //   0x2d ('-') - G2, 96 chars
    //   0x2e ('.') - G3, 96 chars
    //   0x24 I ('$I') - multiple byte graphic sets (I from above)
    //   I 0x20 ('I ') - dynamically redefinable character sets
    // Final character:
    //   0x30 - 0x3f = private character set
    //   0x40 - 0x7f = standardized character set
    // Character set invocation:
    //   G0 : SI = 0x15
    //   G1 : SO = 0x14,             LS1R = 0x1b 0x7e ('~')
    //   G2 : LS2 = 0x1b 0x6e ('n'), LS2R = 0x1b 0x7d ('}')
    //   G3 : LS3 = 0x1b 0x6f ('o'), LS3R = 0x1b 0x7c ('|')
    //   (the locking shift "R" codes shift into 0x80-0xff space)
    // Single character invocation:
    //   G2 : SS2 = 0x1b 0x8e (or 0x4e in 7-bit)
    //   G3 : SS3 = 0x1b 0x8f (or 0x4f in 7-bit)
    // Control chars (designated and invoked)
    //   C0 : 0x1b 0x21 F (0x21 = '!')
    //   C1 : 0x1b 0x22 F (0x22 = '"')
    // Complete codes (control+graphics, designated and invoked)
    //   0x1b 0x25 F   (0x25 = '%')
    //   0x1b 0x25 I F
    //   0x1b 0x25 0x47 ("\x1b%G") - UTF-8
    //   0x1b 0x25 0x40 ("\x1b%@") - return to ISO 2022
    // -------------------------------

    //------------------------------------------------------------------------------
    // Inverse print conversion for CodedCharacterSet
    // Inputs: 0) value
    public static function PrintInvCodedCharset($val)
    {
        $code = self::IPTC_CHARSET_INV[strtoupper(Perl::str($val))] ?? null;
        if (!$code) {
            $code = preg_replace('/ESC */i', "\x1b", Perl::str($val), -1, $n);  // translate ESC chars
            if ($n) {
                $code = str_replace(", \x1b", "\x1b", $code);  // remove comma separators
                $code = str_replace(' ', '', $code);           // remove spaces
            } else {
                ExifTool::PerlWarn("Bad syntax (use 'UTF8' or 'ESC X Y[, ...]')\n");
            }
        }
        return $code;
    }

    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckIPTC(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = Perl::str(($tagInfo->Format ?: $tagInfo->Table['FORMAT']) ?: '');
        if (preg_match('/^int(\d+)/', $format, $m)) {
            $bytes = Perl::int(Perl::num($m[1] ?: 0) / 8);
            if ($bytes != 1 and $bytes != 2 and $bytes != 4) {
                return "Can't write $bytes-byte integer";
            }
            $val = $valPtr;
            if (!ExifTool::IsInt($val)) {
                if (!ExifTool::IsHex($val)) return 'Not an integer';
                $val = $valPtr = Perl::hex($val);
            }
            $val = (int) Perl::num($val);
            for ($n = 0; $n < $bytes; ++$n) $val >>= 8;
            if ($val) return "Value too large for $bytes-byte format";
        } elseif (preg_match('/^(string|digits|undef)\[?(\d+),?(\d*)\]?$/', $format, $m)) {
            [$fmt, $minlen, $maxlen] = [$m[1], $m[2], $m[3]];
            $len = strlen(Perl::str($valPtr));
            if ($fmt === 'digits') {
                if (!preg_match('/^\d*$/', Perl::str($valPtr))) return 'Non-numeric characters in value';
                if ($len < $minlen and $len) {
                    // left pad with zeros if necessary
                    $valPtr = str_repeat('0', $minlen - $len) . $valPtr;
                    $len = $minlen;
                }
            }
            if ($fmt !== 'string') {    // (must truncate strings later, after recoding)
                if (!$maxlen) $maxlen = $minlen;
                if ($len < $minlen) {
                    if (!$et->OPTIONS['IgnoreMinorErrors']) {
                        return "[Minor] String too short (minlen is $minlen)";
                    }
                    $et->CHECK_WARN = "String too short for IPTC:{$tagInfo->Name} (written anyway)";
                } elseif ($len > $maxlen and !$et->OPTIONS['IgnoreMinorErrors']) {
                    $et->CHECK_WARN = "[Minor] IPTC:{$tagInfo->Name} exceeds length limit (truncated)";
                    $valPtr = substr(Perl::str($valPtr), 0, (int) $maxlen);
                }
            }
        } else {
            return "Bad IPTC Format ($format)";
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // format IPTC data for writing
    // Inputs: 0) ExifTool object ref, 1) tagInfo pointer,
    //         2) value reference (changed if necessary),
    //         3) reference to character set for translation (changed if necessary)
    //         4) record number, 5) flag set to read value (instead of write)
    public static function FormatIPTC(ExifTool $et, TagInfo $tagInfo, &$valPtr, &$xlatPtr, $rec, $read = null): void
    {
        $format = $tagInfo->Format ?: $tagInfo->Table['FORMAT'];
        if (!$format) return;
        if (preg_match('/^int(\d+)/', $format, $m)) {
            if ($read) {
                $len = strlen($valPtr);
                if ($len <= 8) {    // limit integer conversion to 8 bytes long
                    $val = 0;
                    for ($i = 0; $i < $len; ++$i) {
                        $val = $val * 256 + ord($valPtr[$i]);
                    }
                    $valPtr = $val;
                }
            } else {
                $len = Perl::int(($m[1] ?: 0) / 8);
                if ($len == 1) {        // 1 byte
                    $valPtr = chr(Perl::int(Perl::num($valPtr)) & 0xff);
                } elseif ($len == 2) {  // 2-byte integer
                    $valPtr = Perl::pack('n', $valPtr);
                } else {                // 4-byte integer
                    $valPtr = Perl::pack('N', $valPtr);
                }
            }
        } elseif (str_starts_with($format, 'string')) {
            if ($rec == 1) {
                if ($tagInfo->Name === 'CodedCharacterSet') {
                    $xlatPtr = self::HandleCodedCharset($et, $valPtr);
                }
            } elseif ($xlatPtr and $rec < 7 and preg_match('/[\x80-\xff]/', Perl::str($valPtr))) {
                self::TranslateCodedString($et, $valPtr, $xlatPtr, $read);
            }
            // must check length now (after any string recoding)
            if (!$read and preg_match('/^string\[(\d+),?(\d*)\]$/', $format, $m)) {
                [$minlen, $maxlen] = [$m[1], $m[2]];
                $len = strlen(Perl::str($valPtr));
                if (!$maxlen) $maxlen = $minlen;
                if ($len < $minlen) {
                    if ($et->Warn("String too short for IPTC:{$tagInfo->Name} (padded)", 2)) {
                        $valPtr .= str_repeat(' ', $minlen - $len);
                    }
                } elseif ($len > $maxlen) {
                    if ($et->Warn("IPTC:{$tagInfo->Name} exceeds length limit (truncated)", 2)) {
                        $valPtr = substr($valPtr, 0, (int) $maxlen);
                        // make sure UTF-8 is still valid
                        if (Perl::str($xlatPtr ?: $et->Options('Charset')) === 'UTF8') {
                            XMP::FixUTF8($valPtr, '.');
                        }
                    }
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // generate IPTC-format date
    // Inputs: 0) EXIF-format date string (YYYY:mm:dd) or date/time string
    // Returns: IPTC-format date string (YYYYmmdd), or undef and issue warning on error
    public static function IptcDate($val)
    {
        $re = '{^.*?(\d{4})[-:/.]?(\d{2})[-:/.]?(\d{2}).*}s';
        if (preg_match($re, Perl::str($val))) {
            $val = preg_replace($re, '$1$2$3', Perl::str($val), 1);
        } else {
            ExifTool::PerlWarn("Invalid date format (use YYYY:mm:dd)\n");
            $val = null;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // generate IPTC-format time
    // Inputs: 0) EXIF-format time string (HH:MM:SS[+/-HH:MM]) or date/time string
    // Returns: IPTC-format time string (HHMMSS+HHMM), or undef and issue warning on error
    public static function IptcTime($val)
    {
        if (preg_match('/(.*?)\b(\d{1,2})(:?)(\d{2})(:?)(\d{2})(\S*)\s*$/s', Perl::str($val), $m) and ($m[3] or !$m[5])) {
            $val = Perl::sprintf('%.2d%.2d%.2d', $m[2], $m[4], $m[6]);
            [$date, $tz] = [$m[1], $m[7]];
            if (preg_match('/([+-]\d{1,2}):?(\d{2})/', $tz, $mm)) {
                $tz = Perl::sprintf('%+.2d%.2d', $mm[1], $mm[2]);
            } elseif (preg_match('/Z/i', $tz)) {
                $tz = '+0000';  // UTC
            } else {
                // use local system timezone by default
                if ($date and preg_match('/^(\d{4}):(\d{2}):(\d{2})\s*$/', $date, $mm)) {
                    // we were given a date too, so determine the local timezone
                    // offset at the specified date/time
                    $d = [$mm[3], $mm[2] - 1, $mm[1]];
                    preg_match('/(\d{2})(\d{2})(\d{2})/', $val, $mm);
                    $tm = [$mm[3], $mm[2], $mm[1], ...$d];
                    $time = ExifTool::TimeLocal(...$tm);
                } else {
                    // it is difficult to get the proper local timezone offset for this
                    // time because the date tag is written separately.  (The offset may be
                    // different on a different date due to daylight savings time.)  In this
                    // case the best we can do easily is to use the current timezone offset.
                    $time = time();
                    $tm = ExifTool::PerlLocaltime($time);
                }
                $tz = str_replace(':', '', ExifTool::TimeZoneString($tm, $time));
            }
            $val .= $tz;
        } else {
            ExifTool::PerlWarn("Invalid time format (use HH:MM:SS[+/-HH:MM])\n");
            $val = null;    // time format error
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Inverse print conversion for IPTC date or time value
    // Inputs: 0) ExifTool ref, 1) IPTC date or 'now'
    // Returns: IPTC date
    public static function InverseDateOrTime(ExifTool $et, $val)
    {
        if (strtolower(Perl::str($val)) === 'now') return $et->TimeNow();
        return $val;
    }

    //------------------------------------------------------------------------------
    // Convert picture number
    // Inputs: 0) value
    // Returns: Converted value
    public static function InvConvertPictureNumber($val)
    {
        $val = preg_replace('/\(.*\)/', '', Perl::str($val));     // remove manufacturer description
        $val = str_replace(':', '', $val);                          // remove date separators
        $val = preg_replace('/[^0-9]/', ' ', $val);                  // turn remaining non-numbers to spaces
        $vals = Perl::split(' ', $val);
        if (count($vals) >= 4) {
            $val = Perl::pack('nNA8n', ...$vals);
        } elseif (preg_match('/unknown/i', $val)) {
            $val = str_repeat("\0", 16);
        } else {
            $val = null;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Write IPTC data record
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: IPTC data block (may be empty if no IPTC data)
    // Notes: Increments ExifTool CHANGED flag for each tag changed
    public static function DoWriteIPTC(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');

        // avoid editing IPTC directory unless necessary:
        // - improves speed
        // - avoids changing current MD5 digest unnecessarily
        // - avoids adding mandatory tags unless some other IPTC is changed
        $editDirs = $et->EDIT_DIRS ?? [];
        if (!(array_key_exists(Perl::str($dirInfo['DirName'] ?? null), $editDirs) or
            // standard IPTC tags in other locations should be edited too (eg. AFCP_IPTC)
            ($tagTablePtr === ExifTool::GetTagTable('Image::ExifTool::IPTC::Main') and array_key_exists('IPTC', $editDirs))))
        {
            return null;
        }
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        if ($dataPt === null) {
            $emptyData = '';
            $dataPt = $emptyData;
        }
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = $dirInfo['DirLen'] ?? null;
        $tagInfo = null;
        $iptcInfo = [];

        // start by assuming default IPTC encoding
        $xlat = $et->Options('CharsetIPTC');
        if (Perl::str($xlat) === Perl::str($et->Options('Charset'))) $xlat = null;

        // make sure our dataLen is defined (note: allow zero length directory)
        if ($dirLen === null) {
            $dataLen = $dirInfo['DataLen'] ?? null;
            if ($dataLen === null) $dataLen = strlen($dataPt);
            $dirLen = $dataLen - $start;
        }
        // quick check for improperly byte-swapped IPTC
        if ($dirLen >= 4 and substr($dataPt, $start, 1) !== "\x1c" and
                             substr($dataPt, $start + 3, 1) === "\x1c")
        {
            $et->Warn('IPTC data was improperly byte-swapped');
            $newData = Perl::pack('N*', ...Perl::unpack('V*', substr($dataPt, $start, $dirLen) . "\0\0\0"));
            $dataPt = $newData;
            $start = 0;
            // NOTE: MUST NOT access $dirInfo DataPt, DirStart or DataLen after this!
        }
        // generate lookup so we can find the record numbers
        // (PHP: keyed by spl_object_id() of the record table)
        $recordNum = [];
        foreach (ExifTool::TagTableKeys($tagTablePtr) as $tag) {
            $tagInfo = $tagTablePtr[$tag];
            if (!($tagInfo instanceof TagInfo and $tagInfo->SubDirectory)) continue;
            $table = $tagInfo->SubDirectory['TagTable'] ?? null;
            if (!$table) continue;
            $subTablePtr = ExifTool::GetTagTable($table);
            $recordNum[spl_object_id($subTablePtr)] = $tag;
        }

        // loop through new values and accumulate all IPTC information
        // into lists based on their IPTC record type
        foreach ($et->GetNewTagInfoList() as $tagInfo) {
            $table = $tagInfo->Table;
            $record = $table instanceof TagTable ? ($recordNum[spl_object_id($table)] ?? null) : null;
            // ignore tags we aren't writing to this directory
            if ($record === null) continue;
            $iptcInfo[$record] ??= [];
            $iptcInfo[$record][] = $tagInfo;
        }

        // get sorted list of records used.  Might as well be organized and
        // write our records in order of record number first, then tag number
        $recordList = array_keys($iptcInfo);
        sort($recordList, SORT_NUMERIC);
        $set = [];
        foreach ($recordList as $record) {
            // sort tagInfo lists by tagID
            usort($iptcInfo[$record], static fn ($a, $b) => Perl::num($a->TagID) <=> Perl::num($b->TagID));
            // build hash of all tagIDs to set
            foreach ($iptcInfo[$record] as $tagInfo) {
                $set[$record][$tagInfo->TagID] = $tagInfo;
            }
        }
        // run through the old IPTC data, inserting our records in
        // sequence and deleting existing records where necessary
        // (the IPTC specification states that records must occur in
        // numerical order, but tags within records need not be ordered)
        $pos = $start;
        $tail = $pos;       // old data written up to this point
        $dirEnd = $start + $dirLen;
        $newData = '';
        $lastRec = -1;
        $lastRecPos = 0;
        $allMandatory = 0;
        $foundRec = [];     // found flags: 0x01-existed before, 0x02-deleted, 0x04-created
        $addNow = null;

        for (;; $tail = $pos) {
            // get next IPTC record from input directory
            $id = $rec = $tag = $len = $valuePtr = null;
            if ($pos + 5 <= $dirEnd) {
                $buff = substr($dataPt, $pos, 5);
                [$id, $rec, $tag, $len] = Perl::unpack('CCCn', $buff);
                if ($id == 0x1c) {
                    if ($rec < $lastRec) {
                        if ($rec == 0) {
                            if ($et->Warn('IPTC record 0 encountered, subsequent records ignored', 2)) return null;
                            $rec = null;
                            $pos = $dirEnd;
                            $len = 0;
                        } else {
                            if ($et->Warn("IPTC doesn't conform to spec: Records out of sequence", 2)) return null;
                        }
                    }
                    // handle extended IPTC entry if necessary
                    $pos += 5;      // step to after field header
                    if ($len & 0x8000) {
                        $n = $len & 0x7fff;     // get num bytes in length field
                        if ($pos + $n <= $dirEnd and $n <= 8) {
                            // determine length (a big-endian, variable sized int)
                            for ($len = 0; $n; ++$pos, --$n) {
                                $len = $len * 256 + ord(substr($dataPt, $pos, 1));
                            }
                        } else {
                            $len = $dirEnd;     // invalid length
                        }
                    }
                    $valuePtr = $pos;
                    $pos += $len;   // step $pos to next entry
                    // make sure we don't go past the end of data
                    // (this can only happen if original data is bad)
                    if ($pos > $dirEnd) $pos = $dirEnd;
                } else {
                    $rec = null;
                }
            }
            // write out all our records that come before this one
            $writeRec = ($rec === null or $rec != $lastRec);
            if ($writeRec or $addNow) {
                for (;;) {
                    $newRec = $recordList[0] ?? null;
                    if ($addNow) {
                        $tagInfo = $addNow;
                    } elseif ($newRec === null or $newRec != $lastRec) {
                        // handle mandatory tags in last record unless it was empty
                        if (strlen($newData) > $lastRecPos) {
                            if ($allMandatory > 1) {
                                // entire lastRec contained mandatory tags, and at least one tag
                                // was deleted, so delete entire record unless we specifically
                                // added a mandatory tag
                                $num = 0;
                                foreach ($foundRec[$lastRec] ?? [] as $code) {
                                    if ($code & 0x04) {
                                        $num = 0;
                                        break;
                                    }
                                    if (($code & 0x03) == 0x01) ++$num;
                                }
                                if ($num) {
                                    $newData = substr($newData, 0, $lastRecPos);
                                    if ($verbose > 1) fwrite($out, "    - $num mandatory tags\n");
                                }
                            } elseif (isset(self::MANDATORY[$lastRec]) and
                                     $tagTablePtr === ExifTool::GetTagTable('Image::ExifTool::IPTC::Main'))
                            {
                                // add required mandatory tags
                                $mandatory = self::MANDATORY[$lastRec];
                                $subTablePtr = null;
                                $mandTags = array_keys($mandatory);
                                sort($mandTags, SORT_NUMERIC);
                                foreach ($mandTags as $mandTag) {
                                    if (!empty($foundRec[$lastRec][$mandTag])) continue;
                                    if (!$subTablePtr) {
                                        $tagInfo = $tagTablePtr[$lastRec];
                                        if (!($tagInfo and $tagInfo->SubDirectory)) {
                                            ExifTool::PerlWarn("WriteIPTC: Internal error 1\n");
                                            continue;
                                        }
                                        if (empty($tagInfo->SubDirectory['TagTable'])) continue;
                                        $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                                    }
                                    $tagInfo = $subTablePtr[$mandTag];
                                    if (!$tagInfo) {
                                        ExifTool::PerlWarn("WriteIPTC: Internal error 2\n");
                                        continue;
                                    }
                                    $value = $mandatory[$mandTag];
                                    $et->VerboseValue("+ IPTC:{$tagInfo->Name}", $value, ' (mandatory)');
                                    // apply necessary format conversions
                                    self::FormatIPTC($et, $tagInfo, $value, $xlat, $lastRec);
                                    $len = strlen($value);
                                    // generate our new entry
                                    $entry = pack('CCCn', 0x1c, $lastRec, $mandTag, strlen($value));
                                    $newData .= $entry . $value;    // add entry to new IPTC data
                                    // (don't mark as changed if just mandatory tags changed)
                                    // ++$$et{CHANGED};
                                }
                            }
                        }
                        if ($newRec === null) break;
                        $lastRec = $newRec;
                        $lastRecPos = strlen($newData);
                        $allMandatory = 1;
                    }
                    if (!$addNow) {
                        // compare current entry with entry next in line to write out
                        // (write out our tags in numerical order even though
                        // this isn't required by the IPTC spec)
                        if ($rec !== null and $rec <= $newRec) break;
                        $tagInfo = $iptcInfo[$newRec][0];
                    }
                    $newTag = $tagInfo->TagID;
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    // only add new values if...
                    $doSet = null;
                    $values = [];
                    $found = ($foundRec[$newRec][$newTag] ?? null) ?: 0;
                    if ($found & 0x02) {
                        // ...tag existed before and was deleted (unless we already added it)
                        if (!($found & 0x04)) $doSet = 1;
                    } elseif ($tagInfo->List) {
                        // ...tag is List and it existed before or we are creating it
                        if ($found ? !$nvHash->CreateOnly : $nvHash->IsCreating) $doSet = 1;
                    } else {
                        // ...tag didn't exist before and we are creating it
                        if (!$found and $nvHash->IsCreating) $doSet = 1;
                    }
                    if ($doSet) {
                        $values = $et->GetNewValueList($nvHash);
                        if ($values) $foundRec[$newRec][$newTag] = $found | 0x04;
                        // write tags for each value in list
                        foreach ($values as $value) {
                            $et->VerboseValue("+ {$dirInfo['DirName']}:{$tagInfo->Name}", $value);
                            // reset allMandatory flag if a non-mandatory tag is written
                            if ($allMandatory) {
                                $mandatory = self::MANDATORY[$newRec] ?? null;
                                if (!($mandatory and !empty($mandatory[$newTag]))) $allMandatory = 0;
                            }
                            // apply necessary format conversions
                            self::FormatIPTC($et, $tagInfo, $value, $xlat, $newRec);
                            // (note: IPTC string values are NOT null terminated)
                            $len = strlen(Perl::str($value));
                            // generate our new entry
                            $entry = pack('CCC', 0x1c, $newRec, $newTag);
                            if ($len <= 0x7fff) {
                                $entry .= pack('n', $len);
                            } else {
                                // extended dataset tag
                                $entry .= pack('nN', 0x8004, $len);
                            }
                            $newData .= $entry . $value;    // add entry to new IPTC data
                            ++$et->CHANGED;
                        }
                    }
                    // continue on with regular programming if done adding tag now
                    if ($addNow) {
                        $addNow = null;
                        if ($writeRec) continue;
                        break;
                    }
                    // remove this tagID from the sorted write list
                    array_shift($iptcInfo[$newRec]);
                    if (!$iptcInfo[$newRec]) array_shift($recordList);
                }
                if ($writeRec) {
                    // all done if no more records to write
                    if ($rec === null) break;
                    // update last record variables
                    $lastRec = $rec;
                    $lastRecPos = strlen($newData);
                    $allMandatory = 1;
                }
            }
            // set flag indicating we found this tag
            $foundRec[$rec][$tag] = (($foundRec[$rec][$tag] ?? null) ?: 0) ?: 0x01;
            // write out this record unless we are setting it with a new value
            $tagInfo = $set[$rec][$tag] ?? null;
            if ($tagInfo) {
                $nvHash = $et->GetNewValueHash($tagInfo);
                $len = $pos - $valuePtr;
                $val = substr($dataPt, $valuePtr, $len);
                // remove null terminator if it exists (written by braindead software like Picasa 2.0)
                if ($tagInfo->Format and str_starts_with($tagInfo->Format, 'string')) $val = preg_replace('/\0+$/', '', $val);
                $oldXlat = $xlat;
                self::FormatIPTC($et, $tagInfo, $val, $xlat, $rec, 1);
                if ($et->IsOverwriting($nvHash, $val)) {
                    $xlat = $oldXlat;   // don't change translation (not writing this value)
                    $et->VerboseValue("- {$dirInfo['DirName']}:{$tagInfo->Name}", $val);
                    ++$et->CHANGED;
                    // set deleted flag to indicate we found and deleted this tag
                    $foundRec[$rec][$tag] |= 0x02;
                    // increment allMandatory flag to indicate a tag was removed
                    if ($allMandatory) ++$allMandatory;
                    // write this tag now if overwriting an existing value
                    if ($nvHash->Value and $recordList and
                        $recordList[0] == $rec and !($foundRec[$rec][$tag] & 0x04))
                    {
                        $addNow = $tagInfo;
                    }
                    continue;
                }
            } elseif ($rec == 1 and $tag == 90) {
                // handle CodedCharacterSet tag
                $val = substr($dataPt, $valuePtr, $pos - $valuePtr);
                $xlat = self::HandleCodedCharset($et, $val);
            }
            // reset allMandatory flag if a non-mandatory tag is written
            if ($allMandatory) {
                $mandatory = self::MANDATORY[$rec] ?? null;
                if (!($mandatory and !empty($mandatory[$tag]))) {
                    $allMandatory = 0;
                }
            }
            // write out the record
            $newData .= substr($dataPt, $tail, $pos - $tail);
        }
        // make sure the rest of the data is zero
        if ($tail < $dirEnd) {
            $pad = substr($dataPt, $tail, $dirEnd - $tail);
            if (preg_match('/[^\0]/', $pad)) {
                if ($et->Warn('Unrecognized data in IPTC padding', 2)) return null;
            }
        }
        return $newData;
    }

    //------------------------------------------------------------------------------
    // Write IPTC data record and calculate NewIPTCDigest
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: IPTC data block (may be empty if no IPTC data)
    // Notes: Increments ExifTool CHANGED flag for each tag changed
    public static function WriteIPTC(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package

        $newData = self::DoWriteIPTC($et, $dirInfo, $tagTablePtr);

        // calculate standard IPTC digests only if we are writing or deleting
        // Photoshop:IPTCDigest with a value of 'new' or 'old'
        while ($iptcDigestInfo = Photoshop::IptcDigestInfo()) {
            $nvHash = $et->NEW_VALUE[spl_object_id($iptcDigestInfo)] ?? null;
            if ($nvHash === null) break;
            if (!self::IsStandardIPTC($et->MetadataPath())) break;
            $values = $et->GetNewValueList($nvHash);
            if ($nvHash->DelValue) array_push($values, ...$nvHash->DelValue);
            $new = count(preg_grep('/^new$/', array_map(Perl::str(...), $values)));
            $old = count(preg_grep('/^old$/', array_map(Perl::str(...), $values)));
            if (!($new or $old)) break;
            // (PHP always has MD5: no "Digest::MD5 must be installed" warning)
            $dataPt = null;
            if ($new) {
                if ($newData !== null) {
                    $dataPt = $newData;
                } else {
                    $dataPt = Perl::str($dirInfo['DataPt'] ?? null);
                    if (($dirInfo['DirStart'] ?? null) or strlen($dataPt) != ($dirInfo['DirLen'] ?? null)) {
                        $buff = substr($dataPt, (int) ($dirInfo['DirStart'] ?? 0), (int) ($dirInfo['DirLen'] ?? 0));
                        $dataPt = $buff;
                    }
                }
                // set NewIPTCDigest data member unless IPTC is being deleted
                if (strlen($dataPt)) $et->NewIPTCDigest = md5($dataPt, true);
            }
            if ($old) {
                if ($new and $newData === null) {
                    $et->OldIPTCDigest = $et->NewIPTCDigest;
                } elseif (($dirInfo['DataPt'] ?? null) !== null) {  // (may be undef if creating new IPTC)
                    $dataPt = Perl::str($dirInfo['DataPt']);
                    if (($dirInfo['DirStart'] ?? null) or strlen($dataPt) != ($dirInfo['DirLen'] ?? null)) {
                        $buff = substr($dataPt, (int) ($dirInfo['DirStart'] ?? 0), (int) ($dirInfo['DirLen'] ?? 0));
                        $dataPt = $buff;
                    }
                    if (strlen($dataPt)) $et->OldIPTCDigest = md5($dataPt, true);
                }
            }
            break;
        }
        // set changed if ForceWrite tag was set to "IPTC"
        if ($newData !== null and strlen($newData) and ($et->FORCE_WRITE['IPTC'] ?? null)) ++$et->CHANGED;
        return $newData;
    }
}
