<?php

//------------------------------------------------------------------------------
// File:         7Z.pm
//
// Description:  Read 7z archive meta information
//
// Revisions:    2023/04/28 - Amir Gooran (Cyberno)
//               2023-05-06 - PH Minor changes in ExifTool interfacing
//
// References:   1) https://py7zr.readthedocs.io/en/latest/archive_format.html
//
// PHP port of Image::ExifTool::7Z 1.01 (no tag table: uses Image::ExifTool::ZIP::RAR5).
// The 7z structures (hashes) are PHP arrays, passed by reference where Perl modifies them.
// GetDecompressor, ReadData, Decompress_Internal and Decompress are not ported: they decode
// LZMA-encoded headers with Compress::Raw::Lzma, which has no PHP equivalent, so encoded
// headers stop with the warning Perl gives when that module is missing.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

use Image\ExifTool\File\RandomAccess;

final class _7Z
{
    public static $VERSION = '1.01';

    /** (PHP: Perl ord(), which gives 0 for an empty string) */
    private static function Ord($buff)
    {
        return strlen((string) $buff) ? ord($buff[0]) : 0;
    }

    public static function ReadUInt32(RandomAccess $raf)
    {
        $buff = null;
        $raf->Read($buff, 4);
        return Perl::unpack('L', $buff)[0] ?? null;
    }

    public static function ReadUInt64(RandomAccess $raf)
    {
        $buff = null;

        $raf->Read($buff, 1);
        $b = self::Ord($buff);
        if ($b == 255) {    // read real uint64
            $raf->Read($buff, 8);
            return Perl::unpack('Q', $buff)[0] ?? null;
        }
        $blen = [0x7F, 0xBF, 0xDF, 0xEF, 0xF7, 0xFB, 0xFD, 0xFE];

        $mask = 0x80;
        $vlen = 8;

        for ($l = 0; $l < count($blen); $l++) {
            $v = $blen[$l];
            if ($b <= $v) {
                $vlen = $l;
                break;
            }
            $mask >>= 1;
        }
        if ($vlen == 0) {
            return $b & ($mask - 1);
        }
        $raf->Read($buff, $vlen);
        $buff .= "\0\0\0\0\0\0\0\0";

        $value = Perl::unpack('Q', $buff)[0];
        $highpart = $b & ($mask - 1);
        return $value + ($highpart << ($vlen * 8));
    }

    public static function ReadRealUInt64(RandomAccess $raf)
    {
        $buff = null;

        $raf->Read($buff, 8);
        $value = Perl::unpack('Q', $buff)[0] ?? null;
        return $value;
    }

