<?php

//------------------------------------------------------------------------------
// File:         Exif.pm
//
// Description:  Read EXIF/TIFF meta information
//
// Revisions:    11/25/2003 - P. Harvey Created
//               02/06/2004 - P. Harvey Moved processing functions from ExifTool
//               03/19/2004 - P. Harvey Check PreviewImage for validity
//               11/11/2004 - P. Harvey Split off maker notes into MakerNotes.pm
//               12/13/2004 - P. Harvey Added AUTOLOAD to load write routines
//
// PHP port of Image::ExifTool::Exif 4.65 (reading only). Tag tables and package
// variables (@formatSize, %formatNumber, %compression...) are generated into
// tables/Exif.php and Vars\Exif. The read-side subs of WriteExif.pl (ValidateIFD,
// GetOffList, ProcessTiffIFD...) are at the end of this class.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Exif
{
    // size limit for loading binary data block into memory
    public static function BINARY_DATA_LIMIT() { return 10 * 1024 * 1024; }

    /**
     * Perl `eval $code` of a table expression (Start, Base, OffsetPt, Validate, IsOffset,
     * ChangeBase, WrongBase): a constant is returned as-is, and a failing eval gives undef
     * like Perl (no PHP twin, port helper).
     */
    public static function EvalCode(mixed $code, mixed ...$args): mixed
    {
        if (!$code instanceof \Closure) {
            return $code;
        }
        try {
            return $code(...$args);
        } catch (\Throwable) {
            return null;
        }
    }

    //------------------------------------------------------------------------------
    // Identify RAW file type for some TIFF-based formats using Compression value
    // Inputs: 0) ExifTool object reference, 1) Compression value
    // - sets TIFF_TYPE and FileType if identified
    public static function IdentifyRawFile(ExifTool $et, $comp)
    {
        if ($et->FILE_TYPE === 'TIFF' and !$et->IdentifiedRawFile) {
            $compression = Vars\Exif::$compression[Perl::str($comp)] ?? null;
            if ($compression and preg_match('/^\w+ ([A-Z]{3}) Compressed$/', $compression, $m)) {
                $et->OverrideFileType($et->TIFF_TYPE = $m[1]);
                $et->IdentifiedRawFile = 1;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Calculate LV (Light Value)
    // Inputs: 0) Aperture, 1) ShutterSpeed, 2) ISO
    // Returns: LV value (and converts input values to floating point if necessary)
    public static function CalculateLV(...$args)
    {
        // do validity checks on arguments
        if (count($args) < 3) return null;
        foreach ($args as &$_) {
            if (!($_ and preg_match('/([+-]?(?=\d|\.\d)\d*(\.\d*)?([Ee]([+-]?\d+))?)/', Perl::str($_), $m) and Perl::num($m[1]) > 0)) {
                return null;
            }
            $_ = Perl::num($m[1]);    // extract float from any other garbage
        }
        unset($_);
        // (A light value of 0 is defined as f/1.0 at 1 second with ISO 100)
        return log($args[0] * $args[0] * 100 / ($args[1] * $args[2])) / log(2);
    }

    //------------------------------------------------------------------------------
    // Calculate scale factor for 35mm effective focal length (ref 26/PH)
    // Inputs: 0) ExifTool object ref
    //         1) Focal length
    //         2) Focal length in 35mm format
    //         3) Canon digital zoom factor
    //         4) Focal plane diagonal size (in mm)
    //         5) Sensor size (X and Y in mm)
    //         6/7) Focal plane X/Y size (in mm)
    //         8) focal plane resolution units (1=None,2=inches,3=cm,4=mm,5=um)
    //         9/10) Focal plane X/Y resolution
    //         11/12,13/14...) Image width/height in order of precedence (first valid pair is used)
    // Returns: 35mm conversion factor (or undefined if it can't be calculated)
    public static function CalcScaleFactor35efl(ExifTool $et, ...$args)
    {
        $res = $args[7] ?? null;    // save resolution units (in case they have been converted to string)
        $sensXY = $args[4] ?? null;
        ExifTool::ToFloat(...$args);
        $focal = array_shift($args);
        $foc35 = array_shift($args);

        if ($focal and $foc35) return $foc35 / $focal;

        $digz = array_shift($args) ?: 1;
        $diag = array_shift($args);
        $sens = array_shift($args);
        // calculate Canon sensor size using a dedicated algorithm
        if ($et->Make === 'Canon') {
            ExifTool::RequireModule('Image::ExifTool::Canon');
            $canonDiag = Canon::CalcSensorDiag($et);
            if ($canonDiag) $diag = $canonDiag;
        }
        if (!($diag and ExifTool::IsFloat($diag))) {
            if ($sens and preg_match('/ (\d+(\.?\d*)?)$/', Perl::str($sensXY), $m)) {
                $diag = sqrt($sens * $sens + $m[1] * $m[1]);
            } else {
                $diag = null;
                $xsize = array_shift($args);
                $ysize = array_shift($args);
                if ($xsize and $ysize) {
                    // validate by checking aspect ratio because FocalPlaneX/YSize is not reliable
                    $a = $xsize / $ysize;
                    if (abs($a - 1.3333) < .1 or abs($a - 1.5) < .1) {
                        $diag = sqrt($xsize * $xsize + $ysize * $ysize);
                    }
                }
            }
            if (!$diag) {
                // get number of mm in units (assume inches unless otherwise specified)
                $lkup = [3 => 10, 4 => 1, 5 => 0.001, 'cm' => 10, 'mm' => 1, 'um' => 0.001];
                $units = ($lkup[Perl::str(array_shift($args) ?: ($res ?: ''))] ?? null) ?: 25.4;
                $x_res = array_shift($args);
                if (!$x_res) return null;
                $y_res = array_shift($args) ?: $x_res;
                if (!(ExifTool::IsFloat($x_res) and $x_res != 0)) return null;
                if (!(ExifTool::IsFloat($y_res) and $y_res != 0)) return null;
                $w = $h = null;
                for (;;) {
                    if (count($args) < 2) return null;
                    $w = array_shift($args);
                    $h = array_shift($args);
                    if (!($w and $h)) continue;
                    $a = $w / $h;
                    if ($a > 0.5 and $a < 2) break; // stop if we get a reasonable value
                }
                // calculate focal plane size in mm
                $w *= $units / $x_res;
                $h *= $units / $y_res;
                $diag = sqrt($w * $w + $h * $h);
                // make sure size is reasonable
                if (!($diag > 1 and $diag < 100)) return null;
            }
        }
        return sqrt(36 * 36 + 24 * 24) * $digz / $diag;
    }

    //------------------------------------------------------------------------------
    // Print exposure compensation fraction
    public static function PrintFraction($val)
    {
        $str = null;
        if ($val !== null) {
            $val = Perl::num($val) * 1.00001;    // avoid round-off errors
            if (!$val) {
                $str = '0';
            } elseif (Perl::int($val) / $val > 0.999) {
                $str = Perl::sprintf('%+d', Perl::int($val));
            } elseif (Perl::int($val * 2) / ($val * 2) > 0.999) {
                $str = Perl::sprintf('%+d/2', Perl::int($val * 2));
            } elseif (Perl::int($val * 3) / ($val * 3) > 0.999) {
                $str = Perl::sprintf('%+d/3', Perl::int($val * 3));
            } else {
                $str = Perl::sprintf('%+.3g', $val);
            }
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Convert fraction or number to floating point value (or 'undef' or 'inf')
    public static function ConvertFraction($val)
    {
        if (preg_match('{([-+]?\d+)/(\d+)}', Perl::str($val), $m)) {
            $val = $m[2] ? Perl::num($m[1]) / Perl::num($m[2]) : ($m[1] ? 'inf' : 'undef');
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Convert EXIF text to something readable
    // Inputs: 0) ExifTool object reference, 1) EXIF text,
    //         2) [optional] 1 to apply CharsetEXIF to ASCII text,
    //         3) tag name for warning message (may be argument 2)
    // Returns: text encoded according to Charset option (with trailing spaces removed)
    public static function ConvertExifText(ExifTool $et, $val, $asciiFlex = null, $tag = null)
    {
        if (strlen($val) < 8) return $val;
        $id = substr($val, 0, 8);
        $str = substr($val, 8);
        $type = null;

        unset($et->WrongByteOrder);
        if (($et->OPTIONS['Validate'] ?? null) and preg_match('/^(ASCII|UNICODE|JIS)?\0* \0*$/', $id, $m)) {
            $et->Warn((($m[1] ?? '') ?: 'Undefined') . ' text header' . ($tag ? " for $tag" : '') . ' has spaces instead of nulls');
        }
        // Note: allow spaces instead of nulls in the ID codes because
        // it is fairly common for camera manufacturers to get this wrong
        // (also handle Canon ZoomBrowser EX 4.5 null followed by 7 bytes of garbage)
        if (preg_match('/^(ASCII)?(\0|[\0 ]+$)/', $id)) {
            // truncate at null terminator (shouldn't have a null based on the
            // EXIF spec, but it seems that few people actually read the spec)
            $str = preg_replace('/\0.*/s', '', $str, 1);
            // allow ASCII text to contain any other specified encoding
            if ($asciiFlex and Perl::str($asciiFlex) === '1') {
                $enc = $et->Options('CharsetEXIF');
                if ($enc) $str = $et->Decode($str, $enc);
            }
        // by the EXIF spec, the following string should be "UNICODE\0", but
        // apparently Kodak sometimes uses "Unicode\0" in the APP3 "Meta" information.
        // However, unfortunately Ricoh uses "Unicode\0" in the RR30 EXIF UserComment
        // when the text is actually ASCII, so only recognize uppercase "UNICODE\0".
        } elseif (preg_match('/^(UNICODE)[\0 ]$/', $id, $m)) {
            $type = $m[1];
            // MicrosoftPhoto writes as little-endian even in big-endian EXIF,
            // so we must guess at the true byte ordering
            $str = $et->Decode($str, 'UTF16', 'Unknown');
        } elseif (preg_match('/^(JIS)[\0 ]{5}$/', $id, $m)) {
            $type = $m[1];
            $str = $et->Decode($str, 'JIS', 'Unknown');
        } else {
            if ($asciiFlex and Perl::str($asciiFlex) !== '1') $tag = $asciiFlex;
            $et->Warn('Invalid EXIF text encoding' . ($tag ? " for $tag" : ''));
            $str = $id . $str;
        }
        if ($et->WrongByteOrder and ($et->OPTIONS['Validate'] ?? null)) {
            $et->Warn('Wrong byte order for EXIF' . ($tag ? " $tag" : '') .
                      ($type ? " $type" : '') . ' text');
        }
        $str = preg_replace('/ +$/', '', $str, 1);    // trim trailing blanks
        return $str;
    }

    //------------------------------------------------------------------------------
    // Print conversion for SpatialFrequencyResponse
    public static function PrintSFR($val)
    {
        if (!(strlen($val) > 4)) return $val;
        [$n, $m] = [ExifTool::Get16u($val, 0), ExifTool::Get16u($val, 2)];
        $cols = Perl::split('/\0/', substr($val, 4), $n + 1);
        $pos = strlen($val) - 8 * $n * $m;
        if (!(count($cols) == $n + 1 and $pos >= 4)) return $val;
        array_pop($cols);
        for ($i = 0; $i < $n; ++$i) {
            $rows = [];
            for ($j = 0; $j < $m; ++$j) {
                $rows[] = Perl::str(ExifTool::GetRational64u($val, $pos + 8 * ($i + $j * $n)));
            }
            $cols[$i] .= '=' . implode(',', $rows) . '';
        }
        return implode('; ', $cols);
    }

    //------------------------------------------------------------------------------
    // Print numerical parameter value (with sign, or 'Normal' for zero)
    // Inputs: 0) value, 1) flag for inverse conversion, 2) conversion hash reference
    public static function PrintParameter($val, $inv = null, $conv = null)
    {
        if ($inv) return $val;
        if (Perl::num($val) > 0) {
            if (Perl::num($val) > 0xfff0) {    // a negative value in disguise?
                $val = Perl::num($val) - 0x10000;
            } else {
                $val = '+' . Perl::str($val);
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Calculate Red/BlueBalance
    // Inputs: 0) 0=red, 1=blue, 1-8) WB_RGGB/RGBG/RBGG/GRBG/GRGB/RGB/GRB/RBLevels,
    //         8) red or blue level, 9) green level
    public static function RedBlueBalance($blue, ...$args)
    {
        $rggbLookup = Vars\Exif::$rggbLookup;
        $val = null;
        for ($i = 0; $i < count($rggbLookup); ++$i) {
            $levels = array_shift($args);
            if (!$levels) continue;
            $levelList = Perl::split(' ', $levels);   // (Perl @levels)
            if (count($levelList) < 2) continue;
            $lookup = $rggbLookup[$i];
            $g = $lookup[1];    // get green level or index
            if ($g < 4) {
                if (count($levelList) < 3) continue;
                $g = (Perl::num($levelList[$g]) + Perl::num($levelList[$lookup[2]])) / 2;
                if (!$g) continue;
            } elseif (Perl::num($levelList[$lookup[$blue * 3]] ?? null) < 4) {
                $g = 1; // Some Nikon cameras use a scaling factor of 1 (E5700)
            }
            $val = Perl::num($levelList[$lookup[$blue * 3]] ?? null) / $g;
            break;
        }
        if ($val === null and (($args[0] ?? null) and ($args[1] ?? null))) {
            $val = Perl::num($args[0]) / Perl::num($args[1]);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Print exposure time as a fraction
    public static function PrintExposureTime($secs)
    {
        if (!ExifTool::IsFloat($secs)) return $secs;
        if (Perl::num($secs) < 0.25001 and Perl::num($secs) > 0) {
            return Perl::sprintf('1/%d', Perl::int(0.5 + 1 / Perl::num($secs)));
        }
        $_ = Perl::sprintf('%.1f', $secs);
        $_ = preg_replace('/\.0$/', '', $_, 1);
        return $_;
    }

    //------------------------------------------------------------------------------
    // Print FNumber
    public static function PrintFNumber($val)
    {
        if (ExifTool::IsFloat($val) and Perl::num($val) > 0) {
            // round to 1 decimal place, or 2 for values < 1.0
            $val = Perl::sprintf(Perl::num($val) < 1 ? '%.2f' : '%.1f', $val);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Decode raw CFAPattern value
    // Inputs: 0) ExifTool ref, 1) binary value
    // Returns: string of numbers
    public static function DecodeCFAPattern(ExifTool $self, $val)
    {
        // some panasonic cameras (SV-AS3, SV-AS30) write this in ascii (very odd)
        if (preg_match('/^[0-6]+$/', $val)) {
            $self->Warn('Incorrectly formatted CFAPattern', 1);
            $val = strtr($val, '0123456', "\x00\x01\x02\x03\x04\x05\x06");
        }
        if (!(strlen($val) >= 4)) return $val;
        $a = Perl::unpack(ExifTool::GetByteOrder() === 'II' ? 'v2C*' : 'n2C*', $val);
        $end = 2 + $a[0] * $a[1];
        if ($end > count($a)) {
            // try swapping byte order (I have seen this order different than in EXIF)
            [$x, $y] = Perl::unpack('n2', Perl::pack('v2', $a[0], $a[1]));
            if (count($a) < 2 + $x * $y) {
                $self->Warn('Invalid CFAPattern', 1);
            } else {
                [$a[0], $a[1]] = [$x, $y];
                // (can't technically be wrong because the order isn't well defined by the EXIF spec)
                // $self->Warn('Wrong byte order for CFAPattern');
            }
        }
        return implode(' ', $a);
    }

    //------------------------------------------------------------------------------
    // Print CFA Pattern
    public static function PrintCFAPattern($val)
    {
        $a = Perl::split(' ', $val);
        if (!(count($a) >= 2)) return '<truncated data>';
        if (!($a[0] and $a[1])) return '<zero pattern size>';
        $end = 2 + Perl::num($a[0]) * Perl::num($a[1]);
        if ($end > count($a)) return '<invalid pattern size>';
        $cfaColor = ['Red', 'Green', 'Blue', 'Cyan', 'Magenta', 'Yellow', 'White'];
        [$pos, $rtnVal] = [2, '['];
        for (;;) {
            $rtnVal .= ($cfaColor[Perl::int($a[$pos])] ?? null) ?: 'Unknown';
            if (++$pos >= $end) break;
            if (Perl::mod($pos - 2, $a[1])) {
                $rtnVal .= ',';
                continue;
            }
            $rtnVal .= '][';
        }
        return $rtnVal . ']';
    }

    //------------------------------------------------------------------------------
    // Print Opcode List
    // Inputs: 0) value, 1) flag for inverse conversion, 2) conversion hash reference
    // Returns: converted value
    public static function PrintOpcode($val, $inv = null, $conv = null)
    {
        if ($inv) return null;   // (can't do inverse conversion)
        $val = $val instanceof ScalarRef ? $val->value : $val;  // ($$val)
        if (!(strlen($val) > 4)) return '';
        $num = Perl::unpack('N', $val)[0];
        $pos = 4;
        $ops = [];
        for ($i = 0; $i < $num; ++$i) {
            if (!($pos + 16 <= strlen($val))) {
                $ops[] = '<err>';
                break;
            }
            [$op, $ver, $flags, $len] = Perl::unpack("x{$pos}N4", $val);
            $ops[] = ($conv[$op] ?? null) ?: "[opcode $op]";
            $pos += 16 + $len;
        }
        return implode(', ', $ops);
    }

    //------------------------------------------------------------------------------
    // Print conversion for lens info
    // Inputs: 0) string of values (min focal, max focal, min F, max F)
    // Returns: string in the form "12-20mm f/3.8-4.5" or "50mm f/1.4"
    public static function PrintLensInfo($val)
    {
        $vals = Perl::split(' ', $val);
        if (count($vals) != 4) return $val;
        $c = 0;
        foreach ($vals as &$_) {
            if (ExifTool::IsFloat($_)) { ++$c; continue; }
            if ($_ === 'inf') { $_ = '?'; ++$c; continue; }
            if ($_ === 'undef') { $_ = '?'; ++$c; continue; }
        }
        unset($_);
        if ($c != 4) return $val;
        $val = $vals[0];
        // (the Pentax Q writes zero for upper value of fixed-focal-length lenses)
        if ($vals[1] and $vals[1] !== $vals[0]) $val .= "-$vals[1]";
        $val .= "mm f/$vals[2]";
        if ($vals[3] and $vals[3] !== $vals[2]) $val .= "-$vals[3]";
        return $val;
    }

    //------------------------------------------------------------------------------
    // Get lens info from lens model string
    // Inputs: 0) lens string, 1) flag to allow unknown "?" values
    // Returns: 0) min focal, 1) max focal, 2) min aperture, 3) max aperture
    // Notes: returns empty list if lens string could not be parsed
    public static function GetLensInfo($lens, $unk = null)
    {
        // extract focal length and aperture ranges for this lens
        $pat = '\d+(?:\.\d+)?';
        if ($unk) $pat .= '|\?';
        if (!preg_match("/($pat)(?:-($pat))?\\s*mm.*?(?:[fF]\\/?\\s*)($pat)(?:-($pat))?/", Perl::str($lens), $m)) return [];
        // ($1=short focal, $2=long focal, $3=max aperture wide, $4=max aperture tele)
        $a = [$m[1], ($m[2] ?? '') !== '' ? $m[2] : null, $m[3], ($m[4] ?? '') !== '' ? $m[4] : null];
        if (!$a[1]) $a[1] = $a[0];
        if (!$a[3]) $a[3] = $a[2];
        if ($unk) {
            foreach ($a as &$_) {
                if ($_ === '?') $_ = 'undef';
            }
            unset($_);
        }
        return $a;
    }

    //------------------------------------------------------------------------------
    // Match lens in list of possbilities based on value of LensModel
    // Inputs: 0) reference to list of possible models, 1) LensModel string
    // - updates list on return; guaranteed not to remove all list entries
    public static function MatchLensModel(array &$try, $lensModel)
    {
        if (count($try) > 1 and $lensModel) {
            $grep = static fn (string $re, array $list) => array_values(array_filter($list, static fn ($t) => preg_match($re, $t)));
            // filter by focal length
            if (preg_match('/((\d+-)?\d+mm)/', $lensModel, $m)) {
                $focal = $m[1];
                $filt = $grep("/$focal/", $try);
                if ($filt and count($filt) < count($try)) $try = $filt;
            }
            // filter by aperture
            if (count($try) > 1 and preg_match('{(?:F/?|1:)(\d+(\.\d+)?)}i', $lensModel, $m)) {
                $fnum = $m[1];
                $filt = $grep("{(F/?|1:)$fnum(\\b|[A-Z])}i", $try);
                if ($filt and count($filt) < count($try)) $try = $filt;
            }
            // filter by model version, and other lens parameters
            foreach (['I+', 'USM'] as $pat) {
                if (!(count($try) > 1 and preg_match("/\\b($pat)\\b/", $lensModel, $m))) continue;
                $val = $m[1];
                $filt = $grep("/\\b$val\\b/", $try);
                if ($filt and count($filt) < count($try)) $try = $filt;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Attempt to identify the specific lens if multiple lenses have the same LensType
    // Inputs: 0) ExifTool object ref, 1) LensType print value, 2) PrintConv hash ref,
    //         3) LensSpec print value, 4) LensType numerical value, 5) FocalLength,
    //         6) MaxAperture, 7) MaxApertureValue, 8) MinFocalLength, 9) MaxFocalLength,
    //         10) LensModel, 11) LensFocalRange, 12) LensSpec
    public static function PrintLensID(ExifTool $et, $lensTypePrt = null, $printConv = null, $lensSpecPrt = null,
        $lensType = null, $focalLength = null, $maxAperture = null, $maxApertureValue = null, $shortFocal = null,
        $longFocal = null, $lensModel = null, $lensFocalRange = null, $lensSpec = null)
    {
        // this logic relies on the LensType lookup:
        if ($lensType === null) return null;
        // get print conversion hash if necessary
        if (!$printConv) $printConv = ($et->TAG_INFO['LensType'] ?? null)?->PrintConv;
        // just copy LensType PrintConv value if it was a lens name
        // (Olympus or Panasonic -- just exclude things like Nikon and Leaf LensType)
        if (!is_array($printConv)) {
            if ($printConv instanceof ConvList and is_array($printConv->list[0] ?? null)) {
                $printConv = $printConv->list[0];
                $lensTypePrt = preg_replace('/;.*/', '', Perl::str($lensTypePrt), 1);
                $lensType = preg_replace('/ .*/', '', Perl::str($lensType), 1);
            } else {
                if (preg_match('/mm/', Perl::str($lensTypePrt))) return $lensTypePrt;
                $lensTypePrt = preg_replace('/(\d)\/F/', '$1mm F', Perl::str($lensTypePrt), 1, $n);
                if ($n) return $lensTypePrt;
                return null;
            }
        }
        // get LensSpec information if available (Sony)
        $sf0 = $lf0 = $sa0 = $la0 = null;
        if ($lensSpecPrt) {
            [$sf0, $lf0, $sa0, $la0] = self::GetLensInfo($lensSpecPrt) + [null, null, null, null];
            if (!$sa0) $sf0 = null; // (make sure aperture isn't zero)
        }
        // use MaxApertureValue if MaxAperture is not available
        if (!$maxAperture) $maxAperture = $maxApertureValue;
        if ($lensFocalRange and preg_match('/^(\d+)(?: (?:to )?(\d+))?$/', $lensFocalRange, $m)) {
            [$shortFocal, $longFocal] = [$m[1], ($m[2] ?? '') ?: $m[1]];
        }
        if ($et->Make === 'SONY') {
            if (Perl::str($lensType) === '65535') {
                // patch for manual lens (forum17379)
                if (($printConv[$lensType] ?? null) and !$focalLength and Perl::num($maxAperture) == 1) return $printConv[$lensType];
                // handle Sony E-type lenses when LensType2 isn't valid (NEX/ILCE models only)
                if (preg_match('/NEX|ILCE/', Perl::str($et->Model))) {
                    if (!Vars\Exif::$sonyEtype) {
                        $index = $i = $lens = null;
                        $did = [];
                        ExifTool::RequireModule('Image::ExifTool::Sony');
                        $keys = array_map('strval', array_keys(Vars\Sony::$sonyLensTypes2));
                        sort($keys, SORT_STRING);
                        foreach ($keys as $_) {
                            $lens = preg_replace('/ or .*/', '', Vars\Sony::$sonyLensTypes2[$_], 1);
                            if ($did[$lens] ?? null) continue;
                            [$i, $index] = $index ? ["65535.$index", $index + 1] : [65535, 1];
                            $did[Vars\Exif::$sonyEtype[$i] = $lens] = 1;
                        }
                    }
                    $printConv = Vars\Exif::$sonyEtype;
                }
            } elseif (Perl::num($lensType) != 0xff00) {
                // Patch for Metabones or other adapters on Sony E-mount cameras (ref Jos Roost)
                // Metabones Canon EF to E-mount adapters add 0xef00, 0xbc00 or 0x7700 to the
                // high byte for 2-byte Canon LensType values, so we need to adjust for these.
                // Offset 0xef00 is also used by Sigma MC-11, Fotodiox and Viltrox EF-E adapters.
                // Have to exclude A-mount Sigma Filtermatic with 'odd' LensType=0xff00.
                ExifTool::RequireModule('Image::ExifTool::Minolta');
                $lensType = Perl::num($lensType);
                if (Vars\Minolta::$metabonesID[Perl::int($lensType) & 0xff00] ?? null) {
                    $lensType -= ($lensType >= 0xef00 ? 0xef00 : ($lensType >= 0xbc00 ? 0xbc00 : 0x7700));
                    ExifTool::RequireModule('Image::ExifTool::Canon');
                    $printConv = Vars\Canon::$canonLensTypes;
                    if ($printConv[Perl::str($lensType)] ?? null) $lensTypePrt = $printConv[Perl::str($lensType)];
                // Test for Sigma MC-11 SA-E adapter with Sigma SA lens using 0x4900 offset.
                // (upper limit of test cuts off two highest Sigma lenses, but prevents
                // conflict with old Minolta 25xxx and higher ID's)
                } elseif ($lensType >= 0x4900 and $lensType <= 0x590a) {
                    ExifTool::RequireModule('Image::ExifTool::Sigma');
                    $lensType -= 0x4900;
                    $printConv = Vars\Sigma::$sigmaLensTypes;
                    if ($printConv[Perl::str($lensType)] ?? null) $lensTypePrt = $printConv[Perl::str($lensType)];
                }
            }
        // (Min/MaxFocalLength may report the current focal length for Tamron zoom lenses)
        } elseif ($shortFocal and $longFocal and (!$lensModel or !preg_match('/^TAMRON.*-\d+mm/', $lensModel))) {
            // Canon (and some other makes) include makernote information
            // which allows better lens identification
            ExifTool::RequireModule('Image::ExifTool::Canon');
            return Canon::PrintLensID($printConv, $lensType,
                        $shortFocal, $longFocal, $maxAperture, $lensModel);
        }
        $lens = $printConv[Perl::str($lensType)] ?? null;
        if (!$lens) return $lensModel ?: $lensTypePrt;
        if (!($printConv[Perl::str($lensType) . '.1'] ?? null)) return $lens;
        $lens = preg_replace('/ or .*/s', '', $lens, 1);    // remove everything after "or"
        // make list of all possible matching lenses
        $lenses = [$lens];
        for ($i = 1; $printConv[Perl::str($lensType) . ".$i"] ?? null; ++$i) {
            $lenses[] = $printConv[Perl::str($lensType) . ".$i"];
        }
        // attempt to determine actual lens
        $matches = $best = $user = [];
        $diff = null;
        foreach ($lenses as $lens) {
            if (Vars\ExifTool::$userLens[$lens] ?? null) $user[] = $lens;
            // sf = short focal
            // lf = long focal
            // sa = max aperture at short focal
            // la = max aperture at long focal
            [$sf, $lf, $sa, $la] = self::GetLensInfo($lens) + [null, null, null, null];
            if (!$sf) continue;
            [$sf, $lf, $sa, $la] = [Perl::num($sf), Perl::num($lf), Perl::num($sa), Perl::num($la)];
            // check against LensSpec parameters if available
            if ($sf0) {
                if (abs($sf - $sf0) > 0.5 or abs($sa - $sa0) > 0.15 or
                    abs($lf - $lf0) > 0.5 or abs($la - $la0) > 0.15) continue;
                // the basic parameters match, but also check against additional lens features:
                // for Sony A and E lenses, the full LensSpec string should match with end of LensType,
                // excluding any part between () at the end, and preceded by a space (the space
                // ensures that e.g. Zeiss Loxia 21mm having LensSpec "E 21mm F2.8" will not be
                // identified as "Sony FE 21mm F2.8 (SEL28F20 + SEL075UWC)")
                if ($lensSpecPrt and preg_match('/ ' . preg_quote($lensSpecPrt, '/') . '( \(| GM$|$)/', $lens)) {
                    $best = [$lens];
                    break;
                }
                // exactly-matching Sony lens should have been found above, so only add non-Sony lenses
                if (!preg_match('/^Sony /', $lens)) $best[] = $lens;
                continue;
            }
            // adjust focal length and aperture if teleconverter is attached (Minolta)
            if (preg_match('/ \+ .*? (\d+(\.\d+)?)x( |$)/', $lens, $m)) {
                $sf *= $m[1];  $lf *= $m[1];
                $sa *= $m[1];  $la *= $m[1];
            }
            // see if we can rule out this lens using FocalLength and MaxAperture
            if ($focalLength) {
                if (Perl::num($focalLength) < $sf - 0.5) continue;
                if (Perl::num($focalLength) > $lf + 0.5) continue;
            }
            if ($maxAperture) {
                // it seems that most manufacturers set MaxAperture and MaxApertureValue
                // to the maximum aperture (smallest F number) for the current focal length
                // of the lens, so assume that MaxAperture varies with focal length and find
                // the closest match (this is somewhat contrary to the EXIF specification which
                // states "The smallest F number of the lens", without mention of focal length)
                if (Perl::num($maxAperture) < $sa - 0.15) continue;  // (0.15 is arbitrary)
                if (Perl::num($maxAperture) > $la + 0.15) continue;
                // now determine the best match for this aperture
                $aa = null; // approximate maximum aperture at this focal length
                if ($sf == $lf or $sa == $la or Perl::num($focalLength) <= $sf) {
                    // either 1) prime lens, 2) fixed-aperture zoom, or 3) zoom at min focal
                    $aa = $sa;
                } elseif (Perl::num($focalLength) >= $lf) {
                    $aa = $la;
                } else {
                    // assume a log-log variation of max aperture with focal length
                    // (see http://regex.info/blog/2006-10-05/263)
                    $aa = exp(log($sa) + (log($la) - log($sa)) / (log($lf) - log($sf)) *
                                         (log(Perl::num($focalLength)) - log($sf)));
                    // a linear relationship between 1/FocalLength and 1/MaxAperture fits Sony better (ref 27)
                    //$aa = 1 / (1/$sa + (1/$focalLength - 1/$sf) * (1/$la - 1/$sa) / (1/$lf - 1/$sf));
                }
                $d = abs(Perl::num($maxAperture) - $aa);
                if ($diff !== null) {
                    if ($d > $diff + 0.15) continue;     // (0.15 is arbitrary)
                    if ($d < $diff - 0.15) $best = [];
                }
                $diff = $d;
                $best[] = $lens;
            }
            $matches[] = $lens;
        }
        // return the user-defined lens if it exists
        if ($user) {
            // choose the best match if we have more than one
            if (count($user) > 1) {
                $good = [];
                foreach ([$best, $matches] as $try) {
                    foreach ($try as $_) {
                        if (Vars\ExifTool::$userLens[$_] ?? null) $good[] = $_;
                    }
                    if ($good) return implode(' or ', $good);
                }
            }
            return implode(' or ', $user);
        }
        // return the best match(es) from the possible lenses, after checking against LensModel
        if (!$best) $best = $matches;
        if ($best) {
            self::MatchLensModel($best, $lensModel);
            return implode(' or ', $best);
        }
        $lens = $printConv[Perl::str($lensType)];
        if ($lensModel and preg_match('/ or /', $lens)) return $lensModel; // (eg. Sony NEX-5N)
        return $lens;
    }

    //------------------------------------------------------------------------------
    // Translate date into standard EXIF format
    // Inputs: 0) date
    // Returns: date in format '2003:10:22'
    // - bad formats recognized: '2003-10-22','2003/10/22','2003 10 22','20031022'
    // - removes null terminator if it exists
    public static function ExifDate($date)
    {
        $date = preg_replace('/\0$/', '', Perl::str($date), 1);       // remove any null terminator
        // separate year:month:day with colons
        // (have seen many other characters, including nulls, used erroneously)
        $date = preg_replace('/(\d{4})[^\d]*(\d{2})[^\d]*(\d{2})$/', '$1:$2:$3', $date, 1);
        return $date;
    }

    //------------------------------------------------------------------------------
    // Translate time into standard EXIF format
    // Inputs: 0) time
    // Returns: time in format '10:30:55'
    // - bad formats recognized: '10 30 55', '103055', '103055+0500'
    // - removes null terminator if it exists
    // - leaves time zone intact if specified (eg. '10:30:55+05:00')
    public static function ExifTime($time)
    {
        $time = strtr(Perl::str($time), ' ', ':');   // use ':' (not ' ') as a separator
        $time = preg_replace('/\0$/', '', $time, 1);   // remove any null terminator
        // add separators if they don't exist
        $time = preg_replace('/^(\d{2})(\d{2})(\d{2})/', '$1:$2:$3', $time, 1);
        $time = preg_replace('/([+-]\d{2})(\d{2})\s*$/', '$1:$2', $time, 1);   // to timezone too
        return $time;
    }

    //------------------------------------------------------------------------------
    // Generate TIFF file from scratch (in current byte order)
    // Inputs: 0) hash of IFD entries (TagID => Value; multiple values space-delimited)
    //         1) raw image data reference
    // Returns: TIFF image data, or undef on error
    public static function GenerateTIFF(array $entries, $dataPt)
    {
        $offsetPos = null;
        $num = count($entries);
        $ifdBuff = ExifTool::GetByteOrder() . ExifTool::Set16u(42) . ExifTool::Set32u(8) . ExifTool::Set16u($num);
        $valBuff = '';
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
        $tags = array_keys($entries);
        sort($tags);
        foreach ($tags as $tag) {
            $tagInfo = $tagTablePtr[$tag];
            $fmt = $tagInfo instanceof TagInfo ? $tagInfo->Writable : 'int32u';
            if ($fmt === null) return null;
            $val = ExifTool::WriteValue($entries[$tag], $fmt, -1);
            if ($val === null) return null;
            $format = Vars\Exif::$formatNumber[$fmt];
            $ifdBuff .= ExifTool::Set16u($tag) . ExifTool::Set16u($format) . ExifTool::Set32u(strlen($val) / Vars\Exif::$formatSize[$format]);
            if ($tag == 0x111) $offsetPos = strlen($ifdBuff); // (remember StripOffsets position)
            if (strlen($val) > 4) {
                $ifdBuff .= ExifTool::Set32u(10 + 12 * $num + 4 + strlen($valBuff));
                $valBuff .= $val;
            } else {
                if (strlen($val) < 4) $val .= str_repeat("\0", 4 - strlen($val));
                $ifdBuff .= $val;
            }
        }
        $ifdBuff .= "\0\0\0\0"; // (no IFD1)
        if (!$offsetPos) return null;
        ExifTool::Set32u(strlen($ifdBuff) + strlen($valBuff), $ifdBuff, $offsetPos);
        return $ifdBuff . $valBuff . $dataPt;
    }

    //------------------------------------------------------------------------------
    // Rebuild TIFF thumbnail(s)/preview(s) into stand-alone files with current byte order
    // Inputs: 0) ExifTool ref, 1) SubfileType, 2) Compression, 3) ImageWidth, 4) ImageHeight,
    //         5) BitsPerSample, 6) PhotometricInterpretation, 7) StripOffsets, 8) SamplesPerPixel,
    //         9) RowsPerStrip, 10) StripByteCounts, 10) PlanarConfiguration, 11) Orientation
    // Returns: 0) TIFF image or undef, 1/2) Family 0/1 groups for TIFF preview IFD
    // (PHP: always the list, as the only caller uses list context)
    public static function RebuildTIFF(ExifTool $et, ...$args)
    {
        $value = $et->VALUE;
        $rtn = $grp0 = $grp1 = null;
        if ($et->FILE_TYPE === 'RWZ') return [null];
        for ($i = 0; ; ++$i) {   // SubFile:
            $key = 'SubfileType' . ($i ? " ($i)" : '');
            if (!isset($value[$key])) break;
            if (Perl::num($value[$key]) != 1) continue; // (reduced-resolution image)
            $grp = $et->GetGroup($key, 1);
            $cmp = $et->FindValue('Compression', $grp);
            if (Perl::num($cmp) != 1) continue; // (no compression)
            $vals = ['Compression' => $cmp, 'PlanarConfiguration' => 1, 'Orientation' => 1];
            foreach (['ImageWidth', 'ImageHeight', 'BitsPerSample', 'PhotometricInterpretation',
                      'StripOffsets', 'SamplesPerPixel', 'RowsPerStrip', 'StripByteCounts',
                      'PlanarConfiguration', 'Orientation'] as $_)
            {
                $val = $et->FindValue($_, $grp);
                if ($val !== null) {
                    $vals[$_] = $val;
                    continue;
                }
                if (!isset($vals[$_])) continue 2;  // next SubFile
            }
            [$w, $h] = [Perl::num($vals['ImageWidth']), Perl::num($vals['ImageHeight'])];
            $bits = Perl::split(' ', $vals['BitsPerSample']);
            $rowBytes = 0;
            foreach ($bits as $_) $rowBytes += $w * Perl::int((Perl::num($_) + 7) / 8);
            $dat = '';
            $off = Perl::split(' ', $vals['StripOffsets']);
            $len = Perl::split(' ', $vals['StripByteCounts']);
            // read the image data
            for ($j = 0; $j < count($off); ++$j) {
                if (Perl::num($len[$j] ?? null) != $rowBytes * Perl::num($vals['RowsPerStrip'])) continue 2;
                $tmp = $et->ExtractBinary($off[$j], $len[$j]);
                if ($tmp === null) continue 2;
                $dat .= $tmp;
            }
            // generate the TIFF image
            $entries = [
                0x0fe => 0,                 // SubfileType = 0
                0x100 => $w,                // ImageWidth
                0x101 => $h,                // ImageHeight
                0x102 => $vals['BitsPerSample'],// BitsPerSample
                0x103 => $vals['Compression'],// Compression
                0x106 => $vals['PhotometricInterpretation'], // PhotometricInterpretation
                0x111 => 0,                 // StripOffsets (will be adjusted later)
                0x112 => $vals['Orientation'],// Orientation
                0x115 => $vals['SamplesPerPixel'], // SamplesPerPixel
                0x116 => $h,                // RowsPerStrip
                0x117 => $h * $rowBytes,    // StripByteCounts
                0x11a => 72,                // XResolution = 72
                0x11b => 72,                // YResolution = 72
                0x11c => $vals['PlanarConfiguration'], // PlanarConfiguration
                0x128 => 2,                 // ResolutionUnit = 2
            ];
            $img = self::GenerateTIFF($entries, $dat);

            if ($img === null) {
                $et->Warn('Invalid ' . ($w > 256 ? 'Preview' : 'Thumbnail') . 'TIFF data');
            } elseif ($rtn or $w > 256) { // (call it a preview if larger than 256 pixels)
                $et->FoundTag('PreviewTIFF', new ScalarRef($img), ...$et->GetGroup($key));
            } else {
                $rtn = new ScalarRef($img);
                [$grp0, $grp1] = $et->GetGroup($key) + [null, null];
            }
        }
        return [$rtn, $grp0, $grp1];
    }

    //------------------------------------------------------------------------------
    // Extract image from file
    // Inputs: 0) ExifTool object reference, 1) data offset (in file), 2) data length
    //         3) [optional] tag name
    // Returns: Reference to Image if specifically requested or "Binary data" message
    //          Returns undef if there was an error loading the image
    public static function ExtractImage(ExifTool $et, $offset, $len, $tag = null)
    {
        $dataPt = $et->EXIF_DATA;
        $dataPos = $et->EXIF_POS;
        $image = null;

        // no image if length is zero, and don't try to extract binary from XMP file
        if (!$len or $et->FILE_TYPE === 'XMP') return null;

        // take data from EXIF block if possible
        if ($dataPos !== null and $offset >= $dataPos and $offset + $len <= $dataPos + strlen($dataPt ?? '')) {
            $image = substr($dataPt, $offset - $dataPos, $len);
        } else {
            $image = $et->ExtractBinary($offset, $len, $tag);
            if ($image === null) return null;
            // patch for incorrect ThumbnailOffset in some Sony DSLR-A100 ARW images
            if ($tag and $tag === 'ThumbnailImage' and $et->TIFF_TYPE === 'ARW' and
                $et->Model === 'DSLR-A100' and $offset < 0x10000 and
                !preg_match('/^(Binary data|\xff\xd8\xff)/', $image))
            {
                $try = $et->ExtractBinary($offset + 0x10000, $len, $tag);
                if ($try !== null and preg_match('/^\xff\xd8\xff/', $try)) {
                    $image = $try;
                    $et->VALUE['ThumbnailOffset'] += 0x10000;
                    $et->Warn('Adjusted incorrect A100 ThumbnailOffset', 1);
                }
            }
        }
        return $et->ValidateImage(new ScalarRef($image), $tag);
    }

    //------------------------------------------------------------------------------
    // Utility routine to return tag ID string for warnings
    // Inputs: 0) Tag ID, 1) [optional] TagInfo ref
    // Returns: "tag 0xXXXX NAME"
    public static function TagName($tagID, $tagInfo = null)
    {
        $tagName = $tagInfo ? ' ' . $tagInfo->Name : '';
        return Perl::sprintf('tag 0x%.4x%s', $tagID, $tagName);
    }

    //------------------------------------------------------------------------------
    // Get class name of next IFD offset for HtmlDump output
    // Inputs: 0) ExifTool ref, 1) current class ID
    // Returns: 0) new IFD offset name, 1) new class ID including "Offset_" for new offset
    //          2) new "Offset_" ID
    public static function NextOffsetName(ExifTool $et, $id = null)
    {
        $et->OffsetNum = $et->OffsetNum !== null ? $et->OffsetNum + 1 : 0;
        $offName = 'o' . $et->OffsetNum;
        $sid = "Offset_$offName";
        $id = ($id !== null ? "$id " : '') . $sid;
        return [$offName, $id, $sid];
    }

    //------------------------------------------------------------------------------
    // Process EXIF directory
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessExif(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $dataLen = $dirInfo['DataLen'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: $dataLen - $dirStart;
        $dirName = $dirInfo['DirName'] ?? null;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $firstBase = $base;
        $raf = $dirInfo['RAF'] ?? null;
        [$verbose, $validate, $saveFormat, $saveBin] = [$et->OPTIONS['Verbose'] ?? null, $et->OPTIONS['Validate'] ?? null,
            $et->OPTIONS['SaveFormat'] ?? null, $et->OPTIONS['SaveBin'] ?? null];
        $htmlDump = $et->HTML_DUMP;
        $success = 1;
        $tagKey = $dirSize = $makerAddr = $strEnc = $offName = $nextOffName = $doHash = null;
        $offsetInfo = [];
        $inMakerNotes = ($tagTablePtr['GROUPS'][0] ?? null) === 'MakerNotes';
        $isExif = ($tagTablePtr === ExifTool::GetTagTable('Image::ExifTool::Exif::Main'));

        // warn for incorrect maker notes in CR3 files
        if ($dirName === 'MakerNotes') {
            if ($et->FileType === 'CR3' and ($dirInfo['Parent'] ?? null) and $dirInfo['Parent'] === 'ExifIFD') {
                $et->Warn("MakerNotes shouldn't exist ExifIFD of CR3 image", 1);
            }
            if (($dirInfo['TagInfo'] ?? null) and $dirInfo['TagInfo']->MakerNotes and
                $et->ExifByteOrder and $et->ExifByteOrder !== ExifTool::GetByteOrder())
            {
                $et->FoundTag('MakerNoteByteOrder', ExifTool::GetByteOrder());
            }
        }
        // set flag to calculate image data hash if requested
        if ($et->ImageDataHash and (($et->FILE_TYPE === 'TIFF' and !$base and !$inMakerNotes) or
            ($et->FILE_TYPE === 'RAF' and $dirName === 'FujiIFD'))) $doHash = 1;

        // set encoding to assume for strings
        if (($tagTablePtr['GROUPS'][0] ?? null) === 'EXIF') $strEnc = $et->Options('CharsetEXIF');

        // ignore non-standard EXIF while in strict MWG compatibility mode
        if (($validate or (MWG::$strict ?? null)) and $dirName === 'IFD0' and
            $isExif and preg_match('/^(JPEG|TIFF|PSD)$/', Perl::str($et->FILE_TYPE)))
        {
            $path = $et->MetadataPath();
            if (preg_match('/^(JPEG-APP1-IFD0|TIFF-IFD0|PSD-EXIFInfo-IFD0)$/', $path)) {
                if (!$et->DOC_NUM) {
                    if ($et->HasExif) $et->Warn("Duplicate EXIF at $path");
                    $et->HasExif = 1;
                }
            } else {
                if (MWG::$strict ?? null) {
                    $et->Warn("Ignored non-standard EXIF at $path");
                    return 0;
                } else {
                    $et->Warn("Non-standard EXIF at $path", 1);
                }
            }
        }
        // mix htmlDump and Validate into verbose so we can test for all at once
        if ($htmlDump) $verbose = -1;
        if ($validate and !$verbose) $verbose = -2;
        if ($dirName === 'EXIF') $dirName = $dirInfo['DirName'] = 'IFD0';
        if (preg_match('/^(IFD0|SubIFD)$/', Perl::str($dirName)) and !isset($dirInfo['Multi'])) $dirInfo['Multi'] = 1;
        // get a more descriptive name for MakerNote sub-directories
        $dir = $dirInfo['Name'] ?? null;
        if (!($dir and $inMakerNotes and !preg_match('/^MakerNote/', $dir))) $dir = $dirName;

        $numEntries = $dirEnd = null;
        if ($dirStart >= 0 and $dirStart <= $dataLen - 2) {
            // make sure data is large enough (patches bug in Olympus subdirectory lengths)
            $numEntries = ExifTool::Get16u($dataPt, $dirStart);
            $dirSize = 2 + 12 * $numEntries;
            $dirEnd = $dirStart + $dirSize;
            if ($dirSize > $dirLen) {
                if (($verbose > 0 or $validate) and !($dirInfo['SubIFD'] ?? null)) {
                    $short = $dirSize - $dirLen;
                    $et->INDENT = preg_replace('/..$/', '', Perl::str($et->INDENT), 1); // keep indent the same
                    $et->Warn("Short directory size for $dir (missing $short bytes)");
                    $et->INDENT .= '| ';
                }
                if ($dirEnd > $dataLen) $dirSize = null; // read from file if necessary
            }
        }
        // read IFD from file if necessary
        if (!$dirSize) {
            $success = 0;
            if ($raf) {
                // read the count of entries in this IFD
                $offset = $dirStart + $dataPos;
                $buff = $buf2 = null;
                if ($raf->Seek($offset + $base, 0) and $raf->Read($buff, 2) == 2) {
                    $len = 12 * ExifTool::Get16u($buff, 0);
                    // also read next IFD pointer if available
                    if ($raf->Read($buf2, $len + 4) >= $len) {
                        $buff .= $buf2;
                        $dataPt = $dirInfo['DataPt'] = $buff;
                        $dataPos = $dirInfo['DataPos'] = $offset;
                        $dataLen = $dirInfo['DataLen'] = strlen($buff);
                        $dirStart = $dirInfo['DirStart'] = 0;
                        $dirLen = $dirInfo['DirLen'] = strlen($buff);
                        $success = 1;
                    }
                }
            }
            if ($success) {
                $numEntries = ExifTool::Get16u($dataPt, $dirStart);
            } else {
                $et->Warn("Bad $dir directory", $inMakerNotes);
                if (!($inMakerNotes and $dirLen >= 14 and $dirStart >= 0 and
                      $dirStart + $dirLen <= strlen($dataPt ?? ''))) return 0;
                $dirSize = $dirLen;
                $numEntries = Perl::int(($dirSize - 2) / 12); // read what we can
                ExifTool::Set16u($numEntries, $dataPt, $dirStart);
                $dirInfo['DataPt'] = $dataPt;   // (same buffer in Perl)
            }
            $dirSize = 2 + 12 * $numEntries;
            $dirEnd = $dirStart + $dirSize;
        }
        if ($verbose > 0) $et->VerboseDir($dirName, $numEntries, null, ExifTool::GetByteOrder());
        $bytesFromEnd = $dataLen - $dirEnd;
        if ($bytesFromEnd < 4) {
            if (!($bytesFromEnd == 2 or $bytesFromEnd == 0)) {
                $et->Warn("Illegal $dir directory size ($numEntries entries)");
                return 0;
            }
        }
        // fix base offset for maker notes if necessary
        if (isset($dirInfo['MakerNoteAddr'])) {
            $makerAddr = $dirInfo['MakerNoteAddr'];
            unset($dirInfo['MakerNoteAddr']);
            if (MakerNotes::FixBase($et, $dirInfo)) {
                $base = $dirInfo['Base'];
                $dataPos = $dirInfo['DataPos'];
            }
        }
        if ($htmlDump) {
            $offName = $dirInfo['OffsetName'] ?? null;
            $longName = $dir === 'MakerNotes' ? (($dirInfo['Name'] ?? null) ?: $dir) : $dir;
            if ($makerAddr !== null) {
                $hdrLen = $dirStart + $dataPos + $base - $makerAddr;
                if ($hdrLen > 0) $et->HDump($makerAddr, $hdrLen, 'MakerNotes header', $longName);
            }
            if (!($dirInfo['NoDumpEntryCount'] ?? null)) {
                $et->HDump($dirStart + $dataPos + $base, 2, "$longName entries",
                           "Entry count: $numEntries", null, $offName);
            }
            $tip = null;
            $id = $offName;
            if ($bytesFromEnd >= 4) {
                $nxt = preg_match('/^(.*?)(\d+)$/', Perl::str($dir), $m) ? $m[1] . ($m[2] + 1) : 'Next IFD';
                $off = ExifTool::Get32u($dataPt, $dirEnd);
                $tip = Perl::sprintf("$nxt offset: 0x%.4x", $off);
                if ($off) [$nextOffName, $id] = self::NextOffsetName($et, $offName);
            }
            $et->HDump($dirEnd + $dataPos + $base, 4, 'Next IFD', $tip, 0, $id);
        }

        // patch for Canon EOS 40D firmware 1.0.4 bug (incorrect directory counts)
        // (must do this before parsing directory or CameraSettings offset will be suspicious)
        if ($inMakerNotes and $et->Model === 'Canon EOS 40D' and $numEntries) {
            $entry = $dirStart + 2 + 12 * ($numEntries - 1);
            $fmt = ExifTool::Get16u($dataPt, $entry + 2);
            if ($fmt < 1 or $fmt > 13) {
                $et->HDump($entry + $dataPos + $base, 12, '[invalid IFD entry]',
                           "Bad format type: $fmt", 1, $offName);
                // adjust the number of directory entries
                --$numEntries;
                $dirEnd -= 12;
            }
        }

        // make sure that Compression and SubfileType are defined for this IFD (for Condition's)
        $et->Compression = $et->SubfileType = '';

        // loop through all entries in an EXIF directory (IFD)
        $valEnd = $offList = $offHash = $mapFmt = null;
        $valPos = [];
        if ($tagTablePtr['VARS']) $mapFmt = $tagTablePtr['VARS']['MAP_FORMAT'] ?? null;

        [$warnCount, $lastID] = [0, -1];
        for ($index = 0; $index < $numEntries; ++$index) {
            if ($warnCount > 10) {
                if ($et->Warn("Too many warnings -- $dir parsing aborted", 2)) return 0;
            }
            $entry = $dirStart + 2 + 12 * $index;
            $tagID = ExifTool::Get16u($dataPt, $entry);
            $format = ExifTool::Get16u($dataPt, $entry + 2);
            $count = ExifTool::Get32u($dataPt, $entry + 4);
            // (Apple uses the BigTIFF format code 16 in the maker notes of their ProRaw DNG files)
            if (($format < 1 or $format > 13) and $format != 129 and !($format == 16 and $et->Make === 'Apple' and $inMakerNotes)) {
                if ($mapFmt and ($mapFmt[$format] ?? null)) {
                    $format = $mapFmt[$format];
                } else {
                    $et->HDump($entry + $dataPos + $base, 12, '[invalid IFD entry]',
                               "Bad format type: $format", 1, $offName);
                    // warn unless the IFD was just padded with zeros
                    if ($format or $validate) {
                        $et->Warn("Bad format ($format) for $dir entry $index", $inMakerNotes);
                        ++$warnCount;
                    }
                    // assume corrupted IFD if this is our first entry (except Sony ILCE which have an empty first entry)
                    if ($index or preg_match('/^ILCE/', Perl::str($et->Model))) continue;
                    // $et->Warn(sprintf('Format code 0x%x encountered -- Possibly corrupted IFD'));
                    return 0;
                }
            }
            $formatStr = Vars\Exif::$formatName[$format] ?? null;   // get name of this format
            $valueDataPt = $dataPt;
            $valueDataPos = $dataPos;
            $valueDataLen = $dataLen;
            $valuePtr = $entry + 8;      // pointer to value within $$dataPt
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            $origFormStr = $bad = $rational = $binVal = $subOffName = null;
            // save the EXIF format codes if requested
            if ($saveFormat) $et->SaveFormat[$saveFormat = $formatStr] = 1;
            // hack to patch incorrect count in Kodak SubIFD3 tags
            if ($count < 2 and $tagTablePtr[$tagID] instanceof TagInfo and $tagTablePtr[$tagID]->FixCount) {
                if ($offList === null) [$offList, $offHash] = self::GetOffList($dataPt, $dirStart, $dataPos,
                                                                              $numEntries, $tagTablePtr);
                $i = $offHash[ExifTool::Get32u($dataPt, $valuePtr)] ?? null;
                if ($i !== null and $i < count($offList) - 1) {
                    $oldCount = $count;
                    $count = Perl::int(($offList[$i + 1] - $offList[$i]) / Vars\Exif::$formatSize[$format]);
                    if ($oldCount != $count) $origFormStr = Vars\Exif::$formatName[$format] . '[' . $oldCount . ']';
                }
            }
            if ($validate and !$inMakerNotes) Validate::ValidateExif(
                $et, $tagTablePtr, $tagID, $tagInfo, $lastID, $dir, $count, $formatStr);
            $size = $count * (Vars\Exif::$formatSize[$format] ?? 0);
            $readSize = $size;
            if ($size > 4) {
                if ($size > 0x7fffffff and (!$tagInfo or !$tagInfo->ReadFromRAF)) {
                    $et->Warn(Perl::sprintf('Invalid size (%u) for %s %s', $size, $dir, self::TagName($tagID, $tagInfo)), $inMakerNotes);
                    ++$warnCount;
                    continue;
                }
                $valuePtr = ExifTool::Get32u($dataPt, $valuePtr);
                if ($validate and !$inMakerNotes) {
                    $tagName = self::TagName($tagID, $tagInfo);
                    if ($valuePtr & 0x01) $et->Warn("Odd offset for $dir $tagName", 1);
                    if ($valuePtr < 8 || ($valuePtr + $size > strlen($dataPt) and
                                          $valuePtr + $size > Perl::num($et->VALUE['FileSize'] ?? null)))
                    {
                        $et->Warn("Invalid offset for $dir $tagName");
                        ++$warnCount;
                        continue;
                    }
                    if ($valuePtr + $size > $dirStart + $dataPos and $valuePtr < $dirEnd + $dataPos + 4) {
                        $et->Warn("Value for $dir $tagName overlaps IFD");
                    }
                    foreach ($valPos as $_) {
                        if ($_[0] >= $valuePtr + $size or $_[0] + $_[1] <= $valuePtr) continue;
                        $et->Warn("Value for $dir $tagName overlaps $_[2]");
                    }
                    $valPos[] = [$valuePtr, $size, $tagName];
                }
                // fix valuePtr if necessary
                if ($dirInfo['FixOffsets'] ?? null) {
                    $wFlag = null;
                    if (!$valEnd) $valEnd = $dataPos + $dirEnd + 4;
                    //#### eval FixOffsets ($valuePtr, $valEnd, $size, $tagID, $wFlag)
                    try {
                        ($dirInfo['FixOffsets'])($valuePtr, $valEnd, $size, $tagID, $wFlag);
                    } catch (\Throwable) {
                    }
                }
                $suspect = null;
                // offset shouldn't point into TIFF header
                if ($valuePtr < 8 and !($dirInfo['ZeroOffsetOK'] ?? null)) $suspect = $warnCount;
                // convert offset to pointer in $$dataPt
                if (($dirInfo['EntryBased'] ?? null) or ($tagTablePtr[$tagID] instanceof TagInfo and
                    $tagTablePtr[$tagID]->EntryBased))
                {
                    $valuePtr += $entry;
                } else {
                    $valuePtr -= $dataPos;
                }
                // value shouldn't overlap our directory
                if ($valuePtr < $dirEnd and $valuePtr + $size > $dirStart) $suspect = $warnCount;
                // load value from file if necessary
                if ($valuePtr < 0 or $valuePtr + $size > $dataLen) {
                    // get value by seeking in file if we are allowed
                    $buff = null;
                    if ($raf) {
                        // avoid loading large binary data unless necessary
                        while ($size > self::BINARY_DATA_LIMIT()) {
                            if ($tagInfo) {
                                // make large unknown blocks binary data
                                if ($tagInfo->Unknown) $tagInfo->Binary = 1;
                                if (!$tagInfo->Binary) break;      // must read non-binary data
                                if ($tagInfo->SubDirectory) break;
                                $lcTag = strtolower($tagInfo->Name);
                                if (($et->OPTIONS['Binary'] ?? null) and !($et->EXCL_TAG_LOOKUP[$lcTag] ?? null)) {
                                    // read binary data if specified unless tagsFromFile won't use it
                                    if (!($et->TAGS_FROM_FILE and $tagInfo->Protected)) break;
                                }
                                // must read if tag is specified by name
                                if ($et->REQ_TAG_LOOKUP[$lcTag] ?? null) break;
                            } else {
                                // must read value if needed for a condition
                                if ($tagInfo !== null) break;
                            }
                            // (note: changing the value without changing $size will cause
                            // a warning in the verbose output, but we need to maintain the
                            // proper size for the htmlDump, so we can't change this)
                            $buff = "Binary data $size bytes";
                            $readSize = strlen($buff);
                            break;
                        }
                        // read from file if necessary
                        if ($buff === null) {
                            $wrn = $truncOK = null;
                            $readFromRAF = ($tagInfo and $tagInfo->ReadFromRAF);
                            if (!$raf->Seek($base + $valuePtr + $dataPos, 0)) {
                                $wrn = "Invalid offset for $dir entry $index";
                            } elseif ($readFromRAF and $size > self::BINARY_DATA_LIMIT() and
                                !($et->REQ_TAG_LOOKUP[strtolower($tagInfo->Name)] ?? null))
                            {
                                $buff = "{$tagInfo->Name} data $size bytes";
                                $readSize = strlen($buff);
                            } elseif ($raf->Read($buff, $size) != $size) {
                                $wrn = Perl::sprintf("Error reading value for $dir entry $index, ID 0x%.4x", $tagID);
                                if ($tagInfo and !$tagInfo->Unknown) {
                                    $wrn .= " {$tagInfo->Name}";
                                    $truncOK = $tagInfo->TruncateOK;
                                }
                            } elseif ($readFromRAF) {
                                // seek back to the start of the value
                                $raf->Seek($base + $valuePtr + $dataPos, 0);
                            }
                            if ($wrn) {
                                $et->Warn($wrn, $inMakerNotes ?: $truncOK);
                                if (!($inMakerNotes or $htmlDump or $truncOK)) return 0;
                                ++$warnCount;
                                if ($buff === null) $buff = '';
                                $readSize = strlen($buff);
                                if (!$truncOK) $bad = 1;
                            }
                        }
                        $valueDataLen = strlen($buff);
                        $valueDataPt = $buff;
                        $valueDataPos = $valuePtr + $dataPos;
                        $valuePtr = 0;
                    } else {
                        $tagStr = $tmpInfo = $leicaTrailer = null;
                        if ($tagInfo) {
                            $tagStr = $tagInfo->Name;
                            $leicaTrailer = $tagInfo->LeicaTrailer;
                        } elseif ($tagInfo !== null) {
                            $tmpInfo = $et->GetTagInfo($tagTablePtr, $tagID, '', $formatStr, $count);
                            if ($tmpInfo) {
                                $tagStr = $tmpInfo->Name;
                                $leicaTrailer = $tmpInfo->LeicaTrailer;
                            }
                        }
                        if ($tagInfo and $tagInfo->ChangeBase) {
                            // adjust base offset for this tag only
                            //#### eval ChangeBase ($dirStart,$dataPos)
                            $newBase = self::EvalCode($tagInfo->ChangeBase, $dirStart, $dataPos);
                            $valuePtr += Perl::num($newBase);
                        }
                        if (!$tagStr) $tagStr = Perl::sprintf('tag 0x%.4x', $tagID);
                        // allow PreviewImage to run outside EXIF data
                        if ($tagStr === 'PreviewImage' and $et->RAF) {
                            $pos = $et->RAF->Tell();
                            $buff = $et->ExtractBinary($base + $valuePtr + $dataPos, $size, 'PreviewImage');
                            $et->RAF->Seek($pos, 0);
                            $valueDataPt = $buff;
                            $valueDataPos = $valuePtr + $dataPos;
                            $valueDataLen = $size;
                            $valuePtr = 0;
                        } elseif ($leicaTrailer and $et->RAF) {
                            if ($verbose > 0) {
                                $et->VPrint(0, "{$et->INDENT}$index) $tagStr --> (outside APP1 segment)\n");
                            }
                            if ($et->Options('FastScan')) {
                                $et->Warn('Ignored Leica MakerNote trailer');
                            } else {
                                $et->LeicaTrailer = [
                                    'TagInfo' => $tagInfo ?: $tmpInfo,
                                    'Offset'  => $base + $valuePtr + $dataPos,
                                    'Size'    => $size,
                                    // ponytail: Image::ExifTool::Fixup is only used when writing
                                    'Fixup'   => null,
                                ];
                            }
                        } else {
                            $et->Warn("Bad offset for $dir $tagStr", $inMakerNotes);
                            ++$warnCount;
                        }
                        if ($buff === null) {
                            $valueDataPt = '';
                            $valueDataPos = $valuePtr + $dataPos;
                            $valueDataLen = 0;
                            $valuePtr = 0;
                            $bad = 1;
                        }
                    }
                }
                // warn about suspect offsets if they didn't already cause another warning
                if ($suspect !== null and $suspect == $warnCount) {
                    $tagStr = $tagInfo ? $tagInfo->Name : Perl::sprintf('tag 0x%.4x', $tagID);
                    if ($et->Warn("Suspicious $dir offset for $tagStr", $inMakerNotes)) {
                        ++$warnCount;
                        if (!$verbose) continue;
                    }
                }
            }
            // treat single unknown byte as int8u
            if ($format == 7 and $count == 1) $formatStr = 'int8u';

            $val = $subdir = $wrongFormat = null;
            if ($tagID > 0xf000 and $isExif) {
                $oldInfo = $tagTablePtr[$tagID];
                if ((!$oldInfo or ($oldInfo instanceof TagInfo and $oldInfo->Condition and
                    !$oldInfo->PSRaw)) and !$bad)
                {
                    // handle special case of Photoshop RAW tags (0xfde8-0xfe58)
                    // --> generate tags from the value if possible
                    $val = ExifTool::ReadValue($valueDataPt, $valuePtr, $formatStr, $count, $readSize);
                    if ($val !== null and preg_match('/(.*): (.*)/', $val, $m)) {
                        $tag = $m[1];
                        $val = $m[2];
                        $tag = preg_replace("/'s/", '', $tag, 1); // remove 's (so "Owner's Name" becomes "OwnerName")
                        $tag = preg_replace('/[^a-zA-Z0-9_]/', '', $tag); // remove unknown characters
                        if ($tag) {
                            $tagInfo = new TagInfo([
                                'Name' => $tag,
                                // Condition => '$$self{TIFF_TYPE} ne "DCR"',
                                'Condition' => static function (ExifTool $self, $valPt, $format, $count) {
                                    return Perl::str($self->TIFF_TYPE) !== 'DCR';
                                },
                                // ValueConv => '$_=$val;s/^.*: //;$_', # remove descr
                                'ValueConv' => static function ($val, ExifTool $self, array $vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
                                    return preg_replace('/^.*: /', '', Perl::str($val), 1);
                                },
                                'PSRaw' => 1, // (just as flag to avoid adding this again)
                            ]);
                            ExifTool::AddTagToTable($tagTablePtr, $tagID, $tagInfo);
                            // generate conditional list if a conditional tag already existed
                            if ($oldInfo) $tagTablePtr[$tagID] = [$oldInfo, $tagInfo];
                        }
                    }
                }
            }
            if ($tagInfo !== null and !$tagInfo) {
                if ($bad) {
                    $tagInfo = null;
                } else {
                    // GetTagInfo() required the value for a Condition
                    $tmpVal = substr($valueDataPt, $valuePtr, $readSize < 128 ? $readSize : 128);
                    // (use original format name in this call -- $formatStr may have been changed to int8u)
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID, $tmpVal,
                                               Vars\Exif::$formatName[$format] ?? null, $count);
                }
            }
            // make sure we are handling the 'ifd' format properly
            if (($format == 13 or $format == 18) and (!$tagInfo or !$tagInfo->SubIFD)) {
                $str = Perl::sprintf('%s tag 0x%.4x IFD format not handled', $dirName, $tagID);
                $et->Warn($str, $inMakerNotes);
            }
            if ($tagInfo !== null) {
                $readFormat = $tagInfo->Format;
                $subdir = $tagInfo->SubDirectory;
                // unless otherwise specified, all SubDirectory data except
                // EXIF SubIFD offsets should be unformatted
                if ($subdir and !$tagInfo->SubIFD and !$readFormat) $readFormat = 'undef';
                // override EXIF format if specified
                if ($readFormat) {
                    $formatStr = $readFormat;
                    $newNum = Vars\Exif::$formatNumber[$formatStr] ?? null;
                    if ($newNum and $newNum != $format) {
                        $origFormStr = Vars\Exif::$formatName[$format] . '[' . $count . ']';
                        $format = $newNum;
                        if ($tagInfo->FixedSize) $size = $readSize = $tagInfo->FixedSize;
                        // adjust number of items for new format size
                        $count = Perl::int($size / Vars\Exif::$formatSize[$format]);
                    }
                }
                // verify that offset-type values are integral
                if (($tagInfo->IsOffset or $tagInfo->SubIFD) and !(Vars\Exif::$intFormat[Perl::str($formatStr)] ?? null)) {
                    $et->Warn(Perl::sprintf('Wrong format (%s) for %s 0x%.4x %s', $formatStr, $dir, $tagID, $tagInfo->Name));
                    if ($validate) {
                        $et->WrongFormat["$dir:{$tagInfo->Name}"] = 1;
                        $offsetInfo[$tagID] = [$tagInfo, ''];
                    }
                    if (!$verbose) continue;
                    $wrongFormat = 1;
                }
            } else {
                if (!$verbose) continue;
            }
            if (!$bad) {
                // limit maximum length of data to reformat
                // (avoids long delays when processing some corrupted files)
                $warned = null;
                if ($count > 100000 and !preg_match('/^(undef|string|binary)$/', Perl::str($formatStr))) {
                    $tagName = $tagInfo ? $tagInfo->Name : Perl::sprintf('tag 0x%.4x', $tagID);
                    // (count of 196608 is typical for ColorMap)
                    if ($tagName !== 'TransferFunction' or $count != 196608) {
                        $minor = $count > 2000000 ? 0 : 2;
                        if ($et->Warn("Ignoring $dirName $tagName with excessive count", $minor)) {
                            if (!($et->OPTIONS['HtmlDump'] ?? null)) continue;
                            $warned = 1;
                        }
                    }
                }
                if ($count > 500 and !preg_match('/^(undef|string|binary)$/', Perl::str($formatStr)) and
                    (!$tagInfo or $tagInfo->LongBinary or $warned) and !($et->OPTIONS['IgnoreMinorErrors'] ?? null))
                {
                    if (!$warned) $et->Warn('Not decoding some large array(s). Ignore minor errors to decode', 2);
                    if ($et->TAGS_FROM_FILE) continue;   // don't generate bogus value when copying tags
                    $val = "(large array of $count $formatStr values)";
                } else {
                    // convert according to specified format
                    $val = ExifTool::ReadValue($valueDataPt, $valuePtr, $formatStr, $count, $readSize, $rational);
                    if ($saveBin) $binVal = substr($valueDataPt, $valuePtr, $readSize);
                    // re-code if necessary
                    if ($val !== null) {
                        if ($formatStr === 'utf8') {
                            $val = $et->Decode($val, 'UTF8');
                        } elseif ($strEnc and $formatStr === 'string') {
                            $val = $et->Decode($val, $strEnc);
                        }
                    }
                }
            }

            if ($verbose) {
                $tval = $val;
                // also show as a rational
                if ($rational !== null) $tval = Perl::str($tval) . " ($rational)";
                if ($htmlDump) {
                    $tagName = $colName = null;
                    if ($tagInfo) {
                        $tagName = $tagInfo->Name;
                    } elseif ($tagID == 0x927c and $dirName === 'ExifIFD') {
                        $tagName = 'MakerNotes';
                    } else {
                        $tagName = Perl::sprintf('Tag 0x%.4x', $tagID);
                    }
                    $dname = Perl::sprintf("{$dir}-%.2d", $index);
                    // build our tool tip
                    if ($size < 0) $size = $count * Vars\Exif::$formatSize[$format];
                    $fstr = Vars\Exif::$formatName[$format] . "[$count]";
                    if ($origFormStr and $origFormStr !== $fstr) $fstr = "$origFormStr read as $fstr";
                    if ($wrongFormat) $fstr .= ' <-- WRONG';
                    $tip = Perl::sprintf("Tag ID: 0x%.4x\n", $tagID) .
                           "Format: $fstr\nSize: $size bytes\n";
                    if ($size > 4) {
                        $offPt = ExifTool::Get32u($dataPt, $entry + 8);
                        // (test this with ../pics/{CanonEOS-1D_XMarkIII.hif,PanasonicDC-G9.rw2,*.raf})
                        $actPt = $valuePtr + $valueDataPos + $base - ($et->EXIF_POS ?: 0) +
                                 ($et->BASE_FUDGE ?: ($et->BASE ?: 0));
                        $tip .= Perl::sprintf("Value offset: 0x%.4x\n", $offPt);
                        // highlight tag name (red for bad size)
                        $style = ($bad or $tval === null) ? 'V' : 'H';
                        if ($actPt != $offPt) {
                            $tip .= Perl::sprintf("Actual offset: 0x%.4x\n", $actPt);
                            $sign = $actPt < $offPt ? '-' : '';
                            $tip .= Perl::sprintf("Offset base: {$sign}0x%.4x\n", abs($actPt - $offPt));
                            if ($style === 'H') $style = 'F';  // purple for different offsets
                        }
                        if ($et->EXIF_POS and !$et->BASE_FUDGE) {
                            $tip .= Perl::sprintf("File offset:   0x%.4x\n", $actPt + $et->EXIF_POS);
                        }
                        $colName = "<span class=$style>$tagName</span>";
                        if ($offPt & 0x01) $colName .= ' <span class=V>(odd)</span>';
                    } else {
                        $colName = $tagName;
                    }
                    if ($wrongFormat) $colName .= ' <span class=V>(err)</span>';
                    if ($tagID <= $lastID and !$inMakerNotes) $colName .= ' <span class=V>(seq)</span>';
                    $lastID = $tagID;
                    if ($tval === null) {
                        $tval = '<bad size/offset>';
                    } else {
                        $tval = Perl::str($tval);
                        if (strlen($tval) > 32) $tval = substr($tval, 0, 28) . '[...]';
                        if (preg_match('/^(string|undef|binary)/', Perl::str($formatStr))) {
                            // translate non-printable characters
                            $tval = preg_replace('/[\x00-\x1f\x7f-\xff]/', '.', $tval);
                        } elseif ($tagInfo and ExifTool::IsInt($tval)) {
                            if ($tagInfo->IsOffset or $tagInfo->SubIFD) {
                                $tval = Perl::sprintf('0x%.4x', $tval);
                                $actPt = $val + $base - ($et->EXIF_POS ?: 0) + ($et->BASE_FUDGE ?: ($et->BASE ?: 0));
                                if ($actPt != $val) {
                                    $tval .= Perl::sprintf("\nActual offset: 0x%.4x", $actPt);
                                    $sign = $actPt < $val ? '-' : '';
                                    $tval .= Perl::sprintf("\nOffset base: {$sign}0x%.4x", abs($actPt - $val));
                                }
                                if ($et->EXIF_POS and !$et->BASE_FUDGE) {
                                    $tip .= Perl::sprintf("File offset:   0x%.4x\n", $actPt + $et->EXIF_POS);
                                }
                            } elseif ($tagInfo->PrintHex) {
                                $tval = Perl::sprintf('0x%x', $tval);
                            }
                        }
                    }
                    $tip .= "Value: $tval";
                    $id = $offName;
                    $sid = null;
                    if ($tagInfo and $tagInfo->SubIFD) [$subOffName, $id, $sid] = self::NextOffsetName($et, $offName);
                    $et->HDump($entry + $dataPos + $base, 12, "$dname $colName", $tip, 1, $id);
                    if ($valueDataLen < 0) continue;  // don't process bad pointer entry
                    if ($size > 4) {
                        $exifDumpPos = $valuePtr + $valueDataPos + $base;
                        $flag = 0;
                        if ($subdir) {
                            if ($tagInfo->MakerNotes) {
                                $flag = 0x04;
                            } elseif ($tagInfo->NestedHtmlDump) {
                                $flag = $tagInfo->NestedHtmlDump == 2 ? 0x10 : 0x04;
                            }
                        }
                        // add value data block (underlining maker notes data)
                        $et->HDump($exifDumpPos, $size, "$tagName value", 'SAME', $flag, $sid);
                        if ($subdir and $tagInfo->MakerNotes and $tagInfo->NotIFD) {
                            $et->HDump($exifDumpPos, $size, "$tagName value", null, null, $dirInfo['OffsetName'] ?? null);
                        }
                    }
                } else {
                    if ($tagID <= $lastID and !$inMakerNotes) {
                        $str = $tagInfo ? ' ' . $tagInfo->Name : '';
                        if ($tagID == $lastID) {
                            $et->Warn(Perl::sprintf('Duplicate tag 0x%.4x%s in %s', $tagID, $str, $dirName));
                        } else {
                            $et->Warn(Perl::sprintf('Tag ID 0x%.4x%s out of sequence in %s', $tagID, $str, $dirName));
                        }
                    }
                    $lastID = $tagID;
                    if ($verbose > 0) {
                        $fstr = Vars\Exif::$formatName[$format] ?? null;
                        if ($origFormStr) $fstr = "$origFormStr read as $fstr";
                        $et->VerboseInfo($tagID, $tagInfo,
                            Table:   $tagTablePtr,
                            Index:   $index,
                            Value:   $tval,
                            DataPt:  $valueDataPt,
                            DataPos: $valueDataPos + $base,
                            Size:    $size,
                            Start:   $valuePtr,
                            Format:  $fstr,
                            Count:   $count,
                        );
                    }
                }
                if (!$tagInfo or $wrongFormat) continue;
            }
            if ($val === null) continue;
            //..............................................................................
            // Handle SubDirectory tag types
            //
            if ($subdir) {
                // don't process empty subdirectories
                if (!$size) {
                    if (!($tagInfo->MakerNotes or $inMakerNotes)) {
                        $et->Warn("Empty {$tagInfo->Name} data", 1);
                    }
                    continue;
                }
                $values = [];
                $newTagTable = $newByteOrder = $invalid = null;
                $tagStr = $tagInfo->Name;
                if ($subdir['MaxSubdirs'] ?? null) {
                    $values = Perl::split(' ', $val);
                    // limit the number of subdirectories we parse
                    $over = count($values) - $subdir['MaxSubdirs'];
                    if ($over > 0) {
                        $et->Warn("Ignoring $over $tagStr directories");
                        array_splice($values, $subdir['MaxSubdirs']);
                    }
                    $val = array_shift($values);
                }
                if ($subdir['TagTable'] ?? null) {
                    $newTagTable = ExifTool::GetTagTable($subdir['TagTable']);
                    if (!$newTagTable) {
                        trigger_error("Unknown tag table {$subdir['TagTable']}", E_USER_WARNING);
                        continue;
                    }
                } else {
                    $newTagTable = $tagTablePtr;    // use existing table
                }
                // loop through all sub-directories specified by this tag
                for ($dirNum = 0; ; ++$dirNum) {
                    $subdirBase = $base;
                    $subdirDataPt = $valueDataPt;
                    $subdirDataPos = $valueDataPos;
                    $subdirDataLen = $valueDataLen;
                    $subdirStart = $valuePtr;
                    if (isset($subdir['Start'])) {
                        // set local $valuePtr relative to file $base for eval
                        // (Perl "my $valuePtr" shadows the entry value pointer in this block only)
                        $localValuePtr = $subdirStart + $subdirDataPos;
                        //#### eval Start ($valuePtr, $val)
                        $newStart = self::EvalCode($subdir['Start'], $localValuePtr, $val, $dirStart, $dataPt);
                        if (!ExifTool::IsInt($newStart)) {
                            $et->Warn("Bad value for $tagStr");
                            break;
                        }
                        // convert back to relative to $subdirDataPt
                        $newStart -= $subdirDataPos;
                        // adjust directory size if necessary
                        if (!($tagInfo->SubIFD or ($subdir['BadOffset'] ?? null))) {
                            $size -= $newStart - $subdirStart;
                        }
                        $subdirStart = $newStart;
                    }
                    // this is a pain, but some maker notes are always a specific
                    // byte order, regardless of the byte order of the file
                    $oldByteOrder = ExifTool::GetByteOrder();
                    $newByteOrder = $subdir['ByteOrder'] ?? null;
                    if ($newByteOrder) {
                        if (preg_match('/^Little/i', $newByteOrder)) {
                            $newByteOrder = 'II';
                        } elseif (preg_match('/^Big/i', $newByteOrder)) {
                            $newByteOrder = 'MM';
                        } elseif ($subdir['OffsetPt'] ?? null) {
                            $newByteOrder = null;
                            trigger_error("Can't have variable byte ordering for SubDirectories using OffsetPt", E_USER_WARNING);
                            break;
                        } elseif ($subdirStart + 2 <= $subdirDataLen) {
                            // attempt to determine the byte ordering by checking
                            // the number of directory entries.  This is an int16u
                            // that should be a reasonable value.
                            $num = ExifTool::Get16u($subdirDataPt, $subdirStart);
                            if ($num & 0xff00 and ($num >> 8) > ($num & 0xff)) {
                                // This looks wrong, we shouldn't have this many entries
                                $otherOrder = ['II' => 'MM', 'MM' => 'II'];
                                $newByteOrder = $otherOrder[$oldByteOrder];
                            } else {
                                $newByteOrder = $oldByteOrder;
                            }
                        }
                    } else {
                        $newByteOrder = $oldByteOrder;
                    }
                    // set base offset if necessary
                    if ($subdir['Base'] ?? null) {
                        // calculate subdirectory start relative to $base for eval
                        $start = $subdirStart + $subdirDataPos;
                        //#### eval Base ($start,$base)
                        $subdirBase = Perl::num(self::EvalCode($subdir['Base'], $start, $base)) + $base;
                    }
                    // add offset to the start of the directory if necessary
                    if ($subdir['OffsetPt'] ?? null) {
                        //#### eval OffsetPt ($valuePtr)
                        $pos = Perl::num(self::EvalCode($subdir['OffsetPt'], $valuePtr));
                        if ($pos + 4 > $subdirDataLen) {
                            $et->Warn("Bad $tagStr OffsetPt");
                            break;
                        }
                        ExifTool::SetByteOrder($newByteOrder);
                        $subdirStart += ExifTool::Get32u($subdirDataPt, $pos);
                        ExifTool::SetByteOrder($oldByteOrder);
                    }
                    if ($subdirStart < 0 or $subdirStart + 2 > $subdirDataLen) {
                        // convert $subdirStart back to a file offset
                        if ($raf) {
                            // reset SubDirectory buffer (we will load it later)
                            $buff = '';
                            $subdirDataPt = $buff;
                            $subdirDataLen = $size = strlen($buff);
                        } else {
                            $msg = "Bad $tagStr SubDirectory start";
                            if ($verbose > 0) {
                                if ($subdirStart < 0) {
                                    $msg .= " (directory start $subdirStart is before EXIF start)";
                                } else {
                                    $end = $subdirStart + $size;
                                    $msg .= " (directory end is $end but EXIF size is only $subdirDataLen)";
                                }
                            }
                            $et->Warn($msg, $inMakerNotes);
                            break;
                        }
                    }

                    // must update subdirDataPos if $base changes for this subdirectory
                    $subdirDataPos += $base - $subdirBase;

                    // build information hash for new directory
                    $subdirInfo = [
                        'Name'       => $tagStr,
                        'Base'       => $subdirBase,
                        'DataPt'     => $subdirDataPt,
                        'DataPos'    => $subdirDataPos,
                        'DataLen'    => $subdirDataLen,
                        'DirStart'   => $subdirStart,
                        'DirLen'     => $size,
                        'RAF'        => $raf,
                        'Parent'     => $dirName,
                        'DirName'    => $subdir['DirName'] ?? null,
                        'FixBase'    => $subdir['FixBase'] ?? null,
                        'FixOffsets' => $subdir['FixOffsets'] ?? null,
                        'EntryBased' => $subdir['EntryBased'] ?? null,
                        'TagInfo'    => $tagInfo,
                        'SubIFD'     => $tagInfo->SubIFD,
                        'Subdir'     => $subdir,
                        'OffsetName' => $subOffName,
                    ];
                    // (remember: some cameras incorrectly write maker notes in IFD0)
                    if ($tagInfo->MakerNotes) {
                        // don't parse makernotes if FastScan > 1
                        $fast = $et->Options('FastScan');
                        if ($fast and $fast > 1) break;
                        $subdirInfo['MakerNoteAddr'] = $valuePtr + $valueDataPos + $base;
                        if (isset($subdir['Base'])) $subdirInfo['NoFixBase'] = 1;
                    }
                    // set directory IFD name from group name of family 1 if it exists,
                    // unless the tag is writable as a block in which case group 1 may
                    // have been set automatically
                    if ($tagInfo->Groups and !$tagInfo->Writable) {
                        $subdirInfo['DirName'] = $tagInfo->Groups[1] ?? null;
                        // number multiple subdirectories
                        if ($dirNum) $subdirInfo['DirName'] = preg_replace('/\d*$/', $dirNum, Perl::str($subdirInfo['DirName']), 1);
                    }
                    ExifTool::SetByteOrder($newByteOrder);    // set byte order for this subdir
                    // validate the subdirectory if necessary
                    $dirData = $subdirDataPt;    // set data pointer to be used in eval
                    //#### eval Validate ($val, $dirData, $subdirStart, $size)
                    $ok = 0;
                    if (isset($subdir['Validate']) and !self::EvalCode($subdir['Validate'], $val, $dirData, $subdirStart, $size, null)) {
                        $et->Warn("Invalid $tagStr data", $inMakerNotes);
                        $invalid = 1;
                    } else {
                        if (!($subdirInfo['DirName'] ?? null) and $inMakerNotes) {
                            $subdirInfo['DirName'] = $tagInfo->Name;
                        }
                        // process the subdirectory
                        $ok = $et->ProcessDirectory($subdirInfo, $newTagTable, $subdir['ProcessProc'] ?? null);
                    }
                    // print debugging information if there were errors
                    if (!$ok and $verbose > 1 and $subdirStart != $valuePtr) {
                        $out = $et->Options('TextOut');
                        fwrite($out, Perl::sprintf("%s    (SubDirectory start = 0x%x)\n", $et->INDENT, $subdirStart));
                    }
                    ExifTool::SetByteOrder($oldByteOrder);    // restore original byte swapping

                    if (!$values) break;
                    $val = array_shift($values);           // continue with next subdir
                }
                $doMaker = $et->Options('MakerNotes');
                if (!($doMaker or ($et->REQ_TAG_LOOKUP[strtolower($tagStr)] ?? null) or $tagInfo->BlockExtract)) continue;
                // extract as a block if specified
                if ($tagInfo->MakerNotes) {
                    // save maker note byte order (if it was significant and valid)
                    if (($subdir['ByteOrder'] ?? null) and !$invalid) {
                        $et->MAKER_NOTE_BYTE_ORDER =
                            $et->UnknownByteOrder !== null ?
                                     $et->UnknownByteOrder : $newByteOrder;
                    }
                    if ($doMaker and Perl::str($doMaker) === '2') {
                        // extract maker notes without rebuilding (no fixup information)
                        unset($et->MAKER_NOTE_FIXUP);
                    } elseif (!$tagInfo->NotIFD or $tagInfo->IsPhaseOne) {
                        // this is a pain, but we must rebuild EXIF-type maker notes to
                        // include all the value data if data was outside the maker notes
                        $makerDirInfo = [
                            'Name'       => $tagStr,
                            'Base'       => $base,
                            'DataPt'     => $valueDataPt,
                            'DataPos'    => $valueDataPos,
                            'DataLen'    => $valueDataLen,
                            'DirStart'   => $valuePtr,
                            'DirLen'     => $size,
                            'RAF'        => $raf,
                            'Parent'     => $dirName,
                            'DirName'    => 'MakerNotes',
                            'FixOffsets' => $subdir['FixOffsets'] ?? null,
                            'TagInfo'    => $tagInfo,
                        ];
                        $val2 = null;
                        if ($tagInfo->IsPhaseOne) {
                            // ponytail: PhaseOne::WritePhaseOne is write-side (not ported), so
                            // the original block is kept, with Perl's rebuild warning
                        } else {
                            if ($subdir['FixBase'] ?? null) $makerDirInfo['FixBase'] = 1;
                            // rebuild maker notes (creates $$et{MAKER_NOTE_FIXUP})
                            $val2 = self::RebuildMakerNotes($et, $makerDirInfo, $newTagTable);
                        }
                        if ($val2 !== null) {
                            $val = $val2;
                        } elseif ($size > 4) {
                            $et->Warn('Error rebuilding maker notes (may be corrupt)');
                        }
                    }
                } else {
                    // extract this directory as a block if specified
                    if (!$tagInfo->Writable) continue;
                }
            }
            //..............................................................................
            // convert to absolute offsets if this tag is an offset
            //#### eval IsOffset ($val, $et)
            if ($tagInfo->IsOffset and self::EvalCode($tagInfo->IsOffset, $val, $et)) {
                $offsetBase = Perl::str($tagInfo->IsOffset) === '2' ? $firstBase : $base;
                $offsetBase += Perl::num($et->BASE);
                // handle offsets which use a wrong base (Minolta A200)
                if ($tagInfo->WrongBase) {
                    $self = $et;
                    //#### eval WrongBase ($self)
                    $offsetBase += Perl::num(self::EvalCode($tagInfo->WrongBase, $self) ?: 0);
                }
                $vals = Perl::split(' ', $val);
                foreach ($vals as &$_) {
                    $_ = Perl::num($_) + $offsetBase;
                }
                unset($_);
                $val = implode(' ', array_map(Perl::str(...), $vals));
            }
            if ($validate or $doHash) {
                if ($tagInfo->OffsetPair) {
                    $offsetInfo[$tagID] = [$tagInfo, $val];
                } elseif ((Vars\Exif::$saveForValidate[$tagID] ?? null) and $isExif) {
                    $offsetInfo[$tagID] = $val;
                }
            }
            // save the value of this tag
            $tagKey = $et->FoundTag($tagInfo, $val);
            if ($tagKey !== null) {
                // set the group 1 name for tags in specified tables
                if ($tagTablePtr['SET_GROUP1']) $et->SetGroup($tagKey, $dirName);
                // save original components of rational numbers (used when copying)
                if ($rational !== null) $et->TAG_EXTRA[$tagKey]['Rational'] = $rational;
                if ($binVal !== null) $et->TAG_EXTRA[$tagKey]['BinVal'] = $binVal;
                if ($saveFormat) $et->TAG_EXTRA[$tagKey]['G6'] = $saveFormat;
                if ($et->MAKER_NOTE_FIXUP) {
                    $et->TAG_EXTRA[$tagKey]['Fixup'] = $et->MAKER_NOTE_FIXUP;
                    unset($et->MAKER_NOTE_FIXUP);
                }
            }
        }

        if ($offsetInfo) {
            // calculate image data hash if requested
            if ($doHash) self::AddImageDataHash($et, $dirInfo, $offsetInfo);
            // validate image data offsets for this IFD (note: modifies %offsetInfo)
            if ($validate) Validate::ValidateOffsetInfo($et, $offsetInfo, $dirName, $inMakerNotes);
        }

        // scan for subsequent IFD's if specified
        if (($dirInfo['Multi'] ?? null) and $bytesFromEnd >= 4) {
            // use same directory information for trailing directory,
            // but change the start location (ProcessDirectory will
            // test to make sure we don't reprocess the same dir twice)
            $newDirInfo = $dirInfo;
            $newDirInfo['Multi'] = 0;  // prevent recursion
            $newDirInfo['OffsetName'] = $nextOffName;
            $et->INDENT = preg_replace('/..$/', '', Perl::str($et->INDENT), 1); // keep indent the same
            for (;;) {
                $offset = ExifTool::Get32u($dataPt, $dirEnd);
                if (!$offset) break;
                $newDirInfo['DirStart'] = $offset - $dataPos;
                // increment IFD number
                $ifdNum = 0;
                if (preg_match('/(\d+)$/', Perl::str($newDirInfo['DirName']), $m)) {
                    $ifdNum = $m[1];
                    $newDirInfo['DirName'] = preg_replace('/(\d+)$/', '', $newDirInfo['DirName'], 1);
                }
                $newDirInfo['DirName'] .= $ifdNum + 1;
                // must validate SubIFD1 because the nextIFD pointer is invalid for some RAW formats
                if ($newDirInfo['DirName'] !== 'SubIFD1' or self::ValidateIFD($newDirInfo)) {
                    $cur = array_pop($et->PATH);
                    if (!$et->ProcessDirectory($newDirInfo, $tagTablePtr)) $success = 0;
                    $et->PATH[] = $cur;
                    if ($success and ($newDirInfo['BytesFromEnd'] ?? null) >= 4) {
                        $dataPt = $newDirInfo['DataPt'];
                        $dataPos = $newDirInfo['DataPos'];
                        $dirEnd = $newDirInfo['DirEnd'];
                        continue;
                    }
                } elseif ($verbose or $et->TIFF_TYPE === 'TIFF') {
                    $et->Warn('Ignored bad IFD linked from SubIFD');
                }
                break;
            }
        } elseif (isset($dirInfo['Multi'])) {
            // return necessary parameters for parsing next IFD
            $dirInfo['DirEnd'] = $dirEnd;
            $dirInfo['OffsetName'] = $nextOffName;
            $dirInfo['BytesFromEnd'] = $bytesFromEnd;
        }
        return $success;
    }

    //==============================================================================
    // Read-side subs of WriteExif.pl (the rest of that file is write-only, not ported)
    //==============================================================================

    //------------------------------------------------------------------------------
    // Inverse print conversion for LensInfo
    // Inputs: 0) lens info string
    // Returns: PrintConvInv of string
    public static function ConvertLensInfo($val)
    {
        $a = self::GetLensInfo($val, 1); // (allow unknown "?" values)
        return $a ? implode(' ', $a) : $val;
    }

    //------------------------------------------------------------------------------
    // rebuild maker notes to properly contain all value data
    // (some manufacturers put value data outside maker notes!!)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: new maker note data (and creates MAKER_NOTE_FIXUP), or undef on error
    public static function RebuildMakerNotes(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dataPt = $dirInfo['DataPt'];

        unset($et->MAKER_NOTE_FIXUP);

        // ponytail: rewriting the maker note IFD needs the writer (WriteDirectory, Fixup), which is
        // out of scope, so the original block is returned as Perl does for text, binary and preview
        // maker notes. Port the rest of WriteExif.pl RebuildMakerNotes() with the writer.
        return substr($dataPt, $dirStart, $dirLen);
    }

    //------------------------------------------------------------------------------
    // Validate IFD entries (strict validation to test possible chained IFD's)
    // Inputs: 0) dirInfo ref (must have RAF set), 1) optional DirStart
    // Returns: true if IFD looks OK
    public static function ValidateIFD(array &$dirInfo, $dirStart = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) return 0;
        $base = $dirInfo['Base'] ?? null;
        if ($dirStart === null) $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $offset = $dirStart + (($dirInfo['DataPos'] ?? null) ?: 0);
        $buff = null;
        if (!($raf->Seek($offset + $base, 0) and $raf->Read($buff, 2) == 2)) return 0;
        $numEntries = ExifTool::Get16u($buff, 0);
        if (!($numEntries > 1 and $numEntries < 64)) return 0;
        $len = 12 * $numEntries;
        if ($raf->Read($buff, $len) != $len) return 0;
        $lastID = -1;
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = 12 * $index;
            $tagID = ExifTool::Get16u($buff, $entry);
            if (!($tagID > $lastID or ($dirInfo['AllowOutOfOrderTags'] ?? null))) return 0;
            $format = ExifTool::Get16u($buff, $entry + 2);
            if (!($format > 0 and $format <= 13)) return 0;
            $count = ExifTool::Get32u($buff, $entry + 4);
            if (!($count > 0)) return 0;
            $lastID = $tagID;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Get sorted list of offsets used in IFD
    // Inputs: 0) data ref, 1) directory start, 2) dataPos, 3) IFD entries, 4) tag table ref
    // Returns: 0) sorted list of offsets (only offsets after the end of the IFD)
    //          1) hash of list indices keyed by offset value
    // Notes: This is used in a patch to fix the count for tags in Kodak SubIFD3
    public static function GetOffList($dataPt, $dirStart, $dataPos, $numEntries, TagTable $tagTablePtr)
    {
        $ifdEnd = $dirStart + 2 + 12 * $numEntries + $dataPos;
        $offHash = [];
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + 2 + 12 * $index;
            $format = ExifTool::Get16u($dataPt, $entry + 2);
            if ($format < 1 or $format > 13) continue;
            $count = ExifTool::Get16u($dataPt, $entry + 4);
            $size = Vars\Exif::$formatSize[$format] * $count;
            if ($size <= 4) {
                $tagID = ExifTool::Get16u($dataPt, $entry);
                if (!($tagTablePtr[$tagID] instanceof TagInfo and $tagTablePtr[$tagID]->FixCount)) continue;
            }
            $offset = ExifTool::Get16u($dataPt, $entry + 8);
            if ($offset >= $ifdEnd) $offHash[$offset] = 1;
        }
        // set offset hash values to indices in list
        $offList = array_keys($offHash);
        sort($offList, SORT_STRING);
        $index = 0;
        foreach ($offList as $offset) {
            $offHash[$offset] = $index++;
        }
        return [$offList, $offHash];
    }

    //------------------------------------------------------------------------------
    // Validate image data size
    // Inputs: 0) ExifTool ref, 1) validate info hash ref,
    //         2) flag to issue error (ie. we're writing)
    // - issues warning or error if problems found
    public static function ValidateImageData(ExifTool $et, array $vInfo, $dirName, $errFlag = null)
    {
        // determine the expected size of the image data for an uncompressed image
        // (0x102 BitsPerSample, 0x103 Compression and 0x115 SamplesPerPixel
        //  all default to a value of 1 if they don't exist)
        if ((!isset($vInfo[0x103]) or Perl::str($vInfo[0x103]) === '1') and
            ($vInfo[0x100] ?? null) and ($vInfo[0x101] ?? null) and (($vInfo[0x117] ?? null) or ($vInfo[0x145] ?? null)))
        {
            $samplesPerPix = ($vInfo[0x115] ?? null) ?: 1;
            $bitsPerSample = ($vInfo[0x102] ?? null) ? Perl::split(' ', $vInfo[0x102]) :
                array_fill(0, max(0, Perl::int($samplesPerPix)), 1);
            $byteCountInfo = ($vInfo[0x117] ?? null) ?: $vInfo[0x145];
            $byteCounts = $byteCountInfo[1];
            $totalBytes = 0;
            foreach (Perl::split(' ', $byteCounts) as $_) $totalBytes += Perl::num($_);
            $minor = null;
            if ($et->DOC_NUM or $et->FILE_TYPE !== 'TIFF') $minor = 1;
            if (count($bitsPerSample) != $samplesPerPix) {
                if (!($et->FILE_TYPE === 'EPS' and count($bitsPerSample) == 1)) {
                    // (just a warning for this problem)
                    $s = Perl::str($samplesPerPix) === '1' ? '' : 's';
                    $et->Warn("$dirName BitsPerSample should have $samplesPerPix value$s", $minor);
                }
                while (count($bitsPerSample) < $samplesPerPix) $bitsPerSample[] = $bitsPerSample[0];
                foreach ($bitsPerSample as $_) {
                    if (Perl::str($_) !== Perl::str($bitsPerSample[0])) $et->Warn("$dirName BitsPerSample values are different", $minor);
                    if (Perl::num($_) < 1 or Perl::num($_) > 32) $et->Warn("Invalid $dirName BitsPerSample value", $minor);
                }
            }
            $bitsPerPixel = 0;
            foreach ($bitsPerSample as $_) $bitsPerPixel += Perl::num($_);
            $expectedBytes = Perl::int((Perl::num($vInfo[0x100]) * Perl::num($vInfo[0x101]) * $bitsPerPixel + 7) / 8);
            if ($expectedBytes != $totalBytes and
                // (this problem seems normal for certain types of RAW files...)
                !preg_match('/^(K25|KDC|MEF|ORF|SRF)$/', Perl::str($et->TIFF_TYPE)))
            {
                $adj = $minor = null;
                if ($expectedBytes > $totalBytes) {
                    $adj = 'Under'; // undersized is a bigger problem because we may lose data
                    if (!$errFlag) $minor = 0;
                } else {
                    $adj = 'Over';
                    $minor = 1;
                }
                $msg = "{$adj}sized $dirName {$byteCountInfo[0]->Name} ($totalBytes bytes, but expected $expectedBytes)";
                if ($minor === null) {
                    // this is a serious error if we are writing the file and there
                    // is a chance that we may not copy all of the image data
                    // (but make it minor to allow the file to be written anyway)
                    $et->Error($msg, 1);
                } else {
                    $et->Warn($msg, $minor);
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Add specified image data to ImageDataHash hash
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) lookup for [tagInfo,value] based on tagID
    public static function AddImageDataHash(ExifTool $et, array &$dirInfo, array $offsetInfo)
    {
        $verbose = $et->Options('Verbose');
        $raf = $dirInfo['RAF'] ?? null;

        $tagIDs = array_keys($offsetInfo);
        sort($tagIDs, SORT_STRING);
        foreach ($tagIDs as $tagID) {
            if (!is_array($offsetInfo[$tagID])) continue; // ignore scalar tag values used for Validate
            $tagInfo = $offsetInfo[$tagID][0];
            if (!$tagInfo->IsImageData) continue;     // only consider image data
            $sizeID = $tagInfo->OffsetPair;
            $sizes = [];
            if ($tagInfo->NotRealPair) {
                $sizes = [999999999];     // (Panasonic hack: raw data runs to end of file)
            } elseif ($sizeID and ($offsetInfo[$sizeID] ?? null)) {
                $sizes = Perl::split(' ', $offsetInfo[$sizeID][1]);
            } else {
                continue;
            }
            $offsets = Perl::split(' ', $offsetInfo[$tagID][1]);
            if ($tagInfo->NotRealPair) $sizes[0] = 999999999;
            $total = 0;
            foreach ($offsets as $offset) {
                $size = array_shift($sizes);
                if (!(preg_match('/^\d+$/', $offset) and $size and preg_match('/^\d+$/', $size) and $size)) continue;
                if (!$raf->Seek($offset, 0)) continue; // (offset is absolute)
                $total += $et->ImageDataHash($raf, $size);
            }
            if ($verbose) {
                $name = ($dirInfo['DirName'] ?? '') . ":{$tagInfo->Name}";
                $name = preg_replace('/Offsets?|Start$/', '', $name, 1);
                $et->VPrint(0, "{$et->INDENT}(ImageDataHash: $total bytes of $name data)\n");
            }
        }
    }

    //------------------------------------------------------------------------------
    // Read/Write IFD with TIFF-like header (used by DNG 1.2)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: Reading: 1 on success, otherwise returns 0 and sets a Warning
    //          (writing is not ported)
    public static function ProcessTiffIFD(?ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if (!$et) return 1;    // allow dummy access
        $raf = $dirInfo['RAF'] ?? null;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $dirName = $dirInfo['DirName'] ?? null;
        $magic = ($dirInfo['Subdir']['Magic'] ?? null) ?: 0x002a;
        $buff = null;

        // structured with a TIFF-like header and relative offsets
        if (!($raf->Seek($base, 0) and $raf->Read($buff, 8) == 8)) return 0;
        if (!(ExifTool::SetByteOrder(substr($buff, 0, 2)) and ExifTool::Get16u($buff, 2) == $magic)) {
            $msg = "Invalid $dirName header";
            $et->Warn($msg);
            return 0;
        }
        $offset = ExifTool::Get32u($buff, 4);
        $tiffDirInfo = [   // (Perl %dirInfo)
            'DirName'  => $dirInfo['DirName'] ?? null,
            'Parent'   => $dirInfo['Parent'] ?? null,
            'Base'     => $base,
            'DataPt'   => $buff,
            'DataLen'  => strlen($buff),
            'DataPos'  => 0,
            'DirStart' => $offset,
            'DirLen'   => strlen($buff) - $offset,
            'RAF'      => $raf,
            'NewDataPos' => 8,
        ];
        if ($et->HTML_DUMP) {
            $tip = Perl::sprintf("Byte order: %s endian\nIdentifier: 0x%.4x\n%s offset: 0x%.4x",
                              (ExifTool::GetByteOrder() === 'II') ? 'Little' : 'Big', $magic, $dirName, $offset);
            $et->HDump($base, 8, "$dirName header", $tip, 0);
        }
        return self::ProcessExif($et, $tiffDirInfo, $tagTablePtr);
    }
}
