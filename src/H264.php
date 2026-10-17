<?php

//------------------------------------------------------------------------------
// File:         H264.pm
//
// Description:  Read meta information from H.264 video
//
// Revisions:    2010/01/31 - P. Harvey Created
//
// References:   1) http://www.itu.int/rec/T-REC-H.264/e (T-REC-H.264-200305-S!!PDF-E.pdf)
//               2) http://miffteevee.co.uk/documentation/development/H264Parser_8cpp-source.html
//               3) http://ffmpeg.org/
//               4) US Patent 2009/0052875 A1
//               5) European Patent (EP2 051 528A1) application no. 07792522.0 filed 08.08.2007
//               6) Dave Nicholson private communication
//               7) http://www.freepatentsonline.com/20050076039.pdf
//               8) Michael Reitinger private communication (RX100)
//
// Glossary:     RBSP = Raw Byte Sequence Payload
//
// PHP port of Image::ExifTool::H264 1.17 (reading only). Tag tables are generated
// into tables/H264.php, %convMake and $parsePictureTiming into Vars\H264.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class H264
{
    public static $VERSION = '1.17';

    //==============================================================================
    // Bitstream functions (used for H264 video)
    //
    // Member variables:
    //   Mask    = mask for next bit to read (0 when all data has been read)
    //   Pos     = byte offset of next word to read
    //   Word    = current data word
    //   Len     = total data length in bytes
    //   DataPt  = data pointer
    // (PHP: a BitStream is an array passed by reference, DataPt holds the data itself)
    //..............................................................................

    //------------------------------------------------------------------------------
    // Read next word from bitstream
    // Inputs: 0) BitStream ref
    // Returns: true if there is more data (and updates
    //          Mask, Pos and Word for first bit in next word)
    public static function ReadNextWord(array &$bstr)
    {
        $pos = $bstr['Pos'];
        if ($pos + 4 <= $bstr['Len']) {
            $bstr['Word'] = Perl::unpack("x$pos N", $bstr['DataPt'])[0];
            $bstr['Mask'] = 0x80000000;
            $bstr['Pos'] += 4;
        } elseif ($pos < $bstr['Len']) {
            $bytes = Perl::unpack("x$pos C*", $bstr['DataPt']);
            [$word, $mask] = [array_shift($bytes), 0x80];
            while ($bytes) {
                $word = ($word << 8) | array_shift($bytes);
                $mask <<= 8;
            }
            $bstr['Word'] = $word;
            $bstr['Mask'] = $mask;
            $bstr['Pos'] = $bstr['Len'];
        } else {
            return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Create a new BitStream object
    // Inputs: 0) data ref
    // Returns: BitStream ref, or null if data is empty
    public static function NewBitStream($dataPt)
    {
        $bstr = [
            'DataPt' => $dataPt,
            'Len'    => strlen($dataPt),
            'Pos'    => 0,
            'Mask'   => 0,
        ];
        if (!self::ReadNextWord($bstr)) $bstr = null;
        return $bstr;
    }

    //------------------------------------------------------------------------------
    // Get number of bits remaining in bit stream
    // Inputs: 0) BitStream ref
    // Returns: number of bits remaining
    public static function BitsLeft(array &$bstr)
    {
        $bits = 0;
        $mask = $bstr['Mask'];
        while ($mask) {
            ++$bits;
            $mask >>= 1;
        }
        return $bits + 8 * ($bstr['Len'] - $bstr['Pos']);
    }

    //------------------------------------------------------------------------------
    // Get integer from bitstream
    // Inputs: 0) BitStream ref, 1) number of bits
    // Returns: integer (and increments position in bitstream)
    public static function GetIntN(array &$bstr, $bits)
    {
        $val = 0;
        while ($bits--) {
            $val <<= 1;
            if ($bstr['Mask'] & $bstr['Word']) ++$val;
            if ($bstr['Mask'] >>= 1) continue;
            if (!self::ReadNextWord($bstr)) break;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Get Exp-Golomb integer from bitstream
    // Inputs: 0) BitStream ref
    // Returns: integer (and increments position in bitstream)
    public static function GetGolomb(array &$bstr)
    {
        // first, count the number of zero bits to get the integer bit width
        $count = 0;
        while (!($bstr['Mask'] & $bstr['Word'])) {
            ++$count;
            if ($bstr['Mask'] >>= 1) continue;
            if (!self::ReadNextWord($bstr)) break;
        }
        // then return the adjusted integer
        return self::GetIntN($bstr, $count + 1) - 1;
    }

    //------------------------------------------------------------------------------
    // Get signed Exp-Golomb integer from bitstream
    // Inputs: 0) BitStream ref
    // Returns: integer (and increments position in bitstream)
    public static function GetGolombS(array &$bstr)
    {
        $val = self::GetGolomb($bstr) + 1;
        return ($val & 1) ? -($val >> 1) : ($val >> 1);
    }

    // end bitstream functions
    //==============================================================================

    //------------------------------------------------------------------------------
    // Decode H.264 scaling matrices
    // Inputs: 0) BitStream ref
    // Reference: http://ffmpeg.org/
    public static function DecodeScalingMatrices(array &$bstr)
    {
        if (self::GetIntN($bstr, 1)) {
            for ($i = 0; $i < 8; ++$i) {
                $size = $i < 6 ? 16 : 64;
                if (!self::GetIntN($bstr, 1)) continue;
                [$last, $next] = [8, 8];
                for ($j = 0; $j < $size; ++$j) {
                    if ($next) $next = ($last + self::GetGolombS($bstr)) & 0xff;
                    if (!($j or $next)) break;
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Parse H.264 sequence parameter set RBSP (ref 1)
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) data ref
    // Notes: All this just to get the image size!
    public static function ParseSeqParamSet(ExifTool $et, TagTable $tagTablePtr, $dataPt)
    {
        // initialize our bitstream object
        $bstr = self::NewBitStream($dataPt);
        if (!$bstr) return;
        $t = $i = $j = $n = null;
        // the messy nature of H.264 encoding makes it difficult to use
        // data-driven structure parsing, so I code it explicitly (yuck!)
        $t = self::GetIntN($bstr, 8);       // profile_idc
        self::GetIntN($bstr, 16);           // constraints and level_idc
        self::GetGolomb($bstr);             // seq_parameter_set_id
        if ($t >= 100) { // (ref b)
            $t = self::GetGolomb($bstr);    // chroma_format_idc
            if ($t == 3) {
                self::GetIntN($bstr, 1);    // separate_colour_plane_flag
                $n = 12;
            } else {
                $n = 8;
            }
            self::GetGolomb($bstr);         // bit_depth_luma_minus8
            self::GetGolomb($bstr);         // bit_depth_chroma_minus8
            self::GetIntN($bstr, 1);        // qpprime_y_zero_transform_bypass_flag
            self::DecodeScalingMatrices($bstr);
        }
        self::GetGolomb($bstr);             // log2_max_frame_num_minus4
        $t = self::GetGolomb($bstr);        // pic_order_cnt_type
        if ($t == 0) {
            self::GetGolomb($bstr);         // log2_max_pic_order_cnt_lsb_minus4
        } elseif ($t == 1) {
            self::GetIntN($bstr, 1);        // delta_pic_order_always_zero_flag
            self::GetGolomb($bstr);         // offset_for_non_ref_pic
            self::GetGolomb($bstr);         // offset_for_top_to_bottom_field
            $n = self::GetGolomb($bstr);    // num_ref_frames_in_pic_order_cnt_cycle
            for ($i = 0; $i < $n; ++$i) {
                self::GetGolomb($bstr);     // offset_for_ref_frame[i]
            }
        }
        self::GetGolomb($bstr);             // num_ref_frames
        self::GetIntN($bstr, 1);            // gaps_in_frame_num_value_allowed_flag
        $w = self::GetGolomb($bstr);        // pic_width_in_mbs_minus1
        $h = self::GetGolomb($bstr);        // pic_height_in_map_units_minus1
        $f = self::GetIntN($bstr, 1);       // frame_mbs_only_flag
        if (!$f) self::GetIntN($bstr, 1);   // mb_adaptive_frame_field_flag
        self::GetIntN($bstr, 1);            // direct_8x8_inference_flag
        // convert image size to pixels
        $w = ($w + 1) * 16;
        $h = (2 - $f) * ($h + 1) * 16;
        // account for cropping (if any)
        $t = self::GetIntN($bstr, 1);       // frame_cropping_flag
        if ($t) {
            $m = 4 - $f * 2;
            $w -=  4 * self::GetGolomb($bstr);  // frame_crop_left_offset
            $w -=  4 * self::GetGolomb($bstr);  // frame_crop_right_offset
            $h -= $m * self::GetGolomb($bstr);  // frame_crop_top_offset
            $h -= $m * self::GetGolomb($bstr);  // frame_crop_bottom_offset
        }
        // quick validity checks (just in case)
        if (!$bstr['Mask']) return;
        if ($w >= 160 and $w <= 4096 and $h >= 120 and $h <= 3072) {
            $et->HandleTag($tagTablePtr, 'ImageWidth', $w);
            $et->HandleTag($tagTablePtr, 'ImageHeight', $h);
            // (whew! -- so much work just to get ImageSize!!)
        }
        // return now unless interested in picture timing information
        if (!Vars\H264::$parsePictureTiming) return;

        // parse vui parameters if they exist
        if (!self::GetIntN($bstr, 1)) return;   // vui_parameters_present_flag
        $t = self::GetIntN($bstr, 1);       // aspect_ratio_info_present_flag
        if ($t) {
            $t = self::GetIntN($bstr, 8);   // aspect_ratio_idc
            if ($t == 255) {                // Extended_SAR ?
                self::GetIntN($bstr, 32);   // sar_width/sar_height
            }
        }
        $t = self::GetIntN($bstr, 1);       // overscan_info_present_flag
        if ($t) self::GetIntN($bstr, 1);    // overscan_appropriate_flag
        $t = self::GetIntN($bstr, 1);       // video_signal_type_present_flag
        if ($t) {
            self::GetIntN($bstr, 4);        // video_format/video_full_range_flag
            $t = self::GetIntN($bstr, 1);   // colour_description_present_flag
            if ($t) self::GetIntN($bstr, 24);   // colour_primaries/transfer_characteristics/matrix_coefficients
        }
        $t = self::GetIntN($bstr, 1);       // chroma_loc_info_present_flag
        if ($t) {
            self::GetGolomb($bstr);         // chroma_sample_loc_type_top_field
            self::GetGolomb($bstr);         // chroma_sample_loc_type_bottom_field
        }
        $t = self::GetIntN($bstr, 1);       // timing_info_present_flag
        if ($t) {
            if (self::BitsLeft($bstr) < 65) return;
            $et->VUI_units = self::GetIntN($bstr, 32); // num_units_in_tick
            $et->VUI_scale = self::GetIntN($bstr, 32); // time_scale
            self::GetIntN($bstr, 1);        // fixed_frame_rate_flag
        }
        $hard = null;
        for ($j = 0; $j < 2; ++$j) {
            $t = self::GetIntN($bstr, 1);   // nal_/vcl_hrd_parameters_present_flag
            if ($t) {
                $et->VUI_hard = 1;
                $hard = 1;
                $n = self::GetGolomb($bstr);    // cpb_cnt_minus1
                self::GetIntN($bstr, 8);    // bit_rate_scale/cpb_size_scale
                for ($i = 0; $i <= $n; ++$i) {
                    self::GetGolomb($bstr); // bit_rate_value_minus1[SchedSelIdx]
                    self::GetGolomb($bstr); // cpb_size_value_minus1[SchedSelIdx]
                    self::GetIntN($bstr, 1);    // cbr_flag[SchedSelIdx]
                }
                self::GetIntN($bstr, 5);    // initial_cpb_removal_delay_length_minus1
                $et->VUI_clen = self::GetIntN($bstr, 5);   // cpb_removal_delay_length_minus1
                $et->VUI_dlen = self::GetIntN($bstr, 5);   // dpb_output_delay_length_minus1
                $et->VUI_toff = self::GetIntN($bstr, 5);   // time_offset_length
            }
        }
        if ($hard) self::GetIntN($bstr, 1); // low_delay_hrd_flag
        $et->VUI_pic = self::GetIntN($bstr, 1);    // pic_struct_present_flag
        // (don't yet decode the rest of the vui data)
    }

    //------------------------------------------------------------------------------
    // Parse H.264 picture timing SEI message (payload type 1) (ref 1)
    // Inputs: 0) ExifTool ref, 1) data ref
    // Notes: this routine is for test purposes only, and not called unless the
    //        $parsePictureTiming flag is set
    public static function ParsePictureTiming(ExifTool $et, $dataPt)
    {
        $bstr = self::NewBitStream($dataPt);
        if (!$bstr) return;
        $i = $t = $n = null;
        // the specification is very odd on this point: the following delays
        // exist if the VUI hardware parameters are present, or if
        // "determined by the application, by some means not specified" -- WTF??
        if ($et->VUI_hard) {
            self::GetIntN($bstr, $et->VUI_clen + 1);   // cpb_removal_delay
            self::GetIntN($bstr, $et->VUI_dlen + 1);   // dpb_output_delay
        }
        if ($et->VUI_pic) {
            $t = self::GetIntN($bstr, 4);   // pic_struct
            // determine NumClockTS ($n)
            $n = [0=>1, 1=>1, 2=>1, 3=>2, 4=>2, 5=>3, 6=>3, 7=>2, 8=>3][$t] ?? null;
            if (!$n) return;
            for ($i = 0; $i < $n; ++$i) {
                $t = self::GetIntN($bstr, 1);   // clock_timestamp_flag[i]
                if (!$t) continue;
                $nu = $s = $m = $h = $o = null;
                self::GetIntN($bstr, 2);    // ct_type
                $nu = self::GetIntN($bstr, 1);  // nuit_field_based_flag
                self::GetIntN($bstr, 5);    // counting_type
                $t = self::GetIntN($bstr, 1);   // full_timestamp_flag
                self::GetIntN($bstr, 1);    // discontinuity_flag
                self::GetIntN($bstr, 1);    // cnt_dropped_flag
                self::GetIntN($bstr, 8);    // n_frames
                if ($t) {
                    $s = self::GetIntN($bstr, 6);   // seconds_value
                    $m = self::GetIntN($bstr, 6);   // minutes_value
                    $h = self::GetIntN($bstr, 5);   // hours_value
                } else {
                    $t = self::GetIntN($bstr, 1);   // seconds_flag
                    if ($t) {
                        $s = self::GetIntN($bstr, 6);   // seconds_value
                        $t = self::GetIntN($bstr, 1);   // minutes_flag
                        if ($t) {
                            $m = self::GetIntN($bstr, 6);   // minutes_value
                            $t = self::GetIntN($bstr, 1);   // hours_flag
                            if ($t) $h = self::GetIntN($bstr, 5);   // hours_value
                        }
                    }
                }
                if ($et->VUI_toff) {
                    $o = self::GetIntN($bstr, $et->VUI_toff);  // time_offset
                }
                break;  // only parse the first clock timestamp found
            }
        }
    }

    //------------------------------------------------------------------------------
    // Process H.264 Supplementary Enhancement Information (ref 1/PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 if we processed payload type 5
    // Payload types:
    //   0 - buffer period
    //   1 - pic timing
    //   2 - pan scan rect
    //   3 - filler payload
    //   4 - user data registered itu t t35
    //   5 - user data unregistered
    //   6 - recovery point
    //   7 - dec ref pic marking repetition
    //   8 - spare pic
    //   9 - sene info
    //  10 - sub seq info
    //  11 - sub seq layer characteristics
    //  12 - sub seq characteristics
    //  13 - full frame freeze
    //  14 - full frame freeze release
    //  15 - full frame snapshot
    //  16 - progressive refinement segment start
    //  17 - progressive refinement segment end
    //  18 - motion constrained slice group set
    public static function ProcessSEI(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $dataPt = $dirInfo['DataPt'];
        $end = strlen($dataPt);
        $pos = 0;
        $type = $size = $index = $t = null;

        // scan through SEI payload for type 5 (the unregistered user data)
        for (;;) {
            $type = 0;
            for (;;) {
                if ($pos >= $end) return 0;
                $t = ExifTool::Get8u($dataPt, $pos++);  // payload type
                $type += $t;
                if ($t != 255) break;
            }
            if ($type == 0x80) return 0;    // terminator (ref PH - maybe byte alignment bits?)
            $size = 0;
            for (;;) {
                if ($pos >= $end) return 0;
                $t = ExifTool::Get8u($dataPt, $pos++);  // payload data length
                $size += $t;
                if ($t != 255) break;
            }
            if ($pos + $size > $end) return 0;
            $et->VPrint(1, "    (SEI type $type)\n");
            if ($type == 1) {                   // picture timing information
                if (Vars\H264::$parsePictureTiming) {
                    $buff = substr($dataPt, $pos, $size);
                    self::ParsePictureTiming($et, $dataPt);
                }
            } elseif ($type == 5) {             // unregistered user data
                break; // exit loop to process user data now
            }
            $pos += $size;
        }

        // look for our 16-byte UUID
        // - plus "MDPM" for "ModifiedDVPackMeta"
        // - plus "GA94" for closed-caption data (currently not decoded)
        if (!($size > 20 and substr($dataPt, $pos, 20) ===
            "\x17\xee\x8c\x60\xf8\x4d\x11\xd9\x8c\xd6\x08\0\x20\x0c\x9a\x66MDPM")) return 0;
//
// parse the MDPM records in the UUID 17ee8c60f84d11d98cd60800200c9a66
// unregistered user data payload (ref PH)
//
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::H264::MDPM');
        $oldIndent = $et->INDENT;
        $et->INDENT .= '| ';
        $end = $pos + $size;    // end of payload
        $pos += 20;             // skip UUID + "MDPM"
        $num = ExifTool::Get8u($dataPt, $pos++);    // get entry count
        $lastTag = 0;
        if ($et->Options('Verbose')) $et->VerboseDir('MDPM', $num);
        // walk through entries in the MDPM payload
        for ($index = 0; $index < $num and $pos < $end; ++$index) {
            $tag = ExifTool::Get8u($dataPt, $pos);
            if ($tag <= $lastTag) { // should be in numerical order (PH)
                $et->Warn('Entries in MDPM directory are out of sequence');
                break;
            }
            $lastTag = $tag;
            $buff = substr($dataPt, $pos + 1, 4);
            $from = null;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo) {
                // use our own print conversion for Unknown tags
                if ($tagInfo->Unknown and !$tagInfo->SetPrintConv) {
                    $tagInfo->PrintConv = static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
                        return Perl::sprintf('0x%.8x', Perl::unpack('N', $val)[0] ?? null);
                    };
                    $tagInfo->SetPrintConv = 1;
                }
                // combine with next value(s) if necessary
                $combine = $tagTablePtr[$tag] instanceof TagInfo ? $tagTablePtr[$tag]->Combine : null;
                while ($combine) {
                    if ($pos + 5 >= $end) break;
                    $t = ExifTool::Get8u($dataPt, $pos + 5);
                    if ($t != $lastTag + 1) break;  // must be consecutive tag ID's
                    $pos += 5;
                    $buff .= substr($dataPt, $pos + 1, 4);
                    if ($from === null) $from = $index;
                    ++$index;
                    ++$lastTag;
                    --$combine;
                }
                $et->HandleTag($tagTablePtr, $tag, null,
                    TagInfo : $tagInfo,
                    DataPt  : $buff,
                    Size    : strlen($buff),
                    Index   : $from !== null ? "$from-$index" : $index,
                );
            }
            $pos += 5;
        }
        $et->INDENT = $oldIndent;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from H.264 video stream
    // Inputs: 0) ExifTool ref, 1) data ref
    // Returns: 0 = done parsing, 1 = we want to parse more of these
    public static function ParseH264Video(ExifTool $et, $dataPt)
    {
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::H264::Main');
        $parseNalUnit = [0x06 => 1, 0x07 => 1];    // NAL unit types to parse
        $foundUserData = null;
        $len = strlen($dataPt);
        $pos = 0;
        $p = 0;     // (pos($$dataPt))
        while ($pos < $len) {
            $nextPos = $end = null;
            // find start of next NAL unit
            if (preg_match('/(\x00{2,3}\x01)/', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
                $nextPos = $p = $m[0][1] + strlen($m[0][0]);
                $end = $nextPos - strlen($m[1][0]);
                if (!$pos) {
                    $pos = $nextPos;
                    continue;
                }
            } else {
                $p = 0;     // (failed match resets pos)
                if (!$pos) break;
                $nextPos = $end = $len;
            }
            if ($pos >= $len) break;
            // parse NAL unit from $pos to $end
            $nal_unit_type = ExifTool::Get8u($dataPt, $pos);
            ++$pos;
            // check forbidden_zero_bit
            if ($nal_unit_type & 0x80) {
                $et->Warn('H264 forbidden bit error');
                break;
            }
            $nal_unit_type &= 0x1f;
            // ignore this NAL unit unless we will parse it
            if (!(!empty($parseNalUnit[$nal_unit_type]) or $verbose)) {
                $pos = $nextPos;
                continue;
            }
            // read NAL unit (and convert all 0x000003's to 0x0000 as per spec.)
            $buff = '';
            $p = $pos + 1;
            while (preg_match('/\x00\x00\x03/', $dataPt, $m, PREG_OFFSET_CAPTURE, $p)) {
                $p = $m[0][1] + 3;
                if ($p > $end) break;
                $buff .= substr($dataPt, $pos, $p - 1 - $pos);
                $pos = $p;
            }
            $buff .= substr($dataPt, $pos, $end - $pos);
            if ($verbose > 1) {
                fwrite($out, Perl::sprintf("  NAL Unit Type: 0x%x (%d bytes)\n", $nal_unit_type, strlen($buff)));
                $et->VerboseDump($buff);
            }
            $p = $pos = $nextPos;

            if ($nal_unit_type == 0x06) {       // sei_rbsp (supplemental enhancement info)

                if ($et->GotNAL06) {
                    // process only the first SEI unless ExtractEmbedded is set
                    if (!$et->Options('ExtractEmbedded')) continue;
                    $et->DOC_NUM = $et->GotNAL06;
                }
                $dirInfo = ['DataPt' => $buff];
                $foundUserData = self::ProcessSEI($et, $dirInfo);
                $et->DOC_NUM = null;
                // keep parsing SEI's until we find the user data
                if (!$foundUserData) continue;
                $et->GotNAL06 = ($et->GotNAL06 ?: 0) + 1;

            } elseif ($nal_unit_type == 0x07) {  // sequence_parameter_set_rbsp

                // process this NAL unit type only once
                if ($et->GotNAL07) continue;
                $et->GotNAL07 = 1;
                self::ParseSeqParamSet($et, $tagTablePtr, $buff);
            }
            // we were successful, so don't parse this NAL unit type again
            unset($parseNalUnit[$nal_unit_type]);
        }
        // parse one extra H264 frame if we didn't find the user data in this one
        // (Panasonic cameras don't put the SEI in the first frame)
        if ($foundUserData or $et->ParsedH264) return 0;
        $et->ParsedH264 = 1;
        return 1;
    }
}
