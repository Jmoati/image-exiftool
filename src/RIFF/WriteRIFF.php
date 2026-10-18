<?php

//------------------------------------------------------------------------------
// File:         WriteRIFF.pl
//
// Description:  Write RIFF-format files
//
// Revisions:    2020-09-26 - P. Harvey Created
//
// Notes:        Currently writes only WEBP files
//
// References:   https://developers.google.com/speed/webp/docs/riff_container
//
// PHP port of the whole file (package Image::ExifTool::RIFF), mixed into class RIFF.
//------------------------------------------------------------------------------

namespace Image\ExifTool\RIFF;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\TagInfo;

trait WriteRIFF
{
    // map of where information is stored in WebP image
    /** my %webpMap */
    private static array $webpMap = [
       'XMP '        => 'RIFF', // (the RIFF chunk name is 'XMP ')
        'EXIF'         => 'RIFF',
        'ICCP'         => 'RIFF',
        'C2PA'         => 'RIFF',
        'JUMBF'        => 'C2PA',
        'XMP'          => 'XMP ',
        'IFD0'         => 'EXIF',
        'IFD1'         => 'IFD0',
        'ICC_Profile'  => 'ICCP',
        'ExifIFD'      => 'IFD0',
        'GPS'          => 'IFD0',
        'SubIFD'       => 'IFD0',
        'GlobParamIFD' => 'IFD0',
        'PrintIM'      => 'IFD0',
        'InteropIFD'   => 'ExifIFD',
        'MakerNotes'   => 'ExifIFD',
    ];

    /** my %deletableGroup */
    private static array $deletableGroup = [
        "XMP\0" => 'XMP', // delete incorrectly written "XMP\0" tag with XMP group
        'SEAL' => 'SEAL',   // delete SEAL tag with SEAL group
    ];

