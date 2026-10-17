<?php

//------------------------------------------------------------------------------
// File:         XISF.pm
//
// Description:  Read Extensible Image Serialization Format metadata
//
// Revisions:    2023-10-10 - P. Harvey Created
//
// References:   1) https://pixinsight.com/doc/docs/XISF-1.0-spec/XISF-1.0-spec.html
//
// PHP port of Image::ExifTool::XISF 1.00 (reading only). Tag tables are generated
// into tables/XISF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class XISF
{
    public static $VERSION = '1.00';

    //------------------------------------------------------------------------------
    // Handle XMP attributes
    // Inputs: 0) attribute list ref, 1) attr hash ref, 2) property name ref,
    //         3) property value ref, 4) empty list ref
    // Returns: true if value was changed
    public static function HandleXISFAttrs(array &$attrList, array &$attrs, &$prop, &$valPt)
    {
        if (!isset($attrs['id'])) return 0;
        $changed = null;
        // use "id" as the tag name, "value" as the value, and ignore "type"
        $prop = $attrs['id'];
        $prop = preg_replace('/^XISF:/', '', $prop);    // remove XISF namespace
        if (isset($attrs['value'])) {
            $valPt = $attrs['value'];
            $changed = 1;
        }
        $attrsList = $attrList;     // (Perl @attrs)
        $attrList = [];
        foreach ($attrsList as $a) {
            if ($a === 'id' or $a === 'value' or $a === 'type') {
                unset($attrs[$a]);
            } else {
                $attrList[] = $a;
            }
        }
        return $changed;
    }

    //------------------------------------------------------------------------------
    // Read information in a XISF document
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid XISF file
    public static function ProcessXISF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if (!($raf->Read($buff, 16) == 16 and preg_match('/^XISF0100/', $buff))) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XISF::Main');
        $hdrLen = ExifTool::Get32u($buff, 8);
        if ($raf->Read($buff, $hdrLen) != $hdrLen) {
            $et->Warn('Error reading XISF header');
            return 1;
        }
        $et->FoundTag('XML', $buff);
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt' => $buff,
            'IgnoreProp' => ['xisf' => 1, 'Metadata' => 1, 'Property' => 1],
            'XMPParseOpts' => ['AttrProc' => 'Image\ExifTool\XISF::HandleXISFAttrs'],
        ];
        XMP::ProcessXMP($et, $subdirInfo, $tagTablePtr);
        $geo = $et->VALUE['ImageGeometry'] ?? null;
        if ($geo) {
            [$w, $h, $n] = Perl::split('/:/', $geo) + [null, null, null];
            $et->FoundTag('ImageWidth', $w);
            $et->FoundTag('ImageHeight', $h);
            $et->FoundTag('NumPlanes', $n);
        }
        return 1;
    }
}
