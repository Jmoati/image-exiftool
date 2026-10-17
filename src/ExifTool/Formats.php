<?php

namespace Image\ExifTool\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars\ExifTool as Vars;

/**
 * Format processors of ExifTool.pm (ProcessJPEG, DoProcessTIFF, trailers...), mixed into
 * Image\ExifTool\ExifTool. Port of lib/Image/ExifTool.pm lines 6984-8921 (reading only:
 * the branches that only matter when writing are left out).
 */
trait Formats
{
    //------------------------------------------------------------------------------
    // Identify trailer ending at specified offset from end of file
    // Inputs: 0) RAF reference, 1) offset from end of file (0 by default)
    // Returns: Trailer info hash (with RAF and DirName set),
    //          or undef if no recognized trailer was found
    // Notes: leaves file position unchanged
    public function IdentifyTrailer(RandomAccess $raf, $offset = null)
    {
        $self = $this;
        $offset or $offset = 0;
        $pos = $raf->Tell();
        $buff = $type = $len = null;
        while ($raf->Seek(-$offset, 2) and ($len = $raf->Tell()) > 0) {
            // read up to 64 bytes before specified offset from end of file
            if ($len > 64) {
                $len = 64;
            }
            if (!($raf->Seek(-$len, 1) and $raf->Read($buff, $len) == $len)) {
                break;
            }
            if (preg_match('/AXS(!|\*).{8}$/s', $buff)) {
                $type = 'AFCP';
            } elseif (preg_match('/\xa1\xb2\xc3\xd4$/', $buff)) {
                $type = 'FotoStation';
            } elseif (preg_match('/cbipcbbl$/', $buff)) {
                $type = 'PhotoMechanic';
            } elseif (preg_match('/^CANON OPTIONAL DATA\x00/', $buff)) {
                $type = 'CanonVRD';
            } elseif (preg_match('/~\x00\x04\x00zmie~\x00\x00\x06.{4}[\x10\x18]\x04$/s', $buff) or
                      preg_match('/~\x00\x04\x00zmie~\x00\x00\x0a.{8}[\x10\x18]\x08$/s', $buff))
            {
                $type = 'MIE';
            } elseif (preg_match('/\x00\x00(QDIOBS|SEFT)$/', $buff)) {
                $type = 'Samsung';
            } elseif (preg_match('/8db42d694ccc418790edff439fe026bf$/s', $buff)) {
                $type = 'Insta360';
            } elseif (preg_match('#\x00{6}/NIKON APP$#', $buff)) {
                $type = 'NikonApp';
            } elseif (preg_match('/\xff{4}\x1b\*9HWfu\x84\x93\xa2\xb1$/', $buff)) {
                $type = 'Vivo';
            } elseif (preg_match('/jxrs...\x00$/s', $buff)) {
                $type = 'OnePlus';
            } elseif ($self->ProcessGoogleTrailer) {
                // check for Google trailer information if specific XMP tags exist
                $type = 'Google';
            }
            break;
        }
        $raf->Seek($pos, 0);    // restore original file position
        return $type ? ['RAF' => $raf, 'DirName' => $type] : null;
    }

    //------------------------------------------------------------------------------
    // Read/rewrite trailer information (including multiple trailers)
    // Inputs: 0) ExifTool object ref, 1) DirInfo ref:
    // - requires RAF and DirName
    // - OutFile is a scalar reference for writing
    // - scans from current file position for each trailer if ScanForTrailer is set
    //   (current file position is just after JPEG EOF for a JPEG image)
    // Returns: 1 if trailer was processed or couldn't be processed (or written OK)
    //          0 if trailer was recognized but offsets need fixing (or write error)
    // - DirName, DirLen, DataPos, Offset, Fixup and OutFile are updated
    // - preserves current file position and byte order
    public function ProcessTrailers(array &$dirInfo)
    {
        $self = $this;
        $dirName = $dirInfo['DirName'];
        $offset = ($dirInfo['Offset'] ?? null) ?: 0;
        $fixup = $dirInfo['Fixup'] ?? null;
        $raf = $dirInfo['RAF'];
        $pos = $raf->Tell();
        $byteOrder = self::GetByteOrder();
        $success = 1;
        $path = &$self->PATH;

        // get position of end of file
        $raf->Seek(0, 2);
        $self->FileEnd = $raf->Tell();

        for (;;) { // loop through all trailers
            $raf->Seek($pos);
            // trailer-processing procs residing in modules of a different name
            $module = ([
                'Insta360' => 'QuickTimeStream.pl',
                'NikonApp' => 'Nikon.pm',
                'Vivo'     => 'Trailer.pm',
                'OnePlus'  => 'Trailer.pm',
                'Google'   => 'Trailer.pm',
            ][$dirName] ?? null) ?: "$dirName.pm";
            $module = preg_replace('/(Stream)?\..*/', '', $module);   // remove extension and change QuickTimeStream to QuickTime
            self::RequireModule("Image::ExifTool::$module");
            $proc = "Image\\ExifTool\\{$module}::Process$dirName";
            // (writing not ported)
            unset($dirInfo['DirLen']);       // reset trailer length
            $dirInfo['Offset'] = $offset;    // set offset from end of file
            $dirInfo['Trailer'] = 1;         // set Trailer flag in case proc cares
            // add trailer and DirName to SubDirectory PATH
            array_push($path, 'Trailer', $dirName);
            //
            // Call proc to read or write this trailer
            //
            // Proc inputs:
            //   0) ExifTool ref, with FileEnd set, and TrailerStart possibly set (start of all trailers)
            //   1) DirInfo with the following elements:
            //        DirName - name of this trailer
            //        RAF     - RAF reference
            //        Offset  - positive offset from end of this trailer to the end of file
            //        OutFile - (write mode) scalar reference for output buffer consisting of an empty string
            //        Trailer - flag set so proc knows we are processing a trailer (if it cares)
            //        Fixup   - optional fixup for pointers in trailer
            //        ScanForTrailer - set if we should now scan for the trailer start.  For JPEG
            //           images the ExifTool TrailerStart member will also be set, but for TIFF
            //           images TrailerStart will only be set when writing, so the proc should
            //           scan from the current file position when reading in a TIFF image.
            // Proc returns in read mode (OutFile not set):
            //   1 = success
            //   0 = error processing trailer (no warning will be issued and remaining trailers will be ignored)
            //  -1 = must scan from TrailerStart since length can not be determined
            //       (in which case this routine will be called again later when TrailerStart is known)
            // Proc sets the following elements of $dirInfo in both read and write mode:
            //   DataPos - file position for start of this trailer
            //   DirLen  - length of this trailer (subsequent trailers are not processed if this is not set)
            //   Fixup   - for any pointers in the trailer that need adjusting
            //
            $result = $self->CallProc($proc, $dirInfo);   // (&$proc($self, $dirInfo))

            // restore PATH (pop last 2 items)
            array_splice($path, -2);

            $dataPos = $dirInfo['DataPos'] ?? null;
            $dirLen = $dirInfo['DirLen'] ?? null;
            if ($result < 0) {
                // can't continue if we must scan for this trailer
                $success = 0;
                break;
            }
            if (!($result > 0 and $dirLen)) {
                break;
            }
            $offset += $dirLen;
            if ($dataPos and $self->TrailerStart and $dataPos <= $self->TrailerStart) {
                break;
            }
            // look for next trailer
            $nextTrail = $self->IdentifyTrailer($raf, $offset);
            // process Google trailer after all others if necessary and not done already
            if (!$nextTrail) {
                if (!$self->ProcessGoogleTrailer) {
                    break;
                }
                $nextTrail = ['DirName' => 'Google', 'RAF' => $raf];
            }
            $dirName = $dirInfo['DirName'] = $nextTrail['DirName'];
        }
        self::SetByteOrder($byteOrder);   // restore original byte order
        $raf->Seek($pos);                 // restore original file position
        $dirInfo['Offset'] = $offset;     // return offset from EOF to start of first trailer
        $dirInfo['Fixup'] = $fixup;       // return fixup information
        return $success;
    }

    //------------------------------------------------------------------------------
    // Get JPEG marker name
    // Inputs: 0) Jpeg number
    // Returns: marker name
    public static function JpegMarkerName($marker)
    {
        $markerName = Vars::$jpegMarker[$marker] ?? null;
        if (!$markerName) {
            $markerName = Vars::$jpegMarker[$marker & 0xf0] ?? null;
            if ($markerName and preg_match('/^([A-Z]+)\d+$/', $markerName, $m)) {
                $markerName = $m[1] . ($marker & 0x0f);
            } else {
                $markerName = Perl::sprintf('marker 0x%.2x', $marker);
            }
        }
        return $markerName;
    }

    //------------------------------------------------------------------------------
    // Adjust directory start position
    // Inputs: 0) dirInfo ref, 1) start offset
    //         2) Base for offsets (relative to DataPos, defaults to absolute Base of 0)
    public static function DirStart(array &$dirInfo, $start, $base = null)
    {
        $dirInfo['DirStart'] = $start;
        $dirInfo['DirLen'] -= $start;
        if ($base !== null) {
            $dirInfo['Base'] = $dirInfo['DataPos'] + $base;
            $dirInfo['DataPos'] = -$base;    // (relative to Base!)
        }
    }

