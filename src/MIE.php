<?php

//------------------------------------------------------------------------------
// File:         MIE.pm
//
// Description:  Read/write MIE meta information
//
// Revisions:    11/18/2005 - P. Harvey Created
//
// PHP port of Image::ExifTool::MIE 1.57. Tag tables are generated into tables/MIE.php,
// the file lexicals ($hasZlib, %mieFormat, %mieCode, %mieMap...) into Vars\MIE.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Vars\MIE as Vars;

final class MIE
{
    public static $VERSION = '1.57';

    //------------------------------------------------------------------------------
    // validate raw values for writing
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and possibly changes value) on success
    public static function CheckMIE(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)
    {
        $format = $tagInfo->Writable ?: $tagInfo->Table['WRITABLE'];
        $err = null;

        if (!$format or Perl::str($format) === '1') return 'No writable format';
        // handle units if supported by this tag
        $ulist = $tagInfo->Units;
        if ($ulist and preg_match('/(.*)\((.*)\)$/', Perl::str($valPtr), $m)) {
            [$val, $units] = [$m[1], $m[2]];
            $units = array_values(@preg_grep(ExifTool::Rx("^$units\$", 'i'), $ulist) ?: [])[0] ?? null;
            if ($units === null) return 'Allowed units: (' . implode('|', $ulist) . ')';
            $err = ExifTool::CheckValue($val, $format, $tagInfo->Count);
            // add units back onto value
            if (!$err) $valPtr = Perl::str($val) . "($units)";
        } elseif (!preg_match('/^(utf|string|undef)/', $format) and preg_match('/\)$/', Perl::str($valPtr))) {
            return 'Units not supported';
        } else {
            if ($format === 'string' and $et->OPTIONS['Charset'] !== 'UTF8' and
                preg_match('/[\x80-\xff]/', Perl::str($valPtr)))
            {
                // convert from Charset to UTF-8
                $valPtr = $et->Encode($valPtr, 'UTF8');
            }
            $err = ExifTool::CheckValue($valPtr, $format, $tagInfo->Count);
        }
        return $err;
    }

    //------------------------------------------------------------------------------
    // Add user-defined MIE groups to %mieMap
    // Inputs: none;  Returns: nothing, but sets $doneMieMap flag
    public static function UpdateMieMap()
    {
        Vars::$doneMieMap = 1;  // set flag so we only do this once
        if (!ExifTool::$UserDefined) return;
        $tables = [];
        $doneTable = [];
        // get list of top-level MIE tables with user-defined tags
        foreach (array_keys(ExifTool::$UserDefined) as $tableName) {
            if (!preg_match('/^Image::ExifTool::MIE::/', $tableName)) continue;
            $tagTablePtr = ExifTool::GetTagTable($tableName);
            if (!$tagTablePtr) continue;
            // (PHP: the user-defined table may still be an array)
            $userTable = ExifTool::$UserDefined[$tableName];
            if (is_array($userTable)) $userTable = ExifTool::$UserDefined[$tableName] = new TagTable($userTable);
            // copy the WRITE_GROUP from the actual table
            $userTable['WRITE_GROUP'] = $tagTablePtr['WRITE_GROUP'];
            // add to list of tables to process
            $doneTable[$tableName] = 1;
            $tables[] = [$tableName, $userTable];
        }
        // recursively add all user-defined groups to MIE map
        while ($tables) {
            [$tableName, $tagTablePtr] = array_shift($tables);
            $parent = $tagTablePtr['WRITE_GROUP'];
            if (!$parent) {
                ExifTool::PerlWarn("No WRITE_GROUP for $tableName\n");
                continue;
            }
            if (!(Vars::$mieMap[$parent] ?? null)) {
                ExifTool::PerlWarn("$parent is not in MIE map\n");
                continue;
            }
            foreach (ExifTool::TagTableKeys($tagTablePtr) as $tagID) {
                $tagInfo = $tagTablePtr[$tagID];
                if (!($tagInfo instanceof TagInfo and $tagInfo->SubDirectory)) continue;
                $subTableName = $tagInfo->SubDirectory['TagTable'] ?? null;
                $subTablePtr = ExifTool::GetTagTable($subTableName);
                if (!$subTablePtr) continue;
                // only care about MIE tables
                if (!($subTablePtr['PROCESS_PROC'] and
                      $subTablePtr['PROCESS_PROC'] === 'Image\ExifTool\MIE::ProcessMIE')) continue;
                $group = $subTablePtr['WRITE_GROUP'];
                if (!$group) {
                    ExifTool::PerlWarn("No WRITE_GROUP for $subTableName\n");
                    continue;
                }
                if ((Vars::$mieMap[$group] ?? null) and Vars::$mieMap[$group] !== $parent) {
                    ExifTool::PerlWarn("$group already has different parent (" . Vars::$mieMap[$group] . ")\n");
                    continue;
                }
                Vars::$mieMap[$group] = $parent;    // add to map
                // process tables within this one too
                if ($doneTable[$subTableName] ?? null) continue;
                $doneTable[$subTableName] = 1;
                $tables[] = [$subTableName, $subTablePtr];
            }
        }
    }

    //------------------------------------------------------------------------------
    // Get localized version of tagInfo hash
    // Inputs: 0) tagInfo hash ref, 1) locale code (eg. "en_CA")
    // Returns: new tagInfo hash ref, or undef if invalid
    public static function GetLangInfo($tagInfo, $langCode)
    {
        // check for properly formatted language code
        if (!preg_match('/^[a-z]{2}([-_])[A-Z]{2}$/', Perl::str($langCode), $m)) return null;
        // use '_' as a separator, but recognize '_' or '-'
        if ($m[1] === '-') $langCode = strtr($langCode, '-', '_');
        // can only set locale on string types
        if ($tagInfo->Writable and Perl::str($tagInfo->Writable) !== 'string') return null;
        return ExifTool::GetLangInfo($tagInfo, $langCode);
    }

    //------------------------------------------------------------------------------
    // return true if we have Zlib::Compress
    // Inputs: 0) ExifTool object ref, 1) verb for what you want to do with the info
    // Returns: 1 if Zlib available, 0 otherwise
    public static function HasZlib(ExifTool $et, $verb)
    {
        if (Vars::$hasZlib === null) {
            Vars::$hasZlib = function_exists('inflate_init') ? 1 : 0;   // (Perl: require Compress::Zlib)
            if (!Vars::$hasZlib) {
                Vars::$hasZlib = 0;
                $et->Warn("Install Compress::Zlib to $verb compressed information");
            }
        }
        return Vars::$hasZlib;
    }

    //------------------------------------------------------------------------------
    // Get format code for MIE group element with current byte order
    // Inputs: 0) [optional] true to convert result to chr()
    // Returns: format code
    public static function MIEGroupFormat($chr = null)
    {
        $format = ExifTool::GetByteOrder() === 'MM' ? 0x10 : 0x18;
        return $chr ? chr($format) : $format;
    }

    //------------------------------------------------------------------------------
    // ReadValue() with added support for UTF formats (utf8, utf16 and utf32)
    // Inputs: 0) data reference, 1) value offset, 2) format string,
    //         3) number of values (or undef to use all data)
    //         4) valid data length relative to offset, 5) returned rational ref
    // Returns: converted value, or undefined if data isn't there
    //          or list of values in list context
    // Notes: all string formats are converted to UTF8
    public static function ReadMIEValue($dataPt, $offset, $format, $count, $size, &$ratPt = null)
    {
        $val = null;
        if (preg_match('/^(utf(8|16|32)|string)/', $format, $m)) {
            if ($m[1] === 'utf8' or $m[1] === 'string') {
                // read the 8-bit string
                $val = substr($dataPt, $offset, $size);
                // (as of ExifTool 7.62, leave string values unconverted)
            } else {
                // convert to UTF8
                if (ExifTool::GetByteOrder() === 'MM') {
                    $fmt = ($m[1] === 'utf16') ? 'n' : 'N';
                } else {
                    $fmt = ($m[1] === 'utf16') ? 'v' : 'V';
                }
                $unpk = Perl::unpack("x{$offset}{$fmt}{$size}", $dataPt);
                $val = Perl::pack('C0U*', ...$unpk);
            }
            // truncate at null unless this is a list
            // (strings shouldn't have a null, but just in case)
            if (!str_ends_with($format, '_list')) $val = preg_replace('/\0.*/s', '', $val);
        } else {
            if ($format === 'free') $format = 'undef';  // read 'free' as 'undef'
            return ExifTool::ReadValue($dataPt, $offset, $format, $count, $size, $ratPt);
        }
        return $val;
    }

    //------------------------------------------------------------------------------
    // Rewrite a MIE directory
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ptr
    // Returns: undef on success, otherwise error message (empty message if nothing to write)
    public static function WriteMIEGroup(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $outfile = $dirInfo['OutFile'];
        $dirName = $dirInfo['DirName'] ?? null;
        $toWrite = ($dirInfo['ToWrite'] ?? null) ?: '';
        $raf = $dirInfo['RAF'] ?? null;
        $verbose = $et->Options('Verbose');
        $optCompress = $et->Options('Compress');
        $out = $et->Options('TextOut');
        $msg = $err = $ok = $sync = $delGroup = null;
        $tag = '';
        $deletedTag = '';

        // count each MIE directory found and make name for this specific instance
        $isWriting = [];
        $cnt = &$et->MIE_COUNT;
        $grp = Perl::str($tagTablePtr['GROUPS'][1] ?? null);
        $n = ($cnt['MIE-Main'] ?? null) ?: 0;
        if ($grp === 'MIE-Main') {
            $cnt[$grp] = ++$n;
            $grp1 = preg_replace('/MIE-/', "MIE$n-", $grp, 1);
        } else {
            $grp1 = preg_replace('/MIE-/', "MIE$n-", $grp, 1);
            $m = $cnt[$grp1] = (($cnt[$grp1] ?? null) ?: 0) + 1;
            $isWriting["$grp$m"] = 1;   // eg. 'MIE-Doc2'
            $isWriting[$grp1] = 1;      // eg. 'MIE1-Doc'
            $grp1 .= $m;
        }
        unset($cnt);
        // build lookup for all valid group names for this MIE group
        $isWriting[$grp] = 1;           // eg. 'MIE-Doc'
        $isWriting[$grp1] = 1;          // eg. 'MIE1-Doc2'
        $isWriting["MIE$n"] = 1;        // eg. 'MIE1'

        // determine if we are deleting this group
        if ($et->DEL_GROUP) {
            if (($et->DEL_GROUP['MIE'] ?? null) or
                ($et->DEL_GROUP[$grp] ?? null) or
                ($et->DEL_GROUP[$grp1] ?? null) or
                ($et->DEL_GROUP["MIE$n"] ?? null)) $delGroup = 1;
        }

        // prepare lookups and lists for writing
        $newTags = $et->GetNewTagInfoHash($tagTablePtr);
        [$addDirs, $editDirs] = $et->GetAddDirHashList($tagTablePtr, $dirName);
        $editTags = array_map('strval', [...array_keys($newTags), ...array_keys($editDirs)]);
        sort($editTags, SORT_STRING);
        if ($verbose) fwrite($out, ($raf ? 'Writing' : 'Creating') . " $grp1:\n");

        // loop through elements in MIE group
        for (;;) {      // (MieElement)
            $format = $tagLen = $valLen = $units = $oldHdr = $buff = null;
            $lastTag = $tag;
            if ($raf) {
                // read first 4 bytes of element header
                $n = $raf->Read($oldHdr, 4);
                if ($n != 4) {
                    if ($n or $sync !== null) break;
                    $raf = null;    // all done reading
                    $ok = 1;
                }
            }
            if ($raf) {
                [$sync, $format, $tagLen, $valLen] = Perl::unpack('aC3', $oldHdr);
                if ($sync !== '~') {
                    $msg = 'Invalid sync byte';
                    break;
                }

                // read tag name
                if ($tagLen) {
                    if ($raf->Read($tag, $tagLen) != $tagLen) break;
                    $oldHdr .= $tag;    // add tag to element header
                    if (strcmp($tag, $lastTag) < 0) $et->Warn("MIE tag '{$tag}' out of sequence");
                    // separate units from tag name if they exist
                    if (preg_match('/\((.*)\)$/', $tag, $m, PREG_OFFSET_CAPTURE)) {
                        $units = $m[1][0];
                        $tag = substr_replace($tag, '', $m[0][1], strlen($m[0][0]));
                    }
                } else {
                    $tag = '';
                }

                // get multi-byte value length if necessary
                if ($valLen > 252) {
                    // calculate number of bytes in extended DataLength
                    $n = 1 << (256 - $valLen);
                    if ($raf->Read($buff, $n) != $n) break;
                    $oldHdr .= $buff;   // add to old header
                    $fmt = 'int' . ($n * 8) . 'u';
                    $valLen = ExifTool::ReadValue($buff, 0, $fmt, 1, $n);
                    if ($valLen > 0x7fffffff) {
                        $msg = "Can't write $tag (DataLength > 2GB not yet supported)";
                        break;
                    }
                }
                // don't rewrite free bytes or information in deleted groups
                if ($format == 0x80 or ($delGroup and $tagLen and ($format & 0xf0) != 0x10)) {
                    if (!$raf->Seek($valLen, 1)) {
                        $msg = 'Seek error';
                        break;
                    }
                    if ($verbose > 1) {
                        $free = ($format == 0x80) ? ' free' : '';
                        fwrite($out, "    - $grp1:$tag ($valLen$free bytes)\n");
                    }
                    if ($delGroup) ++$et->CHANGED;
                    continue;
                }
            } else {
                // no more elements to read
                $tagLen = $valLen = 0;
                $tag = '';
            }
//
// write necessary new tags and process directories
//
            while ($editTags) {
                if ($tagLen and strcmp($editTags[0], $tag) > 0) break;
                // we are writing the new tag now
                $newVal = $writable = $oldVal = $newFormat = $compress = null;
                $newTag = array_shift($editTags);
                if (strlen($newTag) > 255) {
                    $et->Warn('Tag name too long');     // (just to be safe)
                    continue;
                }
                $newInfo = $editDirs[$newTag] ?? null;
                if ($newInfo) {
                    // create the new subdirectory or rewrite existing non-MIE directory
                    $subTablePtr = ExifTool::GetTagTable($newInfo->SubDirectory['TagTable'] ?? null);
                    if (!$subTablePtr) {
                        $et->Warn("No tag table for $newTag {$newInfo->Name}");
                        continue;
                    }
                    $subdirInfo = [];
                    $isMieGroup = ($subTablePtr['WRITE_PROC'] and
                                   $subTablePtr['WRITE_PROC'] === 'Image\ExifTool\MIE::ProcessMIE');

                    if ($newTag === $tag) {
                        // make sure that either both or neither old and new tags are MIE groups
                        if ($isMieGroup xor ($format & 0xf3) == 0x10) {
                            $et->Warn("Tag '{$tag}' not expected type");
                            continue;   // don't write our new tag
                        }
                        // uncompress existing directory into $oldVal since we are editing it
                        if ($format & 0x04) {
                            if (!self::HasZlib($et, 'edit')) break;
                            if ($raf->Read($oldVal, $valLen) != $valLen) break 2;
                            // (Perl: Compress::Zlib::inflateInit() and inflate())
                            $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                            $v2 = $inflate ? @inflate_add($inflate, $oldVal, ZLIB_SYNC_FLUSH) : false;
                            if (!($inflate and $v2 !== false and inflate_get_status($inflate) == ZLIB_STREAM_END)) {
                                $msg = "Error inflating $tag";
                                break 2;
                            }
                            $oldVal = $v2;
                            $compress = 1;
                            $valLen = strlen($oldVal);  // uncompressed value length
                        }
                    } else {
                        // don't create this directory unless necessary
                        if (!($addDirs[$newTag] ?? null)) continue;
                    }

                    if ($isMieGroup) {
                        $hdr = $newValPt = null;    // (PHP: $newValPt is \$newVal)
                        if ($newTag === $tag) {
                            // rewrite existing directory later unless it was compressed
                            if (!$compress) break;
                            // rewrite directory to '$newVal'
                            $newValPt = new ScalarRef('');
                            $subdirInfo = [
                                'OutFile' => $newValPt,
                                'RAF' => new RandomAccess($oldVal),
                            ];
                        } elseif ($optCompress and !($dirInfo['IsCompressed'] ?? null)) {
                            // write to memory so we can compress the new MIE group
                            $compress = 1;
                            $newValPt = new ScalarRef(null);
                            $subdirInfo = [
                                'OutFile' => $newValPt,
                            ];
                        } else {
                            $hdr = '~' . self::MIEGroupFormat(1) . chr(strlen($newTag)) .
                                   "\0" . $newTag;
                            $subdirInfo = [
                                'OutFile' => $outfile,
                                'ToWrite' => $toWrite . $hdr,
                            ];
                        }
                        $subdirInfo['DirName'] = ($newInfo->SubDirectory['DirName'] ?? null) ?: $newTag;
                        $subdirInfo['Parent'] = $dirName;
                        // don't compress elements of an already compressed group
                        $subdirInfo['IsCompressed'] = ($dirInfo['IsCompressed'] ?? null) ?: $compress;
                        $msg = self::WriteMIEGroup($et, $subdirInfo, $subTablePtr);
                        if ($newValPt) $newVal = $newValPt->value;
                        if ($msg) break 2;
                        // message is defined but empty if nothing was written
                        if ($msg !== null) {
                            $msg = null;    // not a problem if nothing was written
                            continue;
                        } elseif (!$compress) {
                            // group was written already
                            $toWrite = '';
                            continue;
                        } elseif (strlen(Perl::str($newVal)) <= 4) {   // terminator only?
                            if ($verbose) fwrite($out, "Deleted compressed $grp1 (empty)\n");
                            if ($newTag === $tag) continue 2;   // deleting the directory
                            continue;   // not creating the new directory
                        }
                        $writable = 'undef';
                        $newFormat = self::MIEGroupFormat();
                    } else {
                        if ($newTag === $tag) {
                            if (!$compress) {
                                // read and edit existing directory
                                if ($raf->Read($oldVal, $valLen) != $valLen) break 2;
                            }
                            $subdirInfo = [
                                'DataPt'  => $oldVal,
                                'DataLen' => $valLen,
                                'DirName' => $newInfo->Name,
                                'DataPos' => ($dirInfo['IsCompressed'] ?? null) ? null : $raf->Tell() - $valLen,
                                'DirStart'=> 0,
                                'DirLen'  => $valLen,
                            ];
                            // write Compact subdirectories if we will compress the data
                            // (Perl: eval { require Compress::Zlib })
                            if (($compress or $optCompress or ($dirInfo['IsCompressed'] ?? null)) and
                                function_exists('deflate_init'))
                            {
                                $subdirInfo['Compact'] = 1;
                                $subdirInfo['ReadOnly'] = 1;    // because XMP is not writable in place
                            }
                        }
                        $subdirInfo['Parent'] = $dirName;
                        $writeProc = $newInfo->SubDirectory['WriteProc'] ?? null;
                        // reset processed lookup to avoid errors in case of multiple EXIF blocks
                        $et->PROCESSED = [];
                        $newVal = $et->WriteDirectory($subdirInfo, $subTablePtr, $writeProc);
                        if ($newVal !== null) {
                            if ($newVal === '') {
                                if ($newTag === $tag) continue 2;   // deleting the directory
                                continue;   // not creating the new directory
                            }
                        } else {
                            if ($oldVal === null) continue;
                            $newVal = $oldVal;  // just copy over the old directory
                        }
                        $writable = 'undef';
                        $newFormat = 0x00;  // all other directories are 'undef' format
                    }
                } else {

                    // get the new tag information
                    $newInfo = $newTags[$newTag];
                    $nvHash = $et->GetNewValueHash($newInfo);
                    $newVals = [];

                    // write information only to specified group
                    $writeGroup = $nvHash ? Perl::str($nvHash->WriteGroup) : '';
                    if (!($isWriting[$writeGroup] ?? null)) break;

                    // if tag existed, must decide if we want to overwrite the value
                    if ($newTag === $tag) {
                        $isList = $newInfo->List;
                        if ($isList) {
                            if ($nvHash->CreateOnly) break;
                            $isOverwriting = -1;    // force processing list elements individually
                        } else {
                            $isOverwriting = $et->IsOverwriting($nvHash);
                            if (!$isOverwriting) break;
                        }
                        $val = $cmpVal = null;
                        if ($isOverwriting < 0 or $verbose > 1) {
                            // check to be sure we can uncompress the value if necessary
                            if ($format & 0x04 and !self::HasZlib($et, 'edit')) break;
                            // read the old value
                            if ($raf->Read($oldVal, $valLen) != $valLen) break 2;
                            // uncompress if necessary
                            if ($format & 0x04) {
                                // must save original compressed value in case we decide
                                // not to overwrite it later
                                $cmpVal = $oldVal;
                                // (Perl: Compress::Zlib::inflateInit() and inflate())
                                $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                                $v2 = $inflate ? @inflate_add($inflate, $oldVal, ZLIB_SYNC_FLUSH) : false;
                                if (!($inflate and $v2 !== false and inflate_get_status($inflate) == ZLIB_STREAM_END)) {
                                    $msg = "Error inflating $tag";
                                    break 2;
                                }
                                $oldVal = $v2;
                                $valLen = strlen($oldVal);  // update value length
                            }
                            // convert according to specified format
                            $formatStr = (Vars::$mieFormat[$format & 0xfb] ?? null) ?: 'undef';
                            $val = self::ReadMIEValue($oldVal, 0, $formatStr, null, $valLen);
                            if ($isOverwriting < 0 and $val !== null) {
                                // handle list values individually
                                if ($isList) {
                                    if (str_ends_with($formatStr, '_list')) {
                                        $vals = Perl::split('/\0/', $val);
                                    } else {
                                        $vals = [$val];
                                    }
                                    // keep any list items that we aren't overwriting
                                    foreach ($vals as $v) {
                                        if ($et->IsOverwriting($nvHash, $v)) continue;
                                        $newVals[] = $v;
                                    }
                                } else {
                                    // test to see if we really want to overwrite the value
                                    $isOverwriting = $et->IsOverwriting($nvHash, $val);
                                }
                            }
                        }
                        if ($isOverwriting) {
                            // skip the old value if we didn't read it already
                            if ($oldVal === null) {
                                if (!$raf->Seek($valLen, 1)) $msg = 'Seek error';
                            }
                            if ($verbose > 1) {
                                if ($units !== null) $val = Perl::str($val) . "($units)";
                                $et->VerboseValue("- $grp1:{$newInfo->Name}", $val);
                            }
                            $deletedTag = $tag;     // remember that we deleted this tag
                            ++$et->CHANGED;     // we deleted the old value
                        } else {
                            if ($oldVal !== null) {
                                // write original compressed value
                                if ($cmpVal !== null) $oldVal = $cmpVal;
                            } else {
                                if ($raf->Read($oldVal, $valLen) != $valLen) break 2;
                            }
                            // write the old value now
                            if (!ExifTool::Write($outfile, $toWrite, $oldHdr, $oldVal)) $err = 1;
                            $toWrite = '';
                            continue 2;
                        }
                        if (!$newVals) {
                            // unshift the new tag info to write it later
                            array_unshift($editTags, $newTag);
                            continue 2;     // get next element from file
                        }
                    } else {
                        // write new value if creating, or if List and list existed, or
                        // if tag was previously deleted
                        if (!($nvHash->IsCreating or
                            ($newTag === $lastTag and ($newInfo->List or $deletedTag === $lastTag)))) continue;
                    }
                    // get the new value to write (undef to delete)
                    array_push($newVals, ...$et->GetNewValueList($nvHash));
                    if (!$newVals) continue;
                    $writable = Perl::str($newInfo->Writable ?: $tagTablePtr['WRITABLE']);
                    if ($writable === 'string') {
                        // join multiple values into a single string
                        $newVal = implode("\0", array_map([Perl::class, 'str'], $newVals));
                        // write string as UTF-8,16 or 32 if value contains valid UTF-8 codes
                        $isUTF8 = ExifTool::IsUTF8($newVal);
                        if ($isUTF8 > 0) {
                            $writable = 'utf8';
                            // write UTF-16 or UTF-32 if it is more compact
                            $to = $isUTF8 > 1 ? 'UCS4' : 'UTF16';
                            $tmp = ExifTool::DecodeStatic(null, $newVal, 'UTF8', null, $to);
                            if (strlen($tmp) < strlen($newVal)) {
                                $newVal = $tmp;
                                $writable = ($isUTF8 > 1) ? 'utf32' : 'utf16';
                            }
                        }
                        // write as a list if we have multiple values
                        if (count($newVals) > 1) $writable .= '_list';
                    } else {
                        // should only be one element in the list
                        $newVal = array_shift($newVals);
                    }
                    $newFormat = Vars::$mieCode[$writable] ?? null;
                    if ($newFormat === null) {
                        $msg = "Bad format '{$writable}' for {$newInfo->Name}";
                        continue 2;
                    }
                }

                // write the new or edited element
                while ($newFormat !== null) {
                    $valPt = $newVal;   // (Perl: $valPt = \$newVal)
                    // remove units from value and add to tag name if supported by this tag
                    if ($newInfo->Units) {
                        if (preg_match('/(.*)\((.*)\)$/', Perl::str($valPt), $m)) {
                            $val2 = $m[1];
                            $newTag .= "($m[2])";
                        } else {
                            $val2 = $valPt;
                            // add default units
                            $ustr = '(' . $newInfo->Units[0] . ')';
                            $newTag .= $ustr;
                            $newVal .= $ustr;   // (Perl: $$valPt .= $ustr)
                        }
                        $valPt = $val2;
                    }
                    // convert value if necessary
                    if (!preg_match('/^(utf|string|undef)/', $writable)) {
                        $val3 = ExifTool::WriteValue($valPt, $writable, $newInfo->Count);
                        if ($val3 === null) {
                            $et->Warn("Error writing $newTag");
                            break;
                        }
                        $valPt = $val3;
                    }
                    $valPt = Perl::str($valPt);
                    $len = strlen($valPt);
                    // compress value before writing if required
                    if (($compress or $optCompress) and !($dirInfo['IsCompressed'] ?? null) and
                        self::HasZlib($et, 'write'))
                    {
                        // (Perl: Compress::Zlib::deflateInit(), deflate() and flush())
                        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['memory' => 9]);
                        $val4 = null;
                        if ($deflate) {
                            $val4 = deflate_add($deflate, $valPt, ZLIB_FINISH);
                            if ($val4 === false) $val4 = null;
                        }
                        if ($val4 !== null) {
                            $len4 = strlen($val4);
                            $saved = $len - $len4;
                            // only use compressed data if it is smaller
                            if ($saved > 0) {
                                if ($verbose) fwrite($out, "  [$newTag compression saved $saved bytes]\n");
                                $newFormat |= 0x04; // set compressed bit
                                $len = $len4;       // set length
                                $valPt = $val4;     // set value pointer
                            } elseif ($verbose) {
                                fwrite($out, "  [$newTag compression saved $saved bytes -- written uncompressed]\n");
                            }
                        } else {
                            $et->Warn("Error deflating $newTag (written uncompressed)");
                        }
                    }
                    // calculate the DataLength code
                    if ($len < 253) {
                        $extLen = '';
                    } elseif ($len < 65536) {
                        $extLen = ExifTool::Set16u($len);
                        $len = 255;
                    } elseif ($len <= 0x7fffffff) {
                        $extLen = ExifTool::Set32u($len);
                        $len = 254;
                    } else {
                        $et->Warn("Can't write $newTag (DataLength > 2GB not yet supported)");
                        break;  // don't write this tag
                    }
                    // write this element (with leading MIE group element if not done already)
                    $hdr = $toWrite . '~' . chr($newFormat) . chr(strlen($newTag));
                    if (!ExifTool::Write($outfile, $hdr, chr($len), $newTag, $extLen, $valPt)) $err = 1;
                    $toWrite = '';
                    // we changed a tag unless just editing a subdirectory
                    if (!($editDirs[$newTag] ?? null)) {
                        $et->VerboseValue("+ $grp1:{$newInfo->Name}", $newVal);
                        ++$et->CHANGED;
                    }
                    break;  // didn't want to loop anyway
                }
                if ($oldVal !== null) continue 2;
            }
//
// rewrite existing element or descend into uncompressed MIE group
//
            // all done this MIE group if we reached the terminator element
            if (!$tagLen) {
                // skip over existing terminator data (if any)
                if ($valLen and !$raf->Seek($valLen, 1)) break;
                $ok = 1;
                // write group terminator if necessary
                if (!$toWrite) {
                    // write end-of-group terminator element
                    $term = "~\0\0\0";
                    if (!($dirInfo['Parent'] ?? null)) {
                        // write extended terminator for file-level group
                        $len = ExifTool::Tell($outfile);    // (length of scalar or file position)
                        // include length of terminator itself minus original $outfile position
                        $len += 10 - (($dirInfo['OutPos'] ?? null) ?: 0);
                        if ($len and $len <= 0x7fffffff) {
                            $term = "~\0\0\x06" . ExifTool::Set32u($len) . self::MIEGroupFormat(1) . "\x04";
                        }
                    }
                    if (!ExifTool::Write($outfile, $term)) $err = 1;
                }
                break;
            }

            // descend into existing uncompressed MIE group
            if ($format == 0x10 or $format == 0x18) {
                // (PHP: $subDirName is Perl's inner "my $dirName", which hides the outer one)
                $subTablePtr = $subDirName = null;
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if ($tagInfo and $tagInfo->SubDirectory) {
                    $subDirName = $tagInfo->SubDirectory['DirName'] ?? null;
                    $subTable = $tagInfo->SubDirectory['TagTable'] ?? null;
                    $subTablePtr = $subTable ? ExifTool::GetTagTable($subTable) : $tagTablePtr;
                } else {
                    $subTablePtr = ExifTool::GetTagTable('Image::ExifTool::MIE::Unknown');
                }
                $hdr = '~' . chr($format) . chr(strlen($tag)) . "\0" . $tag;
                $subdirInfo = [
                    'DirName' => $subDirName ?: $tag,
                    'RAF'     => $raf,
                    'ToWrite' => $toWrite . $hdr,
                    'OutFile' => $outfile,
                    'Parent'  => $subDirName,
                    'IsCompressed' => $dirInfo['IsCompressed'] ?? null,
                ];
                $oldOrder = ExifTool::GetByteOrder();
                ExifTool::SetByteOrder($format & 0x08 ? 'II' : 'MM');
                $msg = self::WriteMIEGroup($et, $subdirInfo, $subTablePtr);
                ExifTool::SetByteOrder($oldOrder);
                if ($msg) break;
                if ($msg !== null) {
                    $msg = null;    // no problem if nothing written
                } else {
                    $toWrite = '';
                }
                continue;
            }
            // just copy existing element
            $oldVal = null;
            if ($raf->Read($oldVal, $valLen) != $valLen) break;
            if ($toWrite) {
                if (!ExifTool::Write($outfile, $toWrite)) $err = 1;
                $toWrite = '';
            }
            if (!ExifTool::Write($outfile, $oldHdr, $oldVal)) $err = 1;
        }
        // return error message
        if ($err) {
            $msg = 'Error writing file';
        } elseif (!$ok and !$msg) {
            $msg = 'Unexpected end of file';
        } elseif (!$msg and $toWrite) {
            $msg = '';  // flag for nothing written
            if ($verbose) fwrite($out, "Deleted $grp1 (empty)\n");
        }
        return $msg;
    }

    //------------------------------------------------------------------------------
    // Process MIE directory
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference, 2) tag table ref
    // Returns: undef on success, or error message if there was a problem
    // Notes: file pointer is positioned at the MIE end on entry
    public static function ProcessMIEGroup(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)
    {
        $raf = $dirInfo['RAF'];
        $verbose = $et->Options('Verbose');
        $out = $et->Options('TextOut');
        $notUTF8 = (Perl::str($et->OPTIONS['Charset']) !== 'UTF8');
        $msg = $buff = $ok = $oldIndent = $mime = null;
        $lastTag = '';

        // get group 1 names: $grp doesn't have numbers (eg. 'MIE-Doc'),
        // and $grp1 does (eg. 'MIE1-Doc1')
        $cnt = &$et->MIE_COUNT;
        $grp1 = Perl::str($tagTablePtr['GROUPS'][1] ?? null);
        $n = ($cnt['MIE-Main'] ?? null) ?: 0;
        if ($grp1 === 'MIE-Main') {
            $cnt[$grp1] = ++$n;
            if ($n > 1) $grp1 = preg_replace('/MIE-/', "MIE$n-", $grp1, 1);
        } else {
            if ($n > 1) $grp1 = preg_replace('/MIE-/', "MIE$n-", $grp1, 1);
            $cnt[$grp1] = (($cnt[$grp1] ?? null) ?: 0) + 1;
            if ($cnt[$grp1] > 1) $grp1 .= $cnt[$grp1];
        }
        unset($cnt);
        // set group1 name for all tags extracted from this group
        $et->SET_GROUP1 = $grp1;

        if ($verbose) {
            $oldIndent = $et->INDENT;
            $et->INDENT .= '| ';
            $et->VerboseDir($grp1);
        }
        $wasCompressed = $dirInfo['WasCompressed'] ?? null;

        // process all MIE elements
        for (;;) {
            if ($raf->Read($buff, 4) != 4) break;
            [$sync, $format, $tagLen, $valLen] = Perl::unpack('aC3', $buff);
            if ($sync !== '~') {
                $msg = 'Invalid sync byte';
                break;
            }

            // read tag name
            $tag = $units = null;
            if ($tagLen) {
                if ($raf->Read($tag, $tagLen) != $tagLen) break;
                if (strcmp($tag, $lastTag) < 0) $et->Warn("MIE tag '{$tag}' out of sequence");
                $lastTag = $tag;
                // separate units from tag name if they exist
                if (preg_match('/\((.*)\)$/', $tag, $m, PREG_OFFSET_CAPTURE)) {
                    $units = $m[1][0];
                    $tag = substr_replace($tag, '', $m[0][1], strlen($m[0][0]));
                }
            } else {
                $tag = '';
            }

            // get multi-byte value length if necessary
            if ($valLen > 252) {
                $n = 1 << (256 - $valLen);
                if ($raf->Read($buff, $n) != $n) break;
                $fmt = 'int' . ($n * 8) . 'u';
                $valLen = ExifTool::ReadValue($buff, 0, $fmt, 1, $n);
                if ($valLen > 0x7fffffff) {
                    $msg = "Can't read $tag (DataLength > 2GB not yet supported)";
                    break;
                }
            }

            // all done if we reached the group terminator
            if (!$tagLen) {
                // skip over terminator data block
                if (!($valLen and !$raf->Seek($valLen, 1))) $ok = 1;
                break;
            }

            // get tag information hash unless this is free space
            $tagInfo = $value = null;
            while ($format != 0x80) {
                $tagInfo = $et->GetTagInfo($tagTablePtr, $tag);
                if ($tagInfo) break;
                // extract tags with locale code
                if (preg_match('/\W/', $tag)) {
                    if (preg_match('/^(\w+)-([a-z]{2}_[A-Z]{2})$/', $tag, $m)) {
                        [$baseTag, $langCode] = [$m[1], $m[2]];
                        $tagInfo = $et->GetTagInfo($tagTablePtr, $baseTag);
                        if ($tagInfo) $tagInfo = self::GetLangInfo($tagInfo, $langCode);
                        if ($tagInfo) break;
                    } else {
                        $et->Warn('Invalid MIE tag name');
                        break;
                    }
                }
                // extract unknown tags if specified
                $tagInfo = ExifTool::AddTagToTable($tagTablePtr, $tag, [
                    'Name' => $tag,
                    'Writable' => 0,
                    'PrintConv' => 'Image\ExifTool\ExifTool::LimitLongValues',
                ]);
                break;
            }

            // read value and uncompress if necessary
            $formatStr = (Vars::$mieFormat[$format & 0xfb] ?? null) ?: 'undef';
            if ($tagInfo or ($formatStr === 'MIE' and $format & 0x04)) {
                if ($raf->Read($value, $valLen) != $valLen) break;
                if ($format & 0x04) {
                    if ($verbose) {
                        fwrite($out, "{$et->INDENT}[Tag '{$tag}' $valLen bytes compressed]\n");
                    }
                    if (!self::HasZlib($et, 'decode')) continue;
                    // (Perl: Compress::Zlib::inflateInit() and inflate())
                    $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
                    $v2 = $inflate ? @inflate_add($inflate, $value, ZLIB_SYNC_FLUSH) : false;
                    if (!($inflate and $v2 !== false and inflate_get_status($inflate) == ZLIB_STREAM_END)) {
                        $et->Warn("Error inflating $tag");
                        continue;
                    }
                    $value = $v2;
                    $valLen = strlen($value);
                    $wasCompressed = 1;
                }
            }

            // process this tag
            if ($formatStr === 'MIE') {
                // process MIE directory
                $subTablePtr = $dirName = null;
                if ($tagInfo and $tagInfo->SubDirectory) {
                    $dirName = $tagInfo->SubDirectory['DirName'] ?? null;
                    $subTable = $tagInfo->SubDirectory['TagTable'] ?? null;
                    $subTablePtr = $subTable ? ExifTool::GetTagTable($subTable) : $tagTablePtr;
                } else {
                    $subTablePtr = ExifTool::GetTagTable('Image::ExifTool::MIE::Unknown');
                }
                if ($verbose) {
                    $order = ', byte order ' . ExifTool::GetByteOrder();
                    $et->VerboseInfo($tag, $tagInfo, Size: $valLen, Extra: $order);
                }
                $subdirInfo = [
                    'DirName' => $dirName ?: $tag,
                    'RAF'     => $raf,
                    'Parent'  => $dirInfo['DirName'] ?? null,
                    'WasCompressed' => $wasCompressed,
                ];
                // read from uncompressed data instead if necessary
                if ($valLen) $subdirInfo['RAF'] = new RandomAccess(Perl::str($value));

                $oldOrder = ExifTool::GetByteOrder();
                ExifTool::SetByteOrder($format & 0x08 ? 'II' : 'MM');
                $msg = self::ProcessMIEGroup($et, $subdirInfo, $subTablePtr);
                ExifTool::SetByteOrder($oldOrder);
                $et->SET_GROUP1 = $grp1;    // restore this group1 name
                if ($msg) break;
            } else {
                // process MIE data format types
                if ($tagInfo) {
                    $rational = $binVal = null;
                    // extract tag value
                    $val = self::ReadMIEValue($value, 0, $formatStr, null, $valLen, $rational);
                    if ($et->OPTIONS['SaveBin']) $binVal = substr($value, 0, $valLen);
                    if ($val === null) {
                        $et->Warn("Error reading $tag value");
                        $val = '<err>';
                    }
                    // save type or mime type
                    if ($tag === '0Type' or $tag === '2MIME') $mime = $val;
                    if ($verbose) {
                        $count = null;
                        $s = ExifTool::FormatSize($formatStr);
                        if ($s and !preg_match('/^(utf|string|undef)/', $formatStr)) {
                            $count = $valLen / $s;
                        }
                        $et->VerboseInfo($lastTag, $tagInfo,
                            DataPt: $value,
                            DataPos: $wasCompressed ? null : $raf->Tell() - $valLen,
                            Size: $valLen,
                            Format: $formatStr,
                            Value: $val,
                            Count: $count,
                        );
                    }
                    if ($tagInfo->SubDirectory) {
                        $subTablePtr = ExifTool::GetTagTable($tagInfo->SubDirectory['TagTable'] ?? null);
                        $subdirInfo = [
                            'DirName' => $tagInfo->Name,
                            'DataPt'  => $value,
                            'DataLen' => $valLen,
                            'DirStart' => 0,
                            'DirLen'  => $valLen,
                            'Parent'  => $dirInfo['DirName'] ?? null,
                            'WasCompressed' => $wasCompressed,
                        ];
                        // set DataPos and Base for uncompressed information only
                        if (!$wasCompressed) {
                            $subdirInfo['DataPos'] = 0; // (relative to Base)
                            $subdirInfo['Base']    = $raf->Tell() - $valLen;
                        }
                        // reset PROCESSED lookup for each MIE directory
                        // (there is no possibility of double-processing a MIE directory)
                        $et->PROCESSED = [];
                        $processProc = $tagInfo->SubDirectory['ProcessProc'] ?? null;
                        $et->SET_GROUP1 = null;
                        $et->NO_LIST = null;
                        $et->ProcessDirectory($subdirInfo, $subTablePtr, $processProc);
                        $et->SET_GROUP1 = $grp1;
                        $et->NO_LIST = 1;
                    } else {
                        // convert to specified character set if necessary
                        if ($notUTF8 and preg_match('/^(utf|string)/', $formatStr)) {
                            $val = $et->Decode($val, 'UTF8');
                        }
                        if (str_ends_with($formatStr, '_list')) {
                            // split list value into separate strings
                            $val = Perl::split('/\0/', $val);
                        }
                        if ($units !== null) {
                            if (is_array($val)) $val = implode(' ', $val);  // convert string list to number list
                            // add units to value if specified
                            $val = Perl::str($val) . "($units)";
                        }
                        $key = $et->FoundTag($tagInfo, $val);
                        if ($key !== null) {
                            if ($rational !== null) $et->TAG_EXTRA[$key]['Rational'] = $rational;
                            if ($binVal !== null) $et->TAG_EXTRA[$key]['BinVal'] = $binVal;
                            if ($et->OPTIONS['SaveFormat']) $et->TAG_EXTRA[$key]['G6'] = $formatStr;
                        }
                    }
                } else {
                    // skip over unknown information or free bytes
                    if (!$raf->Seek($valLen, 1)) {
                        $msg = 'Seek error';
                        break;
                    }
                    if ($verbose) $et->VerboseInfo($tag, null, Size: $valLen);
                }
            }
        }
        // modify MIME type if necessary
        if ($mime and empty($dirInfo['Parent'])) $et->ModifyMimeType($mime);

        if (!($ok or $msg)) $msg = 'Unexpected end of file';
        if ($verbose) $et->INDENT = $oldIndent;
        return $msg;
    }

    //------------------------------------------------------------------------------
    // Read/write a MIE file
    // Inputs: 0) ExifTool object reference, 1) DirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid MIE file, or -1 on write error
    // - process as a trailer if "Trailer" flag set in dirInfo
    public static function ProcessMIE(?ExifTool $et = null, ?array &$dirInfo = null)
    {
        if ($et === null) return 1;
        $raf = $dirInfo['RAF'];
        $outfile = $dirInfo['OutFile'] ?? null;
        $buff = $err = $msg = $pos = $end = $isCreating = null;
        $numDocs = 0;
//
// process as a trailer (from end of file) if specified
//
        if (!empty($dirInfo['Trailer'])) {
            $offset = ($dirInfo['Offset'] ?? null) ?: 0;    // offset from end of file
            if (!$raf->Seek(-10 - $offset, 2)) return 0;
            for (;;) {
                // read and validate last 10 bytes
                if ($raf->Read($buff, 10) != 10) break;
                if (!(preg_match('/~\0\0\x06.{4}(\x10|\x18)(\x04)$/s', $buff, $m) or
                      preg_match('/(\x10|\x18)(\x08)$/s', $buff, $m))) break;
                ExifTool::SetByteOrder($m[1] === "\x10" ? 'MM' : 'II');
                $len = ($m[2] === "\x04") ? ExifTool::Get32u($buff, 4) : ExifTool::Get64u($buff, 0);
                $curPos = $raf->Tell();
                if (!$curPos) break;
                if ($len < 12 or $len > $curPos) break;
                // validate element header if 8-byte offset was used
                if ($m[2] === "\x08") {
                    if ($len < 14) break;
                    if (!($raf->Seek($curPos - 14, 0) and $raf->Read($buff, 4))) break;
                    if ($buff !== "~\0\0\x0a") break;
                }
                // looks like a good group, so remember start position
                $pos = $curPos - $len;
                if (!$end) $end = $curPos;
                // seek to 10 bytes from end of previous group
                if (!$raf->Seek($pos - 10, 0)) break;
            }
            // seek to start of first MIE group
            if (!($pos !== null and $raf->Seek($pos, 0))) return 0;
            // update DataPos and DirLen for ProcessTrailers()
            $dirInfo['DataPos'] = $pos;
            $dirInfo['DirLen'] = $end - $pos;
            if ($outfile and ($et->DEL_GROUP['MIE'] ?? null)) {
                // delete the trailer
                $et->VPrint(0, "  Deleting MIE trailer\n");
                ++$et->CHANGED;
                return 1;
            } elseif ($et->Options('Verbose') or $et->HTML_DUMP) {
                $et->DumpTrailer($dirInfo);
            }
        }
//
// loop through all documents in MIE file
//
        for (;;) {
            // look for "0MIE" group element
            $num = $raf->Read($buff, 8);
            if ($num == 8) {
                // verify file identifier
                if (preg_match('/^~(\x10|\x18)\x04(.)0MIE/s', $buff, $m)) {
                    ExifTool::SetByteOrder($m[1] === "\x10" ? 'MM' : 'II');
                    $len = ord($m[2]);
                    // skip extended DataLength if it exists
                    if ($len > 252 and !$raf->Seek(1 << (256 - $len), 1)) {
                        $msg = 'Seek error';
                        break;
                    }
                } else {
                    if (!$numDocs) return 0;    // not a MIE file
                    if (str_starts_with($buff, '~')) {
                        $msg = 'Non-standard file-level MIE element';
                    } else {
                        $msg = 'Invalid MIE file-level data';
                    }
                }
            } elseif ($numDocs) {
                if (!$num) break;   // OK, all done with file
                $msg = 'Truncated MIE element header';
            } else {
                if ($num or !$outfile) return 0;
                // we have the ability to create a MIE file from scratch
                $buff = ''; // start from nothing
                // set byte order according to preferences
                $et->SetPreferredByteOrder();
                $isCreating = 1;
            }
            if ($msg) {
                if (!empty($dirInfo['Trailer'])) break; // allow other trailers after MIE
                if ($outfile) {
                    $et->Error($msg);
                } else {
                    $et->Warn($msg);
                }
                break;
            }
            // this is a new MIE document -- increment document count
            if (!$numDocs) {
                // this is a valid MIE file (unless a trailer on another file)
                $et->SetFileType();
                $et->NO_LIST = 1;   // handle lists ourself
                $et->MIE_COUNT = [];
                Vars::$hasZlib = null;
            }
            ++$numDocs;

            // process the MIE groups recursively, beginning with the main MIE group
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::MIE::Main');

            $subdirInfo = [
                'DirName' => 'MIE',
                'RAF' => $raf,
                'OutFile' => $outfile,
                // don't define Parent so WriteMIEGroup() writes extended terminator
            ];
            if ($outfile) {
                // save start position in $outfile
                $subdirInfo['OutPos'] = ExifTool::Tell($outfile);
                // generate lookup for MIE format codes if not done already
                if (!Vars::$mieCode) {
                    foreach (Vars::$mieFormat as $code => $fmt) {
                        Vars::$mieCode[$fmt] = $code;
                    }
                }
                // update %mieMap with user-defined MIE groups
                if (!Vars::$doneMieMap) self::UpdateMieMap();
                // initialize write directories, with MIE tags taking priority
                // (note that this may re-initialize directories when writing trailer
                //  to another type of image, but this is OK because we are done writing
                //  the other format by the time we start writing the trailer)
                $et->InitWriteDirs(Vars::$mieMap, 'MIE');
                $subdirInfo['ToWrite'] = '~' . self::MIEGroupFormat(1) . "\x04\xfe0MIE\0\0\0\0";
                $msg = self::WriteMIEGroup($et, $subdirInfo, $tagTablePtr);
                if ($msg) {
                    $et->Error($msg);
                    $err = 1;
                    break;
                } elseif ($msg !== null and $isCreating) {
                    break;
                }
            } else {
                $msg = self::ProcessMIEGroup($et, $subdirInfo, $tagTablePtr);
                if ($msg) {
                    $et->Warn($msg);
                    break;
                }
            }
        }
        $et->NO_LIST = null;
        $et->MIE_COUNT = null;
        $et->SET_GROUP1 = null;
        return $err ? -1 : 1;
    }
}
