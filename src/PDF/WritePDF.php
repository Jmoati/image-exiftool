<?php

//------------------------------------------------------------------------------
// File:         WritePDF.pl
//
// Description:  Write PDF meta information
//
// Revisions:    12/08/2007 - P. Harvey Created
//
// PHP port (package Image::ExifTool::PDF), mixed into class Image\ExifTool\PDF.
// PDF dictionaries are PHP arrays (copies, not shared references): WritePDF() works on
// references into the capture array filled by PDF::ProcessDict()/ReadPDF().
//------------------------------------------------------------------------------

namespace Image\ExifTool\PDF;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;

trait WritePDF
{
    // comments to mark beginning and end of ExifTool incremental update
    private static string $beginComment = '%BeginExifToolUpdate';
    private static string $endComment = '%EndExifToolUpdate ';

    private static $keyExt = null;      // crypt key extension
    private static $pdfVer = null;      // version of PDF file we are writing (highest Version in Root dictionaries)

    // internal tags used in dictionary objects
    private const array myDictTags = [
        '_tags' => 1, '_stream' => 1, '_decrypted' => 1, '_needCrypt' => 1,
        '_filtered' => 1, '_entry_size' => 1, '_table' => 1,
    ];

    //------------------------------------------------------------------------------
    // Validate raw PDF values for writing (string date integer real boolean name)
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckPDF(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = Perl::str($tagInfo->Writable ?: $tagInfo->Table['WRITABLE']);
        $val = Perl::str($valPtr);
        if (!$format) {
            return 'No writable format';
        } elseif ($format === 'string') {
            // (encode later because list-type string tags need to be encoded as a unit)
        } elseif ($format === 'date') {
            // be flexible about this for now
            if (!preg_match('/^\d{4}/', $val)) return 'Bad date format';
        } elseif ($format === 'integer') {
            if (!ExifTool::IsInt($valPtr)) return 'Not an integer';
        } elseif ($format === 'real') {
            if (!preg_match('/^[+-]?(?=\d|\.\d)\d*(\.\d*)?$/', $val)) return 'Not a real number';
        } elseif ($format === 'boolean') {
            $valPtr = ($valPtr and !preg_match('/^f/i', $val)) ? 'true' : 'false';
        } elseif ($format === 'name') {
            if (str_contains($val, "\0")) return 'Invalid PDF name';
        } else {
            return "Invalid PDF format '{$format}'";
        }
        return null;    // value is OK
    }

    //------------------------------------------------------------------------------
    // Format value for writing to PDF file
    // Inputs: 0) ExifTool ref, 1) value, 2) format string (string,date,integer,real,boolean,name)
    // Returns: formatted value or undef on error
    // Notes: Called at write time, so $pdfVer may be checked
    public static function WritePDFValue(ExifTool $et, $val, $format)
    {
        $format = Perl::str($format);
        if (!$format) {
            return null;
        } elseif ($format === 'string') {
            $val = Perl::str($val);
            // encode as UCS2 if it contains any special characters
            if (preg_match('/[\x80-\xff]/', $val)) $val = "\xfe\xff" . $et->Encode($val, 'UCS2', 'MM');
            self::EncodeString($val);
        } elseif ($format === 'date') {
            $val = Perl::str($val);
            // convert date to "D:YYYYmmddHHMMSS+-HH'MM'" format
            $val = preg_replace('/(:\d{2})\.\d*/', '$1', $val, 1);              // remove sub-seconds
            $val = preg_replace('/([-+]\d{2}):(\d{2})/', "\$1'\$2'", $val, 1);  // change timezone delimiters if necessary
            $val = str_replace([' ', ':'], '', $val);                           // remove spaces and colons
            $val = "D:$val";                                                    // add leading "D:"
            self::EncodeString($val);
        } elseif (preg_match('/^(integer|real|boolean)$/', $format)) {
            // no reformatting necessary
        } elseif ($format === 'name') {
            $val = Perl::str($val);
            if (str_contains($val, "\0")) return null;
            if (Perl::num(self::$pdfVer) >= 1.2) {
                $val = preg_replace_callback('/([\t\n\f\r ()<>\[\]{}\/%#])/s',
                    static fn ($m) => sprintf('#%02x', ord($m[1])), $val);
            } else {
                if (preg_match('/[\t\n\f\r ()<>\[\]{}\/%]/', $val)) return null;
            }
            $val = "/$val"; // add leading '/'
        } else {
            return null;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Encode PDF string
    // Inputs: 0) reference to PDF string
    // Returns: (updates string with encoded data)
    public static function EncodeString(&$strPt)
    {
        if (is_array($strPt)) {
            foreach ($strPt as &$str) {
                self::EncodeString($str);
            }
            return;
        }
        $strPt = Perl::str($strPt);
        self::Crypt($strPt, self::$keyExt, 1);  // encrypt if necessary
        // encode as hex if we have any control characters (except tab)
        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f\xff]/', $strPt)) {
            // encode as hex
            $str = '';
            $len = strlen($strPt);
            $i = 0;
            for (;;) {
                $n = $len - $i;
                if (!$n) break;
                if ($n > 40) $n = 40;   // break into reasonable-length lines
                if ($i) $str .= Perl::$RS;
                $str .= bin2hex(substr($strPt, $i, $n));
                $i += $n;
            }
            $strPt = "<$str>";
        } else {
            $strPt = preg_replace('/([()\\\\])/', '\\\\$1', $strPt);    // must escape round brackets and backslashes
            $strPt = "($strPt)";
        }
    }

