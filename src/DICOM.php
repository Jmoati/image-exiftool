<?php

//------------------------------------------------------------------------------
// File:         DICOM.pm
//
// Description:  Read DICOM and ACR-NEMA medical images
//
// Revisions:    2005/11/09 - P. Harvey Created
//               2009/11/19 - P. Harvey Added private GE tags from ref 4
//               2009/12/11 - P. Harvey Updated to DICOM 2008 spec
//               2010/04/08 - P. Harvey Updated to DICOM 2009 spec
//
// References:   1) http://medical.nema.org/
//               2) http://www.sph.sc.edu/comd/rorden/dicom.html
//               3) http://www.dclunie.com/
//               4) http://www.gehealthcare.com/usen/interoperability/dicom/docs/2258357r3.pdf
//
// PHP port of Image::ExifTool::DICOM 1.25 (reading only). Tag tables are generated
// into tables/DICOM.php; %uid, %dicomFormat, %vr32 and %implicitVR are in Vars\DICOM.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class DICOM
{
    public static $VERSION = '1.25';

    //------------------------------------------------------------------------------
    // Extract information from a DICOM (DCM) image
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid DICOM file
    public static function ProcessDICOM(ExifTool $et, array &$dirInfo)
    {
        $dicomFormat = Vars\DICOM::$dicomFormat;
        $vr32 = Vars\DICOM::$vr32;
        $implicitVR = Vars\DICOM::$implicitVR;
        $raf = $dirInfo['RAF'];
        $unknown = $et->Options('Unknown');
        $verbose = $et->Options('Verbose');
        $hdr = $buff = $implicit = $vr = $len = null;
//
// identify the DICOM or ACR-NEMA file
//
        if ($raf->Read($hdr, 12) != 12) return 0;   // save for ACR identification later
        if (!$raf->Seek(128, 0)) return 0;          // skip to end of DICM header
        if ($raf->Read($buff, 4) != 4) return 0;    // read signature
        if ($buff === 'DICM') {
            // file meta information transfer syntax is explicit little endian
            ExifTool::SetByteOrder('II');
            $et->SetFileType('DICOM');
        } else {
            // test for a RAW DCM image (ACR-NEMA format, ie. no header)
            foreach (['II', 'MM', ''] as $_) {
                if (!$_) return 0;  // no luck identifying the syntax
                ExifTool::SetByteOrder($_);
                $g = ExifTool::Get16u($hdr, 0);
                // expect group number to be small and even
                if ($g < 2 or $g > 8 or ($g & 0x01)) continue;
                $e = ExifTool::Get16u($hdr, 2);
                if ($e > 0x20) continue;        // expect a low element number at start
                $vr = substr($hdr, 4, 2);       // look for explicit VR
                if (preg_match('/^[A-Z]{2}$/', $vr)) {
                    $implicit = 0;
                    if (!empty($vr32[$vr])) {
                        if (ExifTool::Get16u($hdr, 6) != 0) continue;  // must be 2 zero bytes
                        $len = ExifTool::Get32u($hdr, 8);
                    } else {
                        if ($e == 0 and $vr !== 'UL') continue;     // group length must be UL
                        $len = ExifTool::Get16u($hdr, 6);
                    }
                } else {
                    $implicit = 1;
                    $len = ExifTool::Get32u($hdr, 4);
                }
                if ($e == 0 and $len != 4) continue;    // group length value must be 4 bytes
                if ($len > 64) continue;    // first element shouldn't be too long
                break;  // success!
            }
            if (!$raf->Seek(0, 0)) return 0;    // rewind to start of file
            $et->SetFileType('ACR');
        }
//
// process the meta information
//
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::DICOM::Main');
        $pos = $raf->Tell();
        $err = 1;
        $transferSyntax = $group2end = null;
        for (;;) {
            if ($raf->Read($buff, 8) != 8) {
                $err = 0;
                break;
            }
            $pos += 8;
            $group = ExifTool::Get16u($buff, 0);
            // implement the transfer syntax at the end of the group 2 data
            if ($transferSyntax and ($group != 0x0002 or
                ($group2end and $pos > $group2end)))
            {
                // 1.2.840.10008.1.2   = implicit VR little endian
                // 1.2.840.10008.1.2.2 = explicit VR big endian
                // 1.2.840.10008.1.2.x = explicit VR little endian
                // 1.2.840.10008.1.2.1.99 = deflated
                if (!preg_match('/^1\.2\.840\.10008\.1\.2(\.\d+)?(\.\d+)?/', Perl::str($transferSyntax), $m)) {
                    $et->Warn("Unrecognized transfer syntax $transferSyntax");
                    break;
                }
                $m1 = $m[1] ?? '';
                $m2 = $m[2] ?? '';
                if (!$m1) {
                    $implicit = 1;
                } elseif ($m1 === '.2') {
                    ExifTool::SetByteOrder('MM');
                    $group = ExifTool::Get16u($buff, 0);    // must get group again
                } elseif ($m1 === '.1' and $m2 and $m2 === '.99') {
                    // inflate compressed data stream
                    // must use undocumented zlib feature to disable zlib header information
                    // because DICOM deflated data doesn't have the zlib header (ref 3)
                    $inflate = @inflate_init(ZLIB_ENCODING_RAW);
                    if ($inflate) {
                        if (!$raf->Seek(-8, 1)) break;
                        $data = '';
                        while ($raf->Read($buff, 65536)) {
                            $buf = @inflate_add($inflate, $buff);
                            $stat = $buf === false ? false : inflate_get_status($inflate);
                            if ($stat === ZLIB_OK or $stat === ZLIB_STREAM_END or $stat === ZLIB_BUF_ERROR) {
                                $data .= $buf;
                                if ($stat === ZLIB_STREAM_END) break;
                            } else {
                                $et->Warn('Error inflating compressed data stream');
                                return 1;
                            }
                        }
                        if (strlen($data) < 8) break;
                        // create new RAF object from inflated data stream
                        $raf = new RandomAccess($data);
                        // re-read start of stream (now decompressed)
                        if ($raf->Read($buff, 8) != 8) break;
                        $group = ExifTool::Get16u($buff, 0);
                    } else {
                        $et->Warn('Error initializing inflation');
                        return 1;
                    }
                }
                $transferSyntax = null;
            }
            $element = ExifTool::Get16u($buff, 2);
            $tag = Perl::sprintf('%.4X,%.4X', $group, $element);

            if ($implicit or !empty($implicitVR[$tag])) {
                // treat everything as string if implicit VR because it
                // isn't worth it to generate the necessary VR lookup tables
                // for the thousands of defined data elements
                $vr = '';       // no VR (treat everything as string)
                $len = ExifTool::Get32u($buff, 4);
            } else {
                $vr = substr($buff, 4, 2);
                if (!preg_match('/^[A-Z]{2}$/', $vr)) break;
                if (!empty($vr32[$vr])) {
                    if ($raf->Read($buff, 4) != 4) break;
                    $pos += 4;
                    $len = ExifTool::Get32u($buff, 0);
                    if ($vr === 'SQ') $len = 0;     // just recurse into sequences
                } else {
                    $len = ExifTool::Get16u($buff, 6);
                }
            }
            if ($len == 0xffffffff) {
                $len = 0;   // don't read value if undefined length
                if ($verbose) {
                    // start list of items in verbose output
                    $et->VPrint(0, "{$et->INDENT}+ [List of items]\n");
                    $et->INDENT .= '| ';
                }
            }
            // read the element value
            if ($len) {
                if ($raf->Read($buff, $len) != $len) break;
                $pos += $len;
            } else {
                $buff = '';
            }

            // handle tags not found in the table
            $tagInfo = $tagTablePtr[$tag];
            if (!$tagInfo) {
                // accept tag ID's with "x" for a wildcard in the following patterns:
                // '60xx,1203', '0020,31xx', '0028,04x2', '1000,xxx0', '1010,xxxx'
                $xx = null;
                if ((($xx = preg_replace('/^(..)../', '${1}xx', $tag, 1)) !== $tag and $tagTablePtr[$xx]) or
                    (($xx = preg_replace('/..$/', 'xx', $tag, 1)) !== $tag and $tagTablePtr[$xx]) or
                    (($xx = preg_replace('/.(.)$/', 'x${1}', $tag, 1)) !== $tag and $tagTablePtr[$xx]) or
                    (($xx = preg_replace('/...(.)$/', 'xxx${1}', $tag, 1)) !== $tag and $tagTablePtr[$xx]) or
                    (($xx = preg_replace('/....$/', 'xxxx', $tag, 1)) !== $tag and $tagTablePtr[$xx]))
                {
                    $tag = $xx;
                    $tagInfo = $tagTablePtr[$xx];
                } elseif ($unknown) {
                    // create tag info hash for unknown elements
                    if ($element == 0) {    // element zero is group length
                        $tagInfo = [
                            'Name' => Perl::sprintf('Group%.4X_Length', $group),
                            'Description' => Perl::sprintf('Group %.4X Length', $group),
                        ];
                    } else {
                        $tagInfo = [
                            'Name' => Perl::sprintf('DICOM_%.4X_%.4X', $group, $element),
                            'Description' => Perl::sprintf('DICOM %.4X,%.4X', $group, $element),
                        ];
                    }
                    $tagInfo['Unknown'] = 1;
                    $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
            }
            // get VR from our tag information if implicit
            if ($tagInfo and !$vr) $vr = $tagInfo->VR ?: '  ';

            if ($element == 0) {
                $vr = 'UL'; // group length element is always unsigned long
            }
            $val = null;
            $format = $dicomFormat[$vr] ?? null;
            // remove trailing space used to pad to an even number of characters
            if (!($format or (strlen($buff) & 0x01))) $buff = preg_replace('/ $/', '', $buff, 1);
            if ($len > 1024) {
                // treat large data elements as binary data
                $binData = null;
                $lcTag = $tagInfo ? strtolower($tagInfo->Name) : 'unknown';
                if (!empty($et->REQ_TAG_LOOKUP[$lcTag]) or
                    ($et->OPTIONS['Binary'] and empty($et->EXCL_TAG_LOOKUP[$lcTag])))
                {
                    $binData = $buff;   // must make a copy
                } else {
                    $binData = "Binary data $len bytes";
                }
                $val = new ScalarRef($binData);
            } elseif ($format) {
                $val = ExifTool::ReadValue($buff, 0, $format, null, $len);
            } else {
                $val = $buff;
                $format = 'string';
                if ($vr === 'DA') {
                    // format date values
                    $val = preg_replace('/^ *(\d{4})(\d{2})(\d{2})/', '$1:$2:$3', $val, 1);
                } elseif ($vr === 'TM') {
                    // format time values
                    $val = preg_replace('/^ *(\d{2})(\d{2})(\d{2}[^ ]*)/', '$1:$2:$3', $val, 1);
                } elseif ($vr === 'DT') {
                    // format date/time values
                    $val = preg_replace('/^ *(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2}[^ ]*)/', '$1:$2:$3 $4:$5:$6', $val, 1);
                } elseif ($vr === 'AT' and $len == 4) {
                    // convert attribute tag ID to hex format
                    [$g, $e] = [ExifTool::Get16u($buff, 0), ExifTool::Get16u($buff, 2)];
                    $val = Perl::sprintf('%.4X,%.4X', $g, $e);
                } elseif ($vr === 'UI') {
                    // add PrintConv to translate registered UID's
                    $val = preg_replace('/\0.*/s', '', $val, 1);    // truncate at null
                    if ($tagInfo) {
                        if (isset(Vars\DICOM::$uid[$val])) $tagInfo->PrintConv = Vars\DICOM::$uid;
                        if ($tagInfo->Hidden === null) $tagInfo->Hidden = '';   // (don't add PrintConv to -listx output)
                    }
                } elseif (preg_match('/^(AE|CS|DS|IS|LO|PN|SH)$/', $vr)) {
                    $val = preg_replace('/ +$/', '', $val, 1);  // leading/trailing spaces not significant
                    $val = preg_replace('/^ +/', '', $val, 1);
                } elseif (preg_match('/^(LT|ST|UT)$/', $vr)) {
                    $val = preg_replace('/ +$/', '', $val, 1);  // trailing spaces not significant
                }
            }
            // save the group 2 end position and transfer syntax
            if ($group == 0x0002) {
                if ($element == 0x0000) $group2end = $pos + Perl::num($val);
                if ($element == 0x0010) $transferSyntax = $val;
            }

            // handle the new tag information
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt: $buff,
                DataPos: $pos - $len,
                Format: $format,
                Start: 0,
                Size: $len,
                Extra: " ($vr)",
            );

            // stop indenting for list if we reached EndOfItems tag
            if ($verbose and $tag === 'FFFE,E00D') $et->INDENT = preg_replace('/..$/', '', $et->INDENT, 1);
        }
        if ($err) $et->Warn('Error reading DICOM file (corrupted?)');
        return 1;
    }
}
