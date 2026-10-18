<?php

//------------------------------------------------------------------------------
// File:         XMP.pm
//
// Description:  Read XMP meta information
//
// Revisions:    11/25/2003 - P. Harvey Created
//               10/28/2004 - P. Harvey Major overhaul to conform with XMP spec
//               02/27/2005 - P. Harvey Also read UTF-16 and UTF-32 XMP
//               08/30/2005 - P. Harvey Split tag tables into separate namespaces
//               10/24/2005 - P. Harvey Added ability to parse .XMP files
//               08/25/2006 - P. Harvey Added ability to handle blank nodes
//               08/22/2007 - P. Harvey Added ability to handle alternate language tags
//               09/26/2008 - P. Harvey Added Iptc4xmpExt tags (version 1.0 rev 2)
//
// References:   1) http://www.adobe.com/products/xmp/pdfs/xmpspec.pdf
//               2) http://www.w3.org/TR/rdf-syntax-grammar/  (20040210)
//               3) http://www.portfoliofaq.com/pfaq/v7mappings.htm
//               4) http://www.iptc.org/IPTC4XMP/
//               5) http://creativecommons.org/technology/xmp
//                  --> changed to http://wiki.creativecommons.org/Companion_File_metadata_specification (2007/12/21)
//               6) http://www.optimasc.com/products/fileid/xmp-extensions.pdf
//               7) Lou Salkind private communication
//               8) http://partners.adobe.com/public/developer/en/xmp/sdk/XMPspecification.pdf
//               9) http://www.w3.org/TR/SVG11/
//               10) http://www.adobe.com/devnet/xmp/pdfs/XMPSpecificationPart2.pdf (Oct 2008)
//               11) http://www.extensis.com/en/support/kb_article.jsp?articleNumber=6102211
//               12) http://www.cipa.jp/std/documents/e/DC-010-2012_E.pdf
//               13) http://www.cipa.jp/std/documents/e/DC-010-2017_E.pdf (changed to
//                   http://www.cipa.jp/std/documents/e/DC-X010-2017.pdf)
//
// Notes:      - Property qualifiers are handled as if they were separate
//               properties (with no associated namespace).
//
//             - Currently, there is no special treatment of the following
//               properties which could potentially affect the extracted
//               information: xml:base, rdf:parseType (note that parseType
//               Literal isn't allowed by the XMP spec).
//
//             - The family 2 group names will be set to 'Unknown' for any XMP
//               tags not found in the XMP or Exif tag tables.
//
// PHP port of Image::ExifTool::XMP 3.79. See PORTING.md.
// The subs of XMPStruct.pl, XMP2.pl and WriteXMP.pl (same Perl package) are in the traits
// of src/XMP/. The tag tables are generated (tables/XMP.php),
// the package variables and file lexicals are in Vars\XMP (tables/vars/XMP.php).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

class XMP
{
    use XMP\XMPStruct;
    use XMP\XMP2;
    use XMP\WriteXMP;

    public static $VERSION = '3.79';

    // special tags in structures below
    // NOTE: this lookup is duplicated in TagLookup.pm!!
    // (PHP: not taken from the generated tables, which treat %specialStruct as a tag table
    //  and drop its NOTES entry)
    public static $specialStruct = [
        'STRUCT_NAME' => 1, // [optional] name of structure
        'NAMESPACE'   => 1, // [mandatory for XMP] namespace prefix used for fields of this structure
        'NOTES'       => 1, // [optional] notes for documentation about this structure
        'TYPE'        => 1, // [optional] rdf:type resource for struct (if used, the StructType flag
                            // will be set automatically for all derived flattened tags when writing)
        'GROUPS'      => 1, // [optional] specifies family group 2 name for the structure
        'SORT_ORDER'  => 1, // [optional] order for sorting fields in documentation
    ];

    /** PrintConv '$self->ConvertDateTime($val)' given to unknown date/time tags by FoundXMP */
    private static ?\Closure $convertDateTime = null;

    //------------------------------------------------------------------------------
    // Escape necessary XML characters in UTF-8 string
    // Inputs: 0) string to be escaped
    // Returns: escaped string
    public static function EscapeXML($str)
    {
        $str = Perl::str($str);
        // escape necessary XML characters
        return preg_replace_callback('/([&><\'"])/s', static fn ($m) => '&' . Vars\XMP::$charName[$m[1]] . ';', $str);
    }

    //------------------------------------------------------------------------------
    // Unescape XML character references (entities and numerical)
    // Inputs: 0) string to be unescaped
    //         1) optional hash reference to convert entity names to numbers
    //         2) optional character encoding
    // Returns: unescaped string
    public static function UnescapeXML($str, $conv = null, $enc = null)
    {
        if (!$conv) $conv = Vars\XMP::$charNum;
        $new = preg_replace_callback('/&(#?\w+);/s', static fn ($m) => self::UnescapeChar($m[1], $conv, $enc),
            Perl::str($str), -1, $count);
        // (like Perl's s///, leave the value untouched if nothing was replaced: it may be a ScalarRef)
        return $count ? $new : $str;
    }

