<?php

//------------------------------------------------------------------------------
// File:         FotoStation.pm
//
// Description:  Read/write FotoWare FotoStation trailer
//
// Revisions:    10/28/2006 - P. Harvey Created
//
// PHP port of Image::ExifTool::FotoStation 1.04. Tag tables are generated into
// tables/FotoStation.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class FotoStation
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Read/write FotoStation information in a file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this file didn't contain FotoStation information
    // - updates DataPos to point to start of FotoStation information
    // - updates DirLen to trailer length
    public static function ProcessFotoStation(?ExifTool $et = null, ?array &$dirInfo = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package
        $buff = $footer = $dirBuff = $tagTablePtr = null;
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'] ?? null;
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $rtnVal = 0;

        $dirInfo['DirLen'] = 0;     // initialize returned trailer length
        $raf->Seek(-$offset, 2);    // seek to specified offset from end of file

        // loop through FotoStation records
        for (;;) {
            // look for trailer signature
            if (!($raf->Seek(-10, 1) and $raf->Read($footer, 10) == 10)) break;
            [$tag, $size, $sig] = Perl::unpack('nNN', $footer);
            if (!($sig == 0xa1b2c3d4 and $size >= 10 and $raf->Seek(-$size, 1))) break;
            $size -= 10;    // size of data only
            if ($raf->Read($buff, $size) != $size) break;
            $raf->Seek(-$size, 1);
            // set variables returned in dirInfo hash
            $dirInfo['DataPos'] = $raf->Tell();
            $dirInfo['DirLen'] += $size + 10;

            if (!$tagTablePtr) {
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FotoStation::Main');
                ExifTool::SetByteOrder('MM');   // necessary for the binary data
                $rtnVal = 1;        // we found a valid FotoStation trailer
            }
            if (!$outfile) {
                // print verbose trailer information
                if ($verbose or $et->HTML_DUMP) {
                    $et->DumpTrailer([
                        'RAF' => $raf,
                        'DataPos' => $dirInfo['DataPos'],
                        'DirLen' => $size + 10,
                        'DirName' => "FotoStation_$tag",
                    ]);
                }
                // extract information for this tag
                $et->HandleTag($tagTablePtr, $tag, $buff,
                    DataPt: $buff,
                    Start: 0,
                    Size: $size,
                    DataPos: $dirInfo['DataPos'],
                );
                continue;
            }
            if ($et->DEL_GROUP['FotoStation'] ?? null) {
                if ($verbose) fwrite($out, "  Deleting FotoStation trailer\n");
                $verbose = 0;   // no more verbose messages after this
                ++$et->CHANGED;
                continue;
            }
            // rewrite this information
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo) {
                $newVal = null;
                $tagName = $tagInfo->Name;
                if ($tagInfo->SubDirectory) {
                    $subdirInfo = [
                        'DataPt' => $buff,
                        'DirStart' => 0,
                        'DirLen' => $size,
                        'DataPos' => $dirInfo['DataPos'],
                        'DirName' => $tagName,
                        'Parent' => 'FotoStation',
                    ];
                    $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    $newVal = $et->WriteDirectory($subdirInfo, $subTable);
                } else {
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    if ($et->IsOverwriting($nvHash) > 0) {
                        $newVal = $et->GetNewValue($nvHash);
                        if ($newVal === null) $newVal = '';
                        if ($verbose > 1) {
                            $n = strlen($newVal);
                            if ($size) fwrite($out, "    - FotoStation:$tagName ($size bytes)\n");
                            if ($n) fwrite($out, "    + FotoStation:$tagName ($n bytes)\n");
                        }
                        ++$et->CHANGED;
                    }
                }
                if ($newVal !== null) {
                    // note: length may be 0 here, but we write the empty record anyway
                    $buff = $newVal;
                    $size = strlen($newVal) + 10;
                    $footer = pack('nNN', $tag, $size, $sig);
                }
            }
            if ($dirBuff !== null) {
                // maintain original record order
                $dirBuff = $buff . $footer . $dirBuff;
            } else {
                $dirBuff = $buff . $footer;
            }
        }
        // write the modified FotoStation trailer
        if ($dirBuff and !ExifTool::Write($outfile, $dirBuff)) $rtnVal = -1;
        return $rtnVal;
    }
}
