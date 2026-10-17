<?php

//------------------------------------------------------------------------------
// File:         MXF.pm
//
// Description:  Read MXF meta information
//
// Revisions:    2010/12/15 - P. Harvey Created
//
// References:   1) http://sourceforge.net/projects/mxflib/
//               2) http://www.aafassociation.org/downloads/whitepapers/MXFPhysicalview.pdf
//               3) http://archive.nlm.nih.gov/pubs/pearson/MJ2_Metadata2005.pdf
//               4) http://www.aafassociation.org/downloads/specifications/AMWA-AS-03-Delivery-Spec-1_0.pdf
//               5) http://paul-sampson.ca/private/s385m.pdf
//               6) http://avwiki.nl/documents/eg41.pdf
//               7) http://avwiki.nl/documents/eg42.pdf
//               8) http://rhea.tele.ucl.ac.be:8081/Plone/Members/egoray/thesaurus-dictionnaire-metadata/
//                   a) S335M Dictionary structure.pdf
//                   b) S330M  UMID.PDF
//               9) http://www.smpte-ra.org/mdd/RP210v12-publication-20100623.xls
//               10) http://www.amwa.tv/downloads/specifications/aafobjectspec-v1.1.pdf
//               11) http://www.mog-solutions.com/img_upload/PDF/XML%20Schema%20for%20MXF%20Metadata.pdf
//               12) http://www.freemxf.org/freemxf_board/viewtopic.php?p=545&sid=00a5c17e07d828c1e93ecdbaed3076f7
//
// Notes:     1) The alternate language support is dependent on the serialization
//               sequence.  Specifically the InstanceUID's must come before any
//               text in an alternate language set, and these sets must come
//               after the language definitions.
//
//            2) UTF-16 surrogate pairs are not handled properly.
//
//            3) This code is not tested for files larger than 2 GB,
//               but in theory this should be OK with 64-bit Perl.
//
// PHP port of Image::ExifTool::MXF 1.09 (reading only). Tag tables are generated
// into tables/MXF.php, file lexicals (%knownType, %localSet) into Vars\MXF.
//
// PHP: the MXF information hash is kept in $et->MXFInfo (the Header RawConvs write
// into it) and accessed by reference.  ProcessLocalSet keeps references to the
// TAG_EXTRA hashes of the extracted tags to set their UID/G1/G5 entries later;
// a PHP array is a value that FoundTag copies when it moves a tag to a new key,
// so each TAG_EXTRA entry instead carries an \ArrayObject ('MXFGroups') that
// follows the tag and stands for the Perl hash ref.  FlushGroups() copies these
// entries into TAG_EXTRA once the object tree has been walked.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class MXF
{
    public static $VERSION = '1.09';

    //------------------------------------------------------------------------------
    // Convert 16 bytes to UL format
    // Inputs: 0) 16-byte value
    // Returns: UL string
    public static function UL($val)
    {
        return implode('.', Perl::unpack('H8H4H4H8H8', $val));
    }

    //------------------------------------------------------------------------------
    // Convert latitude and/or longitude in [d]ddmmss(N|S|E|W) format
    // Inputs: 0) string value to convert
    // Returns: numerical lat and/or long
    public static function ConvLatLon($val)
    {
        $convVal = [];
        foreach (['NS', 'EW'] as $ne) {
            if (!preg_match("/(\\d{2,3})(\\d{2})(\\d{2})([$ne])/", Perl::str($val), $m)) continue;
            $convVal[] = $m[1] + ($m[2] + $m[3] / 60) / 60 * (($m[4] === 'N' or $m[4] === 'E') ? 1 : -1);
        }
        return implode(' ', array_map(Perl::str(...), $convVal));
    }

    //------------------------------------------------------------------------------
    // Read MXF-specific Format types
    // Inputs: 0) ExifTool ref, 1) value, 2) MXF value type
    // Returns: formatted value
    // Note: All types recognized here should be defined in the %knownType lookup
    public static function ReadMXFValue(ExifTool $et, $val, $type)
    {
        $len = strlen($val);

        if ($type === 'UTF-16') {
            $val = $et->Decode($val, 'UTF16');
        } elseif ($type === 'ProductVersion') {
            $a = Perl::unpack('n*', $val);
            while (count($a) < 5) $a[] = 0;
            $a[4] = ([0 => 'unknown', 1 => 'released', 2 => 'debug', 3 => 'patched',
                      4 => 'beta', 5 => 'private build'][$a[4]] ?? null) ?: "unknown $a[4]";
            $val = implode('.', array_slice($a, 0, 4)) . ' ' . $a[4];
        } elseif ($type === 'VersionType') {
            $val = implode('.', Perl::unpack('C*', $val));
        } elseif ($type === 'Timestamp') {
            $a = Perl::unpack('nC*', $val);
            $max = [3000, 12, 31, 24, 59, 59, 249];
            foreach ($a as $_) {
                if (!($max and $_ <= $max[0])) break;
                array_shift($max);
            }
            if ($max) {
                $val = 'Invalid (0x' . Perl::unpack('H*', $val)[0] . ')';
            } else {
                $a[6] *= 4;
                $val = Perl::sprintf('%.4d:%.2d:%.2d %.2d:%.2d:%.2d.%.3d', ...$a);
            }
        } elseif ($type === 'Position' or $type === 'Length') {
            $val = ExifTool::Get64u($val, 0);
        } elseif ($type === 'Boolean') {
            $val = $val === "\0" ? 'False' : 'True';
        } elseif (preg_match('/^(Alt|Lat|Lon)$/', $type) and $len == 4) {
            // split into nibbles after swapping byte order (see reference 8b)
            $val = Perl::unpack('H*', Perl::pack('N', Perl::unpack('V', $val)[0]))[0];
            if ($type === 'Alt') {
                // drop satellite information and only support altitudes up to +/-99999 m
                $val = preg_replace('/^[abc]../', '', $val, 1, $n);
                if (!$n) $val = preg_replace('/^[def]../', '-', $val, 1, $n);
                if (!$n) $val = "$val (from earth centre)";
            } else {
                $val = preg_replace('/(...)/', '$1.', $val, 1);    // insert decimal point after 3rd character
                if ($type === 'Lat') {
                    $val = preg_replace('/^f/', '-', $val, 1);      // south with first digit = 0
                } else {
                    $val = preg_replace('/^e/', '-', $val, 1);      // east with first digit = 0
                    $val = preg_replace('/^f/', '-1', $val, 1);     // east with first digit = 1
                }
            }
        } elseif (preg_match('/(Array|Batch)/', $type) and $len > 16) {
            [$count, $size] = Perl::unpack('NN', $val);
            // validate data length
            if (!($len == 8 + $count * $size)) $et->Warn('Bad array or batch size');
            $a = [];
            for ($i = 0; $i < $count; ++$i) {
                $pos = 8 + $i * $size;
                if ($pos + $size > $len) break;
                $a[] = substr($val, $pos, $size);
            }
            if (preg_match('/^StrongReference/', $type)) {
                foreach ($a as &$_) $_ = implode('-', Perl::unpack('H8H4H4H4H12', $_));
                unset($_);
            } elseif ($type === 'BatchOfUL' or preg_match('/^WeakReference/', $type)) {
                foreach ($a as &$_) $_ = self::ReadMXFValue($et, $_, 'UL');
                unset($_);
            }
            $val = $a;
        } elseif ($len == 32) {
            // 32-byte types include UMID, PackageID
            $val = implode('.', Perl::unpack('H8H4H4H8', $val)) . ' ' .
                   implode(' ', Perl::unpack('x12H2H6', $val)) . ' ' .
                   implode('-', Perl::unpack('x16H8H4H4H4H12', $val));
        } elseif ($len == 16) {
            // convert remaining 16-byte known types as GUID or UL
            // - this covers all UID's, UL, Label, WeakReference, StrongReference
            if ($type === 'UL' or $type === 'WeakReference') {
                // reverse high/low word if a reversed GUID is stored in a UL type
                // (see ref 8b, section 5.5.8)
                // - a GUID will have high bit of byte 8 set (reversed, this is in byte 0)
                if (!(Perl::unpack('C', $val)[0] & 0x80)) return self::UL($val);
                $val = substr($val, 8) . substr($val, 0, 8);
            }
            $val = implode('-', Perl::unpack('H8H4H4H4H12', $val)); // compact GUID format
        } else {
            // Note: a 64-byte extended UMID contains date/time, GPS coordinates, etc,
            // which would could be decoded here (ref 8b, section 5)...
            $val = Perl::unpack('H*', $val)[0];
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Process MXF Primer (to build lookup for translating local tag ID's)
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessPrimer(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $end = $dirInfo['DirLen'];
        if (!($end > 8)) return 0;
        $count = ExifTool::Get32u($dataPt, 0);
        $size = ExifTool::Get32u($dataPt, 4);
        if (!($size >= 18)) return 0;
        $et->VerboseDir('MXF Primer', $count);
        $verbose = $et->Options('Verbose');
        $primer = &$et->MXFInfo['Primer'];
        $pos = 8;
        for ($i = 0; $i < $count; ++$i) {
            if ($pos + $size > $end) break;
            $local = ExifTool::Get16u($dataPt, $pos);
            $global = self::UL(substr($dataPt, $pos + 2, 16));
            $pos += $size;
            // save this entry in the primer lookup table
            $primer[$local] = $global;
            // print lookup details in verbose mode
            if (!$verbose) continue;
            $indx = $i . ')';
            if (strlen($indx) < 3) $indx .= ' ';
            $et->VPrint(0, Perl::sprintf("  | $indx 0x%.4x => '{$global}'\n", $local));
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read tags from an MXF local set
    // Inputs: 0) ExifTool ref, 1) dirInfo ref, 2) tag table ref
    // Returns: 1 on success
    public static function ProcessLocalSet(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $dataPt = $dirInfo['DataPt'];
        $dataPos = $dirInfo['DataPos'] ?? null;
        $end = $dirInfo['DirLen'];
        $mxfInfo = &$et->MXFInfo;
        $primer = $mxfInfo['Primer'] ?? [];
        $strongRef = $groups = [];
        $instance = $editRate = $trackID = $langCode = $textLang = null;

        $et->VerboseDir('MXF LocalSet', null, $end);

        // loop through all tags in this local set
        $pos = 0;
        while ($pos + 4 < $end) {
            $loc = ExifTool::Get16u($dataPt, $pos);         // get key (local tag ID)
            $len = ExifTool::Get16u($dataPt, $pos + 2);     // get length
            $pos += 4;
            if ($pos + $len > $end) break;
            $tag = $primer[$loc] ?? null;
            $extra = $val = $type = $langInfo = null;
            if ($tag and isset($tagTablePtr[$tag])) {
                $extra = Perl::sprintf(', Local 0x%.4x', $loc);
            } else {
                $tag = $loc;
              // $et->Warn('Missing local key for at least one tag');
                $extra = ', NOT IN PRIMER!';
            }
            $tagInfo = $tagTablePtr[$tag];
            // handle our MXF-specific format types
            if ($tagInfo) {
                $type = $tagInfo->Type;
                if ($type and !empty(Vars\MXF::$knownType[$type])) {
                    $val = self::ReadMXFValue($et, substr($dataPt, $pos, $len), $type);
                    if (preg_match('/^StrongReference/', $type)) array_push($strongRef, ...(is_array($val) ? $val : [$val]));
                    // remember instance UID of this local set
                    if ($tagInfo->Name === 'InstanceUID') {
                        $instance = $val;
                        // set language code for text
                        // (only works if InstanceUID comes before text)
                        if (!empty($mxfInfo[$instance])) $textLang = $mxfInfo[$instance]['LangCode'] ?? null;
                    } elseif ($type === 'UTF-16' and $textLang) {
                        $langInfo = ExifTool::GetLangInfo($tagInfo, $textLang);
                    }
                }
            }
            // get tagInfo ref the standard way to handle Unknown tags
            $tagInfo = $langInfo ?: $et->GetTagInfo($tagTablePtr, $tag);
            // set Binary flag to extract all unknown-format tags as Binary data
            if ($tagInfo and $tagInfo->Unknown and $tagInfo->Binary === null) {
                $tagInfo->Binary = !($tagInfo->Format or ($type and !empty(Vars\MXF::$knownType[$type])));
            }
            $key = $et->HandleTag($tagTablePtr, $tag, $val,
                Extra       : $extra,
                TagInfo     : $tagInfo,
                DataPt      : $dataPt,
                DataPos     : $dataPos,
                Start       : $pos,
                Size        : $len,
                ProcessProc : 'Image\ExifTool\MXF::ProcessLocalSet',
            );
            $pos += $len;
            if (!$key) continue;
            // save information to allow later fixup of durations and group1 names
            // (necessary because we don't have all the information we need
            //  to do this on the fly when the file is parsed linearly)
            // (PHP: the shared 'MXFGroups' object stands for the TAG_EXTRA hash ref)
            $groups[] = $et->TAG_EXTRA[$key]['MXFGroups'] ??= new \ArrayObject();
            if (!$tagInfo) continue;
            $name = $tagInfo->Name;
            if ($tagInfo->IsDuration) {
                $mxfInfo['FixDuration'][$key] = 1;
            } elseif ($tagInfo->LanguageCode) {
                $langCode = $et->VALUE[$key] ?? null;
            } elseif ($name === 'EditRate') {
                $editRate = $et->VALUE[$key] ?? null;
            } elseif (preg_match('/TrackID$/', $name)) {
                $trackID = $et->VALUE[$key] ?? null;
                if (empty($mxfInfo['Group1'][Perl::str($trackID)])) {
                    // save lookup to convert TrackID to our group 1 name
                    $mxfInfo['Group1'][Perl::str($trackID)] = 'Track' . ++$mxfInfo['NumTracks'];
                }
            }
        }

        // save object information now that we know the instance UID
        if ($instance) {
            if (!empty($mxfInfo[$instance])) {
                $objInfo = &$mxfInfo[$instance];
                array_push($objInfo['StrongRef'], ...$strongRef);
                array_push($objInfo['Groups'], ...$groups);
            } else {
                $mxfInfo[$instance] = [
                    'StrongRef' => $strongRef,
                    'Groups' => $groups,
                ];
                $objInfo = &$mxfInfo[$instance];
            }
            // save instance UID's in groups hash (used to remove duplicates later)
            foreach ($groups as $_) $_['UID'] = $instance;
            $objInfo['Name'] = $et->DIR_NAME;
            if ($trackID !== null) $objInfo['TrackID'] = $trackID;
            if ($editRate) $objInfo['EditRate'] = $editRate;
            if ($langCode) {
                $objInfo['LangCode'] = $langCode;
            } else {
                $langCode = $objInfo['LangCode'] ?? null;
            }
            if ($langCode) {
                // pre-set language code in all children
                foreach ($objInfo['StrongRef'] as $ul) {
                    if (empty($mxfInfo[$ul])) $mxfInfo[$ul] = ['StrongRef' => [], 'Groups' => [], 'Name' => 'XXX'];
                    $mxfInfo[$ul]['LangCode'] = $langCode;
                }
            }
            unset($objInfo);
            // save instance UID's of Preface's
            if (Perl::str($et->DIR_NAME) === 'Preface') $mxfInfo['Preface'][] = $instance;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Walk MXF tree to set group names
    // Inputs: 0) MXF information hash ref, 1) object instance UID,
    //         2) path information hash ref (only for group 5 names), 3) track ID number
    // Notes: also generates lookup table for EditRate based on group 1 name
    //        and the instance UID of the SequenceSet with the preferred Duration value
    public static function SetGroups(array &$mxfInfo, $instance, ?array &$pathInfo = null, $trackID = null)
    {
        if (empty($mxfInfo[$instance]) or !empty($mxfInfo[$instance]['DidGroups'])) return;
        $objInfo = &$mxfInfo[$instance];
        $objInfo['DidGroups'] = 1;  // avoid reprocessing this object
        if (isset($objInfo['TrackID'])) $trackID = $objInfo['TrackID'];
        $g1 = $g5 = $nameCount = $setSource = null;
        // generate group 1 name for different tracks
        if ($trackID !== null) {
            $objInfo['TrackID'] = $trackID;
            $g1 = $mxfInfo['Group1'][Perl::str($trackID)] ?? null;
            // build a lookup to determine edit rates based on group 1 name
            $editRate = $objInfo['EditRate'] ?? null;
            if ($editRate !== null) $mxfInfo['EditRate'][Perl::str($g1)] = $editRate;
            // save the TimeCodeComponent instance UID (for determining Duration later)
            if (Perl::str($objInfo['Name'] ?? null) === 'TimecodeComponent') {
                $inWhat = !empty($mxfInfo['InSource']) ? 'Source' : 'Other';
                $mxfInfo['BestDuration'][$inWhat] = $instance;
            }
        }
        // set flag if we are in the SourcePackage (contains our preferred TimecodeComponent)
        $name = Perl::str($objInfo['Name'] ?? null);
        if ($name === 'SourcePackage') $setSource = $mxfInfo['InSource'] = 1;

        // generate group 5 path names if requested
        if ($pathInfo) {
            $nameCount = ($pathInfo['NameCount'] ?? null) ?: [];
            $nameCount[$name] = ($nameCount[$name] ?? 0) + 1;
            $pathInfo['Path'][] = $name . $nameCount[$name];
            $g5 = implode('-', $pathInfo['Path']);
            $pathInfo['NameCount'] = [];    // use new name count for child objects
        }
        foreach ($objInfo['Groups'] as $groups) {
            if ($g1) $groups['G1'] = $g1;
            if ($g5) $groups['G5'] = $g5;
        }
        // walk through remaining objects in tree
        foreach ($objInfo['StrongRef'] as $ul) {
            self::SetGroups($mxfInfo, $ul, $pathInfo, $trackID);
        }
        if ($pathInfo) {
            array_pop($pathInfo['Path']);
            $pathInfo['NameCount'] = $nameCount;
        }
        if ($setSource) unset($mxfInfo['InSource']);
    }

    //------------------------------------------------------------------------------
    // (PHP only) Copy the entries of the 'MXFGroups' objects into their TAG_EXTRA hashes
    // Inputs: 0) ExifTool object ref
    public static function FlushGroups(ExifTool $et)
    {
        foreach ($et->TAG_EXTRA as $key => $extra) {
            if (!isset($extra['MXFGroups'])) continue;
            foreach ($extra['MXFGroups'] as $k => $v) $et->TAG_EXTRA[$key][$k] = $v;
            unset($et->TAG_EXTRA[$key]['MXFGroups']);
        }
    }

    //------------------------------------------------------------------------------
    // Convert all duration values to seconds
    // Inputs: 0) ExifTool object ref, 1) MXF information hash ref
    public static function ConvertDurations(ExifTool $et, array &$mxfInfo)
    {
        $valueHash = &$et->VALUE;
        $infoHash = $et->TAG_INFO;
        $tagExtra = $et->TAG_EXTRA;
        $editHash = $mxfInfo['EditRate'] ?? [];
        foreach (array_keys($mxfInfo['FixDuration']) as $tag) {
            // loop through all instances of this tag name
            for ($i = 0, $key = $tag; ; ++$i, $key = "$tag ($i)") {
                $tagInfo = $infoHash[$key] ?? null;
                if (!$tagInfo) break;
                if (!$tagInfo->IsDuration) continue;    // test IsDuration flag to be sure
                $g1 = $tagExtra[$key]['G1'] ?? null;
                if (!$g1) continue;
                $editRate = $editHash[$g1] ?? null;
                if ($editRate) $valueHash[$key] = Perl::num($valueHash[$key] ?? null) / Perl::num($editRate);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Read information in a MXF file
    // Inputs: 0) ExifTool ref, 1) dirInfo ref
    // Returns: 1 on success, 0 if this wasn't a valid MXF file
    public static function ProcessMXF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $unknown = $et->Options('Unknown');
        $buff = $preface = $n = $headerEnd = $footerPos = null;

        // read enough to allow skipping over run-in if it exists
        if (!$raf->Read($buff, 65547)) return 0;
        if (!preg_match('/\x06\x0e\x2b\x34\x02\x05\x01\x01\x0d\x01\x02/', $buff, $m, PREG_OFFSET_CAPTURE)) return 0;
        $start = $m[0][1];  // (pos($buff) - 11)

        $et->SetFileType();
        ExifTool::SetByteOrder('MM');
        if (!$raf->Seek($start, 0)) {
            $et->Warn('Seek error');
            return 1;
        }
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MXF::Main');

        // determine header length and type

        // initialize MXF information lookups
        $et->MXFInfo = [
            'Primer' => [],         // convert local keys to global UL
            'Group1' => [],         // group 1 names base on TrackID
            'NumTracks' => 0,       // counts number of tracks in file
            'FixDuration' => [],    // names of all Duration tags that need fixing
            'Preface' => [],        // instance UID's for all Preface objects
        ];
        $mxfInfo = &$et->MXFInfo;

        // set group 1 name for all tags (so we can overwrite with track number later)
        $et->SET_GROUP1 = 'MXF';

        for (;;) {
            $pos = $raf->Tell();
            // did we just finish parsing the header partition?
            if ($headerEnd and $pos >= $headerEnd) {
                // all done if it was a closed and complete header
                if (Perl::str($mxfInfo['HeaderType'] ?? null) === 'ClosedCompleteHeader' and !$verbose) break;
                $headerEnd = null;  // (only test this once)
                // skip directly to footer if possible
                if ($footerPos and $footerPos > $pos and (!$verbose or !$unknown)) {
                    $et->VPrint(0, "[Skipping to footer. Use Unknown option to parse body partitions]\n");
                    if (!$raf->Seek($footerPos, 0)) break;
                    $pos = $footerPos;
                }
            }
            // read the next KLV Key and first byte of Value
            if ($raf->Read($buff, 17) != 17) break;
            $tag = substr($buff, 0, 16);    // get KLV Key (global tag ID)
            $len = ExifTool::Get8u($buff, 16);  // get KLV Length
            $n = null;
            if ($len >= 0x80) {
                $n = $len & 0x7f;
                if ($raf->Read($buff, $n) != $n) break;
                $len = 0;
                foreach (Perl::unpack('C*', $buff) as $b) {
                    $len = $len * 256 + $b;
                }
            } else {
                $n = 0;
            }
            // convert tag ID to ASCII UL notation
            $ul = self::UL($tag);
            $tagInfo = $tagTablePtr[$ul];
            if (!$tagInfo and preg_match('/^060e2b34\.0253\.0101\.(0d|0f)/', $ul, $m) and
                ($m[1] === '0d' or $verbose or $unknown))
            {
                // generate some unknown set tags automatically
                $name = $m[1] === '0d' ? 'UserOrganizationPublicUse' : 'Experimental';
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $ul, ['Name' => $name, ...Vars\MXF::$localSet]);
            }
            $val = $dataPt = null;
            if ($tagInfo and !$tagInfo->Unknown and $len < 10000000) {
                // save information about header/footer positions for skipping over body partitions
                if ($tagInfo->Name === 'Primer' and !empty($mxfInfo['HeaderSize'])) {
                    // footer position relative to header start and only valid if non-zero
                    if (!empty($mxfInfo['FooterPos'])) $footerPos = $start + $mxfInfo['FooterPos'];
                    // header length is relative to start of Primer
                    $headerEnd = $pos + $mxfInfo['HeaderSize'];
                } elseif ($tagInfo->IsHeader) {
                    // save position of header start to allow calculation of footer position
                    $start = $pos;
                }
                if ($raf->Read($buff, $len) != $len) break;   // get KLV Value
                $dataPt = $buff;
                $type = $tagInfo->Type;
                if ($type and !empty(Vars\MXF::$knownType[$type])) $val = self::ReadMXFValue($et, $buff, $type);
            } elseif (($tagInfo and (!$tagInfo->Unknown or $unknown)) or $verbose) {
                if ($tagInfo) {
                    // set Binary flag to extract all unknown-format tags as Binary data
                    if ($tagInfo->Unknown and $tagInfo->Binary === null) {
                        $type = $tagInfo->Type;
                        $tagInfo->Binary = !($tagInfo->Format or ($type and !empty(Vars\MXF::$knownType[$type])));
                    }
                } else {
                    $id = Perl::unpack('H*', $tag)[0];
                    $tagInfo = new TagInfo([
                        'Name'        => "MXF_$id",
                        'Description' => "MXF $id",
                    ]);
                    // note: don't add unknown tags to table because we don't
                    // want them to be extracted with the Unknown option
                }
                // read the first 64kB max
                // (PHP: $n2 is the "my $n" of this block, $n is still used below)
                $n2 = $len < 65536 ? $len : 65536;
                if ($raf->Read($val, $n2) != $n2) break;
                $dataPt = $val;
                $more = $len - $n2;
                if ($more and !$raf->Seek($more, 1)) break;
            } else {
                if (!$raf->Seek($len, 1)) break;    // skip this value
                continue;
            }
            $et->HandleTag($tagTablePtr, $ul, $val,
                TagInfo     : $tagInfo,
                DataPt      : $dataPt,
                DataPos     : $pos + 17 + $n,
                Size        : $len,
                ProcessProc : 'Image\ExifTool\MXF::ProcessLocalSet',
            );
        }
        // walk entire MXF object tree to fix family 1 group names
        $pathInfo = $tag = null;
        $did = $bestDur = [];
        if ($et->Options('SavePath')) $pathInfo = ['Path' => ['MXF']];
        foreach ($mxfInfo['Preface'] as $preface) {
            self::SetGroups($mxfInfo, $preface, $pathInfo);
        }
        self::FlushGroups($et);
        // convert Duration values to seconds based on the appropriate EditRate
        self::ConvertDurations($et, $mxfInfo);

        // remove tags to keep only the one from the most recent instance of the object
        $tagExtra = &$et->TAG_EXTRA;
        $fileOrder = $et->FILE_ORDER;
        // also determine our best Duration value
        if (!empty($mxfInfo['BestDuration'])) {
            $instance = ($mxfInfo['BestDuration']['Source'] ?? null) ?: ($mxfInfo['BestDuration']['Other'] ?? null);
            if ($instance) $bestDur["Duration $instance"] = 1;
        }
        // process tags in reverse order to preserve the last found of each identical tag
        $tags = array_keys($tagExtra);
        usort($tags, static fn ($a, $b) => ($fileOrder[$b] ?? 0) <=> ($fileOrder[$a] ?? 0));
        foreach ($tags as $tag) {
            $instance = $tagExtra[$tag]['UID'] ?? null;
            if (!$instance) continue;
            unset($tagExtra[$tag]['UID']);  // (no longer needed)
            preg_match('/^(\S+)/', (string) $tag, $m);  // get tag name without index number
            $utag = "$m[1] $instance";      // instance-specific tag name
            if (!empty($did[$utag])) {
                $et->DeleteTag($tag);       // delete the duplicate
            } else {
                $did[$utag] = 1;
                if (!empty($bestDur[$utag])) {
                    // save best duration value
                    $val = $et->VALUE[$tag] ?? null;
                    $et->HandleTag($tagTablePtr, '060e2b34.0101.0102.07020201.01030000', $val);
                }
            }
        }
        unset($tagExtra);

        // clean up and return
        $et->SET_GROUP1 = null;
        unset($mxfInfo);
        unset($et->MXFInfo);
        return 1;
    }
}
