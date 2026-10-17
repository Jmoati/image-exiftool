<?php

//------------------------------------------------------------------------------
// File:         RSRC.pm
//
// Description:  Read Mac OS Resource information
//
// Revisions:    2010/03/17 - P. Harvey Created
//
// References:   1) http://developer.apple.com/legacy/mac/library/documentation/mac/MoreToolbox/MoreToolbox-99.html
//
// PHP port of Image::ExifTool::RSRC 1.10 (reading only). Tag tables are generated into
// tables/RSRC.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class RSRC
{
    public static $VERSION = '1.10';

    //------------------------------------------------------------------------------
    // Read information from a Mac resource file (ref 1)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid resource file
    public static function ProcessRSRC(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'] ?? null;
        $hdr = $map = $buff = null;

        // allow access with data reference
        if (!$raf) $raf = new RandomAccess($dirInfo['DataPt']);

        // attempt to validate the format as thoroughly as practical
        if ($raf->Read($hdr, 30) != 30) return 0;
        [$datOff, $mapOff, $datLen, $mapLen] = Perl::unpack('N*', $hdr);
        if (!$raf->Seek(0, 2)) return 0;
        $fLen = $raf->Tell();
        if ($datOff < 0x10 or $datOff + $datLen > $fLen) return 0;
        if ($mapOff < 0x10 or $mapOff + $mapLen > $fLen or $mapLen < 30) return 0;
        if ($datOff < $mapOff and $datOff + $datLen > $mapOff) return 0;
        if ($mapOff < $datOff and $mapOff + $mapLen > $datOff) return 0;

        // read the resource map
        if (!($raf->Seek($mapOff, 0) and $raf->Read($map, $mapLen) == $mapLen)) return 0;
        ExifTool::SetByteOrder('MM');
        $typeOff = ExifTool::Get16u($map, 24);
        $nameOff = ExifTool::Get16u($map, 26);
        $numTypes = (ExifTool::Get16u($map, 28) + 1) & 0xffff;

        // validate offsets in the resource map
        if ($typeOff < 28 or $nameOff < 30) return 0;

        if (!$et->IN_RESOURCE) $et->SetFileType('RSRC');
        $verbose = $et->Options('Verbose');
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::RSRC::Main');
        $et->VerboseDir('RSRC', $numTypes);

        // parse resource type list
        for ($i = 0; $i < $numTypes; ++$i) {
            $off = $typeOff + 2 + 8 * $i;    // offset of entry in type list
            if ($off + 8 > $mapLen) break;
            $resType = substr($map, $off, 4);  // resource type
            $resNum = ExifTool::Get16u($map, $off + 4);  // number of resources - 1
            $refOff = ExifTool::Get16u($map, $off + 6) + $typeOff; // offset to first resource reference
            // loop through all resources
            for ($j = 0; $j <= $resNum; ++$j) {
                $roff = $refOff + 12 * $j;
                if ($roff + 12 > $mapLen) break;
                // read only the 24-bit resource data offset
                $id = ExifTool::Get16u($map, $roff);
                $resOff = (ExifTool::Get32u($map, $roff + 4) & 0x00ffffff) + $datOff;
                $resNameOff = ExifTool::Get16u($map, $roff + 2) + $nameOff + $mapOff;
                $tag = $val = $valLen = null;
                $tagInfo = $tagTablePtr[$resType];
                if ($tagInfo) {
                    $tag = $resType;
                } else {
                    $tag = Perl::sprintf('%s_0x%.4x', $resType, $id);
                    $tagInfo = $tagTablePtr[$tag];
                }
                // read the resource data if necessary
                if ($tagInfo or $verbose) {
                    if (!($raf->Seek($resOff, 0) and $raf->Read($buff, 4) == 4 and
                          ($valLen = Perl::unpack('N', $buff)[0]) < 100000000 and // arbitrary size limit (100MB)
                          $raf->Read($val, $valLen) == $valLen))
                    {
                        $et->Warn("Error reading $resType resource");
                        continue;
                    }
                }
                if ($verbose) {
                    $resName = $nameLen = null;
                    if (!($raf->Seek($resNameOff, 0) and $raf->Read($buff, 1) and
                          ($nameLen = ord($buff)) != 0 and $raf->Read($resName, $nameLen) == $nameLen)) $resName = '';
                    $et->VPrint(0, Perl::sprintf("%s resource ID 0x%.4x (offset 0x%.4x, $valLen bytes, name='%s'):\n",
                        $resType, $id, $resOff, $resName));
                    $et->VerboseDump($val);
                }
                if (!$tagInfo) continue;
                if ($resType === 'vers') {
                    // parse the 'vers' resource to get the long version string
                    if (!($valLen > 8)) continue;
                    // long version string is after short version
                    $p = 7 + ExifTool::Get8u($val, 6);
                    if ($p >= $valLen) continue;
                    $vlen = ExifTool::Get8u($val, $p++);
                    if ($p + $vlen > $valLen) continue;
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::RSRC::Main');
                    $val = $et->Decode(substr($val, $p, $vlen), 'MacRoman');
                } elseif ($resType === 'sfnt') {
                    // parse the OTF font block
                    if (!$raf->Seek($resOff + 4, 0)) continue;
                    $dirInfo['Base'] = $resOff + 4;
                    ExifTool::RequireModule('Image::ExifTool::Font');
                    if (!Font::ProcessOTF($et, $dirInfo)) {
                        $et->Warn('Unrecognized sfnt resource format');
                    }
                    // assume this is a DFONT file unless processing the rsrc fork
                    if (!$et->DOC_NUM) $et->OverrideFileType('DFONT');
                    continue;
                } elseif ($resType === '8BIM') {
                    $ttPtr = ExifTool::GetTagTable('Image::ExifTool::Photoshop::Main');
                    $et->HandleTag($ttPtr, $id, $val,
                        DataPt:  $val,
                        DataPos: $resOff + 4,
                        Size:    $valLen,
                        Start:   0,
                        Parent:  'RSRC',
                    );
                    continue;
                } elseif ($resType === 'STR ' and $valLen > 1) {
                    // extract Pascal string
                    $len = ord($val);
                    if (!($valLen >= $len + 1)) continue;
                    $val = substr($val, 1, $len);
                } elseif ($resType === 'usro' and $valLen > 4) {
                    $len = Perl::unpack('N', $val)[0];
                    if (!($valLen >= $len + 4)) continue;
                    $val = preg_replace('/\0.*/', '', substr($val, 4, $len)); // truncate at null
                } elseif ($resType === 'STR#' and $valLen > 2) {
                    // extract list of strings (ref http://simtech.sourceforge.net/tech/strings.html)
                    $num = Perl::unpack('n', $val)[0];
                    if ($num & 0xf000) continue; // (ignore special-format STR# resources)
                    $vals = [];
                    $pos = 2;
                    for ($k = 0; $k < $num; ++$k) {     // (Perl: my $i)
                        if ($pos >= $valLen) break;
                        $len = ord($val[$pos++]);
                        if ($pos + $len > $valLen) break;
                        $vals[] = substr($val, $pos, $len);
                        $pos += $len;
                    }
                    $val = $vals;
                } elseif ($resType === 'POST') {
                    // assume this is a DFONT file unless processing the rsrc fork
                    if (!$et->DOC_NUM) $et->OverrideFileType('DFONT');
                    $val = substr($val, 2);
                } elseif ($resType !== 'TEXT') {
                    continue;
                }
                $et->HandleTag($tagTablePtr, $tag, $val);
            }
        }
        return 1;
    }
}
