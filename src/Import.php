<?php

//------------------------------------------------------------------------------
// File:         Import.pm
//
// Description:  Import CSV and JSON database files
//
// Revisions:    2011-03-05 - P. Harvey Created
//
// PHP port of Image::ExifTool::Import 1.15. Lexicals %unescapeJSON and $charset are in
// Vars\Import.
// A JSON object (Perl HASH ref) is a string-keyed array whose first key is '_ordered_keys_'
// (list of its keys in file order), a JSON array is a list; numbers, true, false and null
// are kept as their text, like Perl.
// File arguments: a file name, a RandomAccess, a file handle (Perl GLOB) or, for ReadJSON,
// a ScalarRef holding the data (Perl SCALAR ref).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\Import as Vars;

final class Import
{
    public static $VERSION = '1.15';

    //------------------------------------------------------------------------------
    // Read CSV file
    // Inputs: 0) CSV file name, file ref or RAF ref, 1) database hash ref,
    //         2) missing tag value, 3) delimiter if other than ','
    // Returns: undef on success, or error string
    // Notes: There are various flavours of CSV, but here we assume that only
    //        double quotes are escaped, and they are escaped by doubling them
    public static function ReadCSV($file, array &$database, $missingValue = null, $delim = null)
    {
        $saveRS = Perl::$RS;
        Perl::$RS = null;   // (local $/)
        try {
            $buff = $found = $err = $raf = $openedFile = $fh = null;
            $tags = [];

            if ($file instanceof RandomAccess) {
                $raf = $file;
                $file = 'CSV file';
            } elseif (is_resource($file)) {
                $raf = new RandomAccess($file);
                $file = 'CSV file';
            } else {
                $fh = @fopen((string) $file, 'rb');
                if (!$fh) return "Error opening CSV file '{$file}'";
                $openedFile = 1;
                $raf = new RandomAccess($fh);
            }
            if ($delim === null) $delim = ',';
            $delimRx = '/' . preg_quote($delim, '/') . '/';
            // set input record separator by first newline found in the file
            // (safe because first line should contain only tag names)
            while ($raf->Read($buff, 65536)) {
                if (preg_match('/(\x0d\x0a|\x0d|\x0a)/', $buff, $m)) {
                    Perl::$RS = $m[1];
                    break;
                }
            }
            $raf->Seek(0, 0);
            while ($raf->ReadLine($buff)) {
                $vals = [];
                $fileInfo = [];
                $toks = Perl::split($delimRx, $buff);
                while ($toks) {
                    $v = preg_replace('/^ +/', '', array_shift($toks));  // remove leading spaces
                    if (str_starts_with($v, '"')) {
                        $v = substr($v, 1);
                        // quoted value must end in an odd number of quotes
                        while (!preg_match('/("+)\s*$/', $v, $m) or !(strlen($m[1]) & 1)) {
                            if ($toks) {
                                $v .= $delim . array_shift($toks);
                            } else {
                                // read another line from the file
                                if (!$raf->ReadLine($buff)) break;
                                $toks = Perl::split($delimRx, $buff);
                                if (!$toks) break;
                                $v .= array_shift($toks);
                            }
                        }
                        $v = preg_replace('/"\s*$/', '', $v, 1);  // remove trailing quote and whitespace
                        $v = str_replace('""', '"', $v);           // un-escape quotes
                    } else {
                        $v = preg_replace('/[ \n\r]+$/', '', $v);  // remove trailing spaces/newlines
                    }
                    $vals[] = $v;
                }
                if ($tags) {
                    // save values for each tag
                    $fileInfo['_ordered_keys_'] = [];
                    for ($i = 0; $i < count($vals) and $i < count($tags); ++$i) {
                        // ignore empty entries unless missingValue is empty too
                        if (!(strlen($vals[$i]) or ($missingValue !== null and $missingValue === ''))) continue;
                        // delete tag (set value to undef) if value is same as missing tag
                        $fileInfo[$tags[$i]] =
                            ($missingValue !== null and $vals[$i] === Perl::str($missingValue)) ? null : $vals[$i];
                        $fileInfo['_ordered_keys_'][] = $tags[$i];
                    }
                    // figure out the file name to use
                    if (!empty($fileInfo['SourceFile'])) {
                        $database[$fileInfo['SourceFile']] = $fileInfo;
                        $found = 1;
                    }
                } else {
                    // the first row should be the tag names
                    foreach ($vals as $tag) {
                        // terminate at first blank tag name (eg. extra comma at end of line)
                        if (!strlen($tag)) break;
                        if (!$tags) $tag = preg_replace('/^\xef\xbb\xbf/', '', $tag); // remove UTF-8 BOM if it exists
                        if (!preg_match('/^([-_0-9A-Z]+:)*[-_0-9A-Z]+#?$/i', $tag)) {
                            if (str_contains($tag, "\0")) {
                                $err = "Format error in $file (doesn't look like UTF-8)";
                                break;
                            }
                            $err = "Invalid tag name '{$tag}' in $file";
                            break;
                        }
                        $tags[] = $tag;
                    }
                    if ($err) break;
                    if (!$tags) {
                        $err = "No tags found in $file";
                        break;
                    }
                    // fix "SourceFile" case if necessary
                    if (strtolower($tags[0]) === 'sourcefile') $tags[0] = 'SourceFile';
                }
            }
            if ($openedFile) fclose($fh);
            $raf = null;
            if (!($found or $err)) $err = "No SourceFile column in $file";
            return $err ?: null;
        } finally {
            Perl::$RS = $saveRS;
        }
    }

