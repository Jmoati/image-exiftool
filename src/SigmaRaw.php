<?php

//------------------------------------------------------------------------------
// File:         SigmaRaw.pm
//
// Description:  Read Sigma/Foveon RAW (X3F) meta information
//
// Revisions:    2005/10/16 - P. Harvey Created
//               2009/11/30 - P. Harvey Support X3F v2.3 written by Sigma DP2
//
// References:   1) http://www.x3f.info/technotes/FileDocs/X3F_Format.pdf
//
// PHP port of Image::ExifTool::SigmaRaw 1.33 (reading only: WriteX3F and the OutFile
// branches of ProcessX3F are not ported). Tag tables are generated into tables/SigmaRaw.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class SigmaRaw
{
    public static $VERSION = '1.33';

    //------------------------------------------------------------------------------
    // Extract null-terminated unicode string from list of characters
    // Inputs: 0) ExifTool ref, 1) list ref, 2) position in list
    // Returns: Converted string
    public static function ExtractUnicodeString(ExifTool $et, array $chars, $pos)
    {
        for ($i = $pos; $i < count($chars); ++$i) {
            if (!$chars[$i]) break;
        }
        $buff = Perl::pack('v*', ...array_slice($chars, $pos, $i - $pos));
        return $et->Decode($buff, 'UTF16', 'II');
    }

    //------------------------------------------------------------------------------
    // Process an X3F header
    // Inputs: 0) ExifTool ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessX3FHeader(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $hdrLen = $dirInfo['DirLen'] ?? null;

        // process the static header structure first
        $et->ProcessBinaryData($dirInfo, $tagTablePtr);

        // process extended data if available
        if (strlen($dataPt) - $hdrLen >= 160) {
            $verbose = $et->Options('Verbose');
            if ($verbose) {
                $et->VerboseDir('X3F HeaderExt', 32);
                $et->VerboseDump($dataPt, Start: $hdrLen);
            }
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::SigmaRaw::HeaderExt');
            $tags = Perl::unpack("x{$hdrLen}C32", $dataPt);
            $unused = 0;
            for ($i = 0; $i < 32; ++$i) {
                if (!$tags[$i]) {
                    ++$unused;
                    continue;
                }
                $et->HandleTag($tagTablePtr, $tags[$i], null,
                    Index: $i,
                    DataPt: $dataPt,
                    Start: $hdrLen + 32 + $i * 4,
                    Size: 4,
                );
            }
            $et->VPrint(0, "{$et->INDENT}($unused entries unused)\n");
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process an X3F properties
    // Inputs: 0) ExifTool ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessX3FProperties(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $size = strlen($dataPt);
        $verbose = $et->Options('Verbose');
        $unknown = $et->Options('Unknown');

        if (!($size >= 24 and preg_match('/^SECp/', $dataPt))) {
            $et->Warn('Bad properties header');
            return 0;
        }
        [$entries, $fmt, $len] = Perl::unpack('x8V2x4V', $dataPt);
        if (!($size >= 24 + 8 * $entries + $len)) {
            $et->Warn('Truncated Property directory');
            return 0;
        }
        if ($verbose) $et->VerboseDir('Properties', $entries);
        if ($fmt != 0) {
            $et->Warn("Unsupported character format $fmt");
            return 0;
        }
        $charPos = 24 + 8 * $entries;
        $chars = Perl::unpack('v*', substr($dataPt, $charPos, $len * 2));
        for ($index = 0; $index < $entries; ++$index) {
            [$namePos, $valPos] = Perl::unpack('V2', substr($dataPt, $index * 8 + 24, 8));
            if ($namePos >= count($chars) or $valPos >= count($chars)) {
                $et->Warn('Bad Property pointer');
                return 0;
            }
            $tag = self::ExtractUnicodeString($et, $chars, $namePos);
            $val = self::ExtractUnicodeString($et, $chars, $valPos);
            if (!$tagTablePtr[$tag] and $unknown and preg_match('/^\w+$/', $tag)) {
                $tagInfo = [
                    'Name' => "SigmaRaw_$tag",
                    'Description' => ExifTool::MakeDescription('SigmaRaw', $tag),
                    'Unknown' => 1,
                    'Writable' => 0,  // can't write unknown tags
                ];
                // add tag information to table
                ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }

            $et->HandleTag($tagTablePtr, $tag, $val,
                Index: $index,
                DataPt: $dataPt,
                Start: $charPos + 2 * $valPos,
                Size: 2 * (strlen($val) + 1),
            );
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process an X3F directory
    // Inputs: 0) ExifTool ref, 1) DirInfo ref, 2) tag table ref
    // Returns: error string or undef on success
    public static function ProcessX3FDirectory(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');

        if (!$raf->Seek($dirInfo['DirStart'], 0)) return 'Error seeking to directory start';

        // parse the X3F directory structure
        $buff = $dir = null;
        if ($raf->Read($buff, 12) != 12) return 'Truncated X3F image';
        if (!preg_match('/^SECd/', $buff)) return 'Bad section header';
        [$ver, $entries] = Perl::unpack('x4V2', $buff);
        if ($verbose) $et->VerboseDir('X3F Subsection', $entries);
        if ($raf->Read($dir, $entries * 12) != $entries * 12) return 'Truncated X3F directory';
        for ($index = 0; $index < $entries; ++$index) {
            $pos = $index * 12;
            [$offset, $len, $tag] = Perl::unpack("x{$pos}V2a4", $dir);
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($verbose) {
                $et->VPrint(0, "{$et->INDENT}$index) $tag Subsection ($len bytes):\n");
                if ($verbose > 2) {
                    if (!$raf->Seek($offset, 0)) return 'Error seeking';
                    if ($raf->Read($buff, $len) != $len) return 'Truncated image';
                    $et->VerboseDump($buff);
                }
            }
            if (!$tagInfo) continue;
            if (!$raf->Seek($offset, 0)) return "Error seeking for {$tagInfo->Name}";
            if ($tagInfo->Name === 'PreviewImage') {
                // check image header to see if this is a JPEG preview image
                if ($raf->Read($buff, 28) != 28) return 'Error reading PreviewImage header';
                $offset += 28;
                $len -= 28;
                // ignore all image data but JPEG compressed (version 2.0, type 2, format 18)
                if (!preg_match('/^SECi\0\0\x02\0\x02\0\0\0\x12\0\0\0/', $buff)) {
                    // do hash on non-preview data if requested
                    if ($et->ImageDataHash and substr($buff, 8, 1) !== "\x02") {
                        $et->ImageDataHash($raf, $len, 'SigmaRaw IMAG');
                    }
                    continue;
                }
                if ($raf->Read($buff, $len) != $len) return 'Error reading PreviewImage data';
                // check fore EXIF segment, and extract this image as the JpgFromRaw
                if (preg_match('/^\xff\xd8\xff\xe1/', $buff)) {
                    $et->IsJpgFromRaw = 1;
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                    unset($et->IsJpgFromRaw);
                }
            } else {
                if ($raf->Read($buff, $len) != $len) return "Error reading {$tagInfo->Name} data";
            }
            $subdir = $tagInfo->SubDirectory;
            if ($subdir) {
                $subdirInfo = ['DataPt' => $buff];     // (Perl: my %dirInfo)
                $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                $et->ProcessDirectory($subdirInfo, $subTable);
            } else {
                // extract metadata from JpgFromRaw
                if ($tagInfo->Name === 'JpgFromRaw') {
                    $subdirInfo = [
                        'Parent' => 'X3F',
                        'RAF'    => new RandomAccess($buff),
                    ];
                    $et->BASE += $offset;
                    $et->ProcessJPEG($subdirInfo);
                    $et->BASE -= $offset;
                    ExifTool::SetByteOrder('II');
                }
                $et->FoundTag($tagInfo, $buff);
            }
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Read/write information from a Sigma raw (X3F) image
    // Inputs: 0) ExifTool ref, 1) DirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid X3F image, or -1 on write error
    public static function ProcessX3F(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        // (writing not ported: $warn is always Image::ExifTool::Warn)
        $buff = $err = $hdrLen = null;

        if ($raf->Read($buff, 40) != 40) return 0;
        if (!preg_match('/^FOVb/', $buff)) return 0;

        ExifTool::SetByteOrder('II');
        $et->SetFileType();

        // check version number
        $ver = Perl::unpack('x4V', $buff)[0];
        $ver = ($ver >> 16) . '.' . ($ver & 0xffff);
        if (Perl::num($ver) > 5) {
            $et->Warn("Untested X3F version ($ver). Please submit sample for testing", 1);
        }
        // read version 2.1/2.2/2.3 extended header
        if (Perl::num($ver) > 2) {
            $extra = $buf2 = null;
            if (Perl::num($ver) >= 4) {
                $hdrLen = 0x300;
                $extra = 0;
            } else {
                $hdrLen = Perl::num($ver) > 2.2 ? 104 : 72;     // SceneCaptureType string added in 2.3
                $extra = 160;                       // (extended header is 160 bytes)
            }
            $more = $hdrLen - strlen($buff) + $extra;
            if ($raf->Read($buf2, $more) != $more) {
                $et->Warn('Error reading X3F header');
                return 1;
            }
            $buff .= $buf2;
        }
        [$widPos, $hdrType] = Perl::num($ver) < 4 ? [28, 'Header'] : [40, 'Header4'];
        // process header information
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::SigmaRaw::Main');
        $et->HandleTag($tagTablePtr, $hdrType, $buff,
            DataPt: $buff,
            Size: $hdrLen,
        );
        // read the directory pointer
        if (!$raf->Seek(-4, 2)) {
            $et->Warn('Seek error');
            return 1;
        }
        if ($raf->Read($buff, 4) != 4) {
            $et->Warn('Error reading X3F dir pointer');
            return 1;
        }
        $offset = Perl::unpack('V', $buff)[0];
        $subdirInfo = [     // (Perl: my %dirInfo)
            'RAF' => $raf,
            'DirStart' => $offset,
        ];
        // process the X3F subsections
        $err = $et->ProcessDirectory($subdirInfo, $tagTablePtr);
        if ($err) $et->Warn($err);
        return 1;
    }
}
