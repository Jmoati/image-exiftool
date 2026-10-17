<?php

//------------------------------------------------------------------------------
// File:         MIFF.pm
//
// Description:  Read Magick Image File Format meta information
//
// Revisions:    06/10/2005 - P. Harvey Created
//
// References:   1) http://www.imagemagick.org/script/miff.php
//               2) http://www.cs.uni.edu/Help/ImageMagick/www/miff.html
//
// PHP port of Image::ExifTool::MIFF 1.07 (reading only). Tag tables are generated
// into tables/MIFF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MIFF
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Extract meta information from a MIFF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid MIFF image
    public static function ProcessMIFF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->OPTIONS['Verbose'];
        $hdr = $buff = null;

        // validate the MIFF file (note: MIFF files _may_ begin with other
        // characters, but this starting sequence is strongly suggested.)
        if ($raf->Read($hdr, 14) != 14) return 0;
        if ($hdr !== 'id=ImageMagick') return 0;
        $et->SetFileType();   // set the FileType tag

        // set end-of-line character sequence to read to end of the TEXT
        // section for new-type MIFF files (text ends with Colon+Ctrl-Z)
        // Old MIFF files end with Colon+Linefeed, so this will likely
        // slurp those entire files, which will be slower, but will work
        // OK except that the profile information won't be decoded
        $saveRS = Perl::$RS;
        Perl::$RS = ":\x1a";
        try {
            $mode = '';
            $profiles = [];
            if ($raf->ReadLine($buff)) {
                Perl::chomp($buff);     // remove end-of-line chars
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MIFF::Main');
                $entries = Perl::split(' ', $buff);
                array_unshift($entries, $hdr);  // put the ID back in
                $tag = $val = null;
                foreach ($entries as $_) {
                    if ($mode === 'com') {
                        if (preg_match('/\}$/', $_)) $mode = '';
                        continue;
                    } elseif (preg_match('/^\{/', $_)) {
                        $mode = 'com';  // read to the end of the comment
                        continue;
                    }
                    if ($mode === 'val') {
                        $val .= " $_";  // join back together with a space
                        if (!preg_match('/\}$/', $_)) continue;
                        $mode = '';
                        $val = preg_replace('/(^\{|\}$)/', '', $val);  // remove braces
                    } elseif (preg_match('/(.+)=(.+)/', $_, $m)) {
                        [$tag, $val] = [$m[1], $m[2]];
                        if (preg_match('/^\{/', $val)) {
                            $mode = 'val';      // read to the end of the value data
                            continue;
                        }
                    } elseif (preg_match('/^:/', $_)) {
                        // this could be the end of an old-style MIFF file
                        break;
                    } else {
                        // something we don't recognize -- stop parsing here
                        $et->Warn('Unrecognized MIFF data');
                        break;
                    }
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                    if (!$tagInfo) {
                        $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $tag]);
                    }
                    if ($verbose) $et->VerboseInfo($tag, $tagInfo,
                        Table:  $tagTablePtr,
                        DataPt: $val,
                    );
                    // handle profile tags specially
                    if (preg_match('/^profile-(.*)/s', $tag, $m)) {
                        $profiles[] = [$m[1], $val];
                    } else {
                        $et->FoundTag($tagInfo, $val);
                    }
                }
            }
        } finally {
            Perl::$RS = $saveRS;
        }

        // process profile information
        foreach ($profiles as $_) {
            [$type, $len] = $_;
            if (!preg_match('/^\d+$/', $len)) {
                $et->Warn("Invalid length for $type profile");
                break;  // don't try to read the rest
            }
            if ($raf->Read($buff, $len) != $len) {
                $et->Warn("Error reading $type profile ($len bytes)");
                continue;
            }
            $processed = 0;
            $subdirInfo = [     // (Perl %dirInfo)
                'Parent'   => 'PNG',
                'DataPt'   => $buff,
                'DataPos'  => $raf->Tell() - $len,
                'DataLen'  => $len,
                'DirStart' => 0,
                'DirLen'   => $len,
            ];
            if ($type === 'icc') {
                // ICC Profile information
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ICC_Profile::Main');
                $processed = $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            } elseif ($type === 'iptc') {
                if (preg_match('/^8BIM/', $buff)) {
                    // Photoshop information
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Main');
                    $processed = $et->ProcessDirectory($subdirInfo, $tagTablePtr);
                }
            // I haven't seen 'exif' or 'xmp' profile types yet, but I have seen them
            // in newer PNG files so presumably they are possible here as well - PH
            } elseif ($type === 'APP1' or $type === 'exif' or $type === 'xmp') {
                if (str_starts_with($buff, Vars\ExifTool::$exifAPP1hdr)) {
                    // APP1 EXIF
                    $hdrLen = strlen(Vars\ExifTool::$exifAPP1hdr);
                    $subdirInfo['DirStart'] += $hdrLen;
                    $subdirInfo['DirLen'] -= $hdrLen;
                    // use the usual position for EXIF data: 12 bytes from start of file
                    // (this may be wrong, but I can't see where the PNG stores this information)
                    $subdirInfo['Base'] = 12; // this is the usual value
                    $processed = $et->ProcessTIFF($subdirInfo);
                } elseif (str_starts_with($buff, Vars\ExifTool::$xmpAPP1hdr)) {
                    // APP1 XMP
                    $hdrLen = strlen(Vars\ExifTool::$xmpAPP1hdr);
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
                    $subdirInfo['DirStart'] += $hdrLen;
                    $subdirInfo['DirLen'] -= $hdrLen;
                    $processed = $et->ProcessDirectory($subdirInfo, $tagTablePtr);
                }
            }
            if (!$processed) {
                $et->Warn("Unknown MIFF $type profile data");
                if ($verbose) {
                    $et->VerboseDir($type, 0, $len);
                    $et->VerboseDump($buff);
                }
            }
        }
        return 1;
    }
}
