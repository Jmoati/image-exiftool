<?php

//------------------------------------------------------------------------------
// File:         Nikon.pm
//
// Description:  Nikon EXIF maker notes tags
//
// Revisions:    12/09/2003 - P. Harvey Created
//               05/17/2004 - P. Harvey Added information from Joseph Heled
//               09/21/2004 - P. Harvey Changed tag 2 to ISOUsed & added PrintConv
//               12/01/2004 - P. Harvey Added default PRINT_CONV
//               01/01/2005 - P. Harvey Decode preview image and preview IFD
//               03/35/2005 - T. Christiansen additions
//               05/10/2005 - P. Harvey Decode encrypted lens data
//               [ongoing]  - P. Harvey Constantly decoding new information
//
// References:   (see Nikon.pm)
//
// PHP port of Image::ExifTool::Nikon 4.58 (reading only). Tag tables are generated into
// tables/Nikon.php, %nikonLensIDs, the AF point maps, @xlat and the decryption state
// ($ci0, $cj0, $ck0, $decryptStart) into Vars\Nikon. Not ported (writing): PrintAFPointsInv,
// PrintAFPointsGridInv, PrintPCInv and the IsWriting/OutFile branches.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Nikon
{
    public static $VERSION = '4.58';

    //------------------------------------------------------------------------------
    // Process Nikon AVI tags (D5000 videos)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonAVI(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirEnd = $pos + $dirInfo['DirLen'];
        $et->VerboseDir($dirInfo, null, $dirInfo['DirLen']);
        ExifTool::SetByteOrder('II');
        while ($pos + 4 <= $dirEnd) {
            $tag = ExifTool::Get16u($dataPt, $pos);
            $size = ExifTool::Get16u($dataPt, $pos + 2);
            $pos += 4;
            if ($pos + $size > $dirEnd) break;
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt: $dataPt,
                Start: $pos,
                Size: $size,
            );
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Print conversion for Nikon AF points
    // Inputs: 0) value to convert (as a string of hex bytes),
    //         1) lookup for AF point bit number (starting at 1), or array ref
    //            (PHP: a Perl hash is a non-list array, a Perl array a list)
    public static function PrintAFPoints($val, $afPoints)
    {
        $points = [];
        $val = str_replace(' ', '', Perl::str($val));   // remove spaces from hex string
        $dat = Perl::unpack('C*', Perl::pack('H*', $val)); // convert to array of bytes
        $isHash = !array_is_list($afPoints);
        // loop through all bytes to find active AF points
        for ($i = 0; $i <= count($dat); ++$i) {
            if (empty($dat[$i])) continue;
            for ($j = 0; $j < 8; ++$j) {
                if (!($dat[$i] & (1 << $j))) continue;
                $point = $isHash ? ($afPoints[$i * 8 + $j + 1] ?? null) : ($afPoints[$i * 8 + $j] ?? null);
                if ($point !== null) $points[] = $point;
            }
        }
        if (!$points) return '(none)';
        // sort the points and return as comma-separated string
        usort($points, static function ($a, $b) {
            if (strlen($a) == strlen($b)) return strcmp($a, $b) <=> 0;
            if (strlen($a) == 2) return strcmp(substr($a, 0, 1) . '0' . substr($a, 1, 1), $b) <=> 0;
            return strcmp($a, substr($b, 0, 1) . '0' . substr($b, 1, 1)) <=> 0;
        });
        return implode(',', $points);
    }

    //------------------------------------------------------------------------------
    // Get AF point name for grid-type AF
    // Inputs: 0) AF point number, 1) number of columns, 2) true for inverse conversion
    // Returns: AF point name, or undef
    public static function GetAFPointGrid($val, $ncol, $inv = null)
    {
        if ($inv) {
            if (!preg_match('/^([A-J])(\d+)$/i', Perl::str($val), $m)) return null;
            return (ord(strtoupper($m[1])) - 65) * $ncol + $m[2] - 1;
        } else {
            $row = ((int) Perl::int((Perl::num($val) + 0.5) / $ncol)) & 0xff;
            $col = Perl::num($val) - $ncol * $row + 1;
            return chr(65 + $row) . Perl::str($col);
        }
    }

    //------------------------------------------------------------------------------
    // Print conversion for grid-type AF points
    // Inputs: 0) value to convert (as a string of hex bytes),
    //         1) number of columns in grid
    public static function PrintAFPointsGrid($val, $ncol)
    {
        $points = [];
        $val = str_replace(' ', '', Perl::str($val));   // remove spaces from hex string
        $dat = Perl::unpack('C*', Perl::pack('H*', $val)); // convert to array of bytes
        // loop through all bytes to find active AF points
        for ($i = 0; $i < count($dat); ++$i) {
            if (!$dat[$i]) continue;
            for ($j = 0; $j < 8; ++$j) {
                if (!($dat[$i] & (1 << $j))) continue;
                $point = self::GetAFPointGrid($i * 8 + $j, $ncol);
                if ($point !== null) $points[] = $point;
            }
        }
        if (!$points) return '(none)';
        return implode(',', $points);   // return as comma-separated string
    }

    //------------------------------------------------------------------------------
    // Print conversion for relative Left/Right AF points (ref 28)
    // Inputs: 0) column, 1) number of columns
    // Returns: AF point data as a string (e.g. '2L of Center' or 'C' or '3R of Center')
    public static function PrintAFPointsLeftRight($col, $ncol)
    {
        $col = Perl::num($col);
        $center = ($ncol + 1) / 2;
        if ($col == 0) return 'n/a';    //out of focus
        if ($col == $center) return 'C';
        if ($col < $center) return Perl::sprintf('%d', $center - $col) . 'L of Center';
        if ($col > $center) return Perl::sprintf('%d', $col - $center) . 'R of Center';
        return '';  // (value of the last failed condition in Perl)
    }

    //------------------------------------------------------------------------------
    // Print conversion for relative Up/Down AF points (ref 28)
    // Inputs: 0) row, 1) number of rows
    // Returns: AF point data as a string (e.g. '2U from Center' or 'C' or '3D from Center')
    public static function PrintAFPointsUpDown($row, $nrow)
    {
        $row = Perl::num($row);
        $center = ($nrow + 1) / 2;
        if ($row == 0) return 'n/a';    //out of focus
        if ($row == $center) return 'C';
        if ($row < $center) return Perl::sprintf('%d', $center - $row) . 'U from Center';
        if ($row > $center) return Perl::sprintf('%d', $row - $center) . 'D from Center';
        return '';  // (value of the last failed condition in Perl)
    }

    //------------------------------------------------------------------------------
    // Print PictureControl value
    // Inputs: 0) value (with 0x80 subtracted),
    //         1) 'Normal' (0 value) string (default 'Normal')
    //         2) format string for numbers (default '%+d'), 3) v2 divisor
    // Returns: PrintConv value
    public static function PrintPC($val, $norm = null, $fmt = null, $div = null)
    {
        $val = Perl::num($val);
        if ($val == 0) return $norm ?: 'Normal';
        if ($val == 0x7f) return 'n/a';
        if ($val == -128) return 'Auto';
        // -127 = custom curve created in Camera Control Pro (show as "User" by D3) - ref 28
        if ($val == -127) return 'User';    //28
        return Perl::sprintf($fmt ?: '%+d', $val / ($div ?: 1));
    }

    //------------------------------------------------------------------------------
    // Convert unknown LensID values
    // Inputs: 0) value, 1) flag for inverse conversion, 2) PrintConv hash ref
    public static function LensIDConv($val, $inv, $conv)
    {
        if ($inv) return null;
        $val = Perl::str($val);
        // multiple lenses with the same LensID are distinguished by decimal values
        if (!empty($conv["$val.1"])) {
            $vals = $user = [];
            for ($i = 1; ; ++$i) {
                $lens = $conv["$val.$i"] ?? null;
                if (!$lens) break;
                if (Vars\ExifTool::$userLens[$lens] ?? null) {
                    $user[] = $lens;
                } else {
                    $vals[] = $lens;
                }
            }
            if ($user) return implode(' or ', $user);
            return implode(' or ', $vals);
        }
        // (Perl /^$pat$/: the pattern is interpolated into a regex)
        $grep = static fn ($pat, array $list) => array_values(preg_grep('/^' . str_replace('/', '\/', $pat) . '$/', $list));
        // Sigma has been changing the LensIDNumber on some new lenses
        // and with some Sigma lenses the LensFStops changes! (argh!)
        // Also, older cameras my not set bits 4-7 of LensType
        $pat = $val;
        $pat = preg_replace('/^\w+ \w+/', '.. ..', $pat, 1);  // ignore LensIDNumber and LensFStops
        $pat = preg_replace('/\w(\w)$/', '.$1', $pat, 1);     // ignore bits 4-7 of LensType
        $ids = $grep($pat, array_map('strval', array_keys($conv)));
        sort($ids, SORT_STRING);
        if ($ids) {
            // first try different LensFStops (2nd value)
            $pat = preg_replace('/ \w+/', ' ..', $val, 1);
            $good = $grep($pat, $ids);
            if ($good) return $conv[$good[0]];
            // then try different LensIDNumber (1st value)
            $pat = preg_replace('/^\w+/', '..', $val, 1);
            $good = $grep($pat, $ids);
            if ($good) return "Unknown ($val) {$conv[$good[0]]} ?";
            // older cameras may not set bits 4-7 of LensType
            $pat = preg_replace('/\w(\w)$/', '.$1', $val, 1);
            $good = $grep($pat, $ids);
            if ($good) return "Unknown ($val) {$conv[$good[0]]} ?";
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Clean up formatting of string values
    // Inputs: 0) string value, 1) ExifTool ref
    // Returns: formatted string value
    // - removes trailing spaces and changes case to something more sensible
    public static function FormatString($str, $et)
    {
        $str = Perl::str($str);
        // limit string length (can be very long for some unknown tags)
        $lim = $et->Options('LimitLongValues');
        if (strlen($str) > $lim and $lim >= 5) {
            $str = substr($str, 0, $lim - 5) . '[...]';
        } else {
            $str = preg_replace('/\s+$/', '', $str);   // remove trailing white space
            // Don't change case of non-words (no vowels)
            if (preg_match('/[AEIOUY]/', $str)) {
                $lower = static fn ($m) => $m[1] . strtolower($m[2]);
                // change all letters but the first to lower case,
                // but only in words containing a vowel
                $str = preg_replace_callback('/\b([AEIOUY])([A-Z]+)/', $lower, $str, -1, $n);
                if ($n) {
                    $str = preg_replace('/\bAf\b/', 'AF', $str, 1);   // patch for "AF"
                    // patch for a number of models that write improper string
                    // terminator for ImageStabilization (VR-OFF, VR-ON)
                    $str = preg_replace('/  +.$/s', '', $str, 1);
                }
                $str = preg_replace_callback('/\b([A-Z])([A-Z]*[AEIOUY][A-Z]*)/', $lower, $str, -1, $n);
                if ($n) {
                    $str = preg_replace('/\bRaw\b/', 'RAW', $str, 1); // patch for "RAW"
                }
            }
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Decrypt Nikon data block (ref 4)
    // Inputs: 0) reference to data block, 1) optional start offset (default 0)
    //         2) optional number of bytes to decode (default to the end of the data)
    //         3) optional serial number key (undef to continue previous decryption)
    //         4) optional shutter count key
    // Returns: data block with specified data decrypted
    // Notes: The first time this is called for a given encrypted data block the serial/count
    //        keys must be defined, and $start must be the offset for initialization of the
    //        decryption parameters (ie. the beginning of the encrypted data, which isn't
    //        necessarily inside the data block if $len is zero).  Subsequent calls for
    //        the same data block do not specify the serial/count keys, and may be used
    //        to decrypt data at any start point within the full data block.
    //        (PHP: the decryption parameters are the file lexicals in Vars\Nikon)
    public static function Decrypt($dataPt, $start = null, $len = null, $serial = null, $count = null)
    {
        $dataPt = Perl::str($dataPt);
        $cj = $ck = null;

        if (!$start) $start = 0;
        $maxLen = strlen($dataPt) - $start;
        if ($len === null or $len > $maxLen) $len = $maxLen;
        if ($serial !== null and $count !== null) {
            // initialize decryption parameters
            $key = 0;
            foreach ([0, 1, 2, 3] as $_) $key ^= ((int) Perl::num($count) >> ($_ * 8)) & 0xff;
            Vars\Nikon::$ci0 = Vars\Nikon::$xlat[0][(int) Perl::num($serial) & 0xff];
            Vars\Nikon::$cj0 = Vars\Nikon::$xlat[1][$key];
            Vars\Nikon::$ck0 = 0x60;
            Vars\Nikon::$decryptStart = null;
        }
        $ci0 = (int) Vars\Nikon::$ci0;
        if (Vars\Nikon::$decryptStart !== null) {
            // initialize decryption parameters for this start position
            $n = $start - Vars\Nikon::$decryptStart;
            $cj = ((int) Vars\Nikon::$cj0 + $ci0 * ($n * Vars\Nikon::$ck0 + intdiv($n * ($n - 1), 2))) & 0xff;
            $ck = (Vars\Nikon::$ck0 + $n) & 0xff;
        } else {
            Vars\Nikon::$decryptStart = $start;
            [$cj, $ck] = [(int) Vars\Nikon::$cj0, (int) Vars\Nikon::$ck0];
        }
        if ($len <= 0) return $dataPt;
        $data = substr($dataPt, $start, $len);
        for ($i = 0, $end = strlen($data); $i < $end; ++$i) {
            $cj = ($cj + $ci0 * $ck) & 0xff;
            $ck = ($ck + 1) & 0xff;
            $data[$i] = chr(ord($data[$i]) ^ $cj);
        }
        return substr($dataPt, 0, $start) . $data . substr($dataPt, $start + $len);
    }

    //------------------------------------------------------------------------------
    // Get serial number for use as a decryption key
    // Inputs: 0) ExifTool object ref, 1) serial number string
    // Returns: serial key integer or undef if no serial number provided
    public static function SerialKey(ExifTool $et, $serial)
    {
        // use serial number as key if integral
        if ($serial === null or preg_match('/^\d+$/', Perl::str($serial))) return $serial;
        if (preg_match('/\bD50$/', Perl::str($et->Model))) return 0x22;    // D50 (ref 8)
        return 0x60;    // D200 (ref 10), D40X (ref PH), etc
    }

    //------------------------------------------------------------------------------
    // Extract information from "NIKON APP" trailer (ref PH)
    // Inputs: 0) ExifTool ref, 1) Optional dirInfo ref for returning trailer info
    // Returns: true on success
    public static function ProcessNikonApp(ExifTool $et, ?array &$dirInfo = null)
    {
        $raf = $et->RAF;
        $offset = $dirInfo ? (($dirInfo['Offset'] ?? null) ?: 0) : 0;
        $buff = null;

        if (!($raf->Seek(-20 - $offset, 2) and $raf->Read($buff, 20) == 20 and
            substr($buff, -16) === "\0\0\0\0\0\0/NIKON APP"))   // check magic number
        {
            return 0;
        }
        $verbose = $et->Options('Verbose');
        $fileEnd = $raf->Tell();
        $trailerLen = Perl::unpack('N', $buff)[0];
        if ($trailerLen > $fileEnd) {
            $et->Warn('Bad NikonApp trailer size');
            return 0;
        }
        if ($dirInfo) {
            $dirInfo['DirLen'] = $trailerLen;
            $dirInfo['DataPos'] = $fileEnd - $trailerLen;
            // (OutFile: writing not ported)
            if ($verbose or $et->HTML_DUMP) $et->DumpTrailer($dirInfo);
        }
        if (!($trailerLen >= 0x40 and $raf->Seek($fileEnd - $trailerLen, 0) and
              $raf->Read($buff, 0x40) == 0x40 and str_contains($buff, "NIKON APP\0")))
        {
            $et->Warn('Error reading NikonApp trailer');
            return 0;
        }
        $et->SET_GROUP0 = 'NikonApp';
        while ($raf->Read($buff, 8) == 8) {
            [$id, $len] = Perl::unpack('N2', $buff);
            if ($len & 0x80000000) {
                $et->Warn('Invalid NikonApp record length');
                break;
            }
            if ($id == 0 and $len == 0) break;
            if ($raf->Read($buff, $len) != $len) {
                $et->Warn('Truncated NikonApp record');
                break;
            }
            if ($id == 1) {
                ExifTool::RequireModule('Image::ExifTool::XMP');
                $xmpInfo = ['DataPt' => $buff];
                XMP::ProcessXMP($et, $xmpInfo);
            } else { // (haven't seen any other types of records)
                $et->Warn("Unknown NikonApp record $id");
                break;
            }
        }
        $et->SET_GROUP0 = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read Nikon NCTG tags in MOV videos
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonMOV(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $needTags = [0x110a431 => 0, 0x20000a7 => null];  // needed for decryption
        $dirName = Perl::str($dirInfo['DirName'] ?? null);
        $et->VerboseDir($dirInfo['DirName'] ?? null, 0, $dirInfo['DirLen']);
        // do two passes so we can pre-scan for necessary decryption keys
        for ($pass = 0; $pass < 2; ++$pass) {
            $pos = $dirInfo['DirStart'];
            $end = $pos + $dirInfo['DirLen'];
            while ($pos + 8 < $end) {
                $tag = ExifTool::Get32u($dataPt, $pos);
                $fmt = ExifTool::Get16u($dataPt, $pos + 4); // (same format code as EXIF)
                $count = ExifTool::Get16u($dataPt, $pos + 6);
                $pos += 8;
                $fmtStr = Vars\Exif::$formatName[$fmt] ?? null;
                if (!$fmtStr) {
                    if ($pass) $et->Warn(Perl::sprintf("Unknown format ($fmt) for $dirName tag 0x%x", $tag));
                    break;
                }
                $size = $count * Vars\Exif::$formatSize[$fmt];
                if ($pos + $size > $end) {
                    if ($pass) $et->Warn(Perl::sprintf("Truncated data for $dirName tag 0x%x", $tag));
                    break;
                }
                if ($pass) {
                    $rational = null;
                    $val = ExifTool::ReadValue($dataPt, $pos, $fmtStr, $count, $size, $rational);
                    $key = $et->HandleTag($tagTablePtr, $tag, $val,
                        DataPt: $dataPt,
                        DataPos: $dataPos,
                        Format: $fmtStr,
                        Start: $pos,
                        Size: $size,
                        Base: $dirInfo['Base'] ?? null,
                    );
                    if ($key) {
                        if ($rational) $et->TAG_EXTRA[$key]['Rational'] = $rational;
                        if ($et->OPTIONS['SaveBin'] ?? null) $et->TAG_EXTRA[$key]['BinVal'] = substr($dataPt, $pos, $size);
                    }
                } elseif (array_key_exists($tag, $needTags)) {
                    $needTags[$tag] = ExifTool::ReadValue($dataPt, $pos, $fmtStr, $count, $size);
                    $et->NikonSerialKey = self::SerialKey($et, $needTags[0x110a431]);
                    $et->NikonCountKey = $needTags[0x20000a7];
                }
                $pos += $size;  // is this padded to an even offset????
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Get offset of end-of-data for a tag
    // Inputs: 0) tag table ref, 1) tag ID, 2) true to not calculate end for a SubDirectory
    // Returns: offset of tag value end, undef if it can't be determined
    public static function GetTagEnd(TagTable $tagTablePtr, $tagID, $ignoreSubdir = null)
    {
        $tagInfo = $tagTablePtr[Perl::str($tagID)];
        if (is_array($tagInfo)) $tagInfo = $tagInfo[0];
        // (can't pre-determine position of offset-based subdirectories)
        if ($ignoreSubdir and $tagInfo?->SubDirectory) return null;
        $fmt = Perl::str($tagInfo?->Format ?: ($tagTablePtr['FORMAT'] ?: 'int8u'));
        $nm = 1;
        if (preg_match('/\[(\d+)\]$/', $fmt, $m, PREG_OFFSET_CAPTURE)) {
            $fmt = substr($fmt, 0, $m[0][1]);
            $nm = (int) $m[1][0];
        }
        $sz = ExifTool::FormatSize($fmt);
        if (!$sz) return null;
        return Perl::int($tagID) + $sz * $nm;
    }

    //------------------------------------------------------------------------------
    // Initialize SubDirectory KnownStart/KnownEnd limits of known tags (used in decryption)
    // Inputs: 0) tagInfo ref containing this SubDirectory, 2) tag table ref for encrypted subdir
    // Notes: KnownStart/KnownEnd are relative to the SubDirectory Start.  If KnownStart/KnownEnd
    //        aren't set then the entire data is decrypted, so all of this effort is just for speed.
    public static function InitEncryptedSubdir(TagInfo $tagInfo, TagTable $tagTablePtr): void
    {
        //
        // for encrypted NIKON_OFFSETS tables we loop through all SubDirectory tags in this table
        // and set the KnownEnd for each of these according to the last tag in the child tables
        //
        $tagTablePtr['VARS'] ??= [];
        if (!empty($tagTablePtr['VARS']['NIKON_OFFSETS']) and empty($tagTablePtr['VARS']['NIKON_INITIALIZED'])) {
            $tagTablePtr['VARS']['NIKON_INITIALIZED'] = 1;
            foreach (ExifTool::TagTableKeys($tagTablePtr) as $tagID) {
                $tagInfo2 = $tagTablePtr[$tagID];   // (Perl: my $tagInfo)
                if (!$tagInfo2 instanceof TagInfo) continue;
                $subdir = $tagInfo2->SubDirectory;
                if (!$subdir) continue;
                $tbl = ExifTool::GetTagTable($subdir['TagTable']);
                $keys = ExifTool::TagTableKeys($tbl);
                usort($keys, static fn ($a, $b) => Perl::num($b) <=> Perl::num($a)); // (reverse sort)
                $last = $keys[0] ?? null;
                $tagInfo2->SubDirectory['KnownEnd'] = self::GetTagEnd($tbl, $last, 1);
            }
        }
        //
        // for other encrypted Nikon tables we set the KnownStart/KnownEnd entries in the
        // SubDirectory of the parent tag
        //
        if (!$tagInfo->NikonInitialized) {
            $tagInfo->NikonInitialized = 1;
            $subdir = &$tagInfo->SubDirectory;
            $start = ($subdir['DecryptStart'] ?? null) ?: 0;
            $off = $subdir['DirOffset'] ?? null;
            $tagIDs = ExifTool::TagTableKeys($tagTablePtr);
            usort($tagIDs, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
            if ($off !== null) {
                $off += $start; // (DirOffset, if specified, is relative to DecryptStart)
            } else {
                // ignore tags that come before the start of encryption
                while ($tagIDs and Perl::num($tagIDs[0]) < $start) array_shift($tagIDs);
                $off = 0;
            }
            if ($tagIDs) {
                [$first, $last] = [$tagIDs[0], $tagIDs[count($tagIDs) - 1]];
                if (Perl::num($first) + $off > $start) $subdir['KnownStart'] = Perl::int($first) + $off;
                $subdir['KnownEnd'] = self::GetTagEnd($tagTablePtr, $last);
                if ($subdir['KnownEnd'] !== null) {
                    $subdir['KnownEnd'] += $off;
                } else {
                    ExifTool::PerlWarn("Internal error setting KnownEnd for {$tagTablePtr['SHORT_NAME']}\n");
                }
            } else {
                $subdir['KnownStart'] = $subdir['KnownEnd'] = $start;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Prepare to process NIKON_OFFSETS directory and decrypt necessary data
    // Inputs: 0) ExifTool ref, 1) data ref, 2) tag table ref, 3) decrypt start,
    //         4) decrypt mode (0=piecewise, 1=continuous to end of last known section, 2=all)
    // Returns: end of decrypted data (or undef for piecewise decryption)
    public static function PrepareNikonOffsets(ExifTool $et, string &$dataPt, TagTable $tagTablePtr, $start, $decryptMode)
    {
        $offset = $tagTablePtr['VARS']['NIKON_OFFSETS'] ?? null;
        $dataLen = strlen($dataPt);
        if ($offset + 4 > $dataLen or $offset < $start) return null;
        $serial = $et->NikonSerialKey;
        $count = $et->NikonCountKey;
        $dpos = $offset + 4;    // decrypt up to NumberOffsets
        $dataPt = self::Decrypt($dataPt, $start, $dpos - $start, $serial, $count);
        $numOffsets = ExifTool::Get32u($dataPt, $offset);
        $more = $numOffsets * 4;    // more bytes to decrypt entire offsets table
        if ($offset + 4 + $more > $dataLen) return null;
        $dataPt = self::Decrypt($dataPt, $dpos, $more);
        $dpos += $more;
        $unknown = $et->Options('Unknown');
        $offInfo = [];
        $end = null;
        // extract non-zero offsets and create unknown subdirectories if Unknown > 1
        for ($i = 0; $i < $numOffsets; ++$i) {
            $pos = $offset + 4 + 4 * $i;
            $off = ExifTool::Get32u($dataPt, $pos);
            if (!$off) continue;
            $tagInfo = $tagTablePtr[$pos];
            $known = 0;
            if ($tagInfo) {
                if (!$tagInfo instanceof TagInfo or !$tagInfo->Unknown) $known = 1;
            } elseif ($unknown > 1) {
                // create new table for unknown information
                $tbl = Perl::sprintf('Image::ExifTool::Nikon::UnknownInfo%.2x', $pos);
                if (!isset(ExifTool::$allTables[$tbl])) {
                    // (Perl: %$tbl = ( %binaryDataAttrs, GROUPS => {...} ); GetTagTable($tbl);
                    //  GetTagTable() only knows the generated tables, so do its setup here)
                    $newTable = new TagTable(Vars\Nikon::$binaryDataAttrs);
                    $newTable['TABLE_NAME'] = $tbl;
                    $newTable['SHORT_NAME'] = preg_replace('/^Image::ExifTool::/', '', $tbl);
                    $newTable['GROUPS'] = [0 => 'MakerNotes', 1 => 'Nikon', 2 => 'Unknown'];
                    $newTable['TAG_PREFIX'] = str_replace('::', '_', $newTable['SHORT_NAME']);
                    ExifTool::SetupTagTable($newTable);
                    ExifTool::$tableOrder[] = $tbl;
                    ExifTool::$allTables[$tbl] = $newTable;
                }
                // add unknown entry in offset table for this subdirectory
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $pos, [
                    'Name' => Perl::sprintf('UnknownOffset%.2x', $pos),
                    'Format' => 'int32u',
                    'SubDirectory' => ['TagTable' => $tbl],
                    'Unknown' => 2,
                ]);
            }
            $offInfo[] = [$pos, $off, $known];  // save parameters for non-zero offsets
        }
        // sort offsets in ascending order, and use the differences to calculate
        // directory lengths and update the SubDirectory DirLen's accordingly
        $sorted = $offInfo;
        usort($sorted, static fn ($a, $b) => ($a[1] <=> $b[1]) ?: ($a[0] <=> $b[0]));
        $sorted[] = [0, strlen($dataPt), 0];
        for ($i = 0; $i < count($sorted) - 1; ++$i) {
            $pos = $sorted[$i][0];
            $len = $sorted[$i + 1][1] - $sorted[$i][1];
            $tagInfo = $tagTablePtr[$pos];
            if ($tagInfo and !($tagInfo instanceof TagInfo and $tagInfo->AlwaysDecrypt)) {
                $tagInfo = $et->GetTagInfo($tagTablePtr, $pos);
            }
            // set DirLen in SubDirectory entry
            $subdir = null;
            if ($tagInfo instanceof TagInfo and $tagInfo->SubDirectory !== null) {
                $tagInfo->SubDirectory['DirLen'] = $len;
                $subdir = $tagInfo->SubDirectory;
            }
            if ($decryptMode) {
                // keep track of end of last known directory
                if ($sorted[$i][2]) $end = $sorted[$i + 1][1];
            } elseif ($tagInfo and (!$tagInfo instanceof TagInfo or !$tagInfo->Unknown)) {
                // decrypt data piecewise as necessary
                $n = $len;
                if ($subdir and !empty($subdir['KnownEnd'])) {
                    $n = $subdir['KnownEnd'];
                    if ($n > $len) {
                        if (!$tagInfo->AlwaysDecrypt) $et->Warn("Data too short for {$tagInfo->Name}", 1);
                        $n = $len;
                    }
                }
                $dataPt = self::Decrypt($dataPt, $sorted[$i][1], $n);
            }
        }
        if ($decryptMode) {
            // decrypt the remaining required data
            if ($decryptMode == 2 or !$end or $end < $dpos) $end = strlen($dataPt);
            $dataPt = self::Decrypt($dataPt, $dpos, $end - $dpos);
        }
        return $end;
    }

    //------------------------------------------------------------------------------
    // Read/Write Nikon Encrypted data block
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success when reading, or new directory when writing (IsWriting set)
    //          (PHP: writing not ported)
    public static function ProcessNikonEncrypted(?ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if (!$et) return 1;     // allow dummy access
        $serial = $et->NikonSerialKey;
        $count = $et->NikonCountKey;
        $isInt = static fn ($v) => (bool) preg_match('/^\d+$/', Perl::str($v));
        if (!($serial !== null and $count !== null and $isInt($serial) and $isInt($count))) {
            if ($serial !== null or $count !== null) {
                if ($serial !== null and $count !== null) {
                    $msg = $isInt($serial) ? 'invalid ShutterCount' : 'invalid SerialNumber';
                } else {
                    $msg = $serial !== null ? 'no ShutterCount' : 'no SerialNumber';
                }
                $et->Warn("Can't decrypt Nikon information ($msg key)");
            }
            unset($et->NikonSerialKey, $et->NikonCountKey);
            return 0;
        }
        $oldOrder = ExifTool::GetByteOrder();
        $verbose = $et->Options('Verbose');
        $tagInfo = $dirInfo['TagInfo'] ?? null;
        $dirStart = $dirInfo['DirStart'];
        $data = substr(Perl::str($dirInfo['DataPt']), $dirStart, $dirInfo['DirLen'] ?? null);

        $start = $len = $offset = $didDecrypt = null;

        if ($tagInfo and $tagInfo->SubDirectory) {
            // initialize SubDirectory entries used in encryption (KnownStart, KnownEnd)
            self::InitEncryptedSubdir($tagInfo, $tagTablePtr);
            $subdir = $tagInfo->SubDirectory;
            $start = ($subdir['DecryptStart'] ?? null) ?: 0;
            // DirOffset, if specified, is the offset to the start of the
            // directory relative to start of encrypted data
            $offset = isset($subdir['DirOffset']) ? $subdir['DirOffset'] + $start : 0;
            // must set byte ordering before calling PrepareNikonOffsets()
            if (!empty($subdir['ByteOrder'])) ExifTool::SetByteOrder($subdir['ByteOrder']);
            // prepare for processing NIKON_OFFSETS directory if necessary
            $unknown = $verbose > 2 || $et->Options('Unknown') > 1;
            // decrypt mode: 0=piecewise, 1=continuous to end of last known section, 2=all
            // (1 and the re-encryption are for writing)
            $dMode = $unknown ? 2 : 0;
            if (!empty($tagTablePtr['VARS']['NIKON_OFFSETS'])) {
                $len = self::PrepareNikonOffsets($et, $data, $tagTablePtr, $start, $dMode);
                $didDecrypt = 1;
            } elseif ($dMode < 2) {
                if ($dMode == 0 and !empty($subdir['KnownStart'])) {
                    // initialize decryption parameters for address DecryptStart address
                    self::Decrypt($data, $start, 0, $serial, $count);
                    // reset serial/count keys so we don't re-initialize below
                    $serial = $count = null;
                    // change decryption start to skip unnecessary data
                    $start = $subdir['KnownStart'];
                }
                if (!empty($subdir['KnownEnd'])) $len = $subdir['KnownEnd'] - $start;
            }
        } else {
            $start = $offset = 0;
        }
        $maxLen = strlen($data) - $start;
        // decrypt all the data unless the length was specified
        if (!($len and $len < $maxLen)) $len = $maxLen;

        if (!$didDecrypt) $data = self::Decrypt($data, $start, $len, $serial, $count);

        if ($verbose > 2) {
            $et->VerboseDir("Decrypted {$tagInfo?->Name}");
            $et->VerboseDump($data,
                Prefix: $et->INDENT . '  ',
                // (remove this because it is useful to have decrypted offsets start at 0)
                //DataPos: $dirStart + $dirInfo['DataPos'] + ($dirInfo['Base'] ?: 0),
            );
        }
        // process the decrypted information
        $subdirInfo = [
            'DataPt'   => $data,
            'DirStart' => $offset,
            'DirLen'   => strlen($data) - $offset,
            'DirName'  => $dirInfo['DirName'] ?? null,
            'DataPos'  => ($dirInfo['DataPos'] ?? 0) + $dirStart,
            'Base'     => $dirInfo['Base'] ?? null,
        ];
        $rtnVal = $et->ProcessBinaryData($subdirInfo, $tagTablePtr);
        ExifTool::SetByteOrder($oldOrder);
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Pre-scan EXIF directory to extract specific tags
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) required tagID hash ref
    // Returns: 1 if directory was scanned successfully
    public static function PrescanExif(ExifTool $et, array &$dirInfo, array &$tagHash)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? null);
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $dataLen = $dirInfo['DataLen'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $raf = $dirInfo['RAF'] ?? null;
        $numEntries = $data = $buff = null;

        // get number of entries in IFD
        if ($dirStart >= 0 and $dirStart <= $dataLen - 2) {
            $numEntries = ExifTool::Get16u($dataPt, $dirStart);
            // reset $numEntries to read from file if necessary
            if ($dirStart + 2 + 12 * $numEntries > $dataLen) $numEntries = null;
        }
        // read IFD from file if necessary
        if (!$numEntries) {
            if (!$raf) return 0;
            $dataPos += $dirStart;  // read data from the start of the directory
            if (!($raf->Seek($dataPos + $base, 0) and $raf->Read($data, 2) == 2)) return 0;
            $numEntries = ExifTool::Get16u($data, 0);
            $len = 12 * $numEntries;
            if ($raf->Read($buff, $len) != $len) return 0;
            $data .= $buff;
            // update variables for the newly loaded IFD (already updated dataPos)
            $dataPt = $data;
            $dataLen = strlen($data);
            $dirStart = 0;
        }
        // loop through Nikon MakerNote IFD entries
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + 2 + 12 * $index;
            $tagID = ExifTool::Get16u($dataPt, $entry);
            if (!array_key_exists($tagID, $tagHash)) continue;  // only extract required tags
            $format = ExifTool::Get16u($dataPt, $entry + 2);
            if ($format < 1 or $format > 13) continue;
            $count = ExifTool::Get32u($dataPt, $entry + 4);
            $size = $count * Vars\Exif::$formatSize[$format];
            $formatStr = Vars\Exif::$formatName[$format];
            $valuePtr = $entry + 8;     // pointer to value within $$dataPt
            if ($size > 4) {
                if ($size > 0x1000000) continue;    // set a reasonable limit on data size (16MB)
                $valuePtr = ExifTool::Get32u($dataPt, $valuePtr);
                // convert offset to pointer in $$dataPt
                // (don't yet handle EntryBased or FixOffsets)
                $valuePtr -= $dataPos;
                if ($valuePtr < 0 or $valuePtr + $size > $dataLen) {
                    if (!($raf and $raf->Seek($base + $valuePtr + $dataPos, 0) and
                          $raf->Read($buff, $size) == $size)) continue;
                    $tagHash[$tagID] = ExifTool::ReadValue($buff, 0, $formatStr, $count, $size);
                    continue;
                }
            }
            $tagHash[$tagID] = ExifTool::ReadValue($dataPt, $valuePtr, $formatStr, $count, $size);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Nikon Capture history data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonCaptureEditVersions(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        ExifTool::RequireModule('Image::ExifTool::NikonCapture');
        return NikonCapture::ProcessNikonCaptureEditVersions($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process Nikon Capture Offsets IFD (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: This isn't a normal IFD, but is close...
    public static function ProcessNikonCaptureOffsets(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $success = 0;
        if (!($dirLen > 2)) return 0;
        $count = ExifTool::Get16u($dataPt, $dirStart);
        if (!($count and $count * 12 + 2 <= $dirLen)) return 0;
        if ($et->Options('Verbose')) {
            $et->VerboseDir('NikonCaptureOffsets', $count);
        }
        for ($index = 0; $index < $count; ++$index) {
            $pos = $dirStart + 12 * $index + 2;
            $tagID = ExifTool::Get32u($dataPt, $pos);
            $value = ExifTool::Get32u($dataPt, $pos + 4);
            if ($et->HandleTag($tagTablePtr, $tagID, $value,
                Index: $index,
                DataPt: $dataPt,
                Start: $pos,
                Size: 12,
            )) $success = 1;
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Read Nikon NKA file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success
    public static function ProcessNKA(ExifTool $et, array &$dirInfo)
    {
        $raf = $et->RAF;
        $buff = null;
        if ($raf->Read($buff, 0x35) != 0x35) return 0;
        $len = Perl::unpack('x49V', $buff)[0];
        if ($raf->Read($buff, $len) != $len) return 0;
        $et->SetFileType('NKA', 'application/x-nikon-nxstudio');
        $xmlInfo = ['DataPt' => $buff, 'DataPos' => 0x35];  // (Perl: my %dirInfo)
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XMP::XML');
        return $et->ProcessDirectory($xmlInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Read/write Nikon MakerNotes directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning when reading
    //          or new directory when writing (IsWriting set in dirInfo)
    //          (PHP: writing not ported)
    public static function ProcessNikon(?ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if (!$et) return 1;     // allow dummy access

        // pre-scan IFD to get SerialNumber (0x001d) and ShutterCount (0x00a7) for use in decryption
        $needTags = [0x001d => 0, 0x00a7 => null];
        self::PrescanExif($et, $dirInfo, $needTags);
        $et->NikonSerialKey = self::SerialKey($et, $needTags[0x001d]);
        $et->NikonCountKey = $needTags[0x00a7];

        // process Nikon makernotes
        $rtnVal = Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
        unset($et->NikonSerialKey, $et->NikonCountKey);
        return $rtnVal;
    }
}
