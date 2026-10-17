<?php

//------------------------------------------------------------------------------
// File:         PSP.pm
//
// Description:  Read Paint Shop Pro meta information
//
// Revisions:    2010/01/23 - P. Harvey Created
//
// References:   1) http://www.jasc.com/support/kb/articles/pspspec.asp
//
// PHP port of Image::ExifTool::PSP 1.05 (reading only). Tag tables are generated
// into tables/PSP.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PSP
{
    public static $VERSION = '1.05';

    //------------------------------------------------------------------------------
    // Extract information from the PSP extended data block
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessExtData(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dirLen = $dirInfo['DirLen'];
        $pos = 0;
        // loop through sub-blocks
        while ($pos + 10 < $dirLen) {
            if (substr($dataPt, $pos, 4) !== "~FL\0") {
                $et->Warn('Lost synchronization while reading sub blocks');
                break;
            }
            $tag = ExifTool::Get16u($dataPt, $pos + 4);
            $len = ExifTool::Get32u($dataPt, $pos + 6);
            $pos += 10 + $len;
            if ($pos > $dirLen) {
                $et->Warn("Truncated sub block ID=$tag len=$len");
                break;
            }
            if (!$tagTablePtr[$tag]) continue;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo) continue;
            $start = $pos - $len;
            if ($tagInfo->Name !== 'EXIFInfo') {
                $et->HandleTag($tagTablePtr, $tag, null,
                    TagInfo: $tagInfo,
                    DataPt:  $dataPt,
                    DataPos: $dirInfo['DataPos'] ?? null,
                    DataLen: strlen($dataPt),
                    Start:   $start,
                    Size:    $len,
                );
                continue;
            }
            // validate EXIF block header and set byte order
            if (!($len > 14 and substr($dataPt, $pos - $len, 6) === "Exif\0\0")) continue;
            if (!ExifTool::SetByteOrder(substr($dataPt, $start + 6, 2))) continue;
            // This is REALLY annoying...  They use a standard TIFF offset to point to
            // the first IFD, but after that the offsets are relative to the start of
            // the IFD instead of the TIFF base, which means that I must handle it as a
            // special case.  Dumb, dumb...
            $start += 14;
            $subdirInfo = [     // (Perl %dirInfo)
                'DirName'  => 'EXIF',
                'Parent'   => 'PSP',
                'DataPt'   => $dataPt,
                'DataPos'  => -$start,      // data position relative to Base
                'DataLen'  => strlen($dataPt),
                'DirStart' => $start,
                'Base'     => $start + ($dirInfo['DataPos'] ?? 0),    // absolute base offset
                'Multi'    => 0,
            ];
            $exifTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
            Exif::ProcessExif($et, $subdirInfo, $exifTable);
            ExifTool::SetByteOrder('II');
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a PSP file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PSP file
    public static function ProcessPSP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tag = $len = $err = null;
        if (!($raf->Read($buff, 32) == 32 and
              $buff === "Paint Shop Pro Image File\x0a\x1a\0\0\0\0\0" and
              $raf->Read($buff, 4) == 4)) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PSP::Main');
        $a = Perl::unpack('v*', $buff);
        // figure out block header length for this format PSP file
        $hlen = $a[0] > 3 ? 10 : 14;
        $et->PSPFileVersion = $a[0];    // save for use in Condition
        $et->HandleTag($tagTablePtr, 'FileVersion', implode(' ', $a));
        // loop through blocks in file
        $pos = 36;
        for (;;) {
            if ($raf->Read($buff, $hlen) != $hlen) break;
            if (!preg_match('/^~BK\0/', $buff)) {
                $et->Warn('Lost synchronization while reading main PSP blocks');
                break;
            }
            $tag = ExifTool::Get16u($buff, 4);
            $len = ExifTool::Get32u($buff, $hlen - 4);
            $pos += $hlen + $len;
            if (!$tagTablePtr[$tag]) {
                if (!$raf->Seek($len, 1)) {
                    $err = 1;
                    break;
                }
                continue;
            }
            if ($raf->Read($buff, $len) != $len) {
                $err = 1;
                break;
            }
            $et->HandleTag($tagTablePtr, $tag, $buff,
                DataPt:  $buff,
                DataPos: $pos - $len,
                Size:    $len,
            );
        }
        if ($err) $et->Warn("Truncated main block ID=$tag len=$len");
        return 1;
    }
}
