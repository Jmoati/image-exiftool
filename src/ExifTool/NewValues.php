<?php

//------------------------------------------------------------------------------
// File:         Writer.pl
//
// Description:  ExifTool write routines
//
// Notes:        Also contains some less used ExifTool functions
//
// URL:          https://exiftool.org/
//
// Revisions:    12/16/2004 - P. Harvey Created
//
// PHP port, part 1 of Writer.pl (package Image::ExifTool): the new values (SetNewValue,
// GetNewValue..., SetNewValuesFromFile, ConvInv...), mixed into Image\ExifTool\ExifTool.
// Part 2 (writing files) is src/ExifTool/Writer.php; the subs used for reading are in
// src/ExifTool.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool\ExifTool;

use Image\ExifTool\ConvList;
use Image\ExifTool\ExifTool;
use Image\ExifTool\HTML;
use Image\ExifTool\NewValue;
use Image\ExifTool\NotPorted;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagLookup;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars\ExifTool as Vars;
use Image\ExifTool\Vars\Shortcuts;
use Image\ExifTool\XMP;

/**
 * PHP notes:
 * - $$self{NEW_VALUE}{$tagInfo} is $this->NEW_VALUE[spl_object_id($tagInfo)] (same for
 *   SAVE_NEW_VALUE); a new value hash is a NewValue object.
 * - wantarray-dependent subs: SetNewValue, GetNewValue, CountNewValues, ReverseLookup and
 *   EncodeBits give the scalar-context value; their list-context twins are SetNewValueList
 *   ([numSet, error]), GetNewValueList (list of values), CountNewValuesList ([num, pseudo]),
 *   ReverseLookupList ([value, multi]) and EncodeBitsList ([value, error]).
 * - options of SetNewValue are named arguments (`$et->SetNewValue('XMP:Title', 'x', Replace: 1)`);
 *   the internal CreateGroups and SetTags options are \ArrayObject's (shared and filled in place,
 *   like the Perl hash refs), so $nvHash->CreateGroups is an \ArrayObject too.
 * - the dummy calls Perl makes to write procs to autoload the write package are DummyWriteCall().
 * - Perl's $_ of the advanced formatting helpers (DateFmt, NoDups, SetTags) is their first
 *   argument, updated in place.
 */
trait NewValues
{
    // (the file-scope data of Writer.pl also used for writing files, %jpegMap,
    //  %translateWriteGroup..., is declared in src/ExifTool/Writer.php)

    // groups we are allowed to delete
    // Notes:
    // 1) these names must either exist in %dirMap, or be translated in InitWriteDirs())
    // 2) any dependencies must be added to %excludeGroups
    /** my @delGroups */
    private const array DEL_GROUPS = [
        'Adobe', 'AFCP', 'APP0', 'APP1', 'APP2', 'APP3', 'APP4', 'APP5', 'APP6', 'APP7', 'APP8', 'APP9', 'APP10', 'APP11', 'APP12',
        'APP13', 'APP14', 'APP15', 'AROT', 'AudioKeys', 'CanonVRD', 'CIFF', 'Ducky', 'EXIF', 'ExifIFD', 'File', 'FlashPix',
        'FotoStation', 'GlobParamIFD', 'GPS', 'ICC_Profile', 'IFD0', 'IFD1', 'Insta360', 'InteropIFD', 'IPTC',
        'ItemList', 'iTunes', 'JFIF', 'Jpeg2000', 'JUMBF', 'Keys', 'MakerNotes', 'Meta', 'MetaIFD', 'Microsoft', 'MIE',
        'MPF', 'Nextbase', 'NikonApp', 'NikonCapture', 'PDF', 'PDF-update', 'PhotoMechanic', 'Photoshop', 'PNG',
        'PNG-pHYs', 'PrintIM', 'QuickTime', 'RMETA', 'RSRC', 'SEAL', 'SubIFD', 'Trailer', 'UserData', 'VideoKeys',
        'Vivo', 'XML', 'XML-*', 'XMP', 'XMP-*',
    ];
    // family 2 group names that we can delete
    /** my @delGroup2 */
    private const array DEL_GROUP2 = [
        'Audio', 'Author', 'Camera', 'Document', 'ExifTool', 'Image', 'Location', 'Other', 'Preview', 'Printing',
        'Time', 'Video',
    ];
    // Extra groups to delete when deleting another group
    /** my %delMore */
    private const array DEL_MORE = [
        'QuickTime' => ['ItemList', 'UserData', 'Keys'],
        'XMP' => ['XMP-*'],
        'XML' => ['XML-*'],
        'SEAL' => ['XMP-SEAL'],
    ];

    // lookup for all valid family 2 groups (lower case)
    /** my %family2groups */
    private const array FAMILY2GROUPS = [
        'audio' => 1, 'author' => 1, 'camera' => 1, 'document' => 1, 'exiftool' => 1, 'image' => 1,
        'location' => 1, 'other' => 1, 'preview' => 1, 'printing' => 1, 'time' => 1, 'video' => 1, 'unknown' => 1,
    ];

    // groups we don't delete when deleting all information
    /** my $protectedGroups */
    private const string PROTECTED_GROUPS = '(IFD1|SubIFD|InteropIFD|GlobParamIFD|PDF-update|Adobe)';

    // other group names of new tag values to remove when deleting an entire group
    /** my %removeGroups */
    private const array REMOVE_GROUPS = [
        'IFD0'    => ['EXIF', 'MakerNotes'],
        'EXIF'    => ['MakerNotes'],
        'ExifIFD' => ['MakerNotes', 'InteropIFD'],
        'Trailer' => ['CanonVRD'], // (because we can add back CanonVRD as a block)
    ];
    // related family 0/1 groups in @delGroups (and not already in %jpegMap)
    // that must be removed from delete list when excluding a group
    /** my %excludeGroups */
    private const array EXCLUDE_GROUPS = [
        'EXIF'         => ['IFD0', 'IFD1', 'ExifIFD', 'GPS', 'MakerNotes', 'GlobParamIFD', 'InteropIFD', 'PrintIM', 'SubIFD'],
        'IFD0'         => ['EXIF'],
        'IFD1'         => ['EXIF'],
        'ExifIFD'      => ['EXIF'],
        'GPS'          => ['EXIF'],
        'MakerNotes'   => ['EXIF'],
        'InteropIFD'   => ['EXIF'],
        'GlobParamIFD' => ['EXIF'],
        'PrintIM'      => ['EXIF'],
        'CIFF'         => ['MakerNotes'],
        // technically correct, but very uncommon and not a good reason to avoid deleting trailer
        // 'IPTC'      => ['AFCP', 'FotoStation', 'Trailer'],
        'AFCP'         => ['Trailer'],
        'FotoStation'  => ['Trailer'],
        'CanonVRD'     => ['Trailer'],
        'PhotoMechanic'=> ['Trailer'],
        'MIE'          => ['Trailer'],
        'QuickTime'    => ['ItemList', 'UserData', 'Keys'],
    ];
    // translate (lower case) wanted group when writing for tags where group name may change
    /** my %translateWantGroup */
    private const array TRANSLATE_WANT_GROUP = [
        'ciff'  => 'canonraw',
    ];
    // names of valid EXIF and Meta directories (lower case keys):
    /** my %exifDirs */
    private const array EXIF_DIRS = [
        'gps'          => 'GPS',
        'exififd'      => 'ExifIFD',
        'subifd'       => 'SubIFD',
        'globparamifd' => 'GlobParamIFD',
        'interopifd'   => 'InteropIFD',
        'previewifd'   => 'PreviewIFD', // (in MakerNotes)
        'metaifd'      => 'MetaIFD', // Kodak APP3 Meta
        'makernotes'   => 'MakerNotes',
    ];
    // valid family 0 groups when WriteGroup is set to "All"
    /** my %allFam0 */
    private const array ALL_FAM0 = [
        'exif'         => 1,
        'makernotes'   => 1,
    ];

    // min/max values for integer formats
    /** my %intRange */
    private const array INT_RANGE = [
        'int8u'  => [0, 0xff],
        'int8s'  => [-0x80, 0x7f],
        'int16u' => [0, 0xffff],
        'int16uRev' => [0, 0xffff],
        'int16s' => [-0x8000, 0x7fff],
        'int32u' => [0, 0xffffffff],
        'int32s' => [-0x80000000, 0x7fffffff],
        'int64u' => [0, 18446744073709551615],
        'int64s' => [PHP_INT_MIN, PHP_INT_MAX],
    ];

    // value separators when conversion list is used (in SetNewValue)
    /** my %listSep */
    private const array LIST_SEP = ['PrintConv' => '; ?', 'ValueConv' => ' '];

    // printConv hash keys to ignore when doing reverse lookup
    /** my %ignorePrintConv */
    private const array IGNORE_PRINT_CONV = ['OTHER' => 1, 'BITMASK' => 1, 'Notes' => 1];

    /** $advFmtSelf: ExifTool object for advanced formatting expressions (package variable) */
    public static $advFmtSelf = null;

    /** my $strptimeLib: strptime library name if available (PHP: Time::Piece emulation) */
    private static $strptimeLib = 'Time::Piece';

    //------------------------------------------------------------------------------
    // Set tag value
    // Inputs: 0) ExifTool object reference
    //         1) tag key, tag name, or '*' (optionally prefixed by group name),
    //            or undef to reset all previous SetNewValue() calls
    //         2) new value (scalar, scalar ref or list ref), or undef to delete tag
    //         3-N) Options:
    //           Type => PrintConv, ValueConv or Raw - specifies value type
    //           AddValue => true to add to list of existing values instead of overwriting
    //           DelValue => true to delete this existing value value from a list, or
    //                       or doing a conditional delete, or to shift a time value
    //           Group => family 0 or 1 group name (case insensitive)
    //           Replace => 0, 1 or 2 - overwrite previous new values (2=reset)
    //           Protected => bitmask to write tags with specified protections
    //           EditOnly => true to only edit existing tags (don't create new tag)
    //           EditGroup => true to only edit existing groups (don't create new group)
    //           Shift => undef, 0, +1 or -1 - shift value if possible
    //           NoFlat => treat flattened tags as 'unsafe'
    //           NoShortcut => true to prevent looking up shortcut tags
    //           ProtectSaved => protect existing new values with a save count greater than this
    //           IgnorePermanent => ignore attempts to delete a permanent tag
    //           CreateGroups => [internal use] createGroups hash ref from related tags
    //           ListOnly => [internal use] set only list or non-list tags
    //           SetTags => [internal use] hash ref to return tagInfo refs of set tags
    //           Sanitized => [internal use] set to avoid double-sanitizing the value
    //           Fixup => [internal use] fixup information when writing maker notes
    // Returns: number of tags set (plus error string in list context)
    // Notes: For tag lists (like Keywords), call repeatedly with the same tag name for
    //        each value in the list.  Internally, the new information is stored in
    //        the following members of the $$self{NEW_VALUE}{$tagInfo} hash:
    //           TagInfo - tag info ref
    //           DelValue - list ref for raw values to delete
    //           Value - list ref for raw values to add (not defined if deleting the tag)
    //           IsCreating - must be set for the tag to be added for the standard file types,
    //                        otherwise just changed if it already exists.  This may be
    //                        overridden for file types with a PREFERRED metadata type.
    //                        Set to 2 to create individual tags but not new groups
    //           EditOnly - flag set if tag should never be created (regardless of file type).
    //                      If this is set, then IsCreating must be false
    //           CreateOnly - flag set if creating only (never edit existing tag)
    //           CreateGroups - hash of all family 0 group names where tag may be created
    //           WriteGroup - group name where information is being written (correct case)
    //           WantGroup - group name as specified in call to function (case insensitive)
    //           Next - pointer to next new value hash (if more than one for this tag)
    //           NoReplace - set if value was created with Replace=0
    //           AddBefore - number of list items added by a subsequent Replace=0 call
    //           IsNVH - flag indicating this is a new value hash
    //           Order - counter to indicate the order that new value hashes were created
    //           Shift - shift value
    //           Save - counter used by SaveNewValues()/RestoreNewValues()
    //           MAKER_NOTE_FIXUP - pointer to fixup if necessary for a maker note value
    // (PHP: scalar context, the error is issued as a warning; list context is SetNewValueList)
    public function SetNewValue($tag = null, $value = null, mixed ...$options)
    {
        $prec = ini_set('precision', '15');
        try {
            return $this->DoSetNewValue(false, $tag, $value, $options)[0];
        } finally {
            ini_set('precision', $prec);
        }
    }

    /** SetNewValue in list context: returns [number of tags set, error string or null] */
    public function SetNewValueList($tag = null, $value = null, mixed ...$options): array
    {
        $prec = ini_set('precision', '15');
        try {
            return $this->DoSetNewValue(true, $tag, $value, $options);
        } finally {
            ini_set('precision', $prec);
        }
    }

