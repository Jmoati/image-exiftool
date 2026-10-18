<?php

//------------------------------------------------------------------------------
// File:         WriteXMP.pl
//
// Description:  Write XMP meta information
//
// Revisions:    12/19/2004 - P. Harvey Created
//
// PHP port of the whole file (package Image::ExifTool::XMP), mixed into class XMP. The file
// lexicals are the private constants and static properties below.
//------------------------------------------------------------------------------

namespace Image\ExifTool\XMP;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars;

trait WriteXMP
{
    /** my $debug */
    private static int $debug = 0;
    /** my $numPadLines: number of blank padding lines */
    private const int NUM_PAD_LINES = 24;

    // when writing extended XMP, resources bigger than this get placed in their own
    // rdf:Description so they can be moved to the extended segments if necessary
    /** my $newDescThresh: 10 kB */
    private const int NEW_DESC_THRESH = 10240;

    // individual resources and namespaces to place last in separate rdf:Description's
    // so they can be moved to extended XMP segments if required (see Oct. 2008 XMP spec)
    /** my %extendedRes */
    private const array EXTENDED_RES = [
        'photoshop:History' => 1,
        'xap:Thumbnails' => 1,
        'xmp:Thumbnails' => 1,
        'crs' => 1,
        'crss' => 1,
    ];

    // namespaces to validate more completely when Validate option is used
    private static array $validateNS = ['tiff' => 1, 'exif' => 1, 'exifEX' => 1];

    private static string $rdfDesc = 'rdf:Description';
    //
    // packet/xmp/rdf headers and trailers
    //
    /** my $pktOpen */
    private const string PKT_OPEN = "<?xpacket begin='\xef\xbb\xbf' id='W5M0MpCehiHzreSzNTczkc9d'?>\n";
    /** my $xmlOpen */
    private const string XML_OPEN = "<?xml version='1.0' encoding='UTF-8'?>\n";
    /** my $rdfClose */
    private const string RDF_CLOSE = "</rdf:RDF>\n";
    /** my $xmpClose */
    private const string XMP_CLOSE = "</x:xmpmeta>\n";
    /** my $pktCloseW: writable by default */
    private const string PKT_CLOSE_W = "<?xpacket end='w'?>";
    /** my $pktCloseR */
    private const string PKT_CLOSE_R = "<?xpacket end='r'?>";
    /** my ($sp, $nl) */
    private static string $sp = ' ';
    private static string $nl = "\n";

    /** my $xmpOpenPrefix */
    private static function XmpOpenPrefix(): string
    {
        return "<x:xmpmeta xmlns:x='" . Vars\XMP::$nsURI['x'] . "'";
    }

    /** my $rdfOpen */
    private static function RdfOpen(): string
    {
        return "<rdf:RDF xmlns:rdf='" . Vars\XMP::$nsURI['rdf'] . "'>\n";
    }

    //------------------------------------------------------------------------------
    // Get XMP opening tag (and set x:xmptk appropriately)
    // Inputs: 0) ExifTool object ref
    // Returns: x:xmpmeta opening tag
    public static function XMPOpen(ExifTool $et)
    {
        $nv = $et->NEW_VALUE[spl_object_id(ExifTool::GetTagTable('Image::ExifTool::XMP::x')['xmptk'])] ?? null;
        $tk = null;
        if ($nv !== null) {
            $tk = $et->GetNewValue($nv);
            $et->VerboseValue(($tk ? '+' : '-') . ' XMP-x:XMPToolkit', $tk);
            ++$et->CHANGED;
        } else {
            $tk = 'Image::ExifTool ' . ExifTool::$VERSION;
        }
        $str = $tk ? (" x:xmptk='" . self::EscapeXML($tk) . "'") : '';
        return self::XmpOpenPrefix() . "$str>\n";
    }

