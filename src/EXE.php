<?php

//------------------------------------------------------------------------------
// File:         EXE.pm
//
// Description:  Read meta information of various executable file formats
//
// Revisions:    2008/08/28 - P. Harvey Created
//               2011/07/12 - P. Harvey Added CHM (ok, not EXE, but it fits here)
//
// References:   1) http://www.openwatcom.org/ftp/devel/docs/pecoff.pdf
//               2) http://support.microsoft.com/kb/65122
//               3) http://www.opensource.apple.com
//               4) http://www.skyfree.org/linux/references/ELF_Format.pdf
//               5) http://msdn.microsoft.com/en-us/library/ms809762.aspx
//               6) http://code.google.com/p/pefile/
//               7) http://www.codeproject.com/KB/DLL/showver.aspx
//               8) https://learn.microsoft.com/en-us/windows/win32/debug/pe-format
//
// PHP port of Image::ExifTool::EXE 1.26. Tag tables are generated into tables/EXE.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\Vars\EXE as Vars;

final class EXE
{
    public static $VERSION = '1.26';

    //------------------------------------------------------------------------------
    // Extract information from a CHM file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid CHM file
    public static function ProcessCHM(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if (!($raf->Read($buff, 56) == 56 and
            preg_match('/^ITSF.{20}\x10\xfd\x01\x7c\xaa\x7b\xd0\x11\x9e\x0c\x00\xa0\xc9\x22\xe6\xec/s', $buff))) return 0;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::CHM');
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $subdir = ['DataPt' => $buff];
        $et->ProcessDirectory($subdir, $tagTablePtr);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Read Unicode string (null terminated) from resource
    // Inputs: 0) data ref, 1) start offset, 2) data end, 3) optional ExifTool object ref
    // Returns: 0) Unicode string translated to UTF8, or current CharSet with ExifTool ref
    //          1) end pos (rounded up to nearest 4 bytes)
    public static function ReadUnicodeStr($dataPt, $pos, $end, ?ExifTool $et = null): array
    {
        if ($end > strlen($dataPt)) $end = strlen($dataPt);    // (be safe)
        $str = '';
        while ($pos + 2 <= $end) {
            $ch = substr($dataPt, $pos, 2);
            $pos += 2;
            if ($ch === "\0\0") break;
            $str .= $ch;
        }
        if ($pos & 0x03) $pos += 2;
        $to = $et ? $et->Options('Charset') : 'UTF8';
        return [ExifTool::DecodeStatic(null, $str, 'UTF16', 'II', $to), $pos];
    }

    //------------------------------------------------------------------------------
    // Process Windows PE Version Resource
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref
    // Returns: true on success
    public static function ProcessPEVersion(ExifTool $et, array &$dirInfo)
    {
        $dataPt = $dirInfo['DataPt'];
        $pos = $dirInfo['DirStart'];
        $end = $pos + $dirInfo['DirLen'];

        // get VS_VERSION_INFO
        for ($index = 0; ; ++$index) {
            $pos = ($pos + 3) & 0xfffffffc;     // align on a 4-byte boundary
            if ($pos + 6 > $end) break;
            $len = ExifTool::Get16u($dataPt, $pos);
            if ($pos + $len > $end) return 0;
            $valLen = ExifTool::Get16u($dataPt, $pos + 2);
            $type = ExifTool::Get16u($dataPt, $pos + 4);
            if (!($len or $valLen)) return 0;   // prevent possible infinite loop
            [$string, $strEnd] = self::ReadUnicodeStr($dataPt, $pos + 6, $pos + $len);
            if ($strEnd + $valLen > $end) return 0;
            if (!($index or $string === 'VS_VERSION_INFO')) {
                $et->Warn('Invalid Version Info block');
                return 0;
            }
            if ($string === 'VS_VERSION_INFO') {
                // parse the fixed version info
                $dirInfo['DirStart'] = $strEnd;
                $dirInfo['DirLen'] = $valLen;
                $subTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::PEVersion');
                $et->ProcessDirectory($dirInfo, $subTablePtr);
                $pos = $strEnd + $valLen;
            } elseif ($string === 'StringFileInfo' and $valLen == 0) {
                $pos += $len;
                $pt = $strEnd;
                // parse string table
                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::PEString');
                for ($index = 0; $pt + 6 < $pos; ++$index) {
                    $len = ExifTool::Get16u($dataPt, $pt);
                    if (!$len) {
                        $et->Warn('Invalid PEString entry');
                        break;
                    }
                    $valLen = ExifTool::Get16u($dataPt, $pt + 2);
                    // $type = Get16u($dataPt, $pt + 4);
                    $entryEnd = $pt + $len;
                    if ($entryEnd > $end) return 0;
                    // get tag ID (converted to UTF8)
                    [$string, $pt] = self::ReadUnicodeStr($dataPt, $pt + 6, $entryEnd);
                    if (!$index) {
                        // separate the language code and character set
                        // (not sure what the CharacterSet tag is for, but the string
                        // values stored here are UCS-2 in all my files even if the
                        // CharacterSet is otherwise)
                        $char = null;
                        if (strlen($string) > 4) {
                            $char = substr($string, 4);
                            $string = substr($string, 0, 4);
                        }
                        $et->HandleTag($tagTablePtr, 'LanguageCode', strtoupper($string));
                        if ($char) $et->HandleTag($tagTablePtr, 'CharacterSet', strtoupper($char));
                        continue;
                    }
                    $tag = $string;
                    // get tag value (converted to current Charset)
                    if ($valLen) {
                        [$string, $pt] = self::ReadUnicodeStr($dataPt, $pt, $entryEnd, $et);
                    } else {
                        $string = '';
                    }
                    $et->HandleTag($tagTablePtr, $tag, $string, MakeTagInfo: 1);
                    // step to next entry (padded to an even word)
                    $pt = ($entryEnd + 3) & 0xfffffffc;
                }
            } else {
                $pos += $len + $valLen;
                // ignore other information (for now)
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Get actual file offset given a virtual address in a PE file
    // Inputs: 0) virtual address, 1) section list ref
    // Returns: absolute file offset or undef on error
    public static function GetFileOffset($addr, array $sections)
    {
        foreach ($sections as $section) {
            if (!($addr >= $section['VirtualAddress'] and
                  $addr <  $section['VirtualAddress'] + $section['Size'])) continue;
            return $addr + $section['Base'] - $section['VirtualAddress'];
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // Process Windows PE Resources
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref
    // Returns: true on success
    public static function ProcessPEResources(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $base = $dirInfo['Base'];
        $dirStart = $dirInfo['DirStart'] + $base;
        $level = ($dirInfo['Level'] ?? null) ?: 0;
        $verbose = $et->Options('Verbose');
        $buff = null;

        if ($level > 10) return 0;      // protect against deep recursion
        // read the resource header
        if (!($raf->Seek($dirStart, 0) and $raf->Read($buff, 16) == 16)) return 0;
        $nameEntries = ExifTool::Get16u($buff, 12);
        $idEntries = ExifTool::Get16u($buff, 14);
        $count = $nameEntries + $idEntries;
        if ($count > 10000) return 0;
        if ($raf->Read($buff, $count * 8) != $count * 8) return 0;
        // loop through all resource entries
        for ($item = 0; $item < $count; ++$item) {
            $pos = $item * 8;
            $name = ExifTool::Get32u($buff, $pos);
            $entryPos = ExifTool::Get32u($buff, $pos + 4);
            if (!$level) {
                // set resource type if this is the 0th level directory
                $resType = (Vars::$resourceType[$name] ?? null) ?: Perl::sprintf('Unknown (0x%x)', $name);
                // ignore everything but the Version resource unless verbose
                if ($verbose) {
                    $et->VPrint(0, "$resType resource:\n");
                } else {
                    if ($resType !== 'Version') continue;
                }
                $dirInfo['ResType'] = $resType;
            }
            if ($entryPos & 0x80000000) { // is this a directory?
                // descend into next directory level
                $dirInfo['DirStart'] = $entryPos & 0x7fffffff;
                $dirInfo['Level'] = $level + 1;
                if (!self::ProcessPEResources($et, $dirInfo)) return 0;
                --$dirInfo['Level'];
            } elseif (($dirInfo['ResType'] ?? '') === 'Version' and $level == 2 and
                empty($dirInfo['GotVersion'])) // (only process first Version resource)
            {
                // get position of this resource in the file
                $buf2 = null;
                if (!($raf->Seek($entryPos + $base, 0) and $raf->Read($buf2, 16) == 16)) return 0;
                $addr = ExifTool::Get32u($buf2, 0);
                $len = ExifTool::Get32u($buf2, 4);
                // determine which section this is in so we can convert the virtual address
                $fileOff = self::GetFileOffset($addr, $dirInfo['Sections']);
                if (!$fileOff) return 0;
                if (!($raf->Seek($fileOff, 0) and $raf->Read($buf2, $len) == $len)) return 0;
                $subdir = [
                    'DataPt'   => $buf2,
                    'DataLen'  => $len,
                    'DirStart' => 0,
                    'DirLen'   => $len,
                ];
                self::ProcessPEVersion($et, $subdir) or $et->Warn('Possibly corrupt Version resource');
                $dirInfo['GotVersion'] = 1;     // set flag so we don't do this again
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Process Windows PE file data dictionary
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref
    // Returns: true on success or if the PE resources didn't exist, or false on error
    //          processing the PE resources
    public static function ProcessPEDict(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $dataPt = $dirInfo['DataPt'];
        $dirLen = strlen($dataPt);
        $sections = $subdir = [];
        $rsrcFound = null;

        // loop through all sections
        for ($pos = 0; $pos + 40 <= $dirLen; $pos += 40) {
            $name = substr($dataPt, $pos, 8);
            $va = ExifTool::Get32u($dataPt, $pos + 12);
            $size = ExifTool::Get32u($dataPt, $pos + 16);
            $offset = ExifTool::Get32u($dataPt, $pos + 20);
            // remember the section offsets for the VirtualAddress lookup later
            $sections[] = ['Base' => $offset, 'Size' => $size, 'VirtualAddress' => $va];
            // save details of the first resource section (or .text if .rsrc not found, ref forum11465)
            if (!(($name === ".rsrc\0\0\0" and !$rsrcFound and ($rsrcFound = 1)) or
                  ($name === ".text\0\0\0" and !$subdir))) continue;
            $subdir = [
                'RAF'      => $raf,
                'Base'     => $offset,
                'DirStart' => 0,   // (relative to Base)
                'DirLen'   => $size,
                'Sections' => null, // (PHP: set below, Perl stores a ref to @sections)
            ];
        }
        $dirInfo['Sections'] = $sections;   // return section information
        // process the first resource section
        if ($subdir) {
            $subdir['Sections'] = $sections;
            if (!self::ProcessPEResources($et, $subdir)) return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Override file type if necessary for Mach object files and libraries
    // Inputs: 0) ExifTool ref, 1) ObjectFileType number, 2) flag for fat binary
    public static function MachOverride(ExifTool $et, $objType, $fat = null)
    {
        $override = Vars::$machOverride[$objType] ?? null;
        if ($override) {
            $desc = 'Mach-O ' . ($fat ? 'fat ' : '') . $override[0];
            $et->OverrideFileType($desc, null, $override[1]);
        }
    }

    //------------------------------------------------------------------------------
    // Extract tags from Mach header
    // Inputs: 0) ExifTool ref, 1) data ref, 2) flag to extract object type
    // Returns: true if Mach header was found
    public static function ExtractMachTags(ExifTool $et, $dataPt, $doObj = null)
    {
        // get information about mach header based on the magic number (first 4 bytes)
        $info = Vars::$machType[substr($dataPt, 0, 4)] ?? null;
        if ($info) {
            // Mach header structure:
            //  0 int32u magic
            //  4 int32u cputype
            //  8 int32u cpusubtype
            // 12 int32u filetype
            // 16 int32u ncmds
            // 20 int32u sizeofcmds
            // 24 int32u flags
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::MachO');
            ExifTool::SetByteOrder($info[1]);
            $cpuType = ExifTool::Get32s($dataPt, 4);
            $subType = ExifTool::Get32s($dataPt, 8);
            $et->HandleTag($tagTablePtr, 0, $info[0]);
            $et->HandleTag($tagTablePtr, 1, $info[1]);
            $et->HandleTag($tagTablePtr, 3, $cpuType);
            $et->HandleTag($tagTablePtr, 4, "$cpuType $subType");
            if ($doObj) {
                $objType = ExifTool::Get32u($dataPt, 12);
                $flags = ExifTool::Get32u($dataPt, 24);
                $et->HandleTag($tagTablePtr, 5, $objType);
                $et->HandleTag($tagTablePtr, 6, $flags);
                // override file type if this is an object file or library
                self::MachOverride($et, $objType);
            } else { // otherwise this was a static library
                $et->OverrideFileType('Mach-O static library', null, 'A');
            }
            return 1;
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Extract information from an EXE file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid EXE file
    public static function ProcessEXE(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $buf2 = $type = $mime = $ext = $tagTablePtr = null;

        $size = $raf->Read($buff, 0x40);
        if (!$size) return 0;
        $fast3 = $et->OPTIONS['FastScan'] && $et->OPTIONS['FastScan'] == 3;
        //
        // DOS and Windows EXE
        //
        if (str_starts_with($buff, 'MZ') and $size == 0x40) {
            // DOS/Windows executable
            // validate DOS header
            // (ref http://www.delphidabbler.com/articles?article=8&part=2)
            //   0 int16u     magic    - Magic number ("MZ")
            //   2 int16u     cblp     - Bytes on last page of file
            //   4 int16u     cp       - Pages in file
            //   6 int16u     crlc     - Relocations
            //   8 int16u     cparhdr  - Size of header in paragraphs
            //  10 int16u     minalloc - Minimum extra paragraphs needed
            //  12 int16u     maxalloc - Maximum extra paragraphs needed
            //  14 int16u     ss       - Initial (relative) SS value
            //  16 int16u     sp       - Initial SP value
            //  18 int16u     csum     - Checksum
            //  20 int16u     ip       - Initial IP value
            //  22 int16u     cs       - Initial (relative) CS value
            //  24 int16u     lfarlc   - Address of relocation table
            //  26 int16u     ovno     - Overlay number
            //  28 int16u[4]  res      - Reserved words
            //  36 int16u     oemid    - OEM identifier (for oeminfo)
            //  38 int16u     oeminfo  - OEM info; oemid specific
            //  40 int16u[10] res2     - Reserved words
            //  60 int32u;    lfanew   - File address of new exe header
            ExifTool::SetByteOrder('II');
            [$cblp, $cp, $lfarlc, $lfanew] = Perl::unpack('x2v2x18vx34V', $buff);
            $fileSize = ($cp - ($cblp ? 1 : 0)) * 512 + $cblp;
            //(patch to accommodate observed 64-bit files)
            //return 0 if $fileSize < 0x40 or $fileSize < $lfarlc;
            //return 0 if $fileSize < 0x40; (changed to warning in ExifTool 12.08)
            if ($fileSize < 0x40) $et->Warn('Invalid file size in DOS header');
            // read the Windows NE, PE or LE (virtual device driver) header
            //if ($lfarlc == 0x40 and $fileSize > $lfanew + 2 and ...
            if ($raf->Seek($lfanew, 0) and $raf->Read($buff, 0x40) and preg_match('/^(NE|PE|LE)/', $buff, $m)) {
                if ($m[1] === 'NE') {
                    if ($size >= 0x40) { // NE header is 64 bytes (ref 2)
                        // check for DLL
                        $appFlags = ExifTool::Get16u($buff, 0x0c);
                        $ext = $appFlags & 0x80 ? 'DLL' : 'EXE';
                        $type = "Win16 $ext";
                        // offset 0x02 is 2 bytes with linker version and revision numbers
                        // offset 0x36 is executable type (2 = Windows)
                    }
                } elseif ($m[1] === 'PE') {
                    // PE header comes at byte 4 in buff:
                    //   4 int16u Machine
                    //   6 int16u NumberOfSections
                    //   8 int32u TimeDateStamp
                    //  12 int32u PointerToSymbolTable
                    //  16 int32u NumberOfSymbols
                    //  20 int16u SizeOfOptionalHeader
                    //  22 int16u Characteristics
                    if ($size >= 24) {  // PE header is 24 bytes (plus optional header)
                        $mach = ExifTool::Get16u($buff, 4);     // MachineType
                        $flags = ExifTool::Get16u($buff, 22);   // ImageFileCharacteristics
                        $machine = (ExifTool::GetTagTable('Image::ExifTool::EXE::Main')[0]->PrintConv[$mach] ?? null) ?: '';
                        $winType = str_contains($machine, '64') ? 'Win64' : 'Win32';
                        $ext = $flags & 0x2000 ? 'DLL' : 'EXE';
                        $et->SetFileType("$winType $ext", null, $ext);
                        if ($fast3) return 1;
                        // read the rest of the optional header if necessary
                        $optSize = ExifTool::Get16u($buff, 20);
                        $more = $optSize + 24 - $size;
                        $magic = 0;
                        if ($more > 0) {
                            if ($raf->Read($buf2, $more) == $more) {
                                $buff .= $buf2;
                                $size += $more;
                                $magic = ExifTool::Get16u($buff, 24);
                                // verify PE magic number
                                if (!($magic == 0x107 or $magic == 0x10b or $magic == 0x20b)) {
                                    $et->Warn('Unknown PE magic number');
                                    return 1;
                                }
                                // --> 64-bit if $magic is 0x20b ????
                            } else {
                                $et->Warn('Error reading optional header');
                            }
                        }
                        // process PE COFF file header
                        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::Main');
                        $subdir = [
                            'DataPt'   => $buff,
                            'DataPos'  => $raf->Tell() - $size,
                            'DataLen'  => $size,
                            'DirStart' => 4,
                            'DirLen'   => $size - 4,
                        ];
                        $et->ProcessDirectory($subdir, $tagTablePtr);
                        $num = ExifTool::Get16u($buff, 6);  // NumberOfSections
                        // image data directory entry for debug info is index 6,
                        // so offset is 4 bytes + 20 byte header + 96 bytes into PE32 optional
                        // header (or 112 bytes for PE32+) + 6 * 8 bytes into data directory
                        $dirEntry = $magic == 0x20b ? 184 : 168;
                        $addr = $len = null;    // virtual address/size of debug section
                        if (strlen($buff) >= $dirEntry + 8) {
                            $addr = ExifTool::Get32u($buff, $dirEntry);
                            $len = ExifTool::Get32u($buff, $dirEntry + 4);
                        }
                        // process data dictionary
                        // (ref https://www.debuginfo.com/articles/debuginfomatch.html)
                        if ($raf->Read($buff, 40 * $num) != 40 * $num) return 1;
                        $subdir = ['RAF' => $raf, 'DataPt' => $buff];
                        self::ProcessPEDict($et, $subdir) or $et->Warn('Error processing PE resources');
                        // dive into debug section to extract pdb info if available
                        if (!($addr and $len < 2800 and !empty($subdir['Sections']) and
                            ($magic == 0x10b or $magic == 0x20b))) return 1;
                        // get file offset for debug section
                        $off = self::GetFileOffset($addr, $subdir['Sections']);
                        if (!($off and $raf->Seek($off, 0) and $raf->Read($buff, $len) == $len)) return 1;
                        for ($pos = 0; $pos + 28 <= $len; $pos += 28) {
                            $type = ExifTool::Get32u($buff, $pos + 12);
                            if (!($type == 2 or $type == 4)) continue;  // (CodeView debug data)
                            [$n, $of] = [ExifTool::Get32u($buff, $pos + 16), ExifTool::Get32u($buff, $pos + 24)];
                            if (!($n < 1e4 and $raf->Seek($of, 0) and $raf->Read($buf2, $n) == $n)) continue;
                            if ($type == 2) { // CodeView debug info
                                if (!preg_match('/^(RSDS|NB10)/', $buf2, $m)) continue;
                                $tagTablePtr = ExifTool::GetTagTable("Image::ExifTool::EXE::Debug$m[1]");
                                $buf2 = substr($buff, $pos + 4, 4) . substr($buf2, 4);  // use timestamp from debug dir
                                $subdir = ['DataPt' => $buf2, 'DataPos' => $of];
                                $et->ProcessDirectory($subdir, $tagTablePtr);
                            } else { // misc debug info
                                if (!($n > 12)) continue;
                                $exe = substr($buf2, 12);
                                if (ExifTool::Get32u($buf2, 8)) $exe = $et->Decode($exe, 'UTF16');
                                $exe = preg_replace('/\x00.*/', '', $exe, 1);  // truncate at null
                                $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::Misc');
                                $et->HandleTag($tagTablePtr, 12, $exe, DataPt: $buf2, DataPos: $of);
                            }
                        }
                        return 1;
                    }
                } else {
                    $type = 'Virtual Device Driver';
                    $ext = '386';
                }
            } else {
                $type = 'DOS EXE';
                $ext = 'exe';
            }
        //
        // Mach-O (Mac OS X)
        //
        } elseif (preg_match('/^(\xca\xfe\xba\xbe|\xfe\xed\xfa(\xce|\xcf)|(\xce|\xcf)\xfa\xed\xfe)/', $buff, $m) and $size > 12) {
            // Mach-O executable
            // (ref http://developer.apple.com/documentation/DeveloperTools/Conceptual/MachORuntime/Reference/reference.html)
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::MachO');
            if ($m[1] === "\xca\xfe\xba\xbe") {
                ExifTool::SetByteOrder('MM');
                $ver = ExifTool::Get32u($buff, 4);
                // Java bytecode .class files have the same magic number, so we need to look deeper
                // (ref https://github.com/file/file/blob/master/magic/Magdir/cafebabe#L6-L15)
                if ($ver > 30) {
                    // this is Java bytecode
                    $et->SetFileType('Java bytecode', 'application/java-byte-code', 'class');
                    return 1;
                }
                $et->SetFileType('Mach-O fat binary executable', null, '');
                if ($fast3) return 1;
                $count = ExifTool::Get32u($buff, 4);    // get architecture count
                $more = $count * 20 - ($size - 8);
                if ($more > 0) {
                    if ($raf->Read($buf2, $more) != $more) {
                        $et->Warn('Error reading fat-arch headers');
                        return 1;
                    }
                    $buff .= $buf2;
                    $size += $more;
                }
                $et->HandleTag($tagTablePtr, 2, $count);
                for ($i = 0; $i < $count; ++$i) {
                    $cpuType = ExifTool::Get32s($buff, 8 + $i * 20);
                    $subType = ExifTool::Get32s($buff, 12 + $i * 20);
                    $et->HandleTag($tagTablePtr, 3, $cpuType);
                    $et->HandleTag($tagTablePtr, 4, "$cpuType $subType");
                }
                // load first Mach-O header to get the object file type
                $offset = ExifTool::Get32u($buff, 16);
                if ($raf->Seek($offset, 0) and $raf->Read($buf2, 16) == 16) {
                    if (preg_match('/^(\xfe\xed\xfa(\xce|\xcf)|(\xce|\xcf)\xfa\xed\xfe)/', $buf2)) {
                        ExifTool::SetByteOrder(str_starts_with($buf2, "\xfe\xed") ? 'MM' : 'II');
                        $objType = ExifTool::Get32u($buf2, 12);
                        $et->HandleTag($tagTablePtr, 5, $objType);
                        // override file type if this is a library or object file
                        self::MachOverride($et, $objType, 'fat');
                    } elseif (str_starts_with($buf2, "!<arch>\x0a")) {
                        // .a libraries use this magic number
                        $et->HandleTag($tagTablePtr, 5, -1);
                        // override file type since this is a library
                        $et->OverrideFileType('Mach-O fat static library', null, 'A');
                    } else {
                        $et->Warn('Unrecognized object file type');
                    }
                } else {
                    $et->Warn('Error reading file');
                }
            } elseif ($size >= 16) {
                $et->SetFileType('Mach-O executable', null, '');
                if ($fast3) return 1;
                self::ExtractMachTags($et, $buff, 1);
            }
            return 1;
        //
        // PEF (classic MacOS)
        //
        } elseif (str_starts_with($buff, 'Joy!peff') and $size > 12) {
            // ref http://developer.apple.com/documentation/mac/pdf/MacOS_RT_Architectures.pdf
            $et->SetFileType('Classic MacOS executable', null, '');
            if ($fast3) return 1;
            ExifTool::SetByteOrder('MM');
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::PEF');
            $subdir = [
                'DataPt' => $buff,
                'DataPos' => 0,
                'DataLen' => $size,
                'DirStart' => 0,
                'DirLen' => $size,
            ];
            $et->ProcessDirectory($subdir, $tagTablePtr);
            return 1;
        //
        // ELF (Unix)
        //
        } elseif (str_starts_with($buff, "\x7fELF") and $size >= 16) {
            $et->SetFileType('ELF executable', null, '');
            if ($fast3) return 1;
            ExifTool::SetByteOrder(ExifTool::Get8u($buff, 5) == 1 ? 'II' : 'MM');
            $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::ELF');
            $subdir = [
                'DataPt'  => $buff,
                'DataPos' => 0,
                'DataLen' => $size,
                'DirLen'  => $size,
            ];
            $et->ProcessDirectory($subdir, $tagTablePtr);
            // override file type if this is a library or object file
            $override = [
                1 => ['ELF object file', 'O'],
                3 => ['ELF shared library', 'SO'],
            ][Perl::str(($et->VALUE['ObjectFileType'] ?? null) ?: 0)] ?? null;
            if ($override) $et->OverrideFileType($override[0], null, $override[1]);
            return 1;
        //
        // .a libraries
        //
        } elseif (str_starts_with($buff, "!<arch>\x0a")) {
            $et->SetFileType('Static library', null, 'A');
            if ($fast3) return 1;
            $pos = 8;   // current file position
            $max = 10;  // maximum number of archive files to check
            // read into list of ar structures (each 60 bytes long):
            while ($max-- > 0) {
                // seek to start of the ar structure and read it
                if (!($raf->Seek($pos, 0) and $raf->Read($buff, 60) == 60)) break;
                if (substr($buff, 58, 2) !== "`\n") {
                    $et->Warn('Invalid archive header');
                    break;
                }
                if (!$tagTablePtr) {
                    // extract some information from first file in archive
                    $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::EXE::AR');
                    $subdir = [
                        'DataPt'  => $buff,
                        'DataPos' => $pos,
                    ];
                    $et->ProcessDirectory($subdir, $tagTablePtr);
                }
                $name = substr($buff, 0, 16);
                if (preg_match('{^#1/(\d+) *$}', $name, $m)) { // check for extended archive (BSD variant)
                    $len = (int) $m[1];
                    if ($len > 256) {
                        $et->Warn('Invalid extended archive name length');
                        break;
                    }
                    // (we read the name here just to move the file pointer)
                    if ($raf->Read($name, $len) != $len) {
                        $et->Warn('Error reading archive name');
                        break;
                    }
                }
                $arSize = substr($buff, 48, 10);
                if (!preg_match('/^(\d+).*/s', $arSize, $m)) break;    // make sure archive size is a number
                $arSize = $m[1];
                if ($raf->Read($buff, 28) != 28) break;     // read (possible) Mach header
                if (self::ExtractMachTags($et, $buff)) break;   // try to extract tags
                $pos += 60 + $arSize;   // step to next entry
                if ($pos & 0x01) ++$pos;    // padded to an even byte
            }
            return 1;
        //
        // various scripts (perl, sh, etc...)
        //
        } elseif (preg_match('{^#!\s*/\S*bin/(\w+)}', $buff, $m)) {
            $prog = $m[1];
            if ($prog === 'env' and preg_match('/\b(perl|python|ruby|php)\b/', $buff, $m)) $prog = $m[1];
            $type = "$prog script";
            $mime = "text/x-$prog";
            $ext = [
                'perl'   => 'pl',
                'python' => 'py',
                'ruby'   => 'rb',
                'php'    => 'php',
            ][$prog] ?? null;
            // use '.sh' for extension of all shell scripts
            if ($ext === null) $ext = preg_match('/sh$/', $prog) ? 'sh' : '';
        }
        if (!$type) return 0;
        $et->SetFileType($type, $mime, $ext);
        return 1;
    }
}
