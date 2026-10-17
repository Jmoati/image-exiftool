<?php

//------------------------------------------------------------------------------
// File:         FLIR.pm
//
// Description:  Read FLIR meta information
//
// Revisions:    2013/03/28 - P. Harvey Created
//
// References:   1) https://exiftool.org/forum/index.php/topic,4898.0.html
//               2) http://www.nuage.ch/site/flir-i7-some-analysis/
//               3) http://www.workswell.cz/manuals/flir/hardware/A3xx_and_A6xx_models/Streaming_format_ThermoVision.pdf
//               4) http://support.flir.com/DocDownload/Assets/62/English/1557488%24A.pdf
//               5) http://code.google.com/p/dvelib/source/browse/trunk/flirPublicFormat/fpfConverter/Fpfimg.h?spec=svn3&r=3
//               6) https://exiftool.org/forum/index.php/topic,5538.0.html
//               JD) Jens Duttke private communication
//
// Glossary:     FLIR = Forward Looking Infra Red
//
// PHP port of Image::ExifTool::FLIR 1.23 (reading only). Tag tables are generated into
// tables/FLIR.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class FLIR
{
    public static $VERSION = '1.23';

    //------------------------------------------------------------------------------
    // Get image type from raw image data
    // Inputs: 0) ExifTool ref, 1) image data, 2) tag name
    // Returns: image type (PNG, JPG, TIFF or undef)
    // - image itself is stored in $$et{$tag}
    public static function GetImageType(ExifTool $et, $val, $tag)
    {
        [$w, $h] = [$et->{$tag . 'Width'}, $et->{$tag . 'Height'}];
        $type = 'DAT';
        // add TIFF header only if this looks like 16-bit raw data
        // (note: MakeTiffHeader currently works only for little-endian,
        //  and I haven't seen any big-endian samples, but check anwyay)
        if (str_starts_with($val, "\x89PNG\r\n\x1a\n")) {
            $type = 'PNG';
        } elseif (str_starts_with($val, "\xff\xd8\xff")) { // (haven't seen this, but just in case - PH)
            $type = 'JPG';
        } elseif (strlen($val) != Perl::num($w) * Perl::num($h) * 2) {
            $et->Warn("Unrecognized FLIR $tag data format");
        } elseif (ExifTool::GetByteOrder() === 'II') {
            $val = ExifTool::MakeTiffHeader($w, $h, 1, 16) . $val;
            $type = 'TIFF';
        } else {
            $et->Warn("Don't yet support big-endian TIFF $tag");
        }
        // save image data
        $et->{$tag} = $val;
        return $type;
    }

    //------------------------------------------------------------------------------
    // Unescape FLIR Unicode character
    // Inputs: 0) escaped character code
    // Returns: UTF8 character
    public static function UnescapeFLIR($char)
    {
        if (strlen($char) != 4) return $char; // escaped ASCII char (eg. '\\')
        $val = hexdec($char);
        if ($val < 0x80) return chr($val);   // simple ASCII
        return ExifTool::PackUTF8($val);     // (Perl: pack('C0U', $val))
    }

    //------------------------------------------------------------------------------
    // Process FLIR text info record (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessFLIRText(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? 0) ?: 0;
        $dirLen = $dirInfo['DirLen'];

        if ($dirLen < 12) return 0;

        $et->VerboseDir('FLIR Text');

        $dat = substr($dataPt, $dirStart + 12, $dirLen - 12);
        $dat = preg_replace('/\0.*/s', '', $dat); // truncate at null

        // the parameter text contains an additional header entry...
        if ($tagTablePtr['TABLE_NAME'] === 'Image::ExifTool::FLIR::ParamInfo' and
            preg_match('/# (Generated) at (.*?)[\n\r]/', $dat, $m))
        {
            $et->HandleTag($tagTablePtr, $m[1], $m[2]);
        }

        $pos = 0;   // (Perl: pos($dat))
        for (;;) {
            if (!preg_match('/.(\d+).(label|value|param) (unicode|text) "(.*)"/', $dat, $m, PREG_OFFSET_CAPTURE, $pos)) break;
            $pos = $m[0][1] + strlen($m[0][0]);
            [$tag, $val] = [ucfirst($m[2][0]) . $m[1][0], $m[4][0]];
            if ($m[3][0] === 'unicode' and str_contains($val, '\\')) {
                // convert escaped Unicode characters (backslash followed by 4 hex digits)
                $val = preg_replace_callback('/\\\\([0-9a-fA-F]{4}|.)/s', fn($mm) => self::UnescapeFLIR($mm[1]), $val);
                $et->Decode($val, 'UTF8');
            }
            if (!$tagTablePtr[$tag]) ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $tag]);
            $et->HandleTag($tagTablePtr, $tag, $val);
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Process FLIR measurement tool record (ref 6)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // (code-driven decoding isn't pretty, but sometimes it is necessary)
    public static function ProcessMeasInfo(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? 0) ?: 0;
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirEnd = $dirStart + $dirInfo['DirLen'];

        $pos = $dirStart + 12;
        if ($pos > $dirEnd) return 0;
        if (ExifTool::Get16u($dataPt, $dirStart) >= 0x100) ExifTool::ToggleByteOrder();
        for ($i = 1; ; ++$i) {
            if ($pos + 2 > $dirEnd) break;
            $recLen = ExifTool::Get16u($dataPt, $pos);
            if ($recLen < 0x28 or $pos + $recLen > $dirEnd) break;
            $pre = 'Meas' . $i;
            $et->VerboseDir("MeasInfo $i", null, $recLen);
            $et->VerboseDump($dataPt, Len: $recLen, Start: $pos, DataPos: $dataPos);
            $coordLen = ExifTool::Get16u($dataPt, $pos + 4);
            // generate tag table entries for this tool if necessary
            foreach (['Type', 'Params', 'Label'] as $t) {
                $tag = $pre . $t;
                if ($tagTablePtr[$tag]) break;
                $tagInfo = ['Name' => $tag];
                $tagInfo['PrintConv'] = $tagTablePtr["Meas1$t"]?->PrintConv;
                ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            // extract measurement tool type
            $et->HandleTag($tagTablePtr, "{$pre}Type", null,
                DataPt: $dataPt, DataPos: $dataPos, Start: $pos + 0x0a, Size: 2);
            if ($pos + 0x24 + $coordLen > $dirEnd) break;
            // extract measurement parameters
            $et->HandleTag($tagTablePtr, "{$pre}Params", null,
                DataPt: $dataPt, DataPos: $dataPos, Start: $pos + 0x24, Size: $coordLen);
            $uni = [];
            // extract label (sometimes-null-terminated Unicode)
            for ($p = 0x24 + $coordLen; $p < $recLen - 1; $p += 2) {
                $ch = ExifTool::Get16u($dataPt, $p + $pos);
                // FLIR Tools v2.0 for Mac doesn't properly null-terminate these strings,
                // so end the string at any funny character
                if ($ch < 0x20 or $ch > 0x7f) break;
                $uni[] = $ch;
            }
            // convert to the ExifTool character set
            $val = Charset::Recompose($et, $uni);
            $et->HandleTag($tagTablePtr, "{$pre}Label", $val,
                DataPt: $dataPt, DataPos: $dataPos, Start: $pos + 0x24 + $coordLen, Size: 2 * count($uni));
            $pos += $recLen;    // step to next record
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process FLIR FFF record (ref PH/1/3)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 if this was a valid FFF record
    public static function ProcessFLIR(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = ($dirInfo['RAF'] ?? null) ?: new RandomAccess($dirInfo['DataPt']);
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $base = $raf->Tell();
        $hdr = $buff = $rec = null;

        // read and verify FFF header
        if (!($raf->Read($hdr, 0x40) == 0x40 and preg_match('/^([AF]FF)\0/', $hdr, $m))) return 0;

        $type = $m[1];

        // set file type if reading from FFF or SEQ file ($tagTablePtr will not be defined)
        if (!$tagTablePtr) $et->SetFileType($type === 'FFF' ? 'FLIR' : 'SEQ');

        // FLIR file header (ref 3)
        // 0x00 - string[4] file format ID = "FFF\0"
        // 0x04 - string[16] file creator: seen "\0","MTX IR\0","CAMCTRL\0"
        // 0x14 - int32u file format version = 100
        // 0x18 - int32u offset to record directory
        // 0x1c - int32u number of entries in record directory
        // 0x20 - int32u next free index ID = 2
        // 0x24 - int16u swap pattern = 0 (?)
        // 0x28 - int16u[7] spares
        // 0x34 - int32u[2] reserved
        // 0x3c - int32u checksum

        // determine byte ordering by validating version number
        // (in my samples FLIR APP1 is big-endian, FFF files are little-endian)
        for ($i = 0; ; ++$i) {
            $ver = ExifTool::Get32u($hdr, 0x14);
            if ($ver >= 100 and $ver < 200) break; // (have seen 100 and 101 - PH)
            ExifTool::ToggleByteOrder();
            if (!$i) continue;
            if ($et->DOC_NUM) return 0;
            $et->Warn("Unsupported FLIR $type version");
            return 1;
        }

        // read the FLIR record directory
        $pos = ExifTool::Get32u($hdr, 0x18);
        $num = ExifTool::Get32u($hdr, 0x1c);
        if (!($raf->Seek($base + $pos) and $raf->Read($buff, $num * 0x20) == $num * 0x20)) {
            $et->Warn('Truncated FLIR FFF directory');
            return $et->DOC_NUM ? 0 : 1;
        }

        if (!$tagTablePtr) {
            $tagTablePtr = ExifTool::GetTagTable("Image::ExifTool::FLIR::$type");
            $et->SET_GROUP0 = 'FLIR'; // (set group 0 to 'FLIR' for FFF files)
        }

        // process the header data
        $et->HandleTag($tagTablePtr, '_header', $hdr);

        $success = 1;
        $oldIndent = $et->INDENT;
        $et->INDENT .= '| ';
        $et->VerboseDir($type, $num);

        for ($i = 0; $i < $num; ++$i) {

            // FLIR record entry (ref 3):
            // 0x00 - int16u record type
            // 0x02 - int16u record subtype: RawData 1=BE, 2=LE, 3=PNG; 1 for other record types
            // 0x04 - int32u record version: seen 0x64,0x66,0x67,0x68,0x6f,0x104
            // 0x08 - int32u index id = 1
            // 0x0c - int32u record offset from start of FLIR data
            // 0x10 - int32u record length
            // 0x14 - int32u parent = 0 (?)
            // 0x18 - int32u object number = 0 (?)
            // 0x1c - int32u checksum: 0 for no checksum

            $entry = $i * 0x20;
            $recType = ExifTool::Get16u($buff, $entry);
            if ($recType == 0) {
                if ($verbose) fwrite($out, "{$et->INDENT}$i) FLIR Record 0x00 (empty)\n");
                continue;
            }
            $recPos = ExifTool::Get32u($buff, $entry + 0x0c);
            $recLen = ExifTool::Get32u($buff, $entry + 0x10);

            if ($verbose) {
                fwrite($out, Perl::sprintf("%s%d) FLIR Record 0x%.2x, offset 0x%.4x, length 0x%.4x\n",
                                           $et->INDENT, $i, $recType, $recPos, $recLen));
            }

            // skip RawData records for embedded documents
            if ($recType == 1 and $et->DOC_NUM and $et->Options('ExtractEmbedded') < 2) {
                if (!$raf->Seek($base + $recPos + $recLen)) {
                    $success = 0;
                    break;
                }
                continue;
            }
            if (!($raf->Seek($base + $recPos) and $raf->Read($rec, $recLen) == $recLen)) {
                if ($et->DOC_NUM) {
                    $success = 0;   // abort processing more documents
                } else {
                    $et->Warn('Invalid FLIR record');
                }
                break;
            }
            if ($tagTablePtr[$recType]) {
                $et->HandleTag($tagTablePtr, $recType, null,
                    Base:    $base,
                    DataPt:  $rec,
                    DataPos: $recPos,
                    Start:   0,
                    Size:    $recLen,
                );
            } elseif ($verbose > 2) {
                $et->VerboseDump($rec, Len: $recLen, DataPos: $recPos);
            }
        }
        $et->SET_GROUP0 = null;
        $et->INDENT = $oldIndent;

        // extract information from subsequent frames in SEQ file if ExtractEmbedded is used
        if (!empty($dirInfo['RAF']) and $et->Options('ExtractEmbedded') and !$et->DOC_NUM) {
            for (;;) {
                $et->DOC_NUM = $et->DOC_COUNT + 1;
                if (!self::ProcessFLIR($et, $dirInfo, $tagTablePtr)) break;
                // (DOC_COUNT will be incremented automatically if we extracted any tags)
            }
            $et->DOC_NUM = null;
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Process FLIR public image format (FPF) file (ref PH/4)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 if this was a valid FFF file
    public static function ProcessFPF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if (!($raf->Read($buff, 892) == 892 and str_starts_with($buff, "FPF Public Image Format\0"))) return 0;

        // I think these are always little-endian, but check FPFVersion just in case
        ExifTool::SetByteOrder('II');
        if (!(ExifTool::Get32u($buff, 0x20) & 0xffff)) ExifTool::ToggleByteOrder();

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FLIR::FPF');
        $et->SetFileType();
        $subdir = ['DataPt' => $buff, 'Parent' => 'FPF'];
        $et->ProcessDirectory($subdir, $tagTablePtr);
        return 1;
    }
}