    public static function ReadBoolean(RandomAccess $raf, $count, $checkall): array
    {
        $buff = null;
        $result = [];

        if ($checkall) {
            $raf->Read($buff, 1);
            $all_defined = self::Ord($buff);
            if ($all_defined != 0) {
                $result = $count > 0 ? array_fill(0, (int) $count, 1) : [];
                return $result;
            }
        }

        $b = 0;
        $mask = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($mask == 0) {
                $raf->Read($buff, 1);
                $b = self::Ord($buff);
                $mask = 0x80;
            }
            $result[] = ($b & $mask) != 0;
            $mask >>= 1;
        }
        return $result;
    }

    public static function ReadUTF16(RandomAccess $raf)
    {
        $val = '';
        $ch = null;

        for ($i = 0; $i < 65536; $i++) {
            $raf->Read($ch, 2);
            if ($ch === "\0\0") {
                break;
            }
            $val .= $ch;
        }
        return $val;
    }

    public static function ReadPackInfo(ExifTool $et, RandomAccess $raf)
    {
        $buff = null;
        $out_packinfo = [];
        $out_packinfo['packsizes'] = [];

        $out_packinfo['packpos'] = self::ReadUInt64($raf);
        $numstreams = self::ReadUInt64($raf);
        $et->VPrint(0, "Number Of Streams: $numstreams\n");

        $raf->Read($buff, 1);
        $pid = self::Ord($buff);

        if ($pid == 9) {    // size
            for ($i = 0; $i < $numstreams; $i++) {
                $out_packinfo['packsizes'][] = self::ReadUInt64($raf);
            }
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
            if ($pid == 10) {   // crc
                $crcs = [];
                $digestdefined = self::ReadBoolean($raf, $numstreams, 1);
                foreach ($digestdefined as $crcexist) {
                    if ($crcexist) {
                        $crcs[] = self::ReadUInt32($raf);
                    }
                }
                $raf->Read($buff, 1);
                $pid = self::Ord($buff);
            }
        }
        if ($pid != 0) {    // end id expected
            return 0;
        }
        return $out_packinfo;
    }

    public static function findInBinPair(array $bindpairs, $index)
    {
        for ($i = 0; $i < count($bindpairs); $i++) {
            if ($bindpairs[$i] == $index) {
                return $i;
            }
        }
        return -1;
    }

    public static function ReadFolder(ExifTool $et, RandomAccess $raf): array
    {
        $buff = null;
        $totalin = 0;
        $totalout = 0;
        $out_folder = [];
        $out_folder['packed_indices'] = [];
        $out_folder['bindpairs'] = [];
        $out_folder['coders'] = [];

        $num_coders = self::ReadUInt64($raf);
        $et->VPrint(0, "Number of coders: $num_coders\n");

        for ($i = 0; $i < $num_coders; $i++) {
            $c = [];
            $raf->Read($buff, 1);
            $b = self::Ord($buff);
            $methodsize = $b & 0xF;
            $iscomplex = ($b & 0x10) == 0x10;
            $hasattributes = ($b & 0x20) == 0x20;
            if ($methodsize > 0) {
                $raf->Read($buff, $methodsize);
                $c['method'] = $buff;
            } else {
                $c['method'] = "\0";
            }
            if ($iscomplex) {
                $c['numinstreams'] = self::ReadUInt64($raf);
                $c['numoutstreams'] = self::ReadUInt64($raf);
            } else {
                $c['numinstreams'] = 1;
                $c['numoutstreams'] = 1;
            }
            $totalin += $c['numinstreams'];
            $totalout += $c['numoutstreams'];
            if ($hasattributes) {
                $proplen = self::ReadUInt64($raf);
                $raf->Read($buff, $proplen);
                $c['properties'] = $buff;
            } else {
                $c['properties'] = null;
            }
            $et->VPrint(0, "Reading coder $i\n");
            $out_folder['coders'][] = $c;
        }
        $num_bindpairs = $totalout - 1;
        for ($i = 0; $i < $num_bindpairs; $i++) {
            $bond = [self::ReadUInt64($raf), self::ReadUInt64($raf)];
            array_push($out_folder['bindpairs'], ...$bond);
        }
        $num_packedstreams = $totalin - $num_bindpairs;
        if ($num_packedstreams == 1) {
            for ($i = 0; $i < $totalin; $i++) {
                if (self::findInBinPair($out_folder['bindpairs'], $i) < 0) {
                    $out_folder['packed_indices'][] = $i;
                }
            }
        } else {
            for ($i = 0; $i < $num_packedstreams; $i++) {
                $out_folder['packed_indices'][] = self::ReadUInt64($raf);
            }
        }

        return $out_folder;
    }

    public static function RetrieveCodersInfo(ExifTool $et, RandomAccess $raf, array &$folders)
    {
        $buff = null;

        $raf->Read($buff, 1);
        $pid = self::Ord($buff);

        if ($pid != 0x0c) { // coders unpack size id expected
            return 0;
        }
        foreach ($folders as &$folder) {
            $folder['unpacksizes'] = [];
            foreach ($folder['coders'] as $c) {
                for ($i = 0; $i < $c['numoutstreams']; $i++) {
                    $folder['unpacksizes'][] = self::ReadUInt64($raf);
                }
            }
        }
        unset($folder);
        $raf->Read($buff, 1);
        $pid = self::Ord($buff);

        if ($pid == 0x0a) {     // crc
            $numfolders = count($folders);
            $et->VPrint(0, "Number of folders: $numfolders\n");
            $defined = self::ReadBoolean($raf, $numfolders, 1);
            $crcs = [];
            foreach ($defined as $crcexist) {
                if ($crcexist) {
                    $crcs[] = self::ReadUInt32($raf);
                }
            }
            for ($i = 0; $i < $numfolders; $i++) {
                $folders[$i]['digestdefined'] = $defined[$i] ?? null;
                $folders[$i]['crc'] = $crcs[$i] ?? null;
            }
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }

        if ($pid != 0x00) { // end id expected
            $et->VPrint(0, "Invalid PID: $pid\n");
            return 0;
        }
        return 1;
    }

    public static function ReadUnpackInfo(ExifTool $et, RandomAccess $raf)
    {
        $buff = null;
        $out_unpackinfo = [];

        $raf->Read($buff, 1);
        $pid = self::Ord($buff);

        if ($pid != 0xb) {  // folder id expected
            return 0;
        }

        $out_unpackinfo['numfolders'] = self::ReadUInt64($raf);
        $out_unpackinfo['folders'] = [];

        $raf->Read($buff, 1);
        $external = self::Ord($buff);

        if ($external == 0x00) {
            for ($i = 0; $i < $out_unpackinfo['numfolders']; $i++) {
                $et->VPrint(0, "Reading folder $i\n");
                $folder = self::ReadFolder($et, $raf);
                $out_unpackinfo['folders'][] = $folder;
            }
        }
        if (!self::RetrieveCodersInfo($et, $raf, $out_unpackinfo['folders'])) return 0;
        return $out_unpackinfo;
    }

    public static function ReadSubstreamsInfo(ExifTool $et, RandomAccess $raf, $numfolders, $folders)
    {
        $buff = null;
        $out_substreamsinfo = [];
        $out_substreamsinfo['num_unpackstreams_folders'] = [];

        $folders = $folders ?: [];

        $raf->Read($buff, 1);
        $pid = self::Ord($buff);
        if ($pid == 13) {   // num unpack stream
            $et->VPrint(0, "Num unpack stream detected.\n");
            for ($i = 0; $i < $numfolders; $i++) {
                $out_substreamsinfo['num_unpackstreams_folders'][] = self::ReadUInt64($raf);
            }
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        } else {
            $out_substreamsinfo['num_unpackstreams_folders'] = $numfolders > 0 ? array_fill(0, (int) $numfolders, 1) : [];
        }
        if ($pid == 9) {    // size property
            $et->VPrint(0, "Size property detected.\n");
            $out_substreamsinfo['unpacksizes'] = [];
            for ($i = 0; $i < count($out_substreamsinfo['num_unpackstreams_folders']); $i++) {
                $totalsize = 0;
                for ($j = 1; $j < $out_substreamsinfo['num_unpackstreams_folders'][$i]; $j++) {
                    $size = self::ReadUInt64($raf);
                    $out_substreamsinfo['unpacksizes'][] = $size;
                    $totalsize += $size;
                }
                // self.unpacksizes.append(folders[i].get_unpack_size() - totalsize)
            }
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        $num_digests = 0;
        $num_digests_total = 0;
        for ($i = 0; $i < $numfolders; $i++) {
            $numsubstreams = $out_substreamsinfo['num_unpackstreams_folders'][$i] ?? null;
            if ($numsubstreams != 1 or empty($folders[$i]['digestdefined'])) {
                $num_digests += $numsubstreams;
            }
            $num_digests_total += $numsubstreams;
        }
        $et->VPrint(0, "Num Digests Total: $num_digests_total\n");
        if ($pid == 10) {   // crc property
            $et->VPrint(0, "CRC property detected.\n");
            $crcs = [];
            $defined = self::ReadBoolean($raf, $num_digests, 1);
            foreach ($defined as $crcexist) {
                $crcs[] = self::ReadUInt32($raf);
            }
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        if ($pid != 0x00) { // end id expected
            return 0;
        }
        return $out_substreamsinfo;
    }

    public static function ReadStreamsInfo(ExifTool $et, RandomAccess $raf)
    {
        $buff = null;
        $unpackinfo = null;
        $out_streamsinfo = [];

        $raf->Read($buff, 1);
        $pid = self::Ord($buff);
        if ($pid == 6) {    // pack info
            $packinfo = self::ReadPackInfo($et, $raf);
            if (!$packinfo) return 0;
            $out_streamsinfo['packinfo'] = $packinfo;
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        if ($pid == 7) {    // unpack info
            $et->VPrint(0, "Unpack info data detected.\n");
            $unpackinfo = self::ReadUnpackInfo($et, $raf);
            if (!$unpackinfo) return 0;
            $out_streamsinfo['unpackinfo'] = $unpackinfo;
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        if ($pid == 8) {    // substreams info
            $et->VPrint(0, "Substreams info data detected.\n");
            $substreamsinfo = self::ReadSubstreamsInfo($et, $raf, $unpackinfo['numfolders'] ?? null, $unpackinfo['folders'] ?? null);
            if (!$substreamsinfo) return 0;
            $out_streamsinfo['substreamsinfo'] = $substreamsinfo;
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        if ($pid != 0x00) { // end id expected
            $et->VPrint(0, "Invalid PID: $pid\n");
            return 0;
        }
        return $out_streamsinfo;
    }

    public static function IsNativeCoder(array $coder)
    {
        $method = $coder['method'] . "\0\0\0\0";
        if (ord($method[0]) == 3) {
            if (ord($method[1]) == 1) {
                if (ord($method[2]) == 1) {
                    return 'LZMA';
                }
            }
        } elseif (ord($method[0]) == 6) {
            if (ord($method[1]) == 0xf1) {
                if (ord($method[2]) == 7) {
                    if (ord($method[3]) == 1) {
                        return '7zAES';
                    }
                }
            }
        }
        return null;
    }

    public static function ReadName(RandomAccess $raf, $numfiles, array &$files)
    {
        for ($i = 0; $i < $numfiles; $i++) {
            $files[$i]['filename'] = self::ReadUTF16($raf);
        }
    }

    public static function ReadTimes(ExifTool $et, RandomAccess $raf, $numfiles, $name, array &$files)
    {
        $external = null;

        $defined = self::ReadBoolean($raf, $numfiles, 1);
        $raf->Read($external, 1);
        if (self::Ord($external) != 0) {
            $et->Warn('Invalid or corrupted file. (ReadTimes)');
            return 0;
        }

        for ($i = 0; $i < $numfiles; $i++) {
            if (!empty($defined[$i])) {
                $value = self::ReadRealUInt64($raf);
                $value = $value / 10000000.0 - 11644473600;
                $files[$i][$name] = $value;
            } else {
                $files[$i][$name] = null;
            }
        }
        return null;
    }

    public static function ReadAttributes(RandomAccess $raf, $numfiles, array $defined, array &$files)
    {
        for ($i = 0; $i < $numfiles; $i++) {
            if (!empty($defined[$i])) {
                $value = self::ReadUInt32($raf);
                $files[$i]['attributes'] = $value >> 8;
            } else {
                $files[$i]['attributes'] = null;
            }
        }
    }

    public static function ReadFilesInfo(ExifTool $et, RandomAccess $raf)
    {
        $buff = null;

        $numfiles = self::ReadUInt64($raf);
        $out_files = [];
        for ($i = 0; $i < $numfiles; $i++) {
            $new_file = [];
            $new_file['emptystream'] = 0;
            $out_files[] = $new_file;
        }
        $numemptystreams = 0;
        $et->VPrint(0, "Number of files: $numfiles\n");
        for (;;) {
            $raf->Read($buff, 1);
            $prop = self::Ord($buff);
            if ($prop == 0) {   // end
                return $out_files;
            }
            $size = self::ReadUInt64($raf);
            if ($prop == 25) {  // dummy
                $raf->Seek($size, 1);
                continue;
            }
            $raf->Read($buff, $size);
            $buffer = new RandomAccess((string) $buff);
            if ($prop == 14) {  // empty stream
                $isempty = self::ReadBoolean($buffer, $numfiles, 0);
                $numemptystreams = 0;
                for ($i = 0; $i < $numfiles; $i++) {
                    if (empty($isempty[$i])) {
                        $out_files[$i]['emptystream'] = 0;
                    } else {
                        $out_files[$i]['emptystream'] = 1;
                        $numemptystreams++;
                    }
                }
            } elseif ($prop == 15) {    // empty file

            } elseif ($prop == 17) {    // name
                $et->VPrint(0, "Name prop detected.\n");
                $external = null;
                $buffer->Read($external, 1);
                $is_external = self::Ord($external);
                if ($is_external == 0) {
                    self::ReadName($buffer, $numfiles, $out_files);
                }
            } elseif ($prop == 20) {    // last write time
                $et->VPrint(0, "Last write time detected.\n");
                self::ReadTimes($et, $buffer, $numfiles, 'lastwritetime', $out_files);
            } elseif ($prop == 21) {    // attributes
                $et->VPrint(0, "File attributes detected.\n");
                $external = null;
                $defined = self::ReadBoolean($buffer, $numfiles, 1);
                $raf->Read($external, 1);   // (sic: reads from the file, not the property buffer)
                if (self::Ord($external) == 0) {
                    self::ReadAttributes($buffer, $numfiles, $defined, $out_files);
                } else {
                    // (Perl calls ReadUINT64 here, which doesn't exist)
                    $dataindex = self::ReadUInt64($buffer);
                    //TODO: try to read external data
                }
            }
        }
    }

    public static function ExtractHeaderInfo(ExifTool $et, RandomAccess $raf)
    {
        $buff = null;
        $out_headerinfo = [];
        $out_headerinfo['files_info'] = [];

        $raf->Read($buff, 1);
        $pid = self::Ord($buff);

        if ($pid == 0x04) {
            $mainstreams = self::ReadStreamsInfo($et, $raf);
            if (!$mainstreams) {
                $et->Warn('Invalid or corrupted file. (ExtractHeaderInfo)');
                return 0;
            }
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        if ($pid == 0x05) {
            $et->VPrint(0, "File info pid reached.\n");
            $files_info = self::ReadFilesInfo($et, $raf);
            $out_headerinfo['files_info'][] = $files_info;
            $raf->Read($buff, 1);
            $pid = self::Ord($buff);
        }
        if ($pid != 0x00) { // end id expected
            $et->VPrint(0, "Invalid PID: $pid\n");
            return 0;
        }
        return $out_headerinfo;
    }

    public static function DisplayFiles(ExifTool $et, $files = null)
    {
        $docNum = 0;
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::RAR5');

        foreach ($files ?: [] as $currentfile) {
            $et->DOC_NUM = ++$docNum;
            $et->HandleTag($tagTablePtr, 'ModifyDate', $currentfile['lastwritetime'] ?? null);
            $et->HandleTag($tagTablePtr, 'ArchivedFileName', $currentfile['filename'] ?? null);
        }
        $et->DOC_NUM = null;
        if ($docNum > 1 and !$et->Options('Duplicates')) {
            $et->Warn("Use the Duplicates option to extract tags for all $docNum files", 1);
        }
    }

    //------------------------------------------------------------------------------
    // Extract information from a 7z file
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid 7z file
    public static function Process7Z(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        if (!($raf->Read($buff, 6) and $buff === "7z\xbc\xaf\x27\x1c")) return 0;

        $et->SetFileType();

        $raf->Read($buff, 2);
        [$major_version, $minor_version] = Perl::unpack('cc', $buff) + [null, null];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZIP::RAR5');
        $et->HandleTag($tagTablePtr, 'FileVersion', Perl::sprintf('7z v%d.%.2d', $major_version, $minor_version));

        $raf->Seek(4, 1);   // skip Start Header CRC

        $raf->Read($buff, 20);
        [$nextheaderoffset, $nextheadersize] = Perl::unpack('QQx', $buff) + [null, null];
        $et->VPrint(0, "NextHeaderOffset: $nextheaderoffset, NextHeaderSize: $nextheadersize\n");

        $raf->Seek((int) $nextheaderoffset, 1);     // going to next header offset
        $raf->Read($buff, 1);
        $pid = self::Ord($buff);
        if ($pid == 1) {    // normal header
            $et->VPrint(0, "Normal header detected. trying to decode\n");
            $headerinfo = self::ExtractHeaderInfo($et, $raf);
            if (!$headerinfo) {
                $et->Warn('Invalid or corrupted file.');
                return 1;
            }
            self::DisplayFiles($et, ...$headerinfo['files_info']);
        } elseif ($pid == 23) { // encoded header
            // (PHP: no LZMA decoder, as in Perl without Compress::Raw::Lzma)
            $et->Warn('Install Compress::Raw::Lzma to read encoded 7z information');
            return 1;
        } else {    // Unknown header
            return 0;
        }

        return 1;
    }
}
