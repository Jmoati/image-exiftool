<?php

//------------------------------------------------------------------------------
// File:         IPTC.pm
//
// Description:  Read IPTC meta information
//
// Revisions:    Jan. 08/2003 - P. Harvey Created
//               Feb. 05/2004 - P. Harvey Added support for records other than 2
//
// References:   1) http://www.iptc.org/IIM/
//
// PHP port of Image::ExifTool::IPTC 1.59 (reading only). Tag tables are generated
// into tables/IPTC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class IPTC
{
    public static $VERSION = '1.59';

    // manufacturer strings for IPTCPictureNumber
    // (my %manufacturer from WriteIPTC.pl, used by ConvertPictureNumber)
    private const array MANUFACTURER = [
        1 => 'Associated Press, USA',
        2 => 'Eastman Kodak Co, USA',
        3 => 'Hasselblad Electronic Imaging, Sweden',
        4 => 'Tecnavia SA, Switzerland',
        5 => 'Nikon Corporation, Japan',
        6 => 'Coatsworth Communications Inc, Canada',
        7 => 'Agence France Presse, France',
        8 => 'T/One Inc, USA',
        9 => 'Associated Newspapers, UK',
        10 => 'Reuters London',
        11 => 'Sandia Imaging Systems Inc, USA',
        12 => 'Visualize, Spain',
    ];

    //------------------------------------------------------------------------------
    // Print conversion for CodedCharacterSet
    // Inputs: 0) value
    public static function PrintCodedCharset($val)
    {
        $val = Perl::str($val);
        if (!empty(Vars\IPTC::$iptcCharset[$val])) return Vars\IPTC::$iptcCharset[$val];
        $val = preg_replace('/(.)/', ' $1', $val);
        $val = preg_replace('/ \x1b/', ', ESC', $val);
        $val = preg_replace('/^,? /', '', $val, 1);
        return $val;
    }

    //------------------------------------------------------------------------------
    // Handle CodedCharacterSet
    // Inputs: 0) ExifTool ref, 1) CodedCharacterSet value
    // Returns: IPTC character set if translation required (or 'bad' if unknown)
    public static function HandleCodedCharset(ExifTool $et, $val)
    {
        $xlat = Vars\IPTC::$iptcCharset[Perl::str($val)] ?? null;
        if (!$xlat) {
            if (preg_match('/^\x1b\x25/', Perl::str($val))) {
                // some unknown character set invoked
                $xlat = 'bad';  // flag unsupported coding
            } else {
                $xlat = $et->Options('CharsetIPTC');
            }
        }
        // no need to translate if Charset is the same
        if (Perl::str($xlat) === Perl::str($et->Options('Charset'))) $xlat = null;
        return $xlat;
    }

    //------------------------------------------------------------------------------
    // Encode or decode coded string
    // Inputs: 0) ExifTool ref, 1) value ptr, 2) IPTC charset (or 'bad') ref
    //         3) flag set to decode (read) value from IPTC
    // Updates value on return
    public static function TranslateCodedString(ExifTool $et, &$valPtr, &$xlatPtr, $read)
    {
        if (Perl::str($xlatPtr) === 'bad') {
            $et->Warn('Some IPTC characters not converted (unsupported CodedCharacterSet)');
            $xlatPtr = null;
        } elseif (!$read) {
            $valPtr = $et->Decode($valPtr, null, null, $xlatPtr);
        } elseif (!preg_match('/[\x14\x15\x1b]/', $valPtr)) {
            $valPtr = $et->Decode($valPtr, $xlatPtr);
        } else {
            // don't yet support reading ISO 2022 shifted character sets
            $et->Warn('Some IPTC characters not converted (ISO 2022 shifting not supported)');
        }
    }

    //------------------------------------------------------------------------------
    // Is this IPTC in a standard location?
    // Inputs: 0) Current metadata path string
    // Returns: true if path is standard, 0 if file type doesn't have standard IPTC,
    //          or undef if IPTC is non-standard
    public static function IsStandardIPTC($path)
    {
        if (!empty(Vars\IPTC::$isStandardIPTC[$path])) return 1;
        if (!(preg_match('/^(\w+)/', $path, $m) and isset(Vars\IPTC::$isStandardIPTC[$m[1]]))) return 0;
        return null;    // non-standard
    }

    //------------------------------------------------------------------------------
    // Convert picture number
    // Inputs: 0) value
    // Returns: Converted value
    // (from WriteIPTC.pl)
    public static function ConvertPictureNumber($val)
    {
        $val = Perl::str($val);
        if ($val === str_repeat("\0", 16)) {
            $val = 'Unknown';
        } elseif (strlen($val) >= 16) {
            $vals = Perl::unpack('nNA8n', $val);
            $val = $vals[0];
            $manu = self::MANUFACTURER[$val] ?? null;
            if ($manu) $val .= " ($manu)";
            $val .= ', equip ' . $vals[1];
            $vals[2] = preg_replace('/(\d{4})(\d{2})(\d{2})/', '$1:$2:$3', $vals[2], 1);
            $val .= ", $vals[2], no. $vals[3]";
        } else {
            $val = '<format error>';
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // get IPTC info
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    //         2) reference to tag table
    // Returns: 1 on success, 0 otherwise
    public static function ProcessIPTC(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: 0;
        $dirEnd = $pos + $dirLen;
        $verbose = $et->Options('Verbose');
        $validate = $et->Options('Validate');
        $success = 0;
        $lastRec = $recordPtr = $recordName = null;
        $seen = [];

        if ($verbose and $dirInfo) $et->VerboseDir('IPTC', 0, $dirInfo['DirLen'] ?? null);

        if ($tagTablePtr === ExifTool::GetTagTable('Image::ExifTool::IPTC::Main')) {
            $path = $et->MetadataPath();
            $isStd = self::IsStandardIPTC($path);
            if ($isStd !== null and empty($et->DIR_COUNT['STD_IPTC'])) {
                // set flag to ensure we only have one family 1 "IPTC" group
                $et->DIR_COUNT['STD_IPTC'] = 1;
                // calculate MD5 if Digest::MD5 is available (truly standard IPTC only)
                if ($isStd) {
                    if ($pos or $dirLen != strlen($dataPt)) {
                        $md5 = md5(substr($dataPt, $pos, $dirLen), true);
                    } else {
                        $md5 = md5($dataPt, true);
                    }
                    $et->FoundTag('CurrentIPTCDigest', $md5);
                }
            } else {
                if ((MWG::$strict or $validate) and
                    preg_match('/^(JPEG|TIFF|PSD)$/', (string) $et->FILE_TYPE))
                {
                    if (MWG::$strict) {
                        // ignore non-standard IPTC while in strict MWG compatibility mode
                        $et->Warn("Ignored non-standard IPTC at $path");
                        return 1;
                    } else {
                        $et->Warn("Non-standard IPTC at $path", 1);
                    }
                }
                // extract non-standard IPTC
                $count = (($et->DIR_COUNT['IPTC'] ?? null) ?: 0) + 1;  // count non-standard IPTC
                $et->DIR_COUNT['IPTC'] = $count;
                $et->LOW_PRIORITY_DIR['IPTC'] = 1;      // lower priority of non-standard IPTC
                $et->SET_GROUP1 = '+' . ($count + 1);   // add number to family 1 group name
            }
        }
        // begin by assuming default IPTC encoding
        $xlat = $et->Options('CharsetIPTC');
        if (Perl::str($xlat) === Perl::str($et->Options('Charset'))) $xlat = null;

        // quick check for improperly byte-swapped IPTC
        if ($dirLen >= 4 and substr($dataPt, $pos, 1) !== "\x1c" and
                             substr($dataPt, $pos + 3, 1) === "\x1c")
        {
            $et->Warn('IPTC data was improperly byte-swapped');
            $newData = Perl::pack('N*', ...Perl::unpack('V*', substr($dataPt, $pos, $dirLen) . "\0\0\0"));
            $dataPt = $newData;
            $pos = 0;
            $dirEnd = $pos + $dirLen;
            // NOTE: MUST NOT access $dirInfo DataPt, DirStart or DataLen after this!
        }
        // extract IPTC as a block if specified
        if (!empty($et->REQ_TAG_LOOKUP['iptc']) or ($et->TAGS_FROM_FILE and
            empty($et->EXCL_TAG_LOOKUP['iptc'])))
        {
            if ($pos or $dirLen != strlen($dataPt)) {
                $et->FoundTag('IPTC', substr($dataPt, $pos, $dirLen));
            } else {
                $et->FoundTag('IPTC', $dataPt);
            }
        }
        while ($pos + 5 <= $dirEnd) {
            $buff = substr($dataPt, $pos, 5);
            [$id, $rec, $tag, $len] = Perl::unpack('CCCn', $buff);
            if ($id != 0x1c) {
                if (!$id) {
                    // scan the rest of the data an give warning unless all zeros
                    // (iMatch pads the IPTC block with nulls for some reason)
                    $remaining = substr($dataPt, $pos, $dirEnd - $pos);
                    if (!preg_match('/[^\0]/', $remaining)) break;
                }
                $et->Warn(Perl::sprintf('Bad IPTC data tag (marker 0x%x)', $id));
                break;
            }
            $pos += 5;      // step to after field header
            // handle extended IPTC entry if necessary
            if ($len & 0x8000) {
                $n = $len & 0x7fff;     // get num bytes in length field
                if ($pos + $n > $dirEnd or $n > 8) {
                    $et->VPrint(0, "Invalid extended IPTC entry (dataset $rec:$tag, len $len)\n");
                    $success = 0;
                    break;
                }
                // determine length (a big-endian, variable sized int)
                for ($len = 0; $n; ++$pos, --$n) {
                    $len = $len * 256 + ord(substr($dataPt, $pos, 1));
                }
            }
            if ($pos + $len > $dirEnd) {
                $et->VPrint(0, "Invalid IPTC entry (dataset $rec:$tag, len $len)\n");
                $success = 0;
                break;
            }
            if ($lastRec === null or $lastRec != $rec) {
                if ($validate and $lastRec !== null and $rec < $lastRec) {
                    $et->Warn("IPTC doesn't conform to spec: Records out of sequence", 1);
                }
                $tableInfo = $tagTablePtr[$rec];
                if (!$tableInfo) {
                    $et->Warn("Unrecognized IPTC record $rec (ignored)");
                    $pos += $len;
                    continue;   // ignore this entry
                }
                $tableName = $tableInfo->SubDirectory['TagTable'] ?? null;
                if (!$tableName) {
                    $et->Warn("No table for IPTC record $rec!");
                    break;  // this shouldn't happen
                }
                $recordName = $tableInfo->Name;
                $recordPtr = ExifTool::GetTagTable($tableName);
                $et->VPrint(0, $et->INDENT, "-- $recordName record --\n");
                $lastRec = $rec;
            }
            $val = substr($dataPt, $pos, $len);

            // add tagInfo for all unknown tags:
            if (!$recordPtr[$tag]) {
                // - no Format so format is auto-detected
                // - no Name so name is generated automatically with decimal tag number
                ExifTool::AddTagToTable($recordPtr, $tag, ['Unknown' => 1]);
            }
            $tagInfo = $et->GetTagInfo($recordPtr, $tag);
            // (Perl autovivifies an undefined $tagInfo here, ignored in the port)
            if ($validate and $tagInfo and !$tagInfo->List and !$tagInfo->Unknown) {
                if (!empty($seen[spl_object_id($tagInfo)])) $et->Warn("Multiple IPTC {$tagInfo->Name} tags");
                $seen[spl_object_id($tagInfo)] = 1;
            }
            $format = null;
            // (could use $$recordPtr{FORMAT} if no Format below, but don't do this to
            //  be backward compatible with improperly written PhotoMechanic tags)
            if ($tagInfo) $format = $tagInfo->Format;
            if (!$format) {
                // guess at "int" format if not specified
                if ($len <= 4 and $len != 3 and preg_match('/[\0-\x08]/', $val)) $format = 'int';
            } elseif ($validate) {
                if (preg_match('/(.*)\[(\d+)(,(\d+))?\]/', $format, $m)) {
                    $fmt = $m[1];
                    $min = $m[2];
                    $max = ($m[4] ?? null) ?: $m[2];
                } else {
                    $fmt = $format;
                    $min = $max = 1;
                }
                $siz = ExifTool::FormatSize($fmt) ?: 1;
                $min *= $siz;
                $max *= $siz;
                if ($len < $min or $len > $max) {
                    $should = ($min == $max) ? $min : ($len < $min ? "$min min" : "$max max");
                    $what = ($len < $siz * $min) ? 'short' : 'long';
                    $et->Warn("IPTC {$tagInfo->Name} too $what ($len bytes; should be $should)", 1);
                }
            }
            if ($format) {
                if (preg_match('/^int/', $format)) {
                    if ($len <= 8) {    // limit integer conversion to 8 bytes long
                        $val = 0;
                        for ($i = 0; $i < $len; ++$i) {
                            $val = $val * 256 + ord(substr($dataPt, $pos + $i, 1));
                        }
                    }
                } elseif (preg_match('/^string/', $format)) {
                    // some braindead softwares add null terminators
                    $val = preg_replace('/\0+$/', '', $val, 1, $n);
                    if ($n and $validate) {
                        $et->Warn("IPTC {$tagInfo->Name} improperly terminated", 1);
                    }
                    if ($rec == 1) {
                        // handle CodedCharacterSet tag
                        if ($tag == 90) $xlat = self::HandleCodedCharset($et, $val);
                    // translate characters if necessary and special characters exist
                    } elseif ($xlat and $rec < 7 and preg_match('/[\x80-\xff]/', $val)) {
                        // translate to specified character set
                        self::TranslateCodedString($et, $val, $xlat, 1);
                    }
                } elseif (preg_match('/^digits/', $format)) {
                    $val = preg_replace('/\0+$/', '', $val, 1, $n);
                    if ($n and $validate) {
                        $et->Warn("IPTC {$tagInfo->Name} improperly terminated", 1);
                    }
                } elseif (!preg_match('/^undef/', $format)) {
                    ExifTool::PerlWarn("Invalid IPTC format: $format");  // (this would be a programming error)
                }
            }
            if ($verbose) {
                $et->VerboseInfo($tag, $tagInfo,
                    Table:   $tagTablePtr,
                    Value:   $val,
                    DataPt:  $dataPt,
                    DataPos: $dirInfo['DataPos'] ?? null,
                    Size:    $len,
                    Start:   $pos,
                    Extra:   ", $recordName record",
                    Format:  $format,
                );
            }
            if ($tagInfo) $et->FoundTag($tagInfo, $val);
            $success = 1;

            $pos += $len;   // increment to next field
        }
        $et->SET_GROUP1 = null;
        unset($et->LOW_PRIORITY_DIR['IPTC']);
        return $success;
    }
}
