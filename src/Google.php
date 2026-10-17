<?php

//------------------------------------------------------------------------------
// File:         Google.pm
//
// Description:  Google maker notes and XMP tags
//
// Revisions:    2025-09-17 - P. Harvey Created
//
// References:   1) https://github.com/jakiki6/ruminant/blob/master/ruminant/modules/images.py
//
// PHP port of Image::ExifTool::Google 1.01 (reading only). Tag tables are generated into
// tables/Google.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Google
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Read HDRP text-format maker note version 2
    // Inputs: 0) ExifTool ref, 1) data ref
    public static function ProcessHDRPMakerNote(ExifTool $et, $dataPt)
    {
        $tag = $pos = null;
        $one = null;    // (Perl $1)
        $p = 0;         // (Perl pos($$dataPt))
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::Google::HDRPMakerNote');
        for (;;) {
            $end = $last = null;
            if (preg_match('/^ ?([A-Z].*)$/m', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
                $p = $m[0][1] + strlen($m[0][0]);
                $one = $m[1][0];
                $end = $p - strlen($one);
            } else {
                $p = 0;
                $end = strlen($dataPt);
                $last = 1;
            }
            if ($tag !== null) {
                $len = $end - ($pos + 1);
                if ($len <= 0) break;   // (just to be safe)
                $et->HandleTag($tagTbl, $tag, substr($dataPt, $pos + 1, $len), MakeTagInfo: 1);
            }
            if ($last) break;
            $tag = $one;
            if (!(str_contains($tag, ':') or preg_match('/^\w+$/', $tag))) {
                $et->HandleTag($tagTbl, 'ProcessingNotes', $tag);
                $tag = null;
                continue;
            }
            $pos = $p;
            if (preg_match('/( \(base64\))?: ?(.*)/', $tag, $m2, PREG_OFFSET_CAPTURE)) {
                $tag = substr($tag, 0, $m2[0][1]) . substr($tag, $m2[0][1] + strlen($m2[0][0]));
                $dat = $m2[2][0];
                if ($dat !== '' and $dat !== '0') {
                    if (isset($m2[1]) and $m2[1][1] >= 0 and $m2[1][0] !== '') $dat = XMP::DecodeBase64($dat);
                    $et->HandleTag($tagTbl, $tag, $dat, MakeTagInfo: 1);
                    $tag = null;
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Decode HDRP maker notes (ref https://github.com/jakiki6/ruminant/blob/master/ruminant/modules/images.py)
    // Inputs: 0) ExifTool ref, 1) base64-encoded string, 2) tagInfo ref
    // Returns: reference to decoded+decrypted+gunzipped data
    // - also extracts protobuf info as separate tags when Unknown used if $$tagInfo{IsProtobuf} is set
    public static function ProcessHDRP(ExifTool $et, array &$dirInfo, ?TagTable $tagTbl = null)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataPt = Perl::str($dataPt);
        $tagInfo = $dirInfo['TagInfo'] ?? null;
        $tagName = $tagInfo ? $tagInfo->Name : '';
        $verbose = $et->Options('Verbose');
        $fast = $et->Options('FastScan') ?: 0;
        $ver = $valPt = null;

        if ($fast > 1) return null;

        if (!empty($dirInfo['DirStart'])) {
            $dataPt = substr($dataPt, $dirInfo['DirStart'], (int) $dirInfo['DirLen']);
        }
        if (preg_match('/^HDRP[\x02\x03]/', $dataPt)) {
            $valPt = $dataPt;
        } else {
            $et->VerboseDir($tagName, null, strlen($dataPt));
            if ($verbose > 2) $et->VerboseDump($dataPt);
            $valPt = XMP::DecodeBase64($dataPt)->value;
            if ($verbose > 2) {
                $et->VerboseDir("Base64-decoded $tagName", null, strlen($valPt));
                $et->VerboseDump($valPt);
            }
        }
        if (preg_match('/^HDRP([\x02\x03])/', $valPt, $m)) {
            $valPt = substr($valPt, 5);
            $ver = ord($m[1]);
        } else {
            $et->Warn('Unrecognized HDRP format');
            return null;
        }
        $pad = (8 - (strlen($valPt) % 8)) & 0x07;
        if ($pad) $valPt .= str_repeat("\0", $pad);     // pad to an even 8 bytes
        $words = Perl::unpack('V*', $valPt);
        // my $key = 0x2515606b4a7791cd;
        [$hi, $lo] = [0x2515606b, 0x4a7791cd];
        $i = 0;
        while ($i < count($words)) {
            // (messy, but handle all 64-bit arithmetic with 32-bit backward
            //  compatibility, so no bit operations on any number > 0xffffffff)
            // rotate the key for each new 64-bit word
            // $key ^= $key >> 12;
            $lo ^= $lo >> 12 | ($hi & 0xfff) << 20;
            $hi ^= $hi >> 12;
            // $key ^= ($key << 25) & 0xffffffffffffffff;
            $hi ^= ($hi & 0x7f) << 25 | $lo >> 7;
            $lo ^= ($lo & 0x7f) << 25;
            // $key ^= ($key >> 27) & 0xffffffffffffffff;
            $lo ^= $lo >> 27 | ($hi & 0x7ffffff) << 5;
            $hi ^= $hi >> 27;
            // $key = ($key * 0x2545f4914f6cdd1d) & 0xffffffffffffffff;
            // (multiply using 16-bit math to avoid overflowing 32-bit integers)
            $a = Perl::unpack('n*', Perl::pack('N*', $hi, $lo));
            $b = [0x2545, 0xf491, 0x4f6c, 0xdd1d];
            $c = array_fill(0, 7, 0);
            for ($j = 0; $j < 4; ++$j) {
                for ($k = 0; $k < 4; ++$k) {
                    $c[$j + $k] += $a[$j] * $b[$k];
                }
            }
            // (we will only retain the low 64-bits of the key, so
            //  don't bother finishing the calculation of the upper bits)
            for ($j = 6; $j >= 3; --$j) {
                while ($c[$j] > 0xffffffff) {
                    ++$c[$j - 2];
                    $c[$j] -= 4294967296;
                }
                $c[$j - 1] += $c[$j] >> 16;
                $c[$j] &= 0xffff;
            }
            $hi = ($c[3] << 16) + $c[4];
            $lo = ($c[5] << 16) + $c[6];
            // apply the key to this 64-bit word
            $words[$i++] ^= $lo;
            $words[$i++] ^= $hi;
        }
        $result = null;
        $val = Perl::pack('V*', ...$words);
        if ($pad) $val = substr($val, 0, -$pad);    // remove padding
        if ($verbose > 2) {
            $et->VerboseDir("Decrypted $tagName", null, strlen($val));
            $et->VerboseDump($val);
        }
        // (IO::Uncompress::Gunzip::gunzip: Transparent mode copies data without a gzip header)
        // (ponytail: gzdecode() also rejects a bad CRC, which gunzip() ignores by default)
        $buff = str_starts_with($val, "\x1f\x8b") ? @gzdecode($val) : $val;
        if ($buff !== false) {
            if ($verbose > 2) {
                $et->VerboseDir("Gunzipped $tagName", null, strlen($buff));
                $et->VerboseDump($buff);
            }
            if ($ver == 3 or ($tagInfo and $tagInfo->IsProtobuf)) {
                $subdirInfo = [
                    'DataPt' => $buff,
                    'DirName' => $tagName,
                ];
                ExifTool::RequireModule('Image::ExifTool::Protobuf');
                Protobuf::ProcessProtobuf($et, $subdirInfo, $tagTbl);
            } else {
                self::ProcessHDRPMakerNote($et, $buff);
            }
            $result = new ScalarRef($buff);
        } else {
            // (ponytail: zlib doesn't give the reason that $GunzipError holds in Perl)
            $et->Warn('Error inflating stream: inflation error');
        }
        return $result;
    }
}
