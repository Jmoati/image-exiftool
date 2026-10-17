<?php

//------------------------------------------------------------------------------
// File:         FlashPix.pm
//
// Description:  Read FlashPix meta information
//
// Revisions:    05/29/2006 - P. Harvey Created
//
// References:   1) http://www.exif.org/Exif2-2.PDF
//               2) http://www.graphcomp.com/info/specs/livepicture/fpx.pdf
//               3) http://search.cpan.org/~jdb/libwin32/
//               4) http://msdn.microsoft.com/en-us/library/aa380374.aspx
//               5) http://www.cpan.org/modules/by-authors/id/H/HC/HCARVEY/File-MSWord-0.1.zip
//               6) https://msdn.microsoft.com/en-us/library/cc313153(v=office.12).aspx
//               7) https://learn.microsoft.com/en-us/openspecs/office_file_formats/ms-oshared/3ef02e83-afef-4b6c-9585-c109edd24e07
//
// PHP port of Image::ExifTool::FlashPix 1.52. Tag tables are generated into tables/FlashPix.php,
// the file lexicals (%oleFormat, %oleFormatSize...) into Vars\FlashPix.
// The constant subs (HDR_SIZE, VT_VARIANT...) are class constants.
// ReadFPXValue is wantarray-dependent: ReadFPXValueList is the list-context version.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\FlashPix as Vars;

final class FlashPix
{
    public static $VERSION = '1.52';

    // sector type constants
    public const HDR_SIZE           = 512;
    public const DIF_SECT           = 0xfffffffc;
    public const FAT_SECT           = 0xfffffffd;
    public const END_OF_CHAIN       = 0xfffffffe;
    public const FREE_SECT          = 0xffffffff;

    // format flags
    public const VT_VECTOR          = 0x1000;
    public const VT_ARRAY           = 0x2000;
    public const VT_BYREF           = 0x4000;
    public const VT_RESERVED        = 0x8000;

    // other constants
    public const VT_VARIANT         = 12;
    public const VT_LPSTR           = 30;

    //------------------------------------------------------------------------------
    // Convert Microsoft DTTM structure to date/time
    // Inputs: 0) DTTM value
    // Returns: EXIF-format date/time string ("0000:00:00 00:00:00" for zero date/time)
    public static function ConvertDTTM($val)
    {
        $val = Perl::int($val);
        $yr  = ($val >> 20) & 0x1ff;
        $mon = ($val >> 16) & 0x0f;
        $day = ($val >> 11) & 0x1f;
        $hr  = ($val >> 6)  & 0x1f;
        $min = ($val & 0x3f);
        if ($val) $yr += 1900;
        // ExifTool 12.48 dropped the "Z" on the time here because a test .doc
        // file written by Word 2011 on Mac certainly used local time here
        return Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:00', $yr, $mon, $day, $hr, $min);
    }

    //------------------------------------------------------------------------------
    // Process hyperlinks from PID_HYPERLINKS array
    // (ref http://msdn.microsoft.com/archive/default.asp?url=/archive/en-us/dnaro97ta/html/msdn_hyper97.asp)
    // Inputs: 0) value, 1) ExifTool ref
    // Returns: list of hyperlinks
    public static function ProcessHyperlinks($val, $et)
    {
        // process as an array of VT_VARIANT's
        $dirEnd = strlen($val);
        if ($dirEnd < 4) return null;
        $num = ExifTool::Get32u($val, 0);
        $valPos = 4;
        $vals = [];
        for ($i = 0; $i < $num; ++$i) {
            // read VT_BLOB entries as an array of VT_VARIANT's
            $value = self::ReadFPXValue($et, $val, $valPos, self::VT_VARIANT, $dirEnd);
            if ($value === null) break;
            $vals[] = $value;
        }
        // filter values to extract only the links
        $links = [];
        for ($i = 0; $i < count($vals); $i += 6) {
            $links[] = $vals[$i + 4] ?? null;   // get address
            if (strlen(Perl::str($vals[$i + 5] ?? null))) $links[count($links) - 1] .= '#' . Perl::str($vals[$i + 5]); // add subaddress
        }
        return $links;
    }

    //------------------------------------------------------------------------------
    // Read FlashPix value
    // Inputs: 0) ExifTool ref, 1) data ref, 2) value offset, 3) FPX format number,
    //         4) end offset, 5) flag for no padding, 6) code page
    // Returns: converted value (or list of values in list context) and updates
    //          value offset to end of value if successful, or returns undef on error
    public static function ReadFPXValue(ExifTool $et, $dataPt, &$valPos, $type, $dirEnd, $noPad = null, $codePage = null)
    {
        return self::DoReadFPXValue($et, $dataPt, $valPos, $type, $dirEnd, $noPad, $codePage, false);
    }

    /** ReadFPXValue in list context */
    public static function ReadFPXValueList(ExifTool $et, $dataPt, &$valPos, $type, $dirEnd, $noPad = null, $codePage = null): array
    {
        return self::DoReadFPXValue($et, $dataPt, $valPos, $type, $dirEnd, $noPad, $codePage, true);
    }

