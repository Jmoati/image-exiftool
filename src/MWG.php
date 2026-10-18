<?php

//------------------------------------------------------------------------------
// File:         MWG.pm
//
// Description:  Metadata Working Group support
//
// Revisions:    2009/10/21 - P. Harvey Created
//
// References:   1) http://www.metadataworkinggroup.org/
//
// PHP port of Image::ExifTool::MWG 1.24 (reading only). See PORTING.md.
// The tag tables (MWG Composite tags, Regions, Keywords, Collections) are generated
// into tables/MWG.php. Not ported (writing): ReconcileIPTCDigest.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

class MWG
{
    public static $VERSION = '1.24';

    /**
     * $Image::ExifTool::MWG::strict: strict MWG conformance mode (EXIF, IPTC and XMP in
     * non-standard locations are ignored), enabled by Load() unless already defined
     */
    public static $strict = null;

    //------------------------------------------------------------------------------
    // Load the MWG Composite tags
    public static function Load(): void
    {
        if (Vars\MWG::$mwgLoaded) return;

        // add our composite tags
        ExifTool::AddCompositeTags('Image::ExifTool::MWG');
        // must also add to lookup so we can write them
        // (since MWG tags aren't in the tag lookup by default)
        ExifTool::AddTagsToLookup(ExifTool::PackageTable('Image::ExifTool::MWG::Composite'),
                                  'Image::ExifTool::Composite');

        // modify EXIF:Artist to behave as a list-type tag
        $artist = ExifTool::PackageTable('Image::ExifTool::Exif::Main')[0x13b];
        $artist->List = 1;
        $artist->IsOverwriting = 'Image\ExifTool\MWG::OverwriteStringList';
        $artist->RawConv = 'Image\ExifTool\MWG::StringToList';

        // enable MWG strict mode if not set already
        // (causes non-standard EXIF, IPTC and XMP to be ignored)
        if (self::$strict === null) self::$strict = 1;

        Vars\MWG::$mwgLoaded = 1;
    }

    //------------------------------------------------------------------------------
    // Change a list of values to a string using MWG rules
    // Inputs: 0)reference to list of values
    // Returns: string of values (and may reformat list entries)
    public static function ListToString(array &$vals)
    {
        foreach ($vals as &$v) {
            // double all quotes in value and quote the value if it begins
            // with a quote or contains a semicolon-space separator
            $v = Perl::str($v);
            if (str_starts_with($v, '"') or str_contains($v, '; ')) {
                $v = str_replace('"', '""', $v);    // double all quotes
                $v = "\"$v\"";                      // quote the value
            }
        }
        unset($v);
        return implode('; ', $vals);
    }

    //------------------------------------------------------------------------------
    // Change a string value to a list of values using MWG rules
    // Inputs: 0) string of values, 1) ExifTool ref
    // Returns: value or list reference if more than one value
    // Notes: Sets Warning tag on error
    public static function StringToList($str, ExifTool $et)
    {
        $vals = [];
        $inQuotes = null;
        $t = Perl::split('/; /', $str, -1);
        foreach ($t as $v) {
            $wasQuotes = $inQuotes;
            if (!$inQuotes and str_starts_with($v, '"')) {
                $v = substr($v, 1);
                $inQuotes = 1;
            }
            if ($inQuotes) {
                // remove the last quote and reset the inQuotes flag if
                // the value ended in an odd number of quotes
                $v = preg_replace('/((^|[^"])("")*)"$/', '$1', $v, 1, $n);
                if ($n) $inQuotes = 0;
                $v = str_replace('""', '"', $v);    // un-double the contained quotes
            }
            if ($wasQuotes) {
                // previous separator was quoted, so concatinate with previous value
                $vals[count($vals) - 1] .= '; ' . $v;
            } else {
                $vals[] = $v;
            }
        }
        if ($inQuotes) $et->Warn('Incorrectly quoted MWG string-list value');
        return count($vals) > 1 ? $vals : ($vals[0] ?? null);
    }

    //------------------------------------------------------------------------------
    // Handle logic for overwriting EXIF string-type list tag
    // Inputs: 0) ExifTool ref, 1) new value hash ref,
    //         2) old string value (or undef if it didn't exist), 3) new value ref
    // Returns: 1 and sets the new value for the tag
    public static function OverwriteStringList(ExifTool $et, array $nvHash, $val, &$newValuePt)
    {
        $new = [];
        $delIndex = null;
        $writeMode = Perl::str($et->Options('WriteMode'));
        if ($writeMode !== 'wcg') {
            if ($val !== null) {
                if (!preg_match('/w/i', $writeMode)) return 0;
            } else {
                if (!preg_match('/c/i', $writeMode)) return 0;
            }
        }
        if (isset($nvHash['DelValue']) and $val !== null) {
            // preserve specified old values
            $old = self::StringToList($val, $et);
            $oldList = is_array($old) ? $old : [$old];
            if ($nvHash['DelValue']) {
                $del = [];
                foreach ($nvHash['DelValue'] as $d) $del[Perl::str($d)] = 1;
                foreach ($oldList as $o) {
                    if (empty($del[Perl::str($o)])) {
                        $new[] = $o;
                        continue;
                    }
                    if (!$delIndex) $delIndex = count($new);
                }
            } else {
                array_push($new, ...$oldList);
            }
        }
        // add new values (at location of deleted values, if any)
        if (!empty($nvHash['Value'])) {
            if ($delIndex !== null) {
                array_splice($new, $delIndex, 0, $nvHash['Value']);
            } else {
                array_push($new, ...$nvHash['Value']);
            }
        }
        if ($new) {
            // convert back to string format
            $newValuePt = self::ListToString($new);
        } else {
            $newValuePt = null;     // delete the tag
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Recover strings which were truncated by IPTC dataset length limit
    // Inputs: 0) IPTC value, 1) XMP value, 2) length limit
    // Notes: handles the case where IPTC and/or XMP values are lists
    public static function RecoverTruncatedIPTC($iptc, $xmp, $limit)
    {
        if ($xmp === null) return $iptc;
        if (is_array($iptc)) {
            if (!is_array($xmp)) $xmp = [$xmp];
            $vals = [];
            for ($i = 0; $i < count($iptc); ++$i) {
                $vals[] = self::RecoverTruncatedIPTC($iptc[$i] ?? null, $xmp[$i] ?? null, $limit);
            }
            return $vals;
        } elseif ($iptc !== null and strlen(Perl::str($iptc)) == $limit) {
            if (is_array($xmp)) $xmp = $xmp[0] ?? null; // take first element of list
            if (strlen(Perl::str($xmp)) > $limit and Perl::str($iptc) === substr(Perl::str($xmp), 0, $limit)) return $xmp;
        }
        return $iptc;
    }
}
