<?php

//------------------------------------------------------------------------------
// File:         Audible.pm
//
// Description:  Read metadata from Audible audio books
//
// Revisions:    2015/04/05 - P. Harvey Created
//
// References:   1) https://github.com/jteeuwen/audible
//               2) https://code.google.com/p/pyaudibletags/
//               3) http://wiki.multimedia.cx/index.php?title=Audible_Audio
//
// PHP port of Image::ExifTool::Audible 1.02 (reading only). Tag tables are generated
// into tables/Audible.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Audible
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Process Audible 'meta' tags from M4B files (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessAudible_meta(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = strlen($dataPt);
        if ($dirLen < 4) return 0;
        $num = ExifTool::Get32u($dataPt, 0);
        $et->VerboseDir('Audible_meta', $num);
        $pos = 4;
        for ($index = 0; $index < $num; ++$index) {
            if ($pos + 3 > $dirLen) break;
            $unk = ExifTool::Get8u($dataPt, $pos);          // ? (0x80 or 0x00)
            if (!($unk == 0x80 or $unk == 0x00)) break;
            $len = ExifTool::Get16u($dataPt, $pos + 1);     // tag length
            $pos += 3;
            if ($pos + $len + 6 > $dirLen or !$len) break;
            $tag = substr($dataPt, $pos, $len);             // tag ID
            $ver = ExifTool::Get16u($dataPt, $pos + $len);  // version?
            if (!($ver == 0x0001)) break;
            $size = ExifTool::Get32u($dataPt, $pos + $len + 2); // data size
            $pos += $len + 6;
            if ($pos + $size > $dirLen) break;
            $val = $et->Decode(substr($dataPt, $pos, $size), 'UTF8');
            if (!isset($tagTablePtr[$tag])) {
                $name = ExifTool::MakeTagName(preg_match('/[a-z]/', $tag) ? $tag : strtolower($tag));
                ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt  : $dataPt,
                DataPos : $dataPos,
                Start   : $pos,
                Size    : $size,
                Index   : $index,
            );
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Audible 'cvrx' cover art atom from M4B files (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessAudible_cvrx(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = strlen($dataPt);
        if (0x0a > $dirLen) return 0;
        $len = ExifTool::Get16u($dataPt, 0x08);
        if (0x0a + $len + 6 > $dirLen) return 0;
        $size = ExifTool::Get32u($dataPt, 0x0a + $len + 2);
        if (0x0a + $len + 6 + $size > $dirLen) return 0;
        $et->VerboseDir('Audible_cvrx', null, $dirLen);
        $et->HandleTag($tagTablePtr, 'CoverArtType', null,
            DataPt  : $dataPt,
            DataPos : $dataPos,
            Start   : 0x0a,
            Size    : $len,
        );
        $et->HandleTag($tagTablePtr, 'CoverArt', null,
            DataPt  : $dataPt,
            DataPos : $dataPos,
            Start   : 0x0a + $len + 6,
            Size    : $size,
        );
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information from an Audible .AA file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid AA file
    public static function ProcessAA(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $toc = null;

        // check magic number
        if (!($raf->Read($buff, 16) == 16 and preg_match('/^.{4}\x57\x90\x75\x36/s', $buff))) return 0;
        // check file size
        if (isset($et->VALUE['FileSize'])) {
            // first 4 bytes of the file should be the filesize
            if (Perl::unpack('N', $buff)[0] != $et->VALUE['FileSize']) return 0;
        }
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $bytes = 12 * ExifTool::Get32u($buff, 8); // table of contents size in bytes
        if ($bytes > 0xc00) { $et->Warn('Invalid TOC'); return 1; }
        // read the table of contents
        if ($raf->Read($toc, $bytes) != $bytes) { $et->Warn('Truncated TOC'); return 1; }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Audible::Main');
        // parse table of contents (in $toc)
        for ($entry = 0; $entry < $bytes; $entry += 12) {
            $type = ExifTool::Get32u($toc, $entry);
            if (!($type == 2 or $type == 6 or $type == 11)) continue;
            $offset = ExifTool::Get32u($toc, $entry + 4);
            $length = ExifTool::Get32u($toc, $entry + 8);
            if (!$length) continue;
            if (!$raf->Seek($offset, 0)) { $et->Warn("Chunk $type seek error"); break; }
            if ($type == 6) {   // offset table
                if ($length < 4 or $raf->Read($buff, 4) != 4) continue; // only read the chapter count
                $et->HandleTag($tagTablePtr, '_chapter_count', ExifTool::Get32u($buff, 0));
                continue;
            }
            // read the chunk
            if ($length > 100000000) { $et->Warn("Chunk $type too big"); continue; }
            if ($raf->Read($buff, $length) != $length) { $et->Warn("Chunk $type read error"); break; }
            if ($type == 11) {  // cover art
                if ($length < 8) continue;
                $len = ExifTool::Get32u($buff, 0);
                $off = ExifTool::Get32u($buff, 4);
                if ($off < $offset + 8 or $off - $offset + $len > $length) continue;
                $et->HandleTag($tagTablePtr, '_cover_art', substr($buff, $off - $offset, $len));
                continue;
            }
            // parse metadata dictionary (in $buff)
            if ($length < 4) { $et->Warn('Bad dictionary'); continue; }
            $num = ExifTool::Get32u($buff, 0);
            if ($num > 0x200) { $et->Warn('Bad dictionary count'); continue; }
            $pos = 4;   // dictionary starts immediately after count
            ExifTool::RequireModule('Image::ExifTool::HTML');  // (for UnescapeHTML)
            $et->VerboseDir('Audible Metadata', $num);
            for ($i = 0; $i < $num; ++$i) {
                $tagPos = $pos + 9;                     // position of tag string
                if ($tagPos > $length) { $et->Warn('Truncated dictionary'); break; }
                // (1 unknown byte ignored at start of each dictionary entry)
                $tagLen = ExifTool::Get32u($buff, $pos + 1);    // tag string length
                $valLen = ExifTool::Get32u($buff, $pos + 5);    // value string length
                $valPos = $tagPos + $tagLen;            // position of value string
                $nxtPos = $valPos + $valLen;            // position of next entry
                if ($nxtPos > $length) { $et->Warn('Bad dictionary entry'); break; }
                $tag = substr($buff, $tagPos, $tagLen);
                $val = substr($buff, $valPos, $valLen);
                if (!isset($tagTablePtr[$tag])) {
                    $name = ExifTool::MakeTagName($tag);
                    $name = preg_replace_callback('/_(.)/', static fn ($m) => strtoupper($m[1]), $name); // change from underscore-separated to mixed case
                    ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
                }
                // unescape HTML character references and convert from UTF-8
                $val = $et->Decode(HTML::UnescapeHTML($val), 'UTF8');
                $et->HandleTag($tagTablePtr, $tag, $val,
                    DataPos : $offset,
                    DataPt  : $buff,
                    Start   : $valPos,
                    Size    : $valLen,
                    Index   : $i,
                );
                $pos = $nxtPos; // step to next dictionary entry
            }
        }
        return 1;
    }
}
