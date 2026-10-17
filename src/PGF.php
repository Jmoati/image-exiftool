<?php

//------------------------------------------------------------------------------
// File:         PGF.pm
//
// Description:  Read Progressive Graphics File meta information
//
// Revisions:    2011/01/25 - P. Harvey Created
//
// References:   1) http://www.libpgf.org/
//               2) http://www.exiv2.org/
//
// PHP port of Image::ExifTool::PGF 1.02 (reading only). Tag tables are generated
// into tables/PGF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PGF
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Extract information from a PGF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PGF file
    public static function ProcessPGF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // read header and check magic number
        if (!($raf->Read($buff, 24) == 24 and preg_match('/^PGF(.)/s', $buff, $m))) return 0;
        $ver = ord($m[1]);
        $et->SetFileType();
        ExifTool::SetByteOrder('II');

        // currently support only version 0x36
        if ($ver != 0x36) {
            $et->Error(Perl::sprintf('Unsupported PGF version 0x%.2x', $ver));
            return 1;
        }
        // extract information from the PGF header
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PGF::Main');
        $subdirInfo = ['DataPt' => $buff, 'DataPos' => 0];
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        $len = ExifTool::Get32u($buff, 4) - 16; // length of post-header data

        // skip colour table if necessary
        if (Perl::num($et->PGFColorMode) == 2) $len -= $raf->Seek(1024, 1) ? 1024 : $len;

        // extract information from the embedded metadata image (PNG format)
        if ($len > 0 and $len < 0x1000000 and $raf->Read($buff, $len) == $len) {
            $et->ExtractInfo(new ScalarRef($buff), ['ReEntry' => 1]);
        }
        return 1;
    }
}