    private function DoSetNewValue(bool $wantarray, $tag, $value, array $options): array
    {
        $err = $tagInfo = null;
        $verbose = $this->OPTIONS['Verbose'];
        $out = $this->OPTIONS['TextOut'] ?? STDOUT;
        $protected = ($options['Protected'] ?? null) ?: 0;
        $listOnly = $options['ListOnly'] ?? null;
        $setTags = $options['SetTags'] ?? null;
        $noFlat = $options['NoFlat'] ?? null;
        $numSet = 0;

        if ($tag === null) {
            $this->NEW_VALUE = null;
            $this->SAVE_COUNT = $this->NV_COUNT = 0;
            $this->DEL_GROUP = [];
            return [1, null];
        }
        // allow value to be scalar or list reference
        if (is_array($value) or is_object($value)) {
            if (is_array($value) and array_is_list($value)) {
                // value is an ARRAY so it may have more than one entry
                // - set values both separately and as a combined string if there are more than one
                if (count($value) > 1) {
                    // set all list-type tags first
                    $replace = $options['Replace'] ?? null;
                    $noJoin = null;
                    foreach ($value as $v) {
                        if (is_array($v) or is_object($v)) $noJoin = 1;
                        [$n, $e] = $this->DoSetNewValue(true, $tag, $v, [...$options, 'ListOnly' => 1]);
                        if ($e) $err = $e;
                        $numSet += $n;
                        unset($options['Replace']); // don't replace earlier values in list
                    }
                    if ($noJoin) return [$numSet, null];  // don't join if list contains objects
                    // and now set only non-list tags
                    $value = self::JoinList($this->OPTIONS['ListSep'], $value);
                    $options['Replace'] = $replace;
                    $listOnly = $options['ListOnly'] = 0;
                } else {
                    $value = $value[0] ?? null;
                    if (self::IsScalarRef($value)) $value = $value->value; // (handle single scalar ref in a list)
                }
            } elseif (self::IsScalarRef($value)) {
                $value = $value->value;
            }
        }
        // un-escape as necessary and make sure the Perl UTF-8 flag is OFF for the value
        // if perl is 5.6 or greater (otherwise our byte manipulations get corrupted!!)
        if ($value !== null and !is_array($value) and !is_object($value) and empty($options['Sanitized'])) {
            $this->Sanitize($value);
        }

        // set group name in options if specified
        $tag = Perl::str($tag);
        if (preg_match('/(.*):(.+)/', $tag, $m)) [$options['Group'], $tag] = [$m[1], $m[2]];

        // allow trailing '#' for ValueConv value
        $tag = preg_replace('/#$/', '', $tag, 1, $count);
        if ($count) $options['Type'] = 'ValueConv';
        $convType = ($options['Type'] ?? null) ?: ($this->OPTIONS['PrintConv'] ? 'PrintConv' : 'ValueConv');

        // filter value if necessary
        if ($convType === 'PrintConv' and !$this->Filter($this->OPTIONS['FilterW'] ?? null, $value)) return [0, null];

        $wantGroupList = [];    // (@wantGroup)
        $family2 = null;
        $wantGroup = $options['Group'] ?? null;
        if ($wantGroup) {
            foreach (Perl::split('/:/', $wantGroup) as $g) {
                // separate family number and group name
                if (!strlen($g) or !preg_match('/^(\d+)?(.*)/', $g, $m, PREG_UNMATCHED_AS_NULL)) continue;
                [$f, $g] = [$m[1], $m[2]];
                $lcg = strtolower($g);
                // save group/family unless '*' or 'all'
                if ($lcg !== '*' and $lcg !== 'all') $wantGroupList[] = [$f, $lcg];
                if (preg_match('/^ID-/i', $g)) {        // family 7 is a tag ID
                    $g = substr($g, 3);
                    if ($f !== null and $f !== '7') return [0, null];
                    $wantGroupList[count($wantGroupList) - 1] = [7, $g]; // group name with 'ID-' removed and case preserved
                } elseif ($f !== null) {
                    if ($f > 2) return [0, null];       // only allow family 0, 1 or 2
                    if ($f == 2) $family2 = 1;          // set flag indicating family 2 was used
                } else {
                    if (isset(self::FAMILY2GROUPS[$lcg])) $family2 = 1;
                }
            }
            if (!$wantGroupList) $wantGroup = null;
        }

        $tag = preg_replace('/ .*/', '', $tag, 1);  // convert from tag key to tag name if necessary
        if (strtolower($tag) === 'all') $tag = '*'; // use '*' instead of 'all'
        //
        // handle group delete
        //
        while ($tag === '*' and $value === null and !$family2 and count($wantGroupList) < 2) {
            // set groups to delete
            $del = [];
            $remove = (($options['Replace'] ?? null) and $options['Replace'] > 1);
            if ($wantGroup) {
                if (!preg_match('/^XM[LP]-\*$/i', $wantGroup)) {
                    $del = array_values(@preg_grep(self::Rx("^$wantGroup\$", 'i'), self::DEL_GROUPS) ?: []);
                }
                // remove associated groups when excluding from mass delete
                if ($del and $remove) {
                    // remove associated groups in other family
                    if (isset(self::EXCLUDE_GROUPS[$del[0]])) array_push($del, ...self::EXCLUDE_GROUPS[$del[0]]);
                    // remove upstream groups according to JPEG map
                    $dirName = $del[0];
                    $dirNames = [];
                    for (;;) {
                        $parent = self::JPEG_MAP[$dirName] ?? null;
                        if (is_array($parent)) {
                            array_push($dirNames, ...$parent);
                            $parent = array_pop($dirNames);
                        }
                        $dirName = $parent ?: array_shift($dirNames);
                        if (!$dirName) break;
                        $del[] = $dirName;  // exclude this too
                    }
                }
                // allow MIE groups to be deleted by number,
                // and allow any XMP family 1 group to be deleted
                if (preg_match('/^(MIE\d+|XM[LP]-[-\w]*\w)$/i', $wantGroup)) $del[] = strtoupper($wantGroup);
            } else {
                // push all groups plus '*', except the protected groups
                foreach (self::DEL_GROUPS as $g) {
                    if (!preg_match('/^' . self::PROTECTED_GROUPS . '$/', $g)) $del[] = $g;
                }
                $del[] = '*';
            }
            if ($del) {
                ++$numSet;
                $donegrps = [];
                $delGroup = &$this->DEL_GROUP;
                foreach ($del as $grp) {
                    if ($remove) {
                        $didExcl = null;
                        if (preg_match('/^(XM[LP])(-.*)?$/', $grp, $m)) {
                            $x = $m[1];
                            if ($grp === $x) {
                                // exclude all related family 1 groups too
                                foreach (array_map('strval', array_keys($delGroup)) as $k) {
                                    if (!preg_match("/^(-?)$x-/", $k, $mm)) continue;
                                    if (!$mm[1]) $donegrps[] = $k;
                                    unset($delGroup[$k]);
                                }
                            } elseif (!empty($delGroup["$x-*"]) and empty($delGroup["-$grp"])) {
                                // must also exclude XMP or XML to prevent bulk delete
                                if (!empty($delGroup[$x])) {
                                    $donegrps[] = $x;
                                    unset($delGroup[$x]);
                                }
                                // flag XMP/XML family 1 group for exclusion with leading '-'
                                $delGroup["-$grp"] = 1;
                                $didExcl = 1;
                            }
                        }
                        if (array_key_exists($grp, $delGroup)) {
                            unset($delGroup[$grp]);
                        } elseif (!$didExcl) {
                            continue;
                        }
                    } else {
                        $delGroup[$grp] = 1;
                        // add extra groups to delete if necessary
                        foreach (self::DEL_MORE[$grp] ?? [] as $g) {
                            $delGroup[$g] = 1;
                            $donegrps[] = $g;
                        }
                        // remove all of this group from previous new values
                        $this->RemoveNewValuesForGroup($grp);
                    }
                    $donegrps[] = $grp;
                }
                unset($delGroup);
                if ($verbose > 1 and $donegrps) {
                    sort($donegrps, SORT_STRING);
                    $msg = $remove ? 'Excluding from deletion' : 'Deleting tags in';
                    fwrite($out, "  $msg: " . implode(' ', $donegrps) . "\n");
                }
            } elseif (@preg_grep(self::Rx("^$wantGroup\$", 'i'), self::DEL_GROUP2)) {
                break;  // allow tags to be deleted by group2 name
            } else {
                $err = "Not a deletable group: $wantGroup";
            }
            // all done
            if (!$wantarray and $err) self::PerlWarn("$err\n");
            return [$numSet, $err];
        }

        // initialize write/create flags
        $createOnly = null;
        $editOnly = $options['EditOnly'] ?? null;
        $editGroup = $options['EditGroup'] ?? null;
        $writeMode = Perl::str($this->OPTIONS['WriteMode']);
        if ($writeMode !== 'wcg') {
            if (!preg_match('/w/i', $writeMode)) $createOnly = 1;   // don't write existing tags
            if (!preg_match('/c/i', $writeMode)) {
                if ($createOnly) return [0, null];  // nothing to do unless writing existing tags
                $editOnly = 1;                      // don't create new tags
            } elseif (!preg_match('/g/i', $writeMode)) {
                $editGroup = 1;                     // don't create new groups
            }
        }
        $ifdName = $mieGroup = $movGroup = null;
        // set family 1 group names
        foreach ($wantGroupList as $fg) {
            if ($fg[0] !== null and $fg[0] != 1) continue;
            $g = $fg[1];    // ($_)
            // set $ifdName if this group is a valid IFD or SubIFD name
            $grpName = null;
            if (preg_match('/^IFD(\d+)$/i', $g, $m)) {
                $grpName = $ifdName = "IFD$m[1]";
            } elseif (preg_match('/^SubIFD(\d+)$/i', $g, $m)) {
                $grpName = $ifdName = "SubIFD$m[1]";
            } elseif (preg_match('/^Version(\d+)$/i', $g, $m)) {
                $grpName = $ifdName = "Version$m[1]"; // Sony IDC VersionIFD
            } elseif (isset(self::EXIF_DIRS[$g])) {
                $grpName = self::EXIF_DIRS[$g];
                if (!($ifdName and isset(self::ALL_FAM0[$g]))) $ifdName = $grpName;
            } elseif (isset(self::ALL_FAM0[$g])) {
                $grpName = self::ALL_FAM0[$g];
            } elseif (preg_match('/^Track(\d+)$/i', $g, $m)) {
                $grpName = $movGroup = "Track$m[1]";  // QuickTime track
            } elseif (preg_match('/^MIE(\d*-?)(\w+)$/i', $g, $m)) {
                $grpName = $mieGroup = "MIE$m[1]" . ucfirst(strtolower($m[2]));
            } elseif (!$ifdName and preg_match('/^XMP\b/i', $g)) {
                // must load XMP table to set group1 names
                $table = self::GetTagTable('Image::ExifTool::XMP::Main');
                $writeProc = $table['WRITE_PROC'];
                if ($writeProc) self::DummyWriteCall($writeProc);
            }
            // fix case for known groups
            if ($grpName and Perl::str($grpName) !== $g) {
                $wantGroup = preg_replace(self::Rx($grpName, 'i'), Perl::str($grpName), $wantGroup, 1);
            }
        }
        //
        // get list of tags we want to set
        //
        $origTag = $tag;
        $matchingTags = TagLookup::FindTagInfo($tag);
        while (!$matchingTags) {
            $langCode = null;
            // allow language suffix of form "-en_CA" or "-<rfc3066>" on tag name
            if (preg_match('/^([?*\w]+)-([a-z]{2})(_[a-z]{2})$/i', $tag, $m) or // MIE
                preg_match('/^([?*\w]+)-([a-z]{2,3}|[xi])(-[a-z\d]{2,8}(-[a-z\d]{1,8})*)?$/i', $tag, $m)) // XMP/PNG/QuickTime
            {
                $tag = $m[1];
                // normalize case of language codes
                $langCode = strtolower($m[2]);
                if (!empty($m[3])) $langCode .= (strlen($m[3]) == 3 ? strtoupper($m[3]) : strtolower($m[3]));
                $newMatches = TagLookup::FindTagInfo($tag);
                foreach ($newMatches as $tagInfo) {
                    // only allow language codes in tables which support them
                    if (!$tagInfo->Table) continue;
                    $langInfoProc = $tagInfo->Table['LANG_INFO'];
                    if (!$langInfoProc) continue;
                    $langInfo = $langInfoProc($tagInfo, $langCode);
                    if ($langInfo) $matchingTags[] = $langInfo;
                }
                if ($matchingTags) break;
            } elseif (empty($options['NoShortcut'])) {
                // look for a shortcut or alias
                $match = null;
                foreach (array_keys(Shortcuts::$Main) as $k) {
                    if (strcasecmp((string) $k, $tag) === 0) {
                        $match = $k;
                        break;
                    }
                }
                $err = null;
                if ($match !== null) {
                    $options['NoShortcut'] = $options['Sanitized'] = 1;
                    foreach (Shortcuts::$Main[$match] as $t) {
                        [$n, $e] = $this->DoSetNewValue(true, $t, $value, $options);
                        $numSet += $n;
                        if ($e) $err = $e;
                    }
                    if ($numSet) $err = null;   // no error if any set successfully
                    if (!$wantarray and $err) self::PerlWarn("$err\n");
                    return [$numSet, $err];
                }
            }
            if (!$listOnly) {
                if (!TagLookup::TagExists($tag)) {
                    if (preg_match('/^[-\w*?]+$/', $tag)) {
                        $pre = $wantGroup ? $wantGroup . ':' : '';
                        $err = "Tag '{$pre}{$origTag}' is not defined";
                        if (str_contains($origTag, '-')) $err .= ' or has a bad language code';
                        if (!$pre and strtoupper($origTag) === 'TAG') {
                            $err .= " (specify a writable tag name, not '{$origTag}' literally)";
                        }
                    } else {
                        $err = "Invalid tag name '{$tag}'";
                        if (str_starts_with($tag, '$')) $err .= " (remove the leading '\$')";
                    }
                } elseif ($langCode) {
                    $err = "Tag '{$tag}' does not support alternate languages";
                } elseif ($wantGroup) {
                    $err = "Sorry, $wantGroup:$origTag doesn't exist or isn't writable";
                } else {
                    $err = "Sorry, $origTag is not writable";
                }
                if ($verbose > 2) fwrite($out, "$err\n");
            }
            // all done
            if (!$wantarray and $err) self::PerlWarn("$err\n");
            return [$numSet, $err];
        }
        // get group name that we're looking for
        $foundMatch = 0;
        //
        // determine the groups for all tags found, and the tag with
        // the highest priority group
        //
        $tagInfoList = $writeAlsoList = $writeGroupHash = $preferred = $tagPriority = [];
        $avoid = $highestPriority = $highestQT = [];
        $wasProtected = $noCreate = null;

        foreach ($matchingTags as $tagInfo) {   // TAG:
            $id = spl_object_id($tagInfo);
            $tag = $tagInfo->Name;      // get tag name for warnings
            $lcTag = strtolower($tag);  // get lower-case tag name for use in variables
            // initialize highest priority if we are starting a new tag
            if (!isset($highestPriority[$lcTag])) $highestPriority[$lcTag] = -999;
            $priority = $writeGroup = null;
            $prfTag = $tagInfo->Preferred !== null ? $tagInfo->Preferred : $tagInfo->Table['PREFERRED'];
            if ($wantGroup) {
                // a WriteGroup of All is special
                $wgAll = ($tagInfo->WriteGroup and $tagInfo->WriteGroup === 'All');
                $grp = $this->GetGroup($tagInfo);
                $hiPri = 1000;
                foreach ($wantGroupList as $fg) {
                    [$fam, $lcWant] = $fg;
                    if (isset(self::TRANSLATE_WANT_GROUP[$lcWant])) $lcWant = self::TRANSLATE_WANT_GROUP[$lcWant];
                    // only set tag in specified group
                    // bump priority of preferred tag
                    if ($prfTag) $hiPri += $prfTag;
                    if ($fam === null) {
                        if ($lcWant === strtolower($grp[0])) {
                            // don't go to more general write group of "All"
                            // if something more specific was wanted
                            if ($wgAll and !$writeGroup) $writeGroup = $grp[0];
                            continue;
                        }
                        if ($lcWant === strtolower($grp[2])) continue;
                    } elseif ($fam == 7) {
                        if (self::IsSameID($tagInfo->TagID, $lcWant)) continue;
                    } elseif ($fam != 1 and !$tagInfo->AllowGroup) {
                        if ($lcWant === strtolower($grp[$fam] ?? '')) continue;
                        if ($wgAll and !$fam and isset(self::ALL_FAM0[$lcWant])) {
                            if (!$writeGroup) $writeGroup = self::ALL_FAM0[$lcWant];
                            continue;
                        }
                        continue 2;     // wrong group
                    }
                    // handle family 1 groups specially
                    if ($grp[0] === 'EXIF' or $grp[0] === 'SonyIDC' or $wgAll) {
                        if (!($ifdName and $lcWant === strtolower($ifdName))) {
                            if (!($wgAll and !$fam and isset(self::ALL_FAM0[$lcWant]))) continue 2;
                            if (!$writeGroup) $writeGroup = self::ALL_FAM0[$lcWant];
                            continue;
                        }
                        if ($wgAll and isset(self::ALL_FAM0[$lcWant]) and $fam) continue 2;
                        // can't yet write PreviewIFD tags (except for image)
                        if ($lcWant === 'PreviewIFD') {
                            ++$foundMatch;
                            continue 2;
                        }
                        $writeGroup = $ifdName; // write to the specified IFD
                    } elseif ($grp[0] === 'QuickTime') {
                        if ($grp[1] === 'Track#') {
                            if (!($movGroup and $lcWant === strtolower($movGroup))) continue 2;
                            $writeGroup = $movGroup;
                        } else {
                            $grpQT = $tagInfo->Table['WRITE_GROUP'];    // (my $grp)
                            if (!($grpQT and $lcWant === strtolower($grpQT))) continue 2;
                            $writeGroup = $grpQT;
                        }
                    } elseif ($grp[0] === 'MIE') {
                        if (!($mieGroup and $lcWant === strtolower($mieGroup))) continue 2;
                        $writeGroup = $mieGroup; // write to specific MIE group
                        // set specific write group with document number if specified
                        if (preg_match('/^MIE\d+$/', $writeGroup) and $tagInfo->Table['WRITE_GROUP']) {
                            $writeGroup = preg_replace('/^MIE/', $mieGroup, $tagInfo->Table['WRITE_GROUP'], 1);
                        }
                    } elseif (!$tagInfo->AllowGroup or !preg_match(self::Rx('^' . $tagInfo->AllowGroup . '$', 'i'), $lcWant)) {
                        // allow group1 name to be specified
                        if ($lcWant !== strtolower($grp[1])) continue 2;
                    }
                }
                if (!$writeGroup) $writeGroup = ($tagInfo->WriteGroup ?: $tagInfo->Table['WRITE_GROUP']) ?: $grp[0];
                $priority = $hiPri; // highest priority since group was specified
            }
            ++$foundMatch;
            // must do a dummy call to the write proc to autoload write package
            // before checking Writable flag
            $table = $tagInfo->Table;
            $writeProc = $table['WRITE_PROC'];
            // load source table if this was a user-defined table
            if ($table['SRC_TABLE']) {
                $src = self::GetTagTable($table['SRC_TABLE']);
                if (!$writeProc and $src) $writeProc = $src['WRITE_PROC'];
            }
            if ($writeProc) {
                // (PHP: classes autoload, so no module to require for a write proc given by name)
                if (!self::DummyWriteCall($writeProc)) continue;
            }
            // must still check writable flags in case of UserDefined tags
            $writable = $tagInfo->Writable;
            if (!($writable or ($table['WRITABLE'] and $writable === null and !$tagInfo->SubDirectory))) continue;
            // set specific write group (if we didn't already)
            if (!$writeGroup or (isset(self::TRANSLATE_WRITE_GROUP[$writeGroup]) and
                (!$tagInfo->WriteGroup or $tagInfo->WriteGroup !== 'All')))
            {
                // use default write group
                $writeGroup = $tagInfo->WriteGroup ?: $tagInfo->Table['WRITE_GROUP'];
                // use group 0 name if no WriteGroup specified
                $group0 = $this->GetGroup($tagInfo, 0);
                if (!$writeGroup) $writeGroup = $group0;
                // get priority for this group
                if (!$priority) {
                    if ($tagInfo->Avoid and $tagInfo->WriteAlso) {
                        $priority = 0;
                    } else {
                        $priority = $this->WRITE_PRIORITY[strtolower($writeGroup)] ?? null;
                        if (!$priority) {
                            $priority = ($this->WRITE_PRIORITY[strtolower($group0)] ?? null) ?: 0;
                        }
                    }
                }
                // adjust priority based on Preferred level for this tag
                if ($prfTag) $priority += $prfTag;
            }
            // don't write tag if protected
            $prot = $tagInfo->Protected;
            if ($noFlat and $tagInfo->Flat !== null) $prot = 1;
            if ($prot) {
                $prot = (int) $prot & ~(int) $protected;
                if ($prot) {
                    $lkup = [1 => 'unsafe', 2 => 'protected', 3 => 'unsafe and protected'];
                    $wasProtected = $lkup[$prot] ?? null;
                    if ($verbose > 1) {
                        $wgrp1 = $this->GetWriteGroup1($tagInfo, $writeGroup);
                        fwrite($out, "Sorry, $wgrp1:$tag is $wasProtected for writing\n");
                    }
                    continue;
                }
            }
            // set priority for this tag
            $tagPriority[$id] = $priority;
            // keep track of highest priority QuickTime tag
            if (($table['GROUPS'][0] ?? null) === 'QuickTime' and
                (!isset($highestQT[$lcTag]) or $highestQT[$lcTag] < $priority))
            {
                $highestQT[$lcTag] = $priority;
            }
            if ($priority > $highestPriority[$lcTag]) {
                $highestPriority[$lcTag] = $priority;
                $preferred[$lcTag] = [$id => 1];
                $avoid[$lcTag] = $tagInfo->Avoid ? 1 : 0;
            } elseif ($priority == $highestPriority[$lcTag]) {
                // create all tags with highest priority
                $preferred[$lcTag][$id] = 1;
                if ($tagInfo->Avoid) $avoid[$lcTag] = ($avoid[$lcTag] ?? 0) + 1;
            }
            if ($tagInfo->WriteAlso) {
                // store WriteAlso tags separately so we can set them first
                $writeAlsoList[] = $tagInfo;
            } else {
                $tagInfoList[] = $tagInfo;
            }
            // special case to allow override of XMP WriteGroup
            if ($writeGroup === 'XMP') {
                $wg = $tagInfo->WriteGroup ?: $table['WRITE_GROUP'];
                if ($wg) $writeGroup = $wg;
            }
            $writeGroupHash[$id] = $writeGroup;     // (%writeGroup)
        }
        // sort tag info list in reverse order of priority (highest number last)
        // so we get the highest priority error message in the end
        usort($tagInfoList, static fn ($a, $b) => $tagPriority[spl_object_id($a)] <=> $tagPriority[spl_object_id($b)]);
        // must write any tags which also write other tags first
        if ($writeAlsoList) array_unshift($tagInfoList, ...$writeAlsoList);

        // check priorities for each set of tags we are writing
        foreach (array_keys($preferred) as $lcTag) {
            $lcTag = (string) $lcTag;
            // don't create tags with priority 0 if group priorities are set
            if (!empty($preferred[$lcTag]) and $highestPriority[$lcTag] == 0 and $this->WRITE_PRIORITY) {
                unset($preferred[$lcTag]);
            }
            // avoid creating tags with 'Avoid' flag set if there are other alternatives
            if (!empty($avoid[$lcTag]) and !empty($preferred[$lcTag])) {
                if ($avoid[$lcTag] < count($preferred[$lcTag])) {
                    // just remove the 'Avoid' tags since there are other preferred tags
                    foreach ($tagInfoList as $tagInfo) {
                        if ($lcTag !== strtolower($tagInfo->Name)) continue;
                        if ($tagInfo->Avoid) unset($preferred[$lcTag][spl_object_id($tagInfo)]);
                    }
                } elseif ($highestPriority[$lcTag] < 1000) {
                    // look for another priority tag to create instead
                    $nextHighest = 0;
                    $nextBestTags = [];
                    foreach ($tagInfoList as $tagInfo) {
                        if ($lcTag !== strtolower($tagInfo->Name)) continue;
                        $priority = $tagPriority[spl_object_id($tagInfo)];
                        if (!$priority) continue;
                        if ($priority == $highestPriority[$lcTag]) continue;
                        if ($priority < $nextHighest) continue;
                        $permanent = $tagInfo->Permanent;
                        if ($permanent === null) $permanent = $tagInfo->Table['PERMANENT'];
                        if ($tagInfo->Avoid or $permanent) continue;
                        if ($writeGroupHash[spl_object_id($tagInfo)] === 'MakerNotes') continue;
                        if ($nextHighest < $priority) {
                            $nextHighest = $priority;
                            $nextBestTags = [];
                        }
                        $nextBestTags[] = $tagInfo;
                    }
                    if ($nextBestTags) {
                        // change our preferred tags to the next best tags
                        $preferred[$lcTag] = [];
                        foreach ($nextBestTags as $tagInfo) {
                            $preferred[$lcTag][spl_object_id($tagInfo)] = 1;
                        }
                    }
                }
            }
        }
        //
        // generate new value hash for each tag
        //
        $prioritySet = $createGroups = null;
        $alsoWrote = new \ArrayObject();

        $this->CHECK_WARN = null;   // reset CHECK_PROC warnings

        // loop through all valid tags to find the one(s) to write
        foreach ($tagInfoList as $tagInfo) {
            $id = spl_object_id($tagInfo);
            if (isset($alsoWrote[$id])) continue;   // don't rewrite tags we already wrote
            // only process List or non-List tags if specified
            if ($listOnly !== null and ((bool) $listOnly xor (bool) $tagInfo->List)) continue;
            $noConv = null;
            $writeGroup = $writeGroupHash[$id];
            $permanent = $tagInfo->Permanent;
            if ($permanent === null) $permanent = $tagInfo->Table['PERMANENT'];
            if ($permanent === null and $writeGroup === 'MakerNotes') $permanent = 1;
            $wgrp1 = $this->GetWriteGroup1($tagInfo, $writeGroup);
            $tag = $tagInfo->Name;      // get tag name for warnings
            $lcTag = strtolower($tag);
            $pref = ($preferred[$lcTag] ?? null) ?: [];
            // don't write Avoid-ed tags with side effect unless preferred
            if (empty($pref[$id]) and $tagInfo->Avoid and $tagInfo->WriteAlso) continue;
            $shift = $options['Shift'] ?? null;
            $addValue = $options['AddValue'] ?? null;
            if ($shift !== null) {
                // (can't currently shift list-type tags)
                $shiftable = null;
                if ($tagInfo->List) {
                    $shiftable = '';    // can add/delete but not shift
                } else {
                    $shiftable = $tagInfo->Shift;
                    if (!$shift) {
                        // set shift according to AddValue/DelValue
                        if ($addValue) $shift = 1;
                        // can shift a date/time with -=, but this is
                        // a conditional delete operation for other tags
                        if (!empty($options['DelValue']) and $shiftable !== null and $shiftable === 'Time') $shift = -1;
                    }
                    if ($shift and ($value === null or !strlen(Perl::str($value)))) {
                        // (now allow -= to be used for shiftable tag - v8.05)
                        //$err = "No value for time shift of $wgrp1:$tag";
                        //$verbose > 2 and print $out "$err\n";
                        //next;
                        $shift = null;
                    }
                }
                    // can't shift List-type tag
                if (($shiftable !== null and !$shiftable) and
                    // and don't try to conditionally delete if Shift is "0"
                    ($shift or (Perl::str($shiftable) === '0' and !empty($options['DelValue']))))
                {
                    $err = "$wgrp1:$tag is not shiftable";
                    if ($verbose) fwrite($out, "$err\n");
                    continue;
                }
            }
            $val = $value;
            if ($val !== null) {
                // check to make sure this is a List or Shift tag if adding
                if ($addValue and !($shift or $tagInfo->List)) {
                    if (Perl::str($addValue) === '2') {
                        $addValue = null;   // quietly reset this option
                    } else {
                        $err = "Can't add $wgrp1:$tag (not a List type)";
                        if ($verbose > 2) fwrite($out, "$err\n");
                        continue;
                    }
                }
                if ($shift) {
                    if ($tagInfo->Shift and $tagInfo->Shift === 'Time') {
                        // add '+' or '-' prefix to indicate shift direction
                        $val = (Perl::num($shift) > 0 ? '+' : '-') . Perl::str($val);
                        // check the shift for validity
                        $err2 = self::CheckShift($tagInfo->Shift, $val);
                        if ($err2) {
                            $err = "$err2 for $wgrp1:$tag";
                            if ($verbose > 2) fwrite($out, "$err\n");
                            continue;
                        }
                    } elseif (self::IsFloat($val)) {
                        $val = Perl::num($val) * Perl::num($shift);
                    } else {
                        $err = "Shift value for $wgrp1:$tag is not a number";
                        if ($verbose > 2) fwrite($out, "$err\n");
                        continue;
                    }
                    $noConv = 1;    // no conversions if shifting tag
                } elseif (!strlen(Perl::str($val)) and !empty($options['DelValue'])) {
                    $noConv = 1;    // no conversions for deleting empty value
                } elseif (is_array($val) and !array_is_list($val) and !$tagInfo->Struct) {
                    $err = "Can't write a structure to $wgrp1:$tag";
                    if ($verbose > 2) fwrite($out, "$err\n");
                    continue;
                }
            } elseif ($permanent) {
                if (!empty($options['IgnorePermanent'])) return [0, null];
                // can't delete permanent tags, so set them to DelValue or empty string instead
                if ($tagInfo->DelValue !== null) {
                    $val = $tagInfo->DelValue;
                    $noConv = 1;    // DelValue is the raw value, so no conversion necessary
                } else {
                    $val = '';
                }
            } elseif ($addValue or !empty($options['DelValue'])) {
                $err = "No value to add or delete in $wgrp1:$tag";
                if ($verbose > 2) fwrite($out, "$err\n");
                continue;
            } else {
                if ($tagInfo->DelCheck) {
                    //### eval DelCheck ($self, $tagInfo, $wantGroup)
                    // (PHP: $val is passed by reference too, the expression may set it)
                    try {
                        $delCheck = $tagInfo->DelCheck;
                        $err2 = $delCheck($this, $tagInfo, $wantGroup, $val);
                    } catch (\Throwable $e) {
                        self::PerlWarn(self::EvalError($e));
                        $err2 = 'Error evaluating DelCheck';
                    }
                    if ($err2 !== null) {
                        // (allow other tags to be set using DelCheck as a hook)
                        if (!$err2) goto WriteAlso; // GOTO!
                        if (!preg_match('/delete$/', $err2)) $err2 .= ' for';
                        $err = "$err2 $wgrp1:$tag";
                        if ($verbose > 2) fwrite($out, "$err\n");
                        continue;
                    }
                }
                // set group delete flag if this tag represents an entire group
                if ($tagInfo->DelGroup and empty($options['DelValue'])) {
                    $del = [$tag];
                    $this->DEL_GROUP[$tag] = 1;
                    // delete extra groups if necessary
                    foreach (self::DEL_MORE[$tag] ?? [] as $g) {
                        $this->DEL_GROUP[$g] = 1;
                        $del[] = $g;
                    }
                    // remove all of this group from previous new values
                    $this->RemoveNewValuesForGroup($tag);
                    if ($verbose) fwrite($out, '  Deleting tags in: ' . implode(' ', $del) . "\n");
                    ++$numSet;
                    continue;
                }
                $noConv = 1;    // value is not defined, so don't do conversion
            }
            // apply inverse PrintConv and ValueConv conversions
            // save ValueConv setting for use in ConvInv()
            if (!$noConv) {
                // set default conversion type used by ConvInv() and CHECK_PROC routines
                $this->ConvType = $convType;
                [$val, $e] = $this->ConvInv($val, $tagInfo, $tag, $wgrp1, $this->ConvType, $wantGroup);
                if ($e !== null) {
                    // empty error string causes error to be ignored without setting the value
                    if (!$e) goto WriteAlso; // GOTO!
                    $err = $e;
                }
            }
            if ($val === null and $value !== null) {
                // if value conversion failed, we must still add a NEW_VALUE
                // entry for this tag it it was a DelValue
                if (empty($options['DelValue'])) continue;
                $val = 'xxx never delete xxx';
            }
            if (!$this->NEW_VALUE) $this->NEW_VALUE = [];
            if (!empty($options['Replace'])) {
                // delete the previous new value
                $this->GetNewValueHash($tagInfo, $writeGroup, 'delete', $options['ProtectSaved'] ?? null);
                // also delete related tag previous new values
                if ($tagInfo->WriteAlso) {
                    $this->INDENT2 = '+';
                    if ($tagInfo->WriteGroup and $tagInfo->WriteGroup === 'All' and $writeGroup) {
                        $wgrp = $writeGroup . ':';
                    } else {
                        $wgrp = '';
                    }
                    $wtags = array_map('strval', array_keys($tagInfo->WriteAlso));
                    sort($wtags, SORT_STRING);
                    foreach ($wtags as $wtag) {
                        [$n, $e] = $this->DoSetNewValue(true, $wgrp . $wtag, null, ['Replace' => 2]);
                        $numSet += $n;
                    }
                    $this->INDENT2 = '';
                }
                if ($options['Replace'] == 2) {
                    ++$numSet;
                    continue;
                }
            }

            if ($val !== null) {
                // we are editing this tag, so create a NEW_VALUE hash entry
                $nvHash = $this->GetNewValueHash($tagInfo, $writeGroup, 'create',
                                    $options['ProtectSaved'] ?? null, (!empty($options['DelValue']) and !$shift));
                // ignore new values protected with ProtectSaved
                if (!$nvHash) {
                    ++$numSet;  // (increment $numSet to avoid warning)
                    continue;
                }
                if ($tagInfo->List and empty($options['Replace'])) $nvHash->NoReplace = 1;
                $nvHash->WantGroup = $wantGroup;
                if ($editOnly) $nvHash->EditOnly = 1;
                // save maker note fixup information if writing maker notes
                if ($tagInfo->MakerNotes) $nvHash->MAKER_NOTE_FIXUP = $options['Fixup'] ?? null;
                if ($createOnly) {  // create only (never edit)
                    // empty item in DelValue list to never edit existing value
                    $nvHash->DelValue = [''];
                    $nvHash->CreateOnly = 1;
                } elseif (!empty($options['DelValue']) or $addValue or $shift) {
                    // flag any AddValue or DelValue by creating the DelValue list
                    $nvHash->DelValue ??= [];
                    if ($shift) {
                        // add shift value to list
                        $nvHash->Shift = $val;
                    } elseif (!empty($options['DelValue'])) {
                        // don't create if we are replacing a specific value
                        if (!(Perl::str($val) === '' or $tagInfo->List)) $nvHash->IsCreating = 0;
                        // add delete value to list
                        $vals = (is_array($val) and array_is_list($val)) ? $val : [$val];
                        array_push($nvHash->DelValue, ...$vals);
                        if ($verbose > 1) {
                            $verb = $permanent ? 'Replacing' : 'Deleting';
                            $fromList = $tagInfo->List ? ' from list' : '';
                            foreach ($vals as $v) {
                                if (is_array($v) and !array_is_list($v)) {
                                    $v = XMP::SerializeStruct($this, $v);
                                }
                                fwrite($out, "{$this->INDENT2}$verb $wgrp1:$tag$fromList if value is '" . Perl::str($v) . "'\n");
                            }
                        }
                    }
                }
                // set priority flag to add only the high priority info
                // (will only create the priority tag if it doesn't exist,
                //  others get changed only if they already exist)
                $prf = $tagInfo->Preferred !== null ? $tagInfo->Preferred : $tagInfo->Table['PREFERRED'];
                // hack to prefer only a single tag in the QuickTime group
                if (($tagInfo->Table['GROUPS'][0] ?? null) === 'QuickTime') {
                    if ($tagPriority[$id] < ($highestQT[$lcTag] ?? 0)) $prf = 0;
                }
                if (!empty($pref[$id]) or $prf) {
                    if ($permanent or $shift) {
                        // don't create permanent or Shift-ed tag but define IsCreating
                        // so we know that it is the preferred tag
                        $nvHash->IsCreating = 0;
                    } elseif (($tagInfo->List and empty($options['DelValue'])) or
                             !($nvHash->DelValue !== null and $nvHash->DelValue) or
                             // also create tag if any DelValue value is empty ('')
                             self::GrepEmpty($nvHash->DelValue))
                    {
                        $nvHash->IsCreating = $editOnly ? 0 : ($editGroup ? 2 : 1);
                        // add to hash of groups where this tag is being created
                        $createGroups ??= ($options['CreateGroups'] ?? null) ?: new \ArrayObject();
                        $createGroups[$this->GetGroup($tagInfo, 0)] = 1;
                        $nvHash->CreateGroups = $createGroups;
                    }
                }
                if ($nvHash->IsCreating) {
                    if ($this->DEL_GROUP) {
                        $grps = [];
                        foreach (array_map('strval', array_keys($this->DEL_GROUP)) as $grp) {
                            if ($this->DEL_GROUP[$grp] == 2) continue;
                            // set flag indicating tags were written after this group was deleted
                            $this->DEL_GROUP[$grp] = 2;
                            $grps[] = $grp;
                        }
                        if ($verbose > 1 and $grps) {
                            sort($grps, SORT_STRING);
                            fwrite($out, '  Writing new tags after deleting groups: ' . implode(' ', $grps) . "\n");
                        }
                    }
                } elseif ($createOnly) {
                    $noCreate = $permanent ? 'permanent' : ($tagInfo->Avoid ? 'avoided' : '');
                    if (!$noCreate) $noCreate = $shift ? 'shifting' : 'not preferred';
                    if ($verbose > 2) fwrite($out, "Not creating $wgrp1:$tag ($noCreate)\n");
                    continue;   // nothing to do (not creating and not editing)
                }
                if ($shift or empty($options['DelValue'])) {
                    $nvHash->Value ??= [];
                    if (!$tagInfo->List) {
                        // not a List tag -- overwrite existing value
                        $nvHash->Value[0] = $val;
                    } elseif ($nvHash->AddBefore !== null and count($nvHash->Value) >= $nvHash->AddBefore) {
                        // values from a later argument have been added (ie. Replace=0)
                        // to this list, so the new values should come before these
                        array_splice($nvHash->Value, -$nvHash->AddBefore, 0, (is_array($val) and array_is_list($val)) ? $val : [$val]);
                    } else {
                        // add at end of existing list
                        array_push($nvHash->Value, ...((is_array($val) and array_is_list($val)) ? $val : [$val]));
                    }
                    if ($verbose > 1) {
                        if ($tagInfo->IsComposite) {
                            // (composite tags don't technically exist in the file)
                            if ($tagInfo->WriteAlso) {
                                $wtags = array_map('strval', array_keys($tagInfo->WriteAlso));
                                sort($wtags, SORT_STRING);
                                $ifExists = ' (+' . implode(',+', $wtags) . '):';
                            } else {
                                $ifExists = '';
                            }
                        } else {
                            $ifExists = $nvHash->IsCreating ? ($createOnly ?
                                       ($nvHash->IsCreating == 2 ?
                                         " if $writeGroup exists and tag doesn't" :
                                         " if tag doesn't exist") :
                                       ($nvHash->IsCreating == 2 ? " if $writeGroup exists" : '')) :
                                      (($nvHash->DelValue !== null and $nvHash->DelValue) ?
                                         ' if tag was deleted' : ' if tag exists');
                        }
                        $verb = ($shift ? 'Shifting' : ($addValue ? 'Adding' : 'Writing'));
                        fwrite($out, "{$this->INDENT2}$verb $wgrp1:$tag$ifExists\n");
                    }
                }
            } elseif ($permanent) {
                $err = "Can't delete Permanent tag $wgrp1:$tag";
                if ($verbose > 1) fwrite($out, "$err\n");
                continue;
            } elseif ($addValue or !empty($options['DelValue'])) {
                if ($verbose > 1) fwrite($out, "Adding/Deleting nothing does nothing\n");
                continue;
            } else {
                // create empty new value hash entry to delete this tag
                $this->GetNewValueHash($tagInfo, $writeGroup, 'delete');
                $nvHash = $this->GetNewValueHash($tagInfo, $writeGroup, 'create');
                $nvHash->WantGroup = $wantGroup;
                if ($verbose > 1) fwrite($out, "{$this->INDENT2}Deleting $wgrp1:$tag\n");
            }
            if ($setTags) $setTags[$id] = 1;
            if (!empty($pref[$id])) $prioritySet = 1;
WriteAlso:
            ++$numSet;
            // also write related tags
            $writeAlso = $tagInfo->WriteAlso;
            if ($writeAlso) {
                $this->INDENT2 = '+';   // indicate related tag with a leading "+"
                if ($tagInfo->WriteGroup and $tagInfo->WriteGroup === 'All' and $writeGroup) {
                    $wgrp = $writeGroup . ':';
                } else {
                    $wgrp = '';
                }
                $wtags = array_map('strval', array_keys($writeAlso));
                sort($wtags, SORT_STRING);
                self::EvalBegin();      // (local $SIG{'__WARN__'} = \&SetWarning)
                try {
                    foreach ($wtags as $wtag) {
                        $opts = [
                            'Type'         => 'ValueConv',
                            'Protected'    => (int) $protected | 0x02,
                            'AddValue'     => $addValue,
                            'DelValue'     => $options['DelValue'] ?? null,
                            'Shift'        => $options['Shift'] ?? null,
                            'Replace'      => $options['Replace'] ?? null,  // handle lists properly
                            'CreateGroups' => $createGroups,
                            'SetTags'      => $alsoWrote,       // remember tags already written
                        ];
                        self::$evalWarning = null;
                        //### eval WriteAlso ($val,%opts)
                        // (PHP: %opts is passed by reference, the expression may change it, then $self)
                        $v = null;
                        try {
                            $code = $writeAlso[$wtag];
                            $v = $code($val, $opts, $this);
                        } catch (\Throwable $e) {
                            self::$evalWarning = self::EvalError($e);  // ($@ and $evalWarning = $@)
                        }
                        // we wanted to do the eval in case there are side effect, but we
                        // don't want to write a value for a tag that is being deleted:
                        if ($val === null) $v = null;
                        if (!self::$evalWarning) {
                            [$n, self::$evalWarning] = $this->DoSetNewValue(true, $wgrp . $wtag, $v, $opts);
                            $numSet += $n;
                            // count this as being set if any related tag is set
                            if ($n and !empty($pref[$id])) $prioritySet = 1;
                        }
                        if (self::$evalWarning and (!$err or $verbose > 2)) {
                            $str = self::CleanWarning();
                            if ($str) {
                                if (!preg_match('/ for [-\w:]+$/', $str)) $str .= " for $wtag";
                                $str .= " in $wgrp1:$tag (WriteAlso)";
                                if (!$err) $err = $str;
                                if ($verbose > 2) fwrite($out, "$str\n");
                            }
                        }
                    }
                } finally {
                    self::EvalEnd();
                }
                $this->INDENT2 = '';
            }
        }
        // print warning if we couldn't set our priority tag
        if ($err !== null and !$prioritySet) {
            if ($err and !$wantarray) self::PerlWarn("$err\n");
        } elseif (!$numSet) {
            $pre = $wantGroup ? $wantGroup . ':' : '';
            if ($wasProtected) {
                $verbose = 0;   // we already printed this verbose message
                if (!(!empty($options['Replace']) and $options['Replace'] == 2)) {
                    $err = "Sorry, $pre$tag is $wasProtected for writing";
                }
            } elseif (!$listOnly) {
                if (preg_match('/[?*]/', $origTag)) {
                    if ($noCreate) {
                        $err = "No tags matching 'pre{$origTag}' will be created";
                        $verbose = 0;   // (already printed)
                    } elseif ($foundMatch) {
                        $err = "Sorry, no writable tags matching '{$pre}{$origTag}'";
                    } else {
                        $err = "No matching tags for '{$pre}{$origTag}'";
                    }
                } elseif ($noCreate) {
                    $err = "Not creating $pre$tag";
                    $verbose = 0;   // (already printed)
                } elseif ($foundMatch) {
                    $err = "Sorry, $pre$tag is not writable";
                } elseif ($wantGroup and $matchingTags) {
                    $err = "Sorry, $pre$tag doesn't exist or isn't writable";
                } else {
                    $err = "Tag '{$pre}{$tag}' is not defined";
                }
            }
            if ($err) {
                if ($verbose > 2) fwrite($out, "$err\n");
                if (!$wantarray) self::PerlWarn("$err\n");
            }
        } elseif ($this->CHECK_WARN) {
            $err = $this->CHECK_WARN;
            if ($verbose > 2) fwrite($out, "$err\n");
        } elseif ($err and !$verbose) {
            $err = null;
        }
        return [$numSet, $err];
    }

