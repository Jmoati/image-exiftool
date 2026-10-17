<?php

//------------------------------------------------------------------------------
// File:         JSON.pm
//
// Description:  Read JSON files
//
// Notes:        Set ExifTool MissingTagValue to "null" to ignore JSON nulls
//
// Revisions:    2017/03/13 - P. Harvey Created
//
// PHP port of Image::ExifTool::JSON 1.11. Tag tables are generated into tables/JSON.php.
// JSON values are read by Import::ReadJSONObject (see src/Import.php for their PHP form).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class JSON
{
    public static $VERSION = '1.11';

    //------------------------------------------------------------------------------
    // Store a tag value
    // Inputs: 0) ExifTool ref, 1) tag table, 2) tag ID, 3) value, 4) tagInfo flags
    //         (PHP: flags are named arguments)
    public static function FoundTag(ExifTool $et, TagTable $tagTablePtr, $tag, $val, ...$flags)
    {
        // special case to reformat ON1 tag names
        $tag = preg_replace('/^settings\w{8}-\w{4}-\w{4}-\w{4}-\w{12}(Data|Metadata.+)$/', 'ON1_Settings$1', Perl::str($tag), 1, $n);
        if ($n) {
            if ($et->FILE_TYPE === 'JSON') $et->OverrideFileType('ONP', 'application/on1');
        }

        // avoid conflict with special table entries
        if (!empty(Vars\ExifTool::$specialTags[$tag])) $tag .= '!';

        if ($tagTablePtr[$tag] === null) {
            $name = strtr($tag, ':', '_');  // use underlines in place of colons in tag name
            $name = preg_replace('/^c2pa/i', 'C2PA', $name, 1);    // hack to fix "C2PA" case
            $name = ExifTool::MakeTagName($name);
            $desc = ExifTool::MakeDescription($name);
            $desc = preg_replace('/^C2 PA/', 'C2PA', $desc, 1);    // hack to get "C2PA" correct
            $et->VPrint(0, $et->INDENT, "[adding $tag]\n");
            ExifTool::AddTagToTable($tagTablePtr, $tag, [
                'Name' => $name,
                'Description' => $desc,
                ...$flags,
                'Temporary' => 1,
            ]);
        }
        $et->HandleTag($tagTablePtr, $tag, $val);
    }

    //------------------------------------------------------------------------------
    // Process a JSON tag
    // Inputs: 0) ExifTool ref, 1) tag table, 2) tag ID, 3) value, 4) tagInfo flags
    // - expands structures into flattened tags as required
    public static function ProcessTag(ExifTool $et, TagTable $tagTablePtr, $tag, $val, ...$flags)
    {
        if (is_array($val) and !array_is_list($val)) {
            if ($et->Options('Struct')) {
                self::FoundTag($et, $tagTablePtr, $tag, $val, ...[...$flags, 'Struct' => 1]);
                if (!($et->Options('Struct') > 1)) return;
            }
            // support hashes with ordered keys
            foreach (ExifTool::OrderedKeys($val) as $k) {
                $k = Perl::str($k);
                $tg = $tag . ((preg_match('/^\d/', $k) and preg_match('/\d$/', Perl::str($tag))) ? '_' : '') . ucfirst($k);
                $tg = preg_replace_callback('/([^a-zA-Z])([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $tg);
                self::ProcessTag($et, $tagTablePtr, $tg, $val[$k] ?? null, ...[...$flags, 'Flat' => 1]);
            }
        } elseif (is_array($val)) {
            foreach ($val as $v) {
                self::ProcessTag($et, $tagTablePtr, $tag, $v, ...[...$flags, 'List' => 1]);
            }
        } elseif ($val !== null) {
            self::FoundTag($et, $tagTablePtr, $tag, $val, ...$flags);
        }
    }

    //------------------------------------------------------------------------------
    // Extract meta information from a JSON file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference, 2) tag table ref
    // Returns: 1 on success, 0 if this wasn't a recognized JSON file
    public static function ProcessJSON(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $structOpt = $et->Options('Struct');
        $database = [];
        $dataPt = null;

        if (!$raf) {
            $dataPt = $dirInfo['DataPt'];
            if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
            $dataPt = Perl::str($dataPt);
            $dirLen = $dirInfo['DirLen'] ?? null;
            if (!empty($dirInfo['DirStart']) or ($dirLen and Perl::str($dirLen) !== (string) strlen($dataPt))) {
                $dataPt = substr($dataPt, (int) Perl::num($dirInfo['DirStart'] ?? 0), (int) Perl::num($dirLen));
            }
            $raf = new RandomAccess($dataPt);
            // extract as a block if requested
            $blockName = !empty($dirInfo['BlockInfo']) ? $dirInfo['BlockInfo']->Name : '';
            $blockExtract = $et->Options('BlockExtract');
            if ($blockName and ($blockExtract or !empty($et->REQ_TAG_LOOKUP[strtolower($blockName)]) or
                ($et->TAGS_FROM_FILE and empty($et->EXCL_TAG_LOOKUP[strtolower($blockName)]))))
            {
                $et->FoundTag($dirInfo['BlockInfo'], $dataPt);
                if ($blockExtract and $blockExtract > 1) return 1;
            }
            $et->VerboseDir('JSON');
        }

        // read information from JSON file into database structure
        $err = Import::ReadJSON($raf, $database,
            $et->Options('MissingTagValue'), $et->Options('Charset'));

        if ($err or !$database) return 0;

        if ($dataPt === null) $et->SetFileType();

        if (!$tagTablePtr) $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::JSON::Main');

        // remove any old tag definitions in case they change flags
        foreach (ExifTool::TagTableKeys($tagTablePtr) as $key) {
            $ti = $tagTablePtr[$key];
            if ($ti instanceof TagInfo and $ti->Temporary) unset($tagTablePtr[$key]);
        }

        // extract tags from JSON database
        $keys = array_map('strval', array_keys($database));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            foreach (ExifTool::OrderedKeys($database[$key]) as $tag) {
                $val = $database[$key][$tag] ?? null;
                // (ignore SourceFile if generated automatically by ReadJSON)
                if ($tag === 'SourceFile' and $val !== null and Perl::str($val) === '*') continue;
                self::ProcessTag($et, $tagTablePtr, $tag, $val);
            }
        }
        return 1;
    }
}