    //------------------------------------------------------------------------------
    // Convert unicode code point to UTF-8
    // Inputs: 0) integer Unicode character
    // Returns: UTF-8 bytes
    public static function ToUTF8($char)
    {
        // (when ReadJSON wasn't called first (Lytro, Trailer), Perl passes an undef $charset
        //  and Recompose then packs 2-byte code units in the current byte order, like UCS2)
        return Charset::Recompose(null, [$char], Vars::$charset ?: 'UCS2');
    }

    //------------------------------------------------------------------------------
    // Read JSON object from file
    // Inputs: 0) RAF reference or undef, 1) optional scalar reference for data
    //            to read before reading from file (ie. the file read buffer)
    //         (PHP: 2) position in the buffer: Perl pos($$buffPt), undef = start)
    // Returns: JSON object (scalar, hash ref, or array ref), or undef on EOF or
    //          empty object or array (and sets $$buffPt to empty string on EOF)
    // Notes: position in buffer is significant
    public static function ReadJSONObject($raf, &$buffPt = null, &$bpos = null)
    {
        // initialize buffer if necessary
        $pos = $readMore = $rtnVal = $tok = $key = $didBOM = $c = null;
        if ($buffPt !== null) {
            $pos = $bpos;
            if ($pos === null) $pos = $bpos = 0;
        } else {
            $buffPt = '';
            $bpos = null;
            $pos = 0;
        }
        for (;;) {      // (Perl label Tok)
            // (didn't spend the time to understand how $pos could be undef, but
            //  put a test here to be safe because one user reported this problem)
            if ($pos === null) break;
            if ($pos >= strlen($buffPt) or $readMore) {
                if ($raf === null) break;
                // read another 64kB and add to unparsed data
                $offset = strlen($buffPt) - $pos;
                if ($offset) {
                    $buff = null;
                    if (!$raf->Read($buff, 65536)) {
                        $buffPt = '';
                        break;
                    }
                    $buffPt = substr($buffPt, $pos) . $buff;
                } else {
                    if (!$raf->Read($buffPt, 65536)) {
                        $buffPt = '';
                        break;
                    }
                }
                if (!$didBOM) {
                    $buffPt = preg_replace('/^\xef\xbb\xbf/', '', $buffPt);  // remove UTF-8 BOM if it exists
                    $didBOM = 1;
                }
                $pos = $bpos = 0;
                $readMore = 0;
            }
            if (!$tok) {
                // skip white space and find next character
                if (!self::NextChar($buffPt, $bpos, $c)) {
                    $pos = strlen($buffPt);
                    continue;
                }
                $tok = $c;
                $pos = $bpos;
            }
            // see what type of object this is
            if ($tok === '{') {     // object (hash)
                if ($rtnVal === null) $rtnVal = ['_ordered_keys_' => []];
                for (;;) {
                    // read "KEY":"VALUE" pairs
                    if ($key === null) {
                        $key = self::ReadJSONObject($raf, $buffPt, $bpos);
                        $pos = $bpos;
                    }
                    // ($key may be undef for empty JSON object)
                    if ($key !== null) {
                        // scan to delimiting ':'
                        if (!self::NextChar($buffPt, $bpos, $c)) {
                            $readMore = 1;
                            continue 2;
                        }
                        if ($c !== ':') return null;    // error if not a colon
                        $val = self::ReadJSONObject($raf, $buffPt, $bpos);
                        $pos = $bpos;
                        if ($val === null) return null;
                        $key = Perl::str($key);
                        $rtnVal[$key] = $val;
                        $rtnVal['_ordered_keys_'][] = $key;
                        $key = null;
                    }
                    // scan to delimiting ',' or bounding '}'
                    if (!self::NextChar($buffPt, $bpos, $c)) {
                        $readMore = 1;
                        continue 2;
                    }
                    if ($c === '}') break;          // check for end of object
                    if ($c !== ',') return null;    // error if not a comma
                }
            } elseif ($tok === '[') {   // array
                if ($rtnVal === null) $rtnVal = [];
                for (;;) {
                    $item = self::ReadJSONObject($raf, $buffPt, $bpos);
                    $pos = $bpos;
                    // ($item may be undef for empty array)
                    if ($item !== null) $rtnVal[] = $item;
                    // scan to delimiting ',' or bounding ']'
                    if (!self::NextChar($buffPt, $bpos, $c)) {
                        $readMore = 1;
                        continue 2;
                    }
                    if ($c === ']') break;          // check for end of array
                    if ($c !== ',') return null;    // error if not a comma
                }
            } elseif ($tok === '"') {   // quoted string
                for (;;) {
                    if (!preg_match('/(\\\\*)"/', $buffPt, $m, PREG_OFFSET_CAPTURE, (int) $bpos)) {
                        $bpos = null;
                        $readMore = 1;
                        continue 2;
                    }
                    $bpos = $m[0][1] + strlen($m[0][0]);
                    if (!(strlen($m[1][0]) & 1)) break; // check for escaped quote
                }
                $rtnVal = substr($buffPt, $pos, $bpos - $pos - 1);
                // unescape characters
                $rtnVal = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', static fn ($m) => self::ToUTF8(hexdec($m[1])), $rtnVal);
                $rtnVal = preg_replace_callback('/\\\\(.)/s', static fn ($m) => (Vars::$unescapeJSON[$m[1]] ?? null) ?: $m[1], $rtnVal);
                // decode base64 (binary data) values
                if (preg_match('/^base64:[A-Za-z0-9+\/]*={0,2}$/', $rtnVal) and strlen($rtnVal) % 4 == 3) {
                    ExifTool::RequireModule('Image::ExifTool::XMP');
                    $rtnVal = XMP::DecodeBase64(substr($rtnVal, 7))->value;
                }
            } elseif ($tok === ']' or $tok === '}' or $tok === ',') {
                // return undef for empty object, array, or list item
                // (empty list item actually not valid JSON)
                $bpos = $bpos - 1;
            } else {                // number, 'true', 'false', 'null'
                if (!preg_match('/[\s:,\}\]]/', $buffPt, $m, PREG_OFFSET_CAPTURE, (int) $bpos)) {
                    $bpos = null;
                    $readMore = 1;
                    continue;
                }
                $bpos = $m[0][1];
                $rtnVal = $tok . substr($buffPt, $pos, $bpos - $pos);
            }
            break;
        }
        return $rtnVal;
    }