    /** Perl grep(/^$/, @list) in boolean context */
    private static function GrepEmpty(array $list): bool
    {
        foreach ($list as $v) {
            if (!is_array($v) and !is_object($v) and preg_match('/^$/', Perl::str($v))) return true;
        }
        return false;
    }

    //------------------------------------------------------------------------------
    // set new values from information in specified file
    // Inputs: 0) ExifTool object reference, 1) source file name or reference, etc,
    //         or ExifTool ref to use already-extracted tags from an ExifTool object,
    //         2-N) List of tags to set (or all if none specified), or reference(s) to
    //         hash for options to pass to SetNewValue.  The Replace option defaults
    //         to 1 for SetNewValuesFromFile -- set this to 0 to allow multiple tags
    //         to be copied to a list
    // Returns: Hash of information set successfully (includes Warning or Error messages)
    // Notes: Tag names may contain a group prefix, a leading '-' to exclude from copy,
    //        and/or a trailing '#' to copy the ValueConv value.  The tag name '*' may
    //        be used to represent all tags in a group.  An optional destination tag
    //        may be specified with '>DSTTAG' ('DSTTAG<TAG' also works, but in this
    //        case the source tag may also be an expression involving tag names).
    //        Simple assignments are also allowed: 'DSTTAG[#][+-][^]=[string]'
    // (PHP: an options hash is an associative array, the tags are strings)
    public function SetNewValuesFromFile($srcFile, ...$setTags): array
    {
        $prec = ini_set('precision', '15');
        try {
            return $this->DoSetNewValuesFromFile($srcFile, array_values($setTags));
        } finally {
            ini_set('precision', $prec);
        }
    }

