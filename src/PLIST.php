<?php

//------------------------------------------------------------------------------
// File:         PLIST.pm
//
// Description:  Read Apple PLIST information
//
// Revisions:    2013-02-01 - P. Harvey Created
//
// References:   1) http://www.apple.com/DTDs/PropertyList-1.0.dtd
//               2) http://opensource.apple.com/source/CF/CF-550/CFBinaryPList.c
//
// Notes:      - Sony MODD files also use XML PLIST format, but with a few quirks
//
//             - Decodes both the binary and XML-based PLIST formats
//
// PHP port of Image::ExifTool::PLIST 1.17. Tag tables are generated into tables/PLIST.php,
// %readProc and %plistType into Vars\PLIST.
// A PLIST dict (Perl HASH ref) is a string-keyed array, an empty dict is an \ArrayObject
// (an empty PHP array would read as an empty list): test with PLIST::IsHash().
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\PLIST as Vars;

final class PLIST
{
    public static $VERSION = '1.17';

    /** '$self->ConvertDateTime($val)' PrintConv of the date tags added on the fly */
    private static ?\Closure $convertDateTime = null;

    //------------------------------------------------------------------------------
    // We found a PLIST XML property name/value
    // Inputs: 0) ExifTool object ref, 1) tag table ref
    //         2) reference to array of XML property names (last is current property)
    //         3) property value, 4) attribute hash ref (not used here)
    // Returns: 1 if valid tag was found
    public static function FoundTag(ExifTool $et, TagTable $tagTablePtr, array $props, $val, $attrs = null)
    {
        if (!$props) return 0;
        $verbose = $et->Options('Verbose');
        $keys = $et->PListKeys ?: [];

        $prop = $props[count($props) - 1];
        if ($verbose > 1) {
            $et->VPrint(0, $et->INDENT, '[', implode('/', $props), ' = ',
                        $et->Printable($val), "]\n");
        }
        // un-escape XML character entities
        $val = XMP::UnescapeXML($val);

        // handle the various PLIST properties
        if ($prop === 'data') {
            if (preg_match('/^[0-9a-f]+$/', Perl::str($val)) and !(strlen(Perl::str($val)) & 0x01)) {
                // MODD files use ASCII-hex encoded "data"...
                $buff = Perl::pack('H*', $val);
                $val = new ScalarRef($buff);
            } else {
                // ...but the PLIST DTD specifies Base64 encoding
                $val = XMP::DecodeBase64($val);
            }
        } elseif ($prop === 'date') {
            $val = XMP::ConvertXMPDate($val);
        } elseif ($prop === 'true' or $prop === 'false') {
            $val = ucfirst($prop);
        } else {
            // convert from UTF8 to ExifTool Charset
            $val = $et->Decode($val, 'UTF8');
            if ($prop === 'key') {
                if (count($props) <= 3) {   // top-level key should be plist/dict/key
                    $keys = [$val];
                } else {
                    // save key names to be used in tag name
                    while (count($keys) < count($props) - 3) $keys[] = '';
                    while (count($keys) > count($props) - 2) array_pop($keys);
                    $keys[count($props) - 3] = $val;
                }
                $et->PListKeys = $keys;
                return 0;
            }
        }
        $et->PListKeys = $keys;

        if (!$keys) return 0;   // can't store value if no associated key

        $tag = implode('/', array_map(Perl::str(...), $keys));  // generate tag ID from 'key' values
        $tagInfo = $tagTablePtr[$tag];
        if (!$tagInfo) {
            if ($verbose) $et->VPrint(0, $et->INDENT, "[adding $tag]\n");
            // generate tag name from ID
            $name = $tag;
            $name = preg_replace('{^MetaDataList//}', '', $name);  // shorten long MODD metadata tag names
            $name = preg_replace('{//name$}', '', $name);          // remove unnecessary MODD "name" property
            $name = preg_replace_callback('/([^A-Za-z])([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name); // capitalize words
            $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);   // remove illegal characters
            $tagInfo = new TagInfo(['Name' => ucfirst($name), 'List' => 1]);
            if ($prop === 'date') {
                $tagInfo->Groups = [2 => 'Time'];
                $tagInfo->PrintConv = self::ConvertDateTimeConv();
            }
            ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
        }
        // allow list-behaviour only for consecutive tags with the same ID
        self::LastPListTag($et, $tagInfo);
        // override file type if applicable
        if (!empty(Vars::$plistType[$tag]) and $et->FILE_TYPE === 'XMP') $et->OverrideFileType(Vars::$plistType[$tag]);
        // handle compressed PLIST/JSON data
        $proc = null;
        if ($tagInfo instanceof TagInfo and $tagInfo->CompressedPLIST and ExifTool::IsScalarRef($val) and
            !str_starts_with(Perl::str($val->value), 'bplist00'))
        {
            // (IO::Uncompress::RawInflate::rawinflate() succeeds even on data it can't inflate:
            //  its Transparent mode returns the data as is, so the "Error inflating" warning
            //  of the Perl code is never issued)
            $inflated = @gzinflate(Perl::str($val->value));
            if ($inflated === false) $inflated = Perl::str($val->value);
            $val = new ScalarRef($inflated);
        }
        // save the tag
        $et->HandleTag($tagTablePtr, $tag, $val, ProcessProc: $proc);

        return 1;
    }

