<?php

//------------------------------------------------------------------------------
// File:         DjVu.pm
//
// Description:  Read DjVu archive meta information
//
// Revisions:    09/25/2008 - P. Harvey Created
//
// References:   1) http://djvu.sourceforge.net/ (DjVu v3 specification, Nov 2005)
//               2) http://www.djvu.org/
//
// Notes:        DjVu files are recognized and the IFF structure is processed
//               by Image::ExifTool::AIFF
//
// PHP port of Image::ExifTool::DjVu 1.07 (reading only). Tag tables are generated into
// tables/DjVu.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class DjVu
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Parse DjVu annotation "s-expression" syntax (recursively)
    // Inputs: 0) data ref (with pos($$dataPt) set to start of annotation)
    // Returns: reference to list of tokens/references, or undef if no tokens,
    //          and the position in $$dataPt is set to end of last token
    // Notes: The DjVu annotation syntax is not well documented, so I make
    //        a number of assumptions here!
    // (PHP: $pos is the Perl pos($$dataPt), shared by the recursive calls; a failed
    //  Perl m//g match resets it to 0)
    public static function ParseAnt($dataPt, &$pos = 0)
    {
        $toks = [];
        $tok = $more = null;
        // (the DjVu annotation syntax really sucks, and requires that every
        // single token be parsed in order to properly scan through the items)
        for (;;) {      // Tok:
            // find the next token
            if (!preg_match('/(\S)/s', $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) {  // get next non-space character
                $pos = 0;
                break;
            }
            $pos = $m[1][1] + 1;
            if ($m[1][0] === '(') {       // start of list
                $tok = self::ParseAnt($dataPt, $pos);
            } elseif ($m[1][0] === ')') {  // end of list
                $more = 1;
                break;
            } elseif ($m[1][0] === '"') {  // quoted string
                $tok = '';
                for (;;) {
                    // get string up to the next quotation mark
                    $p = $pos;
                    $q = strpos($dataPt, '"', $pos);
                    if ($q === false) {
                        $pos = 0;
                        break 2;    // last Tok
                    }
                    $pos = $q + 1;
                    $tok .= substr($dataPt, $p, $pos - 1 - $p);
                    // we're good unless quote was escaped by odd number of backslashes
                    if (!(preg_match('/(\\\\+)$/', $tok, $m) and strlen($m[1]) & 0x01)) break;
                    $tok .= '"';    // quote is part of the string
                }
                // convert C escape sequences, allowed in quoted text
                // (note: this only converts a few of them!)
                $esc = ['a' => "\x07", 'b' => "\x08", 'f' => "\x0c", 'n' => "\n",
                        'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\'];
                $tok = preg_replace_callback('/\\\\(.)/s', fn($m) => $esc[$m[1]] ?? '\\' . $m[1], $tok);
            } else {                // key name
                $pos = $pos - 1;
                // allow anything in key but whitespace, braces and double quotes
                // (this is one of those assumptions I mentioned)
                if (preg_match('/([^\s()"]+)/s', $dataPt, $m, PREG_OFFSET_CAPTURE, $pos)) {
                    $tok = $m[1][0];
                    $pos = $m[1][1] + strlen($tok);
                } else {
                    $tok = null;
                    $pos = 0;
                }
            }
            if ($tok !== null) $toks[] = $tok;
        }
        // prevent further parsing unless more after this
        if (!$more) $pos = strlen($dataPt);
        return $toks ?: null;
    }

    //------------------------------------------------------------------------------
    // Process DjVu annotation chunk (ANTa or decoded ANTz)
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessAnt(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];

        // quick pre-scan to check for metadata or XMP
        if (!preg_match('/\(\s*(metadata|xmp)[\s("]/s', $dataPt)) return 1;

        // parse annotations into a tree structure
        $pos = 0;
        $toks = self::ParseAnt($dataPt, $pos);
        if (!$toks) return 0;

        // process annotations individually
        foreach ($toks as $ant) {
            if (!(is_array($ant) and count($ant) >= 2)) continue;
            $tag = array_shift($ant);
            if (is_array($tag) or !isset($tagTablePtr[$tag])) continue;
            if ($tag === 'metadata') {
                // ProcessMeta() takes array reference
                $et->HandleTag($tagTablePtr, $tag, $ant);
            } else {
                if (is_array($ant[0])) continue;   // only process simple values
                $et->HandleTag($tagTablePtr, $tag, $ant[0]);
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process DjVu metadata
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ref
    // Returns: 1 on success
    // Notes: input dirInfo DataPt is a reference to a list of pre-parsed metadata entries
    public static function ProcessMeta(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if (!(is_array($dataPt) and array_is_list($dataPt))) return 0;
        $et->VerboseDir('Metadata', count($dataPt));
        $err = null;
        foreach ($dataPt as $item) {
            // make sure item is a simple tag/value pair
            if (!(is_array($item) and count($item) >= 2 and
                  !is_array($item[0]) and !is_array($item[1])))
            {
                $err = 1;
                continue;
            }
            // add any new tags to the table
            if (!$tagTablePtr[$item[0]]) {
                $name = $item[0];
                $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name); // remove illegal characters
                if (!strlen($name)) {
                    $err = 1;
                    continue;
                }
                ExifTool::AddTagToTable($tagTablePtr, $item[0], ['Name' => ucfirst($name)]);
            }
            $et->HandleTag($tagTablePtr, $item[0], $item[1]);
        }
        if ($err) $et->Warn('Ignored invalid metadata entry(s)');
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process BZZ-compressed data (in DjVu images)
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessBZZ(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $buff = BZZ::Decode($dirInfo['DataPt']);
        if ($buff === null) {
            $et->Warn("Error decoding {$dirInfo['DirName']}");
            return 0;
        }
        $verbose = $et->Options('Verbose');
        if ($verbose >= 3) {
            // dump the decoded data in very verbose mode
            $et->VerboseDir("Decoded {$dirInfo['DirName']}", 0, strlen($buff));
            $et->VerboseDump($buff);
        }
        $dirInfo['DataPt'] = $buff;
        $dirInfo['DataLen'] = $dirInfo['DirLen'] = strlen($buff);
        // process the data using the default process proc for this table
        $processProc = $tagTablePtr['PROCESS_PROC'];
        if (!$processProc) return 0;
        return $et->CallProc($processProc, $dirInfo, $tagTablePtr);
    }
}