    private function DoSetNewValuesFromFile($srcFile, array $setTags): array
    {
        $srcExifTool = $tag = $info = null;
        $exclude = $reqTags = [];

        // get initial SetNewValuesFromFile options
        $opts = ['Replace' => 1];   // replace existing list items by default
        while ($setTags and is_array($setTags[0]) and !array_is_list($setTags[0])) {
            foreach (array_shift($setTags) as $key => $v) $opts[$key] = $v;
        }
        // expand shortcuts
        if ($setTags) self::ExpandShortcuts($setTags);
        // set options for our extraction tool
        $options = $this->OPTIONS;
        $printConv = $options['PrintConv'];
        if (!empty($opts['Type'])) {
            // save source type separately because it may be different than dst Type
            $opts['SrcType'] = $opts['Type'];
            // override PrintConv option with initial Type if given
            $printConv = ($opts['Type'] === 'PrintConv' ? 1 : 0);
        }
        $srcType = $printConv ? 'PrintConv' : 'ValueConv';
        $structOpt = $options['Struct'] ?? 2;

        if ($srcFile instanceof ExifTool) {
            $srcExifTool = $srcFile;
            $info = $srcExifTool->GetInfo(['PrintConv' => $printConv]);
        } else {
            $srcExifTool = new ExifTool();
            $srcExifTool->Options('PrintConv', $printConv);
            // set flag to indicate we are being called from inside SetNewValuesFromFile()
            $srcExifTool->TAGS_FROM_FILE = 1;
            // synchronize and increment the file sequence number
            $srcExifTool->FILE_SEQUENCE = $this->FILE_SEQUENCE++;
            // copy both structured and flattened tags by default (but flattened tags are "unsafe")
            // copy structures only if no tags specified (since flattened tags are "unsafe")
            if (Perl::str($structOpt) === '2' and !$setTags) $structOpt = 1;
            // +------------------------------------------+
            // ! DON'T FORGET!!  Must consider each new   !
            // ! option to decide how it is handled here. !
            // +------------------------------------------+
            foreach (['ByteUnit', 'Charset', 'CharsetEXIF', 'CharsetFileName', 'CharsetID3', 'CharsetIPTC',
                    'CharsetPhotoshop', 'Composite', 'DateFormat', 'Debug', 'EncodeHangs', 'Escape',
                    'ExtendedXMP', 'ExtractEmbedded', 'FastScan', 'Filter', 'FixBase', 'Geolocation',
                    'GeolocAltNames', 'GeolocFeature', 'GeolocMinPop', 'GeolocMaxDist',
                    'GlobalTimeShift', 'GPSQuadrant', 'HexTagIDs', 'IgnoreGroups', 'IgnoreMinorErrors',
                    'IgnoreTags', 'ImageHashType', 'KeepUTCTime', 'Lang', 'LargeFileSupport',
                    'LigoGPSScale', 'ListItem', 'ListSep', 'MDItemTags', 'MissingTagValue', 'NoPDFList',
                    'NoWarning', 'Password', 'PrintConv', 'QuickTimeUTC', 'RequestTags', 'SaveFormat',
                    'SavePath', 'ScanForXMP', 'StructFormat', 'SystemTags', 'SystemTimeRes', 'TimeZone',
                    'Unknown', 'UserParam', 'Validate', 'WindowsLongPath', 'WindowsWideFile', 'XAttrTags',
                    'XMPAutoConv'] as $o)
            {
                $srcExifTool->Options($o, $options[$o] ?? null);
            }
            $srcExifTool->Options(
                'Binary',          1,
                'CoordFormat',     ($options['CoordFormat'] ?? null) ?: '%d %d %.8f', // copy coordinates at high resolution unless otherwise specified
                'Duplicates',      1,
                // Exclude (set below)
                'LimitLongValues', 10000000, // (10 MB)
                'List',            1,
                'MakerNotes',      (($options['FastScan'] ?? null) and $options['FastScan'] > 1) ? null : 1,
                'RequestAll',      ($options['RequestAll'] ?? null) ?: 1, // (must request all because reqTags doesn't cover wildcards)
                'StrictDate',      $options['StrictDate'] ?? 1,
                'Struct',          $structOpt,
            );
            // reset Geolocation option if we aren't copying any geolocation tags
            if (!empty($options['Geolocation']) and !preg_grep('/\bGeolocation/i', array_filter($setTags, 'is_string'))) {
                $this->VPrint(0, '(resetting unnecessary Geolocation option)');
                $srcExifTool->OPTIONS['Geolocation'] = null;
            }
            $srcExifTool->GLOBAL_TIME_OFFSET = $this->GLOBAL_TIME_OFFSET;
            $srcExifTool->ALT_EXIFTOOL = $this->ALT_EXIFTOOL;
            foreach ($setTags as $tag) {
                if (is_array($tag) or is_object($tag)) continue;
                // avoid extracting tags that are excluded
                if (preg_match('/^-(.*)/', $tag, $m)) {
                    $exclude[] = $m[1];
                    continue;
                }
                // add specified tags to list of requested tags
                $t = $tag;  // ($_)
                if (preg_match('/(.+?)\s*(>|<)\s*(.+)/', $t, $m)) {
                    if ($m[2] === '>') {
                        $t = $m[1];
                    } else {
                        $t = $m[3];
                        if (str_contains($t, '$')) {
                            preg_match_all('/\$\{?(?:[-\w]+:)*([-\w?*]+)/', $t, $mm);
                            array_push($reqTags, ...$mm[1]);
                            continue;
                        }
                    }
                }
                if (preg_match('/(^|:)([-\w?*]+)#?$/', $t, $m)) $reqTags[] = $m[2];
            }
            if ($exclude) {
                self::ExpandShortcuts($exclude, 1);
                $srcExifTool->Options('Exclude', $exclude);
            }
            if ($reqTags) $srcExifTool->Options('RequestTags', $reqTags);
            // get all tags from source file (including MakerNotes block)
            $info = $srcExifTool->ImageInfo($srcFile);
        }
        // (allow processing to continue if we have alternate files that may have been read OK)
        // (PHP: ALT_EXIFTOOL is a hash ref in Perl, so it is always true unless deleted)
        if (($info['Error'] ?? null) === 'Error opening file' and $this->ALT_EXIFTOOL === null) return $info;
        unset($srcExifTool->VALUE['Error']);  // delete so we can check this later

        // sort tags in file order with priority tags last
        $tags = $prio = [];
        $keys = array_map('strval', array_keys($info));
        $order = $srcExifTool->FILE_ORDER;
        usort($keys, static fn ($a, $b) => Perl::num($order[$a] ?? null) <=> Perl::num($order[$b] ?? null));
        foreach ($keys as $k) {
            if (str_contains($k, ' ')) {
                $tags[] = $k;
            } else {
                $prio[] = $k;
            }
        }
        array_push($tags, ...$prio);
        //
        // simply transfer all tags from source image if no tags specified
        //
        if (!$setTags) {
            // transfer maker note information to this object
            $this->MAKER_NOTE_BYTE_ORDER = $srcExifTool->MAKER_NOTE_BYTE_ORDER;
            $tagExtra = $srcExifTool->TAG_EXTRA;
            foreach ($tags as $tag) {
                // don't try to set errors or warnings
                if (preg_match('/^(Error|Warning)\b/', $tag)) continue;
                // get appropriate value type if necessary
                if (!empty($opts['SrcType']) and $opts['SrcType'] !== $srcType) {
                    $info[$tag] = $srcExifTool->GetValue($tag, $opts['SrcType']);
                }
                $fixup = $tagExtra[$tag]['Fixup'] ?? null;
                if ($fixup) $opts['Fixup'] = $fixup;
                // set value for this tag
                [$n, $e] = $this->DoSetNewValue(true, $tag, $info[$tag] ?? null, $opts);
                // delete this tag if we couldn't set it
                if (!$n) unset($info[$tag]);
                if ($fixup) unset($opts['Fixup']);
            }
            return $info;
        }
        //
        // transfer specified tags in the proper order
        //
        // 1) loop through input list of tags to set, and build @setList
        $setList = $setMatches = $altFiles = [];
        $assign = 0;
        foreach ($setTags as $t) {
            if (is_array($t)) {
                // update current options
                foreach ($t as $key => $v) $opts[$key] = $v;
                continue;
            }
            // make a copy of the current options for this setTag
            // (also use this hash to store expression and wildcard flags, EXPR and WILD)
            $opts2 = $opts;     // (my $opts)
            $tag = strtolower(Perl::str($t));   // change tag/group names to all lower case
            $fg = [];
            $grp = $dst = $dstGrp = $dstTag = $isExclude = null;
            // handle redirection to another tag
            if (preg_match('/(.+?)\s*(>|<|=)(\s*)(.*)/', $tag, $m)) {
                $dstGrp = '';
                $opt = $op = $spc = null;
                if ($m[2] === '>') {
                    [$tag, $dstTag] = [$m[1], $m[4]];
                    // flag add and delete (eg. '+<' and '-<') redirections
                    if (preg_match('/\s*([-+])$/', $tag, $mm)) {
                        $tag = preg_replace('/\s*([-+])$/', '', $tag, 1);
                        $opt = $mm[1];
                    } elseif (preg_match('/^([-+])\s*/', $dstTag, $mm)) {
                        $dstTag = preg_replace('/^([-+])\s*/', '', $dstTag, 1);
                        $opt = $mm[1];
                    }
                } else {
                    [$dstTag, $op, $spc, $tag] = [$m[1], $m[2], $m[3], $m[4]];
                    if (preg_match('/\s*([-+])$/', $dstTag, $mm)) {
                        $dstTag = preg_replace('/\s*([-+])$/', '', $dstTag, 1);
                        $opt = $mm[1];
                    }
                    if ($op === '=') {
                        // simple assignment ($tag will be the new value)
                        $tag = $spc . $tag;
                        if (str_ends_with($dstTag, '^')) {
                            $dstTag = substr($dstTag, 0, -1);
                        } elseif (!strlen($tag)) {
                            $tag = null;
                        }
                        $opts2['ASSIGN'] = ++$assign;
                    } elseif (str_contains($tag, '$')) {    // handle expressions
                        $tag = Perl::str($t);  // restore original case
                        // recover leading whitespace (except for initial single space)
                        $tag = preg_replace('/(.+?)\s*(>|<) ?/', '', $tag, 1);
                        $opts2['EXPR'] = 1; // flag this expression
                    } else {
                        // (not sure why this is here because sign should be before '<')
                        // (--> allows "<+" or "<-", which is an undocumented feature)
                        if (preg_match('/^([-+])\s*/', $tag, $mm)) {
                            $tag = preg_replace('/^([-+])\s*/', '', $tag, 1);
                            $opt = $mm[1];
                        }
                    }
                }
                if (str_starts_with($dstTag, '+')) {
                    $dstTag = substr($dstTag, 1);
                    $opts2['Replace'] = 0;
                }
                // validate tag name(s)
                if (!(!empty($opts2['EXPR']) or !empty($opts2['ASSIGN']) or self::ValidTagName($tag))) {
                    $this->Warn("Invalid tag name '{$tag}'. Use '=' not '<' to assign a tag value");
                    continue;
                }
                if (!self::ValidTagName($dstTag)) {
                    $this->Warn("Invalid tag name '{$dstTag}'");
                    continue;
                }
                // translate '+' and '-' to appropriate SetNewValue option
                if ($opt) {
                    $opts2[['+' => 'AddValue', '-' => 'DelValue'][$opt]] = 1;
                    $opts2['Shift'] = 0;    // shift if shiftable
                }
                if (preg_match('/(.*):(.+)/', $dstTag, $mm)) [$dstGrp, $dstTag] = [$mm[1], $mm[2]];
                // ValueConv may be specified separately on the destination with '#'
                if (str_ends_with($dstTag, '#')) {
                    $dstTag = substr($dstTag, 0, -1);
                    $opts2['Type'] = 'ValueConv';
                }
                // replace tag name of 'all' with '*'
                if ($dstTag === 'all') $dstTag = '*';
            } elseif (str_starts_with($tag, '+')) {
                $tag = substr($tag, 1);
                $opts2['Replace'] = 0;
            }
            if (empty($opts2['EXPR']) and empty($opts2['ASSIGN'])) {
                $isExclude = str_starts_with($tag, '-');
                if ($isExclude) $tag = substr($tag, 1);
                if (preg_match('/(.*):(.+)/', $tag, $m)) {
                    [$grp, $tag] = [$m[1], $m[2]];
                    foreach (Perl::split('/:/', $grp) as $g) {
                        // save family/groups in list (ignoring 'all' and '*')
                        if (!strlen($g) or !preg_match('/^(\d+)?(.*)/', $g, $mm, PREG_UNMATCHED_AS_NULL)) continue;
                        [$f, $g] = [$mm[1], $mm[2]];
                        if ((!$f or $f === '7') and preg_match('/^ID-/i', $g)) {
                            $g = substr($g, 3);
                            $f = 7;
                        }
                        if (preg_match('/^file\d+$/i', $g) and (!$f or $f === '8')) {
                            $f = 8;
                            $g8 = ucfirst($g);
                            if (!empty($srcExifTool->ALT_EXIFTOOL[$g8])) {
                                $opts2['GROUP8'] = $g8;
                                $altFiles[$g8] ??= [];
                                // save list of requested tags for this alternate ExifTool object
                                $altFiles[$g8][] = "$grp:$tag";
                            }
                        }
                        if ($g !== '*' and $g !== 'all') $fg[] = [$f, $g];
                    }
                }
                // allow ValueConv to be specified by a '#' on the tag name
                if (str_ends_with($tag, '#')) {
                    $tag = substr($tag, 0, -1);
                    $opts2['SrcType'] = 'ValueConv';
                    if (!$dstTag) $opts2['Type'] = 'ValueConv';
                }
                // replace 'all' with '*' in tag and group names
                if ($tag === 'all') $tag = '*';
                // allow wildcards in tag names (handle differently from all tags: '*')
                if (preg_match('/[?*]/', $tag) and $tag !== '*') {
                    $opts2['WILD'] = 1;     // set flag indicating wildcards were used in source tag
                    $tag = str_replace(['*', '?'], ['[-\w]*', '[-\w]'], $tag);
                }
            }
            // redirect, exclude or set this tag (Note: @fg is empty if we don't care about the group)
            if ($dstTag) {
                // redirect this tag
                if ($isExclude) return ['Error' => "Can't redirect excluded tag"];
                // set destination group the same as source if necessary
                // (removed in 7.72 so '-*:*<xmp:*' will preserve XMP family 1 groups)
                // $dstGrp = $grp if $dstGrp eq '*' and $grp;
                // write to specified destination group/tag
                $dst = [$dstGrp, $dstTag];
            } elseif ($isExclude) {
                // implicitly assume '*' if first entry is an exclusion
                if (!$setList) array_unshift($setList, [[], '*', ['', '*'], $opts2]);
                // exclude this tag by leaving $dst undefined
            } else {
                $dst = [$grp ?: '', !empty($opts2['WILD']) ? '*' : $tag]; // use same group name for dest
            }
            // save in reverse order so we don't set tags before an exclude
            array_unshift($setList, [$fg, $tag, $dst, $opts2]);
        }
        // 1b) copy requested tags for each alternate ExifTool object
        $g8s = array_map('strval', array_keys($altFiles));
        sort($g8s, SORT_STRING);
        foreach ($g8s as $g8) {
            // request specific alternate tags to load them from the alternate ExifTool object
            $altInfo = $srcExifTool->GetInfo($altFiles[$g8]);
            // add to tags list after dummy entry to signify start of tags for this alt file
            if ($altInfo) {
                $altKeys = array_map('strval', array_keys($altInfo));
                sort($altKeys, SORT_STRING);
                array_push($tags, 'Warning DUMMY', ...array_reverse($altKeys));
                foreach ($altInfo as $k => $v) $info[$k] = $v;
            }
        }
        // 2) initialize lists of matching tags for each setTag
        // (PHP: %setMatches is keyed by the index of the setTag in @setList)
        foreach ($setList as $i => $set) {
            if ($set[2]) $setMatches[$i] = [];
        }
        // no need to search source tags if doing only assignments
        if ($assign == count($setList)) $tags = [];
        // 3) loop through all tags in source image and save tags matching each setTag
        $rtnInfo = [];
        $isAlt = null;
        foreach ($tags as $tag) {
            // don't try to set errors or warnings
            if (preg_match('/^(Error|Warning)( |$)/', $tag)) {
                if ($tag === 'Warning DUMMY') {
                    $isAlt = 1; // start of the alt tags
                } else {
                    $rtnInfo[$tag] = $info[$tag] ?? null;
                }
                continue;
            }
            // only set specified tags
            $lcTag = strtolower(self::GetTagName($tag));
            $grp = $grpH = [];      // (@grp, %grp)
            foreach ($setList as $i => $set) {  // SET:
                $opts2 = $set[3];
                if (!empty($opts2['EXPR'])) continue;   // (expressions handled in step 4)
                if ((bool) ($opts2['GROUP8'] ?? null) xor (bool) $isAlt) continue;
                // check first for matching tag
                if (!(Perl::str($set[1]) === $lcTag or $set[1] === '*')) {
                    // handle wildcards
                    if (!(!empty($opts2['WILD']) and preg_match(self::Rx('^' . $set[1] . '$'), $lcTag))) continue;
                }
                // then check for matching group
                if ($set[0]) {
                    // get lower case group names if not done already
                    if (!$grp) {
                        $grp = array_map(static fn ($g) => strtolower(Perl::str($g)), $srcExifTool->GetGroup($tag));
                        foreach ($grp as $g) $grpH[$g] = 1;
                    }
                    foreach ($set[0] as [$f, $g]) {
                        if ($f === null) {
                            if (empty($grpH[$g])) continue 2;
                        } elseif ($f == 7) {
                            if (!self::IsSameID($srcExifTool->GetTagID($tag), $g)) continue 2;
                        } else {
                            if (!(isset($grp[$f]) and $g === $grp[$f])) continue 2;
                        }
                    }
                }
                if (!$set[2]) break;    // all done if we hit an exclude
                // add to the list of tags matching this setTag
                $setMatches[$i][] = $tag;
            }
        }
        // 4) loop through each setTag in original order, setting new tag values
        foreach (array_reverse($setList, true) as $i => $set) {
            // get options for SetNewValue
            $opts2 = $set[3];
            // handle expressions
            if (!empty($opts2['EXPR']) or !empty($opts2['ASSIGN'])) {
                $val = null;
                if (!empty($opts2['EXPR'])) {
                    $val = $srcExifTool->InsertTagValues($set[1], $tags, 'Error');
                    $err = $srcExifTool->VALUE['Error'] ?? null;
                    if ($err) {
                        // pass on any error as a warning unless it is suppressed
                        $noWarn = $srcExifTool->OPTIONS['NoWarning'] ?? null;
                        if (!($noWarn and (@preg_match(self::Rx($noWarn), $err) or
                            // (also apply expression to warning without "[minor] " prefix)
                            (preg_match('/^\[minor\] /i', $err) and @preg_match(self::Rx($noWarn), preg_replace('/^\[minor\] /i', '', $err, 1))))))
                        {
                            $tag = self::NextFreeTagKey($rtnInfo, 'Warning');
                            $rtnInfo[$tag] = $srcExifTool->VALUE['Error'];
                        }
                        unset($srcExifTool->VALUE['Error']);
                        if ($val === null) continue;
                    }
                } else {
                    $val = $set[1];
                }
                [$dstGrp, $dstTag] = $set[2];
                if (!(preg_match('/[?*]/', $dstTag) and $dstTag !== '*')) $opts2['Protected'] = 1;
                if ($dstGrp) $opts2['Group'] = $dstGrp;
                $rtnVals = $this->DoSetNewValue(true, $dstTag, $val, $opts2);
                if ($rtnVals[0]) $rtnInfo[$dstTag] = $val;  // tag was set successfully
                // return warning if any
                if ($rtnVals[1]) $rtnInfo[self::NextFreeTagKey($rtnInfo, 'Warning')] = $rtnVals[1];
                continue;
            }
            foreach ($setMatches[$i] ?? [] as $tag) {
                $val = $noWarn = null;
                if (!empty($opts2['SrcType']) and $opts2['SrcType'] !== $srcType) {
                    $val = $srcExifTool->GetValue($tag, $opts2['SrcType']);
                } else {
                    $val = $info[$tag] ?? null;
                }
                [$dstGrp, $dstTag] = $set[2];
                if ($dstGrp) {
                    $dstGrps = Perl::split('/:/', $dstGrp);
                    // destination group of '*' writes to same group as source tag
                    // (family 1 unless otherwise specified)
                    foreach ($dstGrps as &$dg) {
                        if (!preg_match('/^(\d*)(all|\*)$/i', $dg, $m)) continue;
                        $dg = $m[1] . $srcExifTool->GetGroup($tag, strlen($m[1]) ? $m[1] : 1);
                        $noWarn = 1;    // don't warn on wildcard destinations
                    }
                    unset($dg);
                    $opts2['Group'] = implode(':', $dstGrps);
                } else {
                    unset($opts2['Group']);
                }
                // transfer maker note information if setting this tag
                $srcInfo = $srcExifTool->TAG_INFO[$tag] ?? null;
                if ($srcInfo and $srcInfo->MakerNotes) {
                    $opts2['Fixup'] = $srcExifTool->TAG_EXTRA[$tag]['Fixup'] ?? null;
                    $this->MAKER_NOTE_BYTE_ORDER = $srcExifTool->MAKER_NOTE_BYTE_ORDER;
                }
                if ($dstTag === '*') {
                    $dstTag = $tag;
                    $noWarn = 1;
                }
                if ($set[1] === '*' or !empty($set[3]['WILD'])) {
                    // don't copy from protected binary tags when using wildcards
                    if ($srcInfo and $srcInfo->Protected and $srcInfo->Binary) continue;
                    // don't copy to protected tags when using wildcards
                    unset($opts2['Protected']);
                    // don't copy flattened tags if copying structures too when copying all
                    $opts2['NoFlat'] = Perl::str($structOpt) === '2' ? 1 : 0;
                } else {
                    // allow protected tags to be copied if specified explicitly
                    if (!preg_match('/[?*]/', $dstTag)) $opts2['Protected'] = 1;
                    unset($opts2['NoFlat']);
                }
                // set value(s) for this tag
                [$rtn, $wrn] = $this->DoSetNewValue(true, $dstTag, $val, $opts2);
                // this was added in version 9.14, and allowed actions like "-subject<all" to
                // write values of multiple tags into a list, but it had the side effect of
                // duplicating items if there were multiple list tags with the same name
                // (eg. -use mwg "-creator<creator"), so disable this as of ExifTool 9.36:
                // $$opts{Replace} = 0;    # accumulate values from tags matching a single argument
                if ($wrn and !$noWarn) {
                    // return this warning
                    $rtnInfo[self::NextFreeTagKey($rtnInfo, 'Warning')] = $wrn;
                    $noWarn = 1;
                }
                unset($opts2['Fixup']);
                if ($rtn) $rtnInfo[$tag] = $val;    // tag was set successfully
            }
        }
        return $rtnInfo;    // return information that we set
    }