    //------------------------------------------------------------------------------
    // Escape string for XML, ensuring valid XML and UTF-8
    // Inputs: 0) string
    // Returns: escaped string
    public static function FullEscapeXML($str)
    {
        $str = self::EscapeXML($str);                // escape necessary XML characters
        $str = str_replace('\\', '&#92;', $str);    // escape backslashes too
        // then use C-escape sequences for invalid characters
        if (preg_match('/[\0-\x1f]/', $str) or ExifTool::IsUTF8($str) < 0) {
            $str = preg_replace_callback('/([\0-\x1f\x7f-\xff])/s', static fn ($m) => Perl::sprintf('\\x%.2x', ord($m[1])), $str);
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Unescape XML/C escaped string
    // Inputs: 0) string
    // Returns: unescaped string
    public static function FullUnescapeXML($str)
    {
        // unescape C escape sequences first
        $str = preg_replace_callback('/\\\\x([\da-f]{2})/s', static fn ($m) => chr(hexdec($m[1])), Perl::str($str));
        $conv = Vars\XMP::$charNum;
        return preg_replace_callback('/&(#?\w+);/s', static fn ($m) => self::UnescapeChar($m[1], $conv), $str);
    }

    //------------------------------------------------------------------------------
    // Convert XML character reference to UTF-8
    // Inputs: 0) XML character reference stripped of the '&' and ';' (eg. 'quot', '#34', '#x22')
    //         1) hash reference for looking up character numbers by name
    //         2) optional character encoding (default 'UTF8')
    // Returns: UTF-8 equivalent (or original character on conversion error)
    public static function UnescapeChar($ch, $conv, $enc = null)
    {
        $val = $conv[$ch] ?? null;
        if ($val === null) {
            if (preg_match('/^#x([0-9a-fA-F]+)$/', $ch, $m)) {
                $val = hexdec($m[1]);
            } elseif (preg_match('/^#(\d+)$/', $ch, $m)) {
                $val = Perl::num($m[1]);
            } else {
                return "&$ch;"; // should issue a warning here? [no]
            }
        }
        if ($val < 0x80) return chr((int) $val);   // simple ASCII
        $val = ExifTool::PackUTF8($val);            // (Perl: pack('C0U', $val))
        if ($enc and $enc !== 'UTF8') $val = ExifTool::DecodeStatic(null, $val, 'UTF8', null, $enc);
        return $val;
    }

    //------------------------------------------------------------------------------
    // Fix malformed UTF8 (by replacing bad bytes with specified character)
    // Inputs: 0) string reference, 1) string to replace each bad byte,
    //         may be '' to delete bad bytes, or undef to use '?'
    // Returns: true if string was fixed, and updates string
    public static function FixUTF8(&$strPt, $bad = null)
    {
        $fixed = null;
        $strPt = Perl::str($strPt);
        $p = 0; // start at beginning of string
        for (;;) {
            if (!preg_match('/([\x80-\xff])/', $strPt, $m, PREG_OFFSET_CAPTURE, $p)) break;
            $ch = ord($m[1][0]);
            $pos = $p = $m[1][1] + 1;
            // (see comments in Image::ExifTool::IsUTF8())
            if ($ch >= 0xc2 and $ch < 0xf8) {
                $n = $ch < 0xe0 ? 1 : ($ch < 0xf0 ? 2 : 3);
                if (preg_match('/\G([\x80-\xbf]{' . $n . '})/', $strPt, $mm, 0, $p)) {
                    $p += $n;
                    if ($n == 1) continue;
                    $c1 = ord($mm[1][0]);
                    if ($n == 2) {
                        if (!(($ch == 0xe0 and ($c1 & 0xe0) == 0x80) or
                              ($ch == 0xed and ($c1 & 0xe0) == 0xa0) or
                              ($ch == 0xef and $c1 == 0xbf and
                                  (ord($mm[1][1]) & 0xfe) == 0xbe))) continue;
                    } else {
                        if (!(($ch == 0xf0 and ($c1 & 0xf0) == 0x80) or
                              ($ch == 0xf4 and $c1 > 0x8f) or $ch > 0xf4)) continue;
                    }
                }
            }
            // replace bad character
            if ($bad === null) $bad = '?';
            $strPt = substr_replace($strPt, $bad, $pos - 1, 1);
            $p = $pos - 1 + strlen($bad);
            $fixed = 1;
        }
        return $fixed;
    }

    //------------------------------------------------------------------------------
    // Utility routine to decode a base64 string
    // Inputs: 0) base64 string
    // Returns: reference to decoded data
    public static function DecodeBase64($str): ScalarRef
    {
        // truncate at first unrecognized character (base 64 data
        // may only contain A-Z, a-z, 0-9, +, /, =, or white space)
        $str = preg_replace('/[^A-Za-z0-9+\/= \t\n\r\f].*/s', '', Perl::str($str));
        // remove padding and white space
        $str = preg_replace('/[= \t\n\r\f]+/', '', $str);
        // (ponytail: base64_decode() replaces Perl's uudecode of 60-character chunks; the
        //  only difference is that Perl reads a lone character at the end of the last
        //  partial chunk as the length byte of a new uuencoded line, giving that many nulls)
        $dat = base64_decode($str);
        $len = strlen($str) % 60;
        if ($len > 1 and $len % 4 == 1) {
            $dat .= str_repeat("\0", strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', $str[-1]));
        }
        return new ScalarRef($dat);
    }

    //------------------------------------------------------------------------------
    // Generate a tag ID for this XMP tag
    // Inputs: 0) tag property name list ref, 1) array ref for receiving structure property list
    //         2) array for receiving namespace list
    // Returns: tagID and outtermost interesting namespace (or '' if no namespace)
    // (PHP: always the list [tagID, namespace]; pass null for 1 to not receive the structure list)
    public static function GetXMPTagID(array $props, ?array &$structProps = null, ?array &$nsList = null): array
    {
        $tag = $namespace = null;
        foreach ($props as $prop) {
            // split name into namespace and property name
            // (Note: namespace can be '' for property qualifiers)
            [$ns, $nm] = preg_match('/(.*?):(.*)/', $prop, $m) ? [$m[1], $m[2]] : ['', $prop];
            if (!empty(Vars\XMP::$ignoreNamespace[$ns]) or !empty(Vars\XMP::$ignoreProp[$prop]) or
                !empty(Vars\XMP::$ignoreEtProp[$prop]))
            {
                // special case: don't ignore rdf numbered items
                // (not technically allowed in XMP, but used in RDF/XML)
                if (!preg_match('/^rdf:(_\d+)$/', $prop, $m)) {
                    // save list index if necessary for structures
                    if ($structProps and preg_match('/^rdf:li (\d+)$/', $prop, $m)) {
                        $structProps[count($structProps) - 1][] = $m[1];
                    }
                    continue;
                }
                if ($tag !== null) $tag .= $m[1];
            } else {
                $nm = preg_replace('/ .*/', '', $nm); // remove nodeID if it exists
                // all uppercase is ugly, so convert it
                if (!preg_match('/[a-z]/', $nm)) {
                    $xlat = (Vars\XMP::$stdXlatNS[$ns] ?? null) ?: $ns;
                    $info = ExifTool::PackageTable('Image::ExifTool::XMP::Main')[$xlat];
                    $table = null;
                    if ($info instanceof TagInfo and $info->SubDirectory) {
                        $table = ExifTool::GetTagTable($info->SubDirectory['TagTable']);
                    }
                    if (!($table and $table[$nm])) {
                        $nm = strtolower($nm);
                        $nm = preg_replace_callback('/_([a-z])/', static fn ($m) => strtoupper($m[1]), $nm);
                    }
                }
                if ($tag !== null) {
                    $tag .= ucfirst($nm);       // add to tag name
                } else {
                    $tag = $nm;
                }
                // save structure information if necessary
                if ($structProps !== null) {
                    $structProps[] = [$nm];
                    if ($nsList !== null) $nsList[] = $ns;
                }
            }
            // save namespace of first property to contribute to tag name
            if (!$namespace) $namespace = $ns;
        }
        return [$tag, $namespace ?: ''];
    }

    //------------------------------------------------------------------------------
    // Register namespace for specified user-defined table
    // Inputs: 0) tag/structure table ref
    // Returns: namespace prefix
    // (PHP: a structure definition is an array, changed in place like the Perl hash)
    public static function RegisterNamespace(&$table)
    {
        if (!is_array($table['NAMESPACE'] ?? null)) return $table['NAMESPACE'] ?? null;
        $nsRef = $table['NAMESPACE'];
        // recognize as either a list or hash
        $ns = null;
        if (array_is_list($nsRef)) {
            $ns = $nsRef[0];
            Vars\XMP::$nsURI[$ns] = $nsRef[1];
            Vars\XMP::$uri2ns[$nsRef[1]] = $ns;
        } else { // must be a hash
            $nsList = array_map('strval', array_keys($nsRef)); // allow multiple namespace definitions
            sort($nsList, SORT_STRING);
            while ($nsList) {
                $ns = array_pop($nsList);
                if (!empty(Vars\XMP::$nsURI[$ns]) and Vars\XMP::$nsURI[$ns] !== $nsRef[$ns]) {
                    ExifTool::PerlWarn("User-defined namespace prefix '{$ns}' conflicts with existing namespace\n");
                }
                Vars\XMP::$nsURI[$ns] = $nsRef[$ns];
                Vars\XMP::$uri2ns[$nsRef[$ns]] = $ns;
            }
        }
        return $table['NAMESPACE'] = $ns;
    }

    //------------------------------------------------------------------------------
    // Generate flattened tags and add to table
    // Inputs: 0) tag table ref, 1) tag ID for Struct tag (if not defined, whole table is done),
    //         2) flag to not expand sub-structures, 3) Hidden flag
    // Returns: number of tags added (not counting those just initialized)
    // Notes: Must have verified that $$tagTablePtr{$tagID}{Struct} exists before calling this routine
    // - makes sure that the tagInfo Struct is a HASH reference
    public static function AddFlattenedTags(TagTable $tagTablePtr, $tagID = null, $noSubStruct = null, $hidden = null)
    {
        $count = 0;
        $tagIDs = [];

        if ($tagID !== null) {
            $tagIDs[] = $tagID;
        } else {
            foreach (ExifTool::TagTableKeys($tagTablePtr) as $tagID) {
                $tagInfo = $tagTablePtr[$tagID];
                if (!($tagInfo instanceof TagInfo and $tagInfo->Struct)) continue;
                $tagIDs[] = $tagID;
            }
        }

        // loop through specified tags
        foreach ($tagIDs as $tagID) {

            $tagInfo = $tagTablePtr[$tagID];

            if ($tagInfo->Flattened) continue;  // only generate flattened tags once
            $tagInfo->Flattened = 1;

            $strTable = $tagInfo->Struct;
            if (!is_array($strTable)) { // (allow a structure name for backward compatibility only)
                $strTable = ExifTool::$UserXmpStruct[$strTable] ?? null;
                if (!$strTable) continue;
            }

            // get prefix for flattened tag names
            $flat = ($tagInfo->FlatName !== null ? $tagInfo->FlatName : $tagInfo->Name);

            // get family 2 group name for this structure tag
            $tagG2 = null;
            if ($tagInfo->Groups) $tagG2 = $tagInfo->Groups[2] ?? null;
            if (!$tagG2) $tagG2 = $tagTablePtr['GROUPS'][2] ?? null;

            foreach ($strTable as $field => $fieldInfo) {
                $field = (string) $field;
                if (isset(self::$specialStruct[$field]) or !is_array($fieldInfo)) continue;
                // (PHP: the generator cuts the self-referential structures with a null Struct:
                //  this field is a structure of the same type as the one containing it)
                if (array_key_exists('Struct', $fieldInfo) and $fieldInfo['Struct'] === null) $fieldInfo['Struct'] = $strTable;
                if (!empty($fieldInfo['LangCode'])) continue;  // don't flatten lang-alt tags
                if (!empty($fieldInfo['Struct']) and $noSubStruct) continue;   // don't expand sub-structures if specified
                // build a tag ID for the corresponding flattened tag
                $fieldName = ucfirst($field);
                $flatField = ($fieldInfo['FlatName'] ?? null) ?: $fieldName;
                $flatID = $tagID . $fieldName;
                $flatInfo = $tagTablePtr[$flatID];
                if ($flatInfo) {
                    if (!$flatInfo instanceof TagInfo) { // (to be safe)
                        ExifTool::PerlWarn(Perl::str($flatInfo) . " is not a HASH!\n");
                        continue;
                    }
                    // pre-defined flattened tags should have Flat flag set
                    if ($flatInfo->Flat === null) {
                        if ($flatInfo->NotFlat) continue;
                        // (warns "Missing Flat flag" if $Image::ExifTool::debug)
                    }
                    $flatInfo->Flat = 0;
                    // copy all missing entries from field information
                    foreach ($fieldInfo as $k => $v) {
                        // must not copy PropertyPath (but can't delete it afterwards
                        // because the flat tag may already have this set)
                        if ($k === 'PropertyPath' or $flatInfo->$k !== null) continue;
                        // copy the property (making a copy of the Groups hash)
                        $flatInfo->$k = $v;
                    }
                    // (NOTE: Can NOT delete Groups because we need them if GotGroups was done)
                    // re-generate List flag unless it is set to 0
                    if ($flatInfo->List) unset($flatInfo->List);
                } else {
                    // generate new flattened tag information based on structure field
                    $flatName = $flat . $flatField;
                    $flatInfo = new TagInfo($fieldInfo);
                    $flatInfo->Name = $flatName;
                    $flatInfo->Flat = 0;
                    if (!$hidden) $flatInfo->Hidden = 0;
                    if (!empty($fieldInfo['FlatName'])) $flatInfo->FlatName = $flatName;
                    // add new flattened tag to table
                    ExifTool::AddTagToTable($tagTablePtr, $flatID, $flatInfo);
                    ++$count;
                }
                // propagate List flag (unless set to 0 in pre-defined flattened tag)
                if ($flatInfo->List === null) {
                    if (!empty($fieldInfo['List']) or $tagInfo->List) $flatInfo->List = ($fieldInfo['List'] ?? null) ?: 1;
                }
                // set group 2 name from the first existing family 2 group in the:
                // 1) structure field Groups, 2) structure table GROUPS, 3) structure tag Groups
                $flatInfo->Groups ??= [];
                if (!empty($fieldInfo['Groups']) and !empty($fieldInfo['Groups'][2])) {
                    $flatInfo->Groups[2] = $fieldInfo['Groups'][2];
                } elseif (!empty($strTable['GROUPS']) and !empty($strTable['GROUPS'][2])) {
                    $flatInfo->Groups[2] = $strTable['GROUPS'][2];
                } else {
                    $flatInfo->Groups[2] = $tagG2;
                }
                // save reference to top-level and parent structures
                $flatInfo->RootTagInfo = $tagInfo->RootTagInfo ?: $tagInfo;
                $flatInfo->ParentTagInfo = $tagInfo;
                // recursively generate flattened tags for sub-structures
                if (!$flatInfo->Struct) continue;
                if (strlen($flatID) > 250) {
                    ExifTool::PerlWarn("Possible deep recursion for tag $flatID\n");
                    break;
                }
                // reset flattened tag just in case we flattened hierarchy in the wrong order
                // because we must start from the outtermost structure to get the List flags right
                // (this should only happen when building tag tables)
                unset($flatInfo->Flattened);
                $count += self::AddFlattenedTags($tagTablePtr, $flatID, $flatInfo->NoSubStruct);
            }
        }
        return $count;
    }

    //------------------------------------------------------------------------------
    // Get localized version of tagInfo hash
    // Inputs: 0) tagInfo hash ref, 1) language code (eg. "x-default")
    // Returns: new tagInfo hash ref, or undef if invalid
    public static function GetLangInfo(TagInfo $tagInfo, $langCode): ?TagInfo
    {
        // only allow alternate language tags in lang-alt lists
        if (!($tagInfo->Writable and $tagInfo->Writable === 'lang-alt')) return null;
        $langCode = strtr(Perl::str($langCode), '_', '-');   // RFC 3066 specifies '-' as a separator
        $langInfo = ExifTool::GetLangInfo($tagInfo, $langCode);
        return $langInfo;
    }

    //------------------------------------------------------------------------------
    // Get standard case for language code
    // Inputs: 0) Language code
    // Returns: Language code in standard case
    public static function StandardLangCase($lang)
    {
        $lang = Perl::str($lang);
        // make 2nd subtag uppercase only if it is 2 letters
        if (preg_match('/^([a-z]{2,3}|[xi])(-[a-z]{2})\b(.*)/i', $lang, $m)) {
            return strtolower($m[1]) . strtoupper($m[2]) . strtolower($m[3]);
        }
        return strtolower($lang);
    }

    //------------------------------------------------------------------------------
    // Scan for XMP in a file
    // Inputs: 0) ExifTool object ref, 1) RAF reference
    // Returns: 1 if xmp was found, 0 otherwise
    // Notes: Currently only recognizes UTF8-encoded XMP
    public static function ScanForXMP(ExifTool $et, $raf)
    {
        $buff = $xmp = null;
        $lastBuff = '';

        $et->VPrint(0, "Scanning for XMP\n");
        for (;;) {
            if ($buff === null and !$raf->Read($buff, 65536)) return 0;
            if ($xmp === null) {
                $lastBuff .= $buff;
                if (!preg_match('/(<\?xpacket begin=)/', $lastBuff, $m, PREG_OFFSET_CAPTURE)) {
                    // must keep last 15 bytes to match 16-byte "xpacket begin" string
                    $lastBuff = strlen($buff) <= 15 ? $buff : substr($buff, -15);
                    $buff = null;
                    continue;
                }
                $xmp = $m[1][0];
                $buff = substr($lastBuff, $m[1][1] + strlen($m[1][0]));
            }
            $pos = strlen($xmp) - 18;   // (18 = length("<?xpacket end...") - 1)
            $xmp .= $buff;              // add new data to our XMP
            // (set start for "xpacket end" scan)
            if (preg_match('/<\?xpacket end=[\'"][wr][\'"]\?>/', $xmp, $m, PREG_OFFSET_CAPTURE, $pos > 0 ? $pos : 0)) {
                $end = $m[0][1] + strlen($m[0][0]);
                $buff = substr($xmp, $end);    // save data after end of XMP
                $xmp = substr($xmp, 0, $end);  // isolate XMP
                // check XMP for validity (not valid if it contains null bytes)
                $pos = Perl::rindex($xmp, "\0") + 1;
                if (!$pos) break;
                $lastBuff = substr($xmp, $pos);     // re-parse beginning after last null byte
                $xmp = null;
            } else {
                $buff = null;
            }
        }
        if (!$et->FileType) {
            $et->FILE_TYPE = $et->FILE_EXT;
            $et->SetFileType('<unknown file containing XMP>', null, '');
        }
        $dirInfo = [
            'DataPt'  => $xmp,
            'DirLen'  => strlen($xmp),
            'DataLen' => strlen($xmp),
        ];
        self::ProcessXMP($et, $dirInfo);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Print conversion for XMP-aux:LensID
    // Inputs: 0) ExifTool ref, 1) LensID, 2) Make, 3) LensInfo, 4) FocalLength,
    //         5) LensModel, 6) MaxApertureValue
    // (yes, this is ugly -- blame Adobe)
    public static function PrintLensID(ExifTool $et, $id = null, $make = null, $info = null, $focalLength = null,
        $lensModel = null, $maxAv = null)
    {
        $alt = ['Pentax' => 'Ricoh'];   // Pentax changed its name to Ricoh
        // missing: Olympus (no XMP:LensID written by Adobe)
        foreach (['Canon', 'Nikon', 'Pentax', 'Sony', 'Sigma', 'Samsung', 'Leica'] as $mk) {
            if (!(preg_match("/$mk/i", Perl::str($make)) or (isset($alt[$mk]) and preg_match("/$alt[$mk]/i", Perl::str($make))))) continue;
            // get name of module containing the lens lookup (default "Make.pm")
            $mod = ['Sigma' => 'SigmaRaw', 'Leica' => 'Panasonic'][$mk] ?? $mk;
            ExifTool::RequireModule("Image::ExifTool::$mod");
            // get the name of the lens name lookup (default "makeLensTypes")
            // (canonLensTypes, pentaxLensTypes, nikonLensIDs, etc)
            $convName = ['Nikon' => 'nikonLensIDs'][$mk] ?? (strtolower($mk) . 'LensTypes');
            $class = "Image\\ExifTool\\Vars\\$mod";
            $printConv = (class_exists($class) and property_exists($class, $convName)) ? $class::$$convName : null;
            if (!$printConv) break;
            // sf = short focal
            // lf = long focal
            // sa = max aperture at short focal
            // la = max aperture at long focal
            $sf = $lf = $sa = $la = null;
            if ($info) {
                $a = Perl::split(' ', $info);
                foreach ($a as &$v) {
                    if ($v === 'undef') $v = null;
                }
                unset($v);
                [$sf, $lf, $sa, $la] = $a + [null, null, null, null];
                // for Sony and ambiguous LensID, $info data may be incorrect:
                // use only if it agrees with $focalLength and $maxAv (ref JR)
                if ($mk === 'Sony' and
                    (($focalLength and (($sf and Perl::num($focalLength) < Perl::num($sf) - 0.5) or
                                        ($lf and Perl::num($focalLength) > Perl::num($lf) + 0.5))) or
                     ($maxAv and (($sa and Perl::num($maxAv) < Perl::num($sa) - 0.15) or
                                  ($la and Perl::num($maxAv) > Perl::num($la) + 0.15)))))
                {
                    $sf = $lf = $sa = $la = null;
                } elseif ($maxAv) {
                    // (using the short-focal-length max aperture in place of MaxAperture
                    // is a bad approximation, so don't do this if MaxApertureValue exists)
                    $sa = null;
                }
            }
            if ($mk === 'Pentax' and preg_match('/^\d+$/', Perl::str($id))) {
                // for Pentax, CS4 stores an int16u, but we use 2 x int8u
                $id = implode(' ', Perl::unpack('C*', Perl::pack('n', $id)));
            }
            // Nikon is a special case because Adobe doesn't store the full LensID
            // (Apple Photos does, but we have to convert back to hex)
            if ($mk === 'Nikon') {
                $id = Perl::sprintf('%X', $id);
                if (strlen($id) & 0x01) $id = "0$id";   // pad with leading 0 if necessary
                $id = preg_replace('/(..)/', '$1 ', $id, -1, $n);   // put spaces between bytes
                if ($n) $id = preg_replace('/ $/', '', $id);
                $newConv = $used = [];
                $i = 0;
                foreach (preg_grep('/^' . preg_quote($id, '/') . '/', array_map('strval', array_keys($printConv))) as $k) {
                    $lens = $printConv[$k];
                    if (isset($used[$lens])) continue; // avoid duplicates
                    $used[$lens] = 1;
                    $newConv[$i ? "$id.$i" : $id] = $lens;
                    ++$i;
                }
                $printConv = $newConv;
            }
            $str = ($printConv[$id] ?? null) ?: "Unknown ($id)";
            return Exif::PrintLensID($et, $str, $printConv,
                        null, $id, $focalLength, $sa, $maxAv, $sf, $lf, $lensModel);
        }
        return 'Unknown (' . Perl::str($id) . ')';
    }

    //------------------------------------------------------------------------------
    // Convert XMP date/time to EXIF format
    // Inputs: 0) XMP date/time string, 1) set if we aren't sure this is a date
    // Returns: EXIF date/time, and flag in list context if this was a standard date/time value
    // (PHP: scalar context; ConvertXMPDateList() is the list context)
    public static function ConvertXMPDate($val, $unsure = null)
    {
        return self::ConvertXMPDateList($val, $unsure)[0];
    }

    /** ConvertXMPDate in list context: [EXIF date/time, 1 if this was a standard date/time value] */
    public static function ConvertXMPDateList($val, $unsure = null): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}:\d{2})(:\d{2})?\s*(\S*)$/', Perl::str($val), $m)) {
            $s = $m[5] ?: '';           // seconds may be missing
            $val = "$m[1]:$m[2]:$m[3] $m[4]$s$m[6]";   // convert back to EXIF time format
            return [$val, 1];
        } elseif (!$unsure and preg_match('/^(\d{4})(-\d{2}){0,2}/', Perl::str($val))) {
            $val = strtr(Perl::str($val), '-', ':');
        }
        return [$val];
    }

