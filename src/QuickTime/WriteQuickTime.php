<?php

//------------------------------------------------------------------------------
// File:         WriteQuickTime.pl
//
// Description:  Write XMP to QuickTime (MOV and MP4) files
//
// Revisions:    2013-10-29 - P. Harvey Created
//
// PHP port (package Image::ExifTool::QuickTime), mixed into class Image\ExifTool\QuickTime.
// GetLangInfo is in src/QuickTime.php (needed for reading). The file lexicals (%movMap,
// %qtFormat, $undLang...) are the constants below, and the code Perl runs when it loads this
// file is LoadWriteQuickTime(), called by the subs that Perl would autoload.
// PHP notes:
// - the entries of the ChunkOffset lists are arrays (each one is in only one list at a time),
//   the mdat chunks are \ArrayObject's because they are shared and modified through several
//   lists, like the Perl array refs; new data of an mdat edit is a ScalarRef (Perl \$newVal).
// - the hashes keyed by a reference ($$didTag{$nvHash}, %keysInfo) use spl_object_id().
//------------------------------------------------------------------------------

namespace Image\ExifTool\QuickTime;

use Image\ExifTool\Exif;
use Image\ExifTool\ExifTool;
use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\GPS;
use Image\ExifTool\MIE;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars;

trait WriteQuickTime
{
    // maps for adding metadata to various QuickTime-based file types
    /** my %movMap */
    private const array MOV_MAP = [
        // MOV (no 'ftyp', or 'ftyp'='qt  ') -> XMP in 'moov'-'udta'-'XMP_'
        'QuickTime' => 'ItemList',    // (default location for QuickTime tags)
        'ItemList'  => 'Meta',        // MOV-Movie-UserData-Meta-ItemList
        'Keys'      => 'Movie',       // MOV-Movie-Meta-Keys !! (hack due to different Meta location)
        'AudioKeys' => 'Track',       // MOV-Movie-Track-Meta-Keys !!
        'VideoKeys' => 'Track',       // MOV-Movie-Track-Meta-Keys !!
        'Meta'      => 'UserData',
        'XMP'       => 'UserData',    // MOV-Movie-UserData-XMP
        'Microsoft' => 'UserData',    // MOV-Movie-UserData-Microsoft
        'UserData'  => 'Movie',       // MOV-Movie-UserData
        'Movie'     => 'MOV',
        'GSpherical' => 'SphericalVideoXML', // MOV-Movie-Track-SphericalVideoXML
        'SphericalVideoXML' => 'Track',      // (video track specifically, don't create if it doesn't exist)
        'Track'     => 'Movie',
    ];
    /** my %mp4Map */
    private const array MP4_MAP = [
        // MP4 ('ftyp' compatible brand 'mp41', 'mp42' or 'f4v ') -> XMP at top level
        'QuickTime' => 'ItemList',    // (default location for QuickTime tags)
        'ItemList'  => 'Meta',        // MOV-Movie-UserData-Meta-ItemList
        'Keys'      => 'Movie',       // MOV-Movie-Meta-Keys !! (hack due to different Meta location)
        'AudioKeys' => 'Track',       // MOV-Movie-Track-Meta-Keys !!
        'VideoKeys' => 'Track',       // MOV-Movie-Track-Meta-Keys !!
        'Meta'      => 'UserData',
        'UserData'  => 'Movie',       // MOV-Movie-UserData
        'Microsoft' => 'UserData',    // MOV-Movie-UserData-Microsoft
        'Movie'     => 'MOV',
        'XMP'       => 'MOV',         // MOV-XMP
        'GSpherical' => 'SphericalVideoXML', // MOV-Movie-Track-SphericalVideoXML
        'SphericalVideoXML' => 'Track',      // (video track specifically, don't create if it doesn't exist)
        'Track'     => 'Movie',
    ];
    /** my %heicMap */
    private const array HEIC_MAP = [
        // HEIC/HEIF/AVIF ('ftyp' compatible brand 'heic','mif1','avif') -> XMP/EXIF in top level 'meta'
        'Meta'         => 'MOV',
        'ItemInformation' => 'Meta',
        'ItemPropertyContainer' => 'Meta',
        'XMP'          => 'ItemInformation',
        'EXIF'         => 'ItemInformation',
        'ICC_Profile'  => 'ItemPropertyContainer',
        'IFD0'         => 'EXIF',
        'IFD1'         => 'IFD0',
        'ExifIFD'      => 'IFD0',
        'GPS'          => 'IFD0',
        'SubIFD'       => 'IFD0',
        'GlobParamIFD' => 'IFD0',
        'PrintIM'      => 'IFD0',
        'InteropIFD'   => 'ExifIFD',
        'MakerNotes'   => 'ExifIFD',
    ];
    /** my %cr3Map */
    private const array CR3_MAP = [
        // CR3 ('ftyp' compatible brand 'crx ') -> XMP at top level
        'Movie'     => 'MOV',
        'XMP'       => 'MOV',
        'UUID-Canon' => 'Movie',
        'ExifIFD'   => 'UUID-Canon',
        'IFD0'      => 'UUID-Canon',
        'GPS'       => 'UUID-Canon',
        //MakerNoteCanon => 'UUID-Canon', # (doesn't yet work -- goes into ExifIFD instead)
        'UUID-Canon2' => 'MOV',
        'CanonVRD'  => 'UUID-Canon2',
    ];
    /** my %dirMap */
    private const array DIR_MAP = [
        'MOV'  => self::MOV_MAP,
        'MP4'  => self::MP4_MAP,
        'CR3'  => self::CR3_MAP,
        'HEIC' => self::HEIC_MAP,
    ];

    // convert ExifTool Format to QuickTime type
    /** my %qtFormat */
    private const array QT_FORMAT = [
        'undef' => 0x00,  'string' => 0x01,
        'int8s'  => 0x15,  'int16s' => 0x15,  'int32s' => 0x15,  'int64s' => 0x15,
        'int8u'  => 0x16,  'int16u' => 0x16,  'int32u' => 0x16,  'int64u' => 0x16,
        'float'  => 0x17,  'double' => 0x18,
    ];
    /** my $undLang: numeric code for default ('und') language */
    private const int UND_LANG = 0x55c4;

    /** my $maxReadLen: maximum size of atom to read into memory (100 MB) */
    private const int MAX_READ_LEN = 100000000;

    // boxes that may exist in an "empty" Meta box:
    /** my %emptyMeta */
    private const array EMPTY_META = [
        'hdlr' => 'Handler', 'keys' => 'Keys', 'lang' => 'Language', 'ctry' => 'Country', 'free' => 'Free',
    ];

    // starting word for Keys tags which use a full tag ID
    /** my %fullKeysID */
    private const array FULL_KEYS_ID = [
        'com' => 1,   'xiaomi' => 1,    'samsung' => 1,
    ];

    // lookup for CTBO ID number based on uuid for Canon CR3 files
    /** my %ctboID */
    private const array CTBO_ID = [
        "\xbe\x7a\xcf\xcb\x97\xa9\x42\xe8\x9c\x71\x99\x94\x91\xe3\xaf\xac" => 1, // XMP
        "\xea\xf4\x2b\x5e\x1c\x98\x4b\x88\xb9\xfb\xb7\xdc\x40\x6e\x4d\x16" => 2, // PreviewImage
        // ID 3 is used for 'mdat' atom (not a uuid)
        // (haven't seen ID 4 yet)
        "\x57\x66\xb8\x29\xbb\x6a\x47\xc5\xbc\xfb\x8b\x9f\x22\x60\xd0\x6d" => 5, // something to do with burst-roll image
    ];

    /** (PHP) set once the load-time code of WriteQuickTime.pl has run */
    private static bool $writeQuickTimeLoaded = false;

    /**
     * (PHP) The code Perl runs when it loads WriteQuickTime.pl, which happens on the first call
     * to one of its subs (AUTOLOAD), usually the dummy call to a QuickTime write proc made by
     * SetNewValue.  Called at the start of those subs.
     */
    private static function LoadWriteQuickTime(): void
    {
        if (self::$writeQuickTimeLoaded) return;
        self::$writeQuickTimeLoaded = true;

        // mark UserData tags that don't have ItemList counterparts as Preferred
        // - and set Preferred to 0 for any Avoid-ed tag
        // - also, for now, set Writable to 0 for any tag with a RawConv and no RawConvInv
        $itemList = ExifTool::PackageTable('Image::ExifTool::QuickTime::ItemList');
        $userData = ExifTool::PackageTable('Image::ExifTool::QuickTime::UserData');
        $pref = [];
        foreach (ExifTool::TagTableKeys($itemList) as $tag) {
            $tagInfo = $itemList[$tag];
            if (!self::IsTagInfoHash($tagInfo)) {
                if (is_array($tagInfo)) continue;   // (ARRAY ref)
                $tagInfo = $itemList[$tag] = new TagInfo(['Name' => $tagInfo]);
            } else {
                if (!$tagInfo instanceof TagInfo) $tagInfo = $itemList[$tag] = new TagInfo();   // (an empty hash)
                if ($tagInfo->RawConv and !$tagInfo->RawConvInv) $tagInfo->Writable = 0;
                if ($tagInfo->Avoid) {
                    $tagInfo->Preferred = 0;
                    continue;
                }
                if ($tagInfo->Preferred !== null and !$tagInfo->Preferred) continue;
            }
            $pref[Perl::str($tagInfo->Name)] = 1;
        }
        foreach (ExifTool::TagTableKeys($userData) as $tag) {
            $tagInfo = $userData[$tag];
            if (!self::IsTagInfoHash($tagInfo)) {
                if (is_array($tagInfo)) continue;   // (ARRAY ref)
                $tagInfo = $userData[$tag] = new TagInfo(['Name' => $tagInfo]);
            } else {
                if (!$tagInfo instanceof TagInfo) $tagInfo = $userData[$tag] = new TagInfo();   // (an empty hash)
                if ($tagInfo->RawConv and !$tagInfo->RawConvInv) $tagInfo->Writable = 0;
                if ($tagInfo->Avoid) {
                    $tagInfo->Preferred = 0;
                    continue;
                }
                if ($tagInfo->Preferred !== null or isset($pref[Perl::str($tagInfo->Name)])) continue;
            }
            $tagInfo->Preferred = 1;
        }
    }

    /** (PHP) Perl ref $tagInfo eq 'HASH' for a tag table entry (an empty hash is generated as []) */
    private static function IsTagInfoHash($tagInfo): bool
    {
        return $tagInfo instanceof TagInfo or $tagInfo === [];
    }

    //------------------------------------------------------------------------------
    // Format GPSCoordinates for writing
    // Inputs: 0) PrintConv value
    // Returns: ValueConv value
    public static function PrintInvGPSCoordinates($val, $et = null)
    {
        self::LoadWriteQuickTime();
        $val = Perl::str($val);
        $v = Perl::split('/, */', $val);
        if (count($v) == 2 or count($v) == 3) {
            $below = (($v[2] ?? null) and preg_match('/below/i', $v[2]));
            $v[0] = GPS::ToDegrees($v[0], 1);
            $v[1] = GPS::ToDegrees($v[1], 1);
            if (count($v) == 3) $v[2] = ExifTool::ToFloat($v[2]) * ($below ? -1 : 1);
            return implode(' ', array_map([Perl::class, 'str'], $v));
        }
        if (preg_match('/^([-+]?\d+(\.\d*)?)\s+([-+]?\d+(\.\d*)?)$/', $val)) return $val; // already 2 floats?
        if (preg_match('/^([-+]\d+(\.\d*)?){2,3}(CRS.*)?\/?$/', $val)) return $val; // already in ISO6709 format?
        return null;
    }

    //------------------------------------------------------------------------------
    // Convert GPS coordinates back to ISO6709 format
    // Inputs: 0) ValueConv value
    // Returns: ISO6709 coordinates
    public static function ConvInvISO6709($val)
    {
        self::LoadWriteQuickTime();
        $val = Perl::str($val);
        $a = Perl::split(' ', $val);
        if (count($a) == 2 or count($a) == 3) {
            // latitude must have 2 digits before the decimal, and longitude 3,
            // and all values must start with a "+" or "-", and Google Photos
            // requires at least 3 digits after the decimal point
            // (and as of Apr 2021, Google Photos doesn't accept coordinats
            //  with more than 5 digits after the decimal place:
            //  https://exiftool.org/forum/index.php?topic=11055.msg67171#msg67171
            //  still a problem Apr 2024: https://exiftool.org/forum/index.php?msg=85761)
            $fmt = ['%s%02d.%s%s', '%s%03d.%s%s', '%s%d.%s%s'];
            $limit = [90, 180];
            foreach ($a as &$v) {
                if (!ExifTool::IsFloat($v)) return null;
                $lim = array_shift($limit);
                if ($lim and abs(Perl::num($v)) > $lim) ExifTool::PerlWarn(($limit ? 'Lat' : 'Long') . "itude out of range\n");
                $v = preg_replace_callback('/^([-+]?)(\d+)\.?(\d*)/', static function ($m) use (&$fmt) {
                    return Perl::sprintf(array_shift($fmt), $m[1] ?: '+', $m[2], $m[3],
                        strlen($m[3]) < 3 ? str_repeat('0', 3 - strlen($m[3])) : '');
                }, $v, 1);
            }
            unset($v);
            return implode('', $a) . '/';
        }
        if (preg_match('/^([-+]\d+(\.\d*)?){2,3}(CRS.*)?\/?$/', $val)) return $val; // already in ISO6709 format?
        return null;
    }

