<?php

//------------------------------------------------------------------------------
// File:         BigTIFF.pm
//
// Description:  Read Big TIFF meta information
//
// Revisions:    07/03/2007 - P. Harvey Created
//
// References:   1) http://www.awaresystems.be/imaging/tiff/bigtiff.html
//
// PHP port of Image::ExifTool::BigTIFF 1.08 (reading only).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class BigTIFF
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Process Big IFD directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessBigIFD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $maxOffset = Vars\BigTIFF::$maxOffset;  // currently supported maximum data offset/size
        $raf = $dirInfo['RAF'];
        $verbose = $et->OPTIONS['Verbose'];
        $htmlDump = $et->HTML_DUMP;
        $dirName = $dirInfo['DirName'];
        $dirStart = $dirInfo['DirStart'];
        $offName = $nextOffName = null;

        if ($htmlDump) {
            $verbose = -1;  // mix htmlDump into verbose so we can test for both at once
            $offName = $dirInfo['OffsetName'] ?? null;
        }

        // loop through IFD chain
        for (;;) {
            if ($dirStart > $maxOffset and !$et->Options('LargeFileSupport')) {
                $et->Warn('Huge offsets not supported (LargeFileSupport not set)');
                break;
            }
            if (!$raf->Seek($dirStart, 0)) {
                $et->Warn("Bad $dirName offset");
                return 0;
            }
            $dirBuff = $index = null;
            if ($raf->Read($dirBuff, 8) != 8) {
                $et->Warn("Truncated $dirName count");
                return 0;
            }
            $numEntries = ExifTool::Get64u($dirBuff, 0);
            if ($verbose > 0) $et->VerboseDir($dirName, $numEntries);
            $bsize = $numEntries * 20;
            if ($bsize > $maxOffset) {
                $et->Warn('Huge directory counts not yet supported');
                break;
            }
            $bufPos = $raf->Tell();
            if ($raf->Read($dirBuff, $bsize) != $bsize) {
                $et->Warn("Truncated $dirName directory");
                return 0;
            }
            $nextIFD = null;
            if ($raf->Read($nextIFD, 8) != 8) $nextIFD = null; // try to read next IFD pointer
            if ($htmlDump) {
                $et->HDump($bufPos - 8, 8, "$dirName entries", "Entry count: $numEntries", null, $offName);
                if ($nextIFD !== null) {
                    $off = ExifTool::Get64u($nextIFD, 0);
                    $tip = Perl::sprintf('Offset: 0x%.8x', $off);
                    $id = $offName;
                    if ($off) [$nextOffName, $id] = Exif::NextOffsetName($et, $id);
                    $et->HDump($bufPos + 20 * $numEntries, 8, 'Next IFD', $tip, 0, $id);
                }
            }
            // loop through all entries in this BigTIFF IFD
            for ($index = 0; $index < $numEntries; ++$index) {
                $entry = 20 * $index;
                $tagID = ExifTool::Get16u($dirBuff, $entry);
                $format = ExifTool::Get16u($dirBuff, $entry + 2);
                $count = ExifTool::Get64u($dirBuff, $entry + 4);
                $formatSize = Vars\Exif::$formatSize[$format] ?? null;
                if ($formatSize === null) {
                    $et->HDump($bufPos + $entry, 20, '[invalid IFD entry]',
                               "Bad format value: $format", 1, $offName);
                    // warn unless the IFD was just padded with zeros
                    $et->Warn(Perl::sprintf("Unknown format ($format) for $dirName tag 0x%x", $tagID));
                    return 0; // assume corrupted IFD
                }
                $formatStr = Vars\Exif::$formatName[$format];
                $size = $count * $formatSize;
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
                if (!($tagInfo !== null or $verbose)) continue;
                $valuePtr = $entry + 12;
                $valBuff = $valBase = $rational = $subOffName = null;
                if ($size > 8) {
                    if ($size > $maxOffset) {
                        $et->Warn("Can't handle $dirName entry $index (huge size)");
                        continue;
                    }
                    $valuePtr = ExifTool::Get64u($dirBuff, $valuePtr);
                    if ($valuePtr > $maxOffset and !$et->Options('LargeFileSupport')) {
                        $et->Warn("Can't handle $dirName entry $index (LargeFileSupport not set)");
                        continue;
                    }
                    if (!($raf->Seek($valuePtr, 0) and $raf->Read($valBuff, $size) == $size)) {
                        $et->Warn("Error reading $dirName entry $index");
                        continue;
                    }
                    $valBase = 0;
                } else {
                    $valBuff = substr($dirBuff, $valuePtr, (int) $size);
                    $valBase = $bufPos;
                }
                if ($tagInfo !== null and !$tagInfo) {
                    // GetTagInfo() required the value for a Condition
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID, $valBuff);
                }
                $val = ExifTool::ReadValue($valBuff, 0, $formatStr, $count, $size, $rational);
                if ($htmlDump) {
                    $tval = $val;
                    // show numerator/denominator separately for rational numbers
                    if ($rational !== null) $tval = Perl::str($tval) . " ($rational)";
                    $tagName = $colName = null;
                    if ($tagID == 0x927c and $dirName === 'ExifIFD') {
                        $tagName = 'MakerNotes';
                    } elseif ($tagInfo) {
                        $tagName = $tagInfo->Name;
                    } else {
                        $tagName = Perl::sprintf('Tag 0x%.4x', $tagID);
                    }
                    $dname = Perl::sprintf("$dirName-%.2d", $index);
                    // build our tool tip
                    $tip = Perl::sprintf("Tag ID: 0x%.4x\n", $tagID) .
                           "Format: $formatStr" . "[$count]\nSize: $size bytes\n";
                    if ($size > 8) {
                        $tip .= Perl::sprintf("Value offset: 0x%.8x\n", $valuePtr);
                        $colName = "<span class=H>$tagName</span>";
                    } else {
                        $colName = $tagName;
                    }
                    $tval = Perl::str($tval);
                    if (strlen($tval) > 32) $tval = substr($tval, 0, 28) . '[...]';
                    if (preg_match('/^(string|undef|binary)/', $formatStr)) {
                        // translate non-printable characters
                        $tval = Perl::tr($tval, "\x00-\x1f\x7f-\xff", '.')[0];
                    } elseif ($tagInfo and ExifTool::IsInt($tval)) {
                        if ($tagInfo->IsOffset) {
                            $tval = Perl::sprintf('0x%.4x', $tval);
                        } elseif ($tagInfo->PrintHex) {
                            $tval = Perl::sprintf('0x%x', $tval);
                        }
                    }
                    $tip .= "Value: $tval";
                    $id = $sid = null;
                    if ($tagInfo and $tagInfo->SubIFD) {
                        [$subOffName, $id, $sid] = Exif::NextOffsetName($et, $offName);
                    } else {
                        $id = $offName;
                    }
                    $et->HDump($entry + $bufPos, 20, "$dname $colName", $tip, 1, $id);
                    if ($size > 8) {
                        // add value data block
                        $flg = ($tagInfo and $tagInfo->SubDirectory and $tagInfo->MakerNotes) ? 4 : 0;
                        $et->HDump($valuePtr, $size, "$tagName value", 'SAME', $flg, $sid);
                    }
                }
                if ($tagInfo and $tagInfo->SubIFD) {
                    // process all SubIFD's as BigTIFF
                    if ($verbose > 0) $et->VerboseInfo($tagID, $tagInfo,
                        Table:   $tagTablePtr,
                        Index:   $index,
                        Value:   $val,
                        DataPt:  $valBuff,
                        DataPos: $valBase + $valuePtr,
                        Start:   0,
                        Size:    $size,
                        Format:  $formatStr,
                        Count:   $count,
                    );
                    $offsets = Perl::split(' ', $val);
                    for ($i = 0; $i < count($offsets); ++$i) {
                        $subdirName = $tagInfo->Name;
                        if ($i) $subdirName .= $i;
                        $subdirInfo = [     // (Perl %subdirInfo)
                            'RAF'        => $raf,
                            'DataPos'    => 0,
                            'DirStart'   => $offsets[$i],
                            'DirName'    => $subdirName,
                            'Parent'     => $dirName,
                            'OffsetName' => $subOffName,
                        ];
                        $et->ProcessDirectory($subdirInfo, $tagTablePtr, 'Image\ExifTool\BigTIFF::ProcessBigIFD');
                    }
                } else {
                    $tagKey = $et->HandleTag($tagTablePtr, $tagID, $val,
                        Index:   $index,
                        DataPt:  $valBuff,
                        DataPos: $valBase + $valuePtr,
                        Start:   0,
                        Size:    $size,
                        Format:  $formatStr,
                        TagInfo: $tagInfo,
                        RAF:     $raf,
                    );
                    if ($tagKey) $et->SetGroup($tagKey, $dirName);
                }
            }
            if (!preg_match('/^(IFD|SubIFD)(\d*)$/', $dirName, $m)) break;
            $dirName = $m[1] . ((($m[2] ?? '') ?: 0) + 1);
            if ($nextIFD === null) {
                $et->Warn("Bad $dirName pointer");
                return 0;
            }
            $dirStart = ExifTool::Get64u($nextIFD, 0);
            if (!$dirStart) break;
            $offName = $nextOffName;
            // protect against infinite loop
            $addr = Perl::str($dirStart);
            if (!empty($et->PROCESSED[$addr])) {
                $et->Warn("$dirName pointer references previous {$et->PROCESSED[$addr]} directory");
                break;
            } else {
                $et->PROCESSED[$addr] = $dirName;
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract meta information from a BigTIFF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid BigTIFF image
    public static function ProcessBTF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if ($raf->Read($buff, 16) != 16) return 0;
        if (!preg_match('/^(MM\0\x2b\0\x08\0\0|II\x2b\0\x08\0\0\0)/', $buff)) return 0;
        if (!empty($dirInfo['OutFile'])) {
            $et->Error('ExifTool does not support writing of BigTIFF images');
            return 1;
        }
        $et->SetFileType('BTF'); // set the FileType tag
        ExifTool::SetByteOrder(substr($buff, 0, 2));
        $offset = ExifTool::Get64u($buff, 8);
        if ($et->HTML_DUMP) {
            $o = (ExifTool::GetByteOrder() === 'II') ? 'Little' : 'Big';
            $et->HDump(0, 8, 'BigTIFF header', "Byte order: $o endian", 0);
            $et->HDump(8, 8, 'IFD0 pointer', Perl::sprintf('Offset: 0x%.8x', $offset), 0);
        }
        $subdirInfo = [     // (Perl %dirInfo)
            'RAF'      => $raf,
            'DataPos'  => 0,
            'DirStart' => $offset,
            'DirName'  => 'IFD0',
            'Parent'   => 'BigTIFF',
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
        $et->ProcessDirectory($subdirInfo, $tagTablePtr, 'Image\ExifTool\BigTIFF::ProcessBigIFD');
        return 1;
    }
}
