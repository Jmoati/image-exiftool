<?php

//------------------------------------------------------------------------------
// File:         OpenEXR.pm
//
// Description:  Read OpenEXR meta information
//
// Revisions:    2011/12/10 - P. Harvey Created
//               2023/01/31 - PH Added support for multipart images
//
// References:   1) http://www.openexr.com/
//
// PHP port of Image::ExifTool::OpenEXR 1.07 (reading only). Tag tables are generated
// into tables/OpenEXR.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class OpenEXR
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Extract information from an OpenEXR file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid OpenEXR file
    public static function ProcessEXR(ExifTool $et, array &$dirInfo)
    {
        $formatType = Vars\OpenEXR::$formatType;
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $binary = $et->Options('Binary') ?: $verbose;
        $buff = $dim = null;

        // verify this is a valid RIFF file
        if ($raf->Read($buff, 8) != 8) return 0;
        if (!preg_match('/^\x76\x2f\x31\x01/s', $buff)) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::OpenEXR::Main');

        // extract information from header
        $flags = Perl::unpack('x4V', $buff)[0];
        $et->HandleTag($tagTablePtr, '_ver', $flags & 0xff);
        $et->HandleTag($tagTablePtr, '_flags', $flags & 0xffffff00);
        $maxLen = ($flags & 0x400) ? 255 : 31;
        $multi = $flags & 0x1000;

        // extract attributes
        for (;;) {
            if (!$raf->Read($buff, ($maxLen + 1) * 2 + 5)) break;
            if (preg_match('/^\0/', $buff)) {
                if (!($multi and $et->Options('ExtractEmbedded'))) break;
                // remove null and process the next frame header as a sub-document
                // (second null is end of all headers)
                if (preg_match('/^(\0+)/', $buff, $m)) {
                    $buff = substr($buff, strlen($m[1]));
                    if (strlen($m[1]) > 1) break;
                }
                $et->DOC_NUM = ++$et->DOC_COUNT;
            }
            if (!preg_match("/^([^\\0]{1,$maxLen})\\0([^\\0]{1,$maxLen})\\0(.{4})/s", $buff, $m)) {
                $et->Warn('EXR format error');
                break;
            }
            [$tag, $type, $size] = [$m[1], $m[2], Perl::unpack('V', $m[3])[0]];
            if (!$raf->Seek(strlen($m[0]) - strlen($buff), 1)) {   // (Perl pos($buff))
                $et->Warn('Seek error');
                break;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo) {
                $name = ucfirst($tag);
                $name = preg_replace_callback('/([^a-zA-Z])([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name);   // capitalize first letter of each word
                $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);
                if (strlen($name) <= 1) {
                    if (strlen($name)) {
                        $name = "Tag$name";
                    } else {
                        $name = 'Invalid';
                    }
                }
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
                $et->VPrint(0, $et->INDENT, "[adding $tag]\n");
            }
            $val = $success = $buf2 = null;
            $format = $formatType[$type] ?? null;
            $subdir = $tagInfo->SubDirectory;
            if ($format or $binary or $subdir) {
                if ($raf->Read($buf2, $size) == $size) $success = 1;
                if ($subdir) {
                    $et->HandleTag($tagTablePtr, $tag, null,
                        DataPt: $buf2, DataPos: $raf->Tell() - strlen((string) $buf2));
                    if ($success) continue;
                } elseif (!$format) {
                    $val = new ScalarRef($buf2);    // treat as undef binary data
                } elseif (Perl::str($format) !== '1') {
                    // handle formats which map nicely into ExifTool format codes
                    if (preg_match('/^(\w+)\[?(\d*)/', $format, $m)) {
                        [$fmt, $cnt] = [$m[1], $m[2]];
                        if (!$cnt) $cnt = $fmt === 'string' ? $size : 1;
                        $val = ExifTool::ReadValue($buf2, 0, $fmt, $cnt, $size);
                    }
                // handle other format types
                } elseif ($type === 'tiledesc') {
                    if ($size >= 9) {
                        $x = ExifTool::Get32u($buf2, 0);
                        $y = ExifTool::Get32u($buf2, 4);
                        $mode = ExifTool::Get8u($buf2, 8);
                        $lvl = [0 => 'One Level', 1 => 'MIMAP Levels', 2 => 'RIPMAP Levels'][$mode & 0x0f] ?? null;
                        if (!$lvl) $lvl = 'Unknown Levels (' . ($mode & 0xf) . ')';
                        $rnd = [0 => 'Round Down', 1 => 'Round Up'][$mode >> 4] ?? null;
                        if (!$rnd) $rnd = 'Unknown Rounding (' . ($mode >> 4) . ')';
                        $val = "{$x}x$y; $lvl; $rnd";
                    }
                } elseif ($type === 'chlist') {
                    $val = [];
                    $pos = 0;
                    while (preg_match('/\G([^\0]{1,31})\0(.{16})/s', $buf2, $m, 0, $pos)) {
                        $pos += strlen($m[0]);
                        [$str, $dat] = [$m[1], $m[2]];
                        [$pix, $lin, $x, $y] = Perl::unpack('VCx3VV', $dat);
                        $pix = ([0 => 'int8u', 1 => 'half', 2 => 'float'][$pix] ?? null) ?: "unknown($pix)";
                        $val[] = "$str $pix" . ($lin ? ' linear' : '') . " $x $y";
                    }
                } elseif ($type === 'stringvector') {
                    $val = [];
                    $pos = 0;
                    while ($pos + 4 <= strlen($buf2)) {
                        $len = ExifTool::Get32u($buf2, $pos);
                        if ($pos + 4 + $len > strlen($buf2)) break;
                        $val[] = substr($buf2, $pos + 4, $len);
                        $pos += 4 + $len;
                    }
                } else {
                    $val = new ScalarRef($buf2);    // (shouldn't happen)
                }
            } else {
                // avoid loading binary data
                $val = new ScalarRef("Binary data $size bytes");
                $success = $raf->Seek($size, 1);
            }
            if (!$success) {
                $et->Warn('Truncated or corrupted EXR file');
                break;
            }
            if ($val === null) $val = '<bad>';

            // take image dimensions from dataWindow (with displayWindow as backup)
            if (($tag === 'dataWindow' or (!$dim and $tag === 'displayWindow')) and
                is_scalar($val) and preg_match('/^(-?\d+) (-?\d+) (-?\d+) (-?\d+)$/', Perl::str($val), $m) and !$et->DOC_NUM)
            {
                $dim = [$m[3] - $m[1] + 1, $m[4] - $m[2] + 1];
            }
            if ($verbose) {
                $dataPt = $val instanceof ScalarRef ? $val->value : $buf2;
                $et->VerboseInfo($tag, $tagInfo,
                    Table:   $tagTablePtr,
                    Value:   $val,
                    Size:    $size,
                    Format:  $type,
                    DataPt:  $dataPt,
                    Addr:    $raf->Tell() - $size,
                );
            }
            $et->FoundTag($tagInfo, $val);
        }
        $et->DOC_NUM = null;
        if ($dim) {
            $et->FoundTag('ImageWidth', $dim[0]);
            $et->FoundTag('ImageHeight', $dim[1]);
        }
        return 1;
    }
}