    //------------------------------------------------------------------------------
    // Handle offsets in iloc (ItemLocation) atom when writing (ref ISO 14496-12:2015 pg.79)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) data ref, 3) output buffer ref
    // Returns: true on success
    // Notes: see also ParseItemLocation() in QuickTime.pm
    // (variable names with underlines correspond to names in ISO 14496-12)
    public static function Handle_iloc(ExifTool $et, array &$dirInfo, $dataPt, ScalarRef $outfile)
    {
        $id = null;

        $dirInfo['ChunkOffset'] ??= [];
        $off = &$dirInfo['ChunkOffset'];
        $len = strlen($dataPt);
        if ($len < 8) return 0;
        $ver = ExifTool::Get8u($dataPt, 0);
        $siz = ExifTool::Get16u($dataPt, 4);
        $noff = ($siz >> 12);
        $nlen = ($siz >> 8) & 0x0f;
        $nbas = ($siz >> 4) & 0x0f;
        $nind = $siz & 0x0f;
        $ok = [0 => 1, 4 => 1, 8 => 8];
        if (!(($ok[$noff] ?? null) and ($ok[$nlen] ?? null) and ($ok[$nbas] ?? null) and ($ok[$nind] ?? null))) return 0;
        // piggy-back on existing code to fix up stco/co64 4/8-byte offsets
        $tag = $noff == 4 ? 'stco_iloc' : 'co64_iloc';
        if ($ver < 2) {
            $num = ExifTool::Get16u($dataPt, 6);
            $pos = 8;
        } else {
            if ($len < 10) return 0;
            $num = ExifTool::Get32u($dataPt, 6);
            $pos = 10;
        }
        for ($i = 0; $i < $num; ++$i) {
            if ($ver < 2) {
                if ($pos + 2 > $len) return 0;
                $id = ExifTool::Get16u($dataPt, $pos);
                $pos += 2;
            } else {
                if ($pos + 4 > $len) return 0;
                $id = ExifTool::Get32u($dataPt, $pos);
                $pos += 4;
            }
            $constOff = $minOffset = null;
            $offBase = $offItem = [];
            if ($ver == 1 or $ver == 2) {
                if ($pos + 2 > $len) return 0;
                // offsets are absolute only if ConstructionMethod is 0, otherwise
                // the relative offsets are constant as far as we are concerned
                $constOff = ExifTool::Get16u($dataPt, $pos) & 0x0f;
                $pos += 2;
            }
            if ($pos + 2 > $len) return 0;
            $drefIdx = ExifTool::Get16u($dataPt, $pos);
            if ($drefIdx) {
                $dref = $et->QtDataRef[$drefIdx - 1] ?? null;
                if ($dref) {
                    // these offsets are constant unless the data is in this file
                    if (!($dref[1] == 1 and $dref[0] !== 'rsrc')) $constOff = 1;
                } else {
                    $et->Error("No data reference for iloc entry $i");
                    return 0;
                }
            }
            $pos += 2;
            // get base offset and save its location if in this file
            $base_offset = self::GetVarInt($dataPt, $pos, $nbas);
            if ($base_offset and !$constOff) {
                $tg = ($nbas == 4 ? 'stco' : 'co64') . '_iloc';
                $offBase[] = [$tg, strlen($outfile->value) + 8 + $pos - $nbas, $nbas, 0, $id];
            }
            if ($pos + 2 > $len) return 0;
            $ext_num = ExifTool::Get16u($dataPt, $pos);
            $pos += 2;
            $listStartPos = $pos;
            // run through the item list to get offset locations and the minimum offset in this file
            for ($j = 0; $j < $ext_num; ++$j) {
                if ($ver == 1 or $ver == 2) $pos += $nind;
                $extent_offset = self::GetVarInt($dataPt, $pos, $noff);
                if ($extent_offset === null) return 0;
                if (!$constOff) {
                    if ($noff) $offItem[] = [$tag, strlen($outfile->value) + 8 + $pos - $noff, $noff, 0, $id];
                    if ($minOffset === null or $minOffset > $extent_offset) $minOffset = $extent_offset;
                }
                if ($pos + $nlen > strlen($dataPt)) return 0;
                $pos += $nlen;
            }
            // decide whether to fix up the base offset or individual item offsets
            // (adjust the one that is larger)
            if ($minOffset !== null and $minOffset > Perl::num($base_offset)) {
                foreach ($offItem as &$o) $o[3] = $base_offset;
                unset($o);
                array_push($off, ...$offItem);
            } else {
                foreach ($offBase as &$o) $o[3] = $minOffset;
                unset($o);
                array_push($off, ...$offBase);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckQTValue(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        self::LoadWriteQuickTime();
        $format = ($tagInfo->Format ?: $tagInfo->Writable) ?: $tagInfo->Table['FORMAT'];
        if (!$format) return null;
        return ExifTool::CheckValue($valPtr, $format, $tagInfo->Count);
    }

    //------------------------------------------------------------------------------
    // Format QuickTime value for writing
    // Inputs: 0) ExifTool ref, 1) value ref, 2) tagInfo ref, 3) Format (or undef)
    // Returns: Flags for QT data type, and reformats value as required (sets to undef on error)
    public static function FormatQTValue(ExifTool $et, &$valPt, TagInfo $tagInfo, $format = null)
    {
        $writable = $tagInfo->Writable;
        $count = $tagInfo->Count;
        $flags = null;
        if (!$format) $format = $tagInfo->Format;
        if (($format and Perl::str($format) !== 'string') or (!$format and $writable and Perl::str($writable) !== 'string')) {
            $valPt = ExifTool::WriteValue($valPt, $format ?: $writable, $count);
            if ($writable and (self::QT_FORMAT[Perl::str($writable)] ?? null)) {
                $flags = self::QT_FORMAT[Perl::str($writable)];
            } else {
                $flags = (self::QT_FORMAT[Perl::str($format ?: 0)] ?? null) ?: 0;
            }
        } elseif (preg_match('/^\xff\xd8\xff/', Perl::str($valPt))) {
            $flags = 0x0d;  // JPG
        } elseif (preg_match('/^(\x89P|\x8aM|\x8bJ)NG\r\n\x1a\n/', Perl::str($valPt))) {
            $flags = 0x0e;  // PNG
        } elseif (preg_match('/^BM.{15}\0/s', Perl::str($valPt))) {
            $flags = 0x1b;  // BMP
        } else {
            $flags = 0x01;  // UTF8
            $valPt = $et->Encode($valPt, 'UTF8');
        }
        if ($valPt === null) $et->Warn("Error converting value for {$tagInfo->Name}");
        return $flags;
    }

    //------------------------------------------------------------------------------
    // Set variable-length integer (used by WriteItemInfo)
    // Inputs: 0) value, 1) integer size in bytes (0, 4 or 8),
    // Returns: packed integer
    public static function SetVarInt($val, $n)
    {
        if ($n == 4) {
            return ExifTool::Set32u($val);
        } elseif ($n == 8) {
            return ExifTool::Set64u($val);
        }
        return '';
    }

    //------------------------------------------------------------------------------
    // Write Nextbase infi atom (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: updated infi data
    public static function WriteNextbase(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        self::LoadWriteQuickTime();
        if (!$et) return 1;
        if ($et->DEL_GROUP['Nextbase'] ?? null) {
            ++$et->CHANGED;
            return '';
        }
        return $dirInfo['DataPt'];
    }

    //------------------------------------------------------------------------------
    // Write Meta Keys to add/delete entries as necessary ('mdta' handler) (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: updated keys box data
    // Note: Residual entries may be left in the 'keys' directory when deleting tags
    //       with language codes because the language code(s) are not known until the
    //       corresponding ItemList entry(s) are processed
    public static function WriteKeys(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        self::LoadWriteQuickTime();
        if (!$et) return 1;      // allow dummy access to autoload this package
        $dataPt = Perl::str($dirInfo['DataPt']);
        $dirLen = strlen($dataPt);
        $outfile = $dirInfo['OutFile'] ?? null;
        $done = $remap = $info = $add = [];

        $av = Vars\QuickTime::$avType[Perl::str($et->MediaType)] ?? null;
        $keysGrp = $av ? "{$av}Keys" : 'Keys';
        if ($dirLen < 8) {
            $et->Warn('Short Keys box');
            $dirLen = 8;
            // ponytail: Perl also changes the caller's buffer, which only shows in its verbose "changed size" message
            $dataPt = str_repeat("\0", 8);
        }
        if ($et->DEL_GROUP[$keysGrp] ?? null) {
            $dirLen = 8;    // delete all existing keys
            // deleted keys are identified by a zero entry in the Remap lookup
            $n = ExifTool::Get32u($dataPt, 4);
            for ($i = 1; $i <= $n; ++$i) $remap[$i] = 0;
            $et->VPrint(0, "  [deleting $n $keysGrp entr" . ($n == 1 ? 'y' : 'ies') . "]\n");
            ++$et->CHANGED;
        }
        $pos = 8;
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);
        $newData = substr($dataPt, 0, $pos);

        $newIndex = 1;
        $index = 1;
        while ($pos < $dirLen - 4) {
            $len = Perl::unpack("x{$pos}N", $dataPt)[0];
            if ($len < 8 or $pos + $len > $dirLen) break;
            $ns = substr($dataPt, $pos + 4, 4);
            $tag = substr($dataPt, $pos + 8, $len - 8);
            $tag = preg_replace('/\0.*/s', '', $tag, 1);    // truncate at null
            if ($ns === 'mdta') $tag = preg_replace('/^com\.apple\.quicktime\./', '', $tag, 1); // remove apple quicktime domain
            if (!$tag) $tag = "Tag_$ns";
            $done[$tag] = 1;    // set flag to avoid creating this tag
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo) {
                $info[$index] = $tagInfo;
                if ($newTags[$tag] ?? null) {
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    // drop this tag if it is being deleted
                    if ($nvHash and $et->IsOverwriting($nvHash) > 0 and $et->GetNewValue($nvHash) === null) {
                        // don't delete this key if we could be writing any alternate-language version of this tag
                        $dontDelete = null;
                        foreach ($newTags as $t => $ti) {
                            if (!($ti->SrcTagInfo and $ti->SrcTagInfo === $tagInfo)) continue;
                            $nv = $et->GetNewValueHash($ti);
                            if (!($et->IsOverwriting($nv) and $et->GetNewValue($nv) !== null)) continue;
                            $dontDelete = 1;
                            break;
                        }
                        if (!$dontDelete) {
                            // delete this key
                            $et->VPrint(1, "{$et->INDENT}[deleting $keysGrp entry $index '{$tag}']\n");
                            $pos += $len;
                            $remap[$index++] = 0;
                            ++$et->CHANGED;
                            continue;
                        }
                    }
                }
            }
            // add to the Keys box data
            $newData .= substr($dataPt, $pos, $len);
            $remap[$index++] = $newIndex++;
            $pos += $len;
        }
        // add keys for any tags we need to create
        foreach (self::SortedKeys($newTags) as $tag) {
            $tagInfo = $newTags[$tag];
            if ($tagInfo->LangCode and $tagInfo->SrcTagInfo) {
                $id = $tagInfo->SrcTagInfo->TagID;
            } else {
                $id = $tag;
            }
            if ($done[$id] ?? null) continue;
            $nvHash = $et->GetNewValueHash($tagInfo);
            if (!($nvHash?->IsCreating and $et->IsOverwriting($nvHash) and
                $et->GetNewValue($nvHash) !== null)) continue;
            // add new entry to 'keys' data
            $val = Perl::str($id);
            if (!(preg_match('/^(.*?)\./', $val, $m) and (self::FULL_KEYS_ID[$m[1]] ?? null))) {
                $val = "com.apple.quicktime.$val";
            }
            $newData .= ExifTool::Set32u(8 + strlen($val)) . 'mdta' . $val;
            $et->VPrint(1, "{$et->INDENT}[adding $keysGrp entry $newIndex '{$id}']\n");
            $add[$newIndex++] = $tagInfo;
            ++$et->CHANGED;
        }
        $num = $newIndex - 1;
        if ($num) {
            ExifTool::Set32u($num, $newData, 4);    // update count in header
        } else {
            $newData = '';  // delete empty Keys box
        }
        // save temporary variables for use when writing ItemList:
        //   Remap - lookup for remapping Keys ID numbers (0 if item is deleted)
        //   Info  - Keys tag information, based on old index value
        //   Add   - Keys items deleted, based on old index value
        //   Num   - Number of items in edited Keys box
        $et->{$keysGrp} = ['Remap' => $remap, 'Info' => $info, 'Add' => $add, 'Num' => $num];

        return $newData;    // return updated Keys box
    }

    /** (PHP) Perl sort keys %$hash: the keys as strings, in string order */
    private static function SortedKeys(array $hash): array
    {
        $keys = array_map('strval', array_keys($hash));
        sort($keys, SORT_STRING);
        return $keys;
    }

    //------------------------------------------------------------------------------
    // Write ItemInformation in HEIC files
    // Inputs: 0) ExifTool ref, 1) dirInfo ref (with BoxPos entry), 2) output buffer ref
    // Returns: mdat edit list ref (empty if nothing changed)
    public static function WriteItemInfo(ExifTool $et, array &$dirInfo, ScalarRef $outfile)
    {
        $boxPos = &$dirInfo['BoxPos'];  // hash of [position,length,irefVer(iref only)] for box in $outfile
        $raf = $et->RAF;
        $items = $et->ItemInfo;
        $did = $mdatEdit = [];
        $name = $tmap = null;

        if (!($items and $raf)) return null;

        // extract information from EXIF/XMP metadata items
        $primary = $et->PrimaryItem;
        $curPos = $raf->Tell();
        $lastID = 0;
        $ids = array_keys($items);
        sort($ids);
        foreach ($ids as $id) {
            $lastID = $id;
            if ($primary === null) $primary = $id;  // assume primary is lowest-number item if pitm missing
            $item = $items[$id];
            // only edit primary EXIF/XMP metadata
            if (!(($item['RefersTo'] ?? null) and ($item['RefersTo'][$primary] ?? null))) continue;
            $type = (($item['ContentType'] ?? null) ?: ($item['Type'] ?? null));
            if (!$type) continue;
            if ($type === 'tmap') $tmap = $id; // save ID of primary 'tmap' item (tone-mapped image)
            // get ExifTool name for this item
            $name = ['Exif' => 'EXIF', 'application/rdf+xml' => 'XMP'][$type] ?? null;
            if (!$name) continue;  // only care about EXIF and XMP
            if (!($et->EDIT_DIRS[$name] ?? null)) continue;
            $did[$name] = 1;    // set flag to prevent creating this metadata
            $warn = $buff = null;
            $edit = [];
            if (!($boxPos['iloc'] ?? null)) $warn = 'Missing iloc box';
            if (!($item['Extents'] ?? null)) $warn = "No Extents for $type item";
            if ($item['ContentEncoding'] ?? null) {
                if ($item['ContentEncoding'] !== 'deflate') {
                    $warn = "Can't currently decode {$item['ContentEncoding']} encoded $type metadata";
                } elseif (!function_exists('inflate_init')) {
                    $warn = "Install Compress::Zlib to decode deflated $type metadata";
                }
            }
            if ($item['ProtectionIndex'] ?? null) $warn = "Can't currently decode protected $type metadata";
            if ($item['ConstructionMethod'] ?? null) $warn = "Can't currently extract $type with construction method {$item['ConstructionMethod']}";
            if ($item['DataReferenceIndex'] ?? null) $warn = "$type metadata is not in this file";
            if ($warn) {
                $et->Warn($warn);
                continue;
            }
            $base = ($item['BaseOffset'] ?? null) ?: 0;
            $val = '';
            foreach ($item['Extents'] as $extent) {
                if ($buff !== null) $val .= $buff;
                $pos = $extent[1] + $base;
                if ($extent[2]) {
                    if (!$raf->Seek($pos, 0)) break;
                    if (!$raf->Read($buff, $extent[2])) break;
                } else {
                    $buff = '';
                }
                $edit[] = [$pos, $pos + $extent[2]];   // replace or delete this if changed
            }
            if ($buff === null) continue;
            if (strlen($val)) $buff = $val . $buff;
            $comp = $et->Options('Compress');
            if ($comp !== null and ((bool) $comp xor (bool) ($item['ContentEncoding'] ?? null))) {
                //TODO: add ability to edit infe entry in iinf to change encoding according to Compress option if set
                $et->Warn("Can't currently change compression when rewriting $name in HEIC", 1);
            }
            $wasDeflated = null;
            if ($item['ContentEncoding'] ?? null) {
                $v2 = $stat = null;
                // (PHP: zlib extension instead of Compress::Zlib)
                $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                if ($inflate) {
                    $v2 = @inflate_add($inflate, $buff, ZLIB_SYNC_FLUSH);
                    $stat = inflate_get_status($inflate);
                }
                $et->VPrint(0, "  (Inflating stored $name metadata)\n");
                if ($inflate and $v2 !== false and $stat == ZLIB_STREAM_END) {
                    $buff = $v2;
                    $wasDeflated = 1;
                } else {
                    $et->Warn("Error inflating $name metadata");
                    continue;
                }
            }
            $hdr = $subTable = $proc = null;
            $strt = 0;
            if ($name === 'EXIF') {
                if (!strlen($buff)) {
                    // create EXIF from scratch
                    $hdr = "\0\0\0\x06Exif\0\0";
                } elseif (preg_match('/^(MM\0\x2a|II\x2a\0)/', $buff)) {
                    $et->Warn('Missing Exif header');
                    $hdr = '';
                } elseif (strlen($buff) >= 4 and strlen($buff) >= 4 + Perl::unpack('N', $buff)[0]) {
                    $hdr = substr($buff, 0, 4 + Perl::unpack('N', $buff)[0]);
                    $strt = strlen($hdr);
                } else {
                    $et->Warn('Invalid Exif header');
                    continue;
                }
                $subTable = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
                $proc = 'Image\ExifTool\ExifTool::WriteTIFF';
            } else {
                $hdr = '';
                $subTable = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
            }
            $subDirInfo = [
                'DataPt'   => $buff,
                'DataLen'  => strlen($buff),
                'DirStart' => $strt,
                'DirLen'   => strlen($buff) - $strt,
            ];
            $changed = $et->CHANGED;
            $newVal = $et->WriteDirectory($subDirInfo, $subTable, $proc);
            if ($newVal !== null and $changed != $et->CHANGED and
                // nothing changed if deleting an empty directory
                (($subDirInfo['DirLen'] ?? null) or strlen($newVal)))
            {
                if (strlen($hdr) and strlen($newVal)) $newVal = $hdr . $newVal;
                if ($wasDeflated) {
                    $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['memory' => 9]);
                    if ($deflate) {
                        $et->VPrint(0, "  (Re-deflating new $name metadata)\n");
                        $buff = deflate_add($deflate, $newVal, ZLIB_FINISH);
                        if ($buff !== false) {
                            $newVal = $buff;
                        }
                    }
                }
                $edit[0][2] = new ScalarRef($newVal);   // replace the old chunk with the new data
                $edit[0][3] = $id;          // mark this chunk with the item ID
                array_push($mdatEdit, ...$edit);
                // update item extent_length
                $n = strlen($newVal);
                foreach ($item['Extents'] as $extent) {
                    [$nlen, $lenPt] = [$extent[3], $extent[4]];
                    if ($nlen == 8) {
                        ExifTool::Set64u($n, $outfile->value, $boxPos['iloc'][0] + 8 + $lenPt);
                    } elseif ($n <= 0xffffffff) {
                        ExifTool::Set32u($n, $outfile->value, $boxPos['iloc'][0] + 8 + $lenPt);
                    } else {
                        $et->Error("Can't yet promote iloc length to 64 bits");
                        return null;
                    }
                    $n = 0;
                }
                if (count($item['Extents']) != 1) {
                    $et->Error("Can't yet handle $name in multiple parts. Please submit sample for testing");
                }
            }
            $et->CHANGED = $changed;   // (will set this later if successful in editing mdat)
        }
        $raf->Seek($curPos, 0);     // seek back to original position

        // add necessary metadata types if they didn't already exist
        $countNew = null;
        $add = $usedID = [];
        foreach (['EXIF', 'XMP'] as $name) {
            if (($did[$name] ?? null) or !($et->ADD_DIRS[$name] ?? null)) continue;
            $missing = [];
            foreach (['iinf', 'iloc'] as $b) {
                if (!($boxPos[$b] ?? null)) $missing[] = $b;
            }
            if ($missing) {
                $str = count($missing) > 1 ? implode(' and ', $missing) . ' boxes' : implode(' ', $missing) . ' box';
                $et->Warn("Can't create $name. Missing expected $str");
                break;
            }
            if ($et->PrimaryItem === null) {
                if ($primary === null) {
                    $et->Warn("Can't create $name. No items to reference");
                    break;
                }
                // add new primary item reference box after hdrl box
                if ($primary < 0x10000) {
                    $add['hdlr'] = Perl::pack('Na4Nn', 14, 'pitm', 0, $primary);
                } else {
                    $add['hdlr'] = Perl::pack('Na4CCCCN', 16, 'pitm', 1, 0, 0, 0, $primary);
                }
                $et->Warn("Added missing PrimaryItemReference (for item $primary)", 1);
            }
            $buff = '';
            $hdr = $subTable = $proc = null;
            if ($name === 'EXIF') {
                $hdr = "\0\0\0\x06Exif\0\0";
                $subTable = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
                $proc = 'Image\ExifTool\ExifTool::WriteTIFF';
            } else {
                $hdr = '';
                $subTable = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
            }
            $subDirInfo = [
                'DataPt'   => $buff,
                'DataLen'  => 0,
                'DirStart' => 0,
                'DirLen'   => 0,
            ];
            $changed = $et->CHANGED;
            $newVal = $et->WriteDirectory($subDirInfo, $subTable, $proc);
            if ($newVal !== null and $changed != $et->CHANGED) {
                $irefVer = null;
                if ($boxPos['iref'] ?? null) {
                    $irefVer = ExifTool::Get8u($outfile->value, $boxPos['iref'][0] + 8);
                } else {
                    // create iref box after end of iinf box (and save version in boxPos list)
                    $irefVer = ($primary < 0x10000 ? 0 : 1);
                    $boxPos['iref'] = [$boxPos['iinf'][0] + $boxPos['iinf'][1], 0, $irefVer];
                }
                if (strlen($hdr)) $newVal = $hdr . $newVal;
                // add new infe to iinf
                if (!isset($add['iinf'])) $add['iinf'] = $add['iref'] = $add['iloc'] = '';
                $type = $mime = null;
                $enc = '';
                if ($name === 'XMP') {
                    $type = "mime\0";
                    $mime = "application/rdf+xml\0";
                    // write compressed XMP if Compress option is set
                    if ($et->Options('Compress') and strlen($newVal)) {
                        if (!function_exists('deflate_init')) {
                            $et->Warn('Install Compress::Zlib to write compressed metadata');
                        } else {
                            $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['memory' => 9]);
                            if ($deflate) {
                                $et->VPrint(0, "  (Deflating new $name metadata)\n");
                                $buf = deflate_add($deflate, $newVal, ZLIB_FINISH);
                                if ($buf !== false) {
                                    $newVal = $buf;
                                    $enc = "deflate\0";
                                }
                            }
                        }
                    }
                } else {
                    $type = "Exif\0";
                    $mime = '';
                }
                $id = ++$lastID; // use next highest available ID (so ID's in iinf are in order)
                //[retracted] # create new item information hash to save infe box in case we need it for sorting
                //[retracted] my $item = $$items{$id} = { };
                // add new infe entry to iinf box
                $n = strlen($type) + strlen($mime) + strlen($enc) + 16;
                if ($id < 0x10000) {
                    $add['iinf'] .= Perl::pack('Na4CCCCnn', $n, 'infe', 2, 0, 0, 1, $id, 0) . $type . $mime . $enc;
                } else {
                    $n += 2;
                    $add['iinf'] .= Perl::pack('Na4CCCCNn', $n, 'infe', 3, 0, 0, 1, $id, 0) . $type . $mime . $enc;
                }
                //[retracted] $add{iinf} .= $$item{infe};
                // add new cdsc to iref (also refer to primary 'tmap' if it exists)
                if ($irefVer) {
                    [$fmt, $siz, $num] = $tmap !== null ? ['N', 22, 2] : ['', 18, 1];
                    $add['iref'] .= Perl::pack('Na4NnN' . $fmt, $siz, 'cdsc', $id, $num, $primary, $tmap);
                } else {
                    [$fmt, $siz, $num] = $tmap !== null ? ['n', 16, 2] : ['', 14, 1];
                    $add['iref'] .= Perl::pack('Na4nnn' . $fmt, $siz, 'cdsc', $id, $num, $primary, $tmap);
                }
                // add new entry to iloc table (see ISO14496-12:2015 pg.79)
                $ilocVer = ExifTool::Get8u($outfile->value, $boxPos['iloc'][0] + 8);
                $siz = ExifTool::Get16u($outfile->value, $boxPos['iloc'][0] + 12);  // get size information
                $noff = ($siz >> 12);
                $nlen = ($siz >> 8) & 0x0f;
                $nbas = ($siz >> 4) & 0x0f;
                $nind = $siz & 0x0f;
                $pbas = $poff = null;
                if ($ilocVer == 0) {
                    // set offset to 0 as flag that this is a new idat chunk being added
                    $pbas = strlen($add['iloc']) + 4;
                    $poff = $pbas + $nbas + 2;
                    $add['iloc'] .= Perl::pack('nn', $id, 0) . self::SetVarInt(0, $nbas) . ExifTool::Set16u(1) .
                                    self::SetVarInt(0, $noff) . self::SetVarInt(strlen($newVal), $nlen);
                } elseif ($ilocVer == 1) {
                    $pbas = strlen($add['iloc']) + 6;
                    $poff = $pbas + $nbas + 2 + $nind;
                    $add['iloc'] .= Perl::pack('nnn', $id, 0, 0) . self::SetVarInt(0, $nbas) . ExifTool::Set16u(1) . self::SetVarInt(0, $nind) .
                                    self::SetVarInt(0, $noff) . self::SetVarInt(strlen($newVal), $nlen);
                } elseif ($ilocVer == 2) {
                    $pbas = strlen($add['iloc']) + 8;
                    $poff = $pbas + $nbas + 2 + $nind;
                    $add['iloc'] .= Perl::pack('Nnn', $id, 0, 0) . self::SetVarInt(0, $nbas) . ExifTool::Set16u(1) . self::SetVarInt(0, $nind) .
                                    self::SetVarInt(0, $noff) . self::SetVarInt(strlen($newVal), $nlen);
                } else {
                    $et->Warn("Can't create $name. Unsupported iloc version $ilocVer");
                    break;
                }
                // add new ChunkOffset entry to update this new offset
                // (Perl: the ChunkOffset array ref is always true when it exists)
                if (!isset($dirInfo['ChunkOffset'])) {
                    $et->Warn('Internal error. Missing ChunkOffset');
                    break;
                }
                $newOff = null;
                if ($noff == 4) {
                    $newOff = ['stco_iloc', $boxPos['iloc'][0] + $boxPos['iloc'][1] + $poff, $noff, 0, $id];
                } elseif ($noff == 8) {
                    $newOff = ['co64_iloc', $boxPos['iloc'][0] + $boxPos['iloc'][1] + $poff, $noff, 0, $id];
                } elseif ($noff == 0) {
                    // offset_size is zero, so store the offset in base_offset instead
                    if ($nbas == 4) {
                        $newOff = ['stco_iloc', $boxPos['iloc'][0] + $boxPos['iloc'][1] + $pbas, $nbas, 0, $id];
                    } elseif ($nbas == 8) {
                        $newOff = ['co64_iloc', $boxPos['iloc'][0] + $boxPos['iloc'][1] + $pbas, $nbas, 0, $id];
                    } else {
                        $et->Warn("Can't create $name. Invalid iloc offset+base size");
                        break;
                    }
                } else {
                    $et->Warn("Can't create $name. Invalid iloc offset size");
                    break;
                }
                // add directory as a new mdat chunk
                $dirInfo['ChunkOffset'][] = $newOff;
                $mdatEdit[] = [0, 0, new ScalarRef($newVal), $id];
                $usedID[$id] = 1;
                $countNew = ($countNew ?: 0) + 1;
                $et->CHANGED = $changed; // set this later if successful in editing mdat
            }
        }
        if ($countNew) {
            // insert new entries into iinf, iref and iloc boxes,
            // and add new pitm box after hdlr if necessary
            $added = 0;
            $tags = array_map('strval', array_keys($boxPos));
            usort($tags, static fn ($a, $b) => $boxPos[$a][0] <=> $boxPos[$b][0]);
            foreach ($tags as $tag) {
                $boxPos[$tag][0] += $added;
                if (!($add[$tag] ?? null)) continue;
                $pos = $boxPos[$tag][0];
                if (!$boxPos[$tag][1]) {
                    if ($tag !== 'iref') {
                        $et->Error('Internal error adding iref box');
                        break;
                    }
                    // create new iref box
                    $add[$tag] = ExifTool::Set32u(12 + strlen($add[$tag])) . $tag .
                                 ExifTool::Set8u($boxPos[$tag][2]) . "\0\0\0" . $add[$tag];
                } elseif ($tag !== 'hdlr') {
                    $n = ExifTool::Get32u($outfile->value, $pos) + strlen($add[$tag]);
                    ExifTool::Set32u($n, $outfile->value, $pos);    // increase box size
                }
                if ($tag === 'iinf') {
                    $iinfVer = ExifTool::Get8u($outfile->value, $pos + 8);
                    if ($iinfVer == 0) {
                        $n = ExifTool::Get16u($outfile->value, $pos + 12) + $countNew;
                        if ($n > 0xffff) {
                            $et->Error("Can't currently handle rollover to long item count");
                            return null;
                        }
                        ExifTool::Set16u($n, $outfile->value, $pos + 12);    // incr count
                    } else {
                        $n = ExifTool::Get32u($outfile->value, $pos + 12) + $countNew;
                        ExifTool::Set32u($n, $outfile->value, $pos + 12);    // incr count
                    }
                } elseif ($tag === 'iref') {
                    // nothing more to do
                } elseif ($tag === 'iloc') {
                    $ilocVer = ExifTool::Get8u($outfile->value, $pos + 8);
                    if ($ilocVer < 2) {
                        $n = ExifTool::Get16u($outfile->value, $pos + 14) + $countNew;
                        ExifTool::Set16u($n, $outfile->value, $pos + 14);    // incr count
                        if ($n > 0xffff) {
                            $et->Error("Can't currently handle rollover to long item count");
                            return null;
                        }
                    } else {
                        $n = ExifTool::Get32u($outfile->value, $pos + 14) + $countNew;
                        ExifTool::Set32u($n, $outfile->value, $pos + 14);    // incr count
                    }
                    // must also update pointer locations in this box
                    if ($added) {
                        foreach ($dirInfo['ChunkOffset'] as &$co) $co[1] += $added;
                        unset($co);
                    }
                } elseif ($tag !== 'hdlr') {
                    continue;
                }
                // add new entries to this box (or add pitm after hdlr)
                $outfile->value = substr_replace($outfile->value, $add[$tag], $pos + $boxPos[$tag][1], 0);
                $boxPos[$tag][1] += strlen($add[$tag]);
                $added += strlen($add[$tag]);    // positions are shifted by length of new entries
            }
        }
        //[This sorting idea was retracted because just sorting 'iinf' wasn't sufficient to
        // repair the problem where an out-of-order ID was added -- Apple Preview still
        // ignores the gain-map image.  It looks like either or both 'iref' and 'iloc' must
        // also be sorted by ID, although the spec doesn't mention this]
        //[retracted] # sort infe entries in iinf box if necessary
        //[retracted] (...)
        unset($et->ItemInfo);
        return $mdatEdit ?: null;
    }

    //------------------------------------------------------------------------------
    // Write a series of QuickTime atoms from file or in memory
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: A) if dirInfo contains DataPt: new directory data
    //          B) otherwise: true on success, 0 if a write error occurred
    //             (true but sets an Error on a file format error)
    // Notes: Yes, this is a real mess.  Just like the QuickTime metadata situation.
    public static function WriteQuickTime(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        self::LoadWriteQuickTime();
        if (!$et) return 1;    // allow dummy access to autoload this package
        $mdat = $edit = $track = $outBuff = $co = $term = $delCount = null;
        $mdats = $mdatEdit = [];    // (Perl @mdat, @mdatEdit)
        $langTags = $boxPos = $didDir = [];
        $canCreate = $delGrp = $writeLast = $err = $atomCount = null;
        $tag = $lastTag = $lastPos = $errStr = $trailer = $buf2 = $keysGrp = $keysPath = $itemIndex = null;
        $outfile = $dirInfo['OutFile'] ?? null;
        if (!$outfile) return 0;
        $raf = $dirInfo['RAF'] ?? null;       // (will be null for lower-level atoms)
        $dataPt = $dirInfo['DataPt'] ?? null; // (will be null for top-level atoms)
        if ($dataPt !== null) $dataPt = Perl::str($dataPt);
        $dirName = $dirInfo['DirName'] ?? null;
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $parent = $dirInfo['Parent'] ?? null;
        $et->ADD_DIRS ??= [];
        $addDirs = &$et->ADD_DIRS;
        // (Perl: a lookup created here if WriteMOV didn't create one)
        if ($et->DidTag !== null) {
            $didTag = &$et->DidTag;
        } else {
            $didTag = [];
        }
        $newTags = [];
        $createKeys = 0;
        [$rtnVal, $rtnErr] = $dataPt !== null ? [null, null] : [1, 0];

        // check for trailer at end of file
        if ($raf) {
            $trailer = self::IdentifyTrailers($raf);
            if ($trailer and !is_array($trailer)) {
                $et->Error($trailer);
                return 1;
            }
        }
        if ($dataPt !== null) {
            $raf = new RandomAccess($dataPt);
        } else {
            if (!$raf) return 0;
        }
        // use buffered output for everything but 'mdat' atoms
        $outBuff = new ScalarRef('');
        $outfile = $outBuff;

        if ($dirStart) $raf->Seek($dirStart, 1);  // skip header if it exists

        $av = Vars\QuickTime::$avType[Perl::str($et->MediaType)] ?? null;
        if ($av) {
            // (note: these won't be correct now if we haven't yet processed the Media box,
            // but in this case they won't be needed until after we set them properly below)
            [$keysGrp, $keysPath] = ["{$av}Keys", 'MOV-Movie-Track'];
        } else {
            [$keysGrp, $keysPath] = ['Keys', 'MOV-Movie'];
        }
        $curPath = implode('-', $et->PATH);
        [$dir, $writePath] = [$dirName, $dirName];
        while (($dir = $et->DirMap[$dir] ?? null) !== null) $writePath = "$dir-$writePath";
        // hack to create Keys directories if necessary (its containing Meta is in a different location)
        if (($addDirs['Keys'] ?? null) and preg_match('/^MOV-Movie(-Meta)?$/', $curPath)) {
            $createKeys = 1;    // create new Keys directories
        } elseif ((($addDirs['AudioKeys'] ?? null) or ($addDirs['VideoKeys'] ?? null)) and preg_match('/^MOV-Movie-Track(-Meta)?$/', $curPath)) {
            $createKeys = -1;   // (must wait until MediaType is known)
        } elseif (($curPath === 'MOV-Movie-Meta-ItemList') or
                 ($curPath === 'MOV-Movie-Track-Meta-ItemList' and $av))
        {
            $createKeys = 2;    // create new Keys tags
            $keys = $et->{$keysGrp};
            if ($keys) {
                // add new tag entries for existing Keys tags, now that we know their ID's
                // - first make lookup to convert Keys tagInfo ref to index number
                $keysInfo = [];
                foreach ($keys['Info'] as $index => $ti) {
                    if ($keys['Remap'][$index] ?? null) $keysInfo[spl_object_id($ti)] = $index;
                }
                $keysTable = ExifTool::GetTagTable("Image::ExifTool::QuickTime::$keysGrp");
                $newKeysTags = $et->GetNewTagInfoHash($keysTable);
                foreach ($newKeysTags as $k => $tagInfo) {
                    $index = ($keysInfo[spl_object_id($tagInfo)] ?? null) ?:
                        ($tagInfo->SrcTagInfo ? ($keysInfo[spl_object_id($tagInfo->SrcTagInfo)] ?? null) : null);
                    if (!$index) continue;
                    $id = ExifTool::Set32u($index);
                    if ($tagInfo->LangCode) {
                        // add to lookup of language tags we are writing with this ID
                        $langTags[$id] ??= [];
                        $langTags[$id][$k] = $tagInfo;
                        $id .= '-' . $tagInfo->LangCode;
                    }
                    $newTags[$id] = $tagInfo;
                }
            }
        } else {
            // get hash of new tags to edit/create in this directory
            $newTags = $et->GetNewTagInfoHash($tagTablePtr);
            // make lookup of language tags for each ID
            foreach ($newTags as $k => $ti) {
                if (!($ti->LangCode and $ti->SrcTagInfo)) continue;
                $id = $ti->SrcTagInfo->TagID;
                $langTags[$id] ??= [];
                $langTags[$id][$k] = $ti;
            }
        }
        if ($curPath === $writePath or $createKeys) {
            $canCreate = 1;
            // (must check the appropriate Keys delete flag if this is a Keys ItemList)
            $delGrp = $et->DEL_GROUP[$createKeys ? $keysGrp : $dirName] ?? null;
        }
        if ($tagTablePtr['VARS']) $atomCount = $tagTablePtr['VARS']['ATOM_COUNT'] ?? null;

        $tag = $lastTag = '';
        if ($dirName === 'ItemPropertyContainer') $itemIndex = 0;

        // read ahead to parse item property associations if this is 'iprp' ItemProperties
        // (necessary to determine which properties belong to primary item in HEIC file)
        if ($dirName === 'ItemProperties') {
            $pos = $raf->Tell();
            for (;;) {
                if ($raf->Read($buf2, 8) != 8) break;
                $size = ExifTool::Get32u($buf2, 0) - 8;    // (atom size without 8-byte header)
                $tag = substr($buf2, 4, 4);
                if ($size < 0) break;
                if ($tag !== 'ipma') {
                    $raf->Seek($size, 1);
                    continue;
                }
                if ($raf->Read($buf2, $size) == $size) self::ParseItemPropAssoc($buf2, $et);
                break;
            }
            $raf->Seek($pos);
        }
        for (;;) {      // loop through all atoms at this level
            if ($itemIndex !== null) ++$itemIndex;
            $lastPos = $raf->Tell();
            // stop processing if we reached a known trailer
            if ($trailer and $lastPos >= $trailer[1]) {
                if ($lastPos != $trailer[1]) $errStr = "Corrupted {$trailer[0]} trailer";
                break;
            }
            if ($tagTablePtr[$tag]) $lastTag = $tag;    // keep track of last known tag
            if ($atomCount !== null and --$atomCount < 0 and $dataPt !== null) {
                // stop processing now and just copy the rest of the atom
                if (!ExifTool::Write($outfile, substr($dataPt, $raf->Tell()))) {
                    $rtnVal = $rtnErr;
                    $err = 1;
                }
                break;
            }
            $hdr = $buff = $keysIndex = null;
            $n = $raf->Read($hdr, 8);
            if ($n != 8) {
                if ($n == 4 and $hdr === "\0\0\0\0") {
                    // "for historical reasons" the udta is optionally terminated by 4 zeros (ref 1)
                    // --> hold this terminator to the end
                    $term = $hdr;
                } elseif ($n != 0) {
                    // warn unless this is 1-3 pad bytes
                    if ($n > 3 or $hdr !== str_repeat("\0", $n)) $et->Error("Unknown $n bytes at end of file", 1);
                }
                break;
            }
            $size = ExifTool::Get32u($hdr, 0) - 8;    // (atom size without 8-byte header)
            $tag = substr($hdr, 4, 4);
            if ($size == -7) {
                // read the extended size
                if ($raf->Read($buff, 8) != 8) {
                    $errStr = 'Truncated extended atom';
                    break;
                }
                $hdr .= $buff;
                [$hi, $lo] = Perl::unpack('NN', $buff);
                if ($hi or $lo > 0x7fffffff) {
                    if ($hi > 0x7fffffff) {
                        $errStr = 'Invalid atom size';
                        break;
                    } elseif (!$et->Options('LargeFileSupport')) {
                        $et->Error('End of processing at large atom (LargeFileSupport not enabled)');
                        break;
                    } elseif (Perl::str($et->Options('LargeFileSupport')) === '2') {
                        $et->Warn('Processing large atom (LargeFileSupport is 2)');
                    }
                }
                $size = $hi * 4294967296 + $lo - 16;
                if ($size < 0) {
                    $errStr = 'Invalid extended atom size';
                    break;
                }
            } elseif ($size == -8) {
                if ($dataPt !== null) {
                    if ($dirInfo['DirName'] === 'CanonCNTH') break;  // (this is normal for Canon CNTH atom)
                    $pos = $raf->Tell() - 4;
                    $raf->Seek(0, 2);
                    $str = $dirInfo['DirName'] . ' with ' . ($raf->Tell() - $pos) . ' bytes';
                    $et->Error("Terminator found in $str remaining", 1);
                } else {
                    // size of zero is only valid for top-level atom, and
                    // indicates the atom extends to the end of file
                    // (save in mdat list to write later; with zero end position to copy rest of file)
                    $mdats[] = new \ArrayObject([$raf->Tell(), 0, $hdr]);
                }
                break;
            } elseif ($size < 0) {
                $errStr = 'Invalid atom size';
                break;
            }

            // keep track of 'mdat' atom locations for writing later
            if ($tag === 'mdat') {
                if ($dataPt !== null) {
                    $et->Error("'mdat' not at top level");
                    break;
                }
                $mdats[] = new \ArrayObject([$raf->Tell(), $raf->Tell() + $size, $hdr]);
                if (!$raf->Seek($size, 1)) {
                    $et->Error('Seek error in mdat atom');
                    return $rtnVal;
                }
                continue;
            } elseif ($tag === 'cmov') {
                $et->Error("Can't yet write compressed movie metadata");
                return $rtnVal;
            } elseif ($tag === 'wide') {
                if ($size) {
                    $et->Warn("Incorrect size for 'wide' atom ($size bytes)");
                    if (!$raf->Seek($size, 1)) $et->Error('Truncated wide atom');
                }
                continue;   // drop 'wide' tag
            }

            // read the atom data
            $got = null;
            if (!$size) {
                $buff = '';
                $got = 0;
            } else {
                // read the atom data (but only first 64kB if data is huge)
                $got = $raf->Read($buff, $size > self::MAX_READ_LEN ? 0x10000 : $size);
            }
            if ($got != $size) {
                // ignore up to 256 bytes of garbage at end of file
                $type = null;
                if (($got <= 256 and $size >= 1024 and $tag !== 'mdat') or
                    ($got < 3000 and preg_match('/^<b[r>]/', Perl::pack('N', $size)) and ($type = 'extraneous HTML')))
                {
                    $bytes = $got + strlen($hdr);
                    if (!$type) $type = 'garbage';
                    if ($et->OPTIONS['IgnoreMinorErrors']) {
                        $et->Warn("Deleted $type at end of file ($bytes bytes)");
                        $buff = $hdr = '';
                    } else {
                        $et->Error("Possible $type at end of file ($bytes bytes)", 1);
                        return $rtnVal;
                    }
                } else {
                    $tag = self::PrintableTagID($tag, 3);
                    if ($size > self::MAX_READ_LEN and $got == 0x10000) {
                        $mb = Perl::int($size / 0x100000 + 0.5);
                        $errStr = "'{$tag}' atom is too large for rewriting ($mb MB)";
                    } else {
                        $errStr = "Truncated '{$tag}' atom";
                    }
                    break;
                }
            }
            // save the handler type of the track media
            if ($tag === 'hdlr' and strlen($buff) >= 12 and
                $et->PATH and $et->PATH[count($et->PATH) - 1] === 'Media')
            {
                $et->MediaType = substr($buff, 8, 4);
            }
            // if this atom stores offsets, save its location so we can fix up offsets later
            // (are there any other atoms that may store absolute file offsets?)
            if (preg_match('/^(stco|co64|iloc|mfra|moof|sidx|saio|gps |CTBO|uuid)$/', $tag)) {
                // (note that we only need to do this if the media data is stored in this file)
                $flg = $et->QtDataFlg;
                if ($tag === 'mfra' or $tag === 'moof') {
                    $et->Error("Can't yet handle movie fragments when writing");
                    return $rtnVal;
                } elseif ($tag === 'sidx' or $tag === 'saio') {
                    $et->Error("Can't yet handle $tag box when writing");
                    return $rtnVal;
                } elseif ($tag === 'iloc') {
                    if (!self::Handle_iloc($et, $dirInfo, $buff, $outfile)) $et->Error('Error parsing iloc atom');
                } elseif ($tag === 'gps ') {
                    // (only care about the 'gps ' box in 'moov')
                    if (($dirInfo['DirID'] ?? null) and $dirInfo['DirID'] === 'moov' and strlen($buff) > 8) {
                        $num = ExifTool::Get32u($buff, 4);
                        if ($num * 8 + 8 > strlen($buff)) $num = Perl::int((strlen($buff) - 8) / 8);
                        for ($i = 0; $i < $num; ++$i) {
                            $dirInfo['ChunkOffset'][] = ['stco_gps ', strlen($outfile->value) + strlen($hdr) + 8 + $i * 8, 4];
                        }
                    }
                } elseif ($tag === 'CTBO' or $tag === 'uuid') { // hack for updating CR3 CTBO offsets
                    $dirInfo['ChunkOffset'][] = [$tag, strlen($outfile->value), strlen($hdr) + $size];
                } elseif (!$flg or $flg == 1) {
                    // assume "1" if stsd is yet to be read
                    if (!$flg) $et->AssumedDataRef = 1;
                    // must update offsets since the data is in this file
                    $dirInfo['ChunkOffset'][] = [$tag, strlen($outfile->value) + strlen($hdr), $size];
                } elseif ($flg == 3) {
                    $et->Error("Can't write files with mixed internal/external media data");
                    return $rtnVal;
                }
            }

            // rewrite this atom
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag, $buff);

            // call write hook if it exists
            if ($tagInfo and $tagInfo->WriteHook) {
                $hook = $tagInfo->WriteHook;
                $hook($buff, $et);
            }

            // allow numerical tag ID's (ItemList entries defined by Keys)
            if (!$tagInfo and $dirName === 'ItemList' and $et->{$keysGrp}) {
                $keysIndex = Perl::unpack('N', $tag)[0];
                $newIndex = $et->{$keysGrp}['Remap'][$keysIndex] ?? null;
                if ($newIndex !== null) {
                    $tagInfo = $et->{$keysGrp}['Info'][$keysIndex] ?? null;
                    if (!$newIndex) {
                        if ($tagInfo) {
                            $et->VPrint(1, "    - Keys:{$tagInfo->Name}");
                        } else {
                            $delCount = ($delCount ?: 0) + 1;
                        }
                        ++$et->CHANGED;
                        continue;
                    }
                    // use the new Keys index of this item if it changed
                    if ($keysIndex != $newIndex) {
                        $tag = ExifTool::Set32u($newIndex);
                        $hdr = substr_replace($hdr, $tag, 4, 4);
                    }
                } else {
                    $keysIndex = null;
                }
            }
            // delete all ItemList tags when deleting group, but take care not to delete UserData Meta
            if ($delGrp) {
                if ($dirName === 'ItemList') {
                    $delCount = ($delCount ?: 0) + 1;
                    ++$et->CHANGED;
                    continue;
                } elseif ($dirName === 'UserData' and (!$tagInfo or !$tagInfo->SubDirectory)) {
                    $delCount = ($delCount ?: 0) + 1;
                    ++$et->CHANGED;
                    continue;
                }
            }
            if ($tagInfo and $tagInfo->AddedUnknown) $tagInfo = null;

            // don't write this tag unless associated with the primary item
            // (Note: This relies on iinf and dimg coming before iprp)
            if ($itemIndex !== null and $et->ItemInfo) {
                $items = $et->ItemInfo;
                $isPrimary = null;
                $primary = $et->PrimaryItem;
                if ($primary === null) {
                    if ($et->ItemInfo) $primary = min(array_keys($et->ItemInfo));
                    if ($primary === null) $primary = 0;
                }
                $pitem = $items[$primary] ?? [];
                $ids = array_keys($items);
                rsort($ids);
                foreach ($ids as $id) {      // ItemID2:
                    if (!($items[$id]['Association'] ?? null)) continue;
                    $item = $items[$id];
                    foreach ($item['Association'] as $prop) {
                        if ($prop != $itemIndex) continue;
                        $dont = Vars\QuickTime::$dontInherit[$tag] ?? 0;
                        if (!($id == $primary or (!$dont and
                            (($item['RefersTo'] ?? null) and ($item['RefersTo'][$primary] ?? null))) or
                            // special case to assume this property belongs to the primary
                            // image if it belongs to an item referred to by the primary
                            ($dont != 1 and ($pitem['RefersTo'][$id] ?? null)))) break;
                        $isPrimary = 1;
                        break 2;
                    }
                }
                if (!$isPrimary) $tagInfo = null;
            }

            if ($tagInfo and ($tagInfo->Writable === null or $tagInfo->Writable)) {
                $subdir = $tagInfo->SubDirectory;
                $newData = null;
                $chunkOffset = [];

                if ($subdir) {  // process atoms in this container from a buffer in memory

                    if ($tag === 'trak') {
                        $et->MediaType = '';  // init media type for this track
                        unset($et->AssumedDataRef);
                    }
                    $subName = ($subdir['DirName'] ?? null) ?: $tagInfo->Name;
                    $start = ($subdir['Start'] ?? null) ?: 0;
                    $base = (($dirInfo['Base'] ?? null) ?: 0) + $raf->Tell() - $size;
                    $dPos = 0;
                    $hdrLen = $start;
                    // handle case where known trailer is the payload of a "DontRead" box (eg. 'inst')
                    if ($tagInfo->DontRead and $trailer and $base == $trailer[1]) $trailer = $trailer[3];
                    if ($subdir['Base'] ?? null) {
                        $localBase = Perl::num(Exif::EvalCode($subdir['Base'], $start, $base));
                        $dPos -= $localBase;
                        $base -= $dPos;
                        // get length of header before base offset
                        if ($localBase <= $hdrLen) $hdrLen -= $localBase;
                    }
                    $subdirInfo = [
                        'Parent'   => $dirName,
                        'DirName'  => $subName,
                        'Name'     => $tagInfo->Name,
                        'TagInfo'  => $tagInfo,
                        'DirID'    => $tag,
                        'DataPt'   => $buff,
                        'DataLen'  => $size,
                        'DataPos'  => $dPos,
                        'DirStart' => $start,
                        'DirLen'   => $size - $start,
                        'Base'     => $base,
                        'HasData'  => $subdir['HasData'] ?? null,
                        'Multi'    => $subdir['Multi'] ?? null,    // necessary?
                        'OutFile'  => $outfile,
                        'NoRefTest' => 1,     // don't check directory references
                        'WriteGroup' => $tagInfo->WriteGroup,
                        'Permanent' => $tagInfo->Permanent,
                        // initialize array to hold details about chunk offset table
                        // (each entry has 3-5 items: 0=atom type, 1=table offset, 2=table size,
                        //  3=optional base offset, 4=optional item ID)
                        'ChunkOffset' => [],
                    ];
                    // set InPlace flag so XMP will be padded properly when
                    // QuickTimePad is used if this is an XMP directory
                    if ($et->Options('QuickTimePad')) $subdirInfo['InPlace'] = 2;
                    // pass the header pointer if necessary (for EXIF IFD's
                    // where the Base offset is at the end of the header)
                    if ($hdrLen and $hdrLen < $size) {
                        $header = substr($buff, 0, $hdrLen);
                        $subdirInfo['HeaderPtr'] = new ScalarRef($header);
                    }
                    if (($subdir['ByteOrder'] ?? null) and preg_match('/^Little/', $subdir['ByteOrder'])) ExifTool::SetByteOrder('II');
                    $oldWriteGroup = $et->CUR_WRITE_GROUP;
                    if ($subName === 'Track') {
                        if (!$track) $track = 0;
                        $et->CUR_WRITE_GROUP = 'Track' . (++$track);
                    }
                    $subTable = ExifTool::GetTagTable($subdir['TagTable'] ?? null);
                    // demote non-QuickTime errors to warnings
                    if (Perl::str($subTable ? $subTable['GROUPS'][0] : null) !== 'QuickTime') $et->DemoteErrors = 1;
                    $oldChanged = $et->CHANGED;
                    $newData = $et->WriteDirectory($subdirInfo, $subTable, $subdir['WriteProc'] ?? null);
                    $chunkOffset = $subdirInfo['ChunkOffset'] ?? [];
                    if ($et->DemoteErrors) {
                        // just copy existing subdirectory if a non-quicktime error occurred
                        if ($et->DemoteErrors > 1) $et->CHANGED = $oldChanged;
                        unset($et->DemoteErrors);
                    }
                    if ($newData !== null and !strlen($newData) and ($tagInfo->Permanent or
                        ($tagTablePtr['PERMANENT'] and $tagInfo->Permanent === null)))
                    {
                        // do nothing if trying to delete tag from a PERMANENT table
                        $et->CHANGED = $oldChanged;
                        $newData = null;
                    }
                    if ($tag === 'trak') {
                        $et->MediaType = '';     // reset media type at end of track
                        if ($et->AssumedDataRef) {
                            $grp = $et->CUR_WRITE_GROUP ?: $dirName;
                            $et->Error("Can't locate data reference to update offsets for $grp");
                            unset($et->AssumedDataRef);
                        }
                    }
                    $et->CUR_WRITE_GROUP = $oldWriteGroup;
                    ExifTool::SetByteOrder('MM');
                    // add back header if necessary
                    if ($start and $newData !== null and (strlen($newData) or
                        ($tagInfo->Permanent !== null and !$tagInfo->Permanent)))
                    {
                        $newData = substr($buff, 0, $start) . $newData;
                        foreach ($chunkOffset as &$co) $co[1] += $start;
                        unset($co);
                    }
                    // the directory exists, so we don't need to add it
                    if ($curPath === $writePath and ($addDirs[$subName] ?? null) and $addDirs[$subName] === $dirName) {
                        unset($addDirs[$subName]);
                    }
                    $didDir[$tag] = 1; // (note: keyed by tag ID)

                } else {    // modify existing QuickTime tags in various formats

                    $nvHash = $et->GetNewValueHash($tagInfo);
                    if ($nvHash or ($langTags[$tag] ?? null) or $delGrp) {
                        $nvHashNoLang = $nvHash;
                        $val = $len = $lang = $type = $flags = $ctry = $charsetQuickTime = null;
                        $format = $tagInfo->Format;
                        $hasData = (($dirInfo['HasData'] ?? null) and preg_match('/\0...data\0/s', $buff));
                        $langInfo = $tagInfo;
                        if ($hasData) {
                            $pos = 0;
                            for (;; $pos += $len) {
                                if ($pos + 16 > $size) {
                                    // add any new alternate language tags now
                                    if ($langTags[$tag] ?? null) {
                                        foreach (['', ...self::SortedKeys($langTags[$tag])] as $tg) {
                                            $ti = $tg !== '' ? $langTags[$tag][$tg] : $nvHashNoLang;
                                            // (Perl looks up the new value hash of $nvHashNoLang, which
                                            //  is never a key of NEW_VALUE, so nothing is done for '')
                                            $nvHash = $ti instanceof TagInfo ? $et->GetNewValueHash($ti) : null;
                                            if (!($nvHash and !($didTag[spl_object_id($nvHash)] ?? null))) continue;
                                            $didTag[spl_object_id($nvHash)] = 1;
                                            if (!($nvHash->IsCreating and $et->IsOverwriting($nvHash))) continue;
                                            $newVal = $et->GetNewValue($nvHash);
                                            if ($newVal === null) continue;
                                            $prVal = $newVal;
                                            $flags2 = self::FormatQTValue($et, $newVal, $tagInfo, $format);
                                            if ($newVal === null) continue;
                                            [$ctry2, $lang2] = [0, 0];
                                            if ($ti->LangCode) {
                                                if (!preg_match('/^([A-Z]{3})?[-_]?([A-Z]{2})?$/i', Perl::str($ti->LangCode), $m)) {
                                                    $et->Warn("Invalid language code for {$ti->Name}");
                                                    continue;
                                                }
                                                // pack language and country codes
                                                if (($m[1] ?? '') and $m[1] !== 'und') {
                                                    foreach (unpack('C*', strtolower($m[1])) as $c) $lang2 = ($lang2 << 5) | ($c - 0x60);
                                                }
                                                if (($m[2] ?? '') and $m[2] !== 'ZZ') $ctry2 = Perl::unpack('n', Perl::pack('a2', strtoupper($m[2])))[0];
                                            }
                                            if ($newData === null) $newData = substr($buff, 0, $pos);
                                            $newData .= Perl::pack('Na4Nnn', 16 + strlen($newVal), 'data', $flags2, $ctry2, $lang2) . $newVal;
                                            $grp = $et->GetGroup($ti, 1);
                                            $et->VerboseValue("+ $grp:{$ti->Name}", $prVal);
                                            ++$et->CHANGED;
                                        }
                                    }
                                    break;
                                }
                                [$len, $type, $flags, $ctry, $lang] = Perl::unpack("x{$pos}Na4Nnn", $buff);
                                if (!$lang) $lang = self::UND_LANG;  // treat both 0 and 'und' as 'und'
                                $langInfo = $tagInfo;
                                $delTag = $delGrp;
                                $newVal = null;
                                $langCode = self::GetLangCode($lang, $ctry, 1);
                                for (;;) {
                                    $langInfo = self::GetLangInfo($tagInfo, $langCode);
                                    $nvHash = $et->GetNewValueHash($langInfo);
                                    if ($nvHash or !$ctry or $lang != self::UND_LANG or strlen(Perl::str($langCode)) == 2) break;
                                    // check to see if tag was written with a 2-char country code only
                                    $langCode = strtolower(Perl::unpack('a2', Perl::pack('n', $ctry))[0]);
                                }
                                // set flag to delete language tag when writing default
                                // (except for a default-language Keys entry)
                                if (!$nvHash and $nvHashNoLang) {
                                    if ($lang == self::UND_LANG and !$ctry and !($didTag[spl_object_id($nvHashNoLang)] ?? null)) {
                                        $nvHash = $nvHashNoLang;    // write existing default
                                    } else {
                                        $delTag = 1;    // delete tag
                                    }
                                }
                                if ($pos + $len > $size) break;
                                if ($type === 'data' and $len >= 16) {
                                    $pos += 16;
                                    $len -= 16;
                                    $val = substr($buff, $pos, $len);
                                    // decode value (see QuickTime.pm for an explanation)
                                    if (Vars\QuickTime::$stringEncoding[$flags] ?? null) {
                                        $val = $et->Decode($val, Vars\QuickTime::$stringEncoding[$flags]);
                                        if (!$tagInfo->Binary) $val = preg_replace('/\0$/', '', Perl::str($val), 1);
                                        $flags = 0x01;  // write all strings as UTF-8
                                    } else {
                                        if ($format) {
                                            // update flags for the format we are writing
                                            if ($tagInfo->Writable and (self::QT_FORMAT[Perl::str($tagInfo->Writable)] ?? null)) {
                                                $flags = self::QT_FORMAT[Perl::str($tagInfo->Writable)];
                                            } elseif (self::QT_FORMAT[$format] ?? null) {
                                                $flags = self::QT_FORMAT[$format];
                                            }
                                        } else {
                                            $format = self::QuickTimeFormat($flags, $len);
                                        }
                                        if ($format) $val = ExifTool::ReadValue($val, 0, $format, $tagInfo->Count, $len);
                                    }
                                    if (($nvHash and $et->IsOverwriting($nvHash, $val)) or $delTag) {
                                        if ($nvHash !== null) $newVal = $et->GetNewValue($nvHash);
                                        if ($delTag or $newVal === null or ($didTag[spl_object_id($nvHash)] ?? null)) {
                                            // delete the tag
                                            $grp = $et->GetGroup($langInfo, 1);
                                            $et->VerboseValue("- $grp:{$langInfo?->Name}", $val);
                                            // copy data up to start of this tag to delete this value
                                            if ($newData === null) $newData = substr($buff, 0, $pos - 16);
                                            ++$et->CHANGED;
                                            continue;
                                        }
                                        $prVal = $newVal;
                                        // format new value for writing (and get new flags)
                                        $flags = self::FormatQTValue($et, $newVal, $tagInfo, $format);
                                        if ($newVal === null) continue;
                                        $grp = $et->GetGroup($langInfo, 1);
                                        $et->VerboseValue("- $grp:{$langInfo?->Name}", $val);
                                        $et->VerboseValue("+ $grp:{$langInfo?->Name}", $prVal);
                                        if ($newData === null) $newData = substr($buff, 0, $pos - 16);
                                        $wLang = $lang == self::UND_LANG ? 0 : $lang;
                                        $newData .= Perl::pack('Na4Nnn', strlen($newVal) + 16, $type, $flags, $ctry, $wLang);
                                        $newData .= $newVal;
                                        ++$et->CHANGED;
                                    } elseif ($newData !== null) {
                                        $newData .= substr($buff, $pos - 16, $len + 16);
                                    }
                                } elseif ($newData !== null) {
                                    $newData .= substr($buff, $pos, $len);
                                }
                                if ($nvHash) $didTag[spl_object_id($nvHash)] = 1;
                            }
                            if ($newData !== null and $pos < $size) $newData .= substr($buff, $pos);
                            $val = null; // (already constructed $newData)
                        } elseif ($format) {
                            $val = ExifTool::ReadValue($buff, 0, $format, null, $size);
                        } elseif ((preg_match('/^\xa9/', $tag) or $tagInfo->IText) and $size >= ($tagInfo->IText ?: 4)) {
                            $iHdr = null;   // (Perl my $hdr)
                            if ($tagInfo->IText and $tagInfo->IText >= 6) {
                                $iText = $tagInfo->IText;
                                $p = $iText - 2;
                                $lang = Perl::unpack("x{$p}n", $buff)[0];
                                $iHdr = substr($buff, 4, $iText - 6);
                                $len = $size - $iText;
                                $val = substr($buff, $iText, $len);
                            } else {
                                [$len, $lang] = Perl::unpack('nn', $buff);
                                if (4 + $len > $size) $len -= 4; // (see QuickTime.pm for explanation)
                                if ($len > $size - 4 or $len < 0) $len = $size - 4;
                                $val = substr($buff, 4, $len);
                            }
                            if (!$lang) $lang = self::UND_LANG;  // treat both 0 and 'und' as 'und'
                            $enc = null;
                            if ($lang < 0x400 and !preg_match('/^\xfe\xff/', $val)) {
                                $charsetQuickTime = $et->Options('CharsetQuickTime');
                                $enc = $charsetQuickTime;
                            } else {
                                $val = preg_replace('/^\xfe\xff/', '', $val, 1, $cnt);
                                $enc = $cnt ? 'UTF16' : 'UTF8';
                            }
                            if (!$tagInfo->NoDecode) {
                                $val = $et->Decode($val, $enc);
                                $val = preg_replace('/\0+$/', '', Perl::str($val), 1);   // remove trailing nulls if they exist
                            }
                            if ($iHdr !== null) $val = $iHdr . $val;
                            $langCode = self::UnpackLang($lang, 1);
                            $langInfo = self::GetLangInfo($tagInfo, $langCode);
                            $nvHash = $et->GetNewValueHash($langInfo);
                            if (!$nvHash and $nvHashNoLang) {
                                if ($lang == self::UND_LANG and !($didTag[spl_object_id($nvHashNoLang)] ?? null)) {
                                    $nvHash = $nvHashNoLang;
                                } elseif ($canCreate) {
                                    // delete other languages when writing default
                                    $grp = $et->GetGroup($langInfo, 1);
                                    $et->VerboseValue("- $grp:{$langInfo?->Name}", $val);
                                    ++$et->CHANGED;
                                    continue;
                                }
                            }
                        } else {
                            $val = $buff;
                            if (preg_match('/^\xa9/', $tag) or $tagInfo->IText) {
                                $et->Warn("Corrupted {$tagInfo->Name} value");
                            }
                        }
                        if ($nvHash and $val !== null) {
                            if ($et->IsOverwriting($nvHash, $val)) {
                                $newData = $et->GetNewValue($nvHash);
                                if (!($newData !== null or $canCreate)) $newData = '';
                                ++$et->CHANGED;
                                $grp = $et->GetGroup($langInfo, 1);
                                $et->VerboseValue("- $grp:{$langInfo?->Name}", $val);
                                if (!($newData !== null and !($didTag[spl_object_id($nvHash)] ?? null))) {
                                    // must not delete items from iprp because it will mess up the ordering
                                    if ($itemIndex === null) continue;
                                }
                                $et->VerboseValue("+ $grp:{$langInfo?->Name}", $newData);
                                // add back necessary header and encode as necessary
                                if ($lang !== null) {
                                    $iText = $tagInfo->IText ?: 0;
                                    $iHdr = null;
                                    if ($iText > 6) {
                                        if (strlen(Perl::str($newData)) < $iText - 6) $newData .= str_repeat(' ', $iText - 6);
                                        $iHdr = substr(Perl::str($newData), 0, $iText - 6);
                                        $newData = substr(Perl::str($newData), $iText - 6);
                                    }
                                    if (!$tagInfo->NoDecode) {
                                        $newData = $et->Encode($newData, $lang < 0x400 ? $charsetQuickTime : 'UTF8');
                                    }
                                    $wLang = $lang == self::UND_LANG ? 0 : $lang;
                                    if ($iText < 6) {
                                        $newData = Perl::pack('nn', strlen(Perl::str($newData)), $wLang) . $newData;
                                    } elseif ($iText == 6) {
                                        $newData = Perl::pack('Nn', 0, $wLang) . $newData . "\0";
                                    } else {
                                        $newData = "\0\0\0\0" . $iHdr . Perl::pack('n', $wLang) . $newData . "\0";
                                    }
                                } elseif (!$format or (preg_match('/^string/', $format) and
                                         !$tagInfo->Binary and !$tagInfo->ValueConv))
                                {
                                    // write all strings as UTF-8
                                    $newData = $et->Encode($newData, 'UTF8');
                                } elseif ($format and !$tagInfo->Binary) {
                                    // format new value for writing
                                    $newData = ExifTool::WriteValue($newData, $format, $tagInfo->Count);
                                }
                            }
                            $didTag[spl_object_id($nvHash)] = 1;   // set flag so we don't add this tag again
                        }
                    }
                }
                // write the new atom if it was modified
                if ($newData !== null) {
                    $newData = Perl::str($newData);
                    $sizeDiff = strlen($buff) - strlen($newData);
                    // pad to original size if specified, otherwise give verbose message about the changed size
                    if ($sizeDiff > 0 and $tagInfo->PreservePadding and $et->Options('QuickTimePad')) {
                        $newData .= str_repeat("\0", $sizeDiff);
                        $et->VPrint(1, "    ({$tagInfo->Name} padded to original size)");
                    } elseif ($sizeDiff) {
                        $et->VPrint(1, "    ({$tagInfo->Name} changed size)");
                    }
                    $len = strlen($newData) + 8;
                    if ($len > 0x7fffffff) {
                        $et->Error("{$tagInfo->Name} to large to write");
                        break;
                    }
                    // update size in ChunkOffset list for modified 'uuid' atom
                    if ($tag === 'uuid') $dirInfo['ChunkOffset'][array_key_last($dirInfo['ChunkOffset'])][2] = $len;
                    if (!($len > 8)) continue;   // don't write empty atom header
                    // maintain pointer to chunk offsets if necessary
                    if ($chunkOffset) {
                        foreach ($chunkOffset as &$co) $co[1] += 8 + strlen($outfile->value);
                        unset($co);
                        array_push($dirInfo['ChunkOffset'], ...$chunkOffset);
                    }
                    if ($tagInfo->WriteLast) {
                        $writeLast = ($writeLast ?: '') . ExifTool::Set32u($len) . $tag . $newData;
                    } else {
                        $boxPos[$tag] = [strlen($outfile->value), strlen($newData) + 8];
                        // write the updated directory with its atom header
                        if (!ExifTool::Write($outfile, ExifTool::Set32u($len), $tag, $newData)) {
                            $rtnVal = $rtnErr;
                            $err = 1;
                            break;
                        }
                    }
                    continue;
                }
            }
            // keep track of data references in this track
            if ($tag === 'dinf') {
                $et->QtDataRef = [];  // initialize list of data references
            } elseif ($parent === 'DataInfo' and strlen($buff) >= 4) {
                // save data reference type and version/flags
                $et->QtDataRef[] = [$tag, ExifTool::Get32u($buff, 0)];
            } elseif ($tag === 'stsd' and strlen($buff) >= 8) {
                $n = ExifTool::Get32u($buff, 4);      // get number of sample descriptions in table
                [$pos, $flg] = [8, 0];
                $msg = null;
                for ($i = 0; $i < $n; ++$i) {       // loop through sample descriptions
                    if (!($pos + 16 <= strlen($buff))) {
                        $msg = 'Truncated sample table';
                        break;
                    }
                    $siz = ExifTool::Get32u($buff, $pos);
                    if (!($pos + $siz <= strlen($buff))) {
                        $msg = 'Truncated sample table';
                        break;
                    }
                    $drefIdx = ExifTool::Get16u($buff, $pos + 14);
                    $drefTbl = $et->QtDataRef;
                    if (!$drefIdx) {
                        $flg |= 0x01;   // in this file if data reference index is 0 (if like iloc)
                    } elseif ($drefTbl and ($drefTbl[$drefIdx - 1] ?? null)) {
                        $dref = $drefTbl[$drefIdx - 1];
                        // $flg = 0x01-in this file, 0x02-in some other file
                        $flg |= ($dref[1] == 1 and $dref[0] !== 'rsrc') ? 0x01 : 0x02;
                    } else {
                        $msg = "No data reference for sample description $i";
                        break;
                    }
                    $pos += $siz;
                }
                if ($msg) {
                    // (allow empty sample description for non-audio/video handler types, eg. 'url ', 'meta')
                    // (also, incorrectly written 'mett' SampleEntry by Google phones,
                    //  see https://exiftool.org/forum/index.php?msg=91158)
                    if (Vars\QuickTime::$avType[Perl::str($et->MediaType)] ?? null) {
                        $grp = $et->CUR_WRITE_GROUP ?: $parent;
                        $et->Error("$msg for $grp");
                        return $rtnErr;
                    }
                    $flg = 1; // (this seems to be the case)
                }
                $et->QtDataFlg = $flg;
                if ($et->AssumedDataRef) {
                    if ($flg != $et->AssumedDataRef) {
                        $grp = $et->CUR_WRITE_GROUP ?: $parent;
                        $et->Error("Assumed incorrect data reference for $grp (was $flg)");
                    }
                    unset($et->AssumedDataRef);
                }
            }
            if ($tagInfo and $tagInfo->WriteLast) {
                $writeLast = ($writeLast ?: '') . $hdr . $buff;
            } else {
                // save position of this box in the output buffer
//TODO do this:
//TODO            my $bp = $boxPos{$tag} || ($boxPos{$tag} = [ ]);
//TODO            push @$bp, length($$outfile), length($hdr) + length($buff);
//TODO instead of this:
                $boxPos[$tag] = [strlen($outfile->value), strlen($hdr) + strlen($buff)];
//TODO then we have the positions of all the infe boxes -- we then only need
//TODO to know the index of the box to edit if the encoding changes for one of them
                // copy the existing atom
                if (!ExifTool::Write($outfile, $hdr, $buff)) {
                    $rtnVal = $rtnErr;
                    $err = 1;
                    break;
                }
            }
        }
        // ($errStr is set if there was an error that could possibly be due to an unknown trailer)
        if ($errStr) {
            $tagEntry = $tagTablePtr[$tag];
            if (($lastTag === 'mdat' or $lastTag === 'moov') and $dataPt === null and (!$tagEntry or
                ($tagEntry instanceof TagInfo and $tagEntry->Unknown)))
            {
                // identify other known trailers from their first bytes
                $buf2 = '';
                if ($raf->Seek($lastPos, 0)) $raf->Read($buf2, 8);
                $type = $len = null;
                if ($buf2 === 'CCCCCCCC') {
                    $type = 'Kenwood';
                } elseif (preg_match('/^(gpsa|gps0|gsen|gsea)...\0/s', $buf2)) {
                    $type = 'RIFF';
                } else {
                    $type = 'Unknown';
                }
                // determine length of this trailer
                if ($trailer) {
                    $len = $trailer[1] - $lastPos; // runs to start of next trailer
                } else {
                    if (!$raf->Seek(0, 2)) {
                        $et->Error('Seek error');
                        return $dataPt !== null ? null : 1;
                    }
                    $len = $raf->Tell() - $lastPos; // runs to end of file
                }
                // add to start of linked list of trailers
                $trailer = [$type, $lastPos, $len, $trailer];
            } else {
                $et->Error($errStr);
                return $dataPt !== null ? null : 1;
            }
        }
        if ($delCount) $et->VPrint(0, "  [deleting $delCount $dirName tag" . ($delCount == 1 ? '' : 's') . "]\n");

        // can finally set necessary variables for creating Video/AudioKeys tags
        if ($createKeys < 0) {
            $av = Vars\QuickTime::$avType[Perl::str($et->MediaType)] ?? null;
            if ($av) {
                $createKeys = 1;
                [$keysGrp, $keysPath] = ["{$av}Keys", 'MOV-Movie-Track'];
            } else {
                $canCreate = 0;
            }
        }
        if (!($addDirs[$keysGrp] ?? null)) $createKeys &= ~0x01;   // (Keys may have been written)

        // add new directories/tags at this level if necessary
        if ($canCreate and (array_key_exists($dirName, $et->EDIT_DIRS ?? []) or $createKeys)) {
            // get a hash of tagInfo references to add to this directory
            $dirs = $et->GetAddDirHash($tagTablePtr, $dirName);
            // make sorted list of new tags to be added
            $addTags = [...array_map('strval', array_keys($dirs)), ...array_map('strval', array_keys($newTags))];
            sort($addTags, SORT_STRING);
            $index = null;
            // add Keys tags if necessary
            if ($createKeys) {
                if ($curPath === $keysPath) {
                    // add Meta for Keys if necessary
                    if (!($didDir['meta'] ?? null)) {
                        $dirs['meta'] = ExifTool::PackageTable('Image::ExifTool::QuickTime::Movie')['meta'];
                        $addTags[] = 'meta';
                    }
                } elseif ($curPath === "$keysPath-Meta") {
                    // special case for Keys Meta -- reset directories and start again
                    $addTags = [];
                    $dirs = [];
                    foreach (['keys', 'ilst'] as $t) {
                        if ($didDir[$t] ?? null) continue;  // don't add again
                        $dirs[$t] = ExifTool::PackageTable('Image::ExifTool::QuickTime::Meta')[$t];
                        $addTags[] = $t;
                    }
                } elseif ($curPath === "$keysPath-Meta-ItemList" and $et->{$keysGrp}) {
                    $indexes = array_keys($et->{$keysGrp}['Add']);
                    sort($indexes);
                    foreach ($indexes as $index) {
                        $id = ExifTool::Set32u($index);
                        $newTags[$id] = $et->{$keysGrp}['Add'][$index];
                        $addTags[] = $id;
                    }
                } else {
                    $dirs = $et->GetAddDirHash($tagTablePtr, $dirName);
                    array_push($addTags, ...self::SortedKeys($dirs));
                }
            }
            // (note that $tag may be a binary Keys index here)
            foreach ($addTags as $tag) {
                $tagInfo = ($dirs[$tag] ?? null) ?: ($newTags[$tag] ?? null);
                if (!$tagInfo instanceof TagInfo) { // (shouldn't happen, but somehow there is forum17260)
                    // (also can happen if Meta exists but Keys does not since 'keys' is an array ref.
                    //  SonyPMW-EX1R.mp4 has a Movie-Meta atom with XML and no Keys that triggers this
                    //  issue.  Note that in this case the Meta HandlerType is 'meta' instead of 'mdta',
                    //  which isn't a problem for ExifTool, but may be for other software?)
                    if (!(is_array($tagInfo) and $tagInfo)) continue;
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                    if (!$tagInfo) continue;
                }
                if ($tagInfo->CanCreate !== null and !$tagInfo->CanCreate) continue;
                if ($tagInfo->MediaType !== null and Perl::str($et->MediaType) !== $tagInfo->MediaType) continue;
                $subdir = $tagInfo->SubDirectory;
                if (!$subdir) {
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    if (!($nvHash and !($didTag[spl_object_id($nvHash)] ?? null))) continue;
                    if (!($nvHash->IsCreating and $et->IsOverwriting($nvHash))) continue;
                    $newVal = $et->GetNewValue($nvHash);
                    if ($newVal === null) continue;
                    $prVal = $newVal;
                    $flags = self::FormatQTValue($et, $newVal, $tagInfo);
                    if ($newVal === null) continue;
                    [$ctry, $lang] = [0, 0];
                    // handle alternate languages
                    if ($tagInfo->LangCode) {
                        $tag = substr($tag, 0, 4);  // strip language code from tag ID
                        if (!preg_match('/^([A-Z]{3})?[-_]?([A-Z]{2})?$/i', Perl::str($tagInfo->LangCode), $m)) {
                            $et->Warn("Invalid language code for {$tagInfo->Name}");
                            continue;
                        }
                        // pack language and country codes
                        if (($m[1] ?? '') and $m[1] !== 'und') {
                            foreach (unpack('C*', strtolower($m[1])) as $c) $lang = ($lang << 5) | ($c - 0x60);
                        }
                        if (($m[2] ?? '') and $m[2] !== 'ZZ') $ctry = Perl::unpack('n', Perl::pack('a2', strtoupper($m[2])))[0];
                    }
                    if ($dirInfo['HasData'] ?? null) {
                        // add 'data' header
                        $newVal = Perl::pack('Na4Nnn', 16 + strlen($newVal), 'data', $flags, $ctry, $lang) . $newVal;
                    } elseif (preg_match('/^\xa9/', $tag) or $tagInfo->IText) {
                        if ($ctry) {
                            $grp = $et->GetGroup($tagInfo, 1);
                            $et->Warn("Can't use country code for $grp:{$tagInfo->Name}");
                            continue;
                        } elseif ($tagInfo->IText and $tagInfo->IText >= 6) {
                            // add 6-byte langText header and trailing null
                            // (with extra junk before language code if IText > 6)
                            $n = $tagInfo->IText - 6;
                            if (strlen($newVal) < $n) $newVal .= str_repeat(' ', $n);
                            $newVal = "\0\0\0\0" . substr($newVal, 0, $n) . Perl::pack('n', 0, $lang) . substr($newVal, $n) . "\0";
                        } else {
                            // add IText header
                            $newVal = Perl::pack('nn', strlen($newVal), $lang) . $newVal;
                        }
                    } elseif ($ctry or $lang) {
                        $grp = $et->GetGroup($tagInfo, 1);
                        $et->Warn("Can't use language code for $grp:{$tagInfo->Name}");
                        continue;
                    }
                    if ($tagInfo->WriteLast) {
                        $writeLast = ($writeLast ?: '') . ExifTool::Set32u(8 + strlen($newVal)) . $tag . $newVal;
                    } else {
                        $boxPos[$tag] = [strlen($outfile->value), 8 + strlen($newVal)];
                        if (!ExifTool::Write($outfile, ExifTool::Set32u(8 + strlen($newVal)), $tag, $newVal)) {
                            $rtnVal = $rtnErr;
                            $err = 1;
                        }
                    }
                    $grp = $et->GetGroup($tagInfo, 1);
                    $et->VerboseValue("+ $grp:{$tagInfo->Name}", $prVal);
                    $didTag[spl_object_id($nvHash)] = 1;
                    ++$et->CHANGED;
                    continue;
                }
                $subName = ($subdir['DirName'] ?? null) ?: $tagInfo->Name;
                // QuickTime hierarchy is complex, so check full directory path before adding
                if ($createKeys and $curPath === $keysPath and $subName === 'Meta') {
                    $et->VPrint(0, "  Creating Meta with mdta Handler and Keys\n");
                    // init Meta box for Keys tags with mdta Handler and empty Keys+ItemList
                    $buf2 = "\0\0\0\x20hdlr\0\0\0\0\0\0\0\0mdta\0\0\0\0\0\0\0\0\0\0\0\0" .
                            "\0\0\0\x10keys\0\0\0\0\0\0\0\0" .
                            "\0\0\0\x08ilst";
                } elseif ($createKeys and $curPath === "$keysPath-Meta") {
                    $buf2 = ($subName === 'Keys' ? "\0\0\0\0\0\0\0\0" : '');
                } elseif ($subName === 'Meta' and ($et->OPTIONS['QuickTimeHandler'] ?? null)) {
                    $et->VPrint(0, "  Creating Meta with mdir Handler\n");
                    // init Meta box for ItemList tags with mdir Handler
                    $buf2 = "\0\0\0\x20hdlr\0\0\0\0\0\0\0\0mdir\0\0\0\0\0\0\0\0\0\0\0\0";
                } else {
                    if (!($curPath === $writePath and ($addDirs[$subName] ?? null) and $addDirs[$subName] === $dirName)) continue;
                    $buf2 = '';  // write from scratch
                }
                $subdirInfo = [
                    'Parent'   => $dirName,
                    'DirName'  => $subName,
                    'DataPt'   => $buf2,
                    'DirStart' => 0,
                    'HasData'  => $subdir['HasData'] ?? null,
                    'OutFile'  => $outfile,
                    'ChunkOffset' => [], // (just to be safe)
                    'WriteGroup' => $tagInfo->WriteGroup,
                ];
                $subTable = ExifTool::GetTagTable($subdir['TagTable'] ?? null);
                $newData = $et->WriteDirectory($subdirInfo, $subTable, $subdir['WriteProc'] ?? null);
                if ($newData and strlen($newData) <= 0x7ffffff7) {
                    $prefix = '';
                    // add atom version or ID if necessary
                    if ($subdir['Start'] ?? null) {
                        if ($subdir['Start'] == 4) {
                            $prefix = "\0\0\0\0"; // a simple version number
                        } else {
                            // get UUID from Condition expression
                            $cond = self::ConditionSource($tagInfo->Condition);
                            if ($cond and preg_match('{preg_match\(\'/\^(.*)/\'}', $cond, $m)) $prefix = stripcslashes($m[1]);
                            if (strlen($prefix) != $subdir['Start']) $et->Error('Internal UUID error');
                        }
                    }
                    $newHdr = ExifTool::Set32u(8 + strlen($newData) + strlen($prefix)) . $tag . $prefix;
                    if ($tagInfo->WriteLast) {
                        $writeLast = ($writeLast ?: '') . $newHdr . $newData;
                    } else {
                        if ($tag === 'uuid') {
                            // add offset for new uuid (needed for CR3 CTBO offsets)
                            $dirInfo['ChunkOffset'][] = [$tag, strlen($outfile->value), strlen($newHdr) + strlen($newData)];
                        }
                        $boxPos[$tag] = [strlen($outfile->value), strlen($newHdr) + strlen($newData)];
                        if (!ExifTool::Write($outfile, $newHdr, $newData)) {
                            $rtnVal = $rtnErr;
                            $err = 1;
                        }
                    }
                }
                // add only once (must delete _after_ call to WriteDirectory())
                // (Keys tags are a special case, and are handled separately)
                if (!$createKeys) unset($addDirs[$subName]);
            }
        }
        // write HEIC metadata after top-level 'meta' box has been processed if editing this information
        if ($curPath === 'MOV-Meta' and ($et->EDIT_DIRS['ItemInformation'] ?? null)) {
            $dirInfo['BoxPos'] = &$boxPos;
            $mdatEditNew = self::WriteItemInfo($et, $dirInfo, $outfile);
            if ($mdatEditNew) {
                if ($et->mdatEdit) $et->Error('Multiple top-level Meta containers');
                $et->mdatEdit = $mdatEditNew;
            }
        }
        // write out any necessary terminator
        if ($term and strlen($outfile->value)) {
            if (!ExifTool::Write($outfile, $term)) {
                $rtnVal = $rtnErr;
                $err = 1;
            }
        }

        // delete temporary Keys variables after Meta is processed
        if ($dirName === 'Meta') {
            // delete any Meta box with no useful information (ie. only 'hdlr','keys','lang','ctry')
            $isEmpty = 1;
            foreach (array_keys($boxPos) as $b) {
                if (!(self::EMPTY_META[$b] ?? null)) {
                    $isEmpty = 0;
                    break;
                }
            }
            if ($isEmpty) {
                if ($boxPos) {
                    $names = array_map(static fn ($b) => self::EMPTY_META[$b], array_map('strval', array_keys($boxPos)));
                    sort($names, SORT_STRING);
                    $et->VPrint(0, '  Deleting ' . implode('+', $names));
                }
                $outfile->value = '';
                // (could report a file if editing nothing when it contained an empty Meta atom)
                // ++$$et{CHANGED};
            }
            if ($curPath === "$keysPath-Meta") {
                unset($addDirs[$keysGrp]); // prevent creation of another Meta for Keys tags
                unset($et->{$keysGrp});
            }
        }

        // return now if writing subdirectory
        if ($dataPt !== null) {
            if ($writeLast) $et->Error("Internal error: WriteLast not on top-level atom!\n");
            return $err ? null : $outfile->value;
        }

        // issue minor error if we didn't find an 'mdat' atom
        $off = $dirInfo['ChunkOffset'] ?? [];
        if (!$mdats) {
            foreach ($off as $co) {
                if ($co[0] === 'uuid') continue;
                $et->Error('Media data referenced but not found');
                return $rtnVal;
            }
            $et->Warn('No media data', 1);
        }

        // edit mdat blocks as required
        // (0=old pos [0 if creating], 1=old end [0 if creating], 2=new data ref or undef to delete,
        //  3=new data item id)
        if ($et->mdatEdit) {
            $mdatEdit = $et->mdatEdit;
            unset($et->mdatEdit);
        }
        foreach ($mdatEdit as $edit) {
            $thisMdat = $newMdat = [];
            $changed = null;
            foreach ($mdats as $mdat) {
                // keep track of all chunks for the mdat with this header
                if (strlen($mdat[2])) {
                    array_push($newMdat, ...$thisMdat);
                    $thisMdat = [];
                }
                $thisMdat[] = $mdat;
                // is this edit inside this mdat chunk?
                // - $$edit[0] and $$edit[1] will both be zero if we are creating a new chunk
                // - $$mdat[1] is zero if mdat runs to end of file
                // - $$edit[0] == $$edit[1] == $$mdat[0] if reviving a deleted chunk
                // - $$mdat[5] is defined if this was a newly added/edited chunk
                if (isset($mdat[5]) or $changed) continue;  // don't replace a newly added chunk
                if (!$edit[0] or    // (newly created chunk)
                    // (edit is inside chunk)
                    ((($edit[0] < $mdat[1] or !$mdat[1]) and $edit[1] > $mdat[0]) or
                    // (edit inserted at start or end of chunk)
                    ($edit[0] == $edit[1] and ($edit[0] == $mdat[0] or $edit[0] == $mdat[1]))))
                {
                    if (!$edit[0]) {
                        $edit[0] = $edit[1] = $mdat[0];  // insert at start of this mdat
                    } elseif ($edit[0] < $mdat[0] or ($edit[1] > $mdat[1] and $mdat[1])) {
                        $et->Error('ItemInfo runs across mdat boundary');
                        return $rtnVal;
                    }
                    $hdrChunk = $thisMdat[0] ?? null;
                    if (!$hdrChunk) {
                        $et->Error('Internal error finding mdat header');
                        return $rtnVal;
                    }
                    // calculate difference in mdat size
                    $diff = (($edit[2] ?? null) ? strlen($edit[2]->value) : 0) - ($edit[1] - $edit[0]);
                    // edit size of mdat in header if necessary
                    if ($diff) {
                        if (strlen($hdrChunk[2]) == 8) {
                            $size = ExifTool::Get32u($hdrChunk[2], 0);
                            if ($size) { // (0 size = extends to end of file)
                                $size += $diff;
                                if ($size > 0xffffffff) {
                                    $et->Error("Can't yet grow mdat across 4GB boundary");
                                    return $rtnVal;
                                }
                                ExifTool::Set32u($size, $hdrChunk[2], 0);
                            }
                        } elseif (strlen($hdrChunk[2]) == 16) {
                            $size = ExifTool::Get64u($hdrChunk[2], 8);
                            if ($size) {
                                $size += $diff;
                                ExifTool::Set64u($size, $hdrChunk[2], 8);
                            }
                        } else {
                            $et->Error('Internal error. Invalid mdat header');
                            return $rtnVal;
                        }
                    }
                    $changed = 1;
                    // remove the edited section of this chunk (if any) and replace with new data (if any)
                    if ($edit[0] > $mdat[0]) {
                        if ($edit[2] ?? null) $thisMdat[] = new \ArrayObject([$edit[0], $edit[1], '', 0, $edit[2], $edit[3] ?? null]);
                        // add remaining data after edit (or empty stub in case it is referenced by an offset)
                        $thisMdat[] = new \ArrayObject([$edit[1], $mdat[1], '']);
                        $mdat[1] = $edit[0];  // now ends at start of edit
                    } else {
                        if ($edit[2] ?? null) {
                            // insert the new chunk before this chunk, moving the header to the new chunk
                            array_splice($thisMdat, -1, 0, [new \ArrayObject([$edit[0], $edit[1], $mdat[2], 0, $edit[2], $edit[3] ?? null])]);
                            $mdat[2] = '';     // (header was moved to new chunk)
                            // initialize ChunkOffset pointer if necessary
                            if ($edit[3] ?? null) {
                                $n = 0;
                                foreach ($off as $co) {
                                    if (!(isset($co[4]) and $co[4] == $edit[3])) continue;
                                    ++$n;
                                    if ($co[0] === 'stco_iloc') {
                                        ExifTool::Set32u($mdat[0], $outfile->value, $co[1]);
                                    } else {
                                        ExifTool::Set64u($mdat[0], $outfile->value, $co[1]);
                                    }
                                }
                                if ($n != 1) $et->Error('Internal error updating chunk offsets');
                            }
                        }
                        $mdat[0] = $edit[1];  // remove old data
                    }
                }
            }
            if ($changed) {
                $mdats = [...$newMdat, ...$thisMdat];
                ++$et->CHANGED;
            } else {
                $et->Error('Internal error modifying mdat');
            }
        }

        // determine our new mdat positions
        // (0=old pos, 1=old end, 2=mdat header, 3=new pos, 4=new data ref if changed, 5=new item ID)
        $pos = strlen($outfile->value);
        foreach ($mdats as $mdat) {
            $pos += strlen($mdat[2]);
            $mdat[3] = $pos;
            $pos += ($mdat[4] ?? null) ? strlen($mdat[4]->value) : $mdat[1] - $mdat[0];
        }

        // fix up offsets for new mdat position(s) (and uuid positions in CR3 images)
        foreach ($off as $co) {
            [$type, $ptr, $len, $base, $id] = $co + [null, null, null, null, null];
            if (!$base) $base = 0;
            if (!preg_match('/^(stco|co64)_?(.*)$/', $type, $tm)) {
                if ($type === 'uuid') continue;
                if ($type !== 'CTBO') {
                    $et->Error('Internal error fixing offsets');
                    break;
                }
                // update 'CTBO' item offsets/sizes in Canon CR3 images
                if (!($co[2] > 12)) {
                    $et->Error('Invalid CTBO atom');
                    break;
                }
                if (!$mdats) {
                    $et->Error('Missing CR3 image data');
                    break;
                }
                $n = ExifTool::Get32u($outfile->value, $co[1] + 8);
                if ($co[2] < $n * 20 + 12) {
                    $et->Error('Truncated CTBO atom');
                    break;
                }
                $ctboOff = [];
                // determine uuid types, and build an offset lookup based on CTBO ID number
                foreach ($off as $o) {
                    if (!($o[0] === 'uuid' and $o[2] >= 24)) continue; // (ignore undersized and deleted uuid boxes)
                    $p = $o[1];
                    if ($p + 24 > strlen($outfile->value)) continue;   // (will happen for WriteLast uuid tags)
                    $siz = ExifTool::Get32u($outfile->value, $p);       // get size of uuid atom
                    if ($siz == 1) {                        // check for extended (8-byte) size
                        if (!($o[2] >= 32)) continue;
                        $p += 8;
                    }
                    // get CTBO entry ID based on 16-byte UUID identifier
                    $cid = self::CTBO_ID[substr($outfile->value, $p + 8, 16)] ?? null;
                    if ($cid !== null) $ctboOff[$cid] = $o;
                }
                // calculate new offset for the first mdat (size of -1 indicates it didn't change)
                $ctboOff[3] = ['mdat', $mdats[0][3] - strlen($mdats[0][2]), -1];
                for ($i = 0; $i < $n; ++$i) {
                    $p = $co[1] + 12 + $i * 20;
                    $cid = ExifTool::Get32u($outfile->value, $p);
                    // ignore if size is zero unless we can add this entry
                    // (note: can't yet add/delete PreviewImage, but leave this possibility open)
                    if (!(ExifTool::Get64u($outfile->value, $p + 12) or $cid == 1 or $cid == 2)) continue;
                    if (!isset($ctboOff[$cid])) {
                        if (!($cid == 1 or $cid == 2)) {
                            $et->Error("Can't handle CR3 CTBO ID number $cid");
                            break;
                        }
                        // XMP or PreviewImage was deleted -- set offset and size to zero
                        $ctboOff[$cid] = ['uuid', 0, 0];
                    }
                    // update the new offset and size of this entry
                    ExifTool::Set64u($ctboOff[$cid][1], $outfile->value, $p + 4);
                    if (!($ctboOff[$cid][2] < 0)) ExifTool::Set64u($ctboOff[$cid][2], $outfile->value, $p + 12);
                }
                continue;
            }
            $siz = $tm[1] === 'co64' ? 8 : 4;
            $n = $tg = null;
            if ($tm[2] !== '') {   // is this an offset in an iloc or 'gps ' atom?
                $n = 1;
                $type = $tm[1];
                $tg = $tm[2];
            } else {    // this is an stco or co84 atom
                if ($len < 8) continue;
                $n = ExifTool::Get32u($outfile->value, $ptr + 4);    // get number of entries in table
                $ptr += 8;
                $len -= 8;
                $tg = $tm[1];
            }
            $end = $ptr + $n * $siz;
            if ($end > $ptr + $len) {
                $et->Error("Invalid $tg table");
                return $rtnVal;
            }
            for (; $ptr < $end; $ptr += $siz) {
                $ok = null;
                $val = $type === 'co64' ? ExifTool::Get64u($outfile->value, $ptr) : ExifTool::Get32u($outfile->value, $ptr);
                for ($i = 0; $i < count($mdats); ++$i) {
                    $mdat = $mdats[$i];
                    $p = $val + $base;
                    if (isset($mdat[5])) { // is this chunk associated with an item we edited?
                        // set offset only for the corresponding new chunk
                        if (!($id !== null and $id == $mdat[5])) {
                            // could have pointed to empty chunk before inserted chunk
                            if (!($p == $mdat[0] and $mdat[0] != $mdat[1])) continue;
                        }
                    } else {
                        // (have seen $pos == $$mdat[1], which is a real PITA)
                        if (!($p >= $mdat[0] and ($p <= $mdat[1] or !$mdat[1]))) continue;
                        // step to next chunk if contiguous and at the end of this one
                        if ($p == $mdat[1] and $i + 1 < count($mdats) and $p == $mdats[$i + 1][0]) continue;
                    }
                    $val += $mdat[3] - $mdat[0];
                    if ($val < 0) {
                        $et->Error("Error fixing up $tg offset");
                        return $rtnVal;
                    }
                    if ($type === 'co64') {
                        ExifTool::Set64u($val, $outfile->value, $ptr);
                    } elseif ($val <= 0xffffffff) {
                        ExifTool::Set32u($val, $outfile->value, $ptr);
                    } else {
                        $et->Error("Can't yet promote $tg offset to 64 bits");
                        return $rtnVal;
                    }
                    $ok = 1;
                    break;
                }
                if (!$ok) {
                    $et->Error("Chunk offset in $tg atom is outside media data");
                    return $rtnVal;
                }
            }
        }

        // switch back to actual output file
        $outfile = $dirInfo['OutFile'];

        // write the metadata
        if (!ExifTool::Write($outfile, $outBuff->value)) $rtnVal = 0;

        // write the media data
        foreach ($mdats as $mdat) {
            if (!ExifTool::Write($outfile, $mdat[2])) $rtnVal = 0;  // write mdat header
            if ($mdat[4] ?? null) {
                if (!ExifTool::Write($outfile, $mdat[4]->value)) $rtnVal = 0;
            } else {
                if (!$raf->Seek($mdat[0], 0)) {
                    $et->Error('Seek error');
                    break;
                }
                if ($mdat[1]) {
                    $result = ExifTool::CopyBlock($raf, $outfile, $mdat[1] - $mdat[0]);
                    if ($result === null) {
                        $rtnVal = 0;
                        break;
                    }
                    if (!$result) {
                        $et->Error('Truncated mdat atom');
                        break;
                    }
                } else {
                    // mdat continues to end of file
                    while ($raf->Read($buf2, 65536)) {
                        if (!ExifTool::Write($outfile, $buf2)) {
                            $rtnVal = 0;
                            break;
                        }
                    }
                }
            }
        }

        // write the stuff that must come last
        if ($writeLast) {
            if (!ExifTool::Write($outfile, $writeLast)) $rtnVal = 0;
        }

        // copy trailers if necessary
        while ($rtnVal and $trailer) {
            // are we deleting the trailers?
            $nvTrail = $et->GetNewValueHash(ExifTool::GetTagTable('Image::ExifTool::Extra')['Trailer']);
            if (($et->DEL_GROUP['Trailer'] ?? null) or ($et->DEL_GROUP[$trailer[0]] ?? null) or
                ($nvTrail and !($nvTrail->Value[0] ?? null)))
            {
                $et->Warn("Deleted {$trailer[0]} trailer", 1);
                ++$et->CHANGED;
                $trailer = $trailer[3];
                continue;
            }
            if (!$raf->Seek($trailer[1], 0)) {
                $rtnVal = 0;
                break;
            }
            if ($trailer[0] === 'MIE') {
                ExifTool::RequireModule('Image::ExifTool::MIE');
                $mieInfo = ['RAF' => $raf, 'OutFile' => $outfile];
                $result = MIE::ProcessMIE($et, $mieInfo);
                if (!($result > 0)) {
                    $et->Error('Error writing MIE trailer');
                    $rtnVal = 0;
                    break;
                }
            } else {
                $et->Warn(Perl::sprintf('Copying %s trailer from offset 0x%x (%d bytes)', $trailer[0], $trailer[1], $trailer[2]), 1);
                $len = $trailer[2];
                while ($len) {
                    $n = $len > 65536 ? 65536 : $len;
                    if (!($raf->Read($buf2, $n) == $n and ExifTool::Write($outfile, $buf2))) {
                        $rtnVal = 0;
                        break;
                    }
                    $len -= $n;
                }
                if (!$rtnVal) {
                    $et->Error("Error copying {$trailer[0]} trailer");
                    break;
                }
            }
            $trailer = $trailer[3];    // step to next trailer in linked list
        }
        return $rtnVal;
    }

    /**
     * (PHP) The source of a Condition, to take the UUID from its regular expression like Perl
     * does with the Perl expression: the PHP source of the translated closure, which tests
     * the same pattern with preg_match('/^...'.
     */
    private static function ConditionSource($cond): ?string
    {
        if (!$cond instanceof \Closure) return is_string($cond) ? $cond : null;
        $r = new \ReflectionFunction($cond);
        $lines = @file($r->getFileName());
        if (!$lines) return null;
        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }

    //------------------------------------------------------------------------------
    // Write QuickTime-format MOV/MP4 file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid QuickTime file,
    //          or -1 if a write error occurred
    public static function WriteMOV(?ExifTool $et = null, ?array &$dirInfo = null)
    {
        self::LoadWriteQuickTime();
        if (!$et) return 1;    // allow dummy access to autoload this package
        $raf = $dirInfo['RAF'] ?? null;
        if (!$raf) return 0;
        $buff = $ftype = null;

        // read the first atom header
        if ($raf->Read($buff, 8) != 8) return 0;
        [$size, $tag] = Perl::unpack('Na4', $buff);
        if ($size < 8 and $size != 1) return 0;

        // validate the file format
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Main');
        if (!$tagTablePtr[$tag]) return 0;

        // determine the file type (by default, assume MP4 if 'ftyp' exists
        // without 'qt  ' as a compatible brand, but HEIC is an exception)
        if ($tag === 'ftyp' and $size >= 12 and $size < 100000 and
            $raf->Read($buff, $size - 8) == $size - 8 and
            !preg_match('/^(....)+(qt  )/s', $buff))
        {
            if (preg_match('/^crx /', $buff)) {
                $ftype = 'CR3';
            } elseif (preg_match('/^(heic|mif1|msf1|heix|hevc|hevx|avif)/', $buff)) {
                $ftype = 'HEIC';
            } else {
                $ftype = 'MP4';
            }
        } else {
            $ftype = 'MOV';
        }
        $et->SetFileType($ftype); // need to set "FileType" tag for a Condition
        if ($ftype === 'HEIC') {
            // EXIF is preferred in HEIC files
            $et->InitWriteDirs(self::DIR_MAP[$ftype], 'EXIF', 'QuickTime');
        } else {
            $et->InitWriteDirs(self::DIR_MAP[$ftype], 'XMP', 'QuickTime');
        }
        $et->DirMap = self::DIR_MAP[$ftype];     // need access to directory map when writing
        // track tags globally to avoid creating multiple tags in the case of duplicate directories
        $et->DidTag = [];
        ExifTool::SetByteOrder('MM');
        $raf->Seek(0, 0);

        // write the file
        $et->MediaType = '';
        $dirInfo['Parent'] = '';
        $dirInfo['DirName'] = 'MOV';
        $dirInfo['ChunkOffset'] = []; // (just to be safe)
        return self::WriteQuickTime($et, $dirInfo, $tagTablePtr) ? 1 : -1;
    }
}
