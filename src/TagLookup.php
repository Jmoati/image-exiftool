<?php

//------------------------------------------------------------------------------
// File:         TagLookup.pm
//
// Description:  Fast lookup for ExifTool tags
//
// Notes:        This lookup is used to look up tag names for writing
//
// Revisions:    2004-12-31 - P. Harvey Created
//               2013-01-06 - PH Allow wildcards in FindTagInfo()
//
// PHP port. The lookup tables (@tableList, %tagLookup, %tagExists, %compositeModules,
// %specialStruct, %tableNumHash) are generated into Image\ExifTool\Vars\TagLookup.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\ExifTool as ExifToolVars;
use Image\ExifTool\Vars\TagLookup as Vars;

class TagLookup
{
    public static $VERSION = '1.20';

    /** set once the code run by Perl when the module is loaded has been run (see Load) */
    private static bool $loaded = false;

    // insert any user-defined tags into our tag lookup
    // (PHP: done on first use, as Perl does when the module is loaded)
    private static function Load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        if (ExifTool::$UserDefined) {
            $userTables = array_map('strval', array_keys(ExifTool::$UserDefined));
            sort($userTables, SORT_STRING);
            while ($userTables) {
                $table = array_shift($userTables);
                $userDef = ExifTool::$UserDefined[$table];
                if (is_array($userDef)) $userDef = ExifTool::$UserDefined[$table] = new TagTable($userDef);
                self::AddTags($userDef, $table);
            }
        }
        // insert any other queued tags from plug-in modules
        if (ExifToolVars::$pluginTags) {
            foreach (ExifToolVars::$pluginTags as $args) self::AddTags($args[0], $args[1]);
            ExifToolVars::$pluginTags = [];
        }
    }

    //------------------------------------------------------------------------------
    // Add tag names to lookup corresponding to flattened XMP tags for all structure fields
    // Inputs: 0) tag table ref for flattened tags, 1) tagID, 2) lowercase tag name,
    //         3) tag ID list ref, 4) reference to list of lowercase tag names
    //         5) table number in lookup, 6) tagInfo hash for parent structure
    //         7) tag ID of top-level structure
    // Notes: Used only for user-defined structures
    // (PHP: the tagInfo of a structure field is an array; the reference to the tag ID of the
    //  top-level structure pushed on the tag ID list is a ScalarRef)
    public static function AddFields(TagTable $tagTablePtr, $tagID, $lcTag, array &$tagIDs, array &$lcTags, $tnum, $tagInfo = null, $baseID = null): void
    {
        if (strlen(Perl::str($tagID)) > 500) return;    // avoid deep recursion
        if (!$tagInfo) {
            $tagInfo = $tagTablePtr[$tagID];
            $baseID = $tagID;
        }
        $strTable = $tagInfo instanceof TagInfo ? $tagInfo->Struct : ($tagInfo['Struct'] ?? null);
        if (!is_array($strTable)) {
            // (PHP: %Image::ExifTool::UserDefined::xmpStruct comes from config files, not supported)
            ExifTool::PerlWarn("No definition for structure '" . Perl::str($strTable) . "'\n");
            return;
        }
        // inherit NAMESPACE from parent table if it doesn't exist
        if (!array_key_exists('NAMESPACE', $strTable)) {
            $strTable['NAMESPACE'] = $tagTablePtr['NAMESPACE'];
            if ($tagInfo instanceof TagInfo) $tagInfo->Struct = $strTable;
        }
        foreach ($strTable as $field => $fieldInfo) {
            $field = (string) $field;
            if (isset(Vars::$specialStruct[$field])) continue;
            $id = $tagID . ucfirst($field);
            // use name of existing flattened tag if already defined
            $flatInfo = $tagTablePtr[$id];
            $flatName = null;
            if (is_array($fieldInfo) and !array_is_list($fieldInfo)) $flatName = $fieldInfo['FlatName'] ?? null;
            if (!$flatName) $flatName = preg_replace('/[^-_a-zA-Z0-9]/', '', $field);  // remove illegal characters
            $lc = ($flatInfo instanceof TagInfo and $flatInfo->Name) ? strtolower($flatInfo->Name) : $lcTag . strtolower($flatName);
            array_push($tagIDs, new ScalarRef($baseID), $id);
            array_push($lcTags, $lc, $lc);
            if (!(is_array($fieldInfo) and !array_is_list($fieldInfo) and !empty($fieldInfo['Struct']))) continue;
            // recursively add flattened tags for all sub-fields
            self::AddFields($tagTablePtr, $id, $lc, $tagIDs, $lcTags, $tnum, $fieldInfo, $baseID);
        }
    }

    //------------------------------------------------------------------------------
    // Add tags to the lookup (for user-defined tags, etc)
    // Inputs: 0) tag table ref, 1) table name
    public static function AddTags(TagTable $tagTablePtr, $table): void
    {
        self::Load();
        // generate lookup for table numbers if not done already
        if (!Vars::$tableNumHash) {
            $tnum = 0;
            foreach (Vars::$tableList as $tbl) {
                Vars::$tableNumHash[$tbl] = $tnum++;
            }
        }
        $moreTables = $moreTableRefs = [];
        for (;;) {
            $tnum = Vars::$tableNumHash[$table] ?? null;
            if ($tnum === null) {
                // add new table to list
                $tnum = count(Vars::$tableList);
                Vars::$tableList[] = $table;
            }
            foreach (ExifTool::TagTableKeys($tagTablePtr) as $tagID) {
                $newInfo = $tagTablePtr[$tagID];
                if ($newInfo instanceof TagInfo) {
                    if (!$newInfo->Name) $newInfo->Name = ExifTool::MakeTagName($tagID);
                    // use new ID of tag in Composite table in case it changed
                    if ($newInfo->NewTagID !== null) $tagID = $newInfo->NewTagID;
                } else {
                    $newInfo = $tagTablePtr[$tagID] = new TagInfo(['Name' => $newInfo]);
                }
                $lcTag = strtolower(Perl::str($newInfo->Name));
                $lcTags = $tagIDs = [];
                $existed = $isFlat = null;
                // if this is a structure, add all flattened tag names to lookup
                if ($newInfo->Struct) {
                    $lcFlat = $newInfo->FlatName ? strtolower($newInfo->FlatName) : $lcTag;
                    self::AddFields($tagTablePtr, $tagID, $lcFlat, $tagIDs, $lcTags, $tnum);
                }
                // add tags to lookup even though we don't know if they are writable
                // (to save speed by not having to load the module)
                for (;;) {  // Tags:
                    Vars::$tagLookup[$lcTag] ??= [];
                    $lookup = &Vars::$tagLookup[$lcTag];
                    if (!empty($lookup[$tnum])) {
                        $le = $lookup[$tnum];
                        $ids = is_array($le) ? $le : [$le];
                        if ($tagID instanceof ScalarRef) {
                            // a reference points to the tag ID of the base structure for
                            // a flattened tag.  There must be only one of these,
                            // and it must come first.
                            if (!($ids[0] instanceof ScalarRef)) array_unshift($ids, $tagID);
                        // only add new tag ID if it didn't already exist
                        } elseif (@preg_grep(ExifTool::Rx('^' . Perl::str($tagID) . '$'), array_filter($ids, 'is_scalar'))) {
                            if (!$isFlat) $existed = 1;
                        } else {
                            $ids[] = $tagID;
                        }
                        // only update lookup if there is now more than one entry
                        // (PHP: or if it was already a list, which Perl updates in place)
                        if (count($ids) > 1 or is_array($le)) $lookup[$tnum] = $ids;
                    } else {
                        $lookup[$tnum] = $tagID;
                    }
                    unset($lookup);
                    if (!$tagIDs) break;
                    for (;;) {
                        $tagID = array_shift($tagIDs);
                        $lcTag = array_shift($lcTags);
                        // don't waste time if it exists
                        if ($tagID instanceof ScalarRef or !isset($tagTablePtr[$tagID])) break;
                        if (!$tagIDs) break 2;
                    }
                    $isFlat = 1;
                }
                // add any new subdirectory tables (unless done already)
                if ($existed or !$newInfo->SubDirectory) continue;
                $subTable = $newInfo->SubDirectory['TagTable'] ?? null;
                if (!($subTable and !isset(Vars::$tableNumHash[$subTable]))) continue;
                if (isset($moreTableRefs[$subTable])) continue;
                $subTablePtr = ExifTool::PackageTable($subTable);
                if (!$subTablePtr or !count($subTablePtr)) continue;
                // save new table to process after this one
                $moreTables[] = $subTable;
                $moreTableRefs[$subTable] = $subTablePtr;
                // save source table name so we can load it when necessary
                $subTablePtr['SRC_TABLE'] = $table;
            }
            $table = array_shift($moreTables);
            if (!$table) break;
            $tagTablePtr = $moreTableRefs[$table];
        }
    }

    //------------------------------------------------------------------------------
    // Return true if the tag exists
    // Inputs: 0) tag name (case insensitive)
    // Returns: true if tag exists
    public static function TagExists($tag)
    {
        self::Load();
        $tag = strtolower(Perl::str($tag));
        if (!empty(Vars::$tagExists[$tag]) or !empty(Vars::$tagLookup[$tag])) return 1;
        return 0;
    }

    //------------------------------------------------------------------------------
    // Find information for writable tags (case insensitive)
    // Inputs: 0) tag name (case insensitive)
    // Returns: Scalar context: tag info or false if none found
    //          List context: list of all matching tagInfo hashes
    //          (PHP: the list, which is what all callers use)
    public static function FindTagInfo($tag): array
    {
        self::Load();
        $lcTag = strtolower(Perl::str($tag));
        $tagInfoList = $lookups = [];
        // (PHP: lookups are referred to by their key so they can be updated in place)
        $lookupKey = isset(Vars::$tagLookup[$lcTag]) ? $lcTag : null;
        if ($lookupKey === null and preg_match('/[?*]/', $lcTag)) {
            // allow wildcards in tag name
            $pat = str_replace(['*', '?'], ['[-\w]*', '[-\w]'], $lcTag);
            $tags = @preg_grep(ExifTool::Rx("^$pat\$"), array_map('strval', array_keys(Vars::$tagLookup))) ?: [];
            if ($tags) {
                sort($tags, SORT_STRING);
                $lookups = $tags;
                $lookupKey = array_shift($lookups);
            }
        }
        while ($lookupKey !== null) {
            $tableNums = array_keys(Vars::$tagLookup[$lookupKey]);
            sort($tableNums);
            foreach ($tableNums as $tableNum) {
                $table = ExifTool::GetTagTable(Vars::$tableList[$tableNum]);
                if (!$table) continue;
                $le = Vars::$tagLookup[$lookupKey][$tableNum];
                if (is_array($le)) {
                    $tagIDs = $le;
                    // if first entry is a reference, it indicates that this is a
                    // flattened tag and refers to the tag ID of the containing top-level
                    // structure, so now is the time to generate the flattened tags
                    if (self::IsRootID($table, $tagIDs)) {
                        // (remove from the lookup since we only need to do this once)
                        $rootIDPtr = array_shift($tagIDs);
                        Vars::$tagLookup[$lookupKey][$tableNum] = $tagIDs;
                        ExifTool::RequireModule('Image::ExifTool::XMP');
                        XMP::AddFlattenedTags($table, $rootIDPtr instanceof ScalarRef ? $rootIDPtr->value : $rootIDPtr);
                    }
                } else {
                    $tagIDs = [$le];
                }
                foreach ($tagIDs as $tagID) {
                    $infoList = ExifTool::GetTagInfoList($table, $tagID);
                    if (!$infoList) {
                        $reload = null;
                        // call write proc if it exists in case it adds tags to the table
                        $writeProc = $table['WRITE_PROC'];
                        if ($writeProc) {
                            if (ExifTool::DummyWriteCall($writeProc)) $reload = 1;
                        }
                        // load module with composite tag if necessary
                        $compMod = Vars::$compositeModules[$lcTag] ?? null;
                        if ($compMod and ExifTool::RequireModule($compMod)) $reload = 1;
                        if ($reload) $infoList = ExifTool::GetTagInfoList($table, $tagID);
                    }
                    if (count($infoList) == 1) {
                        array_push($tagInfoList, ...$infoList);
                    } elseif (count($infoList) > 1) {
                        // must check tag list in case tags have different names
                        foreach ($infoList as $tagInfo) {
                            if ($lcTag !== strtolower($tagInfo->Name)) continue;
                            $tagInfoList[] = $tagInfo;
                        }
                    }
                }
            }
            $lookupKey = array_shift($lookups);
        }
        return $tagInfoList;
    }

    /**
     * Perl: ref $$tagIDs[0] (the first entry is a reference to the tag ID of the top-level
     * structure of a flattened tag).  PHP: the generated lookup holds the ID itself, which is
     * recognized as the ID of a structure tag of the table that starts the next tag ID (this
     * gives exactly the Perl references for the generated lookup); entries added at run time
     * by AddTags hold a ScalarRef.
     */
    private static function IsRootID(?TagTable $table, array $tagIDs): bool
    {
        $first = $tagIDs[0] ?? null;
        if ($first instanceof ScalarRef) return true;
        if (!$table or count($tagIDs) < 2 or !is_string($first) or !is_string($tagIDs[1])) return false;
        $tagInfo = $table[$first];
        return $tagInfo instanceof TagInfo and $tagInfo->Struct and str_starts_with($tagIDs[1], $first);
    }
}
