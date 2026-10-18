<?php

//------------------------------------------------------------------------------
// File:         MakerNotes.pm
//
// Description:  Read and write EXIF maker notes
//
// Revisions:    11/11/2004 - P. Harvey Created
//
// PHP port of Image::ExifTool::MakerNotes 2.19. The conditional list
// @Image::ExifTool::MakerNotes::Main is generated into the tag tables that use it
// (and into Vars\MakerNotes::$Main).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MakerNotes
{
    //------------------------------------------------------------------------------
    // Get normal offset of value data from end of maker note IFD
    // Inputs: 0) ExifTool object reference
    // Returns: Array: 0) relative flag (undef for no change)
    //                 1) normal offset from end of IFD to first value (empty if unknown)
    //                 2-N) other possible offsets used by some models
    // Notes: Directory size should be validated before calling this routine
    public static function GetMakerNoteOffset(ExifTool $et)
    {
        // figure out where we expect the value data based on camera type
        $make = Perl::str($et->Make);
        $model = Perl::str($et->Model);
        $relative = null;
        $offsets = [];

        // normally value data starts 4 bytes after end of directory, so this is the default.
        // offsets of 0 and 4 are always allowed even if not specified,
        // but the first offset specified is the one used when writing
        if (preg_match('/^Canon/', $make)) {
            $offsets[] = preg_match('/\b(20D|350D|REBEL XT|Kiss Digital N)\b/', $model) ? 6 : 4;
            // some Canon models (FV-M30, Optura50, Optura60) leave 24 unused bytes
            // at the end of the IFD (2 spare IFD entries?)
            if (preg_match('/\b(FV\b|OPTURA)/', $model)) $offsets[] = 28;
            // some Canon PowerShot models leave 12 unused bytes
            if (preg_match('/(PowerShot|IXUS|IXY)/', $model)) $offsets[] = 16;
        } elseif (preg_match('/^CASIO/', $make)) {
            // Casio AVI and MOV images use no padding, and their JPEG's use 4,
            // except some models like the EX-S770,Z65,Z70,Z75 and Z700 which use 16,
            // and the EX-Z35 which uses 2 (grrrr...)
            array_push($offsets, ...(preg_match('/^(RIFF|MOV)$/', Perl::str($et->FILE_TYPE)) ? [0] : [4, 16, 2]));
        } elseif (preg_match('/^(General Imaging Co.|GEDSC IMAGING CORP.)/i', $make)) {
            $offsets[] = 0;
        } elseif (preg_match('/^KYOCERA/', $make)) {
            $offsets[] = 12;
        } elseif (preg_match('/^Leica Camera AG/', $make)) {
            if ($model === 'S2') {
                // lots of empty space before first value in S2 images
                array_push($offsets, 4, ($et->FILE_TYPE === 'JPEG' ? 286 : 274));
            } elseif ($model === 'LEICA M MONOCHROM (Typ 246)') {
                array_push($offsets, 4, 130);
            } elseif ($model === 'LEICA M (Typ 240)') {
                array_push($offsets, 4, 118);
            } elseif (preg_match('/^(R8|R9|M8)\b/', $model)) {
                $offsets[] = 6;
            } else {
                $offsets[] = 4;
            }
        } elseif (preg_match('/^OLYMPUS/', $make) and preg_match('/^E-(1|300|330)\b/', $model)) {
            $offsets[] = 16;
        } elseif (preg_match('/^OLYMPUS/', $make) and
            // these Olympus models are just weird
            preg_match('/^(C2500L|C-1Z?|C-5000Z|X-2|C720UZ|C725UZ|C150|C2Z|E-10|E-20|FerrariMODEL2003|u20D|u10D)\b/', $model))
        {
            // no expected offset --> determine offset empirically via FixBase()
        } elseif (preg_match('/^(Panasonic|JVC)\b/', $make)) {
            $offsets[] = 0;
        } elseif (preg_match('/^SONY/', $make)) {
            // earlier DSLR and "PREMI" models use an offset of 4
            if (preg_match('/^(DSLR-.*|SLT-A(33|35|55V)|NEX-(3|5|C3|VG10E))$/', $model) or
                $et->OlympusCAMER)
            {
                $offsets[] = 4;
            } else {
                $offsets[] = 0;
            }
        } elseif ($et->TIFF_TYPE === 'SRW' and $make === 'SAMSUNG' and $model === 'EK-GN120') {
            $offsets[] = 40;  // patch to read most of the maker notes, but breaks PreviewIFD
        } elseif ($make === 'FUJIFILM') {
            // some models have offset of 6, so allow that too (A345,A350,A360,A370)
            array_push($offsets, 4, 6);
        } elseif (preg_match('/^TOSHIBA/', $make)) {
            // similar to Canon, can also have 24 bytes of padding
            array_push($offsets, 0, 24);
        } elseif (preg_match('/^PENTAX/', $make)) {
            $offsets[] = 4;
            // the Pentax addressing mode is determined automatically, but
            // sometimes the algorithm gets it wrong, but Pentax always uses
            // absolute addressing, so force it to be absolute
            $relative = 0;
        } elseif (preg_match('/^Konica Minolta/i', $make)) {
            // patch for DiMAGE X50, Xg, Z2 and Z10
            array_push($offsets, 4, -16);
        } elseif (preg_match('/^Minolta/', $make)) {
            // patch for DiMAGE 7, X20 and Z1
            array_push($offsets, 4, -8, -12);
        } else {
            $offsets[] = 4;   // the normal offset
        }
        return [$relative, ...$offsets];
    }

    //------------------------------------------------------------------------------
    // Get hash of value offsets / block sizes
    // Inputs: 0) Data pointer, 1) offset to start of directory,
    //         2) hash ref to return value pointers based on tag ID
    // Returns: 0) hash reference: keys are offsets, values are block sizes
    //          1) same thing, but with keys adjusted for value-based offsets
    // Notes: Directory size should be validated before calling this routine
    // - calculates MIN and MAX offsets in entry-based hash
    public static function GetValueBlocks($dataPt, $dirStart, ?array &$tagPtr = null)
    {
        $numEntries = ExifTool::Get16u($dataPt, $dirStart);
        $valBlock = $valBlkAdj = [];
        for ($index = 0; $index < $numEntries; ++$index) {
            $entry = $dirStart + 2 + 12 * $index;
            $format = ExifTool::Get16u($dataPt, $entry + 2);
            if ($format < 1 or $format > 13) break;
            $count = ExifTool::Get32u($dataPt, $entry + 4);
            $size = $count * Vars\Exif::$formatSize[$format];
            if ($size <= 4) continue;
            $valPtr = ExifTool::Get32u($dataPt, $entry + 8);
            if ($tagPtr !== null) $tagPtr[ExifTool::Get16u($dataPt, $entry)] = $valPtr;
            // save location and size of longest block at this offset
            if (!(isset($valBlock[$valPtr]) and $valBlock[$valPtr] > $size)) {
                $valBlock[$valPtr] = $size;
            }
            // adjust for case of value-based offsets
            $valPtr += 12 * $index;
            if (!(isset($valBlkAdj[$valPtr]) and $valBlkAdj[$valPtr] > $size)) {
                $valBlkAdj[$valPtr] = $size;
                $end = $valPtr + $size;
                if (isset($valBlkAdj['MIN'])) {
                    // save minimum only if it has a value of 12 or greater
                    if ($valBlkAdj['MIN'] < 12 or $valBlkAdj['MIN'] > $valPtr) $valBlkAdj['MIN'] = $valPtr;
                    if ($valBlkAdj['MAX'] > $end) $valBlkAdj['MAX'] = $end;
                } else {
                    $valBlkAdj['MIN'] = $valPtr;
                    $valBlkAdj['MAX'] = $end;
                }
            }
        }
        return [$valBlock, $valBlkAdj];
    }

    //------------------------------------------------------------------------------
    // Fix base for value offsets in maker notes IFD (if necessary)
    // Inputs: 0) ExifTool object ref, 1) DirInfo hash ref
    // Return: amount of base shift (and $dirInfo Base and DataPos are updated,
    //         FixedBy is set if offsets fixed, and Relative or EntryBased may be set)
    public static function FixBase(ExifTool $et, array &$dirInfo)
    {
        // don't fix base if fixing offsets individually or if we don't want to fix them
        if (($dirInfo['FixOffsets'] ?? null) or ($dirInfo['NoFixBase'] ?? null)) return 0;

        $dataPt = $dirInfo['DataPt'] ?? null;
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $entryBased = $dirInfo['EntryBased'] ?? null;
        $dirName = $dirInfo['DirName'] ?? null;
        $fixBase = $et->Options('FixBase');
        $setBase = ($fixBase !== null and $fixBase !== '') ? 1 : 0;
        $fix = $fixedBy = null;
        $tagPtr = [];

        // get hash of value block positions
        [$valBlock, $valBlkAdj] = self::GetValueBlocks($dataPt, $dirStart, $tagPtr);
        if (!$valBlock) return 0;
        // get sorted list of value offsets
        $valPtrs = array_keys($valBlock);
        sort($valPtrs);
        //
        // handle special case of Canon maker notes with TIFF footer containing original offset
        //
        if (preg_match('/^Canon/', Perl::str($et->Make)) and ($dirInfo['DirLen'] ?? null) > 8) {
            $footerPos = $dirStart + $dirInfo['DirLen'] - 8;
            $footer = substr($dataPt, $footerPos, 8);
            if (preg_match('/^(II\x2a\0|MM\0\x2a)/', $footer) and  // check for TIFF footer
                substr($footer, 0, 2) === ExifTool::GetByteOrder()) // validate byte ordering
            {
                $oldOffset = ExifTool::Get32u($footer, 4);
                $newOffset = $dirStart + $dataPos;
                if ($setBase) {
                    $fix = $fixBase;
                } else {
                    $fix = $newOffset - $oldOffset;
                    if (!$fix) return 0;
                    // Picasa and ACDSee have a bug where they update other offsets without
                    // updating the TIFF footer (PH - 2009/02/25), so test for this case:
                    // validate Canon maker note footer fix by checking offset of last value
                    $maxPt = $valPtrs[count($valPtrs) - 1] + $valBlock[$valPtrs[count($valPtrs) - 1]];
                    // compare to end of maker notes, taking 8-byte footer into account
                    $endDiff = $dirStart + $dirInfo['DirLen'] - ($maxPt - $dataPos) - 8;
                    // ignore footer offset only if end difference is exactly correct
                    // (allow for possible padding byte, although I have never seen this)
                    if (!$endDiff or $endDiff == 1) {
                        $et->Warn('Canon maker note footer may be invalid (ignored)', 1);
                        return 0;
                    }
                }
                $et->Warn("Adjusted $dirName base by $fix", 1);
                $dirInfo['FixedBy'] = $fix;
                $dirInfo['Base'] = ($dirInfo['Base'] ?? null) + $fix;
                $dirInfo['DataPos'] = ($dirInfo['DataPos'] ?? null) - $fix;
                return $fix;
            }
        }
        //
        // analyze value offsets to see if they need fixing.  The first task is to determine
        // the minimum valid offset used (this is tricky, because we have to weed out bad
        // offsets written by some cameras)
        //
        $minPt = $dirInfo['MinOffset'] = $valPtrs[0]; // if life were simple, this would be it
        $ifdLen = 2 + 12 * ExifTool::Get16u($dirInfo['DataPt'], $dirStart);
        $ifdEnd = $dirStart + $ifdLen;
        $offsets = self::GetMakerNoteOffset($et);
        $relative = array_shift($offsets);
        $makeDiff = $offsets[0] ?? null;
        $verbose = $et->Options('Verbose');
        $diff = $shift = null;

        // calculate expected minimum value offset
        $expected = $dataPos + $ifdEnd + ($makeDiff !== null ? $makeDiff : 4);
        if (Vars\MakerNotes::$debug) echo "$expected expected\n";

        // zero our counters
        [$countNeg12, $countZero, $countOverlap] = [0, 0, 0];
        $last = null;
        foreach ($valPtrs as $valPtr) {
            if (Vars\MakerNotes::$debug) {
                printf("%d - %d (%d)\n", $valPtr, $valPtr + $valBlock[$valPtr], $valPtr - ($last ?: 0));
            }
            if ($last !== null) {
                $gap = $valPtr - $last;
                if ($gap == 0 or $gap == 1) {
                    ++$countZero;
                } elseif ($gap == -12 and !$entryBased) {
                    // you get this when value offsets are relative to the IFD entry
                    ++$countNeg12;
                } elseif ($gap < 0) {
                    // any other negative difference indicates overlapping values
                    if ($valPtr) ++$countOverlap; // (but ignore zero value pointers)
                } elseif ($gap >= $ifdLen) {
                    // ignore previous minimum if we took a jump to near the expected value
                    // (some values can be stored before the IFD)
                    if (abs($valPtr - $expected) <= 4) $minPt = $valPtr;
                }
                // an offset less than 12 is surely garbage, so ignore it
                if ($minPt < 12) $minPt = $valPtr;
            }
            $last = $valPtr + $valBlock[$valPtr];
        }
        // could this IFD be using entry-based offsets?
        if (((($countNeg12 > $countZero and $valBlkAdj['MIN'] >= $ifdLen - 2) or
             ($valBlkAdj['MIN'] == $ifdLen - 2 or $valBlkAdj['MIN'] == $ifdLen + 2)
            ) and $valBlkAdj['MAX'] <= ($dirInfo['DirLen'] ?? null) - 2))
        {
            // looks like these offsets are entry-based, so use the offsets
            // which have been correcting for individual entry position
            $entryBased = 1;
            if ($verbose) $et->Warn("$dirName offsets are entry-based", 1);
        } else {
            // calculate offset difference from end of IFD to first value
            $diff = ($minPt - $dataPos) - $ifdEnd;
            $shift = 0;
            if ($countOverlap) $et->Warn("Overlapping $dirName values", 1);
            if ($entryBased) {
                $et->Warn("$dirName offsets do NOT look entry-based", 1);
                $entryBased = null;
                $relative = null;
            }
            // use PrintIM tag to do special check for correct absolute offsets
            if ($tagPtr[0xe00] ?? null) {
                $ptr = $tagPtr[0xe00] - $dataPos;
                if ($ptr > 0 and $ptr <= strlen($dataPt) - 8 and
                    substr($dataPt, $ptr, 8) === "PrintIM\0") return 0;
            }
            // allow a range of reasonable differences for Unknown maker notes
            if (($dirInfo['FixBase'] ?? null) and $dirInfo['FixBase'] == 2) {
                if ($diff >= 0 and $diff <= 24) return 0;
            }
            // ******** (used for testing to extract differences) ********
            // $et->FoundTag('Diff', $diff);
            // $et->FoundTag('MakeDiff',$makeDiff);
        }
        //
        // handle entry-based offsets
        //
        if ($entryBased) {
            if (Vars\MakerNotes::$debug) echo "--> entry-based!\n";
            // most of my entry-based samples have first value immediately
            // after last IFD entry (ie. no padding or next IFD pointer)
            $makeDiff = 0;
            $offsets[] = 4;   // but some do have a next IFD pointer
            // corrected entry-based offsets are relative to start of first entry
            $expected = 12 * ExifTool::Get16u($dirInfo['DataPt'], $dirStart);
            $diff = $valBlkAdj['MIN'] - $expected;
            // set up directory to read values with entry-based offsets
            // (ignore everything and set base to start of first entry)
            $shift = $dataPos + $dirStart + 2;
            $dirInfo['Base'] = ($dirInfo['Base'] ?? null) + $shift;
            $dirInfo['DataPos'] = ($dirInfo['DataPos'] ?? null) - $shift;
            $dirInfo['EntryBased'] = 1;
            $dirInfo['Relative'] = 1;    // entry-based offsets are relative
            unset($dirInfo['FixBase']);  // no automatic base fix
            if (!$setBase) $fixBase = null;
        }
        //
        // return without doing shift if offsets look OK
        //
        if (!$setBase) {
            // don't try to fix offsets for whacky cameras
            if ($makeDiff === null) return $shift;
            // normal value data starts 4 bytes after IFD, but allow 0 or 4...
            if ($diff == 0 or $diff == 4) return $shift;
            // also check for allowed make-specific differences
            foreach ($offsets as $_) {
                if ($diff == $_) return $shift;
            }
        }
        //
        // apply the fix, or issue a warning
        //
        // use default padding of 4 bytes unless already specified
        if ($makeDiff === null) $makeDiff = 4;
        $fix = $makeDiff - $diff;   // assume standard diff for this make

        if ($dirInfo['FixBase'] ?? null) {
            // set flag if offsets are relative (base is at or above directory start)
            if ($dataPos - $fix + $dirStart <= 0) {
                $dirInfo['Relative'] = ($relative !== null) ? $relative : 1;
            }
            if ($setBase) {
                $fixedBy = $fixBase;
                $fix += $fixBase;
            }
        } elseif ($fixBase !== null) {
            if ($fixBase !== '') $fix = $fixBase;
            $fixedBy = $fix;
        } else {
            // print warning unless difference looks reasonable
            if ($diff < 0 or $diff > 16 or ($diff & 0x01)) {
                $et->Warn("Possibly incorrect maker notes offsets (fix by $fix?)", 1);
            }
            // don't do the fix (but we already adjusted base if entry-based)
            return $shift;
        }
        if ($fixedBy !== null) {
            $et->Warn("Adjusted $dirName base by $fixedBy", 1);
            $dirInfo['FixedBy'] = $fixedBy;
        }
        $dirInfo['Base'] = ($dirInfo['Base'] ?? null) + $fix;
        $dirInfo['DataPos'] = ($dirInfo['DataPos'] ?? null) - $fix;
        return $fix + $shift;
    }

    //------------------------------------------------------------------------------
    // Find start of IFD in unknown maker notes
    // Inputs: 0) reference to directory information
    // Returns: offset to IFD on success, undefined otherwise
    // - dirInfo may contain TagInfo reference for tag associated with directory
    // - on success, updates DirStart, DirLen, Base and DataPos in dirInfo
    // - also sets Relative flag in dirInfo if offsets are relative to IFD
    // Note: Changes byte ordering!
    public static function LocateIFD(ExifTool $et, array &$dirInfo)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        // (ignore MakerNotes DirLen since sometimes this is incorrect)
        $size = ($dirInfo['DataLen'] ?? null) - $dirStart;
        $dirLen = isset($dirInfo['DirLen']) ? $dirInfo['DirLen'] : $size;
        $tagInfo = $dirInfo['TagInfo'] ?? null;
        $ifdOffsetPos = null;
        // the IFD should be within the first 32 bytes
        // (Kyocera sets the current record at 22 bytes)
        [$firstTry, $lastTry] = [0, 32];

        // make sure Base and DataPos are defined
        if (!($dirInfo['Base'] ?? null)) $dirInfo['Base'] = 0;
        if (!($dirInfo['DataPos'] ?? null)) $dirInfo['DataPos'] = 0;
        //
        // use tag information (if provided) to determine directory location
        //
        if ($tagInfo and $tagInfo->SubDirectory) {
            $subdir = $tagInfo->SubDirectory;
            $proc = $subdir['ProcessProc'] ?? null;
            if (!($proc and
                 ($proc === self::class . '::ProcessUnknown' or
                  $proc === self::class . '::ProcessUnknownOrPreview')))
            {
                // look for the IFD at the "Start" specified in our SubDirectory information
                $valuePtr = $dirStart;
                $newStart = $dirStart;
                if (isset($subdir['Start'])) {
                    //#### eval Start ($valuePtr)
                    $newStart = Exif::EvalCode($subdir['Start'], $valuePtr, null, $dirStart, $dataPt);
                }
                if ($subdir['Base'] ?? null) {
                    // calculate subdirectory start relative to $base for eval
                    $start = $newStart + $dirInfo['DataPos'];
                    $base = ($dirInfo['Base'] ?? null) ?: 0;
                    //#### eval Base ($start,$base)
                    $baseShift = Perl::num(Exif::EvalCode($subdir['Base'], $start, $base));
                    // shift directory base (note: we may do this again below
                    // if an OffsetPt is defined, but that doesn't matter since
                    // the base shift is relative to DataPos, which we set too)
                    $dirInfo['Base'] += $baseShift;
                    $dirInfo['DataPos'] -= $baseShift;
                    // this is a relative directory if Base depends on $start
                    // (Perl: $$subdir{Base} =~ /\$start\b/; the PHP Base is a closure, so test
                    // whether its value changes with $start)
                    if (Exif::EvalCode($subdir['Base'], 0, $base) != Exif::EvalCode($subdir['Base'], 1, $base)) {
                        $dirInfo['Relative'] = 1;
                        // hack to fix Leica quirk
                        if ($proc and $proc === self::class . '::FixLeicaBase') {
                            $oldStart = $dirInfo['DirStart'] ?? null;
                            $dirInfo['DirStart'] = $newStart;
                            self::FixLeicaBase($et, $dirInfo);
                            $dirInfo['DirStart'] = $oldStart;
                        }
                    }
                }
                // add offset to the start of the directory if necessary
                if ($subdir['OffsetPt'] ?? null) {
                    if (preg_match('/^Little/i', Perl::str($subdir['ByteOrder'] ?? null))) {
                        ExifTool::SetByteOrder('II');
                    } elseif (preg_match('/^Big/i', Perl::str($subdir['ByteOrder'] ?? null))) {
                        ExifTool::SetByteOrder('MM');
                    } else {
                        trigger_error("Can't have variable byte ordering for SubDirectories using OffsetPt", E_USER_WARNING);
                        return null;
                    }
                    //#### eval OffsetPt ($valuePtr)
                    $ifdOffsetPos = Perl::num(Exif::EvalCode($subdir['OffsetPt'], $valuePtr)) - $dirStart;
                }
                // pinpoint position to look for this IFD
                $firstTry = $lastTry = $newStart - $dirStart;
            }
        }
        //
        // scan for something that looks like an IFD
        //
        if ($dirLen >= 14 + $firstTry) {  // minimum size for an IFD
            for ($offset = $firstTry; $offset <= $lastTry; $offset += 2) {   // IFD_TRY:
                if ($offset + 14 > $dirLen) break;    // 14 bytes is minimum size for an IFD
                $pos = $dirStart + $offset;
                //
                // look for a standard TIFF header (Nikon uses it, others may as well),
                //
                if (ExifTool::SetByteOrder(substr($dataPt, $pos, 2)) and
                    ExifTool::Get16u($dataPt, $pos + 2) == 0x2a)
                {
                    $ifdOffsetPos = 4;
                }
                if ($ifdOffsetPos !== null) {
                    // get pointer to IFD
                    $ptr = ExifTool::Get32u($dataPt, $pos + $ifdOffsetPos);
                    if ($ptr >= $ifdOffsetPos + 4 and $ptr + $offset + 14 <= $dirLen) {
                        // shift directory start and shorten dirLen accordingly
                        $dirInfo['DirStart'] += $ptr + $offset;
                        $dirInfo['DirLen'] -= $ptr + $offset;
                        // shift pointer base to the start of the TIFF header
                        $shift = $dirInfo['DataPos'] + $dirStart + $offset;
                        $dirInfo['Base'] += $shift;
                        $dirInfo['DataPos'] -= $shift;
                        $dirInfo['Relative'] = 1;   // set "relative offsets" flag
                        return $ptr + $offset;
                    }
                    $ifdOffsetPos = null;
                }
                //
                // look for a standard IFD (starts with 2-byte entry count)
                //
                $num = ExifTool::Get16u($dataPt, $pos);
                if (!$num) continue;
                // number of entries in an IFD should be between 1 and 255
                if (!($num & 0xff)) {
                    // lower byte is zero -- byte order could be wrong
                    ExifTool::ToggleByteOrder();
                    $num >>= 8;
                } elseif ($num & 0xff00) {
                    // upper byte isn't zero -- not an IFD
                    continue;
                }
                $bytesFromEnd = $size - ($offset + 2 + 12 * $num);
                if ($bytesFromEnd < 4) {
                    if (!($bytesFromEnd == 2 or $bytesFromEnd == 0)) continue;
                }
                // do a quick validation of all format types
                for ($index = 0; $index < $num; ++$index) {
                    $entry = $pos + 2 + 12 * $index;
                    $format = ExifTool::Get16u($dataPt, $entry + 2);
                    $count = ExifTool::Get32u($dataPt, $entry + 4);
                    if (!$format) {
                        // patch for buggy Samsung NX200 JPEG MakerNotes entry count
                        if ($num == 23 and $index == 21 and $et->Make === 'SAMSUNG') {
                            ExifTool::Set16u(21, $dataPt, $pos);  // really 21 IFD entries!
                            $dirInfo['DataPt'] = $dataPt;          // (same buffer in Perl)
                            $et->Warn('Fixed incorrect Makernote entry count', 1);
                            break;
                        }
                        // allow everything to be zero if not first entry
                        // because some manufacturers pad with null entries
                        if (!($count or $index == 0)) continue;
                        // patch for Canon EOS 40D firmware 1.0.4 bug: allow zero format for last entry
                        if ($index == $num - 1 and preg_match('/EOS 40D/', Perl::str($et->Model))) continue;
                    }
                    // patch for Sony cameras like the DSC-P10 that have invalid MakerNote entries
                    if ($num == 12 and $et->Make === 'SONY' and $index >= 8) continue;
                    // patch for Apple ProRaw DNG which uses format 16 in the maker notes
                    if ($format == 16 and $et->Make === 'Apple') continue;
                    // (would like to verify tag ID, but some manufactures don't
                    //  sort entries in order of tag ID so we don't have much of
                    //  a handle to verify this field)
                    // verify format
                    if ($format < 1 or $format > 13) continue 2;   // next IFD_TRY
                    // count must be reasonable (can't test for zero count because
                    // cameras like the 1DmkIII use this value)
                    if ($count & 0xff000000) continue 2;   // next IFD_TRY
                    // extra tests to avoid mis-identifying Samsung makernotes (GT-I9000, etc)
                    if ($num != 1) continue;
                    $valueSize = $count * Vars\Exif::$formatSize[$format];
                    if ($valueSize > 4) {
                        if ($valueSize > $size) continue 2;   // next IFD_TRY
                        $valuePtr = ExifTool::Get32u($dataPt, $entry + 8);
                        if ($valuePtr > 0x10000) continue 2;   // next IFD_TRY
                    }
                }
                $dirInfo['DirStart'] += $offset;    // update directory start
                $dirInfo['DirLen'] -= $offset;
                return $offset;   // success!!
            }
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Fix base offset for Leica maker notes
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, and updates $dirInfo if necessary for new directory
    public static function FixLeicaBase(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        // get hash of value block positions
        [$valBlock, $valBlkAdj] = self::GetValueBlocks($dataPt, $dirStart);
        if ($valBlock) {
            // get sorted list of value offsets
            $valPtrs = array_keys($valBlock);
            sort($valPtrs);
            $numEntries = ExifTool::Get16u($dataPt, $dirStart);
            $diff = $valPtrs[0] - ($numEntries * 12 + 4);
            if ($diff > 8) {
                $dirInfo['Base'] = ($dirInfo['Base'] ?? null) - 8;
                $dirInfo['DataPos'] = ($dirInfo['DataPos'] ?? null) + 8;
            }
        }
        $success = 1;
        if ($tagTablePtr) {
            $success = Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Process Canon maker notes
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCanon(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        // identify Canon MakerNote footer in HtmlDump
        // (this code moved from FixBase so it also works for Adobe MakN in DNG images)
        if ($et->HTML_DUMP and ($dirInfo['DirLen'] ?? null) > 8) {
            $dataPos = $dirInfo['DataPos'] ?? null;
            $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
            $footerPos = $dirStart + $dirInfo['DirLen'] - 8;
            $footer = substr($dirInfo['DataPt'], $footerPos, 8);
            if (preg_match('/^(II\x2a\0|MM\0\x2a)/', $footer) and substr($footer, 0, 2) === ExifTool::GetByteOrder()) {
                $oldOffset = ExifTool::Get32u($footer, 4);
                $newOffset = $dirStart + $dataPos;
                $str = Perl::sprintf('Original maker note offset: 0x%.4x', $oldOffset);
                if ($oldOffset != $newOffset) {
                    $str .= Perl::sprintf("\nCurrent maker note offset: 0x%.4x", $newOffset);
                }
                $filePos = (($dirInfo['Base'] ?? null) ?: 0) + $dataPos + $footerPos;
                $et->HDump($filePos, 8, '[Canon MakerNotes footer]', $str);
            }
        }
        // process as normal
        return Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process GE type 2 maker notes
    // Inputs: 0) ExifTool object ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessGE2(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if (!($dirInfo['DataPt'] ?? null)) return 0;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;

        // these maker notes are missing the IFD entry count, but they
        // always have 25 entries, so write the entry count manually
        ExifTool::Set16u(25, $dirInfo['DataPt'], $dirStart);
        return Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process broken Kodak type 8b maker notes
    // Inputs: 0) ExifTool object ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessKodakPatch(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if (!($dirInfo['DataPt'] ?? null)) return 0;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;

        // these maker notes have an 2 extra null bytes either before or after the entry count,
        // so fix this by making a 2-byte entry count just before the first IFD entry
        if (!(($dirInfo['DirLen'] ?? null) >= 4)) return 0;
        $t1 = ExifTool::Get16u($dirInfo['DataPt'], $dirStart);
        $t2 = ExifTool::Get16u($dirInfo['DataPt'], $dirStart + 2);
        ExifTool::Set16u($t1 ?: $t2, $dirInfo['DataPt'], $dirStart + 2);
        $dirInfo['DirStart'] += 2;
        return Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process unknown maker notes or PreviewImage
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, and updates $dirInfo if necessary for new directory
    public static function ProcessUnknownOrPreview(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        $dirStart = $dirInfo['DirStart'] ?? null;
        $dirLen = $dirInfo['DirLen'] ?? null;
        // check to see if this is a preview image
        if ($dirLen > 6 and substr($dataPt, $dirStart, 3) === "\xff\xd8\xff") {
            $et->VerboseDir('PreviewImage');
            if ($et->HTML_DUMP) {
                $pos = ($dirInfo['DataPos'] ?? null) + ($dirInfo['Base'] ?? null) + $dirStart;
                $et->HDump($pos, $dirLen, '(MakerNotes:PreviewImage data)', "Size: $dirLen bytes");
            }
            $et->FoundTag('PreviewImage', substr($dataPt, $dirStart, $dirLen));
            return 1;
        }
        return self::ProcessUnknown($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Write unknown maker notes or PreviewImage
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: directory data, '' to delete, or undef on error
    public static function WriteUnknownOrPreview(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? null);
        $dirStart = $dirInfo['DirStart'] ?? null;
        $dirLen = $dirInfo['DirLen'] ?? null;
        $newVal = null;
        // check to see if this is a preview image
        if ($dirLen > 6 and substr($dataPt, $dirStart, 3) === "\xff\xd8\xff") {
            $previewInfo = ExifTool::PackageTable('Image::ExifTool::Extra')['PreviewImage'];
            if ($previewInfo instanceof TagInfo and ($et->NEW_VALUE[spl_object_id($previewInfo)] ?? null)) {
                // write or delete new preview (if deleted, it can't currently be added back again)
                $newVal = $et->GetNewValue('PreviewImage') ?: '';
                if ($et->Options('Verbose') > 1) {
                    $et->VerboseValue('- MakerNotes:PreviewImage', substr($dataPt, $dirStart, $dirLen));
                    if ($newVal) $et->VerboseValue('+ MakerNotes:PreviewImage', $newVal);
                }
                ++$et->CHANGED;
            } else {
                $newVal = substr($dataPt, $dirStart, $dirLen);
            }
        } else {
            // rewrite MakerNote IFD
            $newVal = Exif::WriteExif($et, $dirInfo, $tagTablePtr);
        }
        return $newVal;
    }

    //------------------------------------------------------------------------------
    // Process unknown maker notes assuming it is in EXIF IFD format
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, and updates $dirInfo if necessary for new directory
    public static function ProcessUnknown(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $success = 0;

        $loc = self::LocateIFD($et, $dirInfo);
        if ($loc !== null) {
            $et->UnknownByteOrder = ExifTool::GetByteOrder();
            if ($et->Options('Verbose') > 1) {
                $out = $et->Options('TextOut');
                $indent = preg_replace('/\| $/', '  ', Perl::str($et->INDENT), 1);
                fwrite($out, Perl::sprintf("{$indent}Found IFD at offset 0x%.4x in maker notes:\n",
                        $dirInfo['DirStart'] + $dirInfo['DataPos'] + $dirInfo['Base']));
            }
            $success = Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
        } else {
            $et->UnknownByteOrder = ''; // indicates we tried but didn't set byte order
            $et->Warn('Unrecognized ' . ($dirInfo['DirName'] ?? ''), 1);
        }
        return $success;
    }
}