    private static function DoReadFPXValue(ExifTool $et, $dataPt, &$valPos, $type, $dirEnd, $noPad, $codePage, bool $wantarray)
    {
        $vals = [];

        $format = Vars::$oleFormat[$type & 0x0fff] ?? null;
        while ($format) {
            $count = 1;
            // handle VT_VECTOR types
            $flags = $type & 0xf000;
            if ($flags) {
                if ($flags == self::VT_VECTOR) {
                    $noPad = 1;     // values sometimes aren't padded inside vectors!!
                    $size = Vars::$oleFormatSize['VT_VECTOR'];
                    if ($valPos + $size > $dirEnd) {
                        $et->Warn('Incorrect FPX VT_VECTOR size');
                        break;
                    }
                    $count = ExifTool::Get32u($dataPt, $valPos);
                    if ($count == 0) $vals[] = '';  // allow zero-element vector
                    $valPos += 4;
                } else {
                    // can't yet handle this property flag
                    $et->Warn('Unknown FPX property');
                    break;
                }
            }
            if (!str_starts_with($format, 'VT_')) {
                $size = ExifTool::FormatSize($format) * $count;
                if ($valPos + $size > $dirEnd) {
                    $et->Warn("Incorrect FPX $format size");
                    break;
                }
                $vals = ExifTool::ReadValueList($dataPt, $valPos, $format, $count, $size);
                // update position to end of value plus padding
                $valPos += ($count * $size + 3) & 0xfffffffc;
                break;
            }
            $size = Vars::$oleFormatSize[$format];
            $val = $len = null;
            for ($item = 0; $item < $count; ++$item) {
                if ($valPos + $size > $dirEnd) {
                    $et->Warn("Truncated FPX $format value");
                    break;
                }
                // sometimes VT_VECTOR items are padded to even 4-byte boundaries, and sometimes they aren't
                if ($noPad and $len !== null and $len & 0x03) {
                    $pad = 4 - ($len & 0x03);
                    if ($valPos + $pad + $size <= $dirEnd) {
                        // skip padding if all zeros
                        if (substr($dataPt, $valPos, $pad) === str_repeat("\0", $pad)) $valPos += $pad;
                    }
                }
                $len = null;
                if ($format === 'VT_VARIANT') {
                    $subType = ExifTool::Get32u($dataPt, $valPos);
                    $valPos += $size;
                    $val = self::ReadFPXValue($et, $dataPt, $valPos, $subType, $dirEnd, $noPad, $codePage);
                    if ($val === null) break;
                    $vals[] = $val;
                    continue;   // avoid adding $size to $valPos again
                } elseif ($format === 'VT_FILETIME') {
                    // convert from time in 100 ns increments to time in seconds
                    $val = 1e-7 * Perl::num(ExifTool::Get64u($dataPt, $valPos));
                    // print as date/time if value is greater than one year (PH hack)
                    $secDay = 24 * 3600;
                    if ($val > 365 * $secDay) {
                        // shift from Jan 1, 1601 to Jan 1, 1970
                        $unixTimeZero = 134774 * $secDay;
                        $val -= $unixTimeZero;
                        // there are a lot of bad programmers out there...
                        $sec100yr = 100 * 365 * $secDay;
                        if ($val < 0 || $val > $sec100yr) {
                            // some software writes the wrong byte order (but the proper word order)
                            $w = Perl::unpack("x{$valPos}NN", $dataPt);
                            $v2 = ($w[0] + $w[1] * 4294967296) * 1e-7 - $unixTimeZero;
                            if ($v2 > 0 && $v2 < $sec100yr) {
                                $val = $v2;
                            // also check for wrong time base
                            } elseif ($val < 0 && $val + $unixTimeZero > 0) {
                                $val += $unixTimeZero;
                            }
                        }
                        $val = ExifTool::ConvertUnixTime($val);
                    }
                } elseif ($format === 'VT_DATE') {
                    $val = ExifTool::GetDouble($dataPt, $valPos);
                    // shift zero from Dec 30, 1899 to Jan 1, 1970 and convert to secs
                    if ($val != 0) $val = ($val - 25569) * 24 * 3600;
                    $val = ExifTool::ConvertUnixTime($val);
                } elseif (str_ends_with($format, 'STR')) {
                    $len = ExifTool::Get32u($dataPt, $valPos);
                    if ($format === 'VT_LPWSTR') $len *= 2;    // convert to byte count
                    if ($valPos + $len + 4 > $dirEnd) {
                        $et->Warn("Truncated $format value");
                        break;
                    }
                    $val = substr($dataPt, $valPos + 4, $len);
                    if ($format === 'VT_LPWSTR') {
                        // convert wide string from Unicode
                        $val = $et->Decode($val, 'UTF16');
                    } elseif ($codePage) {
                        $charset = \Image\ExifTool\Vars\ExifTool::$charsetName['cp' . Perl::str($codePage)] ?? null;
                        if ($charset) {
                            $val = $et->Decode($val, $charset);
                        } elseif ($codePage == 1200) {  // UTF-16, little endian
                            $val = $et->Decode($val, 'UTF16', 'II');
                        }
                    }
                    $val = preg_replace('/\0.*/s', '', $val);   // truncate at null terminator
                    // update position for string length
                    // (the spec states that strings should be padded to align
                    //  on even 32-bit boundaries, but this isn't always the case)
                    $valPos += $noPad ? $len : ($len + 3) & 0xfffffffc;
                } elseif ($format === 'VT_BLOB' or $format === 'VT_CF') {
                    $bLen = ExifTool::Get32u($dataPt, $valPos);   // (use local $len because we always expect padding)
                    if ($valPos + $bLen + 4 > $dirEnd) {
                        $et->Warn("Truncated $format value");
                        break;
                    }
                    $val = substr($dataPt, $valPos + 4, $bLen);
                    // update position for data length plus padding
                    // (does this padding disappear in arrays too?)
                    $valPos += ($bLen + 3) & 0xfffffffc;
                } elseif ($format === 'VT_CLSID') {
                    $val = ASF::GetGUID(substr($dataPt, $valPos, $size));
                }
                $valPos += $size;   // update value pointer to end of value
                $vals[] = $val;
            }
            // join VT_ values with commas unless we want an array
            if (count($vals) > 1 and !$wantarray) {
                $vals = [implode(Perl::str($et->Options('ListSep')), array_map(Perl::str(...), $vals))];
            }
            break;  // didn't really want to loop
        }

        if (Perl::str($type) === '0') $vals[] = ''; // (VT_EMPTY)
        if ($wantarray) {
            return $vals;
        } elseif (count($vals) > 1) {
            return implode(' ', array_map(Perl::str(...), $vals));
        } else {
            return $vals[0] ?? null;
        }
    }

