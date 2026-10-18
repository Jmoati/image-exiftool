<?php

//------------------------------------------------------------------------------
// File:         WritePostScript.pl
//
// Description:  Write PostScript meta information
//
// Revisions:    03/03/2006 - P. Harvey Created
//
// References:   (see references in PostScript.pm, plus:)
//               1) http://www.adobe.com/products/postscript/pdfs/PLRM.pdf
//               2) http://www-cdf.fnal.gov/offline/PostScript/PLRM2.pdf
//               3) http://partners.adobe.com/public/developer/en/acrobat/sdk/pdf/pdf_creation_apis_and_specs/pdfmarkReference.pdf
//               4) http://www.npes.org/standards/Tools/DCS20Spec.pdf
//
// PHP port of the whole file (package Image::ExifTool::PostScript), mixed into class
// PostScript. Perl's input record separator $/ is Perl::$RS.
//------------------------------------------------------------------------------

namespace Image\ExifTool\PostScript;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;

// Structure of a DSC PS/EPS document:
//
//   %!PS-Adobe-3.0     [plus " EPSF-3.0" for EPS]
//     <comments>
//   %%EndComments      [optional]
//   %%BeginXxxx
//     <stuff to ignore>
//   %%EndXxxx
//   %%BeginProlog
//     <prolog stuff>
//   %%EndProlog
//   %%BeginSetup
//     <setup stuff>
//   %%EndSetup
//   %ImageData x x x x  [written by Photoshop]
//   %BeginPhotoshop: xxxx
//     <ascii-hex IRB information>
//   %EndPhotosop
//   %%BeginICCProfile: (name) <num> <type>
//     <ICC Profile info>
//   %%EndICCProfile
//   %begin_xml_code
//     <postscript code to define and read the XMP stream object>
//   %begin_xml_packet: xxxx
//     <XMP data>
//   %end_xml_packet
//     <postscript code to add XMP stream to dictionary>
//   %end_xml_code
//   %%Page: x x         [PS only (optional?)]
//     <graphics commands>
//   %%PageTrailer
//   %%Trailer
//     <a bit more code to bracket EPS content for distiller>
//   %%EOF

trait WritePostScript
{
    // map of where information is stored in PS image
    /** my %psMap */
    private static array $psMap = [
        'XMP'          => 'PostScript',
        'Photoshop'    => 'PostScript',
        'IPTC'         => 'Photoshop',
        'EXIFInfo'     => 'Photoshop',
        'EXIF'         => 'EXIFInfo',
        'IFD0'         => 'EXIFInfo',
        'IFD1'         => 'IFD0',
        'ICC_Profile'  => 'PostScript',
        'ExifIFD'      => 'IFD0',
        'GPS'          => 'IFD0',
        'SubIFD'       => 'IFD0',
        'GlobParamIFD' => 'IFD0',
        'PrintIM'      => 'IFD0',
        'InteropIFD'   => 'ExifIFD',
        'MakerNotes'   => 'ExifIFD',
    ];

