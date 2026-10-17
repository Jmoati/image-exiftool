<?php

//------------------------------------------------------------------------------
// File:         BMP.pm
//
// Description:  Read BMP meta information
//
// Revisions:    07/16/2005 - P. Harvey Created
//
// References:   1) http://www.fortunecity.com/skyscraper/windows/364/bmpffrmt.html
//               2) http://www.fourcc.org/rgb.php
//               3) https://msdn.microsoft.com/en-us/library/dd183381(v=vs.85).aspx
//
// PHP port of Image::ExifTool::BMP 1.09 (reading only). Tag tables are generated
// into tables/BMP.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class BMP
{
    public static $VERSION = '1.09';

    //------------------------------------------------------------------------------
    // Extract EXIF information from a BMP image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid BMP file
    public static function ProcessBMP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tagTablePtr = null;

        // verify this is a valid BMP file
        if ($raf->Read($buff, 18) != 18) return 0;
        if (!preg_match('/^BM/', $buff)) return 0;
        ExifTool::SetByteOrder('II');
        $len = ExifTool::Get32u($buff, 14);
        // len = v1:12, v4:108, v5:124
        if (!($len == 12 or $len == 16 or ($len >= 40 and $len < 1000000))) return 0;
        if (!($raf->Seek(-4, 1) and $raf->Read($buff, $len) == $len)) return 0;
        $et->SetFileType();     // set the FileType tag
//
// process the BMP header
//
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt' => $buff,
            'DirStart' => 0,
            'DirLen' => strlen($buff),
        ];
        if ($len == 12 or $len == 16 or $len == 64) {   // old OS/2 format BMP
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::BMP::OS2');
        } else {
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::BMP::Main');
        }
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);
//
// extract any embedded images
//
        $extraTable = ExifTool::GetTagTable('Image::ExifTool::BMP::Extra');
        if ($et->BMPCompression and $et->BMPImageLength and
            ($et->BMPCompression == 4 or $et->BMPCompression == 5))
        {
            $tag = $et->BMPCompression == 4 ? 'EmbeddedJPG' : 'EmbeddedPNG';
            $val = $et->ExtractBinary($raf->Tell(), $et->BMPImageLength, $tag);
            if ($val) {
                $et->HandleTag($extraTable, $tag, $val);
            }
        }
//
// process profile data if it exists (v5 header only)
//
        if ($len == 124 and $et->BMPProfileOffset) {
            $pos = $et->BMPProfileOffset + 14;  // (note the 14-byte shift!)
            $size = $et->BMPProfileSize;
            if ($raf->Seek($pos, 0) and $raf->Read($buff, $size) == $size) {
                if (Perl::str($et->BMPColorSpace) === 'LINK') {
                    $buff = preg_replace('/\0+$/', '', $buff, 1);   // remove null terminator(s)
                    $buff = $et->Decode($buff, 'Latin');    // convert from Latin
                    $tag = 'LinkedProfileName';
                } else {
                    $tag = 'ICC_Profile';
                }
                $et->HandleTag($extraTable, $tag, $buff, Size: $size, DataPos: $pos);
            } else {
                $et->Warn('Error loading profile data', 1);
            }
        }
        return 1;
    }
}
