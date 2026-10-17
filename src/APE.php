<?php

//------------------------------------------------------------------------------
// File:         APE.pm
//
// Description:  Read Monkey's Audio meta information
//
// Revisions:    11/13/2006 - P. Harvey Created
//
// References:   1) http://www.monkeysaudio.com/
//               2) http://www.personal.uni-jena.de/~pfk/mpp/sv8/apetag.html
//
// PHP port of Image::ExifTool::APE 1.07 (reading only). Tag tables are generated
// into tables/APE.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class APE
{
    public static $VERSION = '1.07';

    //------------------------------------------------------------------------------
    // Make tag info hash for specified tag
    // Inputs: 0) tag name, 1) tag table ref
    // - must only call if tag doesn't exist
    public static function MakeTag($tag, TagTable $tagTablePtr)
    {
        $name = ucfirst(strtolower($tag));
        // remove invalid characters in tag name and capitalize following letters
        $name = preg_replace_callback('/[^\w-]+(.?)/s', static fn ($m) => strtoupper($m[1]), $name);
        $name = preg_replace_callback('/([a-z0-9])_([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name);
        $tagInfo = ['Name' => $name];
        if (preg_match('/^Cover Art/', $tag) and !preg_match('/Desc$/', $tag)) $tagInfo['Groups'] = [2 => 'Preview'];
        return ExifTool::AddTagToTable($tagTablePtr, $tag, $tagInfo);
    }

    //------------------------------------------------------------------------------
    // Extract information from an APE file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // - Just looks for APE trailer if FileType is already set
    // Returns: 1 on success, 0 if this wasn't a valid APE file
    public static function ProcessAPE(ExifTool $et, array &$dirInfo)
    {
        // must first check for leading/trailing ID3 information
        if (!$et->DoneID3) {
            ExifTool::RequireModule('Image::ExifTool::ID3');
            if (ID3::ProcessID3($et, $dirInfo)) return 1;
        }
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $buff = $header = $tagTablePtr = $dataPos = $oldIndent = null;

        $et->DoneAPE = 1;

        // check APE signature and process audio information
        // unless this is some other type of file
        if (!$et->FileType) {
            if ($raf->Read($buff, 32) != 32) return 0;
            if (!preg_match('/^(MAC |APETAGEX)/', $buff)) return 0;
            $et->SetFileType();
            ExifTool::SetByteOrder('II');

            if (str_starts_with($buff, 'APETAGEX')) {
                // we already read the APE header
                $header = 1;
            } else {
                // process the MAC header
                $vers = ExifTool::Get16u($buff, 4);
                $table = null;
                if ($vers <= 3970) {
                    $buff = substr($buff, 4);
                    $table = ExifTool::GetTagTable('Image::ExifTool::APE::OldHeader');
                } else {
                    $dlen = ExifTool::Get32u($buff, 8);
                    $hlen = ExifTool::Get32u($buff, 12);
                    if (!($dlen & 0x80000000 or $hlen & 0x80000000)) {
                        if ($raf->Seek($dlen, 0) and $raf->Read($buff, $hlen) == $hlen) {
                            $table = ExifTool::GetTagTable('Image::ExifTool::APE::NewHeader');
                        }
                    }
                }
                if ($table) {
                    $subdirInfo = ['DataPt' => $buff];  // (Perl anonymous hash)
                    $et->ProcessDirectory($subdirInfo, $table);
                }
            }
        }
        // look for APE trailer unless we already found an APE header
        if (!$header) {
            // look for the APE trailer footer...
            $footPos = -32;
            // (...but before the ID3v1 trailer if it exists)
            if ($et->DoneID3 > 1) $footPos -= $et->DoneID3;
            if (!$raf->Seek($footPos, 2)) return 1;
            if ($raf->Read($buff, 32) != 32) return 1;
            if (!str_starts_with($buff, 'APETAGEX')) return 1;
            ExifTool::SetByteOrder('II');
        }
//
// Read the APE data (we have just read the APE header or footer into $buff)
//
        [$version, $size, $count, $flags] = Perl::unpack('x8V4', $buff);
        $version /= 1000;
        $size -= 32;    // get size of data only
        if (($size & 0x80000000) == 0 and
            ($header or $raf->Seek(-$size - 32, 1)) and
            $raf->Read($buff, $size) == $size)
        {
            if ($verbose) {
                $oldIndent = $et->INDENT;
                $et->INDENT .= '| ';
                $et->VerboseDir('APEv' . Perl::str($version), $count, $size);
                $et->VerboseDump($buff, DataPos: $raf->Tell() - $size);
            }
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::APE::Main');
            $dataPos = $raf->Tell() - $size;
        } else {
            $count = -1;
        }
//
// Process the APE tags
//
        $pos = 0;
        for ($i = 0; $i < $count; ++$i) {
            // read next APE tag
            if ($pos + 8 > $size) break;
            $len = ExifTool::Get32u($buff, $pos);
            $flags = ExifTool::Get32u($buff, $pos + 4);
            if (!preg_match('/\G(.*?)\0/s', $buff, $m, 0, $pos + 8)) break;
            $tag = $m[1];
            // avoid conflicts with our special table entries
            if (isset(Vars\ExifTool::$specialTags[$tag])) $tag .= '.';
            $pos += 8 + strlen($m[0]);  // (Perl pos($buff))
            if ($pos + $len > $size) break;
            $val = substr($buff, $pos, $len);
            if (!isset($tagTablePtr[$tag])) self::MakeTag($tag, $tagTablePtr);
            // handle binary-value tags
            if (($flags & 0x06) == 0x02) {
                $buf2 = $val;
                // extract cover art description separately (hackitty hack)
                if (preg_match('/^Cover Art/', $tag)) {
                    $m = [];    // (Perl $1 is undef if the substitution fails)
                    if (preg_match('/^([\x20-\x7e]*)\0/', $buf2, $m)) $buf2 = substr($buf2, strlen($m[0]));
                    if (!empty($m[1])) {
                        $t = "$tag Desc";
                        $v = $m[1];
                        if (!isset($tagTablePtr[$t])) self::MakeTag($t, $tagTablePtr);
                        $et->HandleTag($tagTablePtr, $t, $v);
                    }
                }
                $val = new ScalarRef($buf2);
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                Index   : $i,
                DataPt  : $buff,
                DataPos : $dataPos,
                Start   : $pos,
                Size    : $len,
            );
            $pos += $len;
        }
        if ($i != $count) $et->Warn('Bad APE trailer');
        if ($oldIndent !== null) $et->INDENT = $oldIndent;
        return 1;
    }
}
