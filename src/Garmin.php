<?php

//------------------------------------------------------------------------------
// File:         Garmin.pm
//
// Description:  Read Garmin FIT files
//
// Revisions:    2026-04-09 - P. Harvey Created
//
// References:   1) https://developer.garmin.com/fit/overview/
//               2) https://developer.garmin.com/fit/protocol/
//               3) https://github.com/garmin/fit-sdk-tools/blob/main/Profile.xlsx
//               4) https://docs.google.com/spreadsheets/d/1x34eRAZ45nbi3U3GyANotgmoQfj0fR49wBxmL-oLogc/
//               5) https://forums.garmin.com/developer/fit-sdk/f/discussion/254469/list-of-undocumented-mesg_num/1223595
//
// PHP port of Image::ExifTool::Garmin 1.02 (reading only). Tag tables and file lexicals
// (%baseType, @crc_table...) are generated into tables/Garmin.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Garmin
{
    public static $VERSION = '1.02';

    // (PHP: the generated %baseType holds the int64 invalid values as floats, but Perl
    //  compares the values read with their exact decimal strings)
    private const INVALID64 = [0x8e => '9223372036854775807', 0x8f => '18446744073709551615'];

    //------------------------------------------------------------------------------
    // Calculate CRC for each byte
    // Inputs: 0) running CRC, 1) byte value
    // Returns: updated CRC
    // - unused here, but included in case it may be helpful later
    public static function CRC($crc, $byte)
    {
        $crc_table = Vars\Garmin::$crc_table;
        // compute checksum of lower four bits of byte
        $tmp = $crc_table[$crc & 0xf];
        $crc = ($crc >> 4) & 0x0fff;
        $crc = $crc ^ $tmp ^ $crc_table[$byte & 0xf];
        // now compute checksum of upper four bits of byte
        $tmp = $crc_table[$crc & 0xf];
        $crc = ($crc >> 4) & 0x0fff;
        $crc = $crc ^ $tmp ^ $crc_table[($byte >> 4) & 0xf];
        return $crc;
    }

    //------------------------------------------------------------------------------
    // Read Garmin FIT file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Return: true on success
    public static function ProcessFIT(ExifTool $et, array &$dirInfo, ?TagTable $unused = null)
    {
        $baseType = Vars\Garmin::$baseType;
        $buff = $err = null;
        $msg = $done = $devData = [];
        $raf = $dirInfo['RAF'];
        $unknown = $et->OPTIONS['Unknown'];
        $verbose = $et->OPTIONS['Verbose'];
        $ee = $et->OPTIONS['ExtractEmbedded'];
        if ($verbose and !$unknown) $unknown = 1;

        // verify this is a valid Garmin FIT file
        if (!($raf->Read($buff, 12) == 12 and preg_match('/^.{8}\.FIT/s', $buff))) return 0;
        $et->SetFileType();
        $tagTbl = ExifTool::GetTagTable('Image::ExifTool::Garmin::FIT');
        $common = ExifTool::GetTagTable('Image::ExifTool::Garmin::Common');
        $devTbl = ExifTool::GetTagTable('Image::ExifTool::Garmin::Dev');
        // clear out old developer tags
        foreach (ExifTool::TagTableKeys($devTbl) as $k) unset($devTbl[$k]);
        $et->HandleTag($tagTbl, 'vers', ExifTool::Get8u($buff, 1));
        if (!$ee) $et->Warn('Use ExtractEmbedded option to extract all timed metadata', 3);
        [$hdrLen, $dataLen] = Perl::unpack('Cx3V', $buff);
        if ($hdrLen > 12) $raf->Read($buff, $hdrLen - 12);
        $timestamp = 0;
        for (;;) {
            $msgStart = $raf->Tell();
            if ($msgStart >= $hdrLen + $dataLen) break;
            if ($raf->Read($buff, 1) != 1) break;
            $flags = ord($buff);
            if ($flags & 0x80) { // compressed header
                $localNum = ($flags >> 5) & 0x03;
                // update current timestamp
                $timeOffset = $flags & 0x1f;
                if ($timeOffset) {
                    // the FIT specification is stupid here. The offset should have
                    // been defined as a simple difference from the previous timestamp
                    // instead of the lower 5 bits with a wrap-around.  Dumb.  All the
                    // resulting bit gymnastics are totally unnecessary:
                    $lowBits = $timestamp & 0x1f;
                    $ts = ($timestamp & 0xffffffe0) + $timeOffset;
                    if ($timeOffset < $lowBits) $ts += 0x20;
                    $msg[$localNum]['TS'] = [253, $ts]; // [num, new timestamp]
                }
            } else { // normal header
                $localNum = $flags & 0x0f;
                if ($flags & 0x40) {
                    //
                    // Process the definition message
                    //
                    if ($raf->Read($buff, 5) != 5) {
                        if ($raf->Tell() != $hdrLen + $dataLen + 2) { // (+2 for checksum)
                            $err = 'Unexpected end of file';
                        }
                        break;
                    }
                    ExifTool::SetByteOrder(ExifTool::Get8u($buff, 1) ? 'MM' : 'II');
                    $msgNum = ExifTool::Get16u($buff, 2);
                    $nFields = ExifTool::Get8u($buff, 4);
                    $len = $nFields * 3;
                    $theMsg = [];  // start new message definition (stored in %msg below)
                    $theMsg['ByteOrder'] = ExifTool::GetByteOrder();
                    $theMsg['MessageNum'] = $msgNum;
                    $tagInfo = $tagTbl[$msgNum];
                    $fieldInfo = null;
                    if ($tagInfo) {
                        $theMsg['Name'] = $msgName = $tagInfo->Name;
                    } else {
                        $theMsg['Name'] = $msgName = "Unknown$msgNum";
                        $tagInfo = ExifTool::AddTagToTable($tagTbl, $msgNum, ['Name' => $msgName, 'Unknown' => 1]);
                    }
                    if (!$tagInfo->SubDirectory) {
                        // construct table for this subdirectory
                        $tableName = 'Image::ExifTool::Garmin::' . $msgName;
                        // (Perl: %$tbl = ( GROUPS => ..., VARS => ... ); GetTagTable() only knows
                        //  the generated tables, so do its setup here)
                        $tbl = new TagTable([
                            'GROUPS' => [0 => 'Garmin', 1 => $msgName, 2 => 'Unknown'],
                            'VARS' => ['ID_FMT' => 'dec', 'NO_LOOKUP' => 1],
                        ]);
                        $tbl['TABLE_NAME'] = $tableName;
                        $tbl['SHORT_NAME'] = preg_replace('/^Image::ExifTool::/', '', $tableName);
                        $tbl['TAG_PREFIX'] = str_replace('::', '_', $tbl['SHORT_NAME']);
                        ExifTool::SetupTagTable($tbl);
                        if (!isset(ExifTool::$allTables[$tableName])) ExifTool::$tableOrder[] = $tableName;
                        ExifTool::$allTables[$tableName] = $tbl;
                        $tagInfo->SubDirectory = ['TagTable' => $tableName];
                    }
                    $subTbl = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable']);
                    $et->VPrint(1, "  + [$msgName definition message (local $localNum)]");
                    if ($raf->Read($buff, $len) != $len) {
                        $err = 'Truncated definition message';
                        break;
                    }
                    $totSize = 0;
                    if (!($tagInfo->Unknown and !$unknown)) {
                        $fieldInfo = [];
                    }
                    // loop through the message field definitions
                    for ($pos = 0; $pos < $len; $pos += 3) {
                        [$num, $size, $type] = Perl::unpack("x$pos C3", $buff);
                        if (isset($baseType[$type])) {
                            // remember offset of timestamp if this message has one
                            if ($num == 253 or $subTbl[$num] and empty($theMsg['TS']) and
                                // IsTimeStamp flag is set for timestamps to consider for
                                // incrementing the document number (eg. new GPS fix)
                                $subTbl[$num]->IsTimeStamp)
                            {
                                $theMsg['TS'] = [$num, $size, $type, $totSize];
                            }
                            // save field definitions for fields we care about
                            if ($fieldInfo !== null) $fieldInfo[] = [$num, $size, $type];
                        } else {
                            $et->Warn("Unknown field type $type");
                        }
                        $totSize += $size;
                    }
                    $nDev = 0;
                    if ($flags & 0x20) { // developer data flag
                        if ($raf->Read($buff, 1) != 1) {
                            $err = 'Missing developer definition';
                            break;
                        }
                        $nDev = ExifTool::Get8u($buff, 0);
                        $len = $nDev * 3;
                        if ($raf->Read($buff, $len) != $len) {
                            $err = 'Truncated developer definition';
                            break;
                        }
                        // loop through the developer fields
                        for ($i = 0, $pos = 0; $i < $nDev; ++$i, $pos += 3) {
                            [$num, $size, $idx] = Perl::unpack("x$pos C3", $buff);
                            // fieldInfo[3] is flag for developer field
                            if ($fieldInfo !== null) $fieldInfo[] = [$num, $size, $idx, 1];
                            $totSize += $size;
                        }
                    }
                    if ($fieldInfo !== null) $theMsg['FieldInfo'] = $fieldInfo;
                    $theMsg['Size'] = $totSize;
                    $msg[$localNum] = $theMsg;
                    if ($verbose > 2) {
                        $n = $raf->Tell() - $msgStart;
                        if (!($raf->Seek(-$n, 1) and $raf->Read($buff, $n))) {
                            $err = 'Seek error';
                            break;
                        }
                        $nDev = $n - 6 - 3 * $nFields;
                        if ($nDev) $nDev = ($nDev - 1) / 3;
                        $et->VPrint(1, "    ($nFields data + $nDev developer fields)");
                        $et->VerboseDump($buff, DataPos: $raf->Tell() - $n);
                    }
                    continue;
                }
            }
            //
            // Process the data message
            //
            $theMsg = $msg[$localNum] ?? null;
            if (!$theMsg) {
                $err = "Missing definition for local message $localNum";
                break;
            }
            $dataPos = $raf->Tell();
            $msgSize = $theMsg['Size'] ?? null;
            $msgNum = $theMsg['MessageNum'] ?? null;
            ExifTool::SetByteOrder($theMsg['ByteOrder'] ?? null);
            if (!$ee) {
                if (!empty($done[$msgNum ?? ''])) {
                    if (!$raf->Seek($msgSize, 1)) {
                        $err = 'Seek error';
                        break;
                    }
                    continue;
                } else {
                    $done[$msgNum ?? ''] = 1;
                }
            }
            if ($raf->Read($buff, $msgSize) != $msgSize) {
                $err = 'Truncated data message';
                break;
            }
            $fieldInfo = $theMsg['FieldInfo'] ?? null;
            $msgName = $theMsg['Name'] ?? null;
            $tagInfo = $tagTbl[$msgNum ?? ''];
            $subTbl = ExifTool::GetTagTable($tagInfo?->SubDirectory['TagTable'] ?? null);
            $oldIndent = $et->INDENT;
            $et->INDENT .= '| ';
            $comp = ($flags & 0x80) ? 'compressed header, ' : '';
            $et->VerboseDir($tagInfo?->Name, null, "(local $localNum) $comp$msgSize");
            if ($verbose > 2) {
                $tmp = chr($flags) . $buff;
                $et->VerboseDump($tmp, DataPos: $raf->Tell() - $msgSize - 1);
            }
            $ts = $theMsg['TS'] ?? null;
            if ($ts and $ts[0] == 253) {
                $parms = [];
                if (isset($ts[2])) {
                    $val = ExifTool::ReadValue($buff, $ts[3], $baseType[$ts[2]][0], 1, $ts[1]);
                } else {
                    $val = $ts[1];
                }
                // save TimeStamp tag now for unknown or compressed message
                if ($timestamp != Perl::num($val)) {
                    $timestamp = Perl::num($val);    // (PHP: only used as a number)
                    // increment document number for GPS data or any message with a new timestamp
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    if (!($fieldInfo and isset($ts[2]))) {
                        if (isset($ts[2])) {
                            $parms = [
                                'DataPt'  => $buff,
                                'DataPos' => $dataPos,
                                'Start'   => $ts[3],
                                'Size'    => $ts[1],
                                'Format'  => "{$baseType[$ts[2]][0]} \"{$baseType[$ts[2]][1]}\"",
                            ];
                        }
                        // set group 1 to message name
                        $et->SET_GROUP1 = $subTbl['GROUPS'][1];
                        $et->HandleTag($common, $ts[0], $val, ...$parms);
                        $et->SET_GROUP1 = null;
                    }
                }
            }
            // ignore this message unless it contains something else we care about
            if (!$fieldInfo) {
                $et->INDENT = $oldIndent;
                continue;
            }
            [$i, $pos] = [0, 0];
            $fieldValue = [];
            $tbl = null;
            foreach ($fieldInfo as $field) {
                [$num, $size, $type] = $field;
                if (empty($field[3])) { // standard field
                    $tbl = $subTbl;
                    if (!$tbl[$num]) {
                        if ($common[$num]) {
                            $tbl = $common;
                            $et->SET_GROUP1 = $subTbl['GROUPS'][1];
                        } else {
                            ExifTool::AddTagToTable($tbl, $num, ['Name' => "{$msgName}_$num", 'Unknown' => 1]);
                        }
                    }
                } else { // developer field
                    //
                    // Example layout of developer messages:
                    //
                    // DevData: app=A idx=0
                    // DevData: app=B idx=1
                    // FieldDescr: idx=0 (app A) num=0 type=uint8  name="speed" units="km/h"
                    // FieldDescr: idx=1 (app B) num=0 type=uint16 name="elev" units="m"
                    // FieldDescr: idx=1 (app B) num=1 type=string name="model" units=""
                    // Message XX definition:
                    //   Fields (2)
                    //     num=0 size=2 type=uint16 (for example)
                    //     num=1 size=4 type=uint32 (for example)
                    //   DevFields (2)
                    //     num=0 size=1 idx=0 (app A speed)
                    //     num=0 size=2 idx=1 (app B elev)
                    //     num=1 size=5 idx=1 (app B model)
                    // Message XX data:
                    //     uint16 uint32 unit8(speed) uint16(elev) "A380\0"(model)
                    //
                    $tbl = $devTbl;
                    $idx = $type;
                    $dev = $devData[$idx] ?? null;
                    if ($dev and !empty($dev[$num]) and isset($dev[$num][2]) and
                        isset($dev[$num][3]) and isset($dev['ID'][1]))
                    {
                        $id = bin2hex(Perl::str($dev['ID'][1])) . '_' . Perl::str($dev[$num][1] ?? null);
                        if (!$tbl[$id]) {
                            $name = Perl::str($dev[$num][3]);
                            $name = preg_replace_callback('/($|_)([a-z])/', fn($m) => strtoupper($m[2]), $name);
                            $name = ExifTool::MakeTagName($name);
                            $tagInfo = ['Name' => $name];
                            $units = $dev[$num][8] ?? null;
                            if ($units) {
                                $units = preg_replace('{[^-_a-zA-Z0-9/+*]}', '', $units);
                                // (Perl: PrintConv => qq("\$val $units"))
                                $tagInfo['PrintConv'] = static fn ($val, $self) => Perl::str($val) . " $units";
                            }
                            ExifTool::AddTagToTable($tbl, $id, $tagInfo);
                        }
                        $type = $dev[$num][2];
                        $num = $id;
                    } else {
                        if ($unknown) $et->Warn('Incomplete developer field definition');
                        $pos += $size;
                        continue;
                    }
                }
                if (isset($baseType[$type])) {
                    $fmt = $baseType[$type][0];
                    $count = $size / ExifTool::FormatSize($fmt);
                    if (Perl::int($count) == $count) {
                        $val = ExifTool::ReadValue($buff, $pos, $fmt, $count, $size);
                        // ignore invalid values
                        // (NAN may be 'NaN' or 'nan' on different systems, so 'lc' is required)
                        if (strtolower(Perl::str($val)) !== (self::INVALID64[$type] ?? Perl::str($baseType[$type][2]))) {
                            if (empty($field[3])) $fieldValue[$num] = $val;
                            $str = "$fmt \"{$baseType[$type][1]}\"";
                            if (!empty($field[3])) $str .= ", devIdx=$field[2]";
                            $et->HandleTag($tbl, $num, $fmt === 'undef' ? new ScalarRef($val) : $val,
                                DataPt:  $buff,
                                DataPos: $dataPos,
                                Start:   $pos,
                                Size:    $size,
                                Index:   $i++,
                                Format:  $str,
                            );
                        }
                    } else {
                        $et->Warn("Bad count for $fmt $msgName field $num");
                    }
                } else {
                    $et->Warn("Unknown field type $type");
                }
                $et->SET_GROUP1 = null;
                $pos += $size;
            }
            $et->INDENT = $oldIndent;
            // save messages required to decode developer fields
            if ($msgNum == 207) {           // DeveloperDataID
                $idx = $fieldValue[3] ?? null;   // DeveloperDataIndex
                if ($idx !== null) $devData[$idx]['ID'] = $fieldValue;
            } elseif ($msgNum == 206) {      // FieldDescription
                $idx = $fieldValue[0] ?? null;   // DeveloperDataIndex
                $num = $fieldValue[1] ?? null;
                if ($idx !== null and $num !== null) {
                    $devData[$idx][$num] = $fieldValue;
                }
            }
        }
        if ($err) $et->Warn($err);
        $et->DOC_NUM = null;
        return 1;
    }
}
