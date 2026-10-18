<?php

//------------------------------------------------------------------------------
// File:         Canon.pm
//
// Description:  Canon EXIF maker notes tags
//
// Revisions:    11/25/2003 - P. Harvey Created
//               12/03/2003 - P. Harvey Decode lots more tags and add CanonAFInfo
//               02/17/2004 - Michael Rommel Added IxusAFPoint
//               01/27/2005 - P. Harvey Disable validation of CanonAFInfo
//               01/30/2005 - P. Harvey Added a few more tags (ref 4)
//               02/10/2006 - P. Harvey Decode a lot of new tags (ref 12)
//               [ongoing]  - P. Harvey Constantly decoding new information
//
// Notes:        Must check FocalPlaneX/YResolution values for each new model!
//
// References:   (see Canon.pm)
//
// PHP port of Image::ExifTool::Canon 5.07. Tag tables are generated into
// tables/Canon.php, %canonLensTypes and %canonModelID into Vars\Canon. Not ported:
// the inverse conversion of CameraISO (writing).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Canon
{
    public static $VERSION = '5.07';

    //------------------------------------------------------------------------------
    // Return lens name with teleconverter if applicable
    // Inputs: 0) lens name string, 1) short focal length
    // Returns: lens string with tc if appropriate
    public static function LensWithTC($lens, $shortFocal)
    {
        $lens = Perl::str($lens);
        // add teleconverter multiplication factor if applicable
        // (and if the LensType doesn't already include one)
        if (!preg_match('/x$/', $lens) and preg_match('/(\d+)/', $lens, $m)) {
            $sf = $m[1];    // short focal length
            foreach ([1, 1.4, 2, 2.8] as $tc) {
                if (abs(Perl::num($shortFocal) - $sf * $tc) > 0.9) continue;
                if ($tc > 1) $lens .= ' + ' . Perl::str($tc) . 'x';
                break;
            }
        }
        return $lens;
    }

    //------------------------------------------------------------------------------
    // Attempt to calculate sensor size for Canon cameras
    // Inputs: 0) ExifTool ref
    // Returns: Sensor diagonal size in mm, or undef
    // Notes: This algorithm is fairly reliable, but has been found to give incorrect
    //        values for some firmware versions of the EOS 20D, A310, SD40 and IXUS 65
    // (ref http://wyw.dcweb.cn/download.asp?path=&file=jhead-2.96-ccdwidth_hack.zip)
    public static function CalcSensorDiag(ExifTool $et)
    {
        // calculation is based on the rational value of FocalPlaneX/YResolution
        // (most Canon cameras store the sensor size in the denominator)
        if (!(($et->TAG_EXTRA['FocalPlaneXResolution'] ?? null) and
              ($et->TAG_EXTRA['FocalPlaneYResolution'] ?? null))) return null;
        $xres = $et->TAG_EXTRA['FocalPlaneXResolution']['Rational'] ?? null;
        $yres = $et->TAG_EXTRA['FocalPlaneYResolution']['Rational'] ?? null;
        if (!($xres and $yres)) return null;
        // assumptions: 1) numerators are image width/height * 1000
        // 2) denominators are sensor width/height in inches * 1000
        $xres = array_map(Perl::num(...), Perl::split('{[ /]}', $xres)) + [0, 0];
        $yres = array_map(Perl::num(...), Perl::split('{[ /]}', $yres)) + [0, 0];
        // verify assumptions as best we can:
            // numerators are always divisible by 1000
        if (Perl::mod($xres[0], 1000) == 0 and Perl::mod($yres[0], 1000) == 0 and
            // at least 640x480 pixels (DC models - PH)
            $xres[0] >= 640000 and $yres[0] >= 480000 and
            // ... but not too big!
            $xres[0] < 10000000 and $yres[0] < 10000000 and
            // minimum sensor size is 0.061 inches (DC models - PH)
            $xres[1] >= 61 and $xres[1] < 1500 and
            $yres[1] >= 61 and $yres[1] < 1000 and
            // sensor isn't square (may happen if rationals have been reduced)
            $xres[1] != $yres[1])
        {
            return sqrt($xres[1] * $xres[1] + $yres[1] * $yres[1]) * 0.0254;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Attempt to identify the specific lens if multiple lenses have the same LensType
    // Inputs: 0) PrintConv hash ref, 1) LensType, 2) MinFocalLength, 3) MaxFocalLength
    //         4) MaxAperture, 5) LensModel
    // Notes: PrintConv, LensType, MinFocalLength and MaxFocalLength must be defined.
    //        Other inputs are optional.
    public static function PrintLensID($printConv, $lensType, $shortFocal = null, $longFocal = null, $maxAperture = null, $lensModel = null)
    {
        $lensType = Perl::str($lensType);
        $lensModel = Perl::str($lensModel);
        $shortFocal = Perl::num($shortFocal);
        $longFocal = Perl::num($longFocal);
        $maxAperture = Perl::num($maxAperture);
        $lens = null;
        if (!($lensType === '-1' or $lensType === '65535')) $lens = $printConv[$lensType] ?? null;
        if ($lens) {
            // return this lens unless other lenses have the same LensType
            if (!($printConv["$lensType.1"] ?? null)) return self::LensWithTC($lens, $shortFocal);
            $lens = preg_replace('/ or .*/s', '', $lens, 1);    // remove everything after "or"
            // make list of all possible matching lenses
            $lenses = [$lens];
            for ($i = 1; $printConv["$lensType.$i"] ?? null; ++$i) {
                $lenses[] = $printConv["$lensType.$i"];
            }
            $user = $maybe = $likely = $matches = [];
            // look for lens in user-defined lenses
            foreach ($lenses as $lens) {
                if (Vars\ExifTool::$userLens[$lens] ?? null) $user[] = $lens;
            }
            $tcs = [1, 1.4, 2, 2.8];
            if (preg_match('/ \+ ((EXTENDER )?RF)?(\d+(\.\d*)?)x\b/', $lensModel, $m)) $tcs = [$m[3]];
            // attempt to determine actual lens
            foreach ($tcs as $tc) {  // loop through teleconverter scaling factors
                $tcNum = Perl::num($tc);
                foreach ($lenses as $lens) {
                    if (!preg_match('/(\d+)(?:-(\d+))?mm.*?(?:[fF]\/?)(\d+(?:\.\d+)?)(?:-(\d+(?:\.\d+)?))?/', $lens, $m)) continue;
                    // ($1=short focal, $2=long focal, $3=max aperture wide, $4=max aperture tele)
                    [$sf, $lf, $sa, $la] = [Perl::num($m[1]), Perl::num($m[2] ?? ''), Perl::num($m[3]), Perl::num($m[4] ?? '')];
                    // see if we can rule out this lens by focal length or aperture
                    if ($sf and !$lf) $lf = $sf;
                    if ($sa and !$la) $la = $sa;
                    // account for converter-specific LensType's (ie. end with " + #.#x")
                    if (preg_match('/ \+ (\d+(\.\d+)?)x$/', $lens, $m)) {
                        $sf *= Perl::num($m[1]);  $lf *= Perl::num($m[1]);
                        $sa *= Perl::num($m[1]);  $la *= Perl::num($m[1]);
                    }
                    if (abs($shortFocal - $sf * $tcNum) > 0.9) continue;
                    $tclens = $lens;
                    if (preg_match('/^(.*) \+ (RF)?(\d+(\.\d*)?)x$/', $lens, $m)) {
                        if ($m[3] !== Perl::str($tc)) continue;
                        // remove previous entry if same lens
                        $lns = '/^' . str_replace('/', '\/', $m[1]) . '/';
                        if ($maybe and preg_match($lns, $maybe[count($maybe) - 1])) array_pop($maybe);
                        if ($likely and preg_match($lns, $likely[count($likely) - 1])) array_pop($likely);
                        if ($matches and preg_match($lns, $matches[count($matches) - 1])) array_pop($matches);
                    } elseif ($tcNum > 1) {
                        $tclens .= ' + ' . Perl::str($tc) . 'x';
                    }
                    $maybe[] = $tclens;
                    if (abs($longFocal - $lf * $tcNum) > 0.9) continue;
                    $likely[] = $tclens;
                    if ($maxAperture) {
                        // (not 100% sure that TC affects MaxAperture, but it should!)
                        // (RF 24-105mm F4L IS USM shows a MaxAperture of 4.177)
                        if ($maxAperture < $sa * $tcNum - 0.18) continue;
                        if ($maxAperture > $la * $tcNum + 0.18) continue;
                    }
                    $matches[] = $tclens;
                }
                if ($maybe) break;
            }
            if ($user) {
                // choose the best match if we have more than one
                if (count($user) > 1) {
                    $good = [];
                    foreach ([$matches, $likely, $maybe] as $try) {
                        foreach ($try as $_) {
                            if (Vars\ExifTool::$userLens[$_] ?? null) {
                                $good[] = $_;
                                continue;
                            }
                            // check for match with TC string removed
                            if (!preg_match('/^(.*) \+ \d+(\.\d+)?x$/', $_, $m)) continue;
                            if (Vars\ExifTool::$userLens[$m[1]] ?? null) $good[] = $_;
                        }
                        if ($good) return implode(' or ', $good);
                    }
                }
                // default to returning the first user-defined lens
                return self::LensWithTC($user[0], $shortFocal);
            }
            // differentiate Sigma Art/Contemporary/Sports models
            if (count($matches) > 1 and $lensModel and preg_match('/(\| [ACS])/', $lensModel, $m)) {
                $type = $m[1];
                $best = [];
                foreach ($matches as $lens) {
                    if (str_contains($lens, $type)) $best[] = $lens;
                }
                if ($best) $matches = $best;
            }
            if (!$matches) $matches = $likely;
            if (!$matches) $matches = $maybe;
            // use LensModel focal length and aperture if necessary and available
            if (count($matches) > 1 and $lensModel and
                preg_match('{(\d+(?:\.\d+)?(?:-\d+(?:\.\d+)?)?) ?mm ?f/?(\d+(?:\.\d+)?(?:-\d+(?:\.\d+)?)?)}i', $lensModel, $m))
            {
                [$mm, $fstop] = [$m[1], $m[2]];
                $best = [];
                foreach ($matches as $lens) {
                    if (!preg_match('{(\d+(?:\.\d+)?(?:-\d+(?:\.\d+)?)?) ?mm ?f/?(\d+(?:\.\d+)?(?:-\d+(?:\.\d+)?)?)}i', $lens, $m)) continue;
                    if ($mm === $m[1] and $fstop === $m[2]) $best[] = $lens;
                }
                if ($best) $matches = $best;
            }

            Exif::MatchLensModel($matches, $lensModel);
            if ($matches) return implode(' or ', $matches);
        } elseif ($lensModel and preg_match('/\d/', $lensModel)) {
            // use lens model as written by the camera
            if ($printConv === Vars\Canon::$canonLensTypes) {
                // add "Canon" to the start since the Canon cameras only understand Canon lenses
                return "Canon $lensModel";
            } else {
                return $lensModel;
            }
        }
        $str = '';
        if ($shortFocal) {
            $str .= Perl::sprintf(' %d', $shortFocal);
            if ($longFocal and $longFocal != $shortFocal) $str .= Perl::sprintf('-%d', $longFocal);
            $str .= 'mm';
        }
        // (careful because Sigma LensType's may not be integer, so use string comparison)
        if ($lensType === '-1' or $lensType === '65535') return "Unknown$str";
        return "Unknown ($lensType)$str";
    }

    //------------------------------------------------------------------------------
    // Swap 16-bit words in 32-bit integers
    // Inputs: 0) string of integers
    // Returns: string of word-swapped integers
    public static function SwapWords($val)
    {
        $a = Perl::split(' ', $val);
        foreach ($a as &$_) {
            $_ = Perl::int($_);
            $_ = (($_ >> 16) | ($_ << 16)) & 0xffffffff;
        }
        unset($_);
        return implode(' ', $a);
    }

    //------------------------------------------------------------------------------
    // Validate first word of Canon binary data
    // Inputs: 0) data pointer, 1) offset, 2-N) list of valid values
    // Returns: true if data value is the same
    public static function Validate($dataPt, $offset, ...$vals)
    {
        // the first 16-bit value is the length of the data in bytes
        $dataVal = ExifTool::Get16u($dataPt, $offset);
        foreach ($vals as $val) {
            if (Perl::num($val) == $dataVal) return 1;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Validate CanonAFInfo
    // Inputs: 0) data pointer, 1) offset, 2) size
    // Returns: true if data appears valid
    public static function ValidateAFInfo($dataPt, $offset, $size)
    {
        if ($size < 24) return 0; // must be at least 24 bytes long (PowerShot Pro1)
        $af = ExifTool::Get16u($dataPt, $offset);
        if (!preg_match('/^(1|5|7|9|15|45|53)$/', Perl::str($af))) return 0; // check NumAFPoints
        $w1 = ExifTool::Get16u($dataPt, $offset + 4);
        $h1 = ExifTool::Get16u($dataPt, $offset + 6);
        if (!($h1 and $w1)) return 0;
        $f1 = $w1 / $h1;
        // check for normal aspect ratio
        if (abs($f1 - 1.33) < 0.01 or abs($f1 - 1.67) < 0.01) return 1;
        // ZoomBrowser can modify this for rotated images (ref Joshua Bixby)
        if (abs($f1 - 0.75) < 0.01 or abs($f1 - 0.60) < 0.01) return 1;
        $w2 = ExifTool::Get16u($dataPt, $offset + 8);
        $h2 = ExifTool::Get16u($dataPt, $offset + 10);
        if (!($h2 and $w2)) return 0;
        // compare aspect ratio with AF image size
        // (but the Powershot AFImageHeight is odd, hence the test above)
        if ($w1 == $h1) return 0;
        $f2 = $w2 / $h2;
        if (abs(1 - $f1 / $f2) < 0.01) return 1;
        if (abs(1 - $f1 * $f2) < 0.01) return 1;
        return 0;
    }

    //------------------------------------------------------------------------------
    // Read original decision data from file (variable length)
    // Inputs: 0) ExifTool object ref, 1) offset in file
    // Returns: reference to original decision data (or undef if no data)
    public static function ReadODD(ExifTool $et, $offset)
    {
        if (!$offset) return null;
        $buff = $buf2 = $warn = null;
        $raf = $et->RAF;
        if ($raf === null) return null;
        // the data block is a variable length and starts with 0xffffffff
        // followed a 4-byte (int32u) version number
        $pos = $raf->Tell();
        if ($raf->Seek(Perl::num($offset), 0) and $raf->Read($buff, 8) == 8 and preg_match('/^\xff{4}.\0\0/s', $buff)) {
            $err = 1;
            // must set byte order in case it is different than current byte order
            // (we could be reading this after byte order was changed)
            $oldOrder = ExifTool::GetByteOrder();
            $version = ExifTool::Get32u($buff, 4);
            if ($version > 20) {
                ExifTool::ToggleByteOrder();
                $version = Perl::unpack('N', Perl::pack('V', $version))[0];
            }
            if ($version == 1 or   // 1Ds (big endian)
                $version == 2)     // 5D/20D (little endian)
            {
                // this data is structured as follows:
                //  4 bytes: all 0xff
                //  4 bytes: version number (=1 or 2)
                // 20 bytes: sha1
                //  4 bytes: record count
                // for each record:
                // |  4 bytes: record number (beginning at 0)
                // |  4 bytes: block offset
                // |  4 bytes: block length
                // | 20 bytes: block sha1
                if ($raf->Read($buf2, 24) == 24) {
                    $buff .= $buf2;
                    $count = ExifTool::Get32u($buf2, 20);
                    // read all records if the count is reasonable
                    if ($count and $count < 20 and
                        $raf->Read($buf2, $count * 32) == $count * 32)
                    {
                        $buff .= $buf2;
                        $err = null;
                    }
                }
            } elseif ($version == 3) { // newer models (little endian)
                // this data is structured as follows:
                //  4 bytes: all 0xff
                //  4 bytes: version number (=3)
                // 24 bytes: sha1 A length (=20) + sha1 A
                // 24 bytes: sha1 B length (=20) + sha1 B
                //  4 bytes: length of remaining data (including this length word!)
                //  8 bytes: salt length (=4) + salt ?
                //  4 bytes: unknown (=3)
                //  4 bytes: size of file
                //  4 bytes: unknown (=1 for most models, 2 for 5DmkII)
                //  4 bytes: unknown (=1)
                //  4 bytes: unknown (always the same for a given firmware version)
                //  4 bytes: unknown (random)
                //  4 bytes: record count
                // for each record:
                // |  4 bytes: record number (beginning at 1)
                // |  8 bytes: salt length (=4) + salt ?
                // | 24 bytes: sha1 length (=20) + sha1
                // |  4 bytes: block count
                // | for each block:
                // | |  4 bytes: block offset
                // | |  4 bytes: block length
                // followed by zero padding to end of ODD data (~72 bytes)
                for ($i = 0; ; ++$i) {
                    if ($i == 3) {  // success!
                        $err = null;
                        break;
                    }
                    if ($raf->Read($buf2, 4) != 4) break;
                    $buff .= $buf2;
                    $len = ExifTool::Get32u($buf2, 0);
                    // (the data length includes the length word itself - doh!)
                    if ($i == 2 and $len >= 4) $len -= 4;
                    // make sure records are a reasonable size (<= 64kB)
                    if (!($len <= 0x10000 and $raf->Read($buf2, $len) == $len)) break;
                    $buff .= $buf2;
                }
            } else {
                $warn = "Unsupported original decision data version $version";
            }
            ExifTool::SetByteOrder($oldOrder);
            if (!$err) {
                if ($et->Options('HtmlDump')) {
                    $et->HDump($offset, strlen($buff), '[OriginalDecisionData]', null);
                }
                $raf->Seek($pos, 0);    // restore original file position
                return new ScalarRef($buff);
            }
        }
        $et->Warn($warn ?: 'Invalid original decision data');
        $raf->Seek($pos, 0);    // restore original file position
        return null;
    }

    //------------------------------------------------------------------------------
    // Convert the CameraISO value
    // Inputs: 0) value, 1) set for inverse conversion
    // (ponytail: the inverse conversion is for writing, not ported)
    public static function CameraISO($val, $inv = null)
    {
        $rtnVal = null;
        $isoLookup = [
             0 => 'n/a',
            14 => 'Auto High', //PH (S3IS)
            15 => 'Auto',
            16 => 50,
            17 => 100,
            18 => 200,
            19 => 400,
            20 => 800, //PH
        ];
        if ($inv) {
            $rtnVal = ExifTool::ReverseLookup($val, $isoLookup);
            if ($rtnVal === null and ExifTool::IsInt($val)) {
                $rtnVal = (Perl::int($val) & 0x3fff) | 0x4000;
            }
        } elseif (Perl::num($val) != 0x7fff) {
            if (Perl::int($val) & 0x4000) {
                $rtnVal = Perl::int($val) & 0x3fff;
            } else {
                $rtnVal = ($isoLookup[Perl::str($val)] ?? null) ?: 'Unknown (' . Perl::str($val) . ')';
            }
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Print range of focal lengths
    // Inputs: 0) short focal, 1) long focal, 2) optional scaling factor
    public static function PrintFocalRange($short, $long, $scale = null)
    {
        $short = Perl::num($short);
        $long = Perl::num($long);
        $scale = Perl::num($scale) ?: 1;
        if ($short == $long) {
            return Perl::sprintf('%.1f mm', $short * $scale);
        } else {
            return Perl::sprintf('%.1f - %.1f mm', $short * $scale, $long * $scale);
        }
    }

    //------------------------------------------------------------------------------
    // Process a serial stream of binary data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: The tagID's for serial stream tags are consecutive indices beginning
    //        at 0, and the corresponding values must be contiguous in memory.
    //        "Unknown" tags must be used to skip padding or unknown values.
    // (does not yet extract Rational values)
    public static function ProcessSerialData(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $offset = $dirInfo['DirStart'];
        $size = $dirInfo['DirLen'];
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;

        // temporarily set Unknown option so GetTagInfo() will return existing unknown tags
        // (require to maintain serial data synchronization)
        $unknown = $et->Options('Unknown', 1);
        // but disable unknown tag generation (because processing ends when we run out of tags)
        $et->NO_UNKNOWN = 1;

        if ($verbose) $et->VerboseDir('SerialData', null, $size);

        // get default format ('int8u' unless specified)
        $defaultFormat = $tagTablePtr['FORMAT'] ?: 'int8u';

        $valHash = [];  // (Perl %val)
        $pos = 0;
        for ($index = 0; $tagTablePtr[$index] and $pos <= $size; ++$index) {
            $tagInfo = $et->GetTagInfo($tagTablePtr, $index);
            if (!$tagInfo) break;
            $format = $tagInfo->Format;
            $count = 1;
            if ($format) {
                if (preg_match('/(.*)\[(.*)\]/', $format, $m)) {
                    $format = $m[1];
                    $count = $m[2];
                    // evaluate count to allow count to be based on previous values
                    //### eval Format (%val, $size)
                    $fc = $tagInfo->FormatCount;
                    if ($fc !== null) {
                        try {
                            $count = $fc($valHash, $size, null, $et);
                        } catch (\Throwable $e) {
                            ExifTool::PerlWarn("Format {$tagInfo->Name}: " . $e->getMessage());
                            break;
                        }
                    }
                    $count = Perl::num($count);
                } elseif ($format === 'string') {
                    // allow string with no specified count to run to end of block
                    $count = ($size > $pos) ? $size - $pos : 0;
                }
            } else {
                $format = $defaultFormat;
            }
            $len = (ExifTool::FormatSize($format) ?: 1) * $count;
            if ($pos + $len > $size) break;
            $val = ExifTool::ReadValue($dataPt, $pos + $offset, $format, $count, $size - $pos);
            if ($val === null) break;
            if ($verbose) {
                $et->VerboseInfo($index, $tagInfo,
                    Index: $index,
                    Table: $tagTablePtr,
                    Value: $val,
                    DataPt: $dataPt,
                    Size: $len,
                    Start: $pos + $offset,
                    Addr: $pos + $offset + $base + $dataPos,
                    Format: $format,
                    Count: $count,
                );
            }
            $valHash[$index] = $val;
            if ($tagInfo->SubDirectory) {
                $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                $subdirInfo = [    // (Perl: my %dirInfo)
                    'DataPt' => $val,
                    'DataPos' => $dataPos + $pos,
                    'DirStart' => 0,
                    'DirLen' => strlen(Perl::str($val)),
                ];
                $et->ProcessDirectory($subdirInfo, $subTablePtr);
            } elseif (!$tagInfo->Unknown or $unknown) {
                // don't extract zero-length information
                $key = $count ? $et->FoundTag($tagInfo, $val) : null;
                if ($key) {
                    if ($et->OPTIONS['SaveFormat']) $et->TAG_EXTRA[$key]['G6'] = $format;
                    if ($et->OPTIONS['SaveBin']) $et->TAG_EXTRA[$key]['BinVal'] = substr($dataPt, (int) ($pos + $offset), (int) $len);
                }
            }
            $pos += $len;
        }
        $et->Options('Unknown', $unknown);    // restore Unknown option
        $et->NO_UNKNOWN = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Print 1D AF points
    // Inputs: 0) value to convert
    // Focus point pattern:
    //            A1  A2  A3  A4  A5  A6  A7
    //      B1  B2  B3  B4  B5  B6  B7  B8  B9  B10
    //    C1  C2  C3  C4  C5  C6  C7  C9  C9  C10  C11
    //      D1  D2  D3  D4  D5  D6  D7  D8  D9  D10
    //            E1  E2  E3  E4  E5  E6  E7
    public static function PrintAFPoints1D($val)
    {
        $val = Perl::str($val);
        if (strlen($val) != 8) return 'Unknown';
        // list of focus point values for decoding the first byte of the 8-byte record.
        // they are the x/y positions of each bit in the AF point mask
        // (y is upper 3 bits / x is lower 5 bits)
        $focusPts = [0,0,
                  0x04,0x06,0x08,0x0a,0x0c,0x0e,0x10,         0,0,
          0x21,0x23,0x25,0x27,0x29,0x2b,0x2d,0x2f,0x31,0x33,
        0x40,0x42,0x44,0x46,0x48,0x4a,0x4c,0x4d,0x50,0x52,0x54,
          0x61,0x63,0x65,0x67,0x69,0x6b,0x6d,0x6f,0x71,0x73,  0,0,
                  0x84,0x86,0x88,0x8a,0x8c,0x8e,0x90,   0,0,0,0,0
        ];
        $focus = ord($val[0]);
        $bits = str_split(Perl::unpack('b*', substr($val, 1))[0]);
        $rows = str_split('  AAAAAAA  BBBBBBBBBBCCCCCCCCCCCDDDDDDDDDD  EEEEEEE     ');
        $focusing = null;
        $points = [];
        $lastRow = '';
        $col = 0;
        foreach ($focusPts as $focusPt) {
            $row = array_shift($rows);
            $col = ($row === $lastRow) ? $col + 1 : 1;
            $lastRow = $row;
            if ($focus == $focusPt) $focusing = "$row$col";
            if (array_shift($bits)) $points[] = "$row$col";
        }
        $focusing or $focusing = ($focus == 0xff) ? 'Auto' : Perl::sprintf('Unknown (0x%.2x)', $focus);
        return "$focusing (" . implode(',', $points) . ')';
    }

    //------------------------------------------------------------------------------
    // Convert Canon hex-based EV (modulo 0x20) to real number
    // Inputs: 0) value to convert
    // eg) 0x00 -> 0
    //     0x0c -> 0.33333
    //     0x10 -> 0.5
    //     0x14 -> 0.66666
    //     0x20 -> 1   ...  etc
    public static function CanonEv($val)
    {
        $val = Perl::num($val);
        // temporarily make the number positive
        if ($val < 0) {
            $val = -$val;
            $sign = -1;
        } else {
            $sign = 1;
        }
        $frac = Perl::int($val) & 0x1f;
        $val -= $frac;      // remove fraction
        // Convert 1/3 and 2/3 codes
        if ($frac == 0x0c) {
            $frac = 0x20 / 3;
        } elseif ($frac == 0x14) {
            $frac = 0x40 / 3;
        }
        return $sign * ($val + $frac) / 0x20;
    }

    //------------------------------------------------------------------------------
    // Convert number to Canon hex-based EV (modulo 0x20)
    // Inputs: 0) number
    // Returns: Canon EV code
    public static function CanonEvInv($num)
    {
        // (Perl warns at the first numeric use of a non-numeric value only)
        $num = Perl::numw($num, 'numeric lt (<)', '$num');
        // temporarily make the number positive
        if ($num < 0) {
            $num = -$num;
            $sign = -1;
        } else {
            $sign = 1;
        }
        $val = Perl::int($num);
        $frac = $num - $val;
        if (abs($frac - 0.33) < 0.05) {
            $frac = 0x0c;
        } elseif (abs($frac - 0.67) < 0.05) {
            $frac = 0x14;
        } else {
            $frac = Perl::int($frac * 0x20 + 0.5);
        }
        return $sign * ($val * 0x20 + $frac);
    }

    //------------------------------------------------------------------------------
    // Read CMT3 maker notes from CR3 file
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table ref
    // Returns: data block (may be empty if no Exif data) or undef on error
    public static function ProcessCMT3(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        // extract the static maker notes to copying to other file types if requested
        // Note: this won't copy makernotes in the timed metadata since these are stored
        // separately, but the only records they have that aren't in the static maker notes
        // (for the M50) are: ColorData9, Flavor, CanonCameraInfoUnknown,
        // VignettingCorrUnknown1, Canon_0x4033 and Canon_0x402e
        if (($et->Options('MakerNotes') or !empty($et->REQ_TAG_LOOKUP['makernotecanon'])) and
            $dirInfo['DirLen'] > 8)
        {
            // remove old (unused) trailer
            $dirInfo['DataPt'] = preg_replace('/(II\x2a\0|MM\0\x2a)\0{4,10}$/', '', Perl::str($dirInfo['DataPt']), 1);
            $dataPt = $dirInfo['DataPt'];
            // remove TIFF header and append as the Canon makernote trailer
            // (so offsets will be interpreted correctly)
            $val = substr($dataPt, 8) . substr($dataPt, 0, 8);
            $et->FoundTag(ExifTool::GetTagTable('Image::ExifTool::Canon::uuid')['CMT3'], new ScalarRef($val));
        }
        return $et->ProcessTIFF($dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process CTMD EXIF information
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessExifInfo(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $start);
        $dirEnd = $start + $dirLen;
        // loop through TIFF-format EXIF/MakerNote records
        for ($pos = $start; $pos + 8 < $dirEnd; $pos += $len) {
            $len = ExifTool::Get32u($dataPt, $pos);
            $tag = ExifTool::Get32u($dataPt, $pos + 4);
            // test size/tag for valid ExifInfo (not EXIF in CRM files)
            if ($len < 8 or $pos + $len > $dirEnd or !$tagTablePtr[$tag]) break;
            if ($pos == $start) $et->VerboseDir('ExifInfo', null, $dirLen);
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt: $dataPt,
                Base: Perl::num($dirInfo['Base'] ?? 0) + $pos + 8, // base for TIFF pointers
                DataPos: -($pos + 8), // (relative to Base)
                Start: $pos + 8,
                Size: $len - 8,
            );
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Canon Timed MetaData (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCTMD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $verbose = $et->Options('Verbose');
        $dirLen = strlen($dataPt);
        $pos = 0;
        ExifTool::SetByteOrder('II');
        while ($pos + 6 < $dirLen) {
            $size = ExifTool::Get32u($dataPt, $pos);
            $type = ExifTool::Get16u($dataPt, $pos + 4);
            // what is the meaning of the 6-byte header of these records?:
            //  type 1 - 00 00 00 01 zz zz - TimeStamp(CR3/CRM); zz=00(CR3),ff(CRM)
            //  type 3 - 00 00 00 01 zz zz - ? "ff ff ff ff"; zz=00(CR3),ff(CRM)
            //  type 4 - 00 00 00 01 ff ff - FocalInfo(CR3/CRM)
            //  type 5 - 00 00 00 01 ff ff - ExposureInfo(CR3/CRM)
            //  type 6 - 00 04 00 01 ff ff - ? "03 04 00 80 e0 15 ff ff"(CRM) [0x15e0 = ColorTemperature?]
            //  type 7 - xx yy 00 01 ff ff - ExifIFD + MakerNotes(CR3), ?(CRM); xxyy=0101(CR3),0004(CRM)
            //  type 8 - 01 yy 00 01 ff ff - MakerNotes(CR3), ?(CRM); yy=01(CR3),04(CRM)
            //  type 9 - 01 yy 00 01 ff ff - MakerNotes(CR3), ?(CRM); yy=01(CR3),00(CRM)
            //  type 10- 01 00 00 01 ff ff - ? (CRM)
            //  type 11- 01 00 00 01 ff ff - ? (CRM)
            // --> maybe yy == 01 for ExifInfo?
            if ($size < 12) {
                $et->Warn('Short CTMD record');
                break;
            }
            if ($pos + $size > $dirLen) {
                $et->Warn('Truncated CTMD record');
                break;
            }
            $et->VerboseDir("CTMD type $type", null, $size - 6);
            if ($verbose > 2) {
                ExifTool::HexDump($dataPt, 6,     // dump 6-byte header
                    Start: $pos + 6,
                    Addr: Perl::num($dirInfo['Base'] ?? 0) + $pos + 6,
                    Prefix: $et->INDENT,
                    Out: $et->Options('TextOut'),
                );
            }
            if ($tagTablePtr[$type]) {
                $et->HandleTag($tagTablePtr, $type, null,
                    DataPt: $dataPt,
                    Base: $dirInfo['Base'] ?? null,
                    Start: $pos + 12,
                    Size: $size - 12,
                );
            } elseif ($verbose) {
                $et->VerboseDump($dataPt, Len: $size - 12, Start: $pos + 12, DataPos: $dirInfo['Base'] ?? null);
            }
            $pos += $size;
        }
        if ($pos != $dirLen) $et->Warn('Error parsing Canon CTMD data', 1);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process a creative filter data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessFilters(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt']);
        $pos = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $end = $pos + $dirLen;
        $verbose = $et->Options('Verbose');

        if ($dirLen < 8) return 0;
        $numFilters = ExifTool::Get32u($dataPt, $pos + 4);
        if ($verbose) $et->VerboseDir('Creative Filter', $numFilters);
        $pos += 8;
        $err = null;
        for ($i = 0; $i < $numFilters; ++$i) {
            // read filter structure:
            // 4 bytes - filter number
            // 4 bytes - filter data length
            // 4 bytes - number of parameters:
            // |  4 bytes - parameter ID
            // |  4 bytes - parameter value count
            // |  4 bytes * count - parameter values (NC)
            if ($pos + 12 > $end) {
                $err = "Truncated data for filter $i";
                break;
            }
            $fnum = ExifTool::Get32u($dataPt, $pos); // (is this an index or an ID?)
            $size = ExifTool::Get32u($dataPt, $pos + 4);
            $nparm = ExifTool::Get32u($dataPt, $pos + 8);
            $nxt = $pos + 4 + $size;
            if ($nxt > $end) {
                $err = "Invalid size ($size) for filter $i";
                break;
            }
            if ($verbose) $et->VerboseDir("Filter $fnum", $nparm, $size);
            $pos += 12;
            for ($j = 0; $j < $nparm; ++$j) {
                if ($pos + 12 > $end) {
                    $err = "Truncated data for filter $i param $j";
                    break;
                }
                $tag = ExifTool::Get32u($dataPt, $pos);
                $count = ExifTool::Get32u($dataPt, $pos + 4);
                $pos += 8;
                if ($pos + 4 * $count > $end) {
                    $err = "Truncated value for filter $i param $j";
                    break;
                }
                $val = ExifTool::ReadValue($dataPt, $pos, 'int32s', $count, 4 * $count);
                $et->HandleTag($tagTablePtr, $tag, $val,
                    DataPt: $dataPt,
                    DataPos: $dataPos,
                    Start: $pos,
                    Size: 4 * $count,
                );
                $pos += 4 * $count;
            }
            $pos = $nxt;    // step to next filter
        }
        if ($err) $et->Warn($err, 1);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Write Canon maker notes
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table ref
    // Returns: data block (may be empty if no Exif data) or undef on error
    public static function WriteCanon(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;    // allow dummy access to autoload this package
        $dirData = Exif::WriteExif($et, $dirInfo, $tagTablePtr);
        // add footer which is written by some Canon models (format of a TIFF header)
        if ($dirData !== null and strlen($dirData) and ($dirInfo['Fixup'] ?? null)) {
            $dirData .= ExifTool::GetByteOrder() . ExifTool::Set16u(42) . ExifTool::Set32u(0);
            $dirInfo['Fixup']->AddFixup(strlen($dirData) - 4);
        }
        return $dirData;
    }
}