    //------------------------------------------------------------------------------
    // Get new value(s) for tag
    // Inputs: 0) ExifTool object reference, 1) tag name (or tagInfo or nvHash ref, not public)
    //         2) optional pointer to return new value hash reference (not part of public API)
    // Returns: List of new Raw values (list may be empty if tag is being deleted)
    // Notes: 1) Preferentially returns new value from Extra table if writable Extra tag exists
    // 2) Must call AFTER IsOverwriting() returns 1 to get proper value for shifted times
    // 3) Tag name is case sensitive and may be prefixed by family 0 or 1 group name
    // 4) Value may have been modified by CHECK_PROC routine after ValueConv
    // (PHP: scalar context, the first value; list context is GetNewValueList)
    public function GetNewValue($tag, &$newValueHashPt = null)
    {
        $prec = ini_set('precision', '15');
        try {
            $vals = $this->DoGetNewValue(false, $tag, $newValueHashPt);
            return $vals ? $vals[0] : null;
        } finally {
            ini_set('precision', $prec);
        }
    }

    /** GetNewValue in list context: the list of new raw values */
    public function GetNewValueList($tag, &$newValueHashPt = null): array
    {
        $prec = ini_set('precision', '15');
        try {
            return $this->DoGetNewValue(true, $tag, $newValueHashPt);
        } finally {
            ini_set('precision', $prec);
        }
    }

    private function DoGetNewValue(bool $wantarray, $tag, &$newValueHashPt): array
    {
        $nvHash = null;
        if (($tag instanceof NewValue and $tag->IsNVH) or $tag === null) {
            $nvHash = $tag;
        } else {
            if ($this->NEW_VALUE) {
                $tagInfo = null;
                if ($tag instanceof TagInfo) {
                    $nvHash = $this->GetNewValueHash($tag);
                } elseif (($tagInfo = self::PackageTable('Image::ExifTool::Extra')[Perl::str($tag)] ?? null) instanceof TagInfo and
                         $tagInfo->Writable)
                {
                    $nvHash = $this->GetNewValueHash($tagInfo);
                } else {
                    // separate group from tag name
                    $groups = [];
                    $tag = Perl::str($tag);
                    if (preg_match('/(.*):/', $tag, $m)) {
                        $groups = Perl::split('/:/', $m[1]);
                        $tag = preg_replace('/(.*):/', '', $tag, 1);
                    }
                    $tagInfoList = TagLookup::FindTagInfo($tag);
                    // decide which tag we want
                    foreach ($tagInfoList as $tagInfo) {    // GNV_TagInfo:
                        $nvh = $this->GetNewValueHash($tagInfo);
                        if (!$nvh) continue;
                        // select tag in specified group(s) if necessary
                        foreach ($groups as $g) {
                            if ($g === $nvh->WriteGroup) continue;
                            $grps = $this->GetGroup($tagInfo);
                            if ($grps[0] === $nvh->WriteGroup) {
                                // check family 1 group only if WriteGroup is not specific
                                if ($g === $grps[1]) continue;
                            } else {
                                // otherwise check family 0 group
                                if ($g === $grps[0]) continue;
                            }
                            // also check family 7
                            if (preg_match('/^ID-(.*)/i', $g, $m) and self::IsSameID($tagInfo->TagID, $m[1])) continue;
                            // step to next entry in list
                            $nvh = $nvh->Next;
                            if (!$nvh) continue 2;
                        }
                        $nvHash = $nvh;
                        // give priority to the one we are creating
                        if ($nvHash->IsCreating !== null) break;
                    }
                }
            }
            // return new value hash if requested
            $newValueHashPt = $nvHash;
        }
        // (Perl: Value is a list ref, true even if empty)
        if (!($nvHash and $nvHash->Value !== null)) return [];
        $vals = $nvHash->Value;
        $copied = false;
        // do inverse raw conversion if necessary
        // - must also check after doing a Shift
        if ($nvHash->TagInfo->RawConvInv or $nvHash->Shift) {
            $copied = true;     // modify a copy of the values (PHP arrays are copied anyway)
            $tagInfo = $nvHash->TagInfo;
            $conv = $tagInfo->RawConvInv;
            $table = $tagInfo->Table;
            $checkProc = null;
            if ($nvHash->Shift and $table) $checkProc = $table['CHECK_PROC'];
            self::EvalBegin();  // (local $SIG{'__WARN__'} = \&SetWarning)
            try {
                self::$evalWarning = null;
                foreach ($vals as &$val) {
                    // must check value now if it was shifted
                    if ($checkProc) {
                        $err = $this->CallCheckProc($checkProc, $tagInfo, $val);
                        if ($err or $val === null) {
                            if (!$err) $err = 'Error generating raw value';
                            $this->Warn("$err for {$tagInfo->Name}");
                            $vals = [];
                            break;
                        }
                        if (!$conv) continue;
                    } else {
                        if (!$conv) break;
                    }
                    // do inverse raw conversion
                    if (self::IsInvCodeRef($conv)) {
                        try {
                            $val = self::CallCode($conv, $val, $this);
                        } catch (NotPorted $e) {
                            $val = null;
                            self::$evalWarning = self::EvalError($e);
                        }
                    } else {
                        //### eval RawConvInv ($self, $val, $tagInfo)
                        try {
                            $val = $conv($val, $this, $tagInfo);
                        } catch (\Throwable $e) {
                            $val = null;
                            self::$evalWarning = self::EvalError($e);
                        }
                    }
                    if (self::$evalWarning) {
                        // an empty warning ("\n") ignores tag with no error
                        if (self::$evalWarning !== "\n") {
                            $err = self::CleanWarning() . " in {$tagInfo->Name} (RawConvInv)";
                            $this->Warn($err);
                        }
                        $vals = [];
                        break;
                    }
                }
                unset($val);
            } finally {
                self::EvalEnd();
            }
        }
        // return our value(s)
        if ($wantarray) {
            // remove duplicates if requested
            if (count($vals) > 1 and $this->Options('NoDups')) {
                $seen = [];
                $vals = array_values(array_filter($vals, static function ($v) use (&$seen) {
                    if (is_array($v) or is_object($v)) return true;     // (refs are all different)
                    $k = Perl::str($v);
                    return !(($seen[$k] = ($seen[$k] ?? 0) + 1) > 1);
                }));
                // (Perl removes them from the new value list itself unless it was copied)
                if (!$copied) $nvHash->Value = $vals;
            }
            return $vals;
        }
        return $vals ? [$vals[0]] : [];
    }

    //------------------------------------------------------------------------------
    // Return the total number of new values set
    // Inputs: 0) ExifTool object reference
    // Returns: Scalar context) Number of new values that have been set (incl pseudo)
    //          List context) Number of new values (incl pseudo), number of "pseudo" values
    // ("pseudo" values are those which don't require rewriting the file to change)
    // (PHP: scalar context; list context is CountNewValuesList)
    public function CountNewValues()
    {
        return $this->CountNewValuesList()[0];
    }

    /** CountNewValues in list context: [number of new values (incl pseudo), number of "pseudo" values] */
    public function CountNewValuesList(): array
    {
        $newVal = $this->NEW_VALUE;
        [$num, $pseudo] = [0, 0];
        if ($newVal) {
            $num = count($newVal);
            foreach ($newVal as $nv) {
                $tagInfo = $nv->TagInfo;
                // don't count tags that don't write anything
                if ($tagInfo->WriteNothing) {
                    --$num;
                    continue;
                }
                // count the number of pseudo tags included
                if ($tagInfo->WritePseudo) ++$pseudo;
            }
        }
        $num += count($this->DEL_GROUP ?: []);
        return [$num, $pseudo];
    }

    //------------------------------------------------------------------------------
    // Save new values for subsequent restore
    // Inputs: 0) ExifTool object reference
    // Returns: Number of times new values have been saved
    // Notes: increments SAVE_COUNT flag each time routine is called
    public function SaveNewValues()
    {
        $newValues = $this->NEW_VALUE;
        $saveCount = ++$this->SAVE_COUNT;
        foreach ($newValues ?: [] as $nvHash) {
            while ($nvHash) {
                // set Save count if not done already
                if (!$nvHash->Save) $nvHash->Save = $saveCount;
                $nvHash = $nvHash->Next;
            }
        }
        // initialize hash for saving overwritten new values
        $this->SAVE_NEW_VALUE = [];
        // make a copy of the delete group hash
        $this->SAVE_DEL_GROUP = $this->DEL_GROUP;
        return $saveCount;
    }

    //------------------------------------------------------------------------------
    // Restore new values to last saved state
    // Inputs: 0) ExifTool object reference
    // Notes: Restores saved new values, but currently doesn't restore them in the
    // original order, so there may be some minor side-effects when restoring tags
    // with overlapping groups. eg) XMP:Identifier, XMP-dc:Identifier
    // Also, this doesn't do the right thing for list-type tags which accumulate
    // values across a save point
    public function RestoreNewValues(): void
    {
        $savedValues = $this->SAVE_NEW_VALUE;
        // 1) remove any new values which don't have the Save flag set
        if ($this->NEW_VALUE) {
            foreach (array_keys($this->NEW_VALUE) as $key) {
                $lastHash = null;
                $nvHash = $this->NEW_VALUE[$key];
                while ($nvHash) {
                    if ($nvHash->Save) {
                        $lastHash = $nvHash;
                    } else {
                        // remove this entry from the list
                        if ($lastHash) {
                            $lastHash->Next = $nvHash->Next;
                        } elseif ($nvHash->Next) {
                            $this->NEW_VALUE[$key] = $nvHash->Next;
                        } else {
                            unset($this->NEW_VALUE[$key]);
                        }
                    }
                    $nvHash = $nvHash->Next;
                }
            }
        }
        // 2) restore saved new values
        if ($savedValues !== null) {    // (a hash ref, true even if empty)
            $this->NEW_VALUE ??= [];
            foreach ($savedValues as $key => $saved) {
                if (!empty($this->NEW_VALUE[$key])) {
                    // add saved values to end of list
                    $nvHash = self::LastInList($this->NEW_VALUE[$key]);
                    $nvHash->Next = $saved;
                } else {
                    $this->NEW_VALUE[$key] = $saved;
                }
            }
            $this->SAVE_NEW_VALUE = [];     // reset saved new values
        }
        // 3) restore delete groups
        $this->DEL_GROUP = $this->SAVE_DEL_GROUP ?? [];
    }

