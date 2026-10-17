<?php

//------------------------------------------------------------------------------
// File:         HTML.pm
//
// Description:  Read HTML meta information
//
// Revisions:    01/30/2007 - P. Harvey Created
//
// References:   1) http://www.w3.org/TR/html4/
//               2) http://www.daisy.org/publications/specifications/daisy_202.html
//               3) http://vancouver-webpages.com/META/metatags.detail.html
//               4) http://www.html-reference.com/META.htm
//
// PHP port of Image::ExifTool::HTML 1.16. Tag tables are generated into tables/HTML.php,
// %htmlCharset, %entityNum and %entityName are in tables/vars/HTML.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class HTML
{
    public static $VERSION = '1.16';

    //------------------------------------------------------------------------------
    // Set HTMLCharset member based on content type
    // Inputs: 0) content type string, 1) ExifTool ref
    // Returns: original string
    public static function SetHTMLCharset($val, ExifTool $et)
    {
        if (preg_match('/charset=[\'"]?([-\w]+)/', Perl::str($val), $m)) {
            $et->HTMLCharset = Vars\HTML::$htmlCharset[strtolower($m[1])] ?? null;
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Convert single UTF-8 character to HTML character reference
    // Inputs: 0) UTF-8 character sequence
    // Returns: HTML character reference (eg. "&quot;");
    // Note: Must be called via EscapeHTML to load name lookup
    public static function EscapeChar($ch)
    {
        // the meaning of "U0" is reversed as of Perl 5.10.0!
        $val = Perl::unpack('C0U', $ch)[0] ?? null;
        if ($val === null) return '?';
        if (!empty(Vars\HTML::$entityName[$val])) return '&' . Vars\HTML::$entityName[$val] . ';';
        return Perl::sprintf('&#x%x;', $val);
    }

    //------------------------------------------------------------------------------
    // Escape any special characters for HTML
    // Inputs: 0) string to be escaped, 1) optional string encoding (default 'UTF8')
    // Returns: escaped string
    public static function EscapeHTML($str, $enc = null)
    {
        // escape XML characters
        $str = XMP::EscapeXML($str);
        // escape other special characters if they exist
        if (preg_match('/[\x80-\xff]/', $str)) {
            // generate entity name lookup if necessary
            if (!Vars\HTML::$entityName) {
                foreach (Vars\HTML::$entityNum as $name => $num) {
                    Vars\HTML::$entityName[$num] = $name;
                }
                unset(Vars\HTML::$entityName[39]);  // 'apos' is not valid HTML
            }
            $escape = static fn ($m) => self::EscapeChar($m[1]);
            if ($enc and $enc !== 'UTF8') {
                $str = ExifTool::DecodeStatic(null, $str, $enc, null, 'UTF8');
                $str = preg_replace_callback('/([\xc2-\xf7][\x80-\xbf]+)/s', $escape, $str);
                $str = ExifTool::DecodeStatic(null, $str, 'UTF8', null, $enc);
            } else {
                // escape any non-ascii characters for HTML
                $str = preg_replace_callback('/([\xc2-\xf7][\x80-\xbf]+)/s', $escape, $str);
            }
        }
        return $str;
    }

    //------------------------------------------------------------------------------
    // Unescape all HTML character references
    // Inputs: 0) string to be unescaped, 1) optional string encoding (default 'UTF8')
    // Returns: unescaped string
    public static function UnescapeHTML($str, $enc = null)
    {
        return XMP::UnescapeXML($str, Vars\HTML::$entityNum, $enc);
    }

    //------------------------------------------------------------------------------
    // Extract information from a HTML file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid HTML file
    public static function ProcessHTML(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // validate HTML or XHTML file
        if (!$raf->Read($buff, 256)) return 0;
        if (!preg_match('/^(\xef\xbb\xbf)?\s*<(!DOCTYPE\s+HTML|HTML|\?xml)/i', $buff, $m)) return 0;
        if ($m[2] === '?xml' and !preg_match('/<(!DOCTYPE\s+)?HTML/i', $buff)) return 0;
        $et->SetFileType();

        if (!$raf->Seek(0, 0)) {
            $et->Warn('Seek error');
            return 1;
        }

        $oldRS = Perl::$RS;     // (Perl: local $/)
        try {
            Perl::$RS = PostScript::GetInputRecordSeparator($raf);
            if (!Perl::$RS) {
                $et->Warn('Invalid HTML data');
                return 1;
            }

            // extract header information
            $doc = null;
            while ($raf->ReadLine($buff)) {
                if ($doc === null) {
                    // look for 'head' element
                    if (!preg_match('/<head\b/i', $buff, $m, PREG_OFFSET_CAPTURE)) continue;
                    $doc = substr($buff, $m[0][1] + strlen($m[0][0]));
                    continue;
                }
                $doc .= $buff;
                if (preg_match('{</head>}i', $buff)) break;
            }
            if ($doc === null) return 1;

            // process all elements in header
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::HTML::Main');
            $dpos = 0;  // (Perl pos($doc))
            for (;;) {
                if (!preg_match('{<([\w:.-]+)(.*?)>}s', $doc, $m, PREG_OFFSET_CAPTURE, $dpos)) break;
                $dpos = $m[0][1] + strlen($m[0][0]);
                [$tagName, $attrs] = [$m[1][0], $m[2][0]];
                $tag = strtolower($tagName);
                $val = $grp = null;
                if (preg_match('{/$}', $attrs)) {   // self-contained XHTML tags end in '/>'
                    $val = '';
                } else {
                    // look for element close
                    $pos = $dpos;
                    $close = "</$tagName>";
                    // the following doesn't work on Solaris Perl 5.6.1 due to Perl bug:
                    // if ($doc =~ m{(.*?)</$tagName>}sg) {
                    //     $val = $1;
                    // (Perl interpolates $close into the pattern: '.' in the tag name matches any character)
                    if (preg_match("#$close#s", $doc, $mc, PREG_OFFSET_CAPTURE, $dpos)) {
                        $dpos = $mc[0][1] + strlen($mc[0][0]);
                        $val = substr($doc, $pos, $dpos - $pos - strlen($close));
                    } else {
                        $dpos = $pos;
                        if ($tag !== 'meta') continue;  // META tags don't need to be closed
                        $val = '';
                    }
                }
                $table = $tagTablePtr;
                if ($tag === 'meta') {
                    // parse HTML META element
                    $tag = null;
                    // tag name is in NAME or HTTP-EQUIV attribute
                    if (preg_match('/\bname\s*=\s*[\'"]?([\w:.-]+)/si', $attrs, $m)) {
                        $tagName = $m[1];
                    } elseif (preg_match('/\bhttp-equiv\s*=\s*[\'"]?([\w:.-]+)/si', $attrs, $m)) {
                        $tagName = "HTTP-equiv.$m[1]";
                    } else {
                        continue;   // no name
                    }
                    $tag = strtolower($tagName);
                    if (!$tag) continue;
                    // tag value is in CONTENT attribute
                    if (preg_match('/\bcontent\s*=\s*([\'"])(.*?)\1/si', $attrs, $m) or
                        preg_match('/\bcontent\s*=\s*([\'"]?)([\w:.-]+)/si', $attrs, $m))
                    {
                        $val = $m[2];
                    } else {
                        if (!strlen($val)) continue;
                    }
                    // isolate group name (separator is '.' in HTML, but ':' in ref 2)
                    if (preg_match('/^([\w-]+)[:.]([\w-]+)/', $tag, $m)) {
                        [$grp, $tag] = [$m[1], $m[2]];
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $grp);
                        if ($tagInfo and $tagInfo->SubDirectory) {
                            $table = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                        } else {
                            $tag = "$grp.$tag";
                        }
                    }
                } elseif ($tag === 'xml') {
                    $et->VPrint(0, "Parsing XML\n");
                    // parse XML tags (quick-and-dirty)
                    $xml = $val;
                    preg_match_all('/<([\w-]+):([\w-]+)(\s.*?)?>([^<]*?)<\/\1:\2>/', $xml, $mm, PREG_SET_ORDER);
                    foreach ($mm as $m) {
                        [$grp, $tag, $val] = [$m[1], $m[2], $m[4]];
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $grp);
                        if (!($tagInfo and $tagInfo->SubDirectory)) continue;
                        $table = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                        if (!$table[$tag]) {
                            $name = ucfirst($tag);
                            $name = preg_replace_callback('/_x([0-9a-f]{4})_/i', static fn ($h) => Perl::chr(hexdec($h[1])), $name); // convert hex codes
                            $name = preg_replace_callback('/\s(.)/', static fn ($c) => strtoupper($c[1]), $name);  // capitalize all words in tag name
                            $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name); // remove illegal characters (also hex code wide chars)
                            ExifTool::AddTagToTable($table, $tag, ['Name' => $name]);
                            $et->VPrint(0, "  [adding $tag '$name']\n");
                        }
                        if ($et->HTMLCharset) $val = $et->Decode($val, $et->HTMLCharset);
                        $et->HandleTag($table, $tag, XMP::UnescapeXML($val));
                    }
                    continue;
                } else {
                    // the only other element we process is TITLE
                    if ($tag !== 'title') continue;
                }
                if (!$table[$tag]) {
                    $name = $tagName;
                    $name = preg_replace_callback('/\W+(\w)/s', static fn ($c) => ucfirst($c[1]), $name);
                    $info = ['Name' => $name, 'Groups' => [0 => 'HTML']];
                    if ($grp) $info['Groups'][1] = ($grp === 'http-equiv' ? 'HTTP-equiv' : "HTML-$grp");
                    ExifTool::AddTagToTable($table, $tag, $info);
                    $et->VPrint(0, "  [adding $tag '$tagName']\n");
                }
                // recode if necessary
                if ($et->HTMLCharset) $val = $et->Decode($val, $et->HTMLCharset);
                $val = preg_replace('/\s*' . preg_quote(Perl::$RS, '/') . '\s*/s', ' ', $val);   // replace linefeeds and indenting spaces
                $val = self::UnescapeHTML($val);    // unescape HTML character references
                $et->HandleTag($table, $tag, $val);
            }
            return 1;
        } finally {
            Perl::$RS = $oldRS;
        }
    }
}
