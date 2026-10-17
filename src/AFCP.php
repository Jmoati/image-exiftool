<?php

//------------------------------------------------------------------------------
// File:         AFCP.pm
//
// Description:  Read/write AXS File Concatenation Protocol trailer
//
// Revisions:    05/26/2006 - P. Harvey Created
//
// Notes:        Plays reasonably well with FotoStation trailers
//
// PHP port of Image::ExifTool::AFCP 1.10 (reading only). Tag tables are generated
// into tables/AFCP.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class AFCP
{
    public static $VERSION = '1.10';

    //------------------------------------------------------------------------------
    // Read/write AFCP information in a file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // (Set 'ScanForTrailer' member in dirInfo to scan from current position for AFCP)
    // Returns: 1 on success, 0 if this file didn't contain AFCP information
    //          -1 on write error or if the offsets were incorrect on reading
    // - updates DataPos to point to actual AFCP start if ScanForTrailer is set
    // - updates DirLen to trailer length
    // - returns Fixup reference in dirInfo hash when writing
    public static function ProcessAFCP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $curPos = $raf->Tell();
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;    // offset from end of file
        $rtnVal = 0;

        // (Perl "last NoAFCP" is a "break 2" from the inner loops)
        for (;;) {
            $buff = $fix = null;
            // look for AXS trailer
            if (!($raf->Seek(-12 - $offset, 2) and
                  $raf->Read($buff, 12) == 12 and
                  preg_match('/^(AXS(!|\*))/', $buff, $m)))
            {
                break;
            }
            $endPos = $raf->Tell();
            $hdr = $m[1];
            ExifTool::SetByteOrder($m[2] === '!' ? 'MM' : 'II');
            $startPos = ExifTool::Get32u($buff, 4);
            // (note: $hdr is used as a regular expression, like Perl does)
            if ($raf->Seek($startPos, 0) and $raf->Read($buff, 12) == 12 and preg_match("/^$hdr/", $buff)) {
                $fix = 0;
            } else {
                $rtnVal = -1;
                // look for start of AXS trailer if 'ScanForTrailer'
                if (!(($dirInfo['ScanForTrailer'] ?? null) and $raf->Seek($curPos, 0))) break;
                $actualPos = $curPos;
                // first look for header right at current position
                for (;;) {
                    if ($raf->Read($buff, 12) == 12 and preg_match("/^$hdr/", $buff)) break;
                    if ($actualPos != $curPos) break 2;
                    // scan for AXS header (could be after preview image)
                    for (;;) {
                        $buf2 = null;
                        if (!$raf->Read($buf2, 65536)) break 3;
                        $buff .= $buf2;
                        if (preg_match("/$hdr/", $buff, $mm, PREG_OFFSET_CAPTURE)) {
                            $actualPos += $mm[0][1] + strlen($mm[0][0]) - strlen($hdr);  // (pos($buff) - length($hdr))
                            break;  // ok, now go back and re-read header
                        }
                        $buf2 = substr($buf2, -3);  // only need last 3 bytes for next test
                        $actualPos += strlen($buff) - strlen($buf2);
                        $buff = $buf2;
                    }
                    if (!$raf->Seek($actualPos, 0)) break;  // seek to start of AFCP
                }
                // calculate shift for fixing AFCP offsets
                $fix = $actualPos - $startPos;
            }
            // set variables returned in dirInfo hash
            $dirInfo['DataPos'] = $startPos + $fix;     // actual start position
            $dirInfo['DirLen'] = $endPos - ($startPos + $fix);

            $rtnVal = 1;
            $verbose = $et->Options('Verbose');
            $out = $et->Options('TextOut');
            // (writing not ported)
            if ($verbose or $et->HTML_DUMP) $et->DumpTrailer($dirInfo);
            // read AFCP directory data
            $numEntries = ExifTool::Get16u($buff, 6);
            $dir = null;
            if ($raf->Read($dir, 12 * $numEntries) != 12 * $numEntries) {
                $et->Error('Error reading AFCP directory', 1);
                break;
            }
            if ($verbose > 2) {
                $dat = $buff . $dir;
                fwrite($out, "  AFCP Directory:\n");
                $et->VerboseDump($dat, Addr: $dirInfo['DataPos'], Width: 12);
            }
            if ($fix) $et->Warn("Adjusted AFCP offsets by $fix", 1);
//
// process AFCP directory
//
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::AFCP::Main');
            for ($index = 0; $index < $numEntries; ++$index) {
                $entry = 12 * $index;
                $tag = substr($dir, $entry, 4);
                $size = ExifTool::Get32u($dir, $entry + 4);
                $offset = ExifTool::Get32u($dir, $entry + 8);
                if ($size < 0x80000000 and
                    $raf->Seek($offset + $fix, 0) and
                    $raf->Read($buff, $size) == $size)
                {
                    // extract information
                    $et->HandleTag($tagTablePtr, $tag, $buff,
                        DataPt: $buff,
                        Size: $size,
                        Index: $index,
                        DataPos: $offset + $fix,
                    );
                } else {
                    $et->Warn('Bad AFCP directory');
                    break;
                }
            }
            break;
        }
        return $rtnVal;
    }
}