    //------------------------------------------------------------------------------
    // Set alternate file for extracting information
    // Inputs: 0) ExifTool ref, 1) family 8 group name (of the form "File#" where # is any number)
    //         2) alternate file name (may contain tag names with leading "$"), or undef to reset
    // Returns: 1 on success, or 0 on invalid group name
    public function SetAlternateFile($g8, $file)
    {
        $g8 = ucfirst(strtolower(Perl::str($g8)));
        if (!preg_match('/^File\d+$/', $g8)) return 0;
        // keep the same file if already initialized (possibly has metadata extracted)
        if ($file === null) {
            unset($this->ALT_EXIFTOOL[$g8]);
        } elseif (!(!empty($this->ALT_EXIFTOOL[$g8]) and !str_contains(Perl::str($file), '$') and
            Perl::str($this->ALT_EXIFTOOL[$g8]->ALT_FILE) === Perl::str($file)))
        {
            $altExifTool = new ExifTool();
            $altExifTool->ALT_FILE = $file;
            $this->ALT_EXIFTOOL[$g8] = $altExifTool;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // get priority group list for new values
    // Inputs: 0) ExifTool object reference
    // Returns: List of group names
    public function GetNewGroups(): array
    {
        return $this->WRITE_GROUPS;
    }

    //------------------------------------------------------------------------------
    // Add user-defined tags at run time
    // Inputs: 0) destination table name, 1) tagID/tagInfo pairs for tags to add
    // Returns: number of tags added
    // Notes: will replace existing tags
    // (PHP: the tagID/tagInfo pairs are an array, tag information as arrays or TagInfo's)
    public static function AddUserDefinedTags($tableName, array $addTags)
    {
        $table = self::GetTagTable($tableName);
        if (!$table) return 0;
        // add tags to writer lookup
        $addTags = new TagTable($addTags);
        TagLookup::AddTags($addTags, $tableName);
        $num = 0;
        foreach ($addTags->keys() as $tagID) {
            if (isset(Vars::$specialTags[$tagID])) continue;
            unset($table[$tagID]);  // delete old entry if it existed
            self::AddTagToTable($table, $tagID, $addTags[$tagID], 1);
            ++$num;
        }
        return $num;
    }

    //==============================================================================
    // Functions below this are not part of the public API

    //------------------------------------------------------------------------------
    // Maintain backward compatibility for old GetNewValues function name
    // (PHP: scalar context, like GetNewValue)
    public function GetNewValues($tag, &$nvHashPt = null)
    {
        return $this->GetNewValue($tag, $nvHashPt);
    }

    //------------------------------------------------------------------------------
    // Un-escape string according to options settings and clear UTF-8 flag
    // Inputs: 0) ExifTool ref, 1) string ref or string ref ref
    // Notes: also de-references SCALAR values
    public function Sanitize(&$valPt): void
    {
        // de-reference SCALAR references
        if (self::IsScalarRef($valPt)) $valPt = $valPt->value;
        // make sure the Perl UTF-8 flag is OFF for the value if perl 5.6 or greater
        // (otherwise our byte manipulations get corrupted!!)
        // (PHP: strings are bytes, there is no UTF-8 flag)
        // un-escape value if necessary
        if ($this->OPTIONS['Escape']) {
            // (XMP.pm and HTML.pm were require'd as necessary when option was set)
            if ($this->OPTIONS['Escape'] === 'XML') {
                $valPt = XMP::UnescapeXML($valPt);
            } elseif ($this->OPTIONS['Escape'] === 'HTML') {
                $valPt = HTML::UnescapeHTML($valPt, $this->OPTIONS['Charset']);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Apply inverse conversions
    // Inputs: 0) ExifTool ref, 1) value, 2) tagInfo (or Struct item) ref,
    //         3) tag name, 4) group 1 name, 5) conversion type (or undef),
    //         6) [optional] want group ("" for structure field)
    // Returns: 0) converted value, 1) error string (or undef on success)
    // Notes:
    // - uses ExifTool "ConvType" member when conversion type is undef
    // - conversion types other than 'ValueConv' and 'PrintConv' are treated as 'Raw'
    // (PHP: a Struct item is an array; it is accessed through a TagInfo built from it)
    public function ConvInv($val, $tagInfo, $tag, $wgrp1, $convType = null, $wantGroup = null): array
    {
        if (is_array($tagInfo)) $tagInfo = new TagInfo($tagInfo);
        $err = $type = null;

        if (!$convType) $convType = $this->ConvType ?: 'PrintConv';

        for (;;) {      // Conv:
            if ($type === null) {
                // split value into list if necessary
                if ($tagInfo->List) {
                    $listSplit = $tagInfo->AutoSplit ?: $this->OPTIONS['ListSplit'];
                    if ($listSplit !== null and !$tagInfo->Struct and
                        ($wantGroup or $wantGroup === null))
                    {
                        if (Perl::str($listSplit) === '1' and $tagInfo->AutoSplit) $listSplit = ',?\s+';
                        $splitVal = Perl::split(self::Rx($listSplit), $val, -1);
                        $val = count($splitVal) > 1 ? $splitVal : ($splitVal ? $splitVal[0] : '');
                    }
                }
                $type = $convType;
            } elseif ($type === 'PrintConv') {
                $type = 'ValueConv';
            } else {
                // split raw value if necessary
                if ($tagInfo->RawJoin and $tagInfo->List and !is_array($val) and !is_object($val)) {
                    $splitVal = Perl::split(' ', $val);
                    if (count($splitVal) > 1) $val = $splitVal;
                }
                // finally, do our value check
                $err2 = null;
                if ($tagInfo->WriteCheck) {
                    //### eval WriteCheck ($self, $tagInfo, $val)
                    // (PHP: $val is passed by reference, the expression may change it)
                    try {
                        $writeCheck = $tagInfo->WriteCheck;
                        $err2 = $writeCheck($this, $tagInfo, $val);
                    } catch (\Throwable $e) {
                        self::PerlWarn(self::EvalError($e));
                        $err2 = 'Error evaluating WriteCheck';
                    }
                }
                if ($err2 === null) {
                    $table = $tagInfo->Table;
                    if ($table and $table['CHECK_PROC'] and !$tagInfo->RawConvInv) {
                        $checkProc = $table['CHECK_PROC'];
                        if (is_array($val) and array_is_list($val)) {
                            // loop through array values
                            foreach ($val as &$v) {
                                $err2 = $this->CallCheckProc($checkProc, $tagInfo, $v, $convType);
                                if ($err2) break;
                            }
                            unset($v);
                        } else {
                            $err2 = $this->CallCheckProc($checkProc, $tagInfo, $val, $convType);
                        }
                    }
                }
                if ($err2 !== null) {
                    if ($err2) {
                        $err = "$err2 for $wgrp1:$tag";
                        $this->VPrint(2, "$err\n");
                        $val = null;    // value was invalid
                    } else {
                        $err = $err2;   // empty error (quietly don't write tag)
                    }
                }
                break;
            }
            $conv = $tagInfo->$type;
            $convInv = $tagInfo->{"{$type}Inv"};
            // nothing to do at this level if no conversion defined
            if ($conv === null and $convInv === null) continue;

            $valList = [];
            $index = $convList = $convInvList = null;
            if (is_array($val) and array_is_list($val)) {
                // handle ValueConv of ListSplit and AutoSplit values
                $valList = $val;
                $val = $valList[$index = 0] ?? null;
            } elseif ($conv instanceof ConvList or $convInv instanceof ConvList) {
                // handle conversion lists
                $valList = Perl::split(self::Rx(self::LIST_SEP[$type]), $val);
                $val = $valList[$index = 0] ?? null;
                if ($conv instanceof ConvList) {
                    $convList = $conv->list;
                    $conv = $convList[0] ?? null;
                }
                if ($convInv instanceof ConvList) {
                    $convInvList = $convInv->list;
                    $convInv = $convInvList[0] ?? null;
                }
            }
            // loop through multiple values if necessary
            for (;;) {
                if ($convInv) {
                    // capture eval warnings too
                    self::$evalWarning = null;
                    self::EvalBegin();
                    try {
                        if (self::IsInvCodeRef($convInv)) {
                            try {
                                $val = self::CallCode($convInv, $val, $this);
                            } catch (NotPorted $e) {
                                $val = null;
                                self::$evalWarning = self::EvalError($e);
                            }
                        } else {
                            //### eval PrintConvInv/ValueConvInv ($val, $self, $wantGroup)
                            try {
                                $val = $convInv($val, $this, $wantGroup);
                            } catch (\Throwable $e) {
                                $val = null;
                                self::$evalWarning = self::EvalError($e);
                            }
                        }
                    } finally {
                        self::EvalEnd();
                    }
                    if (self::$evalWarning) {
                        // an empty warning ("\n") ignores tag with no error
                        if (self::$evalWarning === "\n") {
                            if ($err === null) $err = '';
                        } else {
                            $err = self::CleanWarning() . " in $wgrp1:$tag ({$type}Inv)";
                            $this->VPrint(2, "$err\n");
                        }
                        $val = null;
                        break 2;
                    } elseif ($val === null) {
                        $err = "Error converting value for $wgrp1:$tag ({$type}Inv)";
                        $this->VPrint(2, "$err\n");
                        break 2;
                    }
                } elseif ($conv) {
                    if (is_array($conv) and (!property_exists($tagInfo, "{$type}Inv") or $convInvList)) {
                        $multi = $lc = null;
                        // insert alternate language print conversions if required
                        // (PHP: the lookup is a copy, so the table's BITMASK isn't translated in place)
                        if ($this->CUR_LANG and $type === 'PrintConv' and
                            is_array($lc = $this->CUR_LANG[$tag] ?? null) and
                            ($lc = $lc['PrintConv'] ?? null))
                        {
                            $newConv = [];
                            foreach ($conv as $k => $v) {
                                $tr = (is_array($v) or is_object($v)) ? null : ($lc[Perl::str($v)] ?? null);
                                if ($tr === null) {
                                    $newConv[$k] = $v;
                                    continue;
                                }
                                $newConv[$k] = $this->Decode($tr, 'UTF8');
                            }
                            if (!empty($conv['BITMASK'])) {
                                foreach ($conv['BITMASK'] as $k => $v) {
                                    $tr = $lc[Perl::str($v)] ?? null;
                                    if ($tr === null) {
                                        $newConv['BITMASK'][$k] = $v;
                                        continue;
                                    }
                                    $newConv['BITMASK'][$k] = $this->Decode($tr, 'UTF8');
                                }
                            }
                            $conv = $newConv;
                        }
                        self::$evalWarning = null;
                        if (!empty($conv['BITMASK'])) {
                            $lookupBits = $conv['BITMASK'];
                            [$wbits, $tbits] = [$tagInfo->BitsPerWord, $tagInfo->BitsTotal];
                            [$val2, $err2] = self::EncodeBitsList($val, $lookupBits, $wbits, $tbits);
                            if ($err2) {
                                // ok, try matching a straight value
                                [$val, $multi] = self::ReverseLookupList($val, $conv);
                                if ($val === null) {
                                    $err = "Can't encode $wgrp1:$tag ($err2)";
                                    $this->VPrint(2, "$err\n");
                                    break 2;
                                }
                            } elseif ($val2 !== null) {
                                $val = $val2;
                            } else {
                                $noBits = $conv;
                                unset($noBits['BITMASK']);
                                [$val, $multi] = self::ReverseLookupList($val, $noBits);
                            }
                        } else {
                            [$val, $multi] = self::ReverseLookupList($val, $conv);
                        }
                        if ($val === null) {
                            $prob = self::$evalWarning ? lcfirst(self::CleanWarning()) : ($multi ? 'matches more than one ' : 'not in ') . $type;
                            $err = "Can't convert $wgrp1:$tag ($prob)";
                            $this->VPrint(2, "$err\n");
                            break 2;
                        } elseif (self::$evalWarning) {
                            $this->VPrint(2, self::CleanWarning() . " for $wgrp1:$tag\n");
                        }
                    } elseif (!$tagInfo->WriteAlso) {
                        $err = "Can't convert value for $wgrp1:$tag (no {$type}Inv)";
                        $this->VPrint(2, "$err\n");
                        $val = null;
                        break 2;
                    }
                }
                if (!$valList) break;
                $valList[$index] = $val;
                if (++$index >= count($valList)) {
                    // leave AutoSplit lists in ARRAY form, or join conversion lists
                    $val = $tagInfo->List ? $valList : self::JoinList(' ', $valList);
                    break;
                }
                if ($convList) $conv = $convList[$index] ?? null;
                if ($convInvList) $convInv = $convInvList[$index] ?? null;
                $val = $valList[$index];
            }
        } // end ValueConv/PrintConv loop

        return [$val, $err];
    }

    /** Perl: ref $conv eq 'CODE' for an inverse conversion (a string expression is a 3-argument closure) */
    private static function IsInvCodeRef($conv): bool
    {
        if (!$conv instanceof \Closure) return true;
        return (new \ReflectionFunction($conv))->getNumberOfParameters() !== 3;
    }

    /**
     * Call a code ref of a tag table (Perl: &$code(@args), not in an eval).  PHP: a sub not
     * ported yet, or a translated sub calling one, fails like a closure not translated yet.
     */
    private static function CallCode($code, ...$args)
    {
        if (!is_callable($code)) throw new NotPorted(is_string($code) ? $code : 'code ref');
        try {
            return $code(...$args);
        } catch (\Error $e) {
            if (str_starts_with($e->getMessage(), 'Call to undefined ')) throw new NotPorted($e->getMessage(), 0, $e);
            throw $e;
        }
    }

    /**
     * Call the CHECK_PROC of a table: &$checkProc($self, $tagInfo, \$val, $convType)
     * (PHP: a check proc not ported yet gives a "not ported" error, like a closure not translated)
     */
    private function CallCheckProc($checkProc, TagInfo $tagInfo, &$val, $convType = null)
    {
        try {
            if (!is_callable($checkProc)) throw new NotPorted('CHECK_PROC ' . (is_string($checkProc) ? $checkProc : 'closure'));
            try {
                return $checkProc($this, $tagInfo, $val, $convType);
            } catch (\Error $e) {
                if (str_starts_with($e->getMessage(), 'Call to undefined ')) throw new NotPorted($e->getMessage(), 0, $e);
                throw $e;
            }
        } catch (NotPorted $e) {
            return self::EvalError($e);
        }
    }

    /**
     * Perl's dummy call to a write proc, &$writeProc() without arguments, made to autoload
     * the write package before the Writable flags are checked.  PHP autoloads classes, so
     * only the returned value matters: Perl write procs start with "$et or return 1"
     * (Kodak::WriteKodakIFD returns '').  A write proc not ported yet, or whose PHP
     * signature requires the arguments, counts as 1.
     */
    public static function DummyWriteCall($writeProc)
    {
        if (!is_callable($writeProc)) return 1;     // ponytail: not ported yet (Perl gives 1)
        try {
            return $writeProc();
        } catch (\ArgumentCountError) {
            return 1;
        }
    }

    //------------------------------------------------------------------------------
    // Reformat date/time value in $_ based on specified format string
    // Inputs: 0) date/time format string
    // Returns: Reformatted date/time string
    // (PHP: $_ is the first argument, updated in place)
    public static function DateFmt(&$val, $fmt)
    {
        $et = new self();   // (Perl: a bare blessed hash with these 2 options)
        $et->OPTIONS['DateFormat'] = $fmt;
        $et->OPTIONS['StrictDate'] = 1;
        $shift = null;
        $advFmtSelf = self::$advFmtSelf;
        if ($advFmtSelf and ($shift = $advFmtSelf->OPTIONS['GlobalTimeShift'] ?? null) !== null) {
            $et->OPTIONS['GlobalTimeShift'] = $shift;
            $et->GLOBAL_TIME_OFFSET = $advFmtSelf->GLOBAL_TIME_OFFSET;
        }
        $val = $et->ConvertDateTime($val);
        if ($val === null) self::PerlWarn("Error converting date/time\n");
        if ($shift) $advFmtSelf->GLOBAL_TIME_OFFSET = $et->GLOBAL_TIME_OFFSET;
        return $val;
    }

    //------------------------------------------------------------------------------
    // Utility routine to remove duplicate items from default input string
    // Inputs: 0) true to set $_ to undef if not changed
    // Notes: - for use only in advanced formatting expressions
    // (PHP: $_ is the first argument, updated in place)
    public static function NoDups(&$val, $flag = null)
    {
        $seen = [];
        $advFmtSelf = self::$advFmtSelf;
        $sep = Perl::str($advFmtSelf ? $advFmtSelf->OPTIONS['ListSep'] : ', ');
        $items = [];
        foreach (Perl::split('/' . preg_quote($sep, '/') . '/', $val) as $item) {
            if (!isset($seen[$item])) $items[] = $item;
            $seen[$item] = 1;
        }
        $new = implode($sep, $items);
        return $val = ($flag and $new === Perl::str($val)) ? null : $new;
    }

    //------------------------------------------------------------------------------
    // Utility routine to set in $_ image from current object
    // Inputs: 0-N) list of tags to copy
    // Returns: Return value from WriteInfo
    // Notes: - for use only in advanced formatting expressions
    // (PHP: $_ is the first argument, updated in place)
    public static function SetTags(&$val, ...$tags)
    {
        $self = self::$advFmtSelf;
        $et = new self();
        $et->SetNewValuesFromFile($self, ...$tags);
        $ref = new ScalarRef($val);
        $rtn = $et->WriteInfo($ref);
        $val = $ref->value;
        return $rtn;
    }

    //------------------------------------------------------------------------------
    // Is specified tag writable
    // Inputs: 0) tag name, case insensitive (optional group name currently ignored)
    // Returns: 0=exists but not writable, 1=writable, undef=doesn't exist
    public static function IsWritable($tag)
    {
        $tag = preg_replace('/^(.*):/', '', Perl::str($tag), 1);    // ignore group name
        $tagInfoList = TagLookup::FindTagInfo($tag);
        if (!$tagInfoList) {
            if (TagLookup::TagExists($tag)) return 0;
            return null;
        }
        foreach ($tagInfoList as $tagInfo) {
            if ($tagInfo->Writable !== null) return $tagInfo->Writable ? 1 : 0;
            if ($tagInfo->Table['WRITABLE']) return 1;
            // must call WRITE_PROC to autoload writer because this may set the writable tag
            $writeProc = $tagInfo->Table['WRITE_PROC'];
            if ($writeProc) {
                self::DummyWriteCall($writeProc);   // dummy call to autoload writer
                if ($tagInfo->Writable) return 1;
            }
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Get next available tag key
    // Inputs: 0) hash reference (keys are tag keys), 1) tag name
    // Returns: next available tag key
    public static function NextFreeTagKey(array $info, $tag)
    {
        if (!array_key_exists($tag, $info)) return $tag;
        for ($i = 1; ; ++$i) {
            $key = "$tag ($i)";
            if (!array_key_exists($key, $info)) return $key;
        }
    }

    //------------------------------------------------------------------------------
    // Reverse hash lookup
    // Inputs: 0) value, 1) hash reference
    // Returns: Hash key or undef if not found (plus flag for multiple matches in list context)
    // (PHP: scalar context; list context is ReverseLookupList)
    public static function ReverseLookup($val, array $conv)
    {
        return self::ReverseLookupList($val, $conv)[0];
    }

    /** ReverseLookup in list context: [hash key or null, flag for multiple matches] */
    public static function ReverseLookupList($val, array $conv): array
    {
        if ($val === null) return [null, null];
        $multi = null;
        $val = Perl::str($val);
        if (preg_match('/^Unknown\s*\((.*)\)$/i', $val, $m)) {
            $val = $m[1];   // was unknown
            if (preg_match('/^0x([\da-fA-F]+)$/', $val, $m)) {
                // disable "Hexadecimal number > 0xffffffff non-portable" warning
                $val = Perl::hex($m[1]); // convert hex value
            }
        } else {
            $qval = preg_replace('/\s+$/', '', $val);   // remove trailing whitespace
            $qval = preg_quote($qval);
            $patterns = [
                "^$qval\$",         // exact match
                "^(?i)$qval\$",     // case-insensitive
                "^(?i)$qval",       // beginning of string
                "(?i)$qval",        // substring
            ];
            // (Perl stringifies the code and hash refs of the entries ignored below)
            $values = [];
            foreach ($conv as $k => $v) {
                $values[$k] = is_array($v) ? 'HASH(0x0)' : ((is_object($v) or $k === 'OTHER') ? 'CODE(0x0)' : Perl::str($v));
            }
            // hash entries to ignore in reverse lookup
            $found = $matches = null;
            foreach ($patterns as $pattern) {   // PAT:
                $rx = self::Rx($pattern);
                $matches = count(preg_grep($rx, $values));
                if (!$matches) continue;
                // multiple matches are bad unless they were exact
                if ($matches > 1 and !preg_match('/\$$/', $pattern)) {
                    // don't match entries that we should ignore
                    foreach (array_keys(self::IGNORE_PRINT_CONV) as $k) {
                        if (isset($values[$k]) and preg_match($rx, $values[$k])) --$matches;
                    }
                    if ($matches > 1) break;
                }
                $keys = array_map('strval', array_keys($conv));
                sort($keys, SORT_STRING);
                foreach ($keys as $k) {
                    if (!preg_match($rx, $values[$k]) or isset(self::IGNORE_PRINT_CONV[$k])) continue;
                    $val = $k;
                    $found = 1;
                    break 2;
                }
            }
            if (!$found) {
                // call OTHER conversion routine if available
                if (!empty($conv['OTHER'])) {
                    self::$evalWarning = null;
                    self::EvalBegin();  // (local $SIG{'__WARN__'} = \&SetWarning)
                    try {
                        $other = $conv['OTHER'];
                        $val = self::CallCode($other, $val, 1, $conv);
                    } catch (NotPorted $e) {
                        $val = null;
                        self::$evalWarning = self::EvalError($e);
                    } finally {
                        self::EvalEnd();
                    }
                } else {
                    $val = null;
                }
                if ($matches > 1) $multi = 1;
            }
        }
        return [$val, $multi];
    }

    //------------------------------------------------------------------------------
    // Return true if we are deleting or overwriting the specified tag
    // Inputs: 0) ExifTool object ref, 1) new value hash reference
    //         2) optional tag value (before RawConv) if deleting specific values
    // Returns: >0 - tag should be overwritten
    //          =0 - the tag should be preserved
    //          <0 - not sure, we need the old value to tell (if there is no old value
    //               then the tag should be written if $$nvHash{IsCreating} is true)
    // Notes: $$nvHash{Value} is updated with the new value when shifting a value
    public function IsOverwriting($nvHash, $val = null)
    {
        if (!$nvHash) return 0;
        // overwrite regardless if no DelValues specified
        if ($nvHash->DelValue === null) return 1;
        // never overwrite if DelValue list exists but is empty
        $shift = $nvHash->Shift;
        if (!($nvHash->DelValue or $shift !== null)) return 0;
        // return "don't know" if we don't have a value to test
        if ($val === null) return -1;
        // apply raw conversion if necessary
        $tagInfo = $nvHash->TagInfo;
        $conv = $tagInfo->RawConv;
        if ($conv) {
            self::$evalWarning = null;
            self::EvalBegin();  // (local $SIG{'__WARN__'} = \&SetWarning)
            try {
                if (self::IsCodeRef($conv)) {
                    try {
                        $val = self::CallCode($conv, $val, $this);
                    } catch (NotPorted $e) {
                        $val = null;
                        self::$evalWarning = self::EvalError($e);
                    }
                } else {
                    $priority = null;
                    $grps = [];
                    $tag = $tagInfo->Name;
                    //### eval RawConv ($self, $val, $tag, $tagInfo, $priority, @grps)
                    try {
                        $val = $conv($val, $this, $tag, $tagInfo, $priority, $grps, []);
                    } catch (\Throwable $e) {
                        $val = null;
                        self::$evalWarning = self::EvalError($e);
                    }
                }
            } finally {
                self::EvalEnd();
            }
            if ($val === null) return -1;
        }
        // do not overwrite if only creating
        if ($nvHash->CreateOnly) return 0;
        // apply time/number shift if necessary
        if ($shift !== null) {
            $shiftType = $tagInfo->Shift;
            if (!($shiftType and $shiftType === 'Time')) {
                if (!self::IsFloat($val)) {
                    // do the ValueConv to try to get a number
                    $conv = $tagInfo->ValueConv;
                    if ($conv !== null) {
                        self::$evalWarning = null;
                        self::EvalBegin();  // (local $SIG{'__WARN__'} = \&SetWarning)
                        try {
                            if ($conv instanceof ConvList or is_array($conv)) {
                                // (not a CODE ref or an expression)
                            } elseif (self::IsCodeRef($conv)) {
                                try {
                                    $val = self::CallCode($conv, $val, $this);
                                } catch (NotPorted $e) {
                                    $val = null;
                                    self::$evalWarning = self::EvalError($e);
                                }
                            } else {
                                //### eval ValueConv ($val, $self)
                                try {
                                    $vals = [];
                                    $val = $conv($val, $this, $vals, [], [], $tagInfo, $tagInfo->Name);
                                } catch (\Throwable $e) {
                                    $val = null;
                                    self::$evalWarning = self::EvalError($e);
                                }
                            }
                        } finally {
                            self::EvalEnd();
                        }
                        if (self::$evalWarning) {
                            $this->Warn("ValueConv {$tagInfo->Name}: " . self::CleanWarning());
                            return 0;
                        }
                    }
                    if (!($val !== null and self::IsFloat($val))) {
                        $this->Warn("Can't shift {$tagInfo->Name} (not a number)");
                        return 0;
                    }
                }
                $shiftType = 'Number';  // allow any number to be shifted
            }
            $err = $this->ApplyShift($shiftType, $shift, $val, $nvHash);
            if ($err) {
                $this->Warn("$err when shifting {$tagInfo->Name}");
                return 0;
            }
            // ensure that the shifted value is valid and reformat if necessary
            $checkVal = $this->GetNewValue($nvHash);
            if ($checkVal === null) return 0;
            // don't bother overwriting if value is the same
            if (Perl::str($val) === Perl::str($nvHash->Value[0] ?? null)) return 0;
            return 1;
        }
        // return 1 if value matches a DelValue
        foreach ($nvHash->DelValue as $delVal) {
            if (!is_array($delVal) and !is_object($delVal) and Perl::str($val) === Perl::str($delVal)) return 1;
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Get write group for specified tag
    // Inputs: 0) new value hash reference
    // Returns: Write group name
    public static function GetWriteGroup(NewValue $nvHash)
    {
        return $nvHash->WriteGroup;
    }

    //------------------------------------------------------------------------------
    // Get name of write group or family 1 group
    // Inputs: 0) ExifTool ref, 1) tagInfo ref, 2) write group name
    // Returns: Name of group for verbose message
    public function GetWriteGroup1(TagInfo $tagInfo, $writeGroup)
    {
        if (!preg_match('/^(MakerNotes|XMP|Composite|QuickTime)$/', Perl::str($writeGroup))) return $writeGroup;
        return $this->GetGroup($tagInfo, 1);
    }

    //------------------------------------------------------------------------------
    // Get list of tags to write for Geolocate feature
    // Inputs: 0) ExifTool ref, 1) group name(s),
    //         2) 0=prefer writing City, 1=prefer writing GPS, undef=deleting tags
    // Returns: list of tags to write/delete
    public function GetGeolocateTags($wantGroup, $writeGPS = null): array
    {
        $grps = $wantGroup ? array_map('strtolower', Perl::split('/:/', $wantGroup)) : [];
        $grpsH = [];    // lookup for specified groups (%grps)
        foreach ($grps as $g) $grpsH[$g] = $g;
        if (!empty($grpsH['exif']) and empty($grpsH['gps'])) {
            $grpsH['gps'] = 'gps';
            $grps[] = 'gps';
        }
        $tagGroups = [
            'xmp-iptcext'   => ['LocationShownCity', 'LocationShownProvinceState', 'LocationShownCountryCode',
                                'LocationShownCountryName', 'LocationShownGPSLatitude', 'LocationShownGPSLongitude'],
            'xmp-photoshop' => ['City', 'State', 'Country'],
            'xmp-iptccore'  => ['CountryCode'],
            'iptc'          => ['City', 'Province-State', 'Country-PrimaryLocationCode', 'Country-PrimaryLocationName'],
            'gps'           => ['GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef'],
            'xmp-exif'      => ['GPSLatitude', 'GPSLongitude'],
            'itemlist'      => ['GPSCoordinates'],
            'userdata'      => ['GPSCoordinates'],
            // more general groups not in this lookup: XMP and QuickTime
        ];
        $tags = [];
        // set specific City and GPS tags
        foreach ($grps as $grp) {
            foreach ($tagGroups[$grp] ?? [] as $t) $tags[] = "$grp:$t";
        }
        // set default XMP City tags if necessary
        if (!$writeGPS) {
            if (!empty($grpsH['keys'])) $tags[] = 'Keys:LocationName';
            if (!empty($grpsH['xmp']) or (!$tags and empty($grpsH['quicktime']))) {
                array_push($tags, 'XMP:City', 'XMP:State', 'XMP:CountryCode', 'XMP:Country', 'Keys:LocationName');
            }
        }
        if ($writeGPS === null) $writeGPS = 1;  // (delete both City and GPS)
        if ($writeGPS and !empty($grpsH['keys'])) $tags[] = 'Keys:GPSCoordinates';
        // set default QuickTime tag if necessary
        $didQT = count(preg_grep('/GPSCoordinates$/', $tags));
        if ((!empty($grpsH['quicktime']) and !$didQT) or ($writeGPS and !$tags and empty($grpsH['xmp']))) {
            $tags[] = 'QuickTime:GPSCoordinates';
        }
        // set default GPS tags if necessary
        if ($writeGPS) {
            if (!empty($grpsH['xmp']) and empty($grpsH['xmp-exif'])) array_push($tags, 'XMP:GPSLatitude', 'XMP:GPSLongitude');
            if (!$wantGroup) array_push($tags, 'GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef');
        }
        return $tags;
    }

    //------------------------------------------------------------------------------
    // Get new value hash for specified tagInfo/writeGroup
    // Inputs: 0) ExifTool object reference, 1) reference to tag info hash
    //         2) Write group name, 3) Options: 'delete' or 'create' new value hash
    //         4) optional ProtectSaved value, 5) true if we are deleting a value
    // Returns: new value hash reference for specified write group
    //          (or first new value hash in linked list if write group not specified)
    // Notes: May return undef when 'create' is used with ProtectSaved
    public function GetNewValueHash(?TagInfo $tagInfo, $writeGroup = null, $opts = null, $protectSaved = null, $delValue = null): ?NewValue
    {
        if (!$tagInfo) return null;
        $key = spl_object_id($tagInfo);
        $nvHash = $this->NEW_VALUE[$key] ?? null;

        $optsH = [];    // quick lookup for options (%opts)
        if ($opts) $optsH[$opts] = 1;
        if ($writeGroup === null) $writeGroup = '';

        if ($writeGroup) {
            // find the new value in the list with the specified write group
            while ($nvHash and Perl::str($nvHash->WriteGroup) !== Perl::str($writeGroup)) {
                // QuickTime and All are special cases because all group1 tags may be updated at once
                if (preg_match('/^(QuickTime|All)$/', Perl::str($nvHash->WriteGroup))) break;
                // replace existing entry if WriteGroup is 'All' (avoids confusion of forum10349)
                //last if $$tagInfo{WriteGroup} and $$tagInfo{WriteGroup} eq 'All'; # (didn't work for forum17770)
                // forum17770 patch (also handles case where "EXIF" is specified as a write group)
                if ($writeGroup === 'All' or ($nvHash->WriteGroup === 'EXIF' and preg_match('/IFD/', Perl::str($writeGroup)))) break;
                $nvHash = $nvHash->Next;
            }
        }
        // remove this entry if deleting, or if creating a new entry and
        // this entry is marked with "Save" flag
        if ($nvHash !== null and (!empty($optsH['delete']) or (!empty($optsH['create']) and $nvHash->Save))) {
            $protect = ($protectSaved !== null and $nvHash->Save !== null and $nvHash->Save > $protectSaved);
            // this is a bit tricky:  we want to add to a protected nvHash only if we
            // are adding a conditional delete ($_[5] true or DelValue with no Shift)
            // or accumulating List items (NoReplace true)
            // (NOTE: this should be looked into --> lists may be accumulated instead of being replaced
            // as expected when copying to the same list from different dynamic -tagsFromFile source files)
            if ($protect and !(!empty($optsH['create']) and ($nvHash->NoReplace or $delValue or
                ($nvHash->DelValue !== null and $nvHash->Shift === null))))
            {
                return null;    // honour ProtectSaved value by not writing this tag
            } elseif (!empty($optsH['delete'])) {
                $this->RemoveNewValueHash($nvHash, $tagInfo);
                $nvHash = null;
            } else {
                // save a copy of this new value hash
                // (PHP: the Value and DelValue lists are copied with the object)
                $copy = clone $nvHash;
                // add to linked list of saved new value hashes
                $copy->Next = $this->SAVE_NEW_VALUE[$key] ?? null;
                $this->SAVE_NEW_VALUE[$key] = $copy;
                unset($nvHash->Save);   // don't save it again
                if ($protect and $nvHash->Value !== null) $nvHash->AddBefore = count($nvHash->Value);
            }
        }
        if ($nvHash === null and !empty($optsH['create'])) {
            // create a new entry
            $nvHash = new NewValue([
                'TagInfo' => $tagInfo,
                'WriteGroup' => $writeGroup,
                'IsNVH' => 1,   // set flag so we can recognize a new value hash
                'Order' => $this->NV_COUNT++,
            ]);
            // add entry to our NEW_VALUE hash
            if (!empty($this->NEW_VALUE[$key])) {
                // add to end of linked list
                $lastHash = self::LastInList($this->NEW_VALUE[$key]);
                $lastHash->Next = $nvHash;
            } else {
                $this->NEW_VALUE[$key] = $nvHash;
            }
        }
        return $nvHash;
    }

    //------------------------------------------------------------------------------
    // Remove new value hash from linked list (and save if necessary)
    // Inputs: 0) ExifTool object reference, 1) new value hash ref, 2) tagInfo ref
    public function RemoveNewValueHash(NewValue $nvHash, TagInfo $tagInfo): void
    {
        $key = spl_object_id($tagInfo);
        $firstHash = $this->NEW_VALUE[$key] ?? null;
        if ($nvHash === $firstHash) {
            // remove first entry from linked list
            if ($nvHash->Next) {
                $this->NEW_VALUE[$key] = $nvHash->Next;
            } else {
                unset($this->NEW_VALUE[$key]);
            }
        } else {
            // find the list element pointing to this hash
            while ($firstHash and $firstHash->Next !== $nvHash) $firstHash = $firstHash->Next;
            // remove from linked list
            if ($firstHash) $firstHash->Next = $nvHash->Next;
        }
        // save the existing entry if necessary
        if ($nvHash->Save) {
            // add to linked list of saved new value hashes
            $nvHash->Next = $this->SAVE_NEW_VALUE[$key] ?? null;
            $this->SAVE_NEW_VALUE[$key] = $nvHash;
        }
    }

    //------------------------------------------------------------------------------
    // Remove all new value entries for specified group
    // Inputs: 0) ExifTool object reference, 1) group name
    public function RemoveNewValuesForGroup($group): void
    {
        if (!$this->NEW_VALUE) return;

        // make list of all groups we must remove
        $groups = [$group];
        array_push($groups, ...(self::REMOVE_GROUPS[$group] ?? []));

        $out = null;
        if ($this->OPTIONS['Verbose'] > 1) $out = $this->OPTIONS['TextOut'] ?? STDOUT;

        // loop though all new values, and remove any in this group
        foreach (array_keys($this->NEW_VALUE) as $hashKey) {
            $nvHash = $this->NEW_VALUE[$hashKey] ?? null;
            // loop through each entry in linked list
            while ($nvHash) {
                $nextHash = $nvHash->Next;
                $tagInfo = $nvHash->TagInfo;
                [$grp0, $grp1] = $this->GetGroup($tagInfo);
                $wgrp = $nvHash->WriteGroup;
                // use group1 if write group is not specific
                if ($wgrp === $grp0) $wgrp = $grp1;
                if ($grp0 === '*' or $wgrp === '*' or @preg_grep(self::Rx("^($grp0|$wgrp)\$", 'i'), $groups)) {
                    if ($out) fwrite($out, "Removed new value for $wgrp:{$tagInfo->Name}\n");
                    // remove from linked list
                    $this->RemoveNewValueHash($nvHash, $tagInfo);
                }
                $nvHash = $nextHash;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Get list of tagInfo hashes for all new data
    // Inputs: 0) ExifTool object reference, 1) optional tag table pointer
    // Returns: list of tagInfo hashes in no particular order
    public function GetNewTagInfoList(?TagTable $tagTablePtr = null): array
    {
        $tagInfoList = [];
        foreach ($this->NEW_VALUE ?: [] as $nvHash) {
            $tagInfo = $nvHash->TagInfo;
            if ($tagTablePtr and $tagTablePtr !== $tagInfo->Table) continue;
            $tagInfoList[] = $tagInfo;
        }
        return $tagInfoList;
    }

    //------------------------------------------------------------------------------
    // Get hash of tagInfo references keyed on tagID for a specific table
    // Inputs: 0) ExifTool object reference, 1-N) tag table pointers
    // Returns: hash reference
    // Notes: returns only one tagInfo ref for each conditional list
    public function GetNewTagInfoHash(?TagTable ...$tables): array
    {
        $tagInfoHash = [];
        $nv = $this->NEW_VALUE;
        while ($nv) {
            $tagTablePtr = array_shift($tables);
            if (!$tagTablePtr) break;
            foreach ($nv as $nvHash) {
                $tagInfo = $nvHash->TagInfo;
                if ($tagTablePtr !== $tagInfo->Table) continue;
                $tagInfoHash[$tagInfo->TagID] = $tagInfo;
            }
        }
        return $tagInfoHash;
    }

    //------------------------------------------------------------------------------
    // Find last element in linked list
    // Inputs: 0) element in list
    // Returns: Last element in list
    public static function LastInList(NewValue $element): NewValue
    {
        while ($element->Next) {
            $element = $element->Next;
        }
        return $element;
    }

    //------------------------------------------------------------------------------
    // Inverse date/time print conversion (reformat to YYYY:mm:dd HH:MM:SS[.ss][+-HH:MM|Z])
    // Inputs: 0) ExifTool object ref, 1) Date/Time string, 2) timezone flag:
    //               0     - remove timezone and sub-seconds if they exist
    //               1     - add timezone if it doesn't exist
    //               undef - leave timezone alone
    //         3) flag to allow date-only (YYYY, YYYY:mm or YYYY:mm:dd) or time without seconds
    // Returns: formatted date/time string (or undef and issues warning on error)
    // Notes: currently accepts different separators, but doesn't use DateFormat yet
    // (PHP: POSIX::strptime is not available, Time::Piece->strptime is emulated)
    public function InverseDateTime($val, $tzFlag = null, $dateOnly = null)
    {
        $rtnVal = $tz = $fs = null;
        $fmt = $this->OPTIONS['DateFormat'];
        $val = Perl::str($val);
        // strip off timezone first if it exists
        if (!$fmt and preg_match('/([-+])(\d{1,2}):?(\d{2})\s*(DST)?$/i', $val, $m)) {
            $val = preg_replace('/([-+])(\d{1,2}):?(\d{2})\s*(DST)?$/i', '', $val, 1);
            $tz = Perl::sprintf("$m[1]%.2d:$m[3]", $m[2]);
        } elseif (!$fmt and preg_match('/Z$/i', $val)) {
            $val = preg_replace('/Z$/i', '', $val, 1);
            $tz = 'Z';
        } else {
            $tz = '';
            // allow special value of 'now'
            if (strtolower($val) === 'now') return $this->TimeNow($tzFlag);
        }
        // only convert date if a format was specified and the date is recognizable
        if ($fmt) {
            $fmt = Perl::str($fmt);
            // handle fractional seconds (%f) and time zone (%z)
            [$fs, $tz] = ['', ''];
            if (preg_match('/%(f|:?z)/', $fmt)) {
                if (preg_match('/(.*[^%])%f/s', $fmt, $m)) {
                    $fmt = preg_replace('/(.*[^%])%f/', '$1', $fmt, 1);
                    if (preg_match('/(.*)(\.\d+)/', $val, $mm)) {   // (take last .### as fractional seconds)
                        $fs = $mm[2];
                        $val = preg_replace('/(.*)(\.\d+)/', '$1', $val, 1);
                    }
                }
                if (preg_match('/(.*[^%])%(:?)z/', $fmt, $m)) {
                    $fmt = preg_replace('/(.*[^%])%(:?)z/', '$1', $fmt, 1);
                    $colon = $m[2];
                    if (preg_match("/(.*)([-+]\\d{2})$colon(\\d{2})/", $val, $mm)) {
                        $tz = "$mm[2]:$mm[3]";
                        $val = preg_replace("/(.*)([-+]\\d{2})$colon(\\d{2})/", '$1', $val, 1);
                    }
                }
            }
            $wrn = null;
            $a = [];
            for ($lib = self::$strptimeLib; ; $lib = '') {  // TryLib:
                // handle %s format ourself (not supported in Fedora, see forum15032)
                if ($fmt === '%s') {
                    $val = self::ConvertUnixTime($val, 1);
                    break;
                }
                if (!$lib) {
                    if (!$this->OPTIONS['StrictDate']) break;
                    self::PerlWarn($wrn ?: "Install POSIX::strptime or Time::Piece for inverse date/time conversions\n");
                    return null;
                } else {
                    try {
                        $t = self::TimePieceStrptime($val, $fmt);
                        $a = [$t[0], $t[1], $t[2], $t[3], $t[4], $t[5]];
                    } catch (\RuntimeException) {
                        $a = [];
                    }
                }
                if (isset($a[5]) and strlen(Perl::str($a[5]))) {
                    $a[5] += 1900;  // add 1900 to year
                } else {
                    $wrn = "Invalid date/time (no year) using $lib\n";
                    continue;
                }
                if (isset($a[4]) and strlen(Perl::str($a[4]))) ++$a[4];     // add 1 to month
                for ($i = 0; $i <= 4; ++$i) {
                    if (!isset($a[$i]) or !strlen(Perl::str($a[$i]))) {
                        if ($i < 2 or $dateOnly) {  // (allow missing minutes/seconds)
                            $a[$i] = '  ';
                        } else {
                            $wrn = "Incomplete date/time specification using $lib\n";
                            continue 2;
                        }
                    } elseif (strlen(Perl::str($a[$i])) < 2) {
                        $a[$i] = '0' . $a[$i];  // pad to 2 digits if necessary
                    }
                }
                $val = implode(':', [$a[5], $a[4], $a[3]]) . ' ' . implode(':', [$a[2], $a[1], $a[0]]) . $fs . $tz;
                break;
            }
        }
        $val = Perl::str($val);
        if (preg_match('/(\d{4})/', $val, $m, PREG_OFFSET_CAPTURE)) {   // get YYYY
            $yr = $m[1][0];
            // get mm, dd, HH, and maybe MM, SS (continuing after YYYY, like Perl's m//g)
            preg_match_all('/\d{1,2}/', $val, $mm, 0, $m[0][1] + strlen($m[0][0]));
            $a = $mm[0];
            foreach ($a as &$v) {
                if (strlen($v) < 2) $v = "0$v";     // pad to 2 digits if necessary
            }
            unset($v);
            if (count($a) >= 3) {
                $ss = $a[4] ?? null;    // get SS
                while (count($a) < 5) $a[] = '00';  // add MM, SS if not given
                // get sub-seconds if they exist (must be after SS, and have leading ".")
                if (!$fmt) {
                    $fs = (count($a) > 5 and preg_match('/(\.\d+)\s*$/', $val, $mm)) ? $mm[1] : '';
                }
                // add/remove timezone if necessary
                if ($tzFlag) {
                    if (!$tz) {
                        // determine timezone offset for this time
                        $args = [$a[4], $a[3], $a[2], $a[1], $a[0] - 1, $yr];
                        $diff = self::TimeLocal_timegm(...$args) - self::TimeLocal(...$args);
                        $tz = self::TimeZoneString($diff / 60);
                    }
                } elseif ($tzFlag !== null) {
                    $tz = $fs = '';     // remove timezone and sub-seconds
                }
                if ($ss !== null and $ss < 60) {
                    $ss = ":$ss";
                } elseif ($dateOnly) {
                    $ss = '';
                } else {
                    $ss = ':00';
                }
                // construct properly formatted date/time string
                if ($a[0] < 1 or $a[0] > 12) {
                    self::PerlWarn("Month '$a[0]' out of range 1..12\n");
                    return null;
                }
                if ($a[1] < 1 or $a[1] > 31) {
                    self::PerlWarn("Day '$a[1]' out of range 1..31\n");
                    return null;
                }
                if ($a[2] > 24) {
                    self::PerlWarn("Hour '$a[2]' out of range 0..24\n");
                    return null;
                }
                if ($a[3] > 59) {
                    self::PerlWarn("Minutes '$a[3]' out of range 0..59\n");
                    return null;
                }
                $rtnVal = "$yr:$a[0]:$a[1] $a[2]:$a[3]$ss$fs$tz";
            } elseif ($dateOnly) {
                $rtnVal = implode(':', [$yr, ...$a]);
            }
        }
        if (!$rtnVal) self::PerlWarn("Invalid date/time (use YYYY:mm:dd HH:MM:SS[.ss][+/-HH:MM|Z])\n");
        return $rtnVal;
    }

    /**
     * Time::Piece->strptime($val, $fmt) as used by InverseDateTime (the C parser of Time::Piece
     * 1.34 in the C locale): returns [sec, min, hour, mday, mon, year-1900] normalized like
     * mini_mktime, throws "Error parsing time", and warns about garbage at the end of the string.
     */
    private static function TimePieceStrptime($str, $fmt): array
    {
        $str = Perl::str($str);
        $tm = [0, 0, 0, 1, 0, 70];  // (sensible defaults: sec, min, hour, mday, mon, year)
        $pos = self::StrptimeParse($str, 0, Perl::str($fmt), $tm);
        if ($pos === null) throw new \RuntimeException('Error parsing time');
        if ($pos < strlen($str)) {
            self::PerlWarn('Garbage at end of string in strptime: ' . substr($str, $pos));
            self::PerlWarn('Perhaps a format flag did not match the actual input?');
        }
        // normalize the time (eg. Feb 30 is Mar 2), but keep a leap second
        $sec = $tm[0] <= 60 ? $tm[0] : 0;
        $t = gmmktime($tm[2], $tm[1], $tm[0] - $sec, $tm[4] + 1, $tm[3], $tm[5] + 1900);
        $a = self::PerlGmtime($t);
        return [$a[0] + $sec, $a[1], $a[2], $a[3], $a[4], $a[5]];
    }

    /**
     * Day/month names and AM/PM of Time::Piece->use_locale(), called by InverseDateTime: the
     * names of the LC_TIME locale of the environment (which Perl sets at startup), from strftime.
     */
    private static function StrptimeNames(): array
    {
        static $names = null;
        if ($names !== null) return $names;
        $names = [
            ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
             'September', 'October', 'November', 'December'],
            ['AM', 'PM'],
        ];
        if (!function_exists('strftime')) return $names;   // (C locale names)
        $old = setlocale(LC_TIME, '0');
        setlocale(LC_TIME, '');
        try {
            // (Jan 4, 1970 was a Sunday)
            for ($i = 0; $i < 7; ++$i) {
                $t = gmmktime(12, 0, 0, 1, 4 + $i, 1970);
                [$names[0][$i], $names[1][$i]] = [@strftime('%a', $t), @strftime('%A', $t)];
            }
            for ($i = 0; $i < 12; ++$i) {
                $t = gmmktime(12, 0, 0, $i + 1, 15, 1970);
                [$names[2][$i], $names[3][$i]] = [@strftime('%b', $t), @strftime('%B', $t)];
            }
            $names[4] = [(string) @strftime('%p', gmmktime(1, 0, 0, 1, 15, 1970)), (string) @strftime('%p', gmmktime(13, 0, 0, 1, 15, 1970))];
        } finally {
            setlocale(LC_TIME, $old);
        }
        return $names;
    }

    /** the C _strptime() of Time::Piece: returns the position after the parsed input, or null */
    private static function StrptimeParse(string $buf, int $b, string $fmt, array &$tm): ?int
    {
        [$wday, $weekday, $mon, $month, $ampm] = self::StrptimeNames();
        $len = strlen($buf);
        $flen = strlen($fmt);
        $p = 0;
        $isDigit = static fn (int $i) => $i < $len and ctype_digit($buf[$i]);
        $isSpace = static fn (string $s, int $i) => $i < strlen($s) and ctype_space($s[$i]);
        // read up to $n digits
        $digits = static function (int $n) use ($buf, &$b, $len): int {
            for ($i = 0; $n and $b < $len and ctype_digit($buf[$b]); ++$b, --$n) $i = $i * 10 + ord($buf[$b]) - 48;
            return $i;
        };
        // skip the rest of the format word if the input is at a space
        $skipWord = static function () use ($buf, &$b, $fmt, &$p, $flen, $isSpace): void {
            if ($isSpace($buf, $b)) while ($p < $flen and !ctype_space($fmt[$p])) ++$p;
        };
        // match one of a list of names (case insensitive)
        $name = static function (array ...$lists) use ($buf, &$b): ?int {
            foreach (array_keys($lists[0]) as $i) {
                foreach ($lists as $list) {
                    if (strncasecmp(substr($buf, $b), $list[$i], strlen($list[$i])) === 0) {
                        $b += strlen($list[$i]);
                        return $i;
                    }
                }
            }
            return null;
        };
        while ($p < $flen) {
            if ($b >= $len) break;
            $c = $fmt[$p++];
            if ($c !== '%') {
                if (ctype_space($c)) {
                    while ($isSpace($buf, $b)) ++$b;
                } elseif ($c !== $buf[$b++]) {
                    return null;
                }
                continue;
            }
            // (%E and %O modifiers are ignored)
            do $c = $p < $flen ? $fmt[$p++] : '%'; while (($c === 'E' or $c === 'O') and $p < $flen);
            switch ($c) {
                case '%':
                    if (($buf[$b++] ?? '') !== '%') return null;
                    break;
                case '+': case 'c': case 'D': case 'F': case 'R': case 'r': case 'T': case 'X': case 'x':
                    $sub = ['+' => '%c', 'c' => '%a %d %b %Y %I:%M:%S %p', 'D' => '%m/%d/%y', 'F' => '%Y-%m-%d',
                            'R' => '%H:%M', 'r' => '%I:%M:%S %p', 'T' => '%H:%M:%S', 'X' => '%H:%M:%S',
                            'x' => '%m/%d/%y'][$c];
                    $b = self::StrptimeParse($buf, $b, $sub, $tm);
                    if ($b === null) return null;
                    break;
                case 'C':
                    if (!$isDigit($b)) return null;
                    $i = $digits(2);
                    if ($i < 19) return null;
                    $tm[5] = $i * 100 - 1900;
                    break;
                case 'n': case 't':
                    if (!$isSpace($buf, $b)) return null;
                    while ($isSpace($buf, $b)) ++$b;
                    break;
                case 'j':
                    if (!$isDigit($b)) return null;
                    $i = $digits(3);
                    if ($i < 1 or $i > 366) return null;
                    $tm[3] = $i;    // (day of year: counted from Jan 1 when normalized)
                    $tm[4] = 0;
                    break;
                case 'M': case 'S':
                    if ($b >= $len or $isSpace($buf, $b)) break;
                    if (!$isDigit($b)) return null;
                    $i = $digits(2);
                    if ($i > ($c === 'M' ? 59 : 60)) return null;
                    $tm[$c === 'M' ? 1 : 0] = $i;
                    $skipWord();
                    break;
                case 'H': case 'I': case 'k': case 'l':
                    if (!$isDigit($b)) return null;
                    $i = $digits(2);
                    if ($i > (($c === 'H' or $c === 'k') ? 23 : 12)) return null;
                    $tm[2] = $i;
                    $skipWord();
                    break;
                case 'p':
                    $i = $name($ampm);
                    if ($i === null or $tm[2] > 12) return null;
                    if ($i == 0 and $tm[2] == 12) $tm[2] = 0;
                    if ($i == 1 and $tm[2] != 12) $tm[2] += 12;
                    break;
                case 'A': case 'a':
                    if (($c === 'A' ? $name($weekday, $wday) : $name($wday)) === null) return null;
                    break;
                case 'U': case 'W':
                    if (!$isDigit($b)) return null;
                    if ($digits(2) > 53) return null;
                    $skipWord();
                    break;
                case 'w':
                    if (!$isDigit($b) or $buf[$b] > '6') return null;
                    ++$b;
                    $skipWord();
                    break;
                case 'd': case 'e':
                    // leading space is ok if date is single digit
                    $n = 2;
                    if ($isSpace($buf, $b) and $isDigit($b + 1) and !$isDigit($b + 2)) {
                        $n = 1;
                        ++$b;
                    }
                    if (!$isDigit($b)) return null;
                    $i = $digits($n);
                    if ($i > 31) return null;
                    $tm[3] = $i;
                    $skipWord();
                    break;
                case 'B': case 'b': case 'h':
                    $i = $c === 'B' ? $name($month, $mon) : $name($mon);
                    if ($i === null) return null;
                    $tm[4] = $i;
                    break;
                case 'm':
                    if (!$isDigit($b)) return null;
                    $i = $digits(2);
                    if ($i < 1 or $i > 12) return null;
                    $tm[4] = $i - 1;
                    $skipWord();
                    break;
                case 's':
                    if (!preg_match('/^\s*[-+]?\d+/', substr($buf, $b), $m)) return null;
                    $b += strlen($m[0]);
                    $a = self::PerlGmtime((int) $m[0]);
                    $tm = [$a[0], $a[1], $a[2], $a[3], $a[4], $a[5]];
                    break;
                case 'Y': case 'y':
                    if ($b >= $len or $isSpace($buf, $b)) break;
                    if (!$isDigit($b)) return null;
                    $i = $digits($c === 'Y' ? 4 : 2);
                    if ($c === 'Y') $i -= 1900;
                    if ($c === 'y' and $i < 69) $i += 100;
                    if ($i < 0) return null;
                    $tm[5] = $i;
                    $skipWord();
                    break;
                case 'Z':
                    if (preg_match('/^[A-Z]+/', substr($buf, $b), $m)) {
                        if ($m[0] !== 'GMT') return null;
                        $b += strlen($m[0]);
                    }
                    break;
                case 'z':
                    if (!preg_match('/^([-+])(\d{4})/', substr($buf, $b), $m)) return null;
                    $b += 5;
                    $sign = $m[1] === '-' ? -1 : 1;
                    $tm[2] -= $sign * intdiv((int) $m[2], 100);
                    $tm[1] -= $sign * ((int) $m[2] % 100);
                    break;
            }
        }
        return $b;
    }

    //------------------------------------------------------------------------------
    // Encode bit mask (the inverse of DecodeBits())
    // Inputs: 0) value to encode, 1) Reference to hash for encoding (or undef)
    //         2) optional number of bits per word (defaults to 32), 3) total bits
    // Returns: bit mask or undef on error (plus error string in list context)
    // (PHP: scalar context; list context is EncodeBitsList)
    public static function EncodeBits($val, $lookup = null, $bits = null, $num = null)
    {
        return self::DoEncodeBits(false, $val, $lookup, $bits, $num)[0];
    }

    /** EncodeBits in list context: [bit mask or null, error string or null] */
    public static function EncodeBitsList($val, $lookup = null, $bits = null, $num = null): array
    {
        return self::DoEncodeBits(true, $val, $lookup, $bits, $num);
    }

    private static function DoEncodeBits(bool $wantarray, $val, $lookup, $bits, $num): array
    {
        if (!$bits) $bits = 32;
        if (!$num) $num = $bits;
        $words = Perl::int((Perl::num($num) + $bits - 1) / $bits);
        $outVal = array_fill(0, (int) $words, 0);
        if (Perl::str($val) !== '(none)') {
            $vals = Perl::split('/\s*,\s*/', $val);
            foreach ($vals as $v) {
                $bit = null;
                if ($lookup) {
                    $bit = self::ReverseLookup($v, $lookup);
                    // (Note: may get non-numerical $bit values from Unknown() tags)
                    if ($bit === null) {
                        if (preg_match('/\[(\d+)\]/', $v, $m)) {   // numerical bit specification
                            $bit = $m[1];
                        } else {
                            // don't return error string unless more than one value
                            if (!(count($vals) > 1 and $wantarray)) return [null, null];
                            return [null, "no match for '{$v}'"];
                        }
                    }
                } else {
                    $bit = $v;
                }
                if (!(self::IsInt($bit) and Perl::num($bit) < $num)) {
                    if (!$wantarray) return [null, null];
                    return [null, self::IsInt($bit) ? 'bit number too high' : 'not an integer'];
                }
                $bit = Perl::num($bit);
                $word = Perl::int($bit / $bits);
                $outVal[$word] |= (1 << ($bit - $word * $bits));
            }
        }
        return [implode(' ', $outVal), null];
    }

    //------------------------------------------------------------------------------
    // check a value for validity
    // Inputs: 0) value reference, 1) format string, 2) optional count
    // Returns: error string, or undef on success
    // Notes: May modify value (if a count is specified for a string, it is null-padded
    // to the specified length, and floating point values are rounded to integer if required)
    public static function CheckValue(&$valPtr, $format, $count = null)
    {
        $vals = [];
        $format = Perl::str($format);

        if ($format === 'string' or $format === 'undef') {
            if (!($count and Perl::num($count) > 0)) return null;
            $len = strlen(Perl::str($valPtr));
            if ($format === 'string') {
                if ($len >= Perl::num($count)) return 'String too long';
            } else {
                if ($len > Perl::num($count)) return 'Data too long';
            }
            if ($len < Perl::num($count)) {
                $valPtr = Perl::str($valPtr) . str_repeat("\0", (int) (Perl::num($count) - $len));
            }
            return null;
        }
        // (PHP: $count may be a non-numeric string, eg. from a Format of CheckBinaryData)
        if ($count and Perl::num($count) != 1) {
            $vals = Perl::split(' ', $valPtr);
            if (Perl::num($count) < 0) {
                $count = count($vals);
                if (!$count) return null;
            }
        } else {
            $count = 1;
            $vals = [$valPtr];
        }
        if (count($vals) != Perl::num($count)) {
            $str = count($vals) > Perl::num($count) ? 'Too many' : 'Not enough';
            return "$str values specified (" . Perl::str($count) . ' required)';
        }
        for ($n = 0; $n < Perl::num($count); ++$n) {
            $val = array_shift($vals);
            if (str_starts_with($format, 'int')) {
                // make sure the value is integer
                if (!self::IsInt($val)) {
                    if (self::IsHex($val)) {
                        $val = $valPtr = Perl::hex($val);
                    } else {
                        // round single floating point values to the nearest integer
                        if (!(self::IsFloat($val) and $count == 1)) return 'Not an integer';
                        $val = $valPtr = Perl::int(Perl::num($val) + (Perl::num($val) < 0 ? -0.5 : 0.5));
                    }
                }
                $rng = self::INT_RANGE[$format] ?? null;
                if (!$rng) return "Bad int format: $format";
                if (Perl::num($val) < $rng[0]) return "Value below $format minimum";
                // (allow 0xfeedfeed code as value for 16-bit pointers)
                if (Perl::num($val) > $rng[1] and Perl::num($val) != 0xfeedfeed) return "Value above $format maximum";
            } elseif (str_starts_with($format, 'rational') or $format === 'float' or $format === 'double') {
                // make sure the value is a valid floating point number
                if (!self::IsFloat($val)) {
                    // allow 'inf', 'undef' and fractional rational values
                    if (str_starts_with($format, 'rational')) {
                        if ($val === 'inf' or $val === 'undef') continue;
                        if (preg_match('{^([-+]?\d+)/(\d+)$}', Perl::str($val), $m)) {
                            if (!($m[1] < 0 and str_ends_with($format, 'u'))) continue;
                            return 'Must be an unsigned rational';
                        }
                    }
                    return 'Not a floating point number';
                }
                if (preg_match('/^rational\d+u$/', $format) and Perl::num($val) < 0) {
                    return 'Must be a positive number';
                }
            }
        }
        return null;    // success!
    }
}
