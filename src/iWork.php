<?php

//------------------------------------------------------------------------------
// File:         iWork.pm
//
// Description:  Read Apple iWork '09 XML+ZIP files
//
// Revisions:    2009/11/11 - P. Harvey Created
//
// PHP port of Image::ExifTool::iWork 1.06. Tag tables are generated into tables/iWork.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\iWork as Vars;

final class iWork
{
    public static $VERSION = '1.06';

    //------------------------------------------------------------------------------
    // Generate a tag ID for this XML tag
    // Inputs: 0) tag property name list ref
    // Returns: tagID
    public static function GetTagID(array $props)
    {
        if (preg_match('/^\w+:ID$/', $props[count($props) - 1])) return 0;  // ignore ID tags
        return preg_match('/^.*?:(.*)/s', $props[0], $m) ? $m[1] : $props[0];
    }

    //------------------------------------------------------------------------------
    // We found an XMP property name/value
    // Inputs: 0) ExifTool object ref, 1) tag table ref
    //         2) reference to array of XMP property names (last is current property)
    //         3) property value, 4) attribute hash ref (not used here)
    // Returns: 1 if valid tag was found
    public static function FoundTag(ExifTool $et, TagTable $tagTablePtr, array $props, $val, $attrs = null)
    {
        if (!$props) return 0;
        $verbose = $et->Options('Verbose');

        if ($verbose > 1) $et->VPrint(0, "  | - Tag '", implode('/', $props), "'\n");

        // un-escape XML character entities
        $val = XMP::UnescapeXML($val);
        // convert from UTF8 to ExifTool Charset
        $val = $et->Decode($val, 'UTF8');
        $tag = self::GetTagID($props);
        if (!$tag) return 0;

        // add any unknown tags to table
        if (!$tagTablePtr[$tag]) {
            if ($verbose) $et->VPrint(0, "  [adding $tag]\n");
            ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => ucfirst($tag)]);
        }
        // save the tag
        $et->HandleTag($tagTablePtr, $tag, $val);

        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from an iWork file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1
    // Notes: Upon entry to this routine, the file type has already been verified
    // as ZIP and the dirInfo hash contains a 'ZIP' Archive::Zip object reference
    public static function Process_iWork(ExifTool $et, array &$dirInfo)
    {
        $zip = $dirInfo['ZIP'];
        $type = $index = $indexFile = $status = null;

        // try to determine the file type
        // (PHP: no Archive::Zip warnings to catch)
        // trust type given by file extension if available
        if ($et->FILE_EXT) $type = Vars::$iWorkType[$et->FILE_EXT] ?? null;
        if (!$type) {
            // read the index file
            $members = $zip->membersMatching('^index\.(xml|apxl)$');
            if ($members) {
                [$index, $status] = $zip->contents($members[0]);
                if (!$status) {
                    $index = Perl::str($index);
                    $indexFile = $members[0]->fileName();
                    if (preg_match('/^\s*<\?xml version=[^<]+<(\w+:\w+)/s', $index, $m)) {
                        if (!empty(Vars::$iWorkType[$m[1]])) $type = Vars::$iWorkType[$m[1]];
                    }
                }
            } else {
                $members = $zip->membersMatching('(?i)^.*\.(pages|numbers|key)/Index.*');
                if ($members) {
                    $tmp = $members[0]->fileName();
                    if (preg_match('/\.(pages|numbers|key)/i', $tmp, $m)) $type = Vars::$iWorkType[strtoupper($m[1])] ?? null;
                }
            }
            $type or $type = 'ZIP';     // assume ZIP by default
        }
        $et->SetFileType($type, Vars::$mimeType[$type] ?? null);

        $members = $zip->members();
        $docNum = 0;
        foreach ($members as $member) {
            // get filename of this ZIP member
            $file = $member->fileName();
            if ($file === null) continue;
            $et->VPrint(0, "File: $file\n");
            // set the document number and extract ZIP tags
            $et->DOC_NUM = ++$docNum;
            ZIP::HandleMember($et, $member);

            // process only the index XML and JPEG thumbnail/preview files
            if (!preg_match('{^(index\.(xml|apxl)|QuickLook/Thumbnail\.jpg|[^/]+/preview(-micro|-web)?.jpg)$}i', $file)) continue;
            // get the file contents if necessary
            // (CAREFUL! $buff MUST be local since we hand off a value ref to PreviewImage)
            if ($indexFile and $indexFile === $file) {
                // use the index file we already loaded
                $buff = $index;
            } else {
                [$buff, $status] = $zip->contents($member);
                if ($status) {
                    $et->Warn("Error extracting $file");
                    continue;
                }
                $buff = Perl::str($buff);
            }
            // extract JPEG as PreviewImage (should only be QuickLook/Thumbnail.jpg)
            if (preg_match('/\.jpg$/', $file)) {
                $type = preg_match('/preview-(\w+)/', $file, $m) ? ($m[1] === 'web' ? 'Other' : 'Thumbnail') : 'Preview';
                $et->FoundTag($type . 'Image', new ScalarRef($buff));
                continue;
            }
            // process "metadata" section of XML index file
            if (!preg_match('/<(\w+):metadata>/', $buff, $m, PREG_OFFSET_CAPTURE)) continue;
            $ns = $m[1][0];
            $p1 = $m[0][1] + strlen($m[0][0]);
            if (!preg_match('{</' . preg_quote($ns) . ':metadata>}', $buff, $m, PREG_OFFSET_CAPTURE, $p1)) continue;
            // construct XML data from "metadata" section only
            $buff = '<?xml version="1.0"?>' . substr($buff, $p1, $m[0][1] + strlen($m[0][0]) - $p1);
            $subdir = [
                'DataPt' => $buff,
                'DirLen' => strlen($buff),
                'DataLen' => strlen($buff),
                'XMPParseOpts' => [
                    'FoundProc' => 'Image\ExifTool\iWork::FoundTag',
                ],
            ];
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::iWork::Main');
            $et->ProcessDirectory($subdir, $tagTablePtr);
            $buff = null;   // (free memory now)
            if ($indexFile === $file) $index = null;
        }
        $et->DOC_NUM = null;
        return 1;
    }
}
