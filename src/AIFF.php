<?php

//------------------------------------------------------------------------------
// File:         AIFF.pm
//
// Description:  Read AIFF meta information
//
// Revisions:    01/06/2006 - P. Harvey Created
//               09/22/2008 - PH Added DjVu support
//
// References:   1) http://developer.apple.com/documentation/QuickTime/INMAC/SOUND/imsoundmgr.30.htm#pgfId=3190
//               2) http://astronomy.swin.edu.au/~pbourke/dataformats/aiff/
//               3) http://www.mactech.com/articles/mactech/Vol.06/06.01/SANENormalized/
//
// PHP port of Image::ExifTool::AIFF 1.13 (reading only). Tag tables and file lexicals
// (%timeInfo) are generated into tables/AIFF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class AIFF
{
    public static $VERSION = '1.13';

    //------------------------------------------------------------------------------
    // Process AIFF Comment chunk
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessComment(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');
        if (!($dirLen > 2)) return 0;
        $numComments = Perl::unpack('n', $dataPt)[0];
        $pos = 2;
        if ($verbose) $et->VerboseDir('Comment', $numComments);
        for ($i = 0; $i < $numComments; ++$i) {
            if ($pos + 8 > $dirLen) break;
            [$time, $markerID, $size] = Perl::unpack("x{$pos}Nnn", $dataPt);
            $et->HandleTag($tagTablePtr, 0, $time);
            if ($markerID) $et->HandleTag($tagTablePtr, 1, $markerID);
            $pos += 8;
            if ($pos + $size > $dirLen) break;
            $val = substr($dataPt, $pos, $size);
            $et->HandleTag($tagTablePtr, 2, $val);
            if ($size & 0x01) ++$size;  // account for padding byte if necessary
            $pos += $size;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Extract information from a AIFF file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid AIFF file
    public static function ProcessAIFF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $err = $tagTablePtr = $page = $type = $n = null;

        // verify this is a valid AIFF file
        if ($raf->Read($buff, 12) != 12) return 0;
        $fast3 = ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3);
        $pos = 12;
        // check for DjVu image
        if (str_starts_with($buff, 'AT&TFORM')) {
            // http://www.djvu.org/
            // http://djvu.sourceforge.net/specs/djvu3changes.txt
            $buf2 = null;
            if (!($raf->Read($buf2, 4) == 4 and preg_match('/^(DJVU|DJVM)/', $buf2))) return 0;
            $pos += 4;
            $buff = substr($buff, 4) . $buf2;
            $et->SetFileType('DJVU');
            if ($fast3) return 1;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::DjVu::Main');
            // modify FileType to indicate a multi-page document
            if ($buf2 === 'DJVM' and !empty($et->VALUE['FileType'])) $et->VALUE['FileType'] .= ' (multi-page)';
            $type = 'DjVu';
        } else {
            if (!preg_match('/^FORM....(AIF(F|C))/s', $buff, $m)) return 0;
            $et->SetFileType($m[1]);
            if ($fast3) return 1;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::AIFF::Main');
            $type = 'AIFF';
        }
        ExifTool::SetByteOrder('MM');
        $verbose = $et->Options('Verbose');
//
// Read through the IFF chunks
//
        for ($n = 0;; ++$n) {
            if ($raf->Read($buff, 8) != 8) break;
            $pos += 8;
            [$tag, $len] = Perl::unpack('a4N', $buff);
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            $et->VPrint(0, "AIFF '{$tag}' chunk ($len bytes of data): ", $raf->Tell(), "\n");
            // AIFF chunks are padded to an even number of bytes
            $len2 = $len + ($len & 0x01);
            if ($len2 > 100000000) {
                if ($len2 >= 0x80000000) {
                    if (!$et->Options('LargeFileSupport')) {
                        $et->Warn('End of processing at large chunk (LargeFileSupport not enabled)');
                        break;
                    } elseif (Perl::str($et->Options('LargeFileSupport')) === '2') {
                        $et->Warn('Skipping large chunk (LargeFileSupport is 2)');
                    }
                }
                if ($tagInfo) {
                    $et->Warn("Skipping large {$tagInfo->Name} chunk (> 100 MB)");
                    $tagInfo = null;
                }
            }
            if ($tagInfo) {
                if ($tagInfo->TypeOnly) {
                    $len = $len2 = 4;
                    $page = ($page ?: 0) + 1;
                    $et->VPrint(0, $et->INDENT . "Page $page:\n");
                }
                if (!($raf->Read($buff, $len2) >= $len)) { $err = 1; break; }
                if (!($tagInfo->SubDirectory or $tagInfo->Binary)) {
                    $buff = preg_replace('/\0+$/', '', $buff, 1);  // remove trailing nulls
                }
                $et->HandleTag($tagTablePtr, $tag, $buff,
                    DataPt  : $buff,
                    DataPos : $pos,
                    Start   : 0,
                    Size    : $len,
                );
            } elseif (!$len) {
                if (++$n < 100) continue;
                $et->Warn('Aborting scan.  Too many empty chunks');
                break;
            } elseif ($verbose > 2 and $len2 < 1024000) {
                if ($raf->Read($buff, $len2) != $len2) { $err = 1; break; }
                $et->VerboseDump($buff);
            } else {
                if (!$raf->Seek($len2, 1)) { $err = 1; break; }
            }
            $pos += $len2;
            $n = 0;
        }
        if ($err) $et->Warn("Error reading $type file (corrupted?)");
        return 1;
    }
}