    //------------------------------------------------------------------------------
    // Scan for XMP in FLA Contents (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: FLA format is proprietary and I couldn't find any documentation,
    //        so this routine is entirely based on observations from sample files
    public static function ProcessContents(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $isFLA = null;

        // all of my FLA samples contain "Contents" data, and no other FPX-like samples have
        // this (except Scene7 VNT viles), but check the data for a familiar pattern to be
        // sure this is FLA: the Contents of all of my FLA samples start with two bytes
        // (0x29,0x38,0x3f,0x43 or 0x47, then 0x01) followed by a number of zero bytes
        // (from 0x18 to 0x26 of them, related somehow to the value of the first byte),
        // followed by the string "DocumentPage"
        if (preg_match('/^..\0+\xff\xff\x01\0\x0d\0CDocumentPage/s', $dataPt)) {
            $isFLA = 1;
        } elseif (preg_match('/^\0{4}.(.{1,255})\x60\xa1\x3f\x22\0{5}(.{8})/s', $dataPt, $m, PREG_OFFSET_CAPTURE)) {
            $p = $m[0][1] + strlen($m[0][0]);   // (pos($$dataPt))
            // this looks like a VNT file
            $et->OverrideFileType('VNT', 'image/x-vignette');
            // hack to set proper file description (extension is the same for V-Note files)
            ExifTool::$static_vars['OverrideFileDescription']['VNT'] = 'Scene7 Vignette';
            $name = $m[1][0];
            [$w, $h] = Perl::unpack('V2', $m[2][0]);
            $et->FoundTag('ImageWidth', $w);
            $et->FoundTag('ImageHeight', $h);
            $et->HandleTag($tagTablePtr, 'OriginalFileName', $name);
            if (preg_match('/\G\x01\0{4}(.{12})/s', $dataPt, $m, 0, $p)) {
                $p += strlen($m[0]);
                // (first 4 bytes seem to be number of objects, next 4 bytes are zero, then ICC size)
                $size = Perl::unpack('x8V', $m[1])[0];
                // (not useful?) $et->FoundTag(NumObjects => $num);
                if ($size and $p + $size < strlen($dataPt)) {
                    $dat = substr($dataPt, $p, $size);
                    $et->FoundTag('ICC_Profile', $dat);
                    $p += $size;
                }
                $et->IeImg_lkup = [];
                $et->IeImg_class = [];
                // - the byte before \x80 is 0x0d, 0x11 or 0x1f for separate images in my samples,
                //   and 0x1c or 0x23 for inline masks
                // - the byte after \xff\xff is 0x3b in my samples for $1 containing 'VnMask' or 'VnCache'
                while (preg_match('/\x0bTargetRole1(?:.\x80|\xff\xff.\0.\0Vn(\w+))\0\0\x01.{4}(.{24})/s', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
                    $p = $m[0][1] + strlen($m[0][0]);
                    $coords = Perl::unpack('Vx4V4', $m[2][0]);
                    $index = array_shift($coords);
                    if ($index == 0xffffffff) continue;
                    if (!empty($et->IeImg_lkup[$index])) $et->Warn('Duplicate image index');
                    $et->IeImg_lkup[$index] = implode(' ', $coords);
                    if (!empty($m[1][0])) $et->IeImg_class[$index] = $m[1][0];
                }
            }
        }

        // do a brute-force scan of the "Contents" for UTF-16 XMP
        // (this may always be little-endian, but allow for either endianness)
        if (preg_match('/<\0\?\0x\0p\0a\0c\0k\0e\0t\0 \0b\0e\0g\0i\0n\0=\0[\'"](\0\xff\xfe|\xfe\xff)/', $dataPt, $m, PREG_OFFSET_CAPTURE)) {
            $p = $m[0][1] + strlen($m[0][0]);
            $dirInfo['DirStart'] = $p - 36;
            if (preg_match('/<\0\?\0x\0p\0a\0c\0k\0e\0t\0 \0e\0n\0d\0=\0[\'"]\0[wr]\0[\'"]\0\?\0>\0?/', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
                $dirInfo['DirLen'] = $m[0][1] + strlen($m[0][0]) - $dirInfo['DirStart'];
                XMP::ProcessXMP($et, $dirInfo, $tagTablePtr);
                // override format if not already FLA but XMP-dc:Format indicates it is
                if ($et->FILE_TYPE !== 'FLA' and !empty($et->VALUE['Format']) and
                    $et->VALUE['Format'] === 'application/vnd.adobe.fla') $isFLA = 1;
            }
        }
        if ($isFLA) $et->OverrideFileType('FLA');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process WordDocument stream of MSWord doc file (ref 6)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessWordDocument(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt === null) return 0;
        $dirLen = strlen($dataPt);
        // validate the FIB signature
        if (!($dirLen > 2 and ExifTool::Get16u($dataPt, 0) == 0xa5ec)) {
            $et->Warn('Invalid FIB signature', 1);
            return 0;
        }
        $et->ProcessBinaryData($dirInfo, $tagTablePtr);    // process FIB
        // continue parsing the WordDocument stream until we find the FibRgFcLcb
        $pos = 32;
        if ($pos + 2 > $dirLen) return 0;
        $n = ExifTool::Get16u($dataPt, $pos);  // read csw
        $pos += 2 + $n * 2;             // skip fibRgW
        if ($pos + 2 > $dirLen) return 0;
        $n = ExifTool::Get16u($dataPt, $pos);  // read cslw
        $pos += 2 + $n * 4;             // skip fibRgLw
        if ($pos + 2 > $dirLen) return 0;
        $n = ExifTool::Get16u($dataPt, $pos);  // read cbRgFcLcb
        $pos += 2;  // point to start of fibRgFcLcbBlob
        if ($pos + $n * 8 > $dirLen) return 0;
        $tableOffsets = [];
        // save necessary entries for later processing of document table
        // (DOP, CommentBy, LastSavedBy)
        foreach ([0xf8, 0x120, 0x238] as $off) {
            if ($off + 8 > $n * 8) break;
            $tableOffsets[] = ExifTool::Get32u($dataPt, $pos + $off);
            $tableOffsets[] = ExifTool::Get32u($dataPt, $pos + $off + 4);
        }
        $tbl = ExifTool::GetTagTable('Image::ExifTool::FlashPix::DocTable');
        // extract ModifyDate if it exists
        $et->HandleTag($tbl, 'ModifyDate', null,
            DataPt: $dataPt,
            Start: $pos + 0x2b8,
            Size: 8,
        );
        $et->HandleTag($tbl, 'TableOffsets', $tableOffsets);   // save for later
        // $pos += $n * 8;                 # skip fibRgFcLcbBlob
        // return 0 if $pos + 2 > $dirLen;
        // $n = Get16u($dataPt, $pos);     # read cswNew
        // return 0 if $pos + 2 + $n * 2 > $dirLen;
        // my $nFib = Get16u($dataPt, 2 + ($n ? $pos : 0));
        // $pos += 2 + $n * 2;             # skip fibRgCswNew
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Microsoft Word Document Table
    // Inputs: 0) ExifTool object ref
    public static function ProcessDocumentTable(ExifTool $et)
    {
        $value = &$et->VALUE;
        $extra = &$et->TAG_EXTRA;
        // loop through TableOffsets for each sub-document
        for ($i = 0; ; ++$i) {
            $key = 'TableOffsets' . ($i ? " ($i)" : '');
            $offsets = $value[$key] ?? null;
            if ($offsets === null) break;
            $doc = ($extra[$key]['G3'] ?? null) ?: '';
            // get DocFlags for this sub-document
            $docFlags = $docTable = null;
            for ($j = 0; ; ++$j) {
                $key = 'DocFlags' . ($j ? " ($j)" : '');
                if (!isset($value[$key])) break;
                $tmp = ($extra[$key]['G3'] ?? null) ?: '';
                if (Perl::str($tmp) === Perl::str($doc)) {
                    $docFlags = $value[$key];
                    break;
                }
            }
            if ($docFlags === null) continue;
            $tag = Perl::num($docFlags) & 0x200 ? 'Table1' : 'Table0';
            // get table for this sub-document
            for ($j = 0; ; ++$j) {
                $key = $tag . ($j ? " ($j)" : '');
                if (!isset($value[$key])) break;
                $tmp = ($extra[$key]['G3'] ?? null) ?: '';
                if (Perl::str($tmp) === Perl::str($doc)) {
                    $docTable = $value[$key];
                    if ($docTable instanceof ScalarRef) $docTable = $docTable->value;
                    break;
                }
            }
            if ($docTable === null) continue;
            // extract DOP and LastSavedBy information from document table
            $et->DOC_NUM = $doc;    // use same document number
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FlashPix::DocTable');
            $offsets = (array) $offsets;
            foreach (['DOP', 'CommentByBlock', 'LastSavedByBlock'] as $tag) {
                if (!$offsets) break;
                $off = array_shift($offsets);
                $len = array_shift($offsets);
                if (!($len and $off + $len <= strlen($docTable))) continue;
                $et->HandleTag($tagTablePtr, $tag, null,
                    DataPt: $docTable,
                    Start: $off,
                    Size: $len,
                );
            }
            $et->DOC_NUM = null;
        }
        // delete intermediate tags
        foreach (['TableOffsets', 'Table0', 'Table1'] as $tag) {
            for ($i = 0; ; ++$i) {
                $key = $tag . ($i ? " ($i)" : '');
                if (!isset($value[$key])) break;
                $et->DeleteTag($key);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Extract names of comment authors (ref 6)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessCommentBy(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $end = $dirInfo['DirLen'] + $pos;
        $et->VerboseDir($dirInfo['DirName']);
        while ($pos + 2 < $end) {
            $len = ExifTool::Get16u($dataPt, $pos);
            $pos += 2;
            if ($pos + $len * 2 > $end) break;
            $author = $et->Decode(substr($dataPt, $pos, $len * 2), 'UTF16');
            $pos += $len * 2;
            $et->HandleTag($tagTablePtr, 'CommentBy', $author);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract last-saved-by names (ref 5)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessLastSavedBy(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $end = $dirInfo['DirLen'] + $pos;
        if ($pos + 6 > $end) return 0;
        $et->VerboseDir($dirInfo['DirName']);
        $num = ExifTool::Get16u($dataPt, $pos + 2);
        $pos += 6;
        while ($num >= 2) {
            if ($pos + 2 > $end) break;
            $len = ExifTool::Get16u($dataPt, $pos);
            $pos += 2;
            if ($pos + $len * 2 > $end) break;
            $author = $et->Decode(substr($dataPt, $pos, $len * 2), 'UTF16');
            $pos += $len * 2;
            if ($pos + 2 > $end) break;
            $len = ExifTool::Get16u($dataPt, $pos);
            $pos += 2;
            if ($pos + $len * 2 > $end) break;
            $path = $et->Decode(substr($dataPt, $pos, $len * 2), 'UTF16');
            $pos += $len * 2;
            $et->HandleTag($tagTablePtr, 'LastSavedBy', Perl::str($author) . ' (' . Perl::str($path) . ')');
            $num -= 2;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Check FPX byte order mark (BOM) and set byte order appropriately
    // Inputs: 0) data ref, 1) offset to BOM
    // Returns: true on success
    public static function CheckBOM($dataPt, $pos)
    {
        $bom = ExifTool::Get16u($dataPt, $pos);
        if ($bom == 0xfffe) return 1;
        if ($bom != 0xfeff) return 0;
        ExifTool::ToggleByteOrder();
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process FlashPix properties
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessProperties(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: strlen($dataPt) - $pos;
        $dirEnd = $pos + $dirLen;
        $verbose = $et->Options('Verbose');

        if ($dirLen < 48) {
            $et->Warn('Truncated FPX properties');
            return 0;
        }
        // check and set our byte order if necessary
        if (!self::CheckBOM($dataPt, $pos)) {
            $et->Warn('Bad FPX property byte order mark');
            return 0;
        }
        // get position of start of section
        $pos = ExifTool::Get32u($dataPt, $pos + 44);
        if ($pos < 48) {
            $et->Warn('Bad FPX property section offset');
            return 0;
        }
        for ($n = 0; $n < 2; ++$n) {
            $dictionary = [];   // dictionary to translate user-defined properties
            $codePage = null;
            if ($pos + 8 > $dirEnd) break;
            // read property section header
            $size = ExifTool::Get32u($dataPt, $pos);
            if (!$size) break;
            $numEntries = ExifTool::Get32u($dataPt, $pos + 4);
            if ($verbose) $et->VerboseDir('Property Info', $numEntries, $size);
            if ($pos + 8 + 8 * $numEntries > $dirEnd) {
                $et->Warn('Truncated property list');
                break;
            }
            for ($index = 0; $index < $numEntries; ++$index) {
                $entry = $pos + 8 + 8 * $index;
                $tag = ExifTool::Get32u($dataPt, $entry);
                $offset = ExifTool::Get32u($dataPt, $entry + 4);
                $valStart = $pos + 4 + $offset;
                if ($valStart >= $dirEnd) break;
                $valPos = $valStart;
                $type = ExifTool::Get32u($dataPt, $pos + $offset);
                if ($tag == 0) {
                    // read dictionary to get tag name lookup for this property set
                    for ($i = 0; $i < $type; ++$i) {
                        if ($valPos + 8 > $dirEnd) break;
                        $tag = ExifTool::Get32u($dataPt, $valPos);
                        $len = ExifTool::Get32u($dataPt, $valPos + 4);
                        $valPos += 8 + $len;
                        if ($valPos > $dirEnd) break;
                        $name = substr($dataPt, $valPos - $len, $len);
                        $name = preg_replace('/\0.*/s', '', $name);
                        if (!strlen($name)) continue;
                        $dictionary[$tag] = $name;
                        if ($tagTablePtr[$name] !== null) continue;
                        $tag = $name;
                        $name = preg_replace_callback('/(^| )([a-z])/', static fn ($m) => strtoupper($m[2]), $name); // start with uppercase
                        $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);    // remove illegal characters
                        if (!strlen($name)) continue;
                        if ($verbose) $et->VPrint(0, "{$et->INDENT}[adding $name]\n");
                        ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
                    }
                    continue;
                }
                // use tag name from dictionary if available
                $custom = $val = null;
                if (isset($dictionary[$tag])) {
                    $tag = $dictionary[$tag];
                    $custom = 1;
                }
                $vals = self::ReadFPXValueList($et, $dataPt, $valPos, $type, $dirEnd, null, $codePage);
                if (!$vals) $et->Warn('Error reading property value');
                $val = count($vals) > 1 ? $vals : ($vals[0] ?? null);
                $format = $type & 0x0fff;
                $flags = $type & 0xf000;
                $formStr = (Vars::$oleFormat[$format] ?? null) ?: "Type $format";
                if ($flags) $formStr .= '|' . ((Vars::$oleFlags[$flags] ?? null) ?: Perl::sprintf('0x%x', $flags));
                $tagInfo = null;
                // check for common tag ID's: Dictionary, CodePage and LocaleIndicator
                // (must be done before masking because masked tags may overlap these ID's)
                if (!$custom and ($tag == 1 or $tag == 0x80000000)) {
                    // get tagInfo from SummaryInfo table
                    $summaryTable = ExifTool::GetTagTable('Image::ExifTool::FlashPix::SummaryInfo');
                    $tagInfo = $et->GetTagInfo($summaryTable, $tag);
                    if ($tag == 1) {
                        if (Perl::num($val) < 0) $val += 0x10000;   // (may be incorrectly stored as int16s)
                        $codePage = $val;           // save code page for translating values
                    }
                } elseif ($tagTablePtr[$tag] !== null) {
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                } elseif ($tagTablePtr['VARS'] and !$custom) {
                    // mask off insignificant bits of tag ID if necessary
                    $masked = $tagTablePtr['VARS'];
                    foreach (array_keys($masked) as $mask) {
                        if (is_array($masked[$mask]) and !empty($masked[$mask][$tag & $mask])) {
                            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag & $mask);
                            break;
                        }
                    }
                }
                $et->HandleTag($tagTablePtr, $tag, $val,
                    DataPt: $dataPt,
                    Start: $valStart,
                    Size: $valPos - $valStart,
                    Format: $formStr,
                    Index: $index,
                    TagInfo: $tagInfo,
                    Extra: ", type=$type",
                );
            }
            // issue warning if we hit end of property section prematurely
            if ($index < $numEntries) $et->Warn('Truncated property data');
            if (empty($dirInfo['Multi'])) break;
            $pos += $size;
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Load chain of sectors from file
    // Inputs: 0) RAF ref, 1) first sector number, 2) FAT ref, 3) sector size, 4) header size
    public static function LoadChain($raf, $sect, $fatPt, $sectSize, $hdrSize)
    {
        if (!$raf) return null;
        $chain = '';
        $buff = null;
        $loadedSect = [];
        for (;;) {
            if ($sect >= self::END_OF_CHAIN) break;
            if (!empty($loadedSect[$sect])) return null;    // avoid infinite loop
            $loadedSect[$sect] = 1;
            $offset = $sect * $sectSize + $hdrSize;
            if (!(($offset <= 0x7fffffff or $raf->LargeFileSupport) and
                  $raf->Seek($offset, 0) and
                  $raf->Read($buff, $sectSize) == $sectSize)) return null;
            $chain .= $buff;
            // step to next sector in chain
            if ($sect * 4 > strlen($fatPt) - 4) return null;
            $sect = ExifTool::Get32u($fatPt, $sect * 4);
        }
        return $chain;
    }

    //------------------------------------------------------------------------------
    // Extract information from a JPEG APP2 FPXR segment
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessFPXR(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');

        if ($dirLen < 13) {
            $et->Warn('FPXR segment too small');
            return 0;
        }

        // get version and segment type (version is 0 in all my samples)
        [$vers, $type] = Perl::unpack('x5C2', $dataPt);

        if ($type == 1) {   // a "Contents List" segment

            if ($vers != 0) $et->Warn("Untested FPXR version $vers");
            if ($et->FPXR !== null) {
                $et->Warn('Multiple FPXR contents lists');
                unset($et->FPXR);
            }
            $numEntries = Perl::unpack('x7n', $dataPt)[0];
            $contents = [];
            if ($verbose) $et->VerboseDir('Contents List', $numEntries);
            $pos = 9;
            for ($entry = 0; $entry < $numEntries; ++$entry) {
                if ($pos + 4 > $dirLen) {
                    $et->Warn('Truncated FPXR contents');
                    return 0;
                }
                [$size, $default] = Perl::unpack("x{$pos}Na", $dataPt);
                $p = $pos + 5;  // (pos($$dataPt))
                // according to the spec, this string is little-endian
                // (very odd, since the size word is big-endian),
                // and the first char must be '/'
                if (!preg_match('{\G(/\0(..)*?)\0\0}s', $dataPt, $m, 0, $p)) {
                    $et->Warn('Invalid FPXR stream name');
                    return 0;
                }
                $p += strlen($m[0]);
                // convert stream pathname to ascii
                $name = ExifTool::DecodeStatic(null, $m[1], 'UTF16', 'II', 'Latin');
                if ($verbose) {
                    $psize = ($size == 0xffffffff) ? 'storage' : "$size bytes";
                    $et->VPrint(0, "  |  $entry) Name: '{$name}' [$psize]\n");
                }
                // remove directory specification
                $name = preg_replace('{.*/}s', '', $name, 1);
                // read storage class ID if necessary
                $classID = null;
                if ($size == 0xffffffff) {
                    if ($p + 16 > strlen($dataPt)) {
                        $et->Warn('Truncated FPXR storage class ID');
                        return 0;
                    }
                    // unpack class ID in case we want to use it sometime
                    $classID = ASF::GetGUID(substr($dataPt, $p, 16));
                    $p += 16;
                }
                // find the tagInfo if available
                $tagInfo = null;
                if ($tagTablePtr[$name] === null) {
                    // remove instance number or class ID from tag if necessary
                    if ((preg_match('/^(.*) \d{6}$/s', $name, $m) and $tagTablePtr[$m[1]] !== null) or
                        (preg_match('/^(.*)_[0-9a-f]{16}$/s', $name, $m) and $tagTablePtr[$m[1]] !== null))
                    {
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $m[1]);
                    }
                }
                // update position in list
                $pos = $p;
                // add to our contents list
                $contents[] = [
                    'Name' => $name,
                    'Size' => $size,
                    'Default' => $default,
                    'ClassID' => $classID,
                    'TagInfo' => $tagInfo,
                ];
            }
            // save contents list as $et member variable
            // (must do this last so we don't save list on error)
            $et->FPXR = $contents;

        } elseif ($type == 2) {     // a "Stream Data" segment

            // get the contents list index and stream data offset
            [$index, $offset] = Perl::unpack('x7nN', $dataPt);
            $fpxr = $et->FPXR;
            if ($fpxr !== null and isset($fpxr[$index])) {
                $obj = &$et->FPXR[$index];
                // extract stream data (after 13-byte header)
                if (!isset($obj['Stream'])) {
                    // ignore offset for first segment of this type
                    // (in my sample images, this isn't always zero as one would expect)
                    $obj['Stream'] = substr($dataPt, $dirStart + 13);
                } else {
                    // add data at the proper offset to the stream
                    $overlap = strlen($obj['Stream']) - $offset;
                    $start = $dirStart + 13;
                    if ($overlap < 0 or $dirLen - $overlap < 13) {
                        $et->Warn("Bad FPXR stream $index offset", 1);
                    } else {
                        // ignore any overlapping data in this segment
                        // (this seems to be the convention)
                        $start += $overlap;
                    }
                    // concatenate data with this stream
                    $obj['Stream'] .= substr($dataPt, $start);
                }
                // save value for this tag if stream is complete
                $len = strlen($obj['Stream']);
                if ($len >= $obj['Size']) {
                    if ($verbose) $et->VPrint(0, "  + [FPXR stream $index, $len bytes]\n");
                    if ($len > $obj['Size']) {
                        $et->Warn('Extra data in FPXR segment (truncated)');
                        $obj['Stream'] = substr($obj['Stream'], 0, $obj['Size']);
                    }
                    // handle this tag
                    $et->HandleTag($tagTablePtr, $obj['Name'], $obj['Stream'],
                        DataPt: $obj['Stream'],
                        TagInfo: $obj['TagInfo'],
                    );
                    unset($obj['Stream']);  // done with this stream
                }
                unset($obj);
            // hack for improperly stored FujiFilm PreviewImage (stored with no contents list)
            } elseif ($index == 512 and $dirLen > 60 and ($et->FujiPreview or
                ($dirLen > 64 and substr($dataPt, $dirStart + 60, 4) === "\xff\xd8\xff\xdb")))
            {
                if ($et->FujiPreview === null) $et->FujiPreview = '';
                // recombine PreviewImage, skipping 13-byte FPXR header + 47-byte Fuji header
                $et->FujiPreview .= substr($dataPt, $dirStart + 60);
            } else {
                // (Kodak uses index 255 for a free segment in images from some cameras)
                if ($index != 255) $et->Warn("Unlisted FPXR segment (index $index)");
            }

        } elseif ($type != 3) {     // not a "Reserved" segment

            $et->Warn("Unknown FPXR segment (type $type)");

        }

        // clean up if this was the last FPXR segment
        if (!empty($dirInfo['LastFPXR'])) {
            if ($et->FPXR !== null) {
                foreach ($et->FPXR as $obj) {
                    if (!(isset($obj['Stream']) and strlen($obj['Stream']))) continue;
                    // parse it even though it isn't the proper length
                    $et->HandleTag($tagTablePtr, $obj['Name'], $obj['Stream'],
                        DataPt: $obj['Stream'],
                        TagInfo: $obj['TagInfo'],
                    );
                }
                unset($et->FPXR);   // delete our temporary variables
            }
            if ($et->FujiPreview) {
                $et->FoundTag('PreviewImage', $et->FujiPreview);
                unset($et->FujiPreview);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Set document number for objects
    // Inputs: 0) object hierarchy hash ref, 1) object index, 2) doc number list ref,
    //         3) doc numbers used at each level, 4) flag set for metadata levels
    //         (PHP: 3) is an \ArrayObject shared like the Perl array ref)
    public static function SetDocNum(array &$hier, $index, $doc = null, ?\ArrayObject $used = null, $meta = null)
    {
        if (!isset($hier[$index])) return;     // (an empty Perl hash ref is true)
        if (array_key_exists('DocNum', $hier[$index])) return;
        $hier[$index]['DocNum'] = $doc;
        $obj = $hier[$index];
        if (!empty($obj['Left'])) self::SetDocNum($hier, $obj['Left'], $doc, $used, $meta);
        if (!empty($obj['Right'])) self::SetDocNum($hier, $obj['Right'], $doc, $used, $meta);
        if (isset($obj['Child'])) {
            if (!$used) $used = new \ArrayObject();
            $subDoc = [];
            if ($doc !== null) array_push($subDoc, ...$doc);
            // we must dive down 2 levels for each sub-document, so use the
            // $meta flag to add a sub-document level only for every 2nd generation
            if ($meta) {
                $subNum = ($used[count($subDoc)] ?? null) ?: 0;
                $used[count($subDoc)] = $subNum;
                $subDoc[] = $subNum;
            } elseif ($subDoc) {
                $last = count($subDoc) - 1;
                $subDoc[$last] = $used[$last] = ($used[$last] ?? 0) + 1;
            }
            self::SetDocNum($hier, $obj['Child'], $subDoc, $used, !$meta);
        }
    }

    //------------------------------------------------------------------------------
    // Extract information from a FlashPix (FPX) file
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid FPX-format file
    public static function ProcessFPX(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $buff = $out = $oldIndent = $miniStreamBuff = $tag = null;
        $hier = $objIndex = $loadedDifSect = [];

        // handle FPX format in memory from PNG cpIp chunk
        if (!$raf) $raf = new RandomAccess(Perl::str($dirInfo['DataPt']));

        // read header
        if ($raf->Read($buff, self::HDR_SIZE) != self::HDR_SIZE) return 0;
        // check signature
        if (!str_starts_with($buff, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")) return 0;

        // set FileType initially based on file extension (we may override this later)
        $fileType = $et->FILE_EXT;
        if (!($fileType and !empty(Vars::$fpxFileType[$fileType]))) $fileType = 'FPX';
        $et->SetFileType($fileType);
        ExifTool::SetByteOrder(substr($buff, 0x1c, 2) === "\xff\xfe" ? 'MM' : 'II');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FlashPix::Main');
        $verbose = $et->Options('Verbose');
        // copy LargeFileSupport option to RAF for use in LoadChain
        $raf->LargeFileSupport = $et->Options('LargeFileSupport');

        $sectSize = 1 << ExifTool::Get16u($buff, 0x1e);
        $miniSize = 1 << ExifTool::Get16u($buff, 0x20);
        $fatCount   = ExifTool::Get32u($buff, 0x2c);  // number of FAT sectors
        $dirStart   = ExifTool::Get32u($buff, 0x30);  // first directory sector
        $miniCutoff = ExifTool::Get32u($buff, 0x38);  // minimum size for big-FAT streams
        $miniStart  = ExifTool::Get32u($buff, 0x3c);  // first sector of mini-FAT
        $miniCount  = ExifTool::Get32u($buff, 0x40);  // number of mini-FAT sectors
        $difStart   = ExifTool::Get32u($buff, 0x44);  // first sector of DIF chain
        $difCount   = ExifTool::Get32u($buff, 0x48);  // number of DIF sectors

        if ($verbose) {
            $out = $et->Options('TextOut');
            fwrite($out, "  Sector size=$sectSize\n  FAT: Count=$fatCount\n");
            fwrite($out, "  DIR: Start=$dirStart\n");
            fwrite($out, "  MiniFAT: Mini-sector size=$miniSize Start=$miniStart Count=$miniCount Cutoff=$miniCutoff\n");
            fwrite($out, "  DIF FAT: Start=$difStart Count=$difCount\n");
        }
//
// load the FAT
//
        $pos = 0x4c;
        $endPos = strlen($buff);
        $fat = '';
        $fatCountCheck = 0;
        $difCountCheck = 0;
        $hdrSize = $sectSize > self::HDR_SIZE ? $sectSize : self::HDR_SIZE;

        for (;;) {
            while ($pos <= $endPos - 4) {
                $sect = ExifTool::Get32u($buff, $pos);
                $pos += 4;
                if ($sect == self::FREE_SECT) continue;
                $offset = $sect * $sectSize + $hdrSize;
                $fatSect = null;
                if (!($raf->Seek($offset, 0) and
                      $raf->Read($fatSect, $sectSize) == $sectSize))
                {
                    $et->Error("Error reading FAT from sector $sect");
                    return 1;
                }
                $fat .= $fatSect;
                ++$fatCountCheck;
            }
            if ($difStart >= self::END_OF_CHAIN) break;
            // read next DIF (Dual Indirect FAT) sector
            if (++$difCountCheck > $difCount) {
                $et->Warn('Unterminated DIF FAT');
                break;
            }
            if (!empty($loadedDifSect[$difStart])) {
                $et->Warn('Cyclical reference in DIF FAT');
                break;
            }
            $offset = $difStart * $sectSize + $hdrSize;
            if (!($raf->Seek($offset, 0) and $raf->Read($buff, $sectSize) == $sectSize)) {
                $et->Error("Error reading DIF sector $difStart");
                return 1;
            }
            $loadedDifSect[$difStart] = 1;
            // set end of sector information in this DIF
            $pos = 0;
            $endPos = $sectSize - 4;
            // next time around we want to read next DIF in chain
            $difStart = ExifTool::Get32u($buff, $endPos);
        }
        if ($fatCountCheck != $fatCount) {
            $et->Warn("Bad number of FAT sectors (expected $fatCount but found $fatCountCheck)");
        }
//
// load the mini-FAT and the directory
//
        $miniFat = self::LoadChain($raf, $miniStart, $fat, $sectSize, $hdrSize);
        $dir = self::LoadChain($raf, $dirStart, $fat, $sectSize, $hdrSize);
        if (!($miniFat !== null and $dir !== null)) {
            $et->Error('Error reading mini-FAT or directory stream');
            return 1;
        }
        if ($verbose) {
            fwrite($out, '  FAT [' . strlen($fat) . " bytes]:\n");
            $et->VerboseDump($fat);
            fwrite($out, '  Mini-FAT [' . strlen($miniFat) . " bytes]:\n");
            $et->VerboseDump($miniFat);
            fwrite($out, '  Directory [' . strlen($dir) . " bytes]:\n");
            $et->VerboseDump($dir);
        }
//
// process the directory
//
        if ($verbose) {
            $oldIndent = $et->INDENT;
            $et->INDENT .= '| ';
            $et->VerboseDir('FPX', null, strlen($dir));
        }
        $miniStream = null;
        $endPos = strlen($dir);
        $index = 0;
        $ee = null; // name of next tag to extract if unknown
        if ($et->Options('ExtractEmbedded')) $ee = 0;

        for ($pos = 0; $pos <= $endPos - 128; $pos += 128, ++$index) {

            // get directory entry type
            // (0=invalid, 1=storage, 2=stream, 3=lockbytes, 4=property, 5=root)
            $type = ExifTool::Get8u($dir, $pos + 0x42);
            if ($type == 0) continue;   // skip invalid entries
            if ($type > 5) {
                $et->Warn("Invalid directory entry type $type");
                break;  // rest of directory is probably garbage
            }
            // get entry name (note: this is supposed to be length in 2-byte
            // characters but this isn't what is done in my sample FPX file, so
            // be very tolerant of this count -- it's null terminated anyway)
            $len = ExifTool::Get16u($dir, $pos + 0x40);
            if ($len > 32) $len = 32;
            $tag = ExifTool::DecodeStatic(null, substr($dir, $pos, $len * 2), 'UTF16', 'II', 'Latin');
            $tag = preg_replace('/\0.*/s', '', $tag);   // truncate at null (in case length was wrong)

            if ($tag === '0' and $ee === null) {
                $et->Warn('Use the ExtractEmbedded option to extract embedded information', 3);
            }
            $sect = ExifTool::Get32u($dir, $pos + 0x74);   // start sector number
            $size = ExifTool::Get32u($dir, $pos + 0x78);   // stream length

            // load Ministream (referenced from first directory entry)
            if (!$miniStream) {
                $miniStreamBuff = self::LoadChain($raf, $sect, $fat, $sectSize, $hdrSize);
                if ($miniStreamBuff === null) {
                    $et->Warn('Error loading Mini-FAT stream');
                    break;
                }
                $miniStream = new RandomAccess($miniStreamBuff);
            }

            $tagInfo = null;
            if ($tagTablePtr[$tag] !== null) {
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            } elseif ($ee !== null and $tag === Perl::str($ee)) {
                $tagInfo = '';  // won't know the actual tagID untile we read the stream
                $ee = Perl::sprintf('%x', hexdec(Perl::str($ee)) + 1);  // tag to look for next
            } else {
                // remove instance number or class ID from tag if necessary
                if ((preg_match('/^(.*) \d{6}$/s', $tag, $m) and $tagTablePtr[$m[1]] !== null) or
                    (preg_match('/^(.*)_[0-9a-f]{16}$/s', $tag, $m) and $tagTablePtr[$m[1]] !== null) or
                    (preg_match('/^(.*)_[0-9]{4}$/s', $tag, $m) and $tagTablePtr[$m[1]] !== null))  // IeImg instances
                {
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $m[1]);
                }
            }

            $lSib = ExifTool::Get32u($dir, $pos + 0x44);   // left sibling
            $rSib = ExifTool::Get32u($dir, $pos + 0x48);   // right sibling
            $chld = ExifTool::Get32u($dir, $pos + 0x4c);   // child directory

            // save information about object hierarchy
            if (!isset($hier[$index])) $hier[$index] = [];
            if ($lSib != self::FREE_SECT) $hier[$index]['Left'] = $lSib;
            if ($rSib != self::FREE_SECT) $hier[$index]['Right'] = $rSib;
            if ($chld != self::FREE_SECT) {
                $hier[$index]['Child'] = $chld;
                if (!isset($hier[$chld])) $hier[$chld] = [];
                $hier[$chld]['Parent'] = $index;
            }

            if (!($tagInfo !== null or $verbose)) continue;

            // load the data for stream types
            $extra = '';
            $typeStr = (Vars::$dirEntryType[$type] ?? null) ?: $type;
            if ($typeStr === 'STREAM') {
                if ($size >= $miniCutoff) {
                    // stream is in the main FAT
                    $buff = self::LoadChain($raf, $sect, $fat, $sectSize, $hdrSize);
                } elseif ($size) {
                    // stream is in the mini-FAT
                    $buff = self::LoadChain($miniStream, $sect, $miniFat, $miniSize, 0);
                } else {
                    $buff = ''; // an empty stream
                }
                if ($buff === null) {
                    $name = $tagInfo ? $tagInfo->Name : 'unknown';
                    $et->Warn("Error reading $name stream");
                    $buff = '';
                }
            } elseif ($typeStr === 'ROOT') {
                $buff = $miniStreamBuff;
                $extra .= ' (Ministream)';
            } else {
                $buff = '';
                $size = null;
            }
            if ($verbose) {
                $flags = ExifTool::Get8u($dir, $pos + 0x43);   // 0=red, 1=black
                $col = ([0 => 'Red', 1 => 'Black'][$flags] ?? null) ?: $flags;
                $extra .= " Type=$typeStr Flags=$col";
                if ($lSib != self::FREE_SECT) $extra .= " Left=$lSib";
                if ($rSib != self::FREE_SECT) $extra .= " Right=$rSib";
                if ($chld != self::FREE_SECT) $extra .= " Child=$chld";
                if ($size !== null) $extra .= " Size=$size";
                $name = null;
                if (!$tagInfo and preg_match('/^[0-9a-f]{1,3}$/', $tag)) $name = "Unknown_0x$tag";
                $et->VerboseInfo($tag, $tagInfo,
                    Index: $index,
                    Value: $buff,
                    DataPt: $buff,
                    Extra: $extra,
                  // Size   => $size, (moved to $extra so we can see the rest of the stream if larger)
                    Name: $name,
                );
            }
            if ($tagInfo !== null and $buff) {
                $num = $et->NUM_FOUND;
                if ($tagInfo and $tagInfo->SubDirectory) {
                    $subdir = $tagInfo->SubDirectory;
                    $subdirInfo = [
                        'DataPt'   => $buff,
                        'DirStart' => $subdir['DirStart'] ?? null,
                        'DirLen'   => strlen($buff),
                        'Multi'    => $tagInfo->Multi,
                    ];
                    $subTablePtr = ExifTool::GetTagTable($subdir['TagTable'] ?? null);
                    $et->ProcessDirectory($subdirInfo, $subTablePtr, $subdir['ProcessProc'] ?? null);
                } elseif ($size !== null and $size > strlen($buff)) {
                    $et->Warn('Truncated object');
                } else {
                    if ($size !== null and $size < strlen($buff)) $buff = substr($buff, 0, $size);
                    if (preg_match('/^IeImg_0*(\d+)$/', $tag, $m)) {
                        // set document number for embedded images and their positions (if available, VNT files)
                        $imgNum = $m[1];    // (Perl: my $num, local to this block)
                        $et->DOC_NUM = ++$et->DOC_COUNT;
                        $et->FoundTag($tagInfo, $buff);
                        if ($et->IeImg_lkup and !empty($et->IeImg_lkup[$imgNum])) {
                            // save position of this image
                            $et->HandleTag($tagTablePtr, 'IeImg_rect', $et->IeImg_lkup[$imgNum]);
                            unset($et->IeImg_lkup[$imgNum]);
                            if ($et->IeImg_class and !empty($et->IeImg_class[$imgNum])) {
                                $et->HandleTag($tagTablePtr, 'IeImg_class', $et->IeImg_class[$imgNum]);
                                unset($et->IeImg_class[$imgNum]);
                            }
                        }
                        $et->DOC_NUM = null;
                    } elseif (!$tagInfo) {
                        // extract some embedded information from PNG Plus images
                        if (preg_match('/^(.{19,40})(\xff\xd8\xff\xe0|\x89PNG\r\n\x1a\n)/s', $buff, $m)) {
                            $id = $m[2] === "\xff\xd8\xff\xe0" ? '_eeJPG' : '_eePNG';
                            $et->HandleTag($tagTablePtr, $id, substr($buff, strlen($m[1])));
                        } elseif (preg_match('/^\0\x80\0\0\x01\0\0\0\x0e\0/', $buff) and strlen($buff) > 18) {
                            $len = Perl::unpack('x17C', $buff)[0];
                            if ($len + 18 > strlen($buff)) continue;
                            $filename = $et->Decode(substr($buff, 18, $len), 'UTF16', 'II');
                            $et->HandleTag($tagTablePtr, '_eeLink', $filename);
                        } else {
                            continue;
                        }
                    } else {
                        $et->FoundTag($tagInfo, $buff);
                    }
                }
                // save object index number for all found tags
                $num2 = $et->NUM_FOUND;
                while ($num < $num2) $objIndex[++$num] = $index;
            }
        }
        // set document numbers for tags extracted from embedded documents
        if (!$et->DOC_NUM) {
            // initialize document number for all objects, beginning at root (index 0)
            self::SetDocNum($hier, 0);
            // set family 3 group name for all tags in embedded documents
            $order = $et->FILE_ORDER;
            $pri = [];
            foreach (array_keys($order) as $tag) {
                $num = $order[$tag];
                if (!($num !== null and !empty($objIndex[$num]))) continue;
                $obj = $hier[$objIndex[$num]] ?? null;
                if ($obj === null) continue;
                $docNums = $obj['DocNum'] ?? null;
                if (!$docNums) continue;
                $et->TAG_EXTRA[$tag]['G3'] = implode('-', $docNums);
                if (!str_contains((string) $tag, ' ')) $pri[] = $tag;   // save keys for priority sub-doc tags
            }
            // swap priority sub-document tags with main document tags if they exist
            foreach ($pri as $tag) {
                for ($copy = 1; ; ++$copy) {
                    $key = "$tag ($copy)";
                    if (!isset($et->VALUE[$key])) break;
                    if (!empty($et->TAG_EXTRA[$key]['G3'])) continue;  // not Main if family 3 group is set
                    foreach (['PRIORITY', 'VALUE', 'FILE_ORDER', 'TAG_INFO', 'TAG_EXTRA'] as $member) {
                        $t = $et->{$member}[$tag] ?? null;
                        $et->{$member}[$tag] = $et->{$member}[$key] ?? null;
                        $et->{$member}[$key] = $t;
                    }
                    break;
                }
            }
        }
        if ($verbose) $et->INDENT = $oldIndent;
        // try to better identify the file type
        if ($et->FileType === 'FPX') {
            $val = $et->CompObjUserType ?: $et->Software;
            if ($val) {
                $types = ['^3ds Max' => 'MAX', 'Word' => 'DOC', 'PowerPoint' => 'PPT', 'Excel' => 'XLS'];
                $pats = array_keys($types);
                sort($pats, SORT_STRING);
                foreach ($pats as $pat) {
                    if (!preg_match("/$pat/", Perl::str($val))) continue;
                    $et->OverrideFileType($types[$pat]);
                    break;
                }
            }
        }
        // process Word document table
        self::ProcessDocumentTable($et);

        if ($et->IeImg_lkup) {
            $et->Warn('Image positions exist without corresponding images');
        }

        return 1;
    }
}
