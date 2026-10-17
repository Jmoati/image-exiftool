<?php

//------------------------------------------------------------------------------
// File:         ICC_Profile.pm
//
// Description:  Read ICC Profile meta information
//
// Revisions:    11/16/2004 - P. Harvey Created
//
// References:   1) http://www.color.org/icc_specs2.html (ICC.1:2003-09)
//               2) http://www.color.org/icc_specs2.html (ICC.1:2001-04)
//               3) http://developer.apple.com/documentation/Carbon/Reference/ColorSync_Manager/ColorSync_Manager.pdf
//               4) http://www.color.org/privatetag2007-01.pdf
//               5) http://www.color.org/icc_specs2.xalter (approved revisions, 2010-07-16)
//               6) Eef Vreeland private communication
//               7) https://color.org/specification/ICC.2-2019.pdf
//
// Notes:        The ICC profile information is different: the format of each
//               tag is embedded in the information instead of in the directory
//               structure. This makes things a bit more complex because I need
//               an extra level of logic to decode the variable-format tags.
//
// PHP port of Image::ExifTool::ICC_Profile 1.42 (reading only). Tag tables are
// generated into tables/ICC_Profile.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class ICC_Profile
{
    public static $VERSION = '1.42';

    //------------------------------------------------------------------------------
    // Print ICC Profile ID in hex
    // Inputs: 1) string of numbers
    // Returns: string of hex digits
    public static function HexID($val)
    {
        $vals = Perl::split(' ', $val);
        // return a simple zero if no MD5 done
        if (!preg_grep('/^0/', $vals, PREG_GREP_INVERT)) return 0;
        $val = '';
        foreach ($vals as $_) {
            $val .= Perl::sprintf('%.2x', $_);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Get formatted value from ICC tag (which has the type embedded)
    // Inputs: 0) data reference, 1) offset to tag data, 2) tag data size
    // Returns: Formatted value or undefined if format not supported
    // Notes: The following types are handled by BinaryTables:
    //  chromaticityType, colorantTableType, measurementType, viewingConditionsType
    // The following types are currently not handled (most are large tables):
    //  curveType, lut16Type, lut8Type, lutAtoBType, lutBtoAType, namedColor2Type,
    //  parametricCurveType, profileSeqDescType, responseCurveSet16Type
    // The multiLocalizedUnicodeType must be handled by the calling routine.
    public static function FormatICCTag($dataPt, $offset, $size)
    {
        if ($size >= 8) {
            // get data type from start of tag data
            $type = substr($dataPt, $offset, 4);
        } else {
            $type = 'err';
        }
        // colorantOrderType
        if ($type === 'clro' and $size >= 12) {
            $num = ExifTool::Get32u($dataPt, $offset + 8);
            if ($size >= $num + 12) {
                $pos = $offset + 12;
                return implode(' ', Perl::unpack("x{$pos}c$num", $dataPt));
            }
        }
        // dataType
        if ($type === 'data' and $size >= 12) {
            $form = ExifTool::Get32u($dataPt, $offset + 8);
            // format 0 is UTF-8 data
            if ($form == 0) return substr($dataPt, $offset + 12, $size - 12);
            // binary data and other data types treat as binary (ie. don't format)
        }
        // dateTimeType
        if ($type === 'dtim' and $size >= 20) {
            return Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d',
                   ExifTool::Get16u($dataPt, $offset + 8),  ExifTool::Get16u($dataPt, $offset + 10),
                   ExifTool::Get16u($dataPt, $offset + 12), ExifTool::Get16u($dataPt, $offset + 14),
                   ExifTool::Get16u($dataPt, $offset + 16), ExifTool::Get16u($dataPt, $offset + 18));
        }
        // s15Fixed16ArrayType
        if ($type === 'sf32') {
            return ExifTool::ReadValue($dataPt, $offset + 8, 'fixed32s', ($size - 8) / 4, $size - 8);
        }
        // signatureType
        if ($type === 'sig ' and $size >= 12) {
            return substr($dataPt, $offset + 8, 4);
        }
        // textType
        if ($type === 'text') return substr($dataPt, $offset + 8, $size - 8);
        // textDescriptionType (ref 2, replaced by multiLocalizedUnicodeType)
        if ($type === 'desc' and $size >= 12) {
            $len = ExifTool::Get32u($dataPt, $offset + 8);
            if ($size >= $len + 12) {
                $str = substr($dataPt, $offset + 12, $len);
                $str = preg_replace('/\0.*/s', '', $str, 1);   // truncate at null terminator
                return $str;
            }
        }
        // u16Fixed16ArrayType
        if ($type === 'uf32') {
            return ExifTool::ReadValue($dataPt, $offset + 8, 'fixed32u', ($size - 8) / 4, $size - 8);
        }
        // uInt32ArrayType
        if ($type === 'ui32') {
            return ExifTool::ReadValue($dataPt, $offset + 8, 'int32u', ($size - 8) / 4, $size - 8);
        }
        // uInt64ArrayType
        if ($type === 'ui64') {
            return ExifTool::ReadValue($dataPt, $offset + 8, 'int64u', ($size - 8) / 8, $size - 8);
        }
        // uInt8ArrayType
        if ($type === 'ui08') {
            return ExifTool::ReadValue($dataPt, $offset + 8, 'int8u', $size - 8, $size - 8);
        }
        // XYZType
        if ($type === 'XYZ ') {
            $str = '';
            for ($pos = 8; $pos + 12 <= $size; $pos += 12) {
                if ($str !== '') $str .= ', ';
                $str .= Perl::str(ExifTool::ReadValue($dataPt, $offset + $pos, 'fixed32s', 3, $size - $pos));
            }
            return $str;
        }
        return null;    // data type is not supported
    }

    //------------------------------------------------------------------------------
    // Process ICC metadata record (ref 5)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessMetadata(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = $dirInfo['DirStart'];
        $dirLen = $dirInfo['DirLen'];
        $dirEnd = $dirStart + $dirLen;

        if ($dirLen < 16 or substr($dataPt, $dirStart, 4) !== 'dict') {
            $et->Warn('Invalid ICC meta dictionary');
            return 0;
        }
        $num = ExifTool::Get32u($dataPt, $dirStart + 8);
        $et->VerboseDir('Metadata', $num);
        $size = ExifTool::Get32u($dataPt, $dirStart + 12);
        if ($size < 16) {
            $et->Warn('Invalid ICC meta record size');
            return 0;
        }
        // NOTE: In the example the minimum offset is 20,
        // but this doesn't jive with the table (both in ref 5)
        $minPtr = 16 + $size * $num;
        for ($index = 0; $index < $num; ++$index) {
            $entry = $dirStart + 16 + $size * $index;
            if ($entry + $size > $dirEnd) {
                $et->Warn('Truncated ICC meta dictionary');
                break;
            }
            $namePtr = ExifTool::Get32u($dataPt, $entry);
            $nameLen = ExifTool::Get32u($dataPt, $entry + 4);
            $valuePtr = ExifTool::Get32u($dataPt, $entry + 8);
            $valueLen = ExifTool::Get32u($dataPt, $entry + 12);
            if (!($namePtr and $valuePtr)) continue;    // ignore if offsets are zero
            if ($namePtr < $minPtr or $namePtr + $nameLen > $dirLen or
                $valuePtr < $minPtr or $valuePtr + $valueLen > $dirLen)
            {
                $et->Warn('Corrupted ICC meta dictionary');
                break;
            }
            $tag = substr($dataPt, $dirStart + $namePtr, $nameLen);
            $val = substr($dataPt, $dirStart + $valuePtr, $valueLen);
            $tag = $et->Decode($tag, 'UTF16', 'MM', 'UTF8');
            $val = $et->Decode($val, 'UTF16', 'MM');
            // generate tagInfo if it doesn't exist
            if (!$tagTablePtr[$tag]) {
                $name = ucfirst($tag);
                $name = preg_replace_callback('/\s+(.)/', static fn ($m) => ucfirst($m[1]), $name);
                $name = preg_replace('/[^-_a-zA-Z0-9]/', '', $name);
                if (!strlen($name)) continue;
                $et->VPrint(0, $et->INDENT, "[adding $tag]\n");
                ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
            }
            $et->HandleTag($tagTablePtr, $tag, $val);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Validate ICC data
    // Inputs: 0) ICC data reference
    // Returns: error string or undef on success
    public static function ValidateICC($valPtr)
    {
        if ($valPtr instanceof ScalarRef) $valPtr = $valPtr->value;
        $valPtr = Perl::str($valPtr);
        $err = null;
        if (strlen($valPtr) < 24) return 'Invalid ICC profile';
        if (empty(Vars\ICC_Profile::$profileClass[substr($valPtr, 12, 4)])) $err = 'profile class';
        $col = substr($valPtr, 16, 4);  // ColorSpaceData
        $con = substr($valPtr, 20, 4);  // ConnectionSpace
        $match = '/(XYZ |Lab |Luv |YCbr|Yxy |RGB |GRAY|HSV |HLS |CMYK|CMY |[2-9A-F]CLR|nc..|\0{4})/';
        if (!preg_match($match, $col)) $err = 'color space';
        if (!preg_match($match, $con)) $err = 'connection space';
        return $err ? "Invalid ICC profile (bad $err)" : null;
    }

    //------------------------------------------------------------------------------
    // Process ICC profile file
    // Inputs: 0) ExifTool object reference, 1) Reference to directory information
    // Returns: 1 if this was an ICC file
    public static function ProcessICC(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;
        if ($raf->Read($buff, 24) != 24) return 0;
        // check to see if this is a valid ICC profile file
        if (self::ValidateICC($buff)) return 0;
        $et->SetFileType();
        // read the profile
        $size = Perl::unpack('N', $buff)[0];
        if ($size < 128 or $size & 0x80000000) {
            $et->Error("Bad ICC Profile length ($size)");
            return 1;
        }
        $raf->Seek(0, 0);
        if ($raf->Read($buff, $size) != $size) {
            $et->Error('Truncated ICC profile');
            return 1;
        }
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt' => $buff,
            'DataLen' => $size,
            'DirStart' => 0,
            'DirLen' => $size,
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ICC_Profile::Main');
        return self::ProcessICC_Profile($et, $subdirInfo, $tagTablePtr);
    }

    //------------------------------------------------------------------------------
    // Process ICC_Profile APP13 record
    // Inputs: 0) ExifTool object reference, 1) Reference to directory information
    //         2) Tag table reference (undefined to read ICC file)
    // Returns: 1 on success
    public static function ProcessICC_Profile(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = $dirInfo['DirLen'];
        $verbose = $et->Options('Verbose');

        if ($dirLen < 4) return 0;

        // extract binary ICC_Profile data block if binary mode or requested
        if ((($et->TAGS_FROM_FILE and empty($et->EXCL_TAG_LOOKUP['icc_profile'])) or
            !empty($et->REQ_TAG_LOOKUP['icc_profile'])) and
            // (don't extract from AsShotICCProfile or CurrentICCProfile)
            (empty($dirInfo['Name']) or $dirInfo['Name'] === 'ICC_Profile'))
        {
            $et->FoundTag('ICC_Profile', substr($dataPt, $dirStart, $dirLen));
        }

        ExifTool::SetByteOrder('MM');   // ICC_Profile is always big-endian

        // check length of table
        $len = ExifTool::Get32u($dataPt, $dirStart);
        if ($len != $dirLen or $len < 128) {
            $et->Warn("Bad length ICC_Profile (length $len)");
            if ($len < 128 or $dirLen < $len) return 0;
        }
        $pos = $dirStart + 128;     // position at start of table
        $numEntries = ExifTool::Get32u($dataPt, $pos);
        if ($numEntries < 1 or $numEntries >= 0x100
            or $numEntries * 12 + 132 > $dirLen)
        {
            $et->Warn("Bad ICC_Profile table ($numEntries entries)");
            return 0;
        }

        if ($verbose) {
            $et->VerboseDir('ICC_Profile', $numEntries, $dirLen);
            $fakeInfo = new TagInfo(['Name' => 'ProfileHeader', 'SubDirectory' => []]);
            $et->VerboseInfo(null, $fakeInfo);
        }
        // increment ICC dir count
        $dirCount = $et->DIR_COUNT['ICC'] = (($et->DIR_COUNT['ICC'] ?? null) ?: 0) + 1;
        if ($dirCount > 1) $et->SET_GROUP1 = '+' . $dirCount;
        // process the header block
        $subdirInfo = [
            'Name'     => 'ProfileHeader',
            'DataPt'   => $dataPt,
            'DataLen'  => $dirInfo['DataLen'] ?? null,
            'DirStart' => $dirStart,
            'DirLen'   => 128,
            'Parent'   => $dirInfo['DirName'] ?? null,
            'DirName'  => 'Header',
        ];
        $newTagTable = ExifTool::GetTagTable('Image::ExifTool::ICC_Profile::Header');
        $et->ProcessDirectory($subdirInfo, $newTagTable);

        $pos += 4;      // skip item count
        for ($index = 0; $index < $numEntries; ++$index) {
            $tagID  = substr($dataPt, $pos, 4);
            $offset = ExifTool::Get32u($dataPt, $pos + 4);
            $size   = ExifTool::Get32u($dataPt, $pos + 8);
            $pos += 12;
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tagID);
            // unknown tags aren't generated automatically by GetTagInfo()
            // if the tagID's aren't numeric, so we must do this manually:
            if (!$tagInfo and ($et->OPTIONS['Unknown'] or $verbose)) {
                $tagInfo = new TagInfo(['Unknown' => 1]);
                ExifTool::AddTagToTable($tagTablePtr, $tagID, $tagInfo);
            }
            if ($tagInfo === null) continue;

            if ($offset + $size > $dirLen) {
                $et->Warn('Bad ICC_Profile table (truncated)');
                break;
            }
            $valuePtr = $dirStart + $offset;

            $subdir = $tagInfo->SubDirectory;
            // format the value unless this is a subdirectory
            $value = $fmt = null;
            if ($size > 4) {
                $fmt = substr($dataPt, $valuePtr, 4);
                // handle multiLocalizedUnicodeType
                if ($fmt === 'mluc' and !$subdir) {
                    if ($size < 28) continue;
                    $count = ExifTool::Get32u($dataPt, $valuePtr + 8);
                    $recLen = ExifTool::Get32u($dataPt, $valuePtr + 12);
                    if ($recLen < 12) continue;
                    for ($i = 0; $i < $count; ++$i) {
                        $recPos = $valuePtr + 16 + $i * $recLen;
                        if ($recPos + $recLen > $valuePtr + $size) break;
                        $lang = substr($dataPt, $recPos, 4);
                        $langInfo = null;
                        // validate language code and change to standard case (just in case)
                        if (preg_match('/^([a-z]{2})([A-Z]{2})$/i', $lang, $m)) {
                            $lang = strtolower($m[1]) . '-' . strtoupper($m[2]);
                            if ($lang !== 'en-US') $langInfo = ExifTool::GetLangInfo($tagInfo, $lang);
                        }
                        $strLen = ExifTool::Get32u($dataPt, $recPos + 4);
                        $strPos = ExifTool::Get32u($dataPt, $recPos + 8);
                        if ($strPos + $strLen > $size) break;
                        $str = substr($dataPt, $valuePtr + $strPos, $strLen);
                        $str = $et->Decode($str, 'UTF16');
                        $et->HandleTag($tagTablePtr, $tagID, $str,
                            TagInfo: $langInfo ?: $tagInfo,
                            Table:  $tagTablePtr,
                            Index:  $index,
                            Value:  $str,
                            DataPt: $dataPt,
                            Size:   $strLen,
                            Start:  $valuePtr + $strPos,
                            Format: "type '{$fmt}'",
                        );
                    }
                    if ($i < $count) $et->Warn("Corrupted {$tagInfo->Name} data");
                    continue;
                }
            } else {
                $fmt = 'err ';
            }
            if (!$subdir) $value = self::FormatICCTag($dataPt, $valuePtr, $size);
            if ($verbose) {
                $et->VerboseInfo($tagID, $tagInfo,
                    Table:  $tagTablePtr,
                    Index:  $index,
                    Value:  $value,
                    DataPt: $dataPt,
                    Size:   $size,
                    Start:  $valuePtr,
                    Format: "type '{$fmt}'",
                );
            }
            if ($subdir) {
                $name = $tagInfo->Name;
                $newTagTable = null;
                if (!empty($subdir['TagTable'])) {
                    $newTagTable = ExifTool::GetTagTable($subdir['TagTable']);
                    if (!$newTagTable) {
                        ExifTool::PerlWarn("Unknown tag table {$subdir['TagTable']}\n");
                        continue;
                    }
                } else {
                    ExifTool::PerlWarn("Must specify TagTable for SubDirectory $name\n");
                    continue;
                }
                $subdirInfo = [
                    'Name'     => $name,
                    'DataPt'   => $dataPt,
                    'DataPos'  => $dirInfo['DataPos'] ?? null,
                    'DataLen'  => $dirInfo['DataLen'] ?? null,
                    'DirStart' => $valuePtr,
                    'DirLen'   => $size,
                    'DirName'  => $name,
                    'Parent'   => $dirInfo['DirName'] ?? null,
                ];
                $type = $fmt;
                //### eval Validate ($type)
                $validate = $subdir['Validate'] ?? null;
                if ($validate !== null) {
                    try {
                        $ok = $validate instanceof \Closure ? $validate(null, null, null, null, $type) : $validate;
                    } catch (\Throwable) {
                        $ok = null;
                    }
                }
                if ($validate !== null and !$ok) {
                    $et->Warn("Invalid ICC $name data");
                } else {
                    $et->ProcessDirectory($subdirInfo, $newTagTable, $subdir['ProcessProc'] ?? null);
                }
            } elseif ($value !== null) {
                $et->FoundTag($tagInfo, $value);
            } else {
                $value = substr($dataPt, $valuePtr, $size);
                // treat unsupported formats as binary data
                if ($tagInfo->ValueConv === null) $tagInfo->ValueConv = static fn ($val) => new ScalarRef($val);   // ('\$val')
                $et->FoundTag($tagInfo, $value);
            }
        }
        $et->SET_GROUP1 = null;
        return 1;
    }
}
