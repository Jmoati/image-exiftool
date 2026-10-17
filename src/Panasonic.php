<?php

//------------------------------------------------------------------------------
// File:         Panasonic.pm
//
// Description:  Panasonic/Leica maker notes tags
//
// Revisions:    11/10/2004 - P. Harvey Created
//
// References:   (see Panasonic.pm)
//
// PHP port of Image::ExifTool::Panasonic 2.29 (reading only: LensTypeConvInv, the inverse
// branch of WhiteBalanceConv and the writing branch of ProcessLeicaTrailer are not ported).
// Tag tables are generated into tables/Panasonic.php, %leicaLensTypes, %frameSelectorBits
// and %shootingMode into Vars\Panasonic.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Panasonic
{
    public static $VERSION = '2.29';

    //------------------------------------------------------------------------------
    // Convert Leica Kelvin white balance
    // Inputs: 0) value, 1) flag to perform inverse conversion
    // Returns: Converted value, or undef on error
    public static function WhiteBalanceConv($val, $inv = null, $conv = null)
    {
        if ($inv) {
            if (preg_match('/(\d+)/', Perl::str($val), $m)) return $m[1] + 0x8000;
        } else {
            if (Perl::num($val) > 0x8000) return Perl::str(Perl::num($val) - 0x8000) . ' Kelvin';
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Process Leica makernotes in LEIC atom of MP4 videos (Leica T and X Vario)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessLeicaLEIC(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        if ($dirLen < 6) return 0;
        ExifTool::SetByteOrder('II');
        $numEntries = ExifTool::Get16u($dataPt, $dirStart);
        if ($numEntries < 1 or $numEntries > 255) return 0;
        $size = ExifTool::Get32u($dataPt, $dirStart + 2);
        if ($size < $numEntries * 12 or $size + 6 > $dirLen) return 0;
        // the Leica programmers want to make things difficult, so they store
        // the entry count before the directory size, making it impossible to
        // process as a standard TIFF IFD without a bit of reorganization...
        // (Perl modifies the data buffer in place: so do we, in the caller's dirInfo)
        ExifTool::Set16u($numEntries, $dirInfo['DataPt'], $dirStart + 4);
        $newDirInfo = $dirInfo;     // (Perl: my %dirInfo = %$dirInfo)
        $newDirInfo['DirStart'] = $dirStart + 4;
        $newDirInfo['DirLen'] = $size - 4;
        $newDirInfo['DataPos'] = ($newDirInfo['DataPos'] ?? null) - $dirStart;
        $newDirInfo['Base'] = ($newDirInfo['Base'] ?? null) + $dirStart;
        return Exif::ProcessExif($et, $newDirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process MakerNote trailer written by Leica S2
    // Inputs: 0) ExifTool object ref, 1) new absolute position of Leica trailer when writing
    // Returns: On success: 1 when reading, directory data when writing; otherwise undef
    // Notes:
    // - may be called twice for a file if the first call doesn't succeed
    // - must leave RAF position unchanged
    // - uses information from LeicaTrailer member:
    //      TagInfo = tag info ref for MakerNote SubDirectory
    //      Offset/Size = value offset/size from MakerNote IFD
    //      TrailStart/TrailLen = actual JPEG trailer position/size (2nd call only)
    // - deletes LeicaTrailer member and sets LeicaTrailerPos when successful
    // (PHP: reading only, $newPos is ignored)
    public static function ProcessLeicaTrailer(ExifTool $et, $newPos = null)
    {
        $trail = $et->LeicaTrailer;
        $raf = $et->RAF;
        $trailPos = $trail['TrailPos'] ?? null;
        $pos = $trailPos ?: ($trail['Offset'] ?? null);
        $len = ($trail['TrailLen'] ?? null) ?: ($trail['Size'] ?? null);
        $buff = $result = null;
        $tagPtr = [];

        if ($trailPos) $et->LeicaTrailer = null;   // done after this
        if (!($len > 0)) {
            if ($trailPos) $et->Warn('Missing Leica MakerNote trailer', 1);
            $et->LeicaTrailer = null;
            return null;
        }
        $oldPos = $raf->Tell();
        $ok = ($raf->Seek($pos, 0) and $raf->Read($buff, $len) == $len);
        $raf->Seek($oldPos, 0);
        if (!$ok) {
            if ($trailPos) $et->Warn('Error reading Leica MakerNote trailer', 1);
            return null;
        }
        // look for Leica MakerNote header (should be at start of
        // trailer, but allow up to 256 bytes of garbage just in case)
        if (!preg_match('/^(.{0,256})LEICA\0..../s', $buff, $m)) {
            $what = $trailPos ? 'trailer' : 'offset';
            $et->Warn("Invalid Leica MakerNote $what", 1);
            return null;
        }
        $junk = $m[1];
        $start = strlen($m[0]) - 10;    // (pos($buff) - 10)
        if ($start and !$trailPos) {
            $et->Warn('Invalid Leica MakerNote offset', 1);
            return null;
        }
        //
        // all checks passed -- go ahead and process the trailer now
        //
        $hdrLen = 8;
        $dirStart = $start + $hdrLen;
        $tagInfo = $trail['TagInfo'];
        if ($et->HTML_DUMP) {
            $name = $tagInfo->Name;
            $et->HDump($pos + $start, $len - $start, "$name value", 'Leica MakerNote trailer', 4);
            $et->HDump($pos + $start, $hdrLen, 'MakerNotes header', $name);
        } elseif ($et->Options('Verbose')) {
            $where = Perl::sprintf('at offset 0x%x', $pos);
            $et->VPrint(0, "Leica MakerNote trailer ($len bytes $where):\n");
        }
        // delete LeicaTrailer member so we don't try to process it again
        $et->LeicaTrailer = null;
        $et->LeicaTrailerPos = $pos + $start;   // return actual start position of Leica trailer

        $oldOrder = ExifTool::GetByteOrder();
        $num = ExifTool::Get16u($buff, $dirStart);          // get entry count
        if (($num >> 8) > ($num & 0xff)) ExifTool::ToggleByteOrder(); // set byte order

        // use specialized algorithm to automatically fix offsets
        $valStart = $dirStart + 2 + 12 * $num + 4;
        $fix = 0;
        if ($valStart < $len) {
            MakerNotes::GetValueBlocks($buff, $dirStart, $tagPtr);
            // find the minimum offset (excluding the PreviewImage tag 0x300 and 0x301)
            $minPtr = null;
            foreach ($tagPtr as $id => $ptr) {
                if ($id == 0x300 or $id == 0x301 or !$ptr or $ptr == 0xffffffff) continue;
                if ($minPtr === null or $minPtr > $ptr) $minPtr = $ptr;
            }
            if ($minPtr) {
                $diff = $minPtr - ($valStart + $pos);
                $expect = null;
                if (Perl::str($et->Model) === 'S2') {
                    // scan value data for the first non-zero byte
                    if (preg_match('/[^\0]/', $buff, $m, PREG_OFFSET_CAPTURE, $valStart)) {
                        $n = $m[0][1] - $valStart; // number of zero bytes
                        // S2 writes 282 bytes of zeros, exiftool writes none
                        $expect = $n >= 282 ? 282 : 0;
                    }
                } else { // M (Type 240)
                    // scan for the lens type (M writes 114 bytes of garbage first)
                    if (preg_match('/\G.{114}([\x20-\x7e]*\0*)/s', $buff, $m, 0, $valStart) and strlen($m[1]) >= 50) {
                        $expect = 114;
                    }
                }
                $fixBase = $et->Options('FixBase');
                if ($expect === null) {
                    $et->Warn('Unrecognized Leica trailer structure');
                } elseif ($diff != $expect or $fixBase !== null) {
                    $fix = $expect - $diff;
                    if ($fixBase !== null) {
                        if (Perl::str($fixBase) !== '') $fix = $fixBase;
                        $et->Warn("Adjusted MakerNotes base by $fix", 1);
                    } else {
                        $et->Warn("Possibly incorrect maker notes offsets (fixed by $fix)", 1);
                    }
                }
            }
        }
        // generate dirInfo for Leica MakerNote directory
        $dirInfo = [
            'Name'       => $tagInfo->Name,
            'Base'       => $fix,
            'DataPt'     => $buff,
            'DataPos'    => $pos - $fix,
            'DataLen'    => $len,
            'DirStart'   => $dirStart,
            'DirLen'     => $len - $dirStart,
            'DirName'    => 'MakerNotes',
            'Parent'     => 'ExifIFD',
            'TagInfo'    => $tagInfo,
        ];
        $tagTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
        // (writing branch for $newPos not ported)
        // extract information
        $result = $et->ProcessDirectory($dirInfo, $tagTablePtr);
        // also extract as a block if necessary
        if ($et->Options('MakerNotes') or
            ($et->REQ_TAG_LOOKUP[strtolower($tagInfo->Name)] ?? null))
        {
            // makernote header must be included in RebuildMakerNotes call
            $dirInfo['DirStart'] -= 8;
            $dirInfo['DirLen'] += 8;
            $et->MAKER_NOTE_BYTE_ORDER = ExifTool::GetByteOrder();
            // rebuild maker notes (creates $$et{MAKER_NOTE_FIXUP})
            $val = Exif::RebuildMakerNotes($et, $dirInfo, $tagTablePtr);
            if ($val === null) {
                if ($len > 4) $et->Warn('Error rebuilding maker notes (may be corrupt)');
                $val = $buff;
            }
            $key = $et->FoundTag($tagInfo, $val);
            $et->SetGroup($key, 'ExifIFD');
            if ($et->MAKER_NOTE_FIXUP) {
                $et->TAG_EXTRA[$key]['Fixup'] = $et->MAKER_NOTE_FIXUP;
                unset($et->MAKER_NOTE_FIXUP);
            }
        }
        ExifTool::SetByteOrder($oldOrder);
        return $result;
    }
}
