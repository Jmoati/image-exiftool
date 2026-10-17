<?php

//------------------------------------------------------------------------------
// File:         WPG.pm
//
// Description:  Read WordPerfect Graphics meta information
//
// Revisions:    2023-05-01 - P. Harvey Created
//
// References:   1) https://www.fileformat.info/format/wpg/egff.htm
//               2) https://archive.org/details/mac_Graphics_File_Formats_Second_Edition_1996/page/n991/mode/2up
//               3) http://libwpg.sourceforge.net/
//
// PHP port of Image::ExifTool::WPG 1.01 (reading only). Tag tables are generated
// into tables/WPG.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class WPG
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Print record type
    // Inputs: 0) record type and count, 1) inverse flag, 2) PrintConv hash ref
    // Returns: converted record name
    public static function PrintRecord($val, $inv, $printConv)
    {
        [$type, $count] = Perl::split('/x/', $val) + [null, null];
        $prt = ($printConv[$type ?? ''] ?? null) ?: Perl::sprintf('Unknown (0x%.2x)', $type);
        if ($count) $prt .= " x $count";
        return $prt;
    }

    //------------------------------------------------------------------------------
    // Read variable-length integer
    // Inputs: 0) RAF ref
    // Returns: integer value
    public static function ReadVarInt($raf)
    {
        $buff = null;
        if (!$raf->Read($buff, 1)) return 0;
        $val = ord($buff);
        if ($val == 0xff) {
            if ($raf->Read($buff, 2) != 2) return 0;
            $val = Perl::unpack('v', $buff)[0];
            if ($val & 0x8000) {
                if ($raf->Read($buff, 2) != 2) return 0;
                $val = (($val & 0x7fff) << 16) | Perl::unpack('v', $buff)[0];
            }
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Read WPG version 1 or 2 image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid WPG file
    public static function ProcessWPG(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $lastType = $count = null;

        // verify this is a valid WPG file
        if ($raf->Read($buff, 16) != 16) return 0;
        if (!preg_match('/^\xff\x57\x50\x43/', $buff)) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::WPG::Main');
        $offset = ExifTool::Get32u($buff, 4);
        [$ver, $rev] = Perl::unpack('x10CC', $buff);
        $et->HandleTag($tagTablePtr, 'WPGVersion', "$ver.$rev");
        if ($ver < 1 or $ver > 2) {
            // currently support only version 1 and 2 formats
            $et->Warn('Unsupported WPG version');
            return 1;
        }
        $tag = $ver == 1 ? 'Records' : 'RecordsV2';
        if ($offset > 16) {
            if (!$raf->Seek($offset - 16, 1)) return 1;
        }
        // loop through records
        for (;;) {
            $type = $len = $getSize = null;
            if ($raf->Read($buff, $ver) == $ver) {  // read 1 or 2 bytes, based on version
                if ($ver == 1) {
                    // read version 1 record header
                    $type = ord($buff);
                    $len = self::ReadVarInt($raf);
                    if ($type == 0x0f) $getSize = 1;    // Start WPG (Type 1)
                } else {
                    // read version 2 record header
                    $type = Perl::unpack('xC', $buff)[0];
                    self::ReadVarInt($raf);     // skip extensions
                    $len = self::ReadVarInt($raf);
                    if ($type == 0x01) $getSize = 1;    // Start WPG
                    if ($type > 0x3f) $type = null;
                }
                if ($getSize) {
                    // read Start record to obtain image size
                    if ($raf->Read($buff, $len) != $len) {
                        $et->Warn('File format error');
                        break;
                    }
                    $w = $h = $xres = $yres = $err = null;
                    if ($ver == 1) {
                        if ($len < 6) {
                            $err = '';
                        } else {
                            [$w, $h] = Perl::unpack('x2vv', $buff);
                        }
                    } else {
                        $precision = $format = null;
                        [$xres, $yres, $precision] = Perl::unpack('vvC', $buff) + [null, null, null];
                        if ($precision == 0 and $len >= 21) {
                            if (!($len >= 13 + 2 * 4)) $err = '';
                            $format = 'int16s';
                        } elseif ($precision == 1 and $len >= 29) {
                            if (!($len >= 13 + 4 * 4)) $err = '';
                            $format = 'int32s';
                        } else {
                            $err = 'Invalid integer precision';
                        }
                        if ($err === null) {
                            [$x1, $y1, $x2, $y2] = ExifTool::ReadValueList($buff, 13, $format, 4, $len - 13) + [null, null, null, null];
                            $w = abs($x2 - $x1);
                            $h = abs($y2 - $y1);
                        }
                    }
                    if ($err !== null) {
                        $et->Warn($err ?: 'Truncated start record');
                    } else {
                        $et->HandleTag($tagTablePtr, 'ImageWidthInches', $w / ($xres ?: 1200));
                        $et->HandleTag($tagTablePtr, 'ImageHeightInches', $h / ($yres ?: 1200));
                    }
                } else {
                    // skip to the next record
                    if (!$raf->Seek($len, 1)) {
                        $et->Warn('Truncated record');
                        break;
                    }
                }
            }
            // go to some trouble to collapse identical sequential entries in record list
            // (trying to keep the length of the list managable for complex images)
            if ($lastType and $type and $type == $lastType) {
                ++$count;
                continue;
            }
            if ($lastType) {
                $val = $count > 1 ? "{$lastType}x$count" : $lastType;
                $et->HandleTag($tagTablePtr, $tag, $val);
            }
            if (!$type) break;
            $lastType = $type;
            $count = 1;
        }
        return 1;
    }
}
