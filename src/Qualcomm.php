<?php

//------------------------------------------------------------------------------
// File:         Qualcomm.pm
//
// Description:  Read Qualcomm APP7 meta information
//
// Revisions:    2012/02/14 - P. Harvey Created
//
// PHP port of Image::ExifTool::Qualcomm 1.02. Tag tables are generated into
// tables/Qualcomm.php (with the Names and Descriptions that the module generates when
// it is loaded), @qualcommFormat into Vars\Qualcomm.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\Qualcomm as Vars;

final class Qualcomm
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Generate tag Name and Description from a Qualcomm tag ID
    // Inputs: 0) tag ID, 1) tagInfo ref
    // Returns: true on success
    public static function MakeNameAndDesc($_, array &$tagInfo)
    {
        $_ = Perl::str($_);
        // capitalize various leading acronyms or just first letter...
        $n = 0;
        $_ = preg_replace_callback('/^(asf|awb|aec|afr|af_|la_|r2_tl|tl)/', static fn ($m) => strtoupper($m[1]), $_, 1, $n);
        if (!$n) $_ = ucfirst($_);
        $_ = preg_replace_callback('/_([a-z])/', static fn ($m) => '_' . strtoupper($m[1]), $_);  // capitalize first letter of each word
        $_ = preg_replace_callback('/\[(\d+)\]$/', static fn ($m) => Perl::sprintf('_%.2d', $m[1]), $_, 1); // use 2-digit subscripts (and remove brackets)
        $_ = preg_replace('/[^-_a-zA-Z0-9]/', '', $_);    // delete invalid characters
        $desc = $_;
        // convert underlines to spaces in description
        if (str_contains($desc, '_')) {
            $desc = strtr($desc, '_', ' ');
            // remove unnecessary underlines from tag name...
            $_ = preg_replace('/_([A-Z][a-z])/', '$1', $_);
            $_ = preg_replace('/([a-z0-9])_([A-Z])/', '$1$2', $_);
            $_ = preg_replace('/([A-Za-z])_(\d)/', '$1$2', $_);
        }
        if (!strlen($_)) return 0;
        $tagInfo['Name'] = $_;
        $tagInfo['Description'] = $desc;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Qualcomm Dual Camera APP4 metadata (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessDualCamera(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = $dirInfo['DirStart'];

        $et->VerboseDir('Qualcomm Dual Camera', null, $dirInfo['DirLen']);
        $p = $pos;  // (Perl pos($$dataPt))
        while (($p = strpos($dataPt, "\x0a", $p)) !== false) {
            ++$p;
            $str = substr($dataPt, $pos, $p - $pos - 1);
            if (!preg_match('/^(.*?)\s*=\s*(.*)/', $str, $m)) continue;
            [$tag, $val] = [$m[1], $m[2]];
            $et->HandleTag($tagTablePtr, $tag, $val, MakeTagInfo: 1);
            $pos = $p;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Qualcomm APP7 metadata (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessQualcomm(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = $dirInfo['DirStart'];
        $dirEnd = $pos + $dirInfo['DirLen'];

        $et->VerboseDir('Qualcomm', null, $dirInfo['DirLen']);
        ExifTool::SetByteOrder('II');

        while ($pos + 3 < $dirEnd) {
            $valLen = ExifTool::Get16u($dataPt, $pos);
            $tagLen = ExifTool::Get8u($dataPt, $pos + 2);
            if ($pos + 8 + $tagLen + $valLen > $dirEnd) break;
            $tag = substr($dataPt, $pos + 3, $tagLen);
            $pos += 3 + $tagLen;    // point to format byte
            $fmt = ExifTool::Get8u($dataPt, $pos);
            // (not sure what these counts are for -- both are always 1 in my samples)
            //my $cnt1 = Get16u($dataPt, $pos + 1);
            //my $cnt2 = Get16u($dataPt, $pos + 3);
            $pos += 5;              // point to start of value data
            if ($fmt <= 7) {
                $format = Vars::$qualcommFormat[$fmt];
                $val = ExifTool::ReadValue($dataPt, $pos, $format, null, $valLen);
            } else {
                $format = "format $fmt";
                $value = substr($dataPt, $pos, $valLen);
                $val = new ScalarRef($value);
            }
            if (!($tagTablePtr[$tag] !== null or isset(\Image\ExifTool\Vars\ExifTool::$specialTags[$tag]))) {
                $tagInfo = [];
                if (self::MakeNameAndDesc($tag, $tagInfo)) {
                    $et->VPrint(0, $et->INDENT, "[adding Qualcomm:{$tagInfo['Name']}]\n");
                    ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                }
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt:  $dataPt,
                DataPos: $dataPos,
                Start:   $pos,
                Size:    $valLen,
                Format:  $format,
            );
            $pos += $valLen;        // point to start of next entry
        }
        return 1;
    }
}
