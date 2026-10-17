<?php

//------------------------------------------------------------------------------
// File:         DNG.pm
//
// Description:  Read DNG-specific information
//
// Revisions:    01/09/2006 - P. Harvey Created
//
// References:   1) http://www.adobe.com/products/dng/
//
// PHP port of Image::ExifTool::DNG 1.25 (reading only: WriteAdobeStuff and the OutFile
// branches are not ported). Tag tables are generated into tables/DNG.php (with the MakN
// list already filled in from the MakerNotes table).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class DNG
{
    public static $VERSION = '1.25';

    //------------------------------------------------------------------------------
    // Process DNG OriginalRawFileData information
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessOriginalRaw(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $end = $start + $dirInfo['DirLen'];
        $pos = $start;
        $err = null;

        ExifTool::SetByteOrder('MM'); // pointers are always big-endian in this structure
        for ($index = 0; $index < 8; ++$index) {
            if ($pos + 4 > $end) break;
            $val = ExifTool::Get32u($dataPt, $pos);
            if (!$val) {    // ignore zero values
                $pos += 4;
                continue;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $index);
            if (!$tagInfo) {
                $err = "Missing DNG tag $index";
                break;
            }
            if ($index & 0x02) {
                // extract a simple file type (tags 2, 3, 6 and 7)
                $val = substr($dataPt, $pos, 4);
                $pos += 4;
            } else {
                // extract a compressed data block (tags 0, 1, 4 and 5)
                $n = Perl::int(($val + 65535) / 65536);
                $hdrLen = 4 * ($n + 2);
                if ($pos + $hdrLen > $end) {
                    $err = '';
                    break;
                }
                $tag = $tagInfo->Name;
                // only extract this information if requested (because it takes time)
                $lcTag = strtolower($tag);
                if (($et->OPTIONS['Binary'] and empty($et->EXCL_TAG_LOOKUP[$lcTag])) or
                    !empty($et->REQ_TAG_LOOKUP[$lcTag]))
                {
                    if (!function_exists('inflate_init')) {
                        $err = 'Install Compress::Zlib to extract compressed images';
                        break;
                    }
                    $val = '';
                    $p2 = $pos + ExifTool::Get32u($dataPt, $pos + 4);
                    for ($i = 0; $i < $n; ++$i) {
                        // inflate this compressed block
                        $p1 = $p2;
                        $p2 = $pos + ExifTool::Get32u($dataPt, $pos + ($i + 2) * 4);
                        if ($p1 >= $p2 or $p2 > $end) {
                            $err = 'Bad compressed RAW image';
                            break;
                        }
                        $buff = substr($dataPt, $p1, $p2 - $p1);
                        // (Perl: Compress::Zlib::inflateInit() and inflate(), zlib format)
                        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                        $v2 = $inflate ? @inflate_add($inflate, $buff, ZLIB_SYNC_FLUSH) : false;
                        if ($inflate and $v2 !== false and inflate_get_status($inflate) == ZLIB_STREAM_END) {
                            $val .= $v2;
                        } else {
                            $err = 'Error inflating compressed RAW image';
                            break;
                        }
                    }
                    $pos = $p2;
                } else {
                    if ($pos + $hdrLen > $end) {
                        $err = '';
                        break;
                    }
                    $len = ExifTool::Get32u($dataPt, $pos + $hdrLen - 4);
                    if ($pos + $len > $end) {
                        $err = '';
                        break;
                    }
                    $val = substr($dataPt, $pos + $hdrLen, $len - $hdrLen);
                    $val = "Binary data $len bytes";
                    $pos += $len;   // skip over this block
                }
            }
            $et->FoundTag($tagInfo, $val);
        }
        if ($err !== null) $et->Warn($err ?: 'Bad OriginalRawFileData');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Adobe DNGPrivateData directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessAdobeData(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = $dirInfo['DirStart'];
        $end = $dirInfo['DirLen'] + $pos;
        $verbose = $et->Options('Verbose');
        $htmlDump = $et->Options('HtmlDump');

        if (!preg_match('/^Adobe\0/', $dataPt)) return 0;
        $et->VerboseDir($dirInfo);
        // don't parse makernotes if FastScan > 1
        $fast = $et->Options('FastScan');
        if ($fast and $fast > 1) return 1;
        if ($htmlDump) $et->HDump($dataPos, 6, 'Adobe DNGPrivateData header');
        ExifTool::SetByteOrder('MM'); // always big endian
        $pos += 6;
        while ($pos + 8 <= $end) {
            [$tag, $size] = Perl::unpack("x{$pos}a4N", $dataPt);
            $pos += 8;
            if ($pos + $size > $end) break;
            $tagInfo = $tagTablePtr[$tag];
            if ($htmlDump) {
                $name = "Adobe$tag";
                $name = str_replace(' ', '', $name);
                $et->HDump($dataPos + $pos - 8, 8, "$name header", "Data Size: $size bytes");
                // dump non-EXIF format data
                if (!preg_match('/^(MakN|SR2 )$/', $tag)) {
                    $et->HDump($dataPos + $pos, $size, "$name data");
                }
            }
            if ($verbose) {
                if (!$tagInfo) $et->VPrint(0, "{$et->INDENT}Unsupported DNGAdobeData record: ($tag)\n");
                $et->VerboseInfo($tag,
                    $tagInfo instanceof TagInfo ? $tagInfo : null,
                    DataPt: $dataPt,
                    DataPos: $dataPos,
                    Start: $pos,
                    Size: $size,
                );
            }
            // (Perl: while ($tagInfo) { ... last; }, which runs at most once)
            if ($tagInfo) {
                $subTable = $subName = $processProc = null;
                $handled = null;
                if ($tagInfo instanceof TagInfo) {
                    if (!$tagInfo->SubDirectory) {
                        $et->HandleTag($tagTablePtr, $tag, substr($dataPt, $pos, $size));
                        $handled = 1;
                    } else {
                        $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                        $subName = $tagInfo->Name;
                        $processProc = $tagInfo->SubDirectory['ProcessProc'] ?? null;
                    }
                } else {
                    $subTable = $tagTablePtr;
                    $subName = 'AdobeMakN';
                    $processProc = 'Image\ExifTool\DNG::ProcessAdobeMakN';
                }
                if (!$handled) {
                    $subdirInfo = [     // (Perl: my %dirInfo)
                        'Base'     => $dirInfo['Base'] ?? null,
                        'DataPt'   => $dataPt,
                        'DataPos'  => $dataPos,
                        'DataLen'  => $dirInfo['DataLen'] ?? null,
                        'DirStart' => $pos,
                        'DirLen'   => $size,
                        'DirName'  => $subName,
                    ];
                    // override process proc for MakN
                    $et->ProcessDirectory($subdirInfo, $subTable, $processProc);
                }
            }
            $pos += $size;
            if ($size & 0x01) ++$pos; // (darn padding)
        }
        if ($pos != $end) $et->Warn("$pos $end Adobe private data is corrupt");
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Adobe CRW directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    // Notes: data has 4 byte header (2 for byte order and 2 for entry count)
    // - this routine would be as simple as ProcessAdobeMRW() below if Adobe hadn't
    //   pulled the bonehead move of reformatting the CRW information
    public static function ProcessAdobeCRW(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $end = $start + $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');
        $buildMakerNotes = $et->Options('MakerNotes');

        ExifTool::SetByteOrder('MM'); // always big endian
        if ($dirInfo['DirLen'] < 4) return 0;
        $byteOrder = substr($dataPt, $start, 2);
        if (!preg_match('/^(II|MM)$/', $byteOrder)) return 0;

        // initialize maker note data if building maker notes
        if ($buildMakerNotes) CanonRaw::InitMakerNotes($et);

        $entries = ExifTool::Get16u($dataPt, $start + 2);
        $pos = $start + 4;
        $et->VerboseDir($dirInfo, $entries);
        // loop through entries in Adobe CRW information
        for ($index = 0; $index < $entries; ++$index) {
            if ($pos + 6 > $end) break;
            $tag = ExifTool::Get16u($dataPt, $pos);
            $size = ExifTool::Get32u($dataPt, $pos + 2);
            $pos += 6;
            if ($pos + $size > $end) break;
            $value = substr($dataPt, $pos, $size);
            $tagID = $tag & 0x3fff;
            $tagType = ($tag >> 8) & 0x38;  // get tag type
            $format = Vars\CanonRaw::$crwTagFormat[$tagType] ?? null;
            $count = null;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID, $value);
            if ($tagInfo) {
                if ($tagInfo->Format) $format = $tagInfo->Format;
                $count = $tagInfo->Count;
            }
            // set count to 1 by default for values that were in the directory entry
            if ($count === null and $tag & 0x4000 and $format and $format !== 'string') {
                $count = 1;
            }
            // set count from tagInfo count if necessary
            if ($format and !$count) {
                // set count according to format and size
                $fnum = Vars\Exif::$formatNumber[$format];
                $fsiz = Vars\Exif::$formatSize[$fnum];
                $count = Perl::int($size / $fsiz);
            }
            $format or $format = 'undef';
            ExifTool::SetByteOrder($byteOrder);
            $val = ExifTool::ReadValue($value, 0, $format, $count, $size);
            $et->HandleTag($tagTablePtr, $tagID, $val,
                Index: $index,
                DataPt: $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Start: $pos,
                Size: $size,
                TagInfo: $tagInfo,
            );
            if ($buildMakerNotes) {
                // build maker notes information if requested
                CanonRaw::BuildMakerNotes($et, $tagID, $tagInfo, $value, $format, $count);
            }
            // (we lost the directory structure, but the second tag 0x0805
            // should be in the ImageDescription directory)
            if ($tagID == 0x0805) $et->DIR_NAME = 'ImageDescription';
            ExifTool::SetByteOrder('MM');
            $pos += $size;
        }
        if ($index != $entries) {
            $et->Warn('Truncated CRW notes');
        } elseif ($pos < $end) {
            $et->Warn(($end - $pos) . ' extra bytes at end of CRW notes');
        }
        // finish building maker notes if necessary
        if ($buildMakerNotes) {
            ExifTool::SetByteOrder($byteOrder);
            CanonRaw::SaveMakerNotes($et);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Adobe MRW directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    // Notes: data has 4 byte header (2 for byte order and 2 for entry count)
    public static function ProcessAdobeMRW(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $dirStart = $dirInfo['DirStart'];

        // construct fake MRW file
        $buff = "\0MRM" . Perl::pack('N', $dirLen - 4);
        // ignore leading byte order and directory count words
        $buff .= substr($dataPt, $dirStart + 4, $dirLen - 4);
        $raf = new RandomAccess($buff);
        $mrwDirInfo = ['RAF' => $raf];  // (Perl: my %dirInfo, OutFile is for writing)
        return MinoltaRaw::ProcessMRW($et, $mrwDirInfo);
    }

    //------------------------------------------------------------------------------
    // Process Adobe RAF directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessAdobeRAF(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $dirEnd = $dirInfo['DirLen'] + $pos;
        $readIt = $warn = null;

        // set byte order according to first 2 bytes of Adobe RAF data
        if ($pos + 2 <= $dirEnd and ExifTool::SetByteOrder(substr($dataPt, $pos, 2))) {
            $pos += 2;
        } else {
            $et->Warn('Invalid DNG RAF data');
            return 0;
        }
        $et->VerboseDir($dirInfo);
        // make fake RAF object for processing (same acronym, different meaning)
        $raf = new RandomAccess($dataPt);
        $num = '';
        // loop through all records in Adobe RAF data:
        // 0 - RAF table (not processed)
        // 1 - first RAF directory
        // 2 - second RAF directory (if available)
        for (;;) {
            if ($pos + 4 > $dirEnd) break;
            $len = ExifTool::Get32u($dataPt, $pos);
            $pos += 4 + $len;   // step to next entry in Adobe RAF record
            if (!$len) break;   // ends with an empty entry
            if (!$readIt) {     // ignore first entry (RAF table)
                $readIt = 1;
                continue;
            }
            $rafDirInfo = [     // (Perl: my %dirInfo)
                'RAF'      => $raf,
                'DirStart' => $pos - $len,
            ];
            $et->SET_GROUP1 = "RAF$num";
            $et->ProcessDirectory($rafDirInfo, $tagTablePtr) or $warn = 1;
            $et->SET_GROUP1 = null;
            $num = ($num ?: 1) + 1;
        }
        if ($warn) $et->Warn('Possibly corrupt RAF information');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Adobe SR2 directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    // Notes: data has 6 byte header (2 for byte order and 4 for original offset)
    public static function ProcessAdobeSR2(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $len = $dirInfo['DirLen'];

        if ($len < 6) return 0;
        ExifTool::SetByteOrder('MM');
        $originalPos = ExifTool::Get32u($dataPt, $start + 2);
        if (!ExifTool::SetByteOrder(substr($dataPt, $start, 2))) return 0;

        $et->VerboseDir($dirInfo);
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirStart = $start + 6;     // pointer to maker note directory
        $dirLen = $len - 6;

        // initialize subdirectory information
        $fix = $dataPos + $dirStart - $originalPos;
        $subdirInfo = [
            'DirName'   => 'AdobeSR2',
            'Base'      => ($dirInfo['Base'] ?? null) + $fix,
            'DataPt'    => $dataPt,
            'DataPos'   => $dataPos - $fix,
            'DataLen'   => $dirInfo['DataLen'] ?? null,
            'DirStart'  => $dirStart,
            'DirLen'    => $dirLen,
            'Parent'    => $dirInfo['DirName'] ?? null,
        ];
        if ($et->Options('HtmlDump')) {
            $et->HDump($dataPos + $start, 6, 'Adobe SR2 data');
        }
        // parse the SR2 directory
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Adobe-mutilated IFD directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    // Notes: data has 2 byte header (byte order of the data)
    public static function ProcessAdobeIFD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $dataPos = $dirInfo['DataPos'] ?? null;

        if ($dirInfo['DirLen'] < 4) return 0;
        $dataOrder = substr($dataPt, $pos, 2);
        if (!ExifTool::SetByteOrder($dataOrder)) return 0;  // validate byte order of data

        // parse the mutilated IFD.  This is similar to a TIFF IFD, except:
        // - data follows directly after Count entry in IFD
        // - byte order of IFD entries is always big-endian, but byte order of data changes
        ExifTool::SetByteOrder('MM');   // IFD structure is always big-endian
        $entries = ExifTool::Get16u($dataPt, $pos + 2);
        $et->VerboseDir($dirInfo, $entries);
        $pos += 4;

        $end = $pos + $dirInfo['DirLen'];
        for ($index = 0; $index < $entries; ++$index) {
            if ($pos + 8 > $end) break;
            ExifTool::SetByteOrder('MM'); // directory entries always big-endian (doh!)
            $tagID = ExifTool::Get16u($dataPt, $pos);
            $format = ExifTool::Get16u($dataPt, $pos + 2);
            $count = ExifTool::Get32u($dataPt, $pos + 4);
            if ($format < 1 or $format > 13) {
                // warn unless the IFD was just padded with zeros
                if ($format) {
                    $et->Warn(Perl::sprintf("Unknown format ($format) for " .
                        Perl::str($dirInfo['DirName'] ?? null) . ' tag 0x%x', $tagID));
                }
                return 0; // must be corrupted
            }
            $size = Vars\Exif::$formatSize[$format] * $count;
            if ($pos + 8 + $size > $end) break;
            $formatStr = Vars\Exif::$formatName[$format];
            ExifTool::SetByteOrder($dataOrder);   // data stored in native order
            $val = ExifTool::ReadValue($dataPt, $pos + 8, $formatStr, $count, $size);
            $et->HandleTag($tagTablePtr, $tagID, $val,
                Index: $index,
                DataPt: $dataPt,
                DataPos: $dataPos,
                Start: $pos + 8,
                Size: $size,
            );
            $pos += 8 + $size;
        }
        if ($index < $entries) {
            $et->Warn('Truncated ' . Perl::str($dirInfo['DirName'] ?? null) . ' directory');
            return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Adobe MakerNotes directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    // Notes: data has 6 byte header (2 for byte order and 4 for original offset)
    //        --> or 18 bytes for DNG converted from JPG by Adobe Camera Raw!
    public static function ProcessAdobeMakN(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $len = $dirInfo['DirLen'];

        if ($len < 6) return 0;
        ExifTool::SetByteOrder('MM');
        $originalPos = ExifTool::Get32u($dataPt, $start + 2);
        if (!ExifTool::SetByteOrder(substr($dataPt, $start, 2))) return 0;

        $et->VerboseDir($dirInfo);
        $dataPos = $dirInfo['DataPos'] ?? null;
        $hdrLen = 6;

        // 2018-09-27: hack for extra 12 bytes in MakN header of JPEG converted to DNG
        // by Adobe Camera Raw (4 bytes "00 00 00 01" followed by 8 unknown bytes)
        // - this is because CameraRaw copies the maker notes from the wrong location
        //   in a JPG image (off by 12 bytes presumably due to the JPEG headers)
        // - this hack won't work in most cases because the extra bytes are not consistent
        //   since they are just the data that existed in the JPG before the maker notes
        // - also, the last 12 bytes of the maker notes will be missing
        // - 2022-04-26: this bug still exists in Camera Raw 14.3
        if ($len >= 18 and substr($dataPt, $start + 6, 4) === "\0\0\0\x01") $hdrLen += 12;

        $dirStart = $start + $hdrLen;   // pointer to maker note directory
        $dirLen = $len - $hdrLen;

        $hdr = substr($dataPt, $dirStart, $dirLen < 48 ? $dirLen : 48);
        $tagInfo = $et->GetTagInfo($tagTablePtr, 'MakN', $hdr);
        if (!($tagInfo and $tagInfo->SubDirectory)) return 0;
        $subdir = $tagInfo->SubDirectory;
        $subTable = ExifTool::GetTagTable($subdir['TagTable']);
        // initialize subdirectory information
        $subdirInfo = [
            'DirName'   => 'MakerNotes',
            'Name'      => $tagInfo->Name,  // needed for maker notes verbose dump
            'Base'      => $dirInfo['Base'] ?? null,
            'DataPt'    => $dataPt,
            'DataPos'   => $dataPos,
            'DataLen'   => $dirInfo['DataLen'] ?? null,
            'DirStart'  => $dirStart,
            'DirLen'    => $dirLen,
            'TagInfo'   => $tagInfo,
            'FixBase'   => $subdir['FixBase'] ?? null,
            'EntryBased'=> $subdir['EntryBased'] ?? null,
            'Parent'    => $dirInfo['DirName'] ?? null,
        ];
        // look for start of maker notes IFD
        $loc = MakerNotes::LocateIFD($et, $subdirInfo);
        if ($loc === null) {
            $et->Warn('Maker notes could not be parsed');
            return 0;
        }
        if ($et->Options('HtmlDump')) {
            $et->HDump($dataPos + $start, $hdrLen, 'Adobe MakN data');
            if ($loc) $et->HDump($dataPos + $dirStart, $loc, "{$tagInfo->Name} header");
        }

        $fix = 0;
        if (empty($subdir['Base'])) {
            // adjust base offset for current maker note position
            $fix = $dataPos + $dirStart - $originalPos;
            $subdirInfo['Base'] += $fix;
            $subdirInfo['DataPos'] -= $fix;
        }
        // parse the maker notes directory
        $et->ProcessDirectory($subdirInfo, $subTable, $subdir['ProcessProc'] ?? null);
        // extract maker notes as a block if specified
        if ($et->Options('MakerNotes') or
            ($et->REQ_TAG_LOOKUP[strtolower($tagInfo->Name)] ?? null))
        {
            $val = null;
            if ($tagInfo->MakerNotes) {
                $subdirInfo['Base']     = ($dirInfo['Base'] ?? null) + $fix;
                $subdirInfo['DataPos']  = $dataPos - $fix;
                $subdirInfo['DirStart'] = $dirStart;
                $subdirInfo['DirLen']   = $dirLen;
                // rebuild the maker notes to identify all offsets that require fixing up
                $val = Exif::RebuildMakerNotes($et, $subdirInfo, $subTable);
                if ($val === null and $dirLen > 4) {
                    $et->Warn('Error rebuilding maker notes (may be corrupt)');
                }
            } else {
                // extract this directory as a block if specified
                if (!$tagInfo->Writable) return 1;
            }
            if ($val === null) $val = substr($dataPt, 20);
            $key = $et->FoundTag($tagInfo, $val);
            if ($et->MAKER_NOTE_FIXUP) {
                $et->TAG_EXTRA[$key]['Fixup'] = $et->MAKER_NOTE_FIXUP;
                unset($et->MAKER_NOTE_FIXUP);
            }
        }
        return 1;
    }
}
