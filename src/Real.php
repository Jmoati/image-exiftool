<?php

//------------------------------------------------------------------------------
// File:         Real.pm
//
// Description:  Read Real audio/video meta information
//
// Revisions:    05/16/2006 - P. Harvey Created
//
// References:   1) http://www.getid3.org/
//               2) https://common.helixcommunity.org/nonav/2003/HCS_SDK_r5/htmfiles/rmff.htm
//
// PHP port of Image::ExifTool::Real 1.07 (reading only). Tag tables and file lexicals
// (%propertyType, %metadataFormat, %metadataFlag) are generated into tables/Real.php and
// Vars\Real.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Real
{
    public static $VERSION = '1.07';

    /** ValueConv and PrintConv added to "date" type tags by ProcessRealMeta (Perl code strings) */
    private static ?\Closure $dateValueConv = null;
    private static ?\Closure $datePrintConv = null;

    //------------------------------------------------------------------------------
    // Process Real NameValueProperties
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessRealProperties(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = $dirInfo['DirLen'];
        $pos = $dirInfo['DirStart'];
        $verbose = $et->Options('Verbose');

        if ($verbose) $et->VerboseDir('RealProperties', null, $dirLen);

        while ($pos + 6 <= $dirLen) {

            // get property size and version
            [$size, $vers] = Perl::unpack("x{$pos}Nn", $dataPt);
            if ($size < 6) break;
            if (!($vers == 0)) {
                $pos += $size;
                continue;
            }
            $pos += 6;

            $tagLen = Perl::unpack("x{$pos}C", $dataPt)[0] ?? 0;
            ++$pos;

            if ($pos + $tagLen > $dirLen) break;
            $tag = substr($dataPt, $pos, $tagLen);
            $pos += $tagLen;

            if ($pos + 6 > $dirLen) break;
            [$type, $valLen] = Perl::unpack("x{$pos}Nn", $dataPt);
            $pos += 6;

            if ($pos + $valLen > $dirLen) break;
            $format = (Vars\Real::$propertyType[$type] ?? null) ?: 'undef';
            $count = Perl::int($valLen / ExifTool::FormatSize($format));
            $val = ExifTool::ReadValue($dataPt, $pos, $format, $count, $dirLen - $pos);

            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo) {
                $tagName = preg_replace('/\s+/', '', $tag);
                if (!preg_match('/^\w+$/', $tagName)) continue;    // ignore crazy names
                $tagInfo = ['Name' => ucfirst($tagName)];
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            if ($verbose) {
                $et->VerboseInfo($tag, $tagInfo,
                    Table  : $tagTablePtr,
                    Value  : $val,
                    DataPt : $dataPt,
                    Size   : $valLen,
                    Start  : $pos,
                    Addr   : $pos + ($dirInfo['DataPos'] ?? 0),
                    Format : $format,
                    Count  : $count,
                );
            }
            $et->FoundTag($tagInfo, $val);
            $pos += $valLen;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Real metadata properties
    // Inputs: 0) ExifTool object reference, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessRealMeta(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = $dirInfo['DirStart'];
        $dirEnd = $pos + $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');
        $prefix = ($dirInfo['Prefix'] ?? null) ?: '';
        if ($prefix) $prefix .= '/';

        if ($verbose) $et->VerboseDir('RealMetadata', null, $dirInfo['DirLen']);

        for (;;) {
            if ($pos + 28 > $dirEnd) break;
            // extract fixed-position metadata structure members
            [$size, $type, $flags, $valuePos, $subPropPos, $numSubProps, $nameLen]
                = Perl::unpack("x{$pos}N7", $dataPt);
            // make pointers relative to data start
            $valuePos += $pos;
            $subPropPos += $pos;
            // validate what we have read so far
            if ($pos + $size > $dirEnd) break;
            if ($pos + 28 + $nameLen > $dirEnd) break;
            if ($valuePos <  $pos + 28 + $nameLen) break;
            if ($valuePos + 4 > $dirEnd) break;
            $tag = substr($dataPt, $pos + 28, $nameLen);
            $tag = preg_replace('/\0.*/s', '', $tag, 1);  // truncate at null
            $tag = $prefix . $tag;
            $valueLen = Perl::unpack("x{$valuePos}N", $dataPt)[0] ?? null;
            $valuePos += 4; // point at value itself
            if ($valuePos + $valueLen > $dirEnd) break;

            $format = Vars\Real::$metadataFormat[$type] ?? null;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo) {
                $tagName = $tag;
                $tagName = Perl::tr($tagName, 'A-Za-z0-9', '', 'dc')[0];
                $tagInfo = ['Name' => ucfirst($tagName)];
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            if ($verbose) {
                if ($format === null) $format = 'undef';
                $flags = ExifTool::DecodeBits($flags, Vars\Real::$metadataFlag);
            }
            if ($valueLen and $format) {
                // (a flag can be 1 or 4 bytes)
                if ($format === 'flag') {
                    $format = ($valueLen == 4) ? 'int32u' : 'int8u';
                } elseif ($type == 7 and $tagInfo) {
                    // add PrintConv and ValueConv for "date" type
                    if (!$tagInfo->ValueConv) $tagInfo->ValueConv = self::$dateValueConv ??=
                        static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
                            return preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', Perl::str($val), $m) ?
                                Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]) :
                                $val;
                        };
                    if (!$tagInfo->PrintConv) $tagInfo->PrintConv = self::$datePrintConv ??=
                        static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
                            return $self->ConvertDateTime($val);
                        };
                }
                $count = Perl::int($valueLen / ExifTool::FormatSize($format));
                $val = ExifTool::ReadValue($dataPt, $valuePos, $format, $count, $dirEnd - $valuePos);
                $et->HandleTag($tagTablePtr, $tag, $val,
                    DataPt  : $dataPt,
                    DataPos : $dataPos,
                    Start   : $valuePos,
                    Size    : $valueLen,
                    Format  : "type=$type, flags=" . Perl::str($flags),
                );
            }
            // extract sub-properties
            if ($numSubProps) {
                $dirStart = $valuePos + $valueLen + $numSubProps * 8;
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt' => $dataPt,
                    'DataPos' => $dataPos,
                    'DirStart' => $dirStart,
                    'DirLen' => $pos + $size - $dirStart,
                    'Prefix' => $tag,
                ];
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            }
            $pos += $size;  // step to next Metadata structure
        }
        if (!($pos == $dirEnd)) {
            $et->Warn('Format error in Real Metadata');
            return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information frame a Real file
    // Inputs: 0) ExifTool object reference, 1) Directory information reference
    // Returns: 1 on success, 0 if this wasn't a valid Real file
    public static function ProcessReal(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tag = $vers = $extra = null;
        $mimeTypes = $dirCount = [];

        if ($raf->Read($buff, 8) != 8) return 0;
        if (!preg_match('{^(\.RMF|\.ra\xfd|pnm://|rtsp://|http://)}', $buff, $m)) return 0;

        $fast3 = ($et->OPTIONS['FastScan'] ?? null) && $et->OPTIONS['FastScan'] == 3;
        $type = $tagTablePtr = null;
        if ($m[1] === '.RMF') {
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Real::Media');
            $type = 'RM';
        } elseif ($m[1] === ".ra\xfd") {
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Real::Audio');
            $type = 'RA';
        } else {
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Real::Metafile');
            $ext = $et->FILE_EXT;
            $type = ($ext and $ext === 'RPM') ? 'RPM' : 'RAM';
            // (local $/)
            $oldRS = Perl::$RS;
            Perl::$RS = self::GetInputRecordSeparator($raf) ?: "\n";
            try {
                $raf->Seek(0, 0);
                while ($raf->ReadLine($buff)) {
                    if (strlen($buff) > 256) break;
                    if (!$buff) continue;
                    Perl::chomp($buff);
                    if ($type) {
                        // must be a Real file type if protocol is http
                        if (preg_match('/^http/', $buff) and !preg_match('/\.(ra|rm|rv|rmvb|smil)$/i', $buff)) return 0;
                        $et->SetFileType($type);
                        if ($fast3) return 1;
                        $type = null;
                    }
                    // save URL or Text from RAM file
                    $tag = preg_match('{^[a-z]{3,4}://}', $buff) ? 'url' : 'txt';
                    $et->HandleTag($tagTablePtr, $tag, $buff);
                }
            } finally {
                Perl::$RS = $oldRS;
            }
            return 1;
        }

        $et->SetFileType($type);
        if ($fast3) return 1;
        ExifTool::SetByteOrder('MM');
        $verbose = $et->Options('Verbose');
//
// Process RealAudio file
//
        if ($type === 'RA') {
            [$vers, $extra] = Perl::unpack('x4nn', $buff);
            $tag = ".ra$vers";
            $fpos = $raf->Tell();
            if (!$raf->Read($buff, 512)) {
                $et->Warn('Error reading audio header');
                return 1;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($verbose > 2) {
                $et->VerboseInfo($tag, $tagInfo, DataPt: $buff, DataPos: $fpos);
            }
            if ($tagInfo) {
                $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt'   => $buff,
                    'DataPos'  => $fpos,
                    'DirLen'   => strlen($buff),
                    'DirStart' => 0,
                ];
                $et->ProcessDirectory($subdirInfo, $subTablePtr);
            } else {
                $et->Warn('Unsupported RealAudio version');
            }
            return 1;
        }
//
// Process RealMedia file
//
        // skip the rest of the RM header
        $size = Perl::unpack('x4N', $buff)[0] ?? null;
        if (!$raf->Seek($size - 8, 1)) {
            $et->Warn('Error seeking in file');
            return 0;
        }

        // Process RealMedia chunks
        for (;;) {
            if ($raf->Read($buff, 10) != 10) break;
            [$tag, $size, $vers] = Perl::unpack('a4Nn', $buff);
            if ($tag === "\0\0\0\0") break;
            if ($verbose) {
                $et->VPrint(0, "$tag chunk ($size bytes):\n");
            } else {
                if ($tag === 'DATA') break; // stop normal parsing at DATA tag
            }
            if ($size & 0x80000000 or $size < 10) {
                $et->Warn('Bad chunk header');
                break;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo and $tagInfo->SubDirectory) {
                $fpos = $raf->Tell();
                if ($raf->Read($buff, $size - 10) != $size - 10) {
                    $et->Warn("Error reading $tag chunk");
                    break;
                }
                if ($verbose > 2) {
                    $et->VerboseInfo($tag, $tagInfo, DataPt: $buff, DataPos: $fpos);
                }
                $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt'   => $buff,
                    'DataPos'  => $fpos,
                    'DirLen'   => strlen($buff),
                    'DirStart' => 0,
                ];
                if (!empty($dirCount[$tag])) {
                    $et->SET_GROUP1 = '+' . ++$dirCount[$tag];
                } else {
                    $dirCount[$tag] = 1;
                }
                $et->ProcessDirectory($subdirInfo, $subTablePtr);
                $et->SET_GROUP1 = null;
                // keep track of stream MIME types
                $mime = $et->RealStreamMime;
                if ($mime) {
                    unset($et->RealStreamMime);
                    $mime = preg_replace('/\0.*/s', '', Perl::str($mime), 1);
                    if (!preg_match('/^logical-/', $mime)) $mimeTypes[] = $mime;
                }
            } else {
                if (!$raf->Seek($size - 10, 1)) {
                    $et->Warn('Error seeking in file');
                    break;
                }
            }
        }
        // override MIMEType with stream MIME type if we only have one stream
        if (count($mimeTypes) == 1 and strlen($mimeTypes[0])) {
            $et->VALUE['MIMEType'] = $mimeTypes[0];
            $et->VPrint(0, "  MIMEType = $mimeTypes[0]\n");
        }
//
// Process footer containing Real metadata and ID3 information
//
        if ($raf->Seek(-140, 2) and $raf->Read($buff, 12) == 12 and preg_match('/^RMJE/', $buff)) {
            $metaSize = Perl::unpack('x8N', $buff)[0] ?? null;
            if ($raf->Seek(-$metaSize - 12, 1) and
                $raf->Read($buff, $metaSize) == $metaSize and
                preg_match('/^RJMD/', $buff))
            {
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt' => $buff,
                    'DataPos' => $raf->Tell() - $metaSize,
                    'DirStart' => 8,
                    'DirLen' => strlen($buff) - 8,
                ];
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Real::Metadata');
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            } else {
                $et->Warn('Bad metadata footer');
            }
            if ($raf->Seek(-128, 2) and $raf->Read($buff, 128) == 128 and preg_match('/^TAG/', $buff)) {
                $et->VPrint(0, "ID3v1:\n");
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt' => $buff,
                    'DirStart' => 0,
                    'DirLen' => strlen($buff),
                ];
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ID3::v1');
                $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            }
        }
        return 1;
    }

    /**
     * Image::ExifTool::PostScript::GetInputRecordSeparator: get input record separator
     * Inputs: 0) RAF reference
     * Returns: separator string or undef if not found
     * (ponytail: PostScript.pm isn't ported, local copy; call PostScript's once it is)
     */
    private static function GetInputRecordSeparator(RandomAccess $raf)
    {
        $pos = $raf->Tell(); // save current position
        $data = $sep = null;
        if (!$raf->Read($data, 256)) return null;
        [$a, $d] = [999, 999];
        if (($p = strpos($data, "\x0a")) !== false) $a = $p + 1;
        if (($p = strpos($data, "\x0d")) !== false) $d = $p + 1;
        $diff = $a - $d;
        if ($diff == 1) {
            $sep = "\x0d\x0a";
        } elseif ($diff == -1) {
            $sep = "\x0a\x0d";
        } elseif ($diff > 0) {
            $sep = "\x0d";
        } elseif ($diff < 0) {
            $sep = "\x0a";
        } // else error
        $raf->Seek($pos, 0);    // restore original position
        return $sep;
    }
}
