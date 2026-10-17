<?php

//------------------------------------------------------------------------------
// File:         PICT.pm
//
// Description:  Read PICT meta information
//
// Revisions:    10/10/2005 - P. Harvey Created
//
// Notes:        Extraction of PICT opcodes is still experimental
//
// - size difference in PixPat color table?? (imagemagick reads only 1 long per entry)
// - other differences in the way imagemagick reads 16-bit images
//
// References:   1) http://developer.apple.com/documentation/mac/QuickDraw/QuickDraw-2.html
//               2) http://developer.apple.com/documentation/QuickTime/INMAC/QT/iqImageCompMgr.a.htm
//
// PHP port of Image::ExifTool::PICT 1.05 (reading only). Tag tables are generated
// into tables/PICT.php; the file lexicals ($vers, $extended, $verbose, $out, $indent,
// @reserved, %structs) are in Vars\PICT.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PICT
{
    public static $VERSION = '1.05';

    //------------------------------------------------------------------------------
    // Get PixData data
    // Inputs: 0) reference to PixMap, 1) RAF reference
    // Returns: reference to PixData or undef on error
    public static function GetPixData($pixMap, $raf)
    {
        $packType = $pixMap['packType'];
        $rowBytes = $pixMap['rowBytes'] & 0x3fff;   // remove flags bits
        $height = $pixMap['bounds']['botRight']['v'] -
                  $pixMap['bounds']['topLeft']['v'];
        $data = $size = $buff = null;

        if ($packType == 1 or $rowBytes < 8) {  // unpacked data
            $size = $rowBytes * $height;
            if ($raf->Read($data, $size) != $size) return null;
        } elseif ($packType == 2) {             // pad byte dropped
            $size = Perl::int($rowBytes * $height * 3 / 4 + 0.5);
            if ($raf->Read($data, $size) != $size) return null;
        } else {
            $data = '';
            for ($i = 0; $i < $height; ++$i) {
                if ($rowBytes > 250) {
                    if ($raf->Read($buff, 2) != 2) return null;
                    $size = Perl::unpack('n', $buff)[0];
                } else {
                    if ($raf->Read($buff, 1) != 1) return null;
                    $size = Perl::unpack('C', $buff)[0];
                }
                $data .= $buff;
                if ($raf->Read($buff, $size) != $size) return null;
                $data .= $buff;
            }
        }
        return new ScalarRef($data);
    }

    //------------------------------------------------------------------------------
    // (PHP: Perl evals the bracketed counts and the code refs of %structs with %val in
    //  scope: here are their translations, keyed by the Perl text with spaces collapsed)
    private static function EvalCount($expr, array $val)
    {
        $expr = preg_replace('/\s+/', ' ', trim($expr));
        return match ($expr) {
            '$val{dataSize}'    => $val['dataSize'] ?? null,
            '$val{ctSize}+1'    => Perl::num($val['ctSize'] ?? 0) + 1,
            '$val{matteSize} ? 1 : 0' => ($val['matteSize'] ?? null) ? 1 : 0,
            '$val{matteSize}'   => $val['matteSize'] ?? null,
            '$val{maskSize}'    => $val['maskSize'] ?? null,
            '$val{nameLen}'     => $val['nameLen'] ?? null,
            '$val{size} - $val{nameLen} - 3' => Perl::num($val['size'] ?? 0) - Perl::num($val['nameLen'] ?? 0) - 3,
            '$val{size}-86'     => Perl::num($val['size'] ?? 0) - 86,
            '$val{count}'       => $val['count'] ?? null,
            '$val{size}'        => $val['size'] ?? null,
            '$val{patType} == 2 ? 1 : 0' => ($val['patType'] ?? null) == 2 ? 1 : 0,
            '$val{patType} == 2 ? 0 : 1' => ($val['patType'] ?? null) == 2 ? 0 : 1,
            '($val{polySize}-10)/2' => (Perl::num($val['polySize'] ?? 0) - 10) / 2,
            '$val{rgnSize}-10'  => Perl::num($val['rgnSize'] ?? 0) - 10,
            // (note: $val{mattSize} is a typo in ExifTool, so it is always undef)
            '$val{size} - 68 - $val{maskSize} - $val{imageDescr}->{size} - ($val{matteSize} ? $val{mattSize} + $val{matteDescr}->{size} : 0)'
                => Perl::num($val['size'] ?? 0) - 68 - Perl::num($val['maskSize'] ?? 0) - Perl::num($val['imageDescr']['size'] ?? 0) -
                   (($val['matteSize'] ?? null) ? 0 + Perl::num($val['matteDescr']['size'] ?? 0) : 0),
            '$val{size} - 50 - ($val{matteSize} ? $val{mattSize} + $val{matteDescr}->{size} : 0)'
                => Perl::num($val['size'] ?? 0) - 50 -
                   (($val['matteSize'] ?? null) ? 0 + Perl::num($val['matteDescr']['size'] ?? 0) : 0),
            default => throw new \RuntimeException("Unknown PICT expression: $expr"),
        };
    }

    //------------------------------------------------------------------------------
    // Read value from PICT file
    // Inputs: 0) RAF reference, 1) tag, 2) format, 3) optional count
    // Returns: value, reference to structure hash, or undef on error
    // (PHP: structure hash = associative array, binary data = ScalarRef)
    public static function ReadPictValue($raf, $tag, $format, $count = null)
    {
        $out = Vars\PICT::$out;
        if (!$format) return null;
        if ($count === null) {
            if (preg_match('/(.+)\[(.+)\]/s', $format, $m)) {
                $format = $m[1];
                $count = $m[2];
            } else {
                $count = 1; // count undefined: assume 1
            }
        }
        $cntStr = ($count == 1) ? '' : '[' . Perl::str($count) . ']';
        // no size if count is 0
        $size = $count ? ExifTool::FormatSize($format) : 0;
        if ($size !== null or $format === 'null') {
            $val = null;
            if ($size) {
                $buff = null;
                $size *= $count;
                if ($raf->Read($buff, $size) != $size) return null;
                $val = ExifTool::ReadValue($buff, 0, $format, $count, $size);
            } else {
                $val = '';
            }
            if (Vars\PICT::$verbose) {
                fwrite($out, Vars\PICT::$indent . "$tag ($format$cntStr)");
                if ($size) {
                    if ($val === null) {
                        fwrite($out, " = <undef>\n");
                    } elseif ($format === 'binary') {
                        fwrite($out, " = <binary data>\n");
                        if (Vars\PICT::$verbose > 2) {
                            $parms = ['Out' => $out];
                            if (Vars\PICT::$verbose < 4) $parms['MaxLen'] = 96;
                            ExifTool::HexDump($val, null, ...$parms);
                        }
                    } else {
                        fwrite($out, ' = ' . Perl::str($val) . "\n");
                    }
                } else {
                    fwrite($out, "\n");
                }
            }
            if ($format === 'binary' and $val !== null) return new ScalarRef($val);
            return $val;
        }
        if (Vars\PICT::$verbose) fwrite($out, Vars\PICT::$indent . "$tag ($format$cntStr):\n");
        $struct = Vars\PICT::$structs[$format] ?? null;
        if (!$struct) return null;
        $vals = [];
        for ($c = 0; $c < $count; ++$c) {
            $valHash = [];      // (Perl %val)
            for ($i = 0; ; $i += 2) {
                $tag = $struct[$i] ?? null;
                if (!$tag) break;
                $fmt = $struct[$i + 1];
                $cnt = $val = null;
                Vars\PICT::$indent .= '  ';
                if (preg_match('/^GetPixData\(/', $fmt)) {   // (Perl: ref $fmt)
                    // GetPixData($val{pixMap}, $raf)
                    $val = self::GetPixData($valHash['pixMap'] ?? null, $raf);
                    if (Vars\PICT::$verbose and $val !== null) {
                        fwrite($out, Perl::sprintf(Vars\PICT::$indent . "$tag (binary[%d]) = <binary data>\n", strlen($val->value)));
                        if (Vars\PICT::$verbose > 2) {
                            $parms = ['Out' => $out];
                            if (Vars\PICT::$verbose < 4) $parms['MaxLen'] = 96;
                            ExifTool::HexDump($val->value, null, ...$parms);
                        }
                    }
                } elseif (preg_match('/(.+)\[(.+)\]/s', $fmt, $m)) {
                    $fmt = $m[1];
                    try {
                        $cnt = self::EvalCount($m[2], $valHash);
                    } catch (\Throwable $e) {
                        ExifTool::PerlWarn($e->getMessage() . "\n");
                    }
                    $val = self::ReadPictValue($raf, $tag, $fmt, $cnt);
                } else {
                    $val = self::ReadPictValue($raf, $tag, $fmt);
                }
                Vars\PICT::$indent = substr(Vars\PICT::$indent, 2);
                if ($val === null) return null;
                $valHash[$tag] = $val;
            }
            if ($count == 1) return $valHash;
            $vals[] = $valHash;
        }
        return $vals;
    }

    //------------------------------------------------------------------------------
    // Extract meta information from a PICT image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PICT image
    public static function ProcessPICT(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        Vars\PICT::$verbose = $et->Options('Verbose');
        $out = Vars\PICT::$out = $et->Options('TextOut');
        Vars\PICT::$indent = '';
        $buff = $tried = $op = $hRes = $vRes = null;
        $hdr = [];

        // recognize both PICT files and PICT resources (PICT files have a
        // 512-byte header that we ignore, but PICT resources do not)
        for (;;) {
            if ($raf->Read($buff, 12) != 12) return 0;
            $hdr = Perl::unpack('x2n5', $buff);
            $op = array_pop($hdr);
            // check for PICT version 1 format
            if ($op == 0x1101) {
                Vars\PICT::$vers = 1;
                Vars\PICT::$extended = null;
                break;
            }
            // check for PICT version 2 format
            if ($op == 0x0011) {
                if ($raf->Read($buff, 28) != 28) return 0;
                if (preg_match('/^\x02\xff\x0c\x00\xff\xff/', $buff)) {
                    Vars\PICT::$vers = 2;
                    Vars\PICT::$extended = null;
                    break;
                }
                if (preg_match('/^\x02\xff\x0c\x00\xff\xfe/', $buff)) {
                    Vars\PICT::$vers = 2;
                    Vars\PICT::$extended = 1;
                    [$hRes, $vRes] = Perl::unpack('x8N2', $buff);
                    break;
                }
            }
            if ($tried) return 0;
            $tried = 1;
            if (!$raf->Seek(512, 0)) return 0;
        }
        $vers = Vars\PICT::$vers;
        // make the bounding rect signed
        foreach ($hdr as &$_) {
            if ($_ >= 0x8000) $_ -= 0x10000;
        }
        unset($_);
        $w = $hdr[3] - $hdr[1];
        $h = $hdr[2] - $hdr[0];
        if (!($w > 0 and $h > 0)) return 0;

        ExifTool::SetByteOrder('MM');

        if (Vars\PICT::$extended) {
            // extended version 2 pictures contain resolution information
            // and image bounds are in 72-dpi equivalent units
            $hRes = ExifTool::GetFixed32s($buff, 8);
            $vRes = ExifTool::GetFixed32s($buff, 12);
            if (!($hRes and $vRes)) return 0;
            $w = Perl::int($w * $hRes / 72 + 0.5);
            $h = Perl::int($h * $vRes / 72 + 0.5);
        }
        $et->SetFileType();
        $et->FoundTag('ImageWidth', $w);
        $et->FoundTag('ImageHeight', $h);
        if ($hRes) $et->FoundTag('XResolution', $hRes);
        if ($vRes) $et->FoundTag('YResolution', $vRes);

        // don't extract image opcodes unless verbose
        if (!(Vars\PICT::$verbose or $et->Options('Unknown'))) return 1;

        if (Vars\PICT::$verbose) fwrite($out, Perl::sprintf("PICT version $vers%s\n", Vars\PICT::$extended ? ' extended' : ''));

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PICT::Main');

        $success = null;
        for (;;) {
            if ($vers == 1) {
                if ($raf->Read($buff, 1) != 1) break;
                $op = ord($buff);
            } else {
                // must start version 2 opcode on an even byte
                if ($raf->Tell() & 0x01) $raf->Read($buff, 1);
                if ($raf->Read($buff, 2) != 2) break;
                $op = Perl::unpack('n', $buff)[0];
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $op);
            if (!$tagInfo) {
                $reserved = Vars\PICT::$reserved;
                // search for reserved tag info
                for ($i = 0; $i < count($reserved); $i += 2) {
                    if (!($op >= $reserved[$i])) continue;
                    if ($op > $reserved[$i + 1]) break;
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $reserved[$i]);
                    break;
                }
                if (!$tagInfo) break;
            }
            if ($op == 0xff) {
                if (Vars\PICT::$verbose) fwrite($out, "End of picture\n");
                $success = 1;
                break;
            }
            $format = $tagInfo->Format;
            if (!$format) {
                $et->Warn("Missing format for $tagInfo->Name");
                break;
            }
            // replace version number for version-dependent formats
            $format = preg_replace('/#$/', (string) $vers, $format, 1);
            $wid = $vers * 2;
            if (Vars\PICT::$verbose) fwrite($out, Perl::sprintf("Tag 0x%.{$wid}x, ", $op));
            $val = self::ReadPictValue($raf, $tagInfo->Name, $format);
            if ($val === null) {
                $et->Warn("Error reading $tagInfo->Name information");
                break;
            }
            if (is_array($val) and !array_is_list($val)) {
                // extract JPEG image from CompressedQuickTime imageData
                if ($tagInfo->Name === 'CompressedQuickTime' and
                    is_array($val['imageDescr'] ?? null) and !array_is_list($val['imageDescr']) and
                    ($val['imageDescr']['compressor'] ?? null) and
                    $val['imageDescr']['compressor'] === 'Photo - JPEG' and
                    ($val['imageData'] ?? null) instanceof ScalarRef and
                    $et->ValidateImage($val['imageData'], 'PreviewImage'))
                {
                    $et->FoundTag('PreviewImage', $val['imageData']);
                }
            } else {
                // $et->FoundTag($tagInfo, $val);
            }
        }
        if (!$success) $et->Warn('End of picture not found');
        return 1;
    }
}
