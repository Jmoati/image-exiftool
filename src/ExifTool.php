<?php

//------------------------------------------------------------------------------
// File:         ExifTool.pm
//
// Description:  Read and write meta information
//
// URL:          https://exiftool.org/
//
// Revisions:    Nov. 12/2003 - P. Harvey Created
//               (See html/history.html for revision history)
//
// Legal:        Copyright (c) 2003-2026, Phil Harvey (philharvey66 at gmail.com)
//               This library is free software; you can redistribute it and/or
//               modify it under the same terms as Perl itself.
//
// PHP port of Image::ExifTool 13.59 (reading only). See PORTING.md.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

/**
 * Image::ExifTool. Members of the Perl object ($$self{...}) are properties: the main ones are
 * declared, any other member is created as null on first access (Perl autovivification).
 *
 * PHP notes (see the method comments for details):
 * - wantarray-dependent subs have a list-context twin: GetValueList, ReadValueList,
 *   GetFileTypeList, GroupMatchesList, SetFoundTagsList. GetGroup() returns the list of groups
 *   when no family (or -1) is given, the group name otherwise.
 * - Perl's in-place update of an ImageInfo tag list (\@tags) is available in $et->IO_TAG_LIST.
 * - Composite tag raw values (Perl hash ref of tag keys) are \ArrayObject's.
 * - Image::ExifTool::Decode(undef, ...) (function call without object) is ExifTool::DecodeStatic(null, ...).
 * - The Filter option is a PHP callable (value => filtered value, or null).
 */
#[\AllowDynamicProperties]
class ExifTool
{
    use \Image\ExifTool\ExifTool\Formats;
    use \Image\ExifTool\ExifTool\Shift;
    use \Image\ExifTool\ExifTool\NewValues;
    use \Image\ExifTool\ExifTool\Writer;

    public static $VERSION = '13.59';
    public static $RELEASE = '';

    /** %allTables: list of all tables loaded (except Composite tags) */
    public static array $allTables = [];
    /** @tableOrder: order the tables were loaded */
    public static array $tableOrder = [];
    /** $loadedAllTables: flag indicating we loaded all tables (Writer.pl) */
    public static $loadedAllTables = null;
    /** my %compositeID: lookup for new ID's of Composite tags based on original ID */
    public static array $compositeID = [];
    /** %UserDefined: user-defined tags (table name => tag definitions) */
    public static array $UserDefined = [];
    /** $evalWarning: warning string from an eval'd conversion (see SetWarning) */
    public static $evalWarning = null;
    /** %static_vars */
    public static array $static_vars = [];

    // the following 4 variables are defined in 'use vars' instead of using 'my'
    // because mod_perl 5.6.1 apparently has a problem with setting file-scope 'my'
    // variables from within subroutines (ref communication with Pavel Merdin):
    // $swapBytes - set if EXIF header is not native byte ordering
    // $swapWords - swap 32-bit words in doubles (ARM quirk)
    public static $currentByteOrder = 'MM'; // current byte ordering ('II' or 'MM')
    public static array $unpackStd = ['S' => 'n', 'L' => 'N', 'C' => 'C', 'c' => 'c'];
    public static $swapBytes = null;
    public static $swapWords = null;

    private const array UNPACK_MOTOROLA = ['S' => 'n', 'L' => 'N', 'C' => 'C', 'c' => 'c'];
    private const array UNPACK_INTEL = ['S' => 'v', 'L' => 'V', 'C' => 'C', 'c' => 'c'];
    private const array UNPACK_REV = ['N' => 'V', 'V' => 'N', 'C' => 'C', 'n' => 'v', 'v' => 'n', 'c' => 'c'];

    // hash lookups for reading values from data
    public static array $formatSize = [
        'int8s' => 1,
        'int8u' => 1,
        'int16s' => 2,
        'int16u' => 2,
        'int16uRev' => 2,
        'int32s' => 4,
        'int32u' => 4,
        'int32uRev' => 4,
        'int64s' => 8,
        'int64u' => 8,
        'rational32s' => 4,
        'rational32u' => 4,
        'rational64s' => 8,
        'rational64u' => 8,
        'fixed16s' => 2,
        'fixed16u' => 2,
        'fixed32s' => 4,
        'fixed32u' => 4,
        'fixed64s' => 8,
        'float' => 4,
        'double' => 8,
        'extended' => 10,
        'unicode' => 2,
        'complex' => 8,
        'string' => 1,
        'binary' => 1,
        'undef' => 1,
        'ifd' => 4,
        'ifd64' => 8,
        'ue7' => 1,
        'utf8' => 1, // (Exif 3.0)
    ];
    private const array READ_VALUE_PROC = [
        'int8s' => 'Get8s',
        'int8u' => 'Get8u',
        'int16s' => 'Get16s',
        'int16u' => 'Get16u',
        'int16uRev' => 'Get16uRev',
        'int32s' => 'Get32s',
        'int32u' => 'Get32u',
        'int32uRev' => 'Get32uRev',
        'int64s' => 'Get64s',
        'int64u' => 'Get64u',
        'rational32s' => 'GetRational32s',
        'rational32u' => 'GetRational32u',
        'rational64s' => 'GetRational64s',
        'rational64u' => 'GetRational64u',
        'fixed16s' => 'GetFixed16s',
        'fixed16u' => 'GetFixed16u',
        'fixed32s' => 'GetFixed32s',
        'fixed32u' => 'GetFixed32u',
        'fixed64s' => 'GetFixed64s',
        'float' => 'GetFloat',
        'double' => 'GetDouble',
        'extended' => 'GetExtended',
        'ifd' => 'Get32u',
        'ifd64' => 'Get64u',
    ];
    // lookup for all rational types
    private const array IS_RATIONAL = [
        'rational32u' => 1,
        'rational32s' => 1,
        'rational64u' => 1,
        'rational64s' => 1,
    ];

    // rationals may be a floating point number, 'inf' or 'undef'
    private static $ratNumer = null;
    private static $ratDenom = null;

    // memory purge variables
    private static $purgeFlag = 0;
    private static array $purgeTags = [];

    //------------------------------------------------------------------------------
    // Members of the ExifTool object (the main ones)
    public $OPTIONS = [];
    public $VALUE = [];
    public $PATH = [];
    public $DEL_GROUP = [];
    public $SAVE_COUNT = 0;
    public $NV_COUNT = 0;
    public $FILE_SEQUENCE = 0;
    public $FILES_WRITTEN = 0;
    public $INDENT2 = '';
    public $ALT_EXIFTOOL = [];
    public $WRITE_PRIORITY = [];
    public $WRITE_GROUPS = [];
    public $FILENAME = null;
    public $RAF = null;
    public $REQUESTED_TAGS = [];
    public $REQ_TAG_LOOKUP = [];
    public $EXCL_TAG_LOOKUP = [];
    public $IO_TAG_LIST = null;
    public $FOUND_TAGS = null;
    public $FILE_ORDER = [];
    public $BOTH = [];
    public $TAG_INFO = [];
    public $TAG_EXTRA = [];
    public $PRIORITY = [];
    public $LIST_TAGS = [];
    public $PROCESSED = [];
    public $DIR_COUNT = [];
    public $DUPL_TAG = [];
    public $WAS_WARNED = [];
    public $WRITTEN = [];
    public $FORCE_WRITE = [];
    public $FOUND_DIR = [];
    public $COMP_KEYS = [];
    public $HAS_DOC = [];
    public $DOC_NUM = null;
    public $DOC_COUNT = 0;
    public $BASE = 0;
    public $NUM_FOUND = 0;
    public $CHANGED = 0;
    public $INDENT = '  ';
    public $PRIORITY_DIR = '';
    public $LOW_PRIORITY_DIR = [];
    public $TIFF_TYPE = '';
    public $FMT_EXPR = null;
    public $DIR_NAME = null;
    public $FILE_TYPE = null;
    public $FILE_EXT = null;
    public $EXIF_DATA = null;
    public $EXIF_POS = null;
    public $FIRST_EXIF_POS = null;
    public $SET_GROUP0 = null;
    public $SET_GROUP1 = null;
    public $ESCAPE_PROC = null;
    public $CUR_LANG = null;
    public $HTML_DUMP = null;

    /** Perl autovivification: a missing member is created (as undef) on first access */
    public function &__get(string $name): mixed
    {
        $this->{$name} = null;
        return $this->{$name};
    }

    public function __isset(string $name): bool
    {
        return false;
    }

    //------------------------------------------------------------------------------
    // Warning handler routines (warning string stored in $evalWarning)
    //
    // Set warning message
    // Inputs: 0) warning string (undef to reset warning)
    public static function SetWarning($str): void
    {
        self::$evalWarning = $str;
    }

    // Get warning message
    public static function GetWarning()
    {
        return self::$evalWarning;
    }

    // Clean unnecessary information (line number, LF) from warning
    // Inputs: 0) warning string or undef to use $evalWarning
    // Returns: cleaned warning
    public static function CleanWarning($str = null)
    {
        if ($str === null) {
            if (self::$evalWarning === null) return null;
            $str = self::$evalWarning;
        }
        $str = Perl::str($str);
        // truncate at first " at " for warnings like "syntax error at (eval 80) line 1, at EOF"
        if (preg_match('/(.*?) at /s', $str, $m)) $str = $m[1];
        return preg_replace('/\s+$/s', '', $str);
    }

    //==============================================================================
    // New - create new ExifTool object
    // Inputs: 0) reference to exiftool object or ExifTool class name
    // Returns: blessed ExifTool object ref
    public function __construct()
    {
        // make sure our main Exif tag table has been loaded
        self::GetTagTable('Image::ExifTool::Exif::Main');

        $this->ClearOptions();      // create default options hash
        $this->VALUE = [];          // must initialize this for warning messages
        $this->PATH = [];           // (this too)
        $this->DEL_GROUP = [];      // lookup for groups to delete when writing
        $this->SAVE_COUNT = 0;      // count calls to SaveNewValues()
        $this->NV_COUNT = 0;        // count of NEW_VALUE entries
        $this->FILE_SEQUENCE = 0;   // sequence number for files when reading
        $this->FILES_WRITTEN = 0;   // count of files successfully written
        $this->INDENT2 = '';        // indentation of verbose messages from SetNewValue
        $this->ALT_EXIFTOOL = [];   // alternate exiftool objects

        // initialize our new groups for writing
        $this->SetNewGroups(...Vars\ExifTool::$defaultWriteGroups);
    }

    //------------------------------------------------------------------------------
    // ImageInfo - return specified information from image file
    // Inputs: 0) [optional] ExifTool object reference
    //         1) filename, file reference, RAF reference, or scalar data reference
    //         2-N) list of tag names to find (or tag list reference or options reference)
    // Returns: reference to hash of tag/value pairs (with "Error" entry on error)
    // Notes:
    //   - if no tags names are specified, the values of all tags are returned
    //   - tags may be specified with leading '-' to exclude, or trailing '#' for ValueConv
    //   - can pass a reference to list of tags to find, in which case the list will
    //     be updated with the tags found in the proper case and in the specified order.
    //     (PHP: the updated list is $et->IO_TAG_LIST)
    //   - can pass reference to hash specifying options
    //   - returned tag values may be scalar references indicating binary data
    //   - see ClearOptions() below for a list of options and their default values
    // PHP arguments: file name string, ScalarRef (data in memory), file handle resource or
    //   RandomAccess; list array = tag list; associative array = options; other strings = tags
    public function ImageInfo(...$args)
    {
        $prec = ini_set('precision', '15');
        try {
            $saveOptions = $this->OPTIONS;      // save original options

            // initialize file information
            $this->FILENAME = $this->RAF = null;

            $this->ParseArguments(...$args);    // parse our function arguments
            $this->ExtractInfo(null);           // extract meta information from image
            $info = $this->GetInfo(null);       // get requested information

            $this->OPTIONS = $saveOptions;      // restore original options

            return $info;   // return requested information
        } finally {
            ini_set('precision', $prec);
        }
    }

    //------------------------------------------------------------------------------
    // Get/set ExifTool options
    // Inputs: 0) ExifTool object reference,
    //         1) Parameter name (case insensitive), 2) Value to set the option
    //         3-N) More parameter/value pairs
    // Returns: original value of last option specified
    public function Options(...$args)
    {
        $oldVal = null;

        while ($args) {
            $param = (string) array_shift($args);
            $plus = null;
            // fix parameter case if necessary
            if (!array_key_exists($param, $this->OPTIONS)) {
                if (str_ends_with($param, '+')) {
                    $param = substr($param, 0, -1);
                    $plus = 1;
                }
                $fixed = null;
                foreach (array_keys($this->OPTIONS) as $key) {
                    if (strcasecmp((string) $key, $param) === 0) {
                        $fixed = $key;
                        break;
                    }
                }
                if ($fixed) {
                    $param = $fixed;
                } else {
                    $param = preg_replace('/^Group(\d*)$/i', 'Group$1', $param);
                }
            }
            $oldVal = $this->OPTIONS[$param] ?? null;
            if (is_array($oldVal) and ($param === 'Compact' or $param === 'XMPShorthand')) {
                // get previous Compact/XMPShorthand setting
                $oldVal = $oldVal[$param] ?? null;
            }
            if (!$args) break;
            $newVal = array_shift($args);
            if ($param === 'Lang') {
                // allow this to be set to undef to select the default language
                if ($newVal === null) $newVal = Vars\ExifTool::$defaultLang;
                if ($newVal === Vars\ExifTool::$defaultLang) {
                    $this->OPTIONS[$param] = $newVal;
                    $this->CUR_LANG = null;
                // make sure the language is available
                } else {
                    // (the Translate hashes are generated into tables/vars/Lang/<lang>.php)
                    $xlat = 'Image\ExifTool\Vars\Lang\\' . $newVal;
                    if (in_array($newVal, Vars\ExifTool::$langs, true) and class_exists($xlat)) {
                        if ($xlat::$Translate) {
                            $this->CUR_LANG = $xlat::$Translate;
                            $this->OPTIONS[$param] = $newVal;
                        }
                    }
                } // else don't change Lang
            } elseif ($param === 'Exclude' and $newVal !== null) {
                // clone Exclude list and expand shortcuts
                if (is_array($newVal)) {
                    $exclude = array_values($newVal);
                } else {
                    $exclude = [$newVal];
                }
                self::ExpandShortcuts($exclude, 1);  // (also remove '#' suffix)
                $this->OPTIONS[$param] = $exclude;
            } elseif (preg_match('/^Charset/', $param) or $param === 'IPTCCharset') {
                // only allow valid character sets to be set
                if ($newVal) {
                    $charset = Vars\ExifTool::$charsetName[strtolower((string) $newVal)] ?? null;
                    if ($charset) {
                        $this->OPTIONS[$param] = $charset;
                        // maintain backward-compatibility with old IPTCCharset option
                        if ($param === 'IPTCCharset') $this->OPTIONS['CharsetIPTC'] = $charset;
                    } else {
                        self::PerlWarn("Invalid Charset $newVal\n");
                    }
                } elseif ($param === 'CharsetEXIF' or $param === 'CharsetFileName' or $param === 'CharsetRIFF') {
                    $this->OPTIONS[$param] = $newVal;    // only these may be set to a false value
                } elseif ($param === 'CharsetQuickTime') {
                    $this->OPTIONS[$param] = 'MacRoman'; // QuickTime defaults to MacRoman
                } else {
                    $this->OPTIONS[$param] = 'Latin';    // all others default to Latin
                }
            } elseif ($param === 'UserParam') {
                // clear options if $newVal is undef
                if ($newVal === null) {
                    $this->OPTIONS[$param] = [];
                    continue;
                }
                $table = self::GetTagTable('Image::ExifTool::UserParam');
                // allow initialization of entire UserParam hash
                if (is_array($newVal)) {
                    $newParams = [];
                    $keys = array_map('strval', array_keys($newVal));
                    sort($keys, SORT_STRING);
                    foreach ($keys as $key) {
                        $lcTag = strtolower($key);
                        $newParams[$lcTag] = $newVal[$key];
                        unset($table[$lcTag]);
                        self::AddTagToTable($table, $lcTag, $key);
                    }
                    $this->OPTIONS[$param] = $newParams;
                    continue;
                }
                $force = $paramName = null;
                // set/reset single UserParam parameter
                if (preg_match('/(.*?)=(.*)/s', (string) $newVal, $m)) {
                    $paramName = $m[1];
                    $newVal = $m[2];
                    if (str_ends_with($paramName, '^')) {
                        $paramName = substr($paramName, 0, -1);
                        $force = 1;
                    }
                    $paramName = preg_replace('/[^-_a-zA-Z0-9#]/', '', $paramName);
                    $param = strtolower($paramName);
                } else {
                    $param = preg_replace('/[^-_a-zA-Z0-9#]/', '', strtolower((string) $newVal));
                    $newVal = null;
                }
                unset($table[$param]);
                $oldVal = $this->OPTIONS['UserParam'][$param] ?? null;
                if ($newVal !== null) {
                    if (strlen($newVal) or $force) {
                        $this->OPTIONS['UserParam'][$param] = $newVal;
                        self::AddTagToTable($table, $param, $paramName);
                    } else {
                        unset($this->OPTIONS['UserParam'][$param]);
                    }
                }
                // remove alternate version of tag
                if (str_ends_with($param, '#')) {
                    $param = substr($param, 0, -1);
                } else {
                    $param .= '#';
                }
                unset($table[$param]);
                unset($this->OPTIONS['UserParam'][$param]);
            } elseif ($param === 'RequestTags') {
                if ($newVal !== null) {
                    // parse list from delimited string if necessary
                    if (is_array($newVal)) {
                        $reqList = array_values($newVal);
                    } else {
                        preg_match_all('/[-\w?*:]+/', (string) $newVal, $m);
                        $reqList = $m[0];
                    }
                    self::ExpandShortcuts($reqList);
                    // add to existing list
                    if (!$this->OPTIONS[$param]) $this->OPTIONS[$param] = [];
                    foreach ($reqList as $req) {
                        if (!preg_match('/^(.*:)?([-\w?*]*)#?$/', $req, $m)) continue;
                        if (!empty($m[2])) $this->OPTIONS[$param][] = strtolower($m[2]);
                        if (empty($m[1])) continue;
                        // add requested groups with trailing colon
                        foreach (Perl::split('/:/', $m[1]) as $g) $this->OPTIONS[$param][] = strtolower($g) . ':';
                    }
                } else {
                    $this->OPTIONS[$param] = null;  // clear the list
                }
            } elseif ($param === 'IgnoreTags' or $param === 'IgnoreGroups') {
                if ($newVal !== null) {
                    if (is_array($newVal) and !array_is_list($newVal)) {
                        $this->OPTIONS[$param] = $newVal;
                        continue;
                    }
                    // parse list from delimited string if necessary
                    if (is_array($newVal)) {
                        $ignoreList = $newVal;
                    } else {
                        preg_match_all('/[-\w?*:#]+/', (string) $newVal, $m);
                        $ignoreList = $m[0];
                    }
                    if ($param === 'IgnoreTags') self::ExpandShortcuts($ignoreList);
                    // add to existing tags/groups to ignore
                    if (!$this->OPTIONS[$param]) $this->OPTIONS[$param] = [];
                    foreach ($ignoreList as $ign) {
                        if (!preg_match('/^(.*:)?([-\w?*]+)#?$/', $ign, $m)) continue;
                        $this->OPTIONS[$param][strtolower($m[2])] = 1;
                    }
                } else {
                    $this->OPTIONS[$param] = null;  // clear the option
                }
            } elseif ($param === 'ListJoin') {
                $this->OPTIONS[$param] = $newVal;
                // set the old List and ListSep options for backward compatibility
                if ($newVal !== null) {
                    $this->OPTIONS['List'] = 0;
                    $this->OPTIONS['ListSep'] = $newVal;
                } else {
                    $this->OPTIONS['List'] = 1;
                    // (ListSep must be defined)
                }
            } elseif ($param === 'List') {
                $this->OPTIONS[$param] = $newVal;
                // set the new ListJoin option for forward compatibility
                $this->OPTIONS['ListJoin'] = $newVal ? null : $this->OPTIONS['ListSep'];
            } elseif ($param === 'Compact' or $param === 'XMPShorthand') {
                // set Compact and XMPShorthand options, preserving backward compatibility
                $compact = [];
                foreach (['Compact', 'XMPShorthand'] as $p) {
                    // (allow setting from a HASH (undocumented)
                    if (is_array($newVal)) {
                        $compact = $newVal;
                        continue;
                    }
                    $val = $param === $p ? $newVal : ($this->OPTIONS['Compact'][$p] ?? null);
                    if ($val !== null) {
                        preg_match_all('/\w+/', (string) $val, $m);
                        $opt = ($p === 'Compact') ? Vars\ExifTool::$compactOpt : Vars\ExifTool::$xmpShorthandOpt;
                        foreach ($m[0] as $v) {
                            $set = $opt[strtolower($v)] ?? null;
                            if (!$set) {
                                self::PerlWarn("Invalid $p setting '{$v}'\n");
                                return $oldVal;
                            }
                            if (!is_array($set)) {
                                $compact[$set] = 1;
                                continue;
                            }
                            foreach ($set as $s) $compact[$s] = 1;
                        }
                    }
                    $compact[$p] = $val; // preserve most recent setting
                }
                $this->OPTIONS['Compact'] = $this->OPTIONS['XMPShorthand'] = $compact;
            } elseif ($param === 'NoWarning') {
                // validate regular expression
                self::$evalWarning = null;
                if ($newVal !== null and @preg_match(self::Rx($newVal), '') === false) {
                    self::$evalWarning = preg_last_error_msg();
                }
                if (self::$evalWarning) {
                    self::PerlWarn('NoWarning: ' . self::CleanWarning() . "\n");
                    continue;
                }
                // add to existing expression if specified
                if ($plus and $oldVal !== null) {
                    $newVal = $newVal !== null ? "$oldVal|$newVal" : $oldVal;
                }
                $this->OPTIONS[$param] = $newVal;
            } elseif ($param === 'ImageHashType') {
                if ($newVal === null) {
                    self::PerlWarn("Can't set $param to undef\n");
                } elseif (preg_match('/^(MD5|SHA256|SHA512)$/i', (string) $newVal)) {
                    $this->OPTIONS[$param] = strtoupper($newVal);
                } else {
                    self::PerlWarn("Invalid $param setting '{$newVal}'\n");
                }
            } elseif ($param === 'StructFormat') {
                if ($newVal !== null) {
                    if (!preg_match('/^(JSON|JSONQ)$/i', (string) $newVal)) {
                        self::PerlWarn("Invalid $param setting '{$newVal}'\n");
                        continue;
                    }
                    $newVal = strtoupper($newVal);
                }
                $this->OPTIONS[$param] = $newVal;
            } elseif ($param === 'ByteUnit') {
                if ($newVal !== null) {
                    // (allow "Metric" or "SI" for SI, and "IT" or "Binary" for Binary)
                    $goodVal = preg_match('/^S|M/i', (string) $newVal) ? 'SI' : (preg_match('/^I|B/i', (string) $newVal) ? 'Binary' : null);
                    if (!$goodVal) {
                        self::PerlWarn("Invalid $param setting '{$newVal}'\n");
                        continue;
                    }
                    $this->OPTIONS[$param] = $goodVal;
                } else {
                    self::PerlWarn("Can't set $param to undef\n");
                }
            } elseif ($param === 'Plot') {
                // add to existing plot settings
                if ($oldVal !== null and $newVal !== null) $newVal = "$oldVal,$newVal";
                $this->OPTIONS[$param] = $newVal;
            } elseif ($param === 'KeepUTCTime' or $param === 'SystemTimeRes') {
                $this->OPTIONS[$param] = self::$static_vars[$param] = $newVal;
            } elseif (strtolower($param) === 'geodir') {
                Geolocation::$geoDir = $newVal;
            } else {
                if ($param === 'Escape') {
                    // set ESCAPE_PROC
                    if ($newVal !== null and $newVal === 'XML') {
                        self::RequireModule('Image::ExifTool::XMP');
                        $this->ESCAPE_PROC = 'Image\ExifTool\XMP::EscapeXML';
                    } elseif ($newVal !== null and $newVal === 'HTML') {
                        self::RequireModule('Image::ExifTool::HTML');
                        $this->ESCAPE_PROC = 'Image\ExifTool\HTML::EscapeHTML';
                    } else {
                        $this->ESCAPE_PROC = null;
                    }
                    // must forget saved values since they depend on Escape method
                    $this->BOTH = [];
                } elseif ($param === 'GlobalTimeShift') {
                    $this->GLOBAL_TIME_OFFSET = null;   // reset our calculated offset
                } elseif ($param === 'TimeZone' and $newVal !== null and strlen($newVal)) {
                    putenv("TZ=$newVal");
                    self::$localTZ = null;  // (POSIX::tzset)
                } elseif ($param === 'Validate') {
                    // load Validate module if Validate option enabled
                    if ($newVal) self::RequireModule('Image::ExifTool::Validate');
                }
                $this->OPTIONS[$param] = $newVal;
            }
        }
        return $oldVal;
    }

    //------------------------------------------------------------------------------
    // ClearOptions - set options to default values
    // Inputs: 0) ExifTool object reference
    public function ClearOptions(): void
    {
        $this->OPTIONS = [];    // clear all options

        // load default options
        foreach (Vars\ExifTool::$availableOptions as $opt) {
            $this->OPTIONS[$opt[0]] = $opt[1];
        }
        // (the default TextOut, \*STDOUT, isn't in the generated list)
        $this->OPTIONS['TextOut'] = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');

        // keep necessary member variables in sync with options
        $this->CUR_LANG = null;
        $this->ESCAPE_PROC = null;

        // load user-defined default options
        // (ponytail: %Image::ExifTool::UserDefined::Options comes from the config file, not supported)
    }

    //------------------------------------------------------------------------------
    // Extract meta information from image
    // Inputs: 0) ExifTool object reference
    //         1-N) Same as ImageInfo()
    // Returns: 1 if this was a valid image, 0 otherwise
    // Notes: pass an undefined value to avoid parsing arguments
    // Internal 'ReEntry' option allows this routine to be called recursively
    //   (PHP: $et->ExtractInfo(new ScalarRef($data), ['ReEntry' => 1]), or a file handle)
    public function ExtractInfo(...$args)
    {
        $prec = ini_set('precision', '15');
        try {
            $fast = $this->OPTIONS['FastScan'] ?: 0;
            $req = $this->REQ_TAG_LOOKUP ?? [];
            $reqAll = $this->OPTIONS['RequestAll'] ?: 0;
            $saveOptions = $reEntry = $rsize = $zid = $type = $startTime = $saveOrder = $isDir = null;
            $arg0 = $args[0] ?? null;
            $arg1 = $args[1] ?? null;

            // check for internal ReEntry option to allow recursive calls to ExtractInfo
            if (is_array($arg1) and !empty($arg1['ReEntry']) and
               ($arg0 instanceof ScalarRef or is_resource($arg0)))
            {
                // save necessary members for restoring later
                $reEntry = [
                    'RAF'       => $this->RAF,
                    'PROCESSED' => $this->PROCESSED,
                    'EXIF_DATA' => $this->EXIF_DATA,
                    'EXIF_POS'  => $this->EXIF_POS,
                    'FILE_TYPE' => $this->FILE_TYPE,
                ];
                $saveOrder = self::GetByteOrder();
                $this->RAF = new RandomAccess($arg0 instanceof ScalarRef ? Perl::str($arg0->value) : $arg0);
                $this->PROCESSED = [];
                $this->EXIF_DATA = null;
                $this->EXIF_POS = null;
            } else {
                if ($arg0 !== null or $this->OPTIONS['HtmlDump'] or !empty($req['validate'])) {
                    $saveOptions = $this->OPTIONS;  // save original options

                    // require duplicates for html dump
                    if ($this->OPTIONS['HtmlDump']) $this->Options('Duplicates', 1);
                    // enable Validate option if Validate tag is requested
                    if (!empty($req['validate'])) $this->Options('Validate', 1);
                    if ($arg0 !== null) {
                        // only initialize filename if called with arguments
                        $this->FILENAME = null;     // name of file (or '' if we didn't open it)
                        $this->RAF = null;          // RandomAccess object reference

                        $this->ParseArguments(...$args);    // initialize from our arguments
                    }
                }
                // ignore all tags and set ExtractEmbedded if outputting CSV directly
                if ($this->Options('PrintCSV')) {
                    $this->OPTIONS['IgnoreTags'] = ['all' => 1];
                    $this->Options('ExtractEmbedded', 1);
                }
                // initialize ExifTool object members
                $this->Init();
                $this->InExtract = 1;   // set flag indicating we are inside ExtractInfo

                $this->MAKER_NOTE_FIXUP = null;     // fixup information for extracted maker notes
                $this->MAKER_NOTE_BYTE_ORDER = null;

                // return our version number
                $this->FoundTag('ExifToolVersion', self::$VERSION . self::$RELEASE);
                if (!empty($req['now']) or $reqAll) $this->FoundTag('Now', $this->TimeNow());
                if (!empty($req['newguid']) or $reqAll) $this->FoundTag('NewGUID', self::NewGUID());
                if (!empty($req['newuuid']) or $reqAll) $this->FoundTag('NewUUID', self::NewUUID());
                // generate sequence number if necessary
                if (!empty($req['filesequence']) or $reqAll) $this->FoundTag('FileSequence', $this->FILE_SEQUENCE);

                if (!empty($req['processingtime']) or $reqAll) {
                    $startTime = microtime(true);
                }

                // create Hash object if ImageDataHash is requested
                // (PHP: $$self{ImageDataHash} is a HashContext, add data with hash_update())
                if (!empty($req['imagedatahash']) and !$this->ImageDataHash) {
                    $imageHashType = $this->Options('ImageHashType');
                    if (preg_match('/^SHA(256|512)$/i', (string) $imageHashType, $m)) {
                        $this->ImageDataHash = hash_init('sha' . $m[1]);
                    } else {
                        $this->ImageDataHash = hash_init('md5');
                    }
                }
                ++$this->FILE_SEQUENCE;     // count files read
            }

            $filename = $this->FILENAME;    // image file name ('' if already open)
            $raf = $this->RAF;              // RandomAccess object

            $realname = $filename;
            if (!$raf) {
                // save file name
                if ($filename !== null and $filename !== '') {
                    if ($filename !== '-') {
                        // extract file name from pipe if necessary
                        if (preg_match('/\|$/', $realname)) $realname = preg_replace('/^.*?"(.*?)".*/s', '$1', $realname);
                        [$dir, $name] = self::SplitFileName($realname);
                        $this->FoundTag('FileName', $name);
                        if (!empty($req['basename']) or
                           ($reqAll and empty($this->EXCL_TAG_LOOKUP['basename'])))
                        {
                            $this->FoundTag('BaseName', preg_match('/(.*)\./', $name, $m) ? $m[1] : $name);
                        }
                        if ($dir !== null and strlen($dir)) $this->FoundTag('Directory', $dir);
                        if (!empty($req['filepath']) or
                           ($reqAll and empty($this->EXCL_TAG_LOOKUP['filepath'])))
                        {
                            $path = realpath($filename);
                            if ($path !== false) {
                                $this->FoundTag('FilePath', $path);
                            }
                        }
                        // get size of resource fork on Mac OS
                        if (PHP_OS_FAMILY === 'Darwin' and !$this->IN_RESOURCE) {
                            $rsize = @filesize("$filename/..namedfork/rsrc") ?: null;
                        }
                        // (Zone.Identifier is a Windows thing)
                    }
                    // open the file
                    $fh = null;
                    if ($this->Open($fh, $filename)) {
                        // create random access file object
                        $raf = new RandomAccess($fh);
                        // patch to force pipe to be buffered because seek returns success
                        // in Windows cmd shell pipe even though it really failed
                        if ($filename === '-' or preg_match('/\|$/', $filename)) $raf->TESTED = -1;
                        $this->RAF = $raf;
                    } elseif ($this->IsDirectory($filename)) {
                        $isDir = 1;
                    } else {
                        $this->Error('Error opening file');
                        // continue to process alt files if necessary
                        // (note: ALT_EXIFTOOL is always a hash ref, so this is always done)
                        if ($this->ALT_EXIFTOOL !== null) $this->DoneExtract();
                    }
                } else {
                    $this->Error('No file specified');
                }
            }

            while ($raf or $isDir) {
                $stat = null;
                $plainFile = null;
                if ($reEntry) {
                    // we already set these tags
                } elseif (!$raf) {
                    $stat = @stat($filename) ?: null;
                } elseif (!$raf->FILE_PT) {
                    // get file size from image in memory
                    $this->FoundTag('FileSize', strlen($raf->BUFF_PT));
                } else {
                    $stat = @fstat($raf->FILE_PT) ?: null;
                    if ($stat and ($stat[2] & 0170000) == 0100000) {
                        // get file tags if this is a plain file
                        $plainFile = 1;
                    } elseif ($stat and ($stat[2] & 0170000) == 0010000) {
                        $stat[7] = null;    // (pipe buffer size isn't useful)
                    }
                }
                $fileSize = $stat[7] ?? null;
                if (isset($stat[7])) $this->FoundTag('FileSize', $stat[7]);
                if ($rsize) $this->FoundTag('ResourceForkSize', $rsize);
                if ($zid) $this->FoundTag('ZoneIdentifier', 'Exists');
                if (isset($stat[9])) $this->FoundTag('FileModifyDate', $stat[9]);
                if (isset($stat[8])) $this->FoundTag('FileAccessDate', $stat[8]);
                $cTag = PHP_OS_FAMILY === 'Windows' ? 'FileCreateDate' : 'FileInodeChangeDate';
                if (isset($stat[10])) $this->FoundTag($cTag, $stat[10]);
                // (File::StatX FileCreateDate on Linux: not available)
                if (isset($stat[2])) $this->FoundTag('FilePermissions', $stat[2]);
                // extract more system info if SystemTags option is set
                if ($stat) {
                    $sys = $this->OPTIONS['SystemTags'] ?: ($reqAll and $this->OPTIONS['SystemTags'] === null);
                    if ($sys or !empty($req['fileattributes'])) {
                        $attr = [$stat[2] & 0xf000, $stat[2] & 0x0e00];
                        $this->FoundTag('FileAttributes', implode(' ', $attr));
                    }
                    if ($sys or !empty($req['filedevicenumber'])) $this->FoundTag('FileDeviceNumber', $stat[0]);
                    if ($sys or !empty($req['fileinodenumber']))  $this->FoundTag('FileInodeNumber', $stat[1]);
                    if ($sys or !empty($req['filehardlinks']))    $this->FoundTag('FileHardLinks', $stat[3]);
                    if ($sys or !empty($req['fileuserid']))       $this->FoundTag('FileUserID', $stat[4]);
                    if ($sys or !empty($req['filegroupid']))      $this->FoundTag('FileGroupID', $stat[5]);
                    if ($sys or !empty($req['filedeviceid']))     $this->FoundTag('FileDeviceID', $stat[6]);
                    if ($sys or !empty($req['fileblocksize']))    $this->FoundTag('FileBlockSize', $stat[11]);
                    if ($sys or !empty($req['fileblockcount']))   $this->FoundTag('FileBlockCount', $stat[12]);
                }
                // extract MDItem tags if requested (only on plain files)
                if (PHP_OS_FAMILY === 'Darwin' and $filename !== null and $filename !== '' and $fileSize !== null) {
                    $reqMacOS = ($reqAll > 1 or !empty($req['macos:']));
                    $crDate = ($reqMacOS or !empty($req['filecreatedate']));
                    $mdItem = ($reqMacOS or $this->OPTIONS['MDItemTags'] or preg_grep('/^mditem/', array_keys($req)));
                    $xattr  = ($reqMacOS or $this->OPTIONS['XAttrTags'] or preg_grep('/^xattr/', array_keys($req)));
                    if (($crDate or $mdItem or $xattr) and self::RequireModule('Image::ExifTool::MacOS')) {
                        if ($crDate and method_exists(MacOS::class, 'GetFileCreateDate')) MacOS::GetFileCreateDate($this, $filename);
                        if ($mdItem and $plainFile and method_exists(MacOS::class, 'ExtractMDItemTags')) MacOS::ExtractMDItemTags($this, $filename);
                        if ($xattr and method_exists(MacOS::class, 'ExtractXAttrTags')) MacOS::ExtractXAttrTags($this, $filename);
                    }
                }
                // do whatever else we can with directories, then return
                if ($isDir or (isset($stat[2]) and ($stat[2] & 0170000) == 0040000)) {
                    $this->FoundTag('FileType', 'DIR');
                    $this->FoundTag('FileTypeExtension', '');
                    $this->DoneExtract();
                    if ($raf) $raf->Close();
                    if ($saveOptions !== null) $this->OPTIONS = $saveOptions;
                    if (!$reEntry) $this->InExtract = null;
                    return 1;
                }
                // get list of file types to check
                $tiffType = null;
                $noMagic = [];
                $recognizedExt = null;
                $ext = $this->FILE_EXT = self::GetFileExtension($realname);
                // set $recognizedExt if this file type is recognized by extension only
                $moduleName = Vars\ExifTool::$moduleName;
                if ($ext !== null and !isset(Vars\ExifTool::$magicNumber[$ext]) and
                    isset($moduleName[$ext]) and !$moduleName[$ext])
                {
                    $recognizedExt = $ext;
                }
                $fileTypeList = self::GetFileTypeList($realname);
                if ($fast >= 4) {
                    if ($fileTypeList) {
                        $type = array_shift($fileTypeList);
                        $this->SetFileType($this->FILE_TYPE = $type);
                    } else {
                        $this->Error('Unknown file type');
                    }
                    $this->DoneExtract();
                    break;  // don't read the file
                }
                if ($fileTypeList) {
                    // add remaining types to end of list so we test them all
                    $pat = implode('|', $fileTypeList);
                    foreach (Vars\ExifTool::$fileTypes as $t) {
                        if (!preg_match("/^($pat)$/", $t)) $fileTypeList[] = $t;
                    }
                    $tiffType = $this->FILE_EXT;
                    if ($fast != 3) {
                        $noMagic['MXF'] = 1;    // don't do magic number test on MXF or DV files
                        $noMagic['DV'] = 1;
                    }
                } else {
                    // scan through all recognized file types
                    $fileTypeList = Vars\ExifTool::$fileTypes;
                    $tiffType = 'TIFF';
                }
                $fileTypeList[] = '';   // end of list marker
                // initialize the input file for seeking in binary data
                $raf->BinMode();        // set binary mode before we start reading
                $pos = $raf->Tell();    // get file position so we can rewind
                // loop through list of file types to test
                $buff = null;
                $err = null;
                // read start of file for testing
                if ($raf->Read($buff, Vars\ExifTool::$testLen)) {
                    if (!$raf->Seek($pos, 0)) $err = 'Error seeking in file';
                } else {
                    $err = $raf->ERROR;
                    $buff = '';
                }
                $dirInfo = ['RAF' => $raf, 'Base' => $pos, 'TestBuff' => $buff];
                while (!$err) {
                    $unkHeader = $magicOK = null;
                    $type = array_shift($fileTypeList);
                    if ($type) {
                        $magic = Vars\ExifTool::$magicNumber[$type] ?? null;
                        if ($magic) {
                            // do quick test for this file type to avoid loading module unnecessarily
                            $magicOK = preg_match(self::Rx('^' . $magic, 's'), $buff);
                            if (!$magicOK and empty($noMagic[$type])) continue;
                        } else {
                            // keep checking for other types if we recognize this file only by extension
                            if (isset($moduleName[$type]) and !$moduleName[$type]) continue;
                            if ($fast > 2) continue;    // keep checking if we aren't processing the file
                        }
                        if (!empty(Vars\ExifTool::$weakMagic[$type]) and $recognizedExt !== null) continue;
                    } elseif ($type === null) {
                        break;
                    } elseif ($recognizedExt) {
                        $type = $recognizedExt; // set type from recognized file extension only
                    } else {
                        // last ditch effort to scan past unknown header for JPEG/TIFF
                        if (!preg_match('/(\xff\xd8\xff|MM\0\x2a|II\x2a\0)/', $buff, $m, PREG_OFFSET_CAPTURE)) continue;
                        $type = ($m[1][0] === "\xff\xd8\xff") ? 'JPEG' : 'TIFF';
                        $skip = $m[1][1];
                        $dirInfo['Base'] = $pos + $skip;
                        if (!$raf->Seek($pos + $skip, 0)) {
                            $err = 'Error seeking in file';
                            break;
                        }
                        $this->Warn("Processing $type-like data after unknown $skip-byte header");
                        if (!$this->DOC_NUM) $unkHeader = 1;
                    }
                    // save file type in member variable
                    $this->FILE_TYPE = $type;
                    $dirInfo['Parent'] = ($type === 'TIFF') ? $tiffType : $type;
                    // don't process the file when FastScan > 2
                    if ($fast > 2 and empty(Vars\ExifTool::$processType[$type])) {
                        if (!(!empty(Vars\ExifTool::$weakMagic[$type]) and (!$ext or $ext !== $type))) {
                            $this->SetFileType($dirInfo['Parent']);
                        }
                        break;
                    }
                    $module = $moduleName[$type] ?? null;
                    if ($module === null) $module = $type;
                    $func = "Process$type";

                    // load module if necessary
                    if ($module) {
                        self::RequireModule("Image::ExifTool::$module");
                        $func = ['Image\\ExifTool\\' . self::ClassName($module), $func];
                    } elseif ((string) $module === '0') {
                        $this->SetFileType();
                        $this->Warn('Unsupported file type');
                        break;
                    }
                    // (PHP: process function not ported yet: try the next type if this one
                    //  was only a guess, otherwise the format is known but unsupported)
                    if (is_array($func) ? !is_callable($func) : !method_exists($this, $func)) {
                        if (!$magicOK or !empty(Vars\ExifTool::$weakMagic[$type]) or $buff === '') {
                            if (!$raf->Seek($pos, 0)) $err = 'Error seeking in file';
                            continue;
                        }
                        $this->SetFileType();
                        $this->Warn('Unsupported file type');
                        break;
                    }
                    $this->PATH[] = $type;      // save file type in metadata PATH

                    // process the file
                    $result = is_array($func) ? $func($this, $dirInfo) : $this->$func($dirInfo);

                    array_pop($this->PATH);

                    if ($result) {  // all done if successful
                        if ($unkHeader) {
                            $this->DeleteTag('FileType');
                            $this->DeleteTag('FileTypeExtension');
                            $this->DeleteTag('MIMEType');
                            $this->VPrint(0, "Reset file type due to unknown header\n");
                        }
                        break;
                    }
                    // seek back to try again from the same position in the file
                    if (!$raf->Seek($pos, 0)) $err = 'Error seeking in file';
                }
                if (!$err and $type === null and !$this->DOC_NUM) {
                    // if we were given a single image with a known type there
                    // must be a format error since we couldn't read it, otherwise
                    // it is likely we don't support images of this type
                    $fileType = self::GetFileType($realname) ?: '';
                    if (!strlen($buff)) {
                        $err = 'File is empty';
                    } else {
                        $ch = $buff[0];
                        if (strlen($buff) < 16 or strspn($buff, $ch) !== strlen($buff)) {
                            if ($fileType === 'RAW') {
                                $err = 'Unsupported RAW file type';
                            } elseif ($fileType) {
                                $err = 'File format error';
                            } else {
                                $err = 'Unknown file type';
                            }
                        } else {
                            // provide some insight into the content of some corrupted files
                            if ($this->OPTIONS['FastScan']) {
                                $err = 'File header is all';
                            } else {
                                $num = 0;
                                for (;;) {
                                    if (!$raf->Read($buff, 65536)) {
                                        $num = null;
                                        break;
                                    }
                                    $n = strspn($buff, $ch);
                                    if ($n < strlen($buff)) {
                                        $num += $n;
                                        break;
                                    }
                                    $num += strlen($buff);
                                }
                                if ($num) {
                                    $err = 'First ' . self::ConvertFileSize($num) . ' of file is';
                                } else {
                                    $err = 'Entire file is';
                                }
                            }
                            if ($ch === "\0") {
                                $err .= ' binary zeros';
                            } elseif ($ch === ' ') {
                                $err .= ' ASCII spaces';
                            } elseif (preg_match('/[a-zA-Z0-9]/', $ch)) {
                                $err .= " ASCII '{$ch}' characters";
                            } else {
                                $err .= Perl::sprintf(" binary 0x%.2x's", ord($ch));
                            }
                        }
                    }
                }
                if ($err) {
                    $this->Error($err);
                } elseif ($this->Options('ScanForXMP') and ($type === null or
                    (!$fast and !$this->FoundXMP)))
                {
                    // scan for XMP
                    $raf->Seek($pos, 0);
                    if (self::RequireModule('Image::ExifTool::XMP') and method_exists(XMP::class, 'ScanForXMP')) {
                        if (XMP::ScanForXMP($this, $raf)) $type = '';
                    }
                }
                // extract binary EXIF data block only if requested
                if ($this->EXIF_DATA !== null and strlen($this->EXIF_DATA) > 16 and
                    (!empty($req['exif']) or
                    // (not extracted normally, so check TAGS_FROM_FILE)
                    ($this->TAGS_FROM_FILE and empty($this->EXCL_TAG_LOOKUP['exif']))))
                {
                    $this->FoundTag('EXIF', $this->EXIF_DATA);
                }
                if (!$reEntry) {
                    $this->PATH = [];   // reset PATH
                    $this->DoneExtract();
                    // (HTML dump not ported)
                }
                if ($filename) {
                    $raf->Close();  // close the file if we opened it
                    // process the resource fork as an embedded file on Mac filesystems
                    if ($rsize and $this->OPTIONS['ExtractEmbedded']) {
                        $rfh = null;
                        if ($this->Open($rfh, "$filename/..namedfork/rsrc")) {
                            $this->DOC_NUM = $this->DOC_COUNT + 1;
                            $this->IN_RESOURCE = 1;
                            $this->ExtractInfo($rfh, ['ReEntry' => 1]);
                            if (is_resource($rfh)) fclose($rfh);
                            $this->IN_RESOURCE = null;
                        } else {
                            $this->Warn('Error opening resource fork');
                        }
                    }
                }
                break;  // (loop was a cheap "goto")
            }

            // Note: This should be the only tag generated after BuildCompositeTags,
            // and as such it can't be used in user-defined Composite tags
            if ($startTime !== null) $this->FoundTag('ProcessingTime', microtime(true) - $startTime);

            // add numbers to warnings with multiple occurrences
            if ($this->WAS_WARNED) {
                $tag = 'Warning';
                for ($i = 1; !empty($this->VALUE[$tag]); ++$i) {
                    $n = $this->WAS_WARNED[$this->VALUE[$tag]] ?? null;
                    if ($n and $n > 1) $this->VALUE[$tag] .= " [x$n]";
                    $tag = "Warning ($i)";
                }
            }
            // restore original options
            if ($saveOptions !== null) $this->OPTIONS = $saveOptions;

            if ($reEntry) {
                // restore necessary members when exiting re-entrant code
                foreach ($reEntry as $key => $val) $this->$key = $val;
                self::SetByteOrder($saveOrder);
            } else {
                // call cleanup routines if necessary
                if ($this->Cleanup) {
                    foreach ($this->Cleanup as $sub) $sub($this);
                    $this->Cleanup = null;
                }
                $this->InExtract = null;
            }

            // ($type may be undef without an Error when processing sub-documents)
            if ($type === null or array_key_exists('Error', $this->VALUE)) return 0;
            return 1;
        } finally {
            ini_set('precision', $prec);
        }
    }

    //------------------------------------------------------------------------------
    // Get hash of extracted meta information
    // Inputs: 0) ExifTool object reference
    //         1-N) options hash reference, tag list reference or tag names
    // Returns: Reference to information hash
    // Notes: - pass an undefined value to avoid parsing arguments
    //        - If groups are specified, first groups take precedence if duplicate
    //          tags found but Duplicates option not set.
    //        - tag names may end in '#' to extract ValueConv value
    public function GetInfo(...$args): array
    {
        $prec = ini_set('precision', '15');
        try {
            $saveOptions = $savedMembers = null;

            // save necessary members to allow GetInfo to be called from within ExtractInfo
            if ($this->InExtract) {
                $savedMembers = [$this->REQUESTED_TAGS, $this->REQ_TAG_LOOKUP, $this->IO_TAG_LIST];
            }
            if (!($args and $args[0] === null)) {
                $saveOptions = $this->OPTIONS; // save original options
                // must set FILENAME so it isn't parsed from the arguments
                if ($this->FILENAME === null) $this->FILENAME = '';
                $this->ParseArguments(...$args);
            }

            // get reference to list of tags for which we will return info
            [$rtnTags, $byValue, $wildTags] = $this->SetFoundTagsList();

            // build hash of tag information
            $info = [];
            $ignored = [];
            $conv = $this->OPTIONS['PrintConv'] ? 'PrintConv' : 'ValueConv';
            foreach ($rtnTags as $t) {
                $val = $this->DoGetValue($t, $conv, null, false);
                if ($val === null) {
                    $ignored[$t] = 1;
                    continue;
                }
                $info[$t] = $val;
            }

            // override specified tags with ValueConv value if necessary
            if ($byValue) {
                // first determine the number of times each non-ValueConv value is used
                $nonVal = [];
                foreach ($rtnTags as $t) $nonVal[$t] = ($nonVal[$t] ?? 0) + 1;
                foreach ($byValue as $i) --$nonVal[$rtnTags[$i]];
                // loop through ValueConv tags, updating tag keys and returned values
                foreach ($byValue as $i) {
                    $tag = $rtnTags[$i];
                    $val = $this->DoGetValue($tag, 'ValueConv', null, false);
                    if ($val === null) continue;
                    // generate a new tag key like "Tag #" or "Tag #(1)"
                    $vtag = preg_replace('/( |$)/', ' #', $tag, 1);
                    if (!isset($this->VALUE[$vtag])) {
                        $this->VALUE[$vtag] = $this->VALUE[$tag] ?? null;
                        $this->TAG_INFO[$vtag] = $this->TAG_INFO[$tag] ?? null;
                        $this->TAG_EXTRA[$vtag] = $this->TAG_EXTRA[$tag] ?? null;
                        $this->FILE_ORDER[$vtag] = $this->FILE_ORDER[$tag] ?? null;
                        // remove existing PrintConv entry unless we are using it too
                        if (!$nonVal[$tag]) unset($info[$tag]);
                    }
                    $rtnTags[$i] = $vtag;   // store ValueConv value with new tag key
                    $info[$vtag] = $val;    // return ValueConv value
                }
            }

            // remove ignored tags from the list
            $reqTags = $this->REQUESTED_TAGS ?: [];
            if ($ignored) {
                if (!$reqTags) {
                    $goodTags = [];
                    foreach ($rtnTags as $t) {
                        if (empty($ignored[$t])) $goodTags[] = $t;
                    }
                    $rtnTags = $this->FOUND_TAGS = $goodTags;
                } elseif ($wildTags) {
                    // only remove tags specified by wildcard
                    $goodTags = [];
                    $i = 0;
                    foreach ($rtnTags as $t) {
                        if ($wildTags and $i == $wildTags[0]) {
                            array_shift($wildTags);
                            if (empty($ignored[$t])) $goodTags[] = $t;
                        } else {
                            $goodTags[] = $t;
                        }
                        ++$i;
                    }
                    $rtnTags = $this->FOUND_TAGS = $goodTags;
                }
            }

            // return sorted tag list if provided with a list reference
            if ($this->IO_TAG_LIST !== null) {
                // use file order by default if no tags specified
                // (no such thing as 'Input' order in this case)
                $sort = $this->OPTIONS['Sort'];
                if (!($reqTags or ($sort and $sort !== 'Input'))) $sort = 'File';
                // return tags in specified sort order
                $this->IO_TAG_LIST = $this->GetTagList($rtnTags, $sort, $this->OPTIONS['Sort2']);
            }

            // restore original options and member variables
            if ($saveOptions !== null) $this->OPTIONS = $saveOptions;
            if ($savedMembers !== null) {
                [$this->REQUESTED_TAGS, $this->REQ_TAG_LOOKUP, $this->IO_TAG_LIST] = $savedMembers;
            }

            return $info;
        } finally {
            ini_set('precision', $prec);
        }
    }

    //------------------------------------------------------------------------------
    // Inputs: 0) ExifTool object reference
    //         1) [optional] reference to info hash or tag list ref (default is found tags)
    //         2) [optional] sort order ('File', 'Input', ...)
    //         3) [optional] secondary sort order
    // Returns: List of tags in specified order
    public function GetTagList($info = null, $sort = null, $sort2 = null)
    {
        $foundTags = null;
        if (is_array($info) and !array_is_list($info)) {
            $foundTags = array_map('strval', array_keys($info));
        } elseif (is_array($info)) {
            $foundTags = $info;
        }
        $fileOrder = &$this->FILE_ORDER;

        if ($foundTags !== null) {
            // make sure a FILE_ORDER entry exists for all tags
            // (note: already generated bogus entries for FOUND_TAGS case below)
            foreach ($foundTags as $t) {
                if (isset($fileOrder[$t])) continue;
                $fileOrder[$t] = 999;
            }
        } else {
            if ($info and !$sort) $sort = $info;
            $foundTags = $this->FOUND_TAGS ?? $this->SetFoundTags();
            if ($foundTags === null) return null;
        }
        if (!$sort) $sort = $this->OPTIONS['Sort'];

        // return original list if no sort order specified
        if (!($sort and $sort !== 'Input')) return $foundTags;

        $fo = $fileOrder;   // (for the sort routines)
        if ($sort === 'Tag' or $sort === 'Alpha') {
            sort($foundTags, SORT_STRING);
            return $foundTags;
        } elseif (preg_match('/^Group(\d*(:\d+)*)/', $sort, $m)) {
            $family = $m[1] ?: 0;
            // want to maintain a basic file order with the groups
            // ordered in the way they appear in the file
            $groupCount = [];
            $groupOrder = [];
            $numGroups = 0;
            $byFile = $foundTags;
            usort($byFile, static fn ($a, $b) => ($fo[$a] ?? 0) <=> ($fo[$b] ?? 0));
            foreach ($byFile as $tag) {
                $group = (string) $this->GetGroup($tag, $family);
                $num = $groupCount[$group] ?? null;
                if (!$num) $num = $groupCount[$group] = ++$numGroups;
                $groupOrder[$tag] = $num;
            }
            if (!$sort2) $sort2 = $this->OPTIONS['Sort2'];
            if ($sort2) {
                if ($sort2 === 'Tag' or $sort2 === 'Alpha') {
                    usort($foundTags, static fn ($a, $b) => ($groupOrder[$a] <=> $groupOrder[$b]) ?: strcmp($a, $b));
                    return $foundTags;
                } elseif ($sort2 === 'Descr') {
                    $desc = $this->GetDescriptions($foundTags);
                    usort($foundTags, static fn ($a, $b) => ($groupOrder[$a] <=> $groupOrder[$b]) ?:
                                                            strcmp($desc[$a], $desc[$b]));
                    return $foundTags;
                }
            }
            usort($foundTags, static fn ($a, $b) => ($groupOrder[$a] <=> $groupOrder[$b]) ?:
                                                    (($fo[$a] ?? 0) <=> ($fo[$b] ?? 0)));
            return $foundTags;
        } elseif ($sort === 'Descr') {
            $desc = $this->GetDescriptions($foundTags);
            usort($foundTags, static fn ($a, $b) => strcmp($desc[$a], $desc[$b]) ?: strcmp($a, $b));
            return $foundTags;
        } else {
            usort($foundTags, static fn ($a, $b) => ($fo[$a] ?? 0) <=> ($fo[$b] ?? 0));
            return $foundTags;
        }
    }

    //------------------------------------------------------------------------------
    // Get list of found tags in specified sort order
    // Inputs: 0) ExifTool object reference, 1) sort order ('File', 'Input', ...)
    //         2) secondary sort order
    // Returns: List of tag keys in specified order
    // Notes: If not specified, sort order is taken from OPTIONS
    public function GetFoundTags($sort = null, $sort2 = null)
    {
        $foundTags = $this->FOUND_TAGS ?? $this->SetFoundTags();
        if ($foundTags === null) return null;
        return $this->GetTagList($foundTags, $sort, $sort2);
    }

    //------------------------------------------------------------------------------
    // Get list of requested tags
    // Inputs: 0) ExifTool object reference
    // Returns: List of requested tag keys
    public function GetRequestedTags(): array
    {
        return $this->REQUESTED_TAGS ?? [];
    }

    //------------------------------------------------------------------------------
    // Get tag value
    // Inputs: 0) ExifTool object reference
    //         1) tag key or tag name with optional group names (case insensitive)
    //            (or flattened tagInfo for getting field values, not part of public API)
    //         2) [optional] Value type: PrintConv, ValueConv, Both, Raw, Bin or Rational, the
    //            default is PrintConv or ValueConv, depending on the PrintConv option setting
    //         3) raw field value (not part of public API)
    // Returns: Scalar context: tag value or undefined
    //          List context: list of values or empty list (PHP: GetValueList)
    public function GetValue($tag, $type = null, $rawFieldValue = null)
    {
        $prec = ini_set('precision', '15');
        try {
            return $this->DoGetValue($tag, $type, $rawFieldValue, false);
        } finally {
            ini_set('precision', $prec);
        }
    }

    /** GetValue in list context (Perl wantarray): returns a list (empty if no value; [ValueConv, PrintConv] for 'Both') */
    public function GetValueList($tag, $type = null, $rawFieldValue = null): array
    {
        $prec = ini_set('precision', '15');
        try {
            return $this->DoGetValue($tag, $type, $rawFieldValue, true);
        } finally {
            ini_set('precision', $prec);
        }
    }

    private function DoGetValue($tag, $type, $rawFieldValue, bool $wantarray)
    {
        $convTypes = [];
        $tagInfo = $valueConv = $both = null;
        $rawValue = $this->VALUE;

        if (!$tag instanceof TagInfo) {
            $tag = Perl::str($tag);
            // get specific tag key if tag has a group name
            if (preg_match('/^(.*):(.+)/', $tag, $m)) {
                [$gp, $tg] = [$m[1], $m[2]];
                $keys = [];
                if (!isset($rawValue[$tg])) {
                    foreach (array_keys($rawValue) as $key) {
                        if (@preg_match(self::Rx("^$tg\$", 'i'), (string) $key)) {
                            $tg = (string) $key;
                            break;
                        }
                    }
                }
                // build list of tag keys in the order of priority (no index
                // is top priority, otherwise higher index is higher priority)
                for ($key = $tg, $i = $this->DUPL_TAG[$tg] ?? 0; ; --$i) {
                    if (isset($rawValue[$key])) $keys[] = $key;
                    if ($i <= 0) break;
                    $key = "$tg ($i)";
                }
                if ($keys) {
                    $key = $this->GroupMatches($gp, $keys);
                    if ($key) $tag = $key;
                }
            } elseif (!isset($rawValue[$tag]) and !str_contains($tag, ' ')) {
                // fix case of tag name
                foreach (array_keys($rawValue) as $key) {
                    if (@preg_match(self::Rx("^$tag\$", 'i'), (string) $key)) {
                        $tag = (string) $key;
                        break;
                    }
                }
            }
        }
        // figure out what conversions to do
        if ($type) {
            if ($type === 'Rational') {
                $r = $tag instanceof TagInfo ? null : ($this->TAG_EXTRA[$tag]['Rational'] ?? null);
                return $wantarray ? [$r] : $r;
            }
            if ($type === 'Bin') {
                $r = $tag instanceof TagInfo ? null : ($this->TAG_EXTRA[$tag]['BinVal'] ?? null);
                return $wantarray ? [$r] : $r;
            }
        } else {
            $type = $this->OPTIONS['PrintConv'] ? 'PrintConv' : 'ValueConv';
        }

        // start with the raw value
        $value = $tag instanceof TagInfo ? null : ($rawValue[$tag] ?? null);
        if ($value === null) {
            if (!$tag instanceof TagInfo) return $wantarray ? [] : null;
            // get the value of a structure field
            $tagInfo = $tag;
            $tag = $tagInfo->Name;
            $value = $rawFieldValue;
            // (note: type "Both" is not allowed for structure fields)
            if ($type !== 'Raw') {
                $convTypes[] = 'ValueConv';
                if ($type !== 'ValueConv') $convTypes[] = 'PrintConv';
            }
        } else {
            $tagInfo = $this->TAG_INFO[$tag] ?? new TagInfo();
            if ($tagInfo->Struct and (is_array($value) or is_object($value))) {
                // must load XMPStruct.pl just in case (should already be loaded if
                // a structure was extracted, but we could also arrive here if a simple
                // list of values was stored incorrectly in a Struct tag)
                self::RequireModule('Image::ExifTool::XMP');
                if (method_exists(XMP::class, 'ConvertStruct')) {
                    // convert strucure field values
                    if ($type !== 'Both') {
                        // (note: ConvertStruct handles the filtering and escaping too if necessary)
                        $r = XMP::ConvertStruct($this, $tagInfo, $value, $type);
                        return $wantarray ? [$r] : $r;
                    }
                    $valueConv = XMP::ConvertStruct($this, $tagInfo, $value, 'ValueConv');
                    $value = XMP::ConvertStruct($this, $tagInfo, $value, 'PrintConv');
                    // (must not save these in $$self{BOTH} because the values may have been escaped)
                    return $wantarray ? [$valueConv, $value] : $value;
                }
            }
            if ($type !== 'Raw') {
                // use values we calculated already if we stored them
                $both = $this->BOTH[$tag] ?? null;
                if ($both) {
                    if ($type === 'PrintConv') {
                        $value = $both[1];
                    } elseif ($type === 'ValueConv') {
                        $value = $both[0];
                        if ($value === null) $value = $both[1];
                    } else {
                        [$valueConv, $value] = $both;
                    }
                } else {
                    $convTypes[] = 'ValueConv';
                    if ($type !== 'ValueConv') $convTypes[] = 'PrintConv';
                }
            }
        }

        // do the conversions
        // (Perl @val, @prt and @raw: values of the tags used by a Composite tag)
        $valA = $prtA = $rawA = [];
        foreach ($convTypes as $convType) {
            // don't convert a scalar reference or structure
            if (self::IsScalarRef($value) and !$tagInfo->ConvertBinary) break;
            $conv = $tagInfo->$convType;
            if ($conv === null) {
                if ($convType === 'ValueConv') {
                    if (!$tagInfo->Binary) continue;
                    $conv = self::BinaryConv();  // return scalar reference for binary values
                } else {
                    // use PRINT_CONV from tag table if PrintConv doesn't exist
                    $table = $tagInfo->Table;
                    $conv = $table ? $table['PRINT_CONV'] : null;
                    if ($conv === null) continue;
                    if (property_exists($tagInfo, $convType)) continue;
                }
            }
            // save old ValueConv value if we want Both
            if ($type === 'Both' and $convType === 'PrintConv') $valueConv = $value;
            $i = $val = $vals = $convList = null;
            $values = [];
            // split into list if conversion is an array
            if ($conv instanceof ConvList) {
                $convList = $conv->list;
                $conv = $convList[0] ?? null;
                $valList = (is_array($value) and array_is_list($value)) ? $value : Perl::split(' ', $value);
                // reorganize list if specified (Note: The writer currently doesn't
                // relist values, so they may be grouped but the order must not change)
                $relist = $tagInfo->Relist;
                if ($relist) {
                    $newList = [];
                    foreach ($relist as $oldIndex) {
                        $newVal = null;
                        if (is_array($oldIndex)) {
                            $join = [];
                            foreach ($oldIndex as $idx) {
                                if (isset($valList[$idx])) $join[] = $valList[$idx];
                            }
                            if ($join) $newVal = self::JoinList(' ', $join);
                        } else {
                            $newVal = $valList[$oldIndex] ?? null;
                        }
                        if ($newVal !== null) $newList[] = $newVal;
                    }
                    $value = $newList;
                } else {
                    $value = $valList;
                }
                if (!$value) return $wantarray ? [] : null;
            }
            // initialize array so we can iterate over values in list
            if (is_array($value) and array_is_list($value)) {
                if ($tagInfo->RawJoin !== null) {
                    $val = self::JoinList(' ', $value);
                } else {
                    $i = 0;
                    $vals = $value;
                    $val = $vals[0] ?? null;
                }
            } else {
                $val = $value;
            }
            // loop through all values in list
            for (;;) {
                if ($conv !== null) {
                    // get values of required tags if this is a Composite tag
                    if ($val instanceof \ArrayObject and !$valA) {
                        // disable escape of source values so we don't double escape them
                        $oldEscape = $this->ESCAPE_PROC;
                        $this->ESCAPE_PROC = null;
                        // temporarily delete filter so it isn't applied to the Require'd values
                        $oldFilter = $this->OPTIONS['Filter'] ?? null;
                        unset($this->OPTIONS['Filter']);
                        foreach ($val as $k => $key) {
                            if ($key === null) continue;
                            $rawA[$k] = $this->VALUE[$key] ?? null;
                            $both2 = $this->DoGetValue($key, 'Both', null, true);
                            $valA[$k] = $both2[0] ?? null;
                            $prtA[$k] = $both2[1] ?? null;
                            if ($valA[$k] !== null or empty($tagInfo->Require[$k])) continue;
                            $this->OPTIONS['Filter'] = $oldFilter;
                            $this->ESCAPE_PROC = $oldEscape;
                            return $wantarray ? [] : null;
                        }
                        $this->OPTIONS['Filter'] = $oldFilter;
                        $this->ESCAPE_PROC = $oldEscape;
                        // set $val to $val[0], or \@val for a CODE ref conversion
                        $val = self::IsCodeRef($conv) ? $valA : ($valA[0] ?? null);
                    }
                    if (is_array($conv)) {
                        // look up converted value in hash
                        $value = $conv[self::HashKey($val)] ?? null;
                        if ($value === null) {
                            if (isset($conv['BITMASK'])) {
                                $value = self::DecodeBits($val, $conv['BITMASK'], $tagInfo->BitsPerWord);
                            } else {
                                // use alternate conversion routine if available
                                if (isset($conv['OTHER'])) {
                                    self::$evalWarning = null;
                                    self::EvalBegin();
                                    try {
                                        $other = $conv['OTHER'];
                                        $value = $other($val, null, $conv);
                                    } catch (\Throwable $e) {
                                        $value = null;
                                        self::$evalWarning = self::EvalError($e);
                                    } finally {
                                        self::EvalEnd();
                                    }
                                    if (self::$evalWarning) $this->Warn("$convType $tag: " . self::CleanWarning());
                                }
                                if ($value === null) {
                                    if ($tagInfo->PrintHex and $val !== null and self::IsInt($val) and
                                        $convType === 'PrintConv')
                                    {
                                        $value = Perl::sprintf('Unknown (0x%x)', $val);
                                    } else {
                                        $value = 'Unknown (' . Perl::str($val) . ')';
                                    }
                                }
                            }
                        }
                        // override with our localized language PrintConv if available
                        if ($this->CUR_LANG and $convType === 'PrintConv' and
                            // (no need to check for lang-alt tag names -- they won't have a PrintConv)
                            is_array($tmp = $this->CUR_LANG[$tagInfo->Name] ?? null) and
                            ($tmp = $tmp['PrintConv'] ?? null))
                        {
                            if (isset($conv['BITMASK']) and !isset($conv[self::HashKey($val)])) {
                                $bits = Perl::split('/, /', $value);    // (Perl @vals)
                                foreach ($bits as &$b) {
                                    if (isset($tmp[$b])) $b = $tmp[$b];
                                }
                                unset($b);
                                $value = implode(', ', $bits);
                            } elseif (($tmp = $tmp[self::HashKey($value)] ?? null) !== null) {
                                $value = $this->Decode($tmp, 'UTF8');
                            }
                        }
                    } else {
                        // call subroutine or do eval to convert value
                        self::$evalWarning = null;
                        self::EvalBegin();
                        try {
                            if (self::IsCodeRef($conv)) {
                                $value = $conv($val, $this);
                            } else {
                                //### eval ValueConv/PrintConv ($val, $self, @val, @prt, @raw)
                                $value = $conv($val, $this, $valA, $prtA, $rawA, $tagInfo, $tag);
                            }
                        } catch (\Throwable $e) {
                            $value = null;
                            self::$evalWarning = self::EvalError($e);
                        } finally {
                            self::EvalEnd();
                        }
                        if (self::$evalWarning) $this->Warn("$convType $tag: " . self::CleanWarning());
                    }
                } else {
                    $value = $val;
                }
                if ($vals === null) break;
                // must store a separate copy of each binary data value in the list
                if (self::IsScalarRef($value)) $value = new ScalarRef($value->value);
                // save this converted value and step to next value in list
                if ($value !== null) $values[] = $value;
                if (++$i >= count($vals)) {
                    if ($values) $value = $values;
                    break;
                }
                $val = $vals[$i];
                if ($convList !== null) {
                    $nextConv = $convList[$i] ?? null;
                    if ($nextConv === 'REPEAT') {
                        $convList = null;
                    } else {
                        $conv = $nextConv;
                    }
                }
            }
            // return undefined now if no value
            if ($value === null) return $wantarray ? [] : null;
            // join back into single value if split for conversion list
            if ($convList !== null and is_array($value) and array_is_list($value)) {
                $value = self::JoinList($convType === 'PrintConv' ? '; ' : ' ', $value);
            }
        }
        if ($type === 'Both') {
            // save both (unescaped) values because we often need them again
            // (Composite tags need "Both" and often Require one tag for various Composite tags)
            if (!$both) $this->BOTH[$tag] = [$valueConv, $value];
            // escape values if necessary
            if ($this->ESCAPE_PROC) {
                self::DoEscape($value, $this->ESCAPE_PROC);
                if ($valueConv !== null) {
                    self::DoEscape($valueConv, $this->ESCAPE_PROC);
                } else {
                    $valueConv = $value;
                }
            } elseif ($valueConv === null) {
                // $valueConv is undefined if there was no print conversion done
                $valueConv = $value;
            }
            $this->Filter($this->OPTIONS['Filter'] ?? null, $value);
            // return Both values as a list (ValueConv, PrintConv)
            return $wantarray ? [$valueConv, $value] : $value;
        }
        // escape value if necessary
        if ($this->ESCAPE_PROC) self::DoEscape($value, $this->ESCAPE_PROC);

        // filter if necessary
        if (($this->OPTIONS['Filter'] ?? null) and $type === 'PrintConv') $this->Filter($this->OPTIONS['Filter'], $value);

        if (is_array($value) and array_is_list($value)) {
            if (isset($this->OPTIONS['ListItem'])) {
                $item = (int) Perl::int($this->OPTIONS['ListItem']);
                if ($item < 0) $item += count($value);
                $value = $value[$item] ?? null;
            } elseif ($wantarray) {
                // return array if requested
                return $value;
            } elseif ($type === 'PrintConv' and !$this->OPTIONS['List']) {
                // join PrintConv values in delimited string if List option not used
                // and list contains simple scalars (otherwise return ARRAY ref)
                foreach ($value as $v) {
                    if (is_array($v) or is_object($v)) return $value;
                }
                $value = self::JoinList($this->OPTIONS['ListSep'], $value);
            }
        }
        return $wantarray ? [$value] : $value;
    }

    //------------------------------------------------------------------------------
    // Get tag identification number
    // Inputs: 0) ExifTool object reference, 1) tag key
    // Returns: Scalar context: tag ID if available, otherwise ''
    //          List context: 0) tag ID (or ''), 1) language code (or undef)
    //          (PHP: scalar context only, the language code is $tagInfo->LangCode)
    public function GetTagID($tag)
    {
        $tagInfo = $this->TAG_INFO[$tag] ?? null;
        if (!$tagInfo or $tagInfo->TagID === null) return '';
        $id = $tagInfo->KeysID ?: $tagInfo->TagID;
        return $id;
    }

    //------------------------------------------------------------------------------
    // Get description for specified tag
    // Inputs: 0) ExifTool object reference, 1) tag key
    // Returns: Tag description
    // Notes: Will always return a defined value, even if description isn't available
    public function GetDescription($tag)
    {
        $desc = null;
        $tagInfo = $this->TAG_INFO[$tag] ?? null;
        // ($tagInfo won't be defined for missing tags extracted with -f)
        if ($tagInfo) {
            // use alternate language description if available
            while ($this->CUR_LANG) {
                $desc = $this->CUR_LANG[$tagInfo->Name] ?? null;
                if ($desc) {
                    // must look up Description if this tag also has a PrintConv
                    if (is_array($desc) and !($desc = $desc['Description'] ?? null)) break;
                } else {
                    // look up default language of lang-alt tag
                    $langCode = $tagInfo->LangCode;
                    if (!($langCode and preg_match('/-' . preg_quote((string) $langCode, '/') . '$/', (string) $tagInfo->Name) and
                        ($desc = $this->CUR_LANG[preg_replace('/-' . preg_quote((string) $langCode, '/') . '$/', '', (string) $tagInfo->Name, 1)] ?? null)))
                    {
                        break;
                    }
                    if (is_array($desc) and !($desc = $desc['Description'] ?? null)) break;
                    $desc .= " ($langCode)";
                }
                // escape description if necessary
                if ($this->ESCAPE_PROC) self::DoEscape($desc, $this->ESCAPE_PROC);
                // return description in proper Charset
                return $this->Decode($desc, 'UTF8');
            }
            $desc = $tagInfo->Description;
        }
        // just make the tag more readable if description doesn't exist
        if (!$desc) {
            $desc = self::MakeDescription(self::GetTagName($tag));
            // save description in tag information
            if ($tagInfo) $tagInfo->Description = $desc;
        }
        return $desc;
    }

    //------------------------------------------------------------------------------
    // Get group name for specified tag
    // Inputs: 0) ExifTool object reference
    //         1) tag key (or reference to tagInfo hash, not part of the public API)
    //         2) [optional] group family (-1 to get extended group list, or multiple
    //            families separated by colons to return multiple groups as a string)
    // Returns: Scalar context: group name (for family 0 if not otherwise specified)
    //          List context: group name if family specified, otherwise list of
    //          group names for each family.  Returns '' for undefined tag.
    // PHP: returns the list of group names (Perl list context) when no family or family -1
    //      is given, otherwise the group name (string).  Pass 0 for the family 0 name.
    // Notes: Multiple families may be specified with ':' in family argument (eg. '1:2')
    public function GetGroup($tag, $family = null)
    {
        $wantarray = ($family === null or Perl::str($family) === '-1');
        $groups = $families = [];
        $simplify = $noID = $byTagInfo = null;
        if ($tag instanceof TagInfo) {
            $tagInfo = $tag;
            $tag = $tagInfo->Name;
            // set flag so we don't get extra information for an extracted tag
            $byTagInfo = 1;
            $ex = [];
        } else {
            $tagInfo = $this->TAG_INFO[$tag] ?? new TagInfo();
            $ex = $this->TAG_EXTRA[$tag] ?? [];
        }
        $grps = $tagInfo->Groups;   // (Perl %$groups)
        // fill in default groups unless already done
        // (after this, Groups 0-2 in tagInfo are guaranteed to be defined)
        if (!$tagInfo->GotGroups) {
            $tagTablePtr = $tagInfo->Table;
            $tableGroups = $tagTablePtr ? ($tagTablePtr['GROUPS'] ?? []) : [];
            // construct our group list
            if (!$grps) $grps = [];
            // fill in default groups
            foreach ([0, 1, 2] as $f) {
                if (empty($grps[$f])) $grps[$f] = ($tableGroups[$f] ?? null) ?: '';
            }
            $tagInfo->Groups = $grps;
            // set flag indicating group list was built
            $tagInfo->GotGroups = 1;
        }
        if ($family !== null and Perl::str($family) !== '-1') {
            if (preg_match('/[^\d]/', Perl::str($family))) {
                preg_match_all('/\d+/', Perl::str($family), $m);
                $families = $m[0];
                if (!$families) return ($ex['G0'] ?? null) ?: $grps[0];
                if (!preg_match('/^:/', Perl::str($family))) $simplify = 1;
                $family = null;
                foreach ([0, 1, 2] as $f) $groups[$f] = $grps[$f];
                if (count($families) == 1 and $families[0] != 7) $noID = 1;
            } else {
                if ($family == 0 or $family == 2) return ($ex["G$family"] ?? null) ?: $grps[$family];
                $groups[1] = $grps[1];
            }
        } else {
            foreach ([0, 1, 2] as $f) $groups[$f] = $grps[$f];
        }
        $groups[3] = 'Main';
        $groups[4] = (preg_match('/\((\d+)\)$/', (string) $tag, $m) and $m[1] !== '0') ? "Copy$m[1]" : '';
        // handle dynamic group names if necessary
        if (!$byTagInfo) {
            if (!empty($ex['G0'])) $groups[0] = $ex['G0'];
            if (!empty($ex['G1'])) $groups[1] = preg_match('/^\+(.*)/s', $ex['G1'], $m) ? $groups[1] . $m[1] : $ex['G1'];
            if (!empty($ex['G3'])) $groups[3] = 'Doc' . $ex['G3'];
            if (isset($ex['G5'])) $groups[5] = $ex['G5'] ?: $groups[1];
            if (isset($ex['G6'])) {
                if (!isset($groups[5])) $groups[5] = '';    // (can't leave a hole in the array)
                $groups[6] = $ex['G6'];
            }
            if (!empty($ex['G8'])) {
                $groups[7] = '';
                $groups[8] = $ex['G8'];
            }
            // generate tag ID group names unless obviously not needed
            if (!$noID) {
                $id = $tagInfo->KeysID ?: $tagInfo->TagID;
                if ($id === null) {
                    $id = '';   // (just to be safe)
                } elseif (preg_match('/^\d+$/', (string) $id)) {
                    if ($this->OPTIONS['HexTagIDs']) $id = Perl::sprintf('0x%x', $id);
                } else {
                    $id = preg_replace_callback('/([^-_A-Za-z0-9])/', static fn ($c) => sprintf('%.2x', ord($c[1])), (string) $id);
                }
                $groups[7] = 'ID-' . $id;
                foreach ([5, 6] as $f) {
                    if (!isset($groups[$f])) $groups[$f] = '';
                }
            }
        }
        if ($family) {
            if ($family > 0) return ($groups[$family] ?? null) ?: '';
            // add additional matching group names to list
            // eg) for MIE-Doc, also add MIE1, MIE1-Doc, MIE-Doc1 and MIE1-Doc1
            // and for MIE2-Doc3, also add MIE2, MIE-Doc3, MIE2-Doc and MIE-Doc
            if (preg_match('/^MIE(\d*)-(.+?)(\d*)$/', (string) $groups[1], $m)) {
                $extra = [
                    'MIE' . ($m[1] ?: '1'),
                    'MIE' . ($m[1] ? '' : '1') . "-$m[2]$m[3]",
                    "MIE$m[1]-$m[2]" . ($m[3] ? '' : '1'),
                    'MIE' . ($m[1] ? '' : '1') . "-$m[2]" . ($m[3] ? '' : '1'),
                ];
                $groups = self::FillList($groups);
                array_push($groups, ...$extra);
            }
        }
        if ($families) {
            $list = [];
            // create list of group names (without identical adjacent groups if simplifying)
            foreach ($families as $f) {
                $grp = $groups[$f] ?? null;
                if (!$grp) {
                    if ($simplify) continue;
                    $grp = '';
                }
                if (!($simplify and $list and $grp === $list[count($list) - 1])) $list[] = $grp;
            }
            // remove leading "Main:" if simplifying
            if ($simplify and count($list) > 1 and $list[0] === 'Main') array_shift($list);
            // return colon-separated string of group names
            return implode(':', $list);
        }
        return $wantarray ? self::FillList($groups) : (($ex['G0'] ?? null) ?: $groups[0]);
    }

    //------------------------------------------------------------------------------
    // Get group names for specified tags
    // Inputs: 0) ExifTool object reference
    //         1) [optional] information hash reference (default all extracted info)
    //         2) [optional] group family (default 0)
    // Returns: List of group names in alphabetical order
    public function GetGroups($info = null, $family = null): array
    {
        // figure out our arguments
        if (!is_array($info)) {
            $family = $info;
            $info = $this->VALUE;
        }
        if ($family === null) $family = 0;

        // get a list of all groups in specified information
        $groups = [];
        foreach (array_keys($info) as $tag) {
            $groups[(string) $this->GetGroup((string) $tag, $family)] = 1;
        }
        $list = array_map('strval', array_keys($groups));
        sort($list, SORT_STRING);
        return $list;
    }

    //------------------------------------------------------------------------------
    // Set priority for group where new values are written
    // Inputs: 0) ExifTool object reference,
    //         1-N) group names (reset to default if no groups specified)
    // - used when new tag values are set (ie. before files are written)
    public function SetNewGroups(...$groups): void
    {
        if (!$groups) $groups = Vars\ExifTool::$defaultWriteGroups;
        $count = count($groups) * 10;
        $priority = [];
        foreach ($groups as $g) {
            $priority[strtolower($g)] = $count;
            $count -= 10;
        }
        $priority['file'] = 500;        // 'File' group is always written (Comment)
        $priority['composite'] = 500;   // 'Composite' group is always written
        // set write priority (higher # is higher priority)
        $this->WRITE_PRIORITY = $priority;
        $this->WRITE_GROUPS = $groups;
    }

    //------------------------------------------------------------------------------
    // Build Composite tags from Require'd/Desire'd tags
    // Inputs: 0) ExifTool object reference, 1) flag to build only tags that require
    //         tags from alternate files (without this, these tags are ignored)
    // Note: Tag values are calculated in alphabetical order unless a tag Require's
    //       or Desire's another Composite tag, in which case the calculation is
    //       deferred until after the other tag is calculated.
    public function BuildCompositeTags($altOnly = null): void
    {
        $this->BuildingComposite = 1;

        $compTable = self::GetTagTable('Image::ExifTool::Composite');
        $tagList = array_map('strval', $compTable->keys());
        sort($tagList, SORT_STRING);
        $specialTags = Vars\ExifTool::$specialTags;
        $cache = [];
        $allBuilt = null;

        for (;;) {
            $notBuilt = [];
            $deferredTags = [];
            foreach ($tagList as $t) {
                if (isset($specialTags[$t]) or !$compTable[$t]) continue;
                $notBuilt[$compTable[$t]->Name] = 1;
            }
COMPOSITE_TAG:
            foreach ($tagList as $tag) {
                if (isset($specialTags[$tag])) continue;
                $tagInfo = $this->GetTagInfo($compTable, $tag);
                if (!$tagInfo) continue;
                $tagName = $compTable[$tag]->Name;
                // put required tags into array and make sure they all exist
                $subDoc = ($tagInfo->SubDoc and $this->DOC_COUNT);
                $require = $tagInfo->Require ?: [];
                $desire  = $tagInfo->Desire  ?: [];
                $inhibit = $tagInfo->Inhibit ?: [];
                // loop through sub-documents if necessary
                $docNum = 0;
                for (;;) {
                    $tagKey = [];
                    $found = $requireAlt = null;
                    // save Require'd and Desire'd tag values in list
                    for ($index = 0; ; ++$index) {
                        $reqTag = ($require[$index] ?? null) ?: (($desire[$index] ?? null) ?: ($inhibit[$index] ?? null));
                        if (!$reqTag) {
                            // allow Composite with no Require'd or Desire'd tags
                            if ($index == 0) $found = 1;
                            break;
                        }
                        if ($subDoc) {
                            // handle SubDoc tags specially to cache tag keys for faster
                            // processing when there are a large number of sub-documents
                            // - get document number from the tag groups if specified,
                            //   otherwise we are looping through all documents for this tag
                            if (preg_match('/\b(Main|Doc(\d+)):/', $reqTag, $m)) {
                                $reqTag = preg_replace('/\b(Main|Doc(\d+)):/', '', $reqTag, 1);
                                $doc = ($m[2] ?? null) ?: 0;
                            } else {
                                $doc = $docNum;
                            }
                            // make fast lookup for keys of this tag with specified groups other than doc group
                            // (similar to code in InsertTagValues(), but this is case-sensitive)
                            $cacheTag = $cache[$reqTag] ?? null;
                            if ($cacheTag === null) {
                                $cacheTag = [];
                                $reqGroup = null;
                                $rt = $reqTag;
                                if (preg_match('/^(.*):/', $rt, $m)) {
                                    $reqGroup = $m[1];
                                    $rt = substr($rt, strlen($m[0]));
                                }
                                $keys = [];
                                // build list of tag keys in order of precedence
                                for ($key = $rt, $i = $this->DUPL_TAG[$rt] ?? 0; ; --$i) {
                                    if (isset($this->VALUE[$key])) $keys[] = $key;
                                    if ($i <= 0) break;
                                    $key = "$rt ($i)";
                                }
                                if ($reqGroup !== null) $keys = $this->GroupMatchesList($reqGroup, $keys);
                                // loop through tags in reverse order of precedence so the higher
                                // priority tag will win in the case of duplicates within a doc
                                foreach (array_reverse($keys) as $key) {
                                    $cacheTag[($this->TAG_EXTRA[$key]['G3'] ?? null) ?: 0] = $key;
                                }
                                $cache[$reqTag] = $cacheTag;
                                $reqTag = $rt;
                            } else {
                                $reqTag = preg_replace('/^(.*):/', '', $reqTag);
                            }
                            // (set $reqTag to a bogus key if not found)
                            $reqTag = ($cacheTag[$doc] ?? null) ?: "$reqTag (0)";
                        } elseif (preg_match('/^(.*):(.+)/', $reqTag, $m)) {
                            [$reqGroup, $name] = [$m[1], $m[2]];
                            if ($reqGroup === 'Composite' and !empty($notBuilt[$name])) {
                                // defer only until all other tags are built if
                                // we are inhibiting based on another Composite tag
                                if (!(!empty($inhibit[$index]) and $allBuilt)) {
                                    $deferredTags[] = $tag;
                                    continue 3;     // next COMPOSITE_TAG
                                }
                            }
                            $keys = [];
                            $altFile = null;
                            $et = $this;
                            // get tags from alternate file if a family 8 group was specified
                            if (preg_match('/\b(File\d+):/i', $reqTag, $mm) and !empty($this->ALT_EXIFTOOL[$mm[1]])) {
                                $et = $this->ALT_EXIFTOOL[$mm[1]];
                                $altFile = $mm[1];
                                // set flags indicating we require tags from alternate files
                                $this->DoAltComposite = $requireAlt = 1;
                            }
                            // (CAREFUL! keys may not be sequential if one was deleted)
                            for ($key = $name, $i = $et->DUPL_TAG[$name] ?? 0; ; --$i) {
                                if (isset($et->VALUE[$key])) $keys[] = $key;
                                if ($i <= 0) break;
                                $key = "$name ($i)";
                            }
                            // make sure the necessary information is available from the alternate file
                            if ($altFile) $this->CopyAltInfo($altFile, $keys);
                            // find first matching tag
                            $key = $this->GroupMatches($reqGroup, $keys);
                            $reqTag = $key ?: "$name (0)";
                        } elseif (!empty($notBuilt[$reqTag]) and empty($inhibit[$index])) {
                            // calculate this tag later if it relies on another
                            // Composite tag which hasn't been calculated yet
                            $deferredTags[] = $tag;
                            continue 3;     // next COMPOSITE_TAG
                        }
                        if (isset($this->VALUE[$reqTag])) {
                            if (!empty($inhibit[$index])) {
                                $found = 0;
                                break;
                            } else {
                                $found = 1;
                            }
                        } elseif (!empty($require[$index])) {
                            $found = 0;
                            break;  // don't continue since we require this tag
                        }
                        $tagKey[$index] = $reqTag;
                    }
                    // stop now if this requires alternate tags and we aren't building them
                    if ((bool) $requireAlt xor (bool) $altOnly) break;
                    if ($docNum) {
                        if ($found) {
                            $this->DOC_NUM = $docNum;
                            $tagKeyObj = new \ArrayObject($tagKey);
                            // save pointers to all used tag keys
                            foreach ($tagKey as $idx => $key) {
                                $this->COMP_KEYS[$key][] = [$tagKeyObj, $idx];
                            }
                            $this->FoundTag($tagInfo, $tagKeyObj);
                            $this->DOC_NUM = null;
                        }
                        if (++$docNum <= $this->DOC_COUNT) continue;
                        break;
                    } elseif ($found) {
                        unset($notBuilt[$tagName]); // this tag is OK to build now
                        $tagKeyObj = new \ArrayObject($tagKey);
                        // save pointers to all used tag keys
                        foreach ($tagKey as $idx => $key) {
                            $this->COMP_KEYS[$key][] = [$tagKeyObj, $idx];
                        }
                        // save reference to tag key lookup as value for Composite tag
                        $this->FoundTag($tagInfo, $tagKeyObj);
                    } elseif ($found === null) {
                        unset($notBuilt[$tagName]); // tag can't be built anyway
                    }
                    if (!$subDoc) break;
                    // don't process sub-documents if there is no chance to build this tag
                    // (can be very time-consuming if there are many docs)
                    if ($require) {
                        foreach ($require as $rt) {
                            $rt = preg_replace('/.*:/', '', $rt);
                            if (!isset($this->VALUE[$rt])) continue 3;  // next COMPOSITE_TAG
                        }
                        $docNum = 1;   // go ahead and process the 1st sub-document
                    } else {
                        $try = is_array($tagInfo->SubDoc) ? $tagInfo->SubDoc : array_keys($desire);
                        // at least one of the specified desire tags must exist
                        foreach ($try as $t) {
                            $desTag = $desire[$t] ?? null;
                            if (!$desTag) continue;
                            $desTag = preg_replace('/.*:/', '', $desTag);
                            if (isset($this->VALUE[$desTag])) {
                                $docNum = 1;
                                break;
                            }
                        }
                        if (!$docNum) break;
                    }
                }
            }
            if (!$deferredTags) break;
            if (count($deferredTags) == count($tagList)) {
                if ($allBuilt) {
                    // everything was deferred in the last pass,
                    // must be a circular dependency
                    self::PerlWarn("Circular dependency in Composite tags\n");
                    break;
                }
                $allBuilt = 1;  // try once more, ignoring Composite Inhibit tags
            }
            $tagList = $deferredTags;   // calculate deferred tags now
        }
        $this->BuildingComposite = null;
    }

    //------------------------------------------------------------------------------
    // Get reference to Composite tag info hash
    // Inputs: 0) case-sensitive Composite tag name
    // Returns: tagInfo hash or undef
    public static function GetCompositeTagInfo($tag): ?TagInfo
    {
        if (empty(self::$compositeID[$tag])) return null;
        $tagInfo = self::GetTagTable('Image::ExifTool::Composite')[self::$compositeID[$tag][0]];
        return $tagInfo instanceof TagInfo ? $tagInfo : null;
    }

    //------------------------------------------------------------------------------
    // Return List ExifTool API options
    // Returns: 0) reference to list of available options -- each entry is a list
    //            [0=option name, 1=default value, 2=description]
    public static function AvailableOptions(): array
    {
        return Vars\ExifTool::$availableOptions;
    }

    //------------------------------------------------------------------------------
    // Get tag name (removes copy index)
    // Inputs: 0) Tag key
    // Returns: Tag name
    public static function GetTagName($tag)
    {
        return preg_match('/^(\S+)/', (string) $tag, $m) ? $m[1] : null;
    }

    //------------------------------------------------------------------------------
    // Get list of shortcuts
    // Returns: Shortcut list (sorted alphabetically)
    public static function GetShortcuts(): array
    {
        $list = array_map('strval', array_keys(Vars\Shortcuts::$Main));
        sort($list, SORT_STRING);
        return $list;
    }

    //------------------------------------------------------------------------------
    // Get file type for specified extension
    // Inputs: 0) file name or extension (case is not significant),
    //            or FileType value if a description is requested
    //         1) flag to return long description instead of type ('0' to return any recognized type)
    // Returns: File type (or desc) or undef if extension not supported or if
    //          description is the same as the input FileType.  In list context,
    //          may return more than one file type if the file may be different formats.
    //          Returns list of all supported extensions if no file specified
    //          (PHP: list context is GetFileTypeList)
    public static function GetFileType($file = null, $desc = null)
    {
        return self::DoGetFileType($file, $desc, false);
    }

    /** GetFileType in list context */
    public static function GetFileTypeList($file = null, $desc = null): array
    {
        return self::DoGetFileType($file, $desc, true);
    }

    private static function DoGetFileType($file, $desc, bool $wantarray)
    {
        $fileTypeLookup = Vars\ExifTool::$fileTypeLookup;
        $moduleName = Vars\ExifTool::$moduleName;
        if ($file === null) {
            $types = [];
            $keys = array_map('strval', array_keys($fileTypeLookup));
            sort($keys, SORT_STRING);
            if ($desc !== null and Perl::str($desc) === '0') {
                // return all recognized types
                $types = $keys;
            } else {
                // return all supported types
                foreach ($keys as $key) {
                    $module = $moduleName[$key] ?? null;
                    if ($module === null and is_string($fileTypeLookup[$key])) $module = $moduleName[$fileTypeLookup[$key]] ?? null;
                    if (!($module !== null and Perl::str($module) === '0')) $types[] = $key;
                }
            }
            return $wantarray ? $types : count($types);
        }
        $file = Perl::str($file);
        $fileType = $subType = null;
        $fileExt = self::GetFileExtension($file);
        if (!$fileExt) {
            if (preg_match('/ \((.*)\)$/', $file, $m)) {
                $subType = $m[1];
                $file = substr($file, 0, -strlen($m[0]));
                $fileExt = self::GetFileExtension($file);
            }
            if (!$fileExt) $fileExt = strtoupper($file);
        }
        if ($fileExt) $fileType = $fileTypeLookup[$fileExt] ?? null; // look up the file type
        while ($fileType and !is_array($fileType)) $fileType = $fileTypeLookup[$fileType] ?? null;
        // return description if specified
        // (allow input $file to be a FileType for this purpose)
        if ($desc) {
            if ($fileType) {
                if (isset(self::$static_vars['OverrideFileDescription'][$fileExt])) {
                    $desc = self::$static_vars['OverrideFileDescription'][$fileExt];
                } else {
                    $desc = $fileType[1];
                }
            } else {
                $desc = (Vars\ExifTool::$fileDescription[$file] ?? null) ?: $file;
            }
            if ($subType) $desc .= ", $subType";
            return $wantarray ? [$desc] : $desc;
        } elseif ($fileType and ($desc === null or Perl::str($desc) !== '0')) {
            // return only supported file types
            $mod = is_string($fileType[0]) ? ($moduleName[$fileType[0]] ?? null) : null;
            if ($mod !== null and Perl::str($mod) === '0') $fileType = null;
        }
        if (!$fileType) return $wantarray ? [] : null;
        $fileType = $fileType[0];   // get file type (or list of types)
        if ($wantarray) {
            return is_array($fileType) ? $fileType : [$fileType];
        } elseif ($fileType) {
            if (is_array($fileType)) $fileType = $fileExt;
        }
        return $fileType;
    }

    //------------------------------------------------------------------------------
    // Return true if we can write the specified file type
    // Inputs: 0) file name or ext
    // Returns: true if writable, 0 if not writable, '' if not writable due to extension,
    //          undef if unrecognized
    public static function CanWrite($file)
    {
        if (!$file) return null;
        $types = self::GetFileTypeList($file);
        if (!$types) return null;
        $type = $types[0];
        if (isset(Vars\ExifTool::$noWriteFile[$type])) {
            // can't write TIFF files with certain extensions (various RAW formats)
            $ext = self::GetFileExtension($file) ?: strtoupper($file);
            if ($ext) return in_array($ext, Vars\ExifTool::$noWriteFile[$type], true) ? '' : 1;
        }
        if (isset(Vars\ExifTool::$onlyWriteFile[$type])) {
            $ext = self::GetFileExtension($file) ?: strtoupper($file);
            if ($ext) return in_array($ext, Vars\ExifTool::$onlyWriteFile[$type], true) ? 1 : 0;
        }
        return in_array($type, Vars\ExifTool::$writeTypes_2, true) ? 1 : null;
    }

    //------------------------------------------------------------------------------
    // Return true if we can create the specified file type
    // Inputs: 0) file name or ext
    // Returns: true if creatable, 0 if not writable, undef if unrecognized
    public static function CanCreate($file)
    {
        if (!$file) return null;
        $ext = self::GetFileExtension($file) ?: strtoupper($file);
        $type = self::GetFileType($file);
        if (!$type) return null;
        if (!empty(Vars\ExifTool::$createTypes[$ext]) or !empty(Vars\ExifTool::$createTypes[$type])) return 1;
        return 0;
    }

    //------------------------------------------------------------------------------
    // Return list of ordered keys if available, otherwise just sort alphabetically
    // Inputs: 0) hash ref
    // Returns: List of ordered/sorted keys
    public static function OrderedKeys(array $hash): array
    {
        // (an empty Perl array ref is still true)
        $ordered = $hash['_ordered_keys_'] ?? null;
        if (null !== $ordered) return $ordered instanceof \ArrayObject ? $ordered->getArrayCopy() : (array) $ordered;
        $keys = array_map('strval', array_keys($hash));
        sort($keys, SORT_STRING);
        return $keys;
    }

    //==============================================================================
    // Functions below this are not part of the public API

    // Initialize member variables before reading or writing a new file
    // Inputs: 0) ExifTool object reference
    public function Init(): void
    {
        // delete all DataMember variables (lower-case names)
        foreach (array_keys(get_object_vars($this)) as $key) {
            if (preg_match('/[a-z]/', $key)) unset($this->$key);
        }
        // reset static variables
        self::$static_vars = [
            'KeepUTCTime' => $this->OPTIONS['KeepUTCTime'] ?? null,
            'SystemTimeRes' => $this->OPTIONS['SystemTimeRes'] ?? null,
        ];
        $this->FOUND_TAGS = null;       // list of found tags
        $this->EXIF_DATA = null;        // the EXIF data block
        $this->EXIF_POS = null;         // EXIF position in file
        $this->FIRST_EXIF_POS = null;   // position of first EXIF in file
        $this->HTML_DUMP = null;        // html dump information
        $this->SET_GROUP0 = null;       // group0 name override
        $this->SET_GROUP1 = null;       // group1 name override
        $this->DOC_NUM = null;          // current embedded document number
        $this->DOC_COUNT  = 0;          // count of embedded documents processed
        $this->BASE       = 0;          // base for offsets from start of file
        $this->FILE_ORDER = [];         // * hash of tag order in file ('*' = based on tag key)
        $this->VALUE      = [];         // * hash of raw tag values
        $this->BOTH       = [];         // * hash for Value/PrintConv values of Require'd tags
        $this->TAG_INFO   = [];         // * hash of tag information
        $this->TAG_EXTRA  = [];         // * hash of extra tag information (dynamic group names)
        $this->PRIORITY   = [];         // * priority of current tags
        $this->LIST_TAGS  = [];         // hash of tagInfo refs for active List-type tags
        $this->PROCESSED  = [];         // hash of processed directory start positions
        $this->DIR_COUNT  = [];         // count various types of directories
        $this->DUPL_TAG   = [];         // last-used index for duplicate-tag keys
        $this->WAS_WARNED = [];         // number of times each warning was issued
        $this->WRITTEN    = [];         // list of tags written (selected tags only)
        $this->FORCE_WRITE= [];         // ForceWrite lookup (set from ForceWrite tag)
        $this->FOUND_DIR  = [];         // hash of directory names found in file
        $this->COMP_KEYS  = [];         // lookup for tag keys used in Composite tags
        $this->PATH       = [];         // current subdirectory path in file when reading
        $this->NUM_FOUND  = 0;          // total number of tags found (incl. duplicates)
        $this->CHANGED    = 0;          // number of tags changed (writer only)
        $this->INDENT     = '  ';       // initial indent for verbose messages
        $this->PRIORITY_DIR = '';       // the priority directory name
        $this->LOW_PRIORITY_DIR = ['PreviewIFD' => 1]; // names of priority 0 directories
        $this->TIFF_TYPE  = '';         // type of TIFF data (APP1, TIFF, NEF, etc...)
        $this->FMT_EXPR   = null;       // current advanced formatting expression
        $this->HAS_DOC    = [];         // lookup for all document numbers in this file
        $this->Make       = '';         // camera make
        $this->Model      = '';         // camera model
        $this->CameraType = '';         // Olympus camera type
        $this->FileType   = '';         // identified file type
        // (HtmlDump not ported: HTML_DUMP stays undefined)
        // make sure our TextOut is a file reference
        if (!is_resource($this->OPTIONS['TextOut'] ?? null)) {
            $this->OPTIONS['TextOut'] = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        }
    }

    //------------------------------------------------------------------------------
    // Purge temporary tags from memory and set purge flag for next time
    // Inputs: 0) false=disable purging, true=enable purging, and
    //            purge now if number of digits in tags to purge >= flag
    public static function Purge($flag = null): void
    {
        self::$purgeFlag = $flag ?: 0;
        if (self::$purgeTags and strlen((string) count(self::$purgeTags)) >= self::$purgeFlag) {
            foreach (self::$purgeTags as $tagInfo) {
                if ($tagInfo->IsProtobuf === null and $tagInfo->Table) unset($tagInfo->Table[$tagInfo->TagID]);
            }
            self::$purgeTags = [];
        }
    }

    //------------------------------------------------------------------------------
    // Combine information from a list of info hashes
    // Unless Duplicates is enabled, first entry found takes priority
    // Inputs: 0) ExifTool object reference, 1-N) list of info hash references
    // Returns: Combined information hash reference
    public function CombineInfo(...$infos): array
    {
        $combinedInfo = [];
        $haveInfo = [];

        if ($this->OPTIONS['Duplicates']) {
            foreach ($infos as $info) {
                foreach ($info as $tag => $val) {
                    $combinedInfo[$tag] = $val;
                }
            }
        } else {
            foreach ($infos as $info) {
                foreach ($info as $tag => $val) {
                    $tagName = self::GetTagName($tag);
                    if (!empty($haveInfo[$tagName])) continue;
                    $haveInfo[$tagName] = 1;
                    $combinedInfo[$tag] = $val;
                }
            }
        }
        return $combinedInfo;
    }

    //------------------------------------------------------------------------------
    // Finish generating tags after extracting information from a file
    // Inputs: 0) ExifTool ref
    // Notes: The sequencing here is a bit tricky because tags from the main file
    //        may be used in the names of alternate files, so we finish generating
    //        all main file tags first (including all Composite tags which don't
    //        rely on alternate files) before extracting tags from alternate files,
    //        then we finish by generating the remaingin Composite tags.
    public function DoneExtract(): void
    {
        $opts = $this->OPTIONS;

        // generate ImageDataHash if requested
        if ($this->ImageDataHash) {
            $digest = hash_final($this->ImageDataHash);
            $this->ImageDataHash = null;
            // (don't store empty digest)
            if (!in_array($digest, [
                'd41d8cd98f00b204e9800998ecf8427e',
                'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e',
            ], true)) {
                $this->FoundTag('ImageDataHash', $digest);
            }
        }
        // generate Validate tag if requested
        if ($opts['Validate'] and method_exists(Validate::class, 'FinishValidate')) {
            Validate::FinishValidate($this, $this->REQ_TAG_LOOKUP['validate'] ?? null);
        }
        // generate geolocation tags if requested
        if ($opts['Geolocation']) {
            $doneCity = $both = null;
            $defaults = $tags = $coord = $ref = $city = [];
            $geoOpt = $opts['Geolocation'];
            foreach (Perl::split('/\s*,\s*/', $opts['Geolocation']) as $arg) {
                if (strtolower($arg) === 'both') {
                    $both = 1;
                    continue;
                }
                if (!str_starts_with($arg, '$')) {
                    $defaults[] = $arg;
                    continue;
                }
                $tags[] = substr($arg, 1);   // argument is a tag name
            }
            $arg = null;    // (Perl restores the foreach variable)
            if (!$tags) {
                // default tags to read if not specified
                $tags = ['GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef',
                         'GPSCoordinates', 'LocationShownGPSLatitude', 'LocationShownGPSLongitude',
                         'XMP:City', 'State', 'CountryCode', 'Country',
                         'IPTC:City', 'Province-State', 'Country-PrimaryLocationCode', 'Country-PrimaryLocationName',
                         'LocationShownCity', 'LocationShownProvinceState', 'LocationShownCountryCode', 'LocationShownCountryName'];
            }
            // get information for specified tags
            $info = $this->GetInfo($tags, ['PrintConv' => 0, 'Duplicates' => 0]); // (returns tags in proper case)
            // (PHP: GetInfo restores IO_TAG_LIST when called from ExtractInfo, so build the
            // updated list here like GetInfo does; tags without a value are skipped below anyway)
            $tags = $this->GetTagList(array_keys($info), $this->OPTIONS['Sort'], $this->OPTIONS['Sort2']);
            $opts = $this->OPTIONS;    // (necessary because GetInfo changes the OPTIONS hash)
            foreach ($tags as $tag) {
                $val = $info[$tag] ?? null;
                if ($val === null) continue;
                $this->VPrint(0, 'Found ' . $tag . ' (' . Perl::str($val) . ")\n");
                if (str_contains($tag, 'Coordinates')) {
                    if (isset($coord[0], $coord[1])) continue;
                    $coord = Perl::split(' ', $val);
                    continue;
                }
                $n = str_contains($tag, 'Latitude') ? 0 : (str_contains($tag, 'Longitude') ? 1 : null);
                if ($n !== null) {
                    if (str_ends_with($tag, 'Ref')) {
                        if (empty($ref[$n])) $ref[$n] = $val;
                    } elseif (!isset($coord[$n])) {
                        $coord[$n] = $val;
                    }
                    continue;
                }
                // handle city tags (save info for first city found)
                if (str_contains($tag, 'City')) {
                    if ($city) {
                        $doneCity = 1;
                        continue;
                    }
                    $city[] = $val;
                } elseif ($city) {
                    if (!$doneCity) $city[] = $val;
                    if ($doneCity) continue;
                }
            }
            if (isset($coord[0], $coord[1])) {
                if (!empty($ref[0]) and Perl::num($coord[0]) > 0 and $ref[0] === 'S') $coord[0] = -Perl::num($coord[0]);
                if (!empty($ref[1]) and Perl::num($coord[1]) > 0 and $ref[1] === 'W') $coord[1] = -Perl::num($coord[1]);
                $arg = implode(',', array_map(Perl::str(...), $coord));
            } elseif ($city) {
                $arg = implode(',', array_map(Perl::str(...), $city));
            }
            if ($arg === null) {
                // use specified default values if no tags found
                $arg = implode(',', $defaults);
                if ($arg === '1') $arg = null;
                $both = 1;  // use 'both' GPS and place names if provided
            }
            if ($arg) {
                if ($both) $arg .= ',both';
                $arg = $this->Encode($arg, 'UTF8');
                Geolocation::Load();
                if ($opts['Verbose']) {
                    // (Perl prints these to STDOUT)
                    if (Geolocation::$dbInfo) {
                        fwrite($opts['TextOut'], 'Loaded ' . Geolocation::$dbInfo . "\n");
                    } else {
                        fwrite($opts['TextOut'], "Error loading Geolocation.dat\n");
                    }
                }
                // local $SIG{'__WARN__'} = \&SetWarning;
                self::$evalWarning = null;
                self::EvalBegin();
                try {
                    $opts['GeolocMulti'] = $opts['Duplicates'];
                    $this->VPrint(0, "Geolocation arguments: '{$arg}'\n");
                    [$cities, $dist] = Geolocation::Geolocate($arg, $opts);
                    unset($opts['GeolocMulti']);
                    if ($cities and (count($cities) < 2 or $dist or !$this->Warn('Multiple Geolocation cities are possible', 2))) {
                        if (count($cities) > 1) $this->FoundTag('GeolocationWarning', 'Search matched ' . count($cities) . ' cities');
                        foreach ($cities as $cty) {     // (Perl $city)
                            if ($cty !== $cities[0]) $this->DOC_NUM = ++$this->DOC_COUNT;
                            $geo = Geolocation::GetEntry($cty, $opts['Lang']);
                            $this->FoundTag('GeolocationCity', $geo[0]);
                            if ($geo[1]) $this->FoundTag('GeolocationRegion', $geo[1]);
                            if ($geo[2]) $this->FoundTag('GeolocationSubregion', $geo[2]);
                            $this->FoundTag('GeolocationCountryCode', $geo[3]);
                            if ($geo[4]) $this->FoundTag('GeolocationCountry', $geo[4]);
                            if ($geo[5]) $this->FoundTag('GeolocationTimeZone', $geo[5]);
                            $this->FoundTag('GeolocationFeatureCode', $geo[6]);
                            if ($geo[10]) $this->FoundTag('GeolocationFeatureType', $geo[10]);
                            $this->FoundTag('GeolocationPopulation', $geo[7]);
                            $this->FoundTag('GeolocationPosition', "$geo[8] $geo[9]");
                            if ($dist) {
                                $this->FoundTag('GeolocationDistance', $dist[0][0]);
                                $this->FoundTag('GeolocationBearing', $dist[0][1]);
                                array_shift($dist);
                            }
                            if (!$opts['Duplicates']) break;
                        }
                        $this->DOC_NUM = null;
                    } elseif (self::$evalWarning) {
                        $this->Warn(self::CleanWarning());
                    }
                } finally {
                    self::EvalEnd();
                }
            }
        }

        // generate tags for user-defined parameters that ended with '#'
        if ($opts['UserParam']) {
            $doMsg = $opts['Verbose'];
            $table = self::GetTagTable('Image::ExifTool::UserParam');
            $keys = array_map('strval', array_keys($opts['UserParam']));
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                if (!str_ends_with($key, '#')) continue;
                if ($doMsg) {
                    $this->VPrint(0, "UserParam tags:\n");
                    $doMsg = null;
                }
                $this->HandleTag($table, $key, $opts['UserParam'][$key]);
            }
        }
        if ($opts['Composite'] and (!$opts['FastScan'] or $opts['FastScan'] < 5)) {
            // build all composite tags except those requiring tags from alternate files
            $this->BuildCompositeTags();
        }
        $g8s = array_map('strval', array_keys($this->ALT_EXIFTOOL ?? []));
        sort($g8s, SORT_STRING);
        foreach ($g8s as $g8) {
            $altExifTool = $this->ALT_EXIFTOOL[$g8];
            if ($altExifTool->DID_EXTRACT) continue;    // avoid extracting twice
            $altExifTool->OPTIONS = $this->OPTIONS;
            $altExifTool->GLOBAL_TIME_OFFSET = $this->GLOBAL_TIME_OFFSET;
            $altExifTool->REQ_TAG_LOOKUP = $this->REQ_TAG_LOOKUP;
            $altExifTool->ReqTagAlreadySet = 1;
            $fileName = $altExifTool->ALT_FILE;
            // allow tags from the main file to be used in the alternate file names
            // (eg. -file1 '$originalfilename')
            if (str_contains((string) $fileName, '$')) {
                $tags = array_map('strval', array_keys($this->VALUE));
                rsort($tags, SORT_STRING);
                $fileName = $this->InsertTagValues($fileName, $tags, 'Warn');
                if ($fileName === null) continue;
            }
            $altExifTool->ExtractInfo($fileName);
            $err = $altExifTool->VALUE['Error'] ?? null;
            if ($err) $this->Warn("$err \"$fileName\"");
            // set family 8 group name for all tags
            foreach (array_keys($altExifTool->VALUE) as $key) $altExifTool->TAG_EXTRA[$key]['G8'] = $g8;
            // prepare our sorted list of found tags
            $altExifTool->FoundTags = $altExifTool->SetFoundTags();
            $altExifTool->DID_EXTRACT = 1;
        }
        // if necessary, build composite tags that rely on tags from alternate files
        if ($this->DoAltComposite) $this->BuildCompositeTags(1);
    }

    //------------------------------------------------------------------------------
    // Get tag table name
    // Inputs: 0) ExifTool object reference, 1) tag key
    // Returns: Table name if available, otherwise ''
    public function GetTableName($tag)
    {
        $tagInfo = $this->TAG_INFO[$tag] ?? null;
        if (!$tagInfo) return '';
        return $tagInfo->Table ? $tagInfo->Table['SHORT_NAME'] : null;
    }

    //------------------------------------------------------------------------------
    // Get tag index number
    // Inputs: 0) ExifTool object reference, 1) tag key
    // Returns: Table index number, or undefined if this tag isn't indexed
    public function GetTagIndex($tag)
    {
        $tagInfo = $this->TAG_INFO[$tag] ?? null;
        if (!$tagInfo) return null;
        return $tagInfo->Index;
    }

    //------------------------------------------------------------------------------
    // Find value for specified tag
    // Inputs: 0) ExifTool ref, 1) tag name, 2) tag group (family 1)
    // Returns: value or undef
    public function FindValue($tag, $grp)
    {
        $val = null;
        for ($i = 0; ; ++$i) {
            $key = $tag . ($i ? " ($i)" : '');
            if (!isset($this->VALUE[$key])) break;
            if ($this->GetGroup($key, 1) === $grp) {
                $val = $this->VALUE[$key];
                break;
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Get tag key for next existing tag
    // Inputs: 0) ExifTool ref, 1) tag key or case-sensitive tag name
    // Returns: Key of next existing tag, or undef if no more
    // Notes: This routine is provided for iterating through duplicate tags in the
    //        ValueConv of Composite tags.
    public function NextTagKey($tag)
    {
        $tag = (string) $tag;
        if (preg_match('/ \((\d+)\)$/', $tag, $m)) {
            $i = $m[1] + 1;
            $tag = substr($tag, 0, -strlen($m[0]));
        } else {
            $i = 1;
        }
        $tag = "$tag ($i)";
        if (isset($this->VALUE[$tag])) return $tag;
        return null;
    }

    //------------------------------------------------------------------------------
    // Does a string contain valid UTF-8 characters?
    // Inputs: 0) string reference, 1) true to allow last character to be truncated
    // Returns: 0=regular ASCII, -1=invalid UTF-8, 1=valid UTF-8 with maximum 16-bit
    //          wide characters, 2=valid UTF-8 requiring 32-bit wide characters
    // Notes: Changes current string position
    // (see http://www.fileformat.info/info/unicode/utf8.htm for help understanding this)
    // (PHP: takes the string itself)
    public static function IsUTF8($strPt, $trunc = null)
    {
        $str = Perl::str($strPt);
        if (!preg_match('/[\x80-\xff]/', $str, $m, PREG_OFFSET_CAPTURE)) return 0;
        $rtnVal = 1;
        $pos = $m[0][1];
        for (;;) {
            $ch = ord($str[$pos]);
            // minimum lead byte for 2-byte sequence is 0xc2 (overlong sequences
            // not allowed), 0xf8-0xfd are restricted by RFC 3629 (no 5 or 6 byte
            // sequences), and 0xfe and 0xff are not valid in UTF-8 strings
            if ($ch < 0xc2 or $ch >= 0xf8) return -1;
            // determine number of bytes remaining in sequence
            if ($ch < 0xe0) {
                $n = 1;
            } elseif ($ch < 0xf0) {
                $n = 2;
            } else {
                $n = 3;
                // character code is greater than 0xffff if more than 2 extra bytes
                // were required in the UTF-8 character
                $rtnVal = 2;
            }
            $pos += 1;
            if (!preg_match('/\G([\x80-\xbf]{' . $n . '})/', $str, $mm, 0, $pos)) {
                if ($trunc and $pos + $n > strlen($str)) return $rtnVal;
                return -1;
            }
            $c1 = ord($mm[1][0]);
            // the following is ref https://www.cl.cam.ac.uk/%7Emgk25/ucs/utf8_check.c
            if ($n == 2) {
                if (($ch == 0xe0 and ($c1 & 0xe0) == 0x80) or
                    ($ch == 0xed and ($c1 & 0xe0) == 0xa0) or
                    ($ch == 0xef and $c1 == 0xbf and (ord($mm[1][1]) & 0xfe) == 0xbe)) return -1;
            } else {
                if (($ch == 0xf0 and ($c1 & 0xf0) == 0x80) or
                    ($ch == 0xf4 and $c1 > 0x8f) or $ch > 0xf4) return -1;
            }
            $pos += $n;
            if (!preg_match('/[\x80-\xff]/', $str, $m, PREG_OFFSET_CAPTURE, $pos)) break;
            $pos = $m[0][1];
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Split file name into directory and name parts
    // Inptus: 0) file name
    // Returns: 0) directory, 1) filename
    public static function SplitFileName($file): array
    {
        return [dirname($file), basename($file)];
    }

    //------------------------------------------------------------------------------
    // Encode file name for calls to system i/o routines
    // Inputs: 0) ExifTool ref, 1) file name in CharsetFileName encoding,
    //         2) flag to force conversion even if no special characters
    // Returns: true if Windows Unicode routines should be used (in which case
    //          the file name will be encoded as a null-terminated UTF-16LE string)
    // (PHP: never uses Windows wide-character routines)
    public function EncodeFileName(&$file, $force = null)
    {
        if ($file === '-') return 0;    // special case for stdin pipe
        $enc = $this->OPTIONS['CharsetFileName'];
        $hasSpecialChars = preg_match('/[\x80-\xff]/', (string) $file);
        if ($hasSpecialChars or $force or $this->OPTIONS['WindowsLongPath'] or $this->OPTIONS['WindowsWideFile']) {
            if (!$enc) $enc = 'UTF8';
            if ($enc !== 'UTF8') {
                // recode as UTF-8 for other platforms if necessary
                $file = $this->Decode($file, $enc, null, 'UTF8');
            }
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Rebuild a path as an absolute long path to be usable in Windows system calls
    // (PHP: Windows long paths not supported, the path is returned unchanged)
    public function WindowsLongPath($path)
    {
        return $path;
    }

    //------------------------------------------------------------------------------
    // Modified perl open() routine to properly handle special characters in file names
    // Inputs: 0) ExifTool ref, 1) filehandle, 2) filename,
    //         3) mode: '<' or undef = read, '>' = write, '+<' = update
    // Returns: true on success
    // (PHP: $fh receives the stream resource; piped commands aren't supported)
    public function Open(&$fh, $file, $mode = null)
    {
        $file = Perl::str($file);
        $file = preg_replace('/^([\s&])/', './$1', $file); // protect leading whitespace or ampersand
        // default to read mode ('<') unless input is a trusted pipe
        if (!$mode) $mode = (preg_match('/\|$/', $file) and $this->TRUST_PIPE) ? '' : '<';
        $this->TRUST_PIPE = null;
        if ($mode === '') return false;     // (ponytail: pipes not supported)
        $this->EncodeFileName($file);
        if ($file === '-') {
            $fh = @fopen($mode === '<' ? 'php://stdin' : 'php://stdout', $mode === '<' ? 'rb' : 'wb');
        } else {
            $phpMode = match ($mode) { '>' => 'wb', '>>' => 'ab', '+<' => 'r+b', default => 'rb' };
            $fh = @fopen($file, $phpMode);
        }
        if ($fh === false) {
            $fh = null;
            return false;
        }
        return true;
    }

    //------------------------------------------------------------------------------
    // Check to see if a file exists (with Windows Unicode support)
    // Inputs: 0) ExifTool ref, 1) file name, 2) flag if we are writing this file
    // Returns: true if file exists
    public function Exists($file, $writing = null)
    {
        $this->EncodeFileName($file);
        if ($writing) {
            // (named pipes already exist, but we pretend that they don't
            //  so we will be able to write them, so test for pipe with -p)
            return file_exists($file) and filetype($file) !== 'fifo';
        }
        return file_exists($file);
    }

    //------------------------------------------------------------------------------
    // Return true if file is a directory (with Windows Unicode support)
    // Inputs: 0) ExifTool ref, 1) file name
    // Returns: true if file is a directory (false if file isn't, or doesn't exist)
    public function IsDirectory($file)
    {
        $this->EncodeFileName($file);
        return is_dir((string) $file);
    }

    //------------------------------------------------------------------------------
    // Create directory for specified file
    // Inputs: 0) ExifTool ref, 1) complete file name including path
    // Returns: '' = directory created, undef = nothing done, otherwise error string
    // (PHP: no Windows wide-character routines, see EncodeFileName)
    public function CreateDirectory($file)
    {
        $err = null;
        $dir = preg_replace('{[^/]*$}', '', (string) $file, 1);   // remove filename from path specification
        if ($dir and !$this->IsDirectory($dir)) {
            $parts = Perl::split('{/}', $dir);
            $dir = '';
            foreach ($parts as $part) {
                $dir .= $part;
                if (strlen($part) and !$this->IsDirectory($dir) and
                    // don't try to create a network drive root directory
                    !(self::IsPC() and preg_match('{^//[^/]*$}', $dir)))
                {
                    // create directory since it doesn't exist
                    $d2 = $dir; // (must make a copy in case EncodeFileName recodes it)
                    $this->EncodeFileName($d2);
                    $success = @mkdir($d2, 0777);
                    if (!$success) {
                        $err = "Error creating directory $dir";
                        break;
                    }
                    $err = '';
                }
                $dir .= '/';
            }
        }
        return $err;
    }

    //------------------------------------------------------------------------------
    // Get file times (Unix seconds since the epoch)
    // Inputs: 0) ExifTool ref, 1) file name or ref
    // Returns: 0) access time, 1) modification time, 2) creation time (or undefs on error)
    //          (PHP: a list, empty on error)
    public function GetFileTime($file): array
    {
        // open file by name if necessary
        if (!is_resource($file) and !is_object($file)) {
            $fh = null;
            if (!$this->Open($fh, $file)) {
                if ($this->IsDirectory($file)) {
                    clearstatcache(true, (string) $file);
                    $stat = @stat($file);
                    if ($stat) return [$stat[8], $stat[9], $stat[10]];
                }
                $this->Warn("GetFileTime error for '$file'");
                return [];
            }
            $file = $fh;    // (closed when $fh goes out of scope)
        }
        // (PHP: the Win32::API branch for Windows file times doesn't apply)
        // other os (or Windows fallback)
        $stat = is_resource($file) ? @fstat($file) : false;
        return $stat ? [$stat[8], $stat[9], $stat[10]] : [];
    }

    //------------------------------------------------------------------------------
    // Parse function arguments and set member variables accordingly
    // Inputs: Same as ImageInfo()
    // - sets REQUESTED_TAGS, REQ_TAG_LOOKUP, IO_TAG_LIST, FILENAME, RAF, OPTIONS
    public function ParseArguments(...$args): void
    {
        $oldGroupOpts = array_values(preg_grep('/^Group/', array_map('strval', array_keys($this->OPTIONS))));
        $exclude = [];
        $wasExcludeOpt = null;

        $this->REQUESTED_TAGS  = [];
        if (!$this->ReqTagAlreadySet) $this->REQ_TAG_LOOKUP = [];
        $this->EXCL_TAG_LOOKUP = [];
        $this->IO_TAG_LIST = null;
        $this->EXCL_XMP_LOOKUP = null;

        // handle our input arguments
        foreach ($args as $arg) {
            if (is_array($arg) or is_resource($arg) or (is_object($arg) and !$arg instanceof \Stringable)) {
                if (is_array($arg) and array_is_list($arg)) {
                    $this->IO_TAG_LIST = $arg;
                    foreach ($arg as $t) {
                        if (preg_match('/^-(.*)/', (string) $t, $m)) {
                            $exclude[] = $m[1];
                        } else {
                            $this->REQUESTED_TAGS[] = $t;
                        }
                    }
                } elseif (is_array($arg)) {
                    foreach ($arg as $opt => $val) {
                        // a single new group option overrides all old group options
                        if ($oldGroupOpts and preg_match('/^Group/', (string) $opt)) {
                            foreach ($oldGroupOpts as $g) {
                                unset($this->OPTIONS[$g]);
                            }
                            $oldGroupOpts = [];
                        }
                        $this->Options($opt, $val);
                        if ($opt === 'Exclude') $wasExcludeOpt = 1;
                    }
                } elseif ($arg instanceof ScalarRef or is_resource($arg)) {
                    if ($this->RAF !== null) continue;
                    $this->RAF = new RandomAccess($arg instanceof ScalarRef ? Perl::str($arg->value) : $arg);
                    // set filename to empty string to indicate that
                    // we have a file but we didn't open it
                    $this->FILENAME = '';
                } elseif ($arg instanceof RandomAccess) {
                    $this->RAF = $arg;
                    $this->FILENAME = '';
                } else {
                    self::PerlWarn("Don't understand ImageInfo argument " . get_class($arg) . "\n");
                }
            } elseif ($this->FILENAME !== null) {
                if (preg_match('/^-(.*)/', (string) $arg, $m)) {
                    $exclude[] = $m[1];
                } else {
                    $this->REQUESTED_TAGS[] = (string) $arg;
                }
            } else {
                $this->FILENAME = $arg === null ? null : (string) $arg;
            }
        }
        // add additional requested tags to lookup
        if ($this->OPTIONS['RequestTags']) {
            foreach ($this->OPTIONS['RequestTags'] as $t) $this->REQ_TAG_LOOKUP[$t] = 1;
        }
        // expand shortcuts in tag arguments if provided
        if ($this->REQUESTED_TAGS) {
            self::ExpandShortcuts($this->REQUESTED_TAGS);
            // initialize lookup for requested tags
            foreach ($this->REQUESTED_TAGS as $t) {
                if (!preg_match('/^(.*:)?([-\w?*]*)#?$/', (string) $t, $m)) continue;
                if (!empty($m[2])) $this->REQ_TAG_LOOKUP[strtolower($m[2])] = 1;
                if (empty($m[1])) continue;
                foreach (Perl::split('/:/', $m[1]) as $g) $this->REQ_TAG_LOOKUP[strtolower($g) . ':'] = 1;
            }
        }
        if ($exclude or $wasExcludeOpt) {
            // must add existing excluded tags
            if ($this->OPTIONS['Exclude']) array_push($exclude, ...$this->OPTIONS['Exclude']);
            $this->OPTIONS['Exclude'] = $exclude;
            // expand shortcuts in new exclude list
            self::ExpandShortcuts($this->OPTIONS['Exclude'], 1); // (also remove '#' suffix)
        }
        // generate lookup for excluded tags
        if ($this->OPTIONS['Exclude']) {
            foreach ($this->OPTIONS['Exclude'] as $e) {
                if (preg_match('/([-\w]+)#?$/', (string) $e, $m)) $this->EXCL_TAG_LOOKUP[strtolower($m[1])] = 1;
                if (preg_match('/(xmp-.*:[-\w]+)#?/i', (string) $e, $m)) {
                    if (!$this->EXCL_XMP_LOOKUP) $this->EXCL_XMP_LOOKUP = [];
                    $this->EXCL_XMP_LOOKUP[strtolower($m[1])] = 1;
                }
            }
            // exclude list is used only for EXCL_TAG_LOOKUP when TAGS_FROM_FILE is set
            if ($this->TAGS_FROM_FILE) $this->OPTIONS['Exclude'] = null;
        }
    }

    //------------------------------------------------------------------------------
    // Does group name match the tag ID?
    // Inputs: 0) tag ID, 1) group name (with "ID-" removed)
    // Returns: true on success
    public static function IsSameID($id, $grp)
    {
        $id = Perl::str($id);
        $grp = Perl::str($grp);
        for (;;) {
            if ($grp === $id) return 1;     // decimal ID's or raw ID's
            if (preg_match('/^\d+$/', $id)) {   // numerical numerical ID's may be in hex
                if (preg_match('/^0x0*/', $grp, $m) and substr($grp, strlen($m[0])) === Perl::sprintf('%x', $id)) return 1;
            } else {                        // other ID's may conform to ExifTool group name conventions
                $n = 0;
                $tmp = preg_replace_callback('/([^-_A-Za-z0-9])/', static fn ($c) => sprintf('%.2x', ord($c[1])), $id, -1, $n);
                if ($n and $grp === $tmp) return 1;
            }
            if (!preg_match('/-.*/s', $id)) break;  // remove language code if it exists
            $id = preg_replace('/-.*/s', '', $id, 1);
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Get list of tags in specified group
    // Inputs: 0) ExifTool ref, 1) group spec (case insensitive), 2) tag key or reference to list of tag keys
    // Returns: list of matching tags in list context, or first match in scalar context
    //          (PHP: list context is GroupMatchesList)
    // Notes: Group spec may contain multiple groups separated by colons, each
    //        possibly with a leading family number
    public function GroupMatches($group, $tagList)
    {
        return $this->DoGroupMatches($group, $tagList, false);
    }

    /** GroupMatches in list context */
    public function GroupMatchesList($group, $tagList): array
    {
        return $this->DoGroupMatches($group, $tagList, true);
    }

    private function DoGroupMatches($group, $tagList, bool $wantarray)
    {
        if (!is_array($tagList)) $tagList = [$tagList];
        $matches = [];
        // check each group name individually (eg. "Author:1IPTC")
        $grps = Perl::split('/:/', $group);
        $fmys = [];
        for ($g = 0; $g < count($grps); ++$g) {
            if (preg_match('/^(\d*)(id-)?/i', $grps[$g], $m)) {
                $grps[$g] = substr($grps[$g], strlen($m[0]));
                if (strlen($m[1])) $fmys[$g] = $m[1];
                if (!empty($m[2])) {
                    $fmys[$g] = 7;
                    continue;   // (don't convert tag ID's to lower case)
                }
            }
            $grps[$g] = strtolower($grps[$g]);
            if ($grps[$g] === 'copy0') $grps[$g] = '';   // accept 'Copy0' for primary tag
        }
        foreach ($tagList as $tag) {
            $groups = $this->GetGroup($tag, -1);
            for ($g = 0; $g < count($grps); ++$g) {
                $grp = $grps[$g];
                if ($grp === '*' or $grp === 'all') continue;
                if (isset($fmys[$g])) {
                    $f = $fmys[$g];
                    if (!isset($groups[$f])) break;
                    if ($f == 7) {
                        if (self::IsSameID($this->GetTagID($tag), $grp)) continue;
                    } else {
                        if ($grp === strtolower((string) $groups[$f])) continue;
                    }
                    break;
                } else {
                    $rx = self::Rx("^$grp\$", 'i');
                    $found = false;
                    foreach ($groups as $gr) {
                        if ($gr !== null and @preg_match($rx, (string) $gr)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) break;
                }
            }
            if ($g == count($grps)) {
                if (!$wantarray) return $tag;
                $matches[] = $tag;
            }
        }
        return $wantarray ? $matches : ($matches[0] ?? null);
    }

    //------------------------------------------------------------------------------
    // Remove specified tags from returned tag list, updating indices in other lists
    // Inputs: 0) tag list ref, 1) index list ref, 2) index list ref, 3) hash ref,
    //         4) true to include tags from hash instead of excluding
    // Returns: nothing, but updates input lists
    public static function RemoveTagsFromList(array &$tags, array &$list1, array &$list2, array $exclude, $inv = null): void
    {
        $filteredTags = [];

        if ($list1 or $list2) {
            while ($tags) {
                $tag = array_pop($tags);
                $i = count($tags);
                if ((!empty($exclude[$tag])) xor (bool) $inv) {
                    // remove index of excluded tag from each list
                    $fix = static function (array $list) use ($i): array {
                        $out = [];
                        foreach ($list as $x) {
                            if ($x < $i) {
                                $out[] = $x;
                            } elseif ($x != $i) {
                                $out[] = $x - 1;
                            }
                        }
                        return $out;
                    };
                    $list1 = $fix($list1);
                    $list2 = $fix($list2);
                } else {
                    array_unshift($filteredTags, $tag);
                }
            }
        } else {
            foreach ($tags as $tag) {
                if (!((!empty($exclude[$tag])) xor (bool) $inv)) $filteredTags[] = $tag;
            }
        }
        $tags = $filteredTags;  // update tag list
    }

    //------------------------------------------------------------------------------
    // Copy tags from alternate input file
    // Inputs: 0) ExifTool ref, 1) family 8 group, 2) list ref for tag keys to copy
    // - updates tag key list to match keys newly added to $self
    public function CopyAltInfo($g8, array &$tags): void
    {
        if (!preg_match('/(\d+)/', (string) $g8, $m)) return;
        $et = $this->ALT_EXIFTOOL[$g8] ?? null;
        if (!$et) return;
        $altOrder = ($m[1] + 1) * 100000;  // increment file order
        foreach ($tags as &$tag) {
            $vtag = preg_replace('/( |$)/', " #[$g8]", $tag, 1);
            if (!isset($this->VALUE[$vtag])) {
                $this->VALUE[$vtag] = $et->VALUE[$tag] ?? null;
                $this->TAG_INFO[$vtag] = $et->TAG_INFO[$tag] ?? null;
                $this->TAG_EXTRA[$vtag] = $et->TAG_EXTRA[$tag] ?? [];
                $this->FILE_ORDER[$vtag] = ($et->FILE_ORDER[$tag] ?? 0) + $altOrder;
            }
            $tag = $vtag;
        }
        unset($tag);
    }

    //------------------------------------------------------------------------------
    // Set list of found tags from previously requested tags
    // Inputs: 0) ExifTool object reference
    // Returns: 0) Reference to list of found tag keys (in order of requested tags)
    //          1) Reference to list of indices for tags requested by value
    //          2) Reference to list of indices for tags specified by wildcard or "all"
    //          (PHP: SetFoundTags returns 0), SetFoundTagsList returns the 3 lists)
    // Notes: index lists are returned in increasing order
    public function SetFoundTags(): array
    {
        return $this->SetFoundTagsList()[0];
    }

    public function SetFoundTagsList(): array
    {
        $options = $this->OPTIONS;
        $reqTags = $this->REQUESTED_TAGS ?: [];
        $duplicates = $options['Duplicates'];
        $exclude = $options['Exclude'];
        $fileOrder = $this->FILE_ORDER;
        $groupOptions = [];
        // ignore empty group options
        $groupKeys = array_values(preg_grep('/^Group/', array_map('strval', array_keys($options))));
        sort($groupKeys, SORT_STRING);
        foreach ($groupKeys as $key) {
            if ($options[$key]) $groupOptions[] = $key;
        }
        $doDups = ($duplicates or $exclude or $groupOptions);
        $rtnTags = null;
        $byValue = $wildTags = [];

        // only return requested tags if specified
        if ($reqTags) {
            $rtnTags = [];
            // scan through the requested tags and generate a list of tags we found
            $tagHash = $this->VALUE;
            foreach ($reqTags as $reqTag) {
                $reqTag = (string) $reqTag;
                $matches = [];
                $group = $allGrp = $allTag = $byVal = $g8 = null;
                $et = $this;
                if (preg_match('/^(.*):(.+)/', $reqTag, $m)) {
                    [$group, $tag] = [$m[1], $m[2]];
                    if (preg_match('/^(\*|all)$/i', $group)) {
                        $allGrp = 1;
                    } elseif (preg_match('/\bfile(\d+):/i', $reqTag, $mm)) {
                        $g8 = "File$mm[1]";
                        $et = $this->ALT_EXIFTOOL[$g8] ?? $this;
                        $fileOrder = $et->FILE_ORDER;
                        $tagHash = $et->VALUE;
                    } elseif (!preg_match('/^[-\w:]*$/', $group)) {
                        $this->Warn("Invalid group name '{$group}'");
                        $group = 'invalid';
                    }
                } else {
                    $tag = $reqTag;
                }
                if (str_ends_with($tag, '#')) {
                    $tag = substr($tag, 0, -1);
                    if ($options['PrintConv']) $byVal = 1;
                }
                $keys = array_map('strval', array_keys($tagHash));
                if (isset($tagHash[$reqTag]) and !$doDups) {
                    $matches[0] = $tag;
                } elseif (preg_match('/^(\*|all)$/i', $tag)) {
                    // tag name of '*' or 'all' matches all tags
                    if ($doDups or $allGrp) {
                        $matches = array_values(preg_grep('/#/', $keys, PREG_GREP_INVERT));
                    } else {
                        $matches = array_values(preg_grep('/ /', $keys, PREG_GREP_INVERT));
                    }
                    if (!$matches) continue;    // don't want entry in list for '*' tag
                    $allTag = 1;
                } elseif (preg_match('/[*?]/', $tag)) {
                    // allow wildcards in tag names
                    $tag = preg_replace('/[^-_A-Za-z0-9*?]/', '', $tag); // sterilize
                    $tag = str_replace(['*', '?'], ['[-\w]*', '[-\w]'], $tag);
                    if ($doDups or $allGrp) $tag .= '( \(.*)?';
                    $matches = array_values(preg_grep("/^$tag\$/i", $keys));
                    if (!$matches) continue;    // don't want entry in list for wildcard tags
                    $allTag = 1;
                } elseif ($doDups or $group !== null) {
                    $tag = preg_replace('/[^-_A-Za-z0-9]/', '', $tag); // sterilize
                    // must also look for tags like "Tag (1)"
                    // (but be sure not to match temporary ValueConv entries like "Tag #")
                    $matches = array_values(preg_grep("/^$tag( \\(|\$)/i", $keys));
                } elseif (preg_match('/^[-\w]+$/', $tag)) {
                    // find first matching value
                    // (use in list context to return value instead of count)
                    $matches = array_values(preg_grep("/^$tag\$/i", $keys));
                    $matches = $matches ? [$matches[0]] : [];
                } else {
                    $this->Warn("Invalid tag name '{$tag}'");
                }
                if ($group !== null and !$allGrp) {
                    // keep only specified group
                    $matches = $et->GroupMatchesList($group, $matches);
                    if (!$matches and $allTag) continue;
                }
                if (count($matches) > 1) {
                    // maintain original file order for multiple tags
                    $fo = $fileOrder;
                    usort($matches, static fn ($a, $b) => ($fo[$a] ?? 0) <=> ($fo[$b] ?? 0));
                    // return only the highest priority tag unless duplicates wanted
                    if (!($doDups or $allTag or $allGrp)) {
                        $tag = array_shift($matches);
                        $oldPriority = ($et->PRIORITY[$tag] ?? null) ?: 1;
                        foreach ($matches as $mt) {
                            $priority = $et->PRIORITY[$mt] ?? null;
                            if ($priority === null) $priority = 1;
                            if (!($priority >= $oldPriority)) continue;
                            $tag = $mt;
                            $oldPriority = $priority ?: 1;
                        }
                        $matches = [$tag];
                    }
                } elseif (!$matches) {
                    // put entry in return list even without value (value is undef)
                    $matches[0] = $byVal ? "$tag #(0)" : "$tag (0)";
                    // bogus file order entry to avoid warning if sorting in file order
                    $this->FILE_ORDER[$matches[0]] = 999999999;
                }
                // copy over necessary information for tags from alternate files
                if ($g8) {
                    $this->CopyAltInfo($g8, $matches);
                    // restore variables to original values for main file
                    $fileOrder = $this->FILE_ORDER;
                    $tagHash = $this->VALUE;
                }
                // save indices of tags extracted by value
                if ($byVal) {
                    for ($i = count($rtnTags); $i < count($rtnTags) + count($matches); ++$i) $byValue[] = $i;
                }
                // save indices of wildcard tags
                if ($allTag) {
                    for ($i = count($rtnTags); $i < count($rtnTags) + count($matches); ++$i) $wildTags[] = $i;
                }
                array_push($rtnTags, ...$matches);
            }
        } else {
            // no requested tags, so we want all tags
            $keys = array_map('strval', array_keys($this->VALUE));
            if ($doDups) {
                $allTags = $keys;
            } else {
                // only include tag if it doesn't end in a copy number
                $allTags = array_values(preg_grep('/ /', $keys, PREG_GREP_INVERT));
            }
            $rtnTags = $allTags;
        }

        // filter excluded tags and group options
        while (($exclude or $groupOptions) and $rtnTags) {
            if ($exclude) {
                $excl = [];
                foreach ($exclude as $pat) {
                    $pat = (string) $pat;
                    $group = null;
                    if (preg_match('/^(.*):(.+)/', $pat, $m)) {
                        [$group, $tag] = [$m[1], $m[2]];
                        if (preg_match('/^(\*|all)$/i', $group)) {
                            $group = null;
                        } elseif (!preg_match('/^[-\w:]*$/', $group)) {
                            $this->Warn("Invalid group name '{$group}'");
                            $group = 'invalid';
                        }
                    } else {
                        $tag = $pat;
                    }
                    if (preg_match('/^(\*|all)$/i', $tag)) {
                        $matches = $rtnTags;
                    } else {
                        // allow wildcards in tag names
                        $tag = str_replace(['*', '?'], ['[-\w]*', '[-\w]'], $tag);
                        $matches = array_values(@preg_grep(self::Rx("^$tag( |\$)", 'i'), $rtnTags) ?: []);
                    }
                    if ($group and $matches) $matches = $this->GroupMatchesList($group, $matches);
                    foreach ($matches as $mt) $excl[$mt] = 1;
                }
                if ($excl) {
                    // remove excluded tags from return list(s)
                    self::RemoveTagsFromList($rtnTags, $byValue, $wildTags, $excl);
                    if (!$rtnTags) break;   // all done if nothing left
                }
                if ($duplicates and !$groupOptions) break;
            }
            // filter groups if requested, or to remove duplicates
            $keepTags = $wantGroup = [];
            $allGroups = 1;
            // build hash of requested/excluded group names for each group family
            $wantOrder = 0;
            foreach ($groupOptions as $groupOpt) {
                if (!preg_match('/^Group(\d*(:\d+)*)/', $groupOpt, $m)) continue;
                $family = $m[1] ?: 0;
                $wantGroup[$family] ??= [];
                $groupList = is_array($options[$groupOpt]) ? $options[$groupOpt] : [$options[$groupOpt]];
                foreach ($groupList as $gl) {
                    // groups have priority in order they were specified
                    ++$wantOrder;
                    if (preg_match('/^-(.*)/', (string) $gl, $mm)) {
                        // excluded group begins with '-'
                        $groupName = $mm[1];
                        $want = 0;          // we don't want tags in this group
                    } else {
                        $groupName = (string) $gl;
                        $want = $wantOrder; // we want tags in this group
                        $allGroups = 0;     // don't want all groups if we requested one
                    }
                    $wantGroup[$family][$groupName] = $want;
                }
            }
            // loop through all tags and decide which ones we want
            $bestTag = [];
GR_TAG:     foreach ($rtnTags as $tag) {
                $wantTag = $allGroups;  // want tag by default if want all groups
                foreach ($wantGroup as $family => $wg) {
                    $group = (string) $this->GetGroup($tag, $family);
                    $wanted = $wg[$group] ?? null;
                    if ($wanted === null) continue;
                    if (!$wanted) continue 2;   // skip tag if group excluded
                    // take lowest non-zero want flag
                    if ($wantTag and $wantTag < $wanted) continue;
                    $wantTag = $wanted;
                }
                if (!$wantTag) continue;
                if ($duplicates) {
                    $keepTags[$tag] = 1;
                    continue;
                }
                // determine which tag we want to keep
                $tagName = self::GetTagName($tag);
                $bt = $bestTag[$tagName] ?? null;
                if ($bt !== null) {
                    if ($wantTag > $keepTags[$bt]) continue;
                    if ($wantTag == $keepTags[$bt]) {
                        // want two tags with the same name -- keep the latest one
                        if (preg_match('/ \((\d+)\)$/', $tag, $m)) {
                            $tagNum = $m[1];
                            if (!preg_match('/ \((\d+)\)$/', $bt, $m2) or $m2[1] > $tagNum) continue;
                        }
                    }
                    // this tag is better, so delete old best tag
                    unset($keepTags[$bt]);
                }
                $keepTags[$tag] = $wantTag;     // keep this tag (for now...)
                $bestTag[$tagName] = $tag;      // this is our current best tag
            }
            // include only tags we want to keep in return lists
            self::RemoveTagsFromList($rtnTags, $byValue, $wildTags, $keepTags, 1);
            break;
        }
        $this->FOUND_TAGS = $rtnTags;   // save found tags

        // return reference to found tag keys (and list of indices of tags to extract by value)
        return [$rtnTags, $byValue, $wildTags];
    }

    //------------------------------------------------------------------------------
    // Add cleanup routine to call before returning from Extract
    // Inputs: 0) ExifTool ref, 1) code ref to routine with ExifTool ref as an argument
    public function AddCleanup(callable $sub): void
    {
        if (!$this->Cleanup) $this->Cleanup = [];
        $this->Cleanup[] = $sub;
    }

    //------------------------------------------------------------------------------
    // Add warning tag
    // Inputs: 0) ExifTool object reference, 1) warning message
    //         2) 0=normal warning, 1=minor, 2=minor with behavioural change when
    //            ignored, 3=warning shouldn't be issued with Validate option,
    //            bit 0x04 set causes warning count to not be incremented
    // Returns: true if warning tag was added
    public function Warn($str, $ignorable = null)
    {
        $noWarn = $this->OPTIONS['NoWarning'] ?? null;
        $noCount = null;
        $str = Perl::str($str);
        while ($ignorable) {
            if ($ignorable & 0x04) {
                $noCount = 1;
                $ignorable &= 0x03;
                if (!$ignorable) break;
            }
            $ign = $ignorable & 0x03;
            if ($this->OPTIONS['IgnoreMinorErrors']) return 0;
            if ($ign == 3 and $this->OPTIONS['Validate']) return 0;
            if ($noWarn !== null and @preg_match(self::Rx($noWarn), $str)) return 1;
            $str = $ign == 2 ? "[Minor] $str" : "[minor] $str";
            break;
        }
        if (!($noWarn !== null and @preg_match(self::Rx($noWarn), $str))) {
            // add each warning only once but count number of occurrences
            if (!empty($this->WAS_WARNED[$str])) {
                if (!$noCount) ++$this->WAS_WARNED[$str];
            } else {
                $this->FoundTag('Warning', $str);
                $this->WAS_WARNED[$str] = 1;
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Add error tag
    // Inputs: 0) ExifTool object reference, 1) error message, 2) true if minor
    // Returns: true if error tag was added, otherwise warning was added
    public function Error($str, $ignorable = null)
    {
        if ($this->DemoteErrors) {
            if ($this->Warn($str)) ++$this->DemoteErrors;
            return 1;
        } elseif ($ignorable) {
            if ($this->OPTIONS['IgnoreMinorErrors']) {
                $this->Warn($str);
                return 0;
            }
            $str = "[minor] $str";
        }
        $this->FoundTag('Error', $str);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Expand shortcuts
    // Inputs: 0) reference to list of tags, 1) set to remove trailing '#'
    // Notes: Handles leading '-' for excluded tags, trailing '#' for ValueConv,
    //        multiple group names, and redirected tags
    public static function ExpandShortcuts(&$tagList, $removeSuffix = null): void
    {
        if (!$tagList) return;

        $shortcuts = Vars\Shortcuts::$Main;
        $findShortcut = static function ($name) use ($shortcuts) {
            foreach (array_keys($shortcuts) as $key) {
                if (strcasecmp((string) $key, (string) $name) === 0) return $key;
            }
            return null;
        };

        // expand shortcuts
        $suffix = $removeSuffix ? '' : '#';
        $expandedTags = [];
        foreach ($tagList as $entry) {
            // skip things like options hash references in list
            if (is_array($entry) or is_object($entry)) {
                $expandedTags[] = $entry;
                continue;
            }
            // remove leading '-'
            preg_match('/^(-?)(.*)/s', (string) $entry, $m);
            [$excl, $tag] = [$m[1], $m[2]];
            $post = $pre = $v = null;
            $postList = [];
            // handle redirection
            if (!$excl and preg_match('/(.+?)([-+]?[<>].+)/s', $tag, $m)) {
                [$tag, $post] = [$m[1], $m[2]];
                if (preg_match('/^[-+]?>/', $post) or !str_contains($post, '$')) {
                    // expand shortcuts in postfix (rhs of redirection)
                    preg_match('/([-+]?[<>])(.+:)?(.+)/', $post, $m);
                    [$op, $p2, $t2] = [$m[1] ?? '', $m[2] ?? '', $m[3] ?? ''];
                    $v = '';
                    if (str_ends_with($t2, '#')) {
                        $t2 = substr($t2, 0, -1);
                        $v = $suffix;   // ValueConv suffix
                    }
                    $match = $findShortcut($t2);
                    if ($match !== null) {
                        foreach ($shortcuts[$match] as $s) {
                            if (preg_match('/^-/', $s)) continue;   // ignore excluded tags
                            if ($p2 and preg_match('/(.+:)(.+)/', $s)) {
                                $postList[] = "$op$s$v";
                            } else {
                                $postList[] = "$op$p2$s$v";
                            }
                        }
                        if (!$postList) continue;
                        $post = array_shift($postList);
                    }
                }
            } else {
                $post = '';
            }
            // handle group names
            if (preg_match('/(.+:)(.+)/', $tag, $m)) {
                [$pre, $tag] = [$m[1], $m[2]];
            } else {
                $pre = '';
            }
            $v = '';
            if (str_ends_with($tag, '#')) {
                $tag = substr($tag, 0, -1);
                $v = $suffix;   // ValueConv suffix
            }
            // loop over all postfixes
            for (;;) {
                // expand the tag name
                $match = $findShortcut($tag);
                if ($match !== null) {
                    if ($excl) {
                        // entry starts with '-', so exclude all tags in this shortcut
                        foreach ($shortcuts[$match] as $s) {
                            if (preg_match('/^-/', $s)) continue;  // ignore excluded exclude tags
                            // group of expanded tag takes precedence
                            if ($pre and preg_match('/(.+:)(.+)/', $s)) {
                                $expandedTags[] = "$excl$s";
                            } else {
                                $expandedTags[] = "$excl$pre$s";
                            }
                        }
                    } elseif (strlen($pre) or strlen($post) or $v) {
                        foreach ($shortcuts[$match] as $s) {
                            preg_match('/(-?)(.+:)?(.+)/', $s, $mm);
                            if (!empty($mm[2])) {
                                // group from expanded tag takes precedence
                                $expandedTags[] = "$s$v$post";
                            } else {
                                $expandedTags[] = $mm[1] . $pre . $mm[3] . $v . $post;
                            }
                        }
                    } else {
                        array_push($expandedTags, ...$shortcuts[$match]);
                    }
                } else {
                    $expandedTags[] = "$excl$pre$tag$v$post";
                }
                if (!$postList) break;
                $post = array_shift($postList);
            }
        }
        $tagList = $expandedTags;
    }

    //------------------------------------------------------------------------------
    // Add hash of Composite tags to our composites
    // Inputs: 0) hash reference to table of Composite tags to add or module name,
    //         1) override existing tag definition
    public static function AddCompositeTags($add, $override = null): void
    {
        $module = null;
        if (!$add instanceof TagTable and !is_array($add)) {
            $prefix = preg_replace('/.*::/', '', (string) $add);
            $module = $add;
            $add = self::PackageTable($add . '::Composite');
            if (!$add) return;
            $prefix .= '-';
        } else {
            if (is_array($add)) $add = new TagTable($add);
            $prefix = 'UserDefined-';
        }
        $defaultGroups = $add['GROUPS'];
        $compTable = self::GetTagTable('Image::ExifTool::Composite');

        // make sure default groups are defined in families 0 and 1
        if ($defaultGroups) {
            if (empty($defaultGroups[0])) $defaultGroups[0] = 'Composite';
            if (empty($defaultGroups[1])) $defaultGroups[1] = 'Composite';
            if (empty($defaultGroups[2])) $defaultGroups[2] = 'Other';
        } else {
            $defaultGroups = [0 => 'Composite', 1 => 'Composite', 2 => 'Other'];
        }
        $add['GROUPS'] = $defaultGroups;
        self::SetupTagTable($add);    // generate Name, TagID, etc
        $tagIDs = array_map('strval', $add->keys());
        sort($tagIDs, SORT_STRING);
        foreach ($tagIDs as $tagID) {
            if (isset(Vars\ExifTool::$specialTags[$tagID])) continue;   // must skip special tags
            $tagInfo = $add[$tagID];
            if (!$tagInfo instanceof TagInfo) continue;
            $new = $prefix . $tagID;     // new tag ID for Composite table
            if ($tagInfo->Writable) $tagInfo->Module = $module;
            if ($override and $tagInfo->Override === null) $tagInfo->Override = 1;
            $tagInfo->IsComposite = 1;
            // handle Composite tags with the same name
            if (!empty(self::$compositeID[$tagID])) {
                // determine if we want to override this tag
                // (=0 keep both, >0 override, <0 keep existing)
                $old = $compTable[self::$compositeID[$tagID][0]];
                $over = ($tagInfo->Override ?: 0) - (($old instanceof TagInfo ? $old->Override : null) ?: 0);
                if ($over < 0) continue;
                if ($over) {
                    // remove existing tags with this ID
                    foreach (self::$compositeID[$tagID] as $id) unset($compTable[$id]);
                    unset(self::$compositeID[$tagID]);
                }
            }
            // make sure new TagID is unique by adding index if necessary
            // (could only happen for UserDefined tags now that module name is added to tag ID)
            $n = 0;
            while ($compTable[$new]) {
                if ($n++) $new = preg_replace('/-\d+$/', '', $new);
                $new .= "-$n";
            }
            // use new ID and save it so we can use it in TagLookup
            if ($tagID !== $new) $tagInfo->NewTagID = $new;

            // add new ID to lookup of Composite tag ID's
            self::$compositeID[$tagID] ??= [];
            array_unshift(self::$compositeID[$tagID], $new);  // (most recent one first)

            // convert scalar Require/Desire/Inhibit entries
            $hashes = $scalars = $used = [];
            foreach (['Require', 'Desire', 'Inhibit'] as $type) {
                $req = $tagInfo->$type;
                if (!$req) continue;
                if (is_array($req)) {
                    $hashes[] = $type;
                } else {
                    $scalars[] = $type;
                }
            }
            if ($scalars) {
                // make lookup for indices that are used
                foreach ($hashes as $type) {
                    foreach (array_keys($tagInfo->$type) as $k) $used[$k] = 1;
                }
                $next = 0;
                foreach ($scalars as $type) {
                    while (!empty($used[$next])) ++$next;
                    $tagInfo->$type = [$next++ => $tagInfo->$type];
                }
            }
            // add this Composite tag to our main Composite table
            $tagInfo->Table = $compTable;
            // (use the original TagID, even if we changed it, so don't do this:)
            $tagInfo->TagID = $new;
            // save tag under new ID in Composite table
            $compTable[$new] = $tagInfo;
            // set all default groups in tag
            $groups = $tagInfo->Groups ?: [];
            // fill in default groups
            foreach ($defaultGroups as $k => $g) {
                if (empty($groups[$k])) $groups[$k] = $g;
            }
            $tagInfo->Groups = $groups;
            // set flag indicating group list was built
            $tagInfo->GotGroups = 1;
        }
    }

    //------------------------------------------------------------------------------
    // Expand tagInfo Flags
    // Inputs: 0) tagInfo hash ref
    // Notes: $$tagInfo{Flags} must be defined to call this routine
    public static function ExpandFlags(TagInfo $tagInfo): void
    {
        $flags = $tagInfo->Flags;
        if (is_array($flags) and array_is_list($flags)) {
            foreach ($flags as $f) {
                $tagInfo->$f = 1;
            }
        } elseif (is_array($flags)) {
            foreach ($flags as $key => $val) {
                $tagInfo->$key = $val;
            }
        } else {
            $tagInfo->$flags = 1;
        }
    }

    //------------------------------------------------------------------------------
    // Set up tag table (must be done once for each tag table used)
    // Inputs: 0) Reference to tag table
    // Notes: - generates 'Name' field from key if it doesn't exist
    //        - stores 'Table' pointer and 'TagID' value
    //        - expands 'Flags' for quick lookup
    public static function SetupTagTable(TagTable $tagTablePtr): void
    {
        $avoid = $tagTablePtr['AVOID'];
        foreach (self::TagTableKeys($tagTablePtr) as $tagID) {
            $infoArray = self::GetTagInfoList($tagTablePtr, $tagID);
            // process conditional tagInfo arrays
            foreach ($infoArray as $tagInfo) {
                $tagInfo->Table = $tagTablePtr;
                $tagInfo->TagID = $tagID;
                if (!$tagInfo->Name) $tagInfo->Name = self::MakeTagName($tagID);
                if ($tagInfo->Flags) self::ExpandFlags($tagInfo);
                if ($avoid !== null) $tagInfo->Avoid = $avoid;
                // calculate BitShift from Mask if necessary
                if ($tagInfo->Mask and $tagInfo->BitShift === null) {
                    $mask = (int) Perl::num($tagInfo->Mask);
                    $bitShift = 0;
                    while ($bitShift < 63 and !($mask & (1 << $bitShift))) ++$bitShift;
                    $tagInfo->BitShift = $bitShift;
                }
            }
            if (count($infoArray) <= 1) continue;
            // add an "Index" member to each tagInfo in a list
            $index = 0;
            foreach ($infoArray as $tagInfo) {
                $tagInfo->Index = $index++;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Is this a PC system?
    // Returns: true for PC systems
    // uses lookup for O/S names which may use a backslash as a directory separator
    // (ref File::Spec of PathTools-3.2701)
    public static function IsPC()
    {
        return PHP_OS_FAMILY === 'Windows' ? 1 : null;
    }

    //------------------------------------------------------------------------------
    // Utilities to check for numerical types
    // Inputs: 0) value;  Returns: true if value is a numerical type
    // Notes: May change commas to decimals in floats for use in other locales
    public static function IsFloat(&$val)
    {
        $str = Perl::str($val);
        if (preg_match('/^[+-]?(?=\d|\.\d)\d*(\.\d*)?([Ee]([+-]?\d+))?$/', $str)) return 1;
        // allow comma separators (for other locales)
        if (!preg_match('/^[+-]?(?=\d|,\d)\d*(,\d*)?([Ee]([+-]?\d+))?$/', $str)) return 0;
        $val = strtr($str, ',', '.');   // but translate ',' to '.'
        return 1;
    }

    public static function IsInt($val)
    {
        return preg_match('/^[+-]?\d+$/', Perl::str($val)) ? 1 : '';
    }

    public static function IsHex($val)
    {
        return preg_match('/^(0x)?[0-9a-f]{1,8}$/i', Perl::str($val)) ? 1 : '';
    }

    public static function IsRational($val)
    {
        return preg_match('{^[-+]?\d+/\d+$}', Perl::str($val)) ? 1 : '';
    }

    // round floating point value to specified number of significant digits
    // Inputs: 0) value, 1) number of sig digits;  Returns: rounded number
    public static function RoundFloat($val, $sig)
    {
        return Perl::sprintf("%.{$sig}g", $val);
    }

    // Convert strings to floating point numbers (or undef)
    // Inputs: 0-N) list of strings (may be undef)
    // Returns: last value converted
    // (PHP: the arguments are converted in place, like Perl does with @_)
    public static function ToFloat(&...$vals)
    {
        foreach ($vals as &$v) {
            if ($v === null) continue;
            // (add 0 to convert "0.0" to "0" for tests)
            $v = preg_match('/((?:[+-]?)(?=\d|\.\d)\d*(?:\.\d*)?(?:[Ee](?:[+-]?\d+))?)/', Perl::str($v), $m) ?
                 Perl::num($m[1]) : null;
        }
        unset($v);
        return $vals ? $vals[count($vals) - 1] : null;
    }

    //------------------------------------------------------------------------------
    // Utility routines to for reading binary data values from file

    // Swap bytes in data if necessary
    // Inputs: 0) data, 1) number of bytes
    // Returns: swapped data
    public static function SwapBytes($val, $bytes)
    {
        if (!self::$swapBytes) return $val;
        return strrev(substr((string) $val, 0, (int) $bytes));
    }

    // Swap words.  Inputs: 8 bytes of data, Returns: swapped data
    public static function SwapWords($val)
    {
        if (!(self::$swapWords and strlen((string) $val) == 8)) return $val;
        return substr($val, 4, 4) . substr($val, 0, 4);
    }

    // Unpack value, letting unpack() handle byte swapping
    // Inputs: 0) unpack template, 1) data reference, 2) offset
    // Returns: unpacked number
    // - uses value of %unpackStd to determine the unpack template
    // - can only be called for 'S' or 'L' templates since these are the only
    //   templates for which you can specify the byte ordering.
    // (PHP: like Perl's unpack, dies if the offset is outside the data)
    public static function DoUnpackStd($template, $dataPt, $pos = 0)
    {
        return self::UnpackStd(self::$unpackStd[$template], (string) $dataPt, $pos);
    }

    // same, but with reversed byte order
    public static function DoUnpackRev($template, $dataPt, $pos = 0)
    {
        return self::UnpackStd(self::UNPACK_REV[self::$unpackStd[$template]], (string) $dataPt, $pos);
    }

    private static function UnpackStd(string $fmt, string $data, $pos)
    {
        $pos = (int) $pos;
        $len = strlen($data);
        if ($pos < 0) throw new \RuntimeException("Invalid type '-' in unpack");
        if ($pos > $len) throw new \RuntimeException("'x' outside of string in unpack");
        $size = ($fmt === 'C' or $fmt === 'c') ? 1 : (($fmt === 'n' or $fmt === 'v') ? 2 : 4);
        if ($pos + $size > $len) return null;
        $val = unpack($fmt, $data, $pos)[1];
        if ($fmt === 'c' and $val > 127) $val -= 256;
        return $val;
    }

    // Pack value
    // Inputs: 0) template, 1) value, 2) data ref (or undef), 3) offset (if data ref)
    // Returns: packed value
    public static function DoPackStd($template, $value, &$dataPt = null, $pos = null)
    {
        $val = Perl::pack(self::$unpackStd[$template], $value);
        if (func_num_args() > 2 and $dataPt !== null) $dataPt = substr_replace($dataPt, $val, (int) $pos, strlen($val));
        return $val;
    }

    // same, but with reversed byte order
    public static function DoPackRev($template, $value, &$dataPt = null, $pos = null)
    {
        $val = Perl::pack(self::UNPACK_REV[self::$unpackStd[$template]], $value);
        if (func_num_args() > 2 and $dataPt !== null) $dataPt = substr_replace($dataPt, $val, (int) $pos, strlen($val));
        return $val;
    }

    // Unpack value, handling the byte swapping manually
    // Inputs: 0) # bytes, 1) unpack template, 2) data reference, 3) offset
    // Returns: unpacked number
    // - uses value of $swapBytes to determine byte ordering
    // (PHP: unpacks with the explicit byte order instead, same result)
    private static function DoUnpack($bytes, string $template, $dataPt, $pos)
    {
        $pos = (int) $pos;
        $dataPt = (string) $dataPt;
        if ($pos < 0 or $pos + $bytes > strlen($dataPt)) return null;
        $big = self::DoUnpackBigEndian();
        switch ($template) {
            case 's':
                $val = unpack($big ? 'n' : 'v', $dataPt, $pos)[1];
                return $val >= 0x8000 ? $val - 0x10000 : $val;
            case 'l':
                $val = unpack($big ? 'N' : 'V', $dataPt, $pos)[1];
                return $val >= 0x80000000 ? $val - 0x100000000 : $val;
            case 'f':
                return unpack($big ? 'G' : 'g', $dataPt, $pos)[1];
        }
        return null;
    }

    // Unpack double value
    // Inputs: 0) unpack template, 1) data reference, 2) offset
    // Returns: unpacked number
    private static function DoUnpackDbl($template, $dataPt, $pos)
    {
        $pos = (int) $pos;
        $dataPt = (string) $dataPt;
        if ($pos < 0 or $pos + 8 > strlen($dataPt)) return null;
        return unpack(self::DoUnpackBigEndian() ? 'E' : 'e', $dataPt, $pos)[1];
    }

    // Byte order used by DoUnpack/DoUnpackDbl: like Perl's $swapBytes, which stays undef (no swap,
    // so native order) until SetByteOrder is first called, while Get16u/Get32u default to 'MM'
    private static function DoUnpackBigEndian(): bool
    {
        static $nativeBig = null;
        if (null === self::$swapBytes) {
            return $nativeBig ??= pack('S', 1) === "\x00\x01";
        }
        return self::$currentByteOrder === 'MM';
    }

    // Inputs: 0) data reference, 1) offset into data
    public static function Get8s($dataPt, $pos = 0)     { return self::DoUnpackStd('c', $dataPt, $pos); }
    public static function Get8u($dataPt, $pos = 0)     { return self::DoUnpackStd('C', $dataPt, $pos); }
    public static function Get16s($dataPt, $pos = 0)    { return self::DoUnpack(2, 's', $dataPt, $pos); }
    public static function Get16u($dataPt, $pos = 0)    { return self::DoUnpackStd('S', $dataPt, $pos); }
    public static function Get32s($dataPt, $pos = 0)    { return self::DoUnpack(4, 'l', $dataPt, $pos); }
    public static function Get32u($dataPt, $pos = 0)    { return self::DoUnpackStd('L', $dataPt, $pos); }
    public static function GetFloat($dataPt, $pos = 0)  { return self::DoUnpack(4, 'f', $dataPt, $pos); }
    public static function GetDouble($dataPt, $pos = 0) { return self::DoUnpackDbl('d', $dataPt, $pos); }
    public static function Get16uRev($dataPt, $pos = 0) { return self::DoUnpackRev('S', $dataPt, $pos); }
    public static function Get32uRev($dataPt, $pos = 0) { return self::DoUnpackRev('L', $dataPt, $pos); }

    // rationals may be a floating point number, 'inf' or 'undef'
    public static function GetRational32s($dataPt, $pos)
    {
        self::$ratNumer = self::Get16s($dataPt, $pos);
        self::$ratDenom = self::Get16s($dataPt, $pos + 2);
        if (!self::$ratDenom) return self::$ratNumer ? 'inf' : 'undef';
        // round off to a reasonable number of significant figures
        return self::RoundFloat((float) self::$ratNumer / self::$ratDenom, 7);
    }

    public static function GetRational32u($dataPt, $pos)
    {
        self::$ratNumer = self::Get16u($dataPt, $pos);
        self::$ratDenom = self::Get16u($dataPt, $pos + 2);
        if (!self::$ratDenom) return self::$ratNumer ? 'inf' : 'undef';
        return self::RoundFloat((float) self::$ratNumer / self::$ratDenom, 7);
    }

    public static function GetRational64s($dataPt, $pos)
    {
        self::$ratNumer = self::Get32s($dataPt, $pos);
        self::$ratDenom = self::Get32s($dataPt, $pos + 4);
        if (!self::$ratDenom) return self::$ratNumer ? 'inf' : 'undef';
        return self::RoundFloat((float) self::$ratNumer / self::$ratDenom, 10);
    }

    public static function GetRational64u($dataPt, $pos)
    {
        self::$ratNumer = self::Get32u($dataPt, $pos);
        self::$ratDenom = self::Get32u($dataPt, $pos + 4);
        if (!self::$ratDenom) return self::$ratNumer ? 'inf' : 'undef';
        return self::RoundFloat((float) self::$ratNumer / self::$ratDenom, 10);
    }

    public static function GetFixed16s($dataPt, $pos)
    {
        $val = self::Get16s($dataPt, $pos) / 0x100;
        return Perl::int($val * 1000 + ($val < 0 ? -0.5 : 0.5)) / 1000;
    }

    public static function GetFixed16u($dataPt, $pos)
    {
        return Perl::int((self::Get16u($dataPt, $pos) / 0x100) * 1000 + 0.5) / 1000;
    }

    public static function GetFixed32s($dataPt, $pos)
    {
        $val = self::Get32s($dataPt, $pos) / 0x10000;
        // remove insignificant digits
        return Perl::int($val * 1e5 + ($val > 0 ? 0.5 : -0.5)) / 1e5;
    }

    public static function GetFixed32u($dataPt, $pos)
    {
        // remove insignificant digits
        return Perl::int((self::Get32u($dataPt, $pos) / 0x10000) * 1e5 + 0.5) / 1e5;
    }

    // Inputs: 0) value, 1) data ref, 2) offset
    public static function Set8s($value, &$dataPt = null, $pos = null)  { return func_num_args() > 1 ? self::DoPackStd('c', $value, $dataPt, $pos) : self::DoPackStd('c', $value); }
    public static function Set8u($value, &$dataPt = null, $pos = null)  { return func_num_args() > 1 ? self::DoPackStd('C', $value, $dataPt, $pos) : self::DoPackStd('C', $value); }
    public static function Set16u($value, &$dataPt = null, $pos = null) { return func_num_args() > 1 ? self::DoPackStd('S', $value, $dataPt, $pos) : self::DoPackStd('S', $value); }
    public static function Set32u($value, &$dataPt = null, $pos = null) { return func_num_args() > 1 ? self::DoPackStd('L', $value, $dataPt, $pos) : self::DoPackStd('L', $value); }
    public static function Set16uRev($value, &$dataPt = null, $pos = null) { return func_num_args() > 1 ? self::DoPackRev('S', $value, $dataPt, $pos) : self::DoPackRev('S', $value); }

    //------------------------------------------------------------------------------
    // Get current byte order ('II' or 'MM')
    public static function GetByteOrder()
    {
        return self::$currentByteOrder;
    }

    //------------------------------------------------------------------------------
    // Set byte ordering
    // Inputs: 0) 'MM'=motorola, 'II'=intel (will translate 'BigEndian', 'LittleEndian')
    // Returns: 1 on success
    public static function SetByteOrder($order)
    {
        $order = Perl::str($order);
        if ($order === 'MM') {          // big endian (Motorola)
            self::$unpackStd = self::UNPACK_MOTOROLA;
        } elseif ($order === 'II') {    // little endian (Intel)
            self::$unpackStd = self::UNPACK_INTEL;
        } elseif (preg_match('/^Big/i', $order)) {
            $order = 'MM';
            self::$unpackStd = self::UNPACK_MOTOROLA;
        } elseif (preg_match('/^Little/i', $order)) {
            $order = 'II';
            self::$unpackStd = self::UNPACK_INTEL;
        } else {
            return 0;
        }
        $nativeOrder = pack('S', 0x4120) === 'A ' ? 'MM' : 'II';
        self::$currentByteOrder = $order;  // save current byte order

        // swap bytes if our native CPU byte ordering is not the same as the EXIF
        self::$swapBytes = ($order !== $nativeOrder);

        // little-endian ARM has big-endian words for doubles (thanks Riku Voipio)
        // (Note: Riku's patch checked for '0ff3', but I think it should be 'f03f' since
        // 1 is '000000000000f03f' on an x86 -- so check for both, but which is correct?)
        $pack1d = pack('d', 1);
        self::$swapWords = ($pack1d === "\0\0\x0f\xf3\0\0\0\0" or $pack1d === "\0\0\xf0\x3f\0\0\0\0");
        return 1;
    }

    //------------------------------------------------------------------------------
    // Change byte order
    public static function ToggleByteOrder()
    {
        return self::SetByteOrder(self::GetByteOrder() === 'II' ? 'MM' : 'II');
    }

    public static function FormatSize($format)
    {
        return self::$formatSize[$format] ?? null;
    }

    //------------------------------------------------------------------------------
    // Read value from binary data (with current byte ordering)
    // Inputs: 0) data reference, 1) value offset, 2) format string,
    //         3) number of values (or undef to use all data),
    //         4) valid data length relative to offset (or undef to use all data),
    //         5) optional pointer to returned rational
    // Returns: converted value, or undefined if data isn't there
    //          or list of values in list context (PHP: ReadValueList)
    public static function ReadValue($dataPt, $offset, $format, $count = null, $size = null, &$ratPt = null)
    {
        $vals = self::ReadValues((string) $dataPt, $offset, $format, $count, $size, func_num_args() > 5, $ratPt);
        if (!is_array($vals)) return $vals;
        if (count($vals) > 1) return self::JoinList(' ', $vals);
        return $vals[0];
    }

    /** ReadValue in list context */
    public static function ReadValueList($dataPt, $offset, $format, $count = null, $size = null, &$ratPt = null): array
    {
        $vals = self::ReadValues((string) $dataPt, $offset, $format, $count, $size, func_num_args() > 5, $ratPt);
        return is_array($vals) ? $vals : [$vals];
    }

    private static function ReadValues(string $dataPt, $offset, $format, $count, $size, bool $wantRat, &$ratPt)
    {
        $offset = (int) $offset;
        $len = self::$formatSize[$format] ?? null;
        if (!$len) {
            self::PerlWarn("Unknown format $format");
            $len = 1;
        }
        if ($size === null) $size = strlen($dataPt) - $offset;
        if (!$count) {
            if ($count !== null or $size < $len) return '';
            $count = Perl::int($size / $len);
        }
        // make sure entry is inside data
        if ($len * $count > $size) {
            $count = Perl::int($size / $len);   // shorten count if necessary
            if ($count < 1) return null;        // return undefined if no data
        }
        $vals = [];
        $proc = self::READ_VALUE_PROC[$format] ?? null;
        if (!$proc) {
            // handle undef/binary/string (also unsupported unicode/complex)
            $vals[0] = substr($dataPt, $offset, (int) ($count * $len));
            // truncate string at null terminator if necessary
            if ($format === 'string') {
                $z = strpos($vals[0], "\0");
                if ($z !== false) $vals[0] = substr($vals[0], 0, $z);
            }
        } elseif (isset(self::IS_RATIONAL[$format]) and $wantRat) {
            // store rationals separately as string fractions
            $rat = [];
            for (;;) {
                $vals[] = self::$proc($dataPt, $offset);
                $rat[] = Perl::str(self::$ratNumer) . '/' . Perl::str(self::$ratDenom);
                if (--$count <= 0) break;
                $offset += $len;
            }
            $ratPt = implode(' ', $rat);
        } else {
            for (;;) {
                $vals[] = self::$proc($dataPt, $offset);
                if (--$count <= 0) break;
                $offset += $len;
            }
        }
        return $vals;
    }

    //------------------------------------------------------------------------------
    // Decode string with specified encoding
    // Inputs: 0) ExifTool object ref, 1) string to decode
    //         2) source character set name (undef for current Charset)
    //         3) optional source byte order (2-byte and 4-byte fixed-width sets only)
    //         4) optional destination character set (defaults to Charset setting)
    //         5) optional destination byte order (2-byte and 4-byte fixed-width only)
    // Returns: string in destination encoding
    // Note: ExifTool ref may be undef if character both character sets are provided
    //       (but in this case no warnings will be issued)
    //       (PHP: use ExifTool::DecodeStatic(null, ...) for that)
    public function Decode($val, $from = null, $fromOrder = null, $to = null, $toOrder = null)
    {
        return self::DecodeStatic($this, $val, $from, $fromOrder, $to, $toOrder);
    }

    /** Image::ExifTool::Decode called as a function (ExifTool ref may be null) */
    public static function DecodeStatic(?ExifTool $self, $val, $from = null, $fromOrder = null, $to = null, $toOrder = null)
    {
        if (!$from) $from = $self->OPTIONS['Charset'];
        if (!$to) $to = $self->OPTIONS['Charset'];
        if ($from !== $to and $val !== null and strlen(Perl::str($val))) {
            $cs1 = Charset::$csType[$from] ?? null;
            $cs2 = Charset::$csType[$to] ?? null;
            if ($cs1 and $cs2 and !($cs2 & 0x002)) {
                // treat as straight ASCII if no character will need remapping
                if (($cs1 | $cs2) & 0x680 or preg_match('/[\x80-\xff]/', Perl::str($val))) {
                    $uni = Charset::Decompose($self, $val, $from, $fromOrder);
                    $val = Charset::Recompose($self, $uni, $to, $toOrder);
                }
            } elseif ($self) {
                $set = $cs1 ? $to : $from;
                if (!$self->{"DecodeWarn$set"}) {
                    $self->Warn("Unsupported character set ($set)");
                    $self->{"DecodeWarn$set"} = 1;
                }
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Encode string (in Charset encoding) to specified encoding
    // Inputs: 0) ExifTool object ref, 1) string, 2) destination character set name,
    //         3) optional destination byte order (2-byte and 4-byte fixed-width sets only)
    // Returns: string in specified encoding
    public function Encode($val, $to = null, $toOrder = null)
    {
        return $this->Decode($val, null, null, $to, $toOrder);
    }

    //------------------------------------------------------------------------------
    // Decode bit mask
    // Inputs: 0) value to decode, 1) Reference to hash for decoding (or undef)
    //         2) optional bits per word (defaults to 32)
    public static function DecodeBits($vals, $lookup = null, $bits = null)
    {
        if (!$bits) $bits = 32;
        $bitList = [];
        $num = 0;
        foreach (Perl::split(' ', $vals) as $val) {
            $val = (int) Perl::num($val);
            for ($i = 0; $i < $bits; ++$i) {
                if (!($val & (1 << $i))) continue;
                $n = $i + $num;
                if (null === $lookup) {   // (an empty Perl hash ref is still true)
                    $bitList[] = $n;
                } elseif (!empty($lookup[$n])) {
                    $bitList[] = $lookup[$n];
                } else {
                    $bitList[] = "[$n]";
                }
            }
            $num += $bits;
        }
        if (!$bitList) return '(none)';
        return implode(null !== $lookup ? ', ' : ',', $bitList);
    }

    //------------------------------------------------------------------------------
    // Validate an extracted image and repair if necessary
    // Inputs: 0) ExifTool object reference, 1) image reference, 2) tag name or key
    // Returns: image reference or undef if it wasn't valid
    // Note: should be called from RawConv, not ValueConv
    // (PHP: image reference = ScalarRef (a string is wrapped), returns the ScalarRef or null)
    public function ValidateImage($imagePt, $tag): ?ScalarRef
    {
        if (!$imagePt instanceof ScalarRef) $imagePt = new ScalarRef($imagePt);
        $img = Perl::str($imagePt->value);
        if ($img === 'none') return null;
        if (!(preg_match('/^(Binary data|\xff\xd8\xff)/', $img) or
              // the first byte of the preview of some Minolta cameras is wrong,
              // so check for this and set it back to 0xff if necessary
              (preg_match('/^.(\xd8\xff\xdb)/s', $img) and ($imagePt->value = "\xff" . substr($img, 1)) !== null) or
              $this->Options('IgnoreMinorErrors')))
        {
            // issue warning only if the tag was specifically requested
            if (!empty($this->REQ_TAG_LOOKUP[strtolower((string) self::GetTagName($tag))])) {
                $this->Warn("$tag is not a valid JPEG image", 1);
                return null;
            }
        }
        return $imagePt;
    }

    //------------------------------------------------------------------------------
    // Validate a tag name argument (including group name and wildcards, etc)
    // Inputs: 0) tag name
    // Returns: true if tag name is valid
    // - a tag name may contain [-_A-Za-z0-9], but may not start with [-0-9]
    // - tag names may contain wildcards [?*], and end with a hash [#]
    // - may have group name prefixes (which may have family number prefix), separated by colons
    // - a group name may be zero or more characters
    public static function ValidTagName($tag)
    {
        return preg_match('/^(([-\w]*|\d*\*):)*[_a-zA-Z?*][-\w?*]*#?$/', (string) $tag) ? 1 : '';
    }

    //------------------------------------------------------------------------------
    // Generate a valid tag name based on the tag ID or name
    // Inputs: 0) tag ID or name
    // Returns: valid tag name
    public static function MakeTagName($name)
    {
        $name = preg_replace('/[^-_a-zA-Z0-9]/', '', Perl::str($name));   // remove illegal characters
        $name = ucfirst($name);         // capitalize first letter
        // must at least 2 characters long and not start with - or 0-9-
        if (strlen($name) < 2 or preg_match('/^[-0-9]/', $name)) $name = "Tag$name";
        return $name;
    }

    //------------------------------------------------------------------------------
    // Make description from a tag name
    // Inputs: 0) tag name 1) optional tagID to add at end of description
    // Returns: description
    public static function MakeDescription($tag, $tagID = null)
    {
        // start with the tag name and force first letter to be upper case
        $desc = ucfirst(Perl::str($tag));
        // translate underlines to spaces
        $desc = strtr($desc, '_', ' ');
        // remove hex TagID from name (to avoid inserting spaces in the number)
        if ($tagID === null and preg_match('/ (0x[\da-f]+)$/i', $desc, $m)) {
            $desc = substr($desc, 0, -strlen($m[0]));
            $tagID = $m[1];
        }
        // put a space between lower/UPPER case and lower/number combinations
        $desc = preg_replace('/([a-z])([A-Z\d])/', '$1 $2', $desc);
        // put a space between acronyms and words
        $desc = preg_replace('/([A-Z])([A-Z][a-z])/', '$1 $2', $desc);
        // put spaces after numbers (if more than one character follows the number)
        $desc = preg_replace('/(\d)([A-Z]\S)/', '$1 $2', $desc);
        // add TagID to description
        if ($tagID !== null) $desc .= ' ' . $tagID;
        return $desc;
    }

    //------------------------------------------------------------------------------
    // Get descriptions for all tags in an array
    // Inputs: 0) ExifTool ref, 1) reference to list of tag keys
    // Returns: reference to hash lookup for descriptions
    // Note: Returned descriptions are NOT escaped by ESCAPE_PROC
    public function GetDescriptions(array $tags): array
    {
        $desc = [];
        $oldEscape = $this->ESCAPE_PROC;
        $this->ESCAPE_PROC = null;
        foreach ($tags as $t) $desc[$t] = $this->GetDescription($t);
        $this->ESCAPE_PROC = $oldEscape;
        return $desc;
    }

    //------------------------------------------------------------------------------
    // Apply filter to value(s) if necessary
    // Inputs: 0) ExifTool ref, 1) filter expression, 2) reference to value to filter
    // Returns: true unless a filter returned undef; changes value if necessary
    // (PHP: the filter is a callable returning the filtered value, or null for undef)
    public function Filter($filter, &$valPt)
    {
        if ($filter === null or $valPt === null) return 1;
        $rtnVal = null;
        if (!is_array($valPt) and !is_object($valPt)) {
            //### eval Filter ($_, $self)
            try {
                $new = $filter($valPt);
            } catch (\Throwable) {
                $new = $valPt;
            }
            if ($new !== null) {
                $valPt = $new;
                $rtnVal = 1;
            }
        } elseif (self::IsScalarRef($valPt)) {
            $val = $valPt->value;   // make a copy to avoid filtering twice
            $rtnVal = $this->Filter($filter, $val);
            $valPt = new ScalarRef($val);
        } elseif (is_array($valPt) or $valPt instanceof \ArrayObject) {
            $val = is_array($valPt) ? $valPt : $valPt->getArrayCopy();  // make a copy to avoid filtering twice
            foreach ($val as &$v) {
                if ($this->Filter($filter, $v)) $rtnVal = 1;
            }
            unset($v);
            $valPt = is_array($valPt) ? $val : new \ArrayObject($val);
        } else {
            $rtnVal = 1;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Return printable value
    // Inputs: 0) ExifTool object reference
    //         1) value to print, 2) line length limit (undef defaults to 60, 0=unlimited)
    // Returns: Printable string
    public function Printable($outStr, $maxLen = null)
    {
        if ($outStr === null) return '(undef)';
        if (self::IsScalarRef($outStr)) return '(Binary data ' . strlen(Perl::str($outStr->value)) . ' bytes)';
        $outStr = preg_replace('/[\x01-\x1f\x7f-\xff]/', '.', Perl::str($outStr));
        $outStr = str_replace("\0", '', $outStr);
        $verbose = $this->OPTIONS['Verbose'];
        if ($verbose < 4) {
            if ($maxLen) {
                if ($maxLen < 20) $maxLen = 20;     // minimum length is 20
            } elseif ($maxLen !== null) {
                $maxLen = strlen($outStr);          // 0 is unlimited
            } else {
                $maxLen = 60;                       // default maximum is 60
            }
        } else {
            $maxLen = strlen($outStr);
            // limit to 2048 characters if verbose < 5
            if ($maxLen > 2048 and $verbose < 5) $maxLen = 2048;
        }

        // limit length if necessary
        if (strlen($outStr) > $maxLen) $outStr = substr($outStr, 0, $maxLen - 6) . '[snip]';
        return $outStr;
    }

    //------------------------------------------------------------------------------
    // Convert date/time from Exif format
    // Inputs: 0) ExifTool object reference, 1) Date/time in EXIF format
    // Returns: Formatted date/time string
    public function ConvertDateTime($date)
    {
        $fmt = $this->OPTIONS['DateFormat'];
        $shift = $this->OPTIONS['GlobalTimeShift'];
        if ($shift) {
            $g = $t = $dir = null;
            $matches = [];
            $shift = Perl::str($shift);
            if (preg_match('/^((\d?[A-Z][-\w]*\w:)*)([A-Z][-\w]*\w)([-+])/i', $shift, $m)) {
                $shift = substr($shift, strlen($m[0]));
                [$g, $t, $dir] = [$m[1], $m[3], $m[4] === '-' ? -1 : 1];
            } else {
                $dir = 1;
                if (preg_match('/^([-+])/', $shift, $m)) { $shift = substr($shift, 1); if ($m[1] === '-') $dir = -1; }
            }
            if ($this->GLOBAL_TIME_OFFSET === null) {
                $this->GLOBAL_TIME_OFFSET = [];
                if ($t) {
                    $matches = preg_grep('/^' . $t . '( \(|$)/i', array_keys($this->VALUE));
                    sort($matches, SORT_STRING);
                    if ($g and $matches) $matches = $this->GroupMatchesList(preg_replace('/:$/', '', $g), $matches);
                }
                if ($matches) {
                    $val = $this->GetValue($matches[0], 'ValueConv');
                    if ($val !== null) self::ShiftTime($val, $shift, $dir, $this->GLOBAL_TIME_OFFSET);
                }
            }
            self::ShiftTime($date, $shift, $dir, $this->GLOBAL_TIME_OFFSET);
        }
        // only convert date if a format was specified and the date is recognizable
        if ($fmt) {
            $date = Perl::str($date);
            // separate time zone if it exists
            $tz = null;
            if (preg_match('/([-+]\d{2}:\d{2}|Z)$/', $date, $m)) {
                $tz = $m[1];
                $date = substr($date, 0, -strlen($m[1]));
            }
            // a few cameras use incorrect date/time formatting:
            // - slashes instead of colons in date (RolleiD330, ImpressCam)
            // - date/time values separated by colon instead of space (Polariod, Sanyo, Sharp, Vivitar)
            // - single-digit seconds with leading space (HP scanners)
            preg_match_all('/\d+/', $date, $m);
            $a = array_reverse($m[0]);  // be very flexible about date/time format
            if ($a and $a[count($a) - 1] >= 1000 and $a[count($a) - 1] < 3000) {
                while (count($a) > 6) array_shift($a);      // remove superfluous entries
                while (count($a) < 3) array_unshift($a, 1); // add month and day if necessary
                while (count($a) < 6) array_unshift($a, 0); // add h,m,s if necessary
                $a = array_map(static fn ($x) => Perl::num($x), $a);
                $a[4] -= 1;                 // base month is 1
                // parse our %f fractional seconds first (and round up seconds if necessary)
                // - if there are multiple %f codes, they all get the same number of digits as the first
                if (preg_match('/%(-?)\.?(\d*)f/', $fmt, $m)) {
                    [$neg, $dig] = [$m[1], $m[2]];
                    $frac = preg_match('/(\.\d+)/', $date, $mm) ? $mm[1] : '';
                    if (!$frac) {
                        if ($dig) $frac = '.' . str_repeat('0', (int) $dig);
                    } elseif (strlen($dig)) {
                        if ($dig + 1 > strlen($frac)) {
                            $frac .= str_repeat('0', $dig + 1 - strlen($frac));
                        } elseif ($dig + 1 < strlen($frac)) {
                            $frac = Perl::sprintf("%.{$dig}f", $frac);
                            if (preg_match('/^(\d)/', $frac, $mm)) {
                                $frac = substr($frac, 1);
                                // this is a pain, but we must round up to the next second
                                while ($mm[1] !== '0') {
                                    if (++$a[0] < 60) break;
                                    $a[0] = 0;
                                    if (++$a[1] < 60) break;
                                    $a[1] = 0;
                                    if (++$a[2] < 24) break;
                                    $a[2] = 0;
                                    if (++$a[3] <= self::DaysInMonth($a[4] + 1, $a[5])) break;
                                    $a[3] = 1;
                                    if (++$a[4] < 12) break;
                                    $a[4] = 0;
                                    ++$a[5];
                                    break; // (this was a goto)
                                }
                            }
                        }
                    }
                    if ($neg) $frac = preg_replace('/^\./', '', $frac);
                    $fmt = preg_replace('/(^|[^%])((%%)*)%-?\.?\d*f/', '${1}${2}' . $frac, $fmt);
                }
                // parse %z and %s ourself (to handle time zones properly)
                if (preg_match('/%:?[sz]/', $fmt)) {
                    // use system time zone unless otherwise specified
                    if (!$tz) $tz = self::TimeZoneString($a, self::TimeLocal(...$a));
                    // remove colon, setting to UTC if time zone is not numeric
                    if (!($tz and preg_match('/^[-+]\d{2}:\d{2}$/', $tz))) $tz = '+00:00';
                    $fmt = preg_replace('/(^|[^%])((%%)*)%:z/', '${1}${2}' . $tz, $fmt);     // convert '%:z' format codes
                    $tz = preg_replace('/:/', '', $tz, 1);
                    $fmt = preg_replace('/(^|[^%])((%%)*)%z/', '${1}${2}' . $tz, $fmt);      // convert '%z' format codes
                    if (str_contains($fmt, '%s')) {
                        // calculate seconds since the Epoch, UTC
                        $tzn = Perl::num($tz);
                        $s = self::TimeLocal_timegm(...$a) - 60 * ($tzn - Perl::int($tzn / 100) * 40);
                        $fmt = preg_replace('/(^|[^%])((%%)*)%s/', '${1}${2}' . $s, $fmt);   // convert '%s' format codes
                    }
                }
                $a[5] -= 1900;  // strftime year starts from 1900
                $date = self::POSIX_strftime($fmt, $a); // generate the formatted date/time
            } elseif ($this->OPTIONS['StrictDate']) {
                $date = null;
            }
        }
        return $date;
    }

    //------------------------------------------------------------------------------
    // Print conversion for time span value
    // Inputs: 0) time ticks, 1) number of seconds per tick (default 1)
    // Returns: readable time
    public static function ConvertTimeSpan($val, $mult = null)
    {
        if (self::IsFloat($val) and Perl::num($val) != 0) {
            $val = Perl::num($val);
            if ($mult) $val *= Perl::num($mult);
            if ($val < 60) {
                $val = Perl::str($val) . ' seconds';
            } elseif ($val < 3600) {
                $fmt = ($mult and $mult >= 60) ? '%d' : '%.1f';
                $s = ($val == 60 and $mult) ? '' : 's';
                $val = Perl::sprintf("$fmt minute$s", $val / 60);
            } elseif ($val < 24 * 3600) {
                $val = Perl::sprintf('%.1f hours', $val / 3600);
            } else {
                $val = Perl::sprintf('%.1f days', $val / (24 * 3600));
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Patched timelocal() that fixes ActivePerl timezone bug
    // Inputs/Returns: same as timelocal()
    // Notes: must 'require Time::Local' before calling this routine.
    // Also note that year should be full year, and not relative to 1900 as with localtime
    public static function TimeLocal(...$tm)
    {
        return self::TimeLocal_timelocal(...$tm);
    }

    //------------------------------------------------------------------------------
    // Get time zone in minutes
    // Inputs: 0) localtime array ref, 1) gmtime array ref
    // Returns: time zone offset in minutes
    public static function GetTimeZone(array $tm, array $gm)
    {
        // compute the number of minutes between localtime and gmtime
        $min = $tm[2] * 60 + $tm[1] - ($gm[2] * 60 + $gm[1]);
        if ($tm[3] != $gm[3]) {
            // account for case where one date wraps to the first of the next month
            if (abs($tm[3] - $gm[3]) != 1) $gm[3] = $tm[3] - ($tm[3] == 1 ? 1 : -1);
            // adjust for the +/- one day difference
            $min += ($tm[3] - $gm[3]) * 24 * 60;
        }
        return $min;
    }

    //------------------------------------------------------------------------------
    // Get time zone string
    // Inputs: 0) time zone offset in minutes
    //     or  0) localtime array ref, 1) corresponding time value
    // Returns: time zone string ("+/-HH:MM")
    public static function TimeZoneString($min, $time = null)
    {
        if (is_array($min)) {
            $gm = self::PerlGmtime($time);
            $min = self::GetTimeZone($min, $gm);
        }
        $min = Perl::num($min);
        $sign = '+';
        if ($min < 0) {
            $sign = '-';
            $min = -$min;
        }
        $min = Perl::int($min + 0.5);   // round off to nearest minute
        $h = Perl::int($min / 60);
        return Perl::sprintf('%s%.2d:%.2d', $sign, $h, $min - $h * 60);
    }

    //------------------------------------------------------------------------------
    // Convert Unix time to EXIF date/time string
    // Inputs: 0) Unix time value, 1) non-zero to convert to local time, 2) number of
    //         digits after the decimal for fractional seconds, negative to trim
    //         trailing zeros, or undef to use SystemTimeRes
    // Returns: EXIF date/time string (with timezone for local times)
    public static function ConvertUnixTime($time, $toLocal = null, $dec = null)
    {
        $time = Perl::num($time);
        if ($time == 0) return '0000:00:00 00:00:00';
        $trim = null;
        if ($dec === null) $dec = (self::$static_vars['SystemTimeRes'] ?? null) ?: 0;
        $dec = Perl::num($dec);
        if ($dec < 0) {
            $dec = -$dec;
            $trim = 1;
        }
        $itime = Perl::int($time);
        $frac = $time - $itime;
        if ($frac < 0) {
            $frac += 1;
            $itime -= 1;
        }
        $dec = Perl::sprintf('%.*f', $dec, $frac);
        // remove number before decimal and increment integer time if necessary
        if (preg_match('/^(\d)/', $dec, $m)) {
            $dec = substr($dec, 1);
            if ($m[1] === '1') $itime += 1;
        }
        if ($trim) $dec = preg_replace('/\.?0+$/', '', $dec);  // trim trailing zeros if specified
        if (!$toLocal) {
            $tm = self::PerlGmtime($itime);
            $tz = '';
        } elseif (!empty(self::$static_vars['KeepUTCTime'])) {
            $tm = self::PerlGmtime($itime);
            $tz = 'Z';
        } else {
            $tm = self::PerlLocaltime($itime);
            $tz = self::TimeZoneString($tm, $itime);
        }
        $str = Perl::sprintf("%4d:%.2d:%.2d %.2d:%.2d:%.2d$dec%s",
                             $tm[5] + 1900, $tm[4] + 1, $tm[3], $tm[2], $tm[1], $tm[0], $tz);
        return $str;
    }

    //------------------------------------------------------------------------------
    // Get Unix time from EXIF-formatted date/time string with optional timezone
    // Inputs: 0) EXIF date/time string, 1) non-zero if time is local, or 2 to assume UTC
    // Returns: Unix time (seconds since 0:00 GMT Jan 1, 1970) or undefined on error
    public static function GetUnixTime($timeStr, $isLocal = null)
    {
        $timeStr = Perl::str($timeStr);
        if ($timeStr === '0000:00:00 00:00:00') return 0;
        if (!preg_match('/^(\d+)[-:](\d+)[-:](\d+)\s+(\d+):(\d+):(\d+)(.*)/', $timeStr, $m)) return null;
        $tm = array_map('intval', array_slice($m, 1, 6));
        [$tzStr, $tzSec] = [$m[7], 0];
        // use specified timezone offset (if given) instead of local system time
        // if we are converting a local time value
        if ($isLocal) {
            if (preg_match('/(?:Z|([-+])(\d+):(\d+))/i', $tzStr, $mm)) {
                // use specified timezone if one exists
                if (!empty($mm[1])) $tzSec = ($mm[2] * 60 + $mm[3]) * ($mm[1] === '-' ? -60 : 60);
                $isLocal = null; // convert using GMT corrected for specified timezone
            } elseif (Perl::str($isLocal) === '2') {
                $isLocal = null;
            }
        }
        $tm[1] -= 1;            // convert month
        $tm = array_reverse($tm);   // change to order required by timelocal()
        $val = $isLocal ? self::TimeLocal(...$tm) : self::TimeLocal_timegm(...$tm) - $tzSec;
        // handle fractional seconds
        if ($tzStr !== '' and preg_match('/^(\.\d+)/', $tzStr, $mm)) $val += (float) $mm[1];
        return $val;
    }

    //------------------------------------------------------------------------------
    // Print conversion for file size
    // Inputs: 0) file size in bytes, 1) optional ExifTool ref
    // Returns: converted file size
    public static function ConvertFileSize($val, $et = null)
    {
        $n = Perl::num($val);
        if ($et instanceof self and $et->OPTIONS['ByteUnit'] === 'Binary') {
            if ($n < 2048) return Perl::str($val) . ' bytes';
            if ($n < 10240) return Perl::sprintf('%.1f KiB', $n / 1024);
            if ($n < 2097152) return Perl::sprintf('%.0f KiB', $n / 1024);
            if ($n < 10485760) return Perl::sprintf('%.1f MiB', $n / 1048576);
            if ($n < 2147483648) return Perl::sprintf('%.0f MiB', $n / 1048576);
            if ($n < 10737418240) return Perl::sprintf('%.1f GiB', $n / 1073741824);
            return Perl::sprintf('%.0f GiB', $n / 1073741824);
        } else {
            if ($n < 2000) return Perl::str($val) . ' bytes';
            if ($n < 10000) return Perl::sprintf('%.1f kB', $n / 1000);
            if ($n < 2000000) return Perl::sprintf('%.0f kB', $n / 1000);
            if ($n < 10000000) return Perl::sprintf('%.1f MB', $n / 1000000);
            if ($n < 2000000000) return Perl::sprintf('%.0f MB', $n / 1000000);
            if ($n < 10000000000) return Perl::sprintf('%.1f GB', $n / 1000000000);
            return Perl::sprintf('%.0f GB', $n / 1000000000);
        }
    }

    //------------------------------------------------------------------------------
    // Convert seconds to duration string (handles negative durations)
    // Inputs: 0) floating point seconds
    // Returns: duration string in form "S.SS s", "H:MM:SS" or "DD days HH:MM:SS"
    public static function ConvertDuration($time)
    {
        if (!self::IsFloat($time)) return $time;
        $time = Perl::num($time);
        if ($time == 0) return '0 s';
        $sign = '';
        if (!($time > 0)) {
            $time = -$time;
            $sign = '-';
        }
        if ($time < 30) return Perl::sprintf("$sign%.2f s", $time);
        $time += 0.5;   // to round off to nearest second
        $h = Perl::int($time / 3600);
        $time -= $h * 3600;
        $m = Perl::int($time / 60);
        $time -= $m * 60;
        if ($h > 24) {
            $d = Perl::int($h / 24);
            $h -= $d * 24;
            $sign = "$sign$d days ";
        }
        return Perl::sprintf("$sign%d:%.2d:%.2d", $h, $m, Perl::int($time));
    }

    //------------------------------------------------------------------------------
    // Print conversion for bitrate values
    // Inputs: 0) bitrate in bits per second
    // Returns: human-readable bitrate string
    // Notes: returns input value without formatting if it isn't numerical
    public static function ConvertBitrate($bitrate)
    {
        if (!self::IsFloat($bitrate)) return $bitrate;
        $bitrate = Perl::num($bitrate);
        $units = ['bps', 'kbps', 'Mbps', 'Gbps'];
        for (;;) {
            $unit = array_shift($units);
            if ($bitrate >= 1000 and $units) {
                $bitrate /= 1000;
                continue;
            }
            $fmt = $bitrate < 100 ? '%.3g' : '%.0f';
            return Perl::sprintf("$fmt $unit", $bitrate);
        }
    }

    //------------------------------------------------------------------------------
    // Convert file name for printing
    // Inputs: 0) ExifTool ref, 1) file name in CharsetFileName character set
    // Returns: converted file name in external character set
    public function ConvertFileName($val)
    {
        $enc = $this->OPTIONS['CharsetFileName'];
        if ($enc) $val = $this->Decode($val, $enc);
        return $val;
    }

    //------------------------------------------------------------------------------
    // Inverse conversion for file name (encode in CharsetFileName)
    // Inputs: 0) ExifTool ref, 1) file name in external character set
    // Returns: file name in CharsetFileName character set
    public function InverseFileName($val)
    {
        $enc = $this->OPTIONS['CharsetFileName'];
        if ($enc) $val = $this->Encode($val, $enc);
        return strtr(Perl::str($val), '\\', '/');   // make sure we are using forward slashes
    }

    //------------------------------------------------------------------------------
    // Limit length of long values (to be used in PrintConv)
    // Inputs: 0) string value, 1) ExifTool ref
    // Returns: length-limited value
    public static function LimitLongValues($str, $self)
    {
        $lim = $self->OPTIONS['LimitLongValues'];
        $str = Perl::str($str);
        if (strlen($str) > $lim and $lim >= 5) {
            $str = substr($str, 0, $lim - 5) . '[...]';
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Save information for HTML dump
    // Inputs: 0) ExifTool hash ref, 1) start offset, 2) data size
    //         3) comment string, 4) tool tip (or SAME), 5) flags, 6) IFD name
    // (ponytail: HtmlDump not ported, HTML_DUMP is never set)
    public function HDump($pos, $len, $com, $tip = null, $flg = null, $ifd = null): void
    {
    }

    // (IdentifyTrailer, ProcessTrailers, JpegMarkerName, DirStart, ProcessJPEG, ProcessEXV,
    //  ProcessEXIF, ProcessTIFF, ProcessSubTIFF and DoProcessTIFF: see ExifTool/Formats.php)

    //------------------------------------------------------------------------------
    // Return list of tag table keys (ignoring special keys)
    // Inputs: 0) reference to tag table
    // Returns: List of table keys (unsorted)
    public static function TagTableKeys(TagTable $tagTablePtr): array
    {
        $keyList = [];
        foreach ($tagTablePtr->keys() as $key) {
            if (!isset(Vars\ExifTool::$specialTags[$key])) $keyList[] = $key;
        }
        return $keyList;
    }

    //------------------------------------------------------------------------------
    // (write proc of the tables which have nothing to write, called as a callable)
    public static function DummyWriteProc() { return 1; }

    //------------------------------------------------------------------------------
    // GetTagTable
    // Inputs: 0) table name
    // Returns: tag table reference, or undefined if not found
    // Notes: Always use this function instead of requiring module and using table
    // directly since this function also does the following the first time the table
    // is loaded:
    // - requires new module if necessary
    // - generates default GROUPS hash and Group 0 name from module name
    // - registers Composite tags if Composite table found
    // - saves descriptions for tags in specified table
    // - generates default TAG_PREFIX to be used for unknown tags
    public static function GetTagTable($tableName): ?TagTable
    {
        if ($tableName instanceof TagTable) return $tableName;
        if (!$tableName) return null;
        $table = self::$allTables[$tableName] ?? null;

        if (!$table) {
            // (try to load module for this table: tables/<Module>.php)
            $table = self::PackageTable($tableName);
            if (!$table) {
                self::PerlWarn("Can't find table $tableName\n");
                return null;
            }
            if ($table['INIT_TABLE']) {
                $init = $table['INIT_TABLE'];
                $init($table);
            }
            $table['TABLE_NAME'] = $tableName;  // set table name
            $table['SHORT_NAME'] = preg_replace('/^Image::ExifTool::/', '', $tableName);
            // set default group 0 and 1 from module name unless already specified
            $defaultGroups = $table['GROUPS'] ?: [];
            if (!(!empty($defaultGroups[0]) and !empty($defaultGroups[1]))) {
                if (preg_match('/Image::.*?::([^:]*)/', $tableName, $m)) {
                    if (empty($defaultGroups[0])) $defaultGroups[0] = $m[1];
                    if (empty($defaultGroups[1])) $defaultGroups[1] = $m[1];
                } else {
                    if (empty($defaultGroups[0])) $defaultGroups[0] = $tableName;
                    if (empty($defaultGroups[1])) $defaultGroups[1] = $tableName;
                }
            }
            if (empty($defaultGroups[2])) $defaultGroups[2] = 'Other';
            $table['GROUPS'] = $defaultGroups;
            if ($defaultGroups[0] === 'XMP' or $table['NAMESPACE']) {
                // initialize some XMP table defaults
                if (self::RequireModule('Image::ExifTool::XMP') and method_exists(XMP::class, 'RegisterNamespace')) {
                    XMP::RegisterNamespace($table); // register all table namespaces
                }
                // set default write/check procs
                if (!$table['WRITE_PROC']) $table['WRITE_PROC'] = 'Image\ExifTool\XMP::WriteXMP';
                if (!$table['CHECK_PROC']) $table['CHECK_PROC'] = 'Image\ExifTool\XMP::CheckXMP';
                if (!$table['LANG_INFO']) $table['LANG_INFO'] = 'Image\ExifTool\XMP::GetLangInfo';
            }
            // generate a tag prefix for unknown tags if necessary
            if ($table['TAG_PREFIX'] === null) {
                if (preg_match('/Image::.*?::(.*)::Main/', $tableName, $m) or preg_match('/Image::.*?::(.*)/', $tableName, $m)) {
                    $tagPrefix = str_replace('::', '_', $m[1]);
                } else {
                    $tagPrefix = $tableName;
                }
                $table['TAG_PREFIX'] = $tagPrefix;
            }
            // set up the new table
            self::SetupTagTable($table);
            // add any user-defined tags (except Composite tags, which are handled specially)
            if (self::$UserDefined and isset(self::$UserDefined[$tableName]) and $tableName !== 'Image::ExifTool::Composite') {
                $userDef = self::$UserDefined[$tableName];
                if (is_array($userDef)) $userDef = self::$UserDefined[$tableName] = new TagTable($userDef);
                foreach (self::TagTableKeys($userDef) as $tagID) {
                    unset($table[$tagID]);  // replace any existing entry
                    self::AddTagToTable($table, $tagID, $userDef[$tagID], 1);
                }
            }
            // remember order we loaded the tables in
            self::$tableOrder[] = $tableName;
            // insert newly loaded table into list
            self::$allTables[$tableName] = $table;
        }
        // must check each time to add UserDefined Composite tags because the Composite table
        // may be loaded before the UserDefined tags are available
        if ($tableName === 'Image::ExifTool::Composite' and empty($table['VARS']['LOADED_USERDEFINED']) and
            self::$UserDefined and isset(self::$UserDefined[$tableName]))
        {
            $userComp = self::$UserDefined[$tableName];
            unset(self::$UserDefined[$tableName]);          // (must delete first to avoid infinite recursion)
            self::AddCompositeTags($userComp, 1);
            self::$UserDefined[$tableName] = $userComp;     // (add back again for adding writable tags later)
            $vars = $table['VARS'] ?: [];
            $vars['LOADED_USERDEFINED'] = 1;                // set flag to avoid doing this again
            $table['VARS'] = $vars;
        }
        return $table;
    }

    //------------------------------------------------------------------------------
    // Process an image directory
    // Inputs: 0) ExifTool object reference, 1) directory information reference
    //         2) tag table reference, 3) optional reference to processing procedure
    // Returns: Result from processing (1=success)
    public function ProcessDirectory(array &$dirInfo, ?TagTable $tagTablePtr, $proc = null)
    {
        if (!$tagTablePtr) return 0;
        // use default proc from tag table or EXIF proc as fallback if no proc specified
        if (!$proc) $proc = $tagTablePtr['PROCESS_PROC'] ?: 'Image\ExifTool\Exif::ProcessExif';
        // set directory name from default group0 name if not done already
        $dirName = $dirInfo['DirName'] ?? null;
        if (!$dirName) {
            $dirName = $tagTablePtr['GROUPS'][0] ?? null;
            if (preg_match('/^APP\d+$/', (string) $dirName)) $dirName = $tagTablePtr['GROUPS'][1] ?? null; // (use specific APP name)
            $dirInfo['DirName'] = $dirName;
        }

        // guard against cyclical recursion into the same directory
        if (isset($dirInfo['DirStart']) and isset($dirInfo['DataPos']) and
            // directories don't overlap if the length is zero
            (!empty($dirInfo['DirLen']) or !isset($dirInfo['DirLen'])))
        {
            $addr = Perl::str($dirInfo['DirStart'] + $dirInfo['DataPos'] + (($dirInfo['Base'] ?? 0) ?: 0) + $this->BASE);
            if (!empty($this->PROCESSED[$addr]) and empty($dirInfo['NotDup'])) {
                $this->Warn("$dirName pointer references previous {$this->PROCESSED[$addr]} directory");
                // patch for bug in Windows phone 7.5 O/S that writes incorrect InteropIFD pointer
                if (!($dirName === 'GPS' and $this->PROCESSED[$addr] === 'InteropIFD')) return 0;
            }
            if (!($tagTablePtr['VARS'] and !empty($tagTablePtr['VARS']['ALLOW_REPROCESS']))) $this->PROCESSED[$addr] = $dirName;
        }
        $oldOrder = self::GetByteOrder();
        $save = [$this->INDENT, $this->DIR_NAME, $this->Compression, $this->SubfileType];
        $this->LIST_TAGS = [];  // don't build lists across different directories
        $this->INDENT .= '| ';
        $this->DIR_NAME = $dirName;
        $this->PATH[] = $dirName;
        $this->FOUND_DIR[$dirName] = 1;

        // process the directory
        $rtnVal = $this->CallProc($proc, $dirInfo, $tagTablePtr);

        array_pop($this->PATH);
        [$this->INDENT, $this->DIR_NAME, $this->Compression, $this->SubfileType] = $save;
        self::SetByteOrder($oldOrder);
        return $rtnVal;
    }

    /**
     * Call a PROCESS_PROC (Perl &$proc($self, $dirInfo, $tagTablePtr)): a string callable naming an
     * ExifTool method ('Image\ExifTool\ExifTool::ProcessBinaryData') is called on this object.
     */
    public function CallProc($proc, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        if (is_string($proc) and str_starts_with($proc, 'Image\ExifTool\ExifTool::')) {
            $method = substr($proc, 25);
            if (method_exists($this, $method)) return $this->$method($dirInfo, $tagTablePtr);
        } elseif (is_callable($proc)) {
            return $proc($this, $dirInfo, $tagTablePtr);
        }
        // (module not ported yet)
        $this->Warn('Not ported: ' . (is_string($proc) ? $proc : get_debug_type($proc)));
        return 0;
    }

    //------------------------------------------------------------------------------
    // Get Metadata path
    // Inputs: 0) ExifTool object ref
    // Return: Metadata path string
    public function MetadataPath()
    {
        return implode('-', $this->PATH);
    }

    //------------------------------------------------------------------------------
    // Get standardized file extension
    // Inputs: 0) file name
    // Returns: standardized extension (all uppercase), or undefined if no extension
    public static function GetFileExtension($filename)
    {
        $fileExt = null;
        if ($filename and preg_match('/^.*\.([^.]+)$/s', (string) $filename, $m)) {
            $fileExt = strtoupper($m[1]);   // change extension to upper case
            // convert TIF extension to TIFF because we use the
            // extension for the file type tag of TIFF images
            if ($fileExt === 'TIF') $fileExt = 'TIFF';
        }
        return $fileExt;
    }

    //------------------------------------------------------------------------------
    // Get list of tag information hashes for given tag ID
    // Inputs: 0) Tag table reference, 1) tag ID
    // Returns: Array of tag information references
    // Notes: Generates tagInfo hash if necessary
    public static function GetTagInfoList(TagTable $tagTablePtr, $tagID): array
    {
        $tagInfo = $tagTablePtr[$tagID];

        if (isset(Vars\ExifTool::$specialTags[$tagID])) {
            // (hopefully this won't happen)
            self::PerlWarn("Tag $tagID conflicts with internal ExifTool variable in {$tagTablePtr['TABLE_NAME']}\n");
        } elseif ($tagInfo instanceof TagInfo) {
            return [$tagInfo];
        } elseif (is_array($tagInfo)) {
            if (!$tagInfo) {
                // (an empty Perl hash: {})
                $tagInfo = $tagTablePtr[$tagID] = new TagInfo();
                return [$tagInfo];
            }
            return $tagInfo;
        } elseif ($tagInfo) {
            // create hash with name
            $tagInfo = $tagTablePtr[$tagID] = new TagInfo(['Name' => $tagInfo]);
            return [$tagInfo];
        }
        return [];
    }

    //------------------------------------------------------------------------------
    // Find tag information, processing conditional tags
    // Inputs: 0) ExifTool object reference, 1) tagTable pointer, 2) tag ID
    //         3) optional value reference (usually reference to binary data value, but
    //            depends on information type), 4) optional format type, 5) optional value count
    //            (PHP: the value itself)
    // Returns: pointer to tagInfo hash, undefined if none found, or '' if $valPt needed
    // Notes: You should always call this routine to find a tag in a table because
    // this routine will evaluate conditional tags.
    // Arguments 3-5 are only required if the information type allows $valPt, $format and/or
    // $count in a Condition, and if not given when needed this routine returns ''.
    public function GetTagInfo(TagTable $tagTablePtr, $tagID, $valPt = null, $format = null, $count = null)
    {
        $infoArray = self::GetTagInfoList($tagTablePtr, $tagID);
        $options = $this->OPTIONS;
        // evaluate condition
        foreach ($infoArray as $tagInfo) {
            $condition = $tagInfo->Condition;
            if ($condition) {
                if ($valPt === null and self::ConditionUsesData($condition)) return '';
                // set old value for use in condition if needed
                self::$evalWarning = null;
                self::EvalBegin();
                try {
                    //### eval Condition ($self, [$valPt, $format, $count])
                    $ok = $condition instanceof \Closure || is_callable($condition) ?
                          $condition($this, $valPt, $format, $count) : $condition;
                } catch (\Throwable $e) {
                    $ok = null;
                    self::$evalWarning = self::EvalError($e);
                } finally {
                    self::EvalEnd();
                }
                if (!$ok) {
                    if (self::$evalWarning) $this->Warn("Condition {$tagInfo->Name}: " . self::CleanWarning());
                    continue;
                }
            }
            // don't return Unknown tags unless that option is set or we are writing (also see forum13716)
            if ($tagInfo->Unknown and !$options['Unknown'] and
                (!$this->IsWriting or $tagInfo->AddedUnknown) and !
                ($options['Verbose'] or $this->HTML_DUMP or
                ($options['Validate'] and !$tagInfo->AddedUnknown)))
            {
                return null;
            }
            // return the tag information we found
            return $tagInfo;
        }
        // generate information for unknown tags (numerical only) if required
        if (($options['Unknown'] or $options['Verbose'] or $this->HTML_DUMP) and
            preg_match('/^\d+$/', (string) $tagID) and !$this->NO_UNKNOWN)
        {
            if ($tagTablePtr['PRINT_CONV'] !== null) {
                $printConv = $tagTablePtr['PRINT_CONV'];
            } else {
                // limit length of printout (can be very long)
                $printConv = 'Image\ExifTool\ExifTool::LimitLongValues';
            }
            $hex = Perl::sprintf('0x%.4x', $tagID);
            $prefix = $tagTablePtr['TAG_PREFIX'];
            $tagInfo = new TagInfo([
                'Name' => "{$prefix}_$hex",
                'Description' => self::MakeDescription($prefix, $hex),
                'Unknown' => 1,
                'Writable' => 0,  // can't write unknown tags
                'PrintConv' => $printConv,
                'AddedUnknown' => 1,
            ]);
            // add tag information to table
            self::AddTagToTable($tagTablePtr, $tagID, $tagInfo);
        } else {
            $tagInfo = null;
        }
        return $tagInfo;
    }

    //------------------------------------------------------------------------------
    // Get tagInfo for an alternate-language tag (from Writer.pl)
    // Inputs: 0) tagInfo hash ref, 1) locale code (eg. "en-CA")
    // Returns: new tagInfo hash ref
    public static function GetLangInfo(TagInfo $tagInfo, $langCode): TagInfo
    {
        // make a new tagInfo hash for this locale
        $table = $tagInfo->Table;
        $tagID = Perl::str($tagInfo->TagID) . '-' . $langCode;
        $langInfo = $table[$tagID];
        if (!$langInfo instanceof TagInfo) {
            // make a new tagInfo entry for this locale
            $langInfo = self::AddTagToTable($table, $tagID, [
                ...$tagInfo->toArray(),
                'Name' => $tagInfo->Name . '-' . $langCode,
                'Description' => self::MakeDescription($tagInfo->Name) . " ($langCode)",
                'LangCode' => $langCode,
                'SrcTagInfo' => $tagInfo, // save reference to original tagInfo
            ]);
        }
        return $langInfo;
    }

    //------------------------------------------------------------------------------
    // Add new tag to table (must use this routine to add new tags to a table)
    // Inputs: 0) reference to tag table, 1) tag ID
    //         2) [optional] tag name or reference to tag information hash
    //         3) [optional] flag to avoid adding prefix when generating tag name
    // Returns: tagInfo ref
    // Notes: - will not override existing entry in table
    // - info need contain no entries when this routine is called
    // - tag name is cleaned if necessary
    public static function AddTagToTable(TagTable $tagTablePtr, $tagID, $tagInfo = null, $noPrefix = null): TagInfo
    {
        // generate tag info hash if necessary
        if (is_array($tagInfo)) {
            $tagInfo = new TagInfo($tagInfo);
        } elseif (!$tagInfo instanceof TagInfo) {
            $tagInfo = new TagInfo($tagInfo ? ['Name' => $tagInfo] : []);
        }

        // define necessary entries in information hash
        $tableGroups = $tagTablePtr['GROUPS'] ?: [];
        if ($tagInfo->Groups) {
            // fill in default groups from table GROUPS
            $groups = $tagInfo->Groups;
            foreach ($tableGroups as $k => $g) {
                if (!empty($groups[$k])) continue;
                $groups[$k] = $g;
            }
            $tagInfo->Groups = $groups;
        } else {
            $tagInfo->Groups = $tableGroups;
        }
        if ($tagInfo->Flags) self::ExpandFlags($tagInfo);
        $tagInfo->GotGroups = 1;
        $tagInfo->Table = $tagTablePtr;
        $tagInfo->TagID = $tagID;
        if ($tagInfo->Hidden === null) $tagInfo->Hidden = 1;
        if ($tagTablePtr['AVOID'] !== null and $tagInfo->Avoid === null) {
            $tagInfo->Avoid = $tagTablePtr['AVOID'];
        }

        $name = $tagInfo->Name;
        if ($name === null) $name = $tagID;
        $name = preg_replace('/[^-_a-zA-Z0-9]/', '', Perl::str($name));   // remove illegal characters
        $name = ucfirst($name);         // capitalize first letter
        // add tag-name prefix if specified and tag name not provided
        if (!($tagInfo->Name !== null or $noPrefix or !$tagTablePtr['TAG_PREFIX'])) {
            // make description to prevent tagID from getting mangled by MakeDescription()
            $tagInfo->Description = self::MakeDescription($tagTablePtr['TAG_PREFIX'], $name);
            $name = "{$tagTablePtr['TAG_PREFIX']}_$name";
        }
        // tag names must be at least 2 characters long and prefer them to start with a letter
        if (strlen($name) < 2 or !preg_match('/^[A-Z]/i', $name)) $name = "Tag$name";
        $tagInfo->Name = $name;
        // add tag to table, but never override existing entries (could potentially happen
        // if someone thinks there isn't any tagInfo because a condition wasn't satisfied)
        if (!(isset($tagTablePtr[$tagID]) or isset(Vars\ExifTool::$specialTags[$tagID]))) {
            $tagTablePtr[$tagID] = $tagInfo;
            if (self::$purgeFlag and $tagInfo->Unknown and !$tagInfo->SubDirectory) {
                self::$purgeTags[] = $tagInfo;
            }
        }
        if ($tagInfo->Unknown) $tagInfo->AddedUnknown = 1;
        return $tagInfo;
    }

    //------------------------------------------------------------------------------
    // Handle simple extraction of new tag information
    // Inputs: 0) ExifTool object ref, 1) tag table reference, 2) tagID, 3) raw value,
    //         4-N) parameters hash: Index, DataPt, DataPos, Base, Start, Size, Parent,
    //              TagInfo, ProcessProc, RAF, Format, Count, MakeTagInfo
    //              (PHP: named arguments, eg. DataPt: $dataPt, Start: 4)
    // Returns: tag key or undef if tag not found
    // Notes: if value is not defined, it is extracted from DataPt using TagInfo
    //        Format and Count if provided
    // - set MakeTagInfo to add tag info for unknown tags with name made from tag ID
    public function HandleTag(TagTable $tagTablePtr, $tag, $val, mixed ...$parms)
    {
        $verbose = $this->OPTIONS['Verbose'];
        $pfmt = $parms['Format'] ?? null;
        $valPt = $val;
        $tagInfo = ($parms['TagInfo'] ?? null) ?: $this->GetTagInfo($tagTablePtr, $tag, $valPt, $pfmt, $parms['Count'] ?? null);
        $dataPt = $parms['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $subdir = $format = $noTagInfo = $rational = $binVal = null;

        // get binary data for Condition if necessary
        if (!$tagInfo and $tagInfo !== null and $dataPt !== null) {
            $start = ($parms['Start'] ?? 0) ?: 0;
            $size = $parms['Size'] ?? null;
            if ($size === null) $size = strlen($dataPt) - $start;
            if ($start + $size > strlen($dataPt)) return null;
            if ($size > 1024) $size = 1024;   // max 1024 bytes available for the Condition
            $dat = substr($dataPt, (int) $start, (int) $size);
            $tagInfo = $this->GetTagInfo($tagTablePtr, $tag, $dat, $pfmt, $parms['Count'] ?? null);
        }
        if ($tagInfo) {
            $subdir = $tagInfo->SubDirectory;
        } elseif (!empty($parms['MakeTagInfo'])) {
            if ($verbose) $this->VPrint(0, $this->INDENT, "[adding $tag]\n");
            $name = Perl::str($tag);
            $name = preg_replace('/([A-Z]) ([A-Z][ A-Z])/', '${1}_$2', $name);  // underline between acronyms
            $name = preg_replace_callback('/([^A-Za-z])([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name); // capitalize words
            $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);                // remove illegal characters
            if (strlen($name) < 2 or preg_match('/^[-0-9]/', $name)) $name = "Tag$name";
            $tagInfo = new TagInfo(['Name' => ucfirst($name)]);
            self::AddTagToTable($tagTablePtr, $tag, $tagInfo);
        } else {
            if (!$verbose) return null;
            $tagInfo = new TagInfo(['Name' => "tag $tag"]);  // create temporary tagInfo hash
            $noTagInfo = 1;
        }
        // read value if not done already (not necessary for subdir)
        if (!($val !== null or ($subdir !== null and !$tagInfo->Writable and !$tagInfo->RawConv))) {
            $start = ($parms['Start'] ?? 0) ?: 0;
            $dLen = $dataPt !== null ? strlen($dataPt) : -1;
            $size = $parms['Size'] ?? null;
            if ($size === null) $size = ($dLen > 0 ? $dLen : 0);
            // read from data in memory if possible
            if ($start >= 0 and $start + $size <= $dLen) {
                $format = $tagInfo->Format ?: $tagTablePtr['FORMAT'];
                if (!$format and $pfmt and isset(self::$formatSize[$pfmt])) $format = $pfmt;
                if (!$format) {
                    $val = substr($dataPt, (int) $start, (int) $size);
                } elseif (!$tagInfo->ByteOrder) {
                    $val = self::ReadValue($dataPt, $start, $format, $tagInfo->Count, $size, $rational);
                } else {
                    $oldOrder = self::GetByteOrder();
                    self::SetByteOrder($tagInfo->ByteOrder);
                    $val = self::ReadValue($dataPt, $start, $format, $tagInfo->Count, $size, $rational);
                    self::SetByteOrder($oldOrder);
                }
                if ($this->OPTIONS['SaveBin']) $binVal = substr($dataPt, (int) $start, (int) $size);
            } else {
                $this->Warn("Error extracting value for {$tagInfo->Name}");
                return null;
            }
        }
        // do verbose print if necessary
        if ($verbose) {
            if ($noTagInfo) $tagInfo = null;
            $parms['Value'] = $val;
            if ($rational !== null) $parms['Value'] = Perl::str($parms['Value']) . " ($rational)";
            $parms['Table'] = $tagTablePtr;
            if ($format) {
                $count = Perl::int((($parms['Size'] ?? 0) ?: 0) / ((self::$formatSize[$format] ?? 0) ?: 1));
                $parms['Format'] = $format . "[$count]";
            }
            $this->VerboseInfo($tag, $tagInfo, ...$parms);
        }
        if ($tagInfo) {
            if ($subdir !== null) {
                if ($tagInfo->MakerNotes and $this->OPTIONS['FastScan'] and $this->OPTIONS['FastScan'] > 1) {
                    return null;    // don't process maker note directories when FastScan > 1
                }
                $subdirStart = $parms['Start'] ?? null;
                $subdirLen = $parms['Size'] ?? null;
                if ($tagInfo->RawConv and !$tagInfo->Writable) {
                    $conv = $tagInfo->RawConv;
                    self::$evalWarning = null;
                    self::EvalBegin();
                    try {
                        if (self::IsCodeRef($conv)) {
                            $val = $conv($val, $this);
                        } else {
                            $priority = null;
                            $grps = [];
                            // NOTE: RawConv is evaluated in Writer.pl and twice in ExifTool.pm
                            //### eval RawConv ($self, $val, $tag, $tagInfo, $priority, @grps)
                            $val = $conv($val, $this, $tag, $tagInfo, $priority, $grps, []);
                        }
                    } catch (\Throwable $e) {
                        $val = null;
                        self::$evalWarning = self::EvalError($e);
                    } finally {
                        self::EvalEnd();
                    }
                    if (self::$evalWarning) $this->Warn("RawConv $tag: " . self::CleanWarning());
                    if ($val === null) return null;
                    $dataPt = self::IsScalarRef($val) ? Perl::str($val->value) : (is_array($val) ? $val : Perl::str($val));
                    $subdirStart = 0;
                    $subdirLen = strlen(Perl::str($dataPt));
                } elseif ($dataPt === null) {
                    $dataPt = self::IsScalarRef($val) ? Perl::str($val->value) : (is_array($val) ? $val : Perl::str($val));
                }
                if (!empty($subdir['Start'])) {
                    $valuePtr = 0;
                    $start = $subdir['Start'];
                    if ($start instanceof \Closure) {
                        //### eval Start ($valuePtr)
                        try {
                            $off = $start($valuePtr, $val, null, $dataPt);
                        } catch (\Throwable) {
                            $off = null;
                        }
                    } else {
                        $off = $start;
                    }
                    $subdirStart += Perl::num($off);
                    $subdirLen -= Perl::num($off);
                }
                // process subdirectory information
                $dirInfo = [
                    'DirName'  => ($subdir['DirName'] ?? null) ?: $tagInfo->Name,
                    'DataPt'   => $dataPt,
                    'DataLen'  => strlen(Perl::str($dataPt)),
                    'DataPos'  => $parms['DataPos'] ?? null,
                    'DirStart' => $subdirStart,
                    'DirLen'   => $subdirLen,
                    'DirID'    => $tag,
                    'Parent'   => $parms['Parent'] ?? null,
                    'Base'     => $parms['Base'] ?? null,
                    'Multi'    => $subdir['Multi'] ?? null,
                    'TagInfo'  => $tagInfo,
                    'IgnoreProp' => $subdir['IgnoreProp'] ?? null,
                    'RAF'      => $parms['RAF'] ?? null,
                ];
                $oldOrder = self::GetByteOrder();
                if (!empty($subdir['ByteOrder'])) {
                    if ($subdir['ByteOrder'] === 'Unknown') {
                        if ($subdirStart + 2 <= $subdirLen) {
                            // attempt to determine the byte ordering of an IFD-style subdirectory
                            $num = self::Get16u($dataPt, $subdirStart);
                            if ($num & 0xff00 and ($num >> 8) > ($num & 0xff)) self::ToggleByteOrder();
                        }
                    } else {
                        self::SetByteOrder($subdir['ByteOrder']);
                    }
                }
                $subTablePtr = self::GetTagTable($subdir['TagTable'] ?? null) ?: $tagTablePtr;
                $this->ProcessDirectory($dirInfo, $subTablePtr, ($subdir['ProcessProc'] ?? null) ?: ($parms['ProcessProc'] ?? null));
                self::SetByteOrder($oldOrder);
                // return now unless directory is writable as a block
                if (!$tagInfo->Writable) return null;
            }
            $key = $this->FoundTag($tagInfo, $val);
            if ($key !== null) {
                // save original components of rational numbers and original binary value
                if ($rational !== null) $this->TAG_EXTRA[$key]['Rational'] = $rational;
                if ($binVal !== null) $this->TAG_EXTRA[$key]['BinVal'] = $binVal;
            }
            return $key;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Add tag to hash of extracted information
    // Inputs: 0) ExifTool object reference
    //         1) reference to tagInfo hash or tag name
    //         2) data value (or reference to require hash if Composite)
    //         3) optional family 0 group, 4) optional family 1 group
    // Returns: tag key or undef if no value
    public function FoundTag($tagInfo, $value, ...$grps)
    {
        $noListDel = $tbl = null;
        $options = $this->OPTIONS;

        if ($tagInfo instanceof TagInfo) {
            $tag = $tagInfo->Name;
            if (!$tag) {
                self::PerlWarn("No tag name\n");
                return null;
            }
            $tbl = $tagInfo->Table;
        } else {
            $tag = Perl::str($tagInfo);
            // look for tag in Extra
            $tbl = self::GetTagTable('Image::ExifTool::Extra');
            $tagInfo = $this->GetTagInfo($tbl, $tag);
            // make temporary hash if tag doesn't exist in Extra
            // (not advised to do this since the tag won't show in list)
            if (!$tagInfo) $tagInfo = new TagInfo(['Name' => $tag, 'Groups' => Vars\ExifTool::$allGroupsExifTool]);
            if ($options['Verbose']) $this->VerboseInfo(null, $tagInfo, Value: $value);
        }
        // get tag priority
        $priority = $tagInfo->Priority;
        if ($priority === null) {
            $priority = $tbl ? $tbl['PRIORITY'] : null;
            if ($priority === null and $tagInfo->Avoid) $priority = 0;
        }
        if (empty($grps[0])) $grps[0] = $this->SET_GROUP0;
        if (empty($grps[1])) $grps[1] = $this->SET_GROUP1;
        if ($options['IgnoreGroups']) {
            foreach ([0, 1] as $f) {
                $table = $tagInfo->Table;
                $g = strtolower((string) (($grps[$f] ?? null) ?: (($tagInfo->Groups[$f] ?? null) ?: ($table ? ($table['GROUPS'][$f] ?? null) : null))));
                if (!empty($options['IgnoreGroups'][$g]) or !empty($options['IgnoreGroups']["$f$g"])) return null;
            }
        }

        if ($tagInfo->RawConv) {
            // initialize @val for use in Composite RawConv expressions
            $vals = [];
            if ($value instanceof \ArrayObject and $tagInfo->IsComposite) {
                foreach ($value as $k => $key) {
                    $vals[$k] = $this->VALUE[$key] ?? null;
                }
            }
            $conv = $tagInfo->RawConv;
            self::$evalWarning = null;
            self::EvalBegin();
            try {
                if (self::IsCodeRef($conv)) {
                    $value = $conv($value, $this);
                    if ($this->grps) {
                        $grps = $this->grps;
                        $this->grps = null;
                    }
                } else {
                    $val = $value;  // do this so eval can use $val
                    // NOTE: RawConv is also evaluated in Writer.pl
                    //### eval RawConv ($self, $val, $tag, $tagInfo, $priority, @grps)
                    $value = $conv($val, $this, $tag, $tagInfo, $priority, $grps, $vals);
                }
            } catch (\Throwable $e) {
                $value = null;
                self::$evalWarning = self::EvalError($e);
            } finally {
                self::EvalEnd();
            }
            if (self::$evalWarning) $this->Warn("RawConv $tag: " . self::CleanWarning());
            if ($value === null) return null;
        }
        // ignore specified tags (AFTER doing RawConv if necessary!)
        if ($options['IgnoreTags']) {
            if (!empty($options['IgnoreTags']['all'])) {
                if (empty($this->REQ_TAG_LOOKUP[strtolower($tag)])) return null;
            } else {
                if (!empty($options['IgnoreTags'][strtolower($tag)])) return null;
            }
        }
        // handle duplicate tag names
        if (isset($this->VALUE[$tag])) {
            // add to list if there is an active list for this tag
            $infoID = spl_object_id($tagInfo);
            if (!empty($this->LIST_TAGS[$infoID])) {
                $tag = $this->LIST_TAGS[$infoID]; // use key from previous list tag
                if ($this->NO_LIST !== null) {
                    // accumulate list in TAG_EXTRA "NoList" element
                    if (isset($this->TAG_EXTRA[$tag]['NoList'])) {
                        $this->TAG_EXTRA[$tag]['NoList'][] = $value;
                    } else {
                        $this->TAG_EXTRA[$tag]['NoList'] = [$this->VALUE[$tag], $value];
                    }
                    $noListDel = 1; // set flag to delete this tag if re-listed
                } else {
                    if (!(is_array($this->VALUE[$tag]) and array_is_list($this->VALUE[$tag]))) {
                        $this->VALUE[$tag] = [$this->VALUE[$tag]];
                    }
                    $this->VALUE[$tag][] = $value;
                    return $tag;    // return without creating a new entry
                }
            }
            // get next available tag key
            $nextInd = $this->DUPL_TAG[$tag] = ($this->DUPL_TAG[$tag] ?? 0) + 1;
            $nextTag = "$tag ($nextInd)";
//
// take tag with highest priority
//
            // promote existing 0-priority tag so it takes precedence over a new 0-tag
            // (unless old tag was a sub-document and new tag isn't.  Also, never override
            //  a Warning tag because they may be added by ValueConv, which could be confusing)
            $oldPriority = $this->PRIORITY[$tag] ?? null;
            if (!$oldPriority) {
                if ($this->DOC_NUM or $tag === 'Warning' or empty($this->TAG_EXTRA[$tag]['G3'])) {
                    $oldPriority = 1;
                } else {
                    $oldPriority = 0; // don't promote sub-document tag over main document
                }
            }
            // set priority for this tag
            if ($priority !== null) {
                // increase 0-priority tags if this is the priority directory
                if (!$priority and $this->DIR_NAME and $this->DIR_NAME === $this->PRIORITY_DIR) $priority = 1;
            } elseif (!empty($this->LOW_PRIORITY_DIR['*']) or
                ($this->DIR_NAME and !empty($this->LOW_PRIORITY_DIR[$this->DIR_NAME])))
            {
                $priority = 0;  // default is 0 for a LOW_PRIORITY_DIR
            } else {
                $priority = 1;  // the normal default
            }
            if ($priority >= $oldPriority and (!$this->DOC_NUM or (!empty($this->TAG_EXTRA[$tag]['G3']) and
                 Perl::str($this->DOC_NUM) === Perl::str($this->TAG_EXTRA[$tag]['G3']))) and !$noListDel)
            {
                // move existing tag out of the way since this tag is higher priority
                // (NOTE: any new members added here must also be added to DeleteTag())
                $this->PRIORITY[$nextTag] = $this->PRIORITY[$tag] ?? null;
                $this->VALUE[$nextTag] = $this->VALUE[$tag];
                $this->FILE_ORDER[$nextTag] = $this->FILE_ORDER[$tag] ?? null;
                $oldInfo = $this->TAG_INFO[$nextTag] = $this->TAG_INFO[$tag] ?? null;
                $this->TAG_EXTRA[$nextTag] = $this->TAG_EXTRA[$tag] ?? null;
                $this->TAG_EXTRA[$tag] = [];
                unset($this->BOTH[$tag]);
                // update tag key for list if necessary
                if ($oldInfo and !empty($this->LIST_TAGS[spl_object_id($oldInfo)])) $this->LIST_TAGS[spl_object_id($oldInfo)] = $nextTag;
                // update this key if used in a Composite tag
                if (!empty($this->COMP_KEYS[$tag])) {
                    foreach ($this->COMP_KEYS[$tag] as $ck) $ck[0][$ck[1]] = $nextTag;
                    $this->COMP_KEYS[$nextTag] = $this->COMP_KEYS[$tag];
                    unset($this->COMP_KEYS[$tag]);
                }
            } else {
                $tag = $nextTag;        // don't override the existing tag
            }
            $this->PRIORITY[$tag] = $priority;
            if ($noListDel) $this->TAG_EXTRA[$tag]['NoListDel'] = 1;
        } elseif ($priority) {
            // set tag priority (only if exists and is non-zero)
            $this->PRIORITY[$tag] = $priority;
        }

        // save the raw value, file order, tagInfo ref, group1 name,
        // and tag key for lists if necessary
        $this->VALUE[$tag] = $value;
        $this->FILE_ORDER[$tag] = ++$this->NUM_FOUND;
        $this->TAG_INFO[$tag] = $tagInfo;
        if (!isset($this->TAG_EXTRA[$tag])) $this->TAG_EXTRA[$tag] = [];
        // set dynamic groups 0, 1 and 3 if necessary
        if (!empty($grps[0])) $this->TAG_EXTRA[$tag]['G0'] = $grps[0];
        if (!empty($grps[1])) $this->TAG_EXTRA[$tag]['G1'] = $grps[1];
        if ($this->DOC_NUM) {
            $this->TAG_EXTRA[$tag]['G3'] = $this->DOC_NUM;
            $this->HAS_DOC[$this->DOC_NUM] = 1;
            if (preg_match('/^(\d+)/', (string) $this->DOC_NUM, $m)) {
                // keep track of maximum 1st-level sub-document number
                if (!($this->DOC_COUNT >= $m[1])) $this->DOC_COUNT = (int) $m[1];
            }
        }
        // save path if requested
        if ($options['SavePath']) $this->TAG_EXTRA[$tag]['G5'] = $this->MetadataPath();

        // remember this tagInfo if we will be accumulating values in a list
        // (but don't override earlier list if this may be deleted by NoListDel flag)
        if ($tagInfo->List and !$this->NO_LIST and !$noListDel) {
            $this->LIST_TAGS[spl_object_id($tagInfo)] = $tag;
        }

        // validate tag if requested (but only for simple values -- could result
        // in infinite recursion if called for a Composite tag (HASH ref value)
        // because FoundTag is called in the middle of building Composite tags
        if ($options['Validate'] and !is_array($value) and !is_object($value) and method_exists(Validate::class, 'ValidateRaw')) {
            Validate::ValidateRaw($this, $tag, $value);
        }

        return $tag;
    }

    //------------------------------------------------------------------------------
    // Make current directory the priority directory if not set already
    // Inputs: 0) ExifTool object reference
    public function SetPriorityDir(): void
    {
        if (!$this->PRIORITY_DIR) $this->PRIORITY_DIR = $this->DIR_NAME;
    }

    //------------------------------------------------------------------------------
    // Set family 0 or 1 group name specific to this tag instance
    // Inputs: 0) ExifTool ref, 1) tag key, 2) group name, 3) family (default 1)
    public function SetGroup($tagKey, $extra, $fam = null): void
    {
        $this->TAG_EXTRA[$tagKey][$fam !== null ? "G$fam" : 'G1'] = $extra;
    }

    //------------------------------------------------------------------------------
    // Delete specified tag
    // Inputs: 0) ExifTool object ref, 1) tag key
    public function DeleteTag($tag): void
    {
        unset($this->VALUE[$tag]);
        unset($this->FILE_ORDER[$tag]);
        unset($this->TAG_INFO[$tag]);
        unset($this->TAG_EXTRA[$tag]);
        unset($this->PRIORITY[$tag]);
        unset($this->BOTH[$tag]);
    }

    //------------------------------------------------------------------------------
    // Escape all elements of a value
    // Inputs: 0) value, 1) escape proc
    public static function DoEscape(&$val, $proc): void
    {
        if (!is_array($val) and !is_object($val)) {
            $val = $proc($val);
        } elseif (is_array($val)) {
            foreach ($val as &$v) {
                self::DoEscape($v, $proc);
            }
            unset($v);
        } elseif ($val instanceof \ArrayObject) {
            foreach ($val as $key => $v) {
                self::DoEscape($v, $proc);
                $val[$key] = $v;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Set the FileType and MIMEType tags
    // Inputs: 0) ExifTool object reference
    //         1) Optional file type (uses FILE_TYPE if not specified)
    //         2) Optional MIME type (uses our lookup if not specified)
    //         3) Optional recommended extension (converted to lower case; uses FileType if undef)
    // Notes:  Will NOT set file type twice (subsequent calls ignored)
    public function SetFileType($fileType = null, $mimeType = null, $normExt = null): void
    {
        // use only the first FileType set if called again for the main document
        if (!($this->FileType and !$this->DOC_NUM)) {
            $fileTypeLookup = Vars\ExifTool::$fileTypeLookup;
            $baseType = $this->FILE_TYPE;
            $ext = $this->FILE_EXT;
            if (!$fileType) $fileType = $baseType;
            // handle sub-types which are identified by extension
            if ($ext !== null and $ext !== $fileType and !$this->DOC_NUM) {
                $f = $fileTypeLookup[$fileType] ?? null;
                $e = $fileTypeLookup[$ext] ?? null;
                if (is_array($f) and is_array($e) and is_string($f[0]) and is_string($e[0]) and $f[0] === $e[0]) {
                    // make sure $fileType was a root type and not another sub-type
                    if ($f[0] === $fileType or !isset($fileTypeLookup[$f[0]])) $fileType = $ext;
                }
            }
            if (!$mimeType) $mimeType = Vars\ExifTool::$mimeType[$fileType] ?? null;
            // use base file type if necessary (except if 'TIFF', which is a special case)
            if (!($mimeType or $baseType === 'TIFF')) $mimeType = Vars\ExifTool::$mimeType[$baseType] ?? null;
            if ($normExt === null) {
                $normExt = Vars\ExifTool::$fileTypeExt[$fileType] ?? null;
                if ($normExt === null) $normExt = $fileType;
            }
            // ($$self{FileType} is the file type of the main document)
            if (!$this->DOC_NUM) $this->FileType = $fileType;
            $this->FoundTag('FileType', $fileType);
            $this->FoundTag('FileTypeExtension', strtoupper((string) $normExt));
            $this->FoundTag('MIMEType', $mimeType ?: 'application/unknown');
        }
    }

    //------------------------------------------------------------------------------
    // Override the FileType and MIMEType tags
    // Inputs: 0) ExifTool object ref, 1) file type, 2) MIME type, 3) normal extension (lower case)
    // Notes:  does nothing if FileType was not previously defined (ie. when writing)
    public function OverrideFileType($fileType, $mimeType = null, $normExt = null): void
    {
        if (isset($this->VALUE['FileType']) and $fileType !== $this->VALUE['FileType']) {
            $this->FileType = $fileType;
            $this->VALUE['FileType'] = $fileType;
            if ($normExt === null) {
                $normExt = Vars\ExifTool::$fileTypeExt[$fileType] ?? null;
                if ($normExt === null) $normExt = $fileType;
            }
            $this->VALUE['FileTypeExtension'] = strtoupper((string) $normExt);
            if (!$mimeType) $mimeType = Vars\ExifTool::$mimeType[$fileType] ?? null;
            if ($mimeType) $this->VALUE['MIMEType'] = $mimeType;
            if ($this->OPTIONS['Verbose']) {
                $this->VPrint(0, "{$this->INDENT}FileType [override] = $fileType\n");
                $this->VPrint(0, "{$this->INDENT}FileTypeExtension [override] = {$this->VALUE['FileTypeExtension']}\n");
                if ($mimeType) $this->VPrint(0, "{$this->INDENT}MIMEType [override] = $mimeType\n");
            }
        }
    }

    //------------------------------------------------------------------------------
    // Modify the value of the MIMEType tag
    // Inputs: 0) ExifTool object reference, 1) file or MIME type
    // Notes: combines existing type with new type: ie) a/b + c/d => c/b-d
    public function ModifyMimeType($mime = null): void
    {
        $mime = (string) $mime;
        if (!str_contains($mime, '/')) {
            $mime = Vars\ExifTool::$mimeType[$mime] ?? null;
            if (!$mime) return;
        }
        $old = $this->VALUE['MIMEType'] ?? null;
        if ($old !== null) {
            $ab = Perl::split('{/}', $old);
            $cd = Perl::split('{/}', $mime);
            $b = $ab[1] ?? '';
            $c = $cd[0] ?? '';
            $d = preg_replace('/^x-/', '', $cd[1] ?? '');
            $this->VALUE['MIMEType'] = "$c/$b-$d";
            $this->VPrint(0, "  Modified MIMEType = $c/$b-$d\n");
        } else {
            $this->FoundTag('MIMEType', $mime);
        }
    }

    //------------------------------------------------------------------------------
    // Print verbose output
    // Inputs: 0) ExifTool ref, 1) verbose level (prints if level > this), 2-N) print args
    public function VPrint($level, ...$args): void
    {
        if ($this->OPTIONS['Verbose'] and $this->OPTIONS['Verbose'] > $level) {
            $out = $this->OPTIONS['TextOut'];
            $str = implode('', array_map(Perl::str(...), $args));
            if (!preg_match('/\n$/', $args ? Perl::str($args[count($args) - 1]) : '')) $str .= "\n";
            fwrite($out, $str);
        }
    }

    //------------------------------------------------------------------------------
    // Print verbose directory information
    // Inputs: 0) ExifTool object reference, 1) directory name or dirInfo ref
    //         2) number of entries in directory (or 0 if unknown)
    //         3) optional size of directory in bytes, 4) optional byte order for -v3 output
    public function VerboseDir($name, $entries = null, $size = null, $byteOrder = null): void
    {
        if (!$this->OPTIONS['Verbose']) return;
        if (is_array($name)) {
            if (!$size) $size = $name['DirLen'] ?? null;
            $name = ($name['Name'] ?? null) ?: ($name['DirName'] ?? null);
        }
        $indent = substr($this->INDENT, 0, -2);
        $out = $this->OPTIONS['TextOut'];
        $str = ($entries or ($entries !== null and !$size)) ? " with $entries entries" : '';
        if ($size) $str .= ", $size bytes";
        if ($byteOrder and $this->OPTIONS['Verbose'] > 2) {
            $str .= ', ' . (self::GetByteOrder() === 'II' ? 'Little-endian' : 'Big-endian');
        }
        fwrite($out, "$indent+ [$name directory$str]\n");
    }

    //------------------------------------------------------------------------------
    // Verbose dump
    // Inputs: 0) ExifTool ref, 1) data ref, 2-N) HexDump options
    public function VerboseDump($dataPt, mixed ...$opts): void
    {
        $verbose = $this->OPTIONS['Verbose'];
        if ($verbose and $verbose > 2) {
            $parms = [
                'Prefix' => $this->INDENT,
                'Out'    => $this->OPTIONS['TextOut'],
                'MaxLen' => $verbose < 4 ? 96 : ($verbose < 5 ? 2048 : null),
            ];
            self::HexDump($dataPt, null, ...array_merge($parms, $opts));
        }
    }

    //------------------------------------------------------------------------------
    // Print data in hex
    // Inputs: 0) data
    // Returns: hex string
    // (this is a convenience function for use in debugging PrintConv statements)
    public static function PrintHex($val)
    {
        $val = Perl::str($val);
        return $val === '' ? '' : implode(' ', str_split(bin2hex($val), 2));
    }

    //------------------------------------------------------------------------------
    // Extract binary data from file
    // 0) ExifTool object reference, 1) offset, 2) length, 3) tag name if conditional
    // Returns: binary data, or undef on error
    // Notes: Returns "Binary data #### bytes" instead of data unless tag is
    //        specifically requested or the Binary option is set
    public function ExtractBinary($offset, $length, $tag = null)
    {
        $isPreview = $buff = null;

        if ($tag) {
            if ($tag === 'PreviewImage') {
                // save PreviewImage start/length in case we want to dump trailer
                $this->PreviewImageStart = $offset;
                $this->PreviewImageLength = $length;
                $isPreview = 1;
            }
            $lcTag = strtolower($tag);
            $options = $this->OPTIONS;
            if ((!$options['Binary'] or !empty($this->EXCL_TAG_LOOKUP[$lcTag])) and
                 !$options['Verbose'] and !$options['Validate'] and
                 empty($this->REQ_TAG_LOOKUP[$lcTag]))
            {
                return "Binary data $length bytes";
            }
        }
        if (!($this->RAF->Seek($offset, 0)
            and $this->RAF->Read($buff, $length) == $length))
        {
            if (!$tag) $tag = 'binary data';
            if ($isPreview and !$this->BuildingComposite) {
                $this->PreviewError = 1;
            } else {
                $this->Warn("Error reading $tag from file", $isPreview);
            }
            return null;
        }
        return $buff;
    }

    //------------------------------------------------------------------------------
    // Process binary data
    // Inputs: 0) ExifTool object ref, 1) directory information ref, 2) tag table ref
    // Returns: 1 on success
    // Notes: dirInfo may contain VarFormatData (reference to empty list) to return
    //        details about any variable-length-format tags in the table (used when writing)
    public function ProcessBinaryData(array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $dataLen = strlen($dataPt);
        $dirStart = (int) (($dirInfo['DirStart'] ?? 0) ?: 0);
        $maxLen = $dataLen - $dirStart;
        $size = $dirInfo['DirLen'] ?? null;
        $base = ($dirInfo['Base'] ?? 0) ?: 0;
        $verbose = $this->OPTIONS['Verbose'];
        $unknown = $this->OPTIONS['Unknown'];
        $dataPos = ($dirInfo['DataPos'] ?? 0) ?: 0;

        if ($size === null or $size > $maxLen) $size = $maxLen;
        // get default format ('int8u' unless specified)
        $defaultFormat = $tagTablePtr['FORMAT'] ?: 'int8u';
        $increment = self::$formatSize[$defaultFormat] ?? null;
        if (!$increment) {
            self::PerlWarn("Unknown format $defaultFormat\n");
            $defaultFormat = 'int8u';
            $increment = self::$formatSize[$defaultFormat];
        }
        // prepare list of tag numbers to extract
        $tags = [];
        $topIndex = $binVal = null;
        if ($unknown > 1 and $tagTablePtr['FIRST_ENTRY'] !== null) {
            // don't create a stupid number of tags if data is huge
            $sizeLimit = $size < 65536 ? $size : 65536;
            // scan through entire binary table
            $topIndex = Perl::int($sizeLimit / $increment);
            $first = (int) $tagTablePtr['FIRST_ENTRY'];
            $tags = $first <= $topIndex - 1 ? range($first, $topIndex - 1) : [];
            // add in floating point tag ID's if they exist
            $ftags = array_values(preg_grep('/\./', array_map('strval', self::TagTableKeys($tagTablePtr))));
            if ($ftags) {
                $tags = array_merge($tags, $ftags);
                usort($tags, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
            }
        } elseif (!empty($dirInfo['DataMember'])) {
            $tags = $dirInfo['DataMember'];
            $verbose = 0;   // no verbose output of extracted values when writing
        } elseif (!empty($dirInfo['MixedTags'])) {
            // process sorted integer-ID tags only
            $tags = array_values(array_filter(self::TagTableKeys($tagTablePtr), static fn ($k) => preg_match('/^\d+$/', (string) $k)));
            usort($tags, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
        } else {
            // extract known tags in numerical order
            $tags = self::TagTableKeys($tagTablePtr);
            usort($tags, static function ($a, $b) {
                $a = Perl::num($a);
                $b = Perl::num($b);
                return ($a < 0 ? $a + 1e9 : $a) <=> ($b < 0 ? $b + 1e9 : $b);
            });
        }
        if ($verbose) $this->VerboseDir('BinaryData', null, $size, self::GetByteOrder());
        // avoid creating unknown tags for tags that fail condition if Unknown is 1
        if ($unknown < 2) $this->NO_UNKNOWN = 1;
        $valHash = [];  // (Perl %val)
        $nextIndex = 0;
        $varSize = 0;
        foreach ($tags as $index) {
            $tagInfo = $val = $saveNextIndex = $len = $mask = $wasVar = $rational = $offAdj = null;
            if ($tagTablePtr[$index]) {
                $tagInfo = $this->GetTagInfo($tagTablePtr, $index);
                if (!$tagInfo) {
                    if ($tagInfo === null) continue;
                    // $entry = offset of value relative to directory start (or end if negative)
                    $entry = Perl::int($index) * $increment + $varSize;
                    if ($entry < 0) {
                        $entry += $size;
                        if ($entry < 0) continue;
                    }
                    if ($entry >= $size) continue;
                    $more = $size - $entry;
                    if ($more > 128) $more = 128;
                    $v = substr($dataPt, (int) ($entry + $dirStart), (int) $more);
                    $tagInfo = $this->GetTagInfo($tagTablePtr, $index, $v);
                    if (!$tagInfo) continue;
                }
                if ($tagInfo->Unknown and
                       ($tagInfo->Unknown > $unknown or $index < $nextIndex)) continue;
            } elseif ($topIndex and $tagTablePtr[Perl::num($index) - $topIndex]) {
                $tagInfo = $this->GetTagInfo($tagTablePtr, Perl::num($index) - $topIndex);
                if (!$tagInfo) continue;
            } else {
                // don't generate unknown tags in binary tables unless Unknown > 1
                if (!($unknown > 1)) continue;
                if ($index < $nextIndex) continue;  // skip if data already used
                $tagInfo = $this->GetTagInfo($tagTablePtr, $index);
                if (!$tagInfo) continue;
                $tagInfo->Unknown = 2;    // set unknown to 2 for binary unknowns
            }
            // get relative offset of this entry
            $entry = Perl::int($index) * $increment + $varSize;
            // allow negative indices to represent bytes from end
            if ($entry < 0) {
                $entry += $size;
                if ($entry < 0) continue;
            }
            $more = $size - $entry;
            if ($more <= 0) break;  // all done if we have reached the end of data
            $count = 1;
            $format = $tagInfo->Format;
            if (!$format) {
                $format = $defaultFormat;
            } elseif ($format === 'string') {
                // string with no specified count runs to end of block
                $count = $more;
            } elseif ($format === 'pstring') {
                $format = 'string';
                $count = self::Get8u($dataPt, ($entry++) + $dirStart);
                --$more;
            } elseif (!isset(self::$formatSize[$format])) {
                if (preg_match('/(.*)\[(.*)\]/', $format, $m)) {
                    // handle format count field
                    $format = $m[1];
                    $count = $m[2];
                    // evaluate count to allow count to be based on previous values
                    //### eval Format size (%val, $size, $varSize, $self)
                    $fc = $tagInfo->FormatCount;
                    if ($fc !== null) {
                        $err = null;
                        self::EvalBegin();
                        try {
                            $count = $fc($valHash, $size, $varSize, $this);
                        } catch (\Throwable $e) {
                            $err = self::EvalError($e);
                        } finally {
                            self::EvalEnd();
                        }
                        if ($err !== null) {
                            self::PerlWarn("Format {$tagInfo->Name}: $err\n");
                            continue;
                        }
                    }
                    $count = Perl::num($count);
                    if ($count < 0) continue;
                    // allow a variable-length value of any format
                    // (note: the next incremental index points to data immediately after
                    //  this value, regardless of the size of this value, even if it is zero)
                    if (str_starts_with($format, 'var_')) {
                        $format = substr($format, 4);
                        $varSize += $count * ((self::$formatSize[$format] ?? 0) ?: 1) - $increment;
                        $wasVar = 1;
                        // save variable size data if required for writing
                        if (isset($dirInfo['VarFormatData'])) {
                            $dirInfo['VarFormatData'][] = [$index, $varSize, $format];
                        }
                        // don't extract value if large and we wanted it just to get
                        // the variable-format information when writing
                        if ($tagInfo->LargeTag and isset($dirInfo['VarFormatData'])) continue;
                    }
                } elseif (str_starts_with($format, 'var_')) {
                    // handle variable-length string formats
                    $format = substr($format, 4);
                    $p = (int) ($entry + $dirStart);
                    $count = null;
                    if ($format === 'ustring') {
                        if (preg_match('/\G(..)*?\0\0/s', $dataPt, $mm, 0, $p)) $count = strlen($mm[0]);
                        $varSize -= 2;  // ($count includes base size of 2 bytes)
                    } elseif ($format === 'pstring') {
                        $count = self::Get8u($dataPt, ($entry++) + $dirStart);
                        --$more;
                    } elseif ($format === 'pstr32' or $format === 'ustr32') {
                        if ($more < 4) break;
                        $count = self::Get32u($dataPt, $entry + $dirStart);
                        if ($format === 'ustr32') $count *= 2;
                        $entry += 4;
                        $more -= 4;
                        $nextIndex += 4 / $increment;   // (increment next index for int32u)
                    } elseif ($format === 'int16u') {
                        // int16u size of binary data to follow
                        if ($more < 2) break;
                        $count = self::Get16u($dataPt, $entry + $dirStart) + 2;
                        $varSize -= 2;  // ($count includes size word)
                        $format = 'undef';
                    } elseif ($format === 'ue7') {
                        $ue7 = method_exists(BPG::class, 'Get_ue7') ? BPG::Get_ue7($dataPt, $entry + $dirStart) : [null, null];
                        [$val, $count] = $ue7 + [null, null];
                        if ($val === null) break;
                        --$varSize;     // ($count includes base size of 1 byte)
                    } elseif (($z = strpos($dataPt, "\0", $p)) !== false) {
                        $count = $z + 1 - $p;
                        --$varSize;     // ($count includes base size of 1 byte)
                    }
                    if ($count === null or $count > $more) $count = $more;
                    $varSize += $count; // shift subsequent indices
                    if ($val === null) {
                        $val = substr($dataPt, (int) ($entry + $dirStart), (int) $count);
                        if ($format === 'ustring' or $format === 'ustr32') $val = $this->Decode($val, 'UTF16');
                        if ($format !== 'undef') $val = preg_replace('/\0.*/s', '', $val); // truncate at null
                    }
                    if ($this->OPTIONS['SaveBin']) $binVal = substr($dataPt, (int) ($entry + $dirStart), (int) $count);
                    $wasVar = 1;
                    // save variable size data if required for writing
                    if (isset($dirInfo['VarFormatData'])) {
                        $dirInfo['VarFormatData'][] = [$index, $varSize, $format];
                    }
                }
            }
            // hook to allow format, etc to be set dynamically
            if ($tagInfo->Hook !== null) {
                $oldVarSize = $varSize;
                $pos = $entry + $dirStart;
                $hook = $tagInfo->Hook;
                //### eval Hook ($format, $varSize, $size, $dataPt, $pos)
                self::EvalBegin();
                try {
                    $hook($this, $format, $varSize, $size, $dataPt, $pos);
                } catch (\Throwable) {
                    // (errors are ignored, like a failed Perl eval)
                } finally {
                    self::EvalEnd();
                }
                // save variable size data if required for writing (in case changed by Hook)
                if (isset($dirInfo['VarFormatData'])) {
                    if ($wasVar) array_pop($dirInfo['VarFormatData']);  // remove previous entry for this tag
                    $dirInfo['VarFormatData'][] = [$index, $varSize, $format];
                } elseif ($varSize != $oldVarSize and $verbose > 2) {
                    [$tmp, $sign] = [$varSize, '+'];
                    if ($tmp < 0) {
                        $tmp = -$tmp;
                        $sign = '-';
                    }
                    $offAdj = Perl::sprintf("{$this->INDENT}[offsets adjusted by {$sign}0x%.4x after 0x%.4x {$tagInfo->Name}]\n", $tmp, $index);
                }
            }
            if ($unknown > 1) {
                // calculate next valid index for unknown tag
                $ni = Perl::int($index);
                if (!$wasVar) $ni += (((self::$formatSize[$format] ?? 0) ?: 1) * $count) / $increment;
                $saveNextIndex = $nextIndex;
                if (!($nextIndex > $ni)) $nextIndex = $ni;
            }
            // allow large tags to be excluded from extraction
            // (provides a work-around for some tight memory situations)
            if ($tagInfo->LargeTag and !empty($this->EXCL_TAG_LOOKUP[strtolower($tagInfo->Name)])) continue;
            // read value now if necessary
            if (!($val !== null and $tagInfo->SubDirectory === null)) {
                $val = self::ReadValue($dataPt, $entry + $dirStart, $format, $count, $more, $rational);
                if ($val === null) continue;
                $mask = $tagInfo->Mask;
                if ($mask) $val = ((int) Perl::num($val) & $mask) >> $tagInfo->BitShift;
            }
            if ($verbose and !$tagInfo->Hidden) {
                if ($tagInfo->SubDirectory === null or $tagInfo->Format) {
                    $len = $count * ((self::$formatSize[$format] ?? 0) ?: 1);
                    if ($len > $more) $len = $more;
                } else {
                    $len = $more;
                }
                $this->VerboseInfo($index, $tagInfo,
                    Table: $tagTablePtr,
                    Value: $val,
                    DataPt: $dataPt,
                    Size: $len,
                    Start: $entry + $dirStart,
                    Addr: $entry + $dirStart + $base + $dataPos,
                    Format: $format,
                    Count: $count,
                    Extra: $mask ? Perl::sprintf(', mask 0x%.2x', $mask) : null,
                );
            }
            if ($offAdj) $this->VPrint(2, $offAdj);
            // parse nested BinaryData directories
            if ($tagInfo->SubDirectory !== null) {
                $subdir = $tagInfo->SubDirectory;
                $subTablePtr = self::GetTagTable($subdir['TagTable'] ?? null);
                // use specified subdirectory length if given
                if ($tagInfo->Format and isset(self::$formatSize[$format])) {
                    $len = $count * self::$formatSize[$format];
                    if ($len > $more) $len = $more;
                } else {
                    $len = $more;   // directory size is all of remaining data
                    if ($subTablePtr and $subTablePtr['PROCESS_PROC'] and
                        $subTablePtr['PROCESS_PROC'] === 'Image\ExifTool\ExifTool::ProcessBinaryData')
                    {
                        // the rest of the data will be printed in the subdirectory
                        $nextIndex = $size / $increment;
                    }
                }
                $subdirBase = $base;
                if (isset($subdir['Base'])) {
                    //### eval Base ($start,$base)
                    $start = $entry + $dirStart + $dataPos;
                    $b = $subdir['Base'];
                    if ($b instanceof \Closure) {
                        try {
                            $b = $b($start, $base);
                        } catch (\Throwable) {
                            $b = null;
                        }
                    }
                    $subdirBase = Perl::num($b) + $base;
                }
                $start = ($subdir['Start'] ?? 0) ?: 0;
                $notDup = null;
                if ($start instanceof \Closure) {
                    // ignore directories with a zero offset (ie. missing Nikon ShotInfo entries)
                    if (!$val) continue;
                    //### eval Start ($val, $dirStart)
                    try {
                        $start = $start(null, $val, $dirStart, $dataPt);
                    } catch (\Throwable) {
                        $start = null;
                    }
                    $start = Perl::num($start);
                    if ($start < $dirStart or $start > $dataLen) continue;
                    $len = $subdir['DirLen'] ?? null;
                    if (!($len and $len <= $dataLen - $start)) $len = $dataLen - $start;
                } else {
                    $start = Perl::num($start) + $dirStart + $entry;
                    $notDup = 1;
                }
                $subdirInfo = [
                    'DataPt'   => $dataPt,
                    'DataPos'  => $dataPos,
                    'DataLen'  => $dataLen,
                    'DirStart' => $start,
                    'DirLen'   => $len,
                    'Base'     => $subdirBase,
                    'NotDup'   => $notDup,
                ];
                $this->NO_UNKNOWN = null;
                $this->ProcessDirectory($subdirInfo, $subTablePtr, $subdir['ProcessProc'] ?? null);
                if ($unknown < 2) $this->NO_UNKNOWN = 1;
                continue;
            }
            if ($tagInfo->IsOffset and Perl::str($tagInfo->IsOffset) !== '3') {
                $et = $this;
                //### eval IsOffset ($val, $et)
                $isOffset = $tagInfo->IsOffset;
                if ($isOffset instanceof \Closure) {
                    try {
                        $isOffset = $isOffset($val, $et);
                    } catch (\Throwable) {
                        $isOffset = null;
                    }
                }
                if ($isOffset) $val = Perl::num($val) + $base + $this->BASE;
            }
            $valHash[$index] = $val;
            $oldBase = null;
            if ($tagInfo->SetBase) {
                $oldBase = $this->BASE;
                $this->BASE += $base;
            }
            $key = $this->FoundTag($tagInfo, $val);
            if ($oldBase !== null) $this->BASE = $oldBase;
            if ($key) {
                if ($rational !== null) $this->TAG_EXTRA[$key]['Rational'] = $rational;
                if ($binVal !== null) $this->TAG_EXTRA[$key]['BinVal'] = $binVal;
            } else {
                // don't increment nextIndex if we didn't extract a tag
                if ($saveNextIndex !== null) $nextIndex = $saveNextIndex;
            }
        }
        $this->NO_UNKNOWN = null;
        return 1;
    }

    //==============================================================================
    // Routines of Writer.pl (and Shift.pl) which are needed for reading

    //------------------------------------------------------------------------------
    // Uncommon utility routines to for reading binary data values
    // Inputs: 0) data reference, 1) offset into data
    public static function Get64s($dataPt, $pos)
    {
        $pt = self::GetByteOrder() === 'MM' ? 0 : 4;    // get position of high word
        $hi = (int) self::Get32s($dataPt, $pos + $pt);  // preserve sign bit of high word
        $lo = (int) self::Get32u($dataPt, $pos + 4 - $pt);
        return ($hi << 32) | $lo;
    }

    public static function Get64u($dataPt, $pos)
    {
        $pt = self::GetByteOrder() === 'MM' ? 0 : 4;    // get position of high word
        $hi = (int) self::Get32u($dataPt, $pos + $pt);  // (unsigned this time)
        $lo = (int) self::Get32u($dataPt, $pos + 4 - $pt);
        // (above PHP_INT_MAX: exact decimal digits, like Perl's unsigned integers)
        if ($hi >= 0x80000000) return sprintf('%u', ($hi << 32) | $lo);
        return ($hi << 32) | $lo;
    }

    public static function GetFixed64s($dataPt, $pos)
    {
        $val = self::Get64s($dataPt, $pos) / 4294967296;
        // remove insignificant digits
        return Perl::int($val * 1e10 + ($val > 0 ? 0.5 : -0.5)) / 1e10;
    }

    // Decode extended 80-bit float used by Apple SANE and Intel 8087
    // (note: different than the IEEE standard 80-bit float)
    public static function GetExtended($dataPt, $pos)
    {
        $pt = self::GetByteOrder() === 'MM' ? 0 : 2;    // get position of exponent
        $exp = (int) self::Get16u($dataPt, $pos + $pt);
        $sig = Perl::num(self::Get64u($dataPt, $pos + 2 - $pt));  // get significand as int64u
        $sign = $exp & 0x8000 ? -1 : 1;
        $exp = ($exp & 0x7fff) - 16383 - 63; // (-63 to fractionalize significand)
        return $sign * $sig * 2 ** $exp;
    }

    //------------------------------------------------------------------------------
    // Get list of all available tags for specified group
    // Inputs: 0) optional group name (or string of names separated by colons)
    // Returns: tag list (sorted alphabetically)
    // Notes: Can't get tags for specific IFD
    public static function GetAllTags($group = null): array
    {
        $allTags = $groups = [];
        if ($group) $groups = Perl::split('/:/', $group);

        $et = new self();
        self::LoadAllTables();  // first load all our tables
        $tableNames = array_map('strval', array_keys(self::$allTables));

        // loop through all tables and save tag names to %allTags hash
        while ($tableNames) {
            $table = self::GetTagTable(array_pop($tableNames));
            if (!$table) continue;
            // generate flattened tag names for structure fields if this is an XMP table
            if ($table['GROUPS'] and Perl::str($table['GROUPS'][0] ?? null) === 'XMP') {
                XMP::AddFlattenedTags($table);
            }
            foreach (self::TagTableKeys($table) as $tagID) {
                foreach (self::GetTagInfoList($table, $tagID) as $tagInfo) {
                    $tag = $tagInfo->Name;
                    if (!$tag) {
                        self::PerlWarn("no name for tag!\n");
                        continue;
                    }
                    // don't list subdirectories unless they are writable
                    if ($tagInfo->SubDirectory !== null and !$tagInfo->Writable) continue;
                    if ($tagInfo->Hidden) continue;    // ignore hidden tags
                    if ($groups) {
                        $tg = $et->GetGroup($tagInfo);
                        foreach ($groups as $g) {
                            if (!@preg_grep('/^' . str_replace('/', '\/', $g) . '$/i', $tg)) continue 2;
                        }
                    }
                    $allTags[$tag] = 1;
                }
            }
        }
        $tags = array_map('strval', array_keys($allTags));
        sort($tags, SORT_STRING);
        return $tags;
    }

    //------------------------------------------------------------------------------
    // Get list of all writable tags
    // Inputs: 0) optional group name (or names separated by colons)
    // Returns: tag list (sorted alphabetically)
    public static function GetWritableTags($group = null): array
    {
        $writableTags = $groups = [];
        if ($group) $groups = Perl::split('/:/', $group);

        $et = new self();
        self::LoadAllTables();
        $tableNames = array_map('strval', array_keys(self::$allTables));

        while ($tableNames) {
            $tableName = array_pop($tableNames);
            $table = self::GetTagTable($tableName);
            if (!$table) continue;
            // generate flattened tag names for structure fields if this is an XMP table
            if ($table['GROUPS'] and Perl::str($table['GROUPS'][0] ?? null) === 'XMP') {
                XMP::AddFlattenedTags($table);
            }
            // attempt to load Write tables if autoloaded
            // (PHP: the Write modules are not ported)
            foreach (self::TagTableKeys($table) as $tagID) {
                foreach (self::GetTagInfoList($table, $tagID) as $tagInfo) {
                    $tag = $tagInfo->Name;
                    if (!$tag) {
                        self::PerlWarn("no name for tag!\n");
                        continue;
                    }
                    $writable = $tagInfo->Writable;
                    if (!($writable or ($table['WRITABLE'] and
                        $writable === null and $tagInfo->SubDirectory === null))) continue;
                    if ($tagInfo->Hidden) continue;    // ignore hidden tags
                    if ($groups) {
                        $tg = $et->GetGroup($tagInfo);
                        foreach ($groups as $g) {
                            if (!@preg_grep('/^' . str_replace('/', '\/', $g) . '$/i', $tg)) continue 2;
                        }
                    }
                    $writableTags[$tag] = 1;
                }
            }
        }
        $tags = array_map('strval', array_keys($writableTags));
        sort($tags, SORT_STRING);
        return $tags;
    }

    //------------------------------------------------------------------------------
    // Get list of all group names
    // Inputs: 0) [optional] ExifTool ref, 1) Group family number
    // Returns: List of group names (sorted alphabetically)
    public static function GetAllGroups($family = null, $family2 = null): array
    {
        $self = null;
        if ($family instanceof self) {
            $self = $family;
            $family = $family2;
        }
        $family = $family ?: 0;

        if ($family == 3) return ['Doc#', 'Main'];
        if ($family == 4) return ['Copy#'];
        if ($family == 5) return ['[too many possibilities to list]'];
        if ($family == 6) {
            $fn = Vars\Exif::$formatNumber;
            $list = array_map('strval', array_keys($fn));
            usort($list, static fn ($a, $b) => $fn[$a] <=> $fn[$b]);
            return $list;
        }
        if ($family == 8) return ['File#'];

        self::LoadAllTables();  // first load all our tables

        $tableNames = array_map('strval', array_keys(self::$allTables));

        $allGroups = [];
        // add family 1 groups not in tables
        // start with family 1 groups that are missing from the tables
        if ($family == 1) {
            foreach (['Garmin', 'AudioItemList', 'AudioUserData', 'VideoItemList', 'VideoUserData',
                'Track#Keys', 'Track#ItemList', 'Track#UserData', 'KFIX'] as $grp) $allGroups[$grp] = 1;
        }
        // loop through all tag tables and get all group names
        while ($tableNames) {
            $table = self::GetTagTable(array_pop($tableNames));
            if (!$table) continue;
            $grps = $table['GROUPS'];
            if ($grps and ($grp = $grps[$family] ?? null)) $allGroups[$grp] = 1;
            foreach (self::TagTableKeys($table) as $tag) {
                $infoArray = self::GetTagInfoList($table, $tag);
                if ($family == 7) {
                    foreach ($infoArray as $tagInfo) {
                        $id = $tagInfo->TagID;
                        if ($id === null) {
                            $id = '';   // (just to be safe)
                        } elseif (preg_match('/^\d+$/', Perl::str($id))) {
                            if ($self and $self->OPTIONS['HexTagIDs']) $id = Perl::sprintf('0x%x', $id);
                        } else {
                            $id = preg_replace_callback('/([^-_A-Za-z0-9])/', static fn ($m) => Perl::sprintf('%.2x', ord($m[1])), Perl::str($id));
                        }
                        $allGroups['ID-' . $id] = 1;
                    }
                } else {
                    foreach ($infoArray as $tagInfo) {
                        $grps = $tagInfo->Groups;
                        if (!($grps and ($grp = $grps[$family] ?? null))) continue;
                        $allGroups[$grp] = 1;
                    }
                }
            }
        }
        unset($allGroups['*']);     // (not a real group)
        $list = array_map('strval', array_keys($allGroups));
        usort($list, static fn ($a, $b) => strcmp(strtolower($a), strtolower($b)));
        return $list;
    }

    //------------------------------------------------------------------------------
    // Get list of all deletable group names
    // Returns: List of group names (sorted alphabetically)
    public static function GetDeleteGroups(): array
    {
        // (@delGroups and @delGroup2 of Writer.pl)
        $list = [
            'Adobe', 'AFCP', 'APP0', 'APP1', 'APP2', 'APP3', 'APP4', 'APP5', 'APP6', 'APP7', 'APP8', 'APP9',
            'APP10', 'APP11', 'APP12', 'APP13', 'APP14', 'APP15', 'AROT', 'AudioKeys', 'CanonVRD', 'CIFF',
            'Ducky', 'EXIF', 'ExifIFD', 'File', 'FlashPix', 'FotoStation', 'GlobParamIFD', 'GPS',
            'ICC_Profile', 'IFD0', 'IFD1', 'Insta360', 'InteropIFD', 'IPTC', 'ItemList', 'iTunes', 'JFIF',
            'Jpeg2000', 'JUMBF', 'Keys', 'MakerNotes', 'Meta', 'MetaIFD', 'Microsoft', 'MIE', 'MPF',
            'Nextbase', 'NikonApp', 'NikonCapture', 'PDF', 'PDF-update', 'PhotoMechanic', 'Photoshop',
            'PNG', 'PNG-pHYs', 'PrintIM', 'QuickTime', 'RMETA', 'RSRC', 'SEAL', 'SubIFD', 'Trailer',
            'UserData', 'VideoKeys', 'Vivo', 'XML', 'XML-*', 'XMP', 'XMP-*',
            'Audio', 'Author', 'Camera', 'Document', 'ExifTool', 'Image', 'Location', 'Other', 'Preview',
            'Printing', 'Time', 'Video',
        ];
        usort($list, static fn ($a, $b) => strcmp(strtolower($a), strtolower($b)));
        return $list;
    }

    //------------------------------------------------------------------------------
    // Load all tag tables
    public static function LoadAllTables(): void
    {
        if (self::$loadedAllTables) return;

        // load all of our non-referenced tables (first our modules)
        foreach (Vars\ExifTool::$loadAllTables as $table) {
            $tableName = "Image::ExifTool::$table";
            if (!str_contains($table, ':')) $tableName .= '::Main';
            self::GetTagTable($tableName);
        }
        // (then our special tables)
        self::GetTagTable('Image::ExifTool::Extra');
        self::GetTagTable('Image::ExifTool::Composite');
        // recursively load all tables referenced by the current tables
        $tableNames = array_map('strval', array_keys(self::$allTables));
        $pushedTables = [];
        while ($tableNames) {
            $table = self::GetTagTable(array_shift($tableNames));
            if (!$table) continue;
            // (PHP: the write procs which may add tags to the table are not ported)
            // recursively scan through tables in subdirectories
            foreach (self::TagTableKeys($table) as $key) {
                foreach (self::GetTagInfoList($table, $key) as $tagInfo) {
                    $subdir = $tagInfo->SubDirectory;
                    if (!$subdir) continue;
                    $tableName = $subdir['TagTable'] ?? null;
                    if (!$tableName) continue;
                    // next if table already loaded or queued for loading
                    if (isset(self::$allTables[$tableName]) or isset($pushedTables[$tableName])) continue;
                    $tableNames[] = $tableName; // must scan this one too
                    $pushedTables[$tableName] = 1;
                }
            }
        }
        self::$loadedAllTables = 1;
    }

    //------------------------------------------------------------------------------
    // Dereference value and push onto list
    // Inputs: 0) ExifTool ref, 1) value, 2) list ref, 3) flag to push MissingTagValue for undef value
    public function PushValue($val, array &$list, $missing = null): void
    {
        if (is_array($val) and array_is_list($val) and !(isset($val[0]) and is_array($val[0]) and !array_is_list($val[0]))) {
            foreach ($val as $v) $this->PushValue($v, $list, $missing);
        } elseif ($val instanceof ScalarRef) {
            if ($this->OPTIONS['Binary'] or preg_match('/^Binary data/', Perl::str($val->value))) {
                $list[] = $val->value;
            } else {
                $list[] = 'Binary data ' . strlen(Perl::str($val->value)) . ' bytes';
            }
        } elseif (is_array($val)) {
            $list[] = XMP::SerializeStruct($this, $val);
        } elseif ($val === null) {
            $mval = $this->OPTIONS['MissingTagValue'];
            if ($missing and $mval !== null) $list[] = $mval;
        } else {
            $list[] = $val;
        }
    }

    //------------------------------------------------------------------------------
    // Convert tag names to values or variables in a string
    // (eg. '${EXIF:ISO}x $$' --> '100x $' without hash ref, or "$info{'EXIF:ISO'}x $" with)
    // Inputs: 0) ExifTool object ref, 1) string with embedded tag names,
    //         2) reference to list of found tags or undef to use FOUND_TAGS, 3) Options:
    //               undef    - set missing tags to ''
    //              'Error'   - issue minor error on missing tag (and return undef if error sent)
    //              'Warn'    - issue minor warning on missing tag (and return undef if warning sent)
    //              'Silent'  - just return undef on missing tag (no errors/warnings)
    //               Hash ref - defined to interpolate as variables in string instead of values
    //                          --> receives tag/value pairs for interpolation of the variables
    //                          (PHP: an \ArrayObject)
    //         4) document group name if extracting from a specific document
    //         5) hash ref to cache tag keys for subsequent calls in document loop
    //            (PHP: an array passed by reference)
    // Returns: string with embedded tag values (or '$info{TAGNAME}' entries with Hash ref option)
    // Notes:
    // - tag names are not case sensitive and may end with '#' for ValueConv value
    // - uses MissingTagValue option if set
    // - '$GROUP:all' evaluates to 1 if any tag from GROUP exists, or 0 otherwise
    // - advanced feature allows Perl expressions inside braces (eg. '${model;tr/ //d}')
    //   (PHP: Perl code can't be evaluated, see EvalAdvancedFormat())
    // - an error/warning in an advanced expression ("${TAG;EXPR}") generates an error
    //   if option set to 'Error', or a warning otherwise
    public function InsertTagValues($line, $foundTags = null, $opt = null, $docGrp = null, &$cache = null)
    {
        $line = Perl::str($line);
        $rtnStr = '';
        $docNum = $tag = null;

        if ($docGrp) {
            $docNum = preg_match('/(\d+(-\d+)*)$/', $docGrp, $m) ? $m[1] : 0;
            $useCache = $cache !== null;    // (a Perl hash ref is true even if empty)
        } else {
            $useCache = false;  // no cache if no document groups
        }
        if ($foundTags === null) $foundTags = $this->FOUND_TAGS ?: [];
        $isHash = $opt instanceof \ArrayObject;
        $listSep = Perl::str($this->OPTIONS['ListSep']);
        while (preg_match('/^(.*?)\$(\{\s*)?([-\w]*\w|\$|\/)/s', $line, $m)) {
            $line = substr($line, strlen($m[0]));
            [$pre, $bra, $var] = [$m[1], ($m[2] ?? '') !== '', $m[3]];
            $tags = $vals = [];
            $val = $type = $expr = $didExpr = $asList = null;
            // "$$" represents a "$" symbol, and "$/" is a newline
            if ($var === '$' or $var === '/') {
                if ($bra) $line = preg_replace('/^\s*\}/', '', $line, 1);
                if ($var === '/') {
                    $var = "\n";
                } elseif (preg_match('/^self\b/', $line) and !preg_match('/\$$/', $rtnStr)) {
                    $var = '$$';    // ("$$self{var}" in string)
                }
                $rtnStr .= "$pre$var";
                continue;
            }
            // allow multiple group names
            while (preg_match('/^:([-\w]*\w)(.*)/s', $line, $m)) {
                $group = $var;
                [$var, $line] = [$m[1], $m[2]];
                $var = "$group:$var";
            }
            // allow trailing '#' to indicate ValueConv value
            if (str_starts_with($line, '#')) {
                $line = substr($line, 1);
                $type = 'ValueConv';
            }
            // special advanced formatting '@' feature to evaluate list values separately
            if ($bra and preg_match('/^\@(#)?/', $line, $m)) {
                $line = substr($line, strlen($m[0]));
                $asList = 1;
                if (!empty($m[1])) $type = 'ValueConv';
            }
            // remove trailing bracket if there was a leading one
            // and extract Perl expression from inside brackets if it exists
            if ($bra) {
                $line = preg_replace('/^\s*\}/', '', $line, 1, $n);
                if (!$n and preg_match('/^\s*;\s*(.*?)\s*\}/s', $line, $m)) {
                    $line = substr($line, strlen($m[0]));
                    $part = $m[1];
                    $expr = '';
                    for ($level = 0; ; --$level) {
                        // increase nesting level for each opening brace
                        $level += substr_count($part, '{');
                        $expr .= $part;
                        if (!($level and preg_match('/^(.*?)\s*\}/s', $line, $m))) break;  // get next part
                        $line = substr($line, strlen($m[0]));
                        $part = $m[1];
                        $expr .= '}';  // this brace was part of the expression
                    }
                    // use default Windows filename filter if expression is empty
                    if (!strlen($expr)) $expr = 'tr(/\\\\?*:|"<>\\0)()d';
                }
            }
            $tags[] = $var;
            self::ExpandShortcuts($tags);
            if (!$tags) {
                $rtnStr .= $pre;
                continue;
            }
            // save advanced formatting expression to allow access by user-defined ValueConv
            $this->FMT_EXPR = $expr;

            for (;;) {
                // temporarily reset ListJoin option if evaluating list values separately
                $oldListJoin = null;
                if ($asList) $oldListJoin = $this->Options('ListJoin', null);
                $tag = array_shift($tags);
                $lcTag = strtolower($tag);
                if ($useCache and !preg_match('/(^|:)all$/', $lcTag)) {
                    // remove group from tag name (but not lower-case version)
                    $group = null;
                    if (preg_match('/^(.*):/s', $tag, $m)) {
                        $tag = substr($tag, strlen($m[0]));
                        $group = $m[1];
                    }
                    // cache tag keys to speed processing for a large number of sub-documents
                    // (similar to code in BuildCompositeTags(), but this is case-insensitive)
                    if (!isset($cache[$lcTag])) {
                        $cacheTag = [];
                        // find all matching keys, organize into groups, and store in cache
                        $ex = $this->TAG_EXTRA;
                        $matches = array_values(preg_grep('/^' . preg_quote($tag, '/') . '(\s|$)/i', $foundTags));
                        if ($group !== null) $matches = $this->GroupMatchesList($group, $matches);
                        foreach ($matches as $k) {
                            $doc = ($ex[$k]['G3'] ?? null) ?: 0;
                            if (isset($cacheTag[$doc])) {
                                if (!preg_match('/ \((\d+)\)$/', $cacheTag[$doc], $mm)) continue;
                                $cur = $mm[1];
                                // keep the most recently extracted tag
                                if (preg_match('/ \((\d+)\)$/', $k, $mm) and $mm[1] < $cur) continue;
                            }
                            $cacheTag[$doc] = $k;
                        }
                        $cache[$lcTag] = $cacheTag;
                    }
                    $cacheTag = $cache[$lcTag];
                    $doc = preg_match('/\b(main|doc(\d+(-\d+)*)):/', $lcTag, $m) ? (($m[2] ?? '') ?: 0) : $docNum;
                    if (!empty($cacheTag[$doc])) {
                        $tag = $cacheTag[$doc];
                        $val = $this->GetValue($tag, $type);
                    }
                } else {
                    // add document number to tag if specified and it doesn't already exist
                    if ($docGrp and !preg_match('/\b(main|doc\d+):/', $lcTag)) {
                        $tag = $docGrp . ':' . $tag;
                        $lcTag = strtolower($tag);
                    }
                    [$et, $fileTags] = [$this, $foundTags];
                    if (preg_match('/(\bfile\d+):/i', $tag, $m)) {
                        $tag = preg_replace('/(\bfile\d+):/i', '', $tag, 1);
                        $et = $this->ALT_EXIFTOOL[ucfirst(strtolower($m[1]))] ?? null;
                        if ($et) {
                            $fileTags = $et->FoundTags ?: [];
                        } else {
                            $et = $this;
                            $tag = 'no_alt_file';
                        }
                    }
                    if ($lcTag === 'all') {
                        $val = 1;   // always some tag available
                    } elseif (isset($et->OPTIONS['UserParam'][$lcTag])) {
                        $val = $et->OPTIONS['UserParam'][$lcTag];
                    } elseif (preg_match('/(.*):(.+)/s', $tag, $m)) {
                        [$group, $tag] = [$m[1], $m[2]];
                        // join values of all matching tags if "All" group is used
                        // (and remove "All" from group prefix)
                        $group = preg_replace_callback('/(^|:)(all|\*)(:|$)/i',
                            static fn ($g) => $g[1] !== '' ? $g[3] : $g[1], $group, 1, $n);
                        if ($n) {
                            if (strtolower($tag) === 'all') {
                                $matches = $group ? $et->GroupMatchesList($group, $fileTags) : $fileTags;
                            } else {
                                $matches = array_values(preg_grep('/^' . preg_quote($tag, '/') . '(\s|$)/i', $fileTags));
                                if ($group) $matches = $et->GroupMatchesList($group, $matches);
                            }
                            foreach ($matches as $k) $this->PushValue($et->GetValue($k, $type), $vals);
                        } elseif (strtolower($tag) === 'all') {
                            // return "1" if any tag from the specified group exists
                            $val = $et->GroupMatches($group, $fileTags) !== null ? 1 : 0;
                        } else {
                            // find the specified tag
                            $matches = array_values(preg_grep('/^' . preg_quote($tag, '/') . '(\s|$)/i', $fileTags));
                            $matches = $et->GroupMatchesList($group, $matches);
                            foreach ($matches as $tg) {
                                if ($val !== null and preg_match('/ \((\d+)\)$/', $tg, $mm)) {
                                    // take the most recently extracted tag
                                    $tagNum = $mm[1];
                                    if (!preg_match('/ \((\d+)\)$/', $tag, $mm) or $mm[1] > $tagNum) continue;
                                }
                                $val = $et->GetValue($tg, $type);
                                $tag = $tg;
                                if (!str_contains($tag, ' ')) break;    // all done if we got our best match
                            }
                        }
                    } elseif ($tag === 'self') {
                        $val = $et; // ("$self{var}" or "$file1:self{var}" in string)
                    } else {
                        // get the tag value (note: this direct access allows excluded tags
                        // to be accessed if the case is correct and a group name is not used)
                        $val = $et->GetValue($tag, $type);
                        if ($val === null) {
                            // check for tag name with different case
                            $tg = array_values(preg_grep('/^' . preg_quote($tag, '/') . '$/i', $fileTags))[0] ?? null;
                            if ($tg !== null) {
                                $val = $et->GetValue($tg, $type);
                                $tag = $tg;
                            }
                        }
                    }
                }
                if ($asList) $this->Options('ListJoin', $oldListJoin);
                $this->PushValue($val, $vals, $asList);
                $val = null;
                if (!$tags) break;
            }
            if ($vals) {
                if ($val !== null) $this->PushValue($val, $vals);
                $val = implode($listSep, array_map(Perl::str(...), $vals));
            } elseif ($val !== null) {
                $this->PushValue($val, $vals);  // (so the eval has access to @val if required)
            }
            // evaluate advanced formatting expression if given (eg. "${TAG;EXPR}")
            if ($expr !== null and $val !== null) {
                self::$evalWarning = null;
                if ($asList) {
                    foreach ($vals as &$v) {
                        $err = self::EvalAdvancedFormat($expr, $v);
                        if ($err !== null) self::$evalWarning = $err;
                    }
                    unset($v);
                    // join back together if any values are still defined
                    $vals = array_values(array_filter($vals, static fn ($v) => $v !== null));
                    $val = $vals ? implode($listSep, array_map(Perl::str(...), $vals)) : null;
                } else {
                    $err = self::EvalAdvancedFormat($expr, $val);
                    if ($err !== null) self::$evalWarning = $err;
                }
                if (self::$evalWarning) {
                    $g3 = ($docGrp and !preg_match('/\b(main|doc\d+):/i', $var)) ? $docGrp . ':' : '';
                    $str = self::CleanWarning() . " for '" . $g3 . $var . "'";
                    if ($opt) {
                        if ($opt === 'Error') {
                            $this->Error($str);
                        } elseif ($opt !== 'Silent') {
                            $this->Warn($str);
                        }
                    }
                }
                $didExpr = 1;   // set flag indicating an expression was evaluated
            }
            if (!($val !== null or ($isHash and $this->OPTIONS['UndefTags']))) {
                $val = $this->OPTIONS['MissingTagValue'];
                if ($val === null) {
                    $g3 = ($docGrp and !preg_match('/\b(main|doc\d+):/i', $var)) ? $docGrp . ':' : '';
                    $msg = $didExpr ? "Advanced formatting expression returned undef for '" . $g3 . $var . "'" :
                                      "Tag '" . $g3 . $var . "' not defined";
                    if ($isHash) {
                        if ($this->OPTIONS['IgnoreMinorErrors']) $val = '';
                    } elseif ($opt) {
                        if ($opt === 'Silent' or $this->$opt($msg, 2)) return $this->FMT_EXPR = null;
                        $val = '';
                    }
                }
            }
            if ($isHash) {
                if ($type) $var .= '#';
                if ($expr !== null) {
                    // generate unique variable name for this modified tag value
                    $i = 1;
                    while ($opt->offsetExists("$var.expr$i")) ++$i;
                    $var .= '.expr' . $i;
                }
                $rtnStr .= "$pre\$info{'" . $var . "'}";
                $opt[$var] = $val;
            } else {
                $rtnStr .= $pre . Perl::str($val);
            }
        }
        $this->FMT_EXPR = null;
        return $rtnStr . $line;
    }

    //------------------------------------------------------------------------------
    // Evaluate an advanced formatting expression on a value (Perl: eval $expr with $_ = $val)
    // Inputs: 0) expression, 1) value (modified in place)
    // Returns: error message, or undef on success
    // (PHP: the expression is Perl code: only the default Windows filename filter (empty
    //  expression) is supported, any other expression is refused and the value is dropped)
    private static function EvalAdvancedFormat($expr, &$val)
    {
        if ($expr === 'tr(/\\\\?*:|"<>\\0)()d') {
            if ($val !== null) $val = str_replace(['/', '\\', '?', '*', ':', '|', '"', '<', '>', "\0"], '', Perl::str($val));
            return null;
        }
        $val = null;
        return "Advanced formatting expressions are not supported by the PHP port ($expr)\n";
    }

    //------------------------------------------------------------------------------
    // Dump data in hex and ASCII to console
    // Inputs: 0) data reference, 1) length or undef, 2-N) Options:
    // Options: Start => offset to start of data (default=0)
    //          Addr => address to print for data start (default=DataPos+Base+Start)
    //          DataPos => position of data within block (relative to Base)
    //          Base => base offset for pointers from start of file
    //          Width => width of printout (bytes, default=16)
    //          Prefix => prefix to print at start of line (default='')
    //          MaxLen => maximum length to dump
    //          Out => output file reference
    //          Len => data length
    public static function HexDump($dataPt, $len = null, mixed ...$opts): void
    {
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataPt = Perl::str($dataPt);
        $start  = ($opts['Start'] ?? 0) ?: 0;
        $addr   = $opts['Addr'] ?? null;
        $wid    = ($opts['Width'] ?? 0) ?: 16;
        $prefix = ($opts['Prefix'] ?? '') ?: '';
        $out    = ($opts['Out'] ?? null) ?: \STDOUT;
        $maxLen = $opts['MaxLen'] ?? null;
        $datLen = strlen($dataPt) - $start;
        $more = null;
        if (isset($opts['Len'])) $len = $opts['Len'];

        if ($addr === null) $addr = $start + (($opts['DataPos'] ?? 0) ?: 0) + (($opts['Base'] ?? 0) ?: 0);
        if ($len === null) $len = $datLen;
        if ($maxLen and $len > $maxLen) {
            // print one line less to allow for $more line below
            $maxLen = Perl::int(($maxLen - 1) / $wid) * $wid;
            $more = $len - $maxLen;
            $len = $maxLen;
        }
        if ($len > $datLen) {
            fwrite($out, "$prefix    Warning: Attempted dump outside data\n");
            fwrite($out, "$prefix    ($len bytes specified, but only $datLen available)\n");
            $len = $datLen;
        }
        $format = Perl::sprintf('%%-%ds', $wid * 3);
        for ($i = 0; $i < $len; $i += $wid) {
            if ($wid > $len - $i) $wid = $len - $i;
            fwrite($out, Perl::sprintf("$prefix%8.4x: ", $addr + $i));
            $dat = substr($dataPt, (int) ($i + $start), (int) $wid);
            $s = implode(' ', str_split(bin2hex($dat), 2));
            fwrite($out, Perl::sprintf($format, $s));
            $dat = preg_replace('/[\x00-\x1f\x7f-\xff]/', '.', $dat);
            fwrite($out, "[$dat]\n");
        }
        if ($more) fwrite($out, "$prefix    [snip $more bytes]\n");
    }

    //------------------------------------------------------------------------------
    // Print verbose tag information
    // Inputs: 0) ExifTool object reference, 1) tag ID
    //         2) tag info reference (or undef)
    //         3-N) extra parms:
    // Parms: Index => Index of tag in menu (starting at 0)
    //        Value => Tag value
    //        DataPt => reference to value data block
    //        DataPos => location of data block in file
    //        Base => base added to all offsets
    //        Size => length of value data within block
    //        Format => value format string
    //        Count => number of values
    //        Extra => Extra Verbose=2 information to put after tag number
    //        Table => Reference to tag table
    //        Name => Name to use for unknown tag
    //        --> plus any of these HexDump() options: Start, Addr, Width
    public function VerboseInfo($tagID, $tagInfo, mixed ...$parms): void
    {
        $verbose = $this->OPTIONS['Verbose'];
        $out = $this->OPTIONS['TextOut'];
        $tag = $line = $hexID = null;
        $table = $parms['Table'] ?? null;

        // generate hex number if tagID is numerical
        if ($tagID !== null) {
            if (preg_match('/^\d+$/', Perl::str($tagID))) {
                if ($table and $table['VARS'] and
                    $table['ID_FMT'] and Perl::str($table['VARS']['ID_FMT'] ?? null) === 'dec')
                {
                    $hexID = $tagID;
                } else {
                    $hexID = Perl::sprintf('0x%.4x', $tagID);
                }
            }
        } else {
            $tagID = 'Unknown';
        }
        // get tag name
        if ($tagInfo and $tagInfo->Name) {
            $tag = $tagInfo->Name;
        } elseif ($parms['Name'] ?? null) { // (used for PNG Plus FPX tags)
            $tag = $parms['Name'];
            $hexID = null;
        } else {
            $prefix = null;
            if ($table) $prefix = $table['TAG_PREFIX'];
            if ($prefix or $hexID !== null) {
                if (!$prefix) $prefix = 'Unknown';
                $tag = $prefix . '_' . ($hexID ?? $tagID);
            } else {
                $tag = $tagID;
            }
        }
        // (PHP: DataPt is the data itself, so test definedness where Perl tests the reference)
        $dataPt = $parms['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $size = $parms['Size'] ?? null;
        if (!($size !== null or $dataPt === null)) $size = strlen(Perl::str($dataPt));
        $indent = $this->INDENT;

        // Level 1: print tag/value information
        $line = $indent;
        $index = $parms['Index'] ?? null;
        if ($index !== null) {
            $line .= $index . ') ';
            if (strlen(Perl::str($index)) < 2) $line .= ' ';
            $indent .= '    '; // indent everything else to align with tag name
        }
        $line .= $tag;
        if ($tagInfo and $tagInfo->SubDirectory !== null) {   // (an empty hash is true in Perl)
            $line .= ' (SubDirectory) -->';
        } else {
            $maxLen = 90 - strlen($line);
            $val = $parms['Value'] ?? null;
            if ($val !== null) {
                if (is_array($val) and array_is_list($val)) $val = '[' . implode(',', array_map(Perl::str(...), $val)) . ']';
                $line .= ' = ' . $this->Printable($val, $maxLen);
            } elseif ($dataPt !== null) {
                $start = ($parms['Start'] ?? 0) ?: 0;
                $line .= ' = ' . $this->Printable(substr(Perl::str($dataPt), (int) $start, (int) $size), $maxLen);
            }
        }
        fwrite($out, "$line\n");

        // Level 2: print detailed information about the tag
        if ($verbose > 1 and (($parms['Extra'] ?? null) or ($parms['Format'] ?? null) or
            $dataPt !== null or $size !== null or str_contains(Perl::str($tagID), '/')))
        {
            $line = $indent . '- Tag ';
            if ($hexID !== null) {
                $line .= $hexID;
            } else {
                $tagID = preg_replace_callback('/[\0-\x1f\x7f-\xff]/', static fn ($m) => Perl::sprintf('\\x%.2x', ord($m[0])), Perl::str($tagID));
                $line .= "'{$tagID}'";
            }
            if (isset($parms['Extra'])) $line .= $parms['Extra'];
            $format = $parms['Format'] ?? null;
            if ($format or $size !== null) {
                $line .= ' (';
                if ($size !== null) {
                    $line .= Perl::str($size) . ' bytes';
                    if ($format) $line .= ', ';
                }
                if ($format) {
                    $line .= $format;
                    if ($parms['Count'] ?? null) $line .= '[' . Perl::str($parms['Count']) . ']';
                }
                $line .= ')';
            }
            if ($verbose > 2 and $dataPt !== null) $line .= ':';
            fwrite($out, "$line\n");
        }

        // Level 3: do hex dump of value
        if ($verbose > 2 and $dataPt !== null and (!$tagInfo or !$tagInfo->ReadFromRAF)) {
            $parms['Out'] = $out;
            $parms['Prefix'] = $indent;
            // limit dump length if Verbose < 5
            if ($verbose < 5) $parms['MaxLen'] = $verbose == 3 ? 96 : 2048;
            unset($parms['DataPt']);
            self::HexDump($dataPt, $size, ...$parms);
        }
    }

    //------------------------------------------------------------------------------
    // Dump trailer information
    // Inputs: 0) ExifTool object ref, 1) dirInfo hash (RAF, DirName, DataPos, DirLen)
    // Notes: Restores current file position before returning
    public function DumpTrailer($dirInfo): void
    {
        $raf = $dirInfo['RAF'];
        $curPos = $raf->Tell();
        $trailer = ($dirInfo['DirName'] ?? null) ?: 'Unknown';
        $pos = $dirInfo['DataPos'] ?? null;
        $verbose = $this->OPTIONS['Verbose'];
        $htmlDump = $this->HTML_DUMP;
        $buff = $buf2 = null;
        $size = $dirInfo['DirLen'] ?? null;
        if ($pos === null) $pos = $curPos;

        // get full trailer size if not specified
        for (;;) {
            if (!$size) {
                if (!$raf->Seek(0, 2)) break;
                $size = $raf->Tell() - $pos;
                if (!$size) break;
            }
            if (!$raf->Seek($pos, 0)) break;
            if ($htmlDump) {
                $num = $raf->Read($buff, $size);
                if (!$num) return;
                $desc = "$trailer trailer";
                if ($trailer === 'Unknown') $desc = "[$desc]";
                $this->HDump($pos, $num, $desc, null, 0x08);
                break;
            }
            $out = $this->OPTIONS['TextOut'];
            fwrite($out, Perl::sprintf("$trailer trailer (%d bytes at offset 0x%.4x):\n", $size, $pos));
            if (!($verbose > 2)) break;
            $num = $size;   // number of bytes to read
            // limit size if not very verbose
            if ($verbose < 5) {
                $limit = $verbose < 4 ? 96 : 512;
                if ($num > $limit) $num = $limit;
            }
            if ($raf->Read($buff, $num) != $num) return;
            // read the end of the trailer too if not done already
            if ($size > 2 * $num) {
                $raf->Seek($pos + $size - $num, 0);
                $raf->Read($buf2, $num);
            } elseif ($size > $num) {
                $raf->Seek($pos + $num, 0);
                $raf->Read($buf2, $size - $num);
                $buff .= $buf2;
                $buf2 = null;
            }
            self::HexDump($buff, null, Addr: $pos, Out: $out);
            if ($buf2 !== null) {
                fwrite($out, '    [snip ' . Perl::str($size - $num * 2) . " bytes]\n");
                self::HexDump($buf2, null, Addr: $pos + $size - $num, Out: $out);
            }
            break;
        }
        $raf->Seek($curPos, 0);
    }

    //------------------------------------------------------------------------------
    // Dump unknown trailer information
    // Inputs: 0) ExifTool ref, 1) dirInfo ref (with RAF, DataPos and DirLen defined)
    // Notes: changes dirInfo elements
    public function DumpUnknownTrailer(array &$dirInfo): void
    {
        $pos = $dirInfo['DataPos'];
        $endPos = $pos + $dirInfo['DirLen'];
        // account for preview/MPF image trailer
        $value = $this->VALUE;
        $prePos = ($value['PreviewImageStart'] ?? null) ?: $this->PreviewImageStart;
        $preLen = ($value['PreviewImageLength'] ?? null) ?: $this->PreviewImageLength;
        $hidPos = $value['HiddenDataOffset'] ?? null;
        $hidLen = $value['HiddenDataLength'] ?? null;
        $tag = 'PreviewImage';
        $mpImageNum = 0;
        $image = [];
        $lastOne = null;
        // add HiddenData to list of known trailer blocks
        if ($hidPos and $hidLen) {
            // call ReadHiddenData to validate hidden data and fix offset if necessary
            $datPt = Sony::ReadHiddenData($this, $hidPos, $hidLen);
            if ($datPt) $image[$hidPos] = ['HiddenData', $hidLen];
        }
        for (;;) {
            // add to Preview block list if valid and in the trailer
            if ($prePos and $preLen and $prePos + $preLen > $pos) $image[$prePos] = [$tag, $preLen];
            if ($lastOne) break;    // checked all images
            // look for MPF images (in the proper order)
            ++$mpImageNum;
            $prePos = $value["MPImageStart ($mpImageNum)"] ?? null;
            if ($prePos !== null) {
                $preLen = $value["MPImageLength ($mpImageNum)"] ?? null;
            } else {
                $prePos = $value['MPImageStart'] ?? null;
                $preLen = $value['MPImageLength'] ?? null;
                $lastOne = 1;
            }
            $tag = "MPImage$mpImageNum";
        }
        // dump trailer sections in order
        $image[$endPos] = ['', 0];  // add terminator "image"
        $keys = array_keys($image);
        sort($keys, SORT_NUMERIC);
        foreach ($keys as $prePos) {
            if ($pos < $prePos) {
                // dump unknown trailer data
                $dirInfo['DirName'] = 'Unknown';
                $dirInfo['DataPos'] = $pos;
                $dirInfo['DirLen'] = $prePos - $pos;
                $this->DumpTrailer($dirInfo);
            }
            [$tag, $preLen] = $image[$prePos];
            if (!$preLen) break;
            // dump image if verbose (it is htmlDump'd by ExtractImage)
            if ($this->OPTIONS['Verbose']) {
                $dirInfo['DirName'] = $tag;
                $dirInfo['DataPos'] = $prePos;
                $dirInfo['DirLen']  = $preLen;
                $this->DumpTrailer($dirInfo);
            }
            $pos = $prePos + $preLen;
        }
    }

    //------------------------------------------------------------------------------
    // Print verbose value while writing
    // Inputs: 0) ExifTool object ref, 1) heading "eg. '+ IPTC:Keywords',
    //         2) value, 3) [optional] extra text after value
    public function VerboseValue($str, $val, $xtra = null): void
    {
        if (!($this->OPTIONS['Verbose'] > 1)) return;
        $out = $this->OPTIONS['TextOut'];
        if (!$xtra) $xtra = '';
        $maxLen = 81 - strlen($str) - strlen($xtra);
        $val = $this->Printable($val, $maxLen);
        fwrite($out, "    $str = '$val'$xtra\n");
    }

    //------------------------------------------------------------------------------
    // Pack Unicode numbers into UTF8 string
    // Inputs: 0-N) list of Unicode numbers
    // Returns: Packed UTF-8 string
    public static function PackUTF8(...$chars)
    {
        $out = [];
        while ($chars) {
            $ch = (int) array_pop($chars);
            if ($ch < 0x80) {
                array_unshift($out, $ch);
                continue;
            }
            array_unshift($out, 0x80 | ($ch & 0x3f));
            $ch >>= 6;
            if ($ch < 0x20) {
                array_unshift($out, 0xc0 | $ch);
                continue;
            }
            array_unshift($out, 0x80 | ($ch & 0x3f));
            $ch >>= 6;
            if ($ch < 0x10) {
                array_unshift($out, 0xe0 | $ch);
                continue;
            }
            array_unshift($out, 0x80 | ($ch & 0x3f));
            $ch >>= 6;
            array_unshift($out, 0xf0 | ($ch & 0x07));
        }
        return implode('', array_map('chr', $out));
    }

    //------------------------------------------------------------------------------
    // Unpack numbers from UTF8 string
    // Inputs: 0) UTF-8 string
    // Returns: List of Unicode numbers (sets $evalWarning on error)
    public static function UnpackUTF8($str): array
    {
        $str = Perl::str($str);
        $out = [];
        $pos = 0;
        $len = strlen($str);
        for (;;) {
            $ch = null;
            if (preg_match('/[\x80-\xff]/', $str, $m, PREG_OFFSET_CAPTURE, $pos)) {
                $ch = ord($m[0][0]);
                $newPos = $m[0][1];
            } else {
                $newPos = $len;
            }
            // unpack 7-bit characters
            for ($i = $pos; $i < $newPos; ++$i) $out[] = ord($str[$i]);
            if ($ch === null) break;
            $pos = $newPos + 1;
            // minimum lead byte for 2-byte sequence is 0xc2 (overlong sequences
            // not allowed), 0xf8-0xfd are restricted by RFC 3629 (no 5 or 6 byte
            // sequences), and 0xfe and 0xff are not valid in UTF-8 strings
            if ($ch < 0xc2 or $ch >= 0xf8) {
                $out[] = ord('?');  // invalid UTF-8
                self::$evalWarning = 'Bad UTF-8';
                continue;
            }
            // decode 2, 3 and 4-byte sequences
            $n = 1;
            if ($ch < 0xe0) {
                $val = $ch & 0x1f;  // 2-byte sequence
            } elseif ($ch < 0xf0) {
                $val = $ch & 0x0f;  // 3-byte sequence
                ++$n;
            } else {
                $val = $ch & 0x07;  // 4-byte sequence
                $n += 2;
            }
            if (!preg_match('/\G([\x80-\xbf]{' . $n . '})/', $str, $mm, 0, $pos)) {
                $out[] = ord('?');  // invalid UTF-8
                self::$evalWarning = 'Bad UTF-8';
                continue;
            }
            foreach (str_split($mm[1]) as $byte) {
                $val = ($val << 6) | (ord($byte) & 0x3f);
            }
            $out[] = $val;  // save Unicode character value
            $pos += $n;     // position at end of UTF-8 character
        }
        return $out;
    }

    //------------------------------------------------------------------------------
    // Return current time in EXIF format
    // Inputs: 0) [optional] ExifTool ref, 1) flag to include timezone (0 to disable,
    //            undef or 1 to include)
    // Returns: time string
    // - a consistent value is returned for each processed file
    public function TimeNow($tzFlag = null)
    {
        if ($this->Now) {
            $timeNow = $this->Now[0];
        } else {
            $time = time();
            $tm = self::PerlLocaltime($time);
            $tz = self::TimeZoneString($tm, $time);
            $timeNow = Perl::sprintf('%4d:%.2d:%.2d %.2d:%.2d:%.2d',
                        $tm[5] + 1900, $tm[4] + 1, $tm[3],
                        $tm[2], $tm[1], $tm[0]);
            $this->Now = [$timeNow, $tz];
        }
        if ($tzFlag or $tzFlag === null) $timeNow .= $this->Now[1];
        return $timeNow;
    }

    //------------------------------------------------------------------------------
    // Generate a new, random GUID
    // Inputs: <none>
    // Returns: GUID string
    private static $guidCount = null;

    public static function NewGUID()
    {
        $tm = self::PerlLocaltime(time());
        if (!(self::$guidCount !== null and ++self::$guidCount < 0x100)) self::$guidCount = 0;
        return Perl::sprintf('%.4d%.2d%.2d%.2d%.2d%.2d%.2X%.4X%.4X%.4X%.4X',
                       $tm[5] + 1900, $tm[4] + 1, $tm[3], $tm[2], $tm[1], $tm[0], self::$guidCount,
                       getmypid() & 0xffff, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    //------------------------------------------------------------------------------
    // Generate a version 4 ("random"), variant 1 UUID (RFC 9562, Section 5.4) (github #421)
    // Inputs: <none>
    // Returns: UUID string
    public static function NewUUID()
    {
        $rnd = array_values(unpack('C*', random_bytes(16)));
        $rnd[6] = ($rnd[6] & 0x0f) | 0x40;
        $rnd[8] = ($rnd[8] & 0x3f) | 0x80;
        return strtoupper(bin2hex(implode('', array_map('chr', $rnd))));
    }

    //------------------------------------------------------------------------------
    // Make TIFF header for raw data
    // Inputs: 0) width, 1) height, 2) num colour components, 3) bits, 4) resolution
    //         5) color-map data for palette-color image (8 or 16 bit)
    // Returns: TIFF header
    // Notes: Multi-byte data must be little-endian
    public static function MakeTiffHeader($w, $h, $cols, $bits, $res = null, $cmap = null)
    {
        if (!$res) $res = 72;
        $saveOrder = self::GetByteOrder();
        self::SetByteOrder('II');
        if (!$cmap) {
            $cmap = '';
        } elseif (strlen($cmap) == 3 * 2 ** $bits) {
            // convert to short
            $cmap = Perl::pack('v*', ...array_map(static fn ($c) => $c | ($c << 8), array_values(unpack('C*', $cmap))));
        } elseif (strlen($cmap) != 6 * 2 ** $bits) {
            $cmap = '';
        }
        $cmo = $cmap !== '' ? 12 : 0;   // offset due to ColorMap IFD entry
        $hdr =
        "\x49\x49\x2a\0\x08\0\0\0\x0e\0" .                  // 0x00 14 menu entries:
        "\xfe\x00\x04\0\x01\0\0\0\x00\0\0\0" .              // 0x0a SubfileType = 0
        "\x00\x01\x04\0\x01\0\0\0" . self::Set32u($w) .     // 0x16 ImageWidth
        "\x01\x01\x04\0\x01\0\0\0" . self::Set32u($h) .     // 0x22 ImageHeight
        "\x02\x01\x03\0" . self::Set32u($cols) .            // 0x2e BitsPerSample
         self::Set32u($cols == 1 ? $bits : 0xb6 + $cmo) .
        "\x03\x01\x03\0\x01\0\0\0\x01\0\0\0" .              // 0x3a Compression = 1
        "\x06\x01\x03\0\x01\0\0\0" .                        // 0x46 PhotometricInterpretation
         self::Set32u($cmap !== '' ? 3 : ($cols == 1 ? 1 : 2)) .
        "\x11\x01\x04\0\x01\0\0\0" .                        // 0x52 StripOffsets
         self::Set32u(0xcc + $cmo + strlen($cmap)) .
        "\x15\x01\x03\0\x01\0\0\0" . self::Set32u($cols) .  // 0x5e SamplesPerPixel
        "\x16\x01\x04\0\x01\0\0\0" . self::Set32u($h) .     // 0x6a RowsPerStrip
        "\x17\x01\x04\0\x01\0\0\0" .                        // 0x76 StripByteCounts
         self::Set32u($w * $h * $cols * Perl::int(($bits + 7) / 8)) .
        "\x1a\x01\x05\0\x01\0\0\0" . self::Set32u(0xbc + $cmo) .  // 0x82 XResolution
        "\x1b\x01\x05\0\x01\0\0\0" . self::Set32u(0xc4 + $cmo) .  // 0x8e YResolution
        "\x1c\x01\x03\0\x01\0\0\0\x01\0\0\0" .              // 0x9a PlanarConfiguration = 1
        "\x28\x01\x03\0\x01\0\0\0\x02\0\0\0" .              // 0xa6 ResolutionUnit = 2
        ($cmap !== '' ?                                     // 0xb2 ColorMap [optional]
        "\x40\x01\x03\0" . self::Set32u(3 * 2 ** $bits) . "\xd8\0\0\0" : '') .
        "\0\0\0\0" .                                        // 0xb2+$cmo (no IFD1)
        str_repeat(self::Set16u($bits), 3) .                // 0xb6+$cmo BitsPerSample value
        self::Set32u($res) . "\x01\0\0\0" .                 // 0xbc+$cmo XResolution = 72
        self::Set32u($res) . "\x01\0\0\0" .                 // 0xc4+$cmo YResolution = 72
        $cmap;                                              // 0xcc or 0xd8 (cmap and data go here)
        self::SetByteOrder($saveOrder);
        return $hdr;
    }

    //------------------------------------------------------------------------------
    // Add data to the image data hash
    // Inputs: 0) ExifTool ref, 1) RAF ref, 2) data size (or undef to read to EOF),
    //         3) data type for verbose message, 4) flag to suppress verbose message
    // Returns: number of bytes read and hashed
    public function ImageDataHash(RandomAccess $raf, $size, $type = null, $noMsg = null)
    {
        $hash = $this->ImageDataHash;
        if (!$hash) return null;
        $bytesRead = 0;
        $n = 65536;
        $buff = null;
        for (;;) {
            if ($size !== null) {
                if (!$size) break;
                $n = $size > 65536 ? 65536 : $size;
                $size -= $n;
            }
            if (!$raf->Read($buff, $n)) {
                if ($type and $size !== null) $this->Warn("Error reading $type data");
                break;
            }
            hash_update($hash, $buff);
            $bytesRead += strlen($buff);
        }
        if ($this->OPTIONS['Verbose'] and $bytesRead and $type and !$noMsg) {
            $this->VPrint(0, "{$this->INDENT}(ImageDataHash: $bytesRead bytes of $type data)\n");
        }
        return $bytesRead;
    }

    //------------------------------------------------------------------------------
    // Assemble a continuing fraction into a rational value
    // Inputs: 0) numerator, 1) denominator
    //         2-N) list of fraction denominators, deepest first
    // Returns: numerator, denominator (in list context)
    public static function AssembleRational($num, $denom, ...$fracs): array
    {
        foreach ($fracs as $frac) {
            [$num, $denom] = [$frac * $num + $denom, $num];
        }
        return [$num, $denom];
    }

    //------------------------------------------------------------------------------
    // Convert a floating point number (or 'inf' or 'undef' or a fraction) into a rational
    // Inputs: 0) floating point number, 1) optional maximum value (defaults to 0x7fffffff)
    // Returns: numerator, denominator (in list context)
    // Notes:
    // - the returned rational will be accurate to at least 8 significant figures if possible
    // - eg. an input of 3.14159265358979 returns a rational of 104348/33215,
    //   which equals    3.14159265392142 and is accurate to 10 significant figures
    // - the returned rational will be reduced to the lowest common denominator except when
    //   the input is a fraction in which case the input is returned unchanged
    // - these routines were a bit tricky, but fun to write!
    public static function Rationalize($val, $maxInt = null): array
    {
        $str = Perl::str($val);
        if ($str === 'inf') return [1, 0];
        if ($str === 'undef') return [0, 0];
        if (preg_match('{^([-+]?\d+)/(\d+)$}', $str, $m)) return [$m[1], $m[2]]; // accept fractional values
        // Note: Just testing "if $val" doesn't work because '0.0' is true!  (ugghh!)
        $val = Perl::num($val);
        if ($val == 0) return [0, 1];
        $sign = 1;
        if ($val < 0) {
            $val = -$val;
            $sign = -1;
        }
        $num = $denom = null;
        $fracs = [];
        $frac = $val;
        if (!$maxInt) $maxInt = 0x7fffffff;
        for (;;) {
            [$n, $d] = self::AssembleRational(Perl::int($frac + 0.5), 1, ...$fracs);
            if ($n > $maxInt or $d > $maxInt) {
                if ($num !== null) break;
                if ($val < 1) return [$sign, $maxInt];
                return [$sign * $maxInt, 1];
            }
            [$num, $denom] = [$n, $d];      // save last good values
            $err = ($n / $d - $val) / $val; // get error of this rational
            if (abs($err) < 1e-8) break;    // all done if error is small
            $int = Perl::int($frac);
            array_unshift($fracs, $int);
            $frac -= $int;
            if (!$frac) break;
            $frac = 1 / $frac;
        }
        return [$num * $sign, $denom];
    }

    //------------------------------------------------------------------------------
    // Utility routines to for writing binary data values
    // Inputs: 0) value, 1) data ref, 2) offset
    // Notes: prototype is (@) so values can be passed from list if desired
    public static function Set16s($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::num($value);
        if ($val < 0) $val += 0x10000;
        return func_num_args() > 1 ? self::Set16u($val, $dataPt, $pos) : self::Set16u($val);
    }

    public static function Set32s($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::num($value);
        if ($val < 0) {
            $val += 0xffffffff;
            ++$val;
        }
        return func_num_args() > 1 ? self::Set32u($val, $dataPt, $pos) : self::Set32u($val);
    }

    public static function Set64u($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::num($value);
        $hi = Perl::int($val / 4294967296);
        $lo = self::Set32u($val - $hi * 4294967296); // NOTE: subject to round-off errors!
        $hi = self::Set32u($hi);
        $val = self::GetByteOrder() === 'MM' ? $hi . $lo : $lo . $hi;
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    public static function Set64s($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::num($value);
        if ($val < 0) $val += 4294967296 * 4294967296; // (temporary hack won't really work due to round-off errors)
        return func_num_args() > 1 ? self::Set64u($val, $dataPt, $pos) : self::Set64u($val);
    }

    public static function SetRational64u($value, &$dataPt = null, $pos = null)
    {
        [$numer, $denom] = self::Rationalize($value, 0xffffffff);
        $val = self::Set32u($numer) . self::Set32u($denom);
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    public static function SetRational64s($value, &$dataPt = null, $pos = null)
    {
        [$numer, $denom] = self::Rationalize($value);
        $val = self::Set32s($numer) . self::Set32u($denom);
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    public static function SetRational32u($value, &$dataPt = null, $pos = null)
    {
        [$numer, $denom] = self::Rationalize($value, 0xffff);
        $val = self::Set16u($numer) . self::Set16u($denom);
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    public static function SetRational32s($value, &$dataPt = null, $pos = null)
    {
        [$numer, $denom] = self::Rationalize($value, 0x7fff);
        $val = self::Set16s($numer) . self::Set16u($denom);
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    public static function SetFixed16u($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::int(Perl::num($value) * 0x100 + 0.5);
        return func_num_args() > 1 ? self::Set16u($val, $dataPt, $pos) : self::Set16u($val);
    }

    public static function SetFixed16s($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::num($value);
        $val = Perl::int($val * 0x100 + ($val < 0 ? -0.5 : 0.5));
        return func_num_args() > 1 ? self::Set16s($val, $dataPt, $pos) : self::Set16s($val);
    }

    public static function SetFixed32u($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::int(Perl::num($value) * 0x10000 + 0.5);
        return func_num_args() > 1 ? self::Set32u($val, $dataPt, $pos) : self::Set32u($val);
    }

    public static function SetFixed32s($value, &$dataPt = null, $pos = null)
    {
        $val = Perl::num($value);
        $val = Perl::int($val * 0x10000 + ($val < 0 ? -0.5 : 0.5));
        return func_num_args() > 1 ? self::Set32s($val, $dataPt, $pos) : self::Set32s($val);
    }

    public static function SetFloat($value, &$dataPt = null, $pos = null)
    {
        // (PHP: pack in the current byte order instead of native order + SwapBytes)
        $val = pack(self::GetByteOrder() === 'MM' ? 'G' : 'g', (float) Perl::num($value));
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    public static function SetDouble($value, &$dataPt = null, $pos = null)
    {
        // swap 32-bit words (ARM quirk) and bytes if necessary
        $val = pack(self::GetByteOrder() === 'MM' ? 'E' : 'e', (float) Perl::num($value));
        if (func_num_args() > 1 and $dataPt !== null) self::PutData($dataPt, $pos, $val);
        return $val;
    }

    // (Perl: substr($$dataPt, $pos, length($val)) = $val)
    private static function PutData(&$dataPt, $pos, string $val): void
    {
        $dataPt = substr_replace((string) $dataPt, $val, (int) $pos, strlen($val));
    }

    //------------------------------------------------------------------------------
    // hash lookups for writing binary data values
    private const array WRITE_VALUE_PROC = [
        'int8s' => 'Set8s',
        'int8u' => 'Set8u',
        'int16s' => 'Set16s',
        'int16u' => 'Set16u',
        'int16uRev' => 'Set16uRev',
        'int32s' => 'Set32s',
        'int32u' => 'Set32u',
        'int64s' => 'Set64s',
        'int64u' => 'Set64u',
        'rational32s' => 'SetRational32s',
        'rational32u' => 'SetRational32u',
        'rational64s' => 'SetRational64s',
        'rational64u' => 'SetRational64u',
        'fixed16u' => 'SetFixed16u',
        'fixed16s' => 'SetFixed16s',
        'fixed32u' => 'SetFixed32u',
        'fixed32s' => 'SetFixed32s',
        'float' => 'SetFloat',
        'double' => 'SetDouble',
        'ifd' => 'Set32u',
    ];

    //------------------------------------------------------------------------------
    // write binary data value (with current byte ordering)
    // Inputs: 0) value, 1) format string
    //         2) number of values:
    //               undef = 1 for numerical types, or data length for string/undef types
    //                  -1 = number of space-delimited values in the input string
    //         3) optional data reference, 4) value offset (may be negative for bytes from end)
    // Returns: packed value (and sets value in data) or undef on error
    // Notes: May modify input value to round for integer formats
    //        (PHP: the input value is not modified)
    public static function WriteValue($val, $format, $count = null, &$dataPt = null, $offset = null)
    {
        $proc = self::WRITE_VALUE_PROC[$format] ?? null;
        $hasData = (func_num_args() > 3 and $dataPt !== null);

        if ($proc) {
            $vals = Perl::split(' ', $val);
            if ($count) {
                if ($count < 0) $count = count($vals);
            } else {
                $count = 1;   // assume 1 if count not specified
            }
            $packed = '';
            while ($count--) {
                $val = array_shift($vals);
                if ($val === null) return null;
                // validate numerical formats
                if (str_starts_with($format, 'int')) {
                    if (!(self::IsInt($val) or self::IsHex($val))) {
                        if (!self::IsFloat($val)) return null;
                        // round to nearest integer
                        $val = Perl::num($val);
                        $val = Perl::int($val + ($val < 0 ? -0.5 : 0.5));
                    }
                } elseif (!self::IsFloat($val)) {
                    if (!(str_starts_with($format, 'rational') and ($val === 'inf' or
                        $val === 'undef' or self::IsRational($val)))) return null;
                }
                $packed .= self::$proc($val);
            }
        } elseif ($format === 'string' or $format === 'undef') {
            $val = Perl::str($val);
            if ($format === 'string') $val .= "\0";   // null-terminate strings
            if ($count and $count > 0) {
                $diff = $count - strlen($val);
                if ($diff) {
                    // adjust length of string to match specified count
                    if ($diff < 0) {
                        if ($format === 'string') {
                            if (!$count) return null;
                            $val = substr($val, 0, $count - 1) . "\0";
                        } else {
                            $val = substr($val, 0, (int) $count);
                        }
                    } else {
                        $val .= str_repeat("\0", (int) $diff);
                    }
                }
            } else {
                $count = strlen($val);
            }
            if ($hasData) $dataPt = substr_replace((string) $dataPt, $val, (int) $offset, (int) $count);
            return $val;
        } else {
            self::PerlWarn("Sorry, Can't write $format values on this platform\n");
            return null;
        }
        if ($hasData) self::PutData($dataPt, $offset, $packed);
        return $packed;
    }

    //------------------------------------------------------------------------------
    // return the number of days in a month (from Shift.pl)
    // Inputs: 0) month number (Jan=1, may be outside range), 1) year
    // Returns: number of days in month
    public static function DaysInMonth($mon, $year)
    {
        $days = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        // adjust to the range [0,11]
        while ($mon < 1)  { $mon += 12; --$year; }
        while ($mon > 12) { $mon -= 12; ++$year; }
        // return standard number of days unless february on a leap year
        if (!($mon == 2 and !Perl::mod($year, 4))) return $days[$mon - 1];
        // leap years don't occur on even centuries except every 400 years
        if (Perl::mod($year, 100) or !Perl::mod($year, 400)) return 29;
        return 28;
    }

    //==============================================================================
    // PHP helpers (Perl built-ins and modules used by ExifTool.pm)

    /** depth of nested Perl eval emulations (see EvalBegin) */
    private static int $evalDepth = 0;
    /** @var \WeakMap<\Closure, bool>|null (keyed by the closure: an object id can be reused once it is freed) */
    private static ?\WeakMap $isCode = null;
    /** @var \WeakMap<\Closure, bool>|null */
    private static ?\WeakMap $usesData = null;
    /** @var array<string, list<string>> */
    private static array $sourceLines = [];
    private static ?array $tableIndex = null;
    /** @var array<string, bool> modules whose tables/<Module>.php was loaded */
    private static array $loadedModules = [];
    /** @var array<string, array> generated tables not converted to TagTable yet */
    private static array $rawTables = [];
    /** @var array<string, TagTable> the Perl package hashes (%Image::ExifTool::Canon::Main...) */
    private static array $pkgTables = [];
    private static ?\DateTimeZone $localTZ = null;
    private static ?\Closure $binaryConv = null;

    /**
     * Start of the emulation of a Perl eval with $SIG{__WARN__} = \&SetWarning: PHP notices and
     * warnings are swallowed (set EXIFTOOL_DEBUG to see them), trigger_error() with
     * E_USER_WARNING/E_USER_NOTICE sets $evalWarning like a Perl warn().
     */
    private static function EvalBegin(): void
    {
        ++self::$evalDepth;
        set_error_handler(static function (int $no, string $str): bool {
            if ($no & (E_USER_WARNING | E_USER_NOTICE)) {
                self::$evalWarning = $str;
            } elseif (getenv('EXIFTOOL_DEBUG')) {
                file_put_contents('php://stderr', "PHP: $str\n");
            }
            return true;
        });
    }

    private static function EvalEnd(): void
    {
        restore_error_handler();
        --self::$evalDepth;
    }

    /** the message Perl would give in $@ for an exception thrown by eval'd code */
    private static function EvalError(\Throwable $e): string
    {
        if ($e instanceof NotPorted) return 'not ported (' . $e->getMessage() . ')';
        if ($e instanceof \DivisionByZeroError) {
            return str_starts_with($e->getMessage(), 'Modulo') ? 'Illegal modulus zero' : 'Illegal division by zero';
        }
        return $e->getMessage();
    }

    /**
     * Perl warn(): sets $evalWarning inside an eval site (like $SIG{__WARN__} = \&SetWarning),
     * else prints on stderr.
     */
    public static function PerlWarn($msg): void
    {
        $msg = Perl::str($msg);
        if (self::$evalDepth) {
            self::$evalWarning = $msg;
            return;
        }
        file_put_contents('php://stderr', str_ends_with($msg, "\n") ? $msg : "$msg\n");
    }

    /** Perl: ref $conv eq 'CODE' (a sub; a string expression has the 7-argument closure signature) */
    private static function IsCodeRef($conv): bool
    {
        if (!$conv instanceof \Closure) return is_string($conv) or is_array($conv);
        $isCode = self::$isCode ??= new \WeakMap();

        return $isCode[$conv] ??= (new \ReflectionFunction($conv))->getNumberOfParameters() !== 7;
    }

    /** Perl: $condition =~ /\$(valPt|format|count)\b/ (the condition needs the data) */
    private static function ConditionUsesData($condition): bool
    {
        if (!$condition instanceof \Closure) return false;
        $usesData = self::$usesData ??= new \WeakMap();
        if (!isset($usesData[$condition])) {
            $r = new \ReflectionFunction($condition);
            $file = $r->getFileName();
            $lines = self::$sourceLines[$file] ??= ((is_string($file) and is_file($file)) ? file($file) : []);
            $src = implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
            // (only the body counts, not the parameter list)
            $src = preg_replace('/^.*?function\s*\([^)]*\)/s', '', $src);
            $usesData[$condition] = (bool) preg_match('/\$(valPt|format|count)\b/', $src);
        }
        return $usesData[$condition];
    }

    /** Perl's '\$val' ValueConv for Binary tags */
    private static function BinaryConv(): \Closure
    {
        return self::$binaryConv ??= static function ($val, $self, $vals, $prt, $raw, $tagInfo, $tag) {
            return new ScalarRef($val);
        };
    }

    /** Perl hash key of a value */
    private static function HashKey($val): string
    {
        return Perl::str($val);
    }

    /** Perl: ref $val eq 'SCALAR' (binary data; a reference to a reference is a 'REF') */
    public static function IsScalarRef($val): bool
    {
        return $val instanceof ScalarRef and !is_array($val->value) and !is_object($val->value);
    }

    /** Perl join() */
    private static function JoinList($sep, array $list): string
    {
        return implode(Perl::str($sep), array_map(Perl::str(...), $list));
    }

    /** Perl array with holes as a PHP list (holes are undef) */
    private static function FillList(array $list): array
    {
        if (!$list) return [];
        $out = [];
        $max = max(array_keys($list));
        for ($i = 0; $i <= $max; ++$i) $out[] = $list[$i] ?? null;
        return $out;
    }

    /** a PCRE for a Perl regular expression held in a string */
    public static function Rx($re, string $mods = ''): string
    {
        $re = Perl::str($re);
        foreach (['/', '#', '~', '%', '!', '@', ';', ',', '`'] as $d) {
            if (!str_contains($re, $d)) return $d . $re . $d . $mods;
        }
        return '/' . str_replace('/', '\/', $re) . '/' . $mods;
    }

    /** class name of a module (Perl Image::ExifTool::7Z => _7Z) */
    private static function ClassName($module): string
    {
        $module = (string) $module;
        return ctype_digit($module[0] ?? '') ? "_$module" : $module;
    }

    /**
     * Perl `require Image::ExifTool::Canon;`: loads the generated tables of the module (running
     * its load-time actions like AddCompositeTags) and returns true if the module exists.
     */
    public static function RequireModule($module): bool
    {
        $key = preg_match('/^Image::ExifTool::(\w+)/', (string) $module, $m) ? $m[1] : 'ExifTool';
        $found = self::LoadModuleTables($key);
        return class_exists('Image\\ExifTool\\' . self::ClassName($key)) or $found;
    }

    private static function LoadModuleTables(string $module): bool
    {
        if (isset(self::$loadedModules[$module])) return self::$loadedModules[$module];
        $file = dirname(__DIR__) . "/tables/$module.php";
        if (!is_file($file)) return self::$loadedModules[$module] = false;
        self::$loadedModules[$module] = true;
        $data = require $file;
        self::$rawTables += $data['tables'];
        foreach ($data['load'] as $action) {
            $func = array_shift($action);
            self::$func(...$action);
        }
        return true;
    }

    /**
     * The package hash of a tag table (Perl \%{"Image::ExifTool::Canon::Main"}), loading the
     * table's module if necessary.  Use GetTagTable() to get a table ready for use.
     */
    public static function PackageTable($tableName): ?TagTable
    {
        $tableName = (string) $tableName;
        if (isset(self::$pkgTables[$tableName])) return self::$pkgTables[$tableName];
        if (!isset(self::$rawTables[$tableName])) {
            self::$tableIndex ??= require dirname(__DIR__) . '/tables/_index.php';
            $module = self::$tableIndex[$tableName] ?? null;
            if ($module === null) return null;
            self::LoadModuleTables($module);
            if (isset(self::$pkgTables[$tableName])) return self::$pkgTables[$tableName];
            if (!isset(self::$rawTables[$tableName])) return null;
        }
        $raw = self::$rawTables[$tableName];
        unset(self::$rawTables[$tableName]);
        return self::$pkgTables[$tableName] = new TagTable($raw);
    }

    /** system time zone, like the C library used by Perl (TZ variable, else /etc/localtime) */
    private static function LocalTZ(): \DateTimeZone
    {
        if (self::$localTZ === null) {
            $name = getenv('TZ');
            if ($name === false or $name === '') {
                $link = @readlink('/etc/localtime');
                $name = ($link !== false and preg_match('{zoneinfo/(.+)$}', $link, $m)) ? $m[1] : 'UTC';
            } else {
                $name = preg_replace('/^:/', '', $name);
                if (preg_match('{zoneinfo/(.+)$}', $name, $m)) $name = $m[1];
            }
            try {
                self::$localTZ = new \DateTimeZone($name);
            } catch (\Exception) {
                self::$localTZ = new \DateTimeZone('UTC');
            }
        }
        return self::$localTZ;
    }

    /** Perl localtime($time): (sec, min, hour, mday, mon, year-1900, wday, yday, isdst) */
    public static function PerlLocaltime($time): array
    {
        return self::TimeArray($time, self::LocalTZ());
    }

    /** Perl gmtime($time) */
    public static function PerlGmtime($time): array
    {
        return self::TimeArray($time, new \DateTimeZone('UTC'));
    }

    private static function TimeArray($time, \DateTimeZone $tz): array
    {
        $t = Perl::num($time);
        $t = is_float($t) ? (int) floor($t) : $t;
        $dt = (new \DateTimeImmutable('@' . $t))->setTimezone($tz);
        [$y, $mo, $d, $h, $mi, $s, $w, $z, $dst] = array_map('intval', explode(' ', $dt->format('Y n j G i s w z I')));
        return [$s, $mi, $h, $d, $mo - 1, $y - 1900, $w, $z, $dst];
    }

    /** Time::Local::timegm (with its year interpretation and range checks) */
    public static function TimeLocal_timegm($sec, $min, $hour, $mday, $month, $year)
    {
        [$sec, $min, $hour, $mday, $month, $year] = array_map(static fn ($v) => Perl::num($v), [$sec, $min, $hour, $mday, $month, $year]);
        if ($year >= 1000) {
            $year -= 1900;
        } elseif ($year < 100 and $year >= 0) {
            // (rolling century)
            $thisYear = (int) date('Y') - 1900;
            $breakpoint = ($thisYear + 50) % 100;
            $nextCentury = $thisYear - $thisYear % 100;
            if ($breakpoint < 50) $nextCentury += 100;
            $century = $nextCentury - 100;
            $year += ($year > $breakpoint) ? $century : $nextCentury;
        }
        if ($month > 11 or $month < 0) throw new \RuntimeException("Month '$month' out of range 0..11");
        $md = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][(int) $month];
        $y = $year + 1900;
        if ($month == 1 and !($y % 4) and ($y % 100 or !($y % 400))) ++$md;
        if ($mday > $md or $mday < 1) throw new \RuntimeException("Day '$mday' out of range 1..$md");
        if ($hour > 23 or $hour < 0) throw new \RuntimeException("Hour '$hour' out of range 0..23");
        if ($min > 59 or $min < 0) throw new \RuntimeException("Minute '$min' out of range 0..59");
        if ($sec >= 60 or $sec < 0) throw new \RuntimeException("Second '$sec' out of range 0..59");
        return $sec + 60 * $min + 3600 * $hour + 86400 * self::DayGM($mday, $month, $year);
    }

    /** Time::Local::_daygm: days since the epoch (year - 1900) */
    private static function DayGM($mday, $month, $year)
    {
        $month = ((int) $month + 10) % 12;
        $year = (int) $year + 1900 - intdiv($month, 10);
        return $mday + (365 * $year + intdiv($year, 4) - intdiv($year, 100) + intdiv($year, 400) +
                        intdiv($month * 306 + 5, 10)) - 719469;
    }

    /** Time::Local::_timegm on a localtime list */
    private static function TimeGMNoCheck(array $tm)
    {
        return $tm[0] + 60 * $tm[1] + 3600 * $tm[2] + 86400 * self::DayGM($tm[3], $tm[4], $tm[5]);
    }

    /** Time::Local::timelocal */
    public static function TimeLocal_timelocal($sec, $min, $hour, $mday, $month, $year)
    {
        $refT = self::TimeLocal_timegm($sec, $min, $hour, $mday, $month, $year);
        $locForRefT = self::TimeGMNoCheck(self::PerlLocaltime($refT));
        $zoneOff = $locForRefT - $refT;
        if (!$zoneOff) return $locForRefT;
        // Adjust for timezone
        $locT = $refT - $zoneOff;
        // Are we close to a DST change or are we done
        $dstOff = $refT - self::TimeGMNoCheck(self::PerlLocaltime($locT));
        // If this evaluates to true, it means that the value in $loc_t is
        // the _second_ hour after a DST change where the local time moves
        // backward.
        if (!$dstOff and (($refT - 3600) - self::TimeGMNoCheck(self::PerlLocaltime($locT - 3600)) < 0)) {
            return $locT - 3600;
        }
        // Adjust for DST change
        $locT += $dstOff;
        if ($dstOff > 0) return $locT;
        // If the original date was a non-existent gap in a forward DST jump, we
        // should now have the wrong answer - undo the DST adjustment
        $lt = self::PerlLocaltime($locT);
        if ($lt[0] != $sec or $lt[1] != $min or $lt[2] != $hour) $locT -= $dstOff;
        return $locT;
    }

    /** POSIX::strftime with a broken-down time (C locale) */
    public static function POSIX_strftime($fmt, array $a): string
    {
        [$sec, $min, $hour, $mday, $mon, $year] = array_map(static fn ($v) => (int) Perl::int($v), array_slice($a, 0, 6)) + [0, 0, 0, 1, 0, 70];
        $dt = (new \DateTimeImmutable('@0'))->setDate($year + 1900, $mon + 1, $mday)->setTime($hour, $min, $sec);
        $f = static fn (string $c) => $dt->format($c);
        $local = static fn () => new \DateTimeImmutable($dt->format('Y-m-d H:i:s'), self::LocalTZ());
        return preg_replace_callback('/%([a-zA-Z%+])/', static function ($m) use ($f, $local) {
            return match ($m[1]) {
                'a' => $f('D'), 'A' => $f('l'), 'b', 'h' => $f('M'), 'B' => $f('F'),
                'c' => $f('D M ') . sprintf('%2d', $f('j')) . $f(' H:i:s Y'),
                'C' => sprintf('%02d', intdiv((int) $f('Y'), 100)),
                'd' => $f('d'), 'D' => $f('m/d/y'), 'e' => sprintf('%2d', $f('j')), 'F' => $f('Y-m-d'),
                'g' => substr($f('o'), -2), 'G' => $f('o'), 'H' => $f('H'), 'I' => $f('h'),
                'j' => sprintf('%03d', $f('z') + 1), 'k' => sprintf('%2d', $f('G')), 'l' => sprintf('%2d', $f('g')),
                'm' => $f('m'), 'M' => $f('i'), 'n' => "\n", 'p' => $f('A'), 'r' => $f('h:i:s A'),
                'R' => $f('H:i'), 'S' => $f('s'), 't' => "\t", 'T' => $f('H:i:s'), 'u' => $f('N'),
                'U' => sprintf('%02d', intdiv($f('z') + 7 - $f('w'), 7)), 'V' => $f('W'), 'w' => $f('w'),
                'W' => sprintf('%02d', intdiv($f('z') + 7 - ($f('w') + 6) % 7, 7)),
                'x' => $f('m/d/y'), 'X' => $f('H:i:s'), 'y' => $f('y'), 'Y' => $f('Y'),
                'z' => $local()->format('O'), 'Z' => $local()->format('T'), 's' => $local()->format('U'),
                '+' => $f('D M ') . sprintf('%2d', $f('j')) . $f(' H:i:s ') . $local()->format('T') . $f(' Y'),
                '%' => '%',
                default => $m[0],
            };
        }, (string) $fmt);
    }
}
