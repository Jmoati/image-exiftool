<?php

//------------------------------------------------------------------------------
// File:         Jpeg2000.pm
//
// Description:  Read JPEG 2000 meta information
//
// Revisions:    02/11/2005 - P. Harvey Created
//               06/22/2007 - PH Added write support (EXIF, IPTC and XMP only)
//
// References:   1) http://www.jpeg.org/public/fcd15444-2.pdf
//               2) ftp://ftp.remotesensing.org/jpeg2000/fcd15444-1.pdf
//
// PHP port of Image::ExifTool::Jpeg2000 1.43. Tag tables are generated into
// tables/Jpeg2000.php, the file lexicals (%uuid, %j2cMarker...) into Vars\Jpeg2000.
// Brotli-compressed boxes need the brotli PHP extension (Perl: IO::Uncompress::Brotli
// and IO::Compress::Brotli).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\Jpeg2000 as Vars;

final class Jpeg2000
{
    public static $VERSION = '1.43';

    //------------------------------------------------------------------------------
    // Read JUMBF box to keep track of sub-document numbers
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessJUMB(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        if ($et->jumd_level) {
            ++$et->jumd_level[count($et->jumd_level) - 1];  // increment current sub-document number
        } else {
            $et->DOC_COUNT = ($et->DOC_COUNT ?: 0) + 1;
            $et->jumd_level = [$et->DOC_COUNT];     // new top-level sub-document
            $et->SET_GROUP0 = 'JUMBF';
        }
        $et->DOC_NUM = implode('-', $et->jumd_level);
        $et->jumd_level[] = 0;
        self::ProcessJpeg2000Box($et, $dirInfo, $tagTablePtr);
        $et->DOC_NUM = null;
        $et->JUMBFLabel = null;
        array_pop($et->jumd_level);
        if (count($et->jumd_level) < 2) {
            $et->jumd_level = null;
            $et->SET_GROUP0 = null;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read JUMBF description box (ref https://github.com/thorfdbg/codestream-parser)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessJUMD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos    = $dirInfo['DirStart'];
        $end    = $pos + $dirInfo['DirLen'];
        $et->VerboseDir('JUMD', 0, $end - $pos);
        $et->JUMBFLabel = null;
        if ($dirInfo['DirLen'] < 17) {
            $et->Warn('Truncated JUMD directory');
            return 0;
        }
        $type = substr($dataPt, $pos, 4);
        $et->HandleTag($tagTablePtr, 'type', substr($dataPt, $pos, 16));
        $pos += 16;
        $flags = ExifTool::Get8u($dataPt, $pos++);
        $et->HandleTag($tagTablePtr, 'toggles', $flags);
        if ($flags & 0x02) {    // label exists?
            $p = strpos($dataPt, "\0", $pos);
            if ($p === false) {
                $et->Warn('Missing JUMD label terminator');
                return 0;
            }
            $len = $p + 1 - $pos;
            $name = substr($dataPt, $pos, $len);
            $et->HandleTag($tagTablePtr, 'label', $name);
            $pos += $len;
            if ($len) {
                $name = preg_replace_callback('/[^-_a-zA-Z0-9]([a-z])/', static fn ($m) => strtoupper($m[1]), $name); // capitalize characters after illegal characters
                $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);   // remove other illegal characters
                $name = preg_replace('/__/', '_', $name, 1);            // collapse double underlines
                $name = ucfirst($name);                                 // capitalize first letter
                $name = preg_replace('/C2pa/', 'C2PA', $name, 1);       // capitalize C2PA
                if (strlen($name) < 2) $name = "Tag$name";              // must at least 2 characters long
                $et->JUMBFLabel = $name;
            }
        }
        if ($flags & 0x04) {    // ID exists?
            if ($pos + 4 > $end) {
                $et->Warn('Missing JUMD ID');
                return 0;
            }
            $et->HandleTag($tagTablePtr, 'id', ExifTool::Get32u($dataPt, $pos));
            $pos += 4;
        }
        if ($flags & 0x08) {    // signature exists?
            if ($pos + 32 > $end) {
                $et->Warn('Missing JUMD signature');
                return 0;
            }
            $et->HandleTag($tagTablePtr, 'sig', substr($dataPt, $pos, 32));
            $pos += 32;
        }
        $more = $end - $pos;
        if ($more) {
            // (may find c2sh box hiding after JUMD record)
            if ($more >= 8) {
                $subdirInfo = [
                    'DataPt'   => $dataPt,
                    'DataLen'  => $dirInfo['DataLen'] ?? null,
                    'DirStart' => $pos,
                    'DirLen'   => $more,
                    'DirName'  => 'JUMDPrivate',
                ];
                $et->ProcessDirectory($subdirInfo, ExifTool::GetTagTable('Image::ExifTool::Jpeg2000::Main'));
            } else {
                $et->Warn("Extra data in JUMD box $more bytes)", 1);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Warn about error in Brotli compression/decompression
    // Inputs: 0) ExifTool ref, 1) box type, 2) true for decoding (Uncompress)
    public static function BrotliWarn(ExifTool $et, $type, $uncompress = null): void
    {
        [$enc, $mod] = $uncompress ? ['decoding', 'Uncompress'] : ['encoding', 'Compress'];
        $et->Warn("Error $enc '{$type}' brob box");
        $et->Warn("Try updating to IO::{$mod}::Brotli 0.004 or later");
    }

    //------------------------------------------------------------------------------
    // Create new JPEG 2000 boxes when writing
    // (Currently only supports adding top-level Writable JPEG2000 tags and certain UUID boxes)
    // Inputs: 0) ExifTool object ref, 1) Output file or scalar ref
    // Returns: 1 on success
    public static function CreateNewBoxes(ExifTool $et, $outfile)
    {
        $addTags = $et->AddJp2Tags ?? [];
        $addDirs = $et->AddJp2Dirs ?? [];
        $et->AddJp2Tags = null;
        $et->AddJp2Dirs = null;
        // add JPEG2000 tags
        $tags = array_map('strval', array_keys($addTags));
        sort($tags, SORT_STRING);
        foreach ($tags as $tag) {
            $tagInfo = $addTags[$tag];
            $nvHash = $et->GetNewValueHash($tagInfo);
            // (native JPEG2000 information is always preferred, so don't check IsCreating)
            if (!($tagInfo->List or $et->IsOverwriting($nvHash) > 0)) continue;
            if ($nvHash?->EditOnly) continue;
            $vals = $et->GetNewValueList($nvHash);
            foreach ($vals as $val) {
                $val = Perl::str($val);
                $boxhdr = pack('N', strlen($val) + 8) . $tagInfo->TagID;
                if (!ExifTool::Write($outfile, $boxhdr, $val)) return 0;
                ++$et->CHANGED;
                $et->VerboseValue("+ Jpeg2000:{$tagInfo->Name}", $val);
            }
        }
        // add UUID boxes (and/or JXL Exif/XML boxes)
        $dirNames = array_map('strval', array_keys($addDirs));
        sort($dirNames, SORT_STRING);
        foreach ($dirNames as $dirName) {
            // handle JPEG XL XMP and EXIF
            if ($dirName === 'xml ' or $dirName === 'Exif') {
                [$tag, $dir] = $dirName === 'xml ' ? ['xml ', 'XMP'] : ['Exif', 'EXIF'];
                $tagInfo = ExifTool::GetTagTable('Image::ExifTool::Jpeg2000::Main')[$tag];
                if (is_array($tagInfo)) $tagInfo = $tagInfo[1];     // (hack for stupid JXL XMP)
                $subdir = $tagInfo->SubDirectory;
                $tagTable = ExifTool::GetTagTable($subdir['TagTable']);
                if ($dir === 'XMP') $tagTable = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
                $subdirInfo = [     // (Perl %dirInfo)
                    'DirName' => $dir,
                    'Parent' => $tag,
                ];
                $compress = $et->Options('Compress');
                if ($et->IsJXL and $compress) $subdirInfo['Compact'] = 1;
                $newdir = $et->WriteDirectory($subdirInfo, $tagTable, $subdir['WriteProc'] ?? null);
                if ($newdir !== null and strlen($newdir)) {
                    // not sure why, but EXIF box is padded with leading 0's in my sample
                    $pad = $dirName === 'Exif' ? "\0\0\0\0" : '';
                    if ($et->IsJXL and $compress) {
                        // create as Brotli-compressed metadata
                        if (function_exists('brotli_compress')) {
                            $compressed = @brotli_compress($pad . $newdir);
                            if (!$compressed) {
                                self::BrotliWarn($et, $dirName);
                            } else {
                                $et->VPrint(0, "  Writing Brotli-compressed $dir\n");
                                $newdir = $compressed;
                                $pad = $tag;
                                $tag = 'brob';
                            }
                        } else {
                            $et->Warn('Install IO::Compress::Brotli to create Brotli-compressed metadata');
                        }
                    }
                    $boxhdr = pack('N', strlen($newdir) + strlen($pad) + 8) . $tag;
                    if (!ExifTool::Write($outfile, $boxhdr, $pad, $newdir)) return 0;
                    continue;
                }
            }
            if (!(Vars::$uuid[$dirName] ?? null)) continue;
            foreach (ExifTool::GetTagTable('Image::ExifTool::Jpeg2000::Main')['uuid'] as $tagInfo) {
                if ($tagInfo->Name !== $dirName) continue;
                $subdir = $tagInfo->SubDirectory;
                $tagTable = ExifTool::GetTagTable($subdir['TagTable']);
                $subdirInfo = [     // (Perl %dirInfo)
                    'DirName' => ($subdir['DirName'] ?? null) ?: $dirName,
                    'Parent' => 'JP2',
                ];
                // remove "UUID-" from start of directory name to allow appropriate
                // directories to be written as a block
                $subdirInfo['DirName'] = preg_replace('/^UUID-/', '', $subdirInfo['DirName']);
                $newdir = $et->WriteDirectory($subdirInfo, $tagTable, $subdir['WriteProc'] ?? null);
                if ($newdir !== null and strlen($newdir)) {
                    $boxhdr = pack('N', strlen($newdir) + 24) . 'uuid' . Vars::$uuid[$dirName];
                    if (!ExifTool::Write($outfile, $boxhdr, $newdir)) return 0;
                    break;
                }
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Create Color Specification Box
    // Inputs: 0) ExifTool object ref, 1) Output file or scalar ref
    // Returns: 1 on success
    public static function CreateColorSpec(ExifTool $et, $outfile)
    {
        $meth   = $et->GetNewValue('Jpeg2000:ColorSpecMethod');
        $prec   = $et->GetNewValue('Jpeg2000:ColorSpecPrecedence') ?: 0;
        $approx = $et->GetNewValue('Jpeg2000:ColorSpecApproximation') ?: 0;
        $icc    = $et->GetNewValue('ICC_Profile');
        $space  = $et->GetNewValue('Jpeg2000:ColorSpace');
        $cdata  = $et->GetNewValue('Jpeg2000:ColorSpecData');
        if (!$meth) {
            if ($icc) {
                $meth = 2;
            } elseif ($space !== null) {
                $meth = 1;
            } elseif ($cdata !== null) {
                $meth = 4;
            } else {
                $et->Warn('Color space not defined');
                return 0;
            }
        }
        $meth = Perl::str($meth);
        if ($meth === '1') {
            if ($space === null) {
                $et->Warn('Must specify ColorSpace');
                return 0;
            }
            $cdata = pack('N', Perl::num($space));
        } elseif ($meth === '2' or $meth === '3') {
            if ($icc === null) {
                $et->Warn('Must specify ICC_Profile');
                return 0;
            }
            $cdata = $icc;
        } elseif ($meth === '4') {
            if ($cdata === null) {
                $et->Warn('Must specify ColorSpecData');
                return 0;
            }
        } else {
            $et->Warn('Unknown ColorSpecMethod');
            return 0;
        }
        $cdata = Perl::str($cdata);
        $boxhdr = pack('N', strlen($cdata) + 11) . 'colr';
        if (!ExifTool::Write($outfile, $boxhdr, Perl::pack('CCC', $meth, $prec, $approx), $cdata)) return 0;
        ++$et->CHANGED;
        $et->VPrint(1, "    + Jpeg2000:ColorSpec\n");
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process JPEG 2000 box
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) Pointer to tag table
    // Returns: 1 on success when reading, or -1 on write error
    //          (or JP2 box or undef when writing from buffer)
    public static function ProcessJpeg2000Box(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataLen = $dirInfo['DataLen'] ?? null;
        $dataPos = ($dirInfo['DataPos'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: 0;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $outfile = $dirInfo['OutFile'] ?? null;
        $dirName = ($dirInfo['DirName'] ?? null) ?: '';
        $dirEnd = $dirStart + $dirLen;
        $err = $outBuff = $verbose = $doColour = $hash = $raf = null;

        if ($dataPt !== null) {
            // save C2PA JUMBF as a block if requested
            if ($dirName === 'JUMBF' and !empty($et->REQ_TAG_LOOKUP['jumbf']) and empty($dirInfo['NoBlockSave'])) {
                if ($dirStart or $dirLen != strlen($dataPt)) {
                    $dat = substr($dataPt, $dirStart, $dirLen);
                    $et->FoundTag('JUMBF', new ScalarRef($dat));
                } else {
                    $et->FoundTag('JUMBF', new ScalarRef($dataPt));
                }
            }
        } else {
            $raf = $dirInfo['RAF'];     // read from RAF
        }

        if ($outfile) {
            if (!$raf) {
                // buffer output to be used for return value
                $outBuff = new ScalarRef('');
                $outfile = $outBuff;
            }
            // determine if we will be writing colr box
            if ($dirName === 'JP2Header') {
                if ($et->GetNewValue('ColorSpecMethod') !== null or $et->GetNewValue('ICC_Profile') or
                    $et->GetNewValue('ColorSpecPrecedence') !== null or $et->GetNewValue('ColorSpace') !== null or
                    $et->GetNewValue('ColorSpecApproximation') !== null or $et->GetNewValue('ColorSpecData') !== null) $doColour = 2;
            }
        } else {
            // (must not set verbose flag when writing!)
            $verbose = $et->OPTIONS['Verbose'];
            if ($verbose) $et->VerboseDir($dirName);
            // do hash if requested, but only for top-level image data
            if ($raf) $hash = $et->ImageDataHash;
        }
        // loop through all contained boxes
        $boxLen = $lastBox = null;
        for ($pos = $dirStart; ; $pos += $boxLen) {
            $boxID = $buff = $valuePtr = null;
            $hdrLen = 8;    // the box header length
            if ($raf) {
                $dataPos = $raf->Tell() - $base;
                $n = $raf->Read($buff, $hdrLen);
                if ($n != $hdrLen) {
                    if ($n) {
                        $err = '';
                        break;
                    }
                    if ($outfile and !self::CreateNewBoxes($et, $outfile)) $err = 1;
                    break;
                }
                $dataPt = $buff;
                $dirLen = $dirEnd = $hdrLen;
                $pos = 0;
            } elseif ($pos >= $dirEnd - $hdrLen) {
                if ($pos != $dirEnd) $err = '';
                break;
            }
            $boxLen = Perl::unpack("x$pos N", $dataPt)[0];    // (length includes header and data)
            $boxID = substr($dataPt, $pos + 4, 4);
            // (ftbl box contains flst boxes with absolute file offsets, not currently handled)
            if ($outfile and $boxID === 'ftbl') {
                $et->Error("Can't yet handle fragmented JPX files");
                return -1;
            }
            // remove old colr boxes if necessary
            if ($doColour and $boxID === 'colr') {
                if ($doColour == 1) {   // did we successfully write the new colr box?
                    $et->VPrint(1, "    - Jpeg2000:ColorSpec\n");
                    ++$et->CHANGED;
                    continue;
                }
                $et->Warn('Out-of-order colr box encountered');
                $doColour = null;
            }
            $lastBox = $boxID;
            $pos += $hdrLen;                // move to end of box header
            if ($boxLen == 1) {
                // box header contains an additional 8-byte integer for length
                $hdrLen += 8;
                if ($raf) {
                    $buf2 = null;
                    if ($raf->Read($buf2, 8) == 8) {
                        $buff .= $buf2;
                        $dataPt = $buff;
                        $dirLen = $dirEnd = $hdrLen;
                    }
                }
                if ($pos > $dirEnd - 8) {
                    $err = '';
                    break;
                }
                [$hi, $lo] = Perl::unpack("x$pos N2", $dataPt);
                if ($hi) {
                    $err = "Can't currently handle JPEG 2000 boxes > 4 GB";
                    break;
                }
                $pos += 8;                  // move to end of extended-length box header
                $boxLen = $lo - $hdrLen;    // length of remaining box data
            } elseif ($boxLen == 0) {
                if ($raf) {
                    if ($outfile) {
                        if (!self::CreateNewBoxes($et, $outfile)) $err = 1;
                        // copy over the rest of the file
                        if (!ExifTool::Write($outfile, $dataPt)) $err = 1;
                        while ($raf->Read($buff, 65536)) {
                            if (!ExifTool::Write($outfile, $buff)) $err = 1;
                        }
                    } else {
                        if ($verbose) {
                            $msg = Perl::sprintf('offset 0x%.4x to end of file', $dataPos + $base + $pos);
                            $et->VPrint(0, "{$et->INDENT}- Tag '{$boxID}' ($msg)\n");
                        }
                        if ($hash and !empty(Vars::$isImageData[$boxID])) {
                            $et->ImageDataHash($raf, null, $boxID);
                        }
                    }
                    break;  // (ignore the rest of the file when reading)
                }
                $boxLen = $dirEnd - $pos;   // data runs to end of file
            } else {
                $boxLen -= $hdrLen;         // length of remaining box data
            }
            if ($boxLen < 0) {
                $err = 'Invalid JPEG 2000 box length';
                break;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $boxID);
            if (!($tagInfo !== null or $verbose)) {
                // no need to process this box
                if ($raf) {
                    if ($outfile) {
                        if (!ExifTool::Write($outfile, $dataPt)) $err = 1;
                        if ($raf->Read($buff, $boxLen) != $boxLen) {
                            $err = '';
                            break;
                        }
                        if (!ExifTool::Write($outfile, $buff)) $err = 1;
                    } elseif ($hash and !empty(Vars::$isImageData[$boxID])) {
                        $et->ImageDataHash($raf, $boxLen, $boxID);
                    } elseif (!$raf->Seek($boxLen, 1)) {
                        $err = 'Seek error';
                        break;
                    }
                } elseif ($outfile) {
                    if (!ExifTool::Write($outfile, substr($dataPt, $pos - $hdrLen, $boxLen + $hdrLen))) {
                        $err = '';
                        break;
                    }
                }
                continue;
            }
            if ($raf) {
                // read the box data
                $dataPos = $raf->Tell() - $base;
                if ($raf->Read($buff, $boxLen) != $boxLen) {
                    $err = '';
                    break;
                }
                $dataPt = $buff;
                if ($hash and !empty(Vars::$isImageData[$boxID])) {
                    hash_update($hash, $buff);
                    $et->VPrint(0, "{$et->INDENT}(ImageDataHash: $boxLen bytes of $boxID data)\n");
                }
                $valuePtr = 0;
                $dataLen = $boxLen;
            } elseif ($pos + $boxLen > $dirEnd) {
                $err = '';
                break;
            } else {
                $valuePtr = $pos;
            }
            if ($tagInfo !== null and !$tagInfo) {
                // GetTagInfo() required the value for a Condition
                $tmpVal = substr($dataPt, $valuePtr, $boxLen < 128 ? $boxLen : 128);
                $tagInfo = $et->GetTagInfo($tagTablePtr, $boxID, $tmpVal);
            }
            // delete all UUID boxes and any writable box if deleting all information
            if ($outfile and $tagInfo) {
                if ($boxID === 'uuid' and ($et->DEL_GROUP['*'] ?? null)) {
                    $et->VPrint(0, "  Deleting {$tagInfo->Name}\n");
                    ++$et->CHANGED;
                    continue;
                } elseif ($tagInfo->Writable) {
                    $isOverwriting = null;
                    if ($et->DEL_GROUP['Jpeg2000'] ?? null) {
                        $isOverwriting = 1;
                    } else {
                        $nvHash = $et->GetNewValueHash($tagInfo);
                        $isOverwriting = $et->IsOverwriting($nvHash);
                    }
                    if ($isOverwriting) {
                        $val = substr($dataPt, $valuePtr, $boxLen);
                        $et->VerboseValue("- Jpeg2000:{$tagInfo->Name}", $val);
                        ++$et->CHANGED;
                        continue;
                    } elseif (!$tagInfo->List) {
                        unset($et->AddJp2Tags[$boxID]);
                    }
                }
            }
            // create new tag for JUMBF data values with name corresponding to JUMBFLabel
            if ($tagInfo and $et->JUMBFLabel and ($tagInfo->SubDirectory === null or $tagInfo->BlockExtract)) {
                $tagInfo = new TagInfo([...$tagInfo->toArray(), 'Name' => $et->JUMBFLabel . ($tagInfo->JUMBF_Suffix ?: '')]);
                $tagInfo->Description = preg_replace('/C2 PA/', 'C2PA', ExifTool::MakeDescription($tagInfo->Name), 1);
                ExifTool::AddTagToTable($tagTablePtr, '_JUMBF_' . $et->JUMBFLabel, $tagInfo);
                unset($tagInfo->Protected);     // (must do this so -j -b returns JUMBF binary data)
                $tagInfo->TagID = $boxID;
            }
            if ($verbose) {
                $et->VerboseInfo($boxID, $tagInfo,
                    Table:  $tagTablePtr,
                    DataPt: $dataPt,
                    Size:   $boxLen,
                    Start:  $valuePtr,
                    Addr:   $valuePtr + $dataPos + $base,
                );
                if (!$tagInfo) continue;
            }
            if ($tagInfo and $tagInfo->SubDirectory !== null) {
                $subdir = $tagInfo->SubDirectory;
                $subdirStart = $valuePtr;
                $subdirLen = $boxLen;
                if (isset($subdir['Start'])) {
                    //### eval Start ($valuePtr, $dataPt)
                    $start = $subdir['Start'];
                    try {
                        $subdirStart = $start instanceof \Closure ? $start($valuePtr, null, null, $dataPt) : $start;
                    } catch (\Throwable) {
                        $subdirStart = null;
                    }
                    $subdirStart = Perl::num($subdirStart);
                    $subdirLen -= $subdirStart - $valuePtr;
                    if ($subdirLen < 0) {
                        $subdirStart = $valuePtr;
                        $subdirLen = 0;
                    }
                }
                $subdirInfo = [
                    'Parent' => 'JP2',
                    'DataPt' => $dataPt,
                    'DataPos' => -$subdirStart, // (relative to Base)
                    'DataLen' => $dataLen,
                    'DirStart' => $subdirStart,
                    'DirLen' => $subdirLen,
                    'DirName' => ($subdir['DirName'] ?? null) ?: $tagInfo->Name,
                    'OutFile' => $outfile,
                    'Base' => $base + $dataPos + $subdirStart,
                ];
                $uuid = Vars::$uuid[$tagInfo->Name] ?? null;
                // remove "UUID-" prefix to allow appropriate directories to be written as a block
                $subdirInfo['DirName'] = preg_replace('/^UUID-/', '', $subdirInfo['DirName']);
                $subTable = ExifTool::GetTagTable($subdir['TagTable'] ?? null) ?: $tagTablePtr;
                if ($outfile) {
                    // (special case for brob box, which may be EXIF or XMP)
                    $fakeID = $boxID;
                    if ($boxID === 'brob') {
                        // I have seen 'brob' ID's with funny cases, so standardize these
                        if (preg_match('/^xml /i', $dataPt)) $fakeID = 'xml ';
                        if (preg_match('/^Exif/i', $dataPt)) $fakeID = 'Exif';
                    }
                    $newdir = null;
                    // only edit writable UUID, Exif and jp2h boxes
                    if ($uuid or $fakeID === 'Exif' or ($fakeID === 'xml ' and $et->IsJXL) or
                        ($boxID === 'jp2h' and ($et->EDIT_DIRS['jp2h'] ?? null)))
                    {
                        $compress = $et->Options('Compress');
                        $subdirInfo['Parent'] = $fakeID;
                        if ($compress and $et->IsJXL) $subdirInfo['Compact'] = 1;
                        $newdir = $et->WriteDirectory($subdirInfo, $subTable, $subdir['WriteProc'] ?? null);
                        if ($newdir !== null and !strlen($newdir)) continue;   // next if deleting the box
                        // compress JXL EXIF or XMP metadata if requested
                        if ($newdir !== null and $et->IsJXL and $compress !== null and
                            ($fakeID === 'Exif' or $fakeID === 'xml '))
                        {
                            if ($compress and $boxID !== 'brob') {
                                // rewrite as a Brotli-compressed 'brob' box
                                if (function_exists('brotli_compress')) {
                                    $pad = $boxID === 'Exif' ? "\0\0\0\0" : '';
                                    $compressed = @brotli_compress($pad . $newdir);
                                    if (!$compressed) {
                                        self::BrotliWarn($et, $boxID);
                                    } else {
                                        $et->VPrint(0, "  Writing Brotli-compressed $boxID\n");
                                        $newdir = $boxID . $compressed;
                                        $boxID = 'brob';
                                        $subdirStart = $valuePtr = 0;
                                        ++$et->CHANGED;
                                    }
                                } else {
                                    $et->Warn('Install IO::Compress::Brotli to write Brotli-compressed metadata');
                                }
                            } elseif (!$compress and $boxID === 'brob') {
                                // (in this case, ProcessBrotli has returned uncompressed data,
                                //  so change to the uncompressed 'xml ' or 'Exif' box type)
                                $et->VPrint(0, "  Writing uncompressed $fakeID\n");
                                $boxID = $fakeID;
                                $subdirStart = $valuePtr = 0;
                                ++$et->CHANGED;
                            }
                        }
                    } elseif ($uuid !== null) {
                        $et->Warn("Not editing {$tagInfo->Name} box", 1);
                    }
                    // remove this directory from our create list
                    unset($et->AddJp2Dirs[$fakeID]);                // (eg. 'Exif' or 'xml ')
                    if ($boxID === 'brob') {
                        // (can't make tag Name 'XMP' or 'Exif' for Brotli-compressed tags because it
                        //  would break the logic in WriteDirectory(), so we do a lookup here instead)
                        unset($et->AddJp2Dirs[['xml ' => 'XMP', 'Exif' => 'EXIF'][$fakeID] ?? '']);
                    } else {
                        unset($et->AddJp2Dirs[$tagInfo->Name]);     // (eg. 'EXIF' or 'XMP')
                    }
                    // use old box data if not changed
                    if ($newdir === null) $newdir = substr($dataPt, $subdirStart, $subdirLen);
                    $prefixLen = $subdirStart - $valuePtr;
                    $boxhdr = pack('N', strlen($newdir) + 8 + $prefixLen) . $boxID;
                    if ($prefixLen) $boxhdr .= substr($dataPt, $valuePtr, $prefixLen);
                    if (!ExifTool::Write($outfile, $boxhdr, $newdir)) $err = 1;
                    // write new colr box immediately after ihdr
                    if ($doColour and $boxID === 'ihdr') {
                        // (shouldn't be multiple ihdr boxes, but just in case, write only 1)
                        $doColour = $doColour == 2 ? self::CreateColorSpec($et, $outfile) : 0;
                    }
                } else {
                    // extract as a block if specified
                    if ($tagInfo->BlockExtract) $subdirInfo['BlockInfo'] = $tagInfo;
                    if ($uuid !== null and $uuid === '0') $et->Warn("Reading non-standard {$tagInfo->Name} box");
                    if (!$et->ProcessDirectory($subdirInfo, $subTable, $subdir['ProcessProc'] ?? null)) {
                        if ($subTable === $tagTablePtr) {
                            $err = 'JPEG 2000 format error';
                            break;
                        }
                        $et->Warn("Unrecognized {$tagInfo->Name} box");
                    }
                }
            } elseif ($tagInfo and $tagInfo->Format and !$outfile) {
                // only save tag values if Format was specified
                $rational = null;
                $val = ExifTool::ReadValue($dataPt, $valuePtr, $tagInfo->Format, null, $boxLen, $rational);
                if ($val !== null) {
                    $key = $et->FoundTag($tagInfo, $val);
                    // save Rational value
                    if ($rational !== null and $key !== null) $et->TAG_EXTRA[$key]['Rational'] = $rational;
                }
            } elseif ($outfile) {
                $boxhdr = pack('N', $boxLen + 8) . $boxID;
                if (!ExifTool::Write($outfile, $boxhdr, substr($dataPt, $valuePtr, $boxLen))) $err = 1;
            }
        }
        if ($err !== null) {
            if (!$err) $err = 'Truncated JPEG 2000 box';
            if ($outfile) {
                if (Perl::str($err) !== '1') $et->Error($err);
                return $raf ? -1 : null;
            }
            $et->Warn($err);
        }
        if ($outfile and !$raf) return $outBuff->value;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Return bits from a bitstream object
    // Inputs: 0) array ref, 1) number of bits
    // Returns: specified number of bits as an integer, and shifts input bitstream
    public static function GetBits(array &$a, $n)
    {
        $v = 0;
        $bit = 1;
        while ($n--) {
            for ($i = 0; $i < count($a); ++$i) {
                // consume bits LSB first
                $set = $a[$i] & 1;
                $a[$i] >>= 1;
                if ($i) {
                    if ($set) $a[$i - 1] |= 0x80;
                } else {
                    if ($set) $v |= $bit;
                    $bit <<= 1;
                }
            }
        }
        return $v;
    }

    //------------------------------------------------------------------------------
    // Read/write Brotli-encoded metadata
    // Inputs: 0) ExifTool ref, 1) dirInfoRef, 2) tag table ref
    // Returns: 1 on success when reading, or new data when writing (undef if unchanged)
    // (ref https://libjxl.readthedocs.io/en/latest/api_decoder.html)
    public static function ProcessBrotli(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;

        if (!(strlen($dataPt) > 4)) return 0;

        $isWriting = $dirInfo['IsWriting'] ?? null;
        $type = substr($dataPt, 0, 4);
        if (!$isWriting) $et->VerboseDir("Decrypted Brotli '{$type}'");
        $knownType = ['exif' => 'Exif', 'xml ' => 'xml ', 'jumb' => 'jumb'];
        $stdType = $knownType[strtolower($type)] ?? null;
        if (!$stdType) {
            $et->Warn('Unknown Brotli box type', 1);
            return 1;
        }
        if ($type !== $stdType) {
            $et->Warn("Incorrect case for Brotli '{$type}' data (should be '{$stdType}')");
            $type = $stdType;
        }
        if (function_exists('brotli_uncompress')) {
            if ($isWriting and !function_exists('brotli_compress')) {
                $et->Warn('Install IO::Compress::Brotli to write Brotli-compressed metadata');
                return null;
            }
            $compress = $et->Options('Compress');
            $verbose = $isWriting ? 0 : $et->Options('Verbose');
            $dat = @brotli_uncompress(substr($dataPt, 4));
            if ($dat === false) {
                self::BrotliWarn($et, $type, 1);
                return 1;
            }
            if ($verbose > 2) $et->VerboseDump($dat, Prefix: $et->INDENT . '  ');
            $subdirInfo = ['DataPt' => $dat];
            if ($type === 'xml ') {
                $subdirInfo['DirName'] = 'XMP';     // (necessary for block read/write)
                ExifTool::RequireModule('Image::ExifTool::XMP');
                if ($isWriting) {
                    if ($compress) $subdirInfo['Compact'] = 1;  // (no need to add padding if writing compressed)
                    $dat = $et->WriteDirectory($subdirInfo, $tagTablePtr);
                } else {
                    XMP::ProcessXMP($et, $subdirInfo, $tagTablePtr);
                }
            } elseif ($type === 'Exif') {
                $subdirInfo['DirName'] = 'EXIF';    // (necessary for block read/write)
                $subdirInfo['DirStart'] = 4 + (strlen($dat) > 4 ? Perl::unpack('N', $dat)[0] : 0);
                if ($subdirInfo['DirStart'] > strlen($dat)) {
                    $et->Warn("Corrupted Brotli '{$type}' data");
                } elseif ($isWriting) {
                    $dat = $et->WriteDirectory($subdirInfo, $tagTablePtr, 'Image\ExifTool\ExifTool::WriteTIFF');
                    // add back header word
                    if ($dat !== null and strlen($dat)) $dat = "\0\0\0\0" . $dat;
                } else {
                    $et->ProcessTIFF($subdirInfo, $tagTablePtr);
                }
            } elseif ($type === 'jumb') {
                if ($isWriting) return null;    // (can't yet write JUMBF)
                self::ProcessJUMB($et, $subdirInfo, $tagTablePtr);  // (untested)
            }
            if ($isWriting) {
                if ($dat === null) return null;
                // rewrite as uncompressed if Compress option is set to 0 (or '')
                if ($compress !== null and !$compress) return $dat;
                $dat = @brotli_compress($dat);
                if ($dat === false) {
                    self::BrotliWarn($et, $type);
                    return null;
                }
                $et->VPrint(0, "  Writing Brotli-compressed $type\n");
                return $type . $dat;
            }
        } else {
            $et->Warn('Install IO::Uncompress::Brotli to decode Brotli-compressed metadata');
            if ($isWriting) return null;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract parameters from JPEG XL codestream [unverified!]
    // Inputs: 0) ExifTool ref, 1) codestream ref
    // Returns: 1 on success
    public static function ProcessJXLCodestream(ExifTool $et, $dataPt)
    {
        $dataPt = $dataPt instanceof ScalarRef ? Perl::str($dataPt->value) : Perl::str($dataPt);
        if (!preg_match('/^(\0\0\0\0)?\xff\x0a/', $dataPt)) return 0;  // validate codestream
        // ignore if already extracted (ie. subsequent jxlp boxes)
        if ($et->ProcessedJXLCodestream) return 0;
        $et->ProcessedJXLCodestream = 1;
        // work with first 64 bytes of codestream data
        // (and add padding if necessary to avoid unpacking past end of data)
        if (strlen($dataPt) > 64) {
            $dat = substr($dataPt, 0, 64);
        } elseif (strlen($dataPt) < 18) {
            $dat = $dataPt . str_repeat("\0", 18);  // (so we'll have a minimum 14 bytes to work with)
        } else {
            $dat = $dataPt;
        }
        $dat = preg_replace('/^\0\0\0\0/', '', $dat);   // remove jxlp header word
        $a = Perl::unpack('x2C12', $dat);
        $small = self::GetBits($a, 1);
        if ($small) {
            $y = (self::GetBits($a, 5) + 1) * 8;
        } else {
            $y = self::GetBits($a, [9, 13, 18, 30][self::GetBits($a, 2)]) + 1;
        }
        $ratio = self::GetBits($a, 3);
        if ($ratio == 0) {
            if ($small) {
                $x = (self::GetBits($a, 5) + 1) * 8;
            } else {
                $x = self::GetBits($a, [9, 13, 18, 30][self::GetBits($a, 2)]) + 1;
            }
        } else {
            $r = [[1, 1], [12, 10], [4, 3], [3, 2], [16, 9], [5, 4], [2, 1]][$ratio - 1];
            $x = Perl::int($y * $r[0] / $r[1]);
        }
        $et->FoundTag('ImageWidth', $x);
        $et->FoundTag('ImageHeight', $y);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read/write meta information from a C2PA/JUMBF file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid JUMBF file
    public static function ProcessJUMBF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $hdr = null;

        // check to be sure this is a valid JPG2000 file
        if (!($raf->Read($hdr, 20) == 20 and $raf->Seek(0, 0))) return 0;
        if (!preg_match('/^.{4}jumb\0.{3}jumd(.{4})/', $hdr, $m)) return 0;
        $et->SetFileType($m[1] === 'c2pa' ? 'C2PA' : 'JUMBF');
        $subdirInfo = [
            'RAF' => $raf,
            'DirName' => 'JUMBF',
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Jpeg2000::Main');
        return $et->ProcessDirectory($subdirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Read/write meta information from a JPEG 2000 image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid JPEG 2000 file, or -1 on write error
    public static function ProcessJP2(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'] ?? null;
        $hdr = null;

        // check to be sure this is a valid JPG2000 file
        if ($raf->Read($hdr, 12) != 12) return 0;
        if (!($hdr === "\0\0\0\x0cjP  \x0d\x0a\x87\x0a" or         // (ref 1)
              $hdr === "\0\0\0\x0cjP\x1a\x1a\x0d\x0a\x87\x0a" or   // (ref 2)
              $et->IsJXL))
        {
            if (!preg_match('/^\xff\x4f\xff\x51\0/', $hdr)) return 0;   // check for JP2 codestream format
            if ($outfile) {
                $et->Error('Writing of J2C files is not yet supported');
                return 0;
            }
            // add J2C markers if not done already
            if (empty(\Image\ExifTool\Vars\ExifTool::$jpegMarker[0x4f])) {
                foreach (Vars::$j2cMarker as $k => $v) \Image\ExifTool\Vars\ExifTool::$jpegMarker[$k] = $v;
            }
            $et->SetFileType('J2C');
            $raf->Seek(0, 0);
            return $et->ProcessJPEG($dirInfo);  // decode with JPEG processor
        }
        if ($outfile) {
            if (!ExifTool::Write($outfile, $hdr)) return -1;
            if ($et->IsJXL) {
                $et->InitWriteDirs(Vars::$jxlMap);
                $et->AddJp2Tags = [];   // (don't add JP2 tags in JXL files)
            } else {
                $et->InitWriteDirs(Vars::$jp2Map);
                $et->AddJp2Tags = $et->GetNewTagInfoHash(ExifTool::GetTagTable('Image::ExifTool::Jpeg2000::Main'));
            }
            // save list of directories to create
            $et->AddJp2Dirs = $et->ADD_DIRS;    // (make a copy)
        } else {
            $buff = $fileType = null;
            // recognize JPX and JPM as unique types of JP2
            if ($raf->Read($buff, 12) == 12 and preg_match('/^.{4}ftyp(.{4})/s', $buff, $m)) {
                if ($m[1] === 'jpx ') $fileType = 'JPX';
                if ($m[1] === 'jpm ') $fileType = 'JPM';
                if ($m[1] === 'jxl ') $fileType = 'JXL';
                if ($m[1] === 'jph ') $fileType = 'JPH';
            }
            if ($buff !== null) $raf->Seek(-strlen($buff), 1);
            $et->SetFileType($fileType);
        }
        ExifTool::SetByteOrder('MM');   // JPEG 2000 files are big-endian
        $subdirInfo = [     // (Perl %dirInfo)
            'RAF' => $raf,
            'DirName' => 'JP2',
            'OutFile' => $dirInfo['OutFile'] ?? null,
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Jpeg2000::Main');
        return $et->ProcessDirectory($subdirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Read/write meta information in a JPEG XL image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid JPEG XL file, -1 on write error
    public static function ProcessJXL(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'] ?? null;
        $hdr = $buff = null;

        if ($raf->Read($hdr, 12) != 12) return 0;
        if ($hdr === "\0\0\0\x0cJXL \x0d\x0a\x87\x0a") {
            // JPEG XL in ISO BMFF container
            $et->IsJXL = 1;
        } elseif (preg_match('/^\xff\x0a/', $hdr)) {
            // JPEG XL codestream
            if ($outfile) {
                if ($et->OPTIONS['IgnoreMinorErrors']) {
                    $et->Warn('Wrapped JXL codestream in ISO BMFF container');
                } else {
                    $et->Error('Will wrap JXL codestream in ISO BMFF container for writing', 1);
                    return 0;
                }
                $et->IsJXL = 2;
                $buf = "\0\0\0\x0cJXL \x0d\x0a\x87\x0a\0\0\0\x14ftypjxl \0\0\0\0jxl ";
                // add metadata to empty ISO BMFF container
                $dirInfo['RAF'] = new File\RandomAccess($buf);
            } else {
                $et->SetFileType('JXL Codestream', 'image/jxl', 'jxl');
                if ($et->ImageDataHash and $raf->Seek(0, 0)) {
                    $et->ImageDataHash($raf, null, 'JXL');
                }
                return self::ProcessJXLCodestream($et, $hdr);
            }
        } else {
            return 0;
        }
        if (!$raf->Seek(0, 0)) {
            $et->Error('Seek error');
            return 0;
        }

        $success = self::ProcessJP2($et, $dirInfo);

        if ($outfile and $success > 0 and $et->IsJXL == 2) {
            // attach the JXL codestream box to the ISO BMFF file
            if (!$raf->Seek(0, 2)) return -1;
            $size = $raf->Tell();
            if (!$raf->Seek(0, 0)) return -1;
            ExifTool::SetByteOrder('MM');
            if (!ExifTool::Write($outfile, ExifTool::Set32u($size + 8), 'jxlc')) return -1;
            while ($raf->Read($buff, 65536)) {
                if (!ExifTool::Write($outfile, $buff)) return -1;
            }
        }
        return $success;
    }
}
