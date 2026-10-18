<?php

//------------------------------------------------------------------------------
// File:         WritePhotoshop.pl
//
// Description:  Write Photoshop IRB meta information
//
// Revisions:    12/17/2004 - P. Harvey Created
//
// PHP port (package Image::ExifTool::Photoshop), mixed into class Image\ExifTool\Photoshop.
//------------------------------------------------------------------------------

namespace Image\ExifTool\Photoshop;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;

trait WritePhotoshop
{
    //------------------------------------------------------------------------------
    // Strip resource name from value prepare resource name for writing into IRB
    // Inputs: 0) tagInfo ref, 1) resource name (padded pascal string), 2) new value ref
    // Returns: none (updates name and value if necessary)
    // (PHP: the name is passed by reference, like Perl's $_[1])
    public static function SetResourceName(TagInfo $tagInfo, &$name, &$valPt): void
    {
        $setName = $tagInfo->SetResourceName;
        if ($setName !== null) {
            // extract resource name from value
            if (preg_match('{.*/#(.{0,255})#/$}s', Perl::str($valPt), $m)) {
                $newName = $m[1];
                // strip name from value
                $valPt = substr(Perl::str($valPt), 0, -4 - strlen($newName));
            } elseif (Perl::str($setName) === '1') {
                return;     // use old name
            } else {
                $newName = Perl::str($setName);
            }
            // convert to padded pascal string
            $newName = Perl::chr(strlen($newName)) . $newName;
            if (strlen($newName) & 0x01) $newName .= "\0";
            $name = $newName;   // return new name
        }
    }

