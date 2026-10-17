<?php

//------------------------------------------------------------------------------
// File:         RTF.pm
//
// Description:  Read Rich Text Format meta information
//
// Revisions:    2010/06/17 - P. Harvey Created
//
// References:   1) http://download.microsoft.com/download/2/f/5/2f599e18-07ee-4ec5-a1e7-f4e6a9423592/Word2007RTFSpec9.doc
//               2) http://search.cpan.org/dist/RTF-Writer/lib/RTF/Cookbook.pod
//
// PHP port of Image::ExifTool::RTF 1.04. Tag tables are generated into tables/RTF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class RTF
{
    public static $VERSION = '1.04';

    //------------------------------------------------------------------------------
    // Read to nested closing curly bracket "}"
    // Inputs: 0) data ref, 1) optional RAF ref to read more data if available
    //         (PHP: 2) pos($$dataPt), updated by reference)
    // Returns: text inside brackets, or undef on error
    // Notes: On entry the current position in the data must be set to immediately
    //        after the command that opens the bracket.  On return the current
    //        position is immediately following the closing brace if the return
    //        value is defined.
    public static function ReadToNested(&$dataPt, $raf, &$dpos)
    {
        $pos = (int) $dpos;
        $level = 1;
        for (;;) {
            // look for the next bracket
            if (!preg_match('/(\\\\*)([{}])/', $dataPt, $m, PREG_OFFSET_CAPTURE, (int) $dpos)) {
                $dpos = null;
                // must read some more data
                $p = strlen($dataPt);
                $buff = null;
                if (!($raf and $raf->Read($buff, 65536))) break;
                $dataPt .= $buff;
                // rewind position to include any leading backslashes
                while ($p and substr($dataPt, $p - 1, 1) === '\\') --$p;
                $dpos = $p; // set position to continue search
                continue;
            }
            $dpos = $m[0][1] + strlen($m[0][0]);
            // bracket is escaped if preceded by an odd number of backslashes
            if ($m[1][0] !== '' and strlen($m[1][0]) & 0x01) continue;
            if ($m[2][0] === '{') {
                ++$level;
                continue;
            }
            if (!(--$level <= 0)) continue;
            return substr($dataPt, $pos, $dpos - $pos - 1);
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Unescape RTF escape sequences
    // Inputs: 0) ExifTool ref, 1) RTF text, 2) RTF character set (for hex characters)
    // Returns: Unescaped text (in current ExifTool Charset)
    public static function UnescapeRTF(ExifTool $et, $val, $charset)
    {
        // return now unless we have a control sequence
        if (!str_contains($val, '\\')) {
            return str_replace(["\n", "\r"], '', $val);    // ignore CR's and LF's
        }
        // CR/LF is significant if it terminates a control sequence (so change these to a space)
        // (was $val =~ s/(^|[^\\])((?:\\\\)*)(\\[a-zA-Z]+(?:-?\d+)?)[\n\r]/$1$2$3 /g;)
        $val = preg_replace_callback('/\\\\(?:([a-zA-Z]+(?:-?\d+)?)[\n\r]|(.))/s',
            static fn ($m) => '\\' . (($m[1] ?? '') !== '' ? "$m[1] " : $m[2]), $val);
        // protect the newline control sequence by converting to a \par command
        // (was $val =~ s/(^|[^\\])((?:\\\\)*)(\\[\n\r])/$1$2\\par /g;)
        $val = preg_replace_callback('/(\\\\[\n\r])|(\\\\.)/s', static fn ($m) => ($m[2] ?? '') ?: '\\par ', $val);
        // all other CR/LF's are ignored (so delete them)
        $val = str_replace(["\n", "\r"], '', $val);

        $rtnVal = '';
        $len = strlen($val);
        $skip = 1;  // default Unicode skip count
        $p0 = 0;
        $vpos = null;   // (Perl pos($val))

        for (;;) {
            // find next backslash
            $p1 = strpos($val, '\\', (int) $vpos);
            $p1 = $vpos = ($p1 === false ? null : $p1 + 1);
            if ($p1 === null) $p1 = $len + 1;
            // add text up to start of this control sequence (or up to end)
            $n = $p1 - $p0 - 1;
            if ($n > 0) $rtnVal .= substr($val, $p0, $n);
            // all done if at the end or if control sequence is empty
            if ($p1 >= $len) break;
            // look for an ASCII-letter control word or Unicode control
            if (preg_match('/\G([a-zA-Z]+)(-?\d+)? ?/', $val, $m, PREG_UNMATCHED_AS_NULL, $vpos)) {
                $vpos += strlen($m[0]);
                // interpret command if recognized
                if ($m[1] === 'uc') {       // \ucN
                    $skip = $m[2];
                } elseif ($m[1] === 'u') {  // \uN
                    if (Perl::num($m[2]) < 0) {
                        $et->Warn('Invalid Unicode character(s) in text');
                        $rtnVal .= '?';
                    } else {
                        $rtnVal .= Charset::Recompose($et, [Perl::num($m[2])]);
                        if ($skip) {
                            // must skip the specified number of characters
                            // (not simple because RTF control words count as a single character)
                            if (!preg_match("/\\G([^\\\\]|\\\\([a-zA-Z]+)(-?\\d+)? ?|\\\\'.{2}|\\\\.){{$skip}}/", $val, $m, 0, $vpos)) break;
                            $vpos += strlen($m[0]);
                        }
                    }
                } elseif (!empty(Vars\RTF::$rtfEntity[$m[1]])) {
                    $rtnVal .= Charset::Recompose($et, [Vars\RTF::$rtfEntity[$m[1]]]);
                } // (else ignore the command)
            } else {
                $ch = substr($val, $p1, 1);
                if ($ch === "'") {
                    // hex character code
                    if ($p1 + 3 > $len) break;
                    $hex = substr($val, $p1 + 1, 2);
                    if (preg_match('/^[0-9a-fA-F]{2}$/', $hex)) {
                        $rtnVal .= $et->Decode(chr(hexdec($hex)), $charset);
                    }
                    $vpos = $p1 + 3;    // skip to after the hex code
                } else {
                    // assume a standard control symbol (\, {, }, etc)
                    // (note, this may not be valid for some uncommon
                    //  control symbols like \~ for non-breaking space)
                    $rtnVal .= $ch;
                    $vpos = $p1 + 1;    // skip to after this character
                }
            }
            $p0 = $vpos;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Read information in a RTF document
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid RTF file
    public static function ProcessRTF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $cs = null;

        if (!($raf->Read($buff, 64) and $raf->Seek(0, 0))) return 0;
        if (!preg_match('/^[\n\r]*\{[\n\r]*\\\\rtf[^a-zA-Z]/', $buff)) return 0;
        $et->SetFileType();
        //
        // determine the RTF character set
        //
        if (preg_match('/\\\\ansicpg(\d*)/', $buff, $m)) {
            $cs = "cp$m[1]";
        } elseif (preg_match('/\\\\(ansi|mac|pc|pca)[^a-zA-Z]/', $buff, $m)) {
            $trans = [
                'ansi' => 'Latin',
                'mac'  => 'MacRoman',
                'pc'   => 'cp437',
                'pca'  => 'cp850',
            ];
            $cs = $trans[$m[1]];
        } else {
            $et->Warn('Unspecified RTF encoding. Will assume Latin');
            $cs = 'Latin';
        }
        $charset = Vars\ExifTool::$charsetName[strtolower($cs)] ?? null;
        if (!$charset) {
            $et->Warn("Unsupported RTF encoding $cs. Will assume Latin.");
            $charset = 'Latin';
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::RTF::Main');
        $buff = null;
        $bpos = null;   // (Perl pos($buff))
        //
        // scan for \info group
        //
        for (;;) {
            if (!$raf->Read($buf2, 65536)) break;
            if ($buff !== null) {
                // read more but leave some overlap for the match
                $buff = substr($buff, -16) . $buf2;
            } else {
                $buff = $buf2;
            }
            if (!preg_match('/[^\\\\]\{[\n\r]*\\\\info([^a-zA-Z])/', $buff, $m, PREG_OFFSET_CAPTURE)) continue;
            $bpos = $m[0][1] + strlen($m[0][0]);
            // anything but a space is included in the contents
            if ($m[1][0] !== ' ') $bpos = $bpos - 1;
            $info = self::ReadToNested($buff, $raf, $bpos);
            if ($info === null) {
                $et->Warn('Unterminated information group');
                break;
            }
            // process info commands (eg. "\author", "\*\copyright");
            $ipos = 0;
            while (preg_match('/\{[\n\r]*(\\\\\*[\n\r]*)?\\\\([a-zA-Z]+)([^a-zA-Z])/', $info, $m, PREG_OFFSET_CAPTURE, (int) $ipos)) {
                $ipos = $m[0][1] + strlen($m[0][0]);
                if ($m[3][0] !== ' ') $ipos = $ipos - 1;
                $tag = $m[2][0];
                $val = self::ReadToNested($info, null, $ipos);
                if ($val === null) break;
                $tagInfo = $tagTablePtr[$tag];
                if ($tagInfo and $tagInfo->Format and $tagInfo->Format === 'date') {
                    // parse RTF date commands
                    $idx = ['yr' => 0, 'mo' => 1, 'dy' => 2, 'hr' => 3, 'min' => 4, 'sec' => 5];
                    $t = array_fill(0, 6, 0);
                    preg_match_all('/\\\\([a-z]+)(\d+)/', $val, $mm, PREG_SET_ORDER);
                    foreach ($mm as $d) {
                        if (!isset($idx[$d[1]])) continue;
                        $t[$idx[$d[1]]] = $d[2];
                    }
                    $val = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d', ...$t);
                } else {
                    // unescape RTF string value
                    $val = self::UnescapeRTF($et, $val, $charset);
                }
                // create tagInfo for unknown tags
                if (!$tagInfo) {
                    ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => ucfirst($tag)]);
                }
                $et->HandleTag($tagTablePtr, $tag, $val);
            }
        }
        if ($buff === null) return 1;
        //
        // scan for \userprops (but don't read more from file to find the start of this command)
        //
        $bpos = 0;
        while (preg_match('/[^\\\\]\{[\n\r]*\\\\\*[\n\r]*\\\\userprops([^a-zA-Z])/', $buff, $m, PREG_OFFSET_CAPTURE, (int) $bpos)) {
            // Note: The RTF spec places brackets around each propinfo structure,
            // but Microsoft Word doesn't write it this way, so tolerate either.
            $bpos = $m[0][1] + strlen($m[0][0]);
            if ($m[1][0] !== ' ') $bpos = $bpos - 1;
            $props = self::ReadToNested($buff, $raf, $bpos);
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::RTF::UserProps');
            if ($props === null) {
                $et->Warn('Unterminated user properties');
                break;
            }
            // process user properties
            $tag = null;
            $ppos = 0;
            while (preg_match('/\{[\n\r]*(\\\\\*[\n\r]*)?\\\\([a-zA-Z]+)([^a-zA-Z])/', $props, $m, PREG_OFFSET_CAPTURE, (int) $ppos)) {
                $ppos = $m[0][1] + strlen($m[0][0]);
                if ($m[3][0] !== ' ') $ppos = $ppos - 1;
                $t = $m[2][0];
                $val = self::ReadToNested($props, null, $ppos);
                if ($val === null) break;
                $val = self::UnescapeRTF($et, $val, $charset);
                if ($t === 'propname') {
                    $tag = $val;
                    continue;
                } elseif ($t !== 'staticval' or $tag === null) {
                    continue;   // ignore \linkval and \proptype for now
                }
                $tag = preg_replace_callback('/\s(.)/', static fn ($m) => strtoupper($m[1]), $tag); // capitalize all words in tag name
                $tag = preg_replace('/[^-_a-zA-Z0-9]/', '', $tag);   // remove illegal characters
                if (!$tag) continue;
                // create tagInfo for unknown tags
                if (!$tagTablePtr[$tag]) {
                    ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $tag]);
                }
                $et->HandleTag($tagTablePtr, $tag, $val);
            }
            break;  // (didn't really want to loop)
        }
        return 1;
    }
}
