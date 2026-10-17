<?php

//------------------------------------------------------------------------------
// File:         GeoTiff.pm
//
// Description:  Read GeoTiff meta information
//
// Revisions:    02/23/2004 - P. Harvey Created
//               02/25/2004 - P. Harvey Added new codes from libgeotiff-1.2.1
//               02/01/2007 - P. Harvey Added new codes from libgeotiff-1.2.3
//               01/22/2014 - P. Harvey Added new codes from libgeotiff-1.4.0
//
// Reference:    ftp://ftp.remotesensing.org/geotiff/libgeotiff/libgeotiff-1.1.4.tar.gz
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GeoTiff
{
    //------------------------------------------------------------------------------
    // Inputs: 0) ExifTool object ref
    // Notes: byte order must be set before calling this routine
    public static function ProcessGeoTiff(ExifTool $et)
    {
        $dirData = $et->GetValue('GeoTiffDirectory', 'ValueConv');
        if (!$dirData) return;

        // avoid re-processing if another EXIF directory is found
        if ($et->DidGeoTiff and $et->DidGeoTiff === $dirData) return;
        $et->DidGeoTiff = $dirData;

        $doubleData = $et->GetValue('GeoTiffDoubleParams', 'ValueConv');
        $asciiData = $et->GetValue('GeoTiffAsciiParams', 'ValueConv');
        $verbose = $et->Options('Verbose');
        // (binary values are scalar refs)
        $dirStr = $dirData instanceof ScalarRef ? (string) $dirData->value : (string) $dirData;

        if (strlen($dirStr) >= 8 and
            strlen($dirStr) >= 8 * (ExifTool::Get16u($dirStr, 6) + 1))
        {
            $version    = ExifTool::Get16u($dirStr, 0);
            $revision   = ExifTool::Get16u($dirStr, 2);
            $minorRev   = ExifTool::Get16u($dirStr, 4);
            $numEntries = ExifTool::Get16u($dirStr, 6);

            if ($verbose) {
                $et->INDENT .= '| ';
                $et->VerboseDir('GeoTiff', $numEntries);
            }
            // generate version number tag (not a real GeoTiff tag)
            $tagTable = ExifTool::GetTagTable('Image::ExifTool::GeoTiff::Main');
            $tagInfo = $et->GetTagInfo($tagTable, 1);
            if ($tagInfo) $et->FoundTag($tagInfo, "$version.$revision.$minorRev");

            for ($i = 0; $i < $numEntries; ++$i) {
                $pt = 8 * ($i + 1);
                $tag    = ExifTool::Get16u($dirStr, $pt);
                $tagInfo = $et->GetTagInfo($tagTable, $tag);
                if (!$tagInfo) continue;
                $loc    = ExifTool::Get16u($dirStr, $pt + 2);
                $count  = ExifTool::Get16u($dirStr, $pt + 4);
                $offset = ExifTool::Get16u($dirStr, $pt + 6);
                $format = Vars\GeoTiff::$geoTiffFormat[$loc] ?? null;
                $dataPt = null;
                if (!$format) {
                    $et->Warn("Unknown GeoTiff location ($loc) for $tagInfo->Name");
                    continue;
                } elseif ($format === 'double') {     // in the double parms
                    $dataPt = $doubleData;
                } elseif ($format === 'string') {     // in the ASCII parms
                    $dataPt = $asciiData;
                } elseif ($format === 'int16u') {     // in the GeoTiffDirectory data
                    $dataPt = $dirData;
                    if (!$loc) {                       // is value is stored in offset?
                        $count = 1;                    // (implied by location of 0)
                        $offset = ($pt + 6) / 2;       // offset of the "offset" value
                    }
                }
                $data = $dataPt instanceof ScalarRef ? (string) $dataPt->value : (null === $dataPt ? null : (string) $dataPt);
                $size = ExifTool::FormatSize($format);
                if (null === $data or strlen($data) < $size * ($offset + $count)) {
                    $et->Warn("Missing $format data for $tagInfo->Name");
                    continue;
                }
                $offset *= $size;
                $val = ExifTool::ReadValue($data, $offset, $format, $count, strlen($data) - $offset);
                // remove trailing terminator (NULL or '|') from string value
                if ($format === 'string') $val = preg_replace('/(\0|\|)$/', '', Perl::str($val));
                if ($verbose) $et->VerboseInfo($tag, $tagInfo,
                    Table:  $tagTable,
                    Index:  $i,
                    Value:  $val,
                    DataPt: $data,
                    Start:  $offset,
                    Format: $format,
                    Count:  $count,
                    Size:   $count * $size,
                );
                $et->FoundTag($tagInfo, $val);
            }
            if ($verbose) {
                $et->INDENT = preg_replace('/..$/', '', $et->INDENT);
            }
        } else {
            $et->Warn('Bad GeoTIFF directory');
        }
        // extract block tags only if requested
        if (!($et->OPTIONS['RequestAll'] ?? null)) {
            if (!($et->REQ_TAG_LOOKUP['geotiffdirectory'] ?? null)) $et->DeleteTag('GeoTiffDirectory');
            if (!($et->REQ_TAG_LOOKUP['geotiffdoubleparams'] ?? null)) $et->DeleteTag('GeoTiffDoubleParams');
            if (!($et->REQ_TAG_LOOKUP['geotiffasciiparams'] ?? null)) $et->DeleteTag('GeoTiffAsciiParams');
        }
    }
}
