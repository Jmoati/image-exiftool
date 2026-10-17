<?php

//------------------------------------------------------------------------------
// File:         Palm.pm
//
// Description:  Read Palm Database files
//
// Revisions:    2014/05/28 - P. Harvey Created
//
// References: 1) http://wiki.mobileread.com/wiki/PDB
//             2) http://wiki.mobileread.com/wiki/MOBI
//
// PHP port of Image::ExifTool::Palm 1.00 (reading only). Tag tables and file lexicals
// (%palmTypes) are generated into tables/Palm.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Palm
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Process the MOBI extended header
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 (EXTH should have already been validated)
    public static function ProcessEXTH(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $enc = ($dirInfo['Encoding'] ?? null) ?: 'UTF8';
        $dirLen = strlen($dataPt);

        $et->VerboseDir('EXTH', $dirInfo['NumEntries'] ?? null, $dirLen);

        // process the EXTH entries
        for ($index = 0, $pos = 0; ; ++$index) {
            if ($pos + 8 > $dirLen) break;
            $tag = ExifTool::Get32u($dataPt, $pos);
            $len = ExifTool::Get32u($dataPt, $pos + 4);
            if ($len < 8 or $pos + $len > $dirLen) break;
            $key = $et->HandleTag($tagTablePtr, $tag, null,
                DataPt:  $dataPt,
                DataPos: $dataPos,
                Start:   $pos + 8,
                Size:    $len - 8,
                Index:   $index,
            );
            // recode text if necessary
            if ($key) $et->VALUE[$key] = $et->Decode($et->VALUE[$key], $enc);
            $pos += $len;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a Palm DB file
    // Inputs: 0) ExifTool ref, 1) dirInfo reference
    // Returns: 1 if this was a recognized PDB file, 0 otherwise
    public static function ProcessPDB(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $size = $enc = null;

        // verify this is a valid Palm DB file
        if ($raf->Read($buff, 86) != 86) return 0;
        $type = Vars\Palm::$palmTypes[substr($buff, 60, 8)] ?? null;
        if (!$type) return 0;
        //
        // Read and process the Palm DB file header
        //
        $et->SetFileType($type === 'Mobipocket' ? 'MOBI' : 'PDB');
        ExifTool::SetByteOrder('MM');

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Palm::Main');
        $subdir = ['DataPt' => $buff];
        $et->ProcessDirectory($subdir, $tagTablePtr);

        if (!($type === 'Mobipocket' and ExifTool::Get16u($buff, 76))) return 1;
        //
        // Read and process MOBI header (should be the first record)
        //
        $offset = ExifTool::Get32u($buff, 78);    // get offset to first record
        if (!($raf->Seek($offset, 0) and $raf->Read($buff, 274) == 274)) {
            $et->Warn('Truncated MOBI header');
            return 1;
        }
        if (substr($buff, 16, 4) !== 'MOBI') {
            $et->Warn('Invalid MOBI header');
            return 1;
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Palm::MOBI');
        $subdir = ['DataPt' => $buff];
        $et->ProcessDirectory($subdir, $tagTablePtr);

        // get text encoding
        if ($et->CodePage) $enc = Vars\ExifTool::$charsetName['cp' . $et->CodePage] ?? null;
        if (!$enc) $enc = 'UTF8';

        // extract the BookName string
        $off = ExifTool::Get32u($buff, 84);
        $len = ExifTool::Get32u($buff, 88);

        if (!($raf->Seek($offset + $off, 0) and $raf->Read($buf2, $len) == $len)) $buf2 = '<err>';
        $et->VALUE['BookName'] = $et->Decode($buf2, $enc);
        //
        // Process the MOBI extended header if it exists
        //
        // first, check the flag bit to see if the EXTH record should exist
        $flag = ExifTool::Get32u($buff, 128);
        if (!($flag & 0x40)) return 1;   // check extended header flag

        $len = ExifTool::Get32u($buff, 20) + 16; // MOBI header length (including PalmDOC header)

        if (!($raf->Seek($offset + $len, 0) and $raf->Read($buf2, 12) == 12 and
            substr($buf2, 0, 4) === 'EXTH' and ($size = ExifTool::Get32u($buf2, 4)) > 12))
        {
            $et->Warn('Invalid MOBI extended header');
            return 1;
        }

        // read and process the MOBI extended header
        $size -= 12;
        if ($raf->Read($buff, $size) != $size) {
            $et->Warn('Truncated MOBI extended header');
            return 1;
        }
        $dirInfo2 = [   // (Perl: my %dirInfo)
            'DataPt'     => $buff,
            'DataPos'    => $offset + $len + 12,
            'NumEntries' => ExifTool::Get32u($buf2, 8),
            'Encoding'   => $enc,
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Palm::EXTH');
        $et->ProcessDirectory($dirInfo2, $tagTablePtr);

        return 1;
    }
}
