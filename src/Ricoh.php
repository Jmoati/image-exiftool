<?php

//------------------------------------------------------------------------------
// File:         Ricoh.pm
//
// Description:  Ricoh EXIF maker notes tags
//
// Revisions:    03/28/2005 - P. Harvey Created
//
// References:   1) http://www.ozhiker.com/electronics/pjmt/jpeg_info/ricoh_mn.html
//               2) http://homepage3.nifty.com/kamisaka/makernote/makernote_ricoh.htm
//               3) Tim Gray private communication (GR)
//               4) https://github.com/atotto/ricoh-theta-tools/
//               5) https://github.com/ricohapi/theta-api-specs/blob/main/theta-metadata/README.md
//               IB) Iliah Borg private communication (LibRaw)
//
// PHP port of Image::ExifTool::Ricoh 1.38. Tag tables are generated into tables/Ricoh.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Ricoh
{
    public static $VERSION = '1.38';

    //------------------------------------------------------------------------------
    // Process Ricoh RDT* real-time metadata
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessRicohRDT(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $dirName = $dirInfo['DirName'];
        $rdtg = null;
        if ($dirLen < 16) return 0;
        $ee = $et->Options('ExtractEmbedded');
        if (!$ee) {
            $et->Warn('Use ExtractEmbedded option to read Ricoh real-time metadata', 3);
            return 1;
        }
        $endian = substr($dataPt, 8, 2);
        ExifTool::SetByteOrder($endian === "\x23\x01" ? 'II' : 'MM');
        $count = ExifTool::Get32u($dataPt, 0);
        $len = ExifTool::Get16u($dataPt, 6);
        if ($dirName === 'RicohRDTG') {
            if ($ee < 2) {
                $et->Warn('Set ExtractEmbedded option to 2 or higher to extract frame timestamps', 3);
                return 1;
            }
            $rdtg = 0;
            if ($len > 8) $et->Warn('Unexpected RDTG record length');
        }
        if ($count * $len + 16 > $dirLen) {
            $et->Warn("Truncated $dirName data");
            $count = Perl::int(($dirLen - 16) / $len);
        }
        $et->VerboseDir($dirName);
        $dirInfo['DirStart'] = 16;
        $dirInfo['DirLen'] = $len;
        for ($i = 0; $i < $count; ++$i) {
            $et->DOC_NUM = ++$et->DOC_COUNT;
            if ($rdtg !== null) $et->HandleTag($tagTablePtr, 100, $rdtg++);
            $et->ProcessBinaryData($dirInfo, $tagTablePtr);
            $dirInfo['DirStart'] += $len;
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Ricoh text-based maker notes
    // Inputs: 0) ExifTool object reference
    //         1) Reference to directory information hash
    //         2) Pointer to tag table for this directory
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessRicohText(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataLen = $dirInfo['DataLen'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: $dataLen - $dirStart;
        $verbose = $et->Options('Verbose');

        $data = substr($dataPt, $dirStart, $dirLen);
        if (preg_match('/^\0/', $data)) return 1;   // blank Ricoh maker notes
        $et->VerboseDir('RicohText', null, $dirLen);
        // validate text maker notes
        if (!preg_match('/^(Rev|Rv)/', $data)) {
            $et->Warn('Bad Ricoh maker notes');
            return 0;
        }
        preg_match_all('/([A-Z][a-z]{1,2})([0-9A-F]+);/s', $data, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $tag = $m[1];
            $val = $m[2];
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($verbose) {
                $et->VerboseInfo($tag, $tagInfo,
                    Table: $tagTablePtr,
                    Value: $val,
                );
            }
            if (!$tagInfo) {
                if (!$et->OPTIONS['Unknown']) continue;
                $tagInfo = [
                    'Name' => "Ricoh_Text_$tag",
                    'Unknown' => 1,
                    'PrintConv' => 'Image\ExifTool\ExifTool::LimitLongValues',
                ];
                // add tag information to table
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            $et->FoundTag($tagInfo, $val);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Ricoh APP5 RMETA information
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessRicohRMETA(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dataLen = strlen($dataPt);
        $dirLen = $dataLen - $dirStart;
        $verbose = $et->Options('Verbose');

        if ($verbose) $et->VerboseDir('Ricoh RMETA');
        if ($dirLen < 20) {
            $et->Warn('Truncated Ricoh RMETA data', 1);
            return 0;
        }
        $byteOrder = substr($dataPt, $dirStart, 2);
        if ($byteOrder === "\0\0") $byteOrder = ExifTool::GetByteOrder(); // (same order as container)
        if (!ExifTool::SetByteOrder($byteOrder)) {
            $et->Warn('Bad Ricoh RMETA data', 1);
            return 0;
        }
        // get the RMETA segment number
        $rmetaNum = ExifTool::Get16u($dataPt, $dirStart + 4);
        if ($rmetaNum != 0) {
            // not sure how to recognize audio, so do it by checking for "RIFF" header
            // and assume all subsequent RMETA segments are part of the audio data
            // (but it looks like the int16u at $dirStart+6 is the next block number
            // if the data is continued, or 0 for the last block)
            if ($dirLen < 14) {
                $et->Warn('Short Ricoh RMETA block', 1);
                return 0;
            }
            if (preg_match('/^.{20}BARCODE/s', $dataPt)) {
                $val = substr($dataPt, 20);
                $val = preg_replace('/\0.*/s', '', $val);
                $val = preg_replace('/^BARCODE\w+,\d{2},/', '', $val);
                $codes = [];
                for (;;) {
                    if (!preg_match('/(\d+),/', $val, $m, PREG_OFFSET_CAPTURE)) break;
                    $val = substr_replace($val, '', $m[0][1], strlen($m[0][0]));
                    $n = Perl::num($m[1][0]);
                    if (!(strlen($val) >= $n)) break;
                    $codes[] = substr($val, 0, $n);
                    if (!(strlen($val) > $n)) break;
                    $val = substr($val, $n + 1);
                }
                if ($codes) $et->HandleTag($tagTablePtr, '_barcode', $codes);
                return 1;
            } elseif (preg_match('/^.{18}ASCII/s', $dataPt)) {
                // (ignore barcode tag names for now)
                return 1;
            }
            $audioLen = ExifTool::Get16u($dataPt, $dirStart + 12);
            if ($audioLen + 14 > $dirLen) {
                $et->Warn('Truncated Ricoh RMETA audio data', 1);
                return 0;
            }
            $buff = substr($dataPt, $dirStart + 14, $audioLen);
            if ($audioLen >= 4 and substr($buff, 0, 4) === 'RIFF') {
                $et->HandleTag($tagTablePtr, '_audio', new ScalarRef($buff));
            } elseif ($et->VALUE['SoundFile'] ?? null) {
                $et->VALUE['SoundFile']->value .= $buff;
            } else {
                $et->Warn('Unknown Ricoh RMETA type', 1);
                return 0;
            }
            return 1;
        }
        // decode standard RMETA tag directory
        $tags = $vals = $nums = [];
        $valPos = $numPos = null;
        $pos = $dirStart + ExifTool::Get16u($dataPt, $dirStart + 8);
        $numEntries = ExifTool::Get16u($dataPt, $pos);
        if ($numEntries > 100) {
            $et->Warn('Bad RMETA entry count');
            return 0;
        }
        $pos += 10; // start of first RMETA section
        // loop through RMETA sections
        while ($pos <= $dataLen - 4) {
            $type = ExifTool::Get16u($dataPt, $pos);
            $size = ExifTool::Get16u($dataPt, $pos + 2);
            if (!$size) break;
            $pos += 4;
            $size -= 2;
            if ($size < 0 or $pos + $size > $dataLen) {
                $et->Warn('Corrupted Ricoh RMETA data', 1);
                break;
            }
            $dat = substr($dataPt, $pos, $size);
            if ($verbose) {
                $et->VPrint(2, "{$et->INDENT}RMETA section type=$type size=$size\n");
                $et->VerboseDump($dat, Addr: ($dirInfo['DataPos'] ?? null) + $pos);
            }
            if ($type == 1) {                       // section 1: tag names
                // save the tag names
                $tags = Perl::split('/\0/', $dat, $numEntries + 1);
            } elseif ($type == 2 || $type == 18) {  // section 2/18: string values (G800 uses type 18)
                // save the tag values (assume "ASCII\0" encoding since others never seen)
                $vals = Perl::split('/\0/', $dat, $numEntries + 1);
                $valPos = $pos; // save position of first string value
            } elseif ($type == 3) {                 // section 3: numerical values
                if ($size < $numEntries * 2) {
                    $et->Warn('Truncated RMETA section 3');
                } else {
                    // save the numerical tag values
                    // (0=empty, 0xffff=text input, otherwise menu item number)
                    $nums = Perl::unpack(($byteOrder === 'MM' ? 'n' : 'v') . $numEntries, $dat);
                    $numPos = $pos; // save position of numerical values
                }
            } elseif ($type != 16) {
                $et->Warn("Unrecognized RMETA section (type $type, len $size)");
            }
            $pos += $size;
        }
        if (!($tags or $vals)) return 1;
        $valPos or $valPos = 0; // (just in case there was no value section)
        // find next tag in null-delimited list
        // unpack numerical values from block of int16u values
        for ($i = 0; $i < $numEntries; ++$i) {
            $tag = $tags[$i] ?? null;
            $val = $vals[$i] ?? null;
            if ($val === null) $val = '';
            if (!($tag !== null and strlen($tag))) {
                if (!strlen($val)) {    // (skip empty entries)
                    ++$valPos;
                    continue;
                }
                $tag = '';
            }
            $name = preg_replace_callback('/\b([a-z])/s', static fn ($m) => strtoupper($m[1]), $tag);  // capitalize all words
            $name = preg_replace_callback('/ (\w)/', static fn ($m) => strtoupper($m[1]), $name);     // remove special characters
            if (!strlen($name)) $name = 'RMETA_Unknown';
            $num = $nums[$i] ?? null;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo) {
                // make sure print conversion is defined
                // (a lookup hash is a PHP array, a Perl ARRAY ref is a ConvList)
                if (!is_array($tagInfo->PrintConv)) $tagInfo->PrintConv = [];
            } else {
                // create tagInfo hash
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name, 'PrintConv' => []]);
            }
            // use string value directly if no numerical value
            if ($num === null) $num = $val;
            // add conversion for this value (replacing any existing entry)
            $tagInfo->PrintConv[$num] = strlen($val) ? $val : $num;
            if ($verbose) {
                $datParms = [];
                if (strlen($val)) {
                    $datParms = ['Start' => $valPos, 'Size' => strlen($val), 'Format' => 'string'];
                } elseif ($numPos) {
                    $datParms = ['Start' => $numPos + $i * 2, 'Size' => 2, 'Format' => 'int16u'];
                }
                if ($datParms) {
                    $datParms['DataPt'] = $dataPt;
                    $datParms['DataPos'] = $dirInfo['DataPos'] ?? null;
                }
                $et->VerboseInfo($tag, $tagInfo, ...['Table' => $tagTablePtr, 'Value' => $num, ...$datParms]);
            }
            $et->FoundTag($tagInfo, $num);
            $valPos += strlen($val) + 1;
        }
        return 1;
    }
}
