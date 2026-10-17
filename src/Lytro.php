<?php

//------------------------------------------------------------------------------
// File:         Lytro.pm
//
// Description:  Read Lytro LFP files
//
// Revisions:    2014-07-17 - P. Harvey Created
//
// References:   1) http://optics.miloush.net/lytro/TheFileFormat.aspx
//
// PHP port of Image::ExifTool::Lytro 1.04. Tag tables are generated into tables/Lytro.php.
// (Image::ExifTool::Import isn't ported: ReadJSONObject is ported here for a buffer, see below)
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Lytro
{
    public static $VERSION = '1.04';

    // (Import.pm lexical %unescapeJSON)
    private const array UNESCAPE_JSON = ['t' => "\t", 'n' => "\n", 'r' => "\r", 'b' => "\x08", 'f' => "\f"];

    //------------------------------------------------------------------------------
    // Extract tags from a parsed JSON hash
    // Inputs: 0) ExifTool ref, 1) tag hash ref, 2) base tag name
    public static function ExtractTags(ExifTool $et, $meta, $parent)
    {
        if (!(is_array($meta) and !array_is_list($meta))) {
            $et->Warn('Invalid LFP metadata');
            return;
        }
        $name = $tagTablePtr = null;
        foreach (ExifTool::OrderedKeys($meta) as $key) {
            $tag = $parent . ucfirst(Perl::str($key));
            $item = $meta[$key] ?? null;
            $isList = (is_array($item) and array_is_list($item));
            foreach ($isList ? $item : [$item] as $val) {
                if (is_array($val) and !array_is_list($val)) {
                    self::ExtractTags($et, $val, $tag);
                    continue;
                }
                $tagTablePtr or $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Lytro::Main');
                if (!$tagTablePtr[$tag]) {
                    $name = preg_replace_callback('/[^-_a-zA-Z0-9](.?)/', static fn ($m) => strtoupper($m[1]), $tag);
                    $name = preg_replace('/ParametersVendorContentComLytroTags/', '', $name, 1);
                    $tagInfo = [];
                    $stripped = preg_replace('/^Devices/', '', $name);
                    if ($stripped === $name) {
                        $tagInfo['Groups'] = [2 => 'Image'];
                    }
                    $name = $stripped;
                    if ($isList) $tagInfo['List'] = 1;
                    $tagInfo['Name'] = $name;
                    $str = $tag === $name ? '' : " as $name";
                    $et->VPrint(0, "  [adding $tag$str]\n");
                    ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
                $et->HandleTag($tagTablePtr, $tag, $val);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Process segments from a Lytro LFP image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid Lytro image
    public static function ProcessLFP(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $id = null;

        // validate the Lytro file header
        if (!($raf->Read($buff, 16) == 16 and preg_match('/^\x89LFP\x0d\x0a\x1a\x0a/', $buff))) return 0;
        $et->SetFileType();   // set the FileType tag
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Lytro::Main');
        while ($raf->Read($buff, 16) == 16) {
            if (!preg_match('/^\x89LF/', $buff)) {
                $et->Warn('LFP format error');
                break;
            }
            $size = ExifTool::Get32u($buff, 12);
            if ($size & 0x80000000) {
                $et->Warn('Invalid LFP segment size');
                break;
            }
            if ($raf->Read($id, 80) != 80) {   // ignore the sha1
                $et->Warn('Truncated LFP segment');
                break;
            }
            if ($verbose) {
                $id = preg_replace('/\0.*/s', '', $id);
                $et->VPrint(0, substr($buff, 1, 3), " segment ($size bytes, $id)\n");
            }
            if ($size > 20000000) {
                if (!$raf->Seek($size, 1)) {
                    $et->Warn('Seek error in LFP file');
                    break;
                }
            } else {
                if ($raf->Read($buff, $size) != $size) {
                    $et->Warn('Truncated LFP data');
                    break;
                }
                $et->VerboseDump($buff, Addr: $raf->Tell() - $size);
                if (preg_match('/^\{\s+"/', $buff)) { // JSON metadata?
                    $et->HandleTag($tagTablePtr, 'JSONMetadata', $buff);
                    $bpos = 0;
                    $meta = self::ReadJSONObject($buff, $bpos);
                    self::ExtractTags($et, $meta, '');
                } elseif (preg_match('/^\xff\xd8\xff/', $buff)) { // embedded JPEG image?
                    $et->HandleTag($tagTablePtr, 'EmbeddedImage', $buff);
                }
            }
            // skip padding if necessary
            $pad = 16 - ($size % 16);
            if ($pad != 16) $raf->Seek($pad, 1);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read JSON object from a buffer (port of Image::ExifTool::Import::ReadJSONObject
    // with an undef RAF, as called by ProcessLFP)
    // Inputs: 0) data buffer, 1) position in buffer (Perl pos($$buffPt), undef = start)
    // Returns: JSON object (scalar, hash ref, or array ref), or undef on EOF or
    //          empty object or array
    // Notes: position in buffer is significant
    // (PHP: a JSON object is a string-keyed array with an '_ordered_keys_' list, a JSON
    //  array is a list, numbers and true/false/null are kept as their text, like Perl)
    // ponytail: local port because Import.pm isn't ported; move it there when it is
    private static function ReadJSONObject(string $buff, ?int &$bpos)
    {
        // initialize buffer if necessary
        $readMore = $rtnVal = $tok = $key = null;
        $pos = $bpos;
        if ($pos === null) $pos = $bpos = 0;
        for (;;) {      // (Perl label Tok)
            // (didn't spend the time to understand how $pos could be undef, but
            //  put a test here to be safe because one user reported this problem)
            if ($pos === null) break;
            if ($pos >= strlen($buff) or $readMore) {
                break;  // (no RAF to read more from)
            }
            if (!$tok) {
                // skip white space and find next character
                if (!self::NextChar($buff, $bpos, $c)) {
                    $pos = strlen($buff);
                    continue;
                }
                $tok = $c;
                $pos = $bpos;
            }
            // see what type of object this is
            if ($tok === '{') {     // object (hash)
                if ($rtnVal === null) $rtnVal = ['_ordered_keys_' => []];
                for (;;) {
                    // read "KEY":"VALUE" pairs
                    if ($key === null) {
                        $key = self::ReadJSONObject($buff, $bpos);
                        $pos = $bpos;
                    }
                    // ($key may be undef for empty JSON object)
                    if ($key !== null) {
                        // scan to delimiting ':'
                        if (!self::NextChar($buff, $bpos, $c)) {
                            $readMore = 1;
                            continue 2;
                        }
                        if ($c !== ':') return null;    // error if not a colon
                        $val = self::ReadJSONObject($buff, $bpos);
                        $pos = $bpos;
                        if ($val === null) return null;
                        $key = Perl::str($key);
                        $rtnVal[$key] = $val;
                        $rtnVal['_ordered_keys_'][] = $key;
                        $key = null;
                    }
                    // scan to delimiting ',' or bounding '}'
                    if (!self::NextChar($buff, $bpos, $c)) {
                        $readMore = 1;
                        continue 2;
                    }
                    if ($c === '}') break;      // check for end of object
                    if ($c !== ',') return null;    // error if not a comma
                }
            } elseif ($tok === '[') {   // array
                if ($rtnVal === null) $rtnVal = [];
                for (;;) {
                    $item = self::ReadJSONObject($buff, $bpos);
                    $pos = $bpos;
                    // ($item may be undef for empty array)
                    if ($item !== null) $rtnVal[] = $item;
                    // scan to delimiting ',' or bounding ']'
                    if (!self::NextChar($buff, $bpos, $c)) {
                        $readMore = 1;
                        continue 2;
                    }
                    if ($c === ']') break;      // check for end of array
                    if ($c !== ',') return null;    // error if not a comma
                }
            } elseif ($tok === '"') {   // quoted string
                $p = $bpos;
                for (;;) {
                    if (!preg_match('/(\\\\*)"/', $buff, $m, PREG_OFFSET_CAPTURE, (int) $p)) {
                        $bpos = null;
                        $readMore = 1;
                        continue 2;
                    }
                    $p = $m[0][1] + strlen($m[0][0]);
                    if (!(strlen($m[1][0]) & 1)) break; // check for escaped quote
                }
                $bpos = $p;
                $rtnVal = substr($buff, $pos, $bpos - $pos - 1);
                // unescape characters
                $rtnVal = preg_replace_callback('/\\\\u([0-9a-f]{4})/i',
                    static fn ($m) => Charset::Recompose(null, [hexdec($m[1])]), $rtnVal);
                $rtnVal = preg_replace_callback('/\\\\(.)/s',
                    static fn ($m) => self::UNESCAPE_JSON[$m[1]] ?? $m[1], $rtnVal);
                // decode base64 (binary data) values
                if (preg_match('/^base64:[A-Za-z0-9+\/]*={0,2}$/', $rtnVal) and strlen($rtnVal) % 4 == 3) {
                    $rtnVal = XMP::DecodeBase64(substr($rtnVal, 7))->value;
                }
            } elseif ($tok === ']' or $tok === '}' or $tok === ',') {
                // return undef for empty object, array, or list item
                // (empty list item actually not valid JSON)
                $bpos = $bpos - 1;
            } else {                // number, 'true', 'false', 'null'
                if (!preg_match('/[\s:,\}\]]/', $buff, $m, PREG_OFFSET_CAPTURE, (int) $bpos)) {
                    $bpos = null;
                    $readMore = 1;
                    continue;
                }
                $bpos = $m[0][1];
                $rtnVal = $tok . substr($buff, $pos, $bpos - $pos);
            }
            break;
        }
        return $rtnVal;
    }

    // Perl: $$buffPt =~ /(\S)/g (starts at pos, or 0 if undef; sets pos after the
    // character, or resets it to undef on failure)
    private static function NextChar(string $buff, ?int &$bpos, &$c): bool
    {
        if (!preg_match('/\S/', $buff, $m, PREG_OFFSET_CAPTURE, $bpos ?? 0)) {
            $bpos = null;
            return false;
        }
        $c = $m[0][0];
        $bpos = $m[0][1] + 1;
        return true;
    }
}
