<?php

//------------------------------------------------------------------------------
// File:         PanasonicRaw.pm
//
// Description:  Read/write Panasonic/Leica RAW/RW2/RWL meta information
//
// Revisions:    2009/03/24 - P. Harvey Created
//               2009/05/12 - PH Added RWL file type (same format as RW2)
//
// References:   1) https://exiftool.org/forum/index.php/topic,1542.0.html
//               2) http://www.cybercom.net/~dcoffin/dcraw/
//               3) http://syscall.eu/#pana
//               4) Klaus Homeister private communication
//              IB) Iliah Borg private communication (LibRaw)
//              JD) Jens Duttke private communication (TZ3,FZ30,FZ50)
//
// PHP port of Image::ExifTool::PanasonicRaw 1.29 (WriteJpgFromRaw and WriteDistortionInfo
// are not ported; PatchRawDataOffset is, for the EXIF writer). Tag tables are generated into
// tables/PanasonicRaw.php, %jpgFromRawMap into Vars\PanasonicRaw.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class PanasonicRaw
{
    public static $VERSION = '1.29';

    //------------------------------------------------------------------------------
    // checksum algorithm for lens distortion correction information (ref 3)
    // Inputs: 0) data ref, 1) start position, 2) number of bytes, 3) incement
    // Returns: checksum value
    public static function Checksum($dataPt, $start, $num, $inc)
    {
        $csum = 0;
        for ($i = 0; $i < $num; ++$i) {
            $csum = (73 * $csum + ExifTool::Get8u($dataPt, $start + $i * $inc)) % 0xffef;
        }
        return $csum;
    }

    //------------------------------------------------------------------------------
    // Read lens distortion information
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessDistortionInfo(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $size = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $start);
        if ($size == 32) {
            // verify the checksums (ref 3)
            $csum1 = self::Checksum($dataPt, $start +  4, 12, 1);
            $csum2 = self::Checksum($dataPt, $start + 16, 12, 1);
            $csum3 = self::Checksum($dataPt, $start +  2, 14, 2);
            $csum4 = self::Checksum($dataPt, $start +  3, 14, 2);
            $res = $csum1 ^ ExifTool::Get16u($dataPt, $start + 2) ^
                   $csum2 ^ ExifTool::Get16u($dataPt, $start + 28) ^
                   $csum3 ^ ExifTool::Get16u($dataPt, $start + 0) ^
                   $csum4 ^ ExifTool::Get16u($dataPt, $start + 30);
            if ($res) $et->Warn('Invalid DistortionInfo checksum', 1);
        } else {
            $et->Warn('Invalid DistortionInfo', 1);
        }
        return $et->ProcessBinaryData($dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Patch for writing non-standard Panasonic RAW/RW2/RWL raw data
    // Inputs: 0) offset info ref, 1) raf ref, 2) IFD number
    // Returns: error string, or undef on success
    // OffsetInfo is a hash by tag ID of lists with the following elements:
    //  0 - tag info ref
    //  1 - pointer to int32u offset in IFD or value data
    //  2 - value count
    //  3 - reference to list of original offset values
    //  4 - IFD format number
    //  5 - (pointer to StripOffsets value added by this PatchRawDataOffset routine)
    //  6 - flag set if this is a fixed offset (Panasonic GH6 fixed-offset hack)
    public static function PatchRawDataOffset(array &$offsetInfo, RandomAccess $raf, $ifd)
    {
        $stripOffsets = $offsetInfo[0x111] ?? null;
        $stripByteCounts = $offsetInfo[0x117] ?? null;
        $rawDataOffset = $offsetInfo[0x118] ?? null;
        $err = null;
        if ($ifd != 0) $err = 1;
        if ($stripOffsets or $stripByteCounts) {
            if (!($stripOffsets and $stripByteCounts and $stripOffsets[2] == 1)) $err = 1;
        } else {
            // the DC-GH6 and DC-GH5M2 write RawDataOffset with no Strip tags, so we need
            // to create fake StripByteCounts information for copying the data
            if ($offsetInfo[0x118] ?? null) { // (just to be safe)
                // (Perl uses $PanasonicRaw::Main{0x117}, an empty package hash, so the tagInfo is undef)
                $stripByteCounts = $offsetInfo[0x117] = [null, 0, 1, [0], 4];
                // set flag so the offset will be fixed (GH6 hack, see https://exiftool.org/forum/index.php?topic=13861.0)
                // (of course, fixing up the offset is now unnecessary, but continue to do this even
                // though the fixup adjustment will be 0 because this allows us to delete the following
                // line to remove the fix-offset restriction if Panasonic ever sees the light, but note
                // that in this case we should investigate the purpose of the seemily-duplicate raw
                // data offset contained within PanasonicRaw_0x0044)
                $offsetInfo[0x118][6] = 1;
                $rawDataOffset = $offsetInfo[0x118];   // (same list in Perl)
            }
        }
        if ($rawDataOffset and !$err) {
            if ($rawDataOffset[2] != 1) $err = 1;
            if ($stripOffsets) {
                if (!(($stripOffsets[3][0] ?? null) == 0xffffffff or ($stripByteCounts[3][0] ?? null) == 0)) $err = 1;
            }
        }
        if ($err) return 'Unsupported Panasonic/Leica RAW variant';
        if ($rawDataOffset) {
            // update StripOffsets along with this tag if it contains a reasonable value
            if ($stripOffsets and ($stripOffsets[3][0] ?? null) != 0xffffffff) {
                // save pointer to StripOffsets value for updating later
                $rawDataOffset[] = $stripOffsets[1];
            }
            // handle via RawDataOffset instead of StripOffsets
            $stripOffsets = $offsetInfo[0x111] = $rawDataOffset;
            unset($offsetInfo[0x118]);
        }
        // determine the length of the raw data
        $pos = $raf->Tell();
        if (!$raf->Seek(0, 2)) $err = 1; // seek to end of file
        $len = $raf->Tell() - ($stripOffsets[3][0] ?? 0);
        $raf->Seek($pos, 0);
        // quick check to be sure the raw data length isn't unreasonable
        // (the 22-byte length is for '<Dummy raw image data>' in our tests)
        if (($len < 1000 and $len != 22) or $len & 0x80000000) $err = 1;
        if ($err) return 'Error reading Panasonic raw data';
        // update StripByteCounts info with raw data length
        // (note that the original value is maintained in the file)
        $offsetInfo[0x117][3][0] = $len;   // (Perl: $$stripByteCounts[3][0])

        return null;
    }

    //------------------------------------------------------------------------------
    // Extract meta information from an Panasonic JpgFromRaw
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid JpgFromRaw image
    public static function ProcessJpgFromRaw(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $byteOrder = ExifTool::GetByteOrder();
        $fileType = $et->TIFF_TYPE;   // RAW, RW2 or RWL
        $tagInfo = $dirInfo['TagInfo'] ?? null;
        $verbose = $et->Options('Verbose');
        $indent = $out = null;
        if (!$tagInfo) {
            $et->Warn('No tag info for Panasonic JpgFromRaw');
            return 0;
        }
        $dirStart = $dirInfo['DirStart'] ?? null;
        if ($dirStart) { // DirStart is non-zero in DNG-converted RW2/RWL
            $dirLen = Perl::int($dirInfo['DirLen'] ?? null) | strlen($dataPt) - $dirStart;
            $dataPt = substr($dataPt, $dirStart, $dirLen);
        }
        $et->BASE = ($dirInfo['DataPos'] ?? null) + ($dirStart ?: 0);
        $et->FILE_TYPE = $et->TIFF_TYPE = 'JPEG';
        $et->DOC_NUM = 1;
        // extract information from embedded JPEG
        $jpgDirInfo = [     // (Perl: my %dirInfo)
            'Parent' => 'RAF',
            'RAF'    => new RandomAccess($dataPt),
        ];
        if ($verbose) {
            // (Perl declares a new "my $indent" here, so the outer $indent stays undef)
            $indentInner = $et->INDENT;
            $et->INDENT = '  ';
            $out = $et->Options('TextOut');
            fwrite($out, '--- DOC1:JpgFromRaw ' . str_repeat('-', 56) . "\n");
        }
        // fudge HtmlDump base offsets to show as a stand-alone JPEG
        $et->BASE_FUDGE = $et->BASE;
        $rtnVal = $et->ProcessJPEG($jpgDirInfo);
        $et->BASE_FUDGE = 0;
        // restore necessary variables for continued RW2/RWL processing
        $et->BASE = 0;
        $et->FILE_TYPE = 'TIFF';
        $et->TIFF_TYPE = $fileType;
        $et->DOC_NUM = null;
        ExifTool::SetByteOrder($byteOrder);
        if ($verbose) {
            $et->INDENT = $indent;
            fwrite($out, str_repeat('-', 76) . "\n");
        }
        return $rtnVal;
    }
}
