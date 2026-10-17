<?php

//------------------------------------------------------------------------------
// File:         Rawzor.pm
//
// Description:  Read meta information from Rawzor compressed images
//
// Revisions:    09/09/2008 - P. Harvey Created
//
// References:   1) http://www.rawzor.com/
//
// PHP port of Image::ExifTool::Rawzor 1.06. Tag tables are generated into tables/Rawzor.php,
// $implementedRawzorVersion into Vars\Rawzor. bzip2 decompression uses ext-bz2
// (Perl: IO::Uncompress::Bunzip2).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\Rawzor as Vars;

final class Rawzor
{
    public static $VERSION = '1.06';

    //------------------------------------------------------------------------------
    // Extract information from a Rawzor file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid Rawzor file
    public static function ProcessRWZ(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = null;

        // read the Rawzor file header:
        //  0 string - "rawzor" signature
        //  6 int16u - Required SDK version
        //  8 int16u - Creator SDK version
        // 10 int64u - RWZ file size
        // 18 int64u - original raw file size
        // 26 undef[12] - reserved
        // 38 int64u - metadata offset
        if (!($raf->Read($buff, 46) == 46 and preg_match('/^rawzor/', $buff))) return 0;

        ExifTool::SetByteOrder('II');
        $reqVers = ExifTool::Get16u($buff, 6);
        $creatorVers = ExifTool::Get16u($buff, 8);
        $rwzSize = ExifTool::Get64u($buff, 10);
        $origSize = ExifTool::Get64u($buff, 18);
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Rawzor::Main');
        $et->HandleTag($tagTablePtr, 'RawzorRequiredVersion', $reqVers);
        $et->HandleTag($tagTablePtr, 'RawzorCreatorVersion', $creatorVers);
        $et->HandleTag($tagTablePtr, 'OriginalFileSize', $origSize);
        if ($rwzSize) $et->HandleTag($tagTablePtr, 'CompressionFactor', $origSize / $rwzSize);
        // check version numbers
        if ($reqVers > Vars::$implementedRawzorVersion) {
            $et->Warn("Version $reqVers Rawzor images not yet supported");
            return 1;
        }
        $metaOffset = ExifTool::Get64u($buff, 38);
        if ($metaOffset > 0x7fffffff) {
            $et->Warn('Bad metadata offset');
            return 1;
        }
        // check for the ability to uncompress the information
        if (!function_exists('bzdecompress')) {
            $et->Warn('Install IO::Compress::Bzip2 to decode Rawzor bzip2 compression');
            return 1;
        }
        // read the metadata header:
        //  0 int64u - metadata section 0 end (offset in original file)
        //  8 int64u - metadata section 1 start
        // 16 int64u - metadata section 1 end
        // 24 int64u - metadata section 2 start
        // 32 undef[4] - reserved
        // 36 int32u - original metadata size
        // 40 int32u - compressed metadata size
        if (!($raf->Seek($metaOffset, 0) and $raf->Read($buff, 44) == 44)) {
            $et->Warn('Error reading metadata header');
            return 1;
        }
        $metaSize = ExifTool::Get32u($buff, 36);
        if ($metaSize) {
            $et->DontValidateImageData = 1;
            // validate the metadata header and read the compressed metadata
            $end0 = ExifTool::Get64u($buff, 0);
            $pos1 = ExifTool::Get64u($buff, 8);
            $end1 = ExifTool::Get64u($buff, 16);
            $pos2 = ExifTool::Get64u($buff, 24);
            $len = ExifTool::Get32u($buff, 40);
            if (!($raf->Read($buff, $len) == $len and
                $end0 + ($end1 - $pos1) + ($origSize - $pos2) == $metaSize and
                $end0 <= $pos1 and $pos1 <= $end1 and $end1 <= $pos2))
            {
                $et->Warn('Error reading image metadata');
                return 1;
            }
            // uncompress the metadata
            $buf2 = bzdecompress($buff);
            if (!(is_string($buf2) and strlen($buf2) == $metaSize)) {
                $et->Warn('Error uncompressing image metadata');
                return 1;
            }
            // re-assemble the original file (sans image data)
            $buff = null; // (can't hurt to free memory as soon as possible)
            $buff = substr($buf2, 0, $end0) . str_repeat("\0", $pos1 - $end0) .
                    substr($buf2, $end0, $end1 - $pos1) . str_repeat("\0", $pos2 - $end1) .
                    substr($buf2, $end0 + $end1 - $pos1, $origSize - $pos2);
            $buf2 = null;

            // extract original information by calling ExtractInfo recursively
            $et->ExtractInfo(new ScalarRef($buff), ['ReEntry' => 1]);
            $buff = null;
        }
        // set OriginalFileType from FileType of original file
        // then change FileType and MIMEType to indicate a Rawzor image
        $origFileType = $et->FileType;
        if ($origFileType) {
            $et->HandleTag($tagTablePtr, 'OriginalFileType', $origFileType);
            $et->OverrideFileType('RWZ');
        } else {
            $et->HandleTag($tagTablePtr, 'OriginalFileType', 'Unknown');
            $et->SetFileType();
        }
        return 1;
    }
}