    //------------------------------------------------------------------------------
    // Convert rational string value
    // Inputs: 0) string (converted to number, 'inf' or 'undef' on return if rational)
    // Returns: true if value was converted
    public static function ConvertRational(&$val)
    {
        if (!preg_match('{^(-?\d+)/(-?\d+)$}', Perl::str($val), $m)) return null;
        if (Perl::num($m[2]) != 0) {
            $val = Perl::num($m[1]) / Perl::num($m[2]); // calculate quotient
        } elseif ($m[1]) {
            $val = 'inf';
        } else {
            $val = 'undef';
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Convert a string of floating point values to rationals
    // Inputs: 0) string of floating point numbers separated by spaces
    // Returns: string of rational numbers separated by spaces
    public static function ConvertRationalList($val)
    {
        $vals = Perl::split(' ', $val);
        if (count($vals) != 4) return $val;
        foreach ($vals as &$v) {
            if (!self::ConvertRational($v)) return $val;
        }
        unset($v);
        return implode(' ', array_map(Perl::str(...), $vals));
    }

    //------------------------------------------------------------------------------
    // We found an XMP property name/value
    // Inputs: 0) ExifTool object ref, 1) Pointer to tag table
    //         2) reference to array of XMP property names (last is current property)
    //         3) property value, 4) attribute hash ref (for 'xml:lang' or 'rdf:datatype')
    // Returns: 1 if valid tag was found
    public static function FoundXMP(ExifTool $et, TagTable $tagTablePtr, array $props, $val, ?array $attrs = null)
    {
        $lang = $rawVal = $rational = null;
        $structProps = $et->OPTIONS['Struct'] ? [] : null;
        [$tag, $ns] = self::GetXMPTagID($props, $structProps);
        if (!$tag) return 0;    // ignore things that aren't valid tags

        // translate namespace if necessary
        if (!empty(Vars\XMP::$stdXlatNS[$ns])) $ns = Vars\XMP::$stdXlatNS[$ns];
        $info = $tagTablePtr[$ns];
        $table = $added = $xns = $tagID = null;
        if ($info) {
            $table = $info instanceof TagInfo ? ($info->SubDirectory['TagTable'] ?? null) : null;
            if (!$table) ExifTool::PerlWarn("Missing TagTable for $tag!\n");
        } elseif (($props[0] ?? null) === 'svg:svg') {
            if (!$ns) {
                // disambiguate MetadataID by adding back the 'metadata' we ignored
                if ($tag === 'id' and ($props[1] ?? null) === 'svg:metadata') $tag = 'metadataId';
                // use SVG namespace in SVG files if nothing better to use
                $table = 'Image::ExifTool::XMP::SVG';
            } elseif (!preg_grep('/^rdf:/', $props)) {
                // only other SVG information if not inside RDF (call it XMP if in RDF)
                $table = 'Image::ExifTool::XMP::otherSVG';
            }
        }

        $xmlGroups = null;
        $grp0 = $tagTablePtr['GROUPS'][0] ?? null;
        if (!$ns and $grp0 !== 'XMP') {
            $tagID = $tag;
        } elseif ($grp0 === 'XML' and !$table) {
            // this is an XML table (no namespace lookup)
            $tagID = "$ns:$tag";
        } else {
            if ($grp0 === 'XML') $xmlGroups = 1;
            // look up this tag in the appropriate table
            if (!$table) $table = 'Image::ExifTool::XMP::other';
            $tagTablePtr = ExifTool::GetTagTable($table);
            if ($tagTablePtr['NAMESPACE']) {
                $tagID = $tag;
            } else {
                $xns = Vars\XMP::$xmpNS[$ns] ?? null;
                if ($xns === null) {
                    $xns = $ns;
                    // validate namespace prefix
                    if (!(preg_match('/^[A-Z_a-z\x80-\xff][-.0-9A-Z_a-z\x80-\xff]*$/', $ns) or $ns === '')) {
                        $et->Warn("Invalid XMP namespace prefix '{$ns}'");
                        // clean up prefix for use as an ExifTool group name
                        $ns = preg_replace('/[^-.0-9A-Z_a-z\x80-\xff]/', '', $ns);
                        if (!preg_match('/^[A-Z_a-z\x80-\xff]/', $ns)) $ns = "ns_$ns";
                        Vars\XMP::$stdXlatNS[$xns] = $ns;
                        Vars\XMP::$xmpNS[$ns] = $xns;
                    }
                }
                // add XMP namespace prefix to avoid collisions in variable-namespace tables
                $tagID = "$xns:$tag";
                // add namespace to top-level structure property
                if ($structProps) $structProps[0][0] = "$xns:" . $structProps[0][0];
            }
        }
        $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);

        if ($attrs) $lang = $attrs['xml:lang'] ?? null;

        // must add a new tag table entry if this tag isn't pre-defined
        // (or initialize from structure field if this is a pre-defined flattened tag)
        // (Perl NoLoop: loop run at most once)
        while (!$tagInfo or $tagInfo->Flat) {
            $tagList = $nsList = [];
            self::GetXMPTagID($props, $tagList, $nsList);
            $t = $ti = $addedFlat = null;
            // build tag ID strings for each level in the property path
            foreach (array_keys($tagList) as $k) {
                // insert tag ID in index 1 of tagList list
                $t = $tagList[$k][1] = $t ? $t . ucfirst($tagList[$k][0]) : $tagList[$k][0];
                // generate flattened tags for top-level structure if necessary
                if ($addedFlat !== null) continue;
                $ti = $tagTablePtr[$t];
                if (!$ti) continue;
                if (!($ti instanceof TagInfo and $ti->Struct)) continue;
                $addedFlat = self::AddFlattenedTags($tagTablePtr, $t);
                // all done if we generated the tag we are looking for
                if ($addedFlat and ($tagInfo = $tagTablePtr[$tagID])) break 2;
            }
            $name = ucfirst($tag);

            // search for the innermost containing structure
            // (in case tag is an unknown field in a known structure)
            // (only necessary if we found a structure above)
            if ($addedFlat !== null) {
                $t2 = '';
                for ($i = count($tagList) - 2; $i >= 0; --$i) {
                    $t = $tagList[$i][1];
                    $t2 = $tagList[$i + 1][0] . ucfirst($t2); // build relative tag id
                    $ti = $tagTablePtr[$t];
                    if (!$ti) continue;
                    if (!$ti instanceof TagInfo) continue;
                    $strTable = $ti->Struct;
                    if (!$strTable) continue;
                    $flat = ($ti->FlatName !== null ? $ti->FlatName : $ti->Name);
                    $name = $flat . ucfirst($t2);
                    // don't continue if structure is known but field is not
                    if (!is_array($strTable) or !empty($strTable['NAMESPACE']) or !array_key_exists('NAMESPACE', $strTable)) break;
                    // this is a variable-namespace structure, so we must:
                    // 1) get tagInfo from corresponding top-level XMP tag if it exists
                    // 2) add new entry in this tag table, but with namespace prefix on tag ID
                    $n = $nsList[$i + 1];   // namespace of structure field
                    // translate to standard ExifTool namespace
                    if (!empty(Vars\XMP::$stdXlatNS[$n])) $n = Vars\XMP::$stdXlatNS[$n];
                    $xn = (Vars\XMP::$xmpNS[$n] ?? null) ?: $n; // standard XMP namespace
                    // no need to continue with variable-namespace logic if
                    // we are in our own namespace (right?)
                    if ($xn === ($tagTablePtr['NAMESPACE'] ?: '')) break;
                    $tagID = "$xn:$tag";    // add namespace to avoid collisions
                    // change structure properties to add the standard XMP namespace
                    // prefix for this field (needed for variable-namespace fields)
                    if ($structProps) {
                        $structProps[$i + 1][0] = "$xn:" . $structProps[$i + 1][0];
                    }
                    // copy tagInfo entries from the existing top-level XMP tag
                    $tg = ExifTool::PackageTable('Image::ExifTool::XMP::Main')[$n];
                    if (!($tg instanceof TagInfo and $tg->SubDirectory)) break;
                    $tbl = ExifTool::GetTagTable($tg->SubDirectory['TagTable']);
                    if (!$tbl) break;
                    $sti = $et->GetTagInfo($tbl, $t2);
                    if (!$sti or $sti->Flat) {
                        // again, we must initialize flattened tags if necessary
                        // (but don't bother to recursively apply full logic to
                        //  allow nested variable-namespace strucures until someone
                        //  actually wants to do such a silly thing)
                        $t3 = '';
                        for ($j = $i + 1; $j < count($tagList); ++$j) {
                            $t3 = $tagList[$j][0] . ucfirst($t3);
                            $ti3 = $tbl[$t3];
                            if (!$ti3) continue;
                            if (!($ti3 instanceof TagInfo and $ti3->Struct)) continue;
                            if (!self::AddFlattenedTags($tbl, $t3)) break;
                            $sti = $tbl[$t2];
                            break;
                        }
                        if (!$sti) break;
                    }
                    // use existing definition if we already added this tag
                    if ($tagTablePtr[$tagID]) {
                        $tagInfo = $tagTablePtr[$tagID];
                    } else {
                        // generate new tagInfo hash based on existing top-level tag
                        $tagInfo = clone $sti;
                        $tagInfo->Name = $flat . $sti->Name;
                        // be careful not to copy elements we shouldn't...
                        unset($tagInfo->Description); // Description will be different
                        // can't copy group hash because group 1 will be different and
                        // we need to check this when writing tag to a specific group
                        unset($tagInfo->Groups);
                        if ($sti->Groups) $tagInfo->Groups = [2 => $sti->Groups[2] ?? null];
                    }
                    break;
                }
            }
            // generate a default tagInfo hash if necessary
            if (!$tagInfo) {
                // shorten tag name if necessary
                if ($et->ShortenXmpTags) {
                    $shorten = $et->ShortenXmpTags;
                    $name = $shorten($name);
                }
                $tagInfo = new TagInfo(['Name' => $name, 'IsDefault' => 1, 'Priority' => 0]);
            }
            // add tag Namespace entry for tags in variable-namespace tables
            if ($xns) $tagInfo->Namespace = $xns;
            $curURI = $et->curURI[$ns] ?? null;
            if ($curURI and preg_match('#^http://ns.exiftool.(?:ca|org)/(.*?)/(.*?)/#', $curURI, $m)) {
                $grps = [0 => $m[1], 1 => $m[2]];
                // apply a little magic to recover original group names
                // from this exiftool-written RDF/XML file
                if ($grps[1] === 'System') {
                    $grps[1] = 'XML-System';
                    $grps[0] = 'XML';
                } elseif (preg_match('/^\d/', $grps[1])) {
                    // URI's with only family 0 are internal tags from the source file,
                    // so change the group name to avoid confusion with tags from this file
                    $grps[1] = "XML-$grps[0]";
                    $grps[0] = 'XML';
                }
                $tagInfo->Groups = $grps;
                // flag to avoid setting group 1 later
                $tagInfo->StaticGroup1 = 1;
            }
            // construct tag information for this unknown tag
            // -> make this a List or lang-alt tag if necessary
            if (count($props) > 2 and preg_match('/^rdf:li \d+$/', $props[count($props) - 1]) and
                preg_match('/^rdf:(Bag|Seq|Alt)$/', $props[count($props) - 2], $m))
            {
                if ($lang and $m[1] === 'Alt') {
                    $tagInfo->Writable = 'lang-alt';
                } else {
                    $tagInfo->List = $m[1];
                }
            // tried this, but maybe not a good idea for complex structures:
            //} elsif (grep / /, @$props) {
            //    $$tagInfo{List} = 1;
            }
            if (!($tagTablePtr[$tagID] and $tagTablePtr[$tagID] === $tagInfo)) {
                // save property list for verbose "adding" message unless this tag already exists
                if (!$tagTablePtr[$tagID]) $added = $tagList;
                // if this is an empty structure, we must add a Struct field
                if (!strlen(Perl::str($val)) and ($attrs['rdf:parseType'] ?? null) === 'Resource') {
                    if (!$tagInfo->Struct) $tagInfo->Struct = ['STRUCT_NAME' => 'XMP Unknown'];
                }
                $tagInfo->Hidden = 2;   // (don't show in -list outputs)
                ExifTool::AddTagToTable($tagTablePtr, $tagID, $tagInfo);
            }
            break;
        }
        // decode value if necessary (et:encoding was used before exiftool 7.71)
        if ($attrs) {
            $enc = ($attrs['rdf:datatype'] ?? null) ?: ($attrs['et:encoding'] ?? null);
            if ($enc and str_contains($enc, 'base64')) {
                $val = self::DecodeBase64($val); // (now a value ref)
                // (sic: \0x0c in this character class matches "\0", "x", "0" and "c")
                if (!(strlen($val->value) > 100 or preg_match('/[\0-\x08\x0b\0x0c\x0e-\x1f]/', $val->value))) $val = $val->value;
            }
        }
        if ($lang !== null and strtolower($lang) !== 'x-default') {
            $lang = self::StandardLangCase($lang);
            $langInfo = self::GetLangInfo($tagInfo, $lang);
            if ($langInfo) $tagInfo = $langInfo;
        }
        // un-escape XML character entities (handling CDATA)
        $str = Perl::str($val);
        if (preg_match_all('/<!\[CDATA\[(.*?)\]\]>/s', $str, $cdata, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            // unescape everything up to the start of each CDATA section
            $v = '';
            $p = 0;
            foreach ($cdata as $c) {
                $v .= self::UnescapeXML(substr($str, $p, $c[0][1] - $p)) . $c[1][0];
                $p = $c[0][1] + strlen($c[0][0]);
            }
            $val = $v . self::UnescapeXML(substr($str, $p));
        } else {
            $val = self::UnescapeXML($val);
        }
        // decode from UTF8
        $val = $et->Decode($val, 'UTF8');
        // convert rational and date values to a more sensible format
        $fmt = $tagInfo->Writable;
        $new = $tagInfo->IsDefault && $et->OPTIONS['XMPAutoConv'];
        if ($fmt or $new) {
            $rawVal = $val; // save raw value for verbose output
            if (($new or $fmt === 'rational') and self::ConvertRational($val)) {
                $rational = $rawVal;
            } else {
                $stdDate = null;
                if ($new or $fmt === 'date') {
                    $r = self::ConvertXMPDateList($val, $new);
                    [$val, $stdDate] = [$r[0], $r[1] ?? null];
                }
                if ($stdDate and $added !== null) {
                    $tagInfo->Groups ??= [];
                    $tagInfo->Groups[2] = 'Time';
                    $tagInfo->PrintConv = self::$convertDateTime ??= static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
                        return $self->ConvertDateTime($val);
                    };
                }
            }
            if ($et->XmpValidate !== null and $fmt and $fmt === 'boolean' and !preg_match('/^True|False$/', Perl::str($val))) {
                if (preg_match('/^true|false$/', Perl::str($val))) {
                    $et->Warn("Boolean value for XMP-$ns:{$tagInfo->Name} should be capitalized", 1);
                } else {
                    $et->Warn("Boolean value for XMP-$ns:{$tagInfo->Name} should be \"True\" or \"False\"", 1);
                }
            }
            // protect against large binary data in unknown tags
            if ($new and strlen(Perl::str($val)) > 65536) $tagInfo->Binary = 1;
        }
        if ($et->OPTIONS['Verbose']) {
            $tagID = implode('/', $props);
            $et->VerboseInfo($tagID, $tagInfo, Value: $rawVal ?: $val);
        }
        // store the value for this tag
        $key = $et->FoundTag($tagInfo, $val);
        if (!$key) return 0;
        // save original components of rational numbers (used when copying)
        if ($rational !== null) $et->TAG_EXTRA[$key]['Rational'] = $rational;
        // save structure/list information if necessary
        if ($structProps and (count($structProps) > 1 or isset($structProps[0][1])) and
            !$et->NO_STRUCT)
        {
            $et->TAG_EXTRA[$key]['Struct'] = $structProps;
            $et->IsStruct = 1;
        }
        if ($xmlGroups) {
            $et->SetGroup($key, 'XML', 0);
            $et->SetGroup($key, "XML-$ns", 1);
        } elseif ($ns and !$tagInfo->StaticGroup1) {
            // set group1 dynamically according to the namespace
            $et->SetGroup($key, ($tagTablePtr['GROUPS'][0] ?? '') . "-$ns");
        }
        if ($added !== null and $et->OPTIONS['Verbose']) {
            if (count($added) > 1) {
                $tagInfo->Flat = 0;     // this is a flattened tag
                $propList = array_map(static fn ($a) => $a[0], $added);
                $props = ' (' . implode('/', $propList) . ')';
            } else {
                $props = '';
            }
            $g1 = $et->GetGroup($key, 1);
            $et->VPrint(0, $et->INDENT, "[adding $g1:$tag]$props\n");
        }
        // allow read-only subdirectories (eg. embedded base64 XMP/IPTC in NKSC files)
        if ($tagInfo->SubDirectory and !$et->IsWriting) {
            $subdir = $tagInfo->SubDirectory;
            $value = $et->VALUE[$key];
            $dataPt = $value instanceof ScalarRef ? $value->value : Perl::str($value);
            // decode if necessary (eg. Nikon XMP-ast:XMLPackets)
            if ($tagInfo->Encoding and $tagInfo->Encoding === 'Base64') $dataPt = self::DecodeBase64($dataPt)->value;
            // process subdirectory information
            $dirInfo = [
                'DirName'  => ($subdir['DirName'] ?? null) ?: $tagInfo->Name,
                'DataPt'   => $dataPt,
                'DirLen'   => strlen($dataPt),
                'TagInfo'  => $tagInfo,
                'IgnoreProp' => $subdir['IgnoreProp'] ?? null, // (allow XML to ignore specified properties)
                'IsExtended' => 1, // (hack to avoid Duplicate warning for embedded XMP)
                'NoStruct' => 1,   // (don't try to build structures since this isn't true XMP)
                'NoBlockSave' => 1,// (don't save as a block because we already did this)
            ];
            $oldOrder = ExifTool::GetByteOrder();
            if (!empty($subdir['ByteOrder'])) ExifTool::SetByteOrder($subdir['ByteOrder']);
            $oldNS = $et->definedNS;
            $et->definedNS = null;
            $subTablePtr = ExifTool::GetTagTable($subdir['TagTable'] ?? null) ?: $tagTablePtr;
            $et->ProcessDirectory($dirInfo, $subTablePtr, $subdir['ProcessProc'] ?? null);
            ExifTool::SetByteOrder($oldOrder);
            $et->definedNS = $oldNS;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Recursively parse nested XMP data element
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) XMP data ref
    //         3) offset to start of XMP element, 4) offset to end of XMP element
    //         5) reference to array of enclosing XMP property names (undef if none)
    //         6) reference to blank node information hash
    // Returns: Number of contained XMP elements
    // (PHP: in the element loop, Perl's %attrs and @attrs are $attrsH and $attrsA; $attrs is
    //  the attribute string)
    public static function ParseXMPElement(ExifTool $et, TagTable $tagTablePtr, $dataPt, $start = null, $end = null,
        ?array &$propList = null, ?array &$blankInfo = null)
    {
        [$count, $nItems] = [0, 0];
        $isWriting = $et->XMP_CAPTURE !== null;     // (Perl: a hash ref, true even if empty)
        $isSVG = $et->XMP_IS_SVG;
        $saveNS = null;     // save xlatNS lookup if changed for the scope of this element
        $definedNS = $usedNS = [];  // namespaces defined and used in this scope
        $err = null;

        // get our parse procs
        $attrProc = null;
        if ($et->XMPParseOpts) {
            $attrProc = $et->XMPParseOpts['AttrProc'] ?? null;
            $foundProc = ($et->XMPParseOpts['FoundProc'] ?? null) ?: 'Image\ExifTool\XMP::FoundXMP';
        } else {
            $foundProc = 'Image\ExifTool\XMP::FoundXMP';
        }
        if (!$start) $start = 0;
        if (!$end) $end = strlen($dataPt);
        if ($propList === null) $propList = [];

        $processBlankInfo = null;
        // create empty blank node information hash if necessary
        if ($blankInfo === null) {
            $blankInfo = ['Prop' => []];
            $processBlankInfo = 1;
        }
        // keep track of current nodeID at this nesting level
        $oldNodeID = $blankInfo['NodeID'] ?? null;
        $pos = $start;  // (Perl pos($$dataPt))

        // lookup for translating namespace prefixes
        $xlatNS = $et->xlatNS ?? [];

        for (;;) { // Element
            // all done if there isn't enough data for another element
            // (the smallest possible element is 4 bytes, eg. "<a/>")
            if ($pos > $end - 4) break;
            // reset nodeID before processing each element
            $nodeID = $blankInfo['NodeID'] = $oldNodeID;
            // find start of next element, skipping over "<![CDATA[xxx]]>" sections
            if (!preg_match('/<([?\/]?)([-\w:.\x80-\xff]+|!--)([^>]*)>|(<!\[CDATA\[)/s', $dataPt, $m,
                PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE, $pos)) break;
            $pos = $m[0][1] + strlen($m[0][0]);
            if ($pos > $end) break;
            // (the only reason we match '<[?/]' is to keep from scanning past the
            //  "<?xpacket end..." terminator or other closing token, so
            if ($m[1][0]) continue;
            if ($m[4][0] !== null) { // skip CDATA
                if (self::Skip('/\]\]>/s', $dataPt, $pos) and $pos <= $end) continue;
                $err = 'Missing CDATA terminator';
                break;
            }
            [$prop, $attrs] = [$m[2][0], $m[3][0]];
            // skip comments
            if ($prop === '!--') {
                if (preg_match('/--$/', $attrs) or self::Skip('/-->/s', $dataPt, $pos)) continue;
                break;
            }
            $valStart = $pos;
            $valEnd = $wasComment = null;
            // only look for closing token if this is not an empty element
            // (empty elements end with '/', eg. <a:b/>)
            $attrs = preg_replace('/\/$/', '', $attrs, 1, $n);
            if (!$n) {
                $nesting = 1;
                $qProp = preg_quote($prop, '/');
                for (;;) {
                    // find next matching closing token, or the next opening token
                    // of a nested same-named element
                    if (!preg_match("/<(?:(\\/?)$qProp([-\\w:.\\x80-\\xff]*)(.*?(\\/?))>|(!\\[CDATA\\[|!--))/s", $dataPt, $mm,
                        PREG_UNMATCHED_AS_NULL | PREG_OFFSET_CAPTURE, $pos) or ($pos = $mm[0][1] + strlen($mm[0][0])) > $end)
                    {
                        $err = "XMP format error (no closing tag for $prop)";
                        break 2;
                    }
                    if ($mm[5][0] !== null) { // find end of CDATA or comment section
                        if ($mm[5][0] === '![CDATA[') {
                            if (self::Skip('/\]\]>/s', $dataPt, $pos) and $pos <= $end) continue;
                            $err = 'Missing CDATA terminator';
                            break 2;
                        } else {
                            if (self::Skip('/-->/s', $dataPt, $pos) and $pos <= $end) {
                                $wasComment = 1;
                                continue;
                            }
                            $err = 'Missing comment terminator';
                            break 2;
                        }
                    }
                    if ($mm[2][0]) continue; // ignore opening properties with different names
                    if ($mm[1][0]) {
                        if (--$nesting) continue;
                        $valEnd = $pos - strlen($prop) - strlen($mm[3][0]) - 3;
                        break;  // this element is complete
                    }
                    // this is a nested opening token (or empty element)
                    if (!$mm[4][0]) ++$nesting;
                }
            } else {
                $valEnd = $valStart;
            }
            $start = $pos;          // start from here the next time around

            // ignore specified XMP namespaces/properties
            if ($et->EXCL_XMP_LOOKUP and !$isWriting and preg_match('/^(.+):(.*)/', $prop, $mm)) {
                [$ns, $nm] = [strtolower((Vars\XMP::$stdXlatNS[$mm[1]] ?? null) ?: $mm[1]), strtolower($mm[2])];
                if (!empty($et->EXCL_XMP_LOOKUP["xmp-$ns:all"]) or !empty($et->EXCL_XMP_LOOKUP["xmp-$ns:$nm"]) or
                    !empty($et->EXCL_XMP_LOOKUP["xmp-all:$nm"]))
                {
                    ++$count;   // (pretend we found something so we don't store as a tag value)
                    continue;
                }
            }

            // extract property attributes
            $parseResource = null;
            $attrsH = $attrsA = [];
// this hangs Perl (v5.18.4) for a specific capture string [patched in ExifTool 12.98]
//        while ($attrs =~ m/(\S+?)\s*=\s*(['"])(.*?)\2/sg) {
// this may hang Perl v5.26.3 (but not v5.18.4) if there is lots of garbage in the XMP [patched in 13.23]
//        while ($attrs =~ /(\S+?)\s*=\s*(['"])/g) {
            $apos = 0;  // (Perl pos($attrs))
            for (;;) {
                if (strlen($attrs) < 2000) { // (do it the easy way if attributes aren't stupid long)
                    if (!preg_match('/(\S+?)\s*=\s*([\'"])/', $attrs, $mm, PREG_OFFSET_CAPTURE, $apos)) break;
                    [$attr, $quote] = [$mm[1][0], $mm[2][0]];
                    $apos = $mm[0][1] + strlen($mm[0][0]);
                } else {
                    // 13.23 patch to avoid capturing tons of garbage if XMP is corrupted
                    if (!preg_match('/=\s*([\'"])/', $attrs, $mm, PREG_OFFSET_CAPTURE, $apos)) break;
                    $quote = $mm[1][0];
                    $apos = $mm[0][1] + strlen($mm[0][0]);
                    $p = $apos > 1000 ? $apos - 1000 : 0;
                    $tmp = substr($attrs, $p, $apos - $p);
                    if (!preg_match('/(\S+)\s*=\s*' . $quote . '$/', $tmp, $mm)) break;
                    $attr = $mm[1];
                }
                $p0 = $apos;
                $p = strpos($attrs, $quote, $apos);
                if ($p === false) break;
                $apos = $p + 1;
                $val = substr($attrs, $p0, $apos - $p0 - 1);
                // handle namespace prefixes (defined by xmlns:PREFIX, or used with PREFIX:tag)
                if (preg_match('/(.*?):/', $attr, $mm)) {
                    if ($mm[1] === 'xmlns') {
                        $ns = substr($attr, 6);
                        $stdNS = Vars\XMP::$uri2ns[$val] ?? null;
                        // keep track of namespace prefixes defined in this scope (for Validate)
                        if (empty($et->definedNS[$ns])) $et->definedNS[$ns] = $definedNS[$ns] = 1;
                        if (!$stdNS) {
                            $try = $val;
                            // patch for Nikon NX2 URI bug for Microsoft PhotoInfo namespace
                            $try = preg_replace('{/$}', '', $try, 1, $n);
                            if (!$n) $try .= '/';
                            $stdNS = Vars\XMP::$uri2ns[$try] ?? null;
                            if ($stdNS) {
                                $val = $try;
                                $et->Warn("Fixed incorrect URI for xmlns:$ns", 1);
                            } elseif (preg_match('(^http://ns.nikon.com/BASIC_PARAM)', $val)) {
                                $et->OverrideFileType('NXD', 'application/x-nikon-nxd');
                            } else {
                                // look for same namespace with different version number
                                $try = preg_replace('/([^A-Za-z0-9_])/', '\\\\$1', $val); // (Perl quotemeta: escapes slashes too)
                                $try = preg_replace_callback('{\\\\/\d+\\\\\.\d+(\\\\/|$)}', static fn ($c) => '\/\d+\.\d+' . $c[1], $try, 1);
                                $good = null;
                                foreach (array_keys(Vars\XMP::$uri2ns) as $u) {
                                    if (!@preg_match("/^$try\$/", (string) $u)) continue;
                                    $good = $u;
                                    break;
                                }
                                if ($good) {
                                    $stdNS = Vars\XMP::$uri2ns[$good];
                                    $et->VPrint(0, $et->INDENT, "[different $stdNS version: $val]\n");
                                }
                            }
                        }
                        // tame wild namespace prefixes (patches Microsoft stupidity)
                        $newNS = null;
                        if ($stdNS) {
                            // use standard namespace prefix if pre-defined
                            if ($stdNS !== $ns) {
                                $newNS = $stdNS;
                            } elseif (!empty($xlatNS[$ns])) {
                                // this prefix is re-defined to the standard prefix in this scope
                                $newNS = '';
                            }
                        } elseif (!empty($et->curNS[$val])) {
                            // use a consistent prefix over the entire XMP for a given namespace URI
                            if ($et->curNS[$val] !== $ns) $newNS = $et->curNS[$val];
                        } else {
                            $usedNS2 = $ns;
                            // use unique prefixes for all namespaces across the entire XMP
                            if (!empty($et->curURI[$ns]) or !empty(Vars\XMP::$nsURI[$ns])) {
                                // generate a temporary namespace prefix to resolve any conflict
                                $i = 0;
                                while (!empty($et->curURI["tmp$i"])) ++$i;
                                $newNS = $usedNS2 = "tmp$i";
                            }
                            // keep track of the namespace prefixes and URI's used in this XMP
                            $et->curNS[$val] = $usedNS2;
                            $et->curURI[$usedNS2] = $val;
                        }
                        if ($newNS !== null) {
                            // save translation used in containing scope if necessary
                            // create new namespace translation for the scope of this element
                            if ($saveNS === null) $saveNS = $xlatNS;
                            if (strlen($newNS)) {
                                // use the new namespace prefix
                                $xlatNS[$ns] = $newNS;
                                $attr = 'xmlns:' . $newNS;
                                // must go through previous attributes and change prefixes if necessary
                                foreach ($attrsA as &$a) {
                                    if (!(preg_match('/(.*?):/', $a, $c) and $c[1] === $ns and $c[1] !== $newNS)) continue;
                                    $newAttr = $newNS . substr($a, strlen($ns));
                                    $attrsH[$newAttr] = $attrsH[$a];
                                    unset($attrsH[$a]);
                                    $a = $newAttr;
                                }
                                unset($a);
                            } else {
                                unset($xlatNS[$ns]);
                            }
                            $et->xlatNS = $xlatNS;
                        }
                    } else {
                        if (!empty($xlatNS[$mm[1]])) $attr = $xlatNS[$mm[1]] . substr($attr, strlen($mm[1]));
                        $usedNS[$mm[1]] = 1;
                    }
                }
                $attrsA[] = $attr;    // preserve order
                $attrsH[$attr] = $val;
            }
            if (preg_match('/(.*?):/', $prop, $mm)) {
                $usedNS[$mm[1]] = 1;
                // tame wild namespace prefixes (patch for Microsoft stupidity)
                if (!empty($xlatNS[$mm[1]])) $prop = $xlatNS[$mm[1]] . substr($prop, strlen($mm[1]));
            }

            if ($prop === 'rdf:li') {
                // impose a reasonable maximum on the number of items in a list
                if ($nItems == 1000) {
                    [$tg, $ns] = self::GetXMPTagID($propList);
                    if ($isWriting) {
                        $et->Warn("Excessive number of items for $ns:$tg. Processing may be slow", 1);
                    } elseif (!$et->OPTIONS['IgnoreMinorErrors']) {
                        $et->Warn("Extracted only 1000 $ns:$tg items. Ignore minor errors to extract all", 2);
                        break;
                    }
                }
                // add index to list items so we can keep them in order
                // (this also enables us to keep structure elements grouped properly
                // for lists of structures, like JobRef)
                // Note: the list index is prefixed by the number of digits so sorting
                // alphabetically gives the correct order while still allowing a flexible
                // number of digits -- this scheme allows up to 9 digits in the index,
                // with index numbers ranging from 0 to 999999999.  The sequence is:
                // 10,11,12-19,210,211-299,3100,3101-3999,41000...9999999999.
                $prop .= ' ' . strlen((string) $nItems) . $nItems;
                // reset LIST_TAGS at the start of the outtermost list
                // (avoids accumulating incorrectly-written elements in a correctly-written list)
                if (!$nItems and !preg_grep('/^rdf:li /', $propList)) {
                    $et->LIST_TAGS = [];
                }
                ++$nItems;
            } elseif ($prop === 'rdf:Description') {
                // remove unnecessary rdf:Description elements since parseType='Resource'
                // is more efficient (also necessary to make property path consistent)
                if (preg_grep('/^rdf:Description$/', $propList)) {
                    $parseResource = 1;
                    // set parseType so we know this is a structure
                    $attrsH['rdf:parseType'] = 'Resource';
                }
            } elseif ($prop === 'xmp:xmpmeta') {
                // patch MicrosoftPhoto unconformity
                $prop = 'x:xmpmeta';
                if ($et->XmpValidate !== null) $et->Warn('Wrong namespace for xmpmeta');
            }

            // hook for special parsing of attributes
            $val = null;
            if ($attrProc) {
                $val = substr($dataPt, $valStart, $valEnd - $valStart);
                if ($attrProc($attrsA, $attrsH, $prop, $val)) {
                    // the value was changed, so reset $valStart/$valEnd to use $val instead
                    $valStart = $valEnd;
                }
            }

            // add nodeID to property path (with leading ' #') if it exists
            if (isset($attrsH['rdf:nodeID'])) {
                $nodeID = $blankInfo['NodeID'] = $attrsH['rdf:nodeID'];
                unset($attrsH['rdf:nodeID']);
                $prop .= ' #' . $nodeID;
                $parseResource = null;  // can't ignore if this is a node
            }

            // push this property name onto our hierarchy list
            if (!$parseResource) $propList[] = $prop;

            if ($isSVG) {
                // ignore everything but top level SVG tags and metadata unless Unknown set
                if (!($et->OPTIONS['Unknown'] > 1 or $et->OPTIONS['Verbose'])) {
                    if (count($propList) > 1 and !preg_match('/\b(metadata|desc|title)$/', $propList[1])) {
                        array_pop($propList);
                        continue;
                    }
                }
                if ($prop === 'svg' or $prop === 'metadata') {
                    // add svg namespace prefix if missing to ignore these entries in the tag name
                    $propList[count($propList) - 1] = "svg:$prop";
                }
            } elseif ($et->XmpIgnoreProps) { // ignore specified properties for tag name
                foreach ($et->XmpIgnoreProps as $ip) {
                    if (!$propList) break;
                    if ($ip === $propList[0]) array_pop($propList);
                }
            }

            // handle properties inside element attributes (RDF shorthand format):
            // (attributes take the form a:b='c' or a:b="c")
            $shorthand = $ignored = null;
            foreach ($attrsA as $shortName) {
                if (!isset($attrsH[$shortName])) continue;
                $propName = $shortName;
                if (preg_match('/(.*?):(.*)/', $propName, $mm)) {
                    $ns = $mm[1];   // specified namespace
                    $name = $mm[2];
                } elseif (preg_match('/(\S*?):/', $prop, $mm)) {
                    $ns = $mm[1];   // assume same namespace as parent
                    $name = $propName;
                    $propName = "$ns:$name";    // generate full property name
                } else {
                    // a property qualifier is the only property name that may not
                    // have a namespace, and a qualifier shouldn't have attributes,
                    // but what the heck, let's allow this anyway
                    $ns = '';
                    $name = $propName;
                }
                if ($propName === 'rdf:about') {
                    if (!$et->XmpAbout) {
                        $et->XmpAbout = $attrsH[$shortName];
                    } elseif ($et->XmpAbout !== $attrsH[$shortName]) {
                        if ($isWriting) {
                            $str = "Different 'rdf:about' attributes not handled";
                            if (empty($et->WAS_WARNED[$str])) {
                                $et->Error($str, 1);
                                $et->WAS_WARNED[$str] = 1;
                            }
                        } elseif ($et->XmpValidate !== null) {
                            $et->Warn("Different 'rdf:about' attributes");
                        }
                    }
                }
                if ($isWriting) {
                    // keep track of our namespaces when writing
                    if ($ns === 'xmlns') {
                        $stdNS = Vars\XMP::$uri2ns[$attrsH[$shortName]] ?? null;
                        if (!($stdNS and ($stdNS === 'x' or $stdNS === 'iX'))) {
                            if (!isset($et->XMP_NS[$name])) $et->XMP_NS[$name] = $attrsH[$shortName];
                        }
                        unset($attrsH[$shortName]);     // (handled by namespace logic)
                        continue;
                    } elseif (!empty(Vars\XMP::$recognizedAttrs[$propName])) {
                        continue;
                    }
                }
                $shortVal = $attrsH[$shortName];
                // Note: $prop is the containing property in this loop (not the shorthand property)
                // so $ignoreProp ignores all attributes of the ignored property
                if (!empty(Vars\XMP::$ignoreNamespace[$ns]) or !empty(Vars\XMP::$ignoreProp[$prop]) or
                    !empty(Vars\XMP::$ignoreEtProp[$propName]))
                {
                    $ignored = $propName;
                    // handle special attributes (extract as tags only once if not empty)
                    $recognized = Vars\XMP::$recognizedAttrs[$propName] ?? null;
                    if (is_array($recognized) and $shortVal) {
                        [$tbl, $id, $name] = $recognized;
                        $tval = self::UnescapeXML($shortVal);
                        if (!(isset($et->VALUE[$name]) and Perl::str($et->VALUE[$name]) === Perl::str($tval))) {
                            $et->HandleTag(ExifTool::GetTagTable($tbl), $id, $tval);
                        }
                    }
                    continue;
                }
                unset($attrsH[$shortName]); // don't re-use this attribute
                $propList[] = $propName;
                // save this shorthand XMP property
                if ($nodeID !== null) {
                    self::SaveBlankInfo($blankInfo, $propList, $shortVal);
                } elseif ($isWriting) {
                    self::CaptureXMP($et, $propList, $shortVal);
                } else {
                    if ($et->XmpValidate !== null) self::ValidateProperty($et, $propList);
                    $foundProc($et, $tagTablePtr, $propList, $shortVal);
                }
                array_pop($propList);
                $shorthand = 1;
            }
            if ($isWriting) {
                if (self::ParseXMPElement($et, $tagTablePtr, $dataPt, $valStart, $valEnd,
                                          $propList, $blankInfo))
                {
                    // (no value since we found more properties within this one)
                    // set an error on any ignored attributes here, because they will be lost
                    if ($ignored) $et->XMP_ERROR = "Can't handle XMP attribute '{$ignored}'";
                } elseif (!$shorthand or $valEnd != $valStart) {
                    $val = substr($dataPt, $valStart, $valEnd - $valStart);
                    // remove comments and whitespace from rdf:Description only
                    if ($prop === 'rdf:Description') {
                        $val = preg_replace('/<!--.*?-->/', '', $val);
                        $val = preg_replace('/^\s+/', '', $val);
                        $val = preg_replace('/\s+$/', '', $val);
                    }
                    if ($nodeID !== null) {
                        self::SaveBlankInfo($blankInfo, $propList, $val, $attrsH);
                    } else {
                        self::CaptureXMP($et, $propList, $val, $attrsH);
                    }
                }
            // look for additional elements contained within this one
            } elseif ($valStart == $valEnd or
                !self::ParseXMPElement($et, $tagTablePtr, $dataPt, $valStart, $valEnd,
                                       $propList, $blankInfo))
            {
                $wasEmpty = null;
                if ($val === null) {
                    $val = substr($dataPt, $valStart, $valEnd - $valStart);
                    // remove comments and whitespace from rdf:Description only
                    if ($prop === 'rdf:Description' and $val) {
                        $val = preg_replace('/<!--.*?-->/', '', $val);
                        $val = preg_replace('/^\s+/', '', $val);
                        $val = preg_replace('/\s+$/', '', $val);
                    } elseif ($wasComment and $val) {
                        $val = preg_replace('/<!--.*?-->/', '', $val);
                    }
                    // if element value is empty, take value from RDF 'value' or 'resource' attribute
                    // (preferentially) or 'about' attribute (if no 'value' or 'resource')
                    if ($val === '' and (preg_match('/\brdf:(?:value|resource)=([\'"])(.*?)\1/', $attrs, $mm) or
                                         preg_match('/\brdf:about=([\'"])(.*?)\1/', $attrs, $mm)))
                    {
                        $val = $mm[2];
                        $wasEmpty = 1;
                    }
                }
                // there are no contained elements, so this must be a simple property value
                // (unless we already extracted shorthand values from this element)
                if (strlen($val) or !$shorthand) {
                    $lastProp = $propList[count($propList) - 1] ?? null;
                    if ($lastProp === null) $lastProp = '';
                    if ($nodeID !== null) {
                        self::SaveBlankInfo($blankInfo, $propList, $val);
                    } elseif ($lastProp === 'rdf:type' and $wasEmpty) {
                        // do not extract empty structure types (for now)
                    } elseif (preg_match('/^et:(desc|prt|val)$/', $lastProp, $mm) and ($count or $mm[1] === 'desc')) {
                        // ignore et:desc, and et:val if preceded by et:prt
                        --$count;
                    } else {
                        if ($et->XmpValidate !== null) self::ValidateProperty($et, $propList, $attrsH);
                        $foundProc($et, $tagTablePtr, $propList, $val, $attrsH);
                    }
                }
            }
            if (!$parseResource) array_pop($propList);
            ++$count;

            // validate namespace prefixes used at this level if necessary
            if ($et->XmpValidate !== null) {
                $keys = array_map('strval', array_keys($usedNS));
                sort($keys, SORT_STRING);
                foreach ($keys as $u) {
                    if (!empty($et->definedNS[$u]) or $u === 'xml') continue;
                    if (isset($et->definedNS[$u])) {
                        $et->Warn("XMP namespace $u is used out of scope");
                    } else {
                        $et->Warn("Undefined XMP namespace: $u");
                    }
                    $et->definedNS[$u] = -1;  // (don't warn again for this namespace)
                }
                // reset namespaces that went out of scope
                foreach (array_keys($definedNS) as $d) $et->definedNS[$d] = 0;
                $usedNS = $definedNS = [];
            }

            if ($start >= $end) break;
            $pos = $start;
            self::Skip('/\G\s+/', $dataPt, $pos);   // skip white space after closing token
        }
        // handle any error which aborted processing
        if ($err) {
            $isWriting ? $et->Error($err) : $et->Warn($err);
        }
        //
        // process resources referenced by blank nodeID's
        //
        if ($processBlankInfo and $blankInfo['Prop']) {
            self::ProcessBlankInfo($et, $tagTablePtr, $blankInfo, $isWriting);
            $blankInfo = [];    // free some memory
        }
        // restore namespace lookup from the containing scope
        if ($saveNS !== null) $et->xlatNS = $saveNS;

        return $count;  // return the number of elements found at this level
    }

    /**
     * Perl `$$dataPt =~ /re/g` continuing from pos(): search from $pos, and if found
     * move $pos to the end of the match.
     */
    private static function Skip(string $re, string $data, &$pos): bool
    {
        if (!preg_match($re, $data, $m, PREG_OFFSET_CAPTURE, $pos)) return false;
        $pos = $m[0][1] + strlen($m[0][0]);
        return true;
    }

    //------------------------------------------------------------------------------
    // Process XMP data
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: The following flavours of XMP files are currently recognized:
    // - standard XMP with xpacket, x:xmpmeta and rdf:RDF elements
    // - XMP that is missing the xpacket and/or x:xmpmeta elements
    // - mutant Microsoft XMP with xmp:xmpmeta element
    // - XML files beginning with "<xml"
    // - SVG files that begin with "<svg" or "<!DOCTYPE svg"
    // - XMP and XML files beginning with a UTF-8 byte order mark
    // - UTF-8, UTF-16 and UTF-32 encoded XMP
    // - erroneously double-UTF8 encoded XMP
    // - otherwise valid files with leading XML comment
    public static function ProcessXMP(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dirStart = $dirLen = $dataLen = $double = null;
        $buff = $fmt = $hasXMP = $isXML = $isRDF = $isSVG = null;
        $rtnVal = 0;
        $bom = 0;
        $path = $et->MetadataPath();

        // namespaces and prefixes currently in effect while parsing the file,
        // and lookup to translate brain-dead-Microsoft-Photo-software prefixes
        $et->curURI = [];
        $et->curNS  = [];
        $et->xlatNS = [];
        $et->definedNS = [];
        $et->XmpAbout = null;
        $et->XmpValidate = null;    // don't validate by default
        $et->XmpValidateLangAlt = null;

        // ignore non-standard XMP while in strict MWG compatibility mode
        if ((MWG::$strict or $et->OPTIONS['Validate']) and
            !($et->XMP_CAPTURE !== null or $et->DOC_NUM) and
            ((($dirInfo['DirName'] ?? null) ?: '') === 'XMP' or $et->FILE_TYPE === 'XMP'))
        {
            if ($et->OPTIONS['Validate']) $et->XmpValidate = [];
            $stdPath = Vars\XMP::$stdPath[$et->FILE_TYPE] ?? null;
            $nonStd = ($stdPath and $path !== $stdPath);
            if ($nonStd and MWG::$strict) {
                $et->Warn("Ignored non-standard XMP at $path");
                return 1;
            }
            if ($nonStd) {
                $et->Warn("Non-standard XMP at $path", 1);
            } elseif (empty($dirInfo['IsExtended'])) {
                if (!empty($et->DIR_COUNT['XMP'])) $et->Warn("Duplicate XMP at $path");
                $et->DIR_COUNT['XMP'] = ($et->DIR_COUNT['XMP'] ?? 0) + 1;   // count standard XMP
            }
        }
        if ($dataPt !== null) {
            $dirStart = ($dirInfo['DirStart'] ?? 0) ?: 0;
            $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $dirStart);
            $dataLen = ($dirInfo['DataLen'] ?? null) ?: strlen($dataPt);
            // check leading BOM (may indicate double-encoded UTF)
            if (preg_match('/\G((\0\0)?\xfe\xff|\xff\xfe(\0\0)?|\xef\xbb\xbf)\0*<\0*\?\0*x\0*p\0*a\0*c\0*k\0*e\0*t/', $dataPt, $m, 0, $dirStart)) {
                $double = $m[1];
            } else {
                // handle UTF-16/32 XML
                if (preg_match('/\G((\0\0)?\xfe\xff|\xff\xfe(\0\0)?|\xef\xbb\xbf)\0*<\0*\?\0*x\0*m\0*l\0* /', $dataPt, $m, 0, $dirStart)) {
                    $tmp = $m[1];
                    $fmt = str_contains($tmp, "\xfe\xff") ? 'n' : 'v';
                    if (str_contains($tmp, "\0\0")) $fmt = strtoupper($fmt);
                    $isXML = 1;
                }
            }
        } else {
            $type = $mime = $buf2 = $buf3 = null;
            // read information from XMP file
            $raf = $dirInfo['RAF'] ?? null;
            if (!$raf) return 0;
            if (!$raf->Read($buff, 256)) return 0;
            $buf2 = str_replace("\0", '', $buff);   // cheap conversion to UTF-8
            // remove leading comments if they exist (eg. ImageIngester)
            while (preg_match('/^\s*<!--/', $buf2)) {
                // remove the comment if it is complete
                $buf2 = preg_replace('/^\s*<!--.*?-->\s+/s', '', $buf2, 1, $n);
                if ($n) {
                    // continue with parsing if we have more than 128 bytes remaining
                    if (strlen($buf2) > 128) continue;
                } else {
                    // don't read more than 10k when looking for the end of comment
                    if (strlen($buf2) > 10000) return 0;
                }
                if (!$raf->Read($buf3, 256)) break; // read more data if available
                $buff .= $buf3;
                $buf3 = str_replace("\0", '', $buf3);
                $buf2 .= $buf3;
            }
            // check to see if this is XMP format
            // (CS2 writes .XMP files without the "xpacket begin")
            if (preg_match('/^\s*(<\?xpacket begin=|<x(mp)?:x[ma]pmeta)/', $buf2)) {
                $hasXMP = 1;
            } else {
                // also recognize XML files and .XMP files with BOM and without x:xmpmeta
                if (preg_match('/^(\xfe\xff)(<\?xml|<rdf:RDF|<x(mp)?:x[ma]pmeta)/', $buf2, $m, PREG_UNMATCHED_AS_NULL)) {
                    $fmt = 'n';     // UTF-16 or 32 MM with BOM
                } elseif (preg_match('/^(\xff\xfe)(<\?xml|<rdf:RDF|<x(mp)?:x[ma]pmeta)/', $buf2, $m, PREG_UNMATCHED_AS_NULL)) {
                    $fmt = 'v';     // UTF-16 or 32 II with BOM
                } elseif (preg_match('/^(\xef\xbb\xbf)?(<\?xml|<rdf:RDF|<x(mp)?:x[ma]pmeta|<svg\b)/', $buf2, $m, PREG_UNMATCHED_AS_NULL)) {
                    $fmt = 0;       // UTF-8 with BOM or unknown encoding without BOM
                } elseif (preg_match('/^(\xfe\xff|\xff\xfe|\xef\xbb\xbf)(<\?xpacket begin=)/', $buf2, $m, PREG_UNMATCHED_AS_NULL)) {
                    $double = $m[1];   // double-encoded UTF
                } else {
                    return 0;       // not recognized XMP or XML
                }
                if ($m[1]) $bom = 1;
                if ($m[2] === '<?xml') {
                    if ($fmt !== null and !$fmt and preg_match('/^[^\n\r]*[\n\r]+<\?aid /s', $buf2)) {
                        $et->XmpValidate = null;    // don't validate INX
                        if ($et->XMP_CAPTURE !== null) {
                            $et->Error('ExifTool does not yet support writing of INX files');
                            return 0;
                        }
                        $type = 'INX';
                    } elseif (preg_match('/<x(mp)?:x[ma]pmeta/', $buf2)) {
                        $hasXMP = 1;
                    } else {
                        $et->XmpValidate = null;    // don't validate XML
                        // identify SVG images and PLIST files by DOCTYPE if available
                        if (preg_match('/<!DOCTYPE\s+(\w+)/', $buf2, $mm)) {
                            if ($mm[1] === 'svg') {
                                $isSVG = 1;
                            } elseif ($mm[1] === 'plist') {
                                $type = 'PLIST';
                            } elseif ($mm[1] === 'REDXIF') {
                                $type = 'RMD';
                                $mime = 'application/xml';
                            } elseif ($mm[1] !== 'fcpxml') { // Final Cut Pro XML
                                return 0;
                            }
                        } elseif (preg_match('/<svg[\s>]/', $buf2)) {
                            $isSVG = 1;
                        } elseif (preg_match('/<rdf:RDF/', $buf2)) {
                            $isRDF = 1;
                        } elseif (preg_match('/<plist[\s>]/', $buf2)) {
                            $type = 'PLIST';
                        }
                    }
                    $isXML = 1;
                } elseif ($m[2] === '<rdf:RDF') {
                    $isRDF = 1;     // recognize XMP without x:xmpmeta element
                } elseif ($m[2] === '<svg') {
                    $isSVG = $isXML = 1;
                }
                if ($isSVG and $et->XMP_CAPTURE !== null) {
                    $et->Error('ExifTool does not yet support writing of SVG images');
                    return 0;
                }
                if (str_starts_with($buff, "\0\0")) {
                    $fmt = 'N';     // UTF-32 MM with or without BOM
                } elseif (preg_match('/^..\0\0/s', $buff)) {
                    $fmt = 'V';     // UTF-32 II with or without BOM
                } elseif (!$fmt) {
                    if (str_starts_with($buff, "\0")) {
                        $fmt = 'n'; // UTF-16 MM without BOM
                    } elseif (preg_match('/^.\0/s', $buff)) {
                        $fmt = 'v'; // UTF-16 II without BOM
                    }
                }
            }
            $size = null;
            if ($type) {
                if ($type === 'PLIST') {
                    $ext = $et->FILE_EXT;
                    if ($ext and $ext === 'MODD') $type = $ext;
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::PLIST::Main');
                    $dirInfo['XMPParseOpts']['FoundProc'] = 'Image\ExifTool\PLIST::FoundTag';
                }
            } else {
                if ($isSVG) {
                    $type = 'SVG';
                } elseif ($isXML and !$hasXMP and !$isRDF) {
                    $type = 'XML';
                    $ext = $et->FILE_EXT;
                    if ($ext and $ext === 'COS') $type = $ext;  // recognize COS by extension
                }
            }
            $et->SetFileType($type, $mime);

            $fast = $et->Options('FastScan');
            if ($fast and $fast == 3) return 1;

            if ($type and $type === 'INX') {
                // brute force search for first XMP packet in INX file
                // start: '<![CDATA[<?xpacket begin' (24 bytes)
                // end:   '<?xpacket end="r"?' . '>]]>'   (22 bytes)
                if (!$raf->Seek(0, 0)) return 0;
                if (!$raf->Read($buff, 65536)) return 1;
                for (;;) {
                    if (preg_match('/<!\[CDATA\[<\?xpacket begin/', $buff, $m, PREG_OFFSET_CAPTURE)) break;
                    if (!$raf->Read($buf2, 65536)) return 1;
                    $buff = substr($buff, -24) . $buf2;
                }
                $buff = substr($buff, $m[0][1] + strlen($m[0][0]) - 15);  // (discard '<![CDATA[' and before)
                $p = 0;
                for (;;) {
                    if (preg_match('/<\?xpacket end="[rw]"\?>\]\]>/', $buff, $m, PREG_OFFSET_CAPTURE, $p)) break;
                    $n = strlen($buff);
                    if (!$raf->Read($buf2, 65536)) {
                        $et->Warn('Missing xpacket end');
                        return 1;
                    }
                    $buff .= $buf2;
                    $p = max(0, $n - 22);   // don't miss end pattern if it was split
                }
                $size = $m[0][1] + strlen($m[0][0]) - 3;    // (discard ']]>' and after)
                $buff = substr($buff, 0, $size);
            } else {
                // read the entire file
                if (!$raf->Seek(0, 2)) return 0;
                $size = $raf->Tell();
                if (!$size) return 0;
                if (!$raf->Seek(0, 0)) return 0;
                if ($raf->Read($buff, $size) != $size) return 0;
            }
            $dataPt = $buff;
            $dirStart = 0;
            $dirLen = $dataLen = $size;
        }

        // decode the first layer of double-encoded UTF text (if necessary)
        if ($double) {
            $buff = substr($dataPt, $dirStart + strlen($double)); // remove leading BOM
            // assume that character data has been re-encoded in UTF, so re-pack
            // as characters and look for warnings indicating a false assumption
            // (PHP: Perl's warnings from malformed UTF-8 and from wrapping characters in pack 'C')
            $warn = false;
            if ($double === "\xef\xbb\xbf") {
                $uni = Charset::UnpackU($buff, $warn);  // (Charset::Decompose(undef,$buff,'UTF8'))
            } else {
                if (strlen($double) == 2) {
                    $fmt2 = ($double === "\xfe\xff") ? 'n' : 'v';
                } else {
                    $fmt2 = ($double === "\0\0\xfe\xff") ? 'N' : 'V';
                }
                $uni = Perl::unpack("$fmt2*", $buff);
            }
            foreach ($uni as $c) {
                if ($c > 255) {
                    $warn = true;
                    break;
                }
            }
            $buf2 = implode('', array_map(static fn ($c) => chr($c & 0xff), $uni));
            if ($warn) {
                if (empty($dirInfo['RAF'])) $et->Warn('Superfluous BOM at start of XMP');
                $dataPt = $buff;    // use XMP with the BOM removed
            } else {
                $et->Warn('XMP is double UTF-encoded');
                $dataPt = $buf2;    // use the decoded XMP
            }
            $dirStart = 0;
            $dirLen = $dataLen = strlen($dataPt);
        }

        // extract XMP/XML as a block if specified
        $blockName = !empty($dirInfo['BlockInfo']) ? $dirInfo['BlockInfo']->Name : 'XMP';
        $blockExtract = $et->Options('BlockExtract');
        if ((!empty($et->REQ_TAG_LOOKUP[strtolower($blockName)]) or ($et->TAGS_FROM_FILE and
            empty($et->EXCL_TAG_LOOKUP[strtolower($blockName)])) or $blockExtract) and
            (($et->FileType === 'XMP' and $blockName === 'XMP') or
            (!empty($dirInfo['DirName']) and $dirInfo['DirName'] === $blockName)))
        {
            $et->FoundTag(($dirInfo['BlockInfo'] ?? null) ?: 'XMP', substr($dataPt, $dirStart, $dirLen));
            if ($blockExtract and $blockExtract > 1) return 1;
        }

        if (!$tagTablePtr) $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
        if ($et->Options('Verbose') and $et->XMP_CAPTURE === null) {
            $dirType = $isSVG ? 'SVG' : $tagTablePtr['GROUPS'][1];
            $et->VerboseDir($dirType, 0, $dirLen);
        }
        //
        // convert UTF-16 or UTF-32 encoded XMP to UTF-8 if necessary
        //
        $begin = '<?xpacket begin=';
        $dirEnd = $dirStart + $dirLen;
        $et->XMP_IS_XML = null;
        $et->XMP_IS_SVG = null;
        if ($isXML or $isRDF) {
            $et->XMP_IS_XML = $isXML;
            $et->XMP_IS_SVG = $isSVG;
            $et->XMP_NO_XPACKET = 1 + $bom;
        } elseif (substr($dataPt, $dirStart, strlen($begin)) === $begin) {
            $et->XMP_NO_XPACKET = null;
        } elseif (preg_match('/<x(mp)?:x[ma]pmeta/', $dataPt, $m, PREG_OFFSET_CAPTURE, $dirStart) and
                 ($p = $m[0][1] + strlen($m[0][0])) > $dirStart and $p < $dirEnd)
        {
            $et->XMP_NO_XPACKET = 1 + $bom;
        } else {
            $et->XMP_NO_XPACKET = null;
            // check for UTF-16 encoding (insert one \0 between characters)
            $begin = implode("\0", str_split($begin));
            $badEnc = null;
            if (preg_match('/\G(\0)?' . preg_quote($begin, '/') . '\0./s', $dataPt, $m, PREG_OFFSET_CAPTURE, $dirStart)) {
                $p = $m[0][1] + strlen($m[0][0]);
                // validate byte ordering by checking for U+FEFF character
                if (isset($m[1]) and $m[1][1] >= 0) {
                    // should be big-endian since we had a leading \0
                    $fmt = 'n';
                    if (substr($dataPt, $p, 2) !== "\xfe\xff") $badEnc = 1;
                } else {
                    $fmt = 'v';
                    if (substr($dataPt, $p, 3) !== "\0\xff\xfe") $badEnc = 1;
                }
            } else {
                // check for UTF-32 encoding (with three \0's between characters)
                $begin = str_replace("\0", "\0\0\0", $begin);
                if (!preg_match('/\G(\0\0\0)?' . preg_quote($begin, '/') . '\0\0\0./s', $dataPt, $m, PREG_OFFSET_CAPTURE, $dirStart)) {
                    $fmt = 0;   // set format to zero as indication we didn't find encoded XMP
                } elseif (isset($m[1]) and $m[1][1] >= 0) {
                    // should be big-endian
                    $fmt = 'N';
                    if (substr($dataPt, $m[0][1] + strlen($m[0][0]), 4) !== "\0\0\xfe\xff") $badEnc = 1;
                } else {
                    $fmt = 'V';
                    if (substr($dataPt, $m[0][1] + strlen($m[0][0]), 7) !== "\0\0\0\xff\xfe\0\0") $badEnc = 1;
                }
            }
            if ($badEnc) $et->Warn('Invalid XMP encoding marker');
        }
        // warn if standard XMP is missing xpacket wrapper
        if ($et->XMP_NO_XPACKET and $et->OPTIONS['Validate'] and
            !empty(Vars\XMP::$stdPath[$et->FILE_TYPE]) and $path === Vars\XMP::$stdPath[$et->FILE_TYPE] and
            empty($dirInfo['IsExtended']) and !$et->DOC_NUM)
        {
            $et->Warn('XMP is missing xpacket wrapper', 1);
        }
        if ($fmt) {
            // trim if necessary to avoid converting non-UTF data
            if ($dirStart or $dirEnd != strlen($dataPt)) {
                $dataPt = substr($dataPt, $dirStart, $dirLen);
            }
            // convert into UTF-8 (Perl: pack('C0U*', unpack("$fmt*",$$dataPt)))
            $dataPt = implode('', array_map(ExifTool::PackUTF8(...), Perl::unpack("$fmt*", $dataPt)));
            $dirStart = 0;
            $dirLen = strlen($dataPt);
            $dirEnd = $dirStart + $dirLen;
        }
        // avoid scanning for XMP later in case ScanForXMP is set
        if ($tagTablePtr === ExifTool::GetTagTable('Image::ExifTool::XMP::Main')) $et->FoundXMP = 1;

        // set XMP parsing options
        $et->XMPParseOpts = $dirInfo['XMPParseOpts'] ?? null;

        // ignore any specified properties (XML hack)
        if (!empty($dirInfo['IgnoreProp'])) {
            Vars\XMP::$ignoreProp = $dirInfo['IgnoreProp'];
        } else {
            Vars\XMP::$ignoreProp = [];
        }

        // need to preserve list indices to be able to handle multi-dimensional lists
        $keepFlat = null;
        if ($et->OPTIONS['Struct']) {
            if (Perl::str($et->OPTIONS['Struct']) === '2') {
                $keepFlat = 1;      // preserve flattened tags
                // setting NO_LIST to 0 combines list items in a TAG_EXTRA "NoList" element
                // to allow them to be re-listed later if necessary.  A "NoListDel" element
                // is also created for tags that wouldn't have existed.
                $et->NO_LIST = 0;
            } else {
                $et->NO_LIST = 1;
            }
        }

        // don't generate structures if this isn't real XMP
        if (!empty($dirInfo['BlockInfo']) or !empty($dirInfo['NoStruct'])) $et->NO_STRUCT = 1;

        // parse the XMP
        if (self::ParseXMPElement($et, $tagTablePtr, $dataPt, $dirStart, $dirEnd)) {
            $rtnVal = 1;
        } elseif (!empty($dirInfo['DirName']) and $dirInfo['DirName'] === 'XMP') {
            // if DirName was 'XMP' we expect well-formed XMP, so set Warning since it wasn't
            // (but allow empty XMP as written by some PhaseOne cameras)
            $xmp = substr($dataPt, $dirStart, $dirLen);
            if (preg_match('/^ *\0*$/', $xmp)) {
                $et->Warn('Invalid XMP');
            } else {
                $et->Warn('Empty XMP', 1);
                $rtnVal = 1;
            }
        }
        $et->NO_STRUCT = null;

        // return DataPt if successful in case we want it for writing
        if ($rtnVal and !empty($dirInfo['RAF'])) $dirInfo['DataPt'] = $dataPt;

        // restore structures if necessary
        if ($et->IsStruct) {
            if (empty($dirInfo['NoStruct'])) {
                self::RestoreStruct($et, $keepFlat);
            }
            $et->IsStruct = null;
        }
        // reset NO_LIST flag (must do this _after_ RestoreStruct() above)
        $et->NO_LIST = null;
        $et->XMPParseOpts = null;
        $et->curURI = null;
        $et->curNS = null;
        $et->xlatNS = null;
        $et->definedNS = null;

        return $rtnVal;
    }
}
