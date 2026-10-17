<?php

//------------------------------------------------------------------------------
// File:         NikonCapture.pm
//
// Description:  Read/write Nikon Capture information
//
// Revisions:    11/08/2005 - P. Harvey Created
//               10/10/2008 - P. Harvey Updated for Capture NX 2
//               16/04/2011 - P. Harvey Decode NikonCaptureEditVersions
//
// References:   1) http://www.cybercom.net/~dcoffin/dcraw/
//               IB) Iliah Borg private communication (LibRaw)
//
// PHP port of Image::ExifTool::NikonCapture 1.14 (reading only). Tag tables are generated
// into tables/NikonCapture.php. Not ported: WriteNikonCapture (writing).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class NikonCapture
{
    public static $VERSION = '1.14';

    //------------------------------------------------------------------------------
    // process Nikon Capture data (ref 1)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonCaptureEditVersions(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dirEnd = $dirStart + $dirLen;
        $verbose = $et->Options('Verbose');
        ExifTool::SetByteOrder('II');
        if (!($dirLen > 4)) return 0;
        $num = ExifTool::Get32u($dataPt, $dirStart);
        $pos = $dirStart + 4;
        if ($verbose) $et->VerboseDir('NikonCaptureEditVersions', $num);
        while ($num) {
            if ($pos + 4 > $dirEnd) break;
            $len = ExifTool::Get32u($dataPt, $pos);
            if ($pos + $len + 4 > $dirEnd) break;
            $subdirInfo = [     // (Perl: my %dirInfo)
                'DirName'  => 'NikonCapture',
                'Parent'   => 'NikonCaptureEditVersions',
                'DataPt'   => $dataPt,
                'DirStart' => $pos + 4,
                'DirLen'   => $len,
            ];
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            --$num;
            $pos += $len + 4;
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // process Nikon Capture data (ref 1)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonCapture(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dirEnd = $dirStart + $dirLen;
        $verbose = $et->Options('Verbose');
        $success = 0;
        ExifTool::SetByteOrder('II');
        if ($verbose) $et->VerboseDir('NikonCapture', 0, $dirLen);
        for ($pos = $dirStart + 22; $pos + 22 < $dirEnd; ) {
            $tagID = ExifTool::Get32u($dataPt, $pos);
            $size = ExifTool::Get32u($dataPt, $pos + 18) - 4;
            $pos += 22;
            if ($size < 0 or $pos + $size > $dirEnd) break;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            if ($tagInfo or $verbose) {
                $format = $value = null;
                // (note that Writable will be 0 for Unknown tags)
                if ($tagInfo) $format = $tagInfo->Format ?: $tagInfo->Writable;
                // generate a reasonable default format type for short values
                if (!$format and ($size == 1 or $size == 2 or $size == 4)) {
                    $format = 'int' . ($size * 8) . 'u';
                }
                if ($format) {
                    $count = 1;
                    if (Perl::str($format) === 'string' or Perl::str($format) === 'undef') {
                        // patch Nikon bug in size of some values (HistogramXML)
                        if ($tagInfo and $tagInfo->AdjustSize) $size += $tagInfo->AdjustSize;
                        $count = $size;
                    }
                    $value = ExifTool::ReadValue($dataPt, $pos, $format, $count, $size);
                } elseif ($size == 1) {
                    $value = substr($dataPt, $pos, $size);
                }
                if ($et->HandleTag($tagTablePtr, $tagID, $value,
                    DataPt: $dataPt,
                    DataPos: $dirInfo['DataPos'] ?? null,
                    Base: $dirInfo['Base'] ?? null,
                    Start: $pos,
                    Size: $size,
                )) $success = 1;
            }
            $pos += $size;
        }
        return $success;
    }
}
