<?php

//------------------------------------------------------------------------------
// File:         PCAP.pm
//
// Description:  Read CAP, PCAP and PCAPNG Packet Capture files
//
// Revisions:    2025-03-03 - P. Harvey Created
//
// References:   1) https://www.ietf.org/archive/id/draft-gharris-opsawg-pcap-01.html
//               2) https://www.ietf.org/archive/id/draft-ietf-opsawg-pcapng-02.html
//               3) https://formats.kaitai.io/microsoft_network_monitor_v2/
//
// PHP port of Image::ExifTool::PCAP 1.01 (reading only). Tag tables are generated
// into tables/PCAP.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PCAP
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Extract metadata from a PCAP file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PCAP file
    public static function ProcessPCAP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tagTablePtr = $type = $ts = $byteOrder = $verPos = null;

        // verify this is a valid PCAP file
        if ($raf->Read($buff, 24) != 24) return 0;

        if (preg_match('/^\xa1\xb2(\xc3\xd4|\x3c\x4d)\0.\0.|(\xd4\xc3|\x4d\x3c)\xb2\xa1.\0.\0/s', $buff, $m)) {
            $type = 'PCAP';
            $tmp = ($m[1] ?? '') ?: ($m[2] ?? '');
            $et->TSResol = ($tmp === "\xc3\xd4" or $tmp === "\xd4\xc3") ? 1e-6 : 1e-9;
            $byteOrder = preg_match('/^\xa1/', $buff) ? 'MM' : 'II';
            $verPos = 4;
        } elseif (preg_match('/^\x0a\x0d\x0d\x0a.{4}(\x1a\x2b\x3c\x4d|\x4d\x3c\x2b\x1a)/s', $buff, $m)) {
            $type = 'PCAPNG';
            $byteOrder = $m[1] === "\x1a\x2b\x3c\x4d" ? 'MM' : 'II';
            $verPos = 12;
        } elseif (preg_match('/^GMBU\0\x02/', $buff)) {
            // handle Windows Network Monitor 2.0 CAP files
            // http://web.archive.org/web/20240430011527/https://learn.microsoft.com/en-us/windows/win32/netmon2/capturefile-header-values
            ExifTool::$static_vars['OverrideFileDescription']['CAP'] = 'Microsoft Network Monitor Capture';
            $et->SetFileType('CAP', 'application/octet-stream', 'cap');
            $type = 'CAP';
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PCAP::Main');
            $a = Perl::unpack('x6v*', $buff);
            $et->WindowsTS = 1;
            $val = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d.%.3d', $a[1], $a[2], $a[4], $a[5], $a[6], $a[7], $a[8]);
            $et->HandleTag($tagTablePtr, 'LinkType', $a[0]);
            $et->HandleTag($tagTablePtr, 'TimeStamp', $val);
            if ($raf->Seek(40, 0) and $raf->Read($buff, 8) == 8) {
                [$off, $len] = Perl::unpack('V2', $buff);
                // extract comment
                if ($len < 1024 and $raf->Seek($off, 0) and $raf->Read($buff, $len) == $len) {
                    $et->HandleTag($tagTablePtr, 1, $buff);    // (NC) null terminated? Unicode?
                }
            }
            return 1;
        } else {
            return 0;
        }
        $et->SetFileType($type);
        ExifTool::SetByteOrder($byteOrder);
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PCAP::Main');
        $ver = ExifTool::Get16u($buff, $verPos) . '.' . ExifTool::Get16u($buff, $verPos + 2);
        $et->HandleTag($tagTablePtr, 'PCAPVersion', "$type $ver");
        $et->HandleTag($tagTablePtr, 'ByteOrder', $byteOrder);

        if ($type === 'PCAP') {
            $et->HandleTag($tagTablePtr, 'LinkType', ExifTool::Get16u($buff, 22));
            if ($raf->Read($buff, 8) != 8) {
                $et->Warn('Truncated PCAP file');
                return 1;
            }
            $ts = ExifTool::Get32u($buff, 0) + ExifTool::Get32u($buff, 4) * $et->TSResol;
            $et->HandleTag($tagTablePtr, 'TimeStamp', $ts);
            return 1;
        }
        // read through PCAPNG options for the SHB, IDB and get the timestamp from the first EPD
        $dir = 'SHB';   // Section Header Block
        for (;;) {
            if ($raf->Read($buff, 4) != 4) break;
            $opt = ExifTool::Get16u($buff, 0);
            $len = ExifTool::Get16u($buff, 2);
            if ($opt == 0) { // (end of options?)
                if ($raf->Read($buff, 20) != 20) break;
                $dirNum = ExifTool::Get32u($buff, 4);
                if ($dirNum == 1) {
                    $et->HandleTag($tagTablePtr, 'LinkType', ExifTool::Get16u($buff, 12));
                    $dir = 'IDB';   // Interface Description Block
                    continue;       // continue with IDB options
                } elseif ($dirNum == 6) { // EPD (Enhanced Packet Data)
                    $ts = 4294967296 * ExifTool::Get32u($buff, 16);
                    // (Perl reads into \$buff, a temporary reference, so $buff is unchanged)
                    $tmp = null;
                    if ($raf->Read($tmp, 4) != 4) break;
                    if ($et->TSResol) {
                        $ts = ($ts + ExifTool::Get32u($buff, 0)) * $et->TSResol + ($et->TSOff ?: 0);
                        $et->HandleTag($tagTablePtr, 'TimeStamp', $ts);
                    }
                }
                break;
            }
            $n = ($len + 3) & 0xfffc;   // round to an even 4 bytes
            if ($raf->Read($buff, $n) != $n) {
                $et->Warn("Error reading $dir options");
                break;
            }
            $id = $tagTablePtr[$opt] ? $opt : "$dir-$opt";
            $et->HandleTag($tagTablePtr, $id, null,
                DataPt:  $buff,
                DataPos: $raf->Tell() - $n,
                Size:    $len,
                MakeTagInfo: 1,
            );
        }
        return 1;
    }
}
