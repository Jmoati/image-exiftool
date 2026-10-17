<?php

//------------------------------------------------------------------------------
// File:         CaptureOne.pm
//
// Description:  Read Capture One EIP and COS files
//
// Revisions:    2009/11/01 - P. Harvey Created
//
// Notes:        The EIP format is a ZIP file containing an image (IIQ or TIFF)
//               and some settings files (COS).  COS files are XML based.
//
// PHP port of Image::ExifTool::CaptureOne 1.05 (reading only). Tag tables are generated
// into tables/CaptureOne.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class CaptureOne
{
    public static $VERSION = '1.05';

    //------------------------------------------------------------------------------
    // Handle XMP attributes
    // Inputs: 0) attribute list ref, 1) attr hash ref,
    //         2) property name ref, 3) property value ref
    // Returns: true if value was changed
    public static function HandleCOSAttrs(array &$attrList, array &$attrs, &$prop, &$valPt)
    {
        $changed = null;
        if (!strlen((string) $valPt) and isset($attrs['K']) and isset($attrs['V'])) {
            $prop = $attrs['K'];
            $valPt = $attrs['V'];
            // remove these attributes from the list
            $attrsList = $attrList;     // (Perl @attrs)
            $attrList = [];
            foreach ($attrsList as $a) {
                if ($a === 'K' or $a === 'V') {
                    unset($attrs[$a]);
                } else {
                    $attrList[] = $a;
                }
            }
            $changed = 1;
        }
        return $changed;
    }

    //------------------------------------------------------------------------------
    // We found a COS property
    // Inputs: 0) ExifTool object ref, 1) tag table ref, 2) reference to array of
    //         property names, 3) property value, 4) attribute hash ref (not used here)
    // Returns: 1 if valid tag was found
    public static function FoundCOS(ExifTool $et, TagTable $tagTablePtr, array $props, $val, ?array $attrs = null)
    {
        $tag = $props[count($props) - 1];
        if (!$tagTablePtr[$tag]) {
            $et->VPrint(0, "  | [adding $tag]\n");
            $name = ucfirst($tag);
            $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);
            if (!strlen($tag)) return 0;
            $tagInfo = ['Name' => $tag];
            // try formatting any tag with "Date" in the name as a date
            // (shouldn't affect non-date tags)
            if (preg_match('/Date(?![a-z])/', $name)) {
                $tagInfo['Groups'] = [2 => 'Time'];
                // 'Image::ExifTool::XMP::ConvertXMPDate($val,1)'
                $tagInfo['ValueConv'] = static fn ($val) => XMP::ConvertXMPDate($val, 1);
                // '$self->ConvertDateTime($val)'
                $tagInfo['PrintConv'] = static fn ($val, ExifTool $self) => $self->ConvertDateTime($val);
            }
            ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
        }
        // convert from UTF8 to ExifTool Charset
        $val = $et->Decode($val, 'UTF8');
        // un-escape XML character entities
        $val = XMP::UnescapeXML($val);
        $et->HandleTag($tagTablePtr, $tag, $val);
        return 0;
    }

    //------------------------------------------------------------------------------
    // Extract information from a COS file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid XML file
    public static function ProcessCOS(ExifTool $et, array &$dirInfo)
    {
        // process using XMP module, but override handling of attributes and tags
        $dirInfo['XMPParseOpts'] = [
            'AttrProc' => 'Image\ExifTool\CaptureOne::HandleCOSAttrs',
            'FoundProc' => 'Image\ExifTool\CaptureOne::FoundCOS',
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::CaptureOne::Main');
        $success = $et->ProcessDirectory($dirInfo, $tagTablePtr);
        unset($dirInfo['XMLParseArgs']);
        return $success;
    }

    //------------------------------------------------------------------------------
    // Extract information from a CaptureOne EIP file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1
    // Notes: Upon entry to this routine, the file type has already been verified
    // and the dirInfo hash contains a ZIP element unique to this process proc:
    //   ZIP - reference to Archive::Zip object for this file
    public static function ProcessEIP(ExifTool $et, array &$dirInfo)
    {
        $zip = $dirInfo['ZIP'];
        $file = $buff = $status = $member = null;
        $parseFile = [];

        $et->SetFileType('EIP');

        // (PHP: no Archive::Zip warnings to catch, so ZIP::WarnProc is not installed)
        // find all manifest files
        $members = $zip->membersMatching('^manifest\d*.xml$');
        // and choose the one with the highest version number (any better ideas?)
        while ($members) {
            $m = array_shift($members);
            $f = $m->fileName();
            if ($file and strcmp($file, $f) > 0) continue;
            $member = $m;
            $file = $f;
        }
        // get file names from our chosen manifest file
        if ($member) {
            [$buff, $status] = $zip->contents($member);
            if (!$status) {
                $foundImage = null;
                preg_match_all('{<(RawPath|SettingsPath)>(.*?)</\1>}s', (string) $buff, $mm, PREG_SET_ORDER);
                foreach ($mm as $m) {
                    $file = $m[2];
                    if (!preg_match('/\.(cos|iiq|jpe?g|tiff?)$/i', $file)) continue;
                    $parseFile[$file] = 1;    // set flag to parse this file
                    if (!preg_match('/\.cos$/i', $file)) $foundImage = 1;
                }
                // ignore manifest unless it contained a valid image
                if (!$foundImage) $parseFile = [];
            }
        }
        // extract meta information from embedded files
        $docNum = 0;
        $members = $zip->members(); // get all members
        foreach ($members as $member) {
            // get filename of this ZIP member
            $file = $member->fileName();
            if ($file === null) continue;
            $et->VPrint(0, "File: $file\n");
            // set the document number and extract ZIP tags
            $et->DOC_NUM = ++$docNum;
            ZIP::HandleMember($et, $member);
            if ($parseFile) {
                if (empty($parseFile[$file])) continue;
            } else {
                // reading the manifest didn't work, so look for image files in the
                // root directory and .cos files in the CaptureOne directory
                if (!preg_match('{^([^/]+\.(iiq|jpe?g|tiff?)|CaptureOne/.*\.cos)$}i', $file)) continue;
            }
            // extract the contents of the file
            // Note: this could use a LOT of memory here for RAW images...
            [$buff, $status] = $zip->contents($member);
            if ($status) {
                $et->Warn("Error extracting $file");
                continue;
            }
            if (preg_match('/\.cos$/i', $file)) {
                // process Capture One Settings files
                $subdirInfo = [     // (Perl %dirInfo)
                    'DataPt' => $buff,
                    'DirLen' => strlen($buff),
                    'DataLen' => strlen($buff),
                ];
                self::ProcessCOS($et, $subdirInfo);
            } else {
                // set HtmlDump error if necessary because it doesn't work with embedded files
                if ($et->HTML_DUMP) {
                    $et->HTML_DUMP['Error'] = "Sorry, can't dump images embedded in ZIP files";
                }
                // process IIQ, JPEG and TIFF images
                $et->ExtractInfo(new ScalarRef($buff), ['ReEntry' => 1]);
            }
            $buff = null;   // (free memory now)
        }
        $et->DOC_NUM = null;
        return 1;
    }
}
