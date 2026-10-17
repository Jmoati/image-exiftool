<?php

//------------------------------------------------------------------------------
// File:         Parrot.pm
//
// Description:  Read timed metadata from Parrot drone videos
//
// Revisions:    2019-10-23 - P. Harvey Created
//
// References:   1) https://developer.parrot.com/docs/pdraw/metadata.html
//               --> changed to https://developer.parrot.com/docs/pdraw/video-metadata.html
//
// PHP port of Image::ExifTool::Parrot 1.03 (reading only). Tag tables are generated into
// tables/Parrot.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Parrot
{
    public static $VERSION = '1.03';

    //------------------------------------------------------------------------------
    // Parse Parrot 'mett' timed metadata
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // (this metadata design is really stupid -- you need to know the size of the base structures)
    public static function Process_mett(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirEnd = strlen($dataPt);
        $pos = ($dirInfo['DirStart'] ?? 0) ?: 0;
        $metaType = $et->MetaType ?: '';

        $et->VerboseDir('Parrot mett', null, $dirEnd);

        if ($tagTbl[$metaType]) {
            // loop through ARCore records
            while ($pos < $dirEnd - 2) {
                if (substr($dataPt, $pos, 1) !== "\x0a") break;
                $len = ord(substr($dataPt, $pos + 1, 1));
                if ($pos + $len + 2 > $dirEnd) {
                    $et->Warn("Unexpected length for $metaType record", 1);
                    break;
                }
                if (!$len) $len = $dirEnd - $pos - 2;  // use entire record if length is 0
                $et->HandleTag($tagTbl, $metaType, null,
                    DataPt:   $dataPt,
                    DataPos:  $dataPos,
                    DirStart: $pos,
                    DirLen:   $len,
                    Base:     $dirInfo['Base'] ?? null,
                );
                $pos += $len + 2;
            }
            return 1;
        }
        while ($pos + 4 < $dirEnd) {
            [$id, $nwords] = Perl::unpack("x{$pos}a2n", $dataPt);
            if (!preg_match('/^[EP]\d/', $id)) {
                // no ID so this should be a 60-byte V1 recording record, otherwise give up
                if ($dirEnd != 60) break;
                $id = 'P1'; // generate a fake ID
                // ignore the first 4 of the record so the fields will align with
                // the other V1 records (unfortunately, this means that we won't
                // decode the V1 recording frame timestamp, but oh well)
                $pos += 4;
                $size = $dirEnd - $pos;
            // must override size for P3 and P3 records since it includes the extensions (augh!)
            } elseif ($id === 'P2') {
                $size = 56;
            } elseif ($id === 'P3') {
                $size = 72;
            } else {
                $size = $nwords * 4 + 4;
            }
            if ($pos + $size > $dirEnd) break;
            $et->HandleTag($tagTbl, $id, null,
                DataPt:  $dataPt,
                DataPos: $dataPos,
                Base:    $dirInfo['Base'] ?? null,
                Start:   $pos,
                Size:    $size,
            );
            $pos += $size;
        }
        return 1;
    }
}
