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
// PHP port of Image::ExifTool::FLIF 1.02 (reading only: SetVarInt and WriteFLIF are
// not ported). Tag tables are generated into tables/FLIF.php.
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
    // Read FLIF header
    // Inputs: 0) RAF reference
    // Returns: Scalar context: binary header block
    //          List context: header values (4 or 5 elements: type,depth,width,height[,frames])
    //          or undef if this isn't a valid FLIF file header
    // (PHP: list context only, returns [] if this isn't a valid FLIF file header)
    public static function ReadFLIFHeader($raf)
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
    // Read FLIF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid FLIF file
    public static function ProcessFLIF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tag = $inflated = null;

        // verify this is a valid FLIF file and read the header
        $vals = self::ReadFLIFHeader($raf);
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
