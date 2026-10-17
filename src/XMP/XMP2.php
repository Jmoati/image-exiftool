<?php

//------------------------------------------------------------------------------
// File:         XMP2.pl
//
// Description:  Additional XMP namespace definitions
//
// Revisions:    10/12/2008 - P. Harvey Created
//
// PHP port: the subs of XMP2.pl (its tables are generated into tables/XMP.php).
//------------------------------------------------------------------------------

namespace Image\ExifTool\XMP;

use Image\ExifTool\ExifTool;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;

trait XMP2
{
    //------------------------------------------------------------------------------
    // We found a SEAL property name/value
    // Inputs: 0) ExifTool ref, 1) tag table ref, 2) xmp property list ref
    //         3) property value, 4) attribute hash ref
    // Returns: 1 if valid tag was found
    public static function FoundSEAL(ExifTool $et, TagTable $tagTablePtr, array $props, $val, ?array $attrs = null)
    {
        // remove 'seal' container property from name
        $sealProps = $props;
        if ($sealProps and $sealProps[0] === 'seal') array_shift($sealProps);
        return self::FoundXMP($et, $tagTablePtr, $sealProps, $val, $attrs);
    }

    //------------------------------------------------------------------------------
    // Process SEAL XML
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessSEAL(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $dirInfo['XMPParseOpts']['FoundProc'] = 'Image\ExifTool\XMP::FoundSEAL';
        return self::ProcessXMP($et, $dirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Generate crd tags
    // Inputs: 0) tag table ref
    public static function Init_crd(TagTable $tagTablePtr): void
    {
        // import tags from CRS namespace
        $crsTable = ExifTool::GetTagTable('Image::ExifTool::XMP::crs');
        foreach (ExifTool::TagTableKeys($crsTable) as $tag) {
            $crsInfo = $crsTable[$tag];
            if (!$crsInfo instanceof TagInfo) continue;
            $tagInfo = $tagTablePtr[$tag] = clone $crsInfo;
            if ($crsInfo->Groups) $tagInfo->Groups = [0 => 'XMP', 1 => 'XMP-crd', 2 => $crsInfo->Groups[2] ?? null];
        }
    }
}
