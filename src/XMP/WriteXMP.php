<?php

//------------------------------------------------------------------------------
// File:         WriteXMP.pl
//
// Description:  Write XMP meta information
//
// Revisions:    12/19/2004 - P. Harvey Created
//
// PHP port: only the subs of WriteXMP.pl that are used when reading (validation, blank
// nodes, SphericalVideoXML and EncodeBase64). Writing is not ported.
//------------------------------------------------------------------------------

namespace Image\ExifTool\XMP;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagTable;

trait WriteXMP
{
    // namespaces to validate more completely when Validate option is used
    private static array $validateNS = ['tiff' => 1, 'exif' => 1, 'exifEX' => 1];

    private static string $rdfDesc = 'rdf:Description';

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
                    self::FoundXMP($et, $tagTablePtr, $propList, $val);
                    unset($unused[$post]);
                }
            }
            // save information from unused properties (if RDF is malformed like f-spot output)
            if ($unused) {
                if ($et->Options('Verbose')) $et->Warn('An XMP resource is about nothing');
                foreach (array_keys($unused) as $post) {
                    [$val, $attrs, $propPath] = $path['Post'][$post];
                    $propList = Perl::split('{/}', $propPath);
                    self::FoundXMP($et, $tagTablePtr, $propList, $val);
                }
            }
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
}