    //------------------------------------------------------------------------------
    // Write Photoshop IRB resource
    // Inputs: 0) ExifTool object reference, 1) source dirInfo reference,
    //         2) tag table reference
    // Returns: IRB resource data (may be empty if no Photoshop data)
    // Notes: Increments ExifTool CHANGED flag for each tag changed
    public static function WritePhotoshop(?ExifTool $et = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$et) return 1;     // allow dummy access to autoload this package
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        if ($dataPt === null) {
            $emptyData = '';
            $dataPt = $emptyData;
        }
        $start = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $start);
        $dirEnd = $start + $dirLen;
        $newData = '';
        $iptcDigestInfo = self::IptcDigestInfo();

        // make a hash of new tag info, keyed on tagID
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);

        [$addDirs, $editDirs] = $et->GetAddDirHashList($tagTablePtr);

        ExifTool::SetByteOrder('MM');   // Photoshop is always big-endian
        //
        // rewrite existing tags in the old directory, deleting ones as necessary
        // (the Photoshop directory entries aren't in any particular order)
        //
        // Format: 0) Type, 4 bytes - '8BIM' (or the rare 'PHUT', 'DCSR', 'AgHg' or 'MeSa')
        //         1) TagID,2 bytes
        //         2) Name, pascal string padded to even no. bytes
        //         3) Size, 4 bytes - N
        //         4) Data, N bytes
        $value = $size = $tagInfo = $tagID = null;
        for ($pos = $start; $pos + 8 < $dirEnd; $pos += $size) {
            // each entry must be on same even byte boundary as directory start
            if (($pos ^ $start) & 0x01) ++$pos;
            $type = substr($dataPt, $pos, 4);
            if (!preg_match('/^(8BIM|PHUT|DCSR|AgHg|MeSa)$/', $type)) {
                $et->Error('Bad Photoshop IRB resource');
                $newData = null;
                break;
            }
            $tagID = ExifTool::Get16u($dataPt, $pos + 4);
            // get resource block name (pascal string padded to an even # of bytes)
            $namelen = 1 + ExifTool::Get8u($dataPt, $pos + 6);
            if ($namelen & 0x01) ++$namelen;
            if ($pos + $namelen + 10 > $dirEnd) {
                $et->Error('Bad APP13 resource block');
                $newData = null;
                break;
            }
            $name = substr($dataPt, $pos + 6, $namelen);
            $size = ExifTool::Get32u($dataPt, $pos + 6 + $namelen);
            $pos += $namelen + 10;
            if ($size + $pos > $dirEnd) {
                $et->Error("Bad APP13 resource data size $size");
                $newData = null;
                break;
            }
            if (($newTags[$tagID] ?? null) and $type === '8BIM') {
                $tagInfo = $newTags[$tagID];
                unset($newTags[$tagID]);
                $nvHash = $et->GetNewValueHash($tagInfo);
                // check to see if we are overwriting this tag
                $value = substr($dataPt, $pos, $size);
                $isOverwriting = $et->IsOverwriting($nvHash, $value);
                // handle special 'new' and 'old' values for IPTCDigest
                if (!$isOverwriting and $tagInfo === $iptcDigestInfo) {
                    $delValues = array_map(Perl::str(...), $nvHash->DelValue ?? []);
                    if (preg_grep('/^new$/', $delValues)) {
                        if ($et->NewIPTCDigest and $et->NewIPTCDigest === $value) $isOverwriting = 1;
                    }
                    if (preg_grep('/^old$/', $delValues)) {
                        if ($et->OldIPTCDigest and $et->OldIPTCDigest === $value) $isOverwriting = 1;
                    }
                }
                if ($isOverwriting) {
                    $et->VerboseValue("- Photoshop:{$tagInfo->Name}", $value);
                    // handle IPTCDigest specially because we want to write it last
                    // so the new IPTC digest will be known
                    if ($tagInfo === $iptcDigestInfo) {
                        $newTags[$tagID] = $tagInfo;    // add later
                        $value = null;
                    } else {
                        $value = $et->GetNewValue($nvHash);
                    }
                    ++$et->CHANGED;
                    if ($value === null) continue;  // next if tag is being deleted
                    // set resource name if necessary
                    self::SetResourceName($tagInfo, $name, $value);
                    $et->VerboseValue("+ Photoshop:{$tagInfo->Name}", $value);
                }
            } else {
                // (PHP: like Perl, $tagInfo keeps its value from the previous entry if this
                //  isn't an 8BIM resource)
                if ($type === '8BIM') {
                    $tagInfo = $editDirs[$tagID] ?? null;
                    if (!$tagInfo) {
                        // process subdirectory anyway if writable (except EXIF to avoid recursion)
                        // --> this allows IPTC to be processed if found here in TIFF images
                        // (but allow EXIF to be written in PSD files if XMP or IPTC tags are
                        //  being written because I have seen cases of XMP in PSD-EXIFInfo-IFD0
                        //  and IPTC in PSD-EXIFInfo-IFD0-IPTC, see forum10768 and forum13198)
                        $tmpInfo = $et->GetTagInfo($tagTablePtr, $tagID);
                        if ($tmpInfo and $tmpInfo->SubDirectory and
                            (Perl::str($tmpInfo->SubDirectory['TagTable'] ?? null) !== 'Image::ExifTool::Exif::Main' or
                             ($et->FILE_TYPE === 'PSD' and (($editDirs[0x0404] ?? null) or ($editDirs[0x0424] ?? null)))))
                        {
                            $table = ExifTool::GetTagTable($tmpInfo->SubDirectory['TagTable'] ?? null);
                            if ($table and $table['WRITE_PROC']) $tagInfo = $tmpInfo;
                        }
                    }
                }
                if ($tagInfo) {
                    if ($addDirs[$tagID] ?? null) unset($addDirs[$tagID]);
                    $subdirInfo = [
                        'DataPt' => $dataPt,
                        'DirStart' => $pos,
                        'DataLen' => $dirLen,
                        'DirLen' => $size,
                        'Parent' => $dirInfo['DirName'] ?? null,
                    ];
                    $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                    $writeProc = $tagInfo->SubDirectory['WriteProc'] ?? null;
                    $newValue = $et->WriteDirectory($subdirInfo, $subTable, $writeProc);
                    if ($newValue !== null) {
                        if (!strlen($newValue)) continue;   // remove subdirectory entry
                        $value = $newValue;
                        self::SetResourceName($tagInfo, $name, $value);
                    } else {
                        $value = substr($dataPt, $pos, $size);  // rewrite old directory
                    }
                } else {
                    $value = substr($dataPt, $pos, $size);
                }
            }
            $newSize = strlen(Perl::str($value));
            // write this directory entry
            $newData .= $type . ExifTool::Set16u($tagID) . $name . ExifTool::Set32u($newSize) . $value;
            if ($newSize & 0x01) $newData .= "\0";  // must null pad to even byte
        }
        //
        // write any remaining entries we didn't find in the old directory
        // (might as well write them in numerical tag order)
        //
        $tagsLeft = [...array_keys($newTags), ...array_keys($addDirs)];
        sort($tagsLeft, SORT_NUMERIC);
        foreach ($tagsLeft as $tagID) {
            $name = "\0\0";
            if ($newTags[$tagID] ?? null) {
                $tagInfo = $newTags[$tagID];
                $nvHash = $et->GetNewValueHash($tagInfo);
                $value = $et->GetNewValue($nvHash);
                // handle new IPTCDigest value specially
                if ($tagInfo === $iptcDigestInfo and $value !== null) {
                    if (Perl::str($value) === 'new') {
                        $value = $et->NewIPTCDigest;
                    } elseif (Perl::str($value) === 'old') {
                        $value = $et->OldIPTCDigest;
                    }
                    // (we already know we want to create this tag)
                } else {
                    // don't add this tag unless specified
                    if (!$nvHash->IsCreating) continue;
                }
                if ($value === null) continue;  // next if tag is being deleted
                $et->VerboseValue("+ Photoshop:{$tagInfo->Name}", $value);
                ++$et->CHANGED;
            } else {
                $tagInfo = $addDirs[$tagID];
                // create new directory
                $subdirInfo = [
                    'Parent' => $dirInfo['DirName'] ?? null,
                ];
                $subTable = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                $writeProc = $tagInfo->SubDirectory['WriteProc'] ?? null;
                $value = $et->WriteDirectory($subdirInfo, $subTable, $writeProc);
                if (!$value) continue;
            }
            // set resource name if necessary
            self::SetResourceName($tagInfo, $name, $value);
            $size = strlen(Perl::str($value));
            // write the new directory entry
            $newData .= '8BIM' . ExifTool::Set16u($tagID) . $name . ExifTool::Set32u($size) . $value;
            if ($size & 0x01) $newData .= "\0";     // must null pad to even numbered byte
            ++$et->CHANGED;
        }
        return $newData;
    }
}
