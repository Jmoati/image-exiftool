<?php

//------------------------------------------------------------------------------
// File:         NikonSettings.pm
//
// Description:  Read Nikon user settings
//
// Revisions:    2021/01/08 - Warren Hatch Created
//               2021/01/12 - PH Created ProcessNikonSettings() to extract tags
//                            by ID instead of using fixed offsets
//
// References:   1) Warren Hatch private communication (D6 and Z7_2)
//
// PHP port of Image::ExifTool::NikonSettings 1.08. Tag tables are generated into
// tables/NikonSettings.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class NikonSettings
{
    public static $VERSION = '1.08';

    //------------------------------------------------------------------------------
    // Process NikonSettings directory (ref PH)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessNikonSettings(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        // NikonSettings directory has a 24-byte header:
        //  0x00 - undef[4]: '0100'
        //  0x04 - int32u:   1 (D-models), 2 (Z-models)
        //  0x08 - undef[4]: '0100'
        //  0x0c - int32u:   1 (Z7), 2 (D850), 3 (D780,Z50), 4 (D6,Z5), 5(Z7m2)
        //  0x10 - undef[4]: firmware version (eg. '0110' for firmware 1.10)
        //  0x14 - int32u:   number of entries in directory

        if ($dirInfo['DirLen'] < 24) return 0;  // sanity check

        $dataPt = $dirInfo['DataPt'];
        $start = $dirInfo['DirStart'];
        $num = ExifTool::Get32u($dataPt, $start + 0x14);

        $et->VerboseDir('NikonSettings', $num);

        $n = Perl::int(($dirInfo['DirLen'] - 0x18) / 8);
        if ($n < $num) {
            $et->Warn('Missing ' . ($num - $n) . ' NikonSettings entries', 1);
            $num = $n;
        } elseif ($n > $num) {
            $et->Warn('Unused space in NikonSettings directory', 1);
        }
        for ($i = 0; $i < $num; ++$i) {
            $entry = $start + 0x18 + $i * 8;
            $tag = ExifTool::Get16u($dataPt, $entry);
            // this is odd, but either the format is 16-bit and always big-endian,
            // or it is 8-bit and we have an unknown byte in the entry...
            $fmt = ExifTool::Get8u($dataPt, $entry + 3);
            $val = ExifTool::Get32u($dataPt, $entry + 4);
            // abort if the tag has a format that we haven't yet seen
            // (assuming this is a size/format code.  So far we have only seen a code of 4)
            if ($fmt != 4) {
                $et->Warn(Perl::sprintf('Unknown format $fmt for NikonSettings tag 0x%.4x', $tag));
                break;
            }
            $et->HandleTag($tagTablePtr, $tag, $val,
                DataPt: $dataPt,
                DataPos: $dirInfo['DataPos'] ?? null,
                Base: $dirInfo['Base'] ?? null,
                Start: $entry + 4,
                Size: 4,
                Format: 'int32u',
                Index: $i,
            );
        }
        return 1;
    }
}
