<?php

//------------------------------------------------------------------------------
// File:         XMPStruct.pl
//
// Description:  XMP structure support
//
// Revisions:    01/01/2011 - P. Harvey Created
//
// PHP port (reading only: SerializeStruct, EscapeJSON, ConvertStruct, RestoreStruct).
// Not ported (writing): InflateStruct, CheckStruct, DeleteStruct, AddNewTag, AddNewStruct,
// GetLangCode, and the DumpStruct debugging routine.
//------------------------------------------------------------------------------

namespace Image\ExifTool\XMP;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\Vars;

/**
 * Structures are PHP arrays: a Perl HASH ref is a string-keyed array, an ARRAY ref a list.
 */
trait XMPStruct
{
    //------------------------------------------------------------------------------
    // Serialize a structure (or other object) into a simple string
    // Inputs: 0) ExifTool ref, 1) HASH ref, ARRAY ref, or SCALAR, 2) closing bracket (or undef)
    // Returns: serialized structure string (in format specified by StructFormat option)
    // eg) "{field=text with {braces|}|, and a comma, field2=val2,field3={field4=[a,b]}}"
    public static function SerializeStruct(ExifTool $et, $obj, $ket = null)
    {
        $vals = [];
        $sfmt = $et->Options('StructFormat');

        if (is_array($obj) and !array_is_list($obj)) {
            // support hashes with ordered keys
            foreach (ExifTool::OrderedKeys($obj) as $key) {
                $hdr = $sfmt ? self::EscapeJSON($key) . ':' : $key . '=';
                $vals[] = $hdr . self::SerializeStruct($et, $obj[$key], '}');
            }
            $rtnVal = '{' . implode(',', $vals) . '}';
        } elseif (is_array($obj)) {
            foreach ($obj as $val) {
                $vals[] = self::SerializeStruct($et, $val, ']');
            }
            $rtnVal = '[' . implode(',', $vals) . ']';
        } elseif ($obj !== null) {
            if ($obj instanceof ScalarRef) $obj = $obj->value;
            // escape necessary characters in string (closing bracket plus "," and "|")
            if ($sfmt) {
                $rtnVal = self::EscapeJSON($obj, $sfmt === 'JSONQ');
            } else {
                $pat = $ket ? '\\' . $ket . '|,|\|' : ',|\|';
                $rtnVal = preg_replace("/($pat)/", '|$1', Perl::str($obj));
                // also must escape opening bracket or whitespace at start of string
                $rtnVal = preg_replace('/^([\s\[\{])/', '|$1', $rtnVal);
            }
        } elseif ($sfmt) {
            $rtnVal = 'null';
        } else {
            $rtnVal = '';   // allow undefined list items
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Escape string for JSON
    // Inputs: 0) string, 1) flag to force numbers to be quoted too
    // Returns: Escaped string (quoted if necessary)
    public static function EscapeJSON($str, $quote = null)
    {
        if (!$quote) {
            if ($str === null) return 'null';
            // JSON boolean (true or false)
            if (preg_match('/^(true|false)$/i', Perl::str($str))) return strtolower(Perl::str($str));
            // JSON number (see json.org for numerical format)
            // return $str if $str =~ /^-?(\d|[1-9]\d+)(\.\d+)?(e[-+]?\d+)?$/i;
            // (these big numbers caused problems for some JSON parsers, so be more conservative)
            if (preg_match('/^-?(\d|[1-9]\d{1,14})(\.\d{1,16})?(e[-+]?\d{1,3})?$/i', Perl::str($str))) return Perl::str($str);
        }
        if ($str === null) return '""';
        $str = Perl::str($str);
        // encode JSON string in base64 if necessary
        if (ExifTool::IsUTF8($str) < 0) return '"base64:' . self::EncodeBase64($str, 1) . '"';
        // escape special characters
        $str = preg_replace_callback('/(["\t\n\r\\\\])/s', static fn ($m) => '\\' . Vars\XMP::$jsonChar[$m[1]], $str);
        $str = str_replace("\0", '', $str);   // remove all nulls
        // escape other control characters with \u
        $str = preg_replace_callback('/([\0-\x1f])/s', static fn ($m) => Perl::sprintf('\\u%.4X', ord($m[1])), $str);
        return '"' . $str . '"';    // return the quoted string
    }

    //------------------------------------------------------------------------------
    // Convert structure field values for printing
    // Inputs: 0) ExifTool ref, 1) tagInfo ref for structure tag, 2) value,
    //         3) conversion type: PrintConv, ValueConv or Raw (Both not allowed)
    //         4) tagID of parent structure (needed only if there was no flattened tag)
    // Notes: Makes a copy of the hash so any applied escapes won't affect raw values
    public static function ConvertStruct(ExifTool $et, TagInfo $tagInfo, $value, $type, $parentID = null)
    {
        if (is_array($value) and !array_is_list($value)) {
            $struct = [];
            $table = $tagInfo->Table;
            if (!$parentID) $parentID = $tagInfo->TagID;
            if (!empty($value['_ordered_keys_'])) $struct['_ordered_keys_'] = [];
            foreach (ExifTool::OrderedKeys($value) as $key) {
                $tagID = $parentID . ucfirst($key);
                $flatInfo = $table ? $table[$tagID] : null;
                if (!$flatInfo) {
                    // handle variable-namespace structures
                    if (preg_match('/^XMP-(.*?:)(.*)/', $key, $m)) {
                        $tagID = $m[1] . $parentID . ucfirst($m[2]);
                        $flatInfo = $table ? $table[$tagID] : null;
                    }
                    if (!$flatInfo) $flatInfo = $tagInfo;
                }
                // (PHP: only a tagInfo hash can be used for the conversion)
                if (!$flatInfo instanceof TagInfo) $flatInfo = $tagInfo;
                $v = $value[$key];
                if (is_array($v) or is_object($v)) {
                    $v = self::ConvertStruct($et, $flatInfo, $v, $type, $tagID);
                } else {
                    $v = $et->GetValue($flatInfo, $type, $v);
                }
                if ($v !== null) {
                    $struct[$key] = $v;  // save the converted value
                    // maintain ordered keys if necessary
                    if (isset($struct['_ordered_keys_'])) $struct['_ordered_keys_'][] = $key;
                }
            }
            return $struct;
        } elseif (is_array($value)) {
            if (isset($et->OPTIONS['ListItem'])) {
                $li = $et->OPTIONS['ListItem'];
                $i = (int) Perl::int($li);
                if ($i < 0) $i += count($value);    // (Perl negative array index)
                if (!isset($value[$i])) return null;
                $et->OPTIONS['ListItem'] = null;    // only do top-level list
                $val = self::ConvertStruct($et, $tagInfo, $value[$i], $type, $parentID);
                $et->OPTIONS['ListItem'] = $li;
                return $val;
            } else {
                $list = [];
                foreach ($value as $val) {
                    $v = self::ConvertStruct($et, $tagInfo, $val, $type, $parentID);
                    if ($v !== null) $list[] = $v;
                }
                return $list;
            }
        } else {
            return $et->GetValue($tagInfo, $type, $value);
        }
    }

    //------------------------------------------------------------------------------
    // Restore XMP structures in extracted information
    // Inputs: 0) ExifTool object ref, 1) flag to keep original flattened tags
    // Notes: also restores lists (including multi-dimensional)
    // (PHP: hashes keyed by reference are keyed by spl_object_id() of the structure tagInfo, and
    //  the structures are built through PHP references; the values of list tags are stored at
    //  the end, once the lists are complete, since Perl stores references to growing lists)
    public static function RestoreStruct(ExifTool $et, $keepFlat = null): void
    {
        $structs = $var = $listKeys = $siList = [];
        $listValue = [];    // (PHP: tag key => id of the structure stored as its value)
        foreach (array_keys($et->TAG_INFO) as $key) {
            $structProps = $et->TAG_EXTRA[$key]['Struct'] ?? null;
            if (!$structProps) continue;
            unset($et->TAG_EXTRA[$key]['Struct']);    // (don't re-use)
            $tagInfo = $et->TAG_INFO[$key];  // tagInfo for flattened tag
            $table = $tagInfo->Table;
            $prop = array_shift($structProps);
            $tag = $prop[0];
            // get reference to structure tag (or normal list tag if not a structure)
            $strInfo = $structProps ? $table[$tag] : $tagInfo;
            if ($strInfo) {
                if (!$strInfo instanceof TagInfo) continue; // (just to be safe)
                if ($structProps and !$strInfo->Struct) {
                    // this could happen for invalid XMP containing mixed lists
                    // (or for something like this -- what should we do here?:
                    // <meta:user-defined meta:name="License">test</meta:user-defined>)
                    if (!$et->NO_STRUCT_WARN) $et->Warn("{$strInfo->Name} is not a structure!");
                    continue;
                }
            } else {
                // create new entry in tag table for this structure
                $g1 = ($table['GROUPS'][0] ?? null) ?: 'XMP';
                $name = $tag;
                // tag keys will have a group 1 prefix when coming from import of XML from -X option
                if (preg_match('/(.+):(.+)/', $tag, $m)) {
                    [$ns, $name] = [$m[1], $m[2]];
                    $ns = preg_replace('/^XMP-/', '', $ns); // remove leading "XMP-" if it exists because we add it later
                    if (!empty(Vars\XMP::$stdXlatNS[$ns])) $ns = Vars\XMP::$stdXlatNS[$ns];
                    $g1 .= "-$ns";
                }
                $strInfo = new TagInfo([
                    'Name' => ucfirst($name),
                    'Groups' => [1 => $g1],
                    'Struct' => 'Unknown',
                ]);
                // add Struct entry if this is a structure
                if ($structProps) {
                    // this is a structure
                    $strInfo->Struct = ['STRUCT_NAME' => 'XMP Unknown'];
                } elseif ($tagInfo->LangCode) {
                    // this is lang-alt list
                    $tag = $tag . '-' . $tagInfo->LangCode;
                    $strInfo->LangCode = $tagInfo->LangCode;
                }
                ExifTool::AddTagToTable($table, $tag, $strInfo);
            }
            // use strInfo ref for base key to avoid collisions
            $id = spl_object_id($strInfo);
            $tag = $id;
            $tagIsRef = true;
            $struct = &$structs;
            $oldStruct = $structs[$id] ?? null;
            // (fyi: 'lang-alt' Writable type will be valid even if tag is not pre-defined)
            $writable = $tagInfo->Writable ?: '';
            // walk through the stored structure property information
            // to rebuild this structure
            $err = null;
            for (;;) {
                $index = $prop[1] ?? null;
                if ($index and !$structProps) {
                    // ignore this list if it is a simple lang-alt tag
                    if ($writable === 'lang-alt') {
                        array_pop($prop);   // remove lang-alt index
                        if (count($prop) < 2) $index = null;
                    }
                    // add language code if necessary
                    if ($tagInfo->LangCode and !$tagIsRef) {
                        $tag = $tag . '-' . $tagInfo->LangCode;
                    }
                }
                $nextStruct = $struct[$tag] ?? null;
                if ($index !== null) {
                    // the field is a list
                    $index = (int) substr($index, 1);   // remove digit count
                    if (self::PerlTrue($nextStruct)) {
                        if (!(is_array($nextStruct) and array_is_list($nextStruct))) {
                            $err = 2;
                            break;
                        }
                        $struct = &$struct[$tag];
                    } else {
                        $struct[$tag] = [];
                        $struct = &$struct[$tag];
                    }
                    // (PHP: $nextIdx is the index of $nextStruct in $struct, which isn't the
                    //  current $index after a multi-dimensional list)
                    $nextStruct = $struct[$nextIdx = $index] ?? null;
                    // descend into multi-dimensional lists
                    for ($i = 2; !empty($prop[$i]); ++$i) {
                        if (self::PerlTrue($nextStruct)) {
                            if (!(is_array($nextStruct) and array_is_list($nextStruct))) break;
                            $struct = &$struct[$nextIdx];
                        } else {
                            self::SetListItem($struct, $index, []);
                            $struct = &$struct[$index];
                        }
                        $nextStruct = $struct[$nextIdx = $index] ?? null;
                        $index = (int) substr($prop[$i], 1);
                    }
                    if (is_array($nextStruct) and !array_is_list($nextStruct)) {
                        $struct = &$struct[$nextIdx];  // continue building sub-structure
                    } elseif ($structProps) {
                        self::SetListItem($struct, $index, []);
                        $struct = &$struct[$index];
                    } else {
                        self::SetListItem($struct, $index, $et->VALUE[$key] ?? null);
                        break;
                    }
                } else {
                    if (self::PerlTrue($nextStruct)) {
                        if (!(is_array($nextStruct) and !array_is_list($nextStruct))) {
                            $err = 3;
                            break;
                        }
                        $struct = &$struct[$tag];
                    } elseif ($structProps) {
                        $struct[$tag] = [];
                        $struct = &$struct[$tag];
                    } else {
                        $struct[$tag] = $et->VALUE[$key] ?? null;
                        break;
                    }
                }
                $prop = array_shift($structProps);
                if (!$prop) break;
                $tag = $prop[0];
                $tagIsRef = false;
                if (preg_match('/(.+):(.+)/', $tag, $m)) {
                    // tag in variable-namespace tables will have a leading
                    // XMP namespace on the tag name.  In this case, add
                    // the corresponding group1 name to the tag ID.
                    [$ns, $name] = [$m[1], $m[2]];
                    if (!empty(Vars\XMP::$stdXlatNS[$ns])) $ns = Vars\XMP::$stdXlatNS[$ns];
                    $tag = "XMP-$ns:" . ucfirst($name);
                } else {
                    $tag = ucfirst($tag);
                }
            }
            unset($struct);
            if ($err) {
                // this may happen if we have a structural error in the XMP
                // (like an improperly contained list for example)
                if (!$et->NO_STRUCT_WARN) {
                    $ns = $tagInfo->Namespace ?: ($tagInfo->Table['NAMESPACE'] ?: '');
                    $et->Warn("Error $err placing $ns:{$tagInfo->TagID} in structure or list", 1);
                }
                if (!self::PerlTrue($oldStruct)) unset($structs[$id]);
            } elseif ($tagInfo === $strInfo) {
                // just a regular list tag (or an empty structure)
                if (self::PerlTrue($oldStruct)) {
                    // keep tag with lowest numbered key (well, not exactly, since
                    // "Tag (10)" is lt "Tag (2)", but at least "Tag" is lt
                    // everything else, and this is really what we care about)
                    $k = $listKeys[$id] ?? null;
                    if ($k) {   // ($k will be undef for an empty structure)
                        if (strcmp($k, (string) $key) < 0) {
                            // keep lowest file order
                            if ($et->FILE_ORDER[$k] > $et->FILE_ORDER[$key]) $et->FILE_ORDER[$k] = $et->FILE_ORDER[$key];
                            $et->DeleteTag($key);
                            continue;
                        }
                        if ($et->FILE_ORDER[$key] > $et->FILE_ORDER[$k]) $et->FILE_ORDER[$key] = $et->FILE_ORDER[$k];
                        $et->DeleteTag($k);   // remove tag with greater copy number
                        unset($listValue[$k]);
                    }
                }
                // replace existing value with new list
                $listValue[$key] = $id;
                $listKeys[$id] = (string) $key;   // save key for this list tag
            } else {
                // save strInfo ref and file order
                if (isset($var[$id])) {
                    // set file order to just before the first associated flattened tag
                    if ($var[$id][1] > $et->FILE_ORDER[$key]) {
                        $var[$id][1] = $et->FILE_ORDER[$key] - 0.5;
                    }
                } else {
                    $var[$id] = [$strInfo, $et->FILE_ORDER[$key] - 0.5];
                }
                // preserve original flattened tags if requested
                if ($keepFlat) {
                    $extra = $et->TAG_EXTRA[$key] ?? [];
                    // restore list behaviour of this flattened tag
                    if (!empty($extra['NoList'])) {
                        $et->VALUE[$key] = $extra['NoList'];
                        unset($et->TAG_EXTRA[$key]['NoList']);
                    } elseif (!empty($extra['NoListDel'])) {
                        // delete this tag since its value was included another list
                        $et->DeleteTag($key);
                    }
                } else {
                    $et->DeleteTag($key); // delete the flattened tag
                }
            }
        }
        // fill in undefined items in lists.  In theory, undefined list items should
        // be fine, but in practice the calling code may not check for this (and
        // historically this wasn't necessary, so do this for backward compatibility)
        // (PHP: every list in the structures we built, instead of Perl's %lists lookup)
        foreach ($structs as &$s) {
            if (is_array($s)) self::FillLists($s);
        }
        unset($s);
        foreach ($listValue as $key => $id) {
            $et->VALUE[$key] = $structs[$id];
        }
        // make a list of all new structures we generated
        foreach (array_keys($structs) as $si) {
            if (isset($var[$si])) $siList[] = $si;
        }
        // save new structures in the same order they were read from file
        usort($siList, static fn ($a, $b) => $var[$a][1] <=> $var[$b][1]);
        foreach ($siList as $si) {
            // test to see if a tag for this structure has already been generated
            // (this could happen only if one of the structures in a list was empty)
            $key = $var[$si][0]->Name;
            $found = null;
            if (self::PerlTrue($et->VALUE[$key] ?? null)) {
                $keys = preg_grep('/^' . preg_quote($key, '/') . '( \(\d+\))?$/', array_map('strval', array_keys($et->VALUE)));
                foreach ($keys as $k) {
                    if (($listValue[$k] ?? null) !== $si) continue;
                    $found = 1;
                    break;
                }
            }
            if (!$found) {
                // otherwise, generate a new tag for this structure
                $key = $et->FoundTag($var[$si][0], '');
                $et->VALUE[$key] = $structs[$si];
            }
            $et->FILE_ORDER[$key] = $var[$si][1];
        }
    }

    /** Perl truth of a value: references are always true */
    private static function PerlTrue($val): bool
    {
        return is_array($val) or is_object($val) or (bool) $val;
    }

    /** $$list[$index] = $val, filling the skipped items with undef as Perl does */
    private static function SetListItem(array &$list, int $index, $val): void
    {
        for ($i = count($list); $i < $index; ++$i) $list[] = null;
        $list[$index] = $val;
    }

    /** replace undefined list items by '' in the structures built by RestoreStruct */
    private static function FillLists(array &$struct): void
    {
        $isList = array_is_list($struct);
        foreach ($struct as &$v) {
            if (is_array($v)) {
                self::FillLists($v);
            } elseif ($v === null and $isList) {
                $v = '';
            }
        }
        unset($v);
    }
}
