<?php

//------------------------------------------------------------------------------
// File:         PDF.pm
//
// Description:  Read PDF meta information
//
// Revisions:    07/11/2005 - P. Harvey Created
//               07/25/2005 - P. Harvey Add support for encrypted documents
//
// References:   1) http://www.adobe.com/devnet/pdf/pdf_reference.html
//               2) http://search.cpan.org/dist/Crypt-RC4/
//               3) http://www.adobe.com/devnet/acrobat/pdfs/PDF32000_2008.pdf
//               4) http://www.adobe.com/content/dam/Adobe/en/devnet/pdf/pdfs/adobe_supplement_iso32000.pdf
//               5) http://tools.ietf.org/search/rfc3454
//               6) http://www.armware.dk/RFC/rfc/rfc4013.html
//
// PHP port of Image::ExifTool::PDF 1.62 (reading only: WritePDF.pl is not ported, and the
// PDF_CAPTURE code used by the writer is left out). Tag tables are generated into
// tables/PDF.php, the file lexicals ($cryptInfo, %streamObjs, %fetched...) are in
// tables/vars/PDF.php.
// PDF objects: a dictionary is an array with string keys plus its '_tags' list (and '_stream'
// for a stream), an array is a PHP list, an indirect reference is a ScalarRef ("12 0 R").
// The xref lookup is passed by reference everywhere (LocateAnyObject caches entries in it).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class PDF
{
    use PDF\WritePDF;

    public static $VERSION = '1.62';

    // $lastFetched - last fetched object reference (used for decryption)
    //                (undefined if fetched object was already decrypted, eg. object from stream)
    public static $lastFetched = null;

    //------------------------------------------------------------------------------
    // Convert from PDF to EXIF-style date/time
    // Inputs: 0) PDF date/time string (D:YYYYmmddHHMMSS+HH'MM')
    // Returns: EXIF date string (YYYY:mm:dd HH:MM:SS+HH:MM)
    public static function ConvertPDFDate($date)
    {
        // remove optional 'D:' prefix
        $date = preg_replace('/^D:/', '', Perl::str($date), 1);
        // fill in default values if necessary
        //              YYYYmmddHHMMSS
        $default = '00000101000000';
        if (strlen($date) < strlen($default)) {
            $date .= substr($default, strlen($date));
        }
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(.*)/', $date, $m)) return $date;
        $date = "$m[1]:$m[2]:$m[3] $m[4]:$m[5]:$m[6]";
        if ($m[7]) {
            $tz = $m[7];
            if (preg_match('/^\s*Z/i', $tz)) {
                // ignore any "HH'mm'" after the Z (OS X 10.6 does this)
                $date .= 'Z';
            // tolerate some improper formatting in timezone specification
            } elseif (preg_match("/^\\s*([-+])\\s*(\\d+)[': ]+(\\d*)/", $tz, $mm)) {
                $date .= $mm[1] . $mm[2] . ':' . ($mm[3] ?: '00');
            }
        }
        return $date;
    }

    //------------------------------------------------------------------------------
    // Locate any object in the XRef tables (including compressed objects)
    // Inputs: 0) XRef reference, 1) object reference string (or free object number)
    // Returns: offset to object in file or compressed object reference string,
    //          0 if object is free, or undefined on error
    public static function LocateAnyObject(&$xref, $ref)
    {
        if (!$xref) return null;
        $ref = Perl::str($ref);
        if (array_key_exists($ref, $xref)) return $xref[$ref];
        // get the object number
        if (!preg_match('/^(\d+)/', $ref, $m)) return null;
        $objNum = $m[1];
        // return 0 if the object number has been reused (old object is free)
        if (isset($xref[$objNum])) return 0;
        //
        // scan our XRef stream dictionaries for this object
        //
        if (empty($xref['dicts'])) return null;
        foreach ($xref['dicts'] as $dict) {
            // quick check to see if the object is in the range for this xref stream
            if ($objNum >= Perl::num($dict['Size'])) continue;
            $index = $dict['Index'];
            if ($objNum < Perl::num($index[0])) continue;
            // scan the tables for the specified object
            $size = $dict['_entry_size'];
            $num = intdiv(count($index), 2);
            $tot = 0;
            for ($i = 0; $i < $num; ++$i) {
                $start = Perl::num($index[$i*2]);
                $count = Perl::num($index[$i*2+1]);
                // table is in ascending order, so quit if we have passed the object
                if ($objNum < $start) break;
                if ($objNum < $start + $count) {
                    $offset = $size * ($objNum - $start + $tot);
                    if ($offset + $size > strlen($dict['_stream'])) break;
                    $c = array_values(unpack("C$size", $dict['_stream'], $offset));
                    // extract values from this table entry
                    // (can be 1, 2, 3, 4, etc.. bytes per value)
                    $t = [];
                    $w = $dict['W'];
                    for ($j = 0; $j < 3; ++$j) {
                        // use default value if W entry is 0 (as per spec)
                        // - 0th element defaults to 1, others default to 0
                        if (!($w[$j] ?? null)) {
                            $t[$j] = ($j ? 0 : 1);
                            continue;
                        }
                        $t[$j] = array_shift($c);
                        for ($k = 1; $k < $w[$j]; ++$k) {
                            $t[$j] = 256 * $t[$j] + array_shift($c);
                        }
                    }
                    // by default, use "o g R" as the xref key
                    // (o = object number, g = generation number)
                    $ref2 = "$objNum $t[2] R";
                    if ($t[0] == 1) {
                        // normal object reference:
                        // $t[1]=offset of object from start, $t[2]=generation number
                        $xref[$ref2] = $t[1];
                    } elseif ($t[0] == 2) {
                        // compressed object reference:
                        // $t[1]=stream object number, $t[2]=index of object in stream
                        $ref2 = "$objNum 0 R";
                        $xref[$ref2] = "I$t[2] $t[1] 0 R";
                    } elseif ($t[0] == 0) {
                        // free object:
                        // $t[1]=next free object in linked list, $t[2]=generation number
                        $xref[$ref2] = 0;
                    } else {
                        // treat as a null object
                        $xref[$ref2] = null;
                    }
                    $xref[$objNum] = $t[1];    // remember offsets by object number too
                    if ($ref === $ref2) return $xref[$ref];
                    return 0;   // object is free or was reused
                }
                $tot += $count;
            }
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Locate a regular object in the XRef tables (does not include compressed objects)
    // Inputs: 0) XRef reference, 1) object reference string (or free object number)
    // Returns: offset to object in file, 0 if object is free,
    //          or undef on error or if object was compressed
    public static function LocateObject(&$xref, $ref)
    {
        $offset = self::LocateAnyObject($xref, $ref);
        if ($offset and str_starts_with((string) $offset, 'I')) return null;
        return $offset;
    }

    //------------------------------------------------------------------------------
    // Check that the correct object is located at the specified file offset
    // Inputs: 0) ExifTool ref, 1) object name, 2) object reference string, 3) file offset
    // Returns: first non-blank line at start of object, or undef on error
    public static function CheckObject(ExifTool $et, $tag, $ref, $offset)
    {
        $data = $dat = null;

        $raf = $et->RAF;
        if (!$raf->Seek($offset + $et->PDFBase, 0)) {
            $et->Warn("Bad $tag offset");
            return null;
        }
        // verify that we are reading the expected object
        $obj = preg_replace('/R/', 'obj', $ref, 1);
        for (;;) {
            if (!$raf->ReadLine($data)) {
                $et->Warn("Error reading $tag data");
                return null;
            }
            $data = preg_replace('/^' . preg_quote($obj, '/') . '/', '', $data, 1, $count);
            if ($count) break;
            if (preg_match('/^\s+$/', $data)) continue;   // keep reading if this was a blank line
            // handle cases where other whitespace characters are used in the object ID string
            while (preg_match('/^\d+(\s+\d+)?\s*$/', $data)) {
                // (PHP: stop at the end of the file instead of looping forever)
                if (!$raf->ReadLine($dat)) break;
                $data .= $dat;
            }
            $pat = str_replace(' ', '\s+', preg_quote($obj, '/'));
            $data = preg_replace("/$pat/", '', $data, 1, $count);
            if (!$count) {
                $tag = ucfirst($tag);
                $et->Warn("$tag object ($obj) not found at offset $offset");
                return null;
            }
            break;
        }
        // read the first line of data from the object (ignoring blank lines and comments)
        for (;;) {
            if (preg_match('/\S/', $data) and !preg_match('/^\s*%/', $data)) break;
            if (!$raf->ReadLine($data)) {
                $et->Warn("Error reading $tag data");
                return null;
            }
        }
        return $data;
    }

    //------------------------------------------------------------------------------
    // Fetch indirect object from file (from inside a stream if required)
    // Inputs: 0) ExifTool object reference, 1) object reference string,
    //         2) xref lookup, 3) object name (for warning messages)
    // Returns: object data or undefined on error
    // Notes: sets $lastFetched to the object reference, or undef if the object
    //        was extracted from an encrypted stream
    public static function FetchObject(ExifTool $et, $ref, &$xref, $tag)
    {
        self::$lastFetched = $ref;  // save this for decoding if necessary
        $offset = self::LocateAnyObject($xref, $ref);
        Vars\PDF::$lastOffset = $offset;
        if (!$offset) {
            if ($offset === null) $et->Warn("Bad $tag reference");
            return null;
        }
        $data = $obj = null;
        if (preg_match('/^I(\d+) /', (string) $offset, $m)) {
            $offset = substr($offset, strlen($m[0]));
            $index = $m[1]; // object index in stream
            $objNum = Perl::split(' ', $ref)[0] ?? null; // save original object number
            $ref = $offset; // now a reference to the containing stream object
            $obj = Vars\PDF::$streamObjs[$ref] ?? null;
            if (!$obj) {
                // don't try to load the same object stream twice
                if ($obj !== null) return null;
                Vars\PDF::$streamObjs[$ref] = '';
                // load the parent object stream
                $obj = self::FetchObject($et, $ref, $xref, $tag);
                // make sure it contains everything we need
                if (!(is_array($obj) and !array_is_list($obj))) return null;
                if (!(($obj['First'] ?? null) and ($obj['N'] ?? null))) return null;
                if (!self::DecodeStream($et, $obj)) return null;
                // add a special '_table' entry to this dictionary which contains
                // the list of object number/offset pairs from the stream header
                $num = Perl::num($obj['N']) * 2;
                $table = Perl::split(' ', $obj['_stream'], $num);
                if (count($table) != $num) return null;
                // remove everything before first object in stream
                $obj['_stream'] = substr($obj['_stream'], Perl::num($obj['First']));
                $table[$num-1] = preg_replace('/^(\d+).*/s', '$1', $table[$num-1]);  // trim excess from last number
                $obj['_table'] = $table;
                // save the object stream so we don't have to re-load it later
                Vars\PDF::$streamObjs[$ref] = $obj;
            }
            // verify that we have the specified object
            $i = 2 * $index;
            $table = $obj['_table'];
            if (!($index < Perl::num($obj['N']) and Perl::num($table[$i] ?? null) == Perl::num($objNum))) {
                $et->Warn("Bad index for stream object $tag");
                return null;
            }
            // extract the object at the specified index in the stream
            // (offsets in table are in sequential order, so we can subtract from
            //  the next offset to get the object length)
            $offset = Perl::num($table[$i + 1]);
            $len = Perl::num(($table[$i + 3] ?? null) ?: strlen($obj['_stream'])) - $offset;
            $data = substr($obj['_stream'], $offset, $len);
            // avoid re-decrypting data in already decrypted streams
            if (Vars\PDF::$cryptStream) self::$lastFetched = null;
            return self::ExtractObject($et, $data);
        }
        // load the start of the object
        $data = self::CheckObject($et, $tag, $ref, $offset);
        if ($data === null) return null;

        return self::ExtractObject($et, $data, $et->RAF, $xref);
    }

    //------------------------------------------------------------------------------
    // Convert PDF value to something readable
    // Inputs: 0) PDF object data
    // Returns: converted object
    public static function ReadPDFValue($str)
    {
        // decode all strings in an array
        if (is_array($str) and array_is_list($str)) {
            // create new list to not alter the original data when rewriting
            $vals = [];
            foreach ($str as $val) {
                $vals[] = self::ReadPDFValue($val);
            }
            return $vals;
        }
        // (a dictionary or reference is returned as is)
        if (!is_scalar($str) or !strlen((string) $str)) return $str;
        $delim = substr($str, 0, 1);
        if ($delim === '(') {   // literal string
            if (preg_match('/^.*?\((.*)\)/s', $str, $m)) $str = $m[1];  // remove brackets
            // decode escape sequences in literal strings
            // (same loop as PostScript::UnescapePostScript)
            $str = PostScript::UnescapeLoop($str, true);
            if (Vars\PDF::$cryptString) self::Crypt($str, self::$lastFetched);
        } elseif ($delim === '<') {     // hex string
            // decode hex data
            $str = preg_replace('/[^0-9A-Fa-f]/', '', $str);
            if (strlen($str) & 0x01) $str .= '0'; // (by the spec)
            $str = hex2bin($str);
            if (Vars\PDF::$cryptString) self::Crypt($str, self::$lastFetched);
        } elseif ($delim === '/') {     // name
            $str = substr($str, 1);
            // convert escape codes (PDF 1.2 or later)
            if (Perl::num(Vars\PDF::$pdfVer) >= 1.2) {
                $str = preg_replace_callback('/#([0-9a-f]{2})/si', static fn ($m) => chr(hexdec($m[1])), $str);
            }
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Extract PDF object from combination of buffered data and file
    // Inputs: 0) ExifTool object reference, 1) data reference,
    //         2) optional raf reference, 3) optional xref table
    // Returns: converted PDF object or undef on error
    //          a) dictionary object --> hash reference
    //          b) array object --> array reference
    //          c) indirect reference --> scalar reference
    //          d) string, name, integer, boolean, null --> scalar value
    // - updates $$dataPt on return to contain unused data
    // - creates two bogus entries ('_stream' and '_tags') in dictionaries to represent
    //   the stream data and a list of the tags (not including '_stream' and '_tags')
    //   in their original order
    public static function ExtractObject(ExifTool $et, &$dataPt, ?RandomAccess $raf = null, &$xref = null)
    {
        $tags = [];
        $data = $objData = null;
        $dict = [];
        $delim = null;

        for (;;) {
            if (preg_match('/^\s*(<{1,2}|\[|\()/s', $dataPt, $m)) {
                $delim = $m[1];
                $dataPt = ltrim($dataPt, " \t\n\r\f\v");    // remove leading white space
                $objData = self::ReadToNested($dataPt, $raf);
                if ($objData === null) return null;
                break;
            } elseif (preg_match('~^\s*(\S[^[(/<>\s]*)\s*~s', $dataPt, $m)) {
                $dataPt = substr($dataPt, strlen($m[0]));
                //
                // extract boolean, numerical, string, name, null object or indirect reference
                //
                $objData = $m[1];
                // look for an indirect reference
                if (preg_match('/^\d+$/', $objData) and preg_match('/^(\d+)\s+R/s', $dataPt, $m)) {
                    $dataPt = substr($dataPt, strlen($m[0]));
                    $objData .= "$m[1] R";
                    $objData = new ScalarRef($objData);   // return scalar reference
                }
                return $objData;    // return simple scalar or scalar reference
            }
            if (!($raf and $raf->ReadLine($data))) return null;
            $dataPt .= $data;
        }
        //
        // return literal string or hex string without parsing
        //
        if ($delim === '(' or $delim === '<') {
            return $objData;
        //
        // extract array
        //
        } elseif ($delim === '[') {
            if (!preg_match('/^.*?\[(.*)\]/s', $objData, $m)) return null;
            $data = $m[1];  // brackets removed
            $list = [];
            $dpos = 0;      // (Perl pos($data))
            for (;;) {
                if (!preg_match('~\s*(\S[^[(/<>\s]*)~s', $data, $m, PREG_OFFSET_CAPTURE, $dpos)) break;
                $dpos = $m[0][1] + strlen($m[0][0]);
                $val = $m[1][0];
                if (preg_match('/^(<{1,2}|\[|\()/', $val)) {
                    $pos = $dpos - strlen($val);
                    // nested dict, array, literal string or hex string
                    $buff = substr($data, $pos);
                    $val = self::ReadToNested($buff);
                    if ($val === null) break;
                    $dpos = $pos + strlen($val);
                    $val = self::ExtractObject($et, $val);
                } elseif (preg_match('/^\d/', $val)) {
                    if (preg_match('/\G\s+(\d+)\s+R/', $data, $mr, 0, $dpos)) {
                        $dpos += strlen($mr[0]);
                        $val = new ScalarRef("$val $mr[1] R");  // make a reference
                    }
                }
                $list[] = $val;
            }
            return $list;
        }
        //
        // extract dictionary
        //
        // Note: entries are not necessarily separated by whitespace (doh!)
        // eg) "/Tag/Name", "/Tag(string)", "/Tag[array]", etc are legal!
        // Also, they may be separated by a comment (eg. "/Tag%comment\nValue"),
        // but comments have already been removed
        $opos = 0;  // (Perl pos($objData))
        while (preg_match('~(\s*)/([^/[\]()<>{}\s]+)\s*(\S[^[(/<>\s]*)~s', $objData, $m, PREG_OFFSET_CAPTURE, $opos)) {
            $opos = $m[0][1] + strlen($m[0][0]);
            $tag = $m[2][0];
            $val = $m[3][0];
            if (preg_match('/^(<{1,2}|\[|\()/', $val)) {
                // nested dict, array, literal string or hex string
                $objData = substr($objData, $opos - strlen($val));
                $val = self::ReadToNested($objData, $raf);
                if ($val === null) break;
                $val = self::ExtractObject($et, $val);
                $opos = 0;
            } elseif (preg_match('/^\d/', $val)) {
                if (preg_match('/\G\s+(\d+)\s+R/s', $objData, $mr, 0, $opos)) {
                    $opos += strlen($mr[0]);
                    $val = new ScalarRef("$val $mr[1] R");  // make a reference
                }
            }
            $old = $dict[$tag] ?? null;
            if (is_array($old) or $old) {   // (Perl: if ($$dict{$tag}), an array ref is true)
                // duplicate dictionary entries are not allowed
                $et->Warn("Duplicate '{$tag}' entry in dictionary (ignored)");
            } else {
                // save the entry
                $tags[] = $tag;
                $dict[$tag] = $val;
            }
        }
        if (!$tags) return null;
        $dict['_tags'] = $tags;
        if (!$raf) return $dict;    // direct objects can not have streams
        //
        // extract the stream object
        //
        // dictionary must specify stream Length
        $length = $dict['Length'] ?? null;
        if (!$length) return $dict;
        if ($length instanceof ScalarRef) {
            $length = $length->value;
            $oldpos = $raf->Tell();
            // get the location of the object specifying the length
            // (compressed objects are not allowed)
            // (Perl warns 'Bad stream Length object' on a false offset, but returns first)
            $offset = self::LocateObject($xref, $length);
            if (!$offset) return $dict;
            $data = self::CheckObject($et, 'stream Length', $length, $offset);
            if ($data === null) return $dict;
            if (!preg_match('/^\s*(\d+)/', $data, $m)) {
                $et->Warn('Stream Length not found');
                return $dict;
            }
            $length = $m[1];
            $raf->Seek($oldpos, 0); // restore position to start of stream
        }
        $length = Perl::num($length);
        // extract the trailing stream data
        for (;;) {
            // find the stream token
            if (preg_match('/(\S+)/', $dataPt, $m)) {
                if ($m[1] !== 'stream') break;
                // read an extra line because it may contain our \x0a
                if ($raf->ReadLine($data)) $dataPt .= $data;
                // remove our stream header
                $dataPt = preg_replace('/^\s*stream(\x0a|\x0d\x0a)/s', '', $dataPt, 1);
                $more = $length - strlen($dataPt);
                if ($more > 0) {
                    if ($raf->Read($data, $more) != $more) {
                        $et->Warn('Error reading stream data');
                        $dataPt = '';
                        return $dict;
                    }
                    $dict['_stream'] = $dataPt . $data;
                    $dataPt = '';
                } elseif ($more < 0) {
                    $dict['_stream'] = substr($dataPt, 0, (int) $length);
                    $dataPt = substr($dataPt, (int) $length);
                } else {
                    $dict['_stream'] = $dataPt;
                    $dataPt = '';
                }
                break;
            }
            if (!$raf->ReadLine($data)) break;
            $dataPt .= $data;
        }
        return $dict;
    }

    //------------------------------------------------------------------------------
    // Read to nested delimiter
    // Inputs: 0) data reference, 1) optional raf reference
    // Returns: data up to and including matching delimiter (or undef on error)
    // - updates data reference with trailing data
    // - unescapes characters in literal strings
    // (%closingDelim lookup for matching delimiter is in tables/vars/PDF.php)
    public static function ReadToNested(&$dataPt, ?RandomAccess $raf = null)
    {
        $delim = [''];  // closing delimiter list, most deeply nested first
        $pos = 0;       // begin at start of data (Perl pos($$dataPt))
        for (;;) {
            if (!preg_match('/(\\\\*)(\(|\)|<{1,2}|>{1,2}|\[|\]|%)/', $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) {
                // must read some more data
                $buff = null;
                if (!($raf and $raf->ReadLine($buff))) break;
                $dataPt .= $buff;
                $pos = strlen($dataPt) - strlen($buff);
                continue;
            }
            $pos = $m[0][1] + strlen($m[0][0]);
            $d = $m[2][0];
            // are we in a literal string?
            if ($delim[0] === ')') {
                // ignore escaped delimiters (preceded by odd number of \'s)
                if (strlen($m[1][0]) & 0x01) continue;
                // ignore all delimiters but unescaped braces
                if (!($d === '(' or $d === ')')) continue;
            } elseif ($d === '%') {
                // ignore the comment
                $p = $pos - 1;
                // remove everything from '%' up to but not including newline
                preg_match('/.*/', $dataPt, $mm, PREG_OFFSET_CAPTURE, $pos);
                $end = $mm[0][1] + strlen($mm[0][0]);
                $dataPt = substr($dataPt, 0, $p) . substr($dataPt, $end);
                $pos = $p;
                continue;
            }
            if (isset(Vars\PDF::$closingDelim[$d])) {
                // push the corresponding closing delimiter
                array_unshift($delim, Vars\PDF::$closingDelim[$d]);
                continue;
            }
            if ($d !== $delim[0]) {
                // handle the case where we find a ">>>" and interpret it
                // as ">> >" instead of "> >>"
                if (!($d === '>>' and $delim[0] === '>')) continue;
                $pos = $pos - 1;
            }
            array_shift($delim);        // remove from nesting list
            if ($delim[0]) continue;    // keep going if we have more nested delimiters
            $buff = substr($dataPt, 0, $pos);
            $dataPt = substr($dataPt, $pos);
            return $buff;   // success!
        }
        return null;    // didn't find matching delimiter
    }

    //------------------------------------------------------------------------------
    // Decode LZW-encoded data (ref 1)
    // Inputs: 0) data reference
    // Returns: true on success and data is decoded, or false and data is untouched
    public static function DecodeLZW(&$dataPt)
    {
        if (strlen($dataPt) < 4) return 0;
        $lzw = array_map('chr', range(0, 255));     // LZW code table
        $lzw[] = null;
        $lzw[] = null;
        $mask = 0x01ff;     // mask for least-significant 9 bits
        $dat = Perl::unpack('n*', $dataPt . "\0");
        $word = ($dat[0] << 16) | $dat[1];
        [$bit, $pos, $bits, $out] = [0, 2, 9, ''];
        $lastVal = null;
        $n = count($dat);
        for (;;) {
            // bits are packed MSB first in PDF LZW (the PDF spec doesn't mention this)
            $shift = 32 - ($bit + $bits);
            if ($shift < 0) {
                if ($pos >= $n) return 0;   // missing EOD marker
                $word = (($word & 0xffff) << 16) | $dat[$pos++];    // read next word
                $bit -= 16;
                $shift += 16;
            }
            $code = ($word >> $shift) & $mask;
            $bit += $bits;
            $val = $lzw[$code] ?? null;
            if ($val !== null) {
                // store new code as previous sequence plus 1st char of new sequence
                if ($lastVal !== null) $lzw[] = $lastVal . $val[0];
            } elseif ($code == count($lzw)) {   // new code
                if ($lastVal === null) return 0;
                // we are using the code that we are about to generate, so the last
                // character in the new sequence must be the same as the first
                // character in the previous sequence (makes sense if you think about it)
                $val = $lastVal . $lastVal[0];
                $lzw[] = $val;
            } elseif ($code == 256) {   // clear table
                array_splice($lzw, 258);
                $bits = 9;
                $mask = 0x1ff;
                $lastVal = null;
                continue;
            } elseif ($code == 257) {   // EOD marker
                break;  // all done!
            } else {
                return 0;
            }
            $out .= $val;   // add this byte sequence to the output
            // we added a new entry to the LZW table, so we must increase
            // the bit width if necessary, up to a maximum of 12
            if (count($lzw) >= $mask and $bits < 12) {
                ++$bits;
                $mask |= $mask << 1;
            }
            $lastVal = $val;
        }
        $dataPt = $out;     // return decompressed data
        return 1;
    }

    //------------------------------------------------------------------------------
    // Decode filtered stream
    // Inputs: 0) ExifTool object reference, 1) dictionary reference
    // Returns: true if stream has been decoded OK
    public static function DecodeStream(ExifTool $et, array &$dict)
    {
        if (!($dict['_stream'] ?? null)) return 0;  // no stream to decode

        // get list of filters
        $filters = $decodeParms = [];
        $f = $dict['Filter'] ?? null;
        if (is_array($f) and array_is_list($f)) {
            $filters = $f;
        } elseif ($f !== null) {
            $filters = [$f];
        }
        // be sure we can process all the filters before we take the time to do the decryption
        foreach ($filters as $filter) {
            if (is_string($filter) and !empty(Vars\PDF::$supportedFilter[$filter])) continue;
            $et->Warn('Unsupported Filter ' . (is_string($filter) ? $filter : 'ref'));
            return 0;
        }
        // apply decryption first if required (and if the default encryption
        // has not been overridden by a Crypt filter. Note: the Crypt filter
        // must be first in the Filter array: ref 3, page 38)
        if (!(isset($dict['_decrypted']) or (($filters[0] ?? null) and $filters[0] === '/Crypt'))) {
            self::CryptStream($dict, self::$lastFetched);
        }
        if (!(is_array($f) or $f)) return 1;            // Filter entry is mandatory
        if (isset($dict['_filtered'])) return 0;        // avoid double-filtering
        $dict['_filtered'] = 1;                         // set flag to prevent double-filtering

        // get array of DecodeParms dictionaries
        $dp = $dict['DecodeParms'] ?? null;
        if (is_array($dp) and array_is_list($dp)) {
            $decodeParms = $dp;
        } else {
            $decodeParms = [$dp];
        }
        $isHash = static fn ($v) => is_array($v) and !array_is_list($v);

        foreach ($filters as $filter) {
            $decodeParms = array_values($decodeParms);
            $decodeParms_ = array_shift($decodeParms);

            if ($filter === '/FlateDecode') {
                // make sure we support the predictor (if used) before decoding
                $pre = null;
                if ($isHash($decodeParms_)) {
                    $pre = $decodeParms_['Predictor'] ?? null;
                    if ($pre and $pre !== '1' and $pre !== '12') {
                        $et->Warn('FlateDecode Predictor ' . Perl::str($pre) . ' currently not supported');
                        return 0;
                    }
                }
                if (function_exists('inflate_init')) {     // (Perl: require Compress::Zlib)
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    $buff = $inflate ? @inflate_add($inflate, $dict['_stream'], ZLIB_SYNC_FLUSH) : false;
                    if ($inflate and $buff !== false and inflate_get_status($inflate) == ZLIB_STREAM_END) {
                        $dict['_stream'] = $buff;
                    } else {
                        $et->Warn('Error inflating stream');
                        return 0;
                    }
                } else {
                    $et->Warn('Install Compress::Zlib to process filtered streams');
                    return 0;
                }
                if (!($pre and $pre === '12')) continue;    // 12 = 'up' prediction

                // apply anti-predictor
                $cols = Perl::num($decodeParms_['Columns'] ?? null);
                if (!$cols) {
                    // currently only support 'up' prediction
                    $et->Warn('No Columns for decoding stream');
                    return 0;
                }
                $bytes = unpack('C*', $dict['_stream']);    // (1-based)
                $nb = count($bytes);
                $pre = array_fill(0, (int) $cols, 0);       // initialize predictor array
                $buff = '';
                for ($p = 1; $nb - $p + 1 > $cols; ) {
                    $b = $bytes[$p++];
                    if ($b != 2) {
                        $et->Warn("Unsupported PNG filter $b"); // (yes, PNG)
                        return 0;
                    }
                    foreach ($pre as &$v) {
                        $v = ($v + ($bytes[$p++] ?? 0)) & 0xff;
                    }
                    unset($v);
                    $buff .= pack('C*', ...$pre);
                }
                $dict['_stream'] = $buff;

            } elseif ($filter === '/Crypt') {

                // (we shouldn't have to check the _decrypted flag since we
                //  already checked the _filtered flag, but what the heck...)
                if (isset($dict['_decrypted'])) continue;
                // assume Identity filter (the default) if DecodeParms are missing
                if (!$isHash($decodeParms_)) continue;
                $name = $decodeParms_['Name'] ?? null;
                if (!($name !== null or $name === 'Identity')) continue;
                if ($name !== 'StdCF') {
                    $et->Warn('Unsupported Crypt Filter ' . Perl::str($name));
                    return 0;
                }
                if (!Vars\PDF::$cryptInfo) {
                    $et->Warn('Missing Encrypt StdCF entry');
                    return 0;
                }
                // decrypt the stream manually because we want to:
                // 1) ignore $cryptStream (StmF) setting
                // 2) ignore EncryptMetadata setting (I can't find mention of how to
                //    reconcile this in the spec., but this would make sense)
                // 3) avoid adding the crypt key extension (ref 3, page 58, Algorithm 1b)
                // 4) set _decrypted flag so we will recrypt according to StmF when
                //    writing (since we don't yet write Filter'd streams)
                self::Crypt($dict['_stream'], 'none');
                $dict['_decrypted'] = (Vars\PDF::$cryptStream ? 1 : 0);

            } elseif ($filter === '/LZWDecode') {

                // make sure we don't have any unsupported decoding parameters
                if ($isHash($decodeParms_)) {
                    if ($decodeParms_['Predictor'] ?? null) {
                        $et->Warn('LZWDecode Predictor ' . Perl::str($decodeParms_['Predictor']) . ' currently not supported');
                        return 0;
                    } elseif ($decodeParms_['EarlyChange'] ?? null) {
                        $et->Warn('LZWDecode EarlyChange currently not supported');
                        return 0;
                    }
                }
                if (!self::DecodeLZW($dict['_stream'])) {
                    $et->Warn('LZW decompress error');
                    return 0;
                }

            } elseif ($filter === '/ASCIIHexDecode') {

                $dict['_stream'] = preg_replace('/>.*/', '', $dict['_stream'], 1);   // truncate at '>' (end of data mark)
                $dict['_stream'] = preg_replace('/[0-9a-zA-Z]/', '', $dict['_stream']); // remove illegal characters
                $dict['_stream'] = Perl::pack('H*', $dict['_stream']);

            } elseif ($filter === '/ASCII85Decode') {

                $err = null;
                $out = [];
                [$n, $val] = [0, 0];
                foreach (str_split($dict['_stream']) as $ch) {
                    if ($ch >= '!' and $ch <= 'u') {
                        $val = 85 * $val + ord($ch) - 33;
                        if (++$n != 5) continue;
                    } elseif ($ch === '~') {
                        if ($n == 1) $err = 1;  // error to have a single char in the last group of 5
                        for ($i = $n; $i < 5; ++$i) { $val *= 85; }
                    } elseif ($ch === 'z') {
                        if ($n) {               // error if 'z' isn't the first char
                            $err = 2;
                            break;
                        }
                        $n = 5;
                    } else {
                        if (preg_match('/^\s$/', $ch)) continue;    // ignore white space
                        $err = 3;               // any other character is an error
                        break;
                    }
                    $val = unpack('V', pack('N', $val))[1];   // reverse byte order
                    while (--$n > 0) {
                        $out[] = $val & 0xff;
                        $val >>= 8;
                    }
                    if ($ch === '~') break;
                    // (both $n and $val are zero again now)
                }
                if ($err) $et->Warn("ASCII85Decode error $err");
                $dict['_stream'] = $out ? pack('C*', ...$out) : '';
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Initialize state for RC4 en/decryption (ref 2)
    // Inputs: 0) RC4 key string
    // Returns: RC4 key hash reference
    public static function RC4Init($key)
    {
        $key = array_values(unpack('C*', $key));
        $state = range(0, 255);
        [$i, $j] = [0, 0];
        $n = count($key);
        while ($i < 256) {
            $st = $state[$i];
            $j = ($j + $st + $key[$i % $n]) & 0xff;
            $state[$i++] = $state[$j];
            $state[$j] = $st;
        }
        return ['State' => $state, 'XY' => [0, 0]];
    }

    //------------------------------------------------------------------------------
    // Apply RC4 en/decryption (ref 2)
    // Inputs: 0) data reference, 1) RC4 key hash reference or RC4 key string
    // - can call this method directly with a key string, or with with the key
    //   reference returned by RC4Init
    // - RC4 is a symmetric algorithm, so encryption is the same as decryption
    // (PHP: a key hash is passed by value, so its state isn't updated for the caller)
    public static function RC4Crypt(&$dataPt, $key)
    {
        if (!is_array($key)) $key = self::RC4Init($key);
        $state = $key['State'];
        [$x, $y] = $key['XY'];

        $data = (string) $dataPt;
        $len = strlen($data);
        for ($p = 0; $p < $len; ++$p) {
            $x = ($x + 1) & 0xff;
            $stx = $state[$x];
            $y = ($stx + $y) & 0xff;
            $sty = $state[$x] = $state[$y];
            $state[$y] = $stx;
            $data[$p] = chr(ord($data[$p]) ^ $state[($stx + $sty) & 0xff]);
        }
        $dataPt = $data;
    }

    //------------------------------------------------------------------------------
    // Update AES cipher with a bit of data
    // Inputs: 0) data
    // Returns: encrypted data
    public static function CipherUpdate($dat)
    {
        $pos = 0;
        if (strlen($dat)) $dat = Vars\PDF::$cipherMore . $dat;
        $out = '';
        while ($pos + 16 <= strlen($dat)) {
            $out .= AES::Cipher(substr($dat, $pos, 16));
            $pos += 16;
        }
        if ($pos < strlen($dat)) {
            Vars\PDF::$cipherMore = substr($dat, $pos);
        } else {
            Vars\PDF::$cipherMore = '';
        }
        return $out;
    }

    //------------------------------------------------------------------------------
    // Get encrypted hash
    // Inputs: 0) Password, 1) salt, 2) vector, 3) encryption revision
    // Returns: hash
    public static function GetHash($password, $salt, $vector, $rev)
    {
        // return Rev 5 hash
        if ($rev == 5) return hash('sha256', $password . $salt . $vector, true);

        // compute Rev 6 hardened hash
        // (ref http://code.google.com/p/origami-pdf/source/browse/lib/origami/encryption.rb)
        $blockSize = 32;
        $input = hash('sha256', $password . $salt . $vector, true) . str_repeat("\0", 32);
        $key = substr($input, 0, 16);
        $iv = substr($input, 16, 16);
        $h = null;
        $x = '';
        $i = 0;
        while ($i < 64 or $i < ord(substr($x, -1, 1)) + 32) {

            $block = substr($input, 0, $blockSize);
            $x = '';
            AES::Crypt($x, $key, $iv, 1);
            Vars\PDF::$cipherMore = '';

            $digest = null;
            for ($j = 0; $j < 64; ++$j) {
                $x = '';
                if (strlen($password)) $x .= self::CipherUpdate($password);
                $x .= self::CipherUpdate($block);
                if (strlen($vector)) $x .= self::CipherUpdate($vector);
                if ($j == 0) {
                    $sum = array_sum(unpack('C16', $x));
                    // set SHA block size (32, 48 or 64 bytes = SHA-256, 384 or 512)
                    $blockSize = 32 + ($sum % 3) * 16;
                    $digest = hash_init('sha' . ($blockSize * 8));
                }
                hash_update($digest, $x);
            }

            $h = hash_final($digest, true);
            $key = substr($h, 0, 16);
            $input = $h . substr($input, 16);   // (Perl: substr($input,0,16) = $h)
            $iv = substr($h, 16, 16);
            ++$i;
        }
        return substr($h, 0, 32);
    }

    //------------------------------------------------------------------------------
    // Initialize decryption
    // Inputs: 0) ExifTool object reference, 1) Encrypt dictionary reference,
    //         2) ID from file trailer dictionary
    // Returns: error string or undef on success (and sets $cryptInfo)
    public static function DecryptInit(ExifTool $et, $encrypt, $id)
    {
        Vars\PDF::$cryptInfo = null;
        if (!(is_array($encrypt) and !array_is_list($encrypt))) {
            return 'Error loading Encrypt object';
        }
        $filt = $encrypt['Filter'] ?? null;
        if (!(is_string($filt) and $filt !== '' and $filt[0] === '/')) {
            return 'Encrypt dictionary has no Filter!';
        }
        $filt = substr($filt, 1);
        // extract some interesting tags
        $ver = ($encrypt['V'] ?? null) ?: 0;
        $rev = ($encrypt['R'] ?? null) ?: 0;
        $enc = "$filt V$ver";
        if ($filt === 'Standard') $enc .= ".$rev";
        if (($encrypt['SubFilter'] ?? null) and preg_match('/^\/(.*)/', Perl::str($encrypt['SubFilter']), $m)) $enc .= " ($m[1])";
        if ($filt === 'Standard') $enc .= ' (' . Perl::str(($encrypt['Length'] ?? null) ?: 40) . '-bit)';
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PDF::Encrypt');
        $et->HandleTag($tagTablePtr, 'Filter', $enc);
        if ($filt !== 'Standard') {
            return "Encryption filter $filt currently not supported";
        } elseif (!isset($encrypt['R'])) {
            return 'Standard security handler missing revision';
        }
        if (!(($encrypt['O'] ?? null) and ($encrypt['P'] ?? null) and ($encrypt['U'] ?? null))) {
            return 'Incomplete Encrypt specification';
        }
        if (Perl::num("$ver.$rev") >= 5.6) {
            // apologize for poor performance (AES is a pure Perl implementation)
            $et->Warn('Decryption is very slow for encryption V5.6 or higher', 3);
        }
        $et->HandleTag($tagTablePtr, 'P', $encrypt['P']);

        $parm = [];     // optional parameters extracted from Encrypt dictionary

        if ($ver == 1 or $ver == 2) {
            Vars\PDF::$cryptString = Vars\PDF::$cryptStream = 1;
        } elseif ($ver == 4 or $ver == 5) {
            // initialize our $cryptString and $cryptStream flags
            foreach (['StrF', 'StmF'] as $f) {
                if ($f === 'StrF') {
                    $flagPt = &Vars\PDF::$cryptString;
                } else {
                    $flagPt = &Vars\PDF::$cryptStream;
                }
                $flagPt = $encrypt[$f] ?? null;
                if ($flagPt and $flagPt === '/Identity') $flagPt = null;
                if ($flagPt and $flagPt !== '/StdCF') return "Unsupported $f encryption " . Perl::str($flagPt);
                unset($flagPt);
            }
            if (Vars\PDF::$cryptString or Vars\PDF::$cryptStream) {
                $cf = $encrypt['CF'] ?? null;
                $stdCF = (is_array($cf) and !array_is_list($cf)) ? ($cf['StdCF'] ?? null) : null;
                if (!(is_array($stdCF) and !array_is_list($stdCF) and ($stdCF['CFM'] ?? null))) {
                    return 'Missing or invalid Encrypt StdCF entry';
                }
                $cryptMeth = Perl::str($stdCF['CFM']);
                if (!preg_match('/^\/(V2|AESV2|AESV3)$/', $cryptMeth)) {
                    return "Unsupported encryption method $cryptMeth";
                }
                // set "_aesv2" or "_aesv3" flag in %$encrypt hash if AES encryption was used
                if (preg_match('/^\/(AESV2|AESV3)$/', $cryptMeth, $m)) $encrypt['_' . strtolower($m[1])] = 1;
            }
            if ($ver == 5) {
                // validate OE and UE entries
                foreach (['OE', 'UE'] as $f) {
                    if (!($encrypt[$f] ?? null)) return "Missing Encrypt $f entry";
                    $parm[$f] = self::ReadPDFValue($encrypt[$f]);
                    if (!(is_string($parm[$f]) and strlen($parm[$f]) == 32)) return "Invalid Encrypt $f entry";
                }
            }
        } else {
            return "Encryption version $ver currently not supported";
        }
        if (!$id) return "Can't decrypt (no document ID)";

        // (Perl: make sure Digest::MD5 or Digest::SHA is available -- PHP has md5() and hash())

        // calculate file-level en/decryption key
        $pad = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08" .
               "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
        $o = Perl::str(self::ReadPDFValue($encrypt['O']));
        $u = Perl::str(self::ReadPDFValue($encrypt['U']));

        // set flag indicating whether metadata is encrypted
        // (in version 4 and higher, metadata streams may not be encrypted)
        $em = $encrypt['EncryptMetadata'] ?? null;
        if ($ver < 4 or !$em or !preg_match('/false/i', Perl::str($em))) {
            $encrypt['_meta'] = 1;
        }
        // try no password first, then try provided password if available
        $key = null;
        for ($try = 0; ; ++$try) {
            if ($try == 0) {
                $password = '';
            } elseif ($try == 1) {
                $password = $et->Options('Password');
                if ($password === null) return 'Document is password protected (use Password option)';
                // (Perl removes the UTF-8 flag from the password: PHP strings are bytes)
                $password = Perl::str($password);
            } else {
                return 'Incorrect password';
            }
            if ($ver < 5) {
                if (strlen($password)) {
                    // password must be encoding in PDFDocEncoding (ref iso32000)
                    $password = $et->Encode($password, 'PDFDoc');
                    // truncate or pad the password to exactly 32 bytes
                    if (strlen($password) > 32) {
                        $password = substr($password, 0, 32);
                    } elseif (strlen($password) < 32) {
                        $password .= substr($pad, 0, 32 - strlen($password));
                    }
                } else {
                    $password = $pad;
                }
                $key = $password . $o . pack('V', (int) Perl::num($encrypt['P'])) . $id;
                $rep = 1;
                if ($rev == 3 or $rev == 4) {
                    // must add this if metadata not encrypted
                    if (empty($encrypt['_meta'])) $key .= "\xff\xff\xff\xff";
                    $rep += 50; // repeat MD5 50 more times if revision is 3 or greater
                }
                if ($ver == 1) {
                    $len = 5;
                } else {
                    $len = Perl::num(($encrypt['Length'] ?? null) ?: 40);
                    if (!($len >= 40)) return 'Bad Encrypt Length';
                    $len = Perl::int($len / 8);
                }
                for ($i = 0; $i < $rep; ++$i) {
                    $key = substr(md5($key, true), 0, $len);
                }
                // decrypt U to see if a user password is required
                if ($rev >= 3) {
                    $dat = md5($pad . $id, true);
                    self::RC4Crypt($dat, $key);
                    for ($i = 1; $i <= 19; ++$i) {
                        $k = $key;
                        for ($j = 0; $j < strlen($k); ++$j) $k[$j] = chr(ord($k[$j]) ^ $i);
                        self::RC4Crypt($dat, $k);
                    }
                    $dat .= substr($u, 16);
                } else {
                    $dat = $pad;
                    self::RC4Crypt($dat, $key);
                }
                if ($dat === $u) break; // all done if this was the correct key
            } else {
                if (strlen($o) < 48 or strlen($u) < 48) return 'Invalid O or U Encrypt entries';
                if (strlen($password)) {
                    // Note: this should be good for passwords containing reasonable characters,
                    // but to be bullet-proof we need to apply the SASLprep (IETF RFC 4013) profile
                    // of stringprep (IETF RFC 3454) to the password before encoding in UTF-8
                    $password = $et->Encode($password, 'UTF8');
                    if (strlen($password) > 127) $password = substr($password, 0, 127);
                }
                // test for the owner password
                $sha = self::GetHash($password, substr($o, 32, 8), substr($u, 0, 48), $rev);
                if ($sha === substr($o, 0, 32)) {
                    $key = self::GetHash($password, substr($o, 40, 8), substr($u, 0, 48), $rev);
                    $dat = str_repeat("\0", 16) . $parm['OE'];
                    // decrypt with no padding
                    $err = AES::Crypt($dat, $key, 0, 1);
                    if ($err) return $err;
                    $key = $dat;    // use this as the file decryption key
                    break;
                }
                // test for the user password
                $sha = self::GetHash($password, substr($u, 32, 8), '', $rev);
                if ($sha === substr($u, 0, 32)) {
                    $key = self::GetHash($password, substr($u, 40, 8), '', $rev);
                    $dat = str_repeat("\0", 16) . $parm['UE'];
                    $err = AES::Crypt($dat, $key, 0, 1);
                    if ($err) return $err;
                    $key = $dat;    // use this as the file decryption key
                    break;
                }
            }
        }
        $encrypt['_key'] = $key;            // save the file-level encryption key
        Vars\PDF::$cryptInfo = $encrypt;    // save reference to the file-level Encrypt object
        return null;                        // success!
    }

    //------------------------------------------------------------------------------
    // Decrypt/Encrypt data
    // Inputs: 0) data ref
    //         1) PDF object reference to use as crypt key extension (may be 'none' to
    //            avoid extending the encryption key, as for streams with Crypt Filter)
    //         2) encrypt flag (false for decryption)
    public static function Crypt(&$dataPt, $keyExt, $encrypt = null)
    {
        if (!Vars\PDF::$cryptInfo) return;
        $cryptInfo = &Vars\PDF::$cryptInfo;
        // do not decrypt if the key extension object is undefined
        // (this doubles as a flag to disable decryption/encryption)
        if ($keyExt === null) return;
        $key = $cryptInfo['_key'];
        // apply the necessary crypt key extension
        if (empty($cryptInfo['_aesv3'])) {
            if ($keyExt !== 'none') {
                // extend crypt key using object and generation number
                if (!preg_match('/^(I\d+ )?(\d+) (\d+)/', Perl::str($keyExt), $m)) {
                    $cryptInfo['_error'] = 'Invalid object reference for encryption';
                    return;
                }
                $key .= substr(pack('V', (int) $m[2]), 0, 3) . substr(pack('V', (int) $m[3]), 0, 2);
            }
            // add AES-128 salt if necessary (this little gem is conveniently
            // omitted from the Adobe PDF 1.6 documentation, causing me to
            // waste 12 hours trying to figure out why this wasn't working --
            // it appears in ISO32000 though, so I should have been using that)
            if (!empty($cryptInfo['_aesv2'])) $key .= 'sAlT';
            $len = strlen($key);
            $key = md5($key, true);                     // get 16-byte MD5 digest
            if ($len < 16) $key = substr($key, 0, $len); // trim if necessary
        }
        // perform the decryption/encryption
        if (!empty($cryptInfo['_aesv2']) or !empty($cryptInfo['_aesv3'])) {
            $err = AES::Crypt($dataPt, $key, $encrypt);
            if ($err) $cryptInfo['_error'] = $err;
        } else {
            self::RC4Crypt($dataPt, $key);
        }
    }

    //------------------------------------------------------------------------------
    // Decrypt/Encrypt stream data
    // Inputs: 0) dictionary ref, 1) PDF object reference to use as crypt key extension
    public static function CryptStream(array &$dict, $keyExt)
    {
        if (!Vars\PDF::$cryptStream) return;
        $type = ($dict['Type'] ?? null) ?: '';
        // XRef streams are not encrypted (ref 3, page 50),
        // and Metadata may or may not be encrypted
        if (Vars\PDF::$cryptInfo and $type !== '/XRef' and
            (!empty(Vars\PDF::$cryptInfo['_meta']) or $type !== '/Metadata'))
        {
            self::Crypt($dict['_stream'], $keyExt, $dict['_decrypted'] ?? null);
            // toggle _decrypted flag
            $dict['_decrypted'] = (!empty($dict['_decrypted']) ? null : 1);
        } else {
            $dict['_decrypted'] = 0;    // stream should never be encrypted
        }
    }

    //------------------------------------------------------------------------------
    // Generate a new PDF tag (based on its ID) and add it to a tag table
    // Inputs: 0) tag table ref, 1) tag ID
    // Returns: tag info ref
    public static function NewPDFTag(TagTable $tagTablePtr, $tag)
    {
        $name = Perl::str($tag);
        // translate URL-like escape sequences
        $name = preg_replace_callback('/#([0-9a-f]{2})/i', static fn ($m) => chr(hexdec($m[1])), $name);
        $name = preg_replace('/[^-\w]+/', '_', $name);     // translate invalid characters to an underline
        $name = preg_replace_callback('/(^|_)([a-z])/', static fn ($m) => strtoupper($m[2]), $name); // start words with upper case
        return ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
    }

    //------------------------------------------------------------------------------
    // Process AcroForm dictionary to set HasXMLFormsArchitecture flag
    // Inputs: Same as ProcessDict
    public static function ProcessAcroForm(ExifTool $et, TagTable $tagTablePtr, $dict, &$xref, $nesting = null, $type = null)
    {
        $xfa = $dict['XFA'] ?? null;
        $et->HandleTag($tagTablePtr, '_has_xfa', (is_array($xfa) or $xfa) ? 'true' : 'false');
        if (!$et->Options('Verbose')) return 1;
        return self::ProcessDict($et, $tagTablePtr, $dict, $xref, $nesting, $type);
    }

    //------------------------------------------------------------------------------
    // Process AF dictionary to extract C2PA manifest
    // Inputs: Same as ProcessDict
    public static function ProcessAF(ExifTool $et, TagTable $tagTablePtr, $dict, &$xref, $nesting = null, $type = null)
    {
        $et->AFRelationship = ($dict['AFRelationship'] ?? null) ?: '';
        // go no further unless Verbose or this is the C2PA_Manifest item
        if (!($et->Options('Verbose') or $et->AFRelationship === '/C2PA_Manifest')) return 1;
        return self::ProcessDict($et, $tagTablePtr, $dict, $xref, $nesting, $type);
    }

    //------------------------------------------------------------------------------
    // Expand array into a string
    // Inputs: 0) array ref
    // Return: string
    public static function ExpandArray($val)
    {
        $list = $val;
        foreach ($list as &$v) {
            if ($v instanceof ScalarRef) {
                $v = "ref($v->value)";
            } elseif (is_array($v) and array_is_list($v)) {
                $v = self::ExpandArray($v);
            } elseif ($v === null) {
                $v = '<undef>';
            } elseif (is_array($v)) {
                $v = 'HASH';    // (Perl prints the HASH(0x...) reference)
            }
        }
        unset($v);
        return '[' . implode(',', $list) . ']';
    }

    //------------------------------------------------------------------------------
    // Process PDF dictionary extract tag values
    // Inputs: 0) ExifTool object reference, 1) tag table reference
    //         2) dictionary reference, 3) cross-reference table reference,
    //         4) nesting depth, 5) dictionary capture type
    public static function ProcessDict(ExifTool $et, TagTable $tagTablePtr, $dict, &$xref, $nesting = null, $type = null)
    {
        $verbose = $et->Options('Verbose');
        $unknown = $tagTablePtr['EXTRACT_UNKNOWN'];
        $embedded = ($unknown !== null and !$unknown and $et->Options('ExtractEmbedded'));
        $tags = $dict['_tags'];
        $next = $validInfo = null;
        $join = [];
        $index = 0;

        $nesting = ($nesting ?: 0) + 1;
        if ($nesting > 50) {
            $et->Warn('Nesting too deep (directory ignored)');
            return null;
        }
        // (PDF_CAPTURE: the entire dictionary is saved here for rewriting -- writer only)
        $validInfo = ($et->Options('Validate') and $tagTablePtr['TABLE_NAME'] === 'Image::ExifTool::PDF::Info');
        $fetched = &Vars\PDF::$fetched;
        $isHash = static fn ($v) => is_array($v) and !array_is_list($v);
        $isList = static fn ($v) => is_array($v) and array_is_list($v);
        //
        // extract information from all tags in the dictionary
        //
        for (;;) {
            $isSubDoc = null;
            if ($tags) {
                $tag = array_shift($tags);
            } elseif ($next !== null and !$next) {
                $tag = 'Next';
                $next = 1;
            } else {
                break;
            }
            $tag = (string) $tag;
            $val = $dict[$tag] ?? null;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo) {
                if ($tagInfo->NoProcess) $tagInfo = null;
            } elseif ($embedded and preg_match('/^(.*?)(\d+)$/', $tag, $m) and
                $tagTablePtr[$m[1]] and (!($val instanceof ScalarRef) or empty($fetched[$val->value])))
            {
                [$name, $num] = [$m[1], $m[2]];
                $tagInfo = $et->GetTagInfo($tagTablePtr, $name);
                if ($tagInfo instanceof TagInfo and $tagInfo->JoinStreams) {
                    $ref = $val instanceof ScalarRef ? $val->value : null;
                    $fetched[Perl::str($ref)] = 1;
                    $obj = self::FetchObject($et, $ref, $xref, $tag);
                    if (!isset($join[$name])) $join[$name] = [];
                    if (!($isHash($obj) and ($obj['_stream'] ?? null))) continue;
                    // save all the stream data to join later
                    self::DecodeStream($et, $obj);
                    $join[$name][(int) $num] = $obj['_stream'];
                    $tagInfo = null;    // don't process
                } else {
                    $isSubDoc = 1;  // treat as a sub-document
                }
            }
            if ($validInfo and Perl::num($et->PDFVersion) >= 2.0 and (!$tagInfo or !$tagInfo->PDF2)) {
                $name = $tagInfo ? ":{$tagInfo->Name}" : " Info tag '{$tag}'";
                $et->Warn("PDF$name is deprecated in PDF 2.0");
            }
            if ($verbose) {
                $val2 = $extra = null;
                if ($val instanceof ScalarRef) {
                    $extra = ", indirect object ({$val->value})";
                    if (!empty($fetched[$val->value])) {
                        $val2 = "ref({$val->value})";
                    } elseif ($tag === 'Next' and !$next) {
                        // handle 'Next' links after all others
                        $next = 0;
                        continue;
                    } else {
                        $fetched[$val->value] = 1;
                        $val = self::FetchObject($et, $val->value, $xref, $tag);
                        if ($val === null) {
                            if (Vars\PDF::$lastOffset !== null) {
                                $val2 = '<free>';
                                $str = 'Object was freed';
                            } else {
                                $val2 = '<err>';
                                $str = 'Error reading object';
                            }
                            $et->VPrint(0, "{$et->INDENT}{$str}:\n");
                        }
                    }
                } elseif ($isHash($val)) {
                    $extra = ', direct dictionary';
                } elseif ($isList($val)) {
                    $extra = ', direct array of ' . count($val) . ' objects';
                } else {
                    $extra = ', direct object';
                }
                $isSubdir = null;
                if ($isHash($val)) {
                    $isSubdir = 1;
                } elseif ($isList($val)) {
                    // recurse into objects in arrays only if they are lists of
                    // dictionaries or indirect objects which could be dictionaries
                    if ($val) $isSubdir = 1;
                    foreach ($val as $v) {
                        if ($isHash($v) or $v instanceof ScalarRef) continue;
                        $isSubdir = null;
                        break;
                    }
                }
                if ($isSubdir) {
                    // create bogus subdirectory to recurse into this dict
                    if (!$tagInfo) $tagInfo = new TagInfo([
                        'Name' => $tag,
                        'SubDirectory' => ['TagTable' => 'Image::ExifTool::PDF::Unknown'],
                    ]);
                } else {
                    if ($isList($val)) $val2 = self::ExpandArray($val);
                    // generate tag info if we will use it later
                    if (!$tagInfo and $val !== null and $unknown) {
                        $tagInfo = self::NewPDFTag($tagTablePtr, $tag);
                    }
                }
                $et->VerboseInfo($tag, $tagInfo,
                    Value: $val2 ?: $val,
                    Extra: $extra,
                    Index: $index++,
                );
                if ($val === null) continue;
            }
            if (!$tagInfo) {
                // add any tag found in Info dictionary to table
                if (!$unknown) continue;
                $tagInfo = self::NewPDFTag($tagTablePtr, $tag);
            }
            // increment document number if necessary
            $oldDocNum = $oldNumTags = null;
            if ($isSubDoc) {
                $oldDocNum = $et->DOC_NUM;
                $oldNumTags = $et->NUM_FOUND;
                $et->DOC_NUM = $et->DOC_COUNT = Perl::num($et->DOC_COUNT) + 1;
            }
            if ($tagInfo->SubDirectory) {
                // process the subdirectory
                if ($isList($val)) {
                    // hack to convert array to dictionary if necessary
                    if ($tagInfo->ConvertToDict and count($val) == 2 and !is_array($val[0]) and !is_object($val[0])) {
                        $tg = preg_replace('{^/}', '', Perl::str($val[0]), 1);  // remove name
                        $subDicts = [['_tags' => [$tg], $tg => $val[1]]];
                    } else {
                        $subDicts = $val;
                    }
                } else {
                    $subDicts = [$val];
                }
                // loop through all values of this tag
                for (;;) {
                    $subDict = array_shift($subDicts);
                    if (!(is_array($subDict) or $subDict)) break;
                    // save last fetched object in case we fetch another one here
                    $prevFetched = self::$lastFetched;
                    if ($subDict instanceof ScalarRef) {
                        // only fetch once (other copies are obsolete)
                        if (!empty($fetched[$subDict->value])) continue;
                        if ($tagInfo->IgnoreDuplicates) {
                            $flag = "ProcessedPDF_$tag";
                            if ($et->{$flag}) {
                                if ($et->Warn("Ignored duplicate $tag dictionary", 2)) continue;
                            } else {
                                $et->{$flag} = 1;
                            }
                        }
                        // load dictionary via an indirect reference
                        $fetched[$subDict->value] = 1;
                        $obj = self::FetchObject($et, $subDict->value, $xref, $tag);
                        if ($obj === null) {
                            if (Vars\PDF::$lastOffset === null) {
                                $et->Warn("Error reading $tag object ({$subDict->value})");
                            }
                            continue;
                        }
                        $subDict = $obj;
                    }
                    if ($isList($subDict)) {
                        // convert array of key/value pairs to a hash
                        if (count($subDict) < 2) continue;
                        $hash = ['_tags' => []];
                        while (count($subDict) >= 2) {
                            $key = preg_replace('/^\//', '', Perl::str(array_shift($subDict)), 1);
                            $hash['_tags'][] = $key;
                            $hash[$key] = array_shift($subDict);
                        }
                        $subDict = $hash;
                    } else {
                        if (!$isHash($subDict)) continue;
                    }
                    // set flag to re-crypt all strings when rewriting if the dictionary
                    // came from an encrypted stream
                    if (!self::$lastFetched) $subDict['_needCrypt']['*'] = 1;
                    $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    if (!$verbose) {
                        $proc = $subTablePtr['PROCESS_PROC'] ?: [self::class, 'ProcessDict'];
                        $proc($et, $subTablePtr, $subDict, $xref, $nesting);
                    } elseif ($next) {
                        // handle 'Next' links at this level to avoid deep recursion
                        $next = null;
                        $index = 0;
                        $tagTablePtr = $subTablePtr;
                        $dict = $subDict;
                        $tags = $subDict['_tags'];
                        $et->VerboseDir($tag, count($tags));
                    } else {
                        $oldIndent = $et->INDENT;
                        $oldDir = $et->DIR_NAME;
                        $et->INDENT .= '| ';
                        $et->DIR_NAME = $tag;
                        $et->VerboseDir($tag, count($subDict['_tags']));
                        $proc = $subTablePtr['PROCESS_PROC'] ?: [self::class, 'ProcessDict'];
                        $proc($et, $subTablePtr, $subDict, $xref, $nesting);
                        $et->INDENT = $oldIndent;
                        $et->DIR_NAME = $oldDir;
                    }
                    self::$lastFetched = $prevFetched;
                }
            } else {
                // fetch object if necessary
                // (OS X 10.6 writes indirect objects in the Info dictionary!)
                if ($val instanceof ScalarRef) {
                    $prevFetched = self::$lastFetched;
                    // (note: fetching the same object multiple times is OK here)
                    $val = self::FetchObject($et, $val->value, $xref, $tag);
                    if ($val !== null) {
                        $val = self::ReadPDFValue($val);
                        // set flag to re-encrypt if necessary if rewritten
                        if (Vars\PDF::$cryptString) $dict['_needCrypt'][$tag] = (self::$lastFetched ? 0 : 1);
                        self::$lastFetched = $prevFetched; // restore last fetched object reference
                    }
                } else {
                    $val = self::ReadPDFValue($val);
                }
                if (is_array($val) or is_object($val)) {
                    if ($isList($val)) {
                        if ($tagInfo->List) unset($et->LIST_TAGS[spl_object_id($tagInfo)]);
                        foreach ($val as $v) {
                            $et->FoundTag($tagInfo, $v);
                        }
                    }
                } elseif ($val !== null) {
                    // convert from UTF-16 (big endian) to UTF-8 or Latin if necessary
                    // unless this is binary data (hex-encoded strings would not have been converted)
                    $format = $tagInfo->Format ?: ($tagInfo->Writable ?: 'string');
                    if ($format === 'date') $val = self::ConvertPDFDate($val);
                    if (!$tagInfo->Binary and preg_match('/[\x18-\x1f\x80-\xff]/', $val)) {
                        // text string is already in Unicode if it starts with "\xfe\xff",
                        // otherwise we must first convert from PDFDocEncoding
                        if (str_starts_with($val, "\xfe\xff")) {
                            $val = substr($val, 2);
                            $from = 'UTF16';
                        } else {
                            $from = 'PDFDoc';
                        }
                        $val = $et->Decode($val, $from, 'MM');
                    }
                    if ($tagInfo->List and !$et->OPTIONS['NoPDFList']) {
                        // separate tokens in comma or whitespace delimited lists
                        $comma = substr_count($val, ',');
                        $semi = substr_count($val, ';');
                        if ($comma or $semi) {
                            $split = $comma > $semi ? '/,+\s*/' : '/;+\s*/';
                        } else {
                            $split = ' ';
                        }
                        foreach (Perl::split($split, $val) as $v) $et->FoundTag($tagInfo, $v);
                    } else {
                        // a simple tag value
                        $et->FoundTag($tagInfo, $val);
                    }
                }
            }
            if ($isSubDoc) {
                // restore original document number
                $et->DOC_NUM = $oldDocNum;
                if ($oldNumTags == $et->NUM_FOUND) --$et->DOC_COUNT;
            }
        }
        //
        // extract information from joined streams if necessary
        //

        if ($join) {
            ksort($join, SORT_STRING);
            foreach ($join as $tag => $list) {
                if (!(isset($list[1]) and preg_match('/^%.*?([\x0d\x0a]*)/', $list[1], $m))) break;
                $buff = "%!PS-Adobe-3.0$m[1]";  // add PS header with same line break
                for ($i = 1; isset($list[$i]); ++$i) {
                    $buff .= $list[$i];
                    $list[$i] = null;   // free memory
                }
                // increment document number for tags extracted from embedded EPS
                $oldDocNum = $et->DOC_NUM;
                $oldNumTags = $et->NUM_FOUND;
                $et->DOC_NUM = $et->DOC_COUNT = Perl::num($et->DOC_COUNT) + 1;
                // extract PostScript information
                $et->HandleTag($tagTablePtr, (string) $tag, $buff);
                $et->DOC_NUM = $oldDocNum;
                // revert document counter if we didn't add any new tags
                if ($oldNumTags == $et->NUM_FOUND) --$et->DOC_COUNT;
                $et->DOC_NUM = null;
            }
        }
        //
        // extract information from stream object if it exists (eg. Metadata stream)
        //
        for (;;) { // (cheap goto)
            if (!($dict['_stream'] ?? null)) break;
            $tag = '_stream';
            // add Subtype (if it exists) to stream name and remove leading '/'
            if ($dict['Subtype'] ?? null) $tag = preg_replace('/^\//', '', Perl::str($dict['Subtype']) . $tag, 1);
            if (!$tagTablePtr[$tag]) break;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo) break;
            $subdir = $tagInfo->SubDirectory;
            if (!$subdir) {
                // don't build filter lists across different images
                $filterInfo = $tagTablePtr['Filter'];
                if ($filterInfo instanceof TagInfo) unset($et->LIST_TAGS[spl_object_id($filterInfo)]);
                // we arrive here only when extracting embedded images
                // - only extract known image types and ignore others
                $filter = ($dict['Filter'] ?? null) ?: '';
                if ($isList($filter)) $filter = end($filter); // (get last Filter type)
                $result = null;
                if ($filter === '/DCTDecode' or $filter === '/JPXDecode') {
                    if (!self::DecodeStream($et, $dict)) break;
                    // save the image itself
                    $et->FoundTag($tagInfo, new ScalarRef($dict['_stream']));
                    // extract information from embedded image
                    $result = $et->ExtractInfo(new ScalarRef($dict['_stream']), ['ReEntry' => 1]);
                }
                if (!$result) {
                    $et->FoundTag('FileType', $result !== null ? '(unknown)' : '(unsupported)');
                }
                break;
            }
            // decode stream if necessary
            $cryptInfo = Vars\PDF::$cryptInfo;
            if ($cryptInfo and (!empty($cryptInfo['_aesv2']) or !empty($cryptInfo['_aesv3']) and
                ($dict['Length'] ?? null) and Perl::num($dict['Length']) > 10000) and empty($dict['_decrypted']))
            {
                $type = ($dict['Type'] ?? null) ?: '';
                if ($type !== '/Metadata' or Perl::num($dict['Length'] ?? 0) > 100000) {
                    if ($et->OPTIONS['IgnoreMinorErrors']) {
                        $et->Warn("Decrypting large {$tagInfo->Name} (will be slow)");
                    } else {
                        $et->Warn("Skipping large AES-encrypted {$tagInfo->Name}", 2);
                        break;
                    }
                }
            }
            if (!self::DecodeStream($et, $dict)) break;
            if ($verbose > 2) {
                $et->VPrint(2, "{$et->INDENT}{$et->DIR_NAME} stream data\n");
                $et->VerboseDump($dict['_stream']);
            }
            // extract information from stream
            $subdirInfo = [     // (Perl %dirInfo)
                'DataPt'   => $dict['_stream'],
                'DataLen'  => strlen($dict['_stream']),
                'DirStart' => 0,
                'DirLen'   => strlen($dict['_stream']),
                'Parent'   => 'PDF',
                'DirName'  => $subdir['DirName'] ?? null,
            ];
            $subTablePtr = ExifTool::GetTagTable($subdir['TagTable']);
            if (!$et->ProcessDirectory($subdirInfo, $subTablePtr)) {
                $et->Warn("Error processing {$tagInfo->Name} information");
            }
            break;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Extract information from PDF file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 0 if not a PDF file, 1 on success, otherwise a negative error number
    public static function ReadPDF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $encrypt = $id = null;
        //
        // validate PDF file
        //
        // (linearization dictionary must be in the first 1024 bytes of the file)
        if (!($raf->Read($buff, 1024) >= 8)) return 0;
        if (!preg_match('/^(\s*)%PDF-(\d+\.\d+)/', $buff, $m)) return 0;
        $et->PDFBase = strlen($m[1]);
        if ($et->PDFBase) $et->Warn('PDF header is not at start of file', 1);
        Vars\PDF::$pdfVer = $et->PDFVersion = $m[2];
        $et->SetFileType();   // set the FileType tag
        // store PDFVersion tag
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PDF::Root');
        $et->HandleTag($tagTablePtr, 'Version', Vars\PDF::$pdfVer);
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PDF::Main');
        //
        // check for a linearized PDF (only if reading)
        //
        $lin = 'false';
        if (($p = strpos($buff, '<<')) !== false) {
            $buff = substr($buff, $p);
            $dict = self::ExtractObject($et, $buff);
            if (is_array($dict) and !array_is_list($dict) and ($dict['Linearized'] ?? null) and ($dict['L'] ?? null)) {
                if (!($et->VALUE['FileSize'] ?? null)) {
                    $lin = null;    // can't determine if it is linearized
                } elseif (Perl::num($dict['L']) == $et->VALUE['FileSize'] - $et->PDFBase) {
                    $lin = 'true';
                }
            }
        }
        if ($lin) $et->HandleTag($tagTablePtr, '_linearized', $lin);
        //
        // read the xref tables referenced from startxref at the end of the file
        //
        $xrefOffsets = [];
        if (!$raf->Seek(0, 2)) return -2;
        // the %%EOF must occur within the last 1024 bytes of the file (PDF spec, appendix H)
        $len = $raf->Tell();
        if ($len > 1024) $len = 1024;
        if (!$raf->Seek(-$len, 2)) return -2;
        if ($raf->Read($buff, $len) != $len) return -3;
        // find the LAST xref table in the file (may be multiple %%EOF marks,
        // and comments between "startxref" and "%%EOF")
        if (!preg_match('/^.*startxref(\s+)(\d+)(\s+)((%[^\x0d\x0a]*\s+)*)%%EOF/s', $buff, $m)) return -4;
        // parse comments to read SEAL information
        if ($m[4]) {
            $com = Perl::split('/[\x0d\x0d]+/', $m[4]);
            foreach ($com as $c) {
                if (!preg_match('/^(%+\s*)<seal seal=/', $c, $ms)) continue;
                $dat = substr($c, strlen($ms[1]));
                $tbl = ExifTool::GetTagTable('Image::ExifTool::XMP::SEAL');
                $sealInfo = ['DataPt' => $dat];
                $et->ProcessDirectory($sealInfo, $tbl);
            }
        }
        $ws = $m[1] . $m[3];
        $xr = $m[2];
        array_push($xrefOffsets, $xr, 'Main');
        // set input record separator
        $oldRS = Perl::$RS;     // (Perl: local $/)
        Perl::$RS = preg_match('/(\x0d\x0a|\x0d|\x0a)/', $ws, $m) ? $m[1] : "\x0a";
        try {
            $xref = $mainDicts = $loaded = [];
            $mainFree = null;
            [$xrefSize, $mainDictSize] = [0, 0];
            // (PDF_CAPTURE: variables captured here for rewriting -- writer only)
            while ($xrefOffsets) {  // (Perl label XRef)
                $offset = array_shift($xrefOffsets);
                $type = array_shift($xrefOffsets);
                if (!is_scalar($offset)) $offset = -1;  // (Perl seeks to the address of a reference: fails)
                if (!empty($loaded[$offset])) continue;     // avoid infinite recursion
                if (!$raf->Seek(Perl::num($offset) + $et->PDFBase, 0)) {
                    if (!$loaded) return -5;
                    $et->Warn('Bad offset for secondary xref table');
                    continue;
                }
                // Note: care must be taken because ReadLine may read more than we want if
                // the newline sequence for this table is different than the rest of the file
                for (;;) {
                    if (!$raf->ReadLine($buff)) {
                        if (!$loaded) return -6;
                        $et->Warn('Bad offset for secondary xref table');
                        continue 2;
                    }
                    if (preg_match('/\S/', $buff)) break;   // skip blank lines
                }
                $loadXRefStream = null;
                if (preg_match('/^\s*xref\s+/s', $buff, $m)) {
                    $buff = substr($buff, strlen($m[0]));
                    // load xref table
                    for (;;) {
                        // read another line if necessary (skipping blank lines)
                        while (!preg_match('/\S/', $buff)) {
                            if (!$raf->ReadLine($buff)) return -6;
                        }
                        if (preg_match('/^\s*trailer([\s<[(])/s', $buff, $m)) {
                            $buff = $m[1] . substr($buff, strlen($m[0]));
                            break;
                        }
                        if (!preg_match('/^\s*(\d+)\s+(\d+)\s+/s', $buff, $m)) return -4;
                        $buff = substr($buff, strlen($m[0]));
                        [$start, $num] = [$m[1], $m[2]];
                        if (!$raf->Seek(-strlen($buff), 1)) return -4;
                        for ($i = 0; $i < $num; ++$i) {
                            if ($raf->Read($buff, 20) != 20) return -6;
                            if (!preg_match('/^\s*(\d{10}) (\d{5}) (f|n)/s', $buff, $m)) return -4;
                            $n = $start + $i;
                            if ($n > $xrefSize) $xrefSize = $n;
                            // locate object to generate entry from stream if necessary
                            // (must do this before we test $xref{$num})
                            if (!empty($xref['dicts'])) self::LocateAnyObject($xref, $n);
                            // save offset for newest copy of all objects
                            // (or next object number for free objects)
                            if (!isset($xref[$n])) {
                                [$offset, $gen] = [(int) $m[1], (int) $m[2]];
                                $xref[$n] = $offset;
                                if ($m[3] === 'f') {
                                    // save free objects in last xref table for rewriting
                                    // (writer only)
                                    continue;
                                }
                                // also save offset keyed by object reference string
                                $xref["$n $gen R"] = $offset;
                            }
                        }
                        // (I have a sample from Adobe which has an empty xref table)
                        // %xref or return -4; # xref table may not be empty
                        $buff = '';
                    }
                    $mainFree = null;   // only do this for the last xref table
                } elseif (preg_match('/^\s*(\d+)\s+(\d+)\s+obj/s', $buff, $m)) {
                    $buff = substr($buff, strlen($m[0]));
                    // this is a PDF-1.5 cross-reference stream dictionary
                    $loadXRefStream = 1;
                } else {
                    if (!$loaded) return -4;
                    $et->Warn('Invalid secondary xref table');
                    continue;
                }
                $mainDict = self::ExtractObject($et, $buff, $raf, $xref);
                if (!(is_array($mainDict) and !array_is_list($mainDict))) {
                    if (!$loaded) return -8;
                    $et->Warn('Error loading secondary dictionary');
                    continue;
                }
                // keep track of total trailer dictionary Size
                if (($mainDict['Size'] ?? null) and Perl::num($mainDict['Size']) > $mainDictSize) $mainDictSize = Perl::num($mainDict['Size']);
                if ($loadXRefStream) {
                    // decode and save our XRef stream from PDF-1.5 file
                    // (but parse it later as required to save time)
                    // Note: this technique can potentially result in an old object
                    // being used if the file was incrementally updated and an older
                    // object from an xref table was replaced by a newer object in an
                    // xref stream.  But doing so isn't a good idea (if allowed at all)
                    // because a PDF 1.4 consumer would also make this same mistake.
                    $w = $mainDict['W'] ?? null;
                    if (($mainDict['Type'] ?? null) === '/XRef' and is_array($w) and array_is_list($w) and
                        count($w) > 2 and ($mainDict['Size'] ?? null) and
                        self::DecodeStream($et, $mainDict))
                    {
                        // create Index entry if it doesn't exist
                        if (!($mainDict['Index'] ?? null)) $mainDict['Index'] = [0, $mainDict['Size']];
                        // create '_entry_size' entry for internal use
                        $size = 0;
                        foreach ($w as $v) { $size += Perl::num($v); }
                        $mainDict['_entry_size'] = $size;
                        // save this stream dictionary to use later if required
                        if (!isset($xref['dicts'])) $xref['dicts'] = [];
                        $xref['dicts'][] = $mainDict;
                    } else {
                        if (!$loaded) return -9;
                        $et->Warn('Invalid xref stream in secondary dictionary');
                    }
                }
                $loaded[$offset] = 1;
                // load XRef stream in hybrid file if it exists
                if ($mainDict['XRefStm'] ?? null) array_push($xrefOffsets, $mainDict['XRefStm'], 'XRefStm');
                if ($mainDict['Encrypt'] ?? null) $encrypt = $mainDict['Encrypt'];
                if ($encrypt and $encrypt === 'null') $encrypt = null;  // (have seen "null")
                if (($mainDict['ID'] ?? null) and is_array($mainDict['ID']) and array_is_list($mainDict['ID'])) {
                    $id = self::ReadPDFValue($mainDict['ID'][0] ?? null);
                }
                array_push($mainDicts, $mainDict, $type);
                // load previous xref table if it exists
                if ($mainDict['Prev'] ?? null) array_push($xrefOffsets, $mainDict['Prev'], 'Prev');
            }
            if ($xrefSize > $mainDictSize) {
                $str = "Objects in xref table ($xrefSize) exceed trailer dictionary Size ($mainDictSize)";
                $et->Warn($str);
            }
            //
            // extract encryption information if necessary
            //
            if ($encrypt) {
                if ($encrypt instanceof ScalarRef) {
                    $encrypt = self::FetchObject($et, $encrypt->value, $xref, 'Encrypt');
                }
                // generate Encryption tag information
                $err = self::DecryptInit($et, $encrypt, $id);
                if ($err) {
                    $et->Warn($err);
                    return -1;
                }
            }
            //
            // extract the information beginning with each of the main dictionaries
            //
            $i = 0;
            $num = intdiv(count($mainDicts), 2);
            while ($mainDicts) {
                $dict = array_shift($mainDicts);
                $type = array_shift($mainDicts);
                if ($verbose) {
                    ++$i;
                    $n = count($dict['_tags']);
                    $et->VPrint(0, "PDF dictionary ($i of $num) with $n entries:\n");
                }
                self::ProcessDict($et, $tagTablePtr, $dict, $xref, 0, $type);
            }
            // handle any decryption errors
            // (PHP: $encrypt is a copy, the errors are set in $cryptInfo)
            if ($encrypt) {
                $err = Vars\PDF::$cryptInfo['_error'] ?? null;
                if ($err) {
                    $et->Warn($err);
                    return -1;
                }
            }
            return 1;
        } finally {
            Perl::$RS = $oldRS;
        }
    }

    //------------------------------------------------------------------------------
    // Extract information from PDF file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 if this was a valid PDF file
    public static function ProcessPDF(ExifTool $et, array &$dirInfo)
    {
        Vars\PDF::$cryptInfo = null;    // (must not delete after returning so writer can use it)
        Vars\PDF::$cryptStream = null;
        Vars\PDF::$cryptString = null;
        try {
            $result = self::ReadPDF($et, $dirInfo);
            if ($result < 0) {
                if (isset(Vars\PDF::$pdfWarning[$result])) $et->Warn(Vars\PDF::$pdfWarning[$result]);
                $result = 1;
            }
        } finally {
            // clean up and return
            Vars\PDF::$streamObjs = [];
            Vars\PDF::$fetched = [];
        }
        return $result;
    }
}
