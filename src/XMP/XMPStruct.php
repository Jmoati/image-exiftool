<?php

//------------------------------------------------------------------------------
// File:         XMPStruct.pl
//
// Description:  XMP structure support
//
// Revisions:    01/01/2011 - P. Harvey Created
//
// PHP port of the whole file (package Image::ExifTool::XMP), mixed into class XMP.
//------------------------------------------------------------------------------

namespace Image\ExifTool\XMP;

use Image\ExifTool\ExifTool;
use Image\ExifTool\NewValue;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagLookup;
use Image\ExifTool\Vars;

/**
 * Structures are PHP arrays: a Perl HASH ref is a string-keyed array, an ARRAY ref a list.
 * An empty structure (Perl { }) is EMPTY_STRUCT, because an empty PHP array is an empty list.
 * The structure definitions (Struct of a tagInfo, or of a field) are arrays too: the subs which
 * add fields to them or cache field information (CheckStruct, AddNewStruct, SetPropertyPath)
 * take them by reference, so pass $tagInfo->Struct itself. A field generated with a null Struct
 * is a circular reference to the structure containing it (see FieldStruct()).
 */
trait XMPStruct
{
    /** an empty structure (Perl { }): an empty PHP array would be an empty list */
    public const array EMPTY_STRUCT = ['_ordered_keys_' => []];

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
    // Inflate structure (or other object) from a serialized string
    // Inputs: 0) ExifTool ref, 1) reference to object in string form
    //           (serialized using the '|' escape, or JSON)
    //         2) extra delimiter for scalar values delimiters
    // Returns: 0) object as a SCALAR, HASH ref, or ARRAY ref (or undef on error),
    //          1) warning string (or undef)
    // Notes: modifies input string to remove parsed objects
    public static function InflateStruct(ExifTool $et, &$obj, $delim = null): array
    {
        $val = $warn = $part = null;
        $sfmt = $et->Options('StructFormat');
        $obj = Perl::str($obj);

        if (self::Subst('/^\s*\{/', $obj)) {
            $struct = [];
            for (;;) {
                if (!($sfmt ? self::Subst('/^\s*"(.*?)"\s*:/s', $obj, $m) :
                              self::Subst('/^\s*([-\w:.]+#?)\s*=/s', $obj, $m))) break;
                $tag = $m[1];
                [$v, $w] = self::InflateStruct($et, $obj, '}');
                if ($w and !$warn) $warn = $w;
                if ($v === null) return [null, $warn];
                $struct[$tag] = $v;
                // eat comma separator, or all done if there wasn't one
                if (!self::Subst('/^\s*,/s', $obj)) break;
            }
            // eat closing brace and warn if we didn't find one
            if (!(self::Subst('/^\s*\}/s', $obj) or $warn)) {
                if (strlen($obj)) {
                    $part = preg_replace('/^\s*/s', '', $obj, 1);
                    $part = preg_replace('/[\x0d\x0a].*/s', '', $part, 1);
                    if (strlen($part) > 30) $part = substr($part, 0, 27) . '...';
                    $warn = "Invalid structure field at '{$part}'";
                } else {
                    $warn = 'Missing closing brace for structure';
                }
            }
            $val = $struct ?: self::EMPTY_STRUCT;
        } elseif (self::Subst('/^\s*\[/', $obj)) {
            $list = [];
            for (;;) {
                [$v, $w] = self::InflateStruct($et, $obj, ']');
                if ($w and !$warn) $warn = $w;
                if ($v === null) return [null, $warn];
                $list[] = $v;
                if (!self::Subst('/^\s*,/s', $obj)) break;
            }
            // eat closing bracket and warn if we didn't find one
            if (!self::Subst('/^\s*\]/s', $obj) and !$warn) $warn = 'Missing closing bracket for list';
            $val = $list;
        } else {
            self::Subst('/^\s+/s', $obj);   // remove leading whitespace
            if ($sfmt) {
                if (self::Subst('/^"/', $obj)) {
                    $val = '';
                    while (self::Subst('/(.*?)"/', $obj, $m)) {
                        $val .= $m[1];
                        if (!(preg_match('/([\\\\]+)$/', $val, $mm) and strlen($mm[1]) & 0x01)) break;
                        $val = substr($val, 0, -1) . '"';   // (was an escaped quote)
                    }
                    if (self::Subst('/^base64:/', $val)) {
                        $val = self::DecodeBase64($val);
                    } else {
                        // un-escape characters in JSON string
                        $val = preg_replace_callback('/\\\\(.)/s',
                            static fn ($c) => (Vars\XMP::$jsonEsc[$c[1]] ?? null) ?: '\\' . $c[1], $val);
                    }
                } elseif (self::Subst('/^(true|false)\b/', $obj, $m)) {
                    $val = '"' . ucfirst($m[1]) . '"';
                } elseif (self::Subst('/^([+-]?(?=\d|\.\d)\d*(\.\d*)?([Ee]([+-]?\d+))?)/', $obj, $m)) {
                    $val = $m[1];
                } else {
                    if (!$warn) $warn = 'Unknown JSON object';
                    $val = '""';
                }
            } else {
                // read scalar up to specified delimiter (or "," if not defined)
                $delim = $delim ? "\\$delim|,|\\||\$" : ',|\||$';
                $val = '';
                for (;;) {
                    if (!self::Subst("/^(.*?)($delim)/s", $obj, $m)) break;
                    $val .= $m[1];
                    if (!$m[2]) break;
                    if ($m[2] !== '|') {
                        $obj = $m[2] . $obj;
                        break;
                    }
                    if (self::Subst('/^(.)/s', $obj, $m)) $val .= $m[1];    // add escaped character
                }
            }
        }
        return [$val, $warn];
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
    // Get XMP language code from tag name string
    // Inputs: 0) tag name string
    // Returns: 0) separated tag name, 1) language code (in standard case), or '' if
    //          language code was 'x-default', or undef if the tag had no language code
    public static function GetLangCode($tag): array
    {
        $tag = Perl::str($tag);
        if (preg_match('/^(\w+)[-_]([a-z]{2,3}|[xi])([-_][a-z\d]{2,8}([-_][a-z\d]{1,8})*)?$/i', $tag, $m)) {
            // normalize case of language codes
            [$tg, $langCode] = [$m[1], strtolower($m[2])];
            $m3 = $m[3] ?? '';
            if ($m3 !== '') $langCode .= (strlen($m3) == 3 ? strtoupper($m3) : strtolower($m3));
            $langCode = strtr($langCode, '_', '-');     // RFC 3066 specifies '-' as a separator
            if (strtolower($langCode) === 'x-default') $langCode = '';
            return [$tg, $langCode];
        } else {
            return [$tag, null];
        }
    }

    //------------------------------------------------------------------------------
    // Debugging routine to dump a structure, list or scalar
    // Inputs: 0) scalar, ARRAY ref or HASH ref, 1) indent (or undef)
    public static function DumpStruct($obj, $indent = null): void
    {
        if (!$indent) $indent = '';
        if (self::IsHash($obj)) {
            echo "{\n";
            foreach (ExifTool::OrderedKeys($obj) as $k) {
                echo "$indent  $k = ";
                self::DumpStruct($obj[$k], "$indent  ");
            }
            echo $indent, "},\n";
        } elseif (is_array($obj)) {
            echo "[\n";
            foreach ($obj as $v) {
                echo "$indent  ";
                self::DumpStruct($v, "$indent  ");
            }
            echo $indent, "],\n";
        } else {
            echo '"', Perl::str($obj instanceof ScalarRef ? $obj->value : $obj), "\",\n";
        }
    }

    //------------------------------------------------------------------------------
    // Recursively validate structure fields (tags)
    // Inputs: 0) ExifTool ref, 1) Structure ref, 2) structure table definition ref
    // Returns: 0) validated structure ref, 1) error string, or undef on success
    // Notes:
    // - fixes field names in structure and applies inverse conversions to values
    // - copies structure to avoid interdependencies with calling code on referenced values
    // - handles lang-alt tags, and '#' on field names
    // - resets UTF-8 flag of SCALAR values
    // - un-escapes for XML or HTML as per Escape option setting
    // (PHP: always the list context; $strTable is changed in place, like the Perl hash)
    public static function CheckStruct(ExifTool $et, $struct, array &$strTable): array
    {
        $strName = ($strTable['STRUCT_NAME'] ?? null) ?: ('XMP ' . Perl::str(self::RegisterNamespace($strTable)));
        if (!self::IsHash($struct)) return [null, "Expecting $strName structure"];

        $err = $warn = $rtnVal = $val = null;
        $copy = [];
        // copy the ordered keys if they exist
        if (isset($struct['_ordered_keys_'])) $copy['_ordered_keys_'] = [];
        foreach (ExifTool::OrderedKeys($struct) as $key) {     // Key:
            $tag = $key;
            // allow trailing '#' to disable print conversion on a per-field basis
            $type = $fieldInfo = null;
            $tag = preg_replace('/#$/', '', $tag, 1, $n);
            if ($n) $type = 'ValueConv';
            if (empty(self::$specialStruct[$tag])) $fieldInfo = $strTable[$tag] ?? null;
            // fix case of field name if necessary
            if (!self::PerlTrue($fieldInfo)) {
                // (sort in reverse to get lower case (not special) tags first)
                $fix = self::FixCase($tag, $strTable);
                if ($fix !== null and empty(self::$specialStruct[$fix])) $fieldInfo = $strTable[$tag = $fix] ?? null;
            }
            while (!is_array($fieldInfo)) {     // (a field definition is a PHP array, maybe empty)
                // generate wildcard fields on the fly (eg. mwg-rs:Extensions)
                if (empty($strTable['NAMESPACE'])) {
                    $grp = $tg = $langCode = null;
                    [$grp, $tg] = preg_match('/^(.+):(.+)/', $tag, $m) ? [strtolower($m[1]), $m[2]] : ['', $tag];
                    if ($grp === 'XMP') $grp = null;    // (a group of 'XMP' is implied)
                    $matches = TagLookup::FindTagInfo($tg);
                    // also look for lang-alt tags
                    if (!$matches) {
                        [$tg, $langCode] = self::GetLangCode($tg);
                        if ($langCode !== null) $matches = TagLookup::FindTagInfo($tg);
                    }
                    $tagInfo = $priority = $g1 = null;
                    // find best matching tag
                    foreach ($matches as $ti) {
                        $grps = $et->GetGroup($ti);
                        if ($grps[0] !== 'XMP') continue;
                        if ($grp and $grp !== strtolower($grps[1])) continue;
                        // must be lang-alt tag if we are writing an alternate language
                        if ($langCode !== null and !($ti->Writable and $ti->Writable === 'lang-alt')) continue;
                        $pri = $ti->Priority ?: 1;
                        if ($ti->Avoid) $pri -= 10;
                        if ($priority !== null and $priority >= $pri) continue;
                        $priority = $pri;
                        $tagInfo = $ti;
                        $g1 = $grps[1];
                    }
                    if (!$tagInfo) {
                        $warn = "'{$tag}' is not a writable XMP tag";
                        continue 2;     // next Key
                    }
                    self::GetPropertyPath($tagInfo);    // make sure property path is generated for this tag
                    $tag = $tagInfo->Name;
                    if ($grp) $tag = "$g1:$tag";
                    if ($langCode) $tag .= "-$langCode";
                    $fieldInfo = $strTable[$tag] ?? null;
                    // create new structure field if necessary
                    if (!self::PerlTrue($fieldInfo)) {
                        $fieldInfo = $strTable[$tag] = [
                            ...$tagInfo->toArray(),     // (also copies the necessary TagID and PropertyPath)
                            'Namespace' => $tagInfo->Namespace ?: $tagInfo->Table['NAMESPACE'],
                            'LangCode'  => $langCode,
                        ];
                    }
                    // delete stuff we don't need (shouldn't cause harm, but better safe than sorry)
                    // - need to keep StructType and Table in case we need to call AddStructType later
                    unset($fieldInfo['Description'], $fieldInfo['Groups']);
                    if (is_array($strTable[$tag] ?? null)) unset($strTable[$tag]['Description'], $strTable[$tag]['Groups']);
                    break;  // write this dynamically-generated field
                }
                // generate lang-alt fields on the fly (eg. Iptc4xmpExt:AOTitle)
                [$tg, $langCode] = self::GetLangCode($tag);
                if ($langCode !== null) {
                    if (empty(self::$specialStruct[$tg])) $fieldInfo = $strTable[$tg] ?? null;
                    if (!self::PerlTrue($fieldInfo)) {
                        $fix = self::FixCase($tg, $strTable);
                        if ($fix !== null and empty(self::$specialStruct[$fix])) $fieldInfo = $strTable[$tg = $fix] ?? null;
                    }
                    if (is_array($fieldInfo) and !empty($fieldInfo['Writable']) and
                        $fieldInfo['Writable'] === 'lang-alt')
                    {
                        $srcInfo = $fieldInfo;
                        if ($langCode) $tag = $tg . '-' . $langCode;
                        $fieldInfo = $strTable[$tag] ?? null;
                        // create new structure field if necessary
                        if (!self::PerlTrue($fieldInfo)) {
                            $fieldInfo = $strTable[$tag] = [
                                ...$srcInfo,
                                'TagID'    => $tg,
                                'LangCode' => $langCode,
                            ];
                        }
                        break;  // write this lang-alt field
                    }
                }
                $warn = "'{$tag}' is not a field of $strName";
                continue 2;     // next Key
            }
            $sv = $struct[$key];
            if (self::IsHash($sv)) {
                if (!self::HasStruct($fieldInfo)) {
                    $warn = "$tag is not a structure in $strName";
                    continue;
                }
                // recursively check this structure
                $subTable = &self::FieldStruct($strTable, $tag);
                [$val, $err] = self::CheckStruct($et, $sv, $subTable);
                unset($subTable);
                if ($err) {
                    $warn = $err;
                    continue;
                }
                $copy[$tag] = $val;
            } elseif (is_array($sv)) {
                if (empty($fieldInfo['List'])) {
                    $warn = "$tag is not a list in $strName";
                    continue;
                }
                // check all items in the list
                $copyList = [];
                $i = 0;
                foreach ($sv as $item) {
                    if (!is_array($item) and !is_object($item)) {
                        if ($item === null) $item = '';     // use empty string for missing items
                        if (self::HasStruct($fieldInfo)) {
                            // (allow empty structures)
                            if (!preg_match('/^\s*$/', Perl::str($item))) {
                                $warn = "$tag items are not valid structures";
                                continue 2;     // next Key
                            }
                            $copyList[$i] = self::EMPTY_STRUCT;     // create hash for empty structure
                        } else {
                            $et->Sanitize($item);
                            [$copyList[$i], $err] = $et->ConvInv($item, $fieldInfo, $tag, $strName, $type, '');
                            if ($copyList[$i] === null) $copyList[$i] = '';     // avoid undefined item
                            if ($err) {
                                $warn = $err;
                                continue 2;     // next Key
                            }
                            $err = self::CheckXMP($et, new TagInfo($fieldInfo), $copyList[$i]);
                            if ($err) {
                                $warn = "$err in $strName $tag";
                                continue 2;     // next Key
                            }
                        }
                    } elseif (self::IsHash($item)) {
                        if (!self::HasStruct($fieldInfo)) {
                            $warn = "$tag is not a structure in $strName";
                            continue 2;     // next Key
                        }
                        $subTable = &self::FieldStruct($strTable, $tag);
                        [$copyList[$i], $err] = self::CheckStruct($et, $item, $subTable);
                        unset($subTable);
                        if ($err) {
                            $warn = $err;
                            continue 2;     // next Key
                        }
                    } else {
                        $warn = "Invalid value for $tag in $strName";
                        continue 2;     // next Key
                    }
                    ++$i;
                }
                $copy[$tag] = $copyList;
            } elseif (self::HasStruct($fieldInfo)) {
                $warn = "Improperly formed structure in $strName $tag";
                continue;
            } else {
                $et->Sanitize($sv);
                [$val, $err] = $et->ConvInv($sv, $fieldInfo, $tag, $strName, $type, '');
                if ($err) {
                    $warn = $err;
                    continue;
                }
                if ($val === null) continue;    // check for undefined
                $err = self::CheckXMP($et, new TagInfo($fieldInfo), $val);
                if ($err) {
                    $warn = "$err in $strName $tag";
                    continue;
                }
                // turn this into a list if necessary
                $copy[$tag] = !empty($fieldInfo['List']) ? [$val] : $val;
            }
            if (isset($copy['_ordered_keys_'])) $copy['_ordered_keys_'][] = $tag;   // save ordered keys
        }
        if ($copy or !$warn) {
            $rtnVal = $copy ?: self::EMPTY_STRUCT;
            $err = null;
            if ($warn) $et->CHECK_WARN = $warn;
        } else {
            $err = $warn;
        }
        return [$rtnVal, $err];
    }

    //------------------------------------------------------------------------------
    // Delete matching structures from existing linearized XMP
    // Inputs: 0) ExifTool ref, 1) capture hash ref, 2) structure path ref,
    //         3) new value hash ref, 4) reference to change counter
    // Returns: 0) delete flag, 1) list index of deleted structure if adding to list
    //          2) flag set if structure existed
    // Notes: updates path to new base path for structure to be added
    public static function DeleteStruct(ExifTool $et, array &$capture, &$pathPt, NewValue $nvHash, &$changed): array
    {
        $deleted = $added = $existed = $p = $pp = $val = $delPath = null;
        $structPaths = $matchingPaths = $delPaths = [];

        // find all existing elements belonging to this structure
        $pp = preg_replace('/ \d+/', ' \\d+', $pathPt);
        $structPaths = self::GrepKeys('^' . $pp . '(\/|$)', $capture);
        sort($structPaths, SORT_STRING);
        if ($structPaths) $existed = 1;
        // delete only structures with matching fields if necessary
        if ($nvHash->DelValue !== null) {
            if ($nvHash->DelValue) {
                $strTable = $nvHash->TagInfo->Struct;
                // all fields must match corresponding elements in the same
                // root structure for it to be deleted
                foreach ($nvHash->DelValue as $val) {
                    if (!self::IsHash($val)) continue;
                    $cap = $match = [];
                    if (!self::AddNewStruct(null, null, $cap, $pathPt, $val, $strTable)) continue;
                    foreach (array_map('strval', array_keys($cap)) as $p) {
                        if (str_contains($p, ' ')) {
                            $p2 = preg_replace('/ \d+/', ' \\d+', $p);
                            $matchingPaths = array_values(@preg_grep(ExifTool::Rx('^' . $p2 . '$'), $structPaths) ?: []);
                            sort($matchingPaths, SORT_STRING);
                        } else {
                            $matchingPaths[] = $p;
                        }
                        foreach ($matchingPaths as $p2) {
                            if (!preg_match(ExifTool::Rx('^(' . $pp . ')'), $p2, $m)) continue;
                            // language attribute must also match if it exists
                            $attr = $cap[$p][1];
                            if (!empty($attr['xml:lang'])) {
                                $a2 = $capture[$p2][1] ?? [];
                                if (!(!empty($a2['xml:lang']) and $a2['xml:lang'] === $attr['xml:lang'])) continue;
                            }
                            if (isset($capture[$p2]) and Perl::str($capture[$p2][0]) === Perl::str($cap[$p][0])) {
                                // ($1 contains root path for this structure)
                                $match[$m[1]] = ($match[$m[1]] ?? 0) + 1;
                            }
                        }
                    }
                    $num = count($cap);
                    foreach (array_map('strval', array_keys($match)) as $p) {
                        // do nothing unless all fields matched the same structure
                        if ($match[$p] != $num) continue;
                        // delete all elements of this structure
                        foreach ($structPaths as $p2) {
                            if (preg_match(ExifTool::Rx('^' . $p), $p2)) $delPaths[] = $p2;
                        }
                        // remember path of first deleted structure
                        if (!$delPath or strcmp($delPath, $p) > 0) $delPath = $p;
                    }
                }
            } // (else don't delete anything)
        } elseif ($structPaths) {
            $delPaths = $structPaths;   // delete all
            preg_match(ExifTool::Rx('^(' . $pp . ')'), $structPaths[0], $m);
            $delPath = $m[1] ?? null;
        }
        if ($delPaths) {
            $verbose = $et->Options('Verbose');
            if ($verbose > 1) sort($delPaths, SORT_STRING);
            foreach ($delPaths as $p) {
                if ($verbose > 1) {
                    $p2 = preg_replace_callback('/^(\w+)/', static fn ($m) => (Vars\XMP::$stdXlatNS[$m[1]] ?? null) ?: $m[1], $p, 1);
                    $et->VerboseValue("- XMP-$p2", $capture[$p][0] ?? null);
                }
                unset($capture[$p]);
                $deleted = 1;
                ++$changed;
            }
            if (!$delPath) {
                ExifTool::PerlWarn("Internal error 1 in DeleteStruct\n");
                return [null, null, $existed];
            }
            $pathPt = $delPath;     // return path of first element deleted
        } elseif ($nvHash->TagInfo->List) {
            // NOTE: we don't yet properly handle lang-alt elements!!!!
            if ($structPaths) {
                if (!preg_match(ExifTool::Rx('^(' . $pp . ')'), $structPaths[count($structPaths) - 1], $m)) {
                    ExifTool::PerlWarn("Internal error 2 in DeleteStruct\n");
                    return [null, null, $existed];
                }
                $path = $m[1];
                // delete any improperly formatted xmp
                if (isset($capture[$path])) {
                    $cap = $capture[$path];
                    // an error unless this was an empty structure
                    if (!is_array($cap) or $cap[0]) {
                        $et->Error("Improperly structured XMP ($path)", 1);
                    }
                    unset($capture[$path]);
                }
                // (match last index to put in same lang-alt list for Bag of lang-alt items)
                if (!preg_match('/.* (\d+)/', $path, $m, PREG_OFFSET_CAPTURE)) {
                    ExifTool::PerlWarn("Internal error 3 in DeleteStruct\n");
                    return [null, null, $existed];
                }
                $added = $m[1][0];
                // add after last item in list
                $len = strlen($added);
                $pos = $m[1][1];
                $nxt = (int) substr($added, 1) + 1;
                $path = substr_replace($path, strlen((string) $nxt) . $nxt, $pos, $len);
                $pathPt = $path;
            } else {
                $added = '10';
            }
        }
        return [$deleted, $added, $existed];
    }

    //------------------------------------------------------------------------------
    // Add new element to XMP capture hash
    // Inputs: 0) ExifTool ref, 1) TagInfo ref, 2) capture hash ref,
    //         3) resource path, 4) value ref, 5) hash ref for last used index numbers
    // (PHP: 1) is the structure field definition)
    public static function AddNewTag(?ExifTool $et, array $tagInfo, array &$capture, $path, $valPtr, array &$langIdx): void
    {
        $val = self::EscapeXML($valPtr instanceof ScalarRef ? $valPtr->value : $valPtr);
        $attrs = [];
        // support writing RDF "resource" values
        if (!empty($tagInfo['Resource'])) {
            $attrs['rdf:resource'] = $val;
            $val = '';
        }
        if (!empty($tagInfo['Writable']) and $tagInfo['Writable'] === 'lang-alt') {
            // write the lang-alt tag
            $langCode = $tagInfo['LangCode'] ?? null;
            // add indexed lang-alt list properties
            $i = ($langIdx[$path] ?? null) ?: 0;
            $langIdx[$path] = $i + 1;   // save next list index
            if ($i) {
                $idx = strlen((string) $i) . $i;
                $path = preg_replace('/(.*) \d+/', '${1} ' . $idx, $path, 1);    // set list index
            }
            $attrs['xml:lang'] = $langCode ?: 'x-default';
        }
        $capture[$path] = [$val, $attrs];
        // print verbose message
        if ($et and $et->Options('Verbose') > 1) {
            $p = preg_replace_callback('/^(\w+)/', static fn ($m) => (Vars\XMP::$stdXlatNS[$m[1]] ?? null) ?: $m[1], $path, 1);
            $et->VerboseValue("+ XMP-$p", $val);
        }
    }

    //------------------------------------------------------------------------------
    // Add new structure to capture hash for writing
    // Inputs: 0) ExifTool object ref (or undef for no warnings),
    //         1) tagInfo ref (or undef if no ExifTool), 2) capture hash ref,
    //         3) base path, 4) struct ref, 5) struct hash ref
    // Returns: number of tags changed
    // Notes: Escapes values for XML
    public static function AddNewStruct(?ExifTool $et, ?TagInfo $tagInfo, array &$capture, $basePath, $struct, array &$strTable)
    {
        $verbose = $et ? $et->Options('Verbose') : 0;
        $langIdx = [];

        $ns = ($strTable['NAMESPACE'] ?? null) ?: '';
        $changed = 0;

        // add dummy field to allow empty structures (name starts with '~' so it will come
        // after all valid structure fields, which is necessary when serializing the XMP later)
        // (PHP: the structure is a copy, the dummy field isn't added to the caller's value)
        if (self::IsEmptyStruct($struct)) $struct = ['~dummy~' => ''];

        foreach (ExifTool::OrderedKeys($struct) as $tag) {
            $fieldInfo = $strTable[$tag] ?? null;
            if (!self::PerlTrue($fieldInfo)) {
                if ($tag !== '~dummy~') continue;   // check for dummy field
                $fieldInfo = [];    // create dummy field info for dummy structure
            } elseif (!is_array($fieldInfo)) {
                continue;   // (PHP: a special entry of the structure table)
            }
            $val = $struct[$tag];
            $propPath = $fieldInfo['PropertyPath'] ?? null;
            if (!$propPath) {
                $propPath = (($fieldInfo['Namespace'] ?? null) ?: $ns) . ':' . (($fieldInfo['TagID'] ?? null) ?: $tag);
                if (!empty($fieldInfo['List'])) {
                    $propPath .= "/rdf:{$fieldInfo['List']}/rdf:li 10";
                }
                if (!empty($fieldInfo['Writable']) and $fieldInfo['Writable'] === 'lang-alt') {
                    $propPath .= '/rdf:Alt/rdf:li 10';
                }
                $fieldInfo['PropertyPath'] = $propPath;     // save for next time
                if (is_array($strTable[$tag] ?? null)) $strTable[$tag]['PropertyPath'] = $propPath;
            }
            $path = $basePath . '/' . self::ConformPathToNamespace($et, $propPath);
            $addedTag = null;
            if (self::IsHash($val)) {
                if (!self::HasStruct($fieldInfo)) continue;
                $subStruct = &self::FieldStruct($strTable, $tag);
                $changed += self::AddNewStruct($et, $tagInfo, $capture, $path, $val, $subStruct);
                unset($subStruct);
            } elseif (is_array($val)) {
                if (empty($fieldInfo['List'])) continue;
                $i = 0;
                $p = null;
                $level = preg_match_all('/ \d+/', $propPath);
                // loop through all list items (note: can't yet write multi-dimensional lists)
                foreach ($val as $item) {
                    if ($i) {
                        // update first index in field property (may be list of lang-alt lists)
                        $p = self::ConformPathToNamespace($et, $propPath);
                        $idx = strlen((string) $i) . $i;
                        $p = preg_replace('/ \d+/', ' ' . $idx, $p, 1);
                        $p = "$basePath/$p";
                    } else {
                        $p = $path;
                    }
                    if (self::IsHash($item)) {
                        if (!self::HasStruct($fieldInfo)) continue;
                        $subStruct = &self::FieldStruct($strTable, $tag);
                        $n = self::AddNewStruct($et, $tagInfo, $capture, $p, $item, $subStruct);
                        unset($subStruct);
                        if (!$n) continue;
                    // don't write empty items in upper-level list
                    // (PHP: an item which is a list is skipped: multi-dimensional lists can't be written)
                    } elseif ($item !== null and !is_array($item) and
                              (strlen(Perl::str($item instanceof ScalarRef ? $item->value : $item)) or $level == 1))
                    {
                        self::AddNewTag($et, $fieldInfo, $capture, $p, $item, $langIdx);
                        $addedTag = 1;
                    }
                    ++$changed;
                    ++$i;
                }
            } else {
                self::AddNewTag($et, $fieldInfo, $capture, $path, $val, $langIdx);
                $addedTag = 1;
                ++$changed;
            }
            // this is tricky, but we must add the rdf:type for contained structures
            // in the case that a whole hierarchy was added at once by writing a
            // flattened tag inside a variable-namespace structure
            if ($addedTag and !empty($fieldInfo['StructType']) and !empty($fieldInfo['Table'])) {
                self::AddStructType($et, $fieldInfo['Table'], $capture, $propPath, $basePath);
            }
        }
        // add 'rdf:type' property if necessary
        if (!empty($strTable['TYPE']) and $changed) {
            $path = $basePath . '/' . self::ConformPathToNamespace($et, 'rdf:type');
            if (!isset($capture[$path])) {
                $capture[$path] = ['', ['rdf:resource' => $strTable['TYPE']]];
                if ($verbose > 1) {
                    $p = preg_replace_callback('/^(\w+)/', static fn ($m) => (Vars\XMP::$stdXlatNS[$m[1]] ?? null) ?: $m[1], $path, 1);
                    $et->VerboseValue("+ XMP-$p", $strTable['TYPE']);
                }
            }
        }
        return $changed;
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
        // (Perl iterates in hash order, which changes from one run to the next; the port uses
        // the order the tags were found, so the first of several items placed in the same
        // non-list structure always wins: see PORTING.md, "Known difference")
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

    /** Perl ref $val eq 'HASH' for a structure value (EMPTY_STRUCT included) */
    private static function IsHash($val): bool
    {
        return is_array($val) and !array_is_list($val);
    }

    /** a structure value without fields (Perl: not %$struct) */
    private static function IsEmptyStruct($struct): bool
    {
        return !$struct or $struct === self::EMPTY_STRUCT;
    }

    /** Perl $$fieldInfo{Struct} is true (a circular reference is generated as a null Struct) */
    private static function HasStruct($fieldInfo): bool
    {
        return is_array($fieldInfo) and (!empty($fieldInfo['Struct']) or
            (array_key_exists('Struct', $fieldInfo) and $fieldInfo['Struct'] === null));
    }

    /**
     * Perl $$strTable{$tag}{Struct}: a reference to the structure definition of a field of
     * $strTable, which is $strTable itself for a circular reference (call HasStruct() first)
     */
    private static function &FieldStruct(array &$strTable, $tag): array
    {
        if ($strTable[$tag]['Struct'] === null) return $strTable;
        return $strTable[$tag]['Struct'];
    }

    /** reverse sort grep /^$tag$/i, keys %$strTable: the last key matching $tag (any case), or null */
    private static function FixCase($tag, array $strTable)
    {
        $keys = @preg_grep(ExifTool::Rx('^' . $tag . '$', 'i'), array_map('strval', array_keys($strTable))) ?: [];
        if (!$keys) return null;
        sort($keys, SORT_STRING);
        return end($keys);
    }

    /** grep /RE/, keys %$hash (RE: a Perl regular expression in a string) */
    private static function GrepKeys(string $re, array $hash, string $mods = ''): array
    {
        return array_values(@preg_grep(ExifTool::Rx($re, $mods), array_map('strval', array_keys($hash))) ?: []);
    }

    /** Perl $str =~ s/RE// (the first match is removed): true if it matched, the groups in $m */
    private static function Subst(string $re, &$str, &$m = null): bool
    {
        if (!preg_match($re, Perl::str($str), $mm, PREG_OFFSET_CAPTURE)) return false;
        $str = substr($str, 0, $mm[0][1]) . substr($str, $mm[0][1] + strlen($mm[0][0]));
        $m = array_map(static fn ($g) => $g[1] < 0 ? '' : $g[0], $mm);
        return true;
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
