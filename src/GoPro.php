<?php

//------------------------------------------------------------------------------
// File:         GoPro.pm
//
// Description:  Read information from GoPro videos
//
// Revisions:    2018/01/12 - P. Harvey Created
//
// References:   1) https://github.com/gopro/gpmf-parser
//               2) https://github.com/stilldavid/gopro-utils
//               3) https://github.com/gopro/gpmf-parser
//
// PHP port of Image::ExifTool::GoPro 1.15. Tag tables are generated into tables/GoPro.php,
// %goProFmt and %goProSize into Vars\GoPro.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GoPro
{
    public static $VERSION = '1.15';

    //------------------------------------------------------------------------------
    // Convert system time to date/time string
    // Inputs: 0) system time value, 1) ExifTool ref
    // Returns: EXIF-format date/time string with milliseconds
    public static function ConvertSystemTime($val, $et)
    {
        $s = $et->SystemTimeList;
        if (!$s) return '<uncalibrated>';
        if (!$et->SystemTimeListSorted) {
            usort($s, static fn ($a, $b) => Perl::num($a[0]) <=> Perl::num($b[0]));
            $et->SystemTimeList = $s;
            $et->SystemTimeListSorted = 1;
        }
        [$i, $j] = [0, count($s) - 1];
        // perform binary search to find this system time value
        while ($j - $i > 1) {
            $t = Perl::int(($i + $j) / 2);
            if (Perl::num($val) < Perl::num($s[$t][0])) $j = $t; else $i = $t;
        }
        if ($i == $j or Perl::num($s[$j][0]) == Perl::num($s[$i][0])) {
            $val = $s[$i][1];
        } else {
            // interpolate between values
            $val = $s[$i][1] + ($s[$j][1] - $s[$i][1]) * ($val - $s[$i][0]) / ($s[$j][0] - $s[$i][0]);
        }
        // (a bit tricky to remove fractional seconds then add them back again after
        //  the date/time conversion while avoiding round-off errors which could
        //  put the seconds out by 1...)
        preg_match('/^(\d+)(\.\d+)/', Perl::str($val), $m);
        [$t, $f] = [$m[1] ?? null, $m[2] ?? null];
        return ExifTool::ConvertUnixTime($t, $et->OPTIONS['QuickTimeUTC']) . $f;
    }

    //------------------------------------------------------------------------------
    // Scale values by last 'SCAL' constants
    // Inputs: 0) value or list of values, 1) string of scale factors
    // Returns: nothing, but updates values
    public static function ScaleValues(&$val, $scl)
    {
        if (!($val and $scl)) return;
        $sclA = Perl::split(' ', Perl::str($scl));  // (@scl)
        if (!$sclA) return;
        $scaled = [];
        $v = (is_array($val) and array_is_list($val)) ? $val : [$val];
        foreach ($v as $vv) {
            $a = Perl::split(' ', Perl::str($vv));
            foreach (array_keys($a) as $k) $a[$k] = Perl::num($a[$k]) / Perl::num($sclA[$k % count($sclA)]);
            $scaled[] = implode(' ', array_map(Perl::str(...), $a));
        }
        $val = count($scaled) > 1 ? $scaled : $scaled[0];
    }

    //------------------------------------------------------------------------------
    // Add units to values for human-readable output
    // Inputs: 0) ExifTool ref, 1) value, 2) tag key
    // Returns: converted value
    public static function AddUnits($et, $val, $tag)
    {
        $tag = Perl::str($tag);
        if ($et and !empty($et->TAG_EXTRA[$tag]['Units'])) {
            $u = $et->TAG_EXTRA[$tag]['Units'];
            if (!(is_array($u) and array_is_list($u))) $u = [$u];
            $a = Perl::split(' ', Perl::str($val));
            if (count($u) == count($a)) {
                for ($i = 0; $i < count($a); ++$i) {
                    if ($u[$i]) $a[$i] .= ' ' . Perl::str($u[$i]);
                }
                $val = implode(' ', $a);
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Process string of values (or array of strings) to extract as separate tags
    // Inputs: 0) ExifTool object ref, 1) directory information ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessString(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $list = (is_array($dataPt) and array_is_list($dataPt)) ? $dataPt : [$dataPt];
        $et->VerboseDir('GoPro structure');
        $docNum = $et->DOC_NUM;
        $subDoc = 0;
        foreach ($list as $string) {
            $vals = Perl::split(' ', Perl::str($string));   // (@val)
            $i = 0;
            foreach ($vals as $val) {
                $et->HandleTag($tagTablePtr, $i, $val);
                if ($tagTablePtr[++$i]) continue;
                // increment subdoc for records stored as string of values (eg. GPS5)
                $i = 0;
                ++$subDoc;
                $et->DOC_NUM = Perl::str($docNum) . "-$subDoc";
            }
            if ($i) {
                // increment subdoc for records stored as array of strings (eg. GPS9)
                ++$subDoc;
                $et->DOC_NUM = Perl::str($docNum) . "-$subDoc";
            }
        }
        $et->DOC_NUM = $docNum;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process "GP\x06\0" records in MP4 'mdat' atom
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref (RAF and DirLen)
    // Returns: size of GoPro record, or 0 on error
    public static function ProcessGP6(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $len = $dirInfo['DirLen'];
        $buff = null;
        while ($len > 16) {
            if ($raf->Read($buff, 16) != 16) break;
            [$tag, $size] = Perl::unpack('a4N', $buff);
            if ($size + 16 > $len or !preg_match('/^GP..\0/s', $buff)) break;
            if ($raf->Read($buff, $size) != $size) break;
            if (preg_match('/^DEVC/', $buff)) {
                $et->DOC_NUM = ++$et->DOC_COUNT;
                $tagTbl = ExifTool::GetTagTable('Image::ExifTool::GoPro::GPMF');
                $di = ['DataPt' => $buff, 'DataPos' => $raf->Tell() - $size];
                self::ProcessGoPro($et, $di, $tagTbl);
            }
            $len -= $size + 16;
        }
        $et->DOC_NUM = null;
        return $dirInfo['DirLen'] - $len;
    }

    //------------------------------------------------------------------------------
    // Process GoPro metadata (gpmd samples, GPMF box, or APP6) (ref PH/1/2)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // - with hack to check for encrypted text in gpmd data (Rove Stealth 4K)
    public static function ProcessGoPro(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $base = $dirInfo['Base'] ?? null;
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirEnd = $pos + (($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $pos));
        $verbose = $et->Options('Verbose');
        $unknown = $verbose ?: $et->Options('Unknown');
        $size = $type = $unit = $scal = $setGroup0 = null;
        $goProFmt = Vars\GoPro::$goProFmt;
        $goProSize = Vars\GoPro::$goProSize;

        if ($verbose) $et->VerboseDir(($dirInfo['DirName'] ?? null) ?: 'GPMF', null, $dirEnd - $pos);
        $et->FoundEmbedded = 1;
        if ($pos) {
            $parent = $dirInfo['Parent'] ?? null;
            if ($parent and $parent === 'APP6') $setGroup0 = $et->SET_GROUP0 = 'APP6';
        } else {
            // set group0 to "QuickTime" unless group1 is being changed (to Track#)
            if (!$et->SET_GROUP1) $setGroup0 = $et->SET_GROUP0 = 'QuickTime';
        }

        for (; $pos + 8 <= $dirEnd; $pos += ($size + 3) & 0xfffffffc) {
            // (PHP: missing values of truncated data are '' / 0, like Perl's undef)
            [$tag, $fmt, $len, $count] = Perl::unpack("x{$pos}a4CCn", $dataPt) + ['', 0, 0, 0];
            if (preg_match('/[^-_a-zA-Z0-9 ]/', $tag)) {
                if ($tag !== "\0\0\0\0") $et->Warn('Unrecognized GoPro record');
                break;
            }
            $size = $len * $count;
            $pos += 8;
            if ($pos + $size > $dirEnd) {
                $et->Warn('Truncated GoPro record');
                break;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tag === "\0\0\0\0") break;     // stop at null tag
            if (!($size or $verbose)) continue; // don't save empty values unless verbose
            $format = ($goProFmt[$fmt] ?? null) ?: 'undef';
            $val = $i = $j = $p = null;
            $v = [];
            if ($fmt == 0x3f and $type !== null) {
                // decode structure with format given by previous 'TYPE'
                $typeStr = Perl::str($type);
                for ($i = 0; $i < $count; ++$i) {
                    $s = [];
                    $l = null;
                    for ($j = 0, $p = 0; $j < strlen($typeStr); ++$j, $p += $l) {
                        $b = ExifTool::Get8u($typeStr, $j);
                        $f = $goProFmt[$b] ?? null;
                        if (!$f) break;
                        $l = ($goProSize[$b] ?? null) ?: ExifTool::FormatSize($f);
                        if (!$l) break;
                        if ($p + $l > $len) break;
                        $sv = ExifTool::ReadValue($dataPt, $pos + $i * $len + $p, $f, null, $l);
                        if ($sv === null) break;
                        $s[] = $sv;
                    }
                    if ($s) $v[] = implode(' ', array_map(Perl::str(...), $s));
                }
                $val = count($v) > 1 ? $v : ($v[0] ?? null);
            } elseif (($format === 'undef' or $format === 'string') and $count > 1 and $len > 1) {
                // unpack multiple undef/string values as a list
                $a = $format === 'undef' ? 'a' : 'A';
                $val = Perl::unpack("x{$pos}" . str_repeat("$a$len", $count), $dataPt);
            } else {
                $val = ExifTool::ReadValue($dataPt, $pos, $format, null, $size);
            }
            // save TYPE, UNIT/SIUN and SCAL values for later
            if ($tag === 'TYPE') $type = $val;
            if ($tag === 'UNIT' or $tag === 'SIUN') $unit = $val;
            if ($tag === 'SCAL') $scal = $val;

            if (!$tagInfo) {
                if (!$unknown) continue;
                $name = QuickTime::PrintableTagID($tag);
                $tagInfo = ['Name' => "GoPro_$name", 'Description' => "GoPro $name", 'Unknown' => 1];
                if (!$fmt) $tagInfo['SubDirectory'] = ['TagTable' => 'Image::ExifTool::GoPro::GPMF'];
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            // apply scaling if available to last tag in this container
            if ($scal and $tag !== 'SCAL' and $pos + $size + 3 >= $dirEnd) self::ScaleValues($val, $scal);
            $key = $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt: $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Base: $base,
                Start: $pos,
                Size: $size,
                TagInfo: $tagInfo,
                Format: $format,
                Extra: $verbose ? ", type='" . ($fmt ? chr($fmt) : '\0') . "' size=$len count=$count" : null,
            );
            // save units for adding in print conversion if specified
            if ($tagInfo->AddUnits and $key) $et->TAG_EXTRA[$key]['Units'] = $unit;
        }
        if ($setGroup0) $et->SET_GROUP0 = null;
        return 1;
    }
}