    //------------------------------------------------------------------------------
    // Get big-endian 24-bit integer
    // Inputs: 0) data ref, 1) offset
    // Returns: integer value
    public static function Get24u($dataPt, $off)
    {
        return Perl::unpack('N', "\0" . substr($dataPt, $off, 3))[0];
    }

    //------------------------------------------------------------------------------
    // Extract object from binary PLIST file at the current file position (ref 2)
    // Inputs: 0) ExifTool ref, 1) PLIST info ref, 2) parent tag ID (undef for top)
    // Returns: the object, or undef on error
    public static function ExtractObject(ExifTool $et, array &$plistInfo, $parent = null)
    {
        $raf = $plistInfo['RAF'];
        $buff = $val = null;

        if ($raf->Read($buff, 1) != 1) return null;
        $type = ord($buff) >> 4;
        $size = ord($buff) & 0x0f;
        if ($type == 0) {       // null/bool/fill
            $val = [0x00 => '<null>', 0x08 => 'True', 0x09 => 'False', 0x0f => '<fill>'][$size] ?? null;
        } elseif ($type == 1 or $type == 2 or $type == 3) { // int, float or date
            $size = 1 << $size;
            $proc = ($type == 1 ? (Vars::$readProc[$size] ?? null) : (Vars::$readProc[$size + 0x100] ?? null));
            if (!$proc) return null;
            if ($raf->Read($buff, $size) == $size) $val = $proc($buff, 0);
            if ($type == 3 and $val !== null) { // date
                // dates are referenced to Jan 1, 2001 (11323 days from Unix time zero)
                $val = ExifTool::ConvertUnixTime(Perl::num($val) + 11323 * 24 * 3600, 1);
                $plistInfo['DateFormat'] = 1;
            }
        } elseif ($type == 8) { // UID
            ++$size;
            if ($raf->Read($buff, $size) != $size) return null;
            $proc = Vars::$readProc[$size] ?? null;
            if ($proc) {
                $val = $proc($buff, 0);
            } elseif ($size == 16) {
                ExifTool::RequireModule('Image::ExifTool::ASF');
                $val = ASF::GetGUID($buff);
            } else {
                $val = '0x' . bin2hex($buff);
            }
        } else {
            // $size is the size of the remaining types
            if ($size == 0x0f) {
                // size is stored in extra integer object
                $size = self::ExtractObject($et, $plistInfo);
                if (!($size !== null and !is_array($size) and !is_object($size) and preg_match('/^\d+$/', Perl::str($size)))) return null;
                $size = Perl::num($size);
            }
            if ($type == 4) {   // data
                if ($size < 1000000 or $et->Options('Binary')) {
                    if (self::Read($raf, $buff, $size) != $size) return null;
                } else {
                    $buff = "Binary data $size bytes";
                }
                $val = new ScalarRef($buff);    // (return reference for binary data)
            } elseif ($type == 5) { // ASCII string
                if (self::Read($raf, $val, $size) != $size) return null;
            } elseif ($type == 6) { // UCS-2BE string
                $size *= 2;
                if (self::Read($raf, $buff, $size) != $size) return null;
                $val = $et->Decode($buff, 'UTF16');     // (might as well support surrogates too)
            } elseif ($type == 10 or $type == 12 or $type == 13) { // array, set or dict
                // the remaining types store a list of references
                $refSize = $plistInfo['RefSize'];
                $refProc = $plistInfo['RefProc'];
                $num = $type == 13 ? $size * 2 : $size;
                $len = $num * $refSize;
                if (self::Read($raf, $buff, $len) != $len) return null;
                $table = $plistInfo['Table'];
                $refs = $array = [];
                for ($i = 0; $i < $num; ++$i) {
                    $ref = $refProc($buff, $i * $refSize);
                    if ($ref >= count($table)) return 0;
                    $refs[] = $ref;
                }
                if ($type == 13) {  // dict
                    // prevent infinite recursion
                    if ($parent !== null and strlen(Perl::str($parent)) > 1000) {
                        $et->Warn('Possible deep recursion while parsing PLIST');
                        return null;
                    }
                    $tagTablePtr = $plistInfo['TagTablePtr'] ?? null;
                    $verbose = $et->Options('Verbose');
                    $val = [];  // initialize return dictionary (will stay empty if tags are saved)
                    for ($i = 0; $i < $size; ++$i) {
                        // get the entry key
                        if (!self::Seek($raf, $table[$refs[$i]])) return null;
                        $key = self::ExtractObject($et, $plistInfo);
                        if (!($key !== null and strlen(Perl::str($key)))) continue;     // silently ignore bad dict entries
                        $key = Perl::str($key);
                        // get the entry value
                        if (!self::Seek($raf, $table[$refs[$i + $size]])) return null;
                        // generate an ID for this tag
                        $tag = $parent !== null ? "$parent/$key" : $key;
                        $plistInfo['DateFormat'] = null;
                        $obj = self::ExtractObject($et, $plistInfo, $tag);
                        if ($obj === null) continue;
                        if (!$tagTablePtr) {
                            // make sure this is a valid structure field name
                            if (!preg_match('/^[-_a-zA-Z0-9]+$/', $key)) {
                                $key = "Tag$i"; // (generate fake tag name if it had illegal characters)
                            } elseif (!preg_match('/^[_a-zA-Z]/', $key)) {
                                $key = "_$key"; // (must begin with alpha or underline)
                            }
                            $val[$key] = $obj;
                            continue;
                        }
                        if (self::IsHash($obj)) continue;
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                        if (!$tagInfo) {
                            if ($verbose) $et->VPrint(0, $et->INDENT, "[adding $tag]\n");
                            $name = $tag;
                            $name = preg_replace_callback('/([^A-Za-z])([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name); // capitalize words
                            $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);   // remove illegal characters
                            if (strlen($name) < 2 or preg_match('/^[-0-9]/', $name)) $name = 'Tag' . ucfirst($name);
                            $tagInfo = new TagInfo(['Name' => ucfirst($name), 'List' => 1]);
                            if (!empty($plistInfo['DateFormat'])) {
                                $tagInfo->Groups = [2 => 'Time'];
                                $tagInfo->PrintConv = self::ConvertDateTimeConv();
                            }
                            ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                        }
                        // allow list-behaviour only for consecutive tags with the same ID
                        self::LastPListTag($et, $tagInfo);
                        $et->HandleTag($tagTablePtr, $tag, $obj);
                    }
                    if (!$val) $val = new \ArrayObject();   // (Perl {})
                } else {
                    // extract the referenced objects
                    foreach ($refs as $ref) {
                        if (!self::Seek($raf, $table[$ref])) return null;   // seek to this object
                        $val = self::ExtractObject($et, $plistInfo, $parent);
                        if (!($val !== null and !self::IsHash($val))) continue;
                        $array[] = $val;
                    }
                    $val = $array;
                }
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Process binary PLIST data (ref 2)
    // Inputs: 0) ExifTool object ref, 1) DirInfo ref, 2) tag table ref
    // Returns: 1 on success (and returns plist value as $$dirInfo{Value})
    public static function ProcessBinaryPLIST(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $buff = null;
        $table = [];
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;

        if (empty($dirInfo['NoVerboseDir'])) $et->VerboseDir('Binary PLIST');
        ExifTool::SetByteOrder('MM');

        if ($dataPt !== null) {
            $dataPt = Perl::str($dataPt);
            $start = $dirInfo['DirStart'] ?? null;
            $dirLen = $dirInfo['DirLen'] ?? null;
            if ($start or ($dirLen and $dirLen != strlen($dataPt))) {
                $buf2 = substr($dataPt, $start ?: 0, (int) $dirLen);
                $dirInfo['RAF'] = new RandomAccess($buf2);
            } else {
                $dirInfo['RAF'] = new RandomAccess($dataPt);
            }
        }
        // read and parse the trailer
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) return 0;
        if (!($raf->Seek(-32, 2) and $raf->Read($buff, 32) == 32)) return 0;
        $intSize = ExifTool::Get8u($buff, 6);
        $refSize = ExifTool::Get8u($buff, 7);
        $numObj = Perl::num(ExifTool::Get64u($buff, 8));
        $topObj = Perl::num(ExifTool::Get64u($buff, 16));
        $tableOff = Perl::num(ExifTool::Get64u($buff, 24));

        if ($topObj >= $numObj) return 0;
        $intProc = Vars::$readProc[$intSize] ?? null;
        if (!$intProc) return 0;
        $refProc = Vars::$readProc[$refSize] ?? null;
        if (!$refProc) return 0;

        // read and parse the offset table
        $tableSize = $intSize * $numObj;
        if (!(self::Seek($raf, $tableOff) and self::Read($raf, $buff, $tableSize) == $tableSize)) return 0;
        for ($i = 0; $i < $numObj; ++$i) {
            $table[] = $intProc($buff, $i * $intSize);
        }
        $plistInfo = [
            'RAF' => $raf,
            'RefSize' => $refSize,
            'RefProc' => $refProc,
            'Table' => $table,
            'TagTablePtr' => $tagTablePtr,
        ];
        // position file pointer at the top object, and extract it
        if (!self::Seek($raf, $table[$topObj])) return 0;
        $dirInfo['Value'] = self::ExtractObject($et, $plistInfo);
        return $dirInfo['Value'] !== null ? 1 : 0;
    }

    //------------------------------------------------------------------------------
    // Extract information from a PLIST file (binary, XML or JSON format)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, 0 if this wasn't valid PLIST
    public static function ProcessPLIST(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $result = $notXML = null;

        if ($dataPt !== null) {
            if (!preg_match('/\G</', $dataPt, $m, 0, $start)) $notXML = 1;
        }
        if (!$notXML) {
            // process XML PLIST data using the XMP module
            $dirInfo['XMPParseOpts']['FoundProc'] = 'Image\ExifTool\PLIST::FoundTag';
            $result = XMP::ProcessXMP($et, $dirInfo, $tagTablePtr);
            unset($dirInfo['XMPParseOpts']);
            if ($result) return $result;
        }
        $buff = null;
        $raf = $dirInfo['RAF'] ?? null;
        if ($raf) {
            if (!($raf->Seek(0, 0) and $raf->Read($buff, 64))) return 0;
            $dataPt = $buff;
        } else {
            if ($dataPt === null) return 0;
        }
        if (preg_match('/\Gbplist0/', $dataPt, $m, 0, $start)) {   // binary PLIST
            // binary PLIST file
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PLIST::Main');
            $et->SetFileType('PLIST', 'application/x-plist');
            $et->SET_GROUP1 = 'PLIST';
            if (!self::ProcessBinaryPLIST($et, $dirInfo, $tagTablePtr)) {
                $et->Error('Error reading binary PLIST file');
            }
            $et->SET_GROUP1 = null;
            $result = 1;
        } elseif (str_starts_with($dataPt, '{"')) {   // JSON PLIST
            if ($raf) $raf->Seek(0);
            ExifTool::RequireModule('Image::ExifTool::JSON');
            $result = $et->CallProc('Image\ExifTool\JSON::ProcessJSON', $dirInfo);
        } elseif ($et->FILE_EXT and $et->FILE_EXT === 'PLIST' and
            str_starts_with($dataPt, "\xfe\xff\x00"))
        {
            // (have seen very old PLIST files encoded as UCS-2BE with leading BOM)
            $et->Error('Old PLIST format currently not supported');
            $result = 1;
        }
        return $result;
    }

    /**
     * Perl ref $val eq 'HASH' for the values returned by ExtractObject(): a dict is a
     * string-keyed array (its keys never look like list indices), or an \ArrayObject when empty.
     */
    public static function IsHash($val): bool
    {
        return $val instanceof \ArrayObject or (is_array($val) and $val and !array_is_list($val));
    }

    /**
     * Perl:  if ($$et{LastPListTag} and $$et{LastPListTag} ne $tagInfo) {
     *            delete $$et{LIST_TAGS}{$$et{LastPListTag}};
     *        }
     *        $$et{LastPListTag} = $tagInfo;
     */
    private static function LastPListTag(ExifTool $et, $tagInfo): void
    {
        $last = $et->LastPListTag;
        if ($last and $last !== $tagInfo and is_object($last)) {
            unset($et->LIST_TAGS[spl_object_id($last)]);
        }
        $et->LastPListTag = $tagInfo;
    }

    private static function ConvertDateTimeConv(): \Closure
    {
        return self::$convertDateTime ??= static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
            return $self->ConvertDateTime($val);
        };
    }

    /**
     * $raf->Seek($pos, 0) and $raf->Read($buff, $len) for the 64-bit offsets and sizes of a
     * binary PLIST (there is no data to read beyond PHP integers, like for Perl)
     */
    private static function Seek(RandomAccess $raf, $pos)
    {
        $pos = Perl::num($pos);
        return $raf->Seek($pos < PHP_INT_MAX ? $pos : PHP_INT_MAX, 0);
    }

    private static function Read(RandomAccess $raf, &$buff, $len)
    {
        if (Perl::num($len) < PHP_INT_MAX) return $raf->Read($buff, $len);
        $buff = '';
        return 0;
    }
}
