<?php

//------------------------------------------------------------------------------
// File:         DSF.pm
//
// Description:  Read DSF meta information
//
// Revisions:    2025-09-24 - P. Harvey Created
//
// References:   1) https://dsd-guide.com/sites/default/files/white-papers/DSFFileFormatSpec_E.pdf
//
// PHP port of Image::ExifTool::DSF 1.00 (reading only). Tag tables are generated
// into tables/DSF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class DSF
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Extract metadata from a DSF file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid DSF file
    public static function ProcessDSF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $tagTablePtr = null;

        // verify this is a valid DSF file
        if ($raf->Read($buff, 40) != 40) return 0;
        if (!preg_match('/^DSD \x1c\0{7}.{16}fmt /s', $buff)) return 0;
        $et->SetFileType();   // set the FileType tag
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::DSF::Main');
        ExifTool::SetByteOrder('II');
        $fmtLen = ExifTool::Get64u($buff, 32);
        if (!($fmtLen > 12 and $fmtLen < 1000 and
            $raf->Read($buf2, $fmtLen - 12) == $fmtLen - 12))
        {
            $et->Warn('Error reading DSF fmt chunk');
            return 1;
        }
        $fileSize = ExifTool::Get64u($buff, 12);
        $metaPos = ExifTool::Get64u($buff, 20);
        $buff = substr($buff, 28) . $buf2;
//
// process the DSF 'fmt ' chunk
//
        $dirInfo2 = [   // (Perl my %dirInfo)
            'DataPt' => $buff,
            'DirStart' => 0,
            'DirLen' => strlen($buff),
        ];
        $et->ProcessBinaryData($dirInfo2, $tagTbl);
//
// process ID3v2 if it exists
//
        $metaLen = $fileSize - $metaPos;
        if ($metaPos and $metaLen > 0 and $metaLen < 20000000 and
            $raf->Seek($metaPos, 0) and $raf->Read($buff, $metaLen) == $metaLen)
        {
            // (Perl: %dirInfo still holds DataPt => \$buff, which now references the ID3 data)
            $dirInfo2['DataPt'] = $buff;
            $dirInfo2['DataPos'] = $metaPos;
            $dirInfo2['DirLen']  = $metaLen;
            $id3Tbl = ExifTool::GetTagTable('Image::ExifTool::ID3::Main');
            $et->ProcessDirectory($dirInfo2, $id3Tbl);
        }
        return 1;
    }
}
