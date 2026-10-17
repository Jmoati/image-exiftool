<?php

//------------------------------------------------------------------------------
// File:         WTV.pm
//
// Description:  Read WTV meta information
//
// Revisions:    2018-05-30 - P. Harvey Created
//
// References:   1) https://wiki.multimedia.cx/index.php?title=WTV
//
// PHP port of Image::ExifTool::WTV 1.01 (reading only). Tag tables are generated
// into tables/WTV.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class WTV
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Read specified sectors from the file
    // Inputs: 0) raf ref, 1) sector table ref, 2) offset in sector table, 3) sector size
    // Returns: Data or undef on error
    public static function ReadSectors(RandomAccess $raf, $secPt, $pos, $secSize)
    {
        $data = $buff = null;
        while ($pos <= strlen($secPt) - 4) {
            $sec = ExifTool::Get32u($secPt, $pos);
            if ($sec == 0xffff) return null;    // (just in case)
            if (!$sec) break;   // a null marks the end of the sector table
            $data !== null ? ($data .= $buff) : ($data = $buff);
            if (!($raf->Seek($sec * $secSize, 0) and $raf->Read($buff, $secSize) == $secSize)) return null;
            $pos += 4;
        }
        return $data !== null ? $data . $buff : $buff;
    }

    //------------------------------------------------------------------------------
    // Process WTV metadata
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessMetadata(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = 0;
        $end = strlen($dataPt);
        $et->VerboseDir('WTV Metadata', null, $end);
        while ($pos + 0x18 < $end) {
            if (substr($dataPt, $pos, 16) !== "\x5a\xfe\xd7\x6d\xc8\x1d\x8f\x4a\x99\x22\xfa\xb1\x1c\x38\x14\x53") break;
            $fmt = ExifTool::Get32u($dataPt, $pos + 0x10);
            $len = ExifTool::Get32u($dataPt, $pos + 0x14);
            $str = '';
            $pos += 0x18;
            for (;;) {
                if ($pos + 2 > $end) {
                    $et->Warn('Corrupt metadata directory');
                    break;
                }
                $ch = substr($dataPt, $pos, 2);
                $pos += 2;
                if ($ch === "\0\0") break;
                $str .= $ch;
            }
            if ($pos + $len > $end) break;
            $tag = $et->Decode($str, 'UTF16', null, 'UTF8');
            $dat = substr($dataPt, $pos, $len);
            // add tag if not already there
            if (!isset($tagTablePtr[$tag])) {
                $name = $tag;
                $name = preg_replace('{^(WTV_Metadata_)?WM/(WMRV)?}', '', $name, 1);
                ExifTool::AddTagToTable($tagTablePtr, $tag, $name);
                $et->VPrint(0, $et->INDENT, "[adding WTV:$name]\n");
            }
            $val = null;
            if ($fmt == 0 or $fmt == 3) {   // int32u or boolean32
                $val = ExifTool::Get32s($dat, 0);
            } elseif ($fmt == 1) {          // string
                $val = $et->Decode($dat, 'UTF16');
            } elseif ($fmt == 6) {          // GUID
                $val = bin2hex($dat);
            } elseif ($fmt == 4) {          // int64u (date/time values use this)
                $val = ExifTool::Get64u($dat, 0);
            } else {
                $val = $dat;
                $fmt = "Unknown($fmt)";
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                Format : "format $fmt",
                Size   : strlen($dat),
            );
            $et->VerboseDump($dat);
            $pos += $len;
        }
    }

    //------------------------------------------------------------------------------
    // Extract information from a WTV video
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid WTV file
    public static function ProcessWTV(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $tagTablePtr = $pos = $len = null;

        // verify this is a valid WTV file
        if ($raf->Read($buff, 0x60) != 0x60) return 0;
        if (!preg_match('/^\xb7\xd8\x00\x20\x37\x49\xda\x11\xa6\x4e\x00\x07\xe9\x5e\xad\x8d/', $buff)) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        // 0x28 - int32u: sector size? (=0x1000) (PH NC)
        // 0x38 - int32u: sector number for main WTV directory (PH assume this is a sector table, NC)
        // 0x58 - int32u: total number of sectors in file
        $secSize = ExifTool::Get32u($buff, 0x28);
        // in case I'm wrong about this, constrain sector size to
        // either 0x1000 (standard) or 0x100 (ExifTool test file) - PH
        if (!($secSize == 0x1000 or $secSize == 0x100)) $secSize = 0x1000;
        $buff = self::ReadSectors($raf, $buff, 0x38, $secSize);  // read the WTV directory
        if ($buff === null) return 0;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::WTV::Main');
        // parse the WTV directory
        $et->VerboseDir('WTV');
        for ($pos = 0; $pos < strlen($buff) - 0x28; $pos += $len) {
            if (!(substr($buff, $pos, 0x10) === "\x92\xb7\x74\x91\x59\x70\x70\x44\x88\xdf\x06\x3b\x82\xcc\x21\x3d")) {
                if (!$pos) $et->Warn("WTV directory wasn't at expected location");
                break;
            }
            $len = ExifTool::Get32u($buff, $pos + 0x10);
            if ($pos + $len > strlen($buff)) break;
            $n = ExifTool::Get32u($buff, $pos + 0x20);
            if (0x28 + $n * 2 + 8 > $len) {
                $et->Warn('WTV directory error');
                break;
            }
            $tag = $et->Decode(substr($buff, $pos + 0x28, $n * 2), 'UTF16', null, 'UTF8');
            $ptr = $pos + 0x28 + $n * 2;
            $flg = ExifTool::Get32u($buff, $ptr + 4);
            if ($verbose) {
                $s = ExifTool::Get32s($buff, $ptr);
                if (!($s < 0)) $s = Perl::sprintf('0x%x', $s);
                $et->VPrint(1, "- Tag '{$tag}' (sector=$s, flag=$flg)");
            }
            if (!(isset($tagTablePtr[$tag]) and ($flg == 0 or $flg == 1))) continue;
            $sec = substr($buff, $ptr, 4);
            $data = self::ReadSectors($raf, $sec, 0, $secSize);
            if ($data === null) break;
            // read sectors from table if necessary (flag=1 indicates a sector table)
            if ($flg == 1) $data = self::ReadSectors($raf, $data, 0, $secSize);
            if ($data === null) {
                $et->Warn("Error fetching data for $tag");
                continue;
            }
            $et->HandleTag($tagTablePtr, $tag, $data);
        }
        return 1;
    }
}
