<?php

//------------------------------------------------------------------------------
// File:         GIF.pm
//
// Description:  Read and write GIF meta information
//
// Revisions:    10/18/2005 - P. Harvey Separated from ExifTool.pm
//               05/23/2008 - P. Harvey Added ability to read/write XMP
//               10/28/2011 - P. Harvey Added ability to read/write ICC_Profile
//
// References:   1) http://www.w3.org/Graphics/GIF/spec-gif89a.txt
//               2) http://www.adobe.com/devnet/xmp/
//               3) http://graphcomp.com/info/specs/ani_gif.html
//               4) http://www.color.org/icc_specs2.html
//               5) http://www.midiox.com/mmgif.htm
//               6) https://web.archive.org/web/20250724171508/https://...
//
// PHP port of Image::ExifTool::GIF 1.21. Tag tables are generated into tables/GIF.php,
// the file lexicals %gifMap and @appExtensions into Vars\GIF (ProcessGIF adds the
// user-defined writable application extensions to them, like Perl).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GIF
{
    public static $VERSION = '1.21';

    //------------------------------------------------------------------------------
    // Read/write meta information in GIF image
    // Inputs: 0) ExifTool object reference, 1) Directory information ref
    // Returns: 1 on success, 0 if this wasn't a valid GIF file, or -1 if
    //          an output file was specified and a write error occurred
    public static function ProcessGIF(ExifTool $et, array &$dirInfo)
    {
        $outfile = $dirInfo['OutFile'] ?? null;
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $a = $s = $ch = $length = $buff = null;
        $err = $newComment = $setComment = $nvComment = $newExt = null;
        $addDirs = null;
        $doneDir = [];
        [$frameCount, $delayTime] = [0, 0];

        // verify this is a valid GIF file
        if (!($raf->Read($buff, 6) == 6
            and preg_match('/^GIF(8[79]a)$/', $buff, $m)
            and $raf->Read($s, 7) == 7))
        {
            return 0;
        }

        $ver = $m[1];
        $rtnVal = 0;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::GIF::Main');
        $extTable = ExifTool::GetTagTable('Image::ExifTool::GIF::Extensions');
        ExifTool::SetByteOrder('II');

        if ($outfile) {
            // add any user-defined writable app extensions to the list
            $exts = array_map('strval', $extTable->keys());
            sort($exts, SORT_STRING);
            foreach ($exts as $ext) {
                if (!($extTable[$ext] instanceof TagInfo)) continue;
                $extInfo = $extTable[$ext];
                if (!($extInfo->SubDirectory and $extInfo->Writable and !isset(Vars\GIF::$gifMap[$extInfo->Name]))) continue;
                Vars\GIF::$gifMap[$extInfo->Name] = 'GIF';
                Vars\GIF::$appExtensions[] = $ext;
            }
            $et->InitWriteDirs(Vars\GIF::$gifMap, 'XMP');   // make XMP the preferred group for GIF
            $addDirs = $et->ADD_DIRS;
            // determine if we are editing the File:Comment tag
            $delGroup = $et->DEL_GROUP;
            $newComment = $et->GetNewValue('Comment', $nvComment);
            if ($nvComment or ($delGroup['File'] ?? null)) $setComment = 1;
            // change to GIF 89a if adding comment, XMP or ICC_Profile
            if ($addDirs or $newComment !== null) $buff = 'GIF89a';
            if (!ExifTool::Write($outfile, $buff, $s)) $err = 1;
            $newExt = $et->GetNewTagInfoHash($extTable);
        } else {
            $et->SetFileType();     // set file type
            $et->HandleTag($tagTablePtr, 'GIFVersion', $ver);
            $et->HandleTag($tagTablePtr, 'ScreenDescriptor', $s);
        }
        $flags = ExifTool::Get8u($s, 4);
        if ($flags & 0x80) {    // does this image contain a color table?
            // calculate color table size
            $length = 3 * (2 << ($flags & 0x07));
            if ($raf->Read($buff, $length) != $length) return 0;    // skip color table
            if ($outfile and !ExifTool::Write($outfile, $buff)) $err = 1;
        }
//
// loop through GIF blocks
//
        for (;;) {  // (Perl label "Block")
            if (!$raf->Read($ch, 1)) break;
            // write out any new metadata now if this isn't an extension block
            if ($outfile and ord($ch) != 0x21) {
                // write the comment first if necessary
                if ($newComment !== null and $nvComment->IsCreating) {
                    // write comment marker
                    if (!ExifTool::Write($outfile, "\x21\xfe")) $err = 1;
                    if ($verbose) fwrite($out, "  + Comment = $newComment\n");
                    $len = strlen($newComment);
                    // write out the comment in 255-byte chunks, each
                    // chunk beginning with a length byte
                    for ($n = 0; $n < $len; $n += 255) {
                        $size = $len - $n;
                        if ($size > 255) $size = 255;
                        $str = substr($newComment, $n, $size);
                        if (!ExifTool::Write($outfile, pack('C', $size), $str)) $err = 1;
                    }
                    if (!ExifTool::Write($outfile, "\0")) $err = 1;     // empty chunk as terminator
                    $newComment = null;
                    $nvComment = null;  // delete any other extraneous comments
                    ++$et->CHANGED;     // increment file changed flag
                }
                // add application extensions if necessary
                $new = array_map('strval', array_keys($newExt));
                sort($new, SORT_STRING);
                foreach ([...Vars\GIF::$appExtensions, ...$new] as $ext) {
                    $extInfo = $extTable[$ext];
                    $name = $extInfo->Name;
                    if ($newExt[$ext] ?? null) {
                        unset($newExt[$ext]);
                        $doneDir[$name] = 1;    // (we wrote this as a block instead)
                        $buff = $et->GetNewValue($extInfo);
                        $et->VerboseValue("+ GIF:$name", $buff);
                    } elseif (array_key_exists($name, $et->ADD_DIRS) and !isset($doneDir[$name])) {
                        $doneDir[$name] = 1;
                        $tbl = ExifTool::GetTagTable($extInfo->SubDirectory['TagTable']);
                        $subdirInfo = ['Parent' => 'GIF'];
                        if ($verbose) fwrite($out, "Creating $name application extension block:\n");
                        $buff = $et->WriteDirectory($subdirInfo, $tbl);
                    } else {
                        continue;
                    }
                    if ($buff !== null and strlen($buff)) {
                        ++$et->CHANGED;
                        if (!ExifTool::Write($outfile, "\x21\xff\x0b", substr($ext, 0, 8), substr($ext, 9, 3))) $err = 1;
                        $pos = 0;
                        if (!$extTable[$ext]->IncludeLengthBytes) {
                            $len = strlen($buff);
                            while ($pos < strlen($buff)) {
                                $n = strlen($buff) - $pos;
                                if ($n > 255) $n = 255;
                                if (!ExifTool::Write($outfile, chr($n), substr($buff, $pos, $n))) $err = 1;
                                $pos += $n;
                            }
                            if (!ExifTool::Write($outfile, "\0")) $err = 1;     // write null terminator
                        } elseif ($extTable[$ext]->IncludeLengthBytes < 2) {
                            while ($pos < strlen($buff)) $pos += ord(substr($buff, $pos, 1)) + 1;
                            // write data, null padding and terminator
                            if (!ExifTool::Write($outfile, $buff, str_repeat("\0", $pos - strlen($buff) + 1))) $err = 1;
                        } else {
                            // write data, landing zone and null terminator
                            if (!ExifTool::Write($outfile, $buff, pack('C*', ...[1, ...array_reverse(range(0, 255)), 0]))) $err = 1;
                        }
                        ++$doneDir[$name];  // set to 2 to indicate we added it
                    } else {
                        if ($verbose) fwrite($out, "  -> no $name to add\n");
                    }
                }
            }
            if (ord($ch) == 0x2c) {
                ++$frameCount;
                if ($outfile and !ExifTool::Write($outfile, $ch)) $err = 1;
                // image descriptor
                if (!($raf->Read($buff, 8) == 8 and $raf->Read($ch, 1))) break;
                if ($outfile and !ExifTool::Write($outfile, $buff, $ch)) $err = 1;
                if ($verbose and !$outfile) {
                    [$left, $top, $w, $h] = Perl::unpack('v*', $buff);
                    fwrite($out, "Image: left=$left top=$top width=$w height=$h\n");
                }
                if (ord($ch) & 0x80) {  // does color table exist?
                    $length = 3 * (2 << (ord($ch) & 0x07));
                    // skip the color table
                    if ($raf->Read($buff, $length) != $length) break;
                    if ($outfile and !ExifTool::Write($outfile, $buff)) $err = 1;
                }
                // skip "LZW Minimum Code Size" byte
                if (!$raf->Read($buff, 1)) break;
                if ($outfile and !ExifTool::Write($outfile, $buff)) $err = 1;
                // skip image blocks
                for (;;) {
                    if (!$raf->Read($ch, 1)) break;
                    if ($outfile and !ExifTool::Write($outfile, $ch)) $err = 1;
                    if (!ord($ch)) break;
                    if (!$raf->Read($buff, ord($ch))) break;
                    if ($outfile and !ExifTool::Write($outfile, $buff)) $err = 1;
                }
                continue;   // continue with next field
            }
//               last if ord($ch) == 0x3b;  # normal end of GIF marker
            if (ord($ch) != 0x21) {
                if ($outfile) {
                    if (!ExifTool::Write($outfile, $ch)) $err = 1;
                    // copy the rest of the file
                    while ($raf->Read($buff, 65536)) {
                        if (!ExifTool::Write($outfile, $buff)) $err = 1;
                    }
                }
                $rtnVal = 1;
                break;
            }
            // get extension block type/size
            if ($raf->Read($s, 2) != 2) break;
            // get marker and block size
            [$a, $length] = Perl::unpack('CC', $s);

            if ($a == 0xfe) {                           // comment extension

                $comment = '';
                while ($length) {
                    if ($raf->Read($buff, $length) != $length) break;
                    if (!$outfile) $et->VerboseDump($buff);
                    // add buffer to comment string
                    $comment .= $buff;
                    if (!$raf->Read($ch, 1)) break;     // read next block header
                    $length = ord($ch);     // get next block size
                }
                if ($length) break;     // was a read error if length isn't zero
                if ($outfile) {
                    $isOverwriting = null;
                    if ($setComment) {
                        if ($nvComment) {
                            $isOverwriting = $et->IsOverwriting($nvComment, $comment);
                            // get new comment again (may have been shifted)
                            if ($newComment !== null) $newComment = $et->GetNewValue($nvComment);
                        } else {
                            // group delete, or deleting additional comments after writing one
                            $isOverwriting = 1;
                        }
                    }
                    if ($isOverwriting) {
                        ++$et->CHANGED;     // increment file changed flag
                        $et->VerboseValue('- GIF:Comment', $comment);
                        $comment = $newComment;
                        if ($comment !== null) $et->VerboseValue('+ GIF:Comment', $comment);
                        $nvComment = null;  // just delete remaining comments
                    } else {
                        $setComment = null; // leave remaining comments alone
                    }
                    if ($comment !== null) {
                        // write comment marker
                        if (!ExifTool::Write($outfile, "\x21\xfe")) $err = 1;
                        $len = strlen($comment);
                        // write out the comment in 255-byte chunks, each
                        // chunk beginning with a length byte
                        for ($n = 0; $n < $len; $n += 255) {
                            $size = $len - $n;
                            if ($size > 255) $size = 255;
                            $str = substr($comment, $n, $size);
                            if (!ExifTool::Write($outfile, pack('C', $size), $str)) $err = 1;
                        }
                        if (!ExifTool::Write($outfile, "\0")) $err = 1;     // empty chunk as terminator
                    }
                    $newComment = null;     // don't write the new comment again
                } else {
                    $rtnVal = 1;
                    if ($comment) $et->FoundTag('Comment', $comment);
                    $comment = null;
                    // assume no more than one comment in FastScan mode
                    if ($et->Options('FastScan')) break;
                }
                continue;

            } elseif ($a == 0xff and $length == 0x0b) {  // application extension

                if ($raf->Read($buff, $length) != $length) break;
                $hdr = "$ch$s$buff";
                // add "/" for readability
                $tag = substr($buff, 0, 8) . '/' . substr($buff, 8);
                $tag = preg_replace('/[\x00-\x1f]/', '', $tag);  // remove nulls and control characters
                if ($verbose) fwrite($out, "Application Extension: $tag\n");

                $extInfo = $extTable[$tag];
                $subdir = $inclLen = $justCopy = $name = null;
                if ($extInfo) {
                    if ($outfile and ($newExt[$extInfo->TagID] ?? null)) {
                        unset($newExt[$extInfo->TagID]);   // don't create again
                        // (write as a block -- don't define $subdir)
                    } else {
                        $subdir = $extInfo->SubDirectory;
                    }
                    $inclLen = $extInfo->IncludeLengthBytes;
                    $name = $extInfo->Name;
                    // rewrite as-is unless this is a writable
                    if ($outfile and !$extInfo->Writable) $justCopy = 1;
                } else {
                    if ($outfile) $justCopy = 1;
                }
                if ($justCopy and !ExifTool::Write($outfile, $hdr)) $err = 1;

                // read the extension data
                $dat = '';
                for (;;) {
                    if (!$raf->Read($ch, 1)) break 2;   // read next block header
                    $length = ord($ch);                 // get next block size
                    if (!$length) break;
                    if ($raf->Read($buff, $length) != $length) break 2;
                    if ($justCopy and !ExifTool::Write($outfile, $ch, $buff)) $err = 1;
                    $dat .= $inclLen ? $ch . $buff : $buff;
                }
                if ($justCopy) {
                    if (!ExifTool::Write($outfile, "\0")) $err = 1;
                    continue;
                } elseif ($inclLen) {
                    // remove landing zone or padding
                    if ($extInfo->Terminator and preg_match('#' . $extInfo->Terminator . '#', $dat, $mm, PREG_OFFSET_CAPTURE)) {
                        $dat = substr($dat, 0, $mm[0][1] + strlen($mm[0][0]));
                    } elseif (($p = strpos($dat, "\0")) !== false) {
                        $dat = substr($dat, 0, $p);
                    }
                }
                if ($subdir) {
                    $subdirInfo = [     // (Perl %dirInfo)
                        'DataPt'  => $dat,
                        'DataLen' => strlen($dat),
                        'DirLen'  => strlen($dat),
                        'DirName' => $name,
                        'Parent'  => 'GIF',
                    ];
                    $subTable = ExifTool::GetTagTable($subdir['TagTable']);
                    if (!$outfile) {
                        $et->ProcessDirectory($subdirInfo, $subTable);
                        continue;
                    }
                    if ($justCopy) continue;
                    if (($doneDir[$name] ?? null) and $doneDir[$name] > 1) {
                        $et->Warn("Duplicate $name block created");
                    }
                    $buff = $et->WriteDirectory($subdirInfo, $subTable);
                    if ($buff !== null) {
                        if (!strlen($buff)) continue;   // delete this extension if length is zero
                        $dat = $buff;
                    }
                    $doneDir[$name] = 1;
                } elseif ($outfile and !$justCopy) {
                    $nvHash = $et->GetNewValueHash($extInfo);
                    if ($nvHash and $et->IsOverwriting($nvHash, $dat)) {
                        ++$et->CHANGED;
                        $val = $et->GetNewValue($extInfo);
                        $et->VerboseValue("- GIF:$name", $dat);
                        if (!($val !== null and strlen($val))) continue;
                        $dat = $val;
                        $et->VerboseValue("+ GIF:$name", $dat);
                        $doneDir[$name] = 1;    // (possibly wrote dir as a block)
                    }
                } elseif (!$outfile) {
                    $et->HandleTag($extTable, $tag, $dat);
                    continue;
                }
                if (!ExifTool::Write($outfile, $hdr)) $err = 1;     // write extension header
                if ($inclLen) {
                    // check for null just to be safe
                    if ($inclLen and str_contains($dat, "\0")) $et->Error("$name contained NULL character");
                    if ($inclLen > 1) {
                        // add landing zone (without terminator, which will be added later)
                        if ($inclLen) $dat .= pack('C*', 1, ...array_reverse(range(0, 255)));
                    } else {
                        // pad with nulls as required
                        $pos = 0;
                        while ($pos < strlen($dat)) $pos += ord(substr($dat, $pos, 1)) + 1;
                        $dat .= str_repeat("\0", max(0, $pos - strlen($dat)));
                    }
                    // write data and landing zone
                    if (!ExifTool::Write($outfile, $dat)) $err = 1;
                } else {
                    // write as sub-blocks
                    $pos = 0;
                    $len = strlen($dat);
                    while ($pos < $len) {
                        $n = $len - $pos;
                        if ($n > 255) $n = 255;
                        if (!ExifTool::Write($outfile, chr($n), substr($dat, $pos, $n))) $err = 1;
                        $pos += $n;
                    }
                }
                if (!ExifTool::Write($outfile, "\0")) $err = 1;     // write null terminator
                continue;

            } elseif ($a == 0xf9 and $length == 4) {     // graphic control extension

                if ($raf->Read($buff, $length) != $length) break;
                // sum the individual delay times
                $delay = ExifTool::Get16u($buff, 1);
                $delayTime += $delay;
                if ($verbose) fwrite($out, Perl::sprintf("Graphic Control: delay=%.2f\n", $delay / 100));
                // get transparent colour
                $bits = ExifTool::Get8u($buff, 0);
                if ($bits & 0x01) $et->HandleTag($tagTablePtr, 'TransparentColor', ExifTool::Get8u($buff, 3));
                if (!$raf->Seek(-$length, 1)) break;

            } elseif ($a == 0x01 and $length == 12) {    // plain text extension

                if ($raf->Read($buff, $length) != $length) break;
                if ($outfile and !ExifTool::Write($outfile, $ch, $s, $buff)) $err = 1;
                if ($verbose and !$outfile) {
                    [$left, $top, $w, $h] = Perl::unpack('v4', $buff);
                    fwrite($out, "Text: left=$left top=$top width=$w height=$h\n");
                }
                $text = '';
                for (;;) {
                    if (!$raf->Read($ch, 1)) break;
                    $length = ord($ch);
                    if (!$length) break;
                    if ($raf->Read($buff, $length) != $length) break;
                    if ($outfile and !ExifTool::Write($outfile, $ch, $buff)) $err = 1;   // write block
                    $text .= $buff;
                }
                if ($outfile and !ExifTool::Write($outfile, "\0")) $err = 1;    // write terminator block
                $et->HandleTag($tagTablePtr, 'Text', $text);
                continue;
            }
            if ($outfile and !ExifTool::Write($outfile, $ch, $s)) $err = 1;
            // skip the block
            while ($length) {
                if ($raf->Read($buff, $length) != $length) break;
                if ($outfile and !ExifTool::Write($outfile, $buff)) $err = 1;
                if (!$raf->Read($ch, 1)) break;     // read next block header
                if ($outfile and !ExifTool::Write($outfile, $ch)) $err = 1;
                $length = ord($ch);     // get next block size
            }
        }
        if (!$outfile) {
            if ($frameCount > 1) $et->HandleTag($tagTablePtr, 'FrameCount', $frameCount);
            if ($delayTime) $et->HandleTag($tagTablePtr, 'Duration', $delayTime / 100);
        }

        // set return value to -1 if we only had a write error
        if ($rtnVal and $err) $rtnVal = -1;
        return $rtnVal;
    }
}