    //------------------------------------------------------------------------------
    // Write RIFF file (currently WebP-type only)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid RIFF file, or -1 if
    //          an output file was specified and a write error occurred
    public static function WriteRIFF(?ExifTool $et = null, ?array &$dirInfo = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package
        $outfile = $dirInfo['OutFile'];
        $outsize = 0;
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $err = $imageWidth = $imageHeight = null;
        $has = $dirDat = [];

        // do this in 2 passes so we can set the size of the containing RIFF chunk
        // without having to buffer the output (also to set the WebP_Flags)
        for ($pass = 0; ; ++$pass) {
            $doneDir = [];
            // verify this is a valid RIFF file
            if ($raf->Read($buff, 12) != 12) return 0;
            if (!preg_match('/^(RIFF|RF64)....(.{4})/s', $buff, $m)) return 0;

            if (!($m[1] === 'RIFF' and $m[2] === 'WEBP')) {
                $type = preg_replace('/[^-_a-zA-Z]/', '', $m[2]);
                $et->Error("Can't currently write $m[1] $type files");
                return 1;
            }
            ExifTool::SetByteOrder('II');

            // determine which directories we must write for this file type
            if ($pass) $et->Options('Verbose', 0);  // (avoid duplicate Verbose options here)
            $et->InitWriteDirs(self::$webpMap);
            $addDirs = &$et->ADD_DIRS;
            $editDirs = &$et->EDIT_DIRS;
            if ($addDirs['EXIF'] ?? null) $addDirs['IFD0'] = 'EXIF';  // set flag to add IFD0 if adding EXIF (don't ask)
            $createVP8X = $deleteVP8X = null;

            // write header
            if ($pass) {
                $et->Options('Verbose', $verbose);
                $needsVP8X = (($has['ANIM'] ?? null) or ($has['XMP '] ?? null) or ($has['EXIF'] ?? null) or
                              ($has['ALPH'] ?? null) or ($has['ICCP'] ?? null));
                if (($has['VP8X'] ?? null) and !$needsVP8X and $et->CHANGED) {
                    $deleteVP8X = 1;    // delete the VP8X chunk
                    $outsize -= 18;     // account for missing VP8X
                } elseif ($needsVP8X and !($has['VP8X'] ?? null)) {
                    if ($imageWidth !== null) {
                        ++$et->CHANGED;
                        $createVP8X = 1;    // add VP8X chunk
                        $outsize += 18;     // account for VP8X size
                    } else {
                        $et->Warn('Error getting image size for required VP8X chunk');
                    }
                }
                // finally we can set the overall RIFF chunk size:
                ExifTool::Set32u($outsize - 8, $buff, 4);
                if (!ExifTool::Write($outfile, $buff)) $err = 1;
                // create VP8X chunk if necessary
                if ($createVP8X) {
                    $et->VPrint(0, "  Adding required VP8X chunk (Extended WEBP)\n");
                    $flags = 0;
                    if ($has['ANIM'] ?? null) $flags |= 0x02;
                    if ($has['XMP '] ?? null) $flags |= 0x04;
                    if ($has['EXIF'] ?? null) $flags |= 0x08;
                    if ($has['ALPH'] ?? null) $flags |= 0x10;
                    if ($has['ICCP'] ?? null) $flags |= 0x20;
                    ExifTool::Write($outfile, 'VP8X', Perl::pack('V3v', 10, $flags,
                        ($imageWidth - 1) | ((($imageHeight - 1) & 0xff) << 24),
                        ($imageHeight - 1) >> 8));
                    // write ICCP after VP8X
                    if ($dirDat['ICCP'] ?? null) {
                        if (!ExifTool::Write($outfile, $dirDat['ICCP'])) $err = 1;
                    }
                }
            } else {
                $outsize += strlen($buff);
            }
            $pos = 12;
            //
            // Read chunks in RIFF image
            //
            for (;;) {
                $tag = $len = null;
                $num = $raf->Read($buff, 8);
                if ($num < 8) {
                    if ($num) {
                        $et->Error('RIFF format error');
                        return 1;
                    }
                    // all done if we hit end of file unless we need to add EXIF or XMP
                    if (!(($addDirs['EXIF'] ?? null) or ($addDirs['XMP '] ?? null) or ($addDirs['ICCP'] ?? null))) break;
                    // continue to add required EXIF or XMP chunks
                    $num = $len = 0;
                    $buff = $tag = '';
                } else {
                    $pos += 8;
                    [$tag, $len] = Perl::unpack('a4V', $buff);
                    if ($len <= 0) {
                        if ($len < 0) {
                            $et->Error('Invalid chunk length');
                            return 1;
                        } elseif ($tag === "\0\0\0\0") {
                            // avoid reading through corrupted files filled with nulls because it takes forever
                            $et->Error('Encountered empty null chunk. Processing aborted');
                            return 1;
                        } else { // (just in case a tag may have no data)
                            if ($pass) {
                                if (!ExifTool::Write($outfile, $buff)) $err = 1;
                            } else {
                                $outsize += strlen($buff);
                            }
                            continue;
                        }
                    }
                }
                // RIFF chunks are padded to an even number of bytes
                $len2 = $len + ($len & 0x01);
                // handle incorrect "XMP\0" chunk ID written by Google software
                if (self::$deletableGroup[$tag] ?? null) {
                    if ($et->DEL_GROUP[self::$deletableGroup[$tag]] ?? null) {
                        // just ignore this chunk if deleting the associated group
                        if (!$raf->Seek($len2, 1)) {
                            $et->Error('Seek error');
                            break;
                        }
                        if ($pass) $et->VPrint(0, '  Deleting ' . self::$deletableGroup[$tag] . "\n");
                        ++$et->CHANGED;
                        continue;
                    } elseif ($tag === "XMP\0") {
                        if ($pass) $et->Warn('Incorrect XMP tag ID', 1);
                    }
                }
                // edit/add/delete necessary metadata chunks (EXIF must come before XMP)
                if (($editDirs[$tag] ?? null) or $tag === '' or ($tag === 'XMP ' and ($addDirs['EXIF'] ?? null))) {
                    $handledTag = null;
                    if ($len2) {
                        if (($doneDir[$tag] ?? null) and !$pass) $et->Warn("Duplicate '{$tag}' chunk");
                        $doneDir[$tag] = 1;
                        if ($raf->Read($buff, $len2) != $len2) {
                            $et->Error("Truncated '{$tag}' chunk");
                            break;
                        }
                        $pos += $len2;  // update current position
                    } else {
                        $buff = '';
                    }
                    //
                    // add/edit/delete EXIF/XMP/ICCP (note: EXIF must come before XMP, and ICCP is written elsewhere)
                    //
                    $dirName = ['EXIF' => 'IFD0', 'XMP ' => 'XMP', 'ICCP' => 'ICC_Profile', 'C2PA' => 'JUMBF'];
                    $tblName = ['EXIF' => 'Exif', 'XMP ' => 'XMP', 'ICCP' => 'ICC_Profile', 'C2PA' => 'Jpeg2000'];
                    foreach (['EXIF', 'XMP ', 'ICCP', 'C2PA'] as $dir) {
                        if (!($tag === $dir or (($addDirs[$dir] ?? null) and
                            ($tag === '' or ($tag === 'XMP ' and $dir === 'EXIF'))))) continue;
                        $start = null;
                        if (!$pass) {
                            // write the EXIF and save the result for the next pass
                            $dataPt = null;     // (Perl: my $dataPt = \$buff)
                            if ($tag === 'EXIF') {
                                // (only need to set directory $start for EXIF)
                                if (str_starts_with($buff, "Exif\0\0")) {
                                    if ($et->DEL_GROUP['EXIF'] ?? null) {
                                        // remove incorrect header if rewriting anyway
                                        $buff = substr($buff, 6);
                                        $len -= 6;
                                        $len2 -= 6;
                                    } else {
                                        if (!$pass) $et->Warn('Improper EXIF header', 1);
                                        $start = 6;
                                    }
                                } else {
                                    $start = 0;
                                }
                            } elseif ($dir !== $tag) {
                                // create from scratch
                                $buf2 = '';
                                $dataPt = $buf2;
                            }
                            // write the new directory to memory
                            $subdirInfo = [     // (Perl: my %dirInfo)
                                'DataPt'   => $dataPt ?? $buff,
                                'DataPos'  => 0,    // (relative to Base)
                                'DirStart' => $start,
                                'Base'     => $pos - $len2,
                                'Parent'   => $dir,
                                'DirName'  => $dirName[$dir],
                            ];
                            // must pass the TagInfo to enable deletion of C2PA information
                            $mainTagInfo = ExifTool::PackageTable('Image::ExifTool::RIFF::Main')[$dir] ?? null;
                            if ($mainTagInfo instanceof TagInfo) {
                                $subdirInfo['TagInfo'] = $mainTagInfo;
                            }
                            $tagTablePtr = ExifTool::GetTagTable("Image::ExifTool::$tblName[$dir]::Main");
                            // (override writeProc for EXIF because it has the TIFF header)
                            $writeProc = $dir === 'EXIF' ? 'Image\ExifTool\ExifTool::WriteTIFF' : null;
                            $dirDat[$dir] = $et->WriteDirectory($subdirInfo, $tagTablePtr, $writeProc);
                        }
                        unset($addDirs[$dir]);  // (don't try to add again)
                        if (($dirDat[$dir] ?? null) !== null) {
                            if ($dir === $tag) {
                                $handledTag = 1;    // set flag indicating we edited this tag
                                // increment CHANGED count if we are deleting the directory
                                if (!strlen($dirDat[$dir])) ++$et->CHANGED;
                            }
                            if (strlen($dirDat[$dir])) {
                                if ($pass) {
                                    // write metadata chunk now (but not ICCP because it was added earlier)
                                    if ($dir !== 'ICCP') {
                                        if (!ExifTool::Write($outfile, $dirDat[$dir])) $err = 1;
                                    }
                                } else {
                                    // preserve (incorrect EXIF) header if it existed
                                    $hdr = $start ? substr($buff, 0, $start) : '';
                                    // (don't overwrite $len here because it may be XMP length)
                                    $dirLen = strlen($dirDat[$dir]) + strlen($hdr);
                                    // add chunk header and padding
                                    $pad = $dirLen & 0x01 ? "\0" : '';
                                    $dirDat[$dir] = $dir . ExifTool::Set32u($dirLen) . $hdr . $dirDat[$dir] . $pad;
                                    $outsize += strlen($dirDat[$dir]);
                                    $has[$dir] = 1;
                                }
                            }
                        }
                    }
                    //
                    // just copy XMP, EXIF or ICC if nothing changed
                    //
                    if (!$handledTag and strlen($buff)) {
                        // write the chunk without changes
                        if ($pass) {
                            if (!ExifTool::Write($outfile, $tag, ExifTool::Set32u($len), $buff)) $err = 1;
                        } else {
                            $outsize += 8 + strlen($buff);
                            $has[$tag] = 1;
                        }
                    }
                    continue;
                }
                $pos += $len2;  // set read position at end of chunk data
                //
                // update necessary flags in VP8X chunk
                //
                if ($tag === 'VP8X') {
                    $buf2 = null;
                    if ($len2 < 10 or $raf->Read($buf2, $len2) != $len2) {
                        $et->Error('Truncated VP8X chunk');
                        return 1;
                    }
                    if ($pass) {
                        if ($deleteVP8X) {
                            $et->VPrint(0, "  Deleting unnecessary VP8X chunk (Standard WEBP)\n");
                            continue;
                        }
                        // ...but first set the VP8X flags
                        $flags = ExifTool::Get32u($buf2, 0);
                        $flags &= ~0x2c; // (reset flags for everything we can write)
                        if ($has['XMP '] ?? null) $flags |= 0x04;
                        if ($has['EXIF'] ?? null) $flags |= 0x08;
                        if ($has['ICCP'] ?? null) $flags |= 0x20;
                        ExifTool::Set32u($flags, $buf2, 0);
                        if (!ExifTool::Write($outfile, $buff, $buf2)) $err = 1;
                    } else {
                        // get the image size
                        $imageWidth  = (ExifTool::Get32u($buf2, 4) & 0xffffff) + 1;
                        $imageHeight = (ExifTool::Get32u($buf2, 6) >> 8) + 1;
                        $outsize += 8 + $len2;
                        $has[$tag] = 1;
                    }
                    // write ICCP after VP8X
                    if ($dirDat['ICCP'] ?? null) {
                        if (!ExifTool::Write($outfile, $dirDat['ICCP'])) $err = 1;
                    }
                    continue;
                }
                //
                // just copy all other chunks
                //
                if ($pass) {
                    // write chunk header (still in $buff)
                    if (!ExifTool::Write($outfile, $buff)) $err = 1;
                } else {
                    $outsize += strlen($buff);
                    $has[$tag] = 1;
                }
                if (!($pass or $imageWidth !== null)) {
                    // get WebP image size from VP8 or VP8L header
                    if ($tag === 'VP8 ' and $len2 >= 16) {
                        if ($raf->Read($buff, 16) != 16) {
                            $et->Error('Truncated VP8 chunk');
                            return 1;
                        }
                        $outsize += 16;
                        if (preg_match('/^...\x9d\x01\x2a/s', $buff)) {
                            $imageWidth  = ExifTool::Get16u($buff, 6) & 0x3fff;
                            $imageHeight = ExifTool::Get16u($buff, 8) & 0x3fff;
                        }
                        $len2 -= 16;
                    } elseif ($tag === 'VP8L' and $len2 >= 6) {
                        if ($raf->Read($buff, 6) != 6) {
                            $et->Error('Truncated VP8L chunk');
                            return 1;
                        }
                        $outsize += 6;
                        if (str_starts_with($buff, "\x2f")) {
                            $word = ExifTool::Get32u($buff, 2);
                            $imageWidth  =  (ExifTool::Get16u($buff, 1) & 0x3fff) + 1;
                            $imageHeight = (($word >> 6) & 0x3fff) + 1;
                            if ($word & 0x100000) $has['ALPH'] = 1; // set alpha flag if necessary
                        }
                        $len2 -= 6;
                    }
                }
                if ($pass) {
                    // copy the chunk data in 64k blocks
                    while ($len2) {
                        $num = $len2;
                        if ($num > 65536) $num = 65536;
                        if ($raf->Read($buff, $num) != $num) {
                            $et->Error('Truncated RIFF chunk');
                            break;
                        }
                        if (!ExifTool::Write($outfile, $buff)) {
                            $err = 1;
                            break;
                        }
                        $len2 -= $num;
                    }
                } else {
                    if (!$raf->Seek($len2, 1)) {
                        $et->Error('Seek error');
                        break;
                    }
                    $outsize += $len2;
                }
            }
            if ($pass) break;
            if (!$raf->Seek(0, 0)) {
                $et->Error('Seek error');
                break;
            }
        }
        return $err ? -1 : 1;
    }
}
