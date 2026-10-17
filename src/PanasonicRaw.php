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
// PHP port of Image::ExifTool::PanasonicRaw 1.29 (reading only: WriteJpgFromRaw,
// WriteDistortionInfo and PatchRawDataOffset are not ported). Tag tables are generated into
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
