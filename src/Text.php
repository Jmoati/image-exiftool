<?php

//------------------------------------------------------------------------------
// File:         Text.pm
//
// Description:  Deduce characteristics of TXT and CSV files
//
// Revisions:    2019-11-01 - P. Harvey Created
//               2020-02-13 - PH Added CSV file support
//
// References:   1) https://github.com/file/file
//
// PHP port of Image::ExifTool::Text 1.05. Tag tables are generated into tables/Text.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Text
{
    public static $VERSION = '1.05';

    //------------------------------------------------------------------------------
    // Extract some stats from a text file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a text file
    public static function ProcessTXT(ExifTool $et, array &$dirInfo)
    {
        $dataPt = $dirInfo['TestBuff'];
        $raf = $dirInfo['RAF'];
        $fast = $et->Options('FastScan') ?: 0;
        $buff = $enc = $isBOM = $isUTF8 = null;
        $nl = '';

        if (!strlen((string) $dataPt)) return 0; // can't call it a text file if it has no text

        // read more from the file if necessary
        if ($fast < 3 and strlen($dataPt) == Vars\ExifTool::$testLen) {
            if (!$raf->Read($buff, 65536)) return 0;
            $dataPt = $buff;
        }
        //
        // make our best guess at the character encoding (EBCDIC is not supported)
        //
        if (preg_match('/([\0-\x06\x0e-\x1a\x1c-\x1f\x7f])/', $dataPt)) {
            // file contains weird control characters, could be multi-byte Unicode
            if (preg_match('/^(\xff\xfe\0\0|\0\0\xfe\xff)/', $dataPt, $m)) {
                if ($m[1] === "\xff\xfe\0\0") {
                    $enc = 'utf-32le';
                    if (preg_match('/(\r\0\0\0\n|\r|\n)\0\0\0/', $dataPt, $m)) $nl = $m[1];
                } else {
                    $enc = 'utf-32be';
                    if (preg_match('/\0\0\0(\r\0\0\0\n|\r|\n)/', $dataPt, $m)) $nl = $m[1];
                }
            } elseif (preg_match('/^(\xff\xfe|\xfe\xff)/', $dataPt, $m)) {
                if ($m[1] === "\xff\xfe") {
                    $enc = 'utf-16le';
                    if (preg_match('/(\r\0\n|\r|\n)\0/', $dataPt, $m)) $nl = $m[1];
                } else {
                    $enc = 'utf-16be';
                    if (preg_match('/\0(\r\0\n|\r|\n)/', $dataPt, $m)) $nl = $m[1];
                }
            } else {
                return 0;       // probably not a text file
            }
            $nl = str_replace("\0", '', $nl);  // remove nulls from newline sequence
            $isBOM = 1;         // (we don't recognize UTF-16/UTF-32 without one)
        } else {
            $isUTF8 = ExifTool::IsUTF8($dataPt, 1);
            if ($isUTF8 == 0) {
                $enc = 'us-ascii';
            } elseif ($isUTF8 > 0) {
                $enc = 'utf-8';
                $isBOM = (preg_match('/^\xef\xbb\xbf/', $dataPt) ? 1 : 0);
            } elseif (!preg_match('/[\x80-\x9f]/', $dataPt)) {
                $enc = 'iso-8859-1';
            } else {
                $enc = 'unknown-8bit';
            }
            if (preg_match('/(\r\n|\r|\n)/', $dataPt, $m)) $nl = $m[1];
        }

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Text::Main');

        $et->SetFileType();
        $et->HandleTag($tagTablePtr, 'MIMEEncoding', $enc);

        if ($fast == 3 or !$raf->Seek(0, 0)) return 1;

        if ($isBOM !== null) $et->HandleTag($tagTablePtr, 'ByteOrderMark', $isBOM);
        $et->HandleTag($tagTablePtr, 'Newlines', $nl);

        if ($fast or $isUTF8 === null) return 1;
        //
        // generate stats for CSV files
        //
        if ($et->FileType === 'CSV') {
            $delim = $quot = $ncols = null;
            $nrows = 0;
            while ($raf->ReadLine($buff)) {
                if ($delim === null) {
                    $count = [',' => 0, ';' => 0, "\t" => 0];
                    preg_match_all('/[,;\t]/', $buff, $mm);
                    foreach ($mm[0] as $ch) ++$count[$ch];
                    if ($count[','] > $count[';'] and $count[','] > $count["\t"]) {
                        $delim = ',';
                    } elseif ($count[';'] > $count["\t"]) {
                        $delim = ';';
                    } elseif ($count["\t"]) {
                        $delim = "\t";
                    } else {
                        $delim = '';
                        $ncols = 1;
                    }
                    if (!$ncols) {
                        // account for delimiters in quotes (simplistically)
                        preg_match_all("/(^|$delim)([\"'])(.*?)\\2(?=$delim|\$)/s", $buff, $mm, PREG_SET_ORDER);
                        foreach ($mm as $m) {
                            $quot = $m[2];
                            $field = $m[3];
                            $count[$delim] -= substr_count($field, $delim);
                        }
                        $ncols = $count[$delim] + 1;
                    }
                } elseif (!$quot) {
                    if (preg_match("/(^|$delim)([\"'])(.*?)\\2(?=$delim|\$)/s", $buff, $m)) $quot = $m[2];
                }
                if (++$nrows == 1000 and $et->Warn('Not counting rows past 1000', 2)) {
                    $nrows = null;
                    break;
                }
            }
            $et->HandleTag($tagTablePtr, 'Delimiter', ($delim ?: ''));
            $et->HandleTag($tagTablePtr, 'Quoting', ($quot ?: ''));
            $et->HandleTag($tagTablePtr, 'ColumnCount', $ncols);
            if ($nrows) $et->HandleTag($tagTablePtr, 'RowCount', $nrows);
            return 1;
        }
        if (($et->VALUE['FileSize'] ?? null) and $et->VALUE['FileSize'] > 20000000 and
            $et->Warn('Not counting lines/words in text file larger than 20 MB', 2)) return 1;
        //
        // count lines/words and check encoding of the rest of the file
        //
        [$lines, $words] = [0, 0];
        $oldNL = Perl::$RS;
        if ($nl) Perl::$RS = $nl;
        while ($raf->ReadLine($buff)) {
            ++$lines;
            $words += preg_match_all('/\S+/', $buff);
            if (!$nl and preg_match('/(\r\n|\r|\n)$/', $buff, $m)) {
                // (the first line must have been longer than 64 kB)
                $et->VALUE['Newlines'] = $nl = $m[1];
            }
            if ($raf->Tell() < 65536) continue;
            // continue to check encoding after the first 64 kB
            if ($isUTF8 >= 0) { // (if ascii or utf8)
                $isUTF8 = ExifTool::IsUTF8($buff);
                if ($isUTF8 > 0) {
                    $enc = 'utf-8';
                } elseif ($isUTF8 < 0) {
                    $enc = preg_match('/[\x80-\x9f]/', $buff) ? 'unknown-8bit' : 'iso-8859-1';
                }
            } elseif ($enc === 'iso-8859-1' and preg_match('/[\x80-\x9f]/', $buff)) {
                $enc = 'unknown-8bit';
            }
        }
        // ($$et{VALUE}{MIMEEncoding} may be undef if it was ignored)
        if (isset($et->VALUE['MIMEEncoding']) and $et->VALUE['MIMEEncoding'] !== $enc) {
            $et->VALUE['MIMEEncoding'] = $enc;
            $et->VPrint(0, "  MIMEEncoding [override] = $enc\n");
        }
        Perl::$RS = $oldNL;
        $et->HandleTag($tagTablePtr, 'LineCount', $lines);
        $et->HandleTag($tagTablePtr, 'WordCount', $words);
        return 1;
    }
}
