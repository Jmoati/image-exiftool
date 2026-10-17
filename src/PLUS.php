<?php

//------------------------------------------------------------------------------
// File:         PLUS.pm
//
// Description:  PLUS (Picture Licensing Universal System) tags
//
// Revisions:    2016/05/18 - P. Harvey Created
//
// References:   1) http://www.useplus.com/useplus/standards.asp
//
// PHP port of Image::ExifTool::PLUS 1.02. Tag tables are generated into tables/PLUS.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PLUS
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Validate Media Summary Code
    // Inputs: 0) Media Usage Code
    // Returns: true if OK, false on severe error
    // - issues warning for detected format problems
    // - repairs some repairable problems
    public static function ValidateMediaSummary(&$val)
    {
        $a = Perl::split('/\|/', $val);
        if (!(count($a) >= 4 and $a[0] === '')) {
            ExifTool::PerlWarn("Not a valid Media Summary Code\n");
            return 0;
        }
        if ($a[1] !== 'PLUS') {
            ExifTool::PerlWarn("Unrecognized Media Usage standard\n");
            return 0;
        }
        if (!preg_match('/^V(\d+)/', $a[2])) ExifTool::PerlWarn("Unrecognized Media Usage version\n");
        if (!preg_match('/^U(\d+)/', $a[3], $m)) {
            ExifTool::PerlWarn("Invalid Media Usage count\n");
            return 0;
        }
        $numUsages = $m[1];
        if ($numUsages != count($a) - 4) {
            ExifTool::PerlWarn("Fixed incorrect number of Media Usages\n");
            $numUsages = count($a) - 4;
            $a[3] = Perl::sprintf('U%.3d', $numUsages);
        }
        for ($i = 1; $i <= $numUsages; ++$i) {
            $usage = $a[$i + 3];
            if (!preg_match('/^1I([A-Z])([A-Z])/', $usage, $m)) {
                ExifTool::PerlWarn("Missing Media Usage $i item count\n");
                return 0;
            }
            if (strlen($usage) % 4) {
                ExifTool::PerlWarn("Incorrect Media Usage $i length\n");
                return 0;
            }
            $numItems = (ord($m[1]) - 65) * 26 + ord($m[2]) - 65 + 1;
            if (strlen($usage) != 4 * ($numItems + 1)) {
                $numItems = strlen($usage) / 4 - 1;
                ExifTool::PerlWarn("Fixed incorrect Media Usage $i item count\n");
                $a[$i + 3] = '1I' . chr(65 + Perl::int($numItems / 26)) . chr($numItems % 26) . substr($usage, 4);
            }
            for ($j = 1; $j <= $numItems; ++$j) {
                $item = substr($usage, $j * 4, 4);
                if (!preg_match('/^\d[A-Z]{3}$/', $item)) {
                    ExifTool::PerlWarn("Invalid item \"$item\" for Media Usage $i\n");
                    return 0;
                }
            }
        }
        if (ExifTool::$evalWarning) $val = implode('|', $a) . '|';
        return 1;
    }
}
