<?php

//------------------------------------------------------------------------------
// File:         PPM.pm
//
// Description:  Read and write PPM meta information
//
// Revisions:    09/03/2005 - P. Harvey Created
//
// References:   1) http://netpbm.sourceforge.net/doc/ppm.html
//               2) http://netpbm.sourceforge.net/doc/pgm.html
//               3) http://netpbm.sourceforge.net/doc/pbm.html
//
// PHP port of Image::ExifTool::PPM 1.11 (reading only: the rewrite branch is not ported).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class PPM
{
    public static $VERSION = '1.11';

    //------------------------------------------------------------------------------
    // Read or write information in a PPM/PGM/PBM image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid PPM file, -1 on write error
    // (PHP: Perl's pos($buff) is the explicit $pos)
    public static function ProcessPPM(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $buff = $num = $type = $seal = null;
        $info = [];
//
// read as much of the image as necessary to extract the header and comments
//
        for (;;) {
            if ($buff !== null) {
                // need to read some more data
                $tmp = null;
                if (!$raf->Read($tmp, 1024)) return 0;
                $buff .= $tmp;
            } else {
                if (!$raf->Read($buff, 1024)) return 0;
            }
            // verify this is a valid PPM file
            if (!preg_match('/^P([1-6])\s+/', $buff, $m)) return 0;
            $pos = strlen($m[0]);
            $num = $m[1];
            // note: may contain comments starting with '#'
            if (preg_match('/\G#/', $buff, $m, 0, $pos)) {
                ++$pos;
                // must read more if we are in the middle of a comment
                if (!preg_match('/\G ?(.*[\n\r]+(#.*[\n\r]+)*)\s*/', $buff, $m, 0, $pos)) continue;
                $pos += strlen($m[0]);
                $info['Comment'] = $m[1];
                if (preg_match('/\G#/', $buff, $m, 0, $pos)) continue;
            } else {
                unset($info['Comment']);
            }
            if (!preg_match('/\G(\S+)\s+(\S+)\s+/', $buff, $m, 0, $pos)) continue;
            $pos += strlen($m[0]);
            $info['ImageWidth'] = $m[1];
            $info['ImageHeight'] = $m[2];
            $type = ['PPM', 'PBM', 'PGM'][$num % 3];
            if ($type === 'PBM') break; // (no MaxVal for PBM images)
            if (preg_match('/\G\s*#/', $buff, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                if (!preg_match('/\G ?(.*[\n\r]+(#.*[\n\r]+)*)\s*/', $buff, $m, 0, $pos)) continue;
                $pos += strlen($m[0]);
                if (!array_key_exists('Comment', $info)) $info['Comment'] = '';
                $info['Comment'] .= $m[1];
                if (preg_match('/\G#/', $buff, $m, 0, $pos)) continue;
            }
            if (!preg_match('/\G(\S+)\s/', $buff, $m, 0, $pos)) continue;
            $pos += strlen($m[0]);
            $info['MaxVal'] = $m[1];
            break;
        }
        // validate numerical values
        foreach (array_keys($info) as $_) {
            if ($_ === 'Comment') continue;
            if (!preg_match('/^\d+$/', $info[$_])) return 0;
        }
        if (isset($info['Comment'])) {
            $info['Comment'] = preg_replace('/^# ?/m', '', $info['Comment']);   // remove "# " at the start of each line
            $info['Comment'] = preg_replace('/[\n\r]+$/', '', $info['Comment'], 1); // remove trailing newline
            if (preg_match('/^<seal seal=/', $info['Comment'])) $seal = 1;
        }
        $et->SetFileType($type);
        $len = $pos;
//
// rewrite the file if requested
//
        // (writing not ported)
//
// save extracted information
//
        if ($verbose > 2) {
            fwrite($out, "$type header ($len bytes):\n");
            $et->VerboseDump($buff, Len: $len);
        }
        if ($seal) {
            $subdirInfo = ['DataPt' => $info['Comment']];
            $et->ProcessDirectory($subdirInfo, ExifTool::GetTagTable('Image::ExifTool::XMP::SEAL'));
            unset($info['Comment']);
        }
        foreach (['Comment', 'ImageWidth', 'ImageHeight', 'MaxVal'] as $tag) {
            if (isset($info[$tag])) $et->FoundTag($tag, $info[$tag]);
        }
        return 1;
    }
}
