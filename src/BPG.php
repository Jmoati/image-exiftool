<?php

//------------------------------------------------------------------------------
// File:         BPG.pm
//
// Description:  Read BPG meta information
//
// Revisions:    2016-07-05 - P. Harvey Created
//
// References:   1) http://bellard.org/bpg/
//
// PHP port of Image::ExifTool::BPG 1.01 (reading only). Tag tables are generated
// into tables/BPG.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class BPG
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Get ue7 integer from binary data block
    // Inputs: 0) data ref, 1) location in data (undef for 0)
    // Returns: 0) ue7 as integer or undef on error, 1) length of ue7 in bytes
    // (PHP: returns [] on error, like the Perl empty list)
    public static function Get_ue7($dataPt, $pos = null)
    {
        $pos = $pos ?: 0;
        $size = strlen($dataPt);
        $val = 0;
        for ($i = 0; ;) {
            if ($pos + $i >= $size or $i >= 5) return [];
            $byte = ExifTool::Get8u($dataPt, $pos + $i);
            $val = ($val << 7) | ($byte & 0x7f);
            if (!($byte & 0x80)) {
                if ($i == 4 and ($byte & 0x70)) return []; // error if bits 32-34 are set
                break;  // this was the last byte
            }
            if ($i == 0 and $byte == 0x80) return [];   // error if first byte is 0x80
            ++$i;       // step to the next byte
        }
        return [$val, $i + 1];
    }

    //------------------------------------------------------------------------------
    // Extract EXIF information from a BPG image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid BPG file
    public static function ProcessBPG(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $size = $n = $len = $pos = null;

        // verify this is a valid BPG file
        if ($raf->Read($buff, 21) != 21) return 0;  // (21 bytes is maximum header length)
        if (!preg_match('/^BPG\xfb/', $buff)) return 0;
        $et->SetFileType();   // set the FileType tag

        ExifTool::SetByteOrder('MM');
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt'   => $buff,
            'DirStart' => 0,
            'DirLen'   => strlen($buff),
            'VarFormatData' => [],
        ];
        $et->ProcessDirectory($subdirInfo, ExifTool::GetTagTable('Image::ExifTool::BPG::Main'));

        if (!(Perl::num($et->VALUE['Flags'] ?? 0) & 0x0008)) return 1;  // all done unless extension flag is set

        // add varSize from last entry in VarFormatData to determine
        // the current read position in the file
        $dataPos = 9 + (array_slice($subdirInfo['VarFormatData'], -1)[0][1] ?? 0);
        // read extension length
        if (!($raf->Seek($dataPos, 0) and $raf->Read($buff, 5) == 5)) {
            $et->Warn('Missing BPG extension data');
            return 1;
        }
        [$size, $n] = self::Get_ue7($buff) + [null, null];
        if ($size === null) {
            $et->Warn('Corrupted BPG extension length');
            return 1;
        }
        $dataPos += $n;
        if ($size > 10000000) {
            $et->Warn('BPG extension is too large');
            return 1;
        }
        if (!($raf->Seek($dataPos, 0) and $raf->Read($buff, $size) == $size)) {
            $et->Warn('Truncated BPG extension');
            return 1;
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::BPG::Extensions');
        // loop through the individual extensions
        for ($pos = 0; $pos < $size; $pos += $len) {
            $type = ExifTool::Get8u($buff, $pos);
            // get length of this extension
            [$len, $n] = self::Get_ue7($buff, ++$pos) + [null, null];
            if ($len === null) {
                $et->Warn('Corrupted BPG extension');
                break;
            }
            $pos += $n; // point to start of data for this extension
            if ($pos + $len > $size) {
                $et->Warn('Invalid BPG extension size');
                break;
            }
            if (!$tagTablePtr[$type]) {
                $et->Warn("Unrecognized BPG extension $type ($len bytes)", 1);
                continue;
            }
            // libbpg (in my opinion) incorrectly copies the padding byte after the
            // "EXIF\0" APP1 header to the start of the BPG EXIF extension, so issue a
            // minor warning and ignore the padding if we find it before the TIFF header
            if ($type == 1 and $len > 3 and preg_match('/^.(II|MM)/s', substr($buff, $pos, 3))) {
                $et->Warn('Ignored extra byte at start of EXIF extension', 1);
                ++$pos;
                --$len;
            }
            $et->HandleTag($tagTablePtr, $type, null,
                DataPt:  $buff,
                DataPos: $dataPos,
                Start:   $pos,
                Size:    $len,
                Parent:  'BPG',
            );
        }
        return 1;
    }
}
