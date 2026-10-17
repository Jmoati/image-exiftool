<?php

//------------------------------------------------------------------------------
// File:         Geolocation.pm
//
// Description:  Determine geolocation from GPS and visa-versa
//
// Revisions:    2024-03-03 - P. Harvey Created
//               2024-03-21 - PH Significant restructuring and addition of
//                            several new features.
//
// References:   https://download.geonames.org/export/
//
// Notes:        Set $Image::ExifTool::Geolocation::geoDir to override the
//               default directory containing the database file Geolocation.dat
//               and the GeoLang directory with the alternate language files.
//               If set, this directory is
//
//               AltNames.dat may be loaded from a different directory by
//               specifying $Image::ExifTool::Geolocation::altDir.  This
//               database and has entries in the same order as Geolocation.dat,
//               and each entry is a newline-separated list of alternate names
//               terminated by a null byte.  These alternate names are used
//               only when searching for a city by name (eg. "Big Apple").
//
//               Databases are based on data from geonames.org with a
//               Creative Commons license, reformatted as follows in the
//               Geolocation.dat file:
//
//   Header:
//       "GeolocationV.VV\tNNNN\n"  (V.VV=version, NNNN=num city entries)
//       "# <comment>\n"
//   NNNN City entries:
//     Offset Format   Description
//        0   int16u - latitude high 16 bits (converted to 0-0x100000 range)
//        2   int8u  - latitude low 4 bits, longitude low 4 bits
//        3   int16u - longitude high 16 bits
//        5   int8u  - index of country in country list
//        6   int8u  - 0xf0 = population E exponent (in format "N.Fe+0E"), 0x0f = population N digit
//        7   int16u - 0xf000 = population F digit, 0x0fff = index in region list (admin1)
//        9   int16u - v1.02: 0x7fff = index in subregion (admin2), 0x8000 = high bit of time zone
//        9   int16u - v1.03: index in subregion (admin2)
//       11   int8u  - low byte of time zone index
//       12   int8u  - 0x3f = feature code index (see below), v1.03: 0x80 = high bit of time zone
//       13   string - UTF8 City name, terminated by newline
//   "\0\0\0\0\x01"
//   Country entries:
//       1. 2-character country code
//       2. Country name, terminated by newline
//   "\0\0\0\0\x02"
//   Region entries:
//       1. Region name, terminated by newline
//   "\0\0\0\0\x03"
//   Subregion entries:
//       1. Subregion name, terminated by newline
//   "\0\0\0\0\x04"
//   Time zone entries:
//       1. Time zone name, terminated by newline
//   "\0\0\0\0\x05" (feature codes added in v1.03)
//   Feature codes:
//       1. Feature code, optional space-followed-by-feature-name, then newline
//   "\0\0\0\0\0"
//
// Feature Codes v1.02: (see http://www.geonames.org/export/codes.html#P for descriptions)
//
//       0. Other    3. PPLA2    6. PPLA5    9. PPLF    12. PPLR   15. PPLX
//       1. PPL      4. PPLA3    7. PPLC    10. PPLG    13. PPLS
//       2. PPLA     5. PPLA4    8. PPLCH   11. PPLL    14. STLMT
//
// PHP port of Image::ExifTool::Geolocation 1.10. Geolocation.dat (ExifTool 13.59) sits next
// to this file. The module load-time code runs on first use (Load()), so that $geoDir may be
// set before, as in Perl.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class Geolocation
{
    // (Perl package variables)
    public static $geoDir = null;
    public static $altDir = null;
    public static $dbInfo = null;

    private static bool $loaded = false;
    private static array $cityList = [], $countryList = [], $regionList = [], $subregionList = [], $timezoneList = [];
    private static array $countryNum = [], $regionNum = [], $subregionNum = [], $timezoneNum = []; // reverse lookups
    private static array $sortOrder = [], $altNames = [], $featureTypes = [];
    private static $nCity = null;
    private static array $featureCodesH = []; // (Perl %featureCodes: lower-case code => index)
    private static $lastArgs = null;
    private static array $lastFound = [], $lastByPop = [], $lastByLat = []; // cached city matches
    private static $dbVer = '1.03';         // database version number
    private static $sortedBy = 'Latitude';
    private static $pi = 3.1415926536;
    private static $earthRadius = 6371;     // earth radius in km
    // hard-coded feature codes for v1.02 database
    private static array $featureCodes = ['Other', 'PPL', 'PPLA', 'PPLA2', 'PPLA3', 'PPLA4', 'PPLA5', 'PPLC',
                                          'PPLCH', 'PPLF', 'PPLG', 'PPLL', 'PPLR', 'PPLS', 'STLMT', 'PPLX'];

    //------------------------------------------------------------------------------
    // Module load-time code (Perl: require Image::ExifTool::Geolocation)
    public static function Load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        // get path name for database file from lib/Image/ExifTool/Geolocation.dat by default,
        // or according to $Image::ExifTool::Geolocation::directory if specified
        $defaultDir = __DIR__;

        // read the Geolocation database unless $geoDir set to empty string
        if (!(self::$geoDir !== null and !self::$geoDir)) {
            if (!(self::$geoDir and self::ReadDatabase(self::$geoDir . '/Geolocation.dat'))) {
                self::ReadDatabase("$defaultDir/Geolocation.dat");
            }
        }

        // set directory for language files and alternate names
        if (self::$geoDir === null) self::$geoDir = $defaultDir;
        if (self::$altDir === null and self::$geoDir and file_exists(self::$geoDir . '/AltNames.dat')) {
            self::$altDir = self::$geoDir;
        }

        // add user-defined entries to the database
        // (ponytail: @Image::ExifTool::UserDefined::Geolocation comes from config files, which
        // aren't ported: call AddEntry() instead)
    }

    //------------------------------------------------------------------------------
    // Read Geolocation database
    // Inputs: 0) database file name
    // Returns: true on success
    public static function ReadDatabase($datfile)
    {
        self::Load();
        // open geolocation database and verify header
        $fh = @fopen($datfile, 'rb');
        if (!$fh) {
            ExifTool::PerlWarn("Error reading $datfile\n");
            return 0;
        }
        $line = fgets($fh);
        if (!preg_match('/^Geolocation(\d+\.\d+)\t(\d+)/', (string) $line, $m)) {
            ExifTool::PerlWarn("Bad format Geolocation database\n");
            fclose($fh);
            return 0;
        }
        [self::$dbVer, self::$nCity] = [$m[1], $m[2]];
        if (!preg_match('/^1\.0[23]$/', self::$dbVer)) {
            $which = self::$dbVer < 1.03 ? 'database' : 'ExifTool';
            ExifTool::PerlWarn("Incompatible Geolocation database (update your $which)\n");
            fclose($fh);
            return 0;
        }
        $comment = fgets($fh);
        if ($comment === false or !preg_match('/ (\d+) /', $comment, $m)) {
            fclose($fh);
            return 0;
        }
        self::$dbInfo = "$datfile v" . self::$dbVer . ': ' . self::$nCity . " cities with population > $m[1]";
        // (the reverse lookups built here for UserDefined entries are built by AddEntry)

        self::$altNames = [];    // reset altNames

        // read city database
        self::$cityList = [];
        for (;;) {
            $line = fgets($fh);
            // (PHP: stop at the end of a truncated file, where Perl loops forever)
            if ($line === false or strlen($line) == 6 and str_contains($line, "\0\0\0\0")) break;
            while (strlen($line) < 14 and ($more = fgets($fh)) !== false) $line .= $more;
            Perl::chomp($line);
            self::$cityList[] = $line;
        }
        if (count(self::$cityList) != self::$nCity) {
            ExifTool::PerlWarn("Bad number of entries in Geolocation database\n");
            return 0;
        }
        // read countries
        for (;;) {
            $line = fgets($fh);
            if ($line === false or strlen($line) == 6 and str_contains($line, "\0\0\0\0")) break;
            Perl::chomp($line);
            self::$countryList[] = $line;
        }
        // read regions
        for (;;) {
            $line = fgets($fh);
            if ($line === false or strlen($line) == 6 and str_contains($line, "\0\0\0\0")) break;
            Perl::chomp($line);
            self::$regionList[] = $line;
        }
        // read subregions
        for (;;) {
            $line = fgets($fh);
            if ($line === false or strlen($line) == 6 and str_contains($line, "\0\0\0\0")) break;
            Perl::chomp($line);
            self::$subregionList[] = $line;
        }
        // read time zones
        for (;;) {
            $line = fgets($fh);
            if ($line === false or strlen($line) == 6 and str_contains($line, "\0\0\0\0")) break;
            Perl::chomp($line);
            self::$timezoneList[] = $line;
        }
        // read feature codes if available
        if ($line === "\0\0\0\0\x05\n") {
            self::$featureCodes = [];
            for (;;) {
                $line = fgets($fh);
                if ($line === false or strlen($line) == 6 and str_contains($line, "\0\0\0\0")) break;
                Perl::chomp($line);
                if (preg_match('/ (.*)/', $line, $m)) {
                    $line = preg_replace('/ (.*)/', '', $line, 1);
                    self::$featureTypes[$line] = $m[1];
                }
                self::$featureCodes[] = $line;
            }
        }
        fclose($fh);
        // initialize featureCodes lookup
        self::$featureCodesH = [];
        foreach (self::$featureCodes as $i => $code) self::$featureCodesH[strtolower($code)] = $i;
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read alternate-names database
    // Returns: True on success
    // Notes: Must be called after ReadDatabase(). Resets $altDir on exit.
    public static function ReadAltNames()
    {
        self::Load();
        $success = null;
        if (self::$altDir and self::$nCity) {
            $data = @file_get_contents(self::$altDir . '/AltNames.dat');
            if ($data !== false) {
                // (Perl reads records terminated by "\0" and chops the last character of each)
                $recs = explode("\0", $data);
                $last = array_pop($recs);
                if ($last !== '') $recs[] = substr($last, 0, -1);
                foreach ($recs as $i => $rec) self::$altNames[$i] = $rec;
                if (count($recs) == self::$nCity) {
                    $success = 1;
                } else {
                    ExifTool::PerlWarn("Bad number of entries in AltNames database\n");
                    self::$altNames = [];
                }
            } else {
                ExifTool::PerlWarn('Error reading ' . self::$altDir . "/AltNames.dat\n");
            }
            self::$altDir = null;
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // Clear last city matches cache
    public static function ClearLastMatches(): void
    {
        self::$lastArgs = null;     // arguments in last call to Geolocate
        self::$lastFound = [];      // keys are last matching city numbers, values are population codes
        self::$lastByPop = [];      // last matching city numbers ordered by population
        self::$lastByLat = [];      // last matching city numbers ordered by latitude
    }

    //------------------------------------------------------------------------------
    // Sort database by specified field
    // Inputs: 0) Field name to sort (Latitude,City,Country)
    // Returns: 1 on success
    public static function SortDatabase($field)
    {
        self::Load();
        if ($field === self::$sortedBy) return 1;    // already sorted?
        self::$sortOrder = [];
        if ($field === 'Latitude') {
            $keys = self::$cityList;
        } elseif ($field === 'City') {
            $keys = array_map(static fn ($c) => substr($c, 13), self::$cityList);
        } elseif ($field === 'Country') {
            $keys = [];
            foreach (self::$cityList as $i => $c) {
                $city = substr($c, 13);
                $ctry = substr(self::$countryList[ord($c[5])] ?? '', 2);
                $keys[$i] = "$ctry $city";
            }
        } else {
            return 0;
        }
        // (stable sort comparing bytes, like Perl's sort with cmp)
        asort($keys, SORT_STRING);
        self::$sortOrder = array_keys($keys);
        self::$sortedBy = $field;
        self::ClearLastMatches();
        return 1;
    }

    //------------------------------------------------------------------------------
    // Add cities to the Geolocation database
    // Inputs: 0-8) city,region,subregion,country_code,country,timezone,feature_code,population,lat,lon,altNames
    // eg. AddEntry('Sinemorets','Burgas','Obshtina Tsarevo','BG','Bulgaria','Europe/Sofia','',400,42.06115,27.97833)
    // Returns: true on success, otherwise issues warning
    public static function AddEntry(...$args)
    {
        self::Load();
        [$city, $region, $subregion, $cc, $country, $timezone, $fc, $pop, $lat, $lon, $altNames] = $args + array_fill(0, 11, null);
        if (count($args) < 10) {
            ExifTool::PerlWarn("Too few arguments in $city definition (check for updated format)\n");
            return 0;
        }
        if (strlen((string) $cc) != 2) {
            ExifTool::PerlWarn("Country code '{$cc}' is not 2 characters\n");
            return 0;
        }
        $fc = (string) $fc;
        if (preg_match('/ (.*)/', $fc, $m)) {
            $fc = preg_replace('/ (.*)/', '', $fc, 1);
            self::$featureTypes[$fc] = $m[1];
        }
        $fn = self::$featureCodesH[strtolower($fc)] ?? null;
        if ($fn === null) {
            if (self::$dbVer === '1.02' or count(self::$featureCodes) > 0x3f or !strlen($fc)) {
                $fn = 0;
            } else {
                self::$featureCodes[] = strtoupper($fc);
                self::$featureCodesH[strtolower($fc)] = $fn = count(self::$featureCodes) - 1;
            }
        }
        if (is_string($lon)) Perl::chomp($lon); // (just in case it was read from file)
        // create reverse lookups for country/region/subregion/timezone if not done already
        // (eg. if the entries are being added manually instead of via UserDefined::Geolocation)
        if (!self::$countryNum) {
            foreach (self::$countryList as $i => $v) self::$countryNum[strtolower(substr($v, 0, 2))] = $i;
            foreach (self::$regionList as $i => $v) self::$regionNum[strtolower($v)] = $i;
            foreach (self::$subregionList as $i => $v) self::$subregionNum[strtolower($v)] = $i;
            foreach (self::$timezoneList as $i => $v) self::$timezoneNum[strtolower($v)] = $i;
        }
        $cn = self::$countryNum[strtolower($cc)] ?? null;
        if ($cn === null) {
            if (count(self::$countryList) - 1 >= 0xff) {
                ExifTool::PerlWarn("AddEntry: Too many countries\n");
                return 0;
            }
            self::$countryList[] = "$cc$country";
            $cn = self::$countryNum[strtolower($cc)] = count(self::$countryList) - 1;
        } elseif ($country) {
            self::$countryList[$cn] = "$cc$country";  // (override existing country name)
        }
        $tn = self::$timezoneNum[strtolower((string) $timezone)] ?? null;
        if ($tn === null) {
            if (count(self::$timezoneList) - 1 >= 0x1ff) {
                ExifTool::PerlWarn("AddEntry: Too many time zones\n");
                return 0;
            }
            self::$timezoneList[] = $timezone;
            $tn = self::$timezoneNum[strtolower((string) $timezone)] = count(self::$timezoneList) - 1;
        }
        $rn = self::$regionNum[strtolower((string) $region)] ?? null;
        if ($rn === null) {
            if (count(self::$regionList) - 1 >= 0xfff) {
                ExifTool::PerlWarn("AddEntry: Too many regions\n");
                return 0;
            }
            self::$regionList[] = $region;
            $rn = self::$regionNum[strtolower((string) $region)] = count(self::$regionList) - 1;
        }
        $sn = self::$subregionNum[strtolower((string) $subregion)] ?? null;
        if ($sn === null) {
            $max = self::$dbVer === '1.02' ? 0x7fff : 0xffff;
            if (count(self::$subregionList) - 1 >= $max) {
                ExifTool::PerlWarn("AddEntry: Too many subregions\n");
                return 0;
            }
            self::$subregionList[] = $subregion;
            $sn = self::$subregionNum[strtolower((string) $subregion)] = count(self::$subregionList) - 1;
        }
        $pop = Perl::sprintf('%.1e', $pop); // format: "3.1e+04" or "3.1e+004"
        // pack CC index, population and region index into a 32-bit integer
        $code = ($cn << 24) | ((int) substr($pop, -1, 1) << 20) | ((int) substr($pop, 0, 1) << 16) |
                ((int) substr($pop, 2, 1) << 12) | $rn;
        // store high bit of timezone index
        if ($tn > 255) {
            if (self::$dbVer === '1.02') {
                $sn |= 0x8000;
            } else {
                $fn |= 0x80;
            }
            $tn -= 256;
        }
        $lat = Perl::int((Perl::num($lat) + 90) / 180 * 0x100000 + 0.5) & 0xfffff;
        $lon = Perl::int((Perl::num($lon) + 180) / 360 * 0x100000 + 0.5) & 0xfffff;
        $hdr = Perl::pack('nCnNnCC', $lat >> 4, (($lat & 0x0f) << 4) | ($lon & 0x0f), $lon >> 4, $code, $sn, $tn, $fn);
        self::$cityList[] = "$hdr$city";
        // add altNames entry if provided
        if ($altNames) {
            Perl::chomp($altNames); // (just in case)
            $altNames = strtr($altNames, ',', "\n");
            // add any more arguments in case altNames were passed separately (undocumented)
            foreach (array_slice($args, 11) as $alt) {
                $alt = (string) $alt;
                Perl::chomp($alt);
                $altNames .= "\n$alt";
            }
            self::$altNames[count(self::$cityList) - 1] = $altNames;
        }
        self::$sortedBy = '';
        self::$lastArgs = null;    // (faster than ClearLastArgs)
        return 1;
    }

    //------------------------------------------------------------------------------
    // Unpack entry in database
    // Inputs: 0) entry number or index into sorted database,
    //         1) optional language code, 2) flag to use index into sorted database
    // Returns: 0-10) city,region,subregion,country_code,country,timezone,
    //                feature_code,pop,lat,lon,feature_type
    public static function GetEntry($entryNum, $lang = null, $sort = null): array
    {
        self::Load();
        if ($entryNum > count(self::$cityList) - 1) return [];
        if ($sort and count(self::$sortOrder) > $entryNum) $entryNum = self::$sortOrder[$entryNum];
        [$lt, $f, $ln, $code, $sn, $tn, $fn] = Perl::unpack('nCnNnCC', self::$cityList[$entryNum]);
        $city = substr(self::$cityList[$entryNum], 13);
        $ctry = self::$countryList[$code >> 24] ?? '';
        $rgn = self::$regionList[$code & 0x0fff] ?? null;
        if (self::$dbVer === '1.02') {
            if ($sn & 0x8000) {
                $tn += 256;
                $sn &= 0x7fff;
            }
        } elseif ($fn & 0x80) {
            $tn += 256;
        }
        $sub = self::$subregionList[$sn] ?? null;
        // convert population digits back into exponent format
        $pop = Perl::str(Perl::num(($code >> 16 & 0x0f) . '.' . ($code >> 12 & 0x0f) . 'e+' . ($code >> 20 & 0x0f)));
        $lt = Perl::sprintf('%.4f', (($lt << 4) | ($f >> 4)) * 180 / 0x100000 - 90);
        $ln = Perl::sprintf('%.4f', (($ln << 4) | ($f & 0x0f)) * 360 / 0x100000 - 180);
        $fc = (self::$featureCodes[$fn & 0x3f] ?? null) ?: 'Other';
        $cc = substr($ctry, 0, 2);
        $country = substr($ctry, 2);
        $ft = self::$featureTypes[$fc] ?? null;
        // (ponytail: the $lang translations aren't ported: ExifTool doesn't distribute the
        // GeoLang/<lang>.pm modules (they come with the alternate databases of exiftool.org),
        // and the user-defined %geoLang comes from config files)
        return [$city, $rgn, $sub, $cc, $country, self::$timezoneList[$tn] ?? null, $fc, $pop, $lt, $ln, $ft];
    }

    //------------------------------------------------------------------------------
    // Get alternate names for specified database entry
    // Inputs: 0) entry number or index into sorted database, 1) sort flag
    // Returns: comma-separated list of alternate names, or empty string if no names
    // Notes: ReadAltNames() must be called before this
    public static function GetAltNames($entryNum, $sort = null)
    {
        self::Load();
        if ($sort and count(self::$sortOrder) > $entryNum) $entryNum = self::$sortOrder[$entryNum];
        $alt = self::$altNames[$entryNum] ?? null;
        if (!$alt) return '';
        return strtr($alt, "\n", ',');
    }

    //------------------------------------------------------------------------------
    // Look up lat,lon or city in geolocation database
    // Inputs: 0) "lat,lon", "city,region,country", etc, (city must be first)
    //         1) options hash reference (or undef for no options)
    // Options: GeolocMinPop, GeolocMaxDist, GeolocMulti, GeolocFeature, GeolocAltNames,
    //          GeolocNearby
    // Returns: 0) Reference to list of indices for matching cities, or undef for no matches
    //          1) Reference to list of distance/bearing pairs, or undef if no GPS
    //          (PHP: always the 2-element list; [null, null] for no matches)
    public static function Geolocate($arg, $opts = null): array
    {
        self::Load();
        $city = $other = $idx = $both = $minPop = null;
        $exact = $regex = $cargs = $matchParms = $coords = $fcOK = [];
        [$pop, $maxDist, $fcodes, $altNames] = $opts ?
            [$opts['GeolocMinPop'] ?? null, $opts['GeolocMaxDist'] ?? null, $opts['GeolocFeature'] ?? null, $opts['GeolocAltNames'] ?? null] :
            [null, null, null, null];
        // (ponytail: the $debug processing times aren't ported, $debug is never set)

        if (!self::$cityList) {
            ExifTool::PerlWarn('No Geolocation database');
            return [null, null];
        }
        // make population code for comparing with 2 bytes at offset 6 in database
        if ($pop) {
            $pop = Perl::sprintf('%.1e', $pop);
            $minPop = chr(((int) substr($pop, -1, 1) << 4) | (int) substr($pop, 0, 1)) . chr((int) substr($pop, 2, 1) << 4);
        }
        if ($fcodes) {
            $neg = preg_match('/^-/', $fcodes);
            $fcodes = preg_replace('/^-/', '', $fcodes, 1);
            $fcodeList = Perl::split('/\s*,-?\s*/', strtolower($fcodes));     // (allow leading dash on subsequent codes)
            if ($neg) {
                foreach (array_keys(self::$featureCodes) as $i) $fcOK[$i] = 1;
                foreach ($fcodeList as $c) {
                    if (isset(self::$featureCodesH[$c])) unset($fcOK[self::$featureCodesH[$c]]);
                }
            } else {
                foreach ($fcodeList as $c) {
                    if (isset(self::$featureCodesH[$c])) $fcOK[self::$featureCodesH[$c]] = 1;
                }
            }
        }
//
// process input argument
//
        $num = 1;
        $arg = preg_replace(['/^\s+/', '/\s+$/'], '', Perl::str($arg), 1); // remove leading/trailing spaces
        $ri = ['cc' => 0, 'co' => 1, 're' => 2, 'sr' => 3, 'ci' => 8, '' => 9];
        foreach (Perl::split('/\s*,\s*/', $arg) as $a) {
            // allow regular expressions optionally prefixed by "ci", "cc", "co", "re" or "sr"
            if (preg_match('#^(-)?(\w{2})?/(.*)/(i?)$#', $a, $m)) {
                $re = ExifTool::Rx($m[3], $m[4] ? 'im' : 'm');
                if (($idx = $ri[$m[2]] ?? null) === null) continue;
                $cargs[] = $a;
                if ($idx < 5) $other = 1;
                if ($m[1]) $idx += 10;   // add 10 for negative matches
                $regex[$idx][] = $re;
                $city ??= '';
            } elseif (preg_match('/^[-+]?\d+(\.\d+)?$/', $a)) {    // coordinate format
                if (count($coords) < 2) $coords[] = $a;
            } elseif (preg_match('/^([-+]?\d+(?:\.\d+)?) *(([NS])[A-Z]*)? +([-+]?\d+(?:\.\d+)?) *(([EW])[A-Z]*)?/i', $a, $m)) { // "lat lon" format
                if ($coords) continue;
                [$lat, $lon] = [$m[1], $m[4]];
                if (!empty($m[3]) and strtoupper($m[3]) === 'S') $lat = -abs(Perl::num($lat));
                if (!empty($m[6]) and strtoupper($m[6]) === 'W') $lon = -abs(Perl::num($lon));
                array_push($coords, $lat, $lon);
            } elseif (strtolower($a) === 'both') {
                $both = 1;
            } elseif (preg_match('/^num=(\d+)$/i', $a, $m)) {
                $num = Perl::num($m[1]);
            } elseif ($a) {
                $cargs[] = $a;
                if ($city) {
                    $exact[] = strtolower($a);
                } else {
                    $city = strtolower($a);
                }
            }
        }
        if (!($city !== null or count($coords) == 2)) {
            ExifTool::PerlWarn("Insufficient information to determine geolocation\n");
            return [null, null];
        }
        // sort database by logitude if finding entry based on coordinates
        if (count($coords) == 2 and ($both or $city === null)) self::SortDatabase('Latitude');
//
// perform reverse Geolocation lookup to determine GPS based on city, country, etc.
//
        while ($city !== null and (count($coords) != 2 or $both)) {
            $cargStr = implode(',', [...$cargs, $pop ?: '', $maxDist ?: '', $fcodes ?: '']); // (Perl $cargs)
            $i = 0;
            if (self::$lastArgs and self::$lastArgs === $cargStr) {
                $i = count(self::$cityList); // bypass search
            } else {
                self::ClearLastMatches();
                self::$lastArgs = $cargStr;
            }
            // read alternate names database if an exact city match is specified
            if ($altNames) {
                if ($city and self::$altDir) self::ReadAltNames();
                $altNames = self::$altNames;
            } else {
                $altNames = [];    // (don't search alt names)
            }
            $altRe = $city ? ExifTool::Rx("^$city\$", 'im') : null;
            for ($n = count(self::$cityList); $i < $n; ++$i) {  // Entry:
                $entry = self::$cityList[$i];
                $cty = substr($entry, 13);
                if ($city and $city !== strtolower($cty)) { // test exact city name first
                    if (!(($altNames[$i] ?? null) and preg_match($altRe, $altNames[$i]))) continue;
                }
                // test with city-specific regexes
                foreach ($regex[8] ?? [] as $re) if (!preg_match($re, $cty)) continue 2;
                foreach ($regex[18] ?? [] as $re) if (preg_match($re, $cty)) continue 2;
                // test other arguments
                [$cd, $sn] = Perl::unpack('x5Nn', $entry);
                $ct = self::$countryList[$cd >> 24] ?? '';
                if (self::$dbVer === '1.02') $sn &= 0x7fff;
                $geo = [substr($ct, 0, 2), substr($ct, 2), self::$regionList[$cd & 0x0fff] ?? null, self::$subregionList[$sn] ?? null];
                if ($exact) {
                    // make quick lookup for all names at this location
                    $geoLkup = [];
                    foreach ($geo as $g) if ($g) $geoLkup[strtolower($g)] = 1;
                    foreach ($exact as $e) if (!isset($geoLkup[$e])) continue 2;
                }
                // test with cc, co, re and sr regexes
                if ($other) {
                    foreach ([0, 1, 2, 3] as $idx) {
                        foreach ($regex[$idx] ?? [] as $re) if (!preg_match($re, (string) $geo[$idx])) continue 3;
                        foreach ($regex[$idx + 10] ?? [] as $re) if (preg_match($re, (string) $geo[$idx])) continue 3;
                    }
                }
                // test regexes for any place name
                if (isset($regex[9]) or isset($regex[19])) {
                    $str = implode("\n", [$cty, ...$geo]);
                    foreach ($regex[9] ?? [] as $re) if (!preg_match($re, $str)) continue 2;
                    foreach ($regex[19] ?? [] as $re) if (preg_match($re, $str)) continue 2;
                }
                // test feature code and population
                if ($fcodes and !isset($fcOK[ord($entry[12]) & 0x3f])) continue;
                $pc = substr($entry, 6, 2);
                if ($minPop === null or strcmp($pc, $minPop) >= 0) {
                    self::$lastFound[$i] = $pc;
                    if (count($coords) == 2) self::$lastByLat[] = $i;
                }
            }
            if (self::$lastFound) {
                if (count($coords) == 2) break;   // continue to use coords with last city matches
                if (count(self::$lastFound) > 200) {
                    ExifTool::PerlWarn("Too many matching cities\n");
                    return [null, null];
                }
                // return nearby cities if "num=" is used and only one match found
                if ($num > 1 and count(self::$lastFound) == 1) {
                    $entry = self::GetEntry(array_key_first(self::$lastFound));
                    $coords = [$entry[8], $entry[9]];
                    self::SortDatabase('Latitude');   // (make sure we are sorted by latitude)
                    break;
                }
                if (!self::$lastByPop) {
                    $byPop = array_keys(self::$lastFound);
                    usort($byPop, static fn ($a, $b) => strcmp(self::$lastFound[$b], self::$lastFound[$a]) ?:
                                                        strcmp(self::$cityList[$a], self::$cityList[$b]));
                    self::$lastByPop = $byPop;
                }
                return [self::$lastByPop, null];
            }
            ExifTool::PerlWarn("No such city in Geolocation database\n");
            return [null, null];
        }
//
// determine Geolocation based on GPS coordinates
//
        [$lat, $lon] = $coords;
        if ($maxDist) {
            $minDistU = Perl::num($maxDist) / (2 * self::$earthRadius);              // min distance on unit sphere
            $minDistC = Perl::num($maxDist) * 0x100000 / (self::$pi * self::$earthRadius); // min distance in coordinate units
        } else {
            $minDistU = self::$pi;
            $minDistC = 0x200000;
        }
        // reduce lat/lon to the range 0-0x100000
        $lat = Perl::int((Perl::num($lat) + 90) / 180 * 0x100000 + 0.5) & 0xfffff;
        $lon = Perl::int((Perl::num($lon) + 180) / 360 * 0x100000 + 0.5) & 0xfffff;
        if (!$lat) $lat = Perl::num($coords[0]) < 0 ? 1 : 0xfffff;    // (zero latitude is a problem for our calculations)
        $coord = Perl::pack('nCn', $lat >> 4, (($lat & 0x0f) << 4) | ($lon & 0x0f), $lon >> 4);
        // start from cached city matches if also using city information
        $numEntries = count(self::$lastByLat) ?: count(self::$cityList);
        // binary search to find closest longitude
        [$n0, $n1] = [0, $numEntries - 1];
        $sorted = self::$lastByLat ?: (self::$sortOrder ?: null);
        while ($n1 - $n0 > 1) {
            $n = intdiv($n0 + $n1, 2);
            if (strcmp($coord, self::$cityList[$sorted ? $sorted[$n] : $n]) < 0) {
                $n1 = $n;
            } else {
                $n0 = $n;
            }
        }
        // step backward then forward through database to find nearest city
        [$inc, $end, $n] = [-1, -1, $n0 + 1];
        [$p0, $t0] = [$lat * self::$pi / 0x100000 - self::$pi / 2, $lon * self::$pi / 0x080000 - self::$pi];
        $cp0 = cos($p0);
        $matches = $rtnList = $dist = [];

        for (;;) {
            if (($n += $inc) == $end) {
                if ($inc == 1 or $n0 == $n1) break;
                [$inc, $end, $n] = [1, $numEntries, $n1];
            }
            $i = $sorted ? $sorted[$n] : $n;
            // get city latitude/longitude
            [$lt, $f, $ln] = Perl::unpack('nCn', self::$cityList[$i]);
            $lt = ($lt << 4) | ($f >> 4);
            // searched far enough if latitude alone is further than best distance
            if (abs($lt - $lat) > $minDistC) {
                $n = $end - $inc;
                continue;
            }
            // ignore if population is below threshold
            if ($minPop !== null and strcmp($minPop, substr(self::$cityList[$i], 6, 2)) >= 0) continue;
            if ($fcodes and !isset($fcOK[ord(self::$cityList[$i][12]) & 0x3f])) continue;
            $ln = ($ln << 4) | ($f & 0x0f);
            // calculate great circle distance to this city on unit sphere
            [$p1, $t1] = [$lt * self::$pi / 0x100000 - self::$pi / 2, $ln * self::$pi / 0x080000 - self::$pi];
            [$sp, $st] = [sin(($p1 - $p0) / 2), sin(($t1 - $t0) / 2)];
            $a = $sp * $sp + $cp0 * cos($p1) * $st * $st;
            $distU = atan2(sqrt($a), sqrt(1 - $a)); // distance on unit sphere
            if ($distU > $minDistU) continue;
            $matchParms = [$i, $p1, $t1, $distU];
            if ($num <= 1) {
                $minDistU = $distU;
            } else {
                // add this entry into list of matching cities ordered by closest first
                for ($j = 0; $j < count($matches); ++$j) {
                    if ($distU < $matches[$j][3]) break;
                }
                if ($j < count($matches) - 1) {
                    array_splice($matches, $j, 0, [$matchParms]);
                } else {
                    $matches[$j] = $matchParms;
                }
                // restrict list to the specified number of nearest cities
                if (count($matches) > $num) array_pop($matches);
                // update minimum distance with furthest match if we satisfied our quota
                if (count($matches) >= $num) $minDistU = $matches[count($matches) - 1][3];
            }
            $minDistC = $minDistU * 0x200000 / self::$pi;  // distance in scaled coordinate units
        }
        if (!$matchParms) {
            ExifTool::PerlWarn("No suitable location in Geolocation database\n");
            return [null, null];
        }
        $num = count($matches);

        for (;;) {
            if ($num > 1) {
                if (!$matches) break;
                $matchParms = array_shift($matches);
            }
            // calculate distance in km and bearing to matching city
            [$ii, $p1, $t1, $distU] = $matchParms;
            $km = Perl::sprintf('%.2f', 2 * self::$earthRadius * $distU);
            $be = atan2(sin($t1 - $t0) * cos($p1 - $p0), $cp0 * sin($p1) - sin($p0) * cos($p1) * cos($t1 - $t0));
            $be = Perl::int($be * 180 / self::$pi + 360.5) % 360; // convert from radians to integer degrees
            $rtnList[] = $ii;
            $dist[] = [$km, $be];
            if ($num <= 1) break;
        }
        return [$rtnList, $dist];
    }
}
