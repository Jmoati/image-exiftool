<?php

//------------------------------------------------------------------------------
// File:         Kodak.pm
//
// Description:  Kodak EXIF maker notes and APP3 "Meta" tags
//
// Revisions:    03/28/2005  - P. Harvey Created
//
// References:   1) http://search.cpan.org/dist/Image-MetaData-JPEG/
//               2) http://www.ozhiker.com/electronics/pjmt/jpeg_info/meta.html
//               3) http://www.cybercom.net/~dcoffin/dcraw/
//               4) Jim McGarvey private communication
//               IB) Iliah Borg private communication (LibRaw)
//
// Notes:        There really isn't much public information about Kodak formats.
//               The only source I could find was Image::MetaData::JPEG, which
//               didn't provide information about decoding the tag values.  So
//               this module represents a lot of work downloading sample images
//               (about 100MB worth!), and testing with my daughter's CX4200.
//
// PHP port of Image::ExifTool::Kodak 1.48.
// Tag tables are generated into tables/Kodak.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Kodak
{
    public static $VERSION = '1.48';

    //------------------------------------------------------------------------------
    // Process Kodak accelerometer data (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessPose(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirLen = strlen($dataPt);
        $ee = $et->Options('ExtractEmbedded');

        if ($dirLen < 0x14) return 0;
        $num = ExifTool::Get32u($dataPt, 0x10);
        if ($dirLen < 0x14 + $num * 24) return 0;

        $et->VerboseDir('Kodak pose', null, $dirLen);

        $et->DOC_NUM = 0;
        for ($i = 0, $pos = 0x14; $i < $num; ++$i, $pos += 24) {
            $et->HandleTag($tagTablePtr, 'AngularVelocity',
                Perl::str(ExifTool::GetRational64s($dataPt, $pos)) . ' ' .
                Perl::str(ExifTool::GetRational64s($dataPt, $pos + 8)) . ' ' .
                Perl::str(ExifTool::GetRational64s($dataPt, $pos + 16)));
            if (!$ee) {
                $pos += $num * 24;
                break;
            }
            ++$et->DOC_NUM;
        }
        $et->DOC_NUM = 0;

        if ($dirLen < $pos + 0x10) return 1;
        $num = ExifTool::Get32u($dataPt, $pos + 0x0c);
        if ($dirLen < $pos + 0x10 + $num * 24) return 1;

        for ($i = 0, $pos += 0x10; $i < $num; ++$i, $pos += 24) {
            $et->HandleTag($tagTablePtr, 'Accelerometer',
                Perl::str(ExifTool::GetRational64s($dataPt, $pos)) . ' ' .
                Perl::str(ExifTool::GetRational64s($dataPt, $pos + 8)) . ' ' .
                Perl::str(ExifTool::GetRational64s($dataPt, $pos + 16)));
            if (!$ee) {
                $pos += $num * 24;
                break;
            }
            ++$et->DOC_NUM;
        }
        $et->DOC_NUM = 0;
        if (!$ee) $et->Warn('Use the ExtractEmbedded option to extract all accelerometer data', 3);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Calculate RGB levels from associated tags (ref 3)
    // Inputs: 0) KodakIFD:WhiteBalance, 1-4) WB_RGBMul0-3, 5-8) WB_RGBCoeffs0-3
    //         9) (optional) KodakIFD:ColorTemperature, 10) (optional) Kodak:WB_RGBLevels
    // Returns: WB_RGBLevels or undef
    public static function CalculateRGBLevels(...$args)
    {
        if ($args[10] ?? null) return null; // use existing software levels if they exist
        $wbi = Perl::num($args[0] ?? null);
        if ($wbi < 0 or $wbi > 3) return null;
        $wbi = Perl::int($wbi);     // (Perl truncates array indices)
        $mul = Perl::split(' ', $args[$wbi + 1] ?? null, 13); // (only use the first 12 coeffs)
        $coefsPt = $args[$wbi + 5] ?? null;
        $coefs = Perl::split(' ', $coefsPt instanceof ScalarRef ? $coefsPt->value : null); // (extra de-reference for Binary data)
        $wbtemp100 = Perl::num(($args[9] ?? null) ?: 6500) / 100;
        if (!(count($mul) >= 3 and count($coefs) >= 12)) return null;
        $cam_mul = [];
        for ($c = $n = 0; $c < 3; ++$c) {
            for ($num = $i = 0; $i < 4; ++$i) {
                $num += Perl::num($coefs[$n++]) * ($wbtemp100 ** $i);
            }
            $cam_mul[$c] = 2048 / ($num * Perl::num($mul[$c]));
        }
        return implode(' ', array_map(Perl::str(...), $cam_mul));
    }

    //------------------------------------------------------------------------------
    // Process Kodak textual TextualInfo
    // Inputs: 0) ExifTool object ref, 1) dirInfo hash ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessKodakText(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: strlen($dataPt) - $dirStart;
        $data = substr($dataPt, $dirStart, $dirLen);
        $data = preg_replace('/\0.*/s', '', $data);    // truncate at null if it exists
        $lines = Perl::split('/[\n\r]+/', $data);
        $success = $tagInfo = null;
        $other = [];
        $et->VerboseDir('Kodak Text');
        foreach ($lines as $line) {
            if (preg_match('/(.*?):\s*(.*)/', $line, $m)) {
                [, $tag, $val] = $m;
                if ($tagTablePtr[$tag]) {
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                } else {
                    $tagName = $tag;
                    $tagName = preg_replace_callback('/([A-Z])\s+([A-Za-z])/', static fn ($m) => $m[1] . '_' . strtoupper($m[2]), $tagName);
                    $tagName = preg_replace_callback('/([a-z])\s+([A-Za-z0-9])/', static fn ($m) => $m[1] . strtoupper($m[2]), $tagName);
                    $tagName = preg_replace('/\s+/', '', $tagName);
                    $tagName = preg_replace('/[^-\w]+/', '', $tagName);    // delete remaining invalid characters
                    if (!$tagName) $tagName = 'NoName';
                    $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $tagName]);
                }
                $et->HandleTag($tagTablePtr, $tag, $val, TagInfo: $tagInfo);
                $success = 1;
            } else {
                // strip off leading/trailing white space and ignore blank lines
                if (preg_match('/^\s*(\S.*?)\s*$/', $line, $m)) $other[] = $m[1];
            }
        }
        if ($success) {
            if ($other) {
                $tagInfo = $et->GetTagInfo($tagTablePtr, '_other_info');
                $et->FoundTag($tagInfo, $other);
            }
        } else {
            $et->Warn("Can't parse Kodak TextualInfo data", 1);
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Process Kodak IFD (with leading byte order mark)
    // Inputs: 0) ExifTool object ref, 1) dirInfo hash ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessKodakIFD(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        if ($dirStart <= 0 or $dirStart + 2 > ($dirInfo['DataLen'] ?? null)) return 1;
        $byteOrder = substr($dirInfo['DataPt'], $dirStart, 2);
        if (!ExifTool::SetByteOrder($byteOrder)) {
            $et->Warn('Invalid Kodak ' . Perl::str($dirInfo['Name'] ?? null) . ' directory');
            return 1;
        }
        $dirInfo['DirStart'] += 2;   // skip byte order mark
        $dirInfo['DirLen'] = ($dirInfo['DirLen'] ?? null) - 2;
        if ($et->HTML_DUMP) {
            $base = ($dirInfo['Base'] ?? null) + ($dirInfo['DataPos'] ?? null);
            $et->HDump($dirStart + $base, 2, 'Byte Order Mark');
        }
        return Exif::ProcessExif($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Write Kodak IFD (with leading byte order mark)
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: Exif data block (may be empty if no Exif data) or undef on error
    // (PHP: the arguments may be omitted, like Perl's dummy call made to autoload the writer)
    public static function WriteKodakIFD(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        if ($dirStart <= 0 or $dirStart + 2 > ($dirInfo['DataLen'] ?? null)) return '';
        $byteOrder = substr($dirInfo['DataPt'], $dirStart, 2);
        if (!ExifTool::SetByteOrder($byteOrder)) return '';
        $dirInfo['DirStart'] += 2;  // skip byte order mark
        $dirInfo['DirLen'] -= 2;
        $buff = Exif::WriteExif($et, $dirInfo, $tagTablePtr);
        if (!($buff !== null and strlen($buff))) return $buff;
        // apply one-time fixup for length of byte order mark
        if (!empty($dirInfo['Fixup'])) {
            $dirInfo['Fixup']->Shift += 2;
            $dirInfo['Fixup']->ApplyFixup($buff);
            unset($dirInfo['Fixup']);
        }
        return ExifTool::GetByteOrder() . $buff;
    }
}
