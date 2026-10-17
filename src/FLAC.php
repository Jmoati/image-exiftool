<?php

//------------------------------------------------------------------------------
// File:         FLAC.pm
//
// Description:  Read Free Lossless Audio Codec information
//
// Revisions:    11/13/2006 - P. Harvey Created
//
// References:   1) http://flac.sourceforge.net/
//
// PHP port of Image::ExifTool::FLAC 1.09 (reading only). Tag tables are generated
// into tables/FLAC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class FLAC
{
    public static $VERSION = '1.09';

    //------------------------------------------------------------------------------
    // Process information in a bit stream
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Notes: Byte order is used to determine the ordering of bits in the stream:
    // 'MM' = bit 0 is most significant, 'II' = bit 0 is least significant
    // - can handle arbitrarily wide values (eg. 8-byte or larger integers)
    public static function ProcessBitStream(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt   = $dirInfo['DataPt'];
        $dataPos  = $dirInfo['DataPos'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen   = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
        $verbose  = $et->Options('Verbose');
        $byteOrder = ExifTool::GetByteOrder();

        if ($verbose) {
            $et->VPrint(0, "  + [BitStream directory, $dirLen bytes, '{$byteOrder}' order]\n");
        }
        $keys = $tagTablePtr->keys();
        sort($keys, SORT_STRING);
        foreach ($keys as $tag) {
            if (!preg_match('/^Bit(\d+)-?(\d+)?/', (string) $tag, $m)) continue;
            [$b1, $b2] = [(int) $m[1], (int) (($m[2] ?? null) ?: $m[1])];   // start/end bit numbers in stream
            [$i1, $i2] = [Perl::int($b1 / 8), Perl::int($b2 / 8)];  // start/end byte numbers
            [$f1, $f2] = [$b1 % 8, $b2 % 8]; // start/end bit numbers within each byte
            if ($i2 >= $dirLen) break;
            $val = $extra = null;
            // if Format is unspecified, convert the specified number of bits to an unsigned integer,
            // otherwise allow HandleTag to convert whole bytes the normal way (via undefined $val)
            if (!($tagTablePtr[$tag] instanceof TagInfo) or !$tagTablePtr[$tag]->Format) {
                $mask = null;
                $val = 0;
                if ($verbose and ($f1 != 0 or $f2 != 7)) $extra = ', Mask=0x';
                if ($byteOrder === 'MM') {
                    // loop from high byte to low byte
                    for ($i = $i1; $i <= $i2; ++$i) {
                        $mask = 0xff;
                        if ($i == $i1 and $f1) {
                            // mask off high bits in first word (0 is high bit)
                            foreach (range(8 - $f1, 7) as $_) $mask ^= (1 << $_);
                        }
                        if ($i == $i2 and $f2 < 7) {
                            // mask off low bits in last word (7 is low bit)
                            foreach (range(0, 6 - $f2) as $_) $mask ^= (1 << $_);
                        }
                        $val = $val * 256 + ($mask & ExifTool::Get8u($dataPt, $i + $dirStart));
                        if ($extra) $extra .= Perl::sprintf('%.2x', $mask);
                    }
                } else {
                    // (FLAC is big-endian, but support little-endian bit streams
                    //  so this routine can be used by other modules)
                    // loop from high byte to low byte
                    for ($i = $i2; $i >= $i1; --$i) {
                        $mask = 0xff;
                        if ($i == $i1 and $f1) {
                            // mask off low bits in first word (0 is low bit)
                            foreach (range(0, $f1 - 1) as $_) $mask ^= (1 << $_);
                        }
                        if ($i == $i2 and $f2 < 7) {
                            // mask off high bits in last word (7 is high bit)
                            foreach (range($f2 + 1, 7) as $_) $mask ^= (1 << $_);
                        }
                        $val = $val * 256 + ($mask & ExifTool::Get8u($dataPt, $i + $dirStart));
                        if ($extra) $extra .= Perl::sprintf('%.2x', $mask);
                    }
                }
                // shift word down until low bit is in position 0
                while (!($mask & 0x01)) {
                    $val /= 2;
                    $mask >>= 1;
                }
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt  : $dataPt,
                DataPos : $dataPos,
                Start   : $dirStart + $i1,
                Size    : $i2 - $i1 + 1,
                Extra   : $extra,
            );
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from an Ogg FLAC file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid Ogg FLAC file
    public static function ProcessFLAC(ExifTool $et, array &$dirInfo)
    {
        // must first check for leading/trailing ID3 information
        if (!$et->DoneID3) {
            ExifTool::RequireModule('Image::ExifTool::ID3');
            if (ID3::ProcessID3($et, $dirInfo)) return 1;
        }
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $buff = $err = null;

        // check FLAC signature
        if (!($raf->Read($buff, 4) == 4 and $buff === 'fLaC')) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FLAC::Main');
        for (;;) {
            // read next metadata block header
            if ($raf->Read($buff, 4) != 4) break;
            $flag = Perl::unpack('C', $buff)[0];
            $size = Perl::unpack('N', $buff)[0] & 0x00ffffff;
            if ($raf->Read($buff, $size) != $size) { $err = 1; break; }
            $last = $flag & 0x80;   // last-metadata-block flag
            $tag  = $flag & 0x7f;   // tag bits
            if ($verbose) {
                fwrite($out, "FLAC metadata block, type $tag:\n");
                $et->VerboseDump($buff, DataPos: $raf->Tell() - $size);
            }
            $et->HandleTag($tagTablePtr, $tag, $buff,
                DataPt  : $buff,
                DataPos : $raf->Tell() - $size,
                Start   : 0,
                Size    : $size,
            );
            if ($last) break;   // all done if  is set
        }
        if ($err) $et->Warn('Format error in FLAC file');
        return 1;
    }
}
