<?php

//------------------------------------------------------------------------------
// File:         Red.pm
//
// Description:  Read Redcode R3D video files
//
// Revisions:    2018-01-25 - P. Harvey Created
//
// References:   1) http://www.wikiwand.com/en/REDCODE
//
// PHP port of Image::ExifTool::Red 1.02. Tag tables are generated into tables/Red.php,
// %redFormat and $errTrunc into Vars\Red.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\Red as Vars;

final class Red
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Process metadata from a Redcode R3D video (ref PH)
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid R3D file
    public static function ProcessR3D(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $pos = $dirLen = $dirEnd = null;
        $verbose = $et->Options('Verbose');
        $errTrunc = Vars::$errTrunc;

        // R3D file structure:
        // - each block starts with int32u block size followed by 4-byte block type
        // - first block type is either "RED1" (version 1) or "RED2" (version 2)
        // - blocks begin on even 0x1000 byte boundaries for version 2 files

        // validate the file header
        if (!($raf->Read($buff, 8) == 8 and preg_match('/^\0\0..RED(1|2)/s', $buff, $m))) return 0;
        $ver = $m[1];
        $size = Perl::unpack('N', $buff)[0];
        if ($size < 8) return 0;

        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Red::Main');
        $dataPos = 0;

        // read the first block of the file
        if ($raf->Read($buf2, $size - 8) != $size - 8) return $et->Warn($errTrunc);
        $buff .= $buf2;

        // extract tags from the header
        $et->HandleTag($tagTablePtr, "RED$ver", null, DataPt: $buff);

        // read the second block from a version 1 file because
        // the first block doesn't contain a Red directory
        if ($ver === '1') {
            // (read more than we need)
            if (!$raf->Read($buff, 0x10000)) return $et->Warn($errTrunc);
            $dataPos += $size;
            $pos = 0x22;    // directory starts at offset 0x22
        } else {
            // calculate position of Red directory start
            $pos = 0x44;
            if (strlen($buff) < $pos) return $et->Warn($errTrunc);
            $pos += ExifTool::Get8u($buff, 0x40) * 0x18;   // skip "rdi" records
            $pos += ExifTool::Get8u($buff, 0x41) * 0x14;   // skip "rda" records
            $pos += ExifTool::Get8u($buff, 0x42) * 0x10;   // skip "rdx" records
        }
        if ($pos + 8 > strlen($buff)) {
            $dirLen = 0;    // find directory the hard way
        } else {
            $dirLen = ExifTool::Get16u($buff, $pos);  // get length of Red directory
            $pos += 2;      // skip length word
        }
        // do sanity check on the directory size (in case our assumptions were wrong)
        if ($dirLen < 300 or $dirLen >= 2048 or $pos + $dirLen > strlen($buff)) {
            // tag 0x1000 with length 0x000f should be near the directory start
            if (!preg_match('/\0\x0f\x10[\0\x06]/', $buff, $m, PREG_OFFSET_CAPTURE)) {
                return $et->Warn("Can't find Red directory. Please submit sample for testing");
            }
            $pos = $m[0][1];    // (pos($buff) - 4)
            $dirEnd = strlen($buff);
            $dirLen = null;
            $et->Warn('This R3D file is different. Please submit a sample for testing');
        } else {
            $dirEnd = $pos + $dirLen;
        }
        if ($verbose) {
            $et->INDENT .= '| ';
            $et->VerboseDir('Red', null, $dirLen);
        }

        // process the first Red directory
        while ($pos + 4 <= $dirEnd) {
            $len = ExifTool::Get16u($buff, $pos);
            if ($len < 4 or $pos + $len > $dirEnd) break;
            $tag = ExifTool::Get16u($buff, $pos + 2);
            $fmt = Vars::$redFormat[$tag >> 12] ?? null;    // format is top 4 bits of tag ID (ref PH)
            if (!$fmt) {
                if ($dirLen) $et->Warn('Unknown format code');
                break;
            }
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt: $buff,
                DataPos: $dataPos,
                Start: $pos + 4,
                Size: $len - 4,
                Format: $fmt,
            );
            $pos += $len;
        }
        if ($verbose) $et->INDENT = substr($et->INDENT, 0, -2);

        return 1;
    }
}
