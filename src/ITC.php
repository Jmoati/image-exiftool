<?php

//------------------------------------------------------------------------------
// File:         ITC.pm
//
// Description:  Read iTunes Cover Flow meta information
//
// Revisions:    01/12/2008 - P. Harvey Created
//
// References:   1) http://www.waldoland.com/dev/Articles/ITCFileFormat.aspx
//               2) http://www.falsecognate.org/2007/01/deciphering_the_itunes_itc_fil/
//
// PHP port of Image::ExifTool::ITC 1.02 (reading only). Tag tables are generated
// into tables/ITC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class ITC
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Process an iTunes Cover Flow (ITC) file
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid ITC file
    public static function ProcessITC(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $rtnVal = 0;
        $buff = $err = $pos = $tagTablePtr = null;

        // loop through all blocks in this image
        for (;;) {
            // read the block header
            $n = $raf->Read($buff, 8);
            if ($n != 8) {
                // no error if we reached the EOF normally
                if (!$n) $err = null;
                break;
            }
            [$size, $tag] = Perl::unpack('Na4', $buff);
            if ($rtnVal) {
                if (!($size >= 8 and $size < 0x80000000)) break;
            } else {
                // check to be sure this is a valid ITC image
                // (first block must be 'itch')
                if ($tag !== 'itch') break;
                if (!($size >= 0x1c and $size < 0x10000)) break;
                $et->SetFileType();
                ExifTool::SetByteOrder('MM');
                $rtnVal = 1;    // this is an ITC file
                $err = 1;       // format error unless we read to EOF
            }
            if ($tag === 'itch') {
                $pos = $raf->Tell();
                $size -= 8; // size of remaining data in block
                if ($raf->Read($buff, $size) != $size) break;
                // extract header information
                $subdirInfo = [     // (Perl %dirInfo)
                    'DirName' => 'ITC Header',
                    'DataPt'  => $buff,
                    'DataPos' => $pos,
                ];
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ITC::Header');
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            } elseif ($tag === 'item') {
                // don't want to read the entire item data (includes image)
                if (!($size > 12)) break;
                if ($raf->Read($buff, 4) != 4) break;
                $len = Perl::unpack('N', $buff)[0];
                if (!($len >= 0xd0 and $len <= $size)) break;
                $size -= $len;  // size of data after item header
                $len -= 12;     // length of remaining item header
                // read in 4-byte blocks until we find the null terminator
                // (this is just a guess about how to parse this variable-length part)
                while ($len >= 4) {
                    if ($raf->Read($buff, 4) != 4) break;
                    $len -= 4;
                    if ($buff === "\0\0\0\0") break;
                }
                if ($len < 4) break;
                $pos = $raf->Tell();
                if ($raf->Read($buff, $len) != $len) break;
                if (!($len >= 0xb4 and substr($buff, 0xb0, 4) === 'data')) {
                    $et->Warn('Parsing error. Please submit this ITC file for testing');
                    break;
                }
                $subdirInfo = [     // (Perl %dirInfo)
                    'DirName' => 'ITC Item',
                    'DataPt'  => $buff,
                    'DataPos' => $pos,
                ];
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ITC::Item');
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
                // extract embedded image
                $pos += $len;
                if ($size > 0) {
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ITC::Main');
                    $tagInfo = $et->GetTagInfo($tagTablePtr, 'data');
                    $image = $et->ExtractBinary($pos, $size, $tagInfo->Name);
                    $et->FoundTag($tagInfo, new ScalarRef($image));
                    // skip the rest of the block if necessary
                    if (!$raf->Seek($pos + $size, 0)) break;
                } elseif ($size < 0) {
                    break;
                }
            } else {
                $et->VPrint(0, "Unknown $tag block ($size bytes)\n");
                if (!$raf->Seek($size - 8, 1)) break;
            }
        }
        if ($err) $et->Warn('ITC file format error');
        return $rtnVal;
    }
}
