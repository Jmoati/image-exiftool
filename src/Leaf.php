<?php

//------------------------------------------------------------------------------
// File:         Leaf.pm
//
// Description:  Read Creo Leaf EXIF meta information
//
// Revisions:    09/28/2005  - P. Harvey Created
//
// PHP port of Image::ExifTool::Leaf 1.08. Tag tables are generated into tables/Leaf.php
// (after the load-time block that generates the tag names and table notes).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Leaf
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Process Leaf information
    // Inputs: 0) ExifTool object reference
    //         1) Reference to directory information hash
    //         2) Pointer to tag table for this directory
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessLeaf(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: ($dirInfo['DataLen'] ?? null) - $dirStart;
        $dirEnd = $dirStart + $dirLen;
        $verbose = $et->Options('Verbose');
        $pos = $dirStart;
        $hdrLen = 52;   // header length for PKTS information
        $success = null;
        // (Perl compares $tagTablePtr with \%Image::ExifTool::Leaf::Main)
        $isMain = ($tagTablePtr['TABLE_NAME'] === 'Image::ExifTool::Leaf::Main');

        if ($verbose) $et->VerboseDir('Leaf');
        for (;;) {
            if ($pos + $hdrLen > $dirEnd) break;
            $header = substr($dataPt, $pos, $hdrLen);
            if (substr($header, 0, 4) !== 'PKTS') break;
            $success = 1;
            $size = ExifTool::Get32u($header, 48);
            $pos += $hdrLen;
            if ($pos + $size > $dirEnd) {
                $et->Warn('Truncated Leaf data');
                break;
            }
            $tag = substr($header, 8, 40);
            $tag = preg_replace('/\0.*/s', '', $tag);
            if (!$tag) continue;   // (doesn't skip the value data, like Perl)
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            // generate tag info for unknown tags
            if ($tagInfo and $tagInfo->Format) {
                $val = ExifTool::ReadValue($dataPt, $pos, $tagInfo->Format, null, $size);
            } else {
                $val = substr($dataPt, $pos, $size);
            }
            if (!$tagInfo) {
                $name = ucfirst($tag);
                $name = preg_replace_callback('/_(.)/', static fn ($m) => strtoupper($m[1]), $name);
                if (preg_match('/^PKTS\0\0\0\x01/', Perl::str($val))) {
                    // also unpack unknown directories
                    $tagInfo = [
                        'Name' => $name,
                        'SubDirectory' => ['TagTable' => 'Image::ExifTool::Leaf::Unknown'],
                    ];
                } elseif (!$isMain or
                         $et->Options('Unknown'))
                {
                    $tagInfo = [
                        'Name' => $name,
                        'Writable' => 0,
                        'PrintConv' => 'Image\ExifTool\ExifTool::LimitLongValues',
                    ];
                    // make tags in main table unknown because they tend to be binary
                    if ($isMain) $tagInfo['Unknown'] = 1;
                }
                if ($tagInfo) $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            if ($verbose) {
                $et->VerboseInfo($tag, $tagInfo,
                    Table: $tagTablePtr,
                    Value: $val,
                    DataPt: $dataPt,
                    DataPos: $dirInfo['DataPos'] ?? null,
                    Size: $size,
                    Start: $pos,
                );
            }
            if ($tagInfo) {
                if ($tagInfo->SubDirectory) {
                    $subdirInfo = [
                        'DataPt' => $dataPt,
                        'DirLen' => $size,
                        'DirStart' => $pos,
                        'DataPos' => $dirInfo['DataPos'] ?? null,
                        'DirName' => 'Leaf PKTS',
                    ];
                    $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    $et->ProcessDirectory($subdirInfo, $subTable);
                } else {
                    $val = strtr(Perl::str($val), "\n", ' ');   // translate newlines to spaces
                    $val = preg_replace('/\0+$/', '', $val);    // remove null terminators
                    $et->FoundTag($tagInfo, $val);
                }
            }
            $pos += $size;
        }
        if (!$success) $et->Warn('Bad format Leaf data');
        return $success;
    }
}