    //------------------------------------------------------------------------------
    // Write XMP directory to file, with begin/end tokens if necessary
    // Inputs: 0) outfile ref, 1) flags hash ref, 2-N) data to write
    // Returns: true on success
    public static function WriteXMPDir($outfile, array $flags, ...$data)
    {
        $success = 1;
        $RS = Perl::$RS;
        if (!($flags['WROTE_BEGIN'] ?? null)) {
            if (!ExifTool::Write($outfile, "%begin_xml_code$RS")) $success = 0;
        }
        if (!ExifTool::Write($outfile, ...$data)) $success = 0;
        if (!($flags['WROTE_BEGIN'] ?? null)) {
            if (!ExifTool::Write($outfile, "%end_xml_code$RS")) $success = 0;
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Write a directory inside a PS document
    // Inputs: 0) ExifTool object ref, 1) output file reference,
    //         2) Directory name, 3) data reference, 4) flags hash ref
    // Returns: 0=error, 1=nothing written, 2=dir written ok
    // (PHP: the data itself, null for none)
    public static function WritePSDirectory(ExifTool $et, $outfile, $dirName, $dataPt, array &$flags)
    {
        $success = 2;
        $len = $dataPt !== null ? strlen($dataPt) : 0;
        $create = $len ? 0 : 1;
        $dirInfo = [
            'DataPt' => $dataPt,
            'DataLen' => $len,
            'DirStart' => 0,
            'DirLen' => $len,
            'DirName' => $dirName,
            'Parent' => 'PostScript',
        ];
        // Note: $$flags{WROTE_BEGIN} may be 1 for XMP (it is always 0 for
        // other dirs, but if 1, the begin/end markers were already written)
        //
        // prepare necessary postscript code to support embedded XMP
        //
        $beforeXMP = $afterXMP = $reportedLen = null;
        if ($dirName === 'XMP' and $len) {
            // isolate the XMP
            if (!preg_match('/(.*)(<\?xpacket begin=.{7,13}W5M0MpCehiHzreSzNTczkc9d)/s', $dataPt, $m, PREG_OFFSET_CAPTURE)) {
                $et->Warn('No XMP packet start');
                return self::WriteXMPDir($outfile, $flags, $dataPt);
            }
            $beforeXMP = $m[1][0];
            $xmp = $m[2][0];
            $p1 = $m[2][1] + strlen($xmp);
            if (!preg_match('{<\?xpacket end=.(w|r).\?>}s', $dataPt, $m, PREG_OFFSET_CAPTURE, $p1)) {
                $et->Warn('No XMP packet end');
                return self::WriteXMPDir($outfile, $flags, $dataPt);
            }
            $p2 = $m[0][1] + strlen($m[0][0]);
            $xmp .= substr($dataPt, $p1, $p2 - $p1);
            $afterXMP = substr($dataPt, $p2);
            // determine if we can adjust the XMP size
            if (preg_match('/%begin_xml_packet: (\d+)/s', $beforeXMP, $m)) {
                $reportedLen = $m[1];
                $matches = preg_match_all("/\\b$reportedLen\\b/s", $beforeXMP);
                if ($matches != 2) $reportedLen = null;
            }
            // must edit in place if we can't reliably change the XMP length
            if (!$reportedLen) $dirInfo['InPlace'] = 1;
            // process XMP only
            $dirInfo['DataLen'] = $dirInfo['DirLen'] = strlen($xmp);
            $dirInfo['DataPt'] = $xmp;
        }
        $tagTablePtr = ExifTool::GetTagTable("Image::ExifTool::{$dirName}::Main");
        $val = $et->WriteDirectory($dirInfo, $tagTablePtr);
        if ($val !== null) {
            $dataPt = $val;     // use modified directory
            $len = strlen($val);
        } elseif ($dirName === 'XMP') {
            if (!$len) return 1;
            // just write the original XMP
            return self::WriteXMPDir($outfile, $flags, $dataPt);
        }
        $RS = Perl::$RS;
        if (!$len) {
            if ($create or $dirName !== 'XMP') return 1;   // nothing to create
            // it would be really difficult to delete the XMP,
            // so instead we write a blank XMP record
            $val = "<?xpacket begin='\xef\xbb\xbf' id='W5M0MpCehiHzreSzNTczkc9d'?>\n" .
                "<x:xmpmeta xmlns:x='adobe:ns:meta/' x:xmptk='Image::ExifTool " . ExifTool::$VERSION . "'>\n" .
                "</x:xmpmeta>\n";
            if (empty($et->OPTIONS['Compact']['NoPadding'])) $val .= str_repeat(str_repeat(' ', 100) . "\n", 24);
            $val .= "<?xpacket end='w'?>";
            $dataPt = $val;
            $len = strlen($val);
        }
        //
        // write XMP directory
        //
        if ($dirName === 'XMP') {
            if ($create) {
                // create necessary PS/EPS code to support XMP
                $beforeXMP = <<<HDR_END
/pdfmark where {pop true} {false} ifelse
/currentdistillerparams where {pop currentdistillerparams
/CoreDistVersion get 5000 ge } {false} ifelse
and not {userdict /pdfmark /cleartomark load put} if
[/NamespacePush pdfmark
[/_objdef {exiftool_metadata_stream} /type /stream /OBJ pdfmark
[{exiftool_metadata_stream} 2 dict begin /Type /Metadata def
  /Subtype /XML def currentdict end /PUT pdfmark
/MetadataString $len string def % exact length of metadata
/TempString 100 string def
/ConsumeMetadata {
currentfile TempString readline pop pop
currentfile MetadataString readstring pop pop
} bind def
ConsumeMetadata
%begin_xml_packet: $len

HDR_END;
                // note: use q() to get necessary linefeed before %end_xml_packet
                $afterXMP = "\n%end_xml_packet\n[{exiftool_metadata_stream} MetadataString /PUT pdfmark\n";
                if ($flags['EPS'] ?? null) {
                    $afterXMP .= "[/Document 1 dict begin\n" .
                        "  /Metadata {exiftool_metadata_stream} def currentdict end /BDC pdfmark\n" .
                        "[/NamespacePop pdfmark\n";
                    // write this at end of file
                    $flags['TRAILER'] = "[/EMC pdfmark$RS";
                } else { // PS
                    $afterXMP .= "[{Catalog} {exiftool_metadata_stream} /Metadata pdfmark\n" .
                        "[/NamespacePop pdfmark\n";
                }
                $beforeXMP = str_replace("\n", $RS, $beforeXMP);  // use proper newline characters
                $afterXMP = str_replace("\n", $RS, $afterXMP);
            } else {
                // replace xmp size in PS code
                if ($reportedLen) $beforeXMP = preg_replace("/\\b$reportedLen\\b/s", (string) $len, $beforeXMP);
            }
            if (!self::WriteXMPDir($outfile, $flags, $beforeXMP, $dataPt, $afterXMP)) $success = 0;
            //
            // Write Photoshop or ICC_Profile directory
            //
        } elseif ($dirName === 'Photoshop' or $dirName === 'ICC_Profile') {
            if ($dirName === 'Photoshop') {
                $startToken = "%BeginPhotoshop: $len";
                $endToken = '%EndPhotoshop';
            } else {
                $startToken = '%%BeginICCProfile: (Photoshop Profile) -1 Hex';
                $endToken = '%%EndICCProfile';
            }
            if (!ExifTool::Write($outfile, $startToken, $RS)) $success = 0;
            // write as an ASCII-hex comment
            $wid = 32;
            for ($i = 0; $i < $len; $i += $wid) {
                if ($wid > $len - $i) $wid = $len - $i;
                $dat = substr($dataPt, $i, $wid);
                if (!ExifTool::Write($outfile, '% ', strtoupper(bin2hex($dat)), $RS)) $success = 0;
            }
            if (!ExifTool::Write($outfile, $endToken, $RS)) $success = 0;
        } else {
            $et->Warn("Can't write PS directory $dirName");
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Encode postscript tag/value
    // Inputs: 0) tag ID, 1) value
    // Returns: postscript comment
    // - adds brackets, escapes special characters, and limits line length
    public static function EncodeTag($tag, $val)
    {
        $val = Perl::str($val);
        if (!preg_match('/^\d+$/', $val)) {
            $val = preg_replace('/([()\\\\])/', '\\\\$1', $val);   // escape brackets and backslashes
            $val = str_replace("\n", '\\n', $val);  // escape newlines
            $val = str_replace("\r", '\\r', $val);  // escape carriage returns
            $val = str_replace("\t", '\\t', $val);  // escape tabs
            // use octal escape codes for other control characters
            $val = preg_replace_callback('/([\x00-\x1f\x7f\xff])/', static fn ($m) => Perl::sprintf('\\%.3o', ord($m[1])), $val);
            $val = "($val)";
        }
        $line = "%%$tag: $val";
        // postscript line limit is 255 characters (but it seems that
        // the limit may be 254 characters if the DOS CR/LF is used)
        // --> split if necessary using continuation comment "%%+"
        $RS = Perl::$RS;
        for ($n = 254; strlen($line) > $n; $n += 254 + strlen($RS)) {
            $line = substr_replace($line, "$RS%%+", $n, 0);
        }
        return $line . $RS;
    }

    //------------------------------------------------------------------------------
    // Write new tags information in comments section
    // Inputs: 0) ExifTool object ref, 1) output file ref, 2) reference to new tag hash
    // Returns: true on success
    public static function WriteNewTags(ExifTool $et, $outfile, array &$newTags)
    {
        $success = 1;

        // get XMP hint and remove from tags hash
        $xmpHint = $newTags['XMP_HINT'] ?? null;
        unset($newTags['XMP_HINT']);

        $tags = array_map('strval', array_keys($newTags));
        sort($tags, SORT_STRING);
        foreach ($tags as $tag) {
            $tagInfo = $newTags[$tag];
            $nvHash = $et->GetNewValueHash($tagInfo);
            if (!$nvHash->IsCreating) continue;
            $val = $et->GetNewValue($nvHash);
            $et->VerboseValue("+ PostScript:{$tagInfo->Name}", $val);
            if (!ExifTool::Write($outfile, self::EncodeTag($tag, $val))) $success = 0;
            ++$et->CHANGED;
        }
        // write XMP hint if necessary
        if ($xmpHint) {
            if (!ExifTool::Write($outfile, '%ADO_ContainsXMP: MainFirst' . Perl::$RS)) $success = 0;
        }

        $newTags = [];      // all done with new tags
        return $success;
    }

    //------------------------------------------------------------------------------
    // Write PS file
    // Inputs: 0) ExifTool object reference, 1) source dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PS file,
    //          or -1 if a write error occurred
    public static function WritePS(?ExifTool $et = null, ?array &$dirInfo = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PostScript::Main');
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $data = $buff = $err = $mode = $endToken = null;
        $flags = [];
        $dos = $psStart = $psNewStart = $xmpHint = null;
        $lines = [];

        if ($raf->Read($data, 4) != 4) return 0;
        if (!preg_match('/^(%!PS|%!Ad|\xc5\xd0\xd3\xc6)/', $data)) return 0;

        if (str_starts_with($data, '%!Ad')) {
            // I've seen PS files start with "%!Adobe-PS"...
            if (!($raf->Read($buff, 6) == 6 and $buff === 'obe-PS')) return 0;
            $data .= $buff;
        } elseif (str_starts_with($data, "\xc5\xd0\xd3\xc6")) {
            //
            // process DOS binary PS files
            //
            // save DOS header then seek ahead and check PS header
            if ($raf->Read($dos, 26) != 26) return 0;
            $dos = $data . $dos;
            ExifTool::SetByteOrder('II');
            $psStart = ExifTool::Get32u($dos, 4);
            if (!($raf->Seek($psStart, 0) and
                  $raf->Read($data, 4) == 4 and $data === '%!PS'))
            {
                $et->Error('Invalid PS header');
                return 1;
            }
            $raf->PSEnd = $psStart + ExifTool::Get32u($dos, 8);
            $base = ExifTool::Get32u($dos, 20);
            ExifTool::Set16u(0xffff, $dos, 28);    // ignore checksum
            if ($base) {
                $tiffInfo = [   // (Perl: my %dirInfo)
                    'Parent' => 'PS',
                    'RAF' => $raf,
                    'Base' => $base,
                    'NoTiffEnd' => 1,   // no end-of-TIFF check
                ];
                $buff = ExifTool::WriteTIFF($et, $tiffInfo);
                ExifTool::SetByteOrder('II');  // (WriteTIFF may change this)
                if ($buff) {
                    $buff = substr($buff, $base);  // remove header written by WriteTIFF()
                } else {
                    // error rewriting TIFF, so just copy over original data
                    $len = ExifTool::Get32u($dos, 24);
                    if (!($raf->Seek($base, 0) and $raf->Read($buff, $len) == $len)) {
                        $et->Error('Error reading embedded TIFF');
                        return 1;
                    }
                    $et->Warn('Bad embedded TIFF');
                }
                ExifTool::Set32u(0, $dos, 12);                  // zero metafile pointer
                ExifTool::Set32u(0, $dos, 16);                  // zero metafile length
                ExifTool::Set32u(strlen($dos), $dos, 20);       // set TIFF pointer
                ExifTool::Set32u(strlen($buff), $dos, 24);      // set TIFF length
            } elseif (($base = ExifTool::Get32u($dos, 12)) != 0) {
                // copy over metafile section
                $len = ExifTool::Get32u($dos, 16);
                if (!($raf->Seek($base, 0) and $raf->Read($buff, $len) == $len)) {
                    $et->Error('Error reading metafile section');
                    return 1;
                }
                ExifTool::Set32u(strlen($dos), $dos, 12);       // set metafile pointer
            } else {
                $buff = '';
            }
            $psNewStart = strlen($dos) + strlen($buff);
            ExifTool::Set32u($psNewStart, $dos, 4);    // set pointer to start of PS
            if (!ExifTool::Write($outfile, $dos, $buff)) $err = 1;
            $raf->Seek($psStart + 4, 0);    // seek back to where we were
        }
        //
        // rewrite PostScript data
        //
        $oldRS = Perl::$RS;     // (Perl: local $/)
        Perl::$RS = self::GetInputRecordSeparator($raf);
        try {
            $RS = Perl::$RS;
            if (!($RS and $raf->ReadLine($buff))) {
                $et->Error('Invalid PostScript data');
                return 1;
            }
            $data .= $buff;
            if (!(preg_match('/^%!PS-Adobe-3\.(\d+)\b/', $data, $m) and $m[1] < 2)) {
                if ($et->Error('Document does not conform to DSC spec. Metadata may be unreadable by other apps', 2)) {
                    return 1;
                }
            }
            if (!ExifTool::Write($outfile, $data)) $err = 1;
            if (str_contains($data, 'EPSF')) $flags['EPS'] = 1;

            // get hash of new information keyed by tagID and directories to add/edit
            $newTags = $et->GetNewTagInfoHash($tagTablePtr);

            // figure out which directories we need to write (PostScript takes priority)
            $et->InitWriteDirs(self::$psMap, 'PostScript');
            $addDirs = &$et->ADD_DIRS;
            $editDirs = &$et->EDIT_DIRS;
            $doneDir = [];

            // set XMP hint flag (1 for adding, 0 for deleting, undef for no change)
            if ($addDirs['XMP'] ?? null) $xmpHint = 1;
            if ($et->DEL_GROUP['XMP'] ?? null) $xmpHint = 0;
            if ($xmpHint) $newTags['XMP_HINT'] = $xmpHint;  // add special tag to newTags list

            for (;;) {
                if (!$lines and !self::GetNextLine($raf, $lines)) break;
                $data = array_shift($lines);
                if ($endToken !== null) {
                    // look for end token
                    if (preg_match('~^' . str_replace('~', '\~', $endToken) . '\s*$~is', $data)) {
                        $endToken = null;
                        // found end: process this information
                        if ($mode) {
                            if ($doneDir[$mode] ?? null) $et->Error("Multiple $mode directories", 1);
                            $doneDir[$mode] = 1;
                            if (!self::WritePSDirectory($et, $outfile, $mode, $buff, $flags)) $err = 1;
                            // write end token if we wrote the begin token
                            if ($flags['WROTE_BEGIN'] ?? null) {
                                if (!ExifTool::Write($outfile, $data)) $err = 1;
                            }
                            $buff = null;
                        } else {
                            if (!ExifTool::Write($outfile, $data)) $err = 1;
                        }
                    } else {
                        // buffer data in current begin/end block
                        if ($mode === null) {
                            // pick up XMP in unrecognized blocks for editing in place
                            if (preg_match('/^<\?xpacket begin=.{7,13}W5M0MpCehiHzreSzNTczkc9d/', $data) and
                                ($editDirs['XMP'] ?? null))
                            {
                                $buff = $data;
                                $mode = 'XMP';
                            } else {
                                if (!ExifTool::Write($outfile, $data)) $err = 1;
                            }
                        } elseif ($mode === 'XMP') {
                            $buff .= $data;
                        } else {
                            // data is ASCII-hex encoded
                            $data = preg_replace('/[^0-9A-Fa-f]/', '', $data);   // remove all but hex characters
                            $buff .= Perl::pack('H*', $data);   // translate from hex
                        }
                    }
                    continue;
                } elseif (preg_match('{^(%{1,2})(Begin)(?!Object:)(.*?)[:\x0d\x0a]}i', $data, $m)) {
                    // comments section is over... write any new tags now
                    if ($newTags) {
                        if (!self::WriteNewTags($et, $outfile, $newTags)) $err = 1;
                    }
                    $xmpHint = null;
                    // the beginning of a data block (can only write XMP and Photoshop)
                    $modeLookup = [
                        '_xml_code' => 'XMP',
                        'photoshop' => 'Photoshop',
                        'iccprofile' => 'ICC_Profile',
                    ];
                    if ($verbose > 1) fwrite($out, "$m[2]$m[3]\n");
                    $endToken = $m[1] . ($m[2] === 'begin' ? 'end' : 'End') . $m[3];
                    $mode = $modeLookup[strtolower($m[3])] ?? null;
                    if ($mode and ($editDirs[$mode] ?? null)) {
                        $buff = '';     // initialize buffer for this block
                        $flags['WROTE_BEGIN'] = 0;
                    } else {
                        $mode = null;   // not editing this directory
                        if (!ExifTool::Write($outfile, $data)) $err = 1;
                        $flags['WROTE_BEGIN'] = 1;
                    }
                    continue;
                } elseif (preg_match('/^%%(?!Page:|PlateFile:|BeginObject:)(\w+): ?(.*)/s', $data, $m)) {
                    // rewrite information from PostScript tags in comments
                    [$tag, $val] = [$m[1], $m[2]];
                    // handle Adobe Illustrator files specially
                    // - EVENTUALLY IT WOULD BE BETTER TO FIND ANOTHER IDENTIFICATION METHOD
                    //   (because Illustrator doesn't care if the Creator is changed)
                    if ($tag === 'Creator' and str_starts_with($val, 'Adobe Illustrator')) {
                        // disable writing XMP to PostScript-format Adobe Illustrator files
                        // because it confuses Illustrator
                        if ($editDirs['XMP'] ?? null) {
                            $et->Warn("Can't write XMP to PostScript-format Illustrator files");
                            // pretend like we wrote it already so we won't try to add it later
                            $doneDir['XMP'] = 1;
                        }
                        // don't allow "Creator" to be changed in Illustrator files
                        // (we need it to be able to recognize these files)
                        // --> find a better way to do this!
                        if ($newTags[$tag] ?? null) {
                            $et->Warn("Can't change Postscript:Creator of Illustrator files");
                            unset($newTags[$tag]);
                        }
                    }
                    if ($newTags[$tag] ?? null) {
                        $tagInfo = $newTags[$tag];
                        unset($newTags[$tag]);  // write it then forget it
                        if (!is_object($tagInfo)) continue;
                        // decode comment string (reading continuation lines if necessary)
                        $val = self::DecodeComment($val, $raf, $lines, $data);
                        if (is_array($val)) $val = implode($et->Options('ListSep'), $val);
                        $nvHash = $et->GetNewValueHash($tagInfo);
                        if ($et->IsOverwriting($nvHash, $val)) {
                            $et->VerboseValue("- PostScript:{$tagInfo->Name}", $val);
                            $val = $et->GetNewValue($nvHash);
                            ++$et->CHANGED;
                            if ($val === null) continue;    // next if tag is being deleted
                            $et->VerboseValue("+ PostScript:{$tagInfo->Name}", $val);
                            $data = self::EncodeTag($tag, $val);
                        }
                    }
                // (note: Adobe InDesign doesn't put colon after %ADO_ContainsXMP -- doh!)
                } elseif ($xmpHint !== null and preg_match('{^%ADO_ContainsXMP:? ?(.+?)[\x0d\x0a]*$}s', $data, $m)) {
                    // change the XMP hint if necessary
                    if ($xmpHint) {
                        if ($m[1] === 'NoMain') $data = "%ADO_ContainsXMP: MainFirst$RS";
                    } else {
                        $data = "%ADO_ContainsXMP: NoMain$RS";
                    }
                    // delete XMP hint flags
                    unset($newTags['XMP_HINT']);
                    $xmpHint = null;
                } else {
                    // look for end of comments section
                    if ($newTags and (!preg_match('/^%\S/', $data) or
                        preg_match('/^%(%EndComments|%Page:|%PlateFile:|%BeginObject:|.*BeginLayer)/', $data)))
                    {
                        // write new tags at end of comments section
                        if (!self::WriteNewTags($et, $outfile, $newTags)) $err = 1;
                        $xmpHint = null;
                    }
                    // look for start of drawing commands (AI uses "%AI5_BeginLayer",
                    // and Helios uses "%%BeginObject:")
                    if (preg_match('/^%(%Page:|%PlateFile:|%BeginObject:|.*BeginLayer)/', $data) or
                        !preg_match('{^(%.*|\s*)$}s', $data))
                    {
                        // we have reached the first page or drawing command, so create necessary
                        // directories and copy the rest of the file, then all done
                        $plateFile = str_starts_with($data, '%%PlateFile:');
                        // create Photoshop first, then XMP if necessary
                        foreach (['Photoshop', 'ICC_Profile', 'XMP'] as $dir) {
                            if (!(($editDirs[$dir] ?? null) and !($doneDir[$dir] ?? null))) continue;
                            if ($plateFile) {
                                // PlateFile comments may contain offsets so we can't edit these files!
                                $et->Warn('Can only edit PostScript information DCS Plate files');
                                break;
                            }
                            if (!(($addDirs[$dir] ?? null) or $dir === 'XMP')) continue;
                            $flags['WROTE_BEGIN'] = 0;
                            if (!self::WritePSDirectory($et, $outfile, $dir, null, $flags)) $err = 1;
                            $doneDir[$dir] = 1;
                        }
                        // copy rest of file
                        if ($flags['TRAILER'] ?? null) {
                            // write trailer before %%EOF
                            for (;;) {
                                if (!ExifTool::Write($outfile, $data)) $err = 1;
                                if ($lines) {
                                    $data = array_shift($lines);
                                } else {
                                    if (!$raf->ReadLine($data)) {
                                        $data = null;
                                        break;
                                    }
                                    if ($dos) self::CheckPSEnd($raf, $data);
                                    if (preg_match('/[\x0d\x0a]%%EOF\b/', $data, $m, PREG_OFFSET_CAPTURE)) {
                                        // split data before "%%EOF"
                                        // (necessary if data contains other newline sequences)
                                        $pos = $m[0][1] + strlen($m[0][0]) - 5;
                                        $lines[] = substr($data, $pos);
                                        $data = substr($data, 0, $pos);
                                    }
                                }
                                if (preg_match('/^%%EOF\b/', $data)) break;
                            }
                            if (!ExifTool::Write($outfile, $flags['TRAILER'])) $err = 1;
                        }
                        // simply copy the rest of the file if any data is left
                        if ($data !== null) {
                            if (!ExifTool::Write($outfile, $data)) $err = 1;
                            if ($lines) {
                                if (!ExifTool::Write($outfile, ...$lines)) $err = 1;
                            }
                            while ($raf->Read($data, 65536)) {
                                if ($dos) self::CheckPSEnd($raf, $data);
                                if (!ExifTool::Write($outfile, $data)) $err = 1;
                            }
                        }
                        break;  // all done!
                    }
                }
                // write new information or copy existing line
                if (!ExifTool::Write($outfile, $data)) $err = 1;
            }
            if ($dos and !$err) {
                // must go back and set length of PS section in DOS header (very dumb design)
                if ($outfile instanceof ScalarRef) {
                    ExifTool::Set32u(strlen($outfile->value) - $psNewStart, $outfile->value, 8);
                } else {
                    $pos = ftell($outfile);
                    if (!($pos !== false and fseek($outfile, 8, SEEK_SET) == 0 and
                          fwrite($outfile, ExifTool::Set32u($pos - $psNewStart)) and
                          fseek($outfile, $pos, SEEK_SET) == 0))
                    {
                        $et->Error("Can't write DOS-style PS files in non-seekable stream");
                        $err = 1;
                    }
                }
            }
            // issue warning if we couldn't write any information
            if (!$err) {
                $notDone = [];
                unset($newTags['XMP_HINT']);
                if ($newTags) $notDone[] = 'PostScript';
                foreach (['Photoshop', 'ICC_Profile', 'XMP'] as $dir) {
                    if (($editDirs[$dir] ?? null) and !($doneDir[$dir] ?? null) and
                        !($et->DEL_GROUP[$dir] ?? null)) $notDone[] = $dir;
                }
                if ($notDone) $et->Warn("Couldn't write " . implode('/', $notDone) . ' information');
            }
            if ($endToken !== null) $et->Error("File missing $endToken");
            return $err ? -1 : 1;
        } finally {
            Perl::$RS = $oldRS;
        }
    }
}
