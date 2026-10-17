<?php

//------------------------------------------------------------------------------
// File:         ZIP.pm
//
// Description:  Read ZIP archive meta information
//
// Revisions:    10/28/2007 - P. Harvey Created
//
// References:   1) http://www.pkware.com/documents/casestudies/APPNOTE.TXT
//               2) http://www.cpanforum.com/threads/9046
//               3) http://www.gzip.org/zlib/rfc-gzip.html
//               4) http://DataCompression.info/ArchiveFormats/RAR202.txt
//               5) https://jira.atlassian.com/browse/CONF-21706
//               6) http://wwwimages.adobe.com/www.adobe.com/content/dam/Adobe/en/devnet/indesign/cs55-docs/IDML/idml-specification.pdf
//               7) https://www.rarlab.com/technote.htm
//
// PHP port of Image::ExifTool::ZIP 1.32. Tag tables are generated into tables/ZIP.php.
// Archive::Zip is replaced by the ArchiveZip class at the end of this file (the subset of
// Archive::Zip 1.68 that ExifTool uses).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\ZIP as Vars;

final class ZIP
{
    public static $VERSION = '1.32';
    public static $warnString;

    public static function WarnProc($w)
    {
        self::$warnString = $w;
    }

    //------------------------------------------------------------------------------
    // Read unsigned LEB (Little Endian Base) from file
    // Inputs: 0) RAF ref
    // Returns: integer value
    public static function ReadULEB(RandomAccess $raf)
    {
        $buff = null;
        $rtnVal = 0;
        for ($i = 0; ; ++$i) {
            if (!$raf->Read($buff, 1)) break;
            $num = ord($buff);
            $rtnVal += ($num & 0x7f) << ($i * 7);
            if (!($num & 0x80)) break;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Extract information from a RAR file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid RAR file
    public static function ProcessRAR(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        $docNum = 0;

        if (!($raf->Read($buff, 7) and preg_match('/Rar!\x1a\x07[\x00\x01]/', $buff))) return 0;

        if ($buff === "Rar!\x1a\x07\0") { // RARv4 (ref 4)

            $et->SetFileType();
            ExifTool::SetByteOrder('II');
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::RAR5');
            $et->HandleTag($tagTablePtr, 'FileVersion', 'RAR v4');
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::RAR');

            for (;;) {
                // read block header
                if ($raf->Read($buff, 7) != 7) break;
                [$type, $flags, $size] = Perl::unpack('xxCvv', $buff);
                $size -= 7;
                if ($flags & 0x8000) {
                    if ($raf->Read($buff, 4) != 4) break;
                    $size += Perl::unpack('V', $buff)[0] - 4;
                }
                if ($size < 0) break;
                if (!$size) continue;   // ignore blocks with no data
                // don't try to read very large blocks unless LargeFileSupport is enabled
                if ($size >= 0x80000000) {
                    if (!$et->Options('LargeFileSupport')) {
                        $et->Warn('Large block encountered. Aborting.');
                        break;
                    } elseif (Perl::str($et->Options('LargeFileSupport')) === '2') {
                        $et->Warn('Processing large block (LargeFileSupport is 2)');
                    }
                }
                // process the block
                if ($type == 0x74) { // file block
                    // read maximum 4 KB from a file block
                    $n = $size > 4096 ? 4096 : $size;
                    if ($raf->Read($buff, $n) != $n) break;
                    // add compressed size to start of data so we can extract it with the other tags
                    $buff = Perl::pack('V', $size) . $buff;
                    $et->DOC_NUM = ++$docNum;
                    $subdir = ['DataPt' => $buff];
                    $et->ProcessDirectory($subdir, $tagTablePtr);
                    $size -= $n;
                } elseif ($type == 0x75 and $size > 6) { // comment block
                    if ($raf->Read($buff, $size) != $size) break;
                    // save comment, only if "Stored" (this is untested)
                    if (ExifTool::Get8u($buff, 3) == 0x30) {
                        $et->FoundTag('Comment', substr($buff, 6));
                    }
                    continue;
                }
                // seek to the start of the next block
                if ($size) {
                    if (!$raf->Seek($size, 1)) break;
                }
            }

        } else { // RARv5 (ref 7, github#203)

            if (!($raf->Read($buff, 1) and $buff === "\0")) return 0;
            $et->SetFileType();
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::RAR5');
            $et->HandleTag($tagTablePtr, 'FileVersion', 'RAR v5');
            $et->INDENT .= '| ';

            // loop through header blocks
            for (;;) {
                $raf->Seek(4, 1);                   // skip header CRC
                $headSize = self::ReadULEB($raf);
                if ($headSize == 0) break;
                // read the header and create new RAF object for reading it
                $header = null;
                if ($raf->Read($header, $headSize) != $headSize) break;
                $rafHdr = new RandomAccess($header);
                $headType = self::ReadULEB($rafHdr);    // get header type

                if ($headType == 4) {  // encryption block
                    $et->Warn('File is encrypted.', 0);
                    break;
                }
                // skip over all headers except file or service header
                if (!($headType == 2 or $headType == 3)) continue;
                if ($headType == 2) $et->VerboseDir('RAR5 file', null, $headSize);

                $headFlag = self::ReadULEB($rafHdr);
                self::ReadULEB($rafHdr);                // skip extraSize
                if ($headFlag & 0x0002) {
                    $dataSize = self::ReadULEB($rafHdr);    // compressed data size
                    if ($headType == 2) {
                        $et->HandleTag($tagTablePtr, 'CompressedSize', $dataSize);
                    } else {
                        $raf->Seek($dataSize, 1);   // skip service data section
                        continue;
                    }
                } else {
                    if ($headType == 3) continue;   // all done with service header
                    $dataSize = 0;
                }
                $fileFlag = self::ReadULEB($rafHdr);
                $uncompressedSize = self::ReadULEB($rafHdr);
                if (!($fileFlag & 0x0008)) $et->HandleTag($tagTablePtr, 'UncompressedSize', $uncompressedSize);
                self::ReadULEB($rafHdr);                // skip file attributes
                if ($fileFlag & 0x0002) {
                    if ($rafHdr->Read($buff, 4) != 4) break;
                    // (untested)
                    $et->HandleTag($tagTablePtr, 'ModifyDate', Perl::unpack('V', $buff)[0]);
                }
                if ($fileFlag & 0x0004) $rafHdr->Seek(4, 1);    // skip CRC if present

                self::ReadULEB($rafHdr);                // skip compressionInfo

                // get operating system
                $os = self::ReadULEB($rafHdr);
                $et->HandleTag($tagTablePtr, 'OperatingSystem', $os);

                // get filename
                if ($rafHdr->Read($buff, 1) != 1) break;
                $nameLen = ord($buff);
                if ($rafHdr->Read($buff, $nameLen) != $nameLen) break;
                $buff = preg_replace('/\x00+$/', '', $buff, 1);    // remove trailing nulls (if any)
                $et->HandleTag($tagTablePtr, 'ArchivedFileName', $buff);

                $et->DOC_NUM = ++$docNum;

                $raf->Seek($dataSize, 1);           // skip data section
            }
            $et->INDENT = substr($et->INDENT, 0, -2);
        }

        $et->DOC_NUM = 0;
        if ($docNum > 1 and !$et->Options('Duplicates')) {
            $et->Warn("Use the Duplicates option to extract tags for all $docNum files", 1);
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from a GNU ZIP file (ref 3)
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid GZIP file
    public static function ProcessGZIP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if (!($raf->Read($buff, 10) and preg_match('/^\x1f\x8b\x08/', $buff))) return 0;

        $et->SetFileType();
        ExifTool::SetByteOrder('II');

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::GZIP');
        $et->HandleTag($tagTablePtr, 2, ExifTool::Get8u($buff, 2));
        $et->HandleTag($tagTablePtr, 3, $flags = ExifTool::Get8u($buff, 3));
        $et->HandleTag($tagTablePtr, 4, ExifTool::Get32u($buff, 4));
        $et->HandleTag($tagTablePtr, 8, ExifTool::Get8u($buff, 8));
        $et->HandleTag($tagTablePtr, 9, ExifTool::Get8u($buff, 9));

        // extract file name and comment if they exist
        if ($flags & 0x18) {
            if ($flags & 0x04) {
                // skip extra field
                if ($raf->Read($buff, 2) != 2) return 1;
                $len = ExifTool::Get16u($buff, 0);
                if ($raf->Read($buff, $len) != $len) return 1;
            }
            if (!$raf->Read($buff, 4096)) return 1;
            $pos = 0;
            $gpos = 0;  // (PHP: pos($buff) of the m//g search)
            // loop for ArchivedFileName (10) and Comment (11) tags
            foreach ([10, 11] as $tagID) {
                $mask = $tagID == 10 ? 0x08 : 0x10;
                if (!($flags & $mask)) continue;
                $p = strpos($buff, "\0", $gpos);
                if ($p !== false) {
                    $gpos = $p + 1;
                    $end = $p;
                } else {
                    $gpos = 0;
                    $end = strlen($buff);
                }
                // (the doc specifies the string should be ISO 8859-1,
                // but in OS X it seems to be UTF-8, so don't translate
                // it because I could just as easily screw it up)
                $str = substr($buff, $pos, $end - $pos);
                $et->HandleTag($tagTablePtr, $tagID, $str);
                if ($end >= strlen($buff)) break;
                $pos = $end + 1;
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Call HandleTags for attributes of an Archive::Zip member
    // Inputs: 0) ExifTool object ref, 1) member ref, 2) optional tag table ref
    public static function HandleMember(ExifTool $et, ArchiveZipMember $member, ?TagTable $tagTablePtr = null)
    {
        $tagTablePtr or $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::Main');
        $et->HandleTag($tagTablePtr, 2, $member->versionNeededToExtract());
        $et->HandleTag($tagTablePtr, 3, $member->bitFlag());
        $et->HandleTag($tagTablePtr, 4, $member->compressionMethod());
        $et->HandleTag($tagTablePtr, 5, $member->lastModFileDateTime());
        $et->HandleTag($tagTablePtr, 7, $member->crc32());
        $et->HandleTag($tagTablePtr, 9, $member->compressedSize());
        $et->HandleTag($tagTablePtr, 11, $member->uncompressedSize());
        $et->HandleTag($tagTablePtr, 15, $member->fileName());
        $com = $member->fileComment();
        if ($com !== null and strlen($com)) $et->HandleTag($tagTablePtr, '_com', $com);
    }

    //------------------------------------------------------------------------------
    // Extract file from ZIP archive
    // Inputs: 0) ExifTool ref, 1) Zip object ref, 2) file name
    // Returns: zip member or undef it it didn't exist
    public static function ExtractFile(ExifTool $et, ArchiveZip $zip, $file)
    {
        $result = $zip->memberNamed($file);
        $et->VPrint(1, "  (Extracting '{$file}' from zip archive)\n");
        return $result;
    }

    //------------------------------------------------------------------------------
    // Extract information from a ZIP file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid ZIP file
    public static function ProcessZIP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $zip = null;

        if (!($raf->Read($buff, 30) == 30 and str_starts_with($buff, "PK\x03\x04"))) return 0;

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::Main');
        $docNum = 0;

        // use Archive::Zip if available
        for (;;) {
            // (PHP: ArchiveZip below needs zlib to inflate the members)
            if (!function_exists('gzinflate')) {
                if ($et->FILE_EXT and $et->FILE_EXT !== 'ZIP') {
                    $et->Warn('Install Archive::Zip to decode compressed ZIP information');
                }
                break;
            }
            // (PHP: ArchiveZip reads through the RAF, so it works the same for files,
            //  pipes and in-memory data. The second read attempt of Perl for an IO error
            //  works around a Perl 5.6.2 bug, it isn't needed here)
            $et->VPrint(1, "  --- using Archive::Zip ---\n");
            $zip = new ArchiveZip();
            $status = $zip->readFromFileHandle($raf);
            if ($status) {
                $zip = null;
                $err = [1 => 'Stream end error', 3 => 'Format error', 4 => 'IO error'];
                $err = ($err[$status] ?? null) ?: "Error $status";
                $et->Warn("$err reading ZIP file");
                break;
            }
            // extract zip file comment
            $comment = $zip->zipfileComment();
            if ($comment !== null and strlen($comment)) $et->FoundTag('Comment', $comment);

            $dirInfo['ZIP'] = $zip;

            // check for an Office Open file (DOCX, etc)
            // --> read '[Content_Types].xml' to determine the file type
            $mime = null;
            $members = [];
            $cType = self::ExtractFile($et, $zip, '[Content_Types].xml');
            if ($cType) {
                [$buff, $status] = $zip->contents($cType);
                $b = Perl::str($buff);
                if (!$status and (
                    // first look for the main document with the expected name
                    preg_match('{\sPartName\s*=\s*[\'"](?:/ppt/presentation.xml|/word/document.xml|/xl/workbook.xml)[\'"][^>]*\sContentType\s*=\s*([\'"])([^"\']+)\.main(\+xml)?\1}', $b, $m) or
                    // then look for the main part
                    preg_match('/<Override[^>]*\sPartName[^<]+\sContentType\s*=\s*([\'"])([^"\']+)\.main(\+xml)?\1/', $b, $m) or
                    // and if all else fails, use the default main
                    preg_match('/ContentType\s*=\s*([\'"])([^"\']+)\.main(\+xml)?\1/', $b, $m)))
                {
                    $mime = $m[2];
                }
            }
            // check for docProps if we couldn't find a MIME type
            $mime or $members = $zip->membersMatching('^docProps/.*\.(xml|XML)$');
            if ($mime or $members) {
                $dirInfo['MIME'] = $mime;
                OOXML::ProcessDOCX($et, $dirInfo);
                unset($dirInfo['MIME']);
                break;
            }

            // check for an EIP file
            $members = $zip->membersMatching('^CaptureOne/.*\.(cos|COS)$');
            if ($members) {
                // (the CaptureOne module may not be ported yet)
                if (ExifTool::RequireModule('Image::ExifTool::CaptureOne') and method_exists('Image\ExifTool\CaptureOne', 'ProcessEIP')) {
                    CaptureOne::ProcessEIP($et, $dirInfo);
                } else {
                    $et->Warn('Not ported: Image::ExifTool::CaptureOne::ProcessEIP');
                }
                break;
            }

            // check for an iWork file
            $members = $zip->membersMatching('(?i)^(index\.(xml|apxl)|QuickLook/Thumbnail\.jpg|[^/]+\.(pages|numbers|key)/Index.(zip|xml|apxl))$');
            if ($members) {
                iWork::Process_iWork($et, $dirInfo);
                break;
            }

            // check for an Open Document, IDML or EPUB file
            $mType = self::ExtractFile($et, $zip, 'mimetype');
            if ($mType) {
                [$mime, $status] = $zip->contents($mType);
                if (!$status and preg_match('/([\x21-\xfe]+)/s', Perl::str($mime), $m)) {
                    // clean up MIME type just in case (note that MIME is case insensitive)
                    $mime = strtolower($m[1]);
                    $et->SetFileType((Vars::$openDocType[$mime] ?? null) ?: 'ZIP', $mime);
                    if (empty(Vars::$openDocType[$mime])) $et->Warn("Unrecognized MIMEType $mime");
                    // extract Open Document metadata from "meta.xml"
                    $meta = self::ExtractFile($et, $zip, 'meta.xml');
                    // IDML files have metadata in a different place (ref 6)
                    $meta or $meta = self::ExtractFile($et, $zip, 'META-INF/metadata.xml');
                    if ($meta) {
                        [$buff, $status] = $zip->contents($meta);
                        if (!$status) {
                            $buff = Perl::str($buff);
                            $subdir = [
                                'DirName' => 'XML',
                                'DataPt'  => $buff,
                                'DirLen'  => strlen($buff),
                                'DataLen' => strlen($buff),
                            ];
                            // (avoid structure warnings when copying from XML)
                            $oldWarn = $et->NO_STRUCT_WARN;
                            $et->NO_STRUCT_WARN = 1;
                            $et->ProcessDirectory($subdir, ExifTool::GetTagTable('Image::ExifTool::XMP::Main'));
                            $et->NO_STRUCT_WARN = $oldWarn;
                        }
                    }
                    // process rootfile of EPUB container if applicable
                    for (;;) {
                        if ($meta and $mime !== 'application/epub+zip') break;
                        $container = self::ExtractFile($et, $zip, 'META-INF/container.xml');
                        [$buff, $status] = $zip->contents($container);
                        if ($status) break;
                        if (!preg_match('/<rootfile\s+[^>]*?\bfull-path=([\'"])(.*?)\1/s', Perl::str($buff), $m)) break;
                        // load the rootfile data (OPF extension; contains XML metadata)
                        $meta2 = $zip->memberNamed($m[2]);
                        if (!$meta2) break;
                        $meta = $meta2;
                        [$buff, $status] = $zip->contents($meta);
                        if ($status) break;
                        $buff = Perl::str($buff);
                        // use opf:event to generate more meaningful tag names for dc:date
                        while (preg_match('{<dc:date opf:event="(\w+)">([^<]+)</dc:date>}s', $buff, $m, PREG_OFFSET_CAPTURE)) {
                            $buff = substr_replace($buff, "<dc:{$m[1][0]}Date>{$m[2][0]}</dc:{$m[1][0]}Date>", $m[0][1], strlen($m[0][0]));
                            $dcTable = ExifTool::GetTagTable('Image::ExifTool::XMP::dc');
                            $tag = "{$m[1][0]}Date";
                            if (!$dcTable[$tag]) {
                                ExifTool::AddTagToTable($dcTable, $tag, [
                                    'Name' => ucfirst($tag),
                                    'Groups' => [2 => 'Time'],
                                    'List' => 'Seq',
                                    ...self::DateTimeInfo(),
                                ]);
                            }
                        }
                        $subdir = [
                            'DataPt' => $buff,
                            'DirLen' => strlen($buff),
                            'DataLen' => strlen($buff),
                            'IgnoreProp' => ['package' => 1, 'metadata' => 1],
                        ];
                        // (avoid structure warnings when copying from XML)
                        $oldWarn = $et->NO_STRUCT_WARN;
                        $et->NO_STRUCT_WARN = 1;
                        $et->ProcessDirectory($subdir, ExifTool::GetTagTable('Image::ExifTool::XMP::XML'));
                        $et->NO_STRUCT_WARN = $oldWarn;
                        break;
                    }
                    if (!empty(Vars::$openDocType[$mime]) or $meta) {
                        // extract preview image(s) from "Thumbnails" directory if they exist
                        $tag = ['jpg' => 'PreviewImage', 'png' => 'PreviewPNG'];
                        foreach (['jpg', 'png'] as $type) {
                            $thumb = self::ExtractFile($et, $zip, "Thumbnails/thumbnail.$type");
                            if (!$thumb) continue;
                            [$buff, $status] = $zip->contents($thumb);
                            if (!$status) $et->FoundTag($tag[$type], $buff);
                        }
                        break;  // all done since we recognized the MIME type or found metadata
                    }
                    // continue on to list ZIP contents...
                }
            }

            // otherwise just extract general ZIP information
            $et->SetFileType();
            $members = $zip->members();
            $iWorkType = null;
            // special files to extract
            $extract = [
                'meta.json' => 1,
                'previews/preview.png' => 'PreviewPNG',
                'preview.jpg' => 'PreviewImage', // (iWork 2013 files)
                'preview-web.jpg' => 'OtherImage', // (iWork 2013 files)
                'preview-micro.jpg' => 'ThumbnailImage', // (iWork 2013 files)
                'QuickLook/Thumbnail.jpg' => 'ThumbnailImage', // (iWork 2009 files)
                'QuickLook/Preview.pdf' => 'PreviewPDF', // (iWork 2009 files)
            ];
            foreach ($members as $member) {
                $et->DOC_NUM = ++$docNum;
                self::HandleMember($et, $member, $tagTablePtr);
                $file = $member->fileName();
                // extract things from Sketch files
                if (!empty($extract[$file])) {
                    [$buff, $status] = $zip->contents($member);
                    if ($status) {
                        $et->Warn("Error extracting $file");
                        continue;
                    }
                    if ($file === 'meta.json') {
                        $et->ExtractInfo(new ScalarRef($buff), ['ReEntry' => 1]);
                        $app = $et->VALUE['App'] ?? null;
                        if ($app and is_scalar($app) and preg_match('/sketch/i', (string) $app)) {
                            $et->OverrideFileType('SKETCH');
                        }
                    } else {
                        $et->FoundTag($extract[$file], $buff);
                    }
                } elseif ($file === 'Index/Document.iwa' and !$iWorkType) {
                    $type = Vars::$iWorkType[$et->FILE_EXT ?: ''] ?? null;
                    $iWorkType = $type ?: 'PAGES';
                } elseif (!empty(Vars::$iWorkFile[$file])) {
                    $iWorkType = Vars::$iWorkFile[$file];
                }
            }
            if ($iWorkType) $et->OverrideFileType($iWorkType);
            break;
        }
        // all done if we processed this using Archive::Zip
        if ($zip) {
            unset($dirInfo['ZIP']);
            $et->DOC_NUM = null;
            if ($docNum > 1 and !$et->Options('Duplicates')) {
                $et->Warn("Use the Duplicates option to extract tags for all $docNum files", 1);
            }
            return 1;
        }
        //
        // process the ZIP file by hand (funny, but this seems easier than using Archive::Zip)
        //
        $et->VPrint(1, "  -- processing as binary data --\n");
        $raf->Seek(30, 0);
        $et->SetFileType();
        ExifTool::SetByteOrder('II');

        //  A.  Local file header:
        //  local file header signature     0) 4 bytes  (0x04034b50)
        //  version needed to extract       4) 2 bytes
        //  general purpose bit flag        6) 2 bytes
        //  compression method              8) 2 bytes
        //  last mod file time             10) 2 bytes
        //  last mod file date             12) 2 bytes
        //  crc-32                         14) 4 bytes
        //  compressed size                18) 4 bytes
        //  uncompressed size              22) 4 bytes
        //  file name length               26) 2 bytes
        //  extra field length             28) 2 bytes
        for (;;) {
            $len = ExifTool::Get16u($buff, 26) + ExifTool::Get16u($buff, 28);
            if ($raf->Read($buf2, $len) != $len) break;

            $et->DOC_NUM = ++$docNum;
            $buff .= $buf2;
            $subdir = [
                'DataPt' => $buff,
                'DataPos' => $raf->Tell() - 30 - $len,
                'DataLen' => 30 + $len,
                'DirStart' => 0,
                'DirLen' => 30 + $len,
                'MixedTags' => 1, // (to ignore FileComment tag)
            ];
            $et->ProcessDirectory($subdir, $tagTablePtr);
            $flags = ExifTool::Get16u($buff, 6);
            if ($flags & 0x08) {
                // we don't yet support skipping stream mode data
                // (when this happens, the CRC, compressed size and uncompressed
                //  sizes are set to 0 in the header.  Instead, they are stored
                //  after the compressed data with an optional header of 0x08074b50)
                $et->Warn('Stream mode data encountered, file list may be incomplete');
                break;
            }
            $len = ExifTool::Get32u($buff, 18);     // file data length
            if (!$raf->Seek($len, 1)) break;        // skip file data
            if (!($raf->Read($buff, 30) == 30 and str_starts_with($buff, "PK\x03\x04"))) break;
        }
        $et->DOC_NUM = null;
        if ($docNum > 1 and !$et->Options('Duplicates')) {
            $et->Warn("Use the Duplicates option to extract tags for all $docNum files", 1);
        }
        return 1;
    }

    /** (PHP: %Image::ExifTool::XMP::dateTimeInfo with its PrintConv string as a closure) */
    private static function DateTimeInfo(): array
    {
        $info = \Image\ExifTool\Vars\XMP::$dateTimeInfo;
        $info['PrintConv'] = static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
            return $self->ConvertDateTime($val);
        };
        return $info;
    }
}

/**
 * (PHP: replaces Archive::Zip 1.68 for reading: the central directory (zip64 included) is read
 *  through the RandomAccess object, members are inflated with zlib.  Status values are the
 *  Archive::Zip constants: AZ_OK 0, AZ_STREAM_END 1, AZ_ERROR 2, AZ_FORMAT_ERROR 3, AZ_IO_ERROR 4)
 */
final class ArchiveZip
{
    /** @var ArchiveZipMember[] */
    private array $members = [];
    private ?string $comment = null;
    private ?RandomAccess $raf = null;
    private $eocdOffset = 0;

    /** Archive::Zip::Archive::readFromFileHandle; returns a status */
    public function readFromFileHandle(RandomAccess $raf)
    {
        $this->raf = $raf;
        $data = null;
        // _findEndOfCentralDirectory: search the last 32 kB (ChunkSize) for the EOCD signature
        if (!$raf->Seek(0, 2)) return 4;
        $fileLength = $raf->Tell();
        if ($fileLength < 22) return 3;     // file is too short
        $n = min($fileLength, 32768);
        if (!$raf->Seek(-$n, 2) or $raf->Read($data, $n) != $n) return 4;
        $pos = strrpos($data, "PK\x05\x06");
        if ($pos === false) return 3;       // can't find EOCD signature
        $eocdPosition = $fileLength - $n + $pos;

        // _readEndOfCentralDirectory
        $zip64 = false;
        $zip64EOCDPosition = null;
        $hdr = $rec = null;
        if ($eocdPosition >= 20 and $raf->Seek($eocdPosition - 20, 0) and $raf->Read($hdr, 20) == 20 and
            str_starts_with($hdr, "PK\x06\x07"))
        {
            $zip64EOCDPosition = Perl::unpack('Q<', substr($hdr, 8, 8))[0];
            $locatorPosition = $eocdPosition - 20;
            if ($zip64EOCDPosition <= $locatorPosition - 56 and $raf->Seek($zip64EOCDPosition, 0) and
                $raf->Read($rec, 56) == 56 and str_starts_with($rec, "PK\x06\x06") and
                $zip64EOCDPosition + 12 + Perl::unpack('Q<', substr($rec, 4, 8))[0] == $locatorPosition)
            {
                $zip64 = true;
                [, $cdSize, $cdOffset] = Perl::unpack('a32 Q< Q<', substr($rec, 8));
            }
        }
        if (!$raf->Seek($eocdPosition + 4, 0) or $raf->Read($hdr, 18) != 18) return 4;
        [$diskNumber, $diskStart, $numOnDisk, $num, $size, $offset, $commentLength] = Perl::unpack('v v v v V V v', $hdr);
        if (!$zip64) {
            if ($diskNumber == 0xffff or $diskStart == 0xffff or $numOnDisk == 0xffff or $num == 0xffff or
                $size == 0xffffffff or $offset == 0xffffffff)
            {
                return 3;   // unexpected zip64 marker values in EOCD
            }
            [$cdSize, $cdOffset] = [$size, $offset];
        }
        if ($commentLength) {
            if ($raf->Read($data, $commentLength) != $commentLength) return 4;
            $this->comment = $data;
        }
        if ($zip64) $eocdPosition = $zip64EOCDPosition;

        // read the central directory
        if (!$raf->Seek($eocdPosition - $cdSize, 0)) return 4;
        // (garbage at the beginning of the archive, eg. self-extracting archives)
        $this->eocdOffset = $eocdPosition - $cdSize - $cdOffset;
        for (;;) {
            if ($raf->Read($data, 4) != 4) return 4;
            $signature = Perl::unpack('V', $data)[0];
            if ($signature == ($zip64 ? 0x06064b50 : 0x06054b50)) break;
            if (!in_array($signature, [0x02014b50, 0x04034b50, 0x06054b50, 0x08074b50, 0x06064b50, 0x07064b50])) return 3;
            if ($raf->Read($data, 42) != 42) return 4;
            $member = new ArchiveZipMember();
            [, , $member->versionNeededToExtract, $member->bitFlag, $member->compressionMethod,
             $member->lastModFileDateTime, $member->crc32, $member->compressedSize, $member->uncompressedSize,
             $fileNameLength, $extraFieldLength, $fileCommentLength, $member->diskNumberStart, ,
             , $member->localHeaderRelativeOffset] = Perl::unpack('C C v v v V V V V v v v v v V V', $data);
            if ($fileNameLength) {
                $raf->Read($data, $fileNameLength);
                $member->fileName = $data;
            }
            if ($extraFieldLength) {
                if ($raf->Read($data, $extraFieldLength) != $extraFieldLength) return 4;
                if ($zip64) $member->ExtractZip64ExtraField($data);
            }
            if ($fileCommentLength) {
                if ($raf->Read($data, $fileCommentLength) != $fileCommentLength) return 4;
                $member->fileComment = $data;
            }
            // NK 10/21/04: added to avoid problems with manipulated headers
            if ($member->uncompressedSize != $member->compressedSize and $member->compressionMethod == 0) {
                $member->uncompressedSize = $member->compressedSize;
            }
            // (Archive::Zip decodes UTF-8 names into Perl character strings, which ExifTool
            //  handles as Latin-1 bytes when no character is above 0xff)
            if ($member->bitFlag & 0x800 and preg_match('/[\x80-\xff]/', $member->fileName) and
                preg_match('/^[\x00-\x{ff}]*$/u', $member->fileName))
            {
                $member->fileName = mb_convert_encoding($member->fileName, 'ISO-8859-1', 'UTF-8');
            }
            $this->members[] = $member;
        }
        return 0;
    }

    /** @return ArchiveZipMember[] */
    public function members(): array
    {
        return $this->members;
    }

    public function memberNamed($fileName): ?ArchiveZipMember
    {
        foreach ($this->members as $member) {
            if ($member->fileName === Perl::str($fileName)) return $member;
        }
        return null;
    }

    /** Perl regex (no delimiters) matched against the member names */
    public function membersMatching($pattern): array
    {
        return array_values(array_filter($this->members, static fn ($m) => preg_match("\x01$pattern\x01", $m->fileName)));
    }

    public function zipfileComment()
    {
        return $this->comment;
    }

    /** Archive::Zip::Archive::contents in list context: [ $contents, $status ] */
    public function contents($member): array
    {
        if ($member === null) return [null, 2];     // no member name given
        if (!$member instanceof ArchiveZipMember) {
            $member = $this->memberNamed($member);
            if (!$member) return [null, 2];
        }
        // (directory member)
        if (str_ends_with($member->fileName, '/') and $member->uncompressedSize == 0) return [null, 0];
        $raf = $this->raf;
        $hdr = $data = null;
        // seek to the local file header (retrying with the EOCD offset if any)
        $where = $member->localHeaderRelativeOffset;
        foreach ([0, $this->eocdOffset] as $i => $off) {
            if ($i and !$off) return [null, 3];
            if (!$raf->Seek($where + $off, 0) or $raf->Read($hdr, 30) != 30) return [null, 4];
            if (str_starts_with($hdr, "PK\x03\x04")) break;
            if ($i) return [null, 3];
        }
        [$fileNameLength, $extraFieldLength] = Perl::unpack('v v', substr($hdr, 26));
        if (!$raf->Seek($fileNameLength + $extraFieldLength, 1)) return [null, 4];
        $size = $member->compressedSize;
        if ($size and $raf->Read($data, $size) != $size) return [null, 4];
        // (encrypted: without a password Archive::Zip decodes the data as an empty string)
        if ($member->bitFlag & 0x01) return $member->compressionMethod == 0 ? ['', 0] : [null, 2];
        if ($member->uncompressedSize == 0) return ['', 0];
        if ($member->compressionMethod == 0) return [$data, 0];
        if ($member->compressionMethod != 8) return [null, 2];  // unsupported compression combination
        $data = @gzinflate((string) $data);
        return $data === false ? [null, 3] : [$data, 0];
    }
}

/** (PHP: Archive::Zip::ZipFileMember accessors used by ExifTool) */
final class ArchiveZipMember
{
    public $versionNeededToExtract;
    public $bitFlag;
    public $compressionMethod;
    public $lastModFileDateTime;
    public $crc32;
    public $compressedSize;
    public $uncompressedSize;
    public $diskNumberStart;
    public $localHeaderRelativeOffset;
    public string $fileName = '';
    public string $fileComment = '';

    public function versionNeededToExtract() { return $this->versionNeededToExtract; }
    public function bitFlag() { return $this->bitFlag; }
    public function compressionMethod() { return $this->compressionMethod; }
    public function lastModFileDateTime() { return $this->lastModFileDateTime; }
    public function crc32() { return $this->crc32; }
    public function compressedSize() { return $this->compressedSize; }
    public function uncompressedSize() { return $this->uncompressedSize; }
    public function fileName() { return $this->fileName; }
    public function fileComment() { return $this->fileComment; }

    /** values of the zip64 extended information extra field (0x0001) for the 0xffffffff fields */
    public function ExtractZip64ExtraField(string $extra): void
    {
        for ($pos = 0; $pos + 4 <= strlen($extra); $pos += 4 + $len) {
            [$id, $len] = Perl::unpack('v v', substr($extra, $pos, 4));
            if ($id != 0x0001) continue;
            $p = $pos + 4;
            foreach (['uncompressedSize', 'compressedSize', 'localHeaderRelativeOffset'] as $field) {
                if ($this->$field != 0xffffffff or $p + 8 > $pos + 4 + $len) continue;
                $this->$field = Perl::unpack('Q<', substr($extra, $p, 8))[0];
                $p += 8;
            }
            if ($this->diskNumberStart == 0xffff and $p + 4 <= $pos + 4 + $len) {
                $this->diskNumberStart = Perl::unpack('V', substr($extra, $p, 4))[0];
            }
            return;
        }
    }
}
