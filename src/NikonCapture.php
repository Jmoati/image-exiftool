<?php

//------------------------------------------------------------------------------
// File:         NikonCapture.pm
//
// Description:  Read/write Nikon Capture information
//
// Revisions:    11/08/2005 - P. Harvey Created
//               10/10/2008 - P. Harvey Updated for Capture NX 2
//               16/04/2011 - P. Harvey Decode NikonCaptureEditVersions
//
// References:   1) http://www.cybercom.net/~dcoffin/dcraw/
//               IB) Iliah Borg private communication (LibRaw)
//
// PHP port of Image::ExifTool::NikonCapture 1.14 . Tag tables are generated
// into tables/NikonCapture.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class NikonCapture
{
    public static $VERSION = '1.14';

    //------------------------------------------------------------------------------
    // write Nikon Capture data (ref 1)
    // Inputs: 0) ExifTool object reference, 1) reference to directory information
    //         2) pointer to tag table
    // Returns: 1 on success
    public static function WriteNikonCapture(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;    // allow dummy access to autoload this package

        // no need to edit this information unless necessary
        if (!(($et->EDIT_DIRS['MakerNotes'] ?? null) or ($et->EDIT_DIRS['IPTC'] ?? null))) {
            return null;
        }
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        if ($dirLen < 22) {
            $et->Warn('Short Nikon Capture Data', 1);
            return null;
        }
        // make sure the capture data is properly contained
        ExifTool::SetByteOrder('II');
        $tagID = ExifTool::Get32u($dataPt, $dirStart);
        // sometimes size includes 18 header bytes, and other times it doesn't (eg. ViewNX 2.1.1)
        $size = ExifTool::Get32u($dataPt, $dirStart + 18);
        $pad = $dirLen - $size - 18;
        if (!($tagID == 0x7a86a940 and ($pad >= 0 or $pad == -18))) {
            $et->Warn('Unrecognized Nikon Capture Data header');
            return null;
        }
        // determine if there is any data after this block
        if ($pad > 0) {
            $pad = substr($dataPt, $dirStart + 18 + $size, $pad);
            $dirLen = $size + 18;
        } else {
            $pad = '';
        }
        $outBuff = '';
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);
        $dirEnd = $dirStart + $dirLen;

        // loop through all entries in the Nikon Capture data
        for ($pos = $dirStart + 22; $pos + 22 < $dirEnd; $pos += 22 + $size) {
            $tagID = ExifTool::Get32u($dataPt, $pos);
            $size = ExifTool::Get32u($dataPt, $pos + 18) - 4;
            if ($size < 0 or $pos + 22 + $size > $dirEnd) break;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            if ($tagInfo) {
                $newVal = null;
                if ($tagInfo->SubDirectory) {
                    // rewrite the subdirectory
                    $subdirInfo = [
                        'DataPt' => $dataPt,
                        'DirStart' => $pos + 22,
                        'DirLen' => $size,
                    ];
                    $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    // ignore minor errors in IPTC since there is typically trailing garbage
                    $oldSetting = $et->Options('IgnoreMinorErrors');
                    if (preg_match('/IPTC/', $tagInfo->Name)) $et->Options('IgnoreMinorErrors', 1);
                    // rewrite the directory
                    $newVal = $et->WriteDirectory($subdirInfo, $subTable);
                    // restore our original options
                    $et->Options('IgnoreMinorErrors', $oldSetting);
                } elseif ($newTags[$tagID] ?? null) {
                    // get new value for this tag if we are writing it
                    $format = $tagInfo->Format ?: $tagInfo->Writable;
                    $oldVal = ExifTool::ReadValue($dataPt, $pos + 22, $format, 1, $size);
                    $nvHash = $et->GetNewValueHash($tagInfo);
                    if ($et->IsOverwriting($nvHash, $oldVal)) {
                        $val = $et->GetNewValue($tagInfo);
                        if ($val !== null) $newVal = ExifTool::WriteValue($val, $tagInfo->Writable);
                        if ($newVal !== null and strlen($newVal)) {
                            ++$et->CHANGED;
                        } else {
                            $newVal = null;
                            $et->Warn("Can't delete {$tagInfo->Name}");
                        }
                    }
                }
                if ($newVal !== null) {
                    if (!strlen($newVal)) continue; // don't write zero length information
                    // write the new value
                    $outBuff .= substr($dataPt, $pos, 18);
                    $outBuff .= ExifTool::Set32u(strlen($newVal) + 4);
                    $outBuff .= $newVal;
                    continue;
                }
            }
            // rewrite the existing information
            $outBuff .= substr($dataPt, $pos, 22 + $size);
        }
        if ($pos != $dirEnd) {
            if ($pos == $dirEnd - 4) {
                // it seems that sometimes (NX2) the main block size is wrong by 4 bytes
                // (did they forget to include the size word?)
                $outBuff .= substr($dataPt, $pos, 4);
            } else {
                $et->Warn('Nikon Capture Data improperly terminated', 1);
                return null;
            }
        }
        // add the header and return the new directory
        return substr($dataPt, $dirStart, 18) .
               ExifTool::Set32u(strlen($outBuff) + 4) .
               $outBuff . $pad;
    }

    //------------------------------------------------------------------------------
    // process Nikon Capture data (ref 1)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonCaptureEditVersions(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dirEnd = $dirStart + $dirLen;
        $verbose = $et->Options('Verbose');
        ExifTool::SetByteOrder('II');
        if (!($dirLen > 4)) return 0;
        $num = ExifTool::Get32u($dataPt, $dirStart);
        $pos = $dirStart + 4;
        if ($verbose) $et->VerboseDir('NikonCaptureEditVersions', $num);
        while ($num) {
            if ($pos + 4 > $dirEnd) break;
            $len = ExifTool::Get32u($dataPt, $pos);
            if ($pos + $len + 4 > $dirEnd) break;
            $subdirInfo = [     // (Perl: my %dirInfo)
                'DirName'  => 'NikonCapture',
                'Parent'   => 'NikonCaptureEditVersions',
                'DataPt'   => $dataPt,
                'DirStart' => $pos + 4,
                'DirLen'   => $len,
            ];
            $et->DOC_NUM = ++$et->DOC_COUNT;
            $et->ProcessDirectory($subdirInfo, $tagTablePtr);
            --$num;
            $pos += $len + 4;
        }
        $et->DOC_NUM = null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // process Nikon Capture data (ref 1)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonCapture(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dirEnd = $dirStart + $dirLen;
        $verbose = $et->Options('Verbose');
        $success = 0;
        ExifTool::SetByteOrder('II');
        if ($verbose) $et->VerboseDir('NikonCapture', 0, $dirLen);
        for ($pos = $dirStart + 22; $pos + 22 < $dirEnd; ) {
            $tagID = ExifTool::Get32u($dataPt, $pos);
            $size = ExifTool::Get32u($dataPt, $pos + 18) - 4;
            $pos += 22;
            if ($size < 0 or $pos + $size > $dirEnd) break;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            if ($tagInfo or $verbose) {
                $format = $value = null;
                // (note that Writable will be 0 for Unknown tags)
                if ($tagInfo) $format = $tagInfo->Format ?: $tagInfo->Writable;
                // generate a reasonable default format type for short values
                if (!$format and ($size == 1 or $size == 2 or $size == 4)) {
                    $format = 'int' . ($size * 8) . 'u';
                }
                if ($format) {
                    $count = 1;
                    if (Perl::str($format) === 'string' or Perl::str($format) === 'undef') {
                        // patch Nikon bug in size of some values (HistogramXML)
                        if ($tagInfo and $tagInfo->AdjustSize) $size += $tagInfo->AdjustSize;
                        $count = $size;
                    }
                    $value = ExifTool::ReadValue($dataPt, $pos, $format, $count, $size);
                } elseif ($size == 1) {
                    $value = substr($dataPt, $pos, $size);
                }
                if ($et->HandleTag($tagTablePtr, $tagID, $value,
                    DataPt: $dataPt,
                    DataPos: $dirInfo['DataPos'] ?? null,
                    Base: $dirInfo['Base'] ?? null,
                    Start: $pos,
                    Size: $size,
                )) $success = 1;
            }
            $pos += $size;
        }
        return $success;
    }
}
