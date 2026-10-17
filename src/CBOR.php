<?php

//------------------------------------------------------------------------------
// File:         CBOR.pm
//
// Description:  Read CBOR format metadata
//
// Revisions:    2021-09-30 - P. Harvey Created
//
// References:   1) https://c2pa.org/public-draft/
//               2) https://datatracker.ietf.org/doc/html/rfc7049
//               3) https://www.iana.org/assignments/cbor-tags/cbor-tags.xhtml
//
// PHP port of Image::ExifTool::CBOR 1.04. Tag tables are generated into tables/CBOR.php,
// %cborType6 and %cborType7 into Vars\CBOR.
// A CBOR map is a string-keyed array with an '_ordered_keys_' list (like JSON objects, see
// src/Import.php), a byte string is a ScalarRef.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\CBOR as Vars;

final class CBOR
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Read CBOR value
    // Inputs: 0) ExifTool ref, 1) data ref, 2) position in data, 3) data end
    // Returns: 0) value, 1) error string, 2) new data position
    public static function ReadCBORValue(ExifTool $et, $dataPt, $pos, $end): array
    {
        if ($pos >= $end) return [null, 'Truncated CBOR data', $pos];
        $verbose = $et->OPTIONS['Verbose'];
        $indent = $et->INDENT;
        $dumpStart = $pos;
        $fmt = ExifTool::Get8u($dataPt, $pos++);
        $dat = $fmt & 0x1f;
        $num = $val = $err = $size = null;
        $fmt >>= 5;
        if ($dat < 24) {
            $num = $dat;
        } elseif ($dat == 31) {     // indefinite count (not used in C2PA)
            $num = -1;  // (flag for indefinite count)
            $et->VPrint(1, "{$et->INDENT} (indefinite count):\n");
        } else {
            $format = [24 => 'int8u', 25 => 'int16u', 26 => 'int32u', 27 => 'int64u'][$dat] ?? null;
            if (!$format) return [null, "Invalid CBOR integer type $dat", $pos];
            $size = ExifTool::FormatSize($format);
            if ($pos + $size > $end) return [null, 'Truncated CBOR integer value', $pos];
            $num = ExifTool::ReadValue($dataPt, $pos, $format, 1, $size);
            $pos += $size;
        }
        $pre = '';
        if ($et->cbor_pre !== null and $fmt != 6) {
            $pre = $et->cbor_pre;
            unset($et->cbor_pre);
        }
        if ($fmt == 0) {            // positive integer
            $val = $num;
            $et->VPrint(1, "{$et->INDENT} {$pre}int+: " . Perl::str($val) . "\n");
        } elseif ($fmt == 1) {      // negative integer
            $val = -1 * $num;
            $et->VPrint(1, "{$et->INDENT} {$pre}int-: " . Perl::str($val) . "\n");
        } elseif ($fmt == 2 or $fmt == 3) { // byte/UTF8 string
            if ($pos + $num > $end) return [null, 'Truncated CBOR string value', $pos];
            if ($num < 0) { // (should not happen in C2PA)
                $string = '';
                $et->INDENT .= '  ';
                for (;;) {
                    [$val, $err, $pos] = self::ReadCBORValue($et, $dataPt, $pos, $end);
                    if ($err) return [null, $err, $pos];
                    if ($val === null) break;   // hit the break?
                    // (note: strictly we should be checking that this was a string we read)
                    $string .= Perl::str($val);
                }
                $et->INDENT = $indent;
                return [$string, null, $pos];   // return concatenated byte/text string
            } else {
                $val = substr($dataPt, $pos, $num);
            }
            $pos += $num;
            if ($fmt == 2) {    // (byte string)
                $et->VPrint(1, "{$et->INDENT} {$pre}byte: <binary data " . strlen($val) . " bytes>\n");
                $val = new ScalarRef($val);     // use scalar reference for binary data
            } else {            // (text string)
                $val = $et->Decode($val, 'UTF8');
                $et->VPrint(1, "{$et->INDENT} {$pre}text: '{$val}'\n");
            }
        } elseif ($fmt == 4 or $fmt == 5) { // list/hash
            if ($fmt == 4) {
                $et->VPrint(1, "{$et->INDENT} {$pre}list: <$num elements>\n");
            } else {
                $et->VPrint(1, "{$et->INDENT} {$pre}hash: <$num pairs>\n");
                $num *= 2;
            }
            $et->INDENT .= '  ';
            $i = 0;
            $list = [];
            if ($verbose > 2) {
                ExifTool::HexDump($dataPt, $pos - $dumpStart,
                    Start: $dumpStart,
                    DataPos: $et->cbor_datapos,
                    Prefix: $et->INDENT,
                    Out: $et->Options('TextOut'),
                );
            }
            while ($num) {
                $et->cbor_pre = "$i) ";
                if ($fmt == 4) {
                    ++$i;
                } elseif ($num & 0x01) {
                    $et->cbor_pre = str_repeat(' ', strlen($et->cbor_pre));
                    ++$i;
                }
                [$val, $err, $pos] = self::ReadCBORValue($et, $dataPt, $pos, $end);
                if ($err) return [null, $err, $pos];
                if ($val === null) {
                    if (!($num < 0)) return [null, 'Unexpected list terminator', $pos];
                    break;
                }
                $list[] = $val;
                --$num;
            }
            $dumpStart = $pos;
            $et->INDENT = $indent;
            if ($fmt == 5) {
                $hash = ['_ordered_keys_' => []];
                for ($i = 0; $i < count($list) - 1; $i += 2) {
                    $key = Perl::str($list[$i]);
                    $hash[$key] = $list[$i + 1];
                    $hash['_ordered_keys_'][] = $key;   // save ordered list of keys
                }
                $val = $hash;
            } else {
                $val = $list;
            }
        } elseif ($fmt == 6) {      // optional tag
            if ($verbose) {
                $str = "$num (" . ((Vars::$cborType6[$num] ?? null) ?: 'unknown') . ')';
                $spc = $et->cbor_pre ? str_repeat(' ', strlen($et->cbor_pre)) : '';
                $et->VPrint(1, "{$et->INDENT} $spc<CBOR optional type $str>\n");
                if ($verbose > 2) {
                    ExifTool::HexDump($dataPt, $pos - $dumpStart,
                        Start: $dumpStart,
                        DataPos: $et->cbor_datapos,
                        Prefix: $et->INDENT . '  ',
                        Out: $et->Options('TextOut'),
                    );
                }
            }
            // read next value (note: in the case of multiple tags,
            // this nesting will apply the tags in the correct order)
            [$val, $err, $pos] = self::ReadCBORValue($et, $dataPt, $pos, $end);
            if ($err) return [null, $err, $pos];
            $dumpStart = $pos;
            // convert some values according to the optional tag number (untested)
            if ($num == 0 and !is_array($val) and !is_object($val)) {   // date/time string
                ExifTool::RequireModule('Image::ExifTool::XMP');
                $val = XMP::ConvertXMPDate($val);
            } elseif ($num == 1 and !is_array($val) and !is_object($val)) { // epoch-based date/time
                if (ExifTool::IsFloat($val)) {
                    $dec = (Perl::num($val) == Perl::int($val)) ? null : 6;
                    $val = ExifTool::ConvertUnixTime($val, 1, $dec);
                }
            } elseif (($num == 2 or $num == 3) and $val instanceof ScalarRef) { // pos/neg bignum
                $big = 0;
                $len = strlen(Perl::str($val->value));
                for ($j = 0; $j < $len; ++$j) $big = 256 * $big + ExifTool::Get8u($val->value, $j);
                $val = $num == 2 ? $big : -$big;
            } elseif (($num == 4 or $num == 5) and // decimal fraction or bigfloat
                is_array($val) and array_is_list($val) and count($val) == 2 and
                ExifTool::IsInt($val[0]) and ExifTool::IsInt($val[1]))
            {
                $val = Perl::num($val[1]) * fpow($num == 4 ? 10 : 2, Perl::num($val[0]));
            }
        } elseif ($fmt == 7) {
            if ($dat == 31) {
                $val = null;    // "break" = end of indefinite array/hash (not used in C2PA)
            } elseif ($dat < 24) {
                $val = Vars::$cborType7[$num] ?? null;
                if ($val === null) $val = 'Unknown (' . Perl::str($val) . ')';
            } elseif ($dat == 25) { // half-precision float
                $exp = ($num >> 10) & 0x1f;
                $mant = $num & 0x3ff;
                if ($exp == 0) {
                    $val = fpow($mant, -24);
                    if ($num & 0x8000) $val *= -1;
                } elseif (true) {   // (Perl: "exp != 31" is exp($_) with $_ undef, so always true)
                    $val = fpow($mant + 1024, $exp - 25);
                    if ($num & 0x8000) $val *= -1;
                } else {
                    $val = $mant == 0 ? '<inf>' : '<nan>';
                }
            } elseif ($dat == 26) { // float
                $val = ExifTool::GetFloat($dataPt, $pos - $size);
            } elseif ($dat == 27) { // double
                $val = ExifTool::GetDouble($dataPt, $pos - $size);
            } else {
                return [null, "Invalid CBOR type 7 variant $num", $pos];
            }
            $et->VPrint(1, "{$et->INDENT} {$pre}typ7: " . ($val !== null ? Perl::str($val) : '<break>') . "\n");
        } else {
            return [null, "Unknown CBOR format $fmt", $pos];
        }
        if ($verbose > 2) {
            ExifTool::HexDump($dataPt, $pos - $dumpStart,
                Start: $dumpStart,
                DataPos: $et->cbor_datapos,
                Prefix: $et->INDENT . '  ',
                MaxLen: $verbose < 5 ? ($verbose == 3 ? 96 : 2048) : null,
                Out: $et->Options('TextOut'),
            );
        }
        return [$val, $err, $pos];
    }

    //------------------------------------------------------------------------------
    // Read CBOR box
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCBOR(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $pos = $dirInfo['DirStart'] ?? 0;
        $end = $pos + $dirInfo['DirLen'];
        $val = $err = null;

        $et->VerboseDir('CBOR', null, $dirInfo['DirLen']);
        ExifTool::SetByteOrder('MM');

        $et->cbor_datapos = Perl::num($dirInfo['DataPos'] ?? 0) + Perl::num($dirInfo['Base'] ?? 0);

        while ($pos < $end) {
            [$val, $err, $pos] = self::ReadCBORValue($et, $dataPt, $pos, $end);
            if ($err) {
                $et->Warn($err);
                break;
            }
            if (is_array($val) and !array_is_list($val)) {
                foreach ($val['_ordered_keys_'] as $tag) {
                    JSON::ProcessTag($et, $tagTablePtr, $tag, $val[$tag] ?? null);
                }
            } elseif (is_array($val)) {
                for ($i = 0; $i < count($val); ++$i) {
                    JSON::ProcessTag($et, $tagTablePtr, "Item$i", $val[$i]);
                }
            } elseif (Perl::str($val) === '0') {
                $et->VPrint(1, "{$et->INDENT} <CBOR end>\n");
                break;  // (treat as padding)
            } else {
                $et->VPrint(1, "{$et->INDENT} Unknown value: " . Perl::str($val) . "\n");
            }
        }
        return 1;
    }
}
