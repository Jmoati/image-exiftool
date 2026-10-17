<?php

//------------------------------------------------------------------------------
// File:         VCard.pm
//
// Description:  Read vCard and iCalendar meta information
//
// Revisions:    2015/04/05 - P. Harvey Created
//               2015/05/02 - PH Added iCalendar support
//
// References:   1) http://en.m.wikipedia.org/wiki/VCard
//               2) http://tools.ietf.org/html/rfc6350
//               3) http://tools.ietf.org/html/rfc5545
//
// PHP port of Image::ExifTool::VCard 1.07 (reading only). Tag tables and file lexicals
// (%unescapeVCard, %isComponent, %timeInfo) are generated into tables/VCard.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class VCard
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Get vCard tag, creating if necessary
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) tag ID, 3) tag Name,
    //         4) source tagInfo ref, 5) lang code
    // Returns: tagInfo ref
    public static function GetVCardTag(ExifTool $et, TagTable $tagTablePtr, $tag, $name, $srcInfo = null, $langCode = null)
    {
        $tagInfo = $tagTablePtr[$tag];
        if (!$tagInfo) {
            if ($srcInfo) {
                $tagInfo = $srcInfo->toArray();
            } else {
                $tagInfo = [];
                $et->VPrint(0, $et->INDENT, "[adding $tag]\n");
            }
            $tagInfo['Name'] = $name;
            unset($tagInfo['Description']);  // create new description
            $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
        }
        // handle alternate languages (the "language" parameter)
        if ($langCode) $tagInfo = ExifTool::GetLangInfo($tagInfo, $langCode);
        return $tagInfo;
    }

    //------------------------------------------------------------------------------
    // Decode vCard text
    // Inputs: 0) ExifTool ref, 1) vCard text, 2) encoding
    // Returns: decoded text (or array ref for a list of values)
    public static function DecodeVCardText(ExifTool $et, $val, $enc = null)
    {
        $enc = $enc !== null ? strtolower($enc) : '';
        if ($enc === 'b' or $enc === 'base64') {
            ExifTool::RequireModule('Image::ExifTool::XMP');
            $val = XMP::DecodeBase64($val);
        } else {
            if ($enc === 'quoted-printable') {
                // convert "=HH" hex codes to characters
                $val = preg_replace_callback('/=([0-9a-f]{2})/i', fn($m) => chr(hexdec($m[1])), $val);
            }
            $val = $et->Decode($val, 'UTF8');   // convert from UTF-8
            // convert unescaped commas to nulls to separate list items
            $val = preg_replace_callback('/(\\\\.)|(,)/s', fn($m) => ($m[1] ?? '') !== '' ? $m[1] : "\0", $val);
            // unescape necessary characters in value
            $val = preg_replace_callback('/\\\\(.)/s', fn($m) => Vars\VCard::$unescapeVCard[$m[1]] ?? $m[1], $val);
            // split into list if necessary
            $vals = Perl::split('/\0/', $val);
            if (count($vals) > 1) $val = $vals;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Read information in a vCard file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid vCard file
    public static function ProcessVCard(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $val = $ok = $component = null;
        $compNum = $count = [];

        if (!($raf->Read($buff, 24) and $raf->Seek(0, 0) and preg_match('/^BEGIN:(VCARD|VCALENDAR|VNOTE)\r\n/i', $buff, $m))) return 0;
        $info = [
            'VCARD'     => ['VCard', 'vCard', 'Main', 'VCF'],
            'VCALENDAR' => ['ICS', 'iCalendar', 'VCalendar', 'ICS'],
            'VNOTE'     => ['VNote', 'vNote', 'VNote', 'VNT', 'text/v-note'],
        ];
        [$type, $lbl, $tbl, $ext, $mime] = $info[strtoupper($m[1])] + [4 => null];
        $et->SetFileType($type, $mime, $ext);
        if ($et->OPTIONS['FastScan'] and $et->OPTIONS['FastScan'] == 3) return 1;
        $saveRS = Perl::$RS;
        Perl::$RS = "\r\n";     // (local $/)
        try {
            $tagTablePtr = ExifTool::GetTagTable("Image::ExifTool::VCard::$tbl");
            $more = $raf->ReadLine($buff);   // read first line
            if ($more) Perl::chomp($buff);
            while ($more) {
                // retrieve previous line from $buff
                if ($buff !== null) $val = $buff;
                // read ahead to next line to see if is a continuation
                $more = $raf->ReadLine($buff);
                if ($more) {
                    Perl::chomp($buff);
                    // add continuation line if necessary
                    if (preg_match('/^[ \t]/', $buff)) {
                        $val .= substr($buff, 1);
                        $buff = null;
                        continue;
                    }
                }
                if (preg_match('/^(BEGIN|END):(V?)(\w+)$/i', $val, $m)) {
                    [$begin, $v, $what] = [(strtolower($m[1]) === 'begin' ? 1 : 0), $m[2], ucfirst(strtolower($m[3]))];
                    if ($what === 'Card' or $what === 'Calendar' or $what === 'Note') {
                        if ($begin) {
                            $count = [[]];  // reset group counters
                        } else {
                            $ok = 1;    // ok if we read at least on full VCARD or VCALENDAR
                        }
                        continue;
                    }
                    // absorb top-level component into family 1 group name
                    if (!empty(Vars\VCard::$isComponent[$what])) {
                        if ($begin) {
                            if (!$component) {
                                // begin a new top-level component
                                $count = [[]];
                                $component = $what;
                                $compNum[$component] = ($compNum[$component] ?? 0) + 1;
                                continue;
                            }
                        } elseif ($component and $component === $what) {
                            // this top-level component has ended
                            $component = null;
                            continue;
                        }
                    }
                    // keep count of each component at this level
                    if ($begin) {
                        if ($v) $count[count($count) - 1][$what] = ($count[count($count) - 1][$what] ?? 0) + 1;
                        $count[] = ['obj' => $what];
                    } elseif (count($count) > 1) {
                        array_pop($count);
                    }
                    continue;
                } elseif ($ok) {
                    $ok = 0;
                    $et->DOC_NUM = ++$et->DOC_COUNT;  // read next card as a new document
                }
                if (!preg_match('/^([-A-Za-z0-9.]+)/', $val, $m)) {
                    $et->Warn("Unrecognized line in $lbl file");
                    continue;
                }
                $val = substr($val, strlen($m[1]));
                $tag = $m[1];
                // set group if it exists
                if (preg_match('/^([-A-Za-z0-9]+)\./', $tag, $m)) {
                    $tag = substr($tag, strlen($m[0]));
                    $et->SET_GROUP1 = ucfirst(strtolower($m[1]));
                } elseif ($component) {
                    $et->SET_GROUP1 = $component . $compNum[$component];
                } else {
                    $et->SET_GROUP1 = null;
                }
                $name = null;
                $param = [];
                // vCard tag ID's are case-insensitive, so normalize to lowercase with
                // an uppercase first letter for use as a tag name
                if (preg_match('/[a-z]/', $tag)) $name = ucfirst($tag);    // preserve mixed case in name if it exists
                $tag = ucfirst(strtolower($tag));
                // get source tagInfo reference
                $srcInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if ($srcInfo) {
                    $name = $srcInfo->Name;    // use our name
                } else {
                    if (!$name) $name = $tag;
                    // remove leading "X-" from name if it exists
                    if (str_starts_with($name, 'X-')) $name = ucfirst(substr($name, 2));
                }
                // add object name(s) to tag if necessary
                if (count($count) > 1) {
                    for ($i = count($count) - 2; $i >= 0; --$i) {
                        // (Perl $count[$i-1] is the last element when $i is 0)
                        $pre = $count[$i - 1 < 0 ? count($count) - 1 : $i - 1]['obj'] ?? null;  // use containing object name as tag prefix
                        $c = $count[$i][$pre ?? ''] ?? null;   // add index for object number
                        if ($c === null) $c = '';
                        $tag = $pre . $c . $tag;
                        $name = $pre . $c . $name;
                    }
                }
                // parse parameters
                while (preg_match('/^;([-A-Za-z0-9]*)(=?)/', $val, $m)) {
                    $val = substr($val, strlen($m[0]));
                    $p = ucfirst(strtolower($m[1]));
                    // convert old vCard 2.x parameters to the new "TYPE=" format
                    if (!$m[2]) {
                        $val = $m[1] . $val;
                        $p = 'Type';
                    }
                    // read parameter value
                    for (;;) {
                        if (!(preg_match('/^"([^"]*)",?/', $val, $m) or preg_match('/^([^";:,]+,?)/', $val, $m))) break;
                        $val = substr($val, strlen($m[0]));
                        $v = $p === 'Type' ? ucfirst(strtolower($m[1])) : $m[1];
                        $param[$p] = isset($param[$p]) ? $param[$p] . $v : $v;
                    }
                    if (isset($param[$p])) {
                        $param[$p] = preg_replace_callback('/\\\\(.)/s', fn($m) => Vars\VCard::$unescapeVCard[$m[1]] ?? $m[1], $param[$p]);
                    } else {
                        $param[$p] = '';
                    }
                }
                if (!str_starts_with($val, ':')) {
                    $et->Warn("Invalid line in $lbl file");
                    continue;
                }
                $val = substr($val, 1);
                // add 'Type' parameter to id and name if it exists
                if (!empty($param['Type'])) {
                    $tag .= $param['Type'];
                    $name .= $param['Type'];
                }
                // convert base64-encoded data
                if (preg_match('{^data:(\w+)/(\w+);base64,}', $val, $m)) {
                    $val = substr($val, strlen($m[0]));
                    $xtra = ucfirst(strtolower($m[1])) . ucfirst(strtolower($m[2]));
                    $tag .= $xtra;
                    $name .= $xtra;
                    $param['Encoding'] = 'base64';
                }
                $val = self::DecodeVCardText($et, $val, $param['Encoding'] ?? null);
                $tagInfo = self::GetVCardTag($et, $tagTablePtr, $tag, $name, $srcInfo, $param['Language'] ?? null);
                $et->HandleTag($tagTablePtr, $tag, $val, TagInfo: $tagInfo);
                // handle some other parameters that we care about (ignore the rest for now)
                foreach (['Geo', 'Label', 'Tzid'] as $p) {
                    if (!isset($param[$p])) continue;
                    // use tag attributes from our table if it exists
                    $srcTag2 = $et->GetTagInfo($tagTablePtr, $p);
                    $pn = $srcTag2 ? $srcTag2->Name : $p;
                    $val = self::DecodeVCardText($et, $param[$p]);
                    // add parameter to tag ID and name
                    [$tg, $nm] = [$tag . $p, $name . $pn];
                    $tagInfo = self::GetVCardTag($et, $tagTablePtr, $tg, $nm, $srcTag2, $param['Language'] ?? null);
                    $et->HandleTag($tagTablePtr, $tg, $val, TagInfo: $tagInfo);
                }
            }
        } finally {
            Perl::$RS = $saveRS;
        }
        $et->SET_GROUP1 = null;
        $et->DOC_NUM = null;
        if (!$ok) $et->Warn("Missing $lbl end");
        return 1;
    }
}
