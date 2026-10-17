<?php

//------------------------------------------------------------------------------
// File:         OOXML.pm
//
// Description:  Read Office Open XML+ZIP files
//
// Revisions:    2009/10/31 - P. Harvey Created
//
// PHP port of Image::ExifTool::OOXML 1.10. Tag tables are generated into tables/OOXML.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\OOXML as Vars;

final class OOXML
{
    public static $VERSION = '1.10';

    /** PrintConv '$self->ConvertDateTime($val)' of the vt:filetime tags */
    private static ?\Closure $convertDateTime = null;

    //------------------------------------------------------------------------------
    // Generate a tag ID for this XML tag
    // Inputs: 0) tag property name list ref
    // Returns: tagID and outtermost interesting namespace (or '' if no namespace)
    public static function GetTagID(array $props): array
    {
        $tag = $namespace = null;
        foreach ($props as $prop) {
            // split name into namespace and property name
            // (Note: namespace can be '' for property qualifiers)
            [$ns, $nm] = preg_match('/(.*?):(.*)/s', $prop, $m) ? [$m[1], $m[2]] : ['', $prop];
            if ($ns === 'vt') continue;     // ignore 'vt' properties
            if ($tag !== null) {
                $tag .= ucfirst($nm);       // add to tag name
            } elseif ($prop !== 'Properties' and $prop !== 'cp:coreProperties' and
                      $prop !== 'property')
            {
                $tag = $nm;
                // save namespace of first property to contribute to tag name
                if (!$namespace) $namespace = $ns;
            }
        }
        return [$tag, $namespace ?: ''];
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

        $tag = $props[count($props) - 1];
        if ($verbose > 1) $et->VPrint(0, "  | - Tag '", implode('/', $props), "'\n");

        // un-escape XML character entities
        $val = XMP::UnescapeXML($val);
        // convert OOXML-escaped characters (eg. "_x0000d_" is a newline)
        $val = preg_replace_callback('/_x([0-9a-f]{4})_/i', static fn ($m) => ExifTool::PackUTF8(hexdec($m[1])), $val);
        // convert from UTF8 to ExifTool Charset
        $val = $et->Decode($val, 'UTF8');
        // queue this attribute for later if necessary
        if (!empty(Vars::$queueAttrs[$tag])) {
            Vars::$queuedAttrs[$tag] = $val;
            return 0;
        }
        [$tag, $ns] = self::GetTagID($props);
        if (!$tag) {
            // all properties are in ignored namespaces
            // so 'name' from our queued attributes for the tag
            $name = Vars::$queuedAttrs['name'] ?? null;
            if (!$name) return 0;
            $name = preg_replace_callback('/(^| )([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name); // start words with uppercase
            $tag = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);
            if (!strlen($tag)) return 0;
            if (!$tagTablePtr[$tag]) {
                $tagInfo = [
                    'Name' => $tag,
                    'Description' => $name,
                ];
                // format as a date/time value if type is 'vt:filetime'
                if ($props[count($props) - 1] === 'vt:filetime') {
                    $tagInfo['Groups'] = [2 => 'Time'];
                    $tagInfo['Format'] = 'date';
                    $tagInfo['PrintConv'] = self::$convertDateTime ??= static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
                        return $self->ConvertDateTime($val);
                    };
                }
                if ($verbose) $et->VPrint(0, "  | [adding $tag]\n");
                ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
        } elseif ($tag === 'xmlns') {
            // ignore namespaces (for now)
            return 0;
        } elseif (($xmpMain = ExifTool::GetTagTable('Image::ExifTool::XMP::Main')[$ns] ?? null) instanceof TagInfo and
            $xmpMain->SubDirectory)
        {
            // use standard XMP table if it exists
            $table = $xmpMain->SubDirectory['TagTable'] ?? null;
            if ($table and ExifTool::GetTagTable($table)) {
                $tagTablePtr = ExifTool::GetTagTable($table);
            }
        } elseif (count($props) > 2 and in_array('vt:vector', $props, true)) {
            // handle vector properties (accumulate as lists)
            if ($props[count($props) - 1] === 'vt:size') {
                Vars::$vectorCount = $val;
                Vars::$vectorVals = [];
                return 0;
            } elseif ($props[count($props) - 1] === 'vt:baseType') {
                return 0;   // ignore baseType
            } elseif (Vars::$vectorCount) {
                Vars::$vectorCount = Perl::num(Vars::$vectorCount) - 1;
                if (Vars::$vectorCount) {
                    Vars::$vectorVals[] = $val;
                    return 0;
                }
                if (Vars::$vectorVals) $val = [...Vars::$vectorVals, $val];
                // Note: we will lose any improper-sized vector elements here
            }
        }
        // add any unknown tags to table
        if ($tagTablePtr[$tag]) {
            $tagInfo = $tagTablePtr[$tag];
            if ($tagInfo instanceof TagInfo) {
                // reformat date/time values
                $fmt = $tagInfo->Format ?: ($tagInfo->Writable ?: '');
                if ($fmt === 'date') $val = XMP::ConvertXMPDate($val);
            }
        } else {
            if ($verbose) $et->VPrint(0, "  [adding $tag]\n");
            ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => ucfirst($tag)]);
        }
        // save the tag
        $et->HandleTag($tagTablePtr, $tag, $val);

        // start fresh for next tag
        Vars::$vectorCount = null;
        Vars::$queuedAttrs = [];

        return 1;
    }

    //------------------------------------------------------------------------------
    // Extract information from an OOXML file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1
    // Notes: Upon entry to this routine, the file type has already been verified
    // and the dirInfo hash contains 2 elements unique to this process proc:
    //   MIME    - mime type of main document from "[Content_Types].xml"
    //   ZIP     - reference to Archive::Zip object for this file
    public static function ProcessDOCX(ExifTool $et, array &$dirInfo)
    {
        $zip = $dirInfo['ZIP'];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::OOXML::Main');
        $mime = ($dirInfo['MIME'] ?? null) ?: \Image\ExifTool\Vars\ExifTool::$mimeType['DOCX'];

        // set the file type ('DOCX' by default)
        $fileType = Vars::$fileType[$mime] ?? null;
        if ($fileType) {
            // THMX is a special case because its contents.main MIME types is PPTX
            if ($fileType === 'PPTX' and $et->FILE_EXT and $et->FILE_EXT === 'THMX') {
                $fileType = 'THMX';
            }
        } else {
            $et->VPrint(0, "Unrecognized MIME type: $mime\n");
            // get MIME type according to file extension
            $fileType = $et->FILE_EXT;
            // default to 'DOCX' if this isn't a known OOXML extension
            if (!($fileType and !empty(Vars::$isOOXML[$fileType]))) $fileType = 'DOCX';
        }
        $et->SetFileType($fileType);

        // (PHP: no Archive::Zip warnings to catch)
        // extract meta information from all files in ZIP "docProps" directory
        $docNum = 0;
        $members = $zip->members();
        foreach ($members as $member) {
            // get filename of this ZIP member
            $file = $member->fileName();
            if ($file === null) continue;
            $et->VPrint(0, "File: $file\n");
            // set the document number and extract ZIP tags
            $et->DOC_NUM = ++$docNum;
            ZIP::HandleMember($et, $member);
            // process only XML and JPEG/WMF thumbnail images in "docProps" directory
            if (!preg_match('{^docProps/(.*\.xml|(thumbnail\.(jpe?g|wmf)))$}i', $file)) continue;
            // get the file contents (CAREFUL! $buff MUST be local since we hand off a value ref)
            [$buff, $status] = $zip->contents($member);
            if ($status) {
                $et->Warn("Error extracting $file");
                continue;
            }
            $buff = Perl::str($buff);
            // extract docProps/thumbnail.(jpg|mwf) as PreviewImage|PreviewMWF
            if (preg_match('/\.(jpe?g|wmf)$/i', $file)) {
                $tag = preg_match('/\.wmf$/i', $file) ? 'PreviewWMF' : 'PreviewImage';
                $et->FoundTag($tag, new ScalarRef($buff));
                continue;
            }
            // process XML files (docProps/app.xml, docProps/core.xml, docProps/custom.xml)
            $subdir = [
                'DataPt'   => $buff,
                'DirLen'   => strlen($buff),
                // (skip over XML header if it exists)
                'DirStart' => preg_match('/<\?xml\s+.*?\?>/', $buff, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] + strlen($m[0][0]) : 0,
                'DataLen'  => strlen($buff),
                'XMPParseOpts' => ['FoundProc' => 'Image\ExifTool\OOXML::FoundTag'],
            ];
            $et->ProcessDirectory($subdir, $tagTablePtr);
            $buff = null;   // (free memory now)
        }
        $et->DOC_NUM = null;
        return 1;
    }
}
