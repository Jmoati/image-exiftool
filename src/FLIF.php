<?php

//------------------------------------------------------------------------------
// File:         FLIF.pm
//
// Description:  Read/write FLIF meta information
//
// Revisions:    2016/10/11 - P. Harvey Created
//               2016/10/14 - PH Added write support
//
// References:   1) http://flif.info/
//               2) https://github.com/FLIF-hub/FLIF/blob/master/doc/metadata
//
// PHP port of Image::ExifTool::FLIF 1.02. Tag tables are generated into tables/FLIF.php,
// %flifMap into Vars\FLIF. IO::Uncompress::RawInflate and IO::Compress::RawDeflate are
// gzinflate() and a raw zlib deflate with the same parameters.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class FLIF
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Read variable-length FLIF integer
    // Inputs: 0) raf reference, 1) number to add to returned value
    // Returns: integer, or undef on EOF
    public static function GetVarInt($raf, $add = null)
    {
        $buff = null;
        for ($val = 0; ; $val <<= 7) {
            if (!$raf->Read($buff, 1)) return null;
            $byte = ord($buff);
            $val |= ($byte & 0x7f);
            if (!($byte & 0x80)) break;
        }
        return $val + ($add ?: 0);
    }

    //------------------------------------------------------------------------------
    // Construct variable-length FLIF integer
    // Inputs: 0) integer
    // Returns: FLIF variable-length integer byte stream
    public static function SetVarInt($val)
    {
        $buff = '';
        $high = 0;
        for (;;) {
            $buff = chr(($val & 0x7f) | $high) . $buff;
            $val >>= 7;
            if (!$val) break;
            $high = 0x80;
        }
        return $buff;
    }

    //------------------------------------------------------------------------------
    // Read FLIF header
    // Inputs: 0) RAF ref
    // Returns: Scalar context: binary header block
    //          List context: header values (4 or 5 elements: type,depth,width,height[,frames])
    //          or undef if this isn't a valid FLIF file header
    // (PHP: scalar context, null if this isn't a valid FLIF file header; the list context
    //  is ReadFLIFHeaderList)
    public static function ReadFLIFHeader($raf)
    {
        if (!self::ReadFLIFHeaderList($raf)) return null;

        // return the binary header block
        $buff = null;
        $hdrLen = $raf->Tell();
        if (!($raf->Seek(0, 0) and $raf->Read($buff, $hdrLen) == $hdrLen)) return null;
        return $buff;
    }

    /** ReadFLIFHeader in list context: the header values, [] if this isn't a valid FLIF file header */
    public static function ReadFLIFHeaderList($raf)
    {
        $buff = null;
        $vals = [];

        // verify this is a valid FLIF file
        if (!($raf->Read($buff, 6) == 6 and preg_match('/^FLIF([0-\x6f])([0-2])/', $buff, $m))) return [];

        // decode header information ("FLIF" chunk)
        array_push($vals, $m[1], $m[2]);                                    // type, depth
        array_push($vals, self::GetVarInt($raf, +1), self::GetVarInt($raf, +1)); // width, height (+1 each)
        if (strcmp($vals[0], 'H') > 0) $vals[] = self::GetVarInt($raf, +2);  // frames (+2)

        if ($vals[count($vals) - 1] === null) return [];
        return $vals;   // return the decoded header values
    }

    //------------------------------------------------------------------------------
    // WriteFLIF : Write FLIF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid FLIF file, or -1 if
    //          an output file was specified and a write error occurred
    public static function WriteFLIF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $soi = null;
        $addTags = $doneTag = [];

        // verify FLIF header and copy it to the output file
        $buff = self::ReadFLIFHeader($raf);
        if (!$buff) return 0;
        $outfile = $dirInfo['OutFile'];
        if (!ExifTool::Write($outfile, $buff)) return -1;

        $et->InitWriteDirs(Vars\FLIF::$flifMap);
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FLIF::Main');

        // loop through the FLIF chunks
        for (;;) {
            $tag = $size = $inflated = null;
            // read new tag (or soi) unless we already hit the soi (start of image)
            if ($soi === null) {
                if ($raf->Read($buff, 4) != 4) {
                    $et->Error('Unexpected EOF');
                    break;
                }
                if (strcmp($buff, ' ') < 0) {
                    $soi = $buff;   // we have hit the start of image (no more metadata)
                    // make list of new tags to add
                    foreach (['eXif', 'eXmp', 'iCCP'] as $tag) {
                        if (($et->ADD_DIRS[$tagTablePtr[$tag]->Name] ?? null) and !isset($doneTag[$tag])) $addTags[] = $tag;
                    }
                }
            }
            if ($soi === null) {
                $tag = $buff;
                $size = self::GetVarInt($raf);  // read the data size
            } elseif ($addTags) {
                $tag = array_shift($addTags);
                [$buff, $size] = ['', 0];       // create metadata from scratch
            } else {
                // finish copying file (no more metadata to add)
                if (!ExifTool::Write($outfile, $soi)) return -1;
                while ($raf->Read($buff, 65536)) {
                    if (!ExifTool::Write($outfile, $buff)) return -1;
                }
                break;  // all done!
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo and $tagInfo->SubDirectory and ($et->EDIT_DIRS[$tagInfo->Name] ?? null)) {
                $doneTag[$tag] = 1;         // prevent adding this back again later
                if ($soi === null) {
                    if ($raf->Read($buff, $size) != $size) {
                        $et->Error("Truncated FLIF $tag chunk");
                        break;
                    }
                }
                // rewrite the compressed data
                if (function_exists('gzinflate') and function_exists('deflate_init')) {
                    if (strlen($buff) == 0) {
                        $inflated = $buff;  // (creating from scratch, so no need to inflate)
                    } elseif (($inflated = @gzinflate($buff)) === false) {
                        $et->Error("Error inflating FLIF $tag chunk");
                        break;
                    }
                    $subdir = $tagInfo->SubDirectory;
                    $subdirInfo = [
                        'DirName'  => $tagInfo->Name,
                        'DataPt'   => $inflated,
                        'DirStart' => strlen($inflated) ? ($subdir['Start'] ?? null) : null,
                        'ReadOnly' => 1,    // (used only by WriteXMP)
                    ];
                    $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                    $inflated = $et->WriteDirectory($subdirInfo, $subTable, $subdir['WriteProc'] ?? null);
                    if ($inflated !== null) {
                        if (!strlen($inflated)) continue;   // (delete directory if length is zero)
                        if ($subdir['Header'] ?? null) $inflated = $subdir['Header'] . $inflated;  // (add back header if necessary)
                        // (IO::Compress::RawDeflate: raw deflate with a MemLevel of 9)
                        $buff = deflate_add(deflate_init(ZLIB_ENCODING_RAW, ['memory' => 9]), $inflated, ZLIB_FINISH);
                        if ($buff === false) {
                            $et->Error("Error deflating FLIF $tag chunk");
                            break;
                        }
                    }
                } else {
                    $et->Warn('Install IO::Compress::RawDeflate to write FLIF metadata');
                }
                if (!ExifTool::Write($outfile, $tag, self::SetVarInt(strlen($buff)), $buff)) return -1;
            } elseif ($soi === null) {
                if (!ExifTool::Write($outfile, $tag, self::SetVarInt($size))) return -1;
                if (!ExifTool::CopyBlock($raf, $outfile, $size)) return -1;
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read FLIF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid FLIF file
    public static function ProcessFLIF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tag = $inflated = null;

        // verify this is a valid FLIF file and read the header
        $vals = self::ReadFLIFHeaderList($raf);
        if (!$vals) return 0;

        $et->SetFileType();
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FLIF::Main');
        $verbose = $et->Options('Verbose');

        // save the header information
        if ($verbose) $et->VPrint(0, "FLIF header:\n");
        for ($tag = 0; isset($vals[$tag]); ++$tag) {
            $et->HandleTag($tagTablePtr, $tag, $vals[$tag]);
        }

        // loop through the FLIF chunks
        for (;;) {
            if ($raf->Read($tag, 4) != 4) {
                $et->Warn('Unexpected EOF');
                break;
            }
            $byte = ord(substr($tag, 0, 1));
            // all done if we arrived at the image chunk
            if ($byte < 32) {
                $et->HandleTag($tagTablePtr, 5, $byte);
                break;
            }
            $size = self::GetVarInt($raf);
            if ($verbose) $et->VPrint(0, "FLIF $tag ($size bytes):\n");
            if ($tagTablePtr[$tag]) {
                if ($raf->Read($buff, $size) != $size) {
                    $et->Warn("Truncated FLIF $tag chunk");
                    break;
                }
                if ($verbose > 2) $et->VerboseDump($buff, Addr: $raf->Tell() - $size);
                // inflate the compressed data
                $inflated = @gzinflate($buff);
                if ($inflated !== false) {
                    $et->HandleTag($tagTablePtr, $tag, $inflated,
                        DataPt: $inflated,
                        Size: strlen($inflated),
                        Extra: ' inflated',
                    );
                } else {
                    $et->Warn("Error inflating FLIF $tag chunk");
                }
            } else {
                if (!$raf->Seek($size, 1)) {
                    $et->Warn('Seek error');
                    break;
                }
            }
        }
        return 1;
    }
}