    //------------------------------------------------------------------------------
    // Encrypt an object
    // Inputs: 0) PDF object (encrypts in place)
    // Notes: Encrypts according to "_needCrypt" dictionary entry,
    //        then deletes "_needCrypt" when done
    public static function CryptObject(&$obj)
    {
        if ($obj instanceof ScalarRef) {
            // (a reference: nothing to do)
        } elseif (!is_array($obj)) {
            // only literal strings and hex strings are encrypted
            if (is_string($obj) and preg_match('/^[(<]/', $obj)) {
                self::$lastFetched = null;  // (reset this just in case)
                $val = self::ReadPDFValue($obj);
                self::EncodeString($val);
                $obj = $val;
            }
        } elseif (!array_is_list($obj)) {
            $needCrypt = $obj['_needCrypt'] ?? null;
            foreach (array_keys($obj) as $tag) {
                if (isset(self::myDictTags[$tag])) continue;
                // re-encrypt necessary objects only (others are still encrypted)
                // (this is really annoying, but is necessary because objects stored
                // in encrypted streams are decrypted when extracting, but strings stored
                // as direct objects are decrypted later since they must be decoded
                // before being decrypted)
                if ($needCrypt) {
                    if (!(($needCrypt[$tag] ?? null) !== null ? $needCrypt[$tag] : ($needCrypt['*'] ?? null))) continue;
                }
                self::CryptObject($obj[$tag]);
            }
            unset($obj['_needCrypt']);  // avoid re-re-crypting
        } else {
            foreach ($obj as &$val) {
                self::CryptObject($val);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Get free entries from xref stream dictionary that we wrote previously
    // Inputs: 0) xref dictionary reference
    // Returns: free entry hash (keys are object numbers, values are xref entry list refs)
    public static function GetFreeEntries($dict)
    {
        $xrefFree = [];
        // we write xref stream entries in 'CNn' or 'CNNn' format (with 8-byte 'NN' offset),
        $w = $dict['W'] ?? null;
        if (is_array($w) and array_is_list($w)) {
            $bytes = implode(' ', $w);
            if ($bytes === '1 4 2') {
                $fmt = 'CNn';
            } elseif ($bytes === '1 8 2') {
                $fmt = 'CNNn';
            } else {
                return $xrefFree;
            }
            $size = $dict['_entry_size'];   // this will be 7 for 'CNn' or 11 for 'CNNn'
            $index = $dict['Index'];
            $len = strlen($dict['_stream']);
            // scan the table for free objects
            $num = count($index) / 2;
            $pos = 0;
            for ($i = 0; $i < $num; ++$i) {
                $start = $index[$i * 2];
                $count = $index[$i * 2 + 1];
                for ($j = 0; $j < $count; ++$j) {
                    if ($pos + $size > $len) break;
                    $t = Perl::unpack($fmt, substr($dict['_stream'], $pos));
                    if (count($t) == 4) {
                        $t[1] = $t[1] * 4294967296 + $t[2];
                        $t[2] = $t[3];
                        $t = [3];   // (sic: Perl sets @t = 3 here)
                    }
                    // add entry if object was free
                    if (($t[0] ?? null) == 0) $xrefFree[$start + $j] = [$t[1] ?? null, $t[2] ?? null, 'f'];
                    $pos += $size;  // step to next entry
                }
            }
        }
        return $xrefFree;
    }

    //------------------------------------------------------------------------------
    // Write PDF object
    // Inputs: 0) output file or scalar ref, 1) PDF object
    // Returns: true on success
    // Notes: inserts white space before object, but none afterward
    public static function WriteObject($outfile, &$obj)
    {
        $RS = Perl::$RS;
        if ($obj instanceof ScalarRef) {
            if (!ExifTool::Write($outfile, ' ', $obj->value)) return 0;
        } elseif (is_array($obj) and array_is_list($obj)) {
            // write array
            if (!ExifTool::Write($outfile, count($obj) > 10 ? $RS : ' ', '[')) return 0;
            foreach ($obj as &$item) {
                if (!self::WriteObject($outfile, $item)) return 0;
            }
            unset($item);
            if (!ExifTool::Write($outfile, ' ]')) return 0;
        } elseif (is_array($obj)) {
            // write dictionary
            if (!ExifTool::Write($outfile, $RS, '<<')) return 0;
            // prepare object as required if it has a stream
            if (($obj['_stream'] ?? null)) {
                // encrypt stream if necessary (must be done before determining Length)
                if ($obj['_decrypted'] ?? null) self::CryptStream($obj, self::$keyExt);
                // write "Length" entry in dictionary
                $obj['Length'] = strlen($obj['_stream']);
                $obj['_tags'][] = 'Length';
                // delete Filter-related entries since we don't yet write filtered streams
                unset($obj['Filter'], $obj['DecodeParms'], $obj['DL']);
            }
            // don't write my internal entries
            $wrote = self::myDictTags;
            // write tags in original order, adding new ones later alphabetically
            $keys = array_map('strval', array_keys($obj));
            sort($keys, SORT_STRING);
            foreach (array_merge($obj['_tags'] ?? [], $keys) as $tag) {
                $tag = (string) $tag;
                // ignore already-written or missing entries
                if (isset($wrote[$tag]) or !isset($obj[$tag])) continue;
                if (!ExifTool::Write($outfile, $RS, "/$tag")) return 0;
                if (!self::WriteObject($outfile, $obj[$tag])) return 0;
                $wrote[$tag] = 1;
            }
            if (!ExifTool::Write($outfile, $RS, '>>')) return 0;
            if ($obj['_stream'] ?? null) {
                // write object stream
                // (a single 0x0d may not follow 'stream', so use 0x0d+0x0a here to be sure)
                if (!ExifTool::Write($outfile, $RS, "stream\x0d\x0a")) return 0;
                if (!ExifTool::Write($outfile, $obj['_stream'], $RS, 'endstream')) return 0;
            }
        } else {
            // write string, number, name or object reference
            ExifTool::Write($outfile, ' ', Perl::str($obj));
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Write PDF File
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if not valid PDF file, -1 on write error
    // Notes: dictionary structure:  Main --+--> Info
    //                                      +--> Root --> Metadata
    public static function WritePDF(?ExifTool $et = null, ?array &$dirInfo = null)
    {
        if (!$et) return 1;     // (DummyWriteCall)
        $oldRS = Perl::$RS;     // (Perl: local $/ further down)
        try {
            return self::DoWritePDF($et, $dirInfo);
        } finally {
            Perl::$RS = $oldRS;
        }
    }

    private static function DoWritePDF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'];
        $capture = [];
        $newXRef = $newObj = [];
        $beginComment = self::$beginComment;
        $endComment = self::$endComment;

        // make sure this is a PDF file
        $pos = $raf->Tell();
        if ($raf->Read($buff, 1024) < 8) return 0;
        if (!preg_match('/^(\s*)%PDF-(\d+\.\d+)/', $buff, $m)) return 0;
        $et->PDFBase = strlen($m[1]);
        $raf->Seek($pos, 0);

        // create a new ExifTool object and use it to read PDF and XMP information
        $newTool = new ExifTool();
        $newTool->Options('List', 1);
        $newTool->Options('Password', $et->Options('Password'));
        $newTool->Options('NoPDFList', $et->Options('NoPDFList'));
        $newTool->PDF_CAPTURE = null;   // (declare the member before binding it by reference)
        $newTool->PDF_CAPTURE = &$capture;
        $info = $newTool->ImageInfo($raf, 'XMP', 'PDF:*', 'Error', 'Warning');
        // not a valid PDF file unless we got a version number
        $pdfVer = self::$pdfVer = $newTool->PDFVersion;
        if (!$pdfVer) {
            $et->Error('Missing PDF:PDFVersion');
            return 0;
        }
        // check version number
        if (Perl::num($pdfVer) > 2.0) {
            if ($et->Error("Writing PDF $pdfVer is untested", 1)) return 0;
        }
        // fail if we had any serious errors while extracting information
        if (($capture['Error'] ?? null) or ($info['Error'] ?? null)) {
            $et->Error(($capture['Error'] ?? null) ?: $info['Error']);
            return 1;
        }
        // make sure we have everything we need to rewrite this file
        foreach (['Main', 'Root', 'xref'] as $obj) {
            if (!empty($capture[$obj])) continue;
            // any warning we received may give a clue about why this object is missing
            if (!empty($info['Warning'])) $et->Error($info['Warning']);
            $et->Error("Can't find $obj object");
            return 1;
        }
        $et->InitWriteDirs(['XMP' => 'PDF'], 'XMP');

        // copy file up to start of previous exiftool update or end of file
        // (comment, startxref & EOF with 11-digit offsets and 2-byte newlines is 63 bytes)
        if (!($raf->Seek(-64, 2) and $raf->Read($buff, 64) and $raf->Seek(0, 0))) return -1;
        $rtn = 1;
        $prevUpdate = null;
        // (now $endComment is before "startxref", but pre-7.41 we wrote it after the EOF)
        if (preg_match('/' . $endComment . '(\d+)\s+(startxref\s+\d+\s+%%EOF\s+)?$/s', $buff, $m)) {
            $prevUpdate = $m[1];
            // rewrite the file up to the original EOF
            if (!ExifTool::CopyBlock($raf, $outfile, $prevUpdate + $et->PDFBase)) $rtn = -1;
            // verify that we are now at the start of an ExifTool update
            if (!($raf->Read($buff, strlen($beginComment)) and $buff === $beginComment)) {
                $et->Error('Previous ExifTool update is corrupted');
                return $rtn;
            }
            if (!$raf->Seek($prevUpdate + $et->PDFBase, 0)) $rtn = -1;
            if (!empty($et->DEL_GROUP['PDF-update'])) {
                $et->VPrint(0, "  Reverted previous ExifTool updates\n");
                ++$et->CHANGED;
                return $rtn;
            }
        } elseif (!empty($et->DEL_GROUP['PDF-update'])) {
            $et->Error('File contains no previous ExifTool update');
            return $rtn;
        } else {
            // rewrite the whole file
            while ($raf->Read($buff, 65536)) {
                if (!ExifTool::Write($outfile, $buff)) $rtn = -1;
            }
        }
        $out = $et->Options('Verbose') ? $et->Options('TextOut') : null;
        //
        // create our new PDF objects to write
        //
        $xref = &$capture['xref'];
        $mainDict = &$capture['Main'];
        $metaRef = $capture['Root']['Metadata'] ?? null;
        $nextObject = null;

        // start by finding reference for info object in case it was deleted
        // in a previous edit so we can re-use it here if adding PDF Info
        $prevInfoRef = null;
        if ($prevUpdate) {
            if (empty($capture['Prev'])) {
                $et->Error("Can't locate trailer dictionary prior to last edit");
                return $rtn;
            }
            $prevInfoRef = $capture['Prev']['Info'] ?? null;
            // start from previous size so the xref table doesn't continue
            // to grow if we repeatedly add and delete the Metadata object
            $nextObject = $capture['Prev']['Size'];
            // don't re-use Meta reference if object was added in a previous update
            if ($metaRef and preg_match('/^(\d+)/', $metaRef->value, $m) and $m[1] >= $nextObject) $metaRef = null;
        } else {
            $prevInfoRef = $mainDict['Info'] ?? null;
            $nextObject = $mainDict['Size'] ?? null;
        }

        // delete entire PDF group if specified
        $infoChanged = 0;
        if (!empty($et->DEL_GROUP['PDF']) and !empty($capture['Info'])) {
            unset($capture['Info']);
            $info = ['XMP' => $info['XMP'] ?? null];    // remove extracted PDF tags
            if ($out) fwrite($out, "  Deleting PDF Info dictionary\n");
            ++$infoChanged;
        }

        // create new Info dictionary if necessary
        if (empty($capture['Info'])) $capture['Info'] = ['_tags' => []];
        $infoDict = &$capture['Info'];

        // must pre-determine Info reference to be used in encryption
        $infoRef = $prevInfoRef ?: new ScalarRef("$nextObject 0 R");
        if (!($infoRef instanceof ScalarRef)) {
            $et->Error('Info dictionary is not an indirect object');
            return $rtn;
        }
        self::$keyExt = $infoRef->value;

        // must encrypt all values in dictionary if they came from an encrypted stream
        if (!empty($infoDict['_needCrypt'])) self::CryptObject($infoDict);

        // must set line separator before calling WritePDFValue()
        Perl::$RS = $capture['newline'];
        $RS = Perl::$RS;

        // rewrite PDF Info tags
        $infoTable = ExifTool::GetTagTable('Image::ExifTool::PDF::Info');
        $newTags = $et->GetNewTagInfoHash($infoTable);
        $tagIDs = array_map('strval', array_keys($newTags));
        sort($tagIDs, SORT_STRING);
        foreach ($tagIDs as $tagID) {
            $tagInfo = $newTags[$tagID];
            if (Perl::num($pdfVer) >= 2.0 and !$tagInfo->PDF2) {
                if ($et->Warn("Writing PDF:{$tagInfo->Name} is deprecated for PDF 2.0 documents", 2)) continue;
            }
            $nvHash = $et->GetNewValueHash($tagInfo);
            $vals = [];
            $deleted = null;
            $tag = $tagInfo->Name;
            $val = $info[$tag] ?? null;
            $tagKey = $tag;
            if ($val === null) {
                // must check for tag key with copy number
                $tagKey = null;
                foreach (array_keys($info) as $k) {
                    if (preg_match('/^' . $tag . '/', (string) $k)) {
                        $tagKey = $k;
                        break;
                    }
                }
                if ($tagKey !== null) $val = $info[$tagKey];
            }
            if ($val !== null) {
                $oldVals = [];
                if (is_array($val) and array_is_list($val)) {
                    $oldVals = $val;
                    $val = array_shift($oldVals);
                }
                for (;;) {
                    if ($et->IsOverwriting($nvHash, $val) > 0) {
                        $deleted = 1;
                        $et->VerboseValue("- PDF:$tag", $val);
                        ++$infoChanged;
                    } else {
                        $vals[] = $val;
                    }
                    if (!$oldVals) break;
                    $val = array_shift($oldVals);
                }
                // don't write this out if we deleted all values
                if (!$vals) unset($infoDict[$tagID]);
            } elseif ($nvHash and $nvHash->EditOnly) {
                continue;
            }
            // decide whether we want to write this tag
            // (native PDF information is always preferred, so don't check IsCreating)
            if (!($deleted or $tagInfo->List or !array_key_exists($tagID, $infoDict))) continue;

            // add new values to existing ones
            $newVals = $et->GetNewValueList($nvHash);
            if ($newVals) {
                array_push($vals, ...$newVals);
                ++$infoChanged;
                if ($out) {
                    foreach ($newVals as $v) {
                        $et->VerboseValue("+ PDF:$tag", $v);
                    }
                }
            }
            if (!$vals) {
                // remove this entry from the Info dictionary if no values remain
                unset($infoDict[$tagID]);
                continue;
            }
            // format value(s) for writing to PDF file
            $writable = $tagInfo->Writable ?: $infoTable['WRITABLE'];
            if (!$tagInfo->List) {
                $val = self::WritePDFValue($et, array_shift($vals), $writable);
            } elseif ($tagInfo->List === 'array') {
                foreach ($vals as &$v) {
                    $v = self::WritePDFValue($et, $v, $writable);
                    if ($v === null) {
                        $vals = [];
                        break;
                    }
                }
                unset($v);
                $val = $vals ?: null;
            } else {
                $val = self::WritePDFValue($et, implode($et->Options('ListSep'), $vals), $writable);
            }
            if ($val !== null) {
                $infoDict[$tagID] = $val;
                ++$infoChanged;
            } else {
                $et->Warn("Error converting {$tagInfo->Name} value");
            }
        }
        if ($infoChanged) {
            $et->CHANGED += $infoChanged;
        } elseif ($prevUpdate) {
            // must still write Info dictionary if it was previously updated
            $oldPos = self::LocateObject($xref, $infoRef->value);
            if ($oldPos and $oldPos > $prevUpdate) $infoChanged = 1;
        }

        // create new Info dictionary if necessary
        if ($infoChanged) {
            // increment object count if we used a new object here
            if (count($capture['Info']) > 1) {
                $newObj[$infoRef->value] = $capture['Info'];    // save to write later
                $mainDict['Info'] = $infoRef;                   // add reference to trailer dictionary
                if (!$prevInfoRef) ++$nextObject;
            } else {
                // remove Info from Main (trailer) dictionary
                unset($mainDict['Info']);
                // write free entry in xref table if Info existed prior to all edits
                if ($prevInfoRef) $newObj[$infoRef->value] = '';
            }
        }

        // rewrite XMP
        $xmpData = $info['XMP'] ?? null;
        $xmpInfo = [
            'DataPt' => $xmpData instanceof ScalarRef ? Perl::str($xmpData->value) : null,
            'Parent' => 'PDF',
        ];
        $xmpTable = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
        $oldChanged = $et->CHANGED;
        $newXMP = $et->WriteDirectory($xmpInfo, $xmpTable);
        if ($newXMP === null) $newXMP = $xmpData ? Perl::str($xmpData->value) : '';

        // WriteDirectory() will increment CHANGED erroneously if non-existent
        // XMP is deleted as a block -- so check for this
        if (!($newXMP or $xmpData)) {
            $et->CHANGED = $oldChanged;
            $et->VPrint(0, "  (XMP not changed -- still empty)\n");
        }
        $metaChanged = $rootChanged = null;

        if ($et->CHANGED != $oldChanged and $newXMP !== null) {
            $metaChanged = 1;
        } elseif ($prevUpdate and !empty($capture['Root']['Metadata'])) {
            // must still write Metadata dictionary if it was previously updated
            $oldPos = self::LocateObject($xref, $capture['Root']['Metadata']->value);
            if ($oldPos and $oldPos > $prevUpdate) $metaChanged = 1;
        }
        if ($metaChanged) {
            if ($newXMP) {
                if (!$metaRef) {
                    // allocate new PDF object
                    $metaRef = new ScalarRef("$nextObject 0 R");
                    ++$nextObject;
                    $capture['Root']['Metadata'] = $metaRef;
                    $rootChanged = 1;   // set flag to replace Root dictionary
                }
                // create the new metadata dictionary to write later
                $newObj[$metaRef->value] = [
                    'Type'       => '/Metadata',
                    'Subtype'    => '/XML',
                  // 'Length'   => length $newXMP, (set by WriteObject)
                    '_tags'      => ['Type', 'Subtype'],
                    '_stream'    => $newXMP,
                    '_decrypted' => 1,  // (this will be ignored if EncryptMetadata is false)
                ];
            } elseif (!empty($capture['Root']['Metadata'])) {
                // free existing metadata object
                $newObj[$capture['Root']['Metadata']->value] = '';
                unset($capture['Root']['Metadata']);
                $rootChanged = 1;       // set flag to replace Root dictionary
            }
        }
        // add new Root dictionary if necessary
        $rootRef = $mainDict['Root'] ?? null;
        if (!$rootRef) {
            $et->Error("Can't find Root dictionary");
            return $rtn;
        }
        if (!$rootChanged and $prevUpdate) {
            // must still write Root dictionary if it was previously updated
            $oldPos = self::LocateObject($xref, $rootRef->value);
            if ($oldPos and $oldPos > $prevUpdate) $rootChanged = 1;
        }
        if ($rootChanged) $newObj[$rootRef->value] = $capture['Root'];
        //
        // write incremental update if anything was changed
        //
        if ($et->CHANGED) {
            // remember position of original EOF
            $oldEOF = ExifTool::Tell($outfile) - $et->PDFBase;
            if (!ExifTool::Write($outfile, $beginComment)) $rtn = -1;

            // write new objects
            $objRefs = array_map('strval', array_keys($newObj));
            sort($objRefs, SORT_STRING);
            foreach ($objRefs as $objRef) {
                if (!preg_match('/^(\d+) (\d+)/', $objRef, $m)) {
                    $rtn = -1;
                    break;
                }
                [$id, $gen] = [(int) $m[1], (int) $m[2]];
                if (!$newObj[$objRef]) {
                    if ($gen < 65535) ++$gen;
                    // write free entry in xref table
                    $newXRef[$id] = [0, $gen, 'f'];
                    continue;
                }
                // create new entry for xref table
                $newXRef[$id] = [ExifTool::Tell($outfile) - $et->PDFBase + strlen($RS), $gen, 'n'];
                self::$keyExt = "$id $gen obj"; // (must set for stream encryption)
                if (!ExifTool::Write($outfile, $RS, self::$keyExt)) $rtn = -1;
                if (!self::WriteObject($outfile, $newObj[$objRef])) $rtn = -1;
                if (!ExifTool::Write($outfile, $RS, 'endobj')) $rtn = -1;
            }

            // Prev points to old xref table
            if (!$prevUpdate) $mainDict['Prev'] = $capture['startxref'];

            // add xref entry for head of free-object list
            $newXRef[0] = [0, 65535, 'f'];

            // must insert free xref entries from previous exiftool update if applicable
            if ($prevUpdate) {
                // extract free entries from our previous Main xref stream
                if (($mainDict['Type'] ?? null) and $mainDict['Type'] === '/XRef') {
                    $mainFree = self::GetFreeEntries($xref['dicts'][0]);
                } else {
                    // free entries from Main xref table already captured for us
                    $mainFree = $capture['mainFree'];
                }
                $freeIDs = array_keys($mainFree);
                sort($freeIDs, SORT_NUMERIC);
                foreach ($freeIDs as $id) {
                    if (empty($newXRef[$id])) $newXRef[$id] = $mainFree[$id];
                }
            }

            // connect linked list of free object in our xref table
            $prevFree = 0;
            $ids = array_keys($newXRef);
            rsort($ids, SORT_NUMERIC);  // (reverse sort)
            foreach ($ids as $id) {
                if ($newXRef[$id][2] !== 'f') continue; // skip if not free
                // no need to add free entry for objects added by us
                // in previous edits then freed again
                if ($id >= $nextObject) {
                    unset($newXRef[$id]);   // Note: deleting newXRef entry!
                    continue;
                }
                $newXRef[$id][0] = $prevFree;
                $prevFree = $id;
            }

            // prepare our main dictionary for writing
            $mainDict['Size'] = $nextObject;    // update number of objects
            // must change the ID if it exists
            if (is_array($mainDict['ID'] ?? null) and array_is_list($mainDict['ID']) and count($mainDict['ID']) > 1) {
                // increment first byte since this is an easy change to make
                $id = $mainDict['ID'][1];
                if (preg_match('/^<([0-9a-f]{2})/i', $id, $m)) {
                    $byte = sprintf('%02x', (hexdec($m[1]) + 1) & 0xff);
                    $id = substr_replace($id, $byte, 1, 2);
                } elseif (preg_match('/^\((.)/s', $id, $m) and $m[1] !== '\\' and $m[1] !== ')' and $m[1] !== '(') {
                    $ch = chr((ord($m[1]) + 1) & 0xff);
                    // avoid generating characters that could cause problems
                    if (preg_match('/[()\\\\\x00-\x08\x0a-\x1f\x7f\xff]/', $ch)) $ch = 'a';
                    $id = substr_replace($id, $ch, 1, 1);
                }
                $mainDict['ID'][1] = $id;
            }

            // remember position of xref table in file (we will write this next)
            $startxref = ExifTool::Tell($outfile) - $et->PDFBase + strlen($RS);

            // must write xref as a stream in xref-stream-only files
            if (($mainDict['Type'] ?? null) and $mainDict['Type'] === '/XRef') {

                // create entry for the xref stream object itself
                $newXRef[$nextObject++] = [ExifTool::Tell($outfile) - $et->PDFBase + strlen($RS), 0, 'n'];
                $mainDict['Size'] = $nextObject;
                // create xref stream and Index entry
                $bits = 4;
                for (;;) {  // (Perl label Restart)
                    $mainDict['W'] = [1, $bits, 2];     // int8u, int32u/int64u, int16u ('CNn' or 'CNNn')
                    $mainDict['Index'] = [];
                    $mainDict['_stream'] = '';
                    $ids = array_keys($newXRef);
                    sort($ids, SORT_NUMERIC);
                    while ($ids) {
                        $startID = $ids[0];
                        for (;;) {
                            $id = array_shift($ids);
                            [$xpos, $gen, $type] = $newXRef[$id];
                            if ($xpos > 0xffffffff) {
                                if ($bits == 4) {
                                    // switch to 64-bit integer offsets
                                    $bits = 8;
                                    continue 3;     // (next Restart)
                                }
                            }
                            if ($bits == 4) {
                                $mainDict['_stream'] .= pack('CNn', $type === 'f' ? 0 : 1, $xpos, $gen);
                            } else {
                                $hi = intdiv($xpos, 4294967296);
                                $lo = $xpos - $hi * 4294967296;
                                $mainDict['_stream'] .= pack('CNNn', $type === 'f' ? 0 : 1, $hi, $lo, $gen);
                            }
                            if (!$ids or $ids[0] != $id + 1) break;
                        }
                        // add Index entries for this section of the xref stream
                        array_push($mainDict['Index'], $startID, $id - $startID + 1);
                    }
                    break;
                }
                // write the xref stream object
                self::$keyExt = "$id 0 obj";    // (set anyway, but xref stream should NOT be encrypted)
                if (!ExifTool::Write($outfile, $RS, self::$keyExt)) $rtn = -1;
                if (!self::WriteObject($outfile, $mainDict)) $rtn = -1;
                if (!ExifTool::Write($outfile, $RS, 'endobj')) $rtn = -1;

            } else {

                // write new xref table
                if (!ExifTool::Write($outfile, $RS, 'xref', $RS)) $rtn = -1;
                // lines must be exactly 20 bytes, so pad newline if necessary
                $endl = (strlen($RS) == 1 ? ' ' : '') . $RS;
                $ids = array_keys($newXRef);
                sort($ids, SORT_NUMERIC);
                while ($ids) {
                    $startID = $ids[0];
                    $buff = '';
                    for (;;) {
                        $id = array_shift($ids);
                        $buff .= Perl::sprintf("%.10d %.5d %s%s", ...$newXRef[$id], ...[$endl]);
                        if (!$ids or $ids[0] != $id + 1) break;
                    }
                    // write this (contiguous-numbered object) section of the xref table
                    if (!ExifTool::Write($outfile, $startID, ' ', $id - $startID + 1, $RS, $buff)) $rtn = -1;
                }

                // write main (trailer) dictionary
                if (!ExifTool::Write($outfile, 'trailer')) $rtn = -1;
                if (!self::WriteObject($outfile, $mainDict)) $rtn = -1;
            }
            // write trailing comment (marker to allow edits to be reverted)
            if (!ExifTool::Write($outfile, $RS, $endComment, $oldEOF, $RS)) $rtn = -1;

            // write pointer to main xref table and EOF marker
            if (!ExifTool::Write($outfile, 'startxref', $RS, $startxref, $RS, '%%EOF', $RS)) $rtn = -1;

        } elseif ($prevUpdate) {

            // nothing new changed, so copy over previous incremental update
            if (!$raf->Seek($prevUpdate + $et->PDFBase, 0)) $rtn = -1;
            while ($raf->Read($buff, 65536)) {
                if (!ExifTool::Write($outfile, $buff)) $rtn = -1;
            }
        }
        if ($rtn > 0 and $et->CHANGED and (!empty($et->DEL_GROUP['PDF']) or !empty($et->DEL_GROUP['XMP']))) {
            $et->Warn('ExifTool PDF edits are reversible. Deleted tags may be recovered!', 1);
        }
        return $rtn;
    }
}
