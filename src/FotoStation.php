<?php

//------------------------------------------------------------------------------
// File:         FotoStation.pm
//
// Description:  Read/write FotoWare FotoStation trailer
//
// Revisions:    10/28/2006 - P. Harvey Created
//
// PHP port of Image::ExifTool::FotoStation 1.04 (reading only). Tag tables are generated
// into tables/FotoStation.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class FotoStation
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Read/write FotoStation information in a file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this file didn't contain FotoStation information
    // - updates DataPos to point to start of FotoStation information
    // - updates DirLen to trailer length
    public static function ProcessFotoStation(ExifTool $et, array &$dirInfo)
    {
        $buff = $footer = $tagTablePtr = null;
        $raf = $dirInfo['RAF'];
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $rtnVal = 0;

        $dirInfo['DirLen'] = 0;     // initialize returned trailer length
        $raf->Seek(-$offset, 2);    // seek to specified offset from end of file

        // loop through FotoStation records
        for (;;) {
            // look for trailer signature
            if (!($raf->Seek(-10, 1) and $raf->Read($footer, 10) == 10)) break;
            [$tag, $size, $sig] = Perl::unpack('nNN', $footer);
            if (!($sig == 0xa1b2c3d4 and $size >= 10 and $raf->Seek(-$size, 1))) break;
            $size -= 10;    // size of data only
            if ($raf->Read($buff, $size) != $size) break;
            $raf->Seek(-$size, 1);
            // set variables returned in dirInfo hash
            $dirInfo['DataPos'] = $raf->Tell();
            $dirInfo['DirLen'] += $size + 10;

            if (!$tagTablePtr) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FotoStation::Main');
                ExifTool::SetByteOrder('MM');   // necessary for the binary data
                $rtnVal = 1;        // we found a valid FotoStation trailer
            }
            // print verbose trailer information
            if ($verbose or $et->HTML_DUMP) {
                $et->DumpTrailer([
                    'RAF' => $raf,
                    'DataPos' => $dirInfo['DataPos'],
                    'DirLen' => $size + 10,
                    'DirName' => "FotoStation_$tag",
                ]);
            }
            // extract information for this tag
            $et->HandleTag($tagTablePtr, $tag, $buff,
                DataPt: $buff,
                Start: 0,
                Size: $size,
                DataPos: $dirInfo['DataPos'],
            );
            // (writing not ported)
        }
        return $rtnVal;
    }
}
