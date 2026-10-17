<?php

//------------------------------------------------------------------------------
// File:         MinoltaRaw.pm
//
// Description:  Read/write Konica-Minolta RAW (MRW) meta information
//
// Revisions:    03/11/2006 - P. Harvey Split out from Minolta.pm
//
// References:   1) http://www.cybercom.net/~dcoffin/dcraw/
//               2) http://www.chauveau-central.net/mrw-format/
//               3) Igal Milchtaich private communication (A100)
//
// PHP port of Image::ExifTool::MinoltaRaw 1.20 (reading only). Tag tables are generated into
// tables/MinoltaRaw.php. Not ported (writing): WriteMRW and the OutFile branches of ProcessMRW.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class MinoltaRaw
{
    public static $VERSION = '1.20';

    //------------------------------------------------------------------------------
    // PrintConv for WBMode
    public static function ConvertWBMode($val)
    {
        $mrwWB = [
            0 => 'Auto',
            1 => 'Daylight',
            2 => 'Cloudy',
            3 => 'Tungsten',
            4 => 'Flash/Fluorescent',
            5 => 'Fluorescent',
            6 => 'Shade',
            7 => 'User 1',
            8 => 'User 2',
            9 => 'User 3',
            10 => 'Temperature',
        ];
        $lo = Perl::int($val) & 0x0f;
        $wbstr = ($mrwWB[$lo] ?? null) ?: "Unknown ($lo)";
        $hi = Perl::int($val) >> 4;
        if ($hi >= 6 and $hi <= 12) $wbstr .= ' (' . ($hi - 8) . ')';
        return $wbstr;
    }

    //------------------------------------------------------------------------------
    // Read or write Minolta MRW file
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) optional tag table ref
    // Returns: 1 on success, 0 if this wasn't a valid MRW file, or -1 on write error
    // Notes: File pointer must be set to start of MRW in RAF upon entry
    public static function ProcessMRW(ExifTool $et, array &$dirInfo, $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $data = $err = null;

        if (isset($dirInfo['DataPt'])) {
            // make a RAF object for MRW information extracted from other file types
            $raf = new RandomAccess(Perl::str($dirInfo['DataPt']));
            // MRW information in DNG images may not start at beginning of data block
            if (!empty($dirInfo['DirStart'])) $raf->Seek(Perl::num($dirInfo['DirStart']), 0);
        }
        if ($raf->Read($data, 8) != 8) return 0;
        // "\0MRM" for big-endian (MRW images), and
        // "\0MRI" for little-endian (MRWInfo in ARW images)
        if (!preg_match('/^\0MR([MI])/', $data, $m)) return 0;
        ExifTool::SetByteOrder($m[1] . $m[1]);
        $et->SetFileType();
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MinoltaRaw::Main');
        // (OutFile: writing not ported)
        $pos = $raf->Tell();
        $offset = ExifTool::Get32u($data, 4) + $pos;
        $rtnVal = 1;
        if ($verbose) fwrite($out, Perl::sprintf("  [MRW Data Offset: 0x%x]\n", $offset));
        // loop through MRW segments (ref 1)
        while ($pos < $offset) {
            if ($raf->Read($data, 8) != 8) {
                $err = 1;
                break;
            }
            $pos += 8;
            $tag = substr($data, 0, 4);
            $len = ExifTool::Get32u($data, 4);
            if ($verbose) {
                fwrite($out, 'MRW ' . $et->Printable($tag) . " segment ($len bytes):\n");
                if ($verbose > 2) {
                    if (!($raf->Read($data, $len) == $len and $raf->Seek($pos, 0))) {
                        $err = 1;
                        break;
                    }
                    $et->VerboseDump($data);
                }
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo and $tagInfo->SubDirectory) {
                $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                $buff = null;
                // save shift for values stored with wrong base offset
                $et->MRW_WrongBase = -($raf->Tell());
                if ($raf->Read($buff, $len) != $len) {
                    $err = 1;
                    break;
                }
                $subdirInfo = [
                    'DataPt' => $buff,
                    'DataLen' => $len,
                    'DataPos' => $pos,
                    'DirStart' => 0,
                    'DirLen' => $len,
                    'DirName' => $tagInfo->Name,
                    'Parent' => 'MRW',
                    'NoTiffEnd' => 1, // no end-of-TIFF check
                ];
                $processProc = $tagInfo->SubDirectory['ProcessProc'] ?? null;
                $et->ProcessDirectory($subdirInfo, $subTable, $processProc);
            } else {
                // skip this segment
                if (!$raf->Seek($pos + $len, 0)) {
                    $err = 1;
                    break;
                }
            }
            $pos += $len;
        }
        if ($pos != $offset) $err = 1;    // meta information length check

        if ($err) $et->Warn('MRW format error');
        if (!$et->A100DataOffset) $et->ImageDataHash($raf, null, 'raw');
        return $rtnVal;
    }
}
