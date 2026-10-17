<?php

//------------------------------------------------------------------------------
// File:         Protobuf.pm
//
// Description:  Decode protocol buffer data
//
// Revisions:    2024-12-04 - P. Harvey Created
//
// Notes:        Tag definitions for Protobuf tags support 'signed', 'unsigned',
//               and 'int64s' formats for VARINT (type 0) values, 'int64u',
//               'int64s', 'rational64u', 'rational64s' and 'double' for I64
//               (type 1), 'undef', 'string' and 'rational' for LEN (type 2),
//               and 'int32u', 'int32s', 'rational32u', 'rational32s',
//               'fixed32u', 'fixed32s' and 'float' for I32 (type 5) values.
//
// References:   1) https://protobuf.dev/programming-guides/encoding/
//
// PHP port of Image::ExifTool::Protobuf 1.07. $intMax and $int64sMin are in Vars\Protobuf.
// VarInt values follow Perl's numbers: an int below 2**63, the exact decimal string of an
// unsigned integer from 2**63 to 2**64-1 (like ExifTool::Get64u), a float above.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\Protobuf as Vars;

final class Protobuf
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Read bytes from dirInfo object
    // Inputs: 0) dirInfo ref (with DataPt and Pos set), 1) number of bytes
    // Returns: binary data or undef on error
    public static function GetBytes(array &$dirInfo, $n)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['Pos'];
        if ($pos + $n > strlen($dataPt)) return null;
        $dirInfo['Pos'] += $n;
        return substr($dataPt, $pos, (int) $n);
    }

    //------------------------------------------------------------------------------
    // Read variable-length integer
    // Inputs: 0) dirInfo ref
    // Returns: integer value
    // - sets $$dirInfo{Bit0} according to bit 0 of returned value
    //   (necessary for cases where a signed integer exceeds $intMax)
    public static function VarInt(array &$dirInfo)
    {
        $buff = self::GetBytes($dirInfo, 1);
        if ($buff === null) return null;
        $val = ord($buff) & 0x7f;
        $dirInfo['Bit0'] = $val & 0x01;
        $mult = 128;
        $i = 0;
        // (PHP: Perl's unsigned integers hold 64 bits: accumulate the bits in $val, which
        //  wraps like a 64-bit unsigned integer, and the value as a float in $big once it
        //  doesn't fit, which is when Perl switches to floating point too)
        $shift = 0;
        $big = null;
        for (;;) {
            if (!(ord($buff) & 0x80)) break;
            $buff = self::GetBytes($dirInfo, 1);
            if ($buff === null) return null;
            $b = ord($buff) & 0x7f;
            $shift += 7;
            if ($big === null and $b and ($shift > 63 or ($shift == 63 and $b > 1))) {
                $big = (float) self::U64Value($val);
            }
            if ($big !== null) {
                $big += $b * $mult;
            } elseif ($b) {
                $val |= $b << $shift;
            }
            if (!(ord($buff) & 0x80)) break;
            if (++$i > 32) return null;     // set a reasonable limit
            $mult *= 128;   // (Note: don't use integer bit shift to avoid integer overflow)
        }
        return $big ?? self::U64Value($val);
    }

    //------------------------------------------------------------------------------
    // Read protobuf record
    // Inputs: 0) dirInfo ref
    // Returns: 0) record payload (plus tag id and format type in list context) or undef on error
    // Notes: Updates $$dirInfo{Pos} to start of next record, and sets $$dirInfo{Bit0}
    //        according to the least significant bit of type 0 (varInt) records
    // (PHP: scalar context; ReadRecordList() is the list context)
    public static function ReadRecord(array &$dirInfo)
    {
        return self::ReadRecordList($dirInfo)[0];
    }

    /** ReadRecord in list context: [payload, tag id, format type] */
    public static function ReadRecordList(array &$dirInfo): array
    {
        $val = self::VarInt($dirInfo);
        if ($val === null) return [null, null, null];
        $u = self::U64($val);
        $id = ($u >> 3) & (PHP_INT_MAX >> 2);   // (unsigned shift)
        $type = $u & 0x07;
        $buff = null;

        if ($type == 0) {       // varInt
            $buff = self::VarInt($dirInfo);
        } elseif ($type == 1) { // 64-bit number
            $buff = self::GetBytes($dirInfo, 8);
        } elseif ($type == 2) { // string, bytes or protobuf
            $len = self::VarInt($dirInfo);
            if ($len) {
                $buff = self::GetBytes($dirInfo, $len);
            } else {
                $buff = '';
            }
        } elseif ($type == 3) { // (deprecated start group)
            $buff = '';
        } elseif ($type == 4) { // (deprecated end group)
            $buff = '';
        } elseif ($type == 5) { // 32-bit number
            $buff = self::GetBytes($dirInfo, 4);
        }
        return [$buff, $id, $type];
    }

    //------------------------------------------------------------------------------
    // Check to see if this could be a protobuf object
    // Inputs: 0) data reference
    // Retursn: true if this looks like a protobuf
    public static function IsProtobuf($pt)
    {
        $dirInfo = ['DataPt' => $pt, 'Pos' => 0];
        for (;;) {
            if (self::ReadRecord($dirInfo) === null) return 0;
            if ($dirInfo['Pos'] == strlen($pt)) return 1;
        }
    }

    //------------------------------------------------------------------------------
    // Process protobuf data (eg. DJI djmd timed data from Action4 videos) (ref 1)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref with DataPt, DataPos, DirName and Base,
    //         2) tag table ptr, 3) prefix of parent protobuf ID's
    // Returns: true on success
    public static function ProcessProtobuf(ExifTool $et, array &$dirInfo, TagTable $tagTbl, $prefix = null)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataPt = Perl::str($dataPt);
        $dirInfo['DataPt'] = $dataPt;
        $dirName = Perl::str($dirInfo['DirName'] ?? null);
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        $dirEnd = $dirStart + $dirLen;
        $dataPos = (($dirInfo['Base'] ?? null) ?: 0) + (($dirInfo['DataPos'] ?? null) ?: 0);
        $unknown = $et->Options('Unknown') ?: $et->Options('Verbose');

        $dirInfo['Pos'] = ($dirInfo['DirStart'] ?? null) ?: 0;  // initialize buffer Pos
        $et->VerboseDir('Protobuf', null, $dirLen);
        if (!$prefix) {
            $prefix = '';
            if (!isset($et->ProtoPrefix[$dirName])) $et->ProtoPrefix[$dirName] = '';
            ExifTool::SetByteOrder('II');
        }
        // prefix for unknown tags
        $unkPre = $tagTbl['TAG_PREFIX'] ? $tagTbl['TAG_PREFIX'] . '_' : 'Protobuf ';

        // loop through protobuf records
        for (;;) {
            $pos = $dirInfo['Pos'];
            if ($pos >= $dirEnd) break;
            [$buff, $id, $type] = self::ReadRecordList($dirInfo);
            if ($buff === null) {
                $et->Warn('Protobuf format error');
                break;
            }
            if ($type == 2 and preg_match('/\.proto$/', $buff)) {
                // save protocol name separately for directory type
                $et->ProtoPrefix[$dirName] = substr($buff, 0, -6) . '_';
                $et->HandleTag($tagTbl, 'Protocol', $buff);
            }
            $tag = $et->ProtoPrefix[$dirName] . "$prefix$id";
            $tagInfo = $tagTbl[$tag];
            if ($tagInfo) {
                if ($type != 2 and $tagInfo->Unknown and !$unknown) continue;
            } else {
                if (!($type == 2 or $unknown)) continue;
                $tagInfo = ExifTool::AddTagToTable($tagTbl, $tag, ['Unknown' => 1]);
            }
            // set IsProtobuf flag (only for Unknown tags) if necessary
            if ($type == 2 and $tagInfo->Unknown) {
                if ($tagInfo->IsProtobuf) {
                    if (!self::IsProtobuf($buff)) $tagInfo->IsProtobuf = 0;
                } elseif ($tagInfo->IsProtobuf === null and preg_match('/[^\x20-\x7e]/', $buff) and
                    self::IsProtobuf($buff))
                {
                    $tagInfo->IsProtobuf = 1;
                }
                if (!($tagInfo->IsProtobuf or $unknown)) continue;
            }
            // format binary payload into a useful value
            $val = null;
            if ($tagInfo->Format) {
                if ($type == 0) {
                    $val = $buff;
                    if ($tagInfo->Format === 'signed') {
                        $val = self::Signed($val, $dirInfo['Bit0']);
                    } elseif ($tagInfo->Format === 'int64s' and self::GE64sMin($val)) {
                        // hack for DJI drones which store 64-bit signed integers improperly
                        // (just toss upper 32 bits which should be all 1's anyway)
                        // Note: do the two subtractions because $int64sMin + 4294967296
                        // is too large for a 64-bit integer
                        $val = self::Minus64sMin($val);
                    }
                } elseif ($type == 2 and $tagInfo->Format === 'rational') {
                    $dir = ['DataPt' => $buff, 'Pos' => 0];
                    $num = self::VarInt($dir);
                    $den = self::VarInt($dir);
                    $val = ($num !== null and $den) ? Perl::num($num) / Perl::num($den) : 'err';
                } else {
                    $val = ExifTool::ReadValue($buff, 0, $tagInfo->Format, null, strlen($buff));
                }
            } elseif ($type == 0) { // varInt
                $val = $buff;
                $hex = Perl::sprintf('%x', is_float($val) ? $val : self::U64($val));
                if (self::GE64sMin($val)) {
                    $s64 = self::Minus64sMin($val);
                    $val = Perl::str($val) . " (0x$hex, int64s " . Perl::str($s64) . ')';
                } else {
                    $signed = self::Signed($val, $dirInfo['Bit0']);
                    $val = Perl::str($val) . " (0x$hex, signed " . Perl::str($signed) . ')';
                }
            } elseif ($type == 1) { // 64-bit number
                $val = '0x' . bin2hex($buff) . ' (double ' . Perl::str(ExifTool::GetDouble($buff, 0)) . ')';
            } elseif ($type == 2) { // string, bytes or protobuf
                if ($tagInfo->SubDirectory) {
                    // (fall through to process known SubDirectory)
                } elseif ($tagInfo->IsProtobuf) {
                    // process Unknown protobuf directories
                    $et->VPrint(1, "{$et->INDENT}{$unkPre}$tag (" . strlen($buff) . " bytes) -->\n");
                    $addr = $dataPos + $dirInfo['Pos'] - strlen($buff);
                    $et->VerboseDump($buff, Addr: $addr, Prefix: $et->INDENT);
                    $subdir = ['DataPt' => $buff, 'DataPos' => $addr, 'DirName' => $dirName];
                    $et->INDENT .= '| ';
                    self::ProcessProtobuf($et, $subdir, $tagTbl, "$prefix$id-");
                    $et->INDENT = substr($et->INDENT, 0, -2);
                    continue;
                } else {
                    // check for rational value (2 varInt values)
                    $rat = null;
                    $dir = ['DataPt' => $buff, 'Pos' => 0];
                    $num = self::VarInt($dir);
                    if ($num !== null) {
                        $denom = self::VarInt($dir);
                        if ($denom and $dir['Pos'] == strlen($buff)) {
                            $rat = ' (rational ' . Perl::str($num) . '/' . Perl::str($denom) . ')';
                        }
                    }
                    if (!preg_match('/[^\r\n\t\x20-\x7e]/', $buff)) {
                        $val = $buff;   // assume this is an ASCII string
                    } elseif (strlen($buff) % 4) {
                        $val = '0x' . bin2hex($buff);
                    } else {
                        $n = strlen($buff) / 4;
                        // (do this instead of '(H8)*' because older Perl version didn't support this)
                        $val = '0x' . implode(' ', Perl::unpack("(H8)$n", $buff));    // (group in 4-byte blocks)
                    }
                    if ($rat) $val .= $rat;
                }
            } elseif ($type == 5) { // 32-bit number
                $val = '0x' . bin2hex($buff) . ' (int32u ' . ExifTool::Get32u($buff, 0);
                if (ord(substr($buff, 3, 1)) & 0x80) $val .= ', int32s ' . ExifTool::Get32s($buff, 0);
                $val .= ', float ' . Perl::str(ExifTool::GetFloat($buff, 0)) . ')';
            } else {
                $val = $buff;
            }
            // get length of data in the record
            $start = $type == 0 ? $pos + 1 : $dirInfo['Pos'] - strlen($buff);
            $et->HandleTag($tagTbl, $tag, $val,
                DataPt:  $dataPt,
                DataPos: $dataPos,
                Start:   $start,
                Size:    $dirInfo['Pos'] - $start,
                Extra:   ", type=$type",
                Format:  $tagInfo->Format,
            );
        }
        // warn if we didn't finish exactly at the end of the buffer
        if (!($prefix or $dirInfo['Pos'] == $dirEnd)) $et->Warn('Truncated protobuf data');
        return 1;
    }

    //------------------------------------------------------------------------------
    // (PHP helpers for Perl's 64-bit unsigned integer arithmetic on VarInt values)

    /** a 64-bit pattern (PHP int) as the value Perl would hold: int, or decimal string from 2**63 */
    private static function U64Value(int $u)
    {
        return $u < 0 ? sprintf('%u', $u) : $u;
    }

    /** VarInt value (int, decimal string from 2**63, float from 2**64) as a 64-bit pattern */
    private static function U64($val): int
    {
        if (is_int($val)) return $val;
        if (is_float($val)) return $val >= 0x7fffffffffffffff ? -1 : (int) $val;   // (Perl casts saturate)
        // 2**63 <= $val < 2**64: subtract 2**64 = 18446744073 * 1e9 + 709551616 in two parts
        $s = Perl::str($val);
        return (((int) substr($s, 0, -9)) - 18446744073) * 1000000000 + ((int) substr($s, -9)) - 709551616;
    }

    /** Perl: $val >= $int64sMin */
    private static function GE64sMin($val): bool
    {
        if (is_float($val)) return $val >= Vars::$int64sMin;
        if (is_int($val)) return false;
        return self::U64($val) >= -4294967296;  // (0xffffffff00000000 as a 64-bit pattern)
    }

    /** Perl: $val - $int64sMin - 4294967296 */
    private static function Minus64sMin($val)
    {
        if (is_float($val)) return $val - Vars::$int64sMin - 4294967296;
        return self::U64($val);     // (the signed 64-bit value)
    }

    /** Perl: $val > $intMax ? ($$dirInfo{Bit0} ? -int($val / 2) - 1 : $val / 2) : ($val & 1) ? -($val >> 1)-1 : ($val >> 1) */
    private static function Signed($val, $bit0)
    {
        if (is_float($val) and $val > Vars::$intMax) {
            // use double math (15 decimal digits precision)
            return $bit0 ? -Perl::int($val / 2) - 1 : $val / 2;
        }
        // use integer math
        $u = self::U64($val);
        $half = ($u >> 1) & PHP_INT_MAX;
        return ($u & 1) ? -$half - 1 : $half;
    }
}