    //------------------------------------------------------------------------------
    // Validate XMP packet and set read or read/write mode
    // Inputs: 0) XMP data reference, 1) 'r' = read only, 'w' or undef = read/write
    // Returns: true if XMP is good (and adds packet header/trailer if necessary)
    public static function ValidateXMP(&$xmpPt, $mode = null)
    {
        $xmpPt = preg_replace('/^\s*<!--.*?-->\s*/s', '', Perl::str($xmpPt), 1);   // remove leading comment if it exists
        if (!preg_match('/^\0*<\0*\?\0*x\0*p\0*a\0*c\0*k\0*e\0*t/', $xmpPt)) {
            if (!preg_match('/^<x(mp)?:x[ma]pmeta/', $xmpPt)) return '';
            // add required xpacket header/trailer
            $xmpPt = self::PKT_OPEN . $xmpPt . self::PKT_CLOSE_W;
        }
        if (!$mode) $mode = 'w';
        $end = substr($xmpPt, -32, 32);
        // check for proper xpacket trailer and set r/w mode if necessary
        $re = '/(e\0*n\0*d\0*=\0*[\'"]\0*)([rw])(\0*[\'"]\0*\?\0*>)/';
        if (!preg_match($re, $end, $m)) return '';
        $end = preg_replace($re, '${1}' . $mode . '${3}', $end, 1);
        if ($m[2] !== $mode) $xmpPt = substr_replace($xmpPt, $end, -32, 32);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Validate XMP property
    // Inputs: 0) ExifTool ref, 1) validate hash ref, 2) attribute hash ref
    // - issues warnings if problems detected
    public static function ValidateProperty(ExifTool $et, array $propList, ?array $attr = null): void
    {
        if ($et->XmpValidate !== null and count($propList) > 2) {
            $last = count($propList) - 1;
            if (preg_match('/^x:x[ma]pmeta$/', $propList[0]) and
                $propList[1] === 'rdf:RDF' and
                preg_match('/rdf:Description( |$)/', $propList[2]))
            {
                if (count($propList) > 3) {
                    if (preg_match('/^rdf:(Bag|Seq|Alt)$/', $propList[$last])) {
                        $et->Warn("Ignored empty {$propList[$last]} list for {$propList[$last - 1]}", 1);
                    } else {
                        if ($propList[$last - 1] === 'rdf:Alt' and $attr) {
                            $lang = $attr['xml:lang'] ?? null;
                            if ($lang and count($propList) >= 5) {
                                $langPath = implode('/', array_slice($propList, 3, $last - 1 - 3));
                                $et->XmpValidateLangAlt ??= [];
                                if (!empty($et->XmpValidateLangAlt[$langPath][$lang])) {
                                    $et->Warn("Duplicate language ($lang) in lang-alt list: $langPath");
                                } else {
                                    $et->XmpValidateLangAlt[$langPath][$lang] = 1;
                                }
                            }
                        }
                        if ((preg_match('/^(\w+):(\w+)/', $propList[$last], $m) and !empty(self::$validateNS[$m[1]])) or
                            ($propList[$last] === 'rdf:li' and preg_match('/^(\w+):(\w+)/', $propList[$last - 2], $m) and !empty(self::$validateNS[$m[1]])))
                        {
                            $tbl = ExifTool::GetTagTable("Image::ExifTool::XMP::$m[1]");
                            if ($tbl and !$tbl[$m[2]]) $et->Warn("Non-standard XMP property: $m[1]:$m[2]");
                        }
                        $path = implode('/', array_slice($propList, 3));
                        if (isset($et->XmpValidate[$path])) {
                            $et->Warn("Duplicate XMP property: $path");
                        } else {
                            $et->XmpValidate[$path] = 1;
                        }
                    }
                }
            } elseif ($propList[0] !== 'rdf:RDF' or
                     !preg_match('/rdf:Description( |$)/', $propList[1]))
            {
                $et->Warn('Improperly enclosed XMP property: ' . implode('/', $propList));
            }
        }
    }

    //------------------------------------------------------------------------------
    // Check XMP date values for validity and format accordingly
    // Inputs: 1) EXIF-format date string (XMP-format also accepted)
    // Returns: XMP date/time string (or undef on error)
    public static function FormatXMPDate($val)
    {
        $val = Perl::str($val);
        $tz = null;
        if (preg_match('/(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}(?::\d{2}(?:\.\d*)?)?)(.*)/', $val, $m) or
            preg_match('/(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}(?::\d{2}(?:\.\d*)?)?)(.*)/', $val, $m))
        {
            [$y, $mo, $d, $t, $tz] = [$m[1], $m[2], $m[3], $m[4], $m[5]];
            $val = "$y-$mo-{$d}T$t";
        } elseif (preg_match('/^\s*\d{4}(:\d{2}){0,2}\s*$/', $val)) {
            // this is just a date (YYYY, YYYY-mm or YYYY-mm-dd)
            $val = strtr($val, ':', '-');
        } elseif (preg_match('/^\s*(\d{2}:\d{2}(?::\d{2}(?:\.\d*)?)?)(.*)\s*$/', $val, $m)) {
            // this is just a time
            [$t, $tz] = [$m[1], $m[2]];
            $val = $t;
        } else {
            return null;
        }
        if ($tz) {
            if (!preg_match('/^(Z|[+-]\d{2}:\d{2})$/', $tz)) return null;
            $val .= $tz;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Check XMP values for validity and format accordingly
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref, 3) conversion type
    // Returns: error string or undef (and may change value) on success
    // Note: copies structured information to avoid conflicts with calling code
    public static function CheckXMP(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        if ($tagInfo->Struct) {
            $item = $err = $w = $warn = null;
            if (!is_array($valPtr) and !is_object($valPtr)) {
                [$valPtr, $warn] = self::InflateStruct($et, $valPtr);
                // expect a structure HASH ref or ARRAY of structures
                if (!is_array($valPtr) and !is_object($valPtr)) {
                    if (Perl::str($valPtr) === '') {
                        $valPtr = self::EMPTY_STRUCT;
                        return null;    // allow empty structures
                    }
                    return 'Improperly formed structure';
                }
            }
            if (is_array($valPtr) and array_is_list($valPtr)) {
                if (!$tagInfo->List) return 'Not a list tag';
                $copy = $valPtr;    // copy the list for ExifTool to use
                foreach ($copy as &$item) {
                    if (!self::IsHash($item)) {
                        if (is_array($item) or is_object($item)) {  // (PHP: can't inflate a reference)
                            $err = 'Improperly formed structure';
                            break;
                        }
                        [$item, $w] = self::InflateStruct($et, $item);  // deserialize structure
                        if ($w) $warn = $w;
                        if (self::IsHash($item)) continue;
                        $err = 'Improperly formed structure';
                        break;
                    }
                    [$item, $err] = self::CheckStruct($et, $item, $tagInfo->Struct);
                    if ($err) break;
                }
                unset($item);
                $valPtr = $copy;    // return the copy
            } else {
                [$valPtr, $err] = self::CheckStruct($et, $valPtr, $tagInfo->Struct);
            }
            if ($warn) $et->CHECK_WARN = $warn;
            return $err;
        }
        $format = $tagInfo->Writable;
        // (if no format specified, value is a simple string)
        if (!$format or $format === 'string' or $format === 'lang-alt') {
            // convert value to UTF8 if necessary
            if ($et->OPTIONS['Charset'] !== 'UTF8') {
                if (preg_match('/[\x80-\xff]/', Perl::str($valPtr))) {
                    // convert from Charset to UTF-8
                    $valPtr = $et->Encode($valPtr, 'UTF8');
                }
            } else {
                // translate invalid XML characters to "."
                $valPtr = preg_replace('/[\0-\x08\x0b\x0c\x0e-\x1f]/', '.', Perl::str($valPtr));
                // fix any malformed UTF-8 characters
                if (self::FixUTF8($valPtr) and !$et->WarnBadUTF8) {
                    $et->Warn('Malformed UTF-8 character(s)');
                    $et->WarnBadUTF8 = 1;
                }
            }
            return null;    // success
        }
        $format = Perl::str($format);
        if ($format === 'rational' or $format === 'real') {
            // make sure the value is a valid floating point number
            if (!(ExifTool::IsFloat($valPtr) or
                // allow 'inf' and 'undef' rational values
                ($format === 'rational' and (Perl::str($valPtr) === 'inf' or
                 Perl::str($valPtr) === 'undef' or ExifTool::IsRational($valPtr)))))
            {
                return 'Not a floating point number';
            }
            if ($format === 'rational') {
                $valPtr = implode('/', array_map(Perl::str(...), ExifTool::Rationalize($valPtr, 0xffffffff)));
            }
        } elseif ($format === 'integer') {
            // make sure the value is integer
            if (ExifTool::IsInt($valPtr)) {
                // no conversion required (converting to 'int' would remove leading '+')
            } elseif (ExifTool::IsHex($valPtr)) {
                $valPtr = Perl::hex($valPtr);
            } else {
                return 'Not an integer';
            }
        } elseif ($format === 'date') {
            $newDate = self::FormatXMPDate($valPtr);
            if (!$newDate) return 'Invalid date/time (use YYYY:mm:dd HH:MM:SS[.ss][+/-HH:MM|Z])';
            $valPtr = $newDate;
        } elseif ($format === 'boolean') {
            $v = Perl::str($valPtr);
            // (allow lower-case 'true' and 'false' if not setting PrintConv value)
            if (!$valPtr or preg_match('/false/i', $v) or preg_match('/^no$/i', $v)) {
                if (!$valPtr or $v !== 'false' or !$convType or $convType === 'PrintConv') {
                    $valPtr = 'False';
                }
            } elseif ($v !== 'true' or !$convType or $convType === 'PrintConv') {
                $valPtr = 'True';
            }
        } elseif ($format === '1') {
            // this is the entire XMP data block
            if (!self::ValidateXMP($valPtr)) return 'Invalid XMP data';
        } else {
            return "Unknown XMP format: $format";
        }
        return null;    // success!
    }

    //------------------------------------------------------------------------------
    // Get PropertyPath for specified tagInfo
    // Inputs: 0) tagInfo reference
    // Returns: PropertyPath string
    public static function GetPropertyPath(TagInfo $tagInfo)
    {
        if (!$tagInfo->PropertyPath) self::SetPropertyPath($tagInfo->Table, $tagInfo->TagID);
        return $tagInfo->PropertyPath;
    }

    //------------------------------------------------------------------------------
    // Set PropertyPath for specified tag (also for associated flattened tags and structure elements)
    // Inputs: 0) tagTable reference, 1) tagID, 2) tagID of parent structure,
    //         3) structure definition ref (or undef), 4) property list up to this point (or undef),
    //         5) flag set if any containing structure has a TYPE
    // Notes: also generates flattened tags if they don't already exist
    // (PHP: 3) is the structure definition itself, passed by reference)
    public static function SetPropertyPath(?TagTable $tagTablePtr, $tagID, $parentID = null, ?array &$structPtr = null,
        ?array $propList = null, $isType = null): void
    {
        if (!$tagTablePtr) return;
        $tagInfo = $structPtr !== null ? ($structPtr[$tagID] ?? null) : $tagTablePtr[$tagID];
        $flatInfo = null;

        // (shouldn't happen)
        if ($structPtr !== null ? !is_array($tagInfo) : !$tagInfo instanceof TagInfo) return;
        // (PHP: a structure field is read through a TagInfo)
        $info = $tagInfo instanceof TagInfo ? $tagInfo : new TagInfo($tagInfo);

        if ($structPtr !== null) {
            $flatID = $parentID . ucfirst(Perl::str($tagID));
            $flatInfo = $tagTablePtr[$flatID];
            if ($flatInfo) {
                if ($flatInfo->PropertyPath) return;
            } elseif (count($propList ?? []) > 50) {
                return;     // avoid deep recursion
            } else {
                // flattened tag doesn't exist, so create it now
                // (could happen if we were just writing a structure)
                $flatInfo = new TagInfo(['Name' => ucfirst($flatID), 'Flat' => 1]);
                ExifTool::AddTagToTable($tagTablePtr, $flatID, $flatInfo);
            }
            if (!empty($structPtr['TYPE'])) $isType = 1;
        } else {
            // don't override existing main table entry if already set by a Struct
            if ($tagInfo->PropertyPath) return;
            // use property path from original tagInfo if this is an alternate-language tag
            $srcInfo = $tagInfo->SrcTagInfo;
            if ($srcInfo) $tagInfo->PropertyPath = self::GetPropertyPath($srcInfo);
            if ($tagInfo->PropertyPath) return;
            // set property path for all flattened tags in structure if necessary
            if ($tagInfo->RootTagInfo) {
                self::SetPropertyPath($tagTablePtr, $tagInfo->RootTagInfo->TagID);
                if ($tagInfo->PropertyPath) return;
                ExifTool::PerlWarn("Internal Error: Didn't set path from root for $tagID\n");
                ExifTool::PerlWarn("(Is the Struct NAMESPACE defined?)\n");
            }
        }
        $ns = $info->Namespace ?: ($structPtr !== null ? ($structPtr['NAMESPACE'] ?? null) : $tagTablePtr['NAMESPACE']);
        if (!$ns) {
            ExifTool::PerlWarn("No namespace for $tagID\n");
            return;
        }
        $propList = $propList ?? [];
        $listType = null;
        $propList[] = "$ns:$tagID";
        // lang-alt lists are handled specially, signified by Writable='lang-alt'
        if ($info->Writable and $info->Writable === 'lang-alt') {
            $listType = 'Alt';
            // remove language code from property path if it exists
            if ($info->LangCode) {
                $propList[count($propList) - 1] = preg_replace(ExifTool::Rx('-' . $info->LangCode . '$'), '', $propList[count($propList) - 1], 1);
            }
            // handle lists of lang-alt lists (eg. XMP-plus:Custom tags)
            if ($info->List and Perl::str($info->List) !== '1') {
                array_push($propList, "rdf:{$info->List}", 'rdf:li 10');
            }
        } else {
            $listType = $info->List;
        }
        // add required properties if this is a list
        if ($listType and Perl::str($listType) !== '1') array_push($propList, "rdf:$listType", 'rdf:li 10');
        // set PropertyPath for all flattened tags of this structure if necessary
        $hasStruct = ($structPtr !== null ? self::HasStruct($tagInfo) : (bool) $tagInfo->Struct);
        if ($hasStruct and !($parentID and
            // must test NoSubStruct flag to avoid infinite recursion
            (($tagTablePtr[$parentID] and $tagTablePtr[$parentID]->NoSubStruct) or
            strlen($parentID) > 500)))  // avoid deep recursion
        {
            if ($structPtr !== null) {
                $strTable = &self::FieldStruct($structPtr, $tagID);
            } else {
                $strTable = &$tagInfo->Struct;
            }
            if (is_array($strTable)) {  // (PHP: a structure named in the UserDefined xmpStruct isn't supported)
                // make sure the structure namespace has been registered
                // (user-defined namespaces may not have been)
                if (is_array($strTable['NAMESPACE'] ?? null)) self::RegisterNamespace($strTable);
                foreach (array_keys($strTable) as $tag) {
                    $tag = (string) $tag;
                    // ignore special fields and any lang-alt fields we may have added
                    if (!empty(self::$specialStruct[$tag]) or
                        (is_array($strTable[$tag]) and !empty($strTable[$tag]['LangCode']))) continue;
                    $fullID = $parentID ? $parentID . ucfirst(Perl::str($tagID)) : $tagID;
                    self::SetPropertyPath($tagTablePtr, $tag, $fullID, $strTable, $propList, $isType);
                }
            }
            unset($strTable);
        }
        // if this was a structure field and not a normal tag,
        // we set PropertyPath in the corresponding flattened tag
        if ($structPtr !== null) {
            $tagInfo = $flatInfo;
            // set StructType flag if any containing structure has a TYPE
            if ($isType) $tagInfo->StructType = 1;
        }
        // set property path for tagInfo in main table
        $tagInfo->PropertyPath = implode('/', $propList);
    }

    //------------------------------------------------------------------------------
    // Save XMP property name/value for rewriting
    // Inputs: 0) ExifTool object reference
    //         1) reference to array of XMP property path (last is current property)
    //         2) property value, 3) optional reference to hash of property attributes
    public static function CaptureXMP(ExifTool $et, array &$propList, $val, ?array $attrs = null): void
    {
        if (!($val !== null and count($propList) > 2)) return;
        $last = count($propList) - 1;
        if (preg_match('/^x:x[ma]pmeta$/', $propList[0]) and
            $propList[1] === 'rdf:RDF' and
            preg_match('/' . self::$rdfDesc . '( |$)/', $propList[2]))
        {
            // no properties to save yet if this is just the description
            if (!(count($propList) > 3)) return;
            // ignore empty list properties
            if (preg_match('/^rdf:(Bag|Seq|Alt)$/', $propList[$last])) {
                $et->Warn("Ignored empty {$propList[$last]} list for {$propList[$last - 1]}", 1);
                return;
            }
            // save information about this property
            $path = implode('/', array_slice($propList, 3));
            if (isset($et->XMP_CAPTURE[$path])) {
                $et->XMP_ERROR = "Duplicate XMP property: $path";
            } else {
                $et->XMP_CAPTURE[$path] = [$val, $attrs ?: []];
            }
        } elseif ($propList[0] === 'rdf:RDF' and
                 preg_match('/' . self::$rdfDesc . '( |$)/', $propList[1]))
        {
            // set flag so we don't write x:xmpmeta element
            $et->XMP_NO_XMPMETA = 1;
            // add missing x:xmpmeta element and try again
            array_unshift($propList, 'x:xmpmeta');
            self::CaptureXMP($et, $propList, $val, $attrs);
        } else {
            $et->XMP_ERROR = 'Improperly enclosed XMP property: ' . implode('/', $propList);
        }
    }

    //------------------------------------------------------------------------------
    // Save information about resource containing blank node with nodeID
    // Inputs: 0) reference to blank node information hash
    //         1) reference to property list
    //         2) property value
    //         3) [optional] reference to attribute hash
    // Notes: This routine and ProcessBlankInfo() are also used for reading information, but
    //        are uncommon so are put in this file to reduce compile time for the common case
    public static function SaveBlankInfo(array &$blankInfo, array $propListPt, $val, ?array $attrs = null): void
    {
        $propPath = implode('/', $propListPt);
        preg_match_all('{ #([^ /]*)}', $propPath, $mm);
        // split the property path at each nodeID
        foreach ($mm[1] as $id) {
            if (!preg_match('{^(.*?)/([^/]*) #' . preg_quote($id, '{') . '((/.*)?)$}', $propPath, $m)) {
                ExifTool::PerlWarn("internal error parsing nodeID's");
                continue;
            }
            [$pre, $prop, $post] = [$m[1], $m[2], $m[3]];
            // the element with the nodeID should be in the path prefix for subject
            // nodes and the path suffix for object nodes
            if ($prop !== self::$rdfDesc) {
                if ($post) {
                    $post = "/$prop$post";
                } else {
                    $pre = "$pre/$prop";
                }
            }
            $blankInfo['Prop'][$id]['Pre'][$pre] = 1;
            if (strlen($post) or ($val !== null and strlen($val))) {
                // save the property value and attributes for each unique path suffix
                $blankInfo['Prop'][$id]['Post'][$post] = [$val, $attrs, $propPath];
            }
        }
    }

    //------------------------------------------------------------------------------
    // Process blank-node information
    // Inputs: 0) ExifTool object ref, 1) tag table ref,
    //         2) blank node information hash ref, 3) flag set for writing
    public static function ProcessBlankInfo(ExifTool $et, TagTable $tagTablePtr, array $blankInfo, $isWriting = null): void
    {
        if (!$isWriting) $et->VPrint(1, "  [Elements with nodeID set:]\n");
        // handle each nodeID separately
        $ids = array_map('strval', array_keys($blankInfo['Prop']));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $path = $blankInfo['Prop'][$id];
            $posts = array_map('strval', array_keys($path['Post'] ?? []));
            sort($posts, SORT_STRING);
            // flag all resource names so we can warn later if some are unused
            $unused = array_fill_keys($posts, 1);
            // combine property paths for all possible paths through this node
            $pres = array_map('strval', array_keys($path['Pre'] ?? []));
            sort($pres, SORT_STRING);
            foreach ($pres as $pre) {
                // there will be no description for the object of a blank node
                if (!str_contains($pre, '/' . self::$rdfDesc . '/')) continue;
                foreach ($posts as $post) {
                    $propList = Perl::split('{/}', "$pre$post");
                    [$val, $attrs] = $path['Post'][$post];
                    if ($isWriting) {
                        self::CaptureXMP($et, $propList, $val, $attrs);
                    } else {
                        self::FoundXMP($et, $tagTablePtr, $propList, $val);
                    }
                    unset($unused[$post]);
                }
            }
            // save information from unused properties (if RDF is malformed like f-spot output)
            if ($unused) {
                if ($et->Options('Verbose')) $et->Warn('An XMP resource is about nothing');
                foreach (array_keys($unused) as $post) {
                    [$val, $attrs, $propPath] = $path['Post'][$post];
                    $propList = Perl::split('{/}', $propPath);
                    if ($isWriting) {
                        self::CaptureXMP($et, $propList, $val, $attrs);
                    } else {
                        self::FoundXMP($et, $tagTablePtr, $propList, $val);
                    }
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Convert path to namespace used in file (this is a pain, but the XMP
    // spec only suggests 'preferred' namespace prefixes...)
    // Inputs: 0) ExifTool object reference, 1) property path
    // Returns: conforming property path
    public static function ConformPathToNamespace(?ExifTool $et, $path)
    {
        $propList = Perl::split('{/}', $path);
        $nsUsed = ($et ? $et->XMP_NS : null) ?? [];
        foreach ($propList as &$prop) {
            if (!preg_match('/(.+?):(.*)/', $prop, $m)) continue;
            [$ns, $tag] = [$m[1], $m[2]];
            if (!empty($nsUsed[$ns])) continue;
            $uri = Vars\XMP::$nsURI[$ns] ?? null;
            if (!$uri) {
                ExifTool::PerlWarn("No URI for namespace prefix $ns!\n");
                continue;
            }
            foreach ($nsUsed as $ns2 => $u) {
                if (Perl::str($u) !== Perl::str($uri)) continue;
                // use the existing namespace prefix instead of ours
                $prop = "$ns2:$tag";
                break;
            }
        }
        unset($prop);
        return implode('/', $propList);
    }

    //------------------------------------------------------------------------------
    // Add necessary rdf:type element when writing structure
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) capture hash ref, 3) path string
    //         4) optional base path (already conformed to namespace) for elements in
    //            variable-namespace structures
    public static function AddStructType(?ExifTool $et, TagTable $tagTablePtr, array &$capture, $path, $basePath = null): void
    {
        $props = Perl::split('{/}', $path);
        $doneID = [];
        for (;;) {
            array_pop($props);
            if (!$props) break;
            $tagID = self::GetXMPTagID($props)[0];
            if (!empty($doneID[(string) $tagID])) continue;
            $doneID[(string) $tagID] = 1;
            $tagInfo = $tagID !== null ? $tagTablePtr[$tagID] : null;
            if (!$tagInfo instanceof TagInfo) break;
            if ($tagInfo->Struct) {
                $type = is_array($tagInfo->Struct) ? ($tagInfo->Struct['TYPE'] ?? null) : null;
                if ($type) {
                    $pat = $tagInfo->PropertyPath;
                    if (!$pat) {
                        ExifTool::PerlWarn("Missing PropertyPath in AddStructType\n");
                        break;
                    }
                    $pat = self::ConformPathToNamespace($et, $pat);
                    $pat = preg_replace('/ \d+/', ' \\d+', $pat);
                    if (!preg_match(ExifTool::Rx('^(' . $pat . ')'), $path, $m)) {
                        ExifTool::PerlWarn("Wrong path in AddStructType\n");
                        break;
                    }
                    $p = $m[1] . '/rdf:type';
                    if ($basePath) $p = "$basePath/$p";
                    if (!isset($capture[$p])) $capture[$p] = ['', ['rdf:resource' => $type]];
                }
            }
            if (!$tagInfo->StructType) break;
        }
    }

    //------------------------------------------------------------------------------
    // Process SphericalVideoXML (see XMP-GSpherical tags documentation)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: SphericalVideoXML data
    public static function ProcessGSpherical(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        // extract SphericalVideoXML as a block if requested
        if (!empty($et->REQ_TAG_LOOKUP['sphericalvideoxml'])) {
            $dataPt = $dirInfo['DataPt'];
            $et->FoundTag('SphericalVideoXML', substr($dataPt instanceof ScalarRef ? $dataPt->value : $dataPt, 16));
        }
        return self::ProcessXMP($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Hack to use XMP writer for SphericalVideoXML (see XMP-GSpherical tags documentation)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: SphericalVideoXML data
    public static function WriteGSpherical(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;     // (PHP: dummy call to load the write proc)
        $dirInfo['Compact'] = 1;
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        if ($dataPt !== null and $dataPt) {
            // make it look like XMP for writing
            $buff = $dataPt;
            $buff = preg_replace('/<rdf:SphericalVideo/', "<?xpacket begin='.*?' id='W5M0MpCehiHzreSzNTczkc9d'?>\n<x:xmpmeta xmlns:x='adobe:ns:meta/'><rdf:RDF", $buff, 1);
            $buff = preg_replace('/\s*xmlns:GSpherical/s', ">\n<rdf:Description xmlns:GSpherical", $buff, 1);
            $buff = preg_replace('/<\/rdf:SphericalVideo>/', '</rdf:Description>', $buff, 1);
            $buff .= "</rdf:RDF></x:xmpmeta><?xpacket end='w'?>";
            $dirInfo['DataPt'] = $buff;
            $dirInfo['DirLen'] = strlen($buff) - (($dirInfo['DirStart'] ?? null) ?: 0);
        }
        $xmp = self::WriteXMP($et, $dirInfo, $tagTablePtr);
        if ($xmp) {
            // change back to rdf:SphericalVideo structure
            $xmp = preg_replace('/^<\?xpacket begin.*?<rdf:RDF/s', "<rdf:SphericalVideo\n", $xmp, 1);
            $xmp = preg_replace('/>\s*<rdf:Description rdf:about=\'\'\s*/', "\n ", $xmp, 1);
            $xmp = preg_replace('/\s*<\/rdf:Description>\s*(<\/rdf:RDF>)/s', "\n</rdf:SphericalVideo>\${1}", $xmp, 1);
            $xmp = preg_replace('/\s*<\/rdf:RDF>\s*<\/x:xmpmeta>.*/s', '', $xmp, 1);
        }
        return $xmp;
    }

    //------------------------------------------------------------------------------
    // Utility routine to encode data in base64
    // Inputs: 0) binary data string, 1) flag to avoid inserting newlines
    // Returns:   base64-encoded string
    // (ponytail: base64_encode() with a newline after every 60 characters is what the
    //  uuencoding of 45-byte chunks gives)
    public static function EncodeBase64($dat, $noNewlines = null)
    {
        $str = base64_encode(Perl::str($dat));
        if ($noNewlines or $str === '') return $str;
        return chunk_split($str, 60, "\n");
    }

    //------------------------------------------------------------------------------
    // sort tagInfo hash references by tag name
    public static function ByTagName(TagInfo $a, TagInfo $b): int
    {
        return strcmp(Perl::str($a->Name), Perl::str($b->Name));
    }

    //------------------------------------------------------------------------------
    // sort alphabetically, but with rdf:type first in the structure
    public static function TypeFirst($a, $b): int
    {
        $a = (string) $a;
        $b = (string) $b;
        if (preg_match('/rdf:type$/', $a)) {
            if (!preg_match('/rdf:type$/', $b)) return strcmp(substr($a, 0, -8), $b);
        } elseif (preg_match('/rdf:type$/', $b)) {
            return strcmp($a, substr($b, 0, -8));
        }
        return strcmp($a, $b);
    }

    //------------------------------------------------------------------------------
    // Limit size of XMP
    // Inputs: 0) ExifTool object ref, 1) XMP data ref (written up to start of $rdfClose),
    //         2) max XMP len, 3) rdf:about string, 4) list ref for description start offsets
    //         5) start offset of first description recommended for extended XMP
    // Returns: 0) extended XMP ref, 1) GUID and updates $$dataPt (or undef if no extended XMP)
    // (PHP: returns the extended XMP data itself)
    public static function LimitXMPSize(ExifTool $et, &$dataPt, $maxLen, $about, array &$startPt, $extStart): array
    {
        // return straight away if it isn't too big
        if (strlen($dataPt) < $maxLen) return [null, null];

        [$sp, $nl, $rdfDesc] = [self::$sp, self::$nl, self::$rdfDesc];
        $startPt[] = strlen($dataPt);   // add end offset to list
        $newData = substr($dataPt, 0, $startPt[0]);
        $guid = str_repeat('0', 32);
        // write the required xmpNote:HasExtendedXMP property
        $newData .= "$nl$sp<$rdfDesc rdf:about='{$about}'\n" . $sp . $sp . "xmlns:xmpNote='" . Vars\XMP::$nsURI['xmpNote'] . "'";
        if (!empty($et->OPTIONS['Compact']['Shorthand'])) {
            $newData .= "\n" . $sp . $sp . "xmpNote:HasExtendedXMP='{$guid}'/>\n";
        } else {
            $newData .= ">$nl$sp$sp<xmpNote:HasExtendedXMP>$guid</xmpNote:HasExtendedXMP>$nl$sp</$rdfDesc>\n";
        }

        $descSize = [];
        // calculate all description block sizes
        for ($i = 1; $i < count($startPt); ++$i) {
            $descSize[$startPt[$i - 1]] = $startPt[$i] - $startPt[$i - 1];
        }
        array_pop($startPt);    // remove end offset
        // write the descriptions from smallest to largest, as many in main XMP as possible
        $descStart = $startPt;
        usort($descStart, static fn ($a, $b) => $descSize[$a] <=> $descSize[$b]);
        $extData = self::XMPOpen($et) . self::RdfOpen();
        for ($i = 0; $i < 2; ++$i) {
            foreach ($descStart as $start) {
                // write main XMP first (in order of size), then extended XMP afterwards (in order)
                if ((bool) $i !== ($start >= $extStart)) continue;
                if (strlen($newData) + $descSize[$start] > $maxLen) {
                    $extData .= substr($dataPt, $start, $descSize[$start]);
                } else {
                    $newData .= substr($dataPt, $start, $descSize[$start]);
                }
            }
        }
        $extData .= self::RDF_CLOSE . self::XMP_CLOSE;  // close rdf:RDF and x:xmpmeta
        // calculate GUID from MD5 of extended XMP data
        $guid = strtoupper(md5($extData));
        $newData = preg_replace('/0{32}/', $guid, $newData, 1);    // update GUID in main XMP segment
        $et->VerboseValue('+ XMP-xmpNote:HasExtendedXMP', $guid);
        $dataPt = $newData;         // return main XMP block
        return [$extData, $guid];   // return extended XMP and its GUID
    }

    //------------------------------------------------------------------------------
    // Close out bottom-level property
    // Inputs: 0) current property path list ref, 1) longhand properties at each resource
    //         level, 2) shorthand properties at each resource level, 3) resource flag for
    //         each property path level (set only if Shorthand is enabled)
    // (PHP: $$long[-1] is $long[$L], $$long[-2] is $long[$L - 1], $$short[-1] is $short[$S])
    public static function CloseProperty(array &$curPropList, array &$long, array &$short, array &$resFlag): void
    {
        [$sp, $nl, $rdfDesc] = [self::$sp, self::$nl, self::$rdfDesc];

        $prop = array_pop($curPropList);
        $prop = preg_replace('/ .*/', '', $prop, 1);    // remove list index if it exists
        $n = count($curPropList);
        $pad = str_repeat($sp, $n + 1);
        $L = count($long) - 1;
        $S = count($short) - 1;
        if (!empty($resFlag[$n])) {
            // close this XMP structure with possible shorthand properties
            if (strlen($short[$S])) {
                if (strlen($long[$L])) {
                    // require a new Description if both longhand and shorthand properties
                    $long[$L - 1] .= ">$nl$pad<$rdfDesc";
                    $short[$S] .= ">$nl";
                    $long[$L] .= "$pad</$rdfDesc>$nl";
                } else {
                    // simply close empty property if all shorthand
                    $short[$S] .= "/>$nl";
                }
            } else {
                // use "parseType" instead of opening a new Description
                $long[$L - 1] .= ' rdf:parseType="Resource"';
                $short[$S] = strlen($long[$L]) ? ">$nl" : "/>$nl";
            }
            if (strlen($long[$L])) $long[$L] .= "$pad</$prop>$nl";
            $long[$L - 1] .= $short[$S] . $long[$L];
            array_pop($short);
            array_pop($long);
        } elseif (isset($resFlag[$n])) {
            // close this top level Description with possible shorthand values
            if (strlen($long[$L])) {
                $long[$L - 1] .= $short[$S] . ">$nl" . $long[$L] . "$pad</$prop>$nl";
            } else {
                $long[$L - 1] .= $short[$S] . "/>$nl";  // empty element (ie. all shorthand)
            }
            $short[$S] = $long[$L] = '';
        } else {
            // close this property (no chance of shorthand)
            $long[$L] .= "$pad</$prop>$nl";
            if (!$curPropList) {
                // add properties now that this top-level Description is complete
                $long[$L - 1] .= ">$nl" . $long[$L];
                $long[$L] = '';
            }
        }
        // remove expired resource flags
        foreach (array_keys($resFlag) as $k) {
            if ($k >= $n) unset($resFlag[$k]);
        }
    }

    //------------------------------------------------------------------------------
    // Write XMP information
    // Inputs: 0) ExifTool ref, 1) source dirInfo ref (with optional WriteGroup),
    //         2) [optional] tag table ref
    // Returns: with tag table: new XMP data (may be empty if no XMP data) or undef on error
    //          without tag table: 1 on success, 0 if not valid XMP file, -1 on write error
    // Notes: May set dirInfo InPlace flag to rewrite with specified DirLen (=2 to allow larger)
    //        May set dirInfo ReadOnly flag to write as read-only XMP ('r' mode and no padding)
    //        May set dirInfo Compact flag to force compact (drops 2kB of padding)
    //        May set dirInfo MaxDataLen to limit output data length -- this causes ExtendedXMP
    //          and ExtendedGUID to be returned in dirInfo if extended XMP was required
    // (PHP: the capture hash is $et->XMP_CAPTURE, which $capture references, like Perl's
    //  $$et{XMP_CAPTURE} = \%capture; the nodes of a path are sorted by TypeFirst)
    public static function WriteXMP(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $xmpErr = $about = null;
        $changed = 0;
        $xmpFile = !$tagTablePtr;   // this is an XMP data file if no $tagTablePtr
        // prefer XMP over other metadata formats in some types of files
        $preferred = ($xmpFile or ($et->PreferredGroup and $et->PreferredGroup === 'XMP'));
        $verbose = $et->OPTIONS['Verbose'];
        $compact = $et->OPTIONS['Compact'] ?: [];   // (make a copy so we can change settings)
        $dirLen = $dirInfo['DirLen'] ?? null;
        if ($dirLen === null and $dataPt !== null) $dirLen = strlen($dataPt);
        //
        // extract existing XMP information into %capture hash
        //
        // define hash in ExifTool object to capture XMP information (also causes
        // CaptureXMP() instead of FoundXMP() to be called from ParseXMPElement())
        //
        // The %capture hash is keyed on the complete property path beginning after
        // rdf:RDF/rdf:Description/.  The values are array references with the
        // following entries: 0) value, 1) attribute hash reference.
        $et->XMP_CAPTURE = [];
        $capture = &$et->XMP_CAPTURE;
        $et->XMP_NS = [];
        $nsUsed = &$et->XMP_NS;
        unset($et->XMP_NO_XMPMETA, $et->XMP_NO_XPACKET, $et->XMP_IS_XML, $et->XMP_IS_SVG);

        // set current padding characters
        [self::$sp, self::$nl] = [!empty($compact['NoIndent']) ? '' : ' ', !empty($compact['NoNewline']) ? '' : "\n"];
        [$sp, $nl, $rdfDesc] = [self::$sp, self::$nl, self::$rdfDesc];

        // get value for new rdf:about
        $tagInfo = ExifTool::GetTagTable('Image::ExifTool::XMP::rdf')['about'];
        if (($et->NEW_VALUE[spl_object_id($tagInfo)] ?? null) !== null) {
            $about = $et->GetNewValue($et->NEW_VALUE[spl_object_id($tagInfo)]) ?: '';
        }

        if ($xmpFile or $dirLen) {
            unset($et->XMP_ERROR);
            // extract all existing XMP information (to the XMP_CAPTURE hash)
            $success = self::ProcessXMP($et, $dirInfo, $tagTablePtr);
            // don't continue if there is nothing to parse or if we had a parsing error
            if (!($success and !$et->XMP_ERROR)) {
                $err = $et->XMP_ERROR ?: 'Error parsing XMP';
                // may ignore this error only if we were successful
                if ($xmpFile) {
                    $raf = $dirInfo['RAF'];
                    // allow empty XMP data so we can create something from nothing
                    if ($success or !$raf->Seek(0, 2) or $raf->Tell()) {
                        // no error message if not an XMP file
                        if (!$et->XMP_ERROR) return 0;
                        if ($et->Error($err, $success)) {
                            unset($et->XMP_CAPTURE);
                            return 0;
                        }
                    }
                } else {
                    if ($success and Perl::str($success) === '1') $success = 2;
                    if ($et->Warn($err, $success)) {
                        unset($et->XMP_CAPTURE);
                        return null;
                    }
                }
            }
            if ($about !== null) {
                if ($verbose > 1) {
                    $wasAbout = $et->XmpAbout;
                    if ($wasAbout !== null) $et->VerboseValue('- XMP-rdf:About', self::UnescapeXML($wasAbout));
                    $et->VerboseValue('+ XMP-rdf:About', $about);
                }
                $about = self::EscapeXML($about);   // must escape for XML
                ++$changed;
            } else {
                $about = $et->XmpAbout ?: '';
            }
            unset($et->XMP_ERROR);

            // call InitWriteDirs to initialize FORCE_WRITE flags if necessary
            // (PHP: an empty map, but an empty PHP array would be a list)
            if ($xmpFile and $et->GetNewValue('ForceWrite')) $et->InitWriteDirs(['XMP' => null], 'XMP');
            // set changed if we are ForceWrite tag was set to "XMP"
            if (!empty($et->FORCE_WRITE['XMP'])) ++$changed;

        } elseif ($about !== null) {
            $et->VerboseValue('+ XMP-rdf:About', $about);
            $about = self::EscapeXML($about);   // must escape for XML
            // (don't increment $changed here because we need another tag to be written)
        } else {
            $about = '';
        }
        //
        // handle writing XMP as a block to XMP file
        //
        if ($xmpFile) {
            $tagInfo = ExifTool::GetTagTable('Image::ExifTool::Extra')['XMP'];
            if ($tagInfo and ($et->NEW_VALUE[spl_object_id($tagInfo)] ?? null)) {
                $rtnVal = 1;
                $newVal = $et->GetNewValue($et->NEW_VALUE[spl_object_id($tagInfo)]);
                if ($newVal !== null and strlen($newVal)) {
                    $et->VPrint(0, "  Writing XMP as a block\n");
                    ++$et->CHANGED;
                    if (!ExifTool::Write($dirInfo['OutFile'], $newVal)) $rtnVal = -1;
                }
                unset($et->XMP_CAPTURE);
                return $rtnVal;
            }
        }
        //
        // delete groups in family 1 if requested
        //
        $del = $et->DEL_GROUP ?: [];
        if ($del and (preg_grep('/^XMP-.+$/', array_map('strval', array_keys($del))) or
            // (logic is a bit more complex for group names in exiftool XML files)
            preg_grep('{^http://ns.exiftool.(?:ca|org)/}', array_map('strval', $nsUsed))))
        {
            foreach (array_map('strval', array_keys($capture)) as $path) {
                $propList = Perl::split('{/}', $path);  // get property list
                [$tag, $ns] = self::GetXMPTagID($propList);
                // translate namespace if necessary
                if (!empty(Vars\XMP::$stdXlatNS[$ns])) $ns = Vars\XMP::$stdXlatNS[$ns];
                $grp = null;
                // no "XMP-" added to most groups in exiftool RDF/XML output file
                if (!empty($nsUsed[$ns]) and preg_match('{^http://ns.exiftool.(?:ca|org)/(.*?)/(.*?)/}', $nsUsed[$ns], $g)) {
                    if (preg_match('/^\d/', $g[2])) {
                        $grp = "XML-$g[1]";
                        //(all XML-* groups stored as uppercase DEL_GROUP key)
                        $ucg = strtoupper($grp);
                        if (!(!empty($del[$ucg]) or (!empty($del['XML-*']) and empty($del["-$ucg"])))) continue;
                    } else {
                        $grp = $g[2];
                        if (!(!empty($del[$grp]) or (!empty($del[$g[1]]) and empty($del["-$grp"])))) continue;
                    }
                } else {
                    $grp = "XMP-$ns";
                    $ucg = strtoupper($grp);
                    if (!(!empty($del[$ucg]) or (!empty($del['XMP-*']) and empty($del["-$ucg"])))) continue;
                }
                $et->VerboseValue("- $grp:$tag", $capture[$path][0]);
                unset($capture[$path]);
                ++$changed;
            }
        }
        // delete HasExtendedXMP tag (we create it as needed)
        $hasExtTag = 'xmpNote:HasExtendedXMP';
        if (isset($capture[$hasExtTag])) {
            $et->VerboseValue("- XMP-$hasExtTag", $capture[$hasExtTag][0]);
            unset($capture[$hasExtTag]);
        }
        // set $xmpOpen now to to handle xmptk tag first
        $xmpOpen = $et->XMP_NO_XMPMETA ? '' : self::XMPOpen($et);
        //
        // add, delete or change information as specified
        //
        // get hash of all information we want to change
        // (sorted by tag name so alternate languages come last, but with structures
        // first so flattened tags may be used to override individual structure elements)
        $tagInfoList = $structList = $delLangPaths = $delAllLang = $langTags = [];
        $delLangPath = $firstNewPath = null;
        $writeGroup = $dirInfo['WriteGroup'] ?? null;
        $sorted = $et->GetNewTagInfoList();
        usort($sorted, self::ByTagName(...));
        foreach ($sorted as $tagInfo) {
            if ($et->GetGroup($tagInfo, 0) !== 'XMP') continue;
            if ($tagInfo->Name === 'XMP') continue;     // (ignore full XMP block if we didn't write it already)
            if ($writeGroup and $writeGroup !== $et->NEW_VALUE[spl_object_id($tagInfo)]->WriteGroup) continue;
            if ($tagInfo->LangCode) {
                $langTags[] = $tagInfo;
            } elseif ($tagInfo->Struct) {
                $structList[] = $tagInfo;
            } else {
                $tagInfoList[] = $tagInfo;
            }
        }
        if ($langTags) {
            // keep original order in which lang-alt entries were added
            usort($langTags, static fn ($a, $b) => $et->NEW_VALUE[spl_object_id($a)]->Order <=> $et->NEW_VALUE[spl_object_id($b)]->Order);
            foreach ($langTags as $tagInfo) {
                if ($tagInfo->Struct) {
                    $structList[] = $tagInfo;
                } else {
                    $tagInfoList[] = $tagInfo;
                }
            }
        }
        foreach ([...$structList, ...$tagInfoList] as $tagInfo) {
            $delPaths = [];     // list of deleted paths
            $tag = $tagInfo->TagID;
            $path = self::GetPropertyPath($tagInfo);
            if (!$path) {
                $et->Warn("Can't write XMP:$tag (namespace unknown)");
                continue;
            }
            // skip tags that were handled specially
            if ($path === 'rdf:about' or $path === 'x:xmptk') {
                ++$changed;
                continue;
            }
            $isStruct = $tagInfo->Struct;
            // change our property path namespace prefixes to conform
            // to the ones used in this file
            $path = self::ConformPathToNamespace($et, $path);
            // find existing property
            $cap = $capture[$path] ?? null;
            // MicrosoftPhoto screws up the case of some tags, and some other software,
            // including Adobe software, has been known to write the wrong list type or
            // not properly enclose properties in a list, so we check for this
            while (!$cap) {
                // find and fix all incorrect property names if this is a structure or a flattened tag
                $fixInfo = [];
                if ($isStruct or $tagInfo->Flat !== null) {
                    // get tagInfo for all containing (possibly nested) structures
                    $props = Perl::split('{/}', $path);
                    $tbl = $tagInfo->Table;
                    while ($props) {
                        $id = self::GetXMPTagID($props)[0];
                        $info = $id !== null ? $tbl[$id] : null;
                        if ($info instanceof TagInfo and $info->Struct and
                            (!$fixInfo or $fixInfo[0] !== $info)) array_unshift($fixInfo, $info);
                        array_pop($props);
                    }
                    if (!$fixInfo) $et->Warn("Error finding parent structure for {$tagInfo->Name}");
                }
                // fix property path for this tag (last in the @fixInfo list)
                if (!($fixInfo and $isStruct)) $fixInfo[] = $tagInfo;
                // start from outermost containing structure, fixing incorrect list types, etc,
                // finally fixing the actual tag properties after all containing structures
                $err = null;
                while ($fixInfo) {
                    $fixInfo1 = array_shift($fixInfo);
                    $fixPath = self::ConformPathToNamespace($et, self::GetPropertyPath($fixInfo1));
                    $regex = self::QuoteMeta($fixPath);
                    $regex = preg_replace('/ \d+/', ' \\d+', $regex);  // match any list index
                    $ok = $regex;
                    $ok2 = $match = null;
                    $fixed = [];
                    $fixedH = [];
                    $fixed1 = null;
                    // check for incorrect list types
                    $regex = preg_replace('{\\\\/rdf\\\\:(Bag|Seq|Alt)\\\\/}', '/rdf:(Bag|Seq|Alt)/', $regex, -1, $n);
                    if ($n) {
                        // also look for missing bottom-level list
                        $regex = preg_replace('{/rdf:\(Bag\|Seq\|Alt\)\/rdf\\\\:li\\\\ \\\\d\+$}', '', $regex, 1, $n);
                        if ($n) {
                            if (!$fixInfo) $regex .= '(/.*)?';
                        }
                    } elseif (!$fixInfo) {
                        $ok2 = $regex;
                        // check for properties in lists that shouldn't be (ref forum4325)
                        $regex .= '(/rdf:(Bag|Seq|Alt)/rdf:li \d+)?';
                    }
                    if ($fixInfo) {
                        $regex .= '(/.*)?';
                        $ok .= '(/.*)?';
                    }
                    $matches = self::GrepKeys('^' . $regex . '$', $capture, 'i');
                    sort($matches, SORT_STRING);
                    if (!$matches) break;
                    if (preg_match(ExifTool::Rx('^' . $ok . '$'), $matches[0])) {
                        if (!$fixInfo) {
                            $path = $matches[0];
                            $cap = $capture[$path];
                        }
                        continue;
                    }
                    // needs fixing...
                    $fixProps = Perl::split('{/}', $fixPath);
                    foreach ($matches as $match) {
                        $matchProps = Perl::split('{/}', $match);
                        // remove superfluous list properties if necessary
                        if ($ok2 and count($matchProps) > count($fixProps)) $matchProps = array_slice($matchProps, 0, count($fixProps));
                        for ($i = 0; $i < count($fixProps); ++$i) {
                            if (!isset($matchProps[$i])) {
                                $matchProps[$i] = $fixProps[$i];
                                continue;
                            }
                            if (preg_match('/ \d+$/', $matchProps[$i]) or $matchProps[$i] === $fixProps[$i]) continue;
                            $matchProps[$i] = $fixProps[$i];
                        }
                        $fixed1 = implode('/', $matchProps);
                        if (!empty($fixedH[$fixed1]) or (isset($capture[$fixed1]) and $match !== $fixed1)) $err = 1;
                        $fixed[] = $fixed1;
                        $fixedH[$fixed1] = 1;
                    }
                    $tg = $et->GetGroup($fixInfo1, 1) . ':' . $fixInfo1->Name;
                    $wrn = strtolower($fixed[0]) === strtolower($matches[0]) ? 'tag ID case' : 'list type';
                    if ($err) {
                        $et->Warn("Incorrect $wrn for existing $tg (not changed)");
                    } else {
                        // fix the incorrect property paths for all values of this tag
                        $didFix = null;
                        foreach ($fixed as $fixed1) {
                            $match = array_shift($matches);
                            if ($fixed1 === $match) continue;
                            $capture[$fixed1] = $capture[$match];
                            unset($capture[$match]);
                            // remove xml:lang attribute from incorrect lang-alt list if necessary
                            if ($ok2 and !preg_match(ExifTool::Rx('^' . $ok2 . '$'), $match)) unset($capture[$fixed1][1]['xml:lang']);
                            $didFix = 1;
                        }
                        if (!$fixInfo) $cap = ($capture[$path] ?? null) ?: ($capture[$fixed[0]] ?? null);
                        if ($didFix) {
                            $et->Warn("Fixed incorrect $wrn for $tg", 1);
                            ++$changed;
                        }
                    }
                }
                break;
            }
            $nvHash = $et->GetNewValueHash($tagInfo);
            $overwrite = $et->IsOverwriting($nvHash);
            $writable = $tagInfo->Writable ?: '';
            $attrs = [];
            $deleted = $added = $existed = $newLang = null;
            $pathPos = null;    // (Perl pos($path))
            // set up variables to save/restore paths of deleted lang-alt tags
            if ($writable === 'lang-alt') {
                $newLang = strtolower($tagInfo->LangCode ?: 'x-default');
                if ($delLangPath and $delLangPath === $path) {
                    // restore paths of deleted entries for this language
                    if (!empty($delLangPaths[$newLang])) $delPaths = $delLangPaths[$newLang];
                } else {
                    $delLangPaths = [];
                    $delLangPath = $path;   // base path for deleted lang-alt tags
                    $delAllLang = [];
                    $firstNewPath = null;   // reset first path for new lang-alt tag
                }
                if ($delAllLang) {
                    // add missing paths to delete list for entries where all languages were deleted
                    $reSort = null;
                    foreach (array_map('strval', array_keys($delAllLang)) as $prefix) {
                        if (@preg_grep(ExifTool::Rx('^' . $prefix), $delPaths)) continue;
                        $delPaths[] = "{$prefix}10";
                        $reSort = 1;
                    }
                    if ($reSort) sort($delPaths, SORT_STRING);
                }
            }
            // delete existing entry if necessary
            if ($isStruct) {
                // delete all structure (or pseudo-structure) elements
                [$deleted, $added, $existed] = self::DeleteStruct($et, $capture, $path, $nvHash, $changed);
                // don't add if it didn't exist and not IsCreating and Avoid
                if (!$existed and !$nvHash->IsCreating and $tagInfo->Avoid) $added = null;
                if (!($deleted or $added or $et->IsOverwriting($nvHash))) continue;
                if ($existed and $nvHash->CreateOnly) continue;
            } elseif ($cap) {
                if ($nvHash->CreateOnly) continue;  // (necessary for List-type tags)
                // take attributes from old values if they exist
                $attrs = $cap[1];
                if ($overwrite) {
                    $oldLang = $delLang = $addLang = $langPathPat = null;
                    $matchingPaths = $langsHere = [];
                    // check to see if this is an indexed list item
                    if (str_contains($path, ' ')) {
                        $pp = preg_replace('/ \d+/', ' \\d+', $path);
                        $matchingPaths = self::GrepKeys('^' . $pp . '$', $capture);
                        sort($matchingPaths, SORT_STRING);
                    } else {
                        $matchingPaths[] = $path;
                    }
                    $oldOverwrite = $overwrite;
                    $savePath = $path;  // (PHP: the Perl foreach variable is restored after the loop)
                    foreach ($matchingPaths as $path) {
                        [$val, $attrs1] = $capture[$path];
                        if ($writable === 'lang-alt') {
                            // get original language code (lc for comparisons)
                            $oldLang = strtolower(($attrs1['xml:lang'] ?? null) ?: 'x-default');
                            // revert to original overwrite flag if this is in a different structure
                            if (!$langPathPat or !preg_match(ExifTool::Rx('^' . $langPathPat . '$'), $path)) {
                                $overwrite = $oldOverwrite;
                                $langPathPat = preg_replace('/\d+$/', '\\d+', $path, 1);
                            }
                            // remember languages in this lang-alt list
                            $langsHere[$langPathPat][$oldLang] = 1;
                            if ($addLang === null) {
                                // add to lang-alt list by default if creating this tag from scratch
                                $addLang = $nvHash->IsCreating ? 1 : 0;
                            }
                            if ($overwrite < 0) {
                                if ($oldLang !== $newLang) continue;
                                // only add new tag if we are overwriting this one
                                // (note: this won't match if original XML contains CDATA!)
                                $addLang = $et->IsOverwriting($nvHash, self::UnescapeXML($val));
                                if (!$addLang) continue;
                            }
                            // delete all if deleting "x-default" and writing with no LangCode
                            // (XMP spec requires x-default language exist and be first in list)
                            if ($oldLang === 'x-default' and !$tagInfo->LangCode) {
                                $delLang = 1;   // delete all languages
                                $overwrite = 1; // force overwrite
                            } elseif ($tagInfo->LangCode and !$delLang) {
                                // only overwrite specified language
                                if (strtolower($tagInfo->LangCode) !== $oldLang) continue;
                            }
                        } elseif ($overwrite < 0) {
                            // only overwrite specific values
                            if ($nvHash->Shift) {
                                // values to be shifted are checked (hence re-formatted) late,
                                // so we must un-format the to-be-shifted value for IsOverwriting()
                                $fmt = $tagInfo->Writable ?: '';
                                if ($fmt === 'rational') {
                                    self::ConvertRational($val);
                                } elseif ($fmt === 'date') {
                                    $val = self::ConvertXMPDate($val);
                                }
                            }
                            // (note: this won't match if original XML contains CDATA!)
                            if (!$et->IsOverwriting($nvHash, self::UnescapeXML($val))) continue;
                        }
                        if ($verbose > 1) {
                            $grp = $et->GetGroup($tagInfo, 1);
                            $tagName = $tagInfo->Name;
                            if ($tagInfo->LangCode) $tagName = preg_replace(ExifTool::Rx('-' . $tagInfo->LangCode . '$'), '', $tagName, 1);
                            if (!empty($attrs1['xml:lang'])) $tagName .= '-' . $attrs1['xml:lang'];
                            $et->VerboseValue("- $grp:$tagName", $val);
                        }
                        // save attributes and path from first deleted property
                        // so we can replace it exactly
                        if (!$delPaths) $attrs = $attrs1;
                        if ($writable === 'lang-alt') {
                            $langsHere[$langPathPat][$oldLang] = 0; // (lang was deleted)
                        }
                        // save deleted paths so we can replace the same elements
                        // (separately for each language of a lang-alt list)
                        if ($writable !== 'lang-alt' or $oldLang === $newLang) {
                            $delPaths[] = $path;
                        } else {
                            $delLangPaths[$oldLang] ??= [];
                            $delLangPaths[$oldLang][] = $path;
                        }
                        // keep track of paths where we deleted all languages of a lang-alt tag
                        if ($delLang) {
                            $p = preg_replace('/\d+$/', '', $path, 1);
                            $delAllLang[$p] = 1;
                        }
                        // delete this tag
                        unset($capture[$path]);
                        ++$changed;
                        // delete rdf:type tag if it is the only thing left in this structure
                        if (preg_match('{^(.*)/}', $path, $m) and isset($capture["$m[1]/rdf:type"])) {
                            $pp = $m[1];
                            $a = self::GrepKeys('^' . preg_quote($pp, '/') . '\/[^\/]+', $capture);
                            if (count($a) == 1) unset($capture["$pp/rdf:type"]);
                        }
                    }
                    $path = $savePath;
                    if (!($delPaths or $tagInfo->List or $addLang)) continue;
                    if ($delPaths) {
                        $path = array_shift($delPaths);
                        // make sure new path is unique
                        while (isset($capture[$path])) {
                            $path = preg_replace_callback('/ \d(\d+)$/', static fn ($m) => ' ' . strlen((string) ((int) $m[1] + 1)) . ((int) $m[1] + 1), $path, 1, $n);
                            if (!$n) break;
                        }
                        $deleted = 1;
                    } else {
                        // don't change tag if we couldn't delete old copy
                        // unless this is a list or an lang-alt tag
                        if (!($tagInfo->List or $oldLang)) continue;
                        // avoid adding duplicate entry to lang-alt in a list
                        if ($writable === 'lang-alt' and $langsHere) {
                            $keys = array_map('strval', array_keys($langsHere));
                            sort($keys, SORT_STRING);
                            foreach ($keys as $k) {
                                if (!preg_match(ExifTool::Rx('^' . $k . '$'), $path)) continue;
                                if (empty($langsHere[$k][$newLang])) break;
                                if (!preg_match('/(.* )\d(\d+)(.*? \d+)$/', $path, $m)) {
                                    $et->Error('Internal error writing lang-alt list');
                                    break;
                                }
                                $nxt = (int) $m[2] + 1;
                                $path = $m[1] . strlen((string) $nxt) . $nxt . $m[3];   // step to next index
                            }
                        }
                        // (match last index to put in same lang-alt list for Bag of lang-alt items)
                        if (!preg_match('/.* (\d+)/', $path, $m, PREG_OFFSET_CAPTURE)) {
                            ExifTool::PerlWarn("Internal error: no list index!\n");
                            continue;
                        }
                        $added = $m[1][0];
                        $pathPos = $m[0][1] + strlen($m[0][0]);
                    }
                } else {
                    // we are never overwriting, so we must be adding to a list
                    // match the last index unless this is a list of lang-alt lists
                    $pat = '.* (\d+)';
                    if ($writable === 'lang-alt') {
                        if ($firstNewPath) {
                            $path = $firstNewPath;
                            $overwrite = 1;     // necessary to put x-default entry first below
                        } else {
                            $pat = '.* (\d+)(.*? \d+)';
                        }
                    }
                    if (preg_match("/$pat/", $path, $m, PREG_OFFSET_CAPTURE)) {
                        $added = $m[1][0];
                        $pathPos = $m[0][1] + strlen($m[0][0]);
                        // set position to end of matching index number
                        if (!empty($m[2][0])) $pathPos -= strlen($m[2][0]);
                    }
                }
                if ($added !== null) {
                    $len = strlen($added);
                    $pos = $pathPos - $len;
                    $nxt = (int) substr($added, 1) + 1;
                    // always insert x-default lang-alt entry first (as per XMP spec)
                    // (need to test $overwrite because this will be a new lang-alt entry otherwise)
                    if ($overwrite and $writable === 'lang-alt' and (!$tagInfo->LangCode or
                        $tagInfo->LangCode === 'x-default'))
                    {
                        $saveCap = $capture[$path] ?? null;
                        while ($saveCap) {
                            $p = substr_replace($path, strlen((string) $nxt) . $nxt, $pos, $len);
                            // increment index in the path of the existing item
                            $nextCap = $capture[$p] ?? null;
                            $capture[$p] = $saveCap;
                            if (!$nextCap) break;
                            $saveCap = $nextCap;
                            ++$nxt;
                        }
                    } else {
                        // add to end of list
                        while (isset($capture[$path])) {
                            $try = strlen((string) $nxt) . $nxt;
                            $path = substr_replace($path, $try, $pos, $len);
                            $len = strlen($try);
                            ++$nxt;
                        }
                    }
                }
            }
            // check to see if we want to create this tag
            // (create non-avoided tags in XMP data files by default)
            $isCreating = ($nvHash->IsCreating or (($isStruct or
                           ($preferred and $nvHash->Shift === null)) and
                           !$tagInfo->Avoid and !$nvHash->EditOnly));

            // don't add new values unless...
                // ...tag existed before and was deleted, or we added it to a list
            if (!($deleted or $added !== null or
                // ...tag didn't exist before and we are creating it
                (!$cap and $isCreating))) continue;

            // get list of new values (all done if no new values specified)
            $newValues = $et->GetNewValueList($nvHash);
            if (!$newValues) continue;

            // set language attribute for lang-alt lists
            if ($writable === 'lang-alt') {
                $attrs['xml:lang'] = $tagInfo->LangCode ?: 'x-default';
                if ($added !== null) $firstNewPath = $path;     // save path of first lang-alt tag added
            }
            // add new value(s) to %capture hash
            $subIdx = null;
            for (;;) {
                $newValue = array_shift($newValues);
                if ($isStruct) {
                    if (self::AddNewStruct($et, $tagInfo, $capture, $path, $newValue, $tagInfo->Struct)) ++$changed;
                } else {
                    $newValue = self::EscapeXML($newValue);
                    for (;;) {  // (a cheap 'goto')
                        if ($tagInfo->Resource) {
                            // only store as a resource if it doesn't contain any illegal characters
                            if (!preg_match('/[^a-z0-9\:\/\?\#\[\]\@\!\$\&\'\(\)\*\+\,\;\=\.\-\_\~]/i', $newValue)) {
                                $capture[$path] = ['', [...$attrs, 'rdf:resource' => $newValue]];
                                break;
                            }
                            $grp = $et->GetGroup($tagInfo, 1);
                            $et->Warn("$grp:{$tagInfo->Name} written as a literal because value is not a valid URI", 1);
                            // fall through to write as a string literal
                        }
                        // remove existing value and/or resource attribute if they exist
                        unset($attrs['rdf:value'], $attrs['rdf:resource']);
                        $capture[$path] = [$newValue, $attrs];
                        break;
                    }
                    if ($verbose > 1) {
                        $grp = $et->GetGroup($tagInfo, 1);
                        $et->VerboseValue("+ $grp:{$tagInfo->Name}", $newValue);
                    }
                    ++$changed;
                    // add rdf:type if necessary
                    if ($tagInfo->StructType) {
                        self::AddStructType($et, $tagInfo->Table, $capture, $path);
                    }
                }
                if (!$newValues) break;
                // match last index except for lang-alt items where we want to put each
                // item in a different lang-alt list (so match the 2nd-last for these)
                $pat = $writable === 'lang-alt' ? '.* (\d+)(.*? \d+)' : '.* (\d+)';
                if (!preg_match("/$pat/", $path, $m, PREG_OFFSET_CAPTURE)) {
                    ExifTool::PerlWarn("Internal error: no list index for $tag ($path) ($pat)!\n");
                    continue;
                }
                $idx = $m[1][0];
                $len = strlen($m[1][0]);
                $pos = $m[0][1] + strlen($m[0][0]) - $len - (!empty($m[2][0]) ? strlen($m[2][0]) : 0);
                // use sub-indices if necessary to store additional values in sequence
                if ($subIdx) {
                    $idx = substr($idx, 0, -strlen($subIdx));   // remove old sub-index
                    $subIdx = (int) substr($subIdx, 1) + 1;
                    $subIdx = strlen((string) $subIdx) . $subIdx;
                } elseif ($delPaths) {
                    $path = array_shift($delPaths);
                    // make sure new path is unique
                    while (isset($capture[$path])) {
                        $path = preg_replace_callback('/ \d(\d+)$/', static fn ($m) => ' ' . strlen((string) ((int) $m[1] + 1)) . ((int) $m[1] + 1), $path, 1, $n);
                        if (!$n) break;
                    }
                    continue;
                } else {
                    $subIdx = '10';
                }
                $path = substr_replace($path, $idx . $subIdx, $pos, $len);
            }
            // make sure any empty structures are deleted
            // (ExifTool shouldn't write these, but other software may)
            if ($tagInfo->Flat !== null) {
                $p = $path;
                while (true) {
                    $p = preg_replace('{/[^/]+$}', '', $p, 1, $n);
                    if (!$n) break;
                    if (!isset($capture[$p])) continue;
                    // it is an error if this property has a value
                    if (preg_match('/\S/', Perl::str($capture[$p][0]))) $et->Error("Improperly structured XMP ($p)", 1);
                    unset($capture[$p]);    // delete the (hopefully) empty structure
                }
            }
        }
        // remove the ExifTool members we created
        unset($et->XMP_CAPTURE, $et->XMP_NS);

        $maxDataLen = $dirInfo['MaxDataLen'] ?? null;
        // get DataPt again because it may have been set by ProcessXMP
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;

        // return now if we didn't change anything
        if (!($changed or ($maxDataLen and $dataPt !== null and strlen($dataPt) > $maxDataLen))) {
            if (!$xmpFile) return null;     // just rewrite original XMP
            if ($dataPt !== null) {
                if (!ExifTool::Write($dirInfo['OutFile'], $dataPt)) return -1;
            }
            return 1;
        }
        //
        // write out the new XMP information (serialize it)
        //
        // start writing the XMP data
        $long = ['', ''];
        $short = [''];
        $resFlag = [];
        if ($et->XMP_NO_XPACKET) {
            // write BOM if flag is set
            if ($et->XMP_NO_XPACKET == 2) $long[0] .= "\xef\xbb\xbf";
        } else {
            $long[0] .= self::PKT_OPEN;
        }
        if ($et->XMP_IS_XML) $long[0] .= self::XML_OPEN;
        $long[0] .= $xmpOpen . self::RdfOpen();

        // initialize current property path list
        $curPropList = $writeLast = $descStart = [];
        $extStart = null;
        $nsCur = [];
        $prop = $n = $path = null;
        $pathList = array_map('strval', array_keys($capture));
        usort($pathList, self::TypeFirst(...));
        // order properties to write large values last if we have a MaxDataLen limit
        if ($maxDataLen and $pathList) {
            $pathTmp = [];
            [$lastProp, $lastNS, $propSize] = ['', '', 0];
            $pathLoop = [...$pathList, ''];     // add empty path to end of list for loop
            $pathList = [];
            foreach ($pathLoop as $path) {
                preg_match('{^((\w*)[^/]*)}', $path, $m);   // get path element ($1) and ns ($2)
                if ($m[1] === $lastProp) {
                    $pathTmp[] = $path;     // accumulate all paths with same root
                } else {
                    // put in list to write last if recommended or values are too large
                    if (!empty(self::EXTENDED_RES[$lastProp]) or !empty(self::EXTENDED_RES[$lastNS]) or
                        $propSize > self::NEW_DESC_THRESH)
                    {
                        array_push($writeLast, ...$pathTmp);
                    } else {
                        array_push($pathList, ...$pathTmp);
                    }
                    if (!$path) break;      // all done if we hit empty path
                    $pathTmp = [$path];
                    [$lastProp, $lastNS, $propSize] = [$m[1], $m[2], 0];
                }
                $propSize += strlen(Perl::str($capture[$path][0]));
            }
        }

        // write out all properties
        for (;;) {
            $nsNew = [];
            $newDesc = null;
            if (!$pathList) {
                if (!$writeLast) break;
                $pathList = $writeLast;
                $writeLast = [];
                $newDesc = 2;   // start with a new description for the extended data
            }
            $path = array_shift($pathList);
            $propList = Perl::split('{/}', $path);  // get property list
            // must open/close rdf:Description too
            array_unshift($propList, $rdfDesc);
            // make sure we have defined all necessary namespaces
            foreach ($propList as $prop) {
                if (!preg_match('/(.*):/', $prop, $m)) continue;
                if ($m[1] === 'rdf') continue;  // rdf namespace already defined
                $uri = $nsUsed[$m[1]] ?? null;
                if (!$uri) {
                    $uri = Vars\XMP::$nsURI[$m[1]] ?? null;     // we must have added a namespace
                    if (!$uri) {
                        // (namespace prefix may be empty if trying to write empty XMP structure, forum12384)
                        if (strlen($m[1])) {
                            $err = "Undefined XMP namespace: $m[1]";
                            if (!$xmpErr or $err !== $xmpErr) {
                                $xmpFile ? $et->Error($err) : $et->Warn($err);
                                $xmpErr = $err;
                            }
                        }
                        continue;
                    }
                }
                $nsNew[$m[1]] = $uri;
                // need a new description if any new namespaces
                if (empty($nsCur[$m[1]])) $newDesc = 1;
            }
            $closeTo = 0;
            if ($newDesc) {
                // look forward to see if we will want to also open other namespaces
                // at this level (this is necessary to keep lists and structures from
                // being broken if a property introduces a new namespace; plus it
                // improves formatting)
                foreach ($pathList as $path2) {
                    preg_match_all('{(?:^|/)([^/]+?):}', $path2, $mm);
                    $opening = !empty($compact['OneDesc']) ? 1 : 0;
                    foreach ($mm[1] as $ns2) {
                        if ($ns2 === 'rdf') continue;
                        if (!empty($nsNew[$ns2])) {
                            ++$opening;
                            continue;
                        }
                        if (!$opening) break;
                        // get URI for this existing or new namespace
                        $uri = (($nsUsed[$ns2] ?? null) ?: (Vars\XMP::$nsURI[$ns2] ?? null));
                        if (!$uri) break;
                        $nsNew[$ns2] = $uri;    // also open this namespace
                    }
                    if (!$opening) break;
                }
            } else {
                // find first property where the current path differs from the new path
                for ($closeTo = 0; $closeTo < count($curPropList); ++$closeTo) {
                    if (!($closeTo < count($propList))) break;
                    if ($propList[$closeTo] !== $curPropList[$closeTo]) break;
                }
            }
            // close out properties down to the common base path
            while (count($curPropList) > $closeTo) self::CloseProperty($curPropList, $long, $short, $resFlag);

            // open new description if necessary
            if ($newDesc) {
                if ($newDesc == 2) $extStart = strlen($long[count($long) - 2]);  // extended data starts after this
                // save rdf:Description start positions so we can reorder them if necessary
                if ($maxDataLen) $descStart[] = strlen($long[count($long) - 2]);
                // open the new description
                $prop = $rdfDesc;
                $nsCur = $nsNew;            // save current namespaces
                $ns = array_map('strval', array_keys($nsCur));
                sort($ns, SORT_STRING);
                $long[count($long) - 2] .= "$nl$sp<$prop rdf:about='{$about}'";
                // generate et:toolkit attribute if this is an exiftool RDF/XML output file
                if ($et->XMP_NO_XMPMETA and $ns and preg_match('{^http://ns.exiftool.(?:ca|org)/}', $nsCur[$ns[0]])) {
                    $long[count($long) - 2] .= "\n" . $sp . $sp . "xmlns:et='http://ns.exiftool.org/1.0/'" .
                                " et:toolkit='Image::ExifTool " . ExifTool::$VERSION . "'";
                }
                foreach ($ns as $k) $long[count($long) - 2] .= "\n" . $sp . $sp . "xmlns:$k='{$nsCur[$k]}'";
                $curPropList[] = $prop;
                // set resFlag to 0 to indicate base description when Shorthand enabled
                if (!empty($compact['Shorthand'])) $resFlag[0] = 0;
            }
            [$val, $attrs] = $capture[$path];
            $val = Perl::str($val);
            if (self::$debug) echo "$path = $val\n";
            // open new properties if necessary
            $attr = $dummy = null;
            for ($n = count($curPropList); $n < count($propList) - 1; ++$n) {
                $prop = $propList[$n];
                $curPropList[] = $prop;
                $prop = preg_replace('/ .*/', '', $prop, 1);    // remove list index if it exists
                // (we may add parseType and shorthand properties later,
                //  so leave off the trailing ">" for now)
                $long[count($long) - 1] .= (!empty($compact['NoIndent']) ? '' : str_repeat(' ', count($curPropList))) . "<$prop";
                if ($prop !== $rdfDesc and (!preg_match('/^rdf:/', $propList[$n + 1]) or
                    ($propList[$n + 1] === 'rdf:type' and $n + 1 == count($propList) - 1)))
                {
                    // check for empty structure
                    if (preg_match('/:~dummy~$/', $propList[$n + 1])) {
                        $long[count($long) - 1] .= " rdf:parseType='Resource'/>$nl";
                        array_pop($curPropList);
                        $dummy = 1;
                        break;
                    }
                    if (!empty($compact['Shorthand'])) {
                        $resFlag[count($curPropList) - 1] = 1;
                        $long[] = '';
                        $short[] = '';
                    } else {
                        // use rdf:parseType='Resource' to avoid new 'rdf:Description'
                        $long[count($long) - 1] .= " rdf:parseType='Resource'>$nl";
                    }
                } else {
                    $long[count($long) - 1] .= ">$nl";  // (will be no shorthand properties)
                }
            }
            $prop2 = array_pop($propList);  // get new property name
            // add element unless it was a dummy structure field
            if (!($dummy or ($val === '' and preg_match('/:~dummy~$/', $prop2)))) {
                $prop2 = preg_replace('/ .*/', '', $prop2, 1);  // remove list index if it exists
                $pad = !empty($compact['NoIndent']) ? '' : str_repeat(' ', count($curPropList) + 1);
                // (can't write as shortcut if it has attributes or CDATA)
                if (isset($resFlag[count($curPropList) - 1]) and !$attrs and !preg_match('/<!\[CDATA\[/', $val)) {
                    $short[count($short) - 1] .= "\n$pad$prop2='{$val}'";
                } else {
                    $long[count($long) - 1] .= "$pad<$prop2";
                    // write out attributes
                    $keys = array_map('strval', array_keys($attrs));
                    sort($keys, SORT_STRING);
                    foreach ($keys as $attr) {
                        $attrVal = Perl::str($attrs[$attr]);
                        $quot = str_contains($attrVal, "'") ? '"' : "'";
                        $long[count($long) - 1] .= " $attr=$quot$attrVal$quot";
                    }
                    $long[count($long) - 1] .= strlen($val) ? ">$val</$prop2>$nl" : "/>$nl";
                }
            }
        }
        // close out all open properties
        while ($curPropList) self::CloseProperty($curPropList, $long, $short, $resFlag);

        // limit XMP length and re-arrange if necessary to fit inside specified size
        if ($maxDataLen) {
            // adjust maxDataLen to allow room for closing elements
            $maxDataLen -= strlen(self::RDF_CLOSE) + strlen(self::XMP_CLOSE) + strlen(self::PKT_CLOSE_W);
            if (!$extStart) $extStart = strlen($long[count($long) - 2]);
            $rtn = self::LimitXMPSize($et, $long[count($long) - 2], $maxDataLen, $about, $descStart, $extStart);
            // return extended XMP information in $dirInfo
            $dirInfo['ExtendedXMP'] = $rtn[0];
            $dirInfo['ExtendedGUID'] = $rtn[1];
            // compact if necessary to fit
            if (strlen($long[count($long) - 2]) + 101 * self::NUM_PAD_LINES > $maxDataLen) $compact['NoPadding'] = 1;
        }
        if (!empty($dirInfo['Compact'])) $compact['NoPadding'] = 1;
        //
        // close out the XMP, clean up, and return our data
        //
        $out = &$long[count($long) - 2];
        $out .= self::RDF_CLOSE;
        if (!$et->XMP_NO_XMPMETA) $out .= self::XMP_CLOSE;

        // remove the ExifTool members we created
        unset($et->XMP_CAPTURE, $et->XMP_NS, $et->XMP_NO_XMPMETA);

        // (the XMP standard recommends writing 2k-4k of white space before the
        // packet trailer, with a newline every 100 characters)
        if (!$et->XMP_NO_XPACKET) {
            $pad = str_repeat(' ', 100) . "\n";
            // get current XMP length without padding
            $len = strlen($out) + strlen(self::PKT_CLOSE_W);
            if (!empty($dirInfo['InPlace']) and !($dirInfo['InPlace'] == 2 and $len > $dirLen)) {
                // pad to specified DirLen
                if ($len > $dirLen) {
                    $str = 'Not enough room to edit XMP in place';
                    if (empty($compact['Shorthand'])) $str .= '. Try Shorthand feature';
                    $et->Warn($str);
                    return null;
                }
                $num = Perl::int(($dirLen - $len) / strlen($pad));
                if ($num) {
                    $out .= str_repeat($pad, $num);
                    $len += strlen($pad) * $num;
                }
                if ($len < $dirLen) $out .= str_repeat(' ', $dirLen - $len - 1) . "\n";
            } elseif (empty($compact['NoPadding']) and !$xmpFile and empty($dirInfo['ReadOnly'])) {
                $out .= str_repeat($pad, self::NUM_PAD_LINES);
            }
            $out .= (!empty($dirInfo['ReadOnly']) ? self::PKT_CLOSE_R : self::PKT_CLOSE_W);
        }
        // return empty data if no properties exist and this is allowed
        if (!($capture or $xmpFile or !empty($dirInfo['InPlace']) or !empty($dirInfo['NoDelete']))) {
            $out = '';
        }
        if ($xmpErr) return $xmpFile ? -1 : null;
        $et->CHANGED += $changed;
        if (self::$debug > 1 and $out) echo $out, "\n";
        if (!$xmpFile) return $out;
        if (!ExifTool::Write($dirInfo['OutFile'], $out)) return -1;
        return 1;
    }

    /** Perl quotemeta: backslash before every character but [A-Za-z0-9_] */
    private static function QuoteMeta($str): string
    {
        return preg_replace('/([^A-Za-z0-9_])/', '\\\\$1', Perl::str($str));
    }
}
