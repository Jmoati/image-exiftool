<?php

//------------------------------------------------------------------------------
// File:         Vorbis.pm
//
// Description:  Read Ogg Vorbis audio meta information
//
// Revisions:    2006/11/10 - P. Harvey Created
//               2011/07/12 - PH Moved Ogg to a separate module and added Theora
//
// References:   1) http://www.xiph.org/vorbis/doc/
//               2) http://flac.sourceforge.net/ogg_mapping.html
//               3) http://www.theora.org/doc/Theora.pdf
//
// PHP port of Image::ExifTool::Vorbis 1.08 (reading only). Tag tables are generated
// into tables/Vorbis.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Vorbis
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Process Vorbis Comments
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success, otherwise returns 0 and sets a Warning
    public static function ProcessComments(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $pos = ($dirInfo['DirStart'] ?? null) ?: 0;
        $end = !empty($dirInfo['DirLen']) ? $pos + $dirInfo['DirLen'] : strlen($dataPt);
        $num = $index = null;

        ExifTool::SetByteOrder('II');
        for (;;) {
            if ($pos + 4 > $end) break;
            $len = ExifTool::Get32u($dataPt, $pos);
            if ($pos + 4 + $len > $end) break;
            $start = $pos + 4;
            $buff = substr($dataPt, $start, $len);
            $pos = $start + $len;
            if ($num !== null) {
                if (!preg_match('/(.*?)=(.*)/s', $buff, $m)) break;
                [$tag, $val] = [strtoupper($m[1]), $m[2]];
                // Vorbis tag ID's are all capitals, so they may conflict with our internal tags
                // --> protect against this by adding a trailing underline if necessary
                if (isset(Vars\ExifTool::$specialTags[$tag])) $tag .= '_';
            } else {
                $tag = 'vendor';
                $val = $buff;
                $num = ($pos + 4 < $end) ? ExifTool::Get32u($dataPt, $pos) : 0;
                $et->VPrint(0, "  + [Vorbis comments with $num entries]\n");
                $pos += 4;
            }
            // add tag to table unless it exists already
            if (!isset($tagTablePtr[$tag])) {
                $name = ucfirst(strtolower($tag));
                // remove invalid characters in tag name and capitalize following letters
                $name = preg_replace_callback('/[^\w-]+(.?)/s', static fn ($m) => strtoupper($m[1]), $name);
                $name = preg_replace_callback('/([a-z0-9])_([a-z])/', static fn ($m) => $m[1] . strtoupper($m[2]), $name);
                $et->VPrint(0, "  | [adding $tag]\n");
                ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $name]);
            }
            $et->HandleTag($tagTablePtr, $tag, $et->Decode($val, 'UTF8'),
                Index   : $index,
                DataPt  : $dataPt,
                DataPos : $dataPos,
                Start   : $start,
                Size    : $len,
            );
            // all done if this was our last tag
            if (!($num--)) return 1;
            $index = ($index !== null) ? $index + 1 : 0;
        }
        $et->Warn('Format error in Vorbis comments');
        return 0;
    }
}
