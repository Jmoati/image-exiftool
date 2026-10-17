<?php

//------------------------------------------------------------------------------
// File:         GM.pm
//
// Description:  Read GM PDR metadata from automobile videos
//
// Revisions:    2024-04-01 - P. Harvey Created
//
// References:   1) https://exiftool.org/forum/index.php?topic=11335
//
// PHP port of Image::ExifTool::GM 1.03 (reading only). Tag tables are generated
// into tables/GM.php; the file lexicals (%convertUnits, %changeOffset, %changeScale,
// %printConv, @channel, %channelStruct) are in Vars\GM.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class GM
{
    public static $VERSION = '1.03';

    //------------------------------------------------------------------------------
    // Print one line of CSV output
    // Inputs: 0) ExifTool ref, 1) timestamp (or undef to use GMMaxTS)
    public static function PrintCSV(ExifTool $et, $ts = null)
    {
        $csv = &$et->GMCsv;     // get the list of channels with measurements
        if ($csv === null) return;
        if (!$csv) return;
        $vals = $et->GMVals;
        $gmDict = $et->GMDictionary;
        $n = $gmDict ? max(array_keys($gmDict)) + 1 : 0;   // (Perl scalar(@$gmDict))
        $items = array_fill(0, $n, '');
        $items[0] = ($ts ?: $et->GMMaxTS) / 1e7;
        // fill in scaled measurements for this TimeStamp
        foreach ($csv as $_) {
            $gmChan = $gmDict[$_];
            $items[$_] = $vals[$_] * $gmChan['Mult'] + $gmChan['Off'];
            // apply CSV conversion if applicable (ie. Gear)
            if (!($gmChan['Conv'] and isset($gmChan['Conv'][Perl::str($items[$_])]))) continue;
            $items[$_] = $gmChan['Conv'][Perl::str($items[$_])];
        }
        ksort($items);
        $out = $et->OPTIONS['TextOut'];
        fwrite($out, implode(',', array_map([Perl::class, 'str'], $items)) . "\n");
        $csv = [];  // clear the channel list
    }

    //------------------------------------------------------------------------------
    // Process mrlv atom
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_mrlv(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = strlen($dataPt);
        $pos = 0;
        // data lengths for known formats
        $fmtLen = [
            'strs' => 64, 'lang' => 64, 'strl' => 256, 'time' => 32, 'date' => 32,
            'tmzn' => 32, 'tstm' => 8, 'focc' => 4, "kvp\0" => 64 + 256,
        ];
        $et->VerboseDir('mrlv', null, $dirLen);
        while ($pos + 8 <= $dirLen) {
            $tag = substr($dataPt, $pos, 4);
            $fmt = substr($dataPt, $pos + 4, 4);
            $len = $fmtLen[$fmt] ?? null;
            if (!$len) {
                [$tag, $fmt] = [QuickTime::PrintableTagID($tag), QuickTime::PrintableTagID($fmt)];
                $et->Warn("Unknown format ($fmt) for tag $tag");
                break;
            }
            if ($pos + 8 + $len > $dirLen) {
                $et->Warn('Truncated mrlv data');
                break;
            }
            $et->HandleTag($tagTablePtr, $tag, null,
                DataPt:  $dataPt,
                DataPos: $dataPos,
                Start:   $pos + 8,
                Size:    $len,
            );
            $pos += 8 + $len;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // (PHP: the %printConv entries and the default "$val UNITS" are Perl code strings,
    //  so build the equivalent PrintConv closure)
    private static function UnitsPrintConv($units): \Closure
    {
        $code = Vars\GM::$printConv[$units] ?? null;
        if ($code !== null and preg_match('/^sprintf\("(.*)", \$val\)$/s', $code, $m)) {
            $fmt = $m[1];
            return static fn ($val) => Perl::sprintf($fmt, $val);
        }
        // qq("\$val \Q$units\E")
        return static fn ($val) => Perl::str($val) . " $units";
    }

    //------------------------------------------------------------------------------
    // Process mrld atom
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_mrld(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $channel = Vars\GM::$channel;
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataPos = $dirInfo['DataPos'] ?? null;
        $dirLen = strlen($dataPt);
        $struct = $et->Options('Struct') ?: 0;
        $et->GMDictionary = [];
        $gmDict = &$et->GMDictionary;
        $marl = ExifTool::GetTagTable('Image::ExifTool::GM::marl');
        $pos = $item = $csv = null;
        // (PHP: Vars\GM::$channelStruct holds untranslated Perl code, so take the
        //  structure from the static Channel01 entry, which is the same Perl hash)
        $channelStruct = $tagTablePtr['Channel01']->Struct;

        $et->VerboseDir('mrld', null, $dirLen);
        XMP::AddFlattenedTags($tagTablePtr, null, null, 1);
        if ($et->Options('PrintCSV')) $csv = [];

        for ($pos = 0; $pos + 448 <= $dirLen; $pos += 448) {
            // unpack 448-byte records:
            // 0. int32u - channel number
            // 1. int32u - measurement type
            // 2. int32u - units number
            // 3. string[64] - units string
            // 4. int32u - flags (0.visible, 1.linear conversion, 2.interpolation OK)
            // 5. int64u - interval
            // 6. int32s - min reading
            // 7. int32s - max reading
            // 8. double - disp min
            // 9. double - disp max
            // 10. double - multiplier
            // 11. double - offset
            // 12. string[64] - channel name
            // 13. string[64] - channel description
            $a = Perl::unpack('NNNZ64Na8N2a8a8a8a8Z64Z64', substr($dataPt, $pos, 448));
            $units = (Vars\GM::$convertUnits[$a[3]] ?? null) ?: $a[3];
            $a[3] = $et->Decode($a[3], 'UTF8');                   // convert from UTF8
            foreach ([6, 7] as $i) {                              // convert signed ints
                if ($a[$i] & 0x8000000) $a[$i] -= 4294967296;
            }
            foreach ([8, 9, 10, 11] as $i) $a[$i] = ExifTool::GetDouble($a[$i], 0);   // convert doubles
            $a[5] = ExifTool::Get64u($a[5], 0);                   // convert 64-bit int
            $chan = $a[0];
            $tag = Perl::sprintf('Channel%.2d', $chan);
            $tagInfo = $tagTablePtr[$tag];
            $hash = [];
            for ($i = 1; $i < count($a); ++$i) $hash[$channel[$i]] = $a[$i];
            if (!$tagInfo) {
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, ['Name' => $tag, 'Struct' => $channelStruct]);
                XMP::AddFlattenedTags($tagTablePtr, $tag, null, 1);
            }
            // extract channel structure if specified
            if ($struct) {
                $hash['_ordered_keys_'] = array_slice($channel, 1);
                $et->FoundTag($tagInfo, $hash);
            }
            // extract flattened channel elements
            if ($struct == 0 or $struct == 2) {
                for ($i = 1; $i < count($a); ++$i) $et->HandleTag($tagTablePtr, "$tag$channel[$i]", $a[$i]);
            }
            // add corresponding tag to marl table
            $name = ExifTool::MakeTagName($a[12]);
            $tagInfo = $marl[$name];
            if (!$tagInfo) {
                $et->VPrint(0, $et->INDENT, "[adding $name]\n");
                $tagInfo = ExifTool::AddTagToTable($marl, $name, []);
            }
            if (!$tagInfo->Description) $tagInfo->Description = $a[13];
            if (!$tagInfo->PrintConv) {
                // add a default print conversion
                $tagInfo->PrintConv = self::UnitsPrintConv($units);
            }
            // adjust multiplier/offset as necessary to scale to more appropriate units
            // (ie. to the units actually specified in this dictionary -- d'oh)
            $mult = $a[10] * ((Vars\GM::$changeScale[$units] ?? null) ?: 1);
            $off =  $a[11] * ((Vars\GM::$changeScale[$units] ?? null) ?: 1) + ((Vars\GM::$changeOffset[$units] ?? null) ?: 0);
            $init = Perl::int(($a[6] + $a[7]) / 2); // initial value for difference readings
            // save information about this channel necessary for processing the marl data
            $gmDict[$chan] = ['Name' => $name, 'Mult' => $mult, 'Off' => $off, 'Init' => $init];
            $gmDict[$chan]['Conv'] = $tagInfo->CSVConv;
            if ($csv !== null) $csv[$chan] = $a[12] . ($a[3] ? " ($a[3])" : '');
        }
        // channel 0 must not be defined because we use it for the TimeStamp
        if (isset($gmDict[0])) {
            $et->Warn('Internal error: PDR channel 0 is used');
            unset($gmDict);
            $et->GMDictionary = null;
        } elseif ($csv !== null) {
            $csv[0] = 'Time (s)';
            for ($i = 0, $n = max(array_keys($csv)); $i <= $n; ++$i) {
                if (!isset($csv[$i])) $csv[$i] = '';
            }
            ksort($csv);
            $out = $et->OPTIONS['TextOut'];
            fwrite($out, implode(',', $csv) . "\n");
            $et->GMCsv = [];
        }
        $et->AddCleanup('Image\ExifTool\GM::PrintCSV');  // print last CSV line when we are done
        // initialize variables for processing marl box
        $et->GMVals = [];
        $et->GMMaxTS = 0;
        $et->GMBadChan = 0;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process marl ctbx data
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function Process_marl(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        if ($dataPt instanceof ScalarRef) $dataPt = $dataPt->value;
        $dataPos = (($dirInfo['DataPos'] ?? null) ?: 0) + (($dirInfo['Base'] ?? null) ?: 0);
        $dataLen = strlen($dataPt);
        $vals = &$et->GMVals;   // running values for each channel (0=TimeStamp)
        $chan = $et->GMChan;    // running channel number
        $gmDict = $et->GMDictionary;
        $csv = &$et->GMCsv;
        $maxTS = $et->GMMaxTS;
        $reqGPSDateTime = $et->REQ_TAG_LOOKUP['gpsdatetime'] ?? null;
        $reqTimeStamp = $reqGPSDateTime ? ($et->REQ_TAG_LOOKUP['timestamp'] ?? null) : 1;
        $pos = $verbose2 = null;

        $et->VerboseDir('marl', null, $dataLen);
        if ($gmDict === null) {
            $et->Warn('Missing marl dictionary');
            return 0;
        }
        $maxChan = $gmDict ? max(array_keys($gmDict)) : -1;
        if ($et->Options('Verbose') > 1) $verbose2 = 1;
        if (!isset($vals[0])) $vals[0] = -1;    // (we use the 0th channel for the TimeStamp)
        $ts = $vals[0];

        for ($pos = 0; $pos + 8 <= $dataLen; $pos += 8) {
            $a = Perl::unpack('NN', substr($dataPt, $pos, 8));
            $ah = $a[0] >> 24;
            $a2 = $ah & 0xc0;
            $val = $chanDiff = $valDiff = $gmChan = null;
            $ts_ = [];      // (Perl @ts)
            if ($a2 == 0xc0) {          // 16-byte full record?
                if ($ah == 0xff) break;     // exit at first empty record
                $chan = $a[0] & 0x0fffffff;
                $gmChan = $gmDict[$chan] ?? null;
                if (!$gmChan) continue;     // (shouldn't happen)
                $val = $a[1] - ($a[1] & 0x80000000 ? 4294967296 : 0);
                $vals[$chan] = $val;
                if ($pos + 16 > $dataLen) break;    // (shouldn't happen)
                $pos += 8;                          // point at time stamp
                $ts_ = Perl::unpack('NN', substr($dataPt, $pos, 8));
                $ts = $ts_[0] * 4294967296 + $ts_[1];
            } elseif ($a2 == 0x40) {    // 8-byte difference record?
                if ($chan === null) continue;       // (shouldn't happen)
                $ts += $a[1];                       // increment time stamp
                $chanDiff = ($ah & 0x3f) - ($ah & 0x20 ? 0x40 : 0);
                $chan += $chanDiff;                 // increment the running channel number
                $gmChan = $gmDict[$chan] ?? null;
                if (!$gmChan) continue;             // (shouldn't happen)
                if (!isset($vals[$chan])) $vals[$chan] = $gmChan['Init'];  // init if necessary
                $valDiff = ($a[0] & 0x00ffffff) - ($a[0] & 0x00800000 ? 0x01000000 : 0);
                $val = ($vals[$chan] += $valDiff);  // increment the running value for this channel
            } else {
                continue;   // (shouldn't happen)
            }
            // ensure that the timestamps are monotonically increasing
            // (have seen backward steps up to 0.033 sec, so fudge these)
            if ($ts > $maxTS) {
                if ($csv !== null) {
                    self::PrintCSV($et, $maxTS);
                } else {
                    $et->DOC_NUM = ++$et->DOC_COUNT;
                    if ($reqTimeStamp) $et->HandleTag($tagTablePtr, 'TimeStamp', $ts);
                    if ($reqGPSDateTime) $et->HandleTag($tagTablePtr, 'GPSDateTime', $ts);
                }
                $maxTS = $ts;
            }
            if ($csv !== null) {
                $csv[] = $chan;
                continue;
            }
            $scaled = $val * $gmChan['Mult'] + $gmChan['Off'];
            $et->HandleTag($tagTablePtr, $gmChan['Name'], $scaled);
            if ($verbose2) {
                $str = ' * ' . Perl::str($gmChan['Mult']) . ' + ' . Perl::str($gmChan['Off']) . ' = ' . Perl::str($scaled);
                $p0 = $dataPos + $pos - ($a2 == 0xc0 ? 8 : 0);
                [$cd, $vd] = $ts_ ? ['', ''] : [Perl::sprintf('%+d', $chanDiff), Perl::sprintf('%+d', $valDiff)];
                echo Perl::sprintf("| %8.4x: %.8x %.8x chan$cd=%.2d $gmChan[Name]$vd = $val$str\n", $p0, $a[0], $a[1], $chan);
                if ($ts_) echo Perl::sprintf("| %8.4x: %.8x %.8x         TimeStamp = %.6f sec\n", $dataPos + $pos, $ts_[0], $ts_[1], $ts / 1e7);
            }
        }
        $vals[0] = $ts;         // save last timestamp
        $et->GMChan = $chan;    // save last channel number
        $et->GMMaxTS = $ts;
        $et->DOC_NUM = null;
        return 1;
    }
}
