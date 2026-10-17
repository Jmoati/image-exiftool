<?php

//------------------------------------------------------------------------------
// File:         GIF.pm
//
// Description:  Read and write GIF meta information
//
// Revisions:    10/18/2005 - P. Harvey Separated from ExifTool.pm
//               05/23/2008 - P. Harvey Added ability to read/write XMP
//               10/28/2011 - P. Harvey Added ability to read/write ICC_Profile
//
// References:   1) http://www.w3.org/Graphics/GIF/spec-gif89a.txt
//               2) http://www.adobe.com/devnet/xmp/
//               3) http://graphcomp.com/info/specs/ani_gif.html
//               4) http://www.color.org/icc_specs2.html
//               5) http://www.midiox.com/mmgif.htm
//               6) https://web.archive.org/web/20250724171508/https://...
//
// PHP port of Image::ExifTool::GIF 1.21 (reading only). Tag tables are generated
// into tables/GIF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GIF
{
    public static $VERSION = '1.21';

    //------------------------------------------------------------------------------
    // Process meta information in GIF image
    // Inputs: 0) ExifTool object reference, 1) Directory information ref
    // Returns: 1 on success, 0 if this wasn't a valid GIF file, or -1 if
    //          an output file was specified and a write error occurred
    public static function ProcessGIF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $a = $s = $ch = $length = $buff = null;
        [$frameCount, $delayTime] = [0, 0];

        // verify this is a valid GIF file
        if (!($raf->Read($buff, 6) == 6
            and preg_match('/^GIF(8[79]a)$/', $buff, $m)
            and $raf->Read($s, 7) == 7))
        {
            return 0;
        }

        $ver = $m[1];
        $rtnVal = 0;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::GIF::Main');
        $extTable = ExifTool::GetTagTable('Image::ExifTool::GIF::Extensions');
        ExifTool::SetByteOrder('II');

        // (writing not ported)
        $et->SetFileType();     // set file type
        $et->HandleTag($tagTablePtr, 'GIFVersion', $ver);
        $et->HandleTag($tagTablePtr, 'ScreenDescriptor', $s);
        $flags = ExifTool::Get8u($s, 4);
        if ($flags & 0x80) {    // does this image contain a color table?
            // calculate color table size
            $length = 3 * (2 << ($flags & 0x07));
            if ($raf->Read($buff, $length) != $length) return 0;    // skip color table
        }
//
// loop through GIF blocks
//
        for (;;) {  // (Perl label "Block")
            if (!$raf->Read($ch, 1)) break;
            if (ord($ch) == 0x2c) {
                ++$frameCount;
                // image descriptor
                if (!($raf->Read($buff, 8) == 8 and $raf->Read($ch, 1))) break;
                if ($verbose) {
                    [$left, $top, $w, $h] = Perl::unpack('v*', $buff);
                    fwrite($out, "Image: left=$left top=$top width=$w height=$h\n");
                }
                if (ord($ch) & 0x80) {  // does color table exist?
                    $length = 3 * (2 << (ord($ch) & 0x07));
                    // skip the color table
                    if ($raf->Read($buff, $length) != $length) break;
                }
                // skip "LZW Minimum Code Size" byte
                if (!$raf->Read($buff, 1)) break;
                // skip image blocks
                for (;;) {
                    if (!$raf->Read($ch, 1)) break;
                    if (!ord($ch)) break;
                    if (!$raf->Read($buff, ord($ch))) break;
                }
                continue;   // continue with next field
            }
//               last if ord($ch) == 0x3b;  # normal end of GIF marker
            if (ord($ch) != 0x21) {
                $rtnVal = 1;
                break;
            }
            // get extension block type/size
            if ($raf->Read($s, 2) != 2) break;
            // get marker and block size
            [$a, $length] = Perl::unpack('CC', $s);

            if ($a == 0xfe) {                           // comment extension

                $comment = '';
                while ($length) {
                    if ($raf->Read($buff, $length) != $length) break;
                    $et->VerboseDump($buff);
                    // add buffer to comment string
                    $comment .= $buff;
                    if (!$raf->Read($ch, 1)) break;     // read next block header
                    $length = ord($ch);     // get next block size
                }
                if ($length) break;     // was a read error if length isn't zero
                $rtnVal = 1;
                if ($comment) $et->FoundTag('Comment', $comment);
                $comment = null;
                // assume no more than one comment in FastScan mode
                if ($et->Options('FastScan')) break;
                continue;

            } elseif ($a == 0xff and $length == 0x0b) {  // application extension

                if ($raf->Read($buff, $length) != $length) break;
                // add "/" for readability
                $tag = substr($buff, 0, 8) . '/' . substr($buff, 8);
                $tag = preg_replace('/[\x00-\x1f]/', '', $tag);  // remove nulls and control characters
                if ($verbose) fwrite($out, "Application Extension: $tag\n");

                $extInfo = $extTable[$tag];
                $subdir = $inclLen = $name = null;
                if ($extInfo) {
                    $subdir = $extInfo->SubDirectory;
                    $inclLen = $extInfo->IncludeLengthBytes;
                    $name = $extInfo->Name;
                }

                // read the extension data
                $dat = '';
                for (;;) {
                    if (!$raf->Read($ch, 1)) break 2;   // read next block header
                    $length = ord($ch);                 // get next block size
                    if (!$length) break;
                    if ($raf->Read($buff, $length) != $length) break 2;
                    $dat .= $inclLen ? $ch . $buff : $buff;
                }
                if ($inclLen) {
                    // remove landing zone or padding
                    if ($extInfo->Terminator and preg_match('#' . $extInfo->Terminator . '#', $dat, $mm, PREG_OFFSET_CAPTURE)) {
                        $dat = substr($dat, 0, $mm[0][1] + strlen($mm[0][0]));
                    } elseif (($p = strpos($dat, "\0")) !== false) {
                        $dat = substr($dat, 0, $p);
                    }
                }
                if ($subdir) {
                    $subdirInfo = [     // (Perl %dirInfo)
                        'DataPt'  => $dat,
                        'DataLen' => strlen($dat),
                        'DirLen'  => strlen($dat),
                        'DirName' => $name,
                        'Parent'  => 'GIF',
                    ];
                    $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                    $et->ProcessDirectory($subdirInfo, $subTable);
                    continue;
                }
                $et->HandleTag($extTable, $tag, $dat);
                continue;

            } elseif ($a == 0xf9 and $length == 4) {     // graphic control extension

                if ($raf->Read($buff, $length) != $length) break;
                // sum the individual delay times
                $delay = ExifTool::Get16u($buff, 1);
                $delayTime += $delay;
                if ($verbose) fwrite($out, Perl::sprintf("Graphic Control: delay=%.2f\n", $delay / 100));
                // get transparent colour
                $bits = ExifTool::Get8u($buff, 0);
                if ($bits & 0x01) $et->HandleTag($tagTablePtr, 'TransparentColor', ExifTool::Get8u($buff, 3));
                if (!$raf->Seek(-$length, 1)) break;

            } elseif ($a == 0x01 and $length == 12) {    // plain text extension

                if ($raf->Read($buff, $length) != $length) break;
                if ($verbose) {
                    [$left, $top, $w, $h] = Perl::unpack('v4', $buff);
                    fwrite($out, "Text: left=$left top=$top width=$w height=$h\n");
                }
                $text = '';
                for (;;) {
                    if (!$raf->Read($ch, 1)) break;
                    $length = ord($ch);
                    if (!$length) break;
                    if ($raf->Read($buff, $length) != $length) break;
                    $text .= $buff;
                }
                $et->HandleTag($tagTablePtr, 'Text', $text);
                continue;
            }
            // skip the block
            while ($length) {
                if ($raf->Read($buff, $length) != $length) break;
                if (!$raf->Read($ch, 1)) break;     // read next block header
                $length = ord($ch);     // get next block size
            }
        }
        if ($frameCount > 1) $et->HandleTag($tagTablePtr, 'FrameCount', $frameCount);
        if ($delayTime) $et->HandleTag($tagTablePtr, 'Duration', $delayTime / 100);

        return $rtnVal;
    }
}
