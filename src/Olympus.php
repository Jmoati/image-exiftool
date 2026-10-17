<?php

//------------------------------------------------------------------------------
// File:         Olympus.pm
//
// Description:  Olympus/Epson EXIF maker notes tags
//
// Revisions:    12/09/2003 - P. Harvey Created
//               11/11/2004 - P. Harvey Added Epson support
//
// References:   (see Olympus.pm)
//
// PHP port of Image::ExifTool::Olympus 2.93 (reading only). Tag tables are generated into
// tables/Olympus.php. (PrintLensInfo is only forward-declared in Olympus.pm: nothing to port.)
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Olympus
{
    public static $VERSION = '2.93';

    //------------------------------------------------------------------------------
    // Determine if the extender (EX-25/EC-14) was really attached (ref 9)
    // Inputs: 0) Extender, 1) LensType string, 2) MaxApertureAtMaxFocal
    // Returns: 0=not attached, 1=attached, 2=could have been removed
    // Notes: Olympus has a bug in the in-camera firmware which results in the
    // extender information being cached and written into the EXIF data even after
    // the extender has been removed.  You must power cycle the camera to prevent it
    // from writing the cached extender information into the EXIF data.
    public static function ExtenderStatus($extender, $lensType, $maxAperture)
    {
        $info = Perl::split(' ', $extender);
        // validate that extender identifier is reasonable
        if (!(count($info) >= 2 and Perl::hex($info[1]))) return 0;
        // if it's not an EC-14 (id '0 04') then assume it was really attached
        // (other extenders don't seem to affect the reported max aperture)
        if ("$info[0] $info[1]" !== '0 04') return 1;
        // get the maximum aperture for this lens (in $1)
        if (!preg_match('/ F(\d+(\.\d+)?)/', Perl::str($lensType), $m)) return 1;
        // If the maximum aperture at the maximum focal length is greater than the
        // known max/max aperture of the lens, then the extender must be attached
        return (Perl::num($maxAperture) - Perl::num($m[1]) > 0.2) ? 1 : 2;
    }

    //------------------------------------------------------------------------------
    // Print AF points
    // Inputs: 0) AF point data (string of integers)
    // Notes: I'm just guessing that the 2nd and 4th bytes are the Y coordinates,
    // and that more AF points will show up in the future (derived from E-1 images,
    // and the E-1 uses just one of 3 possible AF points, all centered in Y) - PH
    public static function PrintAFAreas($val)
    {
        $points = Perl::split(' ', $val);
        $afPointNames = [
            0x36794285 => 'Left',
            0x79798585 => 'Center',
            0xBD79C985 => 'Right',
        ];
        $val = '';
        foreach ($points as $pt) {
            if (!$pt) continue;
            if ($val) $val .= ', ';
            if (!empty($afPointNames[$pt])) $val .= $afPointNames[$pt] . ' ';
            $coords = Perl::unpack('C4', Perl::pack('N', $pt));
            $val .= "($coords[0],$coords[1])-($coords[2],$coords[3])";
        }
        if (!$val) $val = 'none';
        return $val;
    }

    //------------------------------------------------------------------------------
    // Extract information from a DSS/DS2 voice recorder audio file or ID3 XOLY frame
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success
    public static function ProcessDSS(ExifTool $et, array &$dirInfo, $tagTablePtr = null)
    {
        // allow this to be called with either RAF or DataPt
        $raf = $dirInfo['RAF'] ?? null;
        if ($raf) {
            $buff = null;
            if (!($raf->Read($buff, 898) > 68)) return 0;
            if (!preg_match('/^(\x02dss|\x03ds2)/', $buff)) return 0;
            // (Perl rebinds its local $dirInfo: break the reference to leave the caller's hash alone)
            unset($dirInfo);
            $dirInfo = ['DataPt' => $buff];
            $et->SetFileType(strtoupper(substr($buff, 1, 3)));
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::Olympus::DSS');
        return $et->ProcessBinaryData($dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process ORF file
    // Inputs: 0) ExifTool object reference, 1) directory information reference
    // Returns: 1 if this looked like a valid ORF file, 0 otherwise
    public static function ProcessORF(ExifTool $et, array &$dirInfo)
    {
        return $et->ProcessTIFF($dirInfo);
    }
}
