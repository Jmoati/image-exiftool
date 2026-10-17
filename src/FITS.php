<?php

//------------------------------------------------------------------------------
// File:         FITS.pm
//
// Description:  Read Flexible Image Transport System metadata
//
// Revisions:    2018/03/07 - P. Harvey Created
//
// References:   1) https://fits.gsfc.nasa.gov/fits_standard.html
//
// PHP port of Image::ExifTool::FITS 1.02 (reading only). Tag tables are generated
// into tables/FITS.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class FITS
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Read information in a FITS document
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid FITS file
    public static function ProcessFITS(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tag = $continue = null;

        if (!($raf->Read($buff, 80) == 80 and preg_match('/^SIMPLE  = {20}T/', $buff))) return 0;
        $et->SetFileType();
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::FITS::Main');

        for (;;) {
            if ($raf->Read($buff, 80) != 80) {
                $et->Warn('Truncated FITS header');
                break;
            }
            $key = substr($buff, 0, 8);
            $key = preg_replace('/ +$/', '', $key);     // remove trailing space from key
            if ($key === 'CONTINUE') {
                if ($continue === null) {
                    $et->Warn('Unexpected FITS CONTINUE keyword');
                    continue;
                }
            } else {
                if ($continue !== null) {
                    // the previous value wasn't continued, so store with the trailing '&'
                    $et->HandleTag($tagTablePtr, $tag, $continue . '&');
                    $continue = null;
                }
                if ($key === 'END') break;
                // make sure the key is valid
                if (!preg_match('/^[-_A-Z0-9]*$/', $key)) {
                    $et->Warn('Format error in FITS header');
                    break;
                }
                if ($key === 'COMMENT' or $key === 'HISTORY') {
                    $val = substr($buff, 8);    // comments start in column 9
                    $val = preg_replace('/ +$/', '', $val);    // remove trailing spaces
                    $et->HandleTag($tagTablePtr, $key, $val);
                    continue;
                }
                // ignore other lines that aren't tags
                if (substr($buff, 8, 2) !== '= ') continue;
                // save tag name (avoiding potential conflict with ExifTool variables)
                $tag = isset(Vars\ExifTool::$specialTags[$key]) ? "_$key" : $key;
                // add to tag table if necessary
                if (!$tagTablePtr[$tag]) {
                    $name = ucfirst(strtolower($tag));  // make tag name lower case with leading capital
                    $name = preg_replace_callback('/_(.)/', static fn ($m) => strtoupper($m[1]), $name);   // remove all '_' and capitalize subsequent letter
                    ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
                }
            }
            $val = substr($buff, 10);
            // parse quoted values
            if (preg_match("/^'(.*?)'(.*)/", $val, $m)) {
                [$val, $buff] = [$m[1], $m[2]];
                while (preg_match("/^('.*?)'(.*)/", $buff, $m)) {   // handle escaped quotes
                    $val .= $m[1];
                    $buff = $m[2];
                }
                $val = preg_replace('/ +$/', '', $val);    // remove trailing spaces
                if ($continue !== null) {
                    $val = $continue . $val;
                    $continue = null;
                }
                // check for possible continuation, removing trailing '&'
                $val = preg_replace('/\&$/', '', $val, 1, $n);
                if ($n) {
                    $continue = $val;
                    continue;
                }
            } elseif ($continue !== null) {
                $et->Warn('Invalid FITS CONTINUE value');
                continue;
            } else {
                $val = preg_replace('/ *(\/.*)?$/', '', $val, 1);    // remove trailing spaces and comment
                if (!strlen($val)) continue;    // ignore undefined values
                $val = preg_replace('/^ +/', '', $val);     // remove leading spaces
                // re-format floating-point values to use 'e'
                if (preg_match('/^[+-]?(?=\d|\.\d)\d*(\.\d*)?([ED]([+-]?\d+))?$/', $val)) $val = strtr($val, 'DE', 'ee');
            }
            $et->HandleTag($tagTablePtr, $tag, $val);
        }
        return 1;
    }
}
