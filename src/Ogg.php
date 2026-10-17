<?php

//------------------------------------------------------------------------------
// File:         Ogg.pm
//
// Description:  Read Ogg meta information
//
// Revisions:    2011/07/13 - P. Harvey Created (split from Vorbis.pm)
//               2016/07/14 - PH Added Ogg Opus support
//
// References:   1) http://www.xiph.org/vorbis/doc/
//               2) http://flac.sourceforge.net/ogg_mapping.html
//               3) http://www.theora.org/doc/Theora.pdf
//
// PHP port of Image::ExifTool::Ogg 1.04 (reading only). %Image::ExifTool::Ogg::Main is for
// documentation only (generated into Vars\Ogg), and so is $MAX_PACKETS.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Ogg
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Process Ogg packet
    // Inputs: 0) ExifTool object ref, 1) data ref
    // Returns: 1 on success
    public static function ProcessPacket(ExifTool $et, $dataPt)
    {
        $rtnVal = 0;
        if (preg_match('/^(.)(vorbis|theora)/s', $dataPt, $m) or preg_match('/^(OpusHead|OpusTags)/', $dataPt, $m)) {
            [$tag, $type, $pos] = !empty($m[2]) ? [ord($m[1]), ucfirst($m[2]), 7] : [$m[1], 'Opus', 8];
            // this is an OGV file if it contains Theora video
            if ($type === 'Theora' and $et->FILE_TYPE === 'OGG') $et->OverrideFileType('OGV');
            if ($type === 'Opus' and $et->FILE_TYPE === 'OGG') $et->OverrideFileType('OPUS');
            $tagTablePtr = ExifTool::GetTagTable("Image::ExifTool::{$type}::Main");
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!($tagInfo and $tagInfo->SubDirectory)) return 0;
            $subdir = $tagInfo->SubDirectory;
            $dirInfo = [
                'DataPt'   => $dataPt,
                'DirName'  => $tagInfo->Name,
                'DirStart' => $pos,
            ];
            $table = ExifTool::GetTagTable($subdir['TagTable']);
            // set group1 so Theoris comments can be distinguised from Vorbis comments
            if ($type === 'Theora') $et->SET_GROUP1 = $type;
            if (!empty($subdir['ByteOrder'])) ExifTool::SetByteOrder($subdir['ByteOrder']);
            $rtnVal = $et->ProcessDirectory($dirInfo, $table);
            ExifTool::SetByteOrder('II');
            $et->SET_GROUP1 = null;     // (Perl delete)
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Extract information from an Ogg file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid Ogg file
    public static function ProcessOGG(ExifTool $et, array &$dirInfo)
    {
        // must first check for leading/trailing ID3 information
        if (!$et->DoneID3) {
            ExifTool::RequireModule('Image::ExifTool::ID3');
            if (ID3::ProcessID3($et, $dirInfo)) return 1;
        }
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        [$success, $page, $packets, $streams, $stream] = [0, 0, 0, 0, ''];
        $buff = $flag = $numFlac = null;
        $val = $streamPage = [];    // (Perl %val, %streamPage)

        for (;;) {
            // must read ahead to next page to see if it is a continuation
            // (this code would be a lot simpler if the continuation flag
            //  was on the leading instead of the trailing page!)
            if ($raf and $raf->Read($buff, 28) == 28) {
                // validate magic number
                if (!str_starts_with($buff, 'OggS')) {
                    if ($success) $et->Warn('Lost synchronization');
                    break;
                }
                if (!$success) {
                    // set file type and initialize on first page
                    $success = 1;
                    $et->SetFileType();
                    ExifTool::SetByteOrder('II');
                }
                $flag = ExifTool::Get8u($buff, 5);      // page flag
                $stream = ExifTool::Get32u($buff, 14);  // stream serial number
                if ($flag & 0x02) {
                    ++$streams;                 // count start-of-stream pages
                    $streamPage[$stream] = $page = 0;
                } else {
                    $page = $streamPage[$stream] ?? null;
                }
                if (!($flag & 0x01)) ++$packets;    // keep track of packet count
            } else {
                // all done unless we have to process our last packet
                if (!$val) break;
                $k = array_keys($val);
                sort($k, SORT_STRING);
                $stream = $k[0];                // take a stream
                $flag = 0;                      // no continuation
                $raf = null;                    // flag for done reading
            }

            if ($numFlac !== null) {
                // stop to process FLAC headers if we hit the end of file
                if (!$raf) break;
                --$numFlac; // one less header packet to read
            } else {
                // can finally process previous packet from this stream
                // unless this is a continuation page
                if (isset($val[$stream]) and !($flag & 0x01)) {
                    self::ProcessPacket($et, $val[$stream]);
                    unset($val[$stream]);
                    // only read the first $MAX_PACKETS packets from each stream
                    if ($packets > Vars\Ogg::$MAX_PACKETS * $streams or $raf === null) {
                        if (!$val) break;   // all done (success!)
                    }
                }
                // stop processing Ogg if we have scanned enough packets
                if ($packets > Vars\Ogg::$MAX_PACKETS * $streams and !$val) break;
            }

            // continue processing the current page
            $pageNum = ExifTool::Get32u($buff, 18);     // page sequence number
            $nseg = ExifTool::Get8u($buff, 26);         // number of segments
            // calculate total data length
            $dataLen = ExifTool::Get8u($buff, 27);
            if ($nseg) {
                if (!$raf) break;
                if ($raf->Read($buff, $nseg - 1) != $nseg - 1) break;
                $segs = Perl::unpack('C*', $buff);
                // could check that all these (but the last) are 255...
                foreach ($segs as $_) $dataLen += $_;
            }
            if ($page !== null) {
                if ($page == $pageNum) {
                    $streamPage[$stream] = ++$page;
                } else {
                    $et->Warn('Missing page(s) in Ogg file');
                    $page = null;
                    unset($streamPage[$stream]);
                }
            }
            // read page data
            if (!($raf and $raf->Read($buff, $dataLen) == $dataLen)) break;
            if ($verbose > 1) {
                fwrite($out, Perl::sprintf("Page %d, stream 0x%x, flag 0x%x (%d bytes)\n",
                       $pageNum, $stream, $flag, $dataLen));
                $et->VerboseDump($buff, DataPos: $raf->Tell() - $dataLen);
            }
            if (isset($val[$stream])) {
                $val[$stream] .= $buff;     // add this continuation page
            } elseif (!($flag & 0x01)) {    // ignore remaining pages of a continued packet
                // ignore the first page of any packet we aren't parsing
                if (preg_match('/^(.(vorbis|theora)|Opus(Head|Tags))/s', $buff)) {
                    $val[$stream] = $buff;      // save this page
                } elseif (preg_match('/^\x7fFLAC..(..)/s', $buff, $m)) {
                    $numFlac = Perl::unpack('n', $m[1])[0];
                    $val[$stream] = substr($buff, 9);
                }
            }
            if ($numFlac !== null) {
                // stop to process FLAC headers if we have them all
                if ($numFlac <= 0) break;
            } elseif (isset($val[$stream]) and $flag & 0x04) {
                // process Ogg packet now if end-of-stream bit is set
                self::ProcessPacket($et, $val[$stream]);
                unset($val[$stream]);
            }
        }
        if ($numFlac !== null and isset($val[$stream])) {
            // process FLAC headers as if it was a complete FLAC file
            ExifTool::RequireModule('Image::ExifTool::FLAC');
            $dirInfo2 = ['RAF' => new RandomAccess($val[$stream])];    // (Perl my %dirInfo)
            FLAC::ProcessFLAC($et, $dirInfo2);
        }
        return $success;
    }
}
