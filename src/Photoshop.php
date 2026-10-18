<?php

//------------------------------------------------------------------------------
// File:         Photoshop.pm
//
// Description:  Read/write Photoshop IRB meta information
//
// Revisions:    02/06/2004 - P. Harvey Created
//               02/25/2004 - P. Harvey Added hack for problem with old photoshops
//               10/04/2004 - P. Harvey Added a bunch of tags (ref Image::MetaData::JPEG)
//                            but left most of them commented out until I have enough
//                            information to write PrintConv routines for them to
//                            display something useful
//               07/08/2005 - P. Harvey Added support for reading PSD files
//               01/07/2006 - P. Harvey Added PSD write support
//               11/04/2006 - P. Harvey Added handling of resource name
//
// References:   1) http://www.fine-view.com/jp/labs/doc/ps6ffspecsv2.pdf
//               2) http://www.ozhiker.com/electronics/pjmt/jpeg_info/irb_jpeg_qual.html
//               3) Matt Mueller private communication (tests with PS CS2)
//               4) http://www.fileformat.info/format/psd/egff.htm
//               5) http://www.telegraphics.com.au/svn/psdparse/trunk/resources.c
//               6) http://libpsd.graphest.com/files/Photoshop%20File%20Formats.pdf
//               7) http://www.adobe.com/devnet-apps/photoshop/fileformatashtml/
//
// PHP port of Image::ExifTool::Photoshop 1.73. Tag tables are generated into
// tables/Photoshop.php; WritePhotoshop.pl is the trait src/Photoshop/WritePhotoshop.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Photoshop
{
    use Photoshop\WritePhotoshop;

    public static $VERSION = '1.73';

    /**
     * $Image::ExifTool::Photoshop::iptcDigestInfo: the IPTCDigest tagInfo
     * ($Image::ExifTool::Photoshop::Main{0x0425}), set when the module is loaded
     */
    public static function IptcDigestInfo(): ?TagInfo
    {
        return ExifTool::PackageTable('Image::ExifTool::Photoshop::Main')[0x0425];
    }

    //------------------------------------------------------------------------------
    // Convert pascal string(s) to something we can use
    // Inputs: 1) Pascal string data
    // Returns: Strings, concatenated with ', '
    public static function ConvertPascalString(ExifTool $et, $inStr)
    {
        $inStr = Perl::str($inStr);
        $outStr = '';
        $len = strlen($inStr);
        $i = 0;
        while ($i < $len) {
            $n = ord($inStr[$i]);
            if ($i + $n >= $len) break;
            if ($i) $outStr .= ', ';
            $outStr .= substr($inStr, $i + 1, $n);
            $i += $n + 1;
        }
        $charset = $et->Options('CharsetPhotoshop') ?: 'Latin';
        return $et->Decode($outStr, $charset);
    }

    //------------------------------------------------------------------------------
    // Process Photoshop layers and mask information section of PSD/PSB file
    // Inputs: 0) ExifTool ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success (and seeks to the end of this section)
    public static function ProcessLayersAndMask(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $raf = $dirInfo['RAF'];
        $fileType = Perl::str($et->FileType);
        $data = null;

        if (!($fileType === 'PSD' or $fileType === 'PSB')) return 0;  // (no layer section in CS1 files)

        // (some words are 4 bytes in PSD files and 8 bytes in PSB)
        [$psb, $psiz] = $fileType === 'PSB' ? [1, 8] : [null, 4];

        // read the layer information header
        $n = $psiz * 2 + 2;
        if ($raf->Read($data, $n) != $n) return 0;
        $tot = $psb ? ExifTool::Get64u($data, 0) : ExifTool::Get32u($data, 0);  // length of layer and mask info
        if ($tot == 0) return 1;
        $end = $raf->Tell() - $psiz - 2 + $tot;
        $data = substr($data, $psiz);
        $len = $psb ? ExifTool::Get64u($data, 0) : ExifTool::Get32u($data, 0);  // length of layer info section
        $num = ExifTool::Get16s($data, $psiz);
        // check for Lr16 block if layers length is 0 (ref https://forums.adobe.com/thread/1540914)
        if ($len == 0 and $num == 0) {
            if ($raf->Read($data, 10) != 10) return 0;
            if (preg_match('/^..8BIMLr16/s', $data)) {
                if ($raf->Read($data, $psiz + 2) != $psiz + 2) return 0;
                $len = $psb ? ExifTool::Get64u($data, 0) : ExifTool::Get32u($data, 0);
            } elseif (preg_match('/^..8BIMMt16/s', $data)) { // (have seen Mt16 before Lr16, ref PH)
                if ($raf->Read($data, $psiz) != $psiz) return 0;
                if ($raf->Read($data, 8) != 8) return 0;
                if ($data === '8BIMLr16') {
                    if ($raf->Read($data, $psiz + 2) != $psiz + 2) return 0;
                    $len = $psb ? ExifTool::Get64u($data, 0) : ExifTool::Get32u($data, 0);
                } else {
                    if (!$raf->Seek(-18 - $psiz, 1)) return 0;
                }
            } else {
                if (!$raf->Seek(-10, 1)) return 0;
            }
        }
        $len += 2;  // include layer count with layer info section
        if (!$raf->Seek(-2, 1)) return 0;
        $dinfo = [
            'RAF' => $raf,
            'DirLen' => $len,
        ];
        $et->IsPSB = $psb;  // set PSB flag
        self::ProcessLayers($et, $dinfo, $tagTablePtr);

        // seek to the end of this section and return success flag
        return $raf->Seek($end, 0) ? 1 : 0;
    }

    //------------------------------------------------------------------------------
    // Process Photoshop channel options (ref forum16762)
    // Inputs: 0) ExifTool ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessChannelOptions(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $end = $dirInfo['DirStart'] + $dirInfo['DirLen'];
        $dirInfo['DirLen'] = 13;
        for ($i = 0; $dirInfo['DirStart'] + 13 <= $end; ++$i) {
            $et->SET_GROUP1 = "Channel$i";
            $et->ProcessBinaryData($dirInfo, $tagTablePtr);
            $dirInfo['DirStart'] += 13;
        }
        $et->SET_GROUP1 = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Photoshop layers (beginning with layer count)
    // Inputs: 0) ExifTool ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: Uses ExifTool IsPSB member to determine whether file is PSB format
    public static function ProcessLayers(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $n = $buff = $buf2 = null;
        $count = [];
        $raf = $dirInfo['RAF'];
        $dirLen = $dirInfo['DirLen'];
        $verbose = $et->OPTIONS['Verbose'];
        // (DataPt => \$buff: the array element is a reference to $buff)
        $dinfo = ['DataPt' => &$buff, 'Base' => $raf->Tell()];
        $pos = 0;
        if ($dirLen < 2) return 0;
        if ($raf->Read($buff, 2) != 2) return 0;
        $num = ExifTool::Get16s($buff, 0);  // number of layers
        if ($num < 0) $num = -$num;         // (first channel is transparency data if negative)
        $et->VerboseDir('Layers', $num, $dirLen);
        $et->HandleTag($tagTablePtr, '_xcnt', $num, ...$dinfo, Start: $pos, Size: 2);  // LayerCount
        $oldIndent = $et->INDENT;
        $et->INDENT .= '| ';
        $pos += 2;
        $psb = $et->IsPSB;  // is PSB format?
        $psiz = $psb ? 8 : 4;
        for ($i = 0; $i < $num; ++$i) {     // process each layer
            $et->VPrint(0, $oldIndent . '+ [Layer ' . ($i + 1) . " of $num]\n");
            if ($pos + 18 > $dirLen) break;
            if ($raf->Read($buff, 18) != 18) break;
            $dinfo['DataPos'] = $pos;
            // save the layer rectangle
            $et->HandleTag($tagTablePtr, '_xrct', null, ...$dinfo, Start: 0, Size: 16);
            $numChannels = ExifTool::Get16u($buff, 16);
            $n = (2 + $psiz) * $numChannels;    // size of channel information
            if (!$raf->Seek($n, 1)) break;
            $pos += 18 + $n;
            if ($pos + 20 > $dirLen) break;
            if ($raf->Read($buff, 20) != 20) break;
            $dinfo['DataPos'] = $pos;
            $sig = substr($buff, 0, 4);
            if (!preg_match('/^(8BIM|MIB8)$/', $sig)) break;  // verify signature
            $et->HandleTag($tagTablePtr, '_xbnd', null, ...$dinfo, Start: 4, Size: 4);
            $et->HandleTag($tagTablePtr, '_xopc', null, ...$dinfo, Start: 8, Size: 1);
            $et->HandleTag($tagTablePtr, '_xvis', null, ...$dinfo, Start: 10, Size: 1);
            $nxt = $pos + 16 + ExifTool::Get32u($buff, 12);
            $n = ExifTool::Get32u($buff, 16);   // get size of layer mask data
            $pos += 20 + $n;                    // skip layer mask data
            if ($pos + 4 > $dirLen) break;
            if (!($raf->Seek($n, 1) and $raf->Read($buff, 4) == 4)) break;
            $n = ExifTool::Get32u($buff, 0);    // get size of layer blending ranges
            $pos += 4 + $n;                     // skip layer blending ranges data
            if ($pos + 1 > $dirLen) break;
            if (!($raf->Seek($n, 1) and $raf->Read($buff, 1) == 1)) break;
            $n = ExifTool::Get8u($buff, 0);     // get length of layer name
            if ($pos + 1 + $n > $dirLen) break;
            if ($raf->Read($buff, $n) != $n) break;
            $dinfo['DataPos'] = $pos + 1;
            $et->HandleTag($tagTablePtr, '_xnam', null, ...$dinfo, Start: 0, Size: $n);
            $frag = ($n + 1) & 0x3;
            if ($frag and !$raf->Seek(4 - $frag, 1)) break;
            $n = ($n + 4) & 0xfffffffc;     // +1 for length byte then pad to multiple of 4 bytes
            $pos += $n;
            // process additional layer info
            while ($pos + 12 <= $nxt) {
                if ($raf->Read($buff, 12) != 12) break;
                $dat = substr($buff, 0, 8);
                if (ExifTool::GetByteOrder() === 'II') $dat = Perl::pack('N*', ...Perl::unpack('V*', $dat));
                $sig = substr($dat, 0, 4);
                if (!($sig === '8BIM' or $sig === '8B64')) break;   // verify signature
                $tag = substr($dat, 4, 4);
                // (some structures have an 8-byte size word [augh!]
                // --> it would be great if '8B64' indicated a 64-bit version, and this may well
                // be the case, but it is not mentioned in the Photoshop file format specification)
                if ($psb and preg_match('/^(LMsk|Lr16|Lr32|Layr|Mt16|Mt32|Mtrn|Alph|FMsk|lnk2|FEid|FXid|PxSD)$/', $tag)) {
                    if ($pos + 16 > $nxt) break;
                    if ($raf->Read($buf2, 4) != 4) break;
                    $buff .= $buf2;
                    $n = ExifTool::Get64u($buff, 8);
                    $pos += 4;
                } else {
                    $n = ExifTool::Get32u($buff, 8);
                }
                $pos += 12;
                if ($pos + $n > $nxt) break;
                $frag = $n & 0x3;
                if ($tagTablePtr[$tag] or $verbose) {
                    // pad with empty entries if necessary to keep the same index for each item in the layer
                    if (!isset($count[$tag])) $count[$tag] = 0;
                    if ($raf->Read($buff, $n) != $n) break;
                    $dinfo['DataPos'] = $pos;
                    while ($count[$tag] < $i) {
                        $et->HandleTag($tagTablePtr, $tag, $tag === 'lsct' ? 0 : '');
                        ++$count[$tag];
                    }
                    $et->HandleTag($tagTablePtr, $tag, null, ...$dinfo, Start: 0, Size: $n);
                    ++$count[$tag];
                    if ($frag) {
                        if (!$raf->Seek(4 - $frag, 1)) break;
                        $n += 4 - $frag;    // pad to multiple of 4 bytes (PH NC)
                    }
                } else {
                    if ($frag) $n += 4 - $frag;
                    if (!$raf->Seek($n, 1)) break;
                }
                $pos += $n;     // step to start of next structure
            }
            $pos = $nxt;
        }
        // pad lists if necessary to have an entry for each layer
        ksort($count, SORT_STRING);
        foreach ($count as $_ => $cnt) {
            $_ = (string) $_;
            while ($count[$_] < $num) {
                $et->HandleTag($tagTablePtr, $_, $_ === 'lsct' ? 0 : '');
                ++$count[$_];
            }
        }
        $et->INDENT = $oldIndent;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Photoshop ImageSourceData
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessDocumentData(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $verbose = $et->OPTIONS['Verbose'];
        $raf = $dirInfo['RAF'] ?? null;
        $dirLen = $dirInfo['DirLen'] ?? null;
        $pos = 36;  // length of header
        $buff = $n = $err = null;

        $et->VerboseDir('Photoshop Document Data', null, $dirLen);
        if (!$raf) {
            $dataPt = $dirInfo['DataPt'];
            $start = ($dirInfo['DirStart'] ?? null) ?: 0;
            $raf = new RandomAccess($dataPt);
            if ($start) $raf->Seek($start, 0);
            if ($dirLen === null) $dirLen = strlen($dataPt) - $start;
            $et->VerboseDump($dataPt, Start: $start, Len: $dirLen, Base: $dirInfo['Base'] ?? null);
        }
        if (!($raf->Read($buff, $pos) == $pos and
              preg_match('/^Adobe Photoshop Document Data (Block|V0002)\0/', $buff, $m)))
        {
            $et->Warn('Invalid Photoshop Document Data');
            return 0;
        }
        $psb = ($m[1] === 'V0002');
        $dinfo = ['DataPt' => &$buff];  // (DataPt => \$buff)
        $et->IsPSB = $psb ? 1 : '';     // set PSB flag (needed when handling Layers directory)
        while ($pos + 12 <= $dirLen) {
            if ($raf->Read($buff, 8) != 8) { $err = 'Error reading document data'; break; }
            // set byte order according to byte order of first signature
            if ($pos == 36) ExifTool::SetByteOrder(preg_match('/^(8BIM|8B64)/', $buff) ? 'MM' : 'II');
            if (ExifTool::GetByteOrder() === 'II') $buff = Perl::pack('N*', ...Perl::unpack('V*', $buff));
            $sig = substr($buff, 0, 4);
            if (!($sig === '8BIM' or $sig === '8B64')) { $err = 'Bad photoshop resource'; break; }   // verify signature
            $tag = substr($buff, 4, 4);
            if ($psb and preg_match('/^(LMsk|Lr16|Lr32|Layr|Mt16|Mt32|Mtrn|Alph|FMsk|lnk2|FEid|FXid|PxSD)$/', $tag)) {
                if ($pos + 16 > $dirLen) { $err = 'Short PSB resource'; break; }
                if ($raf->Read($buff, 8) != 8) { $err = 'Error reading PSB resource'; break; }
                $n = ExifTool::Get64u($buff, 0);
                $pos += 4;
            } else {
                if ($raf->Read($buff, 4) != 4) { $err = 'Error reading PSD resource'; break; }
                $n = ExifTool::Get32u($buff, 0);
            }
            $pos += 12;
            if ($pos + $n > $dirLen) { $err = 'Truncated photoshop resource'; break; }
            $pad = (4 - ($n & 3)) & 3;  // number of padding bytes
            $tagInfo = $tagTablePtr[$tag];
            if ($tagInfo or $verbose) {
                if ($tagInfo and $tagInfo->SubDirectory) {
                    $fpos = $raf->Tell() + $n + $pad;
                    $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    $subdirInfo = ['RAF' => $raf, 'DirLen' => $n];
                    $et->ProcessDirectory($subdirInfo, $subTable);
                    if (!$raf->Seek($fpos, 0)) { $err = 'Seek error'; break; }
                } else {
                    $dinfo['DataPos'] = $raf->Tell();
                    $dinfo['Start'] = 0;
                    $dinfo['Size'] = $n;
                    if ($raf->Read($buff, $n) != $n) { $err = 'Error reading photoshop resource'; break; }
                    $et->HandleTag($tagTablePtr, $tag, null, ...$dinfo);
                    if (!$raf->Seek($pad, 1)) { $err = 'Seek error'; break; }
                }
            } else {
                if (!$raf->Seek($n + $pad, 1)) { $err = 'Seek error'; break; }
            }
            $pos += $n + $pad;      // step to start of next structure
        }
        if ($err) $et->Warn($err);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Photoshop APP13 record
    // Inputs: 0) ExifTool object reference, 1) Reference to directory information
    //         2) Tag table reference
    // Returns: 1 on success
    public static function ProcessPhotoshop(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $dirEnd = $pos + $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');
        $success = 0;

        // ignore non-standard XMP while in strict MWG compatibility mode
        if ((MWG::$strict or $et->Options('Validate')) and
            preg_match('/^(JPEG|TIFF|PSD)$/', (string) $et->FILE_TYPE))
        {
            $path = $et->MetadataPath();
            if (!preg_match('/^(JPEG-APP13-Photoshop|TIFF-IFD0-Photoshop|PSD)$/', $path)) {
                if (MWG::$strict) {
                    $et->Warn("Ignored non-standard Photoshop at $path");
                    return 1;
                } else {
                    $et->Warn("Non-standard Photoshop at $path", 1);
                }
            }
        }
        if ($et->FILE_TYPE === 'JPEG' and Perl::str($dirInfo['Parent'] ?? null) !== 'APP13') {
            $et->LOW_PRIORITY_DIR['*'] = 1;     // lower priority of all these tags
        }
        ExifTool::SetByteOrder('MM');   // Photoshop is always big-endian
        if ($verbose) $et->VerboseDir('Photoshop', 0, $dirInfo['DirLen']);

        // scan through resource blocks:
        // Format: 0) Type, 4 bytes - '8BIM' (or the rare 'PHUT', 'DCSR', 'AgHg' or 'MeSa')
        //         1) TagID,2 bytes
        //         2) Name, pascal string padded to even no. bytes
        //         3) Size, 4 bytes - N
        //         4) Data, N bytes
        while ($pos + 8 < $dirEnd) {
            $type = substr($dataPt, $pos, 4);
            $ttPtr = $extra = $val = $name = null;
            if ($type === '8BIM') {
                $ttPtr = $tagTablePtr;
            } elseif (preg_match('/^(PHUT|DCSR|AgHg|MeSa)$/', $type)) {   // (PHUT~ImageReady, MeSa~PhotoDeluxe)
                $ttPtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Unknown');
            } else {
                $type = preg_replace_callback('/([^\w])/', static fn ($m) => Perl::sprintf('\\x%.2x', ord($m[1])), $type);
                $et->Warn("Bad Photoshop IRB resource \"$type\"");
                break;
            }
            $tag = ExifTool::Get16u($dataPt, $pos + 4);
            $pos += 6;  // point to start of name
            $nameLen = ExifTool::Get8u($dataPt, $pos);
            $namePos = ++$pos;
            // skip resource block name (pascal string, padded to an even # of bytes)
            $pos += $nameLen;
            if (!($nameLen & 0x01)) ++$pos;
            if ($pos + 4 > $dirEnd) {
                $et->Warn('Bad Photoshop resource block');
                break;
            }
            $size = ExifTool::Get32u($dataPt, $pos);
            $pos += 4;
            if ($size + $pos > $dirEnd) {
                $et->Warn("Bad Photoshop resource data size $size");
                break;
            }
            $success = 1;
            if ($nameLen) {
                $name = substr($dataPt, $namePos, $nameLen);
                $extra = ", Name=\"$name\"";
            } else {
                $name = '';
            }
            $tagInfo = $et->GetTagInfo($ttPtr, $tag);
            // append resource name to value if requested (braced by "/#...#/")
            if ($tagInfo and $tagInfo->SetResourceName !== null and
                Perl::str($tagInfo->SetResourceName) === '1' and !str_contains($name, '/#'))
            {
                $val = substr($dataPt, $pos, $size) . '/#' . $name . '#/';
            }
            $et->HandleTag($ttPtr, $tag, $val,
                TagInfo: $tagInfo,
                Extra:   $extra,
                DataPt:  $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Size:    $size,
                Start:   $pos,
                Base:    $dirInfo['Base'] ?? null,
                Parent:  $dirInfo['DirName'] ?? null,
            );
            if ($size & 0x01) $size += 1;   // size is padded to an even # bytes
            $pos += $size;
        }
        // warn about incorrect IPTCDigest
        if (($et->VALUE['IPTCDigest'] ?? null) and ($et->VALUE['CurrentIPTCDigest'] ?? null) and
            Perl::str($et->VALUE['IPTCDigest']) !== Perl::str($et->VALUE['CurrentIPTCDigest']))
        {
            $et->Warn('IPTCDigest is not current. XMP may be out of sync');
        }
        unset($et->LOW_PRIORITY_DIR['*']);
        return $success;
    }

    //------------------------------------------------------------------------------
    // extract information from Photoshop PSD file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a valid PSD file, -1 on write error
    public static function ProcessPSD(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'] ?? null;
        $data = $err = $tagTablePtr = null;

        if ($raf->Read($data, 30) != 30) return 0;
        if (!preg_match('/^8BPS\0([\x01\x02])/', $data, $m)) return 0;
        ExifTool::SetByteOrder('MM');
        $et->SetFileType($m[1] === "\x01" ? 'PSD' : 'PSB');    // set the FileType tag
        // (Perl %dirInfo, with DataPt => \$data: the element is a reference to $data)
        $psdInfo = [
            'DataPt' => &$data,
            'DirStart' => 0,
            'DirName' => 'Photoshop',
        ];
        $len = ExifTool::Get32u($data, 26);
        if ($outfile) {
            if (!ExifTool::Write($outfile, $data)) $err = 1;
            if ($raf->Read($data, (int) $len) != $len) return -1;
            if (!ExifTool::Write($outfile, $data)) $err = 1;    // write color mode data
            // initialize map of where things are written
            $et->InitWriteDirs(Vars\Photoshop::$psdMap);
        } else {
            // process the header
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Header');
            $psdInfo['DirLen'] = 30;
            $et->ProcessDirectory($psdInfo, $tagTablePtr);
            if (!$raf->Seek($len, 1)) $err = 1;     // skip over color mode data
        }
        // read image resource section
        if ($raf->Read($data, 4) != 4) $err = 1;
        $len = ExifTool::Get32u($data, 0);
        if ($raf->Read($data, (int) $len) != $len) $err = 1;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Main');
        $psdInfo['DirLen'] = $len;
        $rtnVal = 1;
        if ($outfile) {
            // rewrite IRB resources
            $data = self::WritePhotoshop($et, $psdInfo, $tagTablePtr);
            if ($data) {
                $len = ExifTool::Set32u(strlen($data));
                if (!ExifTool::Write($outfile, $len, $data)) $err = 1;
                // look for trailer and edit if necessary
                $trailInfo = $et->IdentifyTrailer($raf);
                if ($trailInfo) {
                    $tbuf = new ScalarRef('');
                    $trailInfo['OutFile'] = $tbuf;  // rewrite trailer(s)
                    // rewrite all trailers to buffer
                    if ($et->ProcessTrailers($trailInfo)) {
                        $copyBytes = $trailInfo['DataPos'] - $raf->Tell();
                        if ($copyBytes >= 0) {
                            // copy remaining PSD file up to start of trailer
                            while ($copyBytes) {
                                $n = ($copyBytes > 65536) ? 65536 : $copyBytes;
                                if ($raf->Read($data, $n) != $n) $err = 1;
                                if (!ExifTool::Write($outfile, $data)) $err = 1;
                                $copyBytes -= $n;
                            }
                            // write the trailer (or not)
                            if (!$et->WriteTrailerBuffer($trailInfo, $outfile)) $err = 1;
                        } else {
                            $et->Warn('Overlapping trailer');
                            $trailInfo = null;
                        }
                    } else {
                        $trailInfo = null;
                    }
                }
                if (!$trailInfo) {
                    // copy over the rest of the file
                    while ($raf->Read($data, 65536)) {
                        if (!ExifTool::Write($outfile, $data)) $err = 1;
                    }
                }
            } else {
                $err = 1;
            }
            if ($err) $rtnVal = -1;
        } elseif ($err) {
            $et->Warn('File format error');
        } else {
            // read IRB resources
            self::ProcessPhotoshop($et, $psdInfo, $tagTablePtr);
            // read layer and mask information section
            $psdInfo['RAF'] = $raf;
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Layers');
            $oldIndent = $et->INDENT;
            $et->INDENT .= '| ';
            if (self::ProcessLayersAndMask($et, $psdInfo, $tagTablePtr) and
                // read compression mode from image data section
                $raf->Read($data, 2) == 2)
            {
                $subdirInfo = [
                    'DataPt'  => $data,
                    'DataPos' => $raf->Tell() - 2,
                ];
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::ImageData');
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            }
            $et->INDENT = $oldIndent;
            // process trailers if they exist
            $trailInfo = $et->IdentifyTrailer($raf);
            if ($trailInfo) $et->ProcessTrailers($trailInfo);
        }
        return $rtnVal;
    }
}
