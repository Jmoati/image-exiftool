<?php

//------------------------------------------------------------------------------
// File:         Validate.pm
//
// Description:  Additional metadata validation
//
// Created:      2017/01/18 - P. Harvey
//
// Notes:        My apologies for the convoluted logic contained herein, but it
//               is done this way to retro-fit the Validate feature into the
//               existing ExifTool code while avoiding a possible performance
//               hit to the main processing code when the Validate feature is
//               not used.
//
// PHP port:     The data (%exifSpec, %otherSpec, %stdFormat, %validValue...) is in
//               tables/vars/Validate.php, and the "Validate" tag is already in the
//               generated Extra table.  The Perl code of %validValue is translated in
//               ValidValueCode().
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Validate
{
    public static $VERSION = '1.27';

    //------------------------------------------------------------------------------
    // Validate the raw value of a tag
    // Inputs: 0) ExifTool ref, 1) tag key, 2) raw tag value
    // Returns: nothing, but issues a minor Warning if a problem was detected
    public static function ValidateRaw(ExifTool $self, $tag, $val): void
    {
        $tagInfo = $self->TAG_INFO[$tag] ?? new TagInfo();
        $wrn = null;

        // evaluate Validate code if specified
        if ($tagInfo->Validate) {
            ExifTool::$evalWarning = null;
            $err = null;
            //### eval Validate ($self, $val, $tagInfo)
            // (PHP: the tag-level Validate code of the tables is one of these two calls)
            if (preg_match('/^Validate(Exif|XMP)Date\(\$val\)$/', Perl::str($tagInfo->Validate), $m)) {
                $w = $m[1] === 'Exif' ? self::ValidateExifDate($val) : self::ValidateXMPDate($val);
            } else {
                $w = null;
                $err = 'not ported (' . Perl::str($tagInfo->Validate) . ')';
            }
            $err = ExifTool::$evalWarning ?: $err;
            if ($w or $err) {
                $name = Perl::str($tagInfo->Table['GROUPS'][0] ?? null) . ':' . ExifTool::GetTagName($tag);
                if ($err) $self->Warn("Validate $name: $err", 1);
                if ($w) $self->Warn("$w for $name", 1);
            }
        }
        // check for unknown values in PrintConv lookup for all standard EXIF tags
        if (is_array($tagInfo->PrintConv) and $tagInfo->Table and (Perl::str($tagInfo->Table['SHORT_NAME']) === 'GPS::Main' or
            ($tagInfo->Table === ExifTool::GetTagTable('Image::ExifTool::Exif::Main') and
             !empty(Vars\Validate::$exifSpec[$tagInfo->TagID]))))
        {
            $prt = $self->GetValue($tag, 'PrintConv');
            if ($prt and preg_match('/^Unknown \(/', Perl::str($prt))) $wrn = 'Unknown value for';
        }
        if (Perl::str($val) === 'undef') $wrn = 'Undefined value for';
        if ($wrn) {
            $name = $self->DIR_NAME . ':' . ExifTool::GetTagName($tag);
            $self->Warn("$wrn $name", 1);
        }
    }

    //------------------------------------------------------------------------------
    // Validate raw EXIF date/time value
    // Inputs: 0) date/time value
    // Returns: error string
    public static function ValidateExifDate($val)
    {
        $val = Perl::str($val);
        if (preg_match('/^\d{4}:(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $val, $m)) {
            $a = [$m[1], $m[2], $m[3], $m[4], $m[5]];
            $bad = [];
            for ($i = 0; $i < count($a); ++$i) {
                $f = Vars\Validate::$validDateField[$i];
                if ($a[$i] === '  ' or ($a[$i] >= $f[1] and $a[$i] <= $f[2])) continue;
                $bad[] = $f[0];
            }
            if ($bad) return implode('+', $bad) . ' out of range';
        // the EXIF specification allows blank fields or an entire blank value
        } elseif ($val !== '    :  :     :  :  ' and $val !== '                   ') {
            return 'Invalid date/time format';
        }
        return null;    // OK!
    }

    //------------------------------------------------------------------------------
    // Validate EXIF-reformatted XMP date/time value
    // Inputs: 0) date/time value
    // Returns: error string
    public static function ValidateXMPDate($val)
    {
        $val = Perl::str($val);
        if (preg_match('/^\d{4}$/', $val, $m) or
            preg_match('/^\d{4}:(\d{2})$/', $val, $m) or
            preg_match('/^\d{4}:(\d{2}):(\d{2})$/', $val, $m) or
            preg_match('/^\d{4}:(\d{2}):(\d{2}) (\d{2}):(\d{2})()(Z|[-+](\d{2}):(\d{2}))?$/', $val, $m, PREG_UNMATCHED_AS_NULL) or
            preg_match('/^\d{4}:(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})(Z|[-+](\d{2}):(\d{2}))?$/', $val, $m, PREG_UNMATCHED_AS_NULL) or
            preg_match('/^\d{4}:(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})\.?\d*(Z|[-+](\d{2}):(\d{2}))?$/', $val, $m, PREG_UNMATCHED_AS_NULL))
        {
            $a = [$m[1] ?? null, $m[2] ?? null, $m[3] ?? null, $m[4] ?? null, $m[5] ?? null, $m[7] ?? null, $m[8] ?? null];
            $bad = [];
            for ($i = 0; $i < count($a); ++$i) {
                if ($a[$i] === null) break;
                $f = Vars\Validate::$validDateField[$i];
                if ($a[$i] === '' or ($a[$i] >= $f[1] and $a[$i] <= $f[2])) continue;
                $bad[] = $f[0];
            }
            if ($bad) return implode('+', $bad) . ' out of range';
        } else {
            return 'Invalid date/time format';
        }
        return null;    // OK!
    }

    //------------------------------------------------------------------------------
    // Validate EXIF tag
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) tag ID, 3) tagInfo ref,
    //         4) previous tag ID, 5) IFD name, 6) number of values, 7) value format string
    // Returns: Nothing, but sets Warning tags if any problems are found
    public static function ValidateExif(ExifTool $et, TagTable $tagTablePtr, $tag, $tagInfo, $lastTag, $ifd, $count, $formatStr): void
    {
        $exifMain = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
        $otherSpec = Vars\Validate::$otherSpec;
        $fileType = Perl::str($et->FileType);

        if ($tag <= $lastTag) $et->Warn("Entries in $ifd are out of order");

        // (get tagInfo for unknown tags if Unknown option not used)
        if ($tagInfo === null and $tagTablePtr[$tag] instanceof TagInfo) {
            $tagInfo = $tagTablePtr[$tag];
        }
        if ($tagInfo !== null) {
            $ti = $tagInfo ?: $tagTablePtr[$tag];
            if (is_array($ti)) $ti = $ti[count($ti) - 1];
            if (!$ti instanceof TagInfo) $ti = new TagInfo(['Name' => $ti]);
            $stdFmt = (Vars\Validate::$stdFormat[$ifd] ?? null) ?: Vars\Validate::$stdFormat['IFD'];
            if (isset($stdFmt['All']) or ($tagTablePtr === $exifMain and
                (!empty(Vars\Validate::$exifSpec[$tag]) or !empty($stdFmt[$tag]) or
                ($tag >= 0xc612 and $tag <= 0xcd48 and !isset($stdFmt[$tag])))) or // (DNG tags)
                Perl::str($tagTablePtr['SHORT_NAME']) === 'GPS::Main')
            {
                $wgp = $ti->WriteGroup ?: $tagTablePtr['WRITE_GROUP'];
                if ($wgp and $wgp !== $ifd and $wgp !== 'All' and !$ti->OffsetPair and
                    (preg_match('/^(Sub|Profile)?IFD\d*$/', $ifd) xor preg_match('/^(Sub)?IFD\d*$/', $wgp)) and
                    ($ti->Writable or $ti->WriteGroup) and !preg_match('/^SRF\d+$/', $ifd))
                {
                    $et->Warn(Perl::sprintf('Wrong IFD for 0x%.4x %s (should be %s not %s)', $tag, $ti->Name, $wgp, $ifd));
                }
                $fmt = ($stdFmt[$tag] ?? null) ?: $ti->Writable;
                if ($fmt and !preg_match('/^' . $fmt . '$/', Perl::str($formatStr)) and (!$tagInfo or
                    !$tagInfo->IsOffset or !empty(Vars\Exif::$intFormat[$formatStr])))
                {
                    $et->Warn(Perl::sprintf('Non-standard format (%s) for %s 0x%.4x %s', $formatStr, $ifd, $tag, $ti->Name));
                }
            } elseif (!empty(Vars\Validate::$stdFormatAnyIFD[$tag])) {
                if ($ti->Writable ?: $ti->WriteGroup) {
                    $wgp = $ti->WriteGroup ?: $tagTablePtr['WRITE_GROUP'];
                    if ($wgp and $wgp !== $ifd) {
                        $et->Warn(Perl::sprintf('Wrong IFD for 0x%.4x %s (should be %s not %s)', $tag, $ti->Name, $wgp, $ifd));
                    }
                }
            } elseif (empty($otherSpec[$fileType]) or
                (empty($otherSpec[$fileType][$tag]) and empty($otherSpec[$fileType]['All'])))
            {
                if ($tagTablePtr === $exifMain or $ti->Unknown) {
                    if (empty($otherSpec[$ifd])) $et->Warn(Perl::sprintf('Non-standard %s tag 0x%.4x %s', $ifd, $tag, $ti->Name), 1);
                }
            }
            // change expected count from read Format to Writable size
            $tiCount = $ti->Count;
            if ($tiCount) {
                $formatNumber = Vars\Exif::$formatNumber;
                if ($ti->Format and $ti->Writable and
                    !empty($formatNumber[$ti->Format]) and
                    !empty($formatNumber[$ti->Writable]))
                {
                    $s1 = Vars\Exif::$formatSize[$formatNumber[$ti->Format]];
                    $s2 = Vars\Exif::$formatSize[$formatNumber[$ti->Writable]];
                    $tiCount = Perl::int($tiCount * $s1 / $s2);
                }
                if ($tiCount > 0 and $count != $tiCount) {
                    $et->Warn(Perl::sprintf('Non-standard count (%d) for %s 0x%.4x %s', $count, $ifd, $tag, $ti->Name));
                }
            }
        } elseif (empty($otherSpec[$fileType]) or
            (empty($otherSpec[$fileType][$tag]) and empty($otherSpec[$fileType]['All'])))
        {
            if (empty($otherSpec[$ifd])) $et->Warn(Perl::sprintf('Unknown %s tag 0x%.4x', $ifd, $tag), 1);
        }
    }

    //------------------------------------------------------------------------------
    // Validate image data offsets/sizes
    // Inputs: 0) ExifTool ref, 1) offset info hash ref (arrays of tagInfo/value pairs, keyed by tagID)
    //         2) directory name, 3) optional flag for minor warning
    public static function ValidateOffsetInfo(ExifTool $et, array &$offsetInfo, $dirName, $minor = null): void
    {
        $fileSize = $et->VALUE['FileSize'] ?? null;
        if (!$fileSize) return;

        // (don't test RWZ files and some other file types)
        if ($et->DontValidateImageData) return;
        // (Minolta A200 uses wrong byte order for these)
        if ($et->TIFF_TYPE === 'MRW' and $dirName === 'IFD0' and preg_match('/^DiMAGE A200/', Perl::str($et->Model))) return;
        // (don't test 3FR, RWL or RW2 files)
        if (preg_match('/^(3FR|RWL|RW2)$/', Perl::str($et->TIFF_TYPE))) return;

        Exif::ValidateImageData($et, $offsetInfo, $dirName);

        // loop through all offsets
        while ($offsetInfo) {
            $keys = array_map('strval', array_keys($offsetInfo));
            sort($keys, SORT_STRING);
            $id1 = $keys[0];
            $offsets = $offsetInfo[$id1];
            unset($offsetInfo[$id1]);
            if (!is_array($offsets)) continue;
            $id2 = $offsets[0]->OffsetPair;
            if (!($id2 !== null and !empty($offsetInfo[$id2]))) {
                if (!($offsets[0]->NotRealPair or ($id2 !== null and $id2 == -1))) {
                    $corr = $offsets[0]->IsOffset ? 'size' : 'offset';
                    if (!$minor) $et->Warn("$dirName:{$offsets[0]->Name} is missing the corresponding $corr tag");
                }
                continue;
            }
            $sizes = $offsetInfo[$id2];
            unset($offsetInfo[$id2]);
            if ($sizes[0]->IsOffset) [$sizes, $offsets] = [$offsets, $sizes];
            $offs = Perl::split(' ', $offsets[1]);
            $szs = Perl::split(' ', $sizes[1]);
            if (count($szs) != count($offs)) {
                $et->Warn(Perl::sprintf('Wrong number of values in %s 0x%.4x %s',
                                        $dirName, $offsets[0]->TagID, $offsets[0]->Name), $minor);
                continue;
            }
            while ($offs) {
                $start = Perl::num(array_pop($offs));
                $end = $start + Perl::num(array_pop($szs));
                if ($start == 0) $et->Warn("$dirName:{$offsets[0]->Name} is zero", $minor);
                if ($start == $end) $et->Warn("$dirName:{$sizes[0]->Name} is zero", $minor);
                if (!($end > $fileSize)) continue;
                if ($start >= $fileSize) {
                    if ($start == 0xffffffff) {
                        $et->Warn("$dirName:{$offsets[0]->Name} is invalid (0xffffffff)", $minor);
                    } else {
                        $et->Warn("$dirName:{$offsets[0]->Name} is past end of file", $minor);
                    }
                } else {
                    $et->Warn("$dirName:{$offsets[0]->Name}+{$sizes[0]->Name} runs past end of file", $minor);
                }
                break;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Finish Validating tags
    // Inputs: 0) ExifTool ref, 1) True to generate Validate tag
    public static function FinishValidate(ExifTool $et, $mkTag): void
    {
        $fileType = $et->FILE_TYPE ?: '';
        if ($fileType === 'TIFF') $fileType = $et->TIFF_TYPE;

        $validValues = Vars\Validate::$validValue[$fileType] ?? null;
        if ($validValues) {
            $grps = array_map('strval', array_keys($validValues));
            sort($grps, SORT_STRING);
            foreach ($grps as $grp) {
                if (empty($et->FOUND_DIR[$grp])) continue;
                $val = $info = [];
                $minor = $verTag = $ver = $vstr = null;
                $verCheck = Vars\Validate::$verCheck[$grp] ?? null;
                if ($verCheck) {
                    $verTag = array_key_first($verCheck);
                    $ver = preg_replace('/[^0-9]/', '', Perl::str($et->VALUE[$verTag] ?? null)); // (remove non-digits)
                    if (!preg_match('/^\d{4}$/', $ver)) $ver = null;  // (already warned if invalid version)
                }
                // get all tags in this group
                $keys = array_map('strval', array_keys($et->VALUE));
                sort($keys, SORT_STRING);
                foreach ($keys as $key) {
                    if ($et->GetGroup($key, 1) !== $grp) continue;
                    if (!empty($et->TAG_EXTRA[$key]['G3'])) continue;   // ignore sub-documents
                    // fill in %val lookup with values based on tag ID
                    $tag = $et->TAG_INFO[$key]->TagID;
                    $val[$tag] = $et->VALUE[$key];
                    // save TagInfo ref for later
                    $info[$tag] = $et->TAG_INFO[$key];
                    if ($ver === null) continue;
                    $chk = $verCheck[$verTag];
                    if (!isset($chk[$tag]) or $chk[$tag] == 1 or $ver >= $chk[$tag]) continue;
                    if ($verTag === 'GPSVersionID') {
                        $vstr = preg_replace('/^(\d)(\d)(\d)/', '$1.$2.$3.', Perl::str($chk[$tag]));
                    } else {
                        $vstr = Perl::sprintf('%.4d', $chk[$tag]);
                    }
                    $et->Warn(Perl::sprintf('%s tag 0x%.4x %s requires %s %s or higher',
                              $grp, $tag, $et->TAG_INFO[$key]->Name, $verTag, $vstr));
                }
                // make quick lookup for values based on tag ID
                $validValue = $validValues[$grp];
                $tags = array_keys($validValue);
                sort($tags, SORT_NUMERIC);
                foreach ($tags as $tag) {
                    $v = $val[$tag] ?? null;
                    $pre = $post = null;
                    if (isset($validValue[$tag])) {
                        //### eval ($val, %val)
                        $code = self::ValidValueCode($validValue[$tag]);
                        try {
                            $result = $code ? $code($v, $val) : null;
                        } catch (\Throwable) {
                            $result = null;
                        }
                        if ($result === null) {
                            $pre = 'Internal error validating';
                        } elseif ($result === '') {
                            $pre = $v !== null ? 'Invalid value for' : "Missing required $fileType";
                        } else {
                            if (Perl::str($result) === '1') continue;
                            $pre = $result;
                        }
                    } else {
                        if ($v === null) continue;
                        $post = "is not allowed in $fileType";
                        $minor = 1;
                    }
                    if (!empty($info[$tag])) {
                        $name = $info[$tag]->Name;
                    } else {
                        $table = 'Image::ExifTool::' . ($grp === 'GPS' ? 'GPS' : 'Exif') . '::Main';
                        $tagInfo = ExifTool::GetTagTable($table)[$tag];
                        if (is_array($tagInfo)) $tagInfo = $tagInfo[0];
                        $name = $tagInfo ? $tagInfo->Name : '<unknown>';
                    }
                    if ($et->WrongFormat and !empty($et->WrongFormat["$grp:$name"])) continue;
                    $pre = $pre ? "$pre " : '';
                    $post = $post ? ' ' . $post : '';
                    $et->Warn(Perl::sprintf('%s%s tag 0x%.4x %s%s', $pre, $grp, $tag, $name, $post), $minor);
                }
            }
        }
        // validate file extension
        if (Perl::str($et->FILENAME) !== '') {
            $fileExt = preg_match('/^.*\.([^.]+)$/s', $et->FILENAME, $m) ? strtoupper($m[1]) : '';
            $extFileType = ExifTool::GetFileType($fileExt);
            if ($extFileType and $extFileType !== $fileType) {
                $normExt = $et->VALUE['FileTypeExtension'] ?? null;
                if ($normExt and $normExt !== $fileExt) {
                    $lkup = Vars\ExifTool::$fileTypeLookup[$fileExt] ?? null;
                    if (is_array($lkup) or Perl::str($lkup) !== $normExt) {
                        $et->Warn("File has wrong extension (should be $normExt, not $fileExt)");
                    }
                }
            }
        }
        // issue warning if FastScan option used
        if ($et->Options('FastScan')) $et->Warn('Validation incomplete because FastScan option used');

        // generate Validate tag if necessary
        if ($mkTag) {
            $num = [
                !empty($et->VALUE['Error'])   ? (($et->DUPL_TAG['Error'] ?? 0) ?: 0) + 1 : 0,
                !empty($et->VALUE['Warning']) ? (($et->DUPL_TAG['Warning'] ?? 0) ?: 0) + 1 : 0,
                0,
            ];
            for ($key = 'Warning'; ;) {
                if (!empty($et->VALUE[$key]) and preg_match('/^\[minor\]/i', Perl::str($et->VALUE[$key]))) ++$num[2];
                $key = $et->NextTagKey($key);
                if (!$key) break;
            }
            $et->FoundTag('Validate', implode(' ', $num));
        }
    }

    //------------------------------------------------------------------------------
    // (PHP) Translation of the Perl code of %validValue
    // Inputs: 0) Perl code
    // Returns: closure ($val, %val) returning what the Perl code evaluates to
    //          (1 or '' for a true or false comparison), or undef if not translated
    private static function ValidValueCode($code): ?\Closure
    {
        $code = trim(preg_replace('/\s+/', ' ', Perl::str($code)));
        $match = static fn ($re, $val) => preg_match($re, Perl::str($val)) ? 1 : '';
        $defined = static fn ($val) => $val !== null ? 1 : '';
        return match ($code) {
            'defined $val' => static fn ($val) => $defined($val),
            'defined $val and $val =~ /^\d{4}$/' => static fn ($val) => $val !== null ? $match('/^\d{4}$/', $val) : '',
            'defined $val and $val =~ /^\d \d \d \d$/' => static fn ($val) => $val !== null ? $match('/^\d \d \d \d$/', $val) : '',
            '$val == 1 or $val == 0xffff' => static fn ($val) => (Perl::num($val) == 1 or Perl::num($val) == 0xffff) ? 1 : '',
            '$val == 6' => static fn ($val) => Perl::num($val) == 6 ? 1 : '',
            '$val =~ /^[12]$/' => static fn ($val) => $match('/^[12]$/', $val),
            '$val =~ /^[123]$/' => static fn ($val) => $match('/^[123]$/', $val),
            '$val =~ /^[0123]$/' => static fn ($val) => $match('/^[0123]$/', $val),
            'not defined $val or $val =~ /^[123]$/' => static fn ($val) => $val === null ? 1 : $match('/^[123]$/', $val),
            '$val =~ /^(GPS|CELLID|WLAN|MANUAL)$/' => static fn ($val) => $match('/^(GPS|CELLID|WLAN|MANUAL)$/', $val),
            'not defined $val or $val =~ /^(GPS|CELLID|WLAN|MANUAL)$/' =>
                static fn ($val) => $val === null ? 1 : $match('/^(GPS|CELLID|WLAN|MANUAL)$/', $val),
            'not defined $val or $val =~ /^(1|5|6|32773)$/ or ($val == 2 and (not defined $val{0x102} or $val{0x102} == 1));' =>
                static fn ($val, $vals) => ($val === null or $match('/^(1|5|6|32773)$/', $val) or
                    (Perl::num($val) == 2 and (!isset($vals[0x102]) or Perl::num($vals[0x102]) == 1))) ? 1 : '',
            'my $pi = $val{0x106} || 0; my $xtra = ($val{0x152} ? scalar(split \' \', $val{0x152}) : 0); ' .
            'if ($pi == 2 or $pi == 6) { return $val == 3 + $xtra; } elsif ($pi == 5) { return $val == 4 + $xtra; } ' .
            'else { return 1; }' => static function ($val, $vals) {
                $pi = ($vals[0x106] ?? 0) ?: 0;
                $xtra = !empty($vals[0x152]) ? count(Perl::split(' ', $vals[0x152])) : 0;
                if ($pi == 2 or $pi == 6) {
                    return Perl::num($val) == 3 + $xtra ? 1 : '';
                } elseif ($pi == 5) {
                    return Perl::num($val) == 4 + $xtra ? 1 : '';
                } else {
                    return 1;
                }
            },
            'return \'\' if defined $val{0x106} and $val{0x106} == 3 xor defined $val; ' .
            'return 1 if not defined $val or length($val) == 6 * 2 ** ($val{0x102} || 0); ' .
            'return \'Invalid count for\';' => static function ($val, $vals) {
                if ((isset($vals[0x106]) and Perl::num($vals[0x106]) == 3) xor $val !== null) return '';
                if ($val === null or strlen(Perl::str($val)) == 6 * 2 ** Perl::num(($vals[0x102] ?? 0) ?: 0)) return 1;
                return 'Invalid count for';
            },
            default => null,
        };
    }
}
