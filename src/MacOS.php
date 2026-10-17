<?php

//------------------------------------------------------------------------------
// File:         MacOS.pm
//
// Description:  Read/write MacOS system tags
//
// Revisions:    2017/03/01 - P. Harvey Created
//               2020/10/13 - PH Added ability to read MacOS "._" files
//
// PHP port of Image::ExifTool::MacOS 1.15 (reading only: System() and SetMacOSTags() are
// write-only). Tag tables and file lexicals (%mdDateInfo, %delXAttr) are generated into
// tables/MacOS.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MacOS
{
    public static $VERSION = '1.15';

    //------------------------------------------------------------------------------
    // Convert OS MDItem time string to standard EXIF-formatted local time
    // Inputs: 0) time string (eg. "2017-02-21 17:21:43 +0000")
    // Returns: EXIF-formatted local time string with timezone
    public static function MDItemLocalTime($val, $et = null)
    {
        $val = strtr(Perl::str($val), '-', ':');
        $val = preg_replace('/ ?([-+]\d{2}):?(\d{2})/', '$1:$2', $val, 1);
        // convert from UTC to local time
        if (preg_match('/\+00:00$/', $val)) {
            $time = ExifTool::GetUnixTime($val);
            if ($time) $val = ExifTool::ConvertUnixTime($time, 1);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // (PHP: Perl backticks in list context; $status is the Perl $?)
    private static function Backticks($cmd, &$status)
    {
        $lines = [];
        $fh = popen($cmd, 'r');
        if (!$fh) {
            $status = -1;
            return $lines;
        }
        while (($line = fgets($fh)) !== false) $lines[] = $line;
        $status = pclose($fh);
        return $lines;
    }

    //------------------------------------------------------------------------------
    // Extract MacOS metadata item tags
    // Inputs: 0) ExifTool object ref, 1) file name
    public static function ExtractMDItemTags(ExifTool $et, $file)
    {
        $tag = $val = $tmp = null;

        $fn = preg_replace('/([`"\$\\\\])/', '\\\\$1', $file);   // escape necessary characters
        $et->VPrint(0, '(running mdls)');
        $mdls = self::Backticks("/usr/bin/mdls \"$fn\" 2> /dev/null", $status);   // get MacOS metadata
        if ($status or !$mdls) {
            $et->Warn('Error running "mdls" to extract MDItem tags');
            return;
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MacOS::MDItem');
        $et->INDENT .= '| ';
        $et->VerboseDir('MDItem');
        foreach ($mdls as $_) {
            if (str_ends_with($_, "\n")) $_ = substr($_, 0, -1);    // chomp
            if (!(is_array($val) and array_is_list($val))) {
                if (!preg_match('/^k?(\w+)\s*= /', $_, $m)) continue;
                $_ = substr($_, strlen($m[0]));
                $tag = $m[1];
                if ($_ === '(') {   // (start of a list)
                    $val = [];
                    continue;
                }
                if ($_ === '(null)') $_ = '';
                if (str_starts_with($_, '"')) $_ = preg_replace('/"$/', '', substr($_, 1));  // remove quotes if they exist
                $val = $_;
            } elseif ($_ === ')') {   // (end of a list)
                $_ = $val[0] ?? null;
                if ($_ === null) continue;
            } else {
                // add item to list
                $_ = preg_replace('/^    /', '', $_);  // remove leading spaces
                $_ = preg_replace('/,$/', '', $_);     // remove trailing comma
                if ($_ === '(null)') $_ = '';
                if (str_starts_with($_, '"')) $_ = preg_replace('/"$/', '', substr($_, 1));  // remove quotes if they exist
                $_ = str_replace('\\"', '"', $_);       // un-escape quotes
                $_ = str_replace('\\\\', '\\', $_);     // un-escape backslashes
                $_ = $et->Decode($_, 'UTF8');
                $val[] = $_;
                continue;
            }
            // add to Extra tags if not done already
            if (!$tagTablePtr[$tag]) {
                // check for a date/time format
                $tagInfo = [];
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $_)) {
                    $tagInfo = [
                        'Groups' => [2 => 'Time'],
                        'ValueConv' => 'Image\ExifTool\MacOS::MDItemLocalTime',
                        // '$self->ConvertDateTime($val)'
                        'PrintConv' => static fn ($val, ExifTool $self) => $self->ConvertDateTime($val),
                    ];
                }
                // change tags like "com_apple_mail_xxx" to "AppleMailXxx"
                $tmp = preg_replace('/^com_/', '', $tag); // remove leading "com_"
                $tmp = preg_replace_callback('/_([a-z])/', fn($m) => strtoupper($m[1]), $tmp);  // use CamelCase
                $tagInfo['Name'] = ExifTool::MakeTagName($tmp);
                if (is_array($val)) $tagInfo['List'] = 1;
                if (str_contains($tag, 'Audio')) $tagInfo['Groups'][2] = 'Audio';
                if (preg_match('/(Copyright|Author)/', $tag)) $tagInfo['Groups'][2] = 'Author';
                $et->VPrint(0, "  [adding $tag]\n");
                ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            if (!is_array($val)) $val = $et->Decode($val, 'UTF8');
            $et->HandleTag($tagTablePtr, $tag, $val);
            $val = null;
        }
        $et->INDENT = preg_replace('/\| $/', '', $et->INDENT);
    }

    //------------------------------------------------------------------------------
    // Read MacOS XAttr value
    // Inputs: 0) ExifTool object ref, 1) file name
    public static function ReadXAttrValue(ExifTool $et, TagTable $tagTablePtr, $tag, $val)
    {
        // add to our table if necessary
        if (!$tagTablePtr[$tag]) {
            // generate tag name from attribute name
            if (preg_match('/^com\.apple\.(.*)$/', $tag, $m)) {
                $name = preg_replace('/^metadata:_?k/', '', $m[1]);
                $name = preg_replace('/^metadata:(com_)?/', '', $name);
            } else {
                $name = $tag;
            }
            $name = preg_replace_callback('/[.:_]([a-z])/', fn($m) => strtoupper($m[1]), $name);
            $name = 'XAttr' . ucfirst($name);
            $tagInfo = ['Name' => $name];
            if (preg_match('/Date$/', $tag)) $tagInfo['Groups'] = [2 => 'Time'];
            $et->VPrint(0, "  [adding $tag]\n");
            ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
        }
        if (str_starts_with($val, 'bplist0')) {
            $dirInfo = ['DataPt' => $val];
            ExifTool::RequireModule('Image::ExifTool::PLIST');
            if (PLIST::ProcessBinaryPLIST($et, $dirInfo, $tagTablePtr)) {
                if (PLIST::IsHash($dirInfo['Value'] ?? null)) return null;
                $val = $dirInfo['Value'];
            } else {
                $et->Warn("Error decoding {$tagTablePtr[$tag]->Name}");
                return null;
            }
        }
        if (!(is_array($val) or is_object($val)) and (str_contains(Perl::str($val), "\0") or strlen(Perl::str($val)) > 200) or $tag === 'XAttrMDLabel') {
            $val = new ScalarRef($val);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Read MacOS extended attribute tags using 'xattr' utility
    // Inputs: 0) ExifTool object ref, 1) file name
    public static function ExtractXAttrTags(ExifTool $et, $file)
    {
        $tag = $val = $warn = null;

        $fn = preg_replace('/([`"\$\\\\])/', '\\\\$1', $file);       // escape necessary characters
        $et->VPrint(0, '(running xattr)');
        $xattr = self::Backticks("/usr/bin/xattr -lx \"$fn\" 2> /dev/null", $status); // get MacOS extended attributes
        if ($status or !$xattr) {
            if ($status) $et->Warn('Error running "xattr" to extract XAttr tags');
            return;
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MacOS::XAttr');
        $et->INDENT .= '| ';
        $et->VerboseDir('XAttr');
        $xattr[] = '';    // (for a list terminator)
        foreach ($xattr as $_) {
            if (str_ends_with($_, "\n")) $_ = substr($_, 0, -1);    // chomp
            if (preg_match('/^[\dA-Fa-f]{8}/', $_)) {
                $_ = substr($_, 8);
                if (!$tag) {
                    $warn = 1;
                    continue;
                }
                $_ = preg_replace('/\|.*/', '', $_);
                $_ = str_replace(' ', '', $_);
                if (preg_match('/[^\dA-Fa-f]/', $_) or strlen($_) & 1) {
                    $warn = 2;
                    continue;
                }
                if ($val === null) $val = '';
                $val .= pack('H*', $_);
                continue;
            } elseif ($tag and $val !== null) {
                $val = self::ReadXAttrValue($et, $tagTablePtr, $tag, $val);
                if ($val !== null) $et->HandleTag($tagTablePtr, $tag, $val);
                $tag = null;
                $val = null;
            }
            if (!strlen($_)) continue;
            if (!str_ends_with($_, ':')) {  // attribute name must have trailing ":"
                $warn = 3;
                continue;
            }
            $_ = substr($_, 0, -1);
            if ($val !== null) {
                $warn = 4;
                $val = null;
            }
            // remove random ID after kMDLabel in tag ID
            $tag = preg_replace('/^com.apple.metadata:kMDLabel_.*/s', 'com.apple.metadata:kMDLabel', $_);
        }
        if ($warn) $et->Warn("Error $warn parsing \"xattr\" output");
        $et->INDENT = preg_replace('/\| $/', '', $et->INDENT);
    }

    //------------------------------------------------------------------------------
    // Extract MacOS file creation date/time
    // Inputs: 0) ExifTool object ref, 1) file name
    public static function GetFileCreateDate(ExifTool $et, $file)
    {
        $fn = preg_replace('/([`"\$\\\\])/', '\\\\$1', $file);   // escape necessary characters
        $et->VPrint(0, '(running stat)');
        $time = implode('', self::Backticks("/usr/bin/stat -f '%SB' -t '%Y:%m:%d %H:%M:%S%z' \"$fn\" 2> /dev/null", $status));
        if ($status or !$time or !preg_match('/([-+]\d{2})(\d{2})\s*$/', $time)) {
            $et->Warn('Error running "stat" to extract FileCreateDate');
            return;
        }
        $time = preg_replace('/([-+]\d{2})(\d{2})\s*$/', '$1:$2', $time, 1);
        $et->SET_GROUP1 = 'MacOS';
        $et->FoundTag('FileCreateDate', $time);
        $et->SET_GROUP1 = null;
    }

    //------------------------------------------------------------------------------
    // Read ATTR metadata from "._" file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Return: 1 on success
    // (ref https://www.swiftforensics.com/2018/11/the-dot-underscore-file-format.html)
    public static function ProcessATTR(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? 0;
        $dataLen = strlen($dataPt);

        if (!($dataLen >= 58 and preg_match('/^.{34}ATTR/s', $dataPt))) {
            $et->Warn('Invalid ATTR header');
            return 0;
        }
        $entries = ExifTool::Get32u($dataPt, 66);
        $et->VerboseDir('ATTR', $entries);
        // (Note: The RAF is not in $dirInfo because it would break RSRC reading --
        // the RSCR block uses relative offsets, while the ATTR block uses absolute! grrr!)
        $pos = 70;       // first entry is after ATTR header
        for ($i = 0; $i < $entries; ++$i) {
            if ($pos + 12 > $dataLen) {
                $et->Warn('Truncated ATTR entry');
                break;
            }
            $off = ExifTool::Get32u($dataPt, $pos);
            $len = ExifTool::Get32u($dataPt, $pos + 4);
            $n = ExifTool::Get8u($dataPt, $pos + 10);  // number of characters in tag name
            if ($pos + 11 + $n > $dataLen) {
                $et->Warn('Truncated ATTR name');
                break;
            }
            $off -= $dataPos;       // convert to relative offset (grrr!)
            // (Perl precedence: "$off < 0 or $off > $dataLen and ..." never stops for $off < 0)
            if (!($off < 0) and $off > $dataLen) {
                $et->Warn('Invalid ATTR offset');
                break;
            }
            $tag = substr($dataPt, $pos + 11, $n);
            $tag = preg_replace('/\0+$/', '', $tag);       // remove null terminator
            // remove random ID after kMDLabel in tag ID
            $tag = preg_replace('/^com.apple.metadata:kMDLabel_.*/s', 'com.apple.metadata:kMDLabel', $tag);
            if ($off + $len > $dataLen) {
                $et->Warn('Truncated ATTR value');
                break;
            }
            $val = self::ReadXAttrValue($et, $tagTablePtr, $tag, substr($dataPt, $off, $len));
            if ($val !== null) {
                $et->HandleTag($tagTablePtr, $tag, $val,
                    DataPt:  $dataPt,
                    DataPos: $dataPos,
                    Start:   $off,
                    Size:    $len,
                );
            }
            $pos += (11 + $n + 3) & -4; // step to next entry (on even 4-byte boundary)
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read information from a MacOS "._" sidecar file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid "._" file
    // (ref https://www.swiftforensics.com/2018/11/the-dot-underscore-file-format.html)
    public static function ProcessMacOS(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $hdr = $buff = null;

        if (!($raf->Read($hdr, 26) == 26 and preg_match('/^\0\x05\x16\x07\0(.)\0\0Mac OS X        /s', $hdr, $m))) return 0;
        $ver = ord($m[1]);
        // (extension may be anything, so just echo back the incoming file extension if it exists)
        $et->SetFileType(null, null, $et->FILE_EXT);
        if ($ver != 2) {
            $et->Warn("Unsupported file version $ver");
            return 1;
        }
        ExifTool::SetByteOrder('MM');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MacOS::Main');
        $entries = ExifTool::Get16u($hdr, 0x18);
        $et->VerboseDir('MacOS', $entries);
        if ($raf->Read($hdr, $entries * 12) != $entries * 12) {
            $et->Warn('Truncated header');
            return 1;
        }
        for ($i = 0; $i < $entries; ++$i) {
            $pos = $i * 12;
            $tag = ExifTool::Get32u($hdr, $pos);
            $off = ExifTool::Get32u($hdr, $pos + 4);
            $len = ExifTool::Get32u($hdr, $pos + 8);
            if ($len > 100000000) {
                $et->Warn('Record size too large');
                break;
            }
            if (!($raf->Seek($off, 0) and $raf->Read($buff, $len) == $len)) {
                $et->Warn('Truncated record');
                break;
            }
            $et->HandleTag($tagTablePtr, $tag, null, DataPt: $buff, DataPos: $off, Index: $i);
        }
        return 1;
    }
}