    //------------------------------------------------------------------------------
    // Extract metadata from a jpg image
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref with RAF set
    //         2) tag table ref to process JPEG-like metadata
    // Returns: 1 on success, 0 if this wasn't a valid JPEG file
    public function ProcessJPEG(array &$dirInfo, ?TagTable $optionalTagTable = null)
    {
        $self = $this;
        $options = $self->OPTIONS;
        $verbose = $options['Verbose'];
        $out = $options['TextOut'];
        $fast = $options['FastScan'] ?: 0;
        $raf = $dirInfo['RAF'] ?? null;
        $req = $self->REQ_TAG_LOOKUP ?? [];
        $htmlDump = $self->HTML_DUMP;
        $dumpParms = ['Out' => $out, 'Prefix' => $self->INDENT];
        $ch = $s = $length = $hash = $hashsize = $indent = null;
        $success = $wantTrailer = $trailInfo = $foundSOS = $gotSize = null;
        $jumbfChunk = [];
        $iccChunk = $flirChunk = [];
        $iccChunkCount = $iccChunksTotal = $flirCount = $flirTotal = null;
        $preview = $scalado = $subSampling = $dumpEnd = null;
        $dqt = $extendedXMP = [];

        $indent = preg_replace('/  $/', '', $self->INDENT);
        if (!$raf) {
            $raf = new RandomAccess($dirInfo['DataPt']);
            $self->VerboseDir('JPEG', null, strlen($dirInfo['DataPt']));
        }
        // (the %dirInfo hashes below are new hashes, not the caller's $dirInfo)
        unset($dirInfo);
        // get pointer to hash object if it exists and we are the top-level JPEG or JP2
        if (preg_match('/^(JPEG|JP2)$/', (string) $self->FILE_TYPE) and !$self->DOC_NUM) {
            $hash = $self->ImageDataHash;
            $hashsize = 0;
        }
        // check to be sure this is a valid JPG (or J2C, or EXV) file
        if ($raf->Read($s, 2) == 2 and preg_match('/^\xff[\xd8\x4f\x01]/', $s)) {
            $optionalTagTable = null;
        } else {
            if (!($optionalTagTable and preg_match('/^\xff[\xe0-\xef]/', $s))) {
                return 0;
            }
            if (!$raf->Seek(-2, 1)) {
                $self->Error('Seek error');
                return 1;
            }
        }
        if ($s === "\xff\x01") {
            if (!($raf->Read($s, 5) == 5 and $s === 'Exiv2')) {
                return 0;
            }
            $self->FILE_TYPE = 'EXV';
        }
        $appBytes = 0;
        $calcImageLen = $req['jpegimagelength'] ?? null;
        if ($options['RequestAll'] and $options['RequestAll'] > 2) {
            $calcImageLen = 1;
        }
        if (!($self->VALUE['FileType'] ?? null) or ($self->DOC_NUM and $options['ExtractEmbedded'])) {
            $self->SetFileType();                  // set FileType tag
            if ($fast > 2) {
                return 1;                          // don't process file when FastScan > 2
            }
            $self->LOW_PRIORITY_DIR['IFD1'] = 1;   // lower priority of IFD1 tags
        }
        if ($self->Options('FastScan')) {
            $raf->NoBuffer = true;                 // disable buffering in FastScan mode
        }

        if ($verbose < 4) {
            $dumpParms['MaxLen'] = 128;
        }
        if ($htmlDump and !$optionalTagTable) {
            $dumpEnd = $raf->Tell();
            [$n, $t, $m] = $s === 'Exiv2' ? [7, 'EXV', 'TEM'] : [2, 'JPEG', 'SOI'];
            $pos = $dumpEnd - $n;
            if ($pos) {
                $self->HDump(0, $pos, '[unknown header]');
            }
            $self->HDump($pos, $n, "$t header", "$m Marker");
        }
        $path = &$self->PATH;
        $pn = count($path);

        // set input record separator to 0xff (the JPEG marker) to make reading quicker
        $oldRS = Perl::$RS;
        Perl::$RS = "\xff";
        try {

        $nextMarker = $nextSegDataPt = $nextSegPos = $combinedSegData = $firstSegPos = null;
        $skipData = [];

        // read file until we reach an end of image (EOI) or start of scan (SOS)
        for (;;) { // Marker:
            unset($dataPt);     // (a new "my $dataPt" in each segment below: break any reference)
            // set marker and data pointer for current segment
            $marker = $nextMarker;
            if ($marker and $marker < 0) {
                break;
            }
            $segDataPt = $nextSegDataPt;
            $segPos = $nextSegPos;
            $skipped = null;
            $nextMarker = null;
            $nextSegDataPt = null;
            //
            // read ahead to the next segment unless we have reached EOI, SOS or SOD
            //
            while (!($marker and ($marker == 0xd9 or ($marker == 0xda and !$wantTrailer and !$hash) or
                $marker == 0x93)))
            {
                // read up to next marker (JPEG markers begin with 0xff)
                $buff = null;
                if (!$raf->ReadLine($buff)) {
                    if (!$optionalTagTable) {
                        break 2;    // last Marker
                    }
                    $nextMarker = -1;
                    $success = 1;
                    break;
                }
                $skipped = strlen($buff) - 1;
                // JPEG markers can be padded with unlimited 0xff's
                for (;;) {
                    if (!$raf->Read($ch, 1)) {
                        break 3;    // last Marker
                    }
                    $nextMarker = ord($ch);
                    if ($nextMarker != 0xff) {
                        break;
                    }
                    ++$skipped;
                }
                // read segment data if it exists
                if (!isset(Vars::$markerLenBytes[$nextMarker])) {
                    // read record length word
                    if ($raf->Read($s, 2) != 2) {
                        break 2;    // last Marker
                    }
                    $len = Perl::unpack('n', $s)[0] ?? null;   // get data length
                    if (!($len !== null and $len >= 2)) {
                        break 2;    // last Marker
                    }
                    $nextSegPos = $raf->Tell();
                    $len -= 2;  // subtract size of length word
                    if ($raf->Read($buff, $len) != $len) {
                        break 2;    // last Marker
                    }
                    $nextSegDataPt = $buff;     // set pointer to our next data
                } elseif (Vars::$markerLenBytes[$nextMarker] == 4) {
                    // handle J2C extensions with 4-byte length word
                    if ($raf->Read($s, 4) != 4) {
                        break 2;    // last Marker
                    }
                    $len = Perl::unpack('N', $s)[0] ?? null;   // get data length
                    if (!($len !== null and $len >= 4)) {
                        break 2;    // last Marker
                    }
                    $nextSegPos = $raf->Tell();
                    $len -= 4;  // subtract size of length word
                    if (!$raf->Seek($len, 1)) {
                        break 2;    // last Marker
                    }
                } elseif ($hash and $marker !== null and ($marker == 0x00 or $marker == 0xda or
                    ($marker >= 0xd0 and $marker <= 0xd7)))
                {
                    // calculate hash for image data (includes leading ff d9 but not trailing ff da)
                    hash_update($hash, "\xff" . chr($marker));
                    $n = $skipped - (strlen($buff) - 1); // number of extra 0xff's
                    if (!$n) {
                        $buff = substr($buff, 0, -1);       // remove trailing 0xff
                    } elseif ($n > 1) {
                        $buff .= str_repeat("\xff", $n - 1); // add back extra 0xff's
                    }
                    hash_update($hash, $buff);
                    $hashsize += $skipped + 2;
                }
                // read second segment too if this was the first
                if ($marker === null) {
                    continue 2;     // next Marker
                }
                break;
            }
            // set some useful variables for the current segment
            $markerName = self::JpegMarkerName($marker);
            $path[$pn] = $markerName;
            // issue warning if we skipped some garbage
            if ($skipped and !$foundSOS and $markerName !== 'SOS') {
                $self->Warn("Skipped unknown $skipped bytes after JPEG $markerName segment", 1);
                if ($htmlDump) {
                    $self->HDump($nextSegPos - 4 - $skipped, $skipped, "[unknown $skipped bytes]", null, 0x08);
                    $dumpEnd = $nextSegPos - 4;
                }
            }
            //
            // parse the current segment
            //
            // handle SOF markers: SOF0-SOF15, except DHT(0xc4), JPGA(0xc8) and DAC(0xcc)
            if (($marker & 0xf0) == 0xc0 and ($marker == 0xc0 or $marker & 0x03)) {
                $length = strlen($segDataPt);
                if ($verbose) {
                    fwrite($out, "{$indent}JPEG $markerName ($length bytes):\n");
                    if ($verbose > 2) {
                        self::HexDump($segDataPt, null, ...array_merge($dumpParms, ['Addr' => $segPos]));
                    }
                } elseif ($htmlDump) {
                    $self->HDump($segPos - 4, $length + 4, "[JPEG $markerName]", null, 0x08);
                    $dumpEnd = $segPos + $length;
                }
                if ($length < 6 or $gotSize) {
                    continue;
                }
                $gotSize = 1; // (ignore subsequent SOF segments in probably corrupted JPEG)
                // extract some useful information
                [$p, $h, $w, $n] = Perl::unpack('Cn2C', $segDataPt);
                $sof = self::GetTagTable('Image::ExifTool::JPEG::SOF');
                $self->HandleTag($sof, 'ImageWidth', $w);
                $self->HandleTag($sof, 'ImageHeight', $h);
                $self->HandleTag($sof, 'EncodingProcess', $marker - 0xc0);
                $self->HandleTag($sof, 'BitsPerSample', $p);
                $self->HandleTag($sof, 'ColorComponents', $n);
                if (!($n == 3 and $length >= 15)) {
                    continue;
                }
                $hmin = $hmax = $vmin = $vmax = null;
                // loop through all components to determine sampling frequency
                $subSampling = '';
                for ($i = 0; $i < $n; ++$i) {
                    $sf = self::Get8u($segDataPt, 7 + 3 * $i);
                    $subSampling .= Perl::sprintf('%.2x', $sf);
                    // isolate horizontal and vertical components
                    [$hf, $vf] = [$sf >> 4, $sf & 0x0f];
                    if (!$i) {
                        $hmin = $hmax = $hf;
                        $vmin = $vmax = $vf;
                        continue;
                    }
                    // determine min/max frequencies
                    if ($hf < $hmin) {
                        $hmin = $hf;
                    }
                    if ($hf > $hmax) {
                        $hmax = $hf;
                    }
                    if ($vf < $vmin) {
                        $vmin = $vf;
                    }
                    if ($vf > $vmax) {
                        $vmax = $vf;
                    }
                }
                if ($hmin and $vmin) {
                    [$hs, $vs] = [$hmax / $hmin, $vmax / $vmin];
                    $self->HandleTag($sof, 'YCbCrSubSampling', Perl::str($hs) . ' ' . Perl::str($vs));
                }
                continue;
            } elseif ($marker == 0xd9) {         // EOI
                array_pop($path);
                if ($verbose) {
                    fwrite($out, "{$indent}JPEG EOI\n");
                }
                $pos = $raf->Tell();
                if (!$self->DOC_NUM) {
                    $self->TrailerStart = $pos;
                }
                if ($htmlDump and $dumpEnd) {
                    $self->HDump($dumpEnd, $pos - 2 - $dumpEnd, '[JPEG Image Data]', null, 0x08);
                    $self->HDump($pos - 2, 2, 'JPEG EOI', null);
                    $dumpEnd = 0;
                }
                if ($foundSOS or $self->FILE_TYPE === 'EXV') {
                    $success = 1;
                } else {
                    $self->Warn('Missing JPEG SOS');
                }
                if ($req['trailer'] ?? null) {
                    // read entire trailer into memory
                    if ($raf->Seek(0, 2)) {
                        $len = $raf->Tell() - $pos;
                        if ($len) {
                            $buff = null;
                            $raf->Seek($pos, 0);
                            if ($raf->Read($buff, $len) == $len) {
                                $self->FoundTag('Trailer', new ScalarRef($buff));
                            }
                            $raf->Seek($pos, 0);
                        }
                    } else {
                        $self->Warn('Error seeking in file');
                    }
                }
                // we are here because we are looking for trailer information
                if ($wantTrailer) {
                    $start = $self->PreviewImageStart;
                    if ($start or $options['ExtractEmbedded']) {
                        $buff = null;
                        // most previews start right after the JPEG EOI, but the Olympus E-20
                        // preview is 508 bytes into the trailer, the K-M Maxxum 7D preview is
                        // 979 bytes in, and Sony previews can start up to 32 kB into the trailer.
                        // (and Minolta and Sony previews can have a random first byte...)
                        $scanLen = preg_match('/Sony/i', (string) $self->Make) ? 65536 : 1024;
                        if ($raf->Read($buff, $scanLen)) {
                            if (preg_match('/^.{4}ftyp/s', $buff)) {
                                $val = null;
                                if ($raf->Seek(0, 2)) {
                                    $len = $raf->Tell() - $pos;
                                    if ($options['Binary']) {
                                        if ($raf->Seek($pos, 0) and $raf->Read($buff, $len) == $len) {
                                            $val = new ScalarRef($buff);
                                        }
                                    } else {
                                        $val = new ScalarRef("Binary data $len bytes");
                                    }
                                    if ($val) {
                                        $self->FoundTag('EmbeddedVideo', $val);
                                    } else {
                                        $self->Warn('Error reading trailer');
                                    }
                                } else {
                                    $self->Warn('Error seeking to end of file');
                                }
                            } elseif (preg_match('/\xff\xd8\xff./', $buff, $m, PREG_OFFSET_CAPTURE) or
                               (preg_match('/(Minolta|Sony)/i', (string) $self->Make) and
                                preg_match('/.\xd8\xff\xdb/', $buff, $m, PREG_OFFSET_CAPTURE)))
                            {
                                // adjust PreviewImageStart to this location
                                $actual = $pos + $m[0][1] + strlen($m[0][0]) - 4;   // ($pos + pos($buff) - 4)
                                if ($start and Perl::str($start) !== Perl::str($actual) and $verbose > 1) {
                                    fwrite($out, "{$indent}(Fixed PreviewImage location: $start -> $actual)\n");
                                }
                                // update preview image offsets
                                if ($start) {
                                    if ($self->VALUE['PreviewImageStart'] ?? null) {
                                        $self->VALUE['PreviewImageStart'] = $actual;
                                    }
                                    $self->PreviewImageStart = $actual;
                                }
                                // load preview now if we tried and failed earlier
                                if ($self->PreviewError and $self->PreviewImageLength) {
                                    if ($raf->Seek($actual, 0) and $raf->Read($buff, $self->PreviewImageLength)) {
                                        $self->FoundTag('PreviewImage', $buff);
                                        unset($self->PreviewError);
                                    }
                                }
                            }
                        }
                        $raf->Seek($pos, 0);
                    }
                }
                // process trailer now or finish processing trailers
                // and scan for AFCP if necessary
                $fromEnd = 0;
                if ($trailInfo) {
                    $trailInfo['ScanForTrailer'] = 1;   // scan now if necessary
                    $self->ProcessTrailers($trailInfo);
                    // save offset from end of file to start of first trailer
                    $fromEnd = $trailInfo['Offset'];
                    $trailInfo = null;
                }
                if ($self->LeicaTrailer) {
                    $raf->Seek(0, 2);
                    $self->LeicaTrailer['TrailPos'] = $pos;
                    $self->LeicaTrailer['TrailLen'] = $raf->Tell() - $pos - $fromEnd;
                    \Image\ExifTool\Panasonic::ProcessLeicaTrailer($self);
                }
                // finally, dump remaining information in JPEG trailer
                if ($verbose or $htmlDump) {
                    $endPos = $self->LeicaTrailerPos;
                    if (!$endPos) {
                        $raf->Seek(0, 2);
                        $endPos = $raf->Tell() - $fromEnd;
                    }
                    if ($endPos > $pos) {
                        $dirInfo = [
                            'RAF' => $raf,
                            'DataPos' => $pos,
                            'DirLen' => $endPos - $pos,
                        ];
                        $self->DumpUnknownTrailer($dirInfo);
                    }
                }
                if ($calcImageLen) {
                    $self->FoundTag('JPEGImageLength', $pos - $appBytes);
                }
                break;      // all done parsing file
            } elseif ($marker == 0xda) {         // SOS
                array_pop($path);
                $foundSOS = 1;
                // all done with meta information unless we have a trailer
                if ($verbose) {
                    fwrite($out, "{$indent}JPEG SOS\n");
                }
                // process extended XMP now if it existed
                // (must do this before trailers because XMP is required to process Google trailer)
                if ($extendedXMP) {
                    // GUID indicated by the last main XMP segment
                    $goodGuid = Perl::str(($self->VALUE['HasExtendedXMP'] ?? null) ?: '');
                    // GUID of the extended XMP that we will process ('2' for all)
                    $readGuid = Perl::str($options['ExtendedXMP'] ?: 0);
                    if ($readGuid === '1') {
                        $readGuid = $goodGuid;
                    }
                    $guids = array_map('strval', array_keys($extendedXMP));
                    sort($guids, SORT_STRING);
                    foreach ($guids as $guid) {
                        if (strlen($guid) != 32) {
                            continue;   // ignore other (internal) keys
                        }
                        $extXMP = $extendedXMP[$guid];
                        $offsets = [];
                        $warn = null;
                        // make sure we have all chunks, and create a list of sorted offsets
                        for ($off = 0; $off < $extXMP['Size'];) {
                            if (!isset($extXMP[$off])) {
                                break;
                            }
                            $offsets[] = $off;
                            $off += strlen($extXMP[$off]);
                        }
                        if ($off != $extXMP['Size']) {
                            $self->Warn("Incomplete extended XMP (GUID $guid)");
                            continue;
                        }
                        if ($guid === $readGuid or $readGuid === '2') {
                            if ($guid !== $goodGuid) {
                                $warn = 'Reading non-';
                            }
                            $buff = '';
                            // assemble XMP all together
                            foreach ($offsets as $_) {
                                $buff .= $extXMP[$_];
                            }
                            $tagTablePtr = self::GetTagTable('Image::ExifTool::XMP::Main');
                            $dirInfo = [
                                'DataPt'      => $buff,
                                'Parent'      => 'APP1',
                                'IsExtended'  => 1,
                            ];
                            $path[$pn] = 'APP1';
                            $self->ProcessDirectory($dirInfo, $tagTablePtr);
                            array_pop($path);
                        } else {
                            $warn = 'Ignored ';
                            if ($guid !== $goodGuid) {
                                $warn .= 'non-';
                            }
                        }
                        if ($warn) {
                            $self->Warn("{$warn}standard extended XMP (GUID $guid)");
                        }
                        unset($extendedXMP[$guid]);
                    }
                }
                if (!$fast) {
                    $trailInfo = $self->IdentifyTrailer($raf);
                    // process trailer now unless we are doing verbose dump
                    if ($trailInfo and $verbose < 3 and !$htmlDump) {
                        // process trailers (keep trailInfo to finish processing later
                        // only if we can't finish without scanning from JPEG EOF)
                        if ($self->ProcessTrailers($trailInfo)) {
                            $trailInfo = null;
                        }
                    }
                    if ($wantTrailer and $self->PreviewImageStart) {
                        // seek ahead and validate preview image
                        $buff = null;
                        $curPos = $raf->Tell();
                        if ($raf->Seek($self->PreviewImageStart, 0) and
                            $raf->Read($buff, 4) == 4 and
                            preg_match('/^.\xd8\xff[\xc4\xdb\xe0-\xef]/', $buff))
                        {
                            $wantTrailer = null;
                        }
                        if (!$raf->Seek($curPos, 0)) {
                            break;
                        }
                    }
                    // seek ahead and process Leica trailer
                    if ($self->LeicaTrailer) {
                        self::RequireModule('Image::ExifTool::Panasonic');
                        \Image\ExifTool\Panasonic::ProcessLeicaTrailer($self);
                        if ($self->LeicaTrailer) {
                            $wantTrailer = 1;
                        }
                    } elseif ($options['ExtractEmbedded'] or (($self->VALUE['HiddenDataOffset'] ?? null) and
                        ($self->VALUE['HiddenDataLength'] ?? null) and ($options['Validate'] or ($req['hiddendata'] ?? null))))
                    {
                        $wantTrailer = 1;
                    }
                    if ($trailInfo or $wantTrailer or $verbose > 2 or $htmlDump) {
                        continue;
                    }
                }
                // must scan to EOI if Validate or JpegCompressionFactor used
                if ($options['Validate'] or $calcImageLen or ($req['trailer'] ?? null) or $hash) {
                    continue;
                }
                // nothing interesting to parse after start of scan (SOS)
                $success = 1;
                break;  // all done parsing file
            } elseif ($marker == 0x93) {
                array_pop($path);
                if ($verbose) {
                    fwrite($out, "{$indent}JPEG SOD\n");
                }
                $success = 1;
                if ($hash and $self->FILE_TYPE === 'JP2') {
                    $pos = $raf->Tell();
                    $self->ImageDataHash($raf, null, 'SOD');
                    $raf->Seek($pos, 0);
                }
                if ($verbose > 2 or $htmlDump) {
                    continue;
                }
                break;  // all done parsing file
            } elseif (isset(Vars::$markerLenBytes[$marker])) {
                // handle other stand-alone markers and segments we skipped over
                if ($verbose and $marker) {
                    if ($verbose < 4 and ($marker & 0xf8) == 0xd0) {
                        continue;
                    }
                    fwrite($out, "{$indent}JPEG $markerName\n");
                }
                continue;
            } elseif ($marker == 0xdb and strlen($segDataPt) and    // DQT
                // save the DQT data only if JPEGDigest has been requested
                // (Note: since we aren't checking the API RequestAll option here, the application
                //  must use the RequestTags option to generate these tags if they have not been
                //  specifically requested.  The reason is that there is too much overhead involved
                //  in the calculation of this tag to make this worth the CPU time.)
                (($req['jpegdigest'] ?? null) or ($req['jpegqualityestimate'] ?? null)
                or ($options['RequestAll'] and $options['RequestAll'] > 2)))
            {
                $num = ord($segDataPt) & 0x0f;      // get table index
                if ($num < 4) {
                    $dqt[$num] = $segDataPt;        // save for hash calculation
                }
            }
            // handle all other markers
            $dumpType = '';
            $desc = $tip = $xtra = $useJpegMain = null;
            $length = strlen($segDataPt);
            if (($marker & 0xf0) == 0xe0) {
                $appBytes += $length + 4;   // total size of APP segments
            }
            if ($verbose) {
                fwrite($out, "{$indent}JPEG $markerName ($length bytes):\n");
                if ($verbose > 2) {
                    $extraParms = ['Addr' => $segPos];
                    if ($verbose == 4) {
                        $extraParms['MaxLen'] = 128;
                    }
                    self::HexDump($segDataPt, null, ...array_merge($dumpParms, $extraParms));
                }
            }
            // prepare dirInfo hash for processing this information
            $dirInfo = [
                'Parent'   => $markerName,
                'DataPt'   => $segDataPt,
                'DataPos'  => $segPos,
                'DataLen'  => $length,
                'DirStart' => 0,
                'DirLen'   => $length,
                'Base'     => 0,
            ];
            if ($marker == 0xe0) {              // APP0 (JFIF, JFXX, CIFF, AVI1, Ocad)
                if (preg_match('/^JFIF\x00/', $segDataPt)) {
                    $dumpType = 'JFIF';
                    self::DirStart($dirInfo, 5);    // start at byte 5
                    self::SetByteOrder('MM');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JFIF::Main');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^JFXX\x00(\x10|\x11|\x13)/', $segDataPt, $m)) {
                    $tag = ord($m[1]);
                    $dumpType = 'JFXX';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JFIF::Extension');
                    $tagInfo = $self->GetTagInfo($tagTablePtr, $tag);
                    $self->FoundTag($tagInfo, substr($segDataPt, 6));
                } elseif (preg_match('/^(II|MM).{4}HEAPJPGM/s', $segDataPt)) {
                    if ($fast > 1) {
                        continue;   // skip processing for very fast
                    }
                    $dumpType = 'CIFF';
                    $dirInfo = ['RAF' => new RandomAccess($segDataPt)];
                    $self->SET_GROUP1 = 'CIFF';
                    $self->PATH[] = 'CIFF';
                    self::RequireModule('Image::ExifTool::CanonRaw');
                    \Image\ExifTool\CanonRaw::ProcessCRW($self, $dirInfo);
                    array_pop($self->PATH);
                    $self->SET_GROUP1 = null;
                } elseif (preg_match('/^(AVI1|Ocad)/', $segDataPt, $m)) {
                    $dumpType = $m[1];
                    self::SetByteOrder('MM');
                    $tagTablePtr = self::GetTagTable("Image::ExifTool::JPEG::$dumpType");
                    self::DirStart($dirInfo, 4);
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                }
            } elseif ($marker == 0xe1) {         // APP1 (EXIF, XMP, QVCI, PARROT)
                // (some Kodak cameras don't put a second "\0", and I have seen an
                //  example where there was a second 4-byte APP1 segment header)
                if (preg_match('/^(.{0,4})Exif\x00./is', $segDataPt, $m)) {
                    $dumpType = null;   // (will be dumped here)
                    // this is EXIF data --
                    // get the data block (into a common variable)
                    $hdrLen = strlen(Vars::$exifAPP1hdr);
                    if (strlen($m[1])) {
                        $hdrLen += strlen($m[1]);
                        $self->Warn('Unknown garbage at start of EXIF segment', 1);
                    } elseif (!preg_match('/^Exif\x00/', $segDataPt)) {
                        $self->Warn('Incorrect EXIF segment identifier', 1);
                    }
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 4, 'APP1 header', "Data size: $length bytes");
                        $self->HDump($segPos, $hdrLen, 'Exif header', 'APP1 data type: Exif');
                        $dumpEnd = $segPos + $length;
                    }
                    $dataPt = &$segDataPt;
                    if ($combinedSegData !== null) {
                        $skipData[] = [$segPos - 4, $segPos + $hdrLen];
                        $combinedSegData .= substr($segDataPt, $hdrLen);
                        $segDataPt = null;
                        $dataPt = &$combinedSegData;
                        $segPos = $firstSegPos;
                    }
                    // peek ahead to see if the next segment is extended EXIF
                    if ($nextMarker == $marker and
                        preg_match('/^' . Vars::$exifAPP1hdr . '(?!(MM\x00\x2a|II\x2a\x00))/', (string) $nextSegDataPt))
                    {
                        // initialize combined data if necessary
                        if ($combinedSegData === null) {
                            $combinedSegData = $segDataPt;
                            $segDataPt = null;
                            $firstSegPos = $segPos;
                            $self->Warn('File contains multi-segment EXIF', 1);
                            $self->ExtendedEXIF = 1;
                        }
                        continue;
                    }
                    $dirInfo['DataPt'] = $dataPt;
                    $dirInfo['DataPos'] = $segPos;
                    $dirInfo['DataLen'] = $dirInfo['DirLen'] = strlen($dataPt);
                    self::DirStart($dirInfo, $hdrLen, $hdrLen);
                    if ($skipData) {
                        $self->SkipData = $skipData;
                    }
                    // extract the EXIF information (it is in standard TIFF format)
                    $self->ProcessTIFF($dirInfo) or $self->Warn('Malformed APP1 EXIF segment');
                    // scan for Vivo HiddenData if necessary
                    if ($self->Make === 'vivo' and
                        // (stored as UserComment by some models)
                        !(($self->VALUE['UserComment'] ?? null) and preg_match('/^filter:/', Perl::str($self->VALUE['UserComment']))) and
                        preg_match('/(filter: .*?; \n)\x00/s', $dataPt, $m, PREG_OFFSET_CAPTURE))
                    {
                        if ($htmlDump) {
                            $n = strlen($m[1][0]) + 1;
                            $self->HDump($segPos + $m[0][1] + strlen($m[0][0]) - $n, $n, '[Vivo HiddenData]', null, 0x08);
                        }
                        $tbl = self::GetTagTable('Image::ExifTool::Trailer::Vivo');
                        $self->HandleTag($tbl, 'HiddenData', $m[1][0]);
                    }
                    // avoid looking for preview unless necessary because it really slows
                    // us down -- only look for it if we found pointer, and preview is
                    // outside EXIF, and PreviewImage is specifically requested
                    $start = $self->GetValue('PreviewImageStart', 'ValueConv');
                    $plen = $self->GetValue('PreviewImageLength', 'ValueConv');
                    if (!$start or (!$plen and $self->PreviewError)) {
                        $start = $self->PreviewImageStart;
                        $plen = $self->PreviewImageLength;
                    }
                    if ($start and $plen and self::IsInt($start) and self::IsInt($plen) and
                        $start + $plen > $self->EXIF_POS + strlen((string) $self->EXIF_DATA) and
                        (($req['previewimage'] ?? null) or
                        // (extracted normally, so check Binary option)
                        ($options['Binary'] and !($self->EXCL_TAG_LOOKUP['previewimage'] ?? null))))
                    {
                        $self->PreviewImageStart = $start;
                        $self->PreviewImageLength = $plen;
                        $wantTrailer = 1;
                    }
                    if ($skipData) {
                        $skipData = [];
                        unset($self->SkipData);
                    }
                    $dataPt = null;     // (undef $$dataPt)
                    continue;
                } elseif (preg_match('#^' . Vars::$xmpExtAPP1hdr . '#', $segDataPt)) {
                    // off len -- extended XMP header (75 bytes total):
                    //   0  35 bytes - signature
                    //  35  32 bytes - GUID (MD5 hash of full extended XMP data in ASCII)
                    //  67   4 bytes - total size of extended XMP data
                    //  71   4 bytes - offset for this XMP data portion
                    $dumpType = 'Extended XMP';
                    if ($length > 75) {
                        [$size, $off] = Perl::unpack('x67N2', $segDataPt);
                        $guid = substr($segDataPt, 35, 32);
                        if (preg_match('/[^A-Za-z0-9]/', $guid)) { // (technically, should be uppercase)
                            $self->Warn($tip = 'Invalid extended XMP GUID');
                        } else {
                            if (!isset($extendedXMP[$guid])) {
                                $extendedXMP[$guid] = [];
                            } elseif ($size != $extendedXMP[$guid]['Size']) {
                                $self->Warn('Inconsistent extended XMP size');
                            }
                            $extendedXMP[$guid]['Size'] = $size;
                            $extendedXMP[$guid][$off] = substr($segDataPt, 75);
                            $tip = "Full length: $size\nChunk offset: $off\nChunk length: " .
                                ($length - 75) . "\nGUID: $guid";
                            // (delay processing extended XMP until after reading all segments)
                        }
                    } else {
                        $self->Warn($tip = 'Invalid extended XMP segment');
                    }
                } elseif (preg_match('/^QVCI\x00/', $segDataPt)) {
                    $dumpType = 'QVCI';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Casio::QVCI');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^FLIR\x00/', $segDataPt) and $length >= 8) {
                    $dumpType = 'FLIR';
                    // must concatenate FLIR chunks (note: handle the case where
                    // some software erroneously writes zeros for the chunk counts)
                    $chunkNum = self::Get8u($segDataPt, 6);
                    $chunksTot = self::Get8u($segDataPt, 7) + 1; // (note the "+ 1"!)
                    if ($verbose) {
                        fwrite($out, Perl::sprintf("{$indent}FLIR chunk %d of %d\n", $chunkNum + 1, $chunksTot));
                    }
                    if ($flirTotal !== null) {
                        // abort parsing FLIR if the total chunk count is inconsistent
                        if ($chunksTot != $flirTotal) {
                            $flirCount = null;
                        }
                    } else {
                        $flirCount = 0;
                        $flirTotal = $chunksTot;
                    }
                    if ($flirCount !== null) {
                        if (isset($flirChunk[$chunkNum])) {
                            $self->Warn('Duplicate FLIR chunk number(s)');
                            $flirChunk[$chunkNum] .= substr($segDataPt, 8);
                        } else {
                            $flirChunk[$chunkNum] = substr($segDataPt, 8);
                        }
                        // process the FLIR information if we have all of the chunks
                        if (++$flirCount >= $flirTotal) {
                            $flir = '';
                            ksort($flirChunk);  // (Perl array order)
                            foreach ($flirChunk as $_) {
                                $flir .= $_;
                            }
                            $flirChunk = [];    // free memory
                            $tagTablePtr = self::GetTagTable('Image::ExifTool::FLIR::FFF');
                            $dirInfo = [
                                'DataPt'   => $flir,
                                'Parent'   => $markerName,
                                'DirName'  => 'FLIR',
                            ];
                            $self->ProcessDirectory($dirInfo, $tagTablePtr);
                            $flirCount = null;  // prevent reprocessing
                        }
                    } else {
                        $self->Warn('Invalid or extraneous FLIR chunk(s)');
                    }
                } elseif (preg_match('/^PARROT\x00(II\x2a\x00|MM\x00\x2a)/', $segDataPt)) {
                    // (don't know if this could span multiple segments)
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::Main');
                    $self->HandleTag($tagTablePtr, 'APP1', $segDataPt);
                    $dumpType = 'Parrot';
                } else {
                    // Hmmm.  Could be XMP, let's see
                    $processed = null;
                    if (preg_match('/^(http|XMP\x00)/', $segDataPt) or preg_match('/<(exif:|\?xpacket)/', $segDataPt)) {
                        $dumpType = 'XMP';
                        // also try to parse XMP with a non-standard header
                        // (note: this non-standard XMP is ignored when writing)
                        $start = preg_match('#^' . Vars::$xmpAPP1hdr . '#', $segDataPt) ? strlen(Vars::$xmpAPP1hdr) : 0;
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::XMP::Main');
                        self::DirStart($dirInfo, $start);
                        $dirInfo['DirName'] = $start ? 'XMP' : 'XML';
                        $processed = $self->ProcessDirectory($dirInfo, $tagTablePtr);
                        if ($processed and !$start) {
                            $self->Warn('Non-standard header for APP1 XMP segment');
                        }
                    }
                    if ($verbose and !$processed) {
                        $self->Warn("Ignored APP1 segment length $length (unknown header)");
                    }
                }
            } elseif ($marker == 0xe2) {         // APP2 (ICC Profile, FPXR, MPF, InfiRay, URN, PreviewImage)
                if (preg_match('/^ICC_PROFILE\x00/', $segDataPt) and $length >= 14) {
                    $dumpType = 'ICC_Profile';
                    // must concatenate profile chunks (note: handle the case where
                    // some software erroneously writes zeros for the chunk counts)
                    $chunkNum = self::Get8u($segDataPt, 12);
                    $chunksTot = self::Get8u($segDataPt, 13);
                    if ($verbose) {
                        fwrite($out, "{$indent}ICC_Profile chunk $chunkNum of $chunksTot\n");
                    }
                    if ($iccChunksTotal !== null) {
                        // abort parsing ICC_Profile if the total chunk count is inconsistent
                        if ($chunksTot != $iccChunksTotal) {
                            $iccChunkCount = null;
                        }
                    } else {
                        $iccChunkCount = 0;
                        $iccChunksTotal = $chunksTot;
                        if (!$chunksTot) {
                            $self->Warn('ICC_Profile chunk count is zero');
                        }
                    }
                    if ($iccChunkCount !== null) {
                        if (isset($iccChunk[$chunkNum])) {
                            $self->Warn('Duplicate ICC_Profile chunk number(s)');
                            $iccChunk[$chunkNum] .= substr($segDataPt, 14);
                        } else {
                            $iccChunk[$chunkNum] = substr($segDataPt, 14);
                        }
                        // process profile if we have all of the chunks
                        if (++$iccChunkCount >= $iccChunksTotal) {
                            $icc_profile = '';
                            ksort($iccChunk);   // (Perl array order)
                            foreach ($iccChunk as $_) {
                                $icc_profile .= $_;
                            }
                            $iccChunk = [];     // free memory
                            $tagTablePtr = self::GetTagTable('Image::ExifTool::ICC_Profile::Main');
                            $dirInfo = [
                                'DataPt'   => $icc_profile,
                                'DataPos'  => $segPos + 14,
                                'DataLen'  => strlen($icc_profile),
                                'DirStart' => 0,
                                'DirLen'   => strlen($icc_profile),
                                'Parent'   => $markerName,
                            ];
                            $self->ProcessDirectory($dirInfo, $tagTablePtr);
                            $iccChunkCount = null;  // prevent reprocessing
                        }
                    } else {
                        $self->Warn('Invalid or extraneous ICC_Profile chunk(s)');
                    }
                } elseif (preg_match('/^FPXR\x00/', $segDataPt)) {
                    if ($fast > 1) {
                        continue;   // skip processing for very fast
                    }
                    $dumpType = 'FPXR';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::FlashPix::Main');
                    // set flag if this is the last FPXR segment
                    $dirInfo['LastFPXR'] = !($nextMarker == $marker and preg_match('/^FPXR\x00/', (string) $nextSegDataPt));
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^MPF\x00/', $segDataPt)) {
                    $dumpType = null;   // (will be dumped here)
                    self::DirStart($dirInfo, 4, 4);
                    $dirInfo['Multi'] = 1;  // the MP Attribute IFD will be MPF1
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 4, 'APP2 header', "Data size: $length bytes");
                        $self->HDump($segPos, 4, 'MPF header', 'APP2 data type: MPF');
                        $dumpEnd = $segPos + $length;
                    }
                    // extract the MPF information (it is in standard TIFF format)
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::MPF::Main');
                    $self->ProcessTIFF($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^....IJPEG\x00/s', $segDataPt)) {
                    $dumpType = 'InfiRay Version';
                    $self->HasIJPEG = 1;
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::Version');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^(|QVGA\x00|BGTH)\xff\xd8\xff[\xdb\xe0\xe1]/', $segDataPt, $m)) {
                    // Samsung/GE/GoPro="", BenQ DC C1220/Pentacon/Polaroid="QVGA\0",
                    // Digilife DDC-690/Rollei="BGTH"
                    $dumpType = 'Preview Image';
                    $preview = substr($segDataPt, strlen($m[1]));
                } elseif (preg_match('/^urn:/', $segDataPt)) {  // (found in Apple HDR images)
                    $dumpType = 'URN';
                    $useJpegMain = 1;
                } elseif ($preview) {
                    $dumpType = 'Preview Image';
                    $preview .= $segDataPt;
                }
                if ($preview and $nextMarker !== $marker) {
                    $self->FoundTag('PreviewImage', $preview);
                    $preview = null;
                }
            } elseif ($marker == 0xe3) {         // APP3 (Kodak "Meta", Stim)
                if (preg_match('/^(Meta|META|Exif)\x00\x00/', $segDataPt)) {
                    $dumpType = null;   // (will be dumped here)
                    self::DirStart($dirInfo, 6, 6);
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 10, 'APP3 Meta header');
                        $dumpEnd = $segPos + $length;
                    }
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Kodak::Meta');
                    $self->ProcessTIFF($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^Stim\x00/', $segDataPt)) {
                    $dumpType = null;   // (will be dumped here)
                    self::DirStart($dirInfo, 6, 6);
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 4, 'APP3 header', "Data size: $length bytes");
                        $self->HDump($segPos, 5, 'Stim header', 'APP3 data type: Stim');
                        $dumpEnd = $segPos + $length;
                    }
                    // extract the Stim information (it is in standard TIFF format)
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Stim::Main');
                    $self->ProcessTIFF($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^_JPSJPS_/', $segDataPt)) {
                    $dumpType = 'JPS';
                    if ($self->FILE_TYPE === 'JPEG') {
                        $self->OverrideFileType('JPS');
                    }
                    self::SetByteOrder('MM');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::JPS');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->HasIJPEG or $self->Make === 'DJI') {
                    $dumpType = $self->HasIJPEG ? 'InfiRay ImagingData' : 'DJI ThermalData';
                    // add this data to the combined data if it exists
                    $dataPt = $segDataPt;
                    if ($combinedSegData !== null) {
                        $combinedSegData .= $segDataPt;
                        $dataPt = $combinedSegData;
                    }
                    if ($nextMarker == $marker) {
                        if ($combinedSegData === null) {
                            $combinedSegData = $segDataPt;
                        }
                    } else {
                        // process InfiRay/DJI thermal data
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::Main');
                        $self->HandleTag($tagTablePtr, 'APP3', $dataPt);
                        $combinedSegData = null;
                    }
                } elseif (preg_match('/^\xff\xd8\xff\xdb/', $segDataPt)) {
                    $dumpType = 'PreviewImage'; // (Samsung, HP, BenQ)
                    $preview = $segDataPt;
                }
                if ($preview and $nextMarker !== 0xe4) { // this preview continues in APP4
                    $self->FoundTag('PreviewImage', $preview);
                    $preview = null;
                }
            } elseif ($marker == 0xe4) {         // APP4 (InfiRay, "SCALADO", FPXR, DJI, PreviewImage)
                if (preg_match('/^SCALADO\x00/', $segDataPt) and $length >= 16) {
                    $dumpType = 'SCALADO';
                    [$num, $idx, $len] = Perl::unpack('x8n2N', $segDataPt);
                    // assume that the segments are in order and just concatinate them
                    if ($scalado === null) {
                        $scalado = '';
                    }
                    $scalado .= substr($segDataPt, 16);
                    if ($idx == $num - 1) {
                        if ($len != strlen($scalado)) {
                            $self->Warn('Possibly corrupted APP4 SCALADO data', 1);
                        }
                        $dirInfo = [
                            'Parent' => $markerName,
                            'DataPt' => $scalado,
                        ];
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::Scalado::Main');
                        $self->ProcessDirectory($dirInfo, $tagTablePtr);
                        $scalado = null;
                    }
                } elseif (preg_match('/^Qualcomm Dual Camera Attributes/', $segDataPt)) {
                    $dumpType = 'Qualcomm Dual Camera';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Qualcomm::DualCamera');
                    self::DirStart($dirInfo, 31);
                    $dirInfo['DirName'] = 'Qualcomm Dual Camera';
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^FPXR\x00/', $segDataPt)) {
                    if ($fast > 1) {
                        continue;   // skip processing for very fast
                    }
                    $dumpType = 'FPXR';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::FlashPix::Main');
                    // set flag if this is the last FPXR segment
                    $dirInfo['LastFPXR'] = !($nextMarker == $marker and preg_match('/^FPXR\x00/', (string) $nextSegDataPt));
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->Make === 'DJI' and preg_match('/^\xaa\x55\x12\x06/', $segDataPt)) {
                    $dumpType = 'DJI ThermalParams';
                    self::DirStart($dirInfo, 0, 0);
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::DJI::ThermalParams');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->Make === 'DJI' and preg_match('/^(.{32})?.{32}\x2c\x01\x20\x00/s', $segDataPt, $m)) {
                    $dumpType = 'DJI ThermalParams2';
                    self::DirStart($dirInfo, ($m[1] ?? '') !== '' ? 32 : 0, 0);
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::DJI::ThermalParams2');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->Make === 'DJI' and preg_match('/^.{32}\xaa\x55\x38\x00/s', $segDataPt)) {
                    $dumpType = 'DJI ThermalParams3';
                    self::DirStart($dirInfo, 32, 0);
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::DJI::ThermalParams3');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->HasIJPEG and $length >= 120) {
                    $dumpType = 'InfiRay Factory';
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::Factory');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($preview) {
                    // continued Samsung S1060 preview from APP3
                    $dumpType = 'PreviewImage';
                    $preview .= $segDataPt;
                }
                // (also seen "QTI Debug Metadata\0" segment in some newer Samsung images)
                // BenQ DC E1050 continues preview in APP5
                if ($preview and $nextMarker !== 0xe5) {
                    $self->FoundTag('PreviewImage', $preview);
                    $preview = null;
                }
            } elseif ($marker == 0xe5) {         // APP5 (InfiRay, Ricoh "RMETA")
                if (preg_match('/^RMETA\x00/', $segDataPt)) {
                    // (NOTE: apparently these may span multiple segments, but I haven't seen
                    // a sample like this, so multi-segment support hasn't yet been implemented)
                    $dumpType = 'Ricoh RMETA';
                    self::DirStart($dirInfo, 6, 6);
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Ricoh::RMETA');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^ssuniqueid\x00/', $segDataPt)) {
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Samsung::APP5');
                    $self->HandleTag($tagTablePtr, 'ssuniqueid', substr($segDataPt, 11));
                } elseif ($self->Make === 'DJI') {
                    $dumpType = 'DJI ThermalCal';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::Main');
                    $self->HandleTag($tagTablePtr, 'APP5', $segDataPt);
                } elseif ($self->HasIJPEG and $length >= 38) {
                    $dumpType = 'InfiRay Picture';
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::Picture');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($preview) {
                    $dumpType = 'PreviewImage';
                    $preview .= $segDataPt;
                    $self->FoundTag('PreviewImage', $preview);
                    $preview = null;
                }
            } elseif ($marker == 0xe6) {         // APP6 (InfiRay, Toshiba EPPIM, NITF, HP_TDHD)
                if (preg_match('/^EPPIM\x00/', $segDataPt)) {
                    $dumpType = null;   // (will be dumped here)
                    self::DirStart($dirInfo, 6, 6);
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 10, 'APP6 EPPIM header');
                        $dumpEnd = $segPos + $length;
                    }
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::EPPIM');
                    $self->ProcessTIFF($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^NITF\x00/', $segDataPt)) {
                    $dumpType = 'NITF';
                    self::SetByteOrder('MM');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::NITF');
                    self::DirStart($dirInfo, 5);
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^TDHD\x01\x00\x00\x00/', $segDataPt) and $length > 12) {
                    // HP Photosmart R837 APP6 "TDHD" segment
                    $dumpType = 'TDHD';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::HP::TDHD');
                    // (ignore first TDHD element because size includes 12-byte tag header)
                    self::DirStart($dirInfo, 12);
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^GoPro\x00/', $segDataPt)) {
                    // GoPro segment
                    $dumpType = 'GoPro';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::GoPro::GPMF');
                    self::DirStart($dirInfo, 6);
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^DTAT\x00\x00.\{/s', $segDataPt)) {
                    $dumpType = 'DJI_DTAT';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::Main');
                    $self->HandleTag($tagTablePtr, 'APP6', $segDataPt);
                } elseif ($self->HasIJPEG and $length >= 129) {
                    $dumpType = 'InfiRay MixMode';
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::MixMode');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                }
            } elseif ($marker == 0xe7) {         // APP7 (InfiRay, Pentax, Huawei, Qualcomm)
                if (preg_match('/^(PENTAX |RICOH)\x00(II|MM)/', $segDataPt, $m)) {
                    // found in K-3 and Ricoh GR_IV images (is this multi-segment??)
                    self::SetByteOrder($m[2]);
                    $dumpType = null; // (dump this ourself)
                    $hdrLen = strlen($m[1]) + 3;
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Pentax::Main');
                    self::DirStart($dirInfo, $hdrLen, 0);
                    $dirInfo['DirName'] = 'Pentax APP7';
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 4, 'APP7 header', "Data size: $length bytes");
                        $self->HDump($segPos, $hdrLen, 'Pentax header', 'APP7 data type: Pentax');
                        $dumpEnd = $segPos + $length;
                    }
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^HUAWEI\x00\x00(II|MM)/', $segDataPt, $m)) {
                    self::SetByteOrder($m[1]);
                    $dumpType = null; // (dump this ourself)
                    $hdrLen = 16;
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Unknown::Main');
                    self::DirStart($dirInfo, $hdrLen, 8);
                    $dirInfo['DirName'] = 'Huawei APP7';
                    if ($htmlDump) {
                        $self->HDump($segPos - 4, 4, 'APP7 header', "Data size: $length bytes");
                        $self->HDump($segPos, $hdrLen, 'Huawei header', 'APP7 data type: Huawei');
                        $dumpEnd = $segPos + $length;
                    }
                    $self->SET_GROUP0 = 'APP7';
                    $self->SET_GROUP1 = 'Huawei';
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                    $self->SET_GROUP0 = null;
                    $self->SET_GROUP1 = null;
                } elseif (preg_match('/^DJI-DBG\x00/', $segDataPt)) {
                    $dumpType = 'DJI Info';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::DJI::Info');
                    self::DirStart($dirInfo, 8, 0);
                    $self->SET_GROUP0 = 'APP7';
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                    $self->SET_GROUP0 = null;
                } elseif (preg_match('/^\x1aQualcomm Camera Attributes/', $segDataPt)) {
                    // found in HP iPAQ_VoiceMessenger
                    $dumpType = 'Qualcomm';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Qualcomm::Main');
                    self::DirStart($dirInfo, 27);
                    $dirInfo['DirName'] = 'Qualcomm';
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->HasIJPEG and $length >= 32) {
                    $dumpType = 'InfiRay OpMode';
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::OpMode');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                }
            } elseif ($marker == 0xe8) {         // APP8 (InfiRay, SPIFF)
                // my sample SPIFF has 32 bytes of data, but spec states 30
                if (preg_match('/^SPIFF\x00/', $segDataPt) and $length == 32) {
                    $dumpType = 'SPIFF';
                    self::DirStart($dirInfo, 6);
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::SPIFF');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif ($self->HasIJPEG and $length >= 32) {
                    $dumpType = 'InfiRay Isothermal';
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::Isothermal');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^SEAL\x00/', $segDataPt)) {
                    $dumpType = 'SEAL';
                    self::DirStart($dirInfo, 5);
                    $self->ProcessDirectory($dirInfo, self::GetTagTable('Image::ExifTool::XMP::SEAL'));
                }
            } elseif ($marker == 0xe9) {         // APP9 (InfiRay, Media Jukebox)
                if (preg_match('/^Media Jukebox\x00/', $segDataPt) and $length > 22) {
                    $dumpType = 'MediaJukebox';
                    // (start parsing after the "<MJMD>")
                    self::DirStart($dirInfo, 22);
                    $dirInfo['DirName'] = 'MediaJukebox';
                    self::RequireModule('Image::ExifTool::XMP');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::MediaJukebox');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr, 'Image\ExifTool\XMP::ProcessXMP');
                } elseif ($self->HasIJPEG and $length >= 768) {
                    $dumpType = 'InfiRay Sensor';
                    self::SetByteOrder('II');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::InfiRay::Sensor');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } elseif (preg_match('/^SEAL\x00/', $segDataPt)) {
                    $dumpType = 'SEAL';
                    self::DirStart($dirInfo, 5);
                    $self->ProcessDirectory($dirInfo, self::GetTagTable('Image::ExifTool::XMP::SEAL'));
                }
            } elseif ($marker == 0xea) {         // APP10 (PhotoStudio Unicode comments, HDR gain curve)
                if (preg_match('/^UNICODE\x00/', $segDataPt)) {
                    $dumpType = 'PhotoStudio';
                    $comment = $self->Decode(substr($segDataPt, 8), 'UTF16', 'MM');
                    $self->FoundTag('Comment', $comment);
                } elseif (preg_match('/^AROT\x00\x00.{4}/s', $segDataPt)) {
                    $dumpType = 'AROT'; // (HDR gain curve? PH guess)
                    $useJpegMain = 1;
                }
            } elseif ($marker == 0xeb) {         // APP11 (JPEG-HDR, JUMBF)
                if (preg_match('/^HDR_RI /', $segDataPt)) {
                    $dumpType = 'JPEG-HDR';
                    $dataPt = $segDataPt;
                    if ($combinedSegData !== null) {
                        if (($p = strpos($segDataPt, "~\x00")) !== false) {    // ($$segDataPt =~ /~\0/g)
                            $combinedSegData .= substr($segDataPt, $p + 2);
                        } else {
                            $self->Warn('Invalid format for JPEG-HDR extended segment');
                        }
                        $dataPt = $combinedSegData;
                    }
                    if ($nextMarker == $marker and preg_match('/^HDR_RI /', (string) $nextSegDataPt)) {
                        if ($combinedSegData === null) {
                            $combinedSegData = $segDataPt;
                        }
                    } else {
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::HDR');
                        $dirInfo = ['DataPt' => $dataPt];
                        $self->ProcessDirectory($dirInfo, $tagTablePtr);
                        $combinedSegData = null;
                    }
                } elseif (preg_match('/^(JP..)/s', $segDataPt, $m) and strlen($segDataPt) >= 16) {
                    // JUMBF extension marker
                    $hdr = $m[1];
                    $dumpType = 'JUMBF';
                    self::SetByteOrder('MM');
                    // (sequence should start from 1, but some software incorrectly writes 0)
                    $seq = self::Get32u($segDataPt, 4);
                    $len = self::Get32u($segDataPt, 8);
                    $type = substr($segDataPt, 12, 4);
                    // a Microsoft bug writes $len and $type incorrectly as little-endian
                    if ($type === 'bmuj') {
                        $self->Warn('Wrong byte order in C2PA APP11 JUMBF header');
                        $type = 'jumb';
                        $len = Perl::unpack('x8V', $segDataPt)[0];
                        // fix the header
                        $segDataPt = substr_replace($segDataPt, self::Set32u($len) . $type, 8, 8);
                    }
                    if ($len == 1 and strlen($segDataPt) >= 24) {
                        // (haven't seen this with the Microsoft bug)
                        $len = self::Get64u($segDataPt, 16);
                        $hdrLen = 16;
                    } else {
                        $hdrLen = 8;
                    }
                    $jumbfChunk[$type] ??= [''];
                    if ($len < $hdrLen) {
                        $self->Warn('Invalid JUMBF segment');
                    } elseif (isset($jumbfChunk[$type][$seq]) and strlen($jumbfChunk[$type][$seq])) {
                        $self->Warn('Duplicate JUMBF sequence number');
                    } else {
                        $seq or $self->Warn('Incorrect JUMBF sequence numbering (should start from 0, not 1)');
                        // add to list of JUMBF chunks
                        $jumbfChunk[$type][$seq] = substr($segDataPt, 8 + $hdrLen);
                        ksort($jumbfChunk[$type]);  // (Perl array order)
                        // check to see if we have a complete JUMBF box
                        // (a missing index is an undefined element of the Perl array)
                        $size = $hdrLen;
                        for ($i = 0; $i < count($jumbfChunk[$type]); ++$i) {
                            if (!isset($jumbfChunk[$type][$i])) {
                                $size = 0;
                                break;
                            }
                            $size += strlen($jumbfChunk[$type][$i]);
                        }
                        if ($size == $len) {
                            $buff = substr($segDataPt, 8, $hdrLen) . implode('', $jumbfChunk[$type]);
                            $dirInfo['DataPt'] = $buff;
                            $dirInfo['DataPos'] = $segPos + 8; // (shows correct offsets for single-segment JUMBF)
                            $dirInfo['DataLen'] = $dirInfo['DirLen'] = $size;
                            $dirInfo['DirName'] = 'JUMBF';
                            $tagTablePtr = self::GetTagTable('Image::ExifTool::Jpeg2000::Main');
                            $self->ProcessDirectory($dirInfo, $tagTablePtr);
                            unset($jumbfChunk[$type]);
                        }
                    }
                }
            } elseif ($marker == 0xec) {         // APP12 (Ducky, Picture Info)
                if (preg_match('/^Ducky/', $segDataPt)) {
                    $dumpType = 'Ducky';
                    self::DirStart($dirInfo, 5);
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::APP12::Ducky');
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                } else {
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::APP12::PictureInfo');
                    if ($self->ProcessDirectory($dirInfo, $tagTablePtr)) {
                        $dumpType = 'Picture Info';
                    }
                }
            } elseif ($marker == 0xed) {         // APP13 (Photoshop, Adobe_CM)
                $isOld = null;
                if (preg_match('#^' . Vars::$psAPP13hdr . '#', $segDataPt) or
                    (preg_match('#^' . Vars::$psAPP13old . '#', $segDataPt) and $isOld = 1))
                {
                    $dumpType = 'Photoshop';
                    // add this data to the combined data if it exists
                    $dataPt = $segDataPt;
                    if ($combinedSegData !== null) {
                        $combinedSegData .= substr($segDataPt, strlen(Vars::$psAPP13hdr));
                        $dataPt = $combinedSegData;
                    }
                    // peek ahead to see if the next segment is photoshop data too
                    if ($nextMarker == $marker and preg_match('#^' . Vars::$psAPP13hdr . '#', (string) $nextSegDataPt)) {
                        // initialize combined data if necessary
                        if ($combinedSegData === null) {
                            $combinedSegData = $segDataPt;
                        }
                        // (will handle the Photoshop data the next time around)
                    } else {
                        $hdrLen = $isOld ? 27 : 14;
                        // process APP13 Photoshop record
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::Photoshop::Main');
                        $dirInfo = [
                            'DataPt'   => $dataPt,
                            'DataPos'  => $segPos,
                            'DataLen'  => strlen($dataPt),
                            'DirStart' => $hdrLen,      // directory starts after identifier
                            'DirLen'   => strlen($dataPt) - $hdrLen,
                            'Parent'   => $markerName,
                        ];
                        $self->ProcessDirectory($dirInfo, $tagTablePtr);
                        $combinedSegData = null;
                    }
                } elseif (preg_match('/^Adobe_CM/', $segDataPt)) {
                    $dumpType = 'Adobe_CM';
                    self::SetByteOrder('MM');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::AdobeCM');
                    self::DirStart($dirInfo, 8);
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                }
            } elseif ($marker == 0xee) {         // APP14 (Adobe)
                if (preg_match('/^Adobe/', $segDataPt)) {
                    // extract as a block if requested, or if copying tags from file
                    if (($req['adobe'] ?? null) or
                        // (not extracted normally, so check TAGS_FROM_FILE)
                        ($self->TAGS_FROM_FILE and !($self->EXCL_TAG_LOOKUP['adobe'] ?? null)))
                    {
                        $self->FoundTag('Adobe', $segDataPt);
                    }
                    $dumpType = 'Adobe';
                    self::SetByteOrder('MM');
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::Adobe');
                    self::DirStart($dirInfo, 5);
                    $self->ProcessDirectory($dirInfo, $tagTablePtr);
                }
            } elseif ($marker == 0xef) {         // APP15 (GraphicConverter)
                if (preg_match('/^Q\s*(\d+)/', $segDataPt, $m) and $length == 4) {
                    $dumpType = 'GraphicConverter';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::GraphConv');
                    $self->HandleTag($tagTablePtr, 'Q', $m[1]);
                }
            } elseif ($marker == 0xfe) {         // COM (JPEG comment)
                $dumpType = 'Comment';
                $segDataPt = preg_replace('/\x00+$/', '', $segDataPt, 1);  // some dumb softwares add null terminators
                $self->FoundTag('Comment', $segDataPt);
            } elseif ($marker == 0x64) {         // CME (J2C comment and extension)
                $dumpType = 'Comment';
                if ($length > 2) {
                    $reg = Perl::unpack('n', $segDataPt)[0];   // get registration value
                    $val = substr($segDataPt, 2);
                    if ($reg == 1) {
                        $val = $self->Decode($val, 'Latin');
                    }
                    // (actually an extension for $reg==65535, but store as binary comment)
                    $self->FoundTag('Comment', ($reg == 0 or $reg == 65535) ? new ScalarRef($val) : $val);
                }
            } elseif ($marker == 0x51) {         // SIZ (J2C)
                [$w, $h] = Perl::unpack('x2N2', $segDataPt) + [null, null];
                if (!$gotSize) {
                    $gotSize = 1;
                    $self->FoundTag('ImageWidth', $w);
                    $self->FoundTag('ImageHeight', $h);
                }
            } elseif (($marker & 0xf0) != 0xe0) {
                $dumpType = "$markerName segment";
                $desc = "[JPEG $markerName]";   // (other known JPEG segments)
            }
            if ($dumpType !== null) {
                if ($useJpegMain) {
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::JPEG::Main');
                    $self->HandleTag($tagTablePtr, $markerName, $segDataPt);
                }
                if (!$dumpType and ($options['Unknown'] or $options['Validate'])) {
                    $str = preg_match('/^([\x20-\x7e]{1,20})\x00/', $segDataPt, $m) ? " '{$m[1]}'" : '';
                    if (!$xtra) {
                        $xtra = 'segment';
                    }
                    $self->Warn("Unknown $markerName$str $xtra", 1);
                }
                if ($htmlDump) {
                    $desc or $desc = $markerName . ($dumpType ? " $dumpType" : '') . ' segment';
                    $self->HDump($segPos - 4, $length + 4, $desc, $tip, 0x08);
                    $dumpEnd = $segPos + $length;
                }
            }
            $segDataPt = null;
        }
        // print verbose hash message if necessary
        if ($hashsize and $verbose) {
            fwrite($out, "{$indent}(ImageDataHash: $hashsize bytes of JPEG image data)\n");
        }
        // calculate JPEGDigest if requested
        if ($dqt) {
            self::RequireModule('Image::ExifTool::JPEGDigest');
            // (Perl array: list in index order, undef for the missing tables)
            $dqtList = array_replace(array_fill(0, max(array_keys($dqt)) + 1, null), $dqt);
            \Image\ExifTool\JPEGDigest::Calculate($self, $dqtList, $subSampling);
        }
        // issue necessary warnings
        if ($jumbfChunk) {
            $self->Warn('Invalid JUMBF size or missing JUMBF chunk');
        }
        if ($iccChunkCount !== null) {
            $self->Warn('Incomplete ICC_Profile record', 1);
        }
        if ($flirCount !== null) {
            $self->Warn('Incomplete FLIR record', 1);
        }
        if ($self->PreviewError) {
            $self->Warn('Error reading PreviewImage', 1);
        }
        $success or $self->Warn('JPEG format error');
        if (count($path) > $pn) {
            array_pop($path);
        }
        return 1;

        } finally {
            Perl::$RS = $oldRS;     // (local $/)
        }
    }

    //------------------------------------------------------------------------------
    // Extract metadata from an Exiv2 EXV file
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref with RAF set
    // Returns: 1 on success, 0 if this wasn't a valid JPEG file
    public function ProcessEXV(array &$dirInfo)
    {
        $self = $this;
        return $self->ProcessJPEG($dirInfo);
    }

    //------------------------------------------------------------------------------
    // Process EXIF file
    // Inputs/Returns: same as ProcessTIFF
    public function ProcessEXIF(array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $self = $this;
        return $self->ProcessTIFF($dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process TIFF data (wrapper for DoProcessTIFF to allow re-entry)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) optional tag table ref
    // Returns: 1 if this looked like a valid EXIF block, 0 otherwise, or -1 on write error
    public function ProcessTIFF(array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $self = $this;
        $exifData = $self->EXIF_DATA;
        $exifPos = $self->EXIF_POS;
        $rtnVal = $self->DoProcessTIFF($dirInfo, $tagTablePtr);
        // restore original EXIF information (in case ProcessTIFF is nested)
        if ($exifData !== null) {
            $self->EXIF_DATA = $exifData;
            $self->EXIF_POS = $exifPos;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process TIFF as a sub-document
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) optional tag table ref
    // Returns: 1 if this looked like a valid EXIF block, 0 otherwise, or -1 on write error
    public function ProcessSubTIFF(array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $self = $this;
        $self->DOC_NUM = ++$self->DOC_COUNT;
        $rtnVal = $self->ProcessTIFF($dirInfo, $tagTablePtr);
        $self->DOC_NUM = null;
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Process TIFF data
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) optional tag table ref
    // Returns: 1 if this looked like a valid EXIF block, 0 otherwise, or -1 on write error
    public function DoProcessTIFF(array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $self = $this;
        $dataPt = $dirInfo['DataPt'] ?? null;
        $fileType = ($dirInfo['Parent'] ?? null) ?: '';
        $raf = $dirInfo['RAF'] ?? null;
        $base = ($dirInfo['Base'] ?? null) ?: 0;
        $err = $sig = $canonSig = $otherSig = null;

        // attempt to read TIFF header
        if ($raf) {
            $self->EXIF_DATA = '';
            if (!$raf->Seek($base, 0)) {
                return 0;
            }
            // extract full EXIF block (for block copy) from EXIF file
            $amount = $fileType === 'EXIF' ? 65536 * 8 : 8;
            $n = $raf->Read($self->EXIF_DATA, $amount);
            if ($n < 8) {
                return 0;   // (creating an EXIF file from scratch is for writing only)
            }
            if ($n > 8) {
                $raf->Seek(8, 0);
                if ($n == $amount) {
                    $self->EXIF_DATA = substr($self->EXIF_DATA, 0, 8);
                    $self->Warn('EXIF too large to extract as a block'); //(shouldn't happen)
                }
            }
        } elseif ($dataPt !== null and strlen($dataPt)) {
            // save a copy of the EXIF data
            $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
            $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
            // (Perl substr gives undef when starting beyond the end of the string)
            $self->EXIF_DATA = $dirStart > strlen($dataPt) ? null : substr($dataPt, $dirStart, $dirLen);
            if ($self->OPTIONS['Verbose'] and strlen($self->INDENT) > 2) {
                $self->VerboseDir('TIFF');
            }
        } else {
            $self->EXIF_DATA = '';
        }
        if ($self->EXIF_DATA === null) {
            // set default byte order for creating new GPS in CR3 images
            $defaultByteOrder = null;
            if (($dirInfo['DirName'] ?? null) and $dirInfo['DirName'] === 'GPS') {
                $defaultByteOrder = $self->SaveExifByteOrder;
            }
            // create TIFF information from scratch
            if ($self->SetPreferredByteOrder($defaultByteOrder) === 'MM') {
                $self->EXIF_DATA = "MM\x00\x2a\x00\x00\x00\x08";
            } else {
                $self->EXIF_DATA = "II\x2a\x00\x08\x00\x00\x00";
            }
        }
        $self->EXIF_POS = $base + $self->BASE;
        if ($self->FIRST_EXIF_POS === null) {
            $self->FIRST_EXIF_POS = $self->EXIF_POS;
        }
        $dataPt = &$self->EXIF_DATA;

        // set byte ordering
        $byteOrder = substr($dataPt, 0, 2);
        if (!self::SetByteOrder($byteOrder)) {
            return 0;
        }

        // verify the byte ordering
        $identifier = self::Get16u($dataPt, 2);
        // identifier is 0x2a for TIFF (but 0x4f52, 0x5352 or ?? for ORF)
        // no longer do this because various files use different values
        // (TIFF=0x2a, RW2/RWL=0x55, HDP=0xbc, BTF=0x2b, ORF=0x4f52/0x5352/0x????)
        //  return 0 unless $identifier == 0x2a;
        if ($fileType === 'APP1' and $identifier != 0x2a) {
            $self->Warn('Invalid magic number in EXIF TIFF header');
        }

        // get offset to IFD0
        if (strlen($dataPt) < 8) {
            return 0;
        }
        $offset = self::Get32u($dataPt, 4);
        if (!($offset >= 8)) {
            return 0;
        }

        if ($raf) {
            // check for canon or EXIF signature
            // (Canon CR2 images should have an offset of 16, but it may be
            //  greater if edited by PhotoMechanic)
            if ($identifier == 0x2a and $offset >= 16) {
                if ($raf->Read($sig, 8) != 8) {
                    return 0;
                }
                $dataPt .= $sig;
                if (preg_match('/^(CR\x02\x00|\xba\xb0\xac\xbb|ExifMeta)/', $sig)) {
                    if ($sig === 'ExifMeta') {
                        $self->SetFileType($fileType = 'EXIF');
                        $otherSig = $sig;
                    } else {
                        $fileType = preg_match('/^CR/', $sig) ? 'CR2' : 'Canon 1D RAW';
                        $canonSig = $sig;
                    }
                    if ($self->HTML_DUMP) {
                        $self->HDump($base + 8, 8, "[$fileType header]");
                    }
                }
            } elseif ($identifier == 0x55 and preg_match('/^(RAW|RW2|RWL|TIFF)$/', $fileType)) {
                // panasonic RAW, RW2 or RWL file
                $magic = null;
                // test for RW2/RWL magic number
                if ($offset >= 0x18 and $raf->Read($magic, 16) and
                    $magic === "\x88\xe7\x74\xd8\xf8\x25\x1d\x4d\x94\x7a\x6e\x77\x82\x2b\x5d\x6a")
                {
                    if ($fileType !== 'RWL') {
                        $fileType = 'RW2';
                    }
                    if ($self->HTML_DUMP) {
                        $self->HDump($base + 8, 16, '[RW2/RWL header]');
                    }
                    $otherSig = $magic; // save signature for writing
                } else {
                    $fileType = 'RAW';
                }
                $tagTablePtr = self::GetTagTable('Image::ExifTool::PanasonicRaw::Main');
            } elseif ($fileType === 'TIFF') {
                if ($identifier == 0x2b) {
                    // this looks like a BigTIFF image
                    $raf->Seek(0);
                    self::RequireModule('Image::ExifTool::BigTIFF');
                    $result = \Image\ExifTool\BigTIFF::ProcessBTF($self, $dirInfo);
                    if ($result) {
                        if ($self->MultiPage) {
                            $self->FoundTag('PageCount', $self->PageCount);
                        }
                        return 1;
                    }
                } elseif ($identifier == 0x4f52 or $identifier == 0x5352) {
                    // Olympus ORF image (set FileType now because base type is 'ORF')
                    $self->SetFileType($fileType = 'ORF');
                } elseif ($identifier == 0x4352) {
                    $fileType = 'DCP';
                } elseif ($byteOrder === 'II' and ($identifier & 0xff) == 0xbc) {
                    $fileType = 'HDP';  // Windows HD Photo file
                    // check version number
                    $ver = self::Get8u($dataPt, 3);
                    if ($ver > 1) {
                        $self->Error("Windows HD Photo version $ver files not yet supported");
                        return 1;
                    }
                }
            } elseif ($fileType === 'ARW') {
                $self->LOW_PRIORITY_DIR['IFD1'] = 1; // lower priority of IFD1 tags in ARW files
            }
            // we have a valid TIFF (or whatever) file
            if ($fileType and !($self->VALUE['FileType'] ?? null)) {
                $lookup = Vars::$fileTypeLookup[$fileType] ?? null;
                if (!(is_array($lookup) or !$lookup)) {
                    $lookup = Vars::$fileTypeLookup[$lookup] ?? null;
                }
                // use file extension to pre-determine type if extension is TIFF-based or type is RAW
                $baseType = $lookup ? (is_array($lookup[0]) ? $lookup[0][0] : $lookup[0]) : '';
                $t = ($baseType === 'TIFF' or preg_match('/RAW/', $fileType)) ? $fileType : null;
                $self->SetFileType($t);
            }
            // don't process file if FastScan > 2
            if ($self->OPTIONS['FastScan'] and $self->OPTIONS['FastScan'] > 2) {
                return 1;
            }
        }
        // (accommodate CR3 images which have a TIFF directory with ExifIFD at the top level)
        $ifdName = (($dirInfo['DirName'] ?? null) and preg_match('/^(ExifIFD|GPS)$/', $dirInfo['DirName'], $m)) ? $m[1] : 'IFD0';
        if (!$tagTablePtr or $tagTablePtr['GROUPS'][0] === 'EXIF') {
            $self->FoundTag('ExifByteOrder', $byteOrder);
            $self->ExifByteOrder = $byteOrder;
        } elseif ($tagTablePtr['GROUPS'][0] === 'MakerNotes') { // (for writing CR3 maker notes)
            $ifdName = $tagTablePtr['GROUPS'][0];
        } else {
            $ifdName = $tagTablePtr['GROUPS'][1];
        }
        if ($self->HTML_DUMP) {
            $tip = Perl::sprintf("Byte order: %s endian\nIdentifier: 0x%.4x\n$ifdName offset: 0x%.4x",
                                 ($byteOrder === 'II') ? 'Little' : 'Big', $identifier, $offset);
            $self->HDump($base, 8, 'TIFF header', $tip, 0);
        }
        // remember where we found the TIFF data (APP1, APP3, TIFF, NEF, etc...)
        $self->TIFF_TYPE = $fileType;

        // get reference to the main EXIF table
        $tagTablePtr or $tagTablePtr = self::GetTagTable('Image::ExifTool::Exif::Main');

        // build directory information hash
        // (%dirInfo is a new hash, not the caller's $dirInfo)
        $multi = $dirInfo['Multi'] ?? null;
        unset($dirInfo);
        $dirInfo = [
            'Base'     => $base,
            'DataPt'   => $dataPt,
            'DataLen'  => strlen($dataPt),
            'DataPos'  => 0,
            'DirStart' => $offset,
            'DirLen'   => strlen($dataPt) - $offset,
            'RAF'      => $raf,
            'DirName'  => $ifdName,
            'Parent'   => $fileType,
            'ImageData'=> 'Main', // set flag to get information to copy main image data later
            'Multi'    => $multi,
        ];

        // extract information from the image
        // process the directory
        $self->ProcessDirectory($dirInfo, $tagTablePtr);
        // process GeoTiff information if available
        if ($self->VALUE['GeoTiffDirectory'] ?? null) {
            self::RequireModule('Image::ExifTool::GeoTiff');
            \Image\ExifTool\GeoTiff::ProcessGeoTiff($self);
        }
        // process information in recognized trailers
        if ($raf) {
            $trailInfo = $self->IdentifyTrailer($raf);
            if ($trailInfo) {
                // scan to find AFCP if necessary (Note: we are scanning
                // from a random file position in the TIFF)
                $trailInfo['ScanForTrailer'] = 1;
                $self->ProcessTrailers($trailInfo);
            }
            // dump any other known trailer (eg. A100 RAW Data)
            if ($self->HTML_DUMP and $self->KnownTrailer) {
                $known = $self->KnownTrailer;
                $raf->Seek(0, 2);
                $len = $raf->Tell() - $known['Start'];
                if ($trailInfo) {
                    $len -= $trailInfo['Offset'];   // account for other trailers
                }
                if ($len > 0) {
                    $self->HDump($known['Start'], $len, "[{$known['Name']}]");
                }
            }
        }
        // update FileType if necessary now that we know more about the file
        if ($self->DNGVersion and $self->FILE_TYPE === 'TIFF' and !preg_match('/^(DNG|GPR)$/', (string) $self->FileType)) {
            // override whatever FileType we set since we now know it is DNG
            $self->OverrideFileType($self->TIFF_TYPE = 'DNG');
        }
        if ($self->TIFF_TYPE === 'TIFF') {
            if ($self->MultiPage) {
                $self->FoundTag('PageCount', $self->PageCount);
            }
        } elseif ($self->TIFF_TYPE === 'NRW' and ($self->VALUE['NEFLinearizationTable'] ?? null)) {
            // fix NEF type if misidentified as NRW
            $self->OverrideFileType($self->TIFF_TYPE = 'NEF');
        }
        if ($self->ImageDataHash and $self->A100DataOffset and $raf->Seek($self->A100DataOffset, 0)) {
            $self->ImageDataHash($raf, null, 'A100');
        }
        return 1;
        // (rewriting the image is not ported)
    }

    //------------------------------------------------------------------------------
    // Set byte order according to our current preferences
    // Inputs: 0) ExifTool object ref, 1) default byte order
    // Returns: new byte order ('II' or 'MM') and sets current byte order
    // Notes: takes the first of the following that is valid:
    //  1) ByteOrder option
    //  2) new value for ExifByteOrder
    //  3) default byte order passed to this routine
    //  4) makenote byte order from last file read
    //  5) big endian
    // (from Writer.pl: only needed by DoProcessTIFF when reading; there are no new values
    //  when reading, so GetNewValue('ExifByteOrder') is always undef)
    public function SetPreferredByteOrder($default = null)
    {
        $self = $this;
        $byteOrder = $self->Options('ByteOrder') ?:
                     $default ?: $self->MAKER_NOTE_BYTE_ORDER ?: 'MM';
        if (!self::SetByteOrder($byteOrder)) {
            if ($self->Options('Verbose')) {
                fwrite(STDERR, "Invalid byte order '$byteOrder'\n");    // (Perl warn)
            }
            $byteOrder = $self->MAKER_NOTE_BYTE_ORDER ?: 'MM';
            self::SetByteOrder($byteOrder);
        }
        return self::GetByteOrder();
    }
}
