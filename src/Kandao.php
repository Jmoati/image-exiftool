<?php

//------------------------------------------------------------------------------
// File:         Kandao.pm
//
// Description:  Read Kandao MP4 metadata
//
// Revisions:    2025-12-10 - P. Harvey Created
//
// Notes:        Tested with videos from the Kandao QooCam 3 Ultra
//
// PHP port of Image::ExifTool::Kandao 1.00 (reading only). Tag tables and file lexicals
// (%format) are generated into tables/Kandao.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Kandao
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Extract information from a Kandao 'kfix' and 'kvar' atoms
    // Inputs: 0) ExifTool ref, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessKandao(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) $raf = new RandomAccess($dirInfo['DataPt']);
        $dirName = $dirInfo['DirName'] ?? null;
        $dataPos = (($dirInfo['DataPos'] ?? 0) ?: 0) + (($dirInfo['Base'] ?? 0) ?: 0);
        $verbose = $et->Options('Verbose');
        $ee = $et->Options('ExtractEmbedded');
        // extract as a block from MP4 if specified
        if (!empty($dirInfo['BlockInfo'])) {
            $blockName = $dirInfo['BlockInfo']->Name;
            $blockExtract = $et->Options('BlockExtract');
            if (($blockExtract or !empty($et->REQ_TAG_LOOKUP[strtolower($blockName)]) or
                ($et->TAGS_FROM_FILE and empty($et->EXCL_TAG_LOOKUP[strtolower($blockName)]))))
            {
                $et->FoundTag($dirInfo['BlockInfo'], new ScalarRef($dirInfo['DataPt']));
                if ($blockExtract and $blockExtract > 1) return 1;
            }
        }
        $buff = $err = null;
        ExifTool::SetByteOrder('II');
        if ($raf->Read($buff, 4) != 4) return 0;
        $n = ExifTool::Get32u($buff, 0);
        $et->VerboseDir($dirName, $n);
        for ($i = 0; $i < $n; ++$i) {
            if ($raf->Read($buff, 0x2c) != 0x2c) {
                $err = '';
                break;
            }
            $tag = preg_replace('/\0+$/', '', substr($buff, 0, 0x20));
            $fmt = preg_replace('/\0+$/', '', substr($buff, 0x20, 8));
            $format = Vars\Kandao::$format[$fmt] ?? null;
            if (!$format) {
                $err = "Unknown Kandao format '{$fmt}'";
                break;
            }
            $num = ExifTool::Get32u($buff, 0x28);
            $size = $num * ExifTool::FormatSize($format);
            $parms = [
                'DataPos' => $dataPos + $raf->Tell(),
                'Index'   => $i,
                'Format'  => $format,
            ];
            if ($raf->Read($buff, $size) != $size) {
                $err = '';
                break;
            }
            $parms['DataPt'] = $buff;    // (Perl: DataPt => \$buff, read after)
            $tagInfo = $et->GetTagInfo($tagTbl, $tag, $buff);
            if (!$tagInfo) {
                $name = ucfirst(strtolower($tag));
                $name = preg_replace_callback('/_([a-z])/', fn($m) => '_' . strtoupper($m[1]), $name);
                $name = ExifTool::MakeTagName($name);
                $et->VPrint(0, "{$et->INDENT}[adding $dirName '{$tag}']\n");
                $tagInfo = ExifTool::AddTagToTable($tagTbl, $tag, ['Name' => $name]);
            }
            $recLen = $tagInfo->RecordSize;
            if ($recLen) {
                if ($verbose) $et->VerboseInfo($tag, $tagInfo, ...$parms);
                $tbl = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                $pt = ($tagInfo->SubDirectory['Start'] ?? 0) ?: 0;
                $subdirInfo = [     // (Perl: my %dirInfo)
                    'DataPt'  => $buff,
                    'DataPos' => $parms['DataPos'],
                    'DirLen'  => $recLen,
                    'DirName' => $tag,
                ];
                $nLimit = 10000;
                $sizeLimit = $size;
                if ($ee and Perl::int(($size - $pt) / $recLen) > $nLimit and
                    $et->Warn("Extracting only the first $nLimit {$tagInfo->Name} records", 2))
                {
                    $sizeLimit = $pt + $recLen * $nLimit;
                }
                while ($pt + $recLen <= $sizeLimit) {
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    $subdirInfo['DirStart'] = $pt;
                    $et->ProcessDirectory($subdirInfo, $tbl);
                    if (!$ee) {
                        $et->Warn('Use the ExtractEmbedded option to extract all timed metadata', 7);
                        break;
                    }
                    $pt += $recLen;
                }
                $et->DOC_NUM = null;
            } else {
                if ($fmt === 'U8' and $num > 1 and !$tagInfo->Format) {
                    // (Perl: \$buff, a reference to the read buffer itself, so the
                    //  value shows the data of the last record read from the file)
                    $val = new ScalarRef(null);
                    $val->value = &$buff;
                } else {
                    // override format if necessary
                    if ($tagInfo->Format) {
                        $format = $tagInfo->Format;
                        $parms['Format'] .= " read as $format";
                        $num = Perl::int($size / ExifTool::FormatSize($format));
                    }
                    $val = ExifTool::ReadValue($buff, 0, $format, $num, $size);
                }
                $key = $et->HandleTag($tagTbl, $tag, $val, ...$parms);
                if ($dirName !== 'KVAR') $et->SetGroup(Perl::str($key), $dirName);
            }
        }
        if ($err !== null) $et->Warn($err ?: "Truncated $dirName record");
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Kandao KVAR file
    // Inputs: 0) ExifTool ref, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid KVAR file
    public static function ProcessKVAR(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // verify this is a valid KVAR file
        if ($raf->Read($buff, 44) != 44) return 0;
        if (!preg_match('/^.{2}\0\0[A-Z].{31}(CHAR|BOOL|[US](8|16|32|64)|FLOAT|DOUBLE)\0/s', $buff)) return 0;
        $et->SetFileType('KVAR', 'application/x-kandaostudio');
        if (!$raf->Seek(0, 0)) {
            $et->Warn('Seek error');
            return 1;
        }
        $dirInfo['DirName'] = 'KVAR';
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Kandao::Main');
        return $et->ProcessDirectory($dirInfo, $tagTablePtr);
    }
}
