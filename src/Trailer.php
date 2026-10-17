<?php

//------------------------------------------------------------------------------
// File:         Trailer.pm
//
// Description:  Read JPEG trailer written by various makes of phone
//
// Revisions:    2025-01-27 - P. Harvey Created
//
// PHP port of Image::ExifTool::Trailer 1.01 (reading only). Tag tables are generated
// into tables/Trailer.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Trailer
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Process Vivo trailer
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 on failure, -1 if we must scan for the start
    //          of the trailer to set the ExifTool TrailerStart member
    // - takes Offset as positive offset from end of trailer to end of file,
    //   and returns DataPos and DirLen, and updates OutFile when writing
    public static function ProcessVivo(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // return now unless we are at a position to scan for the trailer
        // (must scan because the trailer footer doesn't indicate the trailer length)
        if (empty($dirInfo['ScanForTrailer'])) return -1;

        $pos = $et->TrailerStart;
        if (!$pos) return 0;
        $len = $et->FileEnd - $pos - $dirInfo['Offset'];
        if (!$raf->Seek($pos, 0)) return 0;
        if (!($len > 0 and $len < 1e7 and $raf->Read($buff, $len) == $len and
            preg_match('/\xff{4}\x1b\*9HWfu\x84\x93\xa2\xb1$/', $buff) and   // validate footer
            preg_match('/(streamdata|vivo\{")/', $buff, $m, PREG_OFFSET_CAPTURE)))  // find start
        {
            return 0;
        }
        $start = $m[1][1];  // (pos($buff) - length($1))
        if ($start) {
            $pos += $start;
            $len -= $start;
            $buff = substr($buff, $start);
        }
        // set trailer position and length
        [$dirInfo['DataPos'], $dirInfo['DirLen']] = [$pos, $len];

        // let ProcessTrailers copy or delete this trailer
        if (!empty($dirInfo['OutFile'])) return -1;

        if ($et->OPTIONS['Verbose'] or $et->HTML_DUMP) $et->DumpTrailer($dirInfo);
        $tbl = ExifTool::GetTagTable('Image::ExifTool::Trailer::Vivo');
        $p = 0;     // rewind search to start of buffer (Perl pos($buff))
        if (preg_match('/^streamdata\xff\xd8\xff/', $buff) and
            preg_match('/\xff\xd9stream(info|coun)/', $buff, $m, PREG_OFFSET_CAPTURE, $p))
        {
            $p = $m[0][1] + strlen($m[0][0]);
            $et->HandleTag($tbl, 'HDRImage', substr($buff, 10, $p - 20));
        }
        // continue looking for Vivo JSON data
        if (preg_match('/vivo\{"/', $buff, $m, PREG_OFFSET_CAPTURE, $p)) {
            $p = $m[0][1] + strlen($m[0][0]);
            $jsonStart = $p - 2;
            if (preg_match('/\}\0/', $buff, $m, PREG_OFFSET_CAPTURE, $p)) {
                $p = $m[0][1] + strlen($m[0][0]);
                $jsonLen = $p - 1 - $jsonStart;
                $et->HandleTag($tbl, 'JSONInfo', substr($buff, $jsonStart, $jsonLen));
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process OnePlus trailer
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 on failure, -1 if we must scan for the start
    //          of the trailer to set the ExifTool TrailerStart member
    // - takes Offset as positive offset from end of trailer to end of file,
    //   and returns DataPos and DirLen, and updates OutFile when writing
    public static function ProcessOnePlus(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = null;

        // return now unless we are at a position to scan for the trailer
        // (must scan because the trailer footer doesn't indicate the entire trailer length)
        if (empty($dirInfo['ScanForTrailer'])) return -1;

        // return -1 to let ProcessTrailers copy or delete the entire trailer
        if (!empty($dirInfo['OutFile'])) return -1;

        $start = $et->TrailerStart;
        if (!$start) return 0;
        if (!($raf->Seek(-8 - $dirInfo['Offset'], 2) and $raf->Read($buff, 8) == 8)) return 0;
        $end = $raf->Tell();    // (same as FileEnd - Offset)

        $dump = ($et->OPTIONS['Verbose'] or $et->HTML_DUMP);
        $tagTable = ExifTool::GetTagTable('Image::ExifTool::Trailer::OnePlus');
        $trailLen = 0;
        if (preg_match('/^jxrs...\0$/', $buff)) {
            $jlen = Perl::unpack('x4V', $buff)[0];
            $maxOff = 0;
            if ($jlen < $end - $start and $jlen > 8 and $raf->Seek($end - $jlen) and
                $raf->Read($buff, $jlen - 8) == $jlen - 8)
            {
                $buff = preg_replace('/\0+$/', '', $buff);  // remove trailing null(s)
                $list = Import::ReadJSONObject(null, $buff);
                if (is_array($list) and array_is_list($list)) {
                    foreach ($list as $item) {
                        $o = is_array($item) ? ($item['offset'] ?? null) : null;
                        if ($o and Perl::num($o) > $maxOff) $maxOff = Perl::num($o);
                    }
                    $trailLen = $maxOff + $jlen;
                    if ($dump and $trailLen) {
                        $et->DumpTrailer([
                            'RAF'     => $raf,
                            'DirName' => 'OnePlus',
                            'DataPos' => $end - $trailLen,
                            'DirLen'  => $trailLen,
                        ]);
                    }
                    $et->HandleTag($tagTable, 'JSONInfo', $buff);
                    foreach ($list as $item) {
                        if (!is_array($item)) continue;
                        [$off, $name, $len] = [$item['offset'] ?? null, $item['name'] ?? null, $item['length'] ?? null];
                        if (!($off and $name and $len)) continue;
                        if ($raf->Seek($end - $jlen - Perl::num($off)) and $raf->Read($buf2, Perl::num($len)) == $len) {
                            $et->HandleTag($tagTable, $name, $buf2, DataPos: $end - $jlen - Perl::num($off), DataPt: $buf2);
                        }
                    }
                } else {
                    $et->HandleTag($tagTable, 'JSONInfo', $buff);
                    $et->Warn('Error parsing OnePlus JSON information');
                }
            }
        }
        [$dirInfo['DataPos'], $dirInfo['DirLen']] = [$end - $trailLen, $trailLen];

        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Google trailer
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 on failure, -1 if we must scan for the start
    //          of the trailer to set the ExifTool TrailerStart member
    // - this trailer won't be identified when writing because XMP isn't extracted then
    public static function ProcessGoogle(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $info = $et->VALUE;
        $isList = static fn ($v) => is_array($v) and array_is_list($v);

        $lenKey = 'DirectoryItemLength';
        [$tag, $mime, $len, $pad] = [$info['DirectoryItemSemantic'] ?? null, $info['DirectoryItemMime'] ?? null,
                                     $info[$lenKey] ?? null, $info['DirectoryItemPadding'] ?? null];

        if (!($isList($tag) and $isList($mime))) {
            $pad = null;
            $lenKey = 'ContainerDirectoryItemLength';
            [$tag, $mime, $len] = [$info['ContainerDirectoryItemDataURI'] ?? null,
                                   $info['ContainerDirectoryItemMime'] ?? null, $info[$lenKey] ?? null];
            if (!($isList($mime) and $isList($tag))) {
                unset($et->ProcessGoogleTrailer);
                return 0;
            }
        }
        // we need to know TrailerStart to be able to read/write this trailer
        if (empty($dirInfo['ScanForTrailer'])) return -1;

        unset($et->ProcessGoogleTrailer);   // reset flag to process the Google trailer

        if (!empty($dirInfo['OutFile'])) return -1;     // let caller handle the writing

        // sometimes DirectoryItemLength is missing the Primary entry
        // (Perl: unshift works on the list held in $$et{VALUE}, so the tag value changes too)
        $wasList = $isList($len);
        if (!$wasList) $len = [$len];
        while (count($len) < count($mime)) array_unshift($len, 0);
        if ($wasList) $et->VALUE[$lenKey] = $len;

        $start = $et->TrailerStart;
        if (!$start) return 0;
        $end = $et->FileEnd;    // (ignore Offset for now because some entries may run into other trailers)

        $dump = ($et->OPTIONS['Verbose'] or $et->HTML_DUMP);
        $tagTable = ExifTool::GetTagTable('Image::ExifTool::Trailer::Google');

        // (ignore first entry: "Primary" or "primary_image")
        $regex = $grp = $type = null;
        $buff = new ScalarRef(null);  // (Perl stores \$buff: every tag found here refers to this one variable)
        for ($i = 1, $pos = 0; isset($mime[$i]); ++$i) {
            $more = $end - $start - $pos;
            if ($more < 16) break;
            if (!(($len[$i] ?? null) and isset($tag[$i]))) continue;
            $n = Perl::num($len[$i]);
            if ($n > $more) break;
            if (!($raf->Seek($start + $pos) and $raf->Read($buff->value, 16) == 16 and $raf->Seek($start + $pos))) break;
            if (Perl::str($mime[$i]) === 'image/jpeg') {
                $regex = '\xff\xd8\xff[\xdb\xe0\xe1]';
            } elseif (Perl::str($mime[$i]) === 'video/mp4') {
                $regex = '\0\0\0.ftyp(mp42|isom)';
            } else {
                $et->Warn('Google trailer ' . Perl::str($tag[$i]) . ' ' . Perl::str($mime[$i]) . ' not handled');
                continue;
            }
            if (preg_match("/^$regex/s", $buff->value)) {
                if ($raf->Read($buff->value, $n) != $n) break;
            } else {
                if ($pos) break;    // don't skip unknown information again
                if ($raf->Read($buff->value, $more) != $more) break;
                if (!preg_match("/($regex)/s", $buff->value, $m, PREG_OFFSET_CAPTURE)) break;
                $pos += $m[1][1];   // (pos($buff) - length($1))
                $more = $end - $start - $pos;
                if ($n > $end - $start - $pos) break;
                $buff->value = substr($buff->value, $pos, (int) $n);
            }
            if (!$tagTable[$tag[$i]]) {
                $name = Perl::str($tag[$i]);
                $name = preg_replace_callback('/([^A-Za-z])([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name); // capitalize words
                $name = ExifTool::MakeTagName($tag[$i]);
                if (Perl::str($mime[$i]) === 'image/jpeg') {
                    [$type, $grp] = ['Image', 'Preview'];
                } else {
                    [$type, $grp] = ['Video', 'Video'];
                }
                $et->VPrint(0, $et->INDENT, "[adding Google:$name]\n");
                ExifTool::AddTagToTable($tagTable, $tag[$i], ['Name' => "$name$type", 'Groups' => [2 => $grp]]);
            }
            if ($dump) {
                $et->DumpTrailer([
                    'RAF'     => $raf,
                    'DirName' => $tag[$i],
                    'DataPos' => $start + $pos,
                    'DirLen'  => $len[$i],
                ]);
            }
            $et->HandleTag($tagTable, $tag[$i], $buff, DataPos: $start + $pos, DataPt: $buff->value);
            // (haven't seen non-zero padding, but I assume this is how it works
            $pos += $n + ((is_array($pad) and !empty($pad[$i])) ? Perl::num($pad[$i]) : 0);
        }
        if (isset($tag[$i]) and isset($mime[$i])) {
            $et->Warn('Error reading ' . Perl::str($tag[$i]) . ' ' . Perl::str($mime[$i]) . ' from trailer', 1);
        }
        if (!$pos) return 0;

        [$dirInfo['DataPos'], $dirInfo['DirLen']] = [$start, $pos];

        return 1;
    }
}