    // Perl: $$buffPt =~ /(\S)/g (starts at pos, sets pos after the character, or resets it
    // to undef when there is no match)
    private static function NextChar(string $buffPt, &$bpos, &$c): bool
    {
        if (!preg_match('/\S/', $buffPt, $m, PREG_OFFSET_CAPTURE, (int) $bpos)) {
            $bpos = null;
            return false;
        }
        $c = $m[0][0];
        $bpos = $m[0][1] + 1;
        return true;
    }

    //------------------------------------------------------------------------------
    // Read JSON file
    // Inputs: 0) JSON file name, file ref, RAF ref or SCALAR ref, 1) database hash ref,
    //         2) flag to delete "-" tags, 3) character set
    // Returns: undef on success, or error string
    public static function ReadJSON($file, array &$database, $missingValue = null, $chset = null)
    {
        $raf = $openedFile = $buff = $fh = null;

        // initialize character set for converting "\uHHHH" chars
        Vars::$charset = $chset ?: 'UTF8';
        if ($file instanceof RandomAccess) {
            $raf = $file;
            $file = 'JSON file';
        } elseif (is_resource($file)) {
            $raf = new RandomAccess($file);
            $file = 'JSON file';
        } elseif ($file instanceof ScalarRef) {
            $raf = new RandomAccess(Perl::str($file->value));
            $file = 'JSON in memory';
        } else {
            $fh = @fopen((string) $file, 'rb');
            if (!$fh) return "Error opening JSON file '{$file}'";
            $openedFile = 1;
            $raf = new RandomAccess($fh);
        }
        $obj = self::ReadJSONObject($raf);
        if ($openedFile) fclose($fh);
        if (!(is_array($obj) and array_is_list($obj))) {
            if (!(is_array($obj) and !array_is_list($obj))) {
                $err = "Format error in $file";
                // (Perl's seek fails on the closed file handle)
                if (!$openedFile and $raf->Seek(0, 0) and $raf->Read($buff, 64) and str_contains($buff, "\0")) {
                    $err .= " (doesn't look like UTF-8)";
                }
                return $err;
            }
            $obj = [$obj];
        }
        $found = null;
        foreach ($obj as $info) {
            if (!(is_array($info) and !array_is_list($info))) continue;
            // fix "SourceFile" case, or assume '*' if SourceFile not specified
            if (!isset($info['SourceFile'])) {
                $key = null;
                foreach (array_keys($info) as $k) {
                    if (preg_match('/^SourceFile$/i', (string) $k)) {
                        $key = $k;
                        break;
                    }
                }
                if ($key) {
                    $info['SourceFile'] = $info[$key];
                    unset($info[$key]);
                } else {
                    $info['SourceFile'] = '*';
                }
            }
            if ($missingValue !== null) {
                foreach (array_keys($info) as $k) {
                    if (Perl::str($info[$k]) === Perl::str($missingValue)) $info[$k] = null;
                }
            }
            $database[Perl::str($info['SourceFile'])] = $info;
            $found = 1;
        }
        return $found ? null : "No valid JSON objects in $file";
    }
}
