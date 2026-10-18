<?php

//------------------------------------------------------------------------------
// File:         PostScript.pm
//
// Description:  Read PostScript meta information
//
// Revisions:    07/08/2005 - P. Harvey Created
//
// References:   1) http://partners.adobe.com/public/developer/en/ps/5002.EPSF_Spec.pdf
//               2) http://partners.adobe.com/public/developer/en/ps/5001.DSC_Spec.pdf
//               3) http://partners.adobe.com/public/developer/en/illustrator/sdk/AI7FileFormat.pdf
//
// PHP port of Image::ExifTool::PostScript 1.46 (the writer, WritePostScript.pl, is the trait
// PostScript\WritePostScript).
// Tag tables are generated into tables/PostScript.php.
// (Perl's input record separator $/ is Perl::$RS, used by RandomAccess::ReadLine)
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class PostScript
{
    use PostScript\WritePostScript;

    public static $VERSION = '1.46';

    //------------------------------------------------------------------------------
    // Get image width or height
    // Inputs: 0) value list ref (ImageData, BoundingBox), 1) true to get height
    public static function ImageSize($vals, $getHeight)
    {
        $w = $h = null;
        if (($vals[0] ?? null) and preg_match('/^(\d+) (\d+)/', Perl::str($vals[0]), $m)) {
            [$w, $h] = [$m[1], $m[2]];
        } elseif (($vals[1] ?? null) and preg_match('/^(\d+) (\d+) (\d+) (\d+)/', Perl::str($vals[1]), $m)) {
            [$w, $h] = [$m[3] - $m[1], $m[4] - $m[2]];
        }
        return $getHeight ? $h : $w;
    }

    //------------------------------------------------------------------------------
    // Set PostScript format error warning
    // Inputs: 0) ExifTool object reference, 1) error string
    // Returns: 1
    public static function PSErr(ExifTool $et, $str)
    {
        // set file type if not done already
        $ext = $et->FILE_EXT;
        $et->SetFileType(($ext and $ext === 'AI') ? 'AI' : 'PS');
        $et->Warn("PostScript format error ($str)");
        return 1;
    }

    //------------------------------------------------------------------------------
    // Return input record separator to use for the specified file
    // Inputs: 0) RAF reference
    // Returns: Input record separator or undef on error
    public static function GetInputRecordSeparator(RandomAccess $raf)
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

    //------------------------------------------------------------------------------
    // Split into lines ending in any CR, LF or CR+LF combination
    // (this is annoying, and could be avoided if EPS files didn't mix linefeeds!)
    // Inputs: 0) data pointer, 1) reference to lines array
    // Notes: Fills @$lines with lines from splitting $$dataPt
    public static function SplitLine($dataPt, array &$lines)
    {
        for (;;) {
            $endl = null;
            // find the position of the first LF (\x0a)
            if (($p = strpos($dataPt, "\x0a")) !== false) $endl = $p + 1;
            if (($p = strpos($dataPt, "\x0d")) !== false) { // find the first CR (\x0d)
                if ($endl !== null) {
                    // (remember, CR+LF is a DOS newline...)
                    if ($p + 1 < $endl - 1) $endl = $p + 1;
                } else {
                    $endl = $p + 1;
                }
            } elseif ($endl === null) {
                $lines[] = $dataPt;
                break;
            }
            if (strlen($dataPt) == $endl) {
                $lines[] = $dataPt;
                break;
            } else {
                // continue to split into separate lines
                $lines[] = substr($dataPt, 0, $endl);
                $dataPt = substr($dataPt, $endl);
            }
        }
    }

    //------------------------------------------------------------------------------
    // check to be sure we haven't read past end of PS data in DOS-style file
    // Inputs: 0) RAF ref (with PSEnd member), 1) data ref
    // - modifies data and sets RAF to EOF if end of PS is reached
    public static function CheckPSEnd(RandomAccess $raf, &$dataPt)
    {
        $pos = $raf->Tell();
        if ($pos >= $raf->PSEnd) {
            $raf->Seek(0, 2);   // seek to end of file so we can't read any more
            if ($pos > $raf->PSEnd) $dataPt = substr($dataPt, 0, strlen($dataPt) - $pos + $raf->PSEnd);
        }
    }

    //------------------------------------------------------------------------------
    // Read next line from EPS file
    // Inputs: 0) RAF ref (with PSEnd member if Postscript ends before end of file)
    //         1) array of lines from file
    // Returns: true on success
    public static function GetNextLine(RandomAccess $raf, array &$lines)
    {
        $data = $changedNL = null;
        $altnl = (Perl::$RS === "\x0d") ? "\x0a" : "\x0d";
        for (;;) {
            if (!$raf->ReadLine($data)) break;
            if ($raf->PSEnd) self::CheckPSEnd($raf, $data);
            // split line if it contains other newline sequences
            if (str_contains($data, $altnl)) {
                if (strlen($data) > 500000 and ExifTool::IsPC()) {
                    // patch for Windows memory problem
                    if (!$changedNL) {
                        $changedNL = Perl::$RS;
                        Perl::$RS = $altnl;
                        $altnl = $changedNL;
                        $raf->Seek(-strlen($data), 1);
                        continue;
                    }
                } else {
                    // split into separate lines if necessary
                    self::SplitLine($data, $lines);
                }
            } else {
                $lines[] = $data;
            }
            if ($changedNL) Perl::$RS = $changedNL;
            return 1;
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // (PHP) decode the escape sequences of a PostScript/PDF literal string: octal codes and
    // nrtbf characters, plus line continuations if $continuation is set
    // (the "while ($str =~ /\\(.)/sg) {...}" loop shared by DecodeComment, UnescapePostScript
    //  and PDF::ReadPDFValue)
    public static function UnescapeLoop($str, $continuation)
    {
        $pos = 0;
        while (preg_match('/\\\\(.)/s', $str, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $n = $m[0][1];
            $c = $m[1][0];
            if (preg_match('/[0-7]/', $c)) {
                // get up to 2 more octal digits
                if (preg_match('/\G([0-7]{1,2})/', $str, $m2, 0, $n + 2)) $c .= $m2[1];
                // convert octal escape code
                $r = chr(octdec($c) & 0xff);
            } elseif ($continuation and $c === "\x0d") {
                // the string is continued if the line ends with '\'
                // (also remove "\x0d\x0a")
                if (substr($str, $n + 2, 1) === "\x0a") $c .= "\x0a";
                $r = '';
            } elseif ($continuation and $c === "\x0a") {
                $r = '';
            } else {
                // convert escaped characters
                $r = strtr($c, 'nrtbf', "\n\r\t\x08\f");
            }
            $str = substr_replace($str, $r, $n, strlen($c) + 1);
            // continue search after this character
            $pos = $n + strlen($r);
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Decode comment from PostScript file
    // Inputs: 0) comment string, 1) RAF ref, 2) reference to lines array
    //         3) optional data reference for extra lines read from file
    // Returns: Decoded comment string (may be an array reference)
    // - handles multi-line comments and escape sequences
    public static function DecodeComment($val, RandomAccess $raf, array &$lines, &$dataPt = null)
    {
        $val = preg_replace('/\x0d*\x0a*$/', '', $val, 1);    // remove trailing CR, LF or CR/LF
        // check for continuation comments
        for (;;) {
            if (!$lines and !self::GetNextLine($raf, $lines)) break;
            if (!preg_match('/^%%\+/', $lines[0])) break;   // is the next line a continuation?
            if ($dataPt !== null) $dataPt .= $lines[0];     // add to data if necessary
            $lines[0] = preg_replace('/\x0d*\x0a*$/', '', $lines[0], 1); // remove trailing CR, LF or CR/LF
            $val .= substr(array_shift($lines), 3);         // add to value (without leading "%%+")
        }
        $vals = [];
        // handle bracketed string values
        if (preg_match('/^\((.*)\)$/', $val, $m)) { // remove brackets if necessary
            $val = $m[1];
            // split into an array of strings if necessary
            $nesting = 1;
            $vpos = 0;  // (Perl pos($val))
            while (preg_match('/(\(|\))/', $val, $m, PREG_OFFSET_CAPTURE, $vpos)) {
                $vpos = $m[0][1] + 1;
                $bra = $m[1][0];
                $pos = $vpos - 2;
                $backslashes = 0;
                while ($pos and substr($val, $pos, 1) === '\\') {
                    --$pos;
                    ++$backslashes;
                }
                if ($backslashes & 0x01) continue;  // escaped if odd number
                if ($bra === '(') {
                    ++$nesting;
                } else {
                    --$nesting;
                    if (!$nesting) {
                        $vals[] = substr($val, 0, $vpos - 1);
                        $val = substr($val, $vpos);
                        $vpos = 0;
                        $val = preg_replace('/\s*\(/', '', $val, 1, $count);
                        if ($count) ++$nesting;
                    }
                }
            }
            $vals[] = $val;
            foreach ($vals as &$val) {
                // decode escape sequences in bracketed strings
                // (similar to code in PDF.pm, but without line continuation)
                $val = self::UnescapeLoop($val, false);
            }
            unset($val);
            $val = count($vals) > 1 ? $vals : $vals[0];
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Unescape PostScript string
    // Inputs: 0) string
    // Returns: unescaped string
    public static function UnescapePostScript($str)
    {
        // decode escape sequences in literal strings
        return self::UnescapeLoop($str, true);
    }

    //------------------------------------------------------------------------------
    // Extract information from EPS, PS or AI file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) optional tag table ref
    // Returns: 1 if this was a valid PostScript file
    public static function ProcessPS(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $embedded = $et->Options('ExtractEmbedded');
        $data = $dos = $endDoc = $fontTable = $comment = null;

        // allow read from data
        if (!$raf) {
            $raf = new RandomAccess(Perl::str($dirInfo['DataPt']));
            $et->VerboseDir('PostScript');
        }
        //
        // determine if this is a postscript file
        //
        if ($raf->Read($data, 4) != 4) return 0;
        // accept either ASCII or DOS binary postscript file format
        if (!preg_match('/^(%!PS|%!Ad|%!Fo|\xc5\xd0\xd3\xc6)/', $data)) return 0;
        if (str_starts_with($data, '%!Ad')) {
            // I've seen PS files start with "%!Adobe-PS"...
            if (!($raf->Read($data, 6) == 6 and $data === 'obe-PS')) return 0;
        } elseif (str_starts_with($data, "\xc5\xd0\xd3\xc6")) {
            // process DOS binary file header
            // - save DOS header then seek ahead and check PS header
            if ($raf->Read($dos, 26) != 26) return 0;
            ExifTool::SetByteOrder('II');
            $psStart = ExifTool::Get32u($dos, 0);
            if (!($raf->Seek($psStart, 0) and
                  $raf->Read($data, 4) == 4 and $data === '%!PS'))
            {
                return self::PSErr($et, 'invalid header');
            }
            $raf->PSEnd = $psStart + ExifTool::Get32u($dos, 4); // set end of PostScript data in RAF
        } else {
            // check for PostScript font file (PFA or PFB)
            $d2 = null;
            if ($raf->Read($d2, 12)) $data .= $d2;
            if (preg_match('/^%!(PS-(AdobeFont-|Bitstream )|FontType1-)/', $data)) {
                $et->SetFileType('PFA');  // PostScript ASCII font file
                $fontTable = ExifTool::GetTagTable('Image::ExifTool::Font::PSInfo');
                // PostScript font files may contain an unformatted comments which may
                // contain useful information, so accumulate these for the Comment tag
                $comment = 1;
            }
            $raf->Seek(-strlen($data), 1);
        }
        //
        // set the newline type based on the first newline found in the file
        //
        $oldRS = Perl::$RS;     // (Perl: local $/)
        try {
            Perl::$RS = self::GetInputRecordSeparator($raf);
            if (!Perl::$RS) return self::PSErr($et, 'invalid PS data');

            // set file type (PostScript or EPS)
            if (!$raf->ReadLine($data)) $data = '';
            if (str_contains($data, 'EPSF')) {
                $type = 'EPS';
            } else {
                // read next line to see if this is an Illustrator file
                $line2 = null;
                $pos = $raf->Tell();
                if ($raf->ReadLine($line2) and str_starts_with($line2, '%%Creator: Adobe Illustrator')) {
                    $type = 'AI';
                } else {
                    $type = 'PS';
                }
                $raf->Seek($pos, 0);
            }
            $et->SetFileType($type);
            if ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3) return 1;
            //
            // extract TIFF information from DOS header
            //
            if (!$tagTablePtr) $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PostScript::Main');
            if ($dos) {
                $base = ExifTool::Get32u($dos, 16);
                if ($base) {
                    $pos = $raf->Tell();
                    // extract the TIFF preview
                    $len = ExifTool::Get32u($dos, 20);
                    $val = $et->ExtractBinary($base, $len, 'TIFFPreview');
                    if ($val !== null and preg_match('/^(MM\0\x2a|II\x2a\0|Binary)/', $val)) {
                        $et->HandleTag($tagTablePtr, 'TIFFPreview', $val);
                    } else {
                        $et->Warn('Bad TIFF preview image');
                    }
                    // extract information from TIFF in DOS header
                    // (set Parent to '' to avoid setting FileType tag again)
                    $subdirInfo = [     // (Perl %dirInfo)
                        'Parent' => '',
                        'RAF' => $raf,
                        'Base' => $base,
                    ];
                    if (!$et->ProcessTIFF($subdirInfo)) $et->Warn('Bad embedded TIFF');
                    // position file pointer to extract PS information
                    $raf->Seek($pos, 0);
                }
            }
            //
            // parse the postscript
            //
            $buff = $mode = $beginToken = $endToken = $docNum = $subDocNum = $changedNL = null;
            $lines = [];
            if (Perl::$RS === "\x0d") {
                $altnl = "\x0a";
            } else {
                Perl::$RS = "\x0a";     // end on any LF (even if DOS CR+LF)
                $altnl = "\x0d";
            }
            for (;;) {
                if ($lines) {
                    $data = array_shift($lines);
                } else {
                    if (!$raf->ReadLine($data)) break;
                    // check for alternate newlines as efficiently as possible
                    if (str_contains($data, $altnl)) {
                        if (strlen($data) > 500000 and ExifTool::IsPC()) {
                            // Windows can't split very long lines due to poor memory handling,
                            // so re-read the file with the other newline character instead
                            // (slower but uses less memory)
                            if (!$changedNL) {
                                $changedNL = 1;
                                $t = Perl::$RS;
                                Perl::$RS = $altnl;
                                $altnl = $t;
                                $raf->Seek(-strlen($data), 1);
                                continue;
                            }
                        } else {
                            // split into separate lines
                            $lines = explode($altnl, $data);
                            $data = array_shift($lines);
                            if (count($lines) == 1 and $lines[0] === Perl::$RS) {
                                // handle case of DOS newline data inside file using Unix newlines
                                $data .= $lines[0];
                                $lines = [];
                            }
                        }
                    }
                }
                $changedNL = null;
                if ($mode) {
                    if (!$endToken) {
                        $buff .= $data;
                        if (!preg_match('{<\?xpacket end=.(w|r).\?>(\n|\r|$)}', $data)) continue;
                    } elseif (!preg_match('/^' . preg_quote($endToken, '/') . '/i', $data)) {
                        if ($mode === 'XMP') {
                            $buff .= $data;
                        } elseif ($mode === 'Document') {
                            // ignore embedded documents, but keep track of nesting level
                            if (preg_match('/^' . preg_quote($beginToken, '/') . '/', $data)) $docNum .= '-1';
                        } else {
                            // data is ASCII-hex encoded
                            $data = preg_replace('/[^0-9A-Fa-f]/', '', $data);  // remove all but hex characters
                            $buff .= Perl::pack('H*', $data);   // translate from hex
                        }
                        continue;
                    } elseif ($mode === 'Document') {
                        $docNum = preg_replace('/-?\d+$/', '', (string) $docNum, 1);   // decrement document nesting level
                        // done with Document mode if we are back at the top level
                        if (!$docNum) $mode = null;
                        continue;
                    }
                } elseif ($endDoc and preg_match('/^' . preg_quote($endDoc, '/') . '/i', $data)) {
                    // decrement nesting level, and remember our last sub-document number
                    $subDocNum = preg_match('/-?(\d+)$/', (string) $docNum, $m) ? $m[1] : null;
                    $docNum = preg_replace('/-?(\d+)$/', '', (string) $docNum, 1);
                    $et->DOC_NUM = $docNum;
                    if (!$docNum) $endDoc = null;   // done with document if top level
                    continue;
                } elseif (preg_match('/^(%{1,2})(Begin)(_xml_packet|Photoshop|ICCProfile|Document|Binary)/i', $data, $m)) {
                    // the beginning of a data block
                    $modeLookup = [
                        '_xml_packet' => 'XMP',
                        'photoshop'   => 'Photoshop',
                        'iccprofile'  => 'ICC_Profile',
                        'document'    => 'Document',
                        'binary'      => null, // (we will try to skip this)
                    ];
                    $mode = $modeLookup[strtolower($m[3])];
                    if (!$mode) {
                        if (!$lines and preg_match('/^%{1,2}BeginBinary:\s*(\d+)/i', $data, $mb)) {
                            if (!$raf->Seek($mb[1], 1)) break;  // skip binary data
                        }
                        continue;
                    }
                    $buff = '';
                    $beginToken = $m[1] . $m[2] . $m[3];
                    $endToken = $m[1] . ($m[2] === 'begin' ? 'end' : 'End') . $m[3];
                    if ($mode === 'Document') {
                        // this is either the 1st sub-document or Nth document
                        if ($docNum) {
                            // increase nesting level
                            $docNum .= '-' . (++$subDocNum);
                        } else {
                            // this is the Nth document
                            $docNum = Perl::num($et->DOC_COUNT) + 1;
                        }
                        $subDocNum = 0; // new level, so reset subDocNum
                        if (!$embedded) continue;   // skip over this document
                        // set document number for family 4-7 group names
                        $et->DOC_NUM = $docNum;
                        $et->LIST_TAGS = [];    // don't build lists across different documents
                        $et->PROCESSED = [];    // re-initialize processed directory lookup too
                        $endDoc = $endToken;    // parse to EndDocument token
                        // reset mode to allow parsing into sub-directories
                        $endToken = null;
                        $mode = null;
                        // save document name if available
                        if (preg_match('/^' . preg_quote($beginToken, '/') . ':\s+([^\n\r]+)/i', $data, $md)) {
                            $docName = $md[1];
                            // remove brackets if necessary
                            if (preg_match('/^\((.*)\)$/', $docName, $md)) $docName = $md[1];
                            $et->HandleTag($tagTablePtr, 'EmbeddedFileName', $docName);
                        }
                    }
                    continue;
                } elseif (preg_match('/^<\?xpacket begin=.{7,13}W5M0MpCehiHzreSzNTczkc9d/', $data)) {
                    // pick up any stray XMP data
                    $mode = 'XMP';
                    $buff = $data;
                    $endToken = null;   // no end token (just look for xpacket end)
                    // XMP could be contained in a single line (if newlines are different)
                    if (!preg_match('{<\?xpacket end=.(w|r).\?>(\n|\r|$)}', $data)) continue;
                } elseif (preg_match('/^%%?(\w+): ?(.*)/s', $data, $m) and $tagTablePtr[$m[1]]) {
                    [$tag, $val] = [$m[1], $m[2]];
                    // only allow 'ImageData' and AI tags to have single leading '%'
                    if (!(preg_match('/^%(%|AI\d+_)/', $data) or $tag === 'ImageData')) continue;
                    // decode comment string (reading continuation lines if necessary)
                    $val = self::DecodeComment($val, $raf, $lines);
                    $et->HandleTag($tagTablePtr, $tag, $val);
                    continue;
                } elseif ($embedded and str_starts_with($data, '%AI12_CompressedData')) {
                    // the rest of the file is compressed
                    if (!function_exists('inflate_init')) {
                        $et->Warn('Install Compress::Zlib to extract compressed embedded data');
                        break;
                    }
                    // seek back to find the start of the compressed data in the file
                    $tlen = strlen($data) + count($lines);
                    foreach ($lines as $l) $tlen += strlen($l);
                    $backTo = $raf->Tell() - $tlen - 64;
                    if ($backTo < 0) $backTo = 0;
                    if (!($raf->Seek($backTo, 0) and $raf->Read($data, 2048))) break;
                    $data = preg_replace('/.*?%AI12_CompressedData/', '', $data, 1, $count);
                    if (!$count) break;
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    if (!$inflate) {
                        $et->Warn('Error initializing inflate');
                        break;
                    }
                    // generate a PS-like file in memory from the compressed data
                    $verbose = $et->Options('Verbose');
                    if ($verbose > 1) {
                        $et->VerboseDir('AI12_CompressedData (first 4kB)');
                        $et->VerboseDump($data);
                    }
                    // remove header if it exists (Windows AI files only)
                    $data = preg_replace('/^.{0,256}EndData[\x0d\x0a]+/s', '', $data, 1);
                    $val = null;
                    for (;;) {
                        // (Perl: ($v2, $stat) = $inflate->inflate($data))
                        $v2 = @inflate_add($inflate, $data, ZLIB_SYNC_FLUSH);
                        if ($v2 !== false and inflate_get_status($inflate) == ZLIB_STREAM_END) {
                            $val .= $v2;
                            break;
                        }
                        if ($v2 === false) {
                            $val = null;
                            break;
                        }
                        if ($val !== null) {
                            $val .= $v2;
                        } elseif (str_starts_with($v2, '%!PS')) {
                            $val = $v2;
                        } else {
                            // add postscript header (for file recognition) if it doesn't exist
                            $val = '%!PS-Adobe-3.0' . Perl::$RS . $v2;
                        }
                        if (!$raf->Read($data, 65536)) break;
                    }
                    if ($val === null) {
                        $et->Warn('Error inflating AI compressed data');
                        break;
                    }
                    if ($verbose > 1) {
                        $et->VerboseDir('Uncompressed AI12 Data');
                        $et->VerboseDump($val);
                    }
                    // extract information from embedded images in the uncompressed data
                    $subdirInfo = ['DataPt' => $val];
                    self::ProcessPS($et, $subdirInfo);
                    break;
                } elseif ($fontTable) {
                    if ($comment !== null) {
                        // extract initial comments from PostScript Font files
                        if (preg_match('/^%\s+(.*?)[\x0d\x0a]/', $data, $m)) {
                            if ($comment) $comment .= "\n";
                            $comment .= $m[1];
                            continue;
                        } elseif (!str_starts_with($data, '%')) {
                            // stop extracting comments at the first non-comment line
                            if (strlen($comment)) $et->FoundTag('Comment', $comment);
                            $comment = null;
                        }
                    }
                    if (preg_match('{^\s*/(\w+)\s*(.*)}', $data, $m) and $fontTable[$m[1]]) {
                        [$tag, $val] = [$m[1], $m[2]];
                        if (preg_match('/^\((.*)\)/', $val, $m)) {
                            $val = self::UnescapePostScript($m[1]);
                        } elseif (preg_match('{/?(\S+)}', $val, $m)) {
                            $val = $m[1];
                        }
                        $et->HandleTag($fontTable, $tag, $val);
                    } elseif (str_starts_with($data, 'currentdict end')) {
                        // only extract tags from initial FontInfo dict
                        $fontTable = null;
                    }
                    continue;
                } else {
                    continue;
                }
                // extract information from buffered data
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt' => $buff,
                    'DataLen' => strlen($buff),
                    'DirStart' => 0,
                    'DirLen' => strlen($buff),
                    'Parent' => 'PostScript',
                ];
                $subTablePtr = ExifTool::GetTagTable("Image::ExifTool::{$mode}::Main");
                if (!$et->ProcessDirectory($subdirInfo, $subTablePtr)) {
                    $et->Warn("Error processing $mode information in PostScript file");
                }
                $buff = null;
                $mode = null;
            }
            if ($endDoc and !$mode) $mode = 'Document';
            if ($mode) self::PSErr($et, "unterminated $mode data");
            return 1;
        } finally {
            Perl::$RS = $oldRS;
        }
    }

    //------------------------------------------------------------------------------
    // Extract information from EPS file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a valid PostScript file
    public static function ProcessEPS(ExifTool $et, array &$dirInfo)
    {
        return self::ProcessPS($et, $dirInfo);
    }
}
