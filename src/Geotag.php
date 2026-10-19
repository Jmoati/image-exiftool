<?php

//------------------------------------------------------------------------------
// File:         Geotag.pm
//
// Description:  Geotagging utility routines
//
// Revisions:    2009/04/01 - P. Harvey Created
//
// PHP port of Image::ExifTool::Geotag 1.88.
// Notes: Perl hashes keyed by a floating point time use the Perl string of the time
// (Perl::str) as key, so that the keys match Perl's. A "reference to a fix" that the
// Perl code keeps after storing it in the Points hash ($lastFix, $fix in the NMEA code)
// is emulated by writing the changes back to the Points hash.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class Geotag
{
    public const JITTER = 2;    // maximum time jitter
    private const SEC_PER_DAY = 86400;

    // XML tags that we recognize (keys are forced to lower case)
    private static array $xmlTag = [
        'lat' => 'lat', 'latitude' => 'lat', 'latitudedegrees' => 'lat',
        'lon' => 'lon', 'longitude' => 'lon', 'longitudedegrees' => 'lon',
        'ele' => 'alt', 'elevation' => 'alt', 'alt' => 'alt', 'altitude' => 'alt', 'altitudemeters' => 'alt',
        'time' => 'time', 'fix' => 'fixtype', 'hdop' => 'hdop', 'vdop' => 'vdop', 'pdop' => 'pdop',
        'sat' => 'nsats', 'atemp' => 'atemp', 'when' => 'time', 'coordinates' => 'coords',
        'coord' => 'coords', 'begin' => 'begin', 'end' => 'time', 'course' => 'dir',
        'pitch' => 'pitch', 'roll' => 'roll', 'speed' => 'speed', 'accuracy_horizontal' => 'err',
        // XML containers (fix is reset at the opening tag of these properties)
        'wpt' => '', 'trkpt' => '', 'rtept' => '', 'trackpoint' => '', 'placemark' => '',
    ];

    private static array $userTag = [];   // user-defined XML tags

    // fix information keys which must be interpolated around a circle
    private static array $cyclical = ['lon' => 1, 'track' => 1, 'dir' => 1, 'pitch' => 1, 'roll' => 1];
    private static array $cyc180 = ['lon' => 1, 'pitch' => 1, 'roll' => 1]; // wraps from 180 to -180

    // fix information keys for each of our general categories
    private static array $fixInfoKeys = [
        'pos' => ['lat', 'lon'],
        'track' => ['track', 'speed'],
        'alt' => ['alt'],
        'orient' => ['dir', 'pitch', 'roll'],
        'atemp' => ['atemp'],
        'err' => ['err'],
        'dop' => ['hdop', 'vdop', 'pdop'],
    ];

    // category for select keys
    private static array $keyCategory = [
        'track' => 'track', 'speed' => 'track', 'alt' => 'alt', 'dir' => 'orient',
        'pitch' => 'orient', 'roll' => 'orient', 'hdop' => 'dop', 'pdop' => 'dop', 'vdop' => 'dop',
    ];

    // list of tags used when geotagging from image/video files
    private static array $tagKey = [
        'GPSDateTime' => 'time', 'GPSLatitude' => 'lat', 'GPSLongitude' => 'lon',
        'GPSTrack' => 'track', 'GPSSpeed' => 'speed', 'GPSAltitude' => 'alt',
        'TimeStamp' => 'time', 'GPSTimeStamp' => '', 'GPSDateStamp' => '',
    ];

    // tags which may exist separately in some formats (eg. CSV)
    private static array $sepTags = [
        'dir' => 1, 'pitch' => 1, 'roll' => 1, 'track' => 1, 'speed' => 1,
        'hdop' => 1, 'pdop' => 1, 'vdop' => 1,
    ];

    // conversion factors for GPSSpeed (standard EXIF units only)
    private static array $speedConv = [
        'K' => 1.852, 'M' => 1.150779448, 'k' => 'K', 'm' => 'M', 'km/h' => 'K', 'mph' => 'M',
    ];

    // all recognized speed conversion factors (non-EXIF included)
    private static array $otherConv = ['km/h' => 1.852, 'mph' => 1.150779448, 'm/s' => 0.514444];

    // hash key for a time
    private static function K($t): string
    {
        return Perl::str($t);
    }

    // (values are passed to SetNewValue as numbers, like Perl: the full precision is used)
    private static function S($v)
    {
        return $v;
    }

    // Perl truthiness of a scalar
    private static function T($v): bool
    {
        return $v !== null && $v !== '' && $v !== '0' && $v !== 0 && $v !== 0.0 && $v !== false;
    }

    //------------------------------------------------------------------------------
    // Split a line of CSV
    // Inputs: 0) line to split, 1) delimiter
    // Returns: list of items
    public static function SplitCSV($line, $delim)
    {
        $toks = $line === '' ? [] : explode($delim, $line);
        while ($toks && end($toks) === '') {
            array_pop($toks);
        }
        $vals = [];
        while ($toks) {
            $v = preg_replace('/^ +/', '', array_shift($toks));
            if (preg_match('/^"/', $v)) {
                $v = substr($v, 1);
                // quoted value must end in an odd number of quotes
                while (!preg_match('/("+)\s*$/', $v, $m) || !(strlen($m[1]) & 1)) {
                    if (!$toks) {
                        break;
                    }
                    $v .= $delim.array_shift($toks);
                }
                $v = preg_replace('/"\s*$/', '', $v);    // remove trailing quote and whitespace
                $v = str_replace('""', '"', $v);          // un-escape quotes
            }
            $vals[] = $v;
        }

        return $vals;
    }

    //------------------------------------------------------------------------------
    // Load GPS track log file or read GPS from image/video file
    // Inputs: 0) ExifTool ref, 1) track log data or file name
    // Returns: geotag hash data reference or error string
    // - the geotag hash has the following members:
    //       Points, Times, NoDate, IsDate, Has (see Geotag.pm)
    // - concatenates new data with existing track data stored in ExifTool NEW_VALUE
    //   for the Geotag tag
    public static function LoadTrackLog(ExifTool $et, $val)
    {
        $saveRS = Perl::$RS;
        try {
            return self::LoadTrackLogInner($et, Perl::str($val));
        } finally {
            Perl::$RS = $saveRS;
        }
    }

    private static function LoadTrackLogInner(ExifTool $et, string $val)
    {
        $raf = $from = $time = $isDate = $noDate = $noDateChanged = $lastDate = $dateFlarm = null;
        $nmeaStart = $fixSecs = $lastFix = $sortFixes = null;
        $fixTimes = [];
        $nmea = [];
        $csvHeadings = [];
        $canCut = $e0 = $e1 = $trackFile = $trackTime = $scaleSpeed = $startTime = null;
        $fh = null;

        self::InitUserTags($et);

        // add data to existing track
        $geotag = $et->GetNewValue('Geotag') ?: [];
        if (!is_array($geotag)) {
            $geotag = [];
        }

        // initialize track points lookup
        $points = $geotag['Points'] ?? [];
        // get lookup for available information types
        $has = $geotag['Has'] ?? ['pos' => 1];

        $format = '';
        // is $val track log data?
        if (preg_match('/^(\xef\xbb\xbf)?<(\?xml|gpx)[\s>]/', $val)) {
            $format = 'XML';
            Perl::$RS = '>';   // set input record separator to '>' for XML/GPX data
        } elseif (preg_match('/(\x0d\x0a|\x0d|\x0a)/', $val, $m)) {
            Perl::$RS = $m[1];
        } else {
            // $val is track file name
            if ($et->Open($fh, $val)) {
                $trackFile = $val;
                $raf = new RandomAccess($fh);
                $line = '';
                if (!$raf->Read($line, 256)) {
                    fclose($fh);

                    return "Empty track file '{$val}'";
                }
                // look for XML or GPX header (might as well allow UTF-8 BOM)
                if (preg_match('/^(\xef\xbb\xbf)?<(\?xml|gpx)[\s>]/', $line)) {
                    $format = 'XML';
                    Perl::$RS = '>';   // set input record separator to '>' for XML/GPX data
                } elseif (preg_match('/(\x0d\x0a|\x0d|\x0a)/', $line, $m)) {
                    Perl::$RS = $m[1];
                } else {
                    fclose($fh);

                    return "Invalid track file '{$val}'";
                }
                $raf->Seek(0, 0);
                $from = "file '{$val}'";
            } elseif ($val === 'DATETIMEONLY') {
                $geotag['DateTimeOnly'] = 1;
                $geotag['IsDate'] = 1;
                $et->VPrint(0, 'Geotagging date/time only');

                return $geotag;
            } else {
                return "Error opening GPS file '{$val}'";
            }
        }
        if (!$from) {
            // set up RAF for reading log file in memory
            $raf = new RandomAccess($val);
            $from = 'data';
        }

        // initialize cuts
        $maxHDOP = $et->Options('GeoMaxHDOP');
        $maxPDOP = $et->Options('GeoMaxPDOP');
        $minSats = $et->Options('GeoMinSats');
        $isCut = $maxHDOP || $maxPDOP || $minSats;

        $numPoints = 0;
        $skipped = 0;
        $lastSecs = 0;
        $fix = [];
        $fixKey = null;     // key of $fix in $points when it has been stored (NMEA)
        $csvDelim = $et->Options('CSVDelim');
        if ($csvDelim === null) {
            $csvDelim = ',';
        }
        $saveFix = $saveTime = [];
        $timeSpan = null;
        $userTag = self::$userTag;
        $xmlTag = self::$xmlTag;
        $keyCategory = self::$keyCategory;
        $sepTags = self::$sepTags;
        $otherConv = self::$otherConv;
        $line = '';
        $m = null;
        $flags = PREG_UNMATCHED_AS_NULL;
        $cutPDOP = $cutHDOP = $cutSats = 0;

        for (;;) {
            if (!$raf->ReadLine($line)) {
                break;
            }
            // determine file format
            if (!$format) {
                $line = preg_replace('/^\xef\xbb\xbf/', '', $line);  // remove leading BOM if it exists
                if (preg_match('/^(\xff\xfe|\xfe\xff)/', $line)) {
                    return "ExifTool doesn't yet read UTF16-format track logs";
                }
                if (preg_match('/^<(\?xml|gpx)[\s>]/', $line)) { // look for XML or GPX header
                    $format = 'XML';
                // check for NMEA sentence
                } elseif (preg_match('/^.*\$([A-Z]{2}(RMC|GGA|GLL|ZDA)|PMGNTRK),/', $line, $m, $flags)) {
                    $format = 'NMEA';
                    $nmeaStart = $m[2] ?: $m[1];  // save type of first sentence
                } elseif (preg_match('/^A(FLA|XSY|FIL)/', $line)) {
                    // (don't set format yet because we want to read HFDTE first)
                    $nmeaStart = 'B';
                    continue;
                } elseif (preg_match('/^HFDTE(?:DATE:)?(\d{2})(\d{2})(\d{2})/', $line, $m)) {
                    $year = $m[3] + ($m[3] >= 70 ? 1900 : 2000);
                    $dateFlarm = ExifTool::TimeLocal_timegm(0, 0, 0, $m[1], $m[2] - 1, $year);
                    $nmeaStart = 'B';
                    $format = 'IGC';
                    continue;
                } elseif ($nmeaStart && preg_match('/^B/', $line)) {
                    // parse IGC fixes without a date
                    $format = 'IGC';
                } elseif (preg_match('/^TP,D,/', $line)) {
                    $format = 'Winplus';
                } elseif (preg_match('/^\s*\d+\s+.*\sypr\s*$/', $line) && count(Perl::split(' ', $line)) == 12) {
                    $format = 'Bramor';
                } elseif (((preg_match('/\b(GPS)?Date/i', $line) && preg_match('/\b(GPS)?(Date)?Time/i', $line))
                    || preg_match('/\bTime\(seconds\)/i', $line)) && preg_match('/'.preg_quote($csvDelim, '/').'/', $line)) {
                    Perl::chomp($line);
                    $csvHeadings = self::SplitCSV($line, $csvDelim);
                    $isColumbus = (($csvHeadings[0] ?? '') !== '' && $csvHeadings[0] === 'INDEX'); // (Columbus GPS logger)
                    $format = 'CSV';
                    // convert recognized headings to our parameter names
                    foreach ($csvHeadings as &$h) {
                        $head = $h;
                        $param = null;
                        $xtra = '';
                        $h = preg_replace('/^GPS ?/', '', $h); // remove leading "GPS" to simplify regex patterns
                        if (preg_match('/^Time ?\(seconds\)$/i', $h)) { // DJI
                            // DJI CSV log files have a column "Time(seconds)" which is seconds since
                            // the start of the flight.  The date/time is obtained from the file name.
                            $param = 'runtime';
                            if ($trackFile !== null && preg_match('/(\d{4})-(\d{2})-(\d{2})[^\/]+(\d{2})-(\d{2})-(\d{2})[^\/]*$/', $trackFile, $mm)) {
                                $trackTime = ExifTool::TimeLocal($mm[6], $mm[5], $mm[4], $mm[3], $mm[2] - 1, $mm[1]);
                                $utc = self::PrintFixTime($trackTime);
                                $tzs = ExifTool::TimeZoneString([$mm[6], $mm[5], $mm[4], $mm[3], $mm[2] - 1, $mm[1] - 1900], $trackTime);
                                $et->VPrint(2, "  DJI start time:  $utc (local timezone is $tzs)\n");
                            } else {
                                return 'Error getting start time from file name for DJI CSV track file';
                            }
                        } elseif (preg_match('/^Date ?Time/i', $h)) {  // ExifTool addition
                            $param = 'datetime';
                        } elseif (preg_match('/^Date/i', $h)) {
                            $param = 'date';
                        } elseif (preg_match('/^Time(?! ?\(text\))/i', $h)) { // (ignore DJI "Time(text)" column)
                            $param = 'time';
                        } elseif (preg_match('/^(Pos)?Lat/i', $h)) {
                            $param = 'lat';
                            if (preg_match('/ref$/i', $h)) {
                                $param .= 'ref';
                            }
                        } elseif (preg_match('/^(Pos)?Lon/i', $h)) {
                            $param = 'lon';
                            if (preg_match('/ref$/i', $h)) {
                                $param .= 'ref';
                            }
                        } elseif (preg_match('/^(Pos)?(Alt|Height)/i', $h)) {
                            $param = 'alt';
                        } elseif (preg_match('/^Speed/i', $h)) {
                            $param = 'speed';
                            // (recognize units in brackets)
                            if (preg_match('{\((mph|km/h|m/s)\)}', $h, $mm)) {
                                $scaleSpeed = $otherConv[$mm[1]];
                                $xtra = " in {$mm[1]}";
                            } elseif ($isColumbus) { // (Columbus GPS logger)
                                $scaleSpeed = $otherConv['km/h'];
                                $xtra = ' in km/h';
                            } else {
                                $xtra = ' in knots';
                            }
                        } elseif (preg_match('/^(Angle)?(Heading|Track|Bearing)/i', $h)) {
                            $param = 'track';
                        } elseif (preg_match('/^(Angle)?Pitch/i', $h) || preg_match('/^Camera ?Elevation ?Angle/i', $h)) {
                            $param = 'pitch';
                        } elseif (preg_match('/^(Angle)?Roll/i', $h)) {
                            $param = 'roll';
                        } elseif (preg_match('/^Img ?Dir/i', $h)) {
                            $param = 'dir';
                        } elseif (self::T($userTag[strtolower($h)] ?? null)) {
                            $param = $userTag[strtolower($h)];
                        }
                        if (self::T($param)) {
                            $et->VPrint(2, "CSV column '{$head}' is $param$xtra\n");
                            $h = $param;
                        } else {
                            $et->VPrint(2, "CSV column '{$head}' ignored\n");
                            $h = '';    // ignore this column
                        }
                    }
                    unset($h);
                    continue;
                } elseif (preg_match('/"(timelineObjects|placeVisit|activitySegment|latitudeE7)"\s*:/', $line)) {
                    // Google Takeout JSON format
                    $format = 'JSON';
                    $sortFixes = 1; // (fixes are not all in order for this format)
                } elseif (preg_match('/"(durationMinutesOffsetFromStartTime|startTime)"\s*:/', $line)) {
                    $format = 'JSON';   // new Google Takeout JSON format (fixes seem to be in order)
                    $raf->Seek(0, 0);    // rewind to start of file
                } elseif (str_contains($line, "\0")) {
                    break; // (could be an image file)
                } else {
                    // search only first 50 lines of file for a valid fix
                    if (++$skipped > 50) {
                        break;
                    }
                    continue;
                }
            }
            //
            // XML format (GPX, KML, Garmin XML/TCX etc)
            //
            if ($format === 'XML') {
                $td = null;
                $args = [];
                if (preg_match('/^([^<]+<\/[^>]+>)/', $line)) {
                    // handle simple property
                    $line = preg_replace('/^\s+</', '<', $line); // remove whitespace if only whitespace before "<"
                    // Workaround for KML generated by Google Location History:
                    // lat/lon/alt are space-separated; we want commas.
                    $line = preg_replace('{(\S+)\s+(\S+)\s+(\S+)(</gx:coord>)}', '$1,$2,$3$4', $line);
                    $args[] = $line;
                } else {
                    // handle property with attributes
                    $line = preg_replace('/\s*=\s*([\'"])\s*/', '=$1', $line);   // remove unnecessary white space in attributes
                    $args = array_merge($args, Perl::split(' ', $line));
                }
                foreach ($args as $arg) {
                    $pos = 0;
                    // parse attributes (eg. GPX 'lat' and 'lon')
                    // (note: ignore namespace prefixes if they exist)
                    if (preg_match('/^(\w+:)?(\w+)=([\'"])(.*?)\3/', $arg, $am, PREG_OFFSET_CAPTURE)) {
                        $pos = $am[0][1] + strlen($am[0][0]);
                        $an = strtolower($am[2][0]);
                        $av = $am[4][0];
                        $tag = $xmlTag[$an] ?? ($userTag[$an] ?? null);
                        if (self::T($tag)) {
                            $fix[$tag] = $av;
                            if (!empty($keyCategory[$tag])) {
                                $has[$keyCategory[$tag]] = 1;
                            } elseif ($tag === 'alt') {
                                // validate altitude
                                if (($fix['alt'] ?? null) !== null && !preg_match('/^[+-]?\d+\.?\d*/', $fix['alt'])) {
                                    $fix['alt'] = null;
                                }
                                if (self::T($fix['alt'] ?? null)) {
                                    $has['alt'] = 1;   // set "has altitude" flag if appropriate
                                }
                            } elseif ($tag === 'atemp' || $tag === 'speed' || $tag === 'err') {
                                $has[$tag] = 1;
                            }
                        }
                    }
                    // loop through XML elements
                    while (preg_match('{([^<>]*)<(/)?(\w+:)?(\w+)(>|$)}', $arg, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos)) {
                        $pos = $m[0][1] + strlen($m[0][0]);
                        $g1 = $m[1][0];
                        $g2 = $m[2][0];
                        $tok = strtolower($m[4][0]);
                        $tag = $xmlTag[$tok] ?? ($userTag[$tok] ?? null);
                        // parse as a simple property if this element has a value
                        if ($tag !== null && !self::T($tag)) {
                            // a containing property was opened or closed
                            if (!self::T($g2)) {
                                // opened: start a new fix
                                $fix = [];
                                $lastFix = 'same';
                                $saveFix = [];
                                continue;
                            } elseif ($lastFix !== null && $fix) {
                                // closed: transfer additional tags from current fix
                                if ($lastFix !== 'same' && isset($points[$lastFix])) {
                                    foreach ($fix as $k => $v) {
                                        if (($points[$lastFix][$k] ?? null) === null) {
                                            $points[$lastFix][$k] = $v;
                                        }
                                    }
                                }
                                $lastFix = null;
                            }
                        }
                        if (strlen($g1)) {
                            if (self::T($tag)) {
                                if ($tag === 'coords') {
                                    // save other fixes if there are more than one
                                    if (isset($fix['lon'], $fix['lat'], $fix['alt'])) {
                                        $saveFix[] = [$fix['lon'], $fix['lat'], $fix['alt']];
                                    }
                                    // read KML "Point" coordinates
                                    $c = Perl::split('/,/', $g1) + [null, null, null];
                                    [$fix['lon'], $fix['lat'], $fix['alt']] = [$c[0], $c[1], $c[2]];
                                    if (self::T($fix['alt'])) {
                                        $has['alt'] = 1;
                                    }
                                } else {
                                    if ($tok === 'when' && self::T($fix['time'] ?? null)) {
                                        $saveTime[] = $g1; // flightaware KML stores times in array
                                    } else {
                                        $fix[$tag] = $g1;
                                    }
                                    if (!empty($keyCategory[$tag])) {
                                        $has[$keyCategory[$tag]] = 1;
                                    } elseif ($tag === 'alt') {
                                        // validate altitude
                                        if (($fix['alt'] ?? null) !== null && !preg_match('/^[+-]?\d+\.?\d*/', $fix['alt'])) {
                                            $fix['alt'] = null;
                                        }
                                        if (self::T($fix['alt'] ?? null)) {
                                            $has['alt'] = 1;   // set "has altitude" flag if appropriate
                                        }
                                    } elseif ($tag === 'atemp' || $tag === 'speed' || $tag === 'err') {
                                        $has[$tag] = 1;
                                    }
                                }
                            }
                            continue;
                        } elseif ($tok === 'td') {
                            $td = 1;
                        }
                        // validate and store GPS fix
                        if (($fix['lat'] ?? null) === null || ($fix['lon'] ?? null) === null) {
                            continue;
                        }
                        if (($fix['time'] ?? null) === null) {
                            if (!$saveTime) {
                                continue;
                            }
                            $fix['time'] = array_shift($saveTime); // get next time in flightaware KML list
                        }
                        if (!preg_match('/^[+-]?\d+\.?\d*/', $fix['lat']) || !preg_match('/^[+-]?\d+\.?\d*/', $fix['lon'])) {
                            if (!$e0) {
                                $et->VPrint(0, "Coordinate format error in $from\n");
                                $e0 = 1;
                            }
                            continue;
                        }
                        if (($time = self::GetTime($fix['time'])) === null) {
                            if (!$e1) {
                                $et->VPrint(0, "Timestamp format error in $from\n");
                                $e1 = 1;
                            }
                            continue;
                        }
                        $isDate = 1;
                        if (($fix['pdop'] ?? null) !== null || ($fix['hdop'] ?? null) !== null || ($fix['nsats'] ?? null) !== null) {
                            $canCut = 1;
                        }
                        // generate extra fixes assuming an equally spaced track
                        if (self::T($fix['begin'] ?? null)) {
                            $begin = self::GetTime($fix['begin']);
                            $fix['begin'] = null;
                            if ($begin !== null && $begin < $time) {
                                $fix['span'] = $timeSpan = ($timeSpan ?: 0) + 1;
                                // duplicate the fix if there is only one so we will have
                                // a fix and the start and end of the TimeSpan
                                if (!$saveFix) {
                                    $saveFix[] = [$fix['lon'] ?? null, $fix['lat'] ?? null, $fix['alt'] ?? null];
                                }
                                $nsf = count($saveFix);
                                for ($i = 0; $i < $nsf; ++$i) {
                                    $t = $begin + ($time - $begin) * ($i / $nsf);
                                    $f = ['lon' => $saveFix[$i][0], 'lat' => $saveFix[$i][1], 'alt' => $saveFix[$i][2]];
                                    if (!$i && self::T($points[self::K($t)] ?? null)) {
                                        $t += 0.001; // (avoid dupicates)
                                    }
                                    $f['span'] = $timeSpan;
                                    $points[self::K($t)] = $f;
                                    $fixTimes[] = $t;
                                }
                            }
                        }
                        $points[self::K($time)] = $fix;
                        if ($lastFix === 'same') {
                            $lastFix = self::K($time);
                        }
                        $fixTimes[] = $time;  // save times of all fixes in order
                        $fix = [];
                        $saveFix = [];
                        ++$numPoints;
                    }
                }
                // last ditch check KML description for timestamp (assume it is UTC)
                if ($td && !self::T($fix['time'] ?? null) &&
                    preg_match('/[\s>](\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2}(\.\d+)?)/', $line, $mm)) {
                    $fix['time'] = "{$mm[1]}T{$mm[2]}Z";
                }
                continue;
            //
            // Winplus Beacon text file
            //
            } elseif ($format === 'Winplus') {
                // TP,D, 44.933666667, -93.186555556, 10/26/2011, 19:07:28, 0
                if (!preg_match('/^TP,D,\s*([-+]?\d+\.\d*),\s*([-+]?\d+\.\d*),\s*(\d+)\/(\d+)\/(\d{4}),\s*(\d+):(\d+):(\d+)/', $line, $mm)) {
                    continue;
                }
                $fix['lat'] = $mm[1];
                $fix['lon'] = $mm[2];
                $time = ExifTool::TimeLocal_timegm($mm[8], $mm[7], $mm[6], $mm[4], $mm[3] - 1, $mm[5]);
                goto DoneFix;
            //
            // Bramor gEO log file
            //
            } elseif ($format === 'Bramor') {
                //   1 0015   18.723675   50.672752  149 169.31 22/04/2015 07:06:55 169.31    8.88   28.07 ypr
                $parts = Perl::split(' ', $line);
                if (count($parts) != 12 || $parts[11] !== 'ypr') {
                    continue;
                }
                $d = explode('/', $parts[6]);  // date (dd/mm/YYYY)
                $t = explode(':', $parts[7]);  // time (HH:MM:SS)
                if (count($d) != 3 || count($t) != 3) {
                    continue;
                }
                [$fix['lat'], $fix['lon'], $fix['alt'], $fix['track'], $fix['dir'], $fix['pitch'], $fix['roll']] =
                    [$parts[2], $parts[3], $parts[4], $parts[5], $parts[8], $parts[9], $parts[10]];
                // (add the seconds afterwards in case some models have decimal seconds)
                $time = ExifTool::TimeLocal_timegm(0, $t[1], $t[0], $d[0], $d[1] - 1, $d[2]) + Perl::num($t[2]);
                // set necessary flags for extra available information
                $has['alt'] = $has['track'] = $has['orient'] = 1;
                goto DoneFix;   // save this fix
            } elseif ($format === 'CSV') {
                Perl::chomp($line);
                $vals = self::SplitCSV($line, $csvDelim);
                //
                // CSV format output of GPS/IMU POS system (see Geotag.pm)
                //
                $date = $secs = null;
                $neg = [];
                foreach ($csvHeadings as $param) {
                    $v = array_shift($vals);
                    if ($v === null || !strlen($v)) {
                        break;
                    }
                    if (!self::T($param)) {
                        continue;
                    }
                    if ($param === 'datetime') {
                        // (fix formats like "24.07.2016 13:47:30")
                        $v = preg_replace('/^(\d{2})[^\d](\d{2})[^\d](\d{4}) /', '$3:$2:$1 ', $v);
                        $dateTime = @$et->InverseDateTime($v);
                        if ($dateTime) {
                            $date = ExifTool::GetUnixTime($v, 2);
                            $secs = 0;
                        }
                    } elseif ($param === 'date') {
                        if (preg_match('{^(\d{2})/(\d{2})/(\d{4})$}', $v, $mm)) {
                            $date = ExifTool::TimeLocal_timegm(0, 0, 0, $mm[1], $mm[2] - 1, $mm[3]);
                        } elseif (preg_match('/(\d{4}).*?(\d{2}).*?(\d{2})/', $v, $mm)) {
                            $date = ExifTool::TimeLocal_timegm(0, 0, 0, $mm[3], $mm[2] - 1, $mm[1]);
                        } elseif (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $mm)) { // (Columbus GPS logger)
                            $date = ExifTool::TimeLocal_timegm(0, 0, 0, $mm[3], $mm[2] - 1, $mm[1] + 2000);
                        }
                    } elseif ($param === 'time') {
                        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2}(\.\d+)?).*?(([-+])(\d{1,2}):?(\d{2}))?/', $v, $mm, $flags)) {
                            $secs = (($mm[1] * 60) + $mm[2]) * 60 + $mm[3];
                            // adjust for time zone if specified
                            if (self::T($mm[5])) {
                                $secs += ($mm[7] * 60 + $mm[8]) * ($mm[6] === '-' ? 60 : -60);
                            }
                        } elseif (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $mm)) { // (Columbus GPS logger)
                            $secs = (($mm[1] * 60) + $mm[2]) * 60 + $mm[3];
                        }
                    } elseif ($param === 'lat' || $param === 'lon') {
                        $fix[$param] = GPS::ToDegrees($v, 1);
                    } elseif ($param === 'latref') {
                        if (preg_match('/^S/i', $v)) {
                            $neg['lat'] = 1;
                        }
                    } elseif ($param === 'lonref') {
                        if (preg_match('/^W/i', $v)) {
                            $neg['lon'] = 1;
                        }
                    } elseif ($param === 'runtime') {
                        $date = $trackTime;
                        $secs = $v;
                    } elseif (str_starts_with($param, '_')) {
                        $fix[$param] = $v;
                    } else {
                        if (self::T($scaleSpeed) && $param === 'speed') {
                            $v = Perl::num($v) / $scaleSpeed;
                        }
                        $fix[$param] = $v;
                        if (!empty($sepTags[$param])) {
                            $has[$param] = 1;
                        }
                    }
                }
                // make coordinate negative according to reference direction if necessary
                foreach (array_keys($neg) as $param) {
                    if (($fix[$param] ?? null) === null) {
                        continue;
                    }
                    $fix[$param] = -abs(Perl::num($fix[$param]));
                }
                if (self::T($date) && $secs !== null && ($fix['lat'] ?? null) !== null && ($fix['lon'] ?? null) !== null) {
                    $time = $date + Perl::num($secs);
                    if (($fix['alt'] ?? null) !== null) {
                        $has['alt'] = 1;
                    }
                    if (($fix['track'] ?? null) !== null) {
                        $has['track'] = 1;
                    }
                    if (($fix['pitch'] ?? null) !== null) {
                        $has['orient'] = 1;
                    }
                    goto DoneFix;
                }
                continue;
            } elseif ($format === 'JSON') {
                // Google Takeout JSON format
                if (preg_match('/"(latitudeE7|longitudeE7|latE7|lngE7|timestamp|startTime|point|durationMinutesOffsetFromStartTime|time)"\s*:\s*"?(.*?)"?,?\s*[\x0d\x0a]/', $line, $mm)) {
                    if ($mm[1] === 'timestamp' || $mm[1] === 'time') {
                        $time = self::GetTime($mm[2]);
                        if (self::T($time) && self::T($fix['lat'] ?? null) && self::T($fix['lon'] ?? null)) {
                            goto DoneFix;
                        }
                    } elseif ($mm[1] === 'startTime') { // (new format)
                        $startTime = self::GetTime($mm[2]);
                    } elseif ($mm[1] === 'latitudeE7' || $mm[1] === 'latE7') {
                        $fix['lat'] = Perl::num($mm[2]) * 1e-7;
                    } elseif ($mm[1] === 'longitudeE7' || $mm[1] === 'lngE7') {
                        $fix['lon'] = Perl::num($mm[2]) * 1e-7;
                    } elseif ($mm[1] === 'point') { // (new format)
                        preg_match_all('/[-+]?\d+\.\d+/', $mm[2], $cm);
                        if (count($cm[0]) == 2) {
                            [$fix['lat'], $fix['lon']] = [$cm[0][0], $cm[0][1]];
                        }
                    } elseif ($mm[1] === 'durationMinutesOffsetFromStartTime' && $startTime !== null) { // (new format)
                        $time = $startTime + Perl::num($mm[2]) * 60;
                        // note: this assumes that "point" comes first, which it does in my sample
                        if (self::T($time) && self::T($fix['lat'] ?? null) && self::T($fix['lon'] ?? null)) {
                            goto DoneFix;
                        }
                    }
                }
                continue;
            }
            $lfix = [];
            $secs = $date = $nm = null;
            if ($format === 'NMEA') {
                // ignore unrecognized NMEA sentences
                // (first 2 characters: GP=GPS, GL=GLONASS, GA=Gallileo, GN=combined, BD=Beidou)
                if (!preg_match('/^(.*)\$([A-Z]{2}(RMC|GGA|GLL|GSA|ZDA)|PMGNTRK|PTNTHPR),/', $line, $mm, $flags)) {
                    continue;
                }
                $nm = $mm[3] ?: $mm[2];
                if (strlen($mm[1])) {
                    $line = substr($line, strlen($mm[1]));
                }
            }
            //
            // IGC (flarm) (ref 4)
            //
            if ($format === 'IGC') {
                // B0939564531208N00557021EA007670089100207
                if (!preg_match('/^B(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{3})([NS])(\d{3})(\d{2})(\d{3})([EW])([AV])(\d{5})(\d{5})/', $line, $mm)) {
                    continue;
                }
                $lfix['lat'] = ($mm[4] + ($mm[5] + $mm[6] / 1000) / 60) * ($mm[7] === 'N' ? 1 : -1);
                $lfix['lon'] = ($mm[8] + ($mm[9] + $mm[10] / 1000) / 60) * ($mm[11] === 'E' ? 1 : -1);
                $lfix['alt'] = $mm[12] === 'A' ? $mm[14] : null;
                $secs = (($mm[1] * 60) + $mm[2]) * 60 + $mm[3];
                // wrap to next day if necessary
                if (self::T($dateFlarm)) {
                    if ($secs < $lastSecs - self::JITTER) {
                        $dateFlarm += self::SEC_PER_DAY;
                    }
                    $date = $dateFlarm;
                }
                $nm = 'B';
            //
            // NMEA RMC sentence (contains date)
            //
            } elseif ($nm === 'RMC') {
                if (!preg_match('/^\$[A-Z]{2}RMC,(\d{2})(\d{2})(\d+(\.\d*)?),A?,(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),(\d*\.?\d*),(\d*\.?\d*),(\d{2})(\d{2})(\d+)/', $line, $mm, $flags)) {
                    continue;
                }
                if ($mm[13] > 31 || $mm[14] > 12 || $mm[15] > 99) {
                    continue;   // validate day/month/year
                }
                $lfix['lat'] = ((self::T($mm[5]) ? $mm[5] : 0) + $mm[6] / 60) * ($mm[7] === 'N' ? 1 : -1);
                $lfix['lon'] = ((self::T($mm[8]) ? $mm[8] : 0) + $mm[9] / 60) * ($mm[10] === 'E' ? 1 : -1);
                if (strlen($mm[11])) {
                    $lfix['speed'] = $mm[11];
                }
                if (strlen($mm[12])) {
                    $lfix['track'] = $mm[12];
                }
                $year = $mm[15] + ($mm[15] >= 70 ? 1900 : 2000);
                $secs = (($mm[1] * 60) + $mm[2]) * 60 + $mm[3];
                $date = ExifTool::TimeLocal_timegm(0, 0, 0, $mm[13], $mm[14] - 1, $year);
            //
            // NMEA GGA sentence (no date)
            //
            } elseif ($nm === 'GGA') {
                if (!preg_match('/^\$[A-Z]{2}GGA,(\d{2})(\d{2})(\d+(\.\d*)?),(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),[1-6]?,(\d+)?,(\.\d+|\d+\.?\d*)?,(-?\d+\.?\d*)?,M?/', $line, $mm, $flags)) {
                    continue;
                }
                $lfix['lat'] = ((self::T($mm[5]) ? $mm[5] : 0) + $mm[6] / 60) * ($mm[7] === 'N' ? 1 : -1);
                $lfix['lon'] = ((self::T($mm[8]) ? $mm[8] : 0) + $mm[9] / 60) * ($mm[10] === 'E' ? 1 : -1);
                [$lfix['nsats'], $lfix['hdop'], $lfix['alt']] = [$mm[11], $mm[12], $mm[13]];
                $secs = (($mm[1] * 60) + $mm[2]) * 60 + $mm[3];
                $canCut = 1;
            //
            // NMEA GLL sentence (no date)
            //
            } elseif ($nm === 'GLL') {
                if (!preg_match('/^\$[A-Z]{2}GLL,(\d*?)(\d{1,2}\.\d+),([NS]),(\d*?)(\d{1,2}\.\d+),([EW]),(\d{2})(\d{2})(\d+(\.\d*)?),A/', $line, $mm, $flags)) {
                    continue;
                }
                $lfix['lat'] = ((self::T($mm[1]) ? $mm[1] : 0) + $mm[2] / 60) * ($mm[3] === 'N' ? 1 : -1);
                $lfix['lon'] = ((self::T($mm[4]) ? $mm[4] : 0) + $mm[5] / 60) * ($mm[6] === 'E' ? 1 : -1);
                $secs = (($mm[7] * 60) + $mm[8]) * 60 + $mm[9];
            //
            // NMEA GSA sentence (satellite status, no date)
            //
            } elseif ($nm === 'GSA') {
                if (!preg_match('/^\$[A-Z]{2}GSA,[AM],([23]),((?:\d*,){11}(?:\d*)),(\d+\.?\d*|\.\d+)?,(\d+\.?\d*|\.\d+)?,(\d+\.?\d*|\.\d+)?\*/', $line, $mm, $flags)) {
                    continue;
                }
                [$lfix['fixtype'], $lfix['sats'], $lfix['pdop'], $lfix['hdop'], $lfix['vdop']] = [$mm[1].'d', $mm[2], $mm[3], $mm[4], $mm[5]];
                // count the number of acquired satellites
                $lfix['nsats'] = preg_match_all('/\d+/', $lfix['sats']);
                $canCut = 1;
            //
            // NMEA ZDA sentence (date/time, contains date)
            //
            } elseif ($nm === 'ZDA') {
                if (!preg_match('/^\$[A-Z]{2}ZDA,(\d{2})(\d{2})(\d{2}(\.\d*)?),(\d+),(\d+),(\d+)/', $line, $mm, $flags)) {
                    continue;
                }
                $secs = (($mm[1] * 60) + $mm[2]) * 60 + $mm[3];
                $date = ExifTool::TimeLocal_timegm(0, 0, 0, $mm[5], $mm[6] - 1, $mm[7]);
            //
            // Magellan eXplorist PMGNTRK (Proprietary MaGellaN TRacK) sentence (optional date)
            //
            } elseif ($nm === 'PMGNTRK') {
                if (!preg_match('/^\$PMGNTRK,(\d+)(\d{2}\.\d+),([NS]),(\d+)(\d{2}\.\d+),([EW]),(-?\d+\.?\d*),([MF]),(\d{2})(\d{2})(\d+(\.\d*)?),A,(?:[^,]*,(\d{2})(\d{2})(\d+))?/', $line, $mm, $flags)) {
                    continue;
                }
                $lfix['lat'] = ($mm[1] + $mm[2] / 60) * ($mm[3] === 'N' ? 1 : -1);
                $lfix['lon'] = ($mm[4] + $mm[5] / 60) * ($mm[6] === 'E' ? 1 : -1);
                $lfix['alt'] = $mm[8] === 'M' ? $mm[7] : $mm[7] * 12 * 0.0254;
                $secs = (($mm[9] * 60) + $mm[10]) * 60 + $mm[11];
                if ($mm[15] !== null) {
                    if ($mm[13] > 31 || $mm[14] > 12 || $mm[15] > 99) {
                        continue;   // validate day/month/year
                    }
                    // optional date is available in PMGNTRK sentence
                    $year = $mm[15] + ($mm[15] >= 70 ? 1900 : 2000);
                    $date = ExifTool::TimeLocal_timegm(0, 0, 0, $mm[13], $mm[14] - 1, $year);
                }
            //
            // Honeywell HMR3000 PTNTHPR (Heading Pitch Roll) sentence (no date)
            //
            } elseif ($nm === 'PTNTHPR') {
                if (!preg_match('/^\$PTNTHPR,(-?[\d.]+),[MNO],(-?[\d.]+),[MNO],(-?[\d.]+),[MNO]/', $line, $mm)) {
                    continue;
                }
                [$lfix['dir'], $lfix['pitch'], $lfix['roll']] = [$mm[1], $mm[2], $mm[3]];
            } else {
                continue;   // this shouldn't happen
            }
            // remember the NMEA formats we successfully read
            $nmea[$nm] = 1;
            // use last date if necessary (and appropriate)
            if ($secs !== null && $date === null && $lastDate !== null) {
                // wrap to next day if necessary
                if ($secs < $lastSecs - self::JITTER) {
                    $lastSecs -= self::SEC_PER_DAY;
                    $lastDate += self::SEC_PER_DAY;
                }
                // use earlier date only if we are within 10 seconds
                if ($secs - $lastSecs < 10) {
                    // last date is close, use it for this fix
                    $date = $lastDate;
                } else {
                    // last date is old, discard it
                    $lastDate = null;
                    $lastSecs = null;
                }
            }
            // save our last date/time
            if ($date !== null) {
                $lastDate = $date;
                $lastSecs = $secs;
            }
            //
            // Add NMEA/IGC fix to our lookup
            // (this is much more complicated than it needs to be because
            //  the stupid NMEA format provides no end-of-fix indication)
            //
            if ($nm === $nmeaStart || ($secs !== null && ($fixSecs === null ||
                // don't combine sentences that are outside 10 seconds apart
                ($secs >= $fixSecs && $secs - $fixSecs >= 10) ||
                ($secs < $fixSecs && $secs + self::SEC_PER_DAY - $fixSecs >= 10)))) {
                // start a new fix
                $fix = $lfix;
                $fixKey = null;
                $fixSecs = $secs;
                $noDateChanged = null;
                // does this fix have a date/time or time stamp?
                if ($date !== null) {
                    $fix['isDate'] = $isDate = 1;
                    $time = $date + $secs;
                } elseif ($secs !== null) {
                    $time = $secs;
                    $noDate = $noDateChanged = 1;
                } else {
                    continue;   // wait until we have a time before adding to lookup
                }
            } else {
                // add new data to existing fix (but don't overwrite earlier values to
                // keep the coordinates in sync with the fix time)
                foreach ($lfix as $k => $v) {
                    if (($fix[$k] ?? null) === null) {
                        $fix[$k] = $v;
                    }
                }
                if ($fixKey !== null && isset($points[$fixKey])) {
                    $points[$fixKey] = $fix;    // (the Points entry is the same hash in Perl)
                }
                if ($date !== null) {
                    if (self::T($fix['isDate'] ?? null)) {
                        continue;
                    }
                    // move this fix to the proper date
                    if ($fixSecs !== null) {
                        unset($points[self::K($fixSecs)]);
                        if ($fixTimes && end($fixTimes) == $fixSecs) {
                            array_pop($fixTimes);
                        }
                        --$numPoints;
                        // if we wrapped to the next day since the start of this fix,
                        // we must shift the date back to the day of $fixSecs
                        if ($secs < $fixSecs) {
                            $date -= self::SEC_PER_DAY;
                        }
                    } else {
                        $fixSecs = $secs;
                    }
                    $time = $date + $fixSecs;
                    $fix['isDate'] = $isDate = 1;
                    // revert noDate flag if it was set for this fix
                    if ($noDateChanged) {
                        $noDate = 0;
                    }
                } elseif ($secs !== null && $fixSecs === null) {
                    $time = $fixSecs = $secs;
                    $noDate = $noDateChanged = 1;
                } else {
                    continue;   // wait until we have a time
                }
            }
            // add fix to our lookup
            $points[self::K($time)] = $fix;
            $fixKey = self::K($time);
            $fixTimes[] = $time;  // save time of all fixes in order
            ++$numPoints;
            continue;

            DoneFix:
            $isDate = 1;
            $points[self::K($time)] = $fix;
            $fixTimes[] = $time;
            $fix = [];
            ++$numPoints;
        }
        //
        // If the file wasn't a recognized track log, it could be one of the file types
        // supported for reading metadata, so try to extract GPS tags from it
        //
        if (!$format) {
            $raf->Seek(0, 0);
            // load track from metadata in file
            $et2 = new ExifTool();
            $et2->Options(
                'Sort', 'File',
                'IgnoreTags', 'All',
                'RequestTags', implode(',', array_keys(self::$tagKey)),
                'PrintConv', 0,
                'KeepUTCTime', 1,
                'ExtractEmbedded', 3,
                'QuickTimeUTC', $et->Options('QuickTimeUTC'));
            if (!$et2->ExtractInfo($raf)) {
                $raf->Close();

                return "Unrecognized file format '{$val}'";
            }
            $tags = $et2->GetTagList();
            $fx = null;
            $tm = null;
            [$lastGrp, $lastTag] = ['', ''];
            if ($tags) {
                $tags[] = end($tags); // duplicate last tag to act as an end marker
                $isDate = 1;
                $sortFixes = 1; // (fixes may not be in order)
            }
            foreach ($tags as $tag) {
                $grp = $et2->GetGroup($tag, 3);
                if ($grp !== $lastGrp || $tag === $lastTag) {
                    // add fix to our lookup
                    if (self::T($tm) && self::T($fx['lat'] ?? null) && self::T($fx['lon'] ?? null)) {
                        if (isset($points[self::K($tm)])) {
                            // could possibly happen if time jumps around
                            foreach ($fx as $k => $v) {
                                $points[self::K($tm)][$k] = $v;
                            }
                        } else {
                            // convert speed to knots
                            if (self::T($fx['speed'] ?? null)) {
                                $fx['speed'] = Perl::num($fx['speed']) / self::$speedConv['K'];
                            }
                            $points[self::K($tm)] = $fx;
                            $fixTimes[] = $tm;  // save time of all fixes in order
                            ++$numPoints;
                        }
                    }
                    $lastGrp = $grp;
                    $tm = null;
                    $fx = [];
                }
                $lastTag = $tag;
                $name = preg_replace('/ .*/s', '', $tag);
                $key = self::$tagKey[$name] ?? null;
                if (!self::T($key)) {
                    if ($key === null) {
                        $et->Warn("Internal error with tag name $key $name ($tag)");
                    }
                    continue;
                }
                $tv = $et2->GetValue($tag);
                if ($key === 'time') {
                    if (!self::T($tm)) {
                        $tm = ExifTool::GetUnixTime($tv);
                    }
                } elseif (($fx[$key] ?? null) === null) {
                    $fx[$key] = $tv;
                    if (!empty($keyCategory[$key])) {
                        $has[$keyCategory[$key]] = 1;
                    }
                }
            }
        }
        $raf->Close();

        // set date flags
        if ($noDate && empty($geotag['NoDate'])) {
            if ($isDate) {
                $et->Warn('Fixes are date-less -- will use time-only interpolation');
            } else {
                $et->Warn('Some fixes are date-less -- may use time-only interpolation');
            }
            $geotag['NoDate'] = 1;
        }
        if ($isDate) {
            $geotag['IsDate'] = 1;
        }

        // cut bad fixes if necessary
        if ($isCut && $canCut) {
            $cutPDOP = $cutHDOP = $cutSats = 0;
            $goodTimes = [];
            foreach ($fixTimes as $t) {
                $k = self::K($t);
                if (!self::T($points[$k] ?? null)) {
                    continue;
                }
                $f = $points[$k];
                if (self::T($maxPDOP) && self::T($f['pdop'] ?? null) && Perl::num($f['pdop']) > $maxPDOP) {
                    unset($points[$k]);
                    ++$cutPDOP;
                } elseif (self::T($maxHDOP) && self::T($f['hdop'] ?? null) && Perl::num($f['hdop']) > $maxHDOP) {
                    unset($points[$k]);
                    ++$cutHDOP;
                } elseif (self::T($minSats) && ($f['nsats'] ?? null) !== null && $f['nsats'] !== '' &&
                    Perl::num($f['nsats']) < $minSats) {
                    unset($points[$k]);
                    ++$cutSats;
                } else {
                    $goodTimes[] = $t;
                }
            }
            $fixTimes = $goodTimes; // update fix times
            $numPoints -= $cutPDOP;
            $numPoints -= $cutHDOP;
            $numPoints -= $cutSats;
        }
        // sort fixes if necessary
        if ($sortFixes) {
            usort($fixTimes, static fn ($a, $b) => Perl::num($a) <=> Perl::num($b));
        }
        // mark first fix of the track
        while ($fixTimes) {
            $k = self::K($fixTimes[0]);
            if (!isset($points[$k])) {
                array_shift($fixTimes);
                continue;
            }
            $points[$k]['first'] = 1;
            break;
        }
        $verbose = $et->Options('Verbose');
        if ($verbose) {
            $out = $et->Options('TextOut');
            if (!$format) {
                $format = 'unknown';
            }
            fwrite($out, "Loaded $numPoints points from $format-format GPS track log $from\n");
            if ($cutPDOP) {
                fwrite($out, "Ignored $cutPDOP points due to GeoMaxPDOP cut\n");
            }
            if ($cutHDOP) {
                fwrite($out, "Ignored $cutHDOP points due to GeoMaxHDOP cut\n");
            }
            if ($cutSats) {
                fwrite($out, "Ignored $cutSats points due to GeoMinSats cut\n");
            }
            if ($numPoints && $verbose > 1) {
                $lbl = ['start:', 'end:  '];
                // (fixes may be in reverse order in GPX files)
                if (Perl::num($fixTimes[0]) > Perl::num(end($fixTimes))) {
                    $lbl = array_reverse($lbl);
                }
                fwrite($out, "  GPS track $lbl[0] ".self::PrintFixTime($fixTimes[0])."\n");
                if ($verbose > 3) {
                    foreach ($fixTimes as $t) {
                        fwrite($out, self::PrintFix($points, $t));
                    }
                }
                fwrite($out, "  GPS track $lbl[1] ".self::PrintFixTime(end($fixTimes))."\n");
            }
        }
        $geotag['Points'] = $points;
        $geotag['Has'] = $has;
        if ($numPoints) {
            // reset timestamp list to force it to be regenerated
            unset($geotag['Times']);
            // set flags for available information
            if (!empty($nmea['GGA']) || !empty($nmea['PMGNTRK']) || !empty($nmea['B'])) {
                $geotag['Has']['alt'] = 1; // alt
            }
            if (!empty($nmea['RMC'])) {
                $geotag['Has']['track'] = 1; // track, speed
            }
            if (!empty($nmea['PTNTHPR'])) {
                $geotag['Has']['orient'] = 1; // dir, pitch, roll
            }

            return $geotag;     // success!
        }

        return "No track points found in GPS $from";
    }

    //------------------------------------------------------------------------------
    // Get floating point UTC time
    // Inputs: 0) XML time string
    // Returns: floating point time or undef on error
    public static function GetTime($timeStr)
    {
        if (!preg_match('/^(\d{4})-(\d+)-(\d+)T(\d+):(\d+):(\d+)(\.\d+)?(.*)/s', Perl::str($timeStr), $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $time = ExifTool::TimeLocal_timegm($m[6], $m[5], $m[4], $m[3], $m[2] - 1, $m[1]);
        if (self::T($m[7])) {
            $time += (float) $m[7];  // add fractional seconds
        }
        $tz = $m[8];
        // adjust for time zone (otherwise assume UTC)
        // - allow timezone of +-HH:MM, +-H:MM, +-HHMM or +-HH since
        //   the spec is unclear about timezone format
        if (preg_match('/^([-+])(\d+):(\d{2})\b/', $tz, $t, PREG_UNMATCHED_AS_NULL) ||
            preg_match('/^([-+])(\d{2})(\d{2})?\b/', $tz, $t, PREG_UNMATCHED_AS_NULL)) {
            $tz = ($t[2] * 60 + (self::T($t[3]) ? $t[3] : 0)) * 60;
            if ($t[1] === '+') {
                $tz *= -1; // opposite sign to change back to UTC
            }
            $time += $tz;
        }

        return $time;
    }

    //------------------------------------------------------------------------------
    // Apply Geosync time correction
    // Inputs: 0) ExifTool ref, 1) Unix UTC time value (updated)
    // Returns: sync time difference (and updates input time), or undef if no sync
    public static function ApplySyncCorr(ExifTool $et, &$time)
    {
        $sync = $et->GetNewValue('Geosync');
        if (is_array($sync)) {
            $syncTimes = $sync['Times'] ?? null;
            if ($syncTimes) {
                // find the nearest 2 sync points
                [$i0, $i1] = [0, count($syncTimes) - 1];
                while ($i1 > $i0 + 1) {
                    $pt = (int) (($i0 + $i1) / 2);
                    if ($time < $syncTimes[$pt]) {
                        $i1 = $pt;
                    } else {
                        $i0 = $pt;
                    }
                }
                [$t0, $t1] = [$syncTimes[$i0], $syncTimes[$i1]];
                // interpolate/extrapolate to account for linear camera clock drift
                $syncPoints = $sync['Points'];
                $f = $t1 == $t0 ? 0 : ($time - $t0) / ($t1 - $t0);
                $sync = $syncPoints[self::K($t1)] * $f + $syncPoints[self::K($t0)] * (1 - $f);
            } else {
                $sync = $sync['Offset']; // use fixed time offset
            }
            $time += $sync;
        } else {
            $sync = null;
        }

        return $sync;
    }

    //------------------------------------------------------------------------------
    // Scan outwards for a fix containing the requested parameter
    // Inputs: 0) name of fix parameter, 1) list of fix times, 2) fix points hash,
    //         3) index of starting time, 4) direction to scan (-1 or +1),
    //         5) maximum time difference
    // Returns: list of 0) time for fix containing requested information (or undef)
    //          1) the corresponding fix, 2) the value of the requested fix parameter
    public static function ScanOutwards($key, $times, $points, $i, $dir, $maxSecs)
    {
        $t0 = $times[$i];
        for (;;) {
            $i += $dir;
            if ($i < 0 || $i >= count($times)) {
                break;
            }
            $t = $times[$i];
            if (abs($t - $t0) > $maxSecs) {
                break;   // don't look too far
            }
            $p = $points[self::K($t)] ?? null;
            $v = $p[$key] ?? null;
            if ($v !== null) {
                return [$t, $p, $v];
            }
        }

        return [null, null, null];
    }

    //------------------------------------------------------------------------------
    // Find nearest fix containing the specified parameter
    // Inputs: 0) ExifTool ref, 1) name of fix parameter, 2) list of fix times,
    //         3) fix points hash, 4) index of starting time,
    //         5) direction to scan (-1, +1 or undef), 6) maximum time difference
    // Returns: fix hash or undef
    public static function FindFix(ExifTool $et, $key, $times, $points, $i, $dir, $maxSecs)
    {
        $t = $p = null;
        if ($dir) {
            [$t, $p] = self::ScanOutwards($key, $times, $points, $i, $dir, $maxSecs);
        } else {
            [$t1, $p1] = self::ScanOutwards($key, $times, $points, $i, -1, $maxSecs);
            [$t2, $p2] = self::ScanOutwards($key, $times, $points, $i, 1, $maxSecs);
            if ($t1 !== null) {
                if ($t2 !== null) {
                    // both surrounding points are valid, so take the closest one
                    // (sic: Perl compares with the undefined $t here)
                    [$t, $p] = (0 - $t1 < $t2 - 0) ? [$t1, $p1] : [$t2, $p2];
                } else {
                    [$t, $p] = [$t1, $p1];
                }
            } elseif ($t2 !== null) {
                [$t, $p] = [$t2, $p2];
            }
        }
        if ($p !== null && $et->OPTIONS['Verbose'] > 2) {
            $et->VPrint(2, "  Taking $key from fix:\n", self::PrintFix($points, $t));
        }

        return $p;
    }

    //------------------------------------------------------------------------------
    // Set new geotagging values according to date/time
    // Inputs: 0) ExifTool object ref, 1) date/time value (or undef to delete tags)
    //         2) optional write group
    // Returns: error string, or '' on success
    // Notes: Uses track data stored in ExifTool NEW_VALUE for Geotag tag
    public static function SetGeoValues(ExifTool $et, $val, $writeGroup = null)
    {
        $geotag = $et->GetNewValue('Geotag');
        if ($geotag !== null && !is_array($geotag)) {
            $geotag = [];   // (a failed Geotag is set to 1 in Perl, with no track points)
        }
        $verbose = $et->Options('Verbose');
        $fix = $time = $fsec = $noDate = $secondTry = $iExt = $iDir = null;

        // remove date if none of our fixes had date information
        if (self::T($val) && $geotag && empty($geotag['IsDate'])) {
            $val = preg_replace('/^\S+\s+/', '', Perl::str($val));
        }

        // maximum time (sec) from nearest GPS fix when position is still considered valid
        $geoMaxIntSecs = $et->Options('GeoMaxIntSecs');
        $geoMaxExtSecs = $et->Options('GeoMaxExtSecs');

        // use 30 minutes for a default
        $geoMaxIntSecs ??= 1800;
        $geoMaxExtSecs ??= 1800;

        $times = $geotag['Times'] ?? null;
        $points = $geotag['Points'] ?? [];
        $has = $geotag['Has'] ?? [];
        $err = '';
        // loop to try date/time value first, then time-only value
        while ($val !== null) {
            if ($geotag === null) {
                $err = 'No GPS track loaded';
                break;
            }
            if (!$times) {
                // generate sorted timestamp list for binary search
                $times = array_map(Perl::num(...), array_keys($points));
                sort($times, SORT_NUMERIC);
            }
            if (!$times && empty($geotag['DateTimeOnly'])) {
                $err = 'GPS track is empty';
                break;
            }
            // convert date/time to UTC
            $val = Perl::str($val);
            if (preg_match('/^(\d{4}):(\d+):(\d+)\s+(\d+):(\d+):(\d+)(\.\d*)?(Z|([-+])(\d+):(\d+))?/', $val, $m, PREG_UNMATCHED_AS_NULL)) {
                // valid date/time value
                [, $year, $mon, $day, $hr, $min, $sec, $fs, $tz, $t0, $t1, $t2] = $m;
            } elseif (preg_match('/^(\d{2}):(\d+):(\d+)(\.\d*)?(Z|([-+])(\d+):(\d+))?/', $val, $m, PREG_UNMATCHED_AS_NULL)) {
                // valid time-only value
                [, $hr, $min, $sec, $fs, $tz, $t0, $t1, $t2] = $m;
                // use Jan. 2 to avoid going negative after tz adjustment
                [$year, $mon, $day] = [1970, 1, 2];
                $noDate = 1;
            } else {
                $err = 'Invalid date/time (use YYYY:mm:dd HH:MM:SS[.ss][+/-HH:MM|Z])';
                break;
            }
            if (self::T($tz)) {
                $time = ExifTool::TimeLocal_timegm($sec, $min, $hr, $day, $mon - 1, $year);
                // use timezone from date/time value
                if ($tz !== 'Z') {
                    $tzmin = $t1 * 60 + $t2;
                    $time -= ($t0 === '-' ? -$tzmin : $tzmin) * 60;
                }
            } else {
                // assume local timezone
                $time = ExifTool::TimeLocal($sec, $min, $hr, $day, $mon - 1, $year);
            }
            // add fractional seconds
            if (self::T($fs) && $fs !== '.') {
                $time += (float) $fs;
            }

            // bring UTC time back to Jan. 1 if no date is given
            // (don't use '%' operator here because it drops fractional seconds)
            if ($noDate) {
                $time -= (int) ($time / self::SEC_PER_DAY) * self::SEC_PER_DAY;
            }

            // apply time synchronization if available
            $sync = self::ApplySyncCorr($et, $time);

            // save fractional seconds string
            $fsec = preg_match('/(\.\d+)$/', Perl::str($time), $mm) ? $mm[1] : '';

            if ($et->Options('Verbose') > 1 && !$secondTry) {
                $out = $et->Options('TextOut');
                $str = '';
                if ($sync !== null) {
                    $str .= Perl::sprintf(' (incl. Geosync offset of %+.3f sec)', $sync);
                }
                if (!self::T($tz)) {
                    $tzs = ExifTool::TimeZoneString([$sec, $min, $hr, $day, $mon - 1, $year - 1900], $time);
                    $str .= " (local timezone is $tzs)";
                }
                fwrite($out, '  Geotime value:   '.self::PrintFixTime($time)."$str\n");
            }
            $nTimes = count($times);
            if (!$nTimes) {
                $fix = []; // dummy fix to geotag date/time only
            // interpolate GPS track at $time
            } elseif ($time < $times[0]) {
                if ($time < $times[0] - $geoMaxExtSecs) {
                    if ($err === '') {
                        $err = 'Time is too far before track';
                    }
                    if ($verbose > 2) {
                        $et->VPrint(2, '  Track start:     ', self::PrintFixTime($times[0]), "\n");
                    }
                    if (!empty($geotag['DateTimeOnly'])) {
                        $fix = [];
                    }
                } else {
                    $fix = $points[self::K($times[0])];
                    $iExt = 0;
                    $iDir = 1;
                    if ($verbose > 2) {
                        $et->VPrint(2, "  Taking pos from fix:\n", self::PrintFix($points, $times[0]));
                    }
                }
            } elseif ($time > $times[$nTimes - 1]) {
                if ($time > $times[$nTimes - 1] + $geoMaxExtSecs) {
                    if ($err === '') {
                        $err = 'Time is too far beyond track';
                    }
                    if ($verbose > 2) {
                        $et->VPrint(2, '  Track end:       ', self::PrintFixTime($times[$nTimes - 1]), "\n");
                    }
                    if (!empty($geotag['DateTimeOnly'])) {
                        $fix = [];
                    }
                } else {
                    $fix = $points[self::K($times[$nTimes - 1])];
                    $iExt = $nTimes - 1;
                    $iDir = -1;
                    if ($verbose > 2) {
                        $et->VPrint(2, "  Taking pos from fix:\n", self::PrintFix($points, $times[$nTimes - 1]));
                    }
                }
            } else {
                // find nearest 2 points in time
                [$i0, $i1] = [0, $nTimes - 1];
                while ($i1 > $i0 + 1) {
                    $pt = (int) (($i0 + $i1) / 2);
                    if ($time < $times[$pt]) {
                        $i1 = $pt;
                    } else {
                        $i0 = $pt;
                    }
                }
                // do linear interpolation for position
                $t0 = $times[$i0];
                $t1 = $times[$i1];
                $p1 = $points[self::K($t1)];
                // check to see if we are extrapolating before the first entry in a track
                $maxSecs = (self::T($p1['first'] ?? null) && $geoMaxIntSecs) ? $geoMaxExtSecs : $geoMaxIntSecs;
                // find time of nearest fix
                if ($time - $t0 < $t1 - $time) {
                    $tn = $t0;
                    $iExt = $i0;
                } else {
                    $tn = $t1;
                    $iExt = $i1;
                }
                // don't interpolate if fixes are too far apart
                // (but always interpolate fixes inside the same TimeSpan)
                $p0 = $points[self::K($t0)];
                if ($t1 - $t0 > $maxSecs && (!self::T($p1['span'] ?? null) || !self::T($p0['span'] ?? null) ||
                    $p1['span'] != $p0['span'])) {
                    // treat as an extrapolation -- use nearest fix if close enough
                    if (abs($time - $tn) > $geoMaxExtSecs) {
                        if ($err === '') {
                            $err = 'Time is too far from nearest GPS fix';
                        }
                        if ($verbose > 2) {
                            $et->VPrint(2, '  Nearest fix:     ', self::PrintFixTime($tn), ' (',
                                (int) abs($time - $tn), " sec away)\n");
                        }
                        if (!empty($geotag['DateTimeOnly'])) {
                            $fix = [];
                        }
                    } else {
                        $fix = $points[self::K($tn)];
                        if ($verbose > 2) {
                            $et->VPrint(2, "  Taking pos from fix:\n", self::PrintFix($points, $tn));
                        }
                    }
                } else {
                    $f0 = $t1 == $t0 ? 0 : ($time - $t0) / ($t1 - $t0);
                    if ($verbose > 2) {
                        $et->VPrint(2, '  Interpolating between fixes (f='.Perl::str($f0)."):\n",
                            self::PrintFix($points, $t0, $t1));
                    }
                    $fix = [];
                    // copy user-defined tags from nearest fix
                    foreach (array_values(self::$userTag) as $k) {
                        $fix[$k] = $points[self::K($tn)][$k] ?? null;
                    }
                    // loop through available fix information categories
                    // (pos, track, alt, orient)
                    foreach (['pos', 'track', 'alt', 'orient', 'atemp', 'err', 'dop'] as $category) {
                        if (!self::T($has[$category] ?? null)) {
                            continue;
                        }
                        $f = $p0b = $p1b = $f0b = null;
                        // loop through specific fix information keys
                        // (lat, lon, alt, track, speed, dir, pitch, roll)
                        foreach (self::$fixInfoKeys[$category] as $key) {
                            $v0 = $p0[$key] ?? null;
                            $v1 = $p1[$key] ?? null;
                            if ($v0 !== null && $v1 !== null) {
                                $f = $f0;
                            } elseif ($f0b !== null) {
                                $v0 = $p0b[$key] ?? null;
                                $v1 = $p1b[$key] ?? null;
                                if ($v0 === null || $v1 === null) {
                                    continue;
                                }
                                $f = $f0b;
                            } else {
                                if (!empty(self::$sepTags[$key])) {
                                    continue; // (don't scan outwards for some formats, eg. CSV)
                                }
                                // scan outwards looking for fixes with the required information
                                // (NOTE: SHOULD EVENTUALLY DO THIS FOR EXTRAPOLATION TOO!)
                                if ($v0 !== null) {
                                    $t0b = $t0;
                                    $p0b = $p0;
                                } else {
                                    [$t0b, $p0b, $v0] = self::ScanOutwards($key, $times, $points, $i0, -1, $maxSecs);
                                    if ($t0b === null) {
                                        continue 2;
                                    }
                                }
                                if ($v1 !== null) {
                                    $t1b = $t1;
                                    $p1b = $p1;
                                } else {
                                    [$t1b, $p1b, $v1] = self::ScanOutwards($key, $times, $points, $i1, 1, $maxSecs);
                                    if ($t1b === null) {
                                        continue 2;
                                    }
                                }
                                // re-calculate the interpolation factor
                                $f = $f0b = $t1b == $t0b ? 0 : ($time - $t0b) / ($t1b - $t0b);
                                if ($verbose > 2) {
                                    $et->VPrint(2, "  Interpolating $category between fixes (f=".Perl::str($f)."):\n",
                                        self::PrintFix($points, $t0b, $t1b));
                                }
                            }
                            $v0 = Perl::num($v0);
                            $v1 = Perl::num($v1);
                            // must interpolate cyclical values differently
                            if (!empty(self::$cyclical[$key]) && abs($v1 - $v0) > 180) {
                                // the acute angle spans the discontinuity, so add
                                // 360 degrees to the smaller angle before interpolating
                                if ($v0 < $v1) {
                                    $v0 += 360;
                                } else {
                                    $v1 += 360;
                                }
                                $fix[$key] = $v1 * $f + $v0 * (1 - $f);
                                // some ranges are -180 to 180, others are 0 to 360
                                $max = !empty(self::$cyc180[$key]) ? 180 : 360;
                                if ($fix[$key] >= $max) {
                                    $fix[$key] -= 360;
                                }
                            } else {
                                // simple linear interpolation
                                $fix[$key] = $v1 * $f + $v0 * (1 - $f);
                            }
                        }
                    }
                }
            }
            if ($fix !== null) {
                $err = '';  // success!
            } elseif (!empty($geotag['NoDate']) && !$noDate && preg_match('/^\S+\s+/', $val)) {
                // try again with no date since some of our track points are date-less
                $val = preg_replace('/^\S+\s+/', '', $val);
                $secondTry = 1;
                continue;
            }
            break;
        }
        if ($fix !== null) {
            $gpsDate = $gpsAlt = $gpsAltRef = null;
            $t = ExifTool::PerlGmtime((int) $time);
            $gpsTime = Perl::sprintf('%.2d:%.2d:%.2d', $t[2], $t[1], $t[0]).$fsec;
            // write GPSDateStamp if date included in track log, otherwise delete it
            if (!$noDate) {
                $gpsDate = Perl::sprintf('%.2d:%.2d:%.2d', $t[5] + 1900, $t[4] + 1, $t[3]);
            }
            // write GPSAltitude tags if altitude included in track log, otherwise delete them
            $alt = $fix['alt'] ?? null;
            if ($alt === null && self::T($has['alt'] ?? null) && $iExt !== null) {
                $tFix = self::FindFix($et, 'alt', $times, $points, $iExt, $iDir, $geoMaxExtSecs);
                if ($tFix !== null) {
                    $alt = $tFix['alt'] ?? null;
                }
            }
            // set new GPS tag values (EXIF, or XMP if write group is 'xmp')
            $xmp = $exif = $qt = false;
            $opts = ['Type' => 'ValueConv']; // write ValueConv values
            if (self::T($writeGroup)) {
                $opts['Group'] = $writeGroup;
                $xmp = (bool) preg_match('/xmp/i', $writeGroup);
                $exif = (bool) preg_match('/^(exif|gps)$/i', $writeGroup);
                $qt = (bool) preg_match('/^(quicktime|keys|itemlist|userdata)$/i', $writeGroup);
            }
            // set QuickTime GPSCoordinates
            $coords = self::S($fix['lat'] ?? null).' '.self::S($fix['lon'] ?? null);
            if ($alt !== null) {
                $gpsAlt = abs(Perl::num($alt));
                $gpsAltRef = (Perl::num($alt) < 0 ? 1 : 0);
                $coords .= ' '.Perl::str($alt);
            }
            $et->SetNewValueList('GPSCoordinates', $coords, ...$opts);
            // also Geolocate if specified
            $nvHash = null;
            $geoloc = $et->GetNewValue('Geolocate', $nvHash);
            if (self::T($geoloc) && preg_match('/\bgeotag\b/i', $geoloc)) {
                $wg = $nvHash->WantGroup ?? null;
                $tag = (self::T($wg) ? "$wg:" : '').'Geolocate';
                // pass along any regular expressions to qualify geolocation search
                $parms = implode(',', array_filter(Perl::split('/\s*,\s*/', $geoloc), static fn ($s) => str_contains($s, '/')));
                if ($parms !== '') {
                    $parms = ",$parms,both";
                }
                $et->SetNewValue($tag, self::S($fix['lat'] ?? null).','.self::S($fix['lon'] ?? null).$parms);
                // (the Geolocate tag will be restored to its original value
                // by RestoreNewValues before the next file in batch processing)
            }
            if ($qt) {
                return $err; // all done if writing to QuickTime only
            }
            // (capture error messages by calling SetNewValue in list context)
            $et->SetNewValueList('GPSLatitude', self::S($fix['lat'] ?? null), ...$opts);
            $et->SetNewValueList('GPSLongitude', self::S($fix['lon'] ?? null), ...$opts);
            $et->SetNewValueList('GPSAltitude', self::S($gpsAlt), ...$opts);
            $et->SetNewValueList('GPSAltitudeRef', self::S($gpsAltRef), ...$opts);
            if (self::T($has['track'] ?? null) || self::T($has['speed'] ?? null)) {
                $type = self::T($has['track'] ?? null) ? 'track' : 'speed';
                $tFix = $fix;
                if (($fix[$type] ?? null) === null && $iExt !== null) {
                    $p = self::FindFix($et, $type, $times, $points, $iExt, $iDir, $geoMaxExtSecs);
                    if ($p !== null) {
                        $tFix = $p;
                    }
                }
                $et->SetNewValueList('GPSTrack', self::S($tFix['track'] ?? null), ...$opts);
                $et->SetNewValueList('GPSTrackRef', (($tFix['track'] ?? null) !== null ? 'T' : null), ...$opts);
                $ref = null;
                if (($spd = $tFix['speed'] ?? null) !== null) {
                    // convert to specified units if necessary
                    $ref = $et->OPTIONS['GeoSpeedRef'] ?? null;
                    if (self::T($ref) && isset(self::$speedConv[$ref])) {
                        if (isset(self::$speedConv[self::$speedConv[$ref]])) {
                            $ref = self::$speedConv[$ref];
                        }
                        $spd = Perl::num($spd) * self::$speedConv[$ref];
                    } else {
                        $ref = 'N';     // knots by default
                    }
                }
                $et->SetNewValueList('GPSSpeed', self::S($spd), ...$opts);
                $et->SetNewValueList('GPSSpeedRef', $ref, ...$opts);
            }
            if (self::T($has['orient'] ?? null)) {
                $tFix = $fix;
                if (($fix['dir'] ?? null) === null && $iExt !== null) {
                    $p = self::FindFix($et, 'dir', $times, $points, $iExt, $iDir, $geoMaxExtSecs);
                    if ($p !== null) {
                        $tFix = $p;
                    }
                }
                $et->SetNewValueList('GPSImgDirection', self::S($tFix['dir'] ?? null), ...$opts);
                $et->SetNewValueList('GPSImgDirectionRef', (($tFix['dir'] ?? null) !== null ? 'T' : null), ...$opts);
                $et->SetNewValueList('CameraElevationAngle', self::S($tFix['pitch'] ?? null), ...$opts);
                // Note: GPSPitch and GPSRoll are non-standard, and must be user-defined
                $et->SetNewValueList('GPSPitch', self::S($tFix['pitch'] ?? null), ...$opts);
                $et->SetNewValueList('GPSRoll', self::S($tFix['roll'] ?? null), ...$opts);
            }
            if (self::T($has['atemp'] ?? null)) {
                $tFix = $fix;
                if (($fix['atemp'] ?? null) === null && $iExt !== null) {
                    // (not all fixes have atemp, so try interpolating specifically for this)
                    $p = self::FindFix($et, 'atemp', $times, $points, $iExt, $iDir, $geoMaxExtSecs);
                    if ($p !== null) {
                        $tFix = $p;
                    }
                }
                $et->SetNewValueList('AmbientTemperature', self::S($tFix['atemp'] ?? null), ...$opts);
            }
            if (self::T($has['err'] ?? null)) {
                $et->SetNewValueList('GPSHPositioningError', self::S($fix['err'] ?? null), ...$opts);
            }
            if (self::T($has['dop'] ?? null)) {
                $dop = $mm = null;
                if (($fix['pdop'] ?? null) !== null) {
                    $dop = $fix['pdop'];
                    $mm = 3;
                } elseif (($fix['hdop'] ?? null) !== null) {
                    if (($fix['vdop'] ?? null) !== null) {
                        $dop = sqrt(Perl::num($fix['hdop']) * Perl::num($fix['hdop']) + Perl::num($fix['vdop']) * Perl::num($fix['vdop']));
                        $mm = 3;
                    } else {
                        $dop = $fix['hdop'];
                        $mm = 2;
                    }
                }
                if ($dop !== null) {
                    $et->SetNewValue('GPSMeasureMode', self::S($mm), ...$opts);
                    $et->SetNewValue('GPSDOP', self::S($dop), ...$opts);
                    // also set GPSHPositioningError if specified
                    $hposErr = $et->OPTIONS['GeoHPosErr'] ?? null;
                    if (self::T($hposErr)) {
                        $hposErr = preg_replace('/gpsdop/i', 'GPSDOP', $hposErr, 1);
                        try {
                            $hposErr = self::EvalGPSDOP($hposErr, $dop);
                            $et->SetNewValue('GPSHPositioningError', $hposErr === null ? null : self::S($hposErr), ...$opts);
                        } catch (\Throwable $e) {
                            $et->Warn('Error calculating GPSHPositioningError: '.ExifTool::CleanWarning($e->getMessage()), 1);
                        }
                    }
                }
            }
            if (!$xmp) {
                $latRef = $lonRef = null;
                if (($fix['lat'] ?? null) !== null) {
                    $latRef = Perl::num($fix['lat']) > 0 ? 'N' : 'S';
                }
                if (($fix['lon'] ?? null) !== null) {
                    $lonRef = Perl::num($fix['lon']) > 0 ? 'E' : 'W';
                }
                $et->SetNewValueList('GPSLatitudeRef', $latRef, ...$opts);
                $et->SetNewValueList('GPSLongitudeRef', $lonRef, ...$opts);
                $et->SetNewValueList('GPSDateStamp', $gpsDate, ...$opts);
                $et->SetNewValueList('GPSTimeStamp', $gpsTime, ...$opts);
                // set options to edit XMP:GPSDateTime only if it already exists
                $opts['EditOnly'] = 1;
                $opts['Group'] = 'XMP';
            }
            if (!$exif) {
                $et->SetNewValueList('GPSDateTime', "$gpsDate $gpsTime", ...$opts);
            }
            // set user-defined tags
            $ut = array_values(self::$userTag);
            sort($ut, SORT_STRING);
            foreach ($ut as $k) {
                if (($fix[$k] ?? null) !== null) {
                    $et->SetNewValueList(substr($k, 1), self::S($fix[$k]));
                }
            }
        } else {
            $opts = ['IgnorePermanent' => 1];
            if ($val !== null) {
                $opts['Replace'] = 2; // remove existing new values
            }
            // reset user-defined GPX tags
            self::InitUserTags($et);  // (won't be set yet because we didn't read a GPX file)
            foreach (array_values(self::$userTag) as $k) {
                $et->SetNewValueList(substr($k, 1), null, ...$opts);
            }
            if (self::T($writeGroup)) {
                $opts['Group'] = $writeGroup;
            }
            // reset any GPS values we might have already set
            foreach (['GPSLatitude', 'GPSLatitudeRef', 'GPSLongitude', 'GPSLongitudeRef',
                'GPSAltitude', 'GPSAltitudeRef', 'GPSDateStamp', 'GPSTimeStamp', 'GPSDateTime',
                'GPSTrack', 'GPSTrackRef', 'GPSSpeed', 'GPSSpeedRef', 'GPSImgDirection',
                'GPSImgDirectionRef', 'GPSPitch', 'GPSRoll', 'CameraElevationAngle',
                'AmbientTemperature', 'GPSHPositioningError', 'GPSCoordinates',
                'GPSMeasureMode', 'GPSDOP'] as $k) {
                $et->SetNewValueList($k, null, ...$opts);
            }
        }

        return $err;
    }

    //------------------------------------------------------------------------------
    // Convert Geotagging time synchronization value
    // Inputs: 0) exiftool object ref,
    //         1) time difference string ("[+-]DD MM:HH:SS.ss"), geosync'd file name,
    //            "GPSTIME@IMAGETIME", or "GPSTIME@FILENAME"
    // Returns: geosync hash: Offset, Points, Times (see Geotag.pm)
    public static function ConvertGeosync(ExifTool $et, $val)
    {
        $val = Perl::str($val);
        $sync = $et->GetNewValue('Geosync') ?: [];
        if (!is_array($sync)) {
            $sync = [];
        }
        $syncFile = $gpsTime = $imgTime = null;

        if (preg_match('/^(.*?)@(.*)/', $val, $m)) {
            $gpsTime = $m[1];
            if (is_file($m[2])) {
                $syncFile = $m[2];
            } else {
                $imgTime = $m[2];
            }
        // (take care because "-f '1:30'" crashes ActivePerl 5.10)
        } elseif (!preg_match('/^\d/', $val) || !str_contains($val, ':')) {
            if (is_file($val)) {
                $syncFile = $val;
            }
        }
        if (self::T($gpsTime) || $syncFile !== null) {
            // (this is a time synchronization vector)
            if ($syncFile !== null) {
                // check the following tags in order to obtain the image timestamp
                $timeTags = ['SubSecDateTimeOriginal', 'SubSecCreateDate', 'SubSecModifyDate',
                    'DateTimeOriginal', 'CreateDate', 'ModifyDate', 'FileModifyDate'];
                $info = (new ExifTool())->ImageInfo($syncFile, ['PrintConv' => 0], ...$timeTags, ...['GPSDateTime', 'GPSTimeStamp']);
                if (isset($info['Error'])) {
                    trigger_error(Perl::str($info['Err'] ?? '')."\n", E_USER_WARNING);

                    return null;
                }
                if (!self::T($gpsTime)) {
                    $gpsTime = self::T($info['GPSDateTime'] ?? null) ? $info['GPSDateTime'] : ($info['GPSTimeStamp'] ?? null);
                    if (self::T($gpsTime) && !self::T($info['GPSDateTime'] ?? null)) {
                        $gpsTime .= 'Z';
                    }
                }
                if (!self::T($gpsTime)) {
                    trigger_error("No GPSTimeStamp in '$syncFile\n", E_USER_WARNING);

                    return null;
                }
                foreach ($timeTags as $tag) {
                    if (self::T($info[$tag] ?? null)) {
                        $imgTime = $info[$tag];
                        $et->VPrint(2, "Geosyncing with $tag from '{$syncFile}'\n");
                        break;
                    }
                }
                if (!self::T($imgTime)) {
                    trigger_error("No image timestamp in '{$syncFile}'\n", E_USER_WARNING);

                    return null;
                }
            }
            $gpsTime = Perl::str($gpsTime);
            $imgTime = Perl::str($imgTime);
            // add date to date-less timestamps
            $noDate = null;
            if (preg_match('/^(\d+:\d+:\d+)\s+\d+/', $imgTime, $m)) {
                $imgDateTime = $imgTime;
                $date = $m[1];
                if (preg_match('/^\d+:\d+:\d+\s+\d+/', $gpsTime)) {
                    $gpsDateTime = $gpsTime;
                } else {
                    $gpsDateTime = "$date $gpsTime";
                }
            } elseif (preg_match('/^(\d+:\d+:\d+)\s+\d+/', $gpsTime, $m)) {
                $imgDateTime = "{$m[1]} $imgTime";
                $gpsDateTime = $gpsTime;
            } else {
                // use a today's date (so hopefully the DST setting will be intuitive)
                $date = date('Y:m:d');
                $gpsDateTime = "$date $gpsTime";
                $imgDateTime = "$date $imgTime";
                $noDate = 1;
            }
            // calculate Unix seconds since the epoch
            $imgSecs = ExifTool::GetUnixTime($imgDateTime, 1);
            if ($imgSecs === null) {
                trigger_error("Invalid image time '{$imgTime}'\n", E_USER_WARNING);

                return null;
            }
            $gpsSecs = ExifTool::GetUnixTime($gpsDateTime, 1);
            if ($gpsSecs === null) {
                trigger_error("Invalid GPS time '{$gpsTime}'\n", E_USER_WARNING);

                return null;
            }
            // add fractional seconds
            if (preg_match('/(\.\d+)/', $gpsTime, $m)) {
                $gpsSecs += (float) $m[1];
            }
            if (preg_match('/(\.\d+)/', $imgTime, $m)) {
                $imgSecs += (float) $m[1];
            }
            // shift dates within 12 hours of each other if either timestamp was date-less
            if ($gpsDateTime !== $gpsTime || $imgDateTime !== $imgTime) {
                $diff = Perl::mod($imgSecs - $gpsSecs, 24 * 3600);
                if ($diff > 12 * 3600) {
                    $diff -= 24 * 3600;
                }
                if ($diff < -12 * 3600) {
                    $diff += 24 * 3600;
                }
                if ($gpsDateTime !== $gpsTime) {
                    $gpsSecs = $imgSecs - $diff;
                } else {
                    $imgSecs = $gpsSecs + $diff;
                }
            }
            // save the synchronization offset
            $sync['Offset'] = $gpsSecs - $imgSecs;
            // save this synchronization point if either timestamp had a date
            if (!$noDate) {
                $sync['Points'][self::K($imgSecs)] = $sync['Offset'];
                // print verbose output
                if ($et->Options('Verbose') > 1) {
                    // print GPS and image timestamps in UTC
                    $et->VPrint(1, "Added Geosync point:\n",
                        '  GPS time stamp:  ', self::PrintFixTime($gpsSecs), "\n",
                        '  Image date/time: ', self::PrintFixTime($imgSecs), "\n");
                }
                // save sorted list of image sync times if we have more than one
                $times = array_map(Perl::num(...), array_keys($sync['Points']));
                if (count($times) > 1) {
                    sort($times, SORT_NUMERIC);
                    $sync['Times'] = $times;
                }
            }
        } else {
            // (this is a simple time difference)
            preg_match_all('/(?=\d|\.\d)\d*(?:\.\d*)?/', $val, $mv);
            $vals = $mv[0];
            if (!$vals) {
                trigger_error("Invalid value (please refer to geotag documentation)\n", E_USER_WARNING);

                return null;
            }
            $secs = 0;
            foreach ([1, 60, 3600, self::SEC_PER_DAY] as $mult) {
                $secs += $mult * Perl::num(array_pop($vals));
                if (!$vals) {
                    break;
                }
            }
            // set constant sync offset
            $sync['Offset'] = preg_match('/^\s*-/', $val) ? -$secs : $secs;
        }

        return $sync;
    }

    //------------------------------------------------------------------------------
    // Print fix time
    // Inputs: 0) time since the epoch
    // Returns: UTC time string with fractional seconds
    public static function PrintFixTime($time)
    {
        return ExifTool::ConvertUnixTime($time, null, 3).' UTC';
    }

    //------------------------------------------------------------------------------
    // Print fix information
    // Inputs: 0) lookup for all fix points, 1-n) list of fix times
    // Returns: fix string (including leading indent and trailing newline)
    public static function PrintFix($points, ...$times)
    {
        $str = '';
        foreach ($times as $time) {
            $str .= '    '.self::PrintFixTime($time).' -';
            $fix = $points[self::K($time)] ?? null;
            if ($fix) {
                $keys = array_map('strval', array_keys($fix));
                sort($keys, SORT_STRING);
                foreach ($keys as $k) {
                    if ($k !== 'time' && ($fix[$k] ?? null) !== null) {
                        $str .= " $k=".Perl::str($fix[$k]);
                    }
                }
            }
            $str .= "\n";
        }

        return $str;
    }

    //------------------------------------------------------------------------------
    // Initialize %userTag for reading user-defined GPX tags
    // Inputs: 0) ExifTool ref
    public static function InitUserTags(ExifTool $et)
    {
        self::$userTag = [];
        $opt = $et->OPTIONS['GeoUserTag'] ?? null;
        if (self::T($opt)) {
            foreach (Perl::split('/\s*,\s*/', $opt) as $s) {
                if (!preg_match('/^(.+)=(.+)$/', $s, $m)) {
                    continue;
                }
                if (self::T(self::$xmlTag[strtolower($m[2])] ?? null)) {
                    $et->Warn("User-defined GPX tag '{$m[2]}' conflicts with existing tag");
                    continue;
                }
                self::$userTag[strtolower($m[2])] = "_{$m[1]}";    // (leading underline prevents conflicts)
            }
        }
    }

    // (PHP) eval a GeoHPosErr Perl expression: translated token by token to PHP (no Perl interpreter
    // exists).  Supported: $GPSDOP, numbers, quoted strings, arithmetic, ?: , && || ! // , eq ne lt gt le ge,
    // . concatenation, undef and the numeric/string builtins listed below.  Anything else throws.
    // ponytail: no regexes, lists, statements or other variables; extend the token map if needed
    private static function EvalGPSDOP(string $code, $dop)
    {
        $fn = ['sprintf' => 'sprintf', 'int' => 'intval', 'abs' => 'abs', 'sqrt' => 'sqrt', 'log' => 'log', 'exp' => 'exp', 'sin' => 'sin',
            'cos' => 'cos', 'atan2' => 'atan2', 'hex' => 'hexdec', 'oct' => 'octdec', 'length' => 'strlen', 'uc' => 'strtoupper', 'lc' => 'strtolower'];
        $word = ['eq' => '==', 'ne' => '!=', 'lt' => '<', 'gt' => '>', 'le' => '<=', 'ge' => '>=', 'and' => '&&', 'or' => '||', 'not' => '!'];
        $php = '';
        for ($p = 0, $n = strlen($code); $p < $n;) {
            if (preg_match('/\G\s+/', $code, $m, 0, $p)) {
                $php .= ' ';
            } elseif (preg_match('/\G(?:\d+\.?\d*(?:[eE][-+]?\d+)?|\.\d+(?:[eE][-+]?\d+)?|0[xX][\da-fA-F]+)(?![\w.])/', $code, $m, 0, $p)) {
                $php .= $m[0];
            } elseif (preg_match('/\G\$GPSDOP\b/', $code, $m, 0, $p)) {
                $php .= '$GPSDOP';
            } elseif (preg_match('/\G\'([^\'\\\\]*)\'/', $code, $m, 0, $p)) {
                $php .= var_export($m[1], true);
            } elseif (preg_match('/\G"([^"\\\\$@]*)"/', $code, $m, 0, $p)) {
                $php .= var_export($m[1], true);
            } elseif (preg_match('/\G(undef|[a-z]\w*)\b/', $code, $m, 0, $p)) {
                $w = $m[1];
                if ($w === 'undef') {
                    $php .= 'null';
                } elseif (isset($word[$w])) {
                    $php .= $word[$w];
                } elseif (isset($fn[$w])) {
                    $php .= $fn[$w];
                } else {
                    throw new \RuntimeException("Unsupported Perl code '$w'");
                }
            } elseif (preg_match('/\G(?:\*\*|\/\/|&&|\|\||==|!=|<=|>=|[-+*\/%.?:!<>(),])/', $code, $m, 0, $p)) {
                $php .= $m[0] === '//' ? '??' : ($m[0] === '.' ? ' . ' : $m[0]);
            } else {
                throw new \RuntimeException("syntax error near '".substr($code, $p, 10)."'");
            }
            $p += strlen($m[0]);
        }
        $GPSDOP = Perl::num($dop);
        return eval("return ($php);");
    }
}
