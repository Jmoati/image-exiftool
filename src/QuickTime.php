<?php

//------------------------------------------------------------------------------
// File:         QuickTime.pm
//
// Description:  Read QuickTime and MP4 meta information
//
// Revisions:    10/04/2005 - P. Harvey Created
//               12/19/2005 - P. Harvey Added MP4 support
//               09/22/2006 - P. Harvey Added M4A support
//               07/27/2010 - P. Harvey Updated to 2010-05-03 QuickTime spec
//
// References:
//
//   1) http://developer.apple.com/mac/library/documentation/QuickTime/QTFF/QTFFChap1/qtff1.html
//   2) http://search.cpan.org/dist/MP4-Info-1.04/
//   3) http://www.geocities.com/xhelmboyx/quicktime/formats/mp4-layout.txt
//   4) http://wiki.multimedia.cx/index.php?title=Apple_QuickTime
//   5) ISO 14496-12 (http://read.pudn.com/downloads64/ebook/226547/ISO_base_media_file_format.pdf)
//   6) ISO 14496-16 (http://www.iec-normen.de/previewpdf/info_isoiec14496-16%7Bed2.0%7Den.pdf)
//   7) http://atomicparsley.sourceforge.net/mpeg-4files.html
//   8) http://wiki.multimedia.cx/index.php?title=QuickTime_container
//   9) http://www.adobe.com/devnet/xmp/pdfs/XMPSpecificationPart3.pdf (Oct 2008)
//   10) http://code.google.com/p/mp4v2/wiki/iTunesMetadata
//   11) http://www.canieti.com.mx/assets/files/1011/IEC_100_1384_DC.pdf
//   12) QuickTime file format specification 2010-05-03
//   13) http://www.adobe.com/devnet/flv/pdf/video_file_format_spec_v10.pdf
//   14) http://standards.iso.org/ittf/PubliclyAvailableStandards/c051533_ISO_IEC_14496-12_2008.zip
//   15) http://getid3.sourceforge.net/source/module.audio-video.quicktime.phps
//   16) http://qtra.apple.com/atoms.html
//   17) http://www.etsi.org/deliver/etsi_ts/126200_126299/126244/10.01.00_60/ts_126244v100100p.pdf
//   18) https://github.com/appsec-labs/iNalyzer/blob/master/scinfo.m
//   19) http://nah6.com/~itsme/cvs-xdadevtools/iphone/tools/decodesinf.pl
//   20) https://developer.apple.com/legacy/library/documentation/quicktime/reference/QT7-1_Update_Reference/QT7-1_Update_Reference.pdf
//   21) Francois Bonzon private communication
//   22) https://developer.apple.com/library/mac/documentation/QuickTime/QTFF/Metadata/Metadata.html
//   23) http://atomicparsley.sourceforge.net/mpeg-4files.html
//   24) https://github.com/sergiomb2/libmp4v2/wiki/iTunesMetadata
//   25) https://cconcolato.github.io/mp4ra/atoms.html
//   26) https://github.com/SamsungVR/android_upload_sdk/blob/master/SDKLib/src/main/java/com/samsung/msca/samsungvr/sdk/UserVideo.java
//   27) https://exiftool.org/forum/index.php?topic=11517.0
//   28) https://docs.mp3tag.de/mapping/
//   29) https://developer.apple.com/documentation/quicktime-file-format/media_data_reference_atom
//
// PHP port of Image::ExifTool::QuickTime 3.35 (reading only). Tag tables and package
// variables (%ftypLookup, %eeBox, %qtFlags...) are generated into tables/QuickTime.php and
// Vars\QuickTime. The read-side subs of WriteQuickTime.pl (GetLangInfo) are included, and
// the subs of QuickTimeStream.pl (timed metadata, ExtractEmbedded option) are in the
// trait QuickTime\Stream (src/QuickTime/Stream.php).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class QuickTime
{
    use QuickTime\Stream;

    public static $VERSION = '3.35';

    /** ValueConv of %unknownInfo (Perl: '$val =~ /^([\x20-\x7e]*)\0*$/ ? $1 : \$val') */
    private static ?\Closure $unknownValueConv = null;

    // handle unknown tags
    // (PHP: %unknownInfo with its ValueConv expression translated)
    private static function UnknownInfo(): array
    {
        $info = Vars\QuickTime::$unknownInfo;
        $info['ValueConv'] = self::$unknownValueConv ??= static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag) {
            return preg_match('/^([\x20-\x7e]*)\0*$/', Perl::str($val), $m) ? $m[1] : new ScalarRef($val);
        };
        return $info;
    }

    /**
     * A SubDirectory ProcessProc given in Perl as a sub name ('Image::ExifTool::PLIST::ProcessBinaryPLIST',
     * called by name by ProcessDirectory) is generated verbatim: make it a PHP callable.
     * (PHP work-around for tools/export-tables.pl, a no-op for generated callables)
     */
    private static function SubProc($proc)
    {
        if (is_string($proc) and preg_match('/^Image::ExifTool::(?:(\w+)::)?(\w+)$/', $proc, $m)) {
            return 'Image\ExifTool\\' . ($m[1] ?: 'ExifTool') . '::' . $m[2];
        }
        return $proc;
    }

    // (GetRotationMatrix and GetMatrixStructure are used only when writing)

    //------------------------------------------------------------------------------
    // Get rotation angle from a matrix
    // Inputs: 0) rotation matrix as a string
    // Return: positive rotation angle in degrees rounded to 3 decimal points,
    //         or undef on error
    public static function GetRotationAngle($rotMatrix)
    {
        $a = Perl::split(' ', $rotMatrix);
        if (Perl::num($a[0] ?? null) == 0 and Perl::num($a[1] ?? null) == 0) return null;
        // calculate the rotation angle (assume uniform rotation)
        $angle = atan2(Perl::num($a[1]), Perl::num($a[0])) * 180 / 3.14159;
        if ($angle < 0) $angle += 360;
        return Perl::int($angle * 1000 + 0.5) / 1000;
    }

    //------------------------------------------------------------------------------
    // Calculate rotation of video track
    // Inputs: 0) ExifTool object ref
    // Returns: rotation angle or undef
    public static function CalcRotation(ExifTool $et)
    {
        $value = $et->VALUE;
        $track = null;
        // get the video track family 1 group (eg. "Track1");
        for ($i = 0; ; ++$i) {
            $idx = $i ? " ($i)" : '';
            $tag = "HandlerType$idx";
            if (empty($value[$tag])) break;
            if (Perl::str($value[$tag]) !== 'vide') continue;
            $track = $et->GetGroup($tag, 1);
            break;
        }
        if (!$track) return null;
        // get the video track matrix
        for ($i = 0; ; ++$i) {
            $idx = $i ? " ($i)" : '';
            $tag = "MatrixStructure$idx";
            if (empty($value[$tag])) break;
            if ($et->GetGroup($tag, 1) !== $track) continue;
            return self::GetRotationAngle($value[$tag]);
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Determine the average sample rate from a time-to-sample table
    // Inputs: 0) ExifTool object ref, 1) time-to-sample table data ref
    // Returns: average sample rate (in Hz)
    public static function CalcSampleRate(ExifTool $et, $valPt)
    {
        $dat = Perl::unpack('N*', $valPt);
        [$num, $dur] = [0, 0];
        for ($i = 2; $i < count($dat) - 1; $i += 2) {
            $num += $dat[$i];                   // total number of samples
            $dur += $dat[$i] * $dat[$i + 1];    // total sample duration
        }
        if (!($num and $dur and $et->MediaTS)) return null;
        return $num * Perl::num($et->MediaTS) / $dur;
    }

    //------------------------------------------------------------------------------
    // Fix incorrect format for ImageWidth/Height as written by Pentax
    public static function FixWrongFormat($val)
    {
        if (!$val) return null;
        return ((int) Perl::num($val) & 0xfff00000) ? Perl::unpack('n', Perl::pack('N', $val))[0] : $val;
    }

    //------------------------------------------------------------------------------
    // Convert ISO 6709 string to standard lag/lon format
    // Inputs: 0) ISO 6709 string (lat, lon, and optional alt)
    // Returns: position in decimal degrees with altitude if available
    // Notes: Wikipedia indicates altitude may be in feet -- how is this specified?
    public static function ConvertISO6709($val)
    {
        $str = Perl::str($val);
        if (preg_match('/^([-+]\d{1,2}(?:\.\d*)?)([-+]\d{1,3}(?:\.\d*)?)([-+]\d+(?:\.\d*)?)?/', $str, $m)) {
            // +DD.DDD+DDD.DDD+AA.AAA
            $val = Perl::str(Perl::num($m[1]) + 0) . ' ' . Perl::str(Perl::num($m[2]) + 0);
            if (!empty($m[3])) $val .= ' ' . Perl::str(Perl::num($m[3]) + 0);
        } elseif (preg_match('/^([-+])(\d{2})(\d{2}(?:\.\d*)?)([-+])(\d{3})(\d{2}(?:\.\d*)?)([-+]\d+(?:\.\d*)?)?/', $str, $m)) {
            // +DDMM.MMM+DDDMM.MMM+AA.AAA
            $lat = Perl::num($m[2]) + Perl::num($m[3]) / 60;
            if ($m[1] === '-') $lat = -$lat;
            $lon = Perl::num($m[5]) + Perl::num($m[6]) / 60;
            if ($m[4] === '-') $lon = -$lon;
            $val = Perl::str($lat) . ' ' . Perl::str($lon);
            if (!empty($m[7])) $val .= ' ' . Perl::str(Perl::num($m[7]) + 0);
        } elseif (preg_match('/^([-+])(\d{2})(\d{2})(\d{2}(?:\.\d*)?)([-+])(\d{3})(\d{2})(\d{2}(?:\.\d*)?)([-+]\d+(?:\.\d*)?)?/', $str, $m)) {
            // +DDMMSS.SSS+DDDMMSS.SSS+AA.AAA
            $lat = Perl::num($m[2]) + Perl::num($m[3]) / 60 + Perl::num($m[4]) / 3600;
            if ($m[1] === '-') $lat = -$lat;
            $lon = Perl::num($m[6]) + Perl::num($m[7]) / 60 + Perl::num($m[8]) / 3600;
            if ($m[5] === '-') $lon = -$lon;
            $val = Perl::str($lat) . ' ' . Perl::str($lon);
            if (!empty($m[9])) $val .= ' ' . Perl::str(Perl::num($m[9]) + 0);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Convert Nero chapter list (ref ffmpeg libavformat/movenc.c)
    // Inputs: 0) binary chpl data
    // Returns: chapter list
    public static function ConvertChapterList($val)
    {
        $val = Perl::str($val);
        $size = strlen($val);
        if ($size < 9) return '<invalid>';
        $num = ExifTool::Get8u($val, 8);
        $chapters = [];
        $pos = 9;
        for ($i = 0; $i < $num; ++$i) {
            if ($pos + 9 > $size) break;
            $dur = Perl::num(ExifTool::Get64u($val, $pos)) / 10000000;
            $len = ExifTool::Get8u($val, $pos + 8);
            if ($pos + 9 + $len > $size) break;
            $title = substr($val, $pos + 9, $len);
            $pos += 9 + $len;
            $chapters[] = Perl::str($dur) . " $title";
        }
        return $chapters;   // return as a list
    }

    //------------------------------------------------------------------------------
    // Print conversion for a Nero chapter list item
    // Inputs: 0) ValueConv chapter string
    // Returns: formatted chapter string
    public static function PrintChapter($val)
    {
        if (!preg_match('/^(\S+) (.*)/', Perl::str($val), $mt)) return $val;
        [$dur, $title] = [Perl::num($mt[1]), $mt[2]];
        $h = Perl::int($dur / 3600);
        $dur -= $h * 3600;
        $m = Perl::int($dur / 60);
        $s = $dur - $m * 60;
        $ss = Perl::sprintf('%06.3f', $s);
        if (Perl::num($ss) >= 60) {
            $ss = '00.000';
            if (++$m >= 60) {
                $m -= 60;
                ++$h;
            }
        }
        return Perl::sprintf('[%d:%.2d:%s] %s', $h, $m, $ss, $title);
    }

    //------------------------------------------------------------------------------
    // Format GPSCoordinates for printing
    // Inputs: 0) string with numerical lat, lon and optional alt, separated by spaces
    //         1) ExifTool object reference
    // Returns: PrintConv value
    public static function PrintGPSCoordinates($val, ?ExifTool $et = null)
    {
        $v = Perl::split(' ', $val);
        $prt = GPS::ToDMS($et, $v[0] ?? null, 1, 'N') . ', ' .
               GPS::ToDMS($et, $v[1] ?? null, 1, 'E');
        if (isset($v[2])) {
            $prt .= ', ' . (Perl::num($v[2]) < 0 ? Perl::str(-Perl::num($v[2])) . ' m Below' : $v[2] . ' m Above') . ' Sea Level';
        }
        return $prt;
    }

    //------------------------------------------------------------------------------
    // Unpack packed ISO 639/T language code
    // Inputs: 0) packed language code (or undef/0), 1) true to not treat 'und' and 'eng' as default
    // Returns: language code, or undef/0 for default language, or 'err' for format error
    public static function UnpackLang($lang, $noDef = null)
    {
        if ($lang) {
            $packed = (int) Perl::num($lang);
            // language code is packed in 5-bit characters
            $lang = Perl::pack('C*', ...array_map(static fn ($s) => (($packed >> $s) & 0x1f) + 0x60, [10, 5, 0]));
            // validate language code
            if (preg_match('/^[a-z]+$/', $lang)) {
                // treat 'eng' or 'und' as the default language
                if (($lang === 'und' or $lang === 'eng') and !$noDef) $lang = null;
            } else {
                $lang = 'err';  // invalid language code
            }
        }
        return $lang;
    }

    //------------------------------------------------------------------------------
    // Get language code string given QuickTime language and country codes
    // Inputs: 0) numerical language code, 1) numerical country code, 2) no defaults
    // Returns: language code string (ie. "fra-FR") or undef for default language
    // ex) 0x15c7 0x0000 is 'eng' with no country (ie. returns 'und' unless $noDef)
    //     0x15c7 0x5553 is 'eng-US'
    //     0x1a41 0x4652 is 'fra-FR'
    //     0x55c4 is 'und'
    public static function GetLangCode($lang, $ctry = null, $noDef = null)
    {
        // ignore country ('ctry') and language lists ('lang') for now
        if ($ctry and $ctry <= 255) $ctry = null;
        if ($lang and $lang <= 255) $lang = null;
        $langCode = self::UnpackLang($lang, $noDef);
        // add country code if specified
        if ($ctry) {
            $ctry = Perl::unpack('a2', Perl::pack('n', $ctry))[0]; // unpack as ISO 3166-1
            // treat 'ZZ' like a default country (see ref 12)
            if ($ctry === 'ZZ') $ctry = null;
            if ($ctry and preg_match('/^[A-Z]{2}$/', $ctry)) {
                if (!$langCode) $langCode = self::UnpackLang($lang, 1) ?: 'und';
                $langCode .= "-$ctry";
            }
        }
        return $langCode;
    }

    //------------------------------------------------------------------------------
    // Get langInfo hash and save details about alt-lang tags
    // Inputs: 0) ExifTool ref, 1) tagInfo hash ref, 2) locale code
    // Returns: new tagInfo hash ref, or undef if invalid
    public static function GetLangInfoQT(ExifTool $et, TagInfo $tagInfo, $langCode)
    {
        $langInfo = self::CoreGetLangInfo($tagInfo, $langCode);
        if ($langInfo) {
            if (!$et->QTLang) $et->QTLang = [];
            $et->QTLang[] = $langInfo->Name;
        }
        return $langInfo;
    }

    /**
     * Image::ExifTool::GetLangInfo (Writer.pl): make a new tagInfo hash for this locale.
     * (PHP: the core doesn't have it, this is a copy)
     */
    private static function CoreGetLangInfo(TagInfo $tagInfo, $langCode): ?TagInfo
    {
        if (method_exists(ExifTool::class, 'GetLangInfo')) return ExifTool::GetLangInfo($tagInfo, $langCode);
        // make a new tagInfo hash for this locale
        $table = $tagInfo->Table;
        $tagID = Perl::str($tagInfo->TagID) . '-' . $langCode;
        $langInfo = $table[$tagID];
        if (!$langInfo instanceof TagInfo) {
            // make a new tagInfo entry for this locale
            $langInfo = ExifTool::AddTagToTable($table, $tagID, [
                ...$tagInfo->toArray(),
                'Name' => $tagInfo->Name . '-' . $langCode,
                'Description' => ExifTool::MakeDescription($tagInfo->Name) . " ($langCode)",
                'LangCode' => $langCode,
                'SrcTagInfo' => $tagInfo, // save reference to original tagInfo
            ]);
        }
        return $langInfo;
    }

    //------------------------------------------------------------------------------
    // Get variable-length integer from data (used by ParseItemLocation)
    // Inputs: 0) data ref, 1) start position, 2) integer size in bytes (0, 4 or 8),
    //         3) default value
    // Returns: integer value, and updates current position
    public static function GetVarInt($dataPt, &$pos, $n, $default = null)
    {
        $len = strlen($dataPt);
        $p = $pos;
        $pos = $p + $n;     // update current position
        if ($p + $n > $len) return null;
        if ($n == 0) {
            return $default ?: 0;
        } elseif ($n == 4) {
            return ExifTool::Get32u($dataPt, $p);
        } elseif ($n == 8) {
            return ExifTool::Get64u($dataPt, $p);
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Get null-terminated string from binary data (used by ParseItemInfoEntry)
    // Inputs: 0) data ref, 1) start position
    // Returns: string, and updates current position
    public static function GetString($dataPt, &$pos)
    {
        $len = strlen($dataPt);
        $str = '';
        $p = $pos;
        while ($p < $len) {
            $ch = $dataPt[$p];
            ++$p;
            if (ord($ch) == 0) break;
            $str .= $ch;
        }
        $pos = $p;  // update current position
        return $str;
    }

    //------------------------------------------------------------------------------
    // Get a printable version of the tag ID
    // Inputs: 0) tag ID, 1) Flag: 0x01 - print as 4- or 8-digit hex value if necessary
    //                             0x02 - put leading backslash before escaped character
    // Returns: Printable tag ID
    public static function PrintableTagID($tagID, $flag = null)
    {
        $id = Perl::str($tagID);
        $tag = preg_replace_callback('/([^-_a-zA-Z0-9])/', static fn ($m) => 'x' . bin2hex($m[1]), $id, -1, $n);
        if ($n and $flag) {
            if ($n > 2 and ($flag & 0x01)) {
                $tag = '0x' . Perl::unpack('H8', $id)[0];
                $tag = preg_replace('/^0x0000/', '0x', $tag, 1);
            } elseif ($flag & 0x02) {
                $tag = preg_replace_callback('/([^-_a-zA-Z0-9])/', static fn ($m) => '\\x' . bin2hex($m[1]), $id);
            }
        }
        return $tag;
    }

    //==============================================================================
    // The following ParseXxx routines parse various boxes to extract this
    // information about embedded items in a $$et{ItemInfo} hash, keyed by item ID:
    //
    // iloc:
    //  ConstructionMethod - offset type: 0=file, 1=idat, 2=item
    //  DataReferenceIndex - 0 for "this file", otherwise index in dref box
    //  BaseOffset         - base for file offsets
    //  Extents            - list of details for data in file:
    //                           0) index  (extent_index)
    //                           1) offset (extent_offset)
    //                           2) length (extent_length)
    //                           3) nlen   (length_size)
    //                           4) lenPt  (pointer to length word)
    // infe:
    //  ProtectionIndex    - index if item is protected (0 for unprotected)
    //  Name               - item name
    //  ContentType        - mime type of item
    //  ContentEncoding    - item encoding
    //  URI                - URI of a 'uri '-type item
    //  infe               - raw data for 'infe' box (when writing only) [retracted]
    // ipma:
    //  Association        - list of associated properties in the ipco container
    //  Essential          - list of "essential" flags for the associated properties
    // cdsc:
    //  RefersTo           - hash lookup of flags based on referred item ID
    // other:
    //  DocNum             - exiftool document number for this item
    //
    //------------------------------------------------------------------------------
    // Parse item location (iloc) box (ref ISO 14496-12:2015 pg.79)
    // Inputs: 0) iloc data, 1) ExifTool ref
    // Returns: undef, and fills in ExifTool ItemInfo hash
    // Notes: see also Handle_iloc() in WriteQuickTime.pl
    public static function ParseItemLocation($val, ExifTool $et)
    {
        $extent_index = $extent_offset = $extent_length = null;

        $verbose = $et->IsWriting ? 0 : $et->Options('Verbose');
        if (!$et->ItemInfo) $et->ItemInfo = [];
        $items = &$et->ItemInfo;
        $val = Perl::str($val);
        $len = strlen($val);
        if ($len < 8) return null;
        $ver = ExifTool::Get8u($val, 0);
        $siz = ExifTool::Get16u($val, 4);
        $noff = ($siz >> 12);
        $nlen = ($siz >> 8) & 0x0f;
        $nbas = ($siz >> 4) & 0x0f;
        $nind = $siz & 0x0f;
        if ($ver < 2) {
            $num = ExifTool::Get16u($val, 6);
            $pos = 8;
        } else {
            if ($len < 10) return null;
            $num = ExifTool::Get32u($val, 6);
            $pos = 10;
        }
        for ($i = 0; $i < $num; ++$i) {
            if ($ver < 2) {
                if ($pos + 2 > $len) return null;
                $id = ExifTool::Get16u($val, $pos);
                $pos += 2;
            } else {
                if ($pos + 4 > $len) return null;
                $id = ExifTool::Get32u($val, $pos);
                $pos += 4;
            }
            if ($ver == 1 or $ver == 2) {
                if ($pos + 2 > $len) return null;
                $items[$id]['ConstructionMethod'] = ExifTool::Get16u($val, $pos) & 0x0f;
                $pos += 2;
            }
            if ($pos + 2 > $len) return null;
            $items[$id]['DataReferenceIndex'] = ExifTool::Get16u($val, $pos);
            $pos += 2;
            $items[$id]['BaseOffset'] = self::GetVarInt($val, $pos, $nbas);
            if ($pos + 2 > $len) return null;
            $ext_num = ExifTool::Get16u($val, $pos);
            $pos += 2;
            $extents = [];
            for ($j = 0; $j < $ext_num; ++$j) {
                if ($ver == 1 or $ver == 2) {
                    $extent_index = self::GetVarInt($val, $pos, $nind, 1);
                }
                $extent_offset = self::GetVarInt($val, $pos, $noff);
                $extent_length = self::GetVarInt($val, $pos, $nlen);
                if ($extent_length === null) return null;
                if ($verbose) {
                    $et->VPrint(1, "{$et->INDENT}  Item $id: const_meth=",
                        $items[$id]['ConstructionMethod'] ?? '',
                        Perl::sprintf(" base=0x%x offset=0x%x len=0x%x\n", $items[$id]['BaseOffset'],
                            $extent_offset, $extent_length));
                }
                $extents[] = [$extent_index, $extent_offset, $extent_length, $nlen, $pos - $nlen];
            }
            // save item location information keyed on 1-based item ID:
            $items[$id]['Extents'] = $extents;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Parse content describes entry (cdsc) box
    // Inputs: 0) cdsc data, 1) ExifTool ref
    // Returns: undef, and fills in ExifTool ItemInfo hash
    public static function ParseContentDescribes($val, ExifTool $et)
    {
        $val = Perl::str($val);
        if ($et->ItemRefVersion) {
            if (strlen($val) < 10) return null;
            $to = Perl::unpack('NnN*', $val);
        } else {
            if (strlen($val) < 6) return null;
            $to = Perl::unpack('nnn*', $val);
        }
        $id = array_shift($to);
        $count = array_shift($to);
        if ($count > count($to)) {
            $str = 'Missing values in ContentDescribes box';
            $et->IsWriting ? $et->Error($str) : $et->Warn($str);
        } elseif ($count < count($to)) {
            $et->Warn('Ignored extra values in ContentDescribes box', 1);
            $to = [$count];
        }
        // add all referenced item ID's to a "RefersTo" lookup
        foreach ($to as $t) $et->ItemInfo[$id]['RefersTo'][$t] = 1;
        return null;
    }

    //------------------------------------------------------------------------------
    // Parse item information entry (infe) box (ref ISO 14496-12:2015 pg.82)
    // Inputs: 0) infe data, 1) ExifTool ref
    // Returns: undef, and fills in ExifTool ItemInfo hash
    public static function ParseItemInfoEntry($val, ExifTool $et)
    {
        $id = null;

        $verbose = $et->IsWriting ? 0 : $et->Options('Verbose');
        if (!$et->ItemInfo) $et->ItemInfo = [];
        $items = &$et->ItemInfo;
        $val = Perl::str($val);
        $len = strlen($val);
        if ($len < 4) return null;
        $ver = ExifTool::Get8u($val, 0);
        $pos = 4;
        if ($pos + 4 > $len) return null;
        if ($ver == 0 or $ver == 1) {
            $id = ExifTool::Get16u($val, $pos);
            $items[$id]['ProtectionIndex'] = ExifTool::Get16u($val, $pos + 2);
            $pos += 4;
            $items[$id]['Name'] = self::GetString($val, $pos);
            $items[$id]['ContentType'] = self::GetString($val, $pos);
            $items[$id]['ContentEncoding'] = self::GetString($val, $pos);
        } else {
            if ($ver == 2) {
                $id = ExifTool::Get16u($val, $pos);
                $pos += 2;
            } elseif ($ver == 3) {
                $id = ExifTool::Get32u($val, $pos);
                $pos += 4;
            }
            if ($pos + 6 > $len) return null;
            $key = $id ?? '';   // (Perl undef key)
            $items[$key]['ProtectionIndex'] = ExifTool::Get16u($val, $pos);
            $type = substr($val, $pos + 2, 4);
            $items[$key]['Type'] = $type;
            $pos += 6;
            $items[$key]['Name'] = self::GetString($val, $pos);
            if ($type === 'mime') {
                $items[$key]['ContentType'] = self::GetString($val, $pos);
                $items[$key]['ContentEncoding'] = self::GetString($val, $pos);
            } elseif ($type === 'uri ') {
                $items[$key]['URI'] = self::GetString($val, $pos);
            }
        }
        //[retracted] # save raw infe box when writing in case we need to sort items later
        //[retracted] $$items{$id}{infe} = pack('N', length($val)+8) . 'infe' . $val if $$et{IsWriting};
        if ($verbose > 1) {
            $item = $items[$id ?? ''];
            $et->VPrint(1, "{$et->INDENT}  Item " . Perl::str($id) . ': Type=', ($item['Type'] ?? null) ?: '',
                           ' Name=', ($item['Name'] ?? null) ?: '',
                           ' ContentType=', ($item['ContentType'] ?? null) ?: '',
                           ($et->PrimaryItem and $et->PrimaryItem == $id) ? ' (PrimaryItem)' : '',
                           "\n");
        }
        if (!(Perl::num($id) > Perl::num($et->LastItemID))) {
            $et->Warn('Item info entries are out of order'); //[retracted] unless $$et{IsWriting};
            //[retracted] $$et{ItemsNotSorted} = 1;   # set flag indicating the items weren't sorted
        }
        $et->LastItemID = $id;
        return null;
    }

    //------------------------------------------------------------------------------
    // Parse item property association (ipma) box (ref https://github.com/gpac/gpac/blob/master/src/isomedia/iff.c)
    // Inputs: 0) ipma data, 1) ExifTool ref
    // Returns: undef, and fills in ExifTool ItemInfo hash
    public static function ParseItemPropAssoc($val, ExifTool $et)
    {
        $verbose = $et->IsWriting ? 0 : $et->Options('Verbose');
        if (!$et->ItemInfo) $et->ItemInfo = [];
        $items = &$et->ItemInfo;
        $val = Perl::str($val);
        $len = strlen($val);
        if ($len < 8) return null;
        $ver = ExifTool::Get8u($val, 0);
        $flg = ExifTool::Get32u($val, 0);
        $num = ExifTool::Get32u($val, 4);
        $pos = 8;
        $lastID = -1;
        for ($i = 0; $i < $num; ++$i) {
            if ($ver == 0) {
                if ($pos + 3 > $len) return null;
                $id = ExifTool::Get16u($val, $pos);
                $pos += 2;
            } else {
                if ($pos + 5 > $len) return null;
                $id = ExifTool::Get32u($val, $pos);
                $pos += 4;
            }
            $n = ExifTool::Get8u($val, $pos++);
            $association = $essential = [];
            if ($flg & 0x01) {
                if ($pos + $n * 2 > $len) return null;
                for ($j = 0; $j < $n; ++$j) {
                    $tmp = ExifTool::Get16u($val, $pos + $j * 2);
                    $association[] = $tmp & 0x7fff;
                    $essential[] = ($tmp & 0x8000) ? 1 : 0;
                }
                $pos += $n * 2;
            } else {
                if ($pos + $n > $len) return null;
                for ($j = 0; $j < $n; ++$j) {
                    $tmp = ExifTool::Get8u($val, $pos + $j);
                    $association[] = $tmp & 0x7f;
                    $essential[] = ($tmp & 0x80) ? 1 : 0;
                }
                $pos += $n;
            }
            $items[$id]['Association'] = $association;
            $items[$id]['Essential'] = $essential;
            if ($verbose > 1) $et->VPrint(1, "{$et->INDENT}  Item $id properties: " . implode(' ', $association) . "\n");
            // (according to ISO/IEC 23008-12, these entries must be sorted by item ID)
            if (!($id > $lastID)) $et->Warn('Item property association entries are out of order');
            $lastID = $id;
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Process item information now
    // Inputs: 0) ExifTool ref
    public static function HandleItemInfo(ExifTool $et)
    {
        $raf = $et->RAF;
        $items = &$et->ItemInfo;
        $verbose = $et->Options('Verbose');
        $buff = null;

        // extract information from EXIF/XMP metadata items
        if ($items and $raf) {
            $et->PATH[] = 'ItemInformation';
            $curPos = $raf->Tell();
            $primary = $et->PrimaryItem;
            $et->VerboseDir('Processing items from ItemInformation', count($items));
            $ids = array_keys($items);
            usort($ids, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
            foreach ($ids as $id) {
                $item = $items[$id];
                $type = ($item['ContentType'] ?? null) ?: ($item['Type'] ?? null);
                if (!$type) continue;
                if ($verbose) {
                    // add up total length of this item for the verbose output
                    $len = 0;
                    if (!empty($item['Extents'])) {
                        foreach ($item['Extents'] as $e) $len += $e[2];
                    }
                    $enc = !empty($item['ContentEncoding']) ? ", {$item['ContentEncoding']} encoded" : '';
                    $et->VPrint(0, "{$et->INDENT}Item $id) '{$type}' ($len bytes$enc)\n");
                }
                // get ExifTool name for this item
                $name = ['Exif' => 'EXIF', 'application/rdf+xml' => 'XMP', 'jpeg' => 'PreviewImage',
                         'uri ' => 'PLIST'][$type] ?? '';
                $warn = null;
                if (!empty($item['ContentEncoding'])) {
                    if ($item['ContentEncoding'] !== 'deflate') {
                        // (other possible values are 'gzip' and 'compress', but I don't have samples of these)
                        $warn = "Can't currently decode {$item['ContentEncoding']} encoded $type metadata";
                    } elseif (!function_exists('inflate_init')) {
                        $warn = "Install Compress::Zlib to decode deflated $type metadata";
                    }
                }
                if (!empty($item['ProtectionIndex'])) $warn = "Can't currently decode protected $type metadata";
                // Note: In HEIC's, these seem to indicate data in 'idat' instead of 'mdat'
                $constMeth = ($item['ConstructionMethod'] ?? null) ?: 0;
                if ($constMeth > 1) $warn = "Can't currently extract $type with construction method $constMeth";
                if ($constMeth == 1 and !$et->MediaDataInfo) $warn = "No 'idat' for $type object with construction method 1";
                if ($warn and $name) $et->Warn($warn);
                if (!empty($item['DataReferenceIndex'])) $warn = 'Not this file'; // (can only extract from "this file")
                if (!(!empty($item['Extents']) or $warn)) {
                    $warn = "No Extents for $type item";
                    if ($name) $et->Warn($warn);
                }
                if ($warn) {
                    if ($verbose > 2) $et->VPrint(0, "{$et->INDENT}    [not extracted]  ($warn)\n");
                    continue;
                }
                $base = Perl::num(($item['BaseOffset'] ?? null) ?: 0) + ($constMeth ? $et->MediaDataInfo[0] : 0);
                if ($verbose > 2) {
                    // do verbose hex dump
                    $len = 0;
                    $buff = null;
                    $val = '';
                    $maxLen = $verbose > 3 ? 2048 : 96;
                    foreach ($item['Extents'] as $extent) {
                        $n = $extent[2];
                        $more = $maxLen - $len;
                        if ($more > 0 and $n) {
                            if ($more > $n) $more = $n;
                            if ($buff !== null) $val .= $buff;
                            if (!$raf->Seek($extent[1] + $base, 0)) break;
                            if (!$raf->Read($buff, $more)) break;
                        }
                        $len += $n;
                    }
                    if ($buff !== null) {
                        if (strlen($val)) $buff = $val . $buff;
                        $et->VerboseDump($buff, DataPos: $item['Extents'][0][1] + $base);
                        $snip = $len - strlen($buff);
                        if ($snip) $et->VPrint(0, "{$et->INDENT}    [snip $snip bytes]\n");
                    }
                }
                // do hash of AVIF "av01" and HEIC image data
                if (!empty(Vars\QuickTime::$isImageData[$type]) and $et->ImageDataHash) {
                    $tot = 0;
                    foreach ($item['Extents'] as $extent) {
                        if (!$raf->Seek($extent[1] + $base, 0)) {
                            $et->Warn("Seek error in $type image data");
                            break;
                        }
                        $tot += $et->ImageDataHash($raf, $extent[2], "$type image", 1);
                    }
                    if ($tot) $et->VPrint(0, "{$et->INDENT}(ImageDataHash: $tot bytes of $type data)\n");
                }
                if (!$name) continue;
                // assemble the data for this item
                $buff = null;
                $val = '';
                foreach ($item['Extents'] as $extent) {
                    if ($buff !== null) $val .= $buff;
                    if (!$raf->Seek($extent[1] + $base, 0)) break;
                    if (!$raf->Read($buff, $extent[2])) break;
                }
                if ($buff === null) continue;
                if (strlen($val)) $buff = $val . $buff;
                if (!strlen($buff)) continue;   // ignore empty directories
                if (!empty($item['ContentEncoding'])) {
                    $v2 = $stat = null;
                    // (PHP: zlib extension instead of Compress::Zlib)
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    if ($inflate) {
                        $v2 = @inflate_add($inflate, $buff, ZLIB_SYNC_FLUSH);
                        $stat = inflate_get_status($inflate);
                    }
                    if ($inflate and $v2 !== false and $stat == ZLIB_STREAM_END) {
                        $buff = $v2;
                        $len = strlen($buff);
                        $et->VPrint(0, "{$et->INDENT}Inflated Item $id) '{$type}' ($len bytes)\n");
                        $et->VerboseDump($buff);
                    } else {
                        $warn = "Error inflating $name metadata";
                        $et->Warn($warn);
                        if ($verbose > 2) $et->VPrint(0, "{$et->INDENT}    [not extracted]  ($warn)\n");
                        continue;
                    }
                }
                $start = $subTable = $proc = null;
                $pos = $item['Extents'][0][1] + $base;
                if ($name === 'EXIF' and strlen($buff) >= 4) {
                    if (preg_match('/^(MM\0\x2a|II\x2a\0)/', $buff)) {
                        $et->Warn('Missing Exif header');
                    } elseif (preg_match('/^Exif\0\0/', $buff)) {
                        // (haven't seen this yet, but it is just a matter of time
                        //  until someone screws it up like this)
                        $et->Warn('Missing Exif header size');
                        $start = 6;
                    } else {
                        $n = Perl::unpack('N', $buff)[0];
                        $start = 4 + $n; // skip "Exif\0\0" header if it exists
                        if ($start > strlen($buff)) {
                            $et->Warn('Invalid EXIF header');
                            continue;
                        }
                        if ($et->HTML_DUMP) {
                            $et->HDump($pos, 4, 'Exif header length', "Value: $n");
                            if ($n) $et->HDump($pos + 4, $start - 4, 'Exif header');
                        }
                    }
                    $subTable = ExifTool::GetTagTable('Image::ExifTool::Exif::Main');
                    $proc = 'Image\ExifTool\ExifTool::ProcessTIFF';
                } elseif ($name === 'PreviewImage') {
                    // take a quick stab at determining the size of the image
                    // (based on JPEG previews found in Fuji X-H2S HIF images)
                    $type = 'PreviewImage';
                    if (preg_match('/^.{556}\xff\xc0\0\x11.(.{4})/s', $buff, $m)) {
                        [$h, $w] = Perl::unpack('n2', $m[1]);
                        // (not sure if $h is ever the long dimension, but test it just in case)
                        if ($w == 160 or $h == 160) {
                            $type = 'ThumbnailImage';
                        } elseif ($w == 1920 or $h == 1920) {
                            $type = 'OtherImage'; // (large preview)
                        } // (PreviewImage is 640x480)
                    }
                    $et->FoundTag($type, $buff);
                    continue;
                } elseif ($name === 'PLIST') {
                    // extract PLIST information from 'uri ' if available
                    if (!preg_match('/^bplist00/', $buff)) continue;
                    $subTable = ExifTool::GetTagTable('Image::ExifTool::PLIST::Main');
                } else {
                    $subTable = ExifTool::GetTagTable('Image::ExifTool::XMP::Main');
                }
                if (!$start) $start = 0;
                $dirInfo = [
                    'DataPt'   => $buff,
                    'DataLen'  => strlen($buff),
                    'DirStart' => $start,
                    'DirLen'   => strlen($buff) - $start,
                    'DataPos'  => $pos,
                    'Base'     => $pos + $start, // (needed for HtmlDump and IsOffset tags in binary data)
                ];
                // handle processing of metadata for sub-documents
                if ($primary !== null and isset($item['RefersTo']) and empty($item['RefersTo'][$primary])) {
                    // set document number if this doesn't refer to the primary document
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    // associate this document number with the lowest item index
                    $refs = array_keys($item['RefersTo']);
                    usort($refs, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
                    $lowest = $refs[0];
                    $items[$lowest]['DocNum'] = $et->DOC_NUM;
                }
                $et->ProcessDirectory($dirInfo, $subTable, $proc);
                $et->DOC_NUM = null;
            }
            if (!$raf->Seek($curPos, 0)) $et->Warn('Seek error');  // seek back to original position
            array_pop($et->PATH);
        }
        unset($items);
        // process the item properties now that we should know their associations and document numbers
        if ($et->ItemPropertyContainer) {
            [$dirInfo, $subTable, $proc] = $et->ItemPropertyContainer;
            $et->IsItemProperty = 1;    // set item property flag
            $et->ProcessDirectory($dirInfo, $subTable, $proc);
            $et->ItemPropertyContainer = null;
            $et->IsItemProperty = null;
            $et->DOC_NUM = null;
        }
        $et->ItemInfo = null;
        $et->MediaDataInfo = null;
    }

    //------------------------------------------------------------------------------
    // Warn if ExtractEmbedded option isn't used
    // Inputs: 0) ExifTool ref
    public static function EEWarn(ExifTool $et)
    {
        $et->Warn('The ExtractEmbedded option may find more tags in the media data', 3);
    }

    //------------------------------------------------------------------------------
    // Get quicktime format from flags word
    // Inputs: 0) quicktime atom flags, 1) data length
    // Returns: ExifTool format string
    public static function QuickTimeFormat($flags, $len)
    {
        $format = null;
        if ($flags == 0x15 or $flags == 0x16) {
            $format = [1 => 'int8', 2 => 'int16', 4 => 'int32', 8 => 'int64'][$len] ?? null;
            if ($format) $format .= $flags == 0x15 ? 's' : 'u';
        } elseif ($flags == 0x17) {
            $format = 'float';
        } elseif ($flags == 0x18) {
            $format = 'double';
        } elseif ($flags == 0x00) {
            $format = [1 => 'int8u', 2 => 'int16u'][$len] ?? null;
        }
        return $format;
    }

    //------------------------------------------------------------------------------
    // Process MPEG-4 MTDT atom (ref 11)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessMetaData(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $dirLen = strlen($dataPt);
        $verbose = $et->Options('Verbose');
        if (!($dirLen >= 2)) return 0;
        $count = ExifTool::Get16u($dataPt, 0);
        if ($verbose) $et->VerboseDir('MetaData', $count);
        $pos = 2;
        for ($i = 0; $i < $count; ++$i) {
            if ($pos + 10 > $dirLen) break;
            $size = ExifTool::Get16u($dataPt, $pos);
            if ($size < 10 or $size + $pos > $dirLen) break;
            $tag  = ExifTool::Get32u($dataPt, $pos + 2);
            $lang = ExifTool::Get16u($dataPt, $pos + 6);
            $enc  = ExifTool::Get16u($dataPt, $pos + 8);
            $val  = substr($dataPt, $pos + 10, $size - 10);
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if ($tagInfo) {
                // convert language code to ASCII (ignore read-only bit)
                $lang = self::UnpackLang($lang);
                // handle alternate languages
                if ($lang) {
                    $langInfo = self::GetLangInfoQT($et, $tagInfo, $lang);
                    if ($langInfo) $tagInfo = $langInfo;
                }
                if ($verbose) {
                    $et->VerboseInfo($tag, $tagInfo,
                        Value:  $val,
                        DataPt: $dataPt,
                        Start:  $pos + 10,
                        Size:   $size - 10,
                    );
                }
                // convert from UTF-16 BE if necessary
                if ($enc == 1) $val = $et->Decode($val, 'UTF16');
                if ($enc == 0 and $tagInfo->Unknown) {
                    // binary data
                    $et->FoundTag($tagInfo, new ScalarRef($val));
                } else {
                    $et->FoundTag($tagInfo, $val);
                }
            }
            $pos += $size;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process sample description table
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    // (ref https://developer.apple.com/library/content/documentation/QuickTime/QTFF/QTFFChap2/qtff2.html#//apple_ref/doc/uid/TP40000939-CH204-25691)
    public static function ProcessSampleDesc(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: (strlen($dataPt) - $pos);
        if ($pos + 8 > $dirLen) return 0;

        $num = ExifTool::Get32u($dataPt, 4);    // get number of sample entries in table
        $pos += 8;
        $err = null;
        for ($i = 0; $i < $num; ++$i) {         // loop through sample entries
            if ($pos + 8 > $dirLen) {
                $err = 1;
                break;
            }
            $size = ExifTool::Get32u($dataPt, $pos);
            if ($pos + $size > $dirLen) {
                $err = 1;
                break;
            }
            $dirInfo['DirStart'] = $pos;
            $dirInfo['DirLen'] = $size;
            self::ProcessHybrid($et, $dirInfo, $tagTablePtr);
            $pos += $size;
        }
        if ($err and $et->HandlerType) {
            $grp = $et->SET_GROUP1 ?: (($dirInfo['Parent'] ?? null) ?: 'unknown');
            $et->Warn("Truncated {$et->HandlerType} sample table for $grp");
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process hybrid binary data + QuickTime container (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessHybrid(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        // brute-force search for child atoms after first 8 bytes of binary data
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = ($dirInfo['DirLen'] ?? null) ?: strlen($dataPt) - $dirStart;
        $end = $dirStart + $dirLen;
        $pos = $dirStart + 8;   // skip length/version
        $try = $pos;
        $childPos = null;

        while ($pos <= $end - 8) {
            $tag = (string) substr($dataPt, $try + 4, 4);
            // look only for well-behaved tag ID's
            if (preg_match('/[^\w ]/', $tag)) {
                $try = ++$pos;
                continue;
            }
            $size = Perl::num(ExifTool::Get32u($dataPt, $try));
            if ($size + $try == $end) {
                // the atom ends exactly at the end of the parent -- this must be it
                $childPos = $pos;
                $dirInfo['DirLen'] = $pos;  // the binary data ends at the first child atom
                break;
            }
            if ($size < 8 or $size + $try > $end - 8) {
                $try = ++$pos;  // fail.  try next position
            } else {
                $try += $size;  // could be another atom following this
            }
        }
        // process binary data
        $dirInfo['MixedTags'] = 1; // ignore non-integer tag ID's
        $et->ProcessBinaryData($dirInfo, $tagTablePtr);
        // process child atoms if found
        if ($childPos) {
            $dirInfo['DirStart'] = $childPos;
            $dirInfo['DirLen'] = $end - $childPos;
            self::ProcessMOV($et, $dirInfo, $tagTablePtr);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process iTunes 'righ' atom (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessRights(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $dataPos = $dirInfo['Base'] ?? null;
        $dirLen = strlen($dataPt);
        $unknown = $et->OPTIONS['Unknown'] ?: $et->OPTIONS['Verbose'];
        $et->VerboseDir('righ', $dirLen / 8);
        for ($pos = 0; $pos + 8 <= $dirLen; $pos += 8) {
            $tag = substr($dataPt, $pos, 4);
            if ($tag === "\0\0\0\0") break;
            $val = substr($dataPt, $pos + 4, 4);
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
            if (!$tagInfo) {
                if (!$unknown) continue;
                $name = self::PrintableTagID($tag);
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, [
                    'Name' => "Unknown_$name",
                    'Description' => "Unknown $name",
                    'Unknown' => 1,
                ]);
            }
            if (!$tagInfo->Format) $val = '0x' . bin2hex($val);
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt:  $dataPt,
                DataPos: $dataPos,
                Start:   $pos + 4,
                Size:    4,
            );
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Nextbase 'infi' atom (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNextbase(ExifTool $et, array &$dirInfo, TagTable $tagTbl)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $et->VerboseDir('Nextbase', null, strlen($dataPt));
        preg_match_all('/(.*?): +(.*)\x0d/', $dataPt, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            [$id, $val] = [$m[1], $m[2]];
            if (!isset($tagTbl[$id])) ExifTool::AddTagToTable($tagTbl, $id, ['Name' => ExifTool::MakeTagName($id)]);
            $et->HandleTag($tagTbl, $id, $val, Size: strlen($val));
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process iTunes Encoding Params (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessEncodingParams(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $dirLen = strlen($dataPt);
        $et->VerboseDir('Encoding Params', $dirLen / 8);
        for ($pos = 0; $pos + 8 <= $dirLen; $pos += 8) {
            [$tag, $val] = Perl::unpack("x{$pos}a4N", $dataPt);
            $et->HandleTag($tagTablePtr, $tag, $val);
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read Meta Keys and add tags to ItemList table ('mdta' handler) (ref PH)
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessKeys(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = Perl::str($dirInfo['DataPt'] ?? '');
        $dirLen = strlen($dataPt);
        $out = null;
        if ($et->Options('Verbose')) {
            $et->VerboseDir('Keys');
            $out = $et->Options('TextOut');
        }
        $pos = 8;
        $index = 1;
        ++$et->KeysCount;   // increment key count for this directory
        $itemList = ExifTool::GetTagTable('Image::ExifTool::QuickTime::ItemList');
        $userData = ExifTool::GetTagTable('Image::ExifTool::QuickTime::UserData');
        while ($pos < $dirLen - 4) {
            $len = Perl::unpack("x{$pos}N", $dataPt)[0];
            if ($len < 8 or $pos + $len > $dirLen) break;
            unset($tagTablePtr[$index]);
            $ns  = substr($dataPt, $pos + 4, 4);
            $tag = substr($dataPt, $pos + 8, $len - 8);
            $tag = preg_replace('/\0.*/s', '', $tag, 1); // truncate at null
            $full = $tag;
            if ($ns === 'mdta') $tag = preg_replace('/^com\.(apple\.quicktime\.)?/', '', $tag, 1); // remove apple quicktime domain
            if (!$tag) $tag = "Tag_$ns";
            $short = $tag;
            $tagInfo = null;
            for (;;) {
                if ($tagInfo = $et->GetTagInfo($tagTablePtr, $tag)) break;
                // also try ItemList and UserData tables
                if ($tagInfo = $et->GetTagInfo($itemList, $tag)) break;
                if ($tagInfo = $et->GetTagInfo($userData, $tag)) break;
                // (I have some samples where the tag is a reversed ItemList or UserData tag ID)
                if (preg_match('/^\w{3}\xa9$/', $tag)) {
                    $tag = Perl::pack('N', Perl::unpack('V', $tag)[0]);
                    if ($tagInfo = $et->GetTagInfo($itemList, $tag)) break;
                    $tagInfo = $et->GetTagInfo($userData, $tag);
                    break;
                }
                if ($tag === $full) {
                    $tag = $short;
                    break;
                }
                $tag = $full;
            }
            $newInfo = $msg = null;
            if ($tagInfo) {
                // copy tag information into new Keys tag
                $newInfo = [
                    'Name'      => $tagInfo->Name,
                    'Format'    => $tagInfo->Format,
                    'ValueConv' => $tagInfo->ValueConv,
                    'ValueConvInv' => $tagInfo->ValueConvInv,
                    'PrintConv' => $tagInfo->PrintConv,
                    'PrintConvInv' => $tagInfo->PrintConvInv,
                    'Writable'  => $tagInfo->Writable !== null ? $tagInfo->Writable : 1,
                    'SubDirectory' => $tagInfo->SubDirectory,
                ];
                $groups = $tagInfo->Groups;
                $newInfo['Groups'] = $groups ?: [];
                foreach ([0, 1, 2] as $g) {
                    if (empty($newInfo['Groups'][$g])) $newInfo['Groups'][$g] = $tagTablePtr['GROUPS'][$g] ?? null;
                }
                // set Keys group.  This is necessary for logic when reading the associated ItemList entry,
                // but note that the group name will be overridden by TAG_EXTRA G1 for tags in a track
                $newInfo['Groups'][1] = 'Keys';
            } elseif (preg_match('/^[-\w. ]+$/', $tag) or preg_match('/\w{4}/', $tag)) {
                // create info for tags with reasonable id's
                $name = ucfirst($tag);
                $name = Perl::tr($name, '-0-9a-zA-Z_. ', '', 'dc')[0];
                $name = preg_replace_callback('/[. ]+(.?)/', static fn ($m) => strtoupper($m[1]), $name);
                $name = preg_replace_callback('/_([a-z])/', static fn ($m) => '_' . strtoupper($m[1]), $name);
                $name = preg_replace('/([a-z])_([A-Z])/', '$1$2', $name);
                if (strlen($name) < 2) $name = "Tag_$name";
                $newInfo = ['Name' => $name, 'Groups' => [1 => 'Keys']];
                $msg = ' (Unknown)';
                $et->VPrint(0, $et->INDENT, "[adding Keys:$tag]\n");
            }
            // substitute this tag in the ItemList table with the given index
            $id = $et->KeysCount . '.' . $index;
            if ($itemList[$id] instanceof TagInfo) {
                // delete other languages too if they exist
                $oldInfo = $itemList[$id];
                if ($oldInfo->OtherLang) {
                    foreach ($oldInfo->OtherLang as $other) unset($itemList[$other]);
                }
                unset($itemList[$id]);
            }
            if ($newInfo) {
                $newInfo['KeysID'] = $tag;  // save original ID for use in family 7 group name
                ExifTool::AddTagToTable($itemList, $id, $newInfo);
                if (!$msg) $msg = '';
                if ($out) fwrite($out, "{$et->INDENT}Added ItemList Tag $id = ($ns) $full$msg\n");
            }
            $pos += $len;
            ++$index;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process keys in MetaSampleDesc directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessMetaKeys(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        // save this information to decode timed metadata samples when ExtractEmbedded is used
        if ($et->OPTIONS['ExtractEmbedded']) self::SaveMetaKeys($et, $dirInfo, $tagTablePtr);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Identify trailers at specified offset from end of file
    // Inputs: 0) RAF reference, 1) Offset from end of file
    // Returns: Array ref to first trailer in linked list: 0) name of trailer,
    //          1) absolute offset to start of this trailer, 2) trailer length,
    //          3) ref to next trailer. Or undef if no trailer found, or error string on error
    // - file position is returned to its original location
    public static function IdentifyTrailers(RandomAccess $raf)
    {
        $trailer = $nextTrail = $buff = $type = $len = null;
        $pos = $raf->Tell();
        $offset = 0; // positive offset back from end of file
        while ($raf->Seek(-40 - $offset, 2) and $raf->Read($buff, 40) == 40) {
            if (substr($buff, 8) === '8db42d694ccc418790edff439fe026bf') {
                [$type, $len] = ['Insta360', Perl::unpack('V', $buff)[0]];
            } elseif (preg_match('/\&\&\&\&(.{4})$/', $buff)) {
                [$type, $len] = ['LigoGPS', ExifTool::Get32u($buff, 36)];
            } elseif (preg_match('/~\0\x04\0zmie~\0\0\x06.{4}([\x10\x18])(\x04)$/s', $buff, $m) or
                      preg_match('/~\0\x04\0zmie~\0\0\x0a.{8}([\x10\x18])(\x08)$/s', $buff, $m))
            {
                $oldOrder = ExifTool::GetByteOrder();
                ExifTool::SetByteOrder($m[1] === "\x10" ? 'MM' : 'II');
                $type = 'MIE';
                $len = ($m[2] === "\x04") ? ExifTool::Get32u($buff, 34) : ExifTool::Get64u($buff, 30);
                ExifTool::SetByteOrder($oldOrder);
            } else {
                break;
            }
            // 0) trailer type, 1) trailer start, 2) trailer length, 3) pointer to next trailer
            $trailer = [$type, $raf->Tell() - $len, $len, $nextTrail];
            $nextTrail = $trailer;
            $offset += $len;
        }
        if (!$raf->Seek($pos, 0)) return 'Seek error';
        return $trailer;
    }

    //------------------------------------------------------------------------------
    // Process a QuickTime atom
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) optional tag table ref
    // Returns: 1 on success
    public static function ProcessMOV(ExifTool $et, array &$dirInfo, ?TagTable $tagTablePtr = null)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $verbose = $et->Options('Verbose');
        $validate = $et->OPTIONS['Validate'];
        $dirBase = ($dirInfo['Base'] ?? null) ?: 0;
        $dataPos = $dirBase;
        $dirID = ($dirInfo['DirID'] ?? null) ?: '';
        $charsetQuickTime = $et->Options('CharsetQuickTime');
        $buff = $tag = $size = $track = $isUserData = $doDefaultLang = $index = null;
        $triplet = [];
        $dirEnd = $unkOpt = $atomCount = $warnStr = $trailer = null;
        $saveOptions = [];

        $topLevel = !$et->InQuickTime;
        $et->InQuickTime = 1;
        if ($topLevel) $et->HandlerType = $et->MetaFormat = $et->MediaType = '';

        if ($et->KeysCount === null) {
            $et->KeysCount = 0;     // initialize ItemList key directory count
            $doDefaultLang = 1;     // flag to generate default language tags
        }
        // more convenient to package data as a RandomAccess file
        if (!$raf) {
            $raf = new RandomAccess(Perl::str($dataPt));
            if (!empty($dirInfo['DirLen'])) $dirEnd = $dataPos + $dirInfo['DirLen'] + (($dirInfo['DirStart'] ?? null) ?: 0);
        }
        // skip leading bytes if necessary
        if (!empty($dirInfo['DirStart'])) {
            if (!$raf->Seek($dirInfo['DirStart'], 1)) return 0;
            $dataPos += $dirInfo['DirStart'];
        }
        // read size/tag name atom header
        if ($raf->Read($buff, 8) != 8) return 0;
        $dataPos += 8;
        if ($tagTablePtr) {
            $isUserData = ($tagTablePtr === ExifTool::PackageTable('Image::ExifTool::QuickTime::UserData'));
        } else {
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Main');
        }
        [$size, $tag] = Perl::unpack('Na4', $buff);
        $fast = $et->OPTIONS['FastScan'] ?: 0;
        // check for Insta360, LIGOGPSINFO or MIE trailer
        if ($topLevel and !$fast) {
            $trailer = self::IdentifyTrailers($raf);
            if ($trailer and !is_array($trailer)) {
                $et->Warn($trailer);
                return 0;
            }
        }
        if ($dataPt !== null) {
            if ($verbose) $et->VerboseDir($dirInfo['DirName'] ?? null);
        } else {
            // check on file type if called with a RAF
            if (!isset($tagTablePtr[$tag])) return 0;
            $fileType = null;
            if ($tag === 'ftyp' and $size >= 12) {
                // read ftyp atom to see what type of file this is
                if ($raf->Read($buff, $size - 8) == $size - 8) {
                    if (!$raf->Seek(-($size - 8), 1)) {
                        $et->Warn('Seek error');
                        return 0;
                    }
                    $type = substr($buff, 0, 4);
                    $et->save_ftyp = $type;
                    // see if we know the extension for this file type
                    $ftyp = Vars\QuickTime::$ftypLookup[$type] ?? null;
                    if ($ftyp and preg_match('/\(\.(\w+)/', $ftyp, $m)) {
                        $fileType = $m[1];
                    // check compatible brands
                    } elseif (preg_match('/^.{8}(.{4})+(mp41|mp42|avc1)/s', $buff)) {
                        $fileType = 'MP4';
                    } elseif (preg_match('/^.{8}(.{4})+(f4v )/s', $buff)) {
                        $fileType = 'F4V';
                    } elseif (preg_match('/^.{8}(.{4})+(qt  )/s', $buff)) {
                        $fileType = 'MOV';
                    }
                }
                if (!$fileType) $fileType = 'MP4'; // default to MP4
                // set file type from extension if appropriate
                $ext = $et->FILE_EXT;
                if ($ext and !empty(Vars\QuickTime::$useExt[$ext]) and $fileType === Vars\QuickTime::$useExt[$ext]) $fileType = $ext;
                $et->SetFileType($fileType, (Vars\QuickTime::$mimeLookup[$fileType] ?? null) ?: 'video/mp4');
                // temporarily set ExtractEmbedded option for CRX files
                if ($fileType === 'CRX') $saveOptions['ExtractEmbedded'] = $et->Options('ExtractEmbedded', 1);
            } else {
                $et->SetFileType();     // MOV
            }
            ExifTool::SetByteOrder('MM');
            // have XMP take priority except for HEIC
            if (!($fileType and $fileType === 'HEIC')) $et->PRIORITY_DIR = 'XMP';
        }
        if ($fast) $raf->NoBuffer = true;   // disable buffering in FastScan mode

        $ee = $et->OPTIONS['ExtractEmbedded'] ?: 0;
        $hash = $et->ImageDataHash;
        if ($ee or $hash) {
            $unkOpt = $et->OPTIONS['Unknown'];
            // (require 'Image/ExifTool/QuickTimeStream.pl': its subs are at the end of this class)
        }
        if ($tagTablePtr['VARS']) {
            $index = $tagTablePtr['VARS']['START_INDEX'] ?? null;
            $atomCount = $tagTablePtr['VARS']['ATOM_COUNT'] ?? null;
        }
        $lastTag = '';
        $lastPos = 0;
        for (;;) {
            $eeTag = $ignore = null;
            if ($atomCount !== null and --$atomCount < 0) break;
            if ($size < 8) {
                if ($size == 0) {
                    if ($dataPt !== null) {
                        // a zero size isn't legal for contained atoms, but Canon uses it to
                        // terminate the CNTH atom (eg. CanonEOS100D.mov), so tolerate it here
                        $pos = $raf->Tell() - 4;
                        if (!$raf->Seek(0, 2)) {
                            $et->Warn('Seek error');
                            return 0;
                        }
                        $str = ($dirInfo['DirName'] ?? '') . ' with ' . ($raf->Tell() - $pos) . ' bytes';
                        $et->VPrint(0, "{$et->INDENT}[Terminator found in $str remaining]");
                    } else {
                        $t = self::PrintableTagID($tag, 2);
                        $et->VPrint(0, "{$et->INDENT}Tag '{$t}' extends to end of file");
                        if (isset($tagTablePtr["$tag-size"])) {
                            $pos = $raf->Tell();
                            if (!$fast) {
                                if (!$raf->Seek(0, 2)) {
                                    $et->Warn('Seek error');
                                    return 0;
                                }
                                $et->HandleTag($tagTablePtr, "$tag-size", $raf->Tell() - $pos);
                            }
                            if (isset($tagTablePtr["$tag-offset"])) $et->HandleTag($tagTablePtr, "$tag-offset", $pos);
                        }
                    }
                    break;
                }
                if ($size != 1) {
                    $warnStr = 'Invalid atom size';
                    break;
                }
                // read extended atom size
                if ($raf->Read($buff, 8) != 8) {
                    $warnStr = 'Truncated atom header';
                    break;
                }
                $dataPos += 8;
                [$hi, $lo] = Perl::unpack('NN', $buff);
                if ($hi or $lo > 0x7fffffff) {
                    if ($hi > 0x7fffffff) {
                        $warnStr = 'Invalid atom size';
                        break;
                    } elseif (!$et->Options('LargeFileSupport')) {
                        $warnStr = 'End of processing at large atom (LargeFileSupport not enabled)';
                        break;
                    } elseif (Perl::str($et->Options('LargeFileSupport')) === '2') {
                        $et->Warn('Processing large atom (LargeFileSupport is 2)');
                    }
                }
                $size = $hi * 4294967296 + $lo - 16;
                if ($size < 0) {
                    $warnStr = 'Invalid extended size';
                    break;
                }
            } else {
                $size -= 8;
            }
            if ($validate) {
                if ($tag === 'wide' and $size) $et->Warn("Invalid 'wide' atom size");
                if (!$et->ValidatePath) $et->ValidatePath = [];
                $path = implode('-', [...$et->PATH, $tag]);
                if ($et->SET_GROUP1) $path = preg_replace_callback('/-Track-/', static fn () => "-{$et->SET_GROUP1}-", $path, 1);
                if (!empty($et->ValidatePath[$path]) and empty(Vars\QuickTime::$dupTagOK[$tag]) and empty(Vars\QuickTime::$dupDirOK[$dirID])) {
                    $i = ExifTool::Get32u($tag, 0);
                    $str = $i < 255 ? "index $i" : "tag '" . self::PrintableTagID($tag, 2) . "'";
                    $path = preg_replace('/-[^-+]$/', '', $path, 1);    // remove tag name
                    $et->Warn("Duplicate $str at $path");
                    if ($path === 'MOV-moov') $et->ValidatePath = []; // avoid warnings for all contained dups
                }
                $et->ValidatePath[$path] = 1;
            }
            if ($isUserData and $et->SET_GROUP1) {
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if (!($tagInfo and $tagInfo->SubDirectory)) {
                    // add track name to UserData tags inside tracks
                    $tag = $et->SET_GROUP1 . $tag;
                    if (!isset($tagTablePtr[$tag]) and $tagInfo) {
                        $newInfo = $tagInfo->toArray();
                        foreach (['Name', 'Description'] as $k) {
                            if (!$tagInfo->$k) continue;
                            $newInfo[$k] = $et->SET_GROUP1 . $tagInfo->$k;
                            $newInfo[$k] = preg_replace('/^(Track\d+)Track/', '$1', $newInfo[$k], 1); // remove duplicate "Track" in name
                        }
                        ExifTool::AddTagToTable($tagTablePtr, $tag, $newInfo);
                    }
                }
            }
            // set flag to store additional information for ExtractEmbedded option
            $handlerType = Perl::str($et->HandlerType);
            $eeBox = Vars\QuickTime::$eeBox;
            if (!empty($eeBox[$handlerType]) and !empty($eeBox[$handlerType][$tag])) {
                if ($ee or $hash) {
                    // (there is another 'gps ' box with a track log that doesn't contain offsets)
                    if ($tag !== 'gps ' or $eeBox[$handlerType][$tag] === $dirID) {
                        $eeTag = 1;
                        $et->OPTIONS['Unknown'] = 1;    // temporarily enable "Unknown" option
                    }
                }
            } elseif ($ee > 1 and !empty(Vars\QuickTime::$eeBox2[$handlerType][$tag])) {
                $eeTag = 1;
                $et->OPTIONS['Unknown'] = 1;
            } elseif ($hash and !empty(Vars\QuickTime::$hashBox[$handlerType][$tag])) {
                $eeTag = 1;
                $et->OPTIONS['Unknown'] = 1;
            }
            $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);

            if ($eeTag) $et->OPTIONS['Unknown'] = $unkOpt;  // restore Unknown option

            // allow numerical tag ID's
            if (!$tagInfo) {
                $id = $et->KeysCount . '.' . Perl::str(Perl::unpack('N', $tag)[0] ?? null);
                if (isset($tagTablePtr[$id])) {
                    $tagInfo = $et->GetTagInfo($tagTablePtr, $id);
                    $tag = $id;
                }
            }
            // generate tagInfo if Unknown option set
            if ($tagInfo === null and ($et->OPTIONS['Unknown'] or
                $verbose or preg_match('/^\xa9/', $tag)))
            {
                $name = self::PrintableTagID($tag, 1);
                if (preg_match('/^xa9(.*)/', $name, $m)) {
                    $tagInfo = [
                        'Name' => "UserData_$m[1]",
                        'Description' => "User Data $m[1]",
                    ];
                } else {
                    $tagInfo = [
                        'Name' => "Unknown_$name",
                        'Description' => "Unknown $name",
                        ...self::UnknownInfo(),
                    ];
                }
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
            }
            // save required tag sizes
            if (isset($tagTablePtr["$tag-size"])) {
                $et->HandleTag($tagTablePtr, "$tag-size", $size);
                if (isset($tagTablePtr["$tag-offset"])) $et->HandleTag($tagTablePtr, "$tag-offset", $raf->Tell() + $dirBase);
            }
            // save position/size of 'idat'
            if ($tag === 'idat') $et->MediaDataInfo = [$raf->Tell() + $dirBase, $size];
            // stop processing at mdat/idat if -fast2 is used
            if ($fast > 1 and ($tag === 'mdat' or ($tag === 'idat' and $et->FileType !== 'HEIC'))) break;
            // load values only if associated with a tag (or verbose) and not too big
            if ($size > 0x2000000) {    // start to get worried above 32 MiB
                // check for RIFF trailer (written by Auto-Vox dashcam)
                if (preg_match('/^(gpsa|gps0|gsen|gsea)...\0/s', $buff)) { // (yet seen only gpsa as first record)
                    $et->VPrint(0, Perl::sprintf('Found RIFF trailer at offset 0x%x', $lastPos));
                    if ($ee) {
                        if (!$raf->Seek(-8, 1)) break;  // seek back to start of trailer
                        $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                        $di = ['RAF' => $raf];
                        self::ProcessRIFFTrailer($et, $di, $tbl);
                    } else {
                        self::EEWarn($et);
                    }
                    break;
                } elseif ($buff === 'CCCCCCCC') {
                    $et->VPrint(0, Perl::sprintf('Found Kenwood trailer at offset 0x%x', $lastPos));
                    $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                    $di = ['RAF' => $raf];
                    self::ProcessKenwoodTrailer($et, $di, $tbl);
                    break;
                }
                if (!$tagInfo or $tagInfo->Unknown) {
                    $ignore = 1;
                } elseif ($size > 0x8000000) {
                    $t = self::PrintableTagID($tag, 2);
                    if ($et->Warn("Skipping '{$t}' atom > 128 MiB", $eeTag ? 2 : 1)) $ignore = 1;
                } elseif (!$eeTag) {
                    $t = self::PrintableTagID($tag, 2);
                    if ($et->Warn("Skipping '{$t}' atom > 32 MiB", 2)) $ignore = 1;
                }
            }
            if ($tagInfo !== null and !$ignore and !($tagInfo and $tagInfo->DontRead)) {
                // set document number for this item property if necessary
                if ($et->IsItemProperty) {
                    $items = &$et->ItemInfo;
                    $docNum = $lowest = null;
                    $primary = $et->PrimaryItem ?: 0;
                    if (isset($items[$primary])) {
                        $pitem = &$items[$primary];
                    } else {
                        $pitem = [];
                    }
                    if (!isset($pitem['RefersTo'])) $pitem['RefersTo'] = [];
                    $ids = array_keys($items ?? []);
                    usort($ids, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
                    foreach (array_reverse($ids) as $id) {     // (ItemID loop)
                        if (!isset($items[$id]['Association'])) continue;
                        $item = $items[$id];
                        foreach ($item['Association'] as $prop) {
                            if (Perl::num($prop) != Perl::num($index)) continue;
                            $dont = Vars\QuickTime::$dontInherit[$tag] ?? 0;
                            if (Perl::num($id) == Perl::num($primary) or (!$dont and
                                (isset($item['RefersTo']) and !empty($item['RefersTo'][$primary]))) or
                                // special case to assume this property belongs to the primary
                                // image if it belongs to an item referred to by the primary
                                ($dont != 1 and !empty($pitem['RefersTo'][$id])))
                            {
                                // this is associated with the primary item or an item describing
                                // the primary item, so consider this part of the main document
                                $docNum = null;
                                $lowest = null;
                                break 2;    // (last ItemID)
                            } elseif (!empty($item['DocNum'])) {
                                // this property is already associated with an item that has
                                // an ExifTool document number, so use the lowest associated DocNum
                                if ($docNum === null or $docNum > $item['DocNum']) $docNum = $item['DocNum'];
                            } else {
                                // keep track of the lowest associated item ID
                                $lowest = $id;
                            }
                        }
                    }
                    if ($docNum === null and $lowest !== null) {
                        // this is the first time we've seen metadata from this item,
                        // so use a new document number
                        $docNum = ++$et->DOC_COUNT;
                        $items[$lowest]['DocNum'] = $docNum;
                    }
                    $et->DOC_NUM = $docNum;
                    unset($pitem, $items);
                }
                $val = null;
                $missing = $size - $raf->Read($val, $size);
                if ($missing) {
                    $t = self::PrintableTagID($tag, 2);
                    $warnStr = "Truncated '{$t}' data (missing $missing bytes)";
                    break;
                }
                // use value to get tag info if necessary
                if (!$tagInfo) $tagInfo = $et->GetTagInfo($tagTablePtr, $tag, $val);
                $hasData = (!empty($dirInfo['HasData']) and preg_match('/^....data\0/s', $val));
                if ($verbose and $val !== null and !$hasData) {
                    $tval = null;
                    if ($tagInfo and $tagInfo->Format) {
                        $tval = ExifTool::ReadValue($val, 0, $tagInfo->Format, $tagInfo->Count, strlen($val));
                    }
                    $et->VerboseInfo($tag, $tagInfo,
                        Value:   $tval,
                        DataPt:  $val,
                        DataPos: $dataPos,
                        Size:    $size,
                        Format:  $tagInfo ? $tagInfo->Format : null,
                        Index:   $index,
                    );
                    // print iref item ID numbers
                    if ($dirID === 'iref') {
                        $id = $count = null;
                        $to = [];
                        if ($et->ItemRefVersion) {
                            if (strlen($val) >= 10) $to = Perl::unpack('NnN*', $val);
                        } else {
                            if (strlen($val) >= 6) $to = Perl::unpack('nnn*', $val);
                        }
                        if ($to) {
                            $id = array_shift($to);
                            $count = array_shift($to);
                        }
                        if ($id === null) {
                            $id = '<err>';
                            $count = 0;
                        }
                        if ($count != count($to)) $id .= " (wrong count: $count)";
                        // convert sequential numbers to a range
                        for ($i = 1; $i < count($to); ) {
                            if (!(preg_match('/(\d+)$/', Perl::str($to[$i - 1]), $m) and $to[$i] == $m[1] + 1)) {
                                ++$i;
                                continue;
                            }
                            $to[$i - 1] = preg_replace('/(-.*)?$/', '-' . $to[$i], Perl::str($to[$i - 1]), 1);
                            array_splice($to, $i, 1);
                        }
                        $et->VPrint(1, "{$et->INDENT}  Item $id refers to: ", implode(',', $to), "\n");
                    }
                }
                // extract metadata from stream if ExtractEmbedded option is enabled
                if ($eeTag) {
                    self::ParseTag($et, $tag, $val);
                    // forget this tag if we generated it only for ExtractEmbedded
                    if ($tagInfo and $tagInfo->Unknown and !$unkOpt) $tagInfo = null;
                }

                // handle iTunesInfo mean/name/data triplets
                if ($tagInfo and $tagInfo->Triplet) {
                    if ($tag === 'data' and !empty($triplet['mean']) and !empty($triplet['name'])) {
                        $tag = $triplet['name'];
                        // add 'mean' to name unless it is 'com.apple.iTunes'
                        if ($triplet['mean'] !== 'com.apple.iTunes') $tag = $triplet['mean'] . '/' . $tag;
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $tag, $val);
                        if (!$tagInfo) {
                            $name = $triplet['name'];
                            $desc = $name;
                            $name = Perl::tr($name, '-_a-zA-Z0-9', '', 'dc')[0];
                            $desc = strtr($desc, '_', ' ');
                            $tagInfo = [
                                'Name' => $name,
                                'Description' => $desc,
                            ];
                            $et->VPrint(0, $et->INDENT, "[adding QuickTime:$name]\n");
                            $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
                        }
                        // ignore 8-byte header
                        if (strlen($val) >= 8) $val = substr($val, 8);
                        if (!($tagInfo->Format or $tagInfo->SubDirectory)) {
                            // extract as binary if it contains any non-ASCII or control characters
                            if (preg_match('/[^\x20-\x7e]/', $val)) {
                                $val = new ScalarRef($val);
                            }
                        }
                        $tagInfo->List = 1; // (allow any of these tags to have multiple data elements)
                        if ($verbose) $et->VerboseInfo($tag, $tagInfo, Value: $val);
                    } else {
                        if (strlen($val) > 4) $triplet[$tag] = substr($val, 4);
                        $tagInfo = null;    // don't store this tag
                    }
                }
                if ($tagInfo) {
                    $found = [];
                    $subdir = $tagInfo->SubDirectory;
                    if ($subdir) {
                        $start = ($subdir['Start'] ?? null) ?: 0;
                        [$base, $dPos] = [$dataPos, 0];
                        if (!empty($subdir['Base'])) {
                            $dPos -= Perl::num(Exif::EvalCode($subdir['Base'], $start, $base));
                            $base -= $dPos;
                        }
                        $subDirInfo = [
                            'DataPt'     => $val,
                            'DataLen'    => $size,
                            'DirStart'   => $start,
                            'DirLen'     => $size - $start,
                            'DirName'    => ($subdir['DirName'] ?? null) ?: $tagInfo->Name,
                            'DirID'      => $tag,
                            'HasData'    => $subdir['HasData'] ?? null,
                            'Multi'      => $subdir['Multi'] ?? null,
                            'IgnoreProp' => $subdir['IgnoreProp'] ?? null, // (XML hack)
                            'DataPos'    => $dPos,
                            'Base'       => $base, // (needed for IsOffset tags in binary data)
                        ];
                        if ($tagInfo->BlockExtract) $subDirInfo['BlockInfo'] = $tagInfo;
                        if (!empty($subdir['ByteOrder']) and preg_match('/^Little/', $subdir['ByteOrder'])) {
                            ExifTool::SetByteOrder('II');
                        }
                        $oldGroup1 = $et->SET_GROUP1;
                        if (($subdir['TagTable'] ?? null) === 'Image::ExifTool::QuickTime::Track') {
                            if (!$track) $track = 0;
                            $et->SET_GROUP1 = 'Track' . (++$track);
                        }
                        $subTable = ExifTool::GetTagTable($subdir['TagTable'] ?? null);
                        $proc = self::SubProc($subdir['ProcessProc'] ?? null);
                        // make ProcessMOV() the default processing procedure for subdirectories
                        if (!($proc or ($subTable and $subTable['PROCESS_PROC']))) $proc = 'Image\ExifTool\QuickTime::ProcessMOV';
                        if ($size > $start) {
                            // delay processing of ipco box until after all other boxes
                            if ($tag === 'ipco' and !$et->IsItemProperty) {
                                $et->ItemPropertyContainer = [$subDirInfo, $subTable, $proc];
                                $et->VPrint(0, "{$et->INDENT}[Process ipco box later]");
                            } elseif ($fast < 2 or !$tagInfo->MakerNotes) {
                                $et->ProcessDirectory($subDirInfo, $subTable, $proc);
                            }
                        }
                        if ($tag === 'stbl') {
                            // process sample data when exiting SampleTable box if extracting embedded
                            if ($ee or $hash) self::ProcessSamples($et);
                        } elseif ($tag === 'minf') {
                            $et->HandlerType = ''; // reset handler type at end of media info box
                        }
                        $et->SET_GROUP1 = $oldGroup1;
                        ExifTool::SetByteOrder('MM');
                    } elseif ($hasData) {
                        // handle atoms containing 'data' tags
                        // (currently ignore contained atoms: 'itif', 'name', etc.)
                        $pos = 0;
                        for (;;) {
                            if ($pos + 16 > $size) break;
                            [$len, $type, $flags, $ctry, $lang] = Perl::unpack("x{$pos}Na4Nnn", $val);
                            if ($pos + $len > $size or !$len) break;
                            $value = $langInfo = $oldDir = null;
                            $format = $tagInfo->Format;
                            if ($type === 'data' and $len >= 16) {
                                $pos += 16;
                                $len -= 16;
                                $value = substr($val, $pos, $len);
                                // format flags (ref 12):
                                // 0x0=binary, 0x1=UTF-8, 0x2=UTF-16, 0x3=ShiftJIS,
                                // 0x4=UTF-8  0x5=UTF-16, 0xd=JPEG, 0xe=PNG,
                                // 0x15=signed int, 0x16=unsigned int, 0x17=float,
                                // 0x18=double, 0x1b=BMP, 0x1c='meta' atom
                                if (!empty(Vars\QuickTime::$stringEncoding[$flags])) {
                                    // handle all string formats
                                    $value = $et->Decode($value, Vars\QuickTime::$stringEncoding[$flags]);
                                    // (shouldn't be null terminated, but some software writes it anyway)
                                    if (!$tagInfo->Binary) $value = preg_replace('/\0$/', '', $value, 1);
                                } else {
                                    if (!$format) {
                                        $format = self::QuickTimeFormat($flags, $len);
                                    } elseif (preg_match('/^int\d+([us])$/', $format, $m)) {
                                        // adjust integer to available length (but not int64)
                                        $fmt = [1 => 'int8', 2 => 'int16', 4 => 'int32'][$len] ?? null;
                                        if ($fmt !== null) $format = $fmt . $m[1];
                                    }
                                    if ($format) {
                                        $value = ExifTool::ReadValue($value, 0, $format, $tagInfo->Count, $len);
                                    } elseif (!$tagInfo->ValueConv) {
                                        // make binary data a scalar reference unless a ValueConv exists
                                        $value = new ScalarRef($value);
                                    }
                                }
                            }
                            if ($ctry or $lang) {
                                $langCode = self::GetLangCode($lang, $ctry);
                                if ($langCode) {
                                    // get tagInfo for other language
                                    $langInfo = self::GetLangInfoQT($et, $tagInfo, $langCode);
                                    // save other language tag ID's so we can delete later if necessary
                                    if ($langInfo) {
                                        if (!$tagInfo->OtherLang) $tagInfo->OtherLang = [];
                                        $tagInfo->OtherLang[] = $langInfo->TagID;
                                    }
                                }
                            }
                            if (!$langInfo) $langInfo = $tagInfo;
                            if ($verbose) {
                                $qtFlag = Vars\QuickTime::$qtFlags[$flags] ?? null;
                                $str = $qtFlag ? " ($qtFlag)" : '';
                                $et->VerboseInfo($tag, $langInfo,
                                    Value:   $value instanceof ScalarRef ? $value->value : $value,
                                    DataPt:  $val,
                                    DataPos: $dataPos,
                                    Start:   $pos,
                                    Size:    $len,
                                    Format:  $format,
                                    Index:   $index,
                                    Extra:   Perl::sprintf(", Type='%s', Flags=0x%x%s, Lang=0x%.4x", $type, $flags, $str, $lang),
                                );
                            }
                            if ($value !== null) {
                                // use "Keys" in path instead of ItemList if this was defined by a Keys tag
                                // (the only reason for this is to have "Keys" in the family 5 group name)
                                // Note that the Keys group is specifically set by the ProcessKeys routine,
                                // even though this tag would be in the ItemList table
                                $isKeys = (($tagInfo->Groups[1] ?? null) === 'Keys');
                                if ($isKeys) {
                                    $last = count($et->PATH) - 1;
                                    $oldDir = $et->PATH[$last];
                                    $et->PATH[$last] = 'Keys';
                                }
                                $found[] = $et->FoundTag($langInfo, $value);
                                if ($isKeys) $et->PATH[$last] = $oldDir;
                            }
                            $pos += $len;
                        }
                    } elseif (preg_match('/^\xa9/', $tag) or $tagInfo->IText) {
                        // parse international text to extract all languages
                        $pos = 0;
                        if ($tagInfo->Format) {
                            $found[] = $et->FoundTag($tagInfo, ExifTool::ReadValue($val, 0, $tagInfo->Format, null, strlen($val)));
                            $pos = $size;
                        }
                        for (;;) {
                            $len = $lang = null;
                            if ($tagInfo->IText and $tagInfo->IText >= 6) {
                                if ($pos + $tagInfo->IText > $size) break;
                                $pos += $tagInfo->IText - 2;
                                $lang = Perl::unpack("x{$pos}n", $val)[0];
                                $pos += 2;
                                $len = $size - $pos;
                            } else {
                                if ($pos + 4 > $size) break;
                                [$len, $lang] = Perl::unpack("x{$pos}nn", $val);
                                $pos += 4;
                                // according to the QuickTime spec (ref 12), $len should include
                                // 4 bytes for length and type words, but nobody (including
                                // Apple, Pentax and Kodak) seems to add these in, so try
                                // to allow for either
                                if ($pos + $len > $size) {
                                    $len -= 4;
                                    if ($pos + $len > $size or $len < 0) break;
                                }
                            }
                            // ignore any empty entries (or null padding) after the first
                            if (!$len and $pos) continue;
                            $str = substr($val, $pos, $len);
                            $langInfo = $enc = null;
                            if (($lang < 0x400 or $lang == 0x7fff) and !str_starts_with($str, "\xfe\xff")) {
                                // this is a Macintosh language code
                                // a language code of 0 is Macintosh english, so treat as default
                                if ($lang) {
                                    if ($lang == 0x7fff) {
                                        // technically, ISO 639-2 doesn't have a 2-character
                                        // equivalent for 'und', but use 'un' anyway
                                        $lang = 'un';
                                    } else {
                                        // use Font.pm to look up language string
                                        ExifTool::RequireModule('Image::ExifTool::Font');
                                        $lang = Vars\Font::$ttLang['Macintosh'][$lang] ?? null;
                                    }
                                } else {
                                    // for the default language code of 0x0000, use UTF-8 instead
                                    // of the CharsetQuickTime setting if obviously UTF8
                                    if (ExifTool::IsUTF8($str) > 0) $enc = 'UTF8';
                                }
                                // the spec says only "Macintosh text encoding", but
                                // allow this to be configured by the user
                                if (!$enc) $enc = $charsetQuickTime;
                            } else {
                                // convert language code to ASCII (ignore read-only bit)
                                $lang = self::UnpackLang($lang);
                                // may be either UTF-8 or UTF-16BE
                                if (str_starts_with($str, "\xfe\xff")) {
                                    $str = substr($str, 2);
                                    $enc = 'UTF16';
                                } else {
                                    $enc = 'UTF8';
                                }
                            }
                            if (!$tagInfo->NoDecode) {
                                $str = $et->Decode($str, $enc);
                                $str = preg_replace('/\0+$/', '', $str, 1);   // remove any trailing nulls (eg. 3gp tags)
                            }
                            if ($tagInfo->IText and $tagInfo->IText > 6) {
                                $n = $tagInfo->IText - 6;
                                // add back extra bytes (eg. 'rtng' box)
                                $str = substr($val, $pos - $n - 2, $n) . $str;
                            }
                            if ($lang) $langInfo = self::GetLangInfoQT($et, $tagInfo, $lang);
                            $found[] = $et->FoundTag($langInfo ?: $tagInfo, $str);
                            $pos += $len;
                        }
                    } else {
                        $format = $tagInfo->Format;
                        if ($format) {
                            $val = ExifTool::ReadValue($val, 0, $format, $tagInfo->Count, strlen($val));
                        }
                        $oldBase = null;
                        if ($tagInfo->SetBase) {
                            $oldBase = $et->BASE;
                            $et->BASE = $dataPos;
                        }
                        $key = $et->FoundTag($tagInfo, $val);
                        $found[] = $key;
                        if ($oldBase !== null) $et->BASE = $oldBase;
                        // decode if necessary (NOTE: must be done after RawConv)
                        if ($key !== null and (!$format or preg_match('/^string/', $format)) and
                            !$tagInfo->Unknown and !$tagInfo->ValueConv and
                            !$tagInfo->Binary and isset($et->VALUE[$key]) and
                            !($val instanceof ScalarRef) and !is_array($val))
                        {
                            $vp = &$et->VALUE[$key];
                            if (!is_array($vp) and !is_object($vp) and strlen(Perl::str($vp)) <= 65536 and
                                preg_match('/[\x80-\xff]/', Perl::str($vp)))
                            {
                                // the encoding of this is not specified, so use CharsetQuickTime
                                // unless the string is valid UTF-8
                                $enc = ExifTool::IsUTF8($vp) > 0 ? 'UTF8' : $charsetQuickTime;
                                $vp = $et->Decode($vp, $enc);
                            }
                            unset($vp);
                        }
                    }
                    // tweak family 1 group names for Keys/ItemList/UserData tags in a track
                    if ($et->SET_GROUP1 and ($dirID === 'ilst' or $dirID === 'udta') and $found) {
                        $type = Vars\QuickTime::$trackPath[implode('-', $et->PATH)] ?? null;
                        if ($type) {
                            $grp = ((Vars\QuickTime::$avType[Perl::str($et->MediaType)] ?? null) ?: $et->SET_GROUP1) . $type;
                            foreach ($found as $f) {
                                if ($f !== null) $et->SetGroup($f, $grp);
                            }
                        }
                    }
                }
            } else {
                if ($verbose) {
                    $et->VerboseInfo($tag, $tagInfo,
                        Size:  $size,
                        Extra: Perl::sprintf(' at offset 0x%.4x', $raf->Tell()),
                    );
                }
                $seekTo = $raf->Tell() + $size;
                if ($tagInfo and $tagInfo->DontRead and $tagInfo->SubDirectory) {
                    // ignore first trailer if it is the payload of this box
                    if ($trailer and $trailer[1] == $raf->Tell()) $trailer = $trailer[3];
                    $subdir = $tagInfo->SubDirectory;
                    $subDirInfo = [
                        'RAF'     => $raf,
                        'DirName' => $tagInfo->Name,
                        'DirID'   => $tag,
                        'DirEnd'  => $seekTo,
                    ];
                    $subTable = ExifTool::GetTagTable($subdir['TagTable'] ?? null);
                    $proc = self::SubProc($subdir['ProcessProc'] ?? null);
                    // make ProcessMOV() the default processing procedure for subdirectories
                    if (!($proc or ($subTable and $subTable['PROCESS_PROC']))) $proc = 'Image\ExifTool\QuickTime::ProcessMOV';
                    $et->ProcessDirectory($subDirInfo, $subTable, $proc);
                    $raf->Seek($seekTo);
                }
                if (!($raf->Seek($seekTo - 1) and $raf->Read($buff, 1) == 1)) {
                    if (preg_match('/^<b[r>]/', Perl::pack('N', $size))) { // check for corrupted HEIC file downloaded from heic.digital
                        $warnStr = Perl::sprintf('Extraneous HTML text appended to file at offset 0x%x', $lastPos);
                    } else {
                        $t = self::PrintableTagID($tag, 2);
                        $warnStr = Perl::sprintf("Truncated '%s' data at offset 0x%x", $t, $lastPos);
                    }
                    break;
                }
            }
            if ($tag === 'trak') $et->MediaType = '';   // reset track type at end of track
            $dataPos += $size + 8;  // point to start of next atom data
            if ($dirEnd and $dataPos >= $dirEnd) break; // (note: ignores last value if 0 bytes)
            $lastPos = $raf->Tell() + $dirBase;
            if ($trailer and $lastPos >= $trailer[1]) {
                $et->Warn(Perl::sprintf('%s trailer at offset 0x%x (%d bytes)', $trailer[0], $trailer[1], $trailer[2]), 1);
                break;
            }
            if ($raf->Read($buff, 8) != 8) break;
            if (isset($tagTablePtr[$tag]) and $tag !== 'free') $lastTag = $tag; // (Insta360 sometimes puts free block before trailer)
            [$size, $tag] = Perl::unpack('Na4', $buff);
            if ($index !== null) ++$index;
        }
        if ($warnStr) {
            // assume this is an unknown trailer if it comes immediately after
            // mdat or moov and has a tag name we don't recognize
            if (($lastTag === 'mdat' or $lastTag === 'moov') and
                (!isset($tagTablePtr[$tag]) or ($tagTablePtr[$tag] instanceof TagInfo and $tagTablePtr[$tag]->Unknown)))
            {
                $et->Warn('Unknown trailer with ' . lcfirst($warnStr));
            } else {
                $et->Warn($warnStr);
            }
        }
        // tweak file type based on track content ("iso*" and "dash" ftyp only ["mp42" added in 13.39])
        if ($topLevel and $et->FileType and $et->FileType === 'MP4' and
            $et->save_ftyp and $et->HasHandler and preg_match('/^(iso|dash|mp42)/', $et->save_ftyp) and
            !empty($et->HasHandler['soun']) and empty($et->HasHandler['vide']))
        {
            $et->OverrideFileType('M4A', 'audio/mp4');
        }
        // fill in missing defaults for alternate language tags
        // (the first language is taken as the default)
        if ($doDefaultLang and $et->QTLang) {
            foreach ($et->QTLang as $tag) {     // (QTLang loop)
                if (!isset($et->VALUE[$tag])) continue;
                $langInfo = $et->TAG_INFO[$tag] ?? null;
                if (!$langInfo) continue;
                $tagInfo = $langInfo->SrcTagInfo;
                if (!$tagInfo) continue;
                $infoHash = $et->TAG_INFO;
                $name = $tagInfo->Name;
                // loop through all instances of this tag name and generate the default-language
                // version only if we don't already have a QuickTime tag with this name
                for ($i = 0, $key = $name; isset($infoHash[$key]); ++$i, $key = "$name ($i)") {
                    if ($et->GetGroup($key, 0) === 'QuickTime') continue 2;
                }
                $oldRawConv = $tagInfo->RawConv;
                if ($oldRawConv !== null) unset($tagInfo->RawConv); // (avoid doing RawConv twice)
                $key = $et->FoundTag($tagInfo, $et->VALUE[$tag]);
                if ($oldRawConv !== null) $tagInfo->RawConv = $oldRawConv;
                // copy extra tag information (groups, etc) to the synthetic tag
                $et->TAG_EXTRA[$key ?? ''] = $et->TAG_EXTRA[$tag] ?? null;
                $et->VPrint(0, "(synthesized default-language tag for QuickTime:{$tagInfo->Name})");
            }
            $et->QTLang = null;
        }
        // process item information now that we are done processing its 'meta' container
        if ($topLevel or $dirID === 'meta') self::HandleItemInfo($et);

        // process linked list of trailers
        for (; $trailer; $trailer = $trailer[3]) {
            if ($lastPos > $trailer[1]) continue;   // skip if we have already processed this as an atom
            if (!$raf->Seek($trailer[1], 0)) break;
            if ($trailer[0] === 'LigoGPS' and $raf->Read($buff, 8) == 8 and preg_match('/skip$/i', $buff)) {
                if (!$ee) {
                    $et->Warn('Use the ExtractEmbedded option to decode timed GPS', 3);
                    continue;
                }
                $len = ExifTool::Get32u($buff, 0) - 16;
                if ($len > 0 and $raf->Read($buff, $len) == $len and preg_match('/^LIGOGPSINFO\0/', $buff)) {
                    $tbl = ExifTool::GetTagTable('Image::ExifTool::QuickTime::Stream');
                    $di = ['DataPt' => $buff, 'DataPos' => $trailer[1] + 8, 'DirName' => 'LigoGPSTrailer'];
                    $et->VerboseDump($buff, DataPos: $di['DataPos']);
                    // (module may not be ported yet)
                    if (ExifTool::RequireModule('Image::ExifTool::LigoGPS') and method_exists(LigoGPS::class, 'ProcessLigoGPS')) {
                        LigoGPS::ProcessLigoGPS($et, $di, $tbl);
                    }
                } else {
                    $et->Warn('Unrecognized data in LigoGPS trailer');
                }
            } elseif ($trailer[0] === 'Insta360' and $ee) {
                // process Insta360 trailer if it exists
                if (!$raf->Seek(0, 2)) {
                    $et->Warn('Seek error');
                    break;
                }
                $offset = $raf->Tell() - $trailer[1] - $trailer[2];
                $di = ['RAF' => $raf, 'DirName' => $trailer[0], 'Offset' => $offset];
                self::ProcessInsta360($et, $di);
            } elseif ($trailer[0] === 'MIE') {
                $di = ['RAF' => $raf, 'DirName' => 'MIE', 'Trailer' => 1];
                // (module may not be ported yet)
                if (ExifTool::RequireModule('Image::ExifTool::MIE') and method_exists(MIE::class, 'ProcessMIE')) {
                    MIE::ProcessMIE($et, $di);
                }
            }
        }
        // brute force scan for metadata embedded in media data
        // (and process Insta360 trailer if it exists)
        if ($ee and $topLevel and !$et->OPTIONS['FastScan']) self::ScanMediaData($et);

        // restore any changed options
        foreach ($saveOptions as $opt => $optVal) $et->Options($opt, $optVal);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process a QuickTime Image File
    // Inputs: 0) ExifTool object reference, 1) directory information reference
    // Returns: 1 on success
    public static function ProcessQTIF(ExifTool $et, array &$dirInfo)
    {
        $table = ExifTool::GetTagTable('Image::ExifTool::QuickTime::ImageFile');
        return self::ProcessMOV($et, $dirInfo, $table);
    }

    //==============================================================================
    // WriteQuickTime.pl routines needed for reading

    //------------------------------------------------------------------------------
    // Get localized version of tagInfo hash
    // Inputs: 0) tagInfo hash ref, 1) language code (eg. "fra-FR")
    // Returns: new tagInfo hash ref, or undef if invalid or no language code
    public static function GetLangInfo(TagInfo $tagInfo, $langCode)
    {
        if (!$langCode) return null;
        // only allow alternate language tags in lang-alt lists
        $writable = $tagInfo->Writable;
        if ($writable === null) $writable = $tagInfo->Table ? $tagInfo->Table['WRITABLE'] : null;
        if (!$writable) return null;
        $langCode = strtr($langCode, '_', '-'); // RFC 3066 specifies '-' as a separator
        $langInfo = self::CoreGetLangInfo($tagInfo, $langCode);
        return $langInfo;
    }
}
