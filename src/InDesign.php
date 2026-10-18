<?php

//------------------------------------------------------------------------------
// File:         InDesign.pm
//
// Description:  Read/write meta information in Adobe InDesign files
//
// Revisions:    2009-06-17 - P. Harvey Created
//
// References:   1) http://www.adobe.com/devnet/xmp/pdfs/XMPSpecificationPart3.pdf
//
// PHP port of Image::ExifTool::InDesign 1.09. File lexicals (%indMap and the GUID's) are
// generated into tables/vars/InDesign.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class InDesign
{
    public static $VERSION = '1.09';

    //------------------------------------------------------------------------------
    // Read or write meta information in an InDesign file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid InDesign file, or -1 on write error
    public static function ProcessIND(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'] ?? null;
        $hdr = $buff = $buf2 = $err = $writeLen = $foundXMP = null;
        $masterPageGUID = Vars\InDesign::$masterPageGUID;

        // validate the InDesign file
        if ($raf->Read($hdr, 16) != 16) return 0;
        if ($hdr !== $masterPageGUID) return 0;
        if ($raf->Read($buff, 8) != 8) return 0;
        $et->SetFileType($buff === 'DOCUMENT' ? 'INDD' : 'IND');   // set the FileType tag

        // read the master pages
        if (!$raf->Seek(0, 0)) {
            $err = 'Seek error';
            goto DONE;
        }
        if (!($raf->Read($buff, 4096) == 4096 and
              $raf->Read($buf2, 4096) == 4096))
        {
            $err = 'Unexpected end of file';
            goto DONE; // (goto's can be our friend)
        }
        ExifTool::SetByteOrder('II');
        if (!str_starts_with($buf2, $masterPageGUID)) {
            $err = 'Second master page is invalid';
            goto DONE;
        }
        $seq1 = ExifTool::Get64u($buff, 264);
        $seq2 = ExifTool::Get64u($buf2, 264);
        // take the most current master page
        $curPage = $seq2 > $seq1 ? $buf2 : $buff;
        // byte order of stream data may be different than headers
        $streamInt32u = ExifTool::Get8u($curPage, 24);
        if ($streamInt32u == 1) {
            $streamInt32u = 'V'; // little-endian int32u
        } elseif ($streamInt32u == 2) {
            $streamInt32u = 'N'; // big-endian int32u
        } else {
            $err = 'Invalid stream byte order';
            goto DONE;
        }
        $pages = ExifTool::Get32u($curPage, 280);
        if ($pages < 2) {
            $err = 'Invalid page count';
            goto DONE;
        }
        $pos = $pages * 4096;
        if ($pos > 0x7fffffff) {
            if (!$et->Options('LargeFileSupport')) {
                $err = 'InDesign files larger than 2 GB not supported (LargeFileSupport not set)';
                goto DONE;
            } elseif (Perl::str($et->Options('LargeFileSupport')) === '2') {
                $et->Warn('Processing large file (LargeFileSupport is 2)');
            }
        }
        if ($outfile) {
            // make XMP the preferred group for writing
            $et->InitWriteDirs(Vars\InDesign::$indMap, 'XMP');

            if (!ExifTool::Write($outfile, $buff, $buf2)) {
                $err = 1;
                goto DONE;
            }
            $result = ExifTool::CopyBlock($raf, $outfile, $pos - 8192);
            if (!$result) {
                $err = $result !== null ? 'Error reading InDesign database' : 1;
                goto DONE;
            }
            $writeLen = 0;
        } elseif (!$raf->Seek($pos, 0)) {
            $err = 'Seek error';
            goto DONE;
        }
        // scan through the contiguous objects for XMP
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        for (;;) {
            if (!$raf->Read($hdr, 32)) break;
            if (!(strlen($hdr) == 32 and str_starts_with($hdr, Vars\InDesign::$objectHeaderGUID))) {
                // this must be null padding or we have a possible error
                if (preg_match('/^\0+$/', $hdr)) break;
                // (could be up to 4095 bytes of non-null garbage plus 4095 null bytes from ExifTool)
                if ($raf->Read($buff, 8192)) $hdr .= $buff;
                $n = strlen($hdr);
                $hdr = preg_replace('/\0+$/', '', $hdr);   // remove trailing nulls
                if ($n > 8190 or strlen($hdr) > 4095) {
                    $err = 'Corrupt file or unsupported InDesign version';
                    break;
                }
                $non = 'Non-null padding at end of file';
                if (!$outfile) {
                    $et->Warn($non, 1);
                } elseif (!$et->Error($non, 1)) {
                    if (!ExifTool::Write($outfile, $hdr)) $err = 1;
                    $writeLen += strlen($hdr);
                }
                break;
            }
            $len = ExifTool::Get32u($hdr, 24);
            if ($verbose) {
                fwrite($out, Perl::sprintf("Contiguous object at offset 0x%x (%d bytes):\n", $raf->Tell(), $len));
                if ($verbose > 2) {
                    $len2 = $len < 1024000 ? $len : 1024000;
                    if (!$raf->Seek(-$raf->Read($buff, $len2), 1)) $err = 1;
                    $et->VerboseDump($buff, Addr: $raf->Tell());
                }
            }
            // check for XMP if stream data is long enough
            // (56 bytes is just enough for XMP header)
            if ($len > 56) {
                if ($raf->Read($buff, 56) != 56) {
                    $err = 'Unexpected end of file';
                    break;
                }
                if (preg_match('/^(....)<\?xpacket begin=([\'"])\xef\xbb\xbf\2 id=([\'"])W5M0MpCehiHzreSzNTczkc9d\3/s', $buff, $m)) {
                    $lenWord = $m[1];   // save length word for writing later
                    $len -= 4;          // get length of XMP only
                    $foundXMP = 1;
                    // I have a sample where the XMP is 107 MB, and ActivePerl may run into
                    // memory troubles (with its apparent 1 GB limit) if the XMP is larger
                    // than about 400 MB, so guard against this
                    if ($len > 300 * 1024 * 1024) {
                        $msg = Perl::sprintf('Insanely large XMP (%.0f MiB)', $len / (1024 * 1024));
                        if ($outfile) {
                            if ($et->Error($msg, 2)) {
                                $err = 1;
                                break;
                            }
                        } elseif ($et->Options('IgnoreMinorErrors')) {
                            $et->Warn($msg);
                        } else {
                            $et->Warn("$msg. Ignored.", 1);
                            $err = 1;
                            break;
                        }
                    }
                    // load and parse the XMP data
                    if (!($raf->Seek(-52, 1) and $raf->Read($buff, $len) == $len)) {
                        $err = 'Error reading XMP stream';
                        break;
                    }
                    $xmpInfo = [    // (Perl: my %dirInfo)
                        'DataPt'  => $buff,
                        'Parent'  => 'IND',
                        'NoDelete' => 1, // do not allow this to be deleted when writing
                    ];
                    // validate xmp data length (should be same as length in header - 4)
                    $xmpLen = Perl::unpack($streamInt32u, $lenWord)[0];
                    if ($xmpLen != $len) {
                        if ($xmpLen < $len) {
                            $xmpInfo['DirLen'] = $xmpLen;
                        } else {
                            $err = 'Truncated XMP stream (missing ' . ($xmpLen - $len) . ' bytes)';
                        }
                    }
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
                    if ($outfile) {
                        if ($err) break;
                        // make sure that XMP is writable
                        $classID = ExifTool::Get32u($hdr, 20);
                        if (!($classID & 0x40000000)) {
                            $err = 'XMP stream is not writable';
                            break;
                        }
                        $xmp = $et->WriteDirectory($xmpInfo, $tagTablePtr);
                        if ($xmp and strlen($xmp)) {
                            // write new xmp with leading length word
                            $buff = Perl::pack($streamInt32u, strlen($xmp)) . $xmp;
                            // update header with new length and invalid checksum
                            ExifTool::Set32u(strlen($buff), $hdr, 24);
                            ExifTool::Set32u(0xffffffff, $hdr, 28);
                        } else {
                            $et->CHANGED = 0;   // didn't change anything
                            if ($xmp !== null) $et->Warn("Can't delete XMP as a block from InDesign file");
                            // put length word back at start of stream
                            $buff = $lenWord . $buff;
                        }
                    } else {
                        $et->ProcessDirectory($xmpInfo, $tagTablePtr);
                    }
                    $len = 0;   // we got the full stream (nothing left to read)
                } else {
                    $len -= 56; // we got 56 bytes of the stream
                }
            } else {
                $buff = '';     // must reset this for writing later
            }
            if ($outfile) {
                // write object header and data
                if (!ExifTool::Write($outfile, $hdr, $buff)) {
                    $err = 1;
                    break;
                }
                $result = ExifTool::CopyBlock($raf, $outfile, $len);
                if (!$result) {
                    $err = $result !== null ? 'Truncated stream data' : 1;
                    break;
                }
                $writeLen += 32 + strlen($buff) + $len;
            } elseif ($len) {
                // skip over remaining stream data
                if (!$raf->Seek($len, 1)) {
                    $err = 'Seek error';
                    break;
                }
            }
            if ($raf->Read($buff, 32) != 32) {
                $err = 'Unexpected end of file';
                break;
            }
            if (!str_starts_with($buff, Vars\InDesign::$objectTrailerGUID)) {
                $err = 'Invalid object trailer';
                break;
            }
            if ($outfile) {
                // make sure object UID and ClassID are the same in the trailer
                if (substr($hdr, 16, 8) !== substr($buff, 16, 8)) {
                    $err = 'Non-matching object trailer';
                    break;
                }
                // write object trailer
                if (!ExifTool::Write($outfile, Vars\InDesign::$objectTrailerGUID, substr($hdr, 16))) {
                    $err = 1;
                    break;
                }
                $writeLen += 32;
            }
        }
        if ($outfile) {
            // write null padding if necessary
            // (InDesign files must be an even number of 4096-byte blocks)
            $part = $writeLen % 4096;
            if ($part) {
                if (!ExifTool::Write($outfile, str_repeat("\0", 4096 - $part))) $err = 1;
            }
        }
DONE:
        if (!$err) {
            if ($outfile and !$foundXMP) $et->Warn('No XMP stream to edit');
            return 1;       // success!
        } elseif (!$outfile) {
            // issue warning on read error
            if (Perl::str($err) !== '1') $et->Warn($err);
        } elseif (Perl::str($err) !== '1') {
            // set error and return success code
            $et->Error($err);
        } else {
            return -1;      // write error
        }
        return 1;
    }
}
