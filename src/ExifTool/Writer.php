<?php

//------------------------------------------------------------------------------
// File:         Writer.pl
//
// Description:  ExifTool write routines
//
// Notes:        Also contains some less used ExifTool functions
//
// URL:          https://exiftool.org/
//
// Revisions:    12/16/2004 - P. Harvey Created
//
// PHP port, part 2 of Writer.pl (package Image::ExifTool): writing files (WriteInfo,
// WriteDirectory, WriteJPEG, WriteBinaryData, trailers, file operations...), mixed into
// Image\ExifTool\ExifTool. Part 1 (new values) is src/ExifTool/NewValues.php; the subs used
// for reading are in src/ExifTool.php.
//
// The file-scope data of Writer.pl used by these subs is below, as private constants named
// after the Perl variables (%jpegMap -> JPEG_MAP...): being part of class ExifTool, they are
// also visible from the NewValues trait.
//------------------------------------------------------------------------------

namespace Image\ExifTool\ExifTool;

use Image\ExifTool\CanonVRD;
use Image\ExifTool\ExifTool;
use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Fixup;
use Image\ExifTool\MacOS;
use Image\ExifTool\NewValue;
use Image\ExifTool\Panasonic;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\Sony;
use Image\ExifTool\TagInfo;
use Image\ExifTool\TagTable;
use Image\ExifTool\Vars\ExifTool as Vars;

/**
 * PHP notes:
 * - an output file ($outfile, OutFile of dirInfo) is a stream resource or a ScalarRef (Perl \$buff);
 *   ExifTool::Write() appends to either and ExifTool::Tell() gives its position/length.
 * - WriteInfo() takes a file name, a ScalarRef (data in memory), a stream resource or a
 *   RandomAccess for the input, and a file name, ScalarRef or stream resource for the output.
 * - the write procs named by the tag tables (WriteBinaryData, WriteTIFF, DummyWriteProc,
 *   CheckBinaryData) are static methods taking the ExifTool object first, like the module ones.
 * - a write proc or module writer that is not ported yet makes the write fail with a
 *   "Not ported: ..." error (Perl always has them).
 */
trait Writer
{
    // the following is a road map of where we write each directory
    // in the different types of files.
    /** my %tiffMap */
    private const array TIFF_MAP = [
        'IFD0'         => 'TIFF',
        'IFD1'         => 'IFD0',
        'XMP'          => 'IFD0',
        'ICC_Profile'  => 'IFD0',
        'ExifIFD'      => 'IFD0',
        'GPS'          => 'IFD0',
        'SubIFD'       => 'IFD0',
        'GlobParamIFD' => 'IFD0',
        'PrintIM'      => 'IFD0',
        'IPTC'         => 'IFD0',
        'Photoshop'    => 'IFD0',
        'SEAL'         => 'IFD0',
        'InteropIFD'   => 'ExifIFD',
        'MakerNotes'   => 'ExifIFD',
        'CanonVRD'     => 'MakerNotes', // (so VRDOffset will get updated)
        'NikonCapture' => 'MakerNotes', // (to allow delete by group)
        'PhaseOne'     => 'MakerNotes', // (for editing PhaseOne SensorCalibration tags)
    ];
    /** my %exifMap */
    private const array EXIF_MAP = [
        'IFD1'         => 'IFD0',
        'EXIF'         => 'IFD0', // to write EXIF as a block
        'ExifIFD'      => 'IFD0',
        'GPS'          => 'IFD0',
        'SubIFD'       => 'IFD0',
        'GlobParamIFD' => 'IFD0',
        'PrintIM'      => 'IFD0',
        'InteropIFD'   => 'ExifIFD',
        'MakerNotes'   => 'ExifIFD',
        'NikonCapture' => 'MakerNotes', // (to allow delete by group)
        // (no CanonVRD trailer allowed)
    ];
    /** my %jpegMap */
    private const array JPEG_MAP = [
        ...self::EXIF_MAP, // covers all JPEG EXIF mappings
        'JFIF'         => 'APP0',
        'CIFF'         => 'APP0',
        'IFD0'         => 'APP1',
        'XMP'          => 'APP1',
        'ICC_Profile'  => 'APP2',
        'FlashPix'     => 'APP2',
        'MPF'          => 'APP2',
        'Meta'         => 'APP3',
        'MetaIFD'      => 'Meta',
        'RMETA'        => 'APP5',
        'SEAL'         => ['APP8', 'APP9'], // (note: add 'IFD0' if this is a possibility)
        'AROT'         => 'APP10',
        'JUMBF'        => 'APP11',
        'Ducky'        => 'APP12',
        'Photoshop'    => 'APP13',
        'Adobe'        => 'APP14',
        'IPTC'         => 'Photoshop',
        'MakerNotes'   => ['ExifIFD', 'CIFF'], // (first parent is the default)
        'CanonVRD'     => 'MakerNotes', // (so VRDOffset will get updated)
        'NikonCapture' => 'MakerNotes', // (to allow delete by group)
        'Comment'      => 'COM',
    ];
    /** my %dirMap */
    private const array DIR_MAP = [
        'JPEG' => self::JPEG_MAP,
        'EXV'  => self::JPEG_MAP,
        'TIFF' => self::TIFF_MAP,
        'ORF'  => self::TIFF_MAP,
        'RAW'  => self::TIFF_MAP,
        'EXIF' => self::EXIF_MAP,
    ];

    // module names and write functions for each writable file type
    // (defaults to "$type" and "Process$type" if not defined)
    // - types that are handled specially will not appear in this list
    /** my %writableType */
    private const array WRITABLE_TYPE = [
        'CRW' => ['CanonRaw',    'WriteCRW'],
        'DR4' =>  'CanonVRD',
        'EPS' => ['PostScript',  'WritePS'],
        'FLIF'=> [null,          'WriteFLIF'],
        'GIF' =>  null,
        'ICC' => ['ICC_Profile', 'WriteICC'],
        'IND' =>  'InDesign',
        'JP2' =>  'Jpeg2000',
        'JXL' =>  'Jpeg2000',
        'MIE' =>  null,
        'MOV' => ['QuickTime',   'WriteMOV'],
        'MRW' =>  'MinoltaRaw',
        'PDF' => [null,          'WritePDF'],
        'PNG' =>  null,
        'PPM' =>  null,
        'PS'  => ['PostScript',  'WritePS'],
        'PSD' =>  'Photoshop',
        'RAF' => ['FujiFilm',    'WriteRAF'],
        'RIFF'=> ['RIFF',        'WriteRIFF'],
        'VRD' =>  'CanonVRD',
        'WEBP'=> ['RIFF',        'WriteRIFF'],
        'X3F' =>  'SigmaRaw',
        'XMP' => [null,          'WriteXMP'],
    ];

    // RAW file types (2 = raw file where we can delete maker notes from ExifIFD)
    /** my %rawType */
    private const array RAW_TYPE = [
        '3FR'=> 1,  'CR3' => 2,  'IIQ' => 1,  'NEF' => 1,  'RW2' => 1,
        'ARQ' => 1,  'CRW' => 1,  'K25' => 1,  'NRW' => 1,  'RWL' => 1,
        'ARW' => 1,  'DCR' => 1,  'KDC' => 1,  'ORF' => 1,  'SR2' => 1,
        'ERF' => 1,  'MEF' => 1,  'PEF' => 1,  'SRF' => 1,
        'CR2' => 1,  'FFF' => 1,  'MOS' => 1,  'RAW' => 1,  'SRW' => 1,
    ];

    // family 0 groups where directories should never be deleted
    /** my %permanentDir */
    private const array PERMANENT_DIR = ['QuickTime' => 1, 'Jpeg2000' => 1];

    // group names to translate for writing
    /** my %translateWriteGroup */
    private const array TRANSLATE_WRITE_GROUP = [
        'EXIF'  => 'ExifIFD',
        'Meta'  => 'MetaIFD',
        'File'  => 'Comment',
        // any entry in this table causes the write group to be set from the
        // tag information instead of whatever the user specified...
        'MIE'   => 'MIE',
        'APP14' => 'APP14',
    ];

    /** my @writableMacOSTags */
    private const array WRITABLE_MAC_OS_TAGS = [
        'FileCreateDate', 'MDItemFinderComment', 'MDItemFSCreationDate', 'MDItemFSLabel',
        'MDItemUserTags', 'XAttrQuarantine', 'XAttrMDItemWhereFroms',
    ];

    // lookup for file types with block-writable EXIF
    /** my %blockExifTypes */
    private const array BLOCK_EXIF_TYPES = [
        'JPEG' => 1, 'PNG' => 1, 'JP2' => 1, 'JXL' => 1, 'MIE' => 1, 'EXIF' => 1, 'FLIF' => 1,
        'MOV' => 1, 'MP4' => 1, 'RIFF' => 1,
    ];

    /** my $maxSegmentLen: maximum length of data in a JPEG segment */
    private const int MAX_SEGMENT_LEN = 0xfffd;
    /** my $maxXMPLen: maximum length of XMP data in JPEG */
    private const int MAX_XMP_LEN = self::MAX_SEGMENT_LEN;

    //------------------------------------------------------------------------------
    // Set filesystem time from from FileModifyDate or FileCreateDate tag
    // Inputs: 0) ExifTool object reference, 1) file name or file ref
    //         2) time (-M or -C) of original file (used for shift; obtained from file if not given)
    //         3) tag name to write (undef for 'FileModifyDate')
    //         4) flag set if argument 2 has already been converted to Unix seconds
    // Returns: 1=time changed OK, 0=nothing done, -1=error setting time
    //          (increments CHANGED flag and sets corresponding WRITTEN tag)
    public function SetFileModifyDate($file, $originalTime = null, $tag = null, $isUnixTime = null)
    {
        $nvHash = null;
        if ($tag === null) $tag = 'FileModifyDate';
        $val = $this->GetNewValue($tag, $nvHash);
        if ($val === null) return 0;
        $isOverwriting = $this->IsOverwriting($nvHash);
        if (!$isOverwriting) return 0;
        // can currently only set creation date on Windows systems
        // (and Mac now too, but that is handled with the MacOS tags)
        if ($tag === 'FileCreateDate' and PHP_OS_FAMILY !== 'Windows') return 0;
        if ($isOverwriting < 0) {  // are we shifting time?
            // use original time of this file if not specified
            if ($originalTime === null) {
                [$aTime, $mTime, $cTime] = $this->GetFileTime($file) + [null, null, null];
                $originalTime = ($tag === 'FileCreateDate') ? $cTime : $mTime;
                if ($originalTime === null) return 0;
                $isUnixTime = 1;
            }
            // ($^T: time the program started)
            if (!$isUnixTime) $originalTime = Perl::int(($_SERVER['REQUEST_TIME'] ?? time()) - $originalTime * (24 * 3600) + 0.5);
            if (!$this->IsOverwriting($nvHash, $originalTime)) return 0;
            $val = $nvHash->Value[0];   // get shifted value
        }
        $aTime = $mTime = $cTime = null;
        if ($tag === 'FileCreateDate') {
            // (PHP: there is no Win32::API to set the creation time)
            $this->Warn("Install Win32::API to set $tag");
            return -1;
        } else {
            $aTime = $mTime = $val;
        }
        if (!$this->SetFileTime($file, $aTime, $mTime, $cTime, 1)) {
            $this->Warn("Error setting $tag");
            return -1;
        }
        ++$this->CHANGED;
        $this->WRITTEN[$tag] = $val;    // remember that we wrote this tag
        $this->VerboseValue("+ $tag", $val);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Change file name and/or directory from FileName and Directory tags
    // Inputs: 0) ExifTool object reference, 1) current file name (including path)
    //         2) new name (or undef to build from FileName and Directory tags)
    //         3) option: 'HardLink'/'SymLink' to create hard/symbolic link instead of renaming
    //                    'Test' to only print new file name
    //         4) 0 to indicate that a file will no longer exist (used for 'Test' only)
    // Returns: 1=name changed OK, 0=nothing changed, -1=error changing name
    //          (and increments CHANGED flag if filename changed)
    // Notes: Will not overwrite existing file.  Creates directories as necessary.
    public function SetFileName($file, $newName = null, $opt = null, $usedFlag = null)
    {
        $nvHash = $doName = $doDir = null;

        if (!$opt) $opt = '';
        // determine the new file name
        if ($newName === null) {
            if ($opt) {
                if ($opt === 'HardLink' or $opt === 'Link') {
                    $newName = $this->GetNewValue('HardLink');
                } elseif ($opt === 'SymLink') {
                    $newName = $this->GetNewValue('SymLink');
                } elseif ($opt === 'Test') {
                    $newName = $this->GetNewValue('TestName');
                }
                if ($newName === null) return 0;
            } else {
                $filename = $this->GetNewValue('FileName', $nvHash);
                if ($filename !== null and $this->IsOverwriting($nvHash, $file)) $doName = 1;
                $dir = $this->GetNewValue('Directory', $nvHash);
                if ($dir !== null and $this->IsOverwriting($nvHash, $file)) $doDir = 1;
                if (!($doName or $doDir)) return 0;  // nothing to do
                if ($doName) {
                    $newName = self::GetNewFileName($file, $filename);
                    if ($doDir) $newName = self::GetNewFileName($newName, $dir);
                } else {
                    $newName = self::GetNewFileName($file, $dir);
                }
            }
        }
        $newName = Perl::str($newName);
        // validate new file name in Windows
        if (PHP_OS_FAMILY === 'Windows') {
            if (preg_match('/[\0-\x1f<>"|*]/', $newName)) {
                $this->Warn('New file name not allowed in Windows (contains reserved characters)');
                return -1;
            }
            if (str_contains($newName, ':') and !preg_match('/^[A-Z]:[^:]*$/i', $newName)) {
                $this->Warn("New file name not allowed in Windows (contains ':')");
                return -1;
            }
            if (str_contains($newName, '?') and !preg_match('{^[\\\\/]{2}\?[\\\\/][^?]*$}', $newName)) {
                $this->Warn("New file name not allowed in Windows (contains '?')");
                return -1;
            }
            if (preg_match('{(^|[\\\\/])(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.[^.]*)?$}i', $newName)) {
                $this->Warn('New file name not allowed in Windows (reserved device name)');
                return -1;
            }
            if (preg_match('/([. ])$/', $newName, $m)) {
                if ($this->Warn("New file name not recommended for Windows (ends with '{$m[1]}')", 2)) return -1;
            }
            if (strlen($newName) > 259 and !str_contains($newName, '?')) {
                if ($this->Warn('New file name not recommended for Windows (exceeds 260 chars)', 2)) return -1;
            }
        } else {
            $newName = str_replace("\0", '', $newName);   // make sure name doesn't contain nulls
        }
        // protect against empty file name
        if (!strlen($newName)) {
            $this->Warn('New file name is empty');
            return -1;
        }
        // don't replace existing file
        if ($this->Exists($newName, 1) and ($usedFlag === null or $usedFlag)) {
            if (Perl::str($file) !== $newName or preg_match('/Link$/', $opt)) {
                // allow for case-insensitive filesystem
                if (preg_match('/Link$/', $opt) or !$this->IsSameFile($file, $newName)) {
                    $this->Warn("File '$newName' already exists");
                    return -1;
                }
            } else {
                $this->Warn('File name is unchanged');
                return 0;
            }
        }
        if ($opt === 'Test') {
            $out = $this->OPTIONS['TextOut'];
            fwrite($out, "'$file' --> '$newName'\n");
            return 1;
        }
        // create directory for new file if necessary
        $err = $this->CreateDirectory($newName);
        if ($err !== null) {
            if ($err) {
                if (!preg_match('/^Error creating/', $err)) $this->Warn($err);
                $this->Warn("Error creating directory for '$newName'");
                return -1;
            }
            $this->VPrint(0, "Created directory for '$newName'\n");
        }
        if ($opt === 'HardLink' or $opt === 'Link') {
            if (!@link($file, $newName)) {
                $this->Warn("Error creating hard link '$newName'");
                return -1;
            }
            ++$this->CHANGED;
            $this->VerboseValue('+ HardLink', $newName);
            return 1;
        } elseif ($opt === 'SymLink') {
            if (PHP_OS_FAMILY === 'Windows') {
                $this->Warn('SymLink not supported in Windows');
                return -1;
            }
            $newName = preg_replace('{^\./}', '', $newName);  // remove leading "./" from link name if it exists
            // path to linked file must be relative to the $newName directory, but $file
            // is relative to the current directory, so convert it to an absolute path
            // if using a relative directory and $newName isn't in the current directory
            if (!str_starts_with($file, '/') and str_contains($newName, '/')) {
                $file = realpath($file);    // (Cwd::abs_path)
                if ($file === false) {
                    $this->Warn('Error in Cwd::abs_path when creating symlink');
                    return -1;
                }
            }
            if (!@symlink($file, $newName)) {
                $this->Warn("Error creating symbolic link '$newName'");
                return -1;
            }
            ++$this->CHANGED;
            $this->VerboseValue('+ SymLink', $newName);
            return 1;
        }
        // attempt to rename the file
        if (!$this->Rename($file, $newName)) {
            $in = $outFh = null;
            // renaming didn't work, so copy the file instead
            if (!$this->Open($in, $file)) {
                $this->Error("Error opening '$file'");
                return -1;
            }
            if (!$this->Open($outFh, $newName, '>')) {
                fclose($in);
                $this->Error("Error creating '$newName'");
                return -1;
            }
            $err = null;
            while (($buff = fread($in, 65536)) !== false and $buff !== '') {
                if (!self::Write($outFh, $buff)) $err = 1;
            }
            if (!fclose($outFh)) $err = 1;
            fclose($in);
            if ($err) {
                $this->Unlink($newName);    // erase bad output file
                $this->Error("Error writing '$newName'");
                return -1;
            }
            // preserve modification time
            [$aTime, $mTime, $cTime] = $this->GetFileTime($file) + [null, null, null];
            $this->SetFileTime($newName, $aTime, $mTime, $cTime);
            // remove the original file
            if (!$this->Unlink($file)) $this->Warn('Error removing old file');
        }
        $this->NewName = $newName;  // remember new file name
        ++$this->CHANGED;
        $this->VerboseValue('+ FileName', $newName);
        return 1;
    }

    //------------------------------------------------------------------------------
    // Set file permissions, group/user id and various MDItem tags from new tag values
    // Inputs: 0) ExifTool ref, 1) file name or glob (must be a name for MDItem tags)
    // Returns: 1=something was set OK, 0=didn't try, -1=error (and warning set)
    // Notes: There may be errors even if 1 is returned
    public function SetSystemTags($file)
    {
        $result = 0;

        $perm = $this->GetNewValue('FilePermissions');
        if ($perm !== null) {
            if (@chmod(self::FilePath($file), Perl::int(Perl::num($perm)) & 07777)) {
                $this->VerboseValue('+ FilePermissions', $perm);
                $result = 1;
            } else {
                $this->Warn('Error setting FilePermissions');
                $result = -1;
            }
        }
        $uid = $this->GetNewValue('FileUserID');
        $gid = $this->GetNewValue('FileGroupID');
        if ($uid !== null or $gid !== null) {
            if ($uid === null) $uid = -1;
            if ($gid === null) $gid = -1;
            if (self::Chown($uid, $gid, self::FilePath($file))) {
                if ($uid >= 0) $this->VerboseValue('+ FileUserID', $uid);
                if ($gid >= 0) $this->VerboseValue('+ FileGroupID', $gid);
                $result = 1;
            } else {
                $this->Warn('Error setting FileGroup/UserID');
                if (!$result) $result = -1;
            }
        }
        foreach (self::WRITABLE_MAC_OS_TAGS as $tag) {
            $nvHash = null;
            $this->GetNewValue($tag, $nvHash);
            if (!$nvHash) continue;
            if (PHP_OS_FAMILY === 'Darwin') {
                if (!is_string($file)) {
                    $this->Warn('Setting MDItem tags requires a file name');
                    break;
                }
                self::RequireModule('Image::ExifTool::MacOS');
                if (!method_exists(MacOS::class, 'SetMacOSTags')) {
                    $this->Error('Not ported: Image\ExifTool\MacOS::SetMacOSTags');
                    $res = -1;
                } else {
                    $res = MacOS::SetMacOSTags($this, $file, self::WRITABLE_MAC_OS_TAGS);
                }
                if ($res == 1 or !$result) $result = $res;
                break;
            } elseif ($tag !== 'FileCreateDate') {
                $this->Warn('Can only set MDItem tags on MacOS');
                break;
            }
        }
        // delete Windows Zone.Identifier if specified
        $zhash = $this->GetNewValueHash(self::GetTagTable('Image::ExifTool::Extra')['ZoneIdentifier']);
        if ($zhash) {
            $res = -1;
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->Warn('ZoneIdentifer is a Windows-only tag');
            } elseif (!is_string($file)) {
                $this->Warn('Writing ZoneIdentifer requires a file name');
            } elseif ($this->GetNewValue('ZoneIdentifier', $zhash) !== null) {
                $this->Warn('ZoneIndentifier may only be deleted');
            } else {
                // (PHP: the Zone.Identifier stream is handled with the PHP file functions
                //  instead of Win32API::File)
                $zfile = "$file:Zone.Identifier";
                if (!file_exists($zfile)) {
                    $res = 0; // file doesn't exist, nothing to do
                } elseif (!is_writable($zfile)) {
                    $this->Warn('Zone.Identifier stream is read-only');
                } else {
                    if (@unlink($zfile)) $res = 1;
                    if ($res > 0) {
                        $this->VPrint(0, "  Deleting Zone.Identifier stream\n");
                    } else {
                        $this->Warn('Error deleting Zone.Identifier stream');
                    }
                }
            }
            if ($res == 1 or !$result) $result = $res;
        }
        return $result;
    }

    //------------------------------------------------------------------------------
    // Write information back to file
    // Inputs: 0) ExifTool object reference,
    //         1) input filename, file ref, RAF ref, or scalar ref (or '' or undef to create from scratch)
    //         2) output filename, file ref, or scalar ref (or undef to overwrite)
    //         3) optional output file type (required only if input file is not specified
    //            and output file is a reference)
    // Returns: 1=file written OK, 2=file written but no changes made, 0=file write error
    // (PHP: a file ref is a stream resource, a scalar ref a ScalarRef, a RAF ref a RandomAccess)
    public function WriteInfo($infile, $outfile = null, $outType = null)
    {
        $prec = ini_set('precision', '15');
        try {

        $fileTypeList = [];
        $fileType = $tiffType = $hdr = $seekErr = $type = $tmpfile = null;
        $inRef = $outRef = $closeIn = $closeOut = $outPos = $outBuff = $eraseIn = $raf = $fileExt = null;
        $hardLink = $symLink = $testName = null;
        $oldRaf = $this->RAF;
        $rtnVal = 0;

        // initialize member variables
        $this->Init();
        $this->IsWriting = 1;

        // first, save original file modify date if necessary
        // (do this now in case we are modifying file in place and shifting date)
        $nvHash = $nvHash2 = $originalTime = $createTime = null;
        $setModDate = $this->GetNewValue('FileModifyDate', $nvHash) !== null;
        $setCreateDate = $this->GetNewValue('FileCreateDate', $nvHash2) !== null;
        $aTime = $mTime = $cTime = null;
        if ($setModDate and $this->IsOverwriting($nvHash) < 0 and
            $infile !== null and !$infile instanceof ScalarRef)
        {
            [$aTime, $mTime, $cTime] = $this->GetFileTime($infile) + [null, null, null];
            $originalTime = $mTime;
        }
        if ($setCreateDate and $this->IsOverwriting($nvHash2) < 0 and
            $infile !== null and !$infile instanceof ScalarRef)
        {
            if ($cTime === null) [$aTime, $mTime, $cTime] = $this->GetFileTime($infile) + [null, null, null];
            $createTime = $cTime;
        }
        //
        // do quick in-place change of file dir/name or date if that is all we are doing
        //
        [$numNew, $numPseudo] = $this->CountNewValuesList();
        if ($outfile === null and $infile !== null) {
            $hardLink = $this->GetNewValue('HardLink');
            $symLink = $this->GetNewValue('SymLink');
            $testName = $this->GetNewValue('TestName');
            if ($hardLink !== null and !strlen($hardLink)) $hardLink = null;
            if ($symLink !== null and !strlen($symLink)) $symLink = null;
            if ($testName !== null and !strlen($testName)) $testName = null;
            $newFileName = $this->GetNewValue('FileName', $nvHash);
            $newDir = $this->GetNewValue('Directory');
            if ($newDir !== null and strlen($newDir)) {
                if (!preg_match('{/$}', $newDir)) $newDir .= '/';
            } else {
                $newDir = null;
            }
            if ($numNew == $numPseudo) {
                $rtnVal = 2;
                if (($newFileName !== null or $newDir !== null) and !self::IsRef($infile)) {
                    $result = $this->SetFileName($infile);
                    if ($result > 0) {
                        $infile = $this->NewName;   // file name changed
                        $rtnVal = 1;
                    } elseif ($result < 0) {
                        return 0;   // don't try to do anything else
                    }
                }
                if (!self::IsRef($infile) or is_resource($infile)) {
                    if ($setModDate and $this->SetFileModifyDate($infile) > 0) $rtnVal = 1;
                    if ($setCreateDate and $this->SetFileModifyDate($infile, null, 'FileCreateDate') > 0) $rtnVal = 1;
                    if ($this->SetSystemTags($infile) > 0) $rtnVal = 1;
                }
                if ($hardLink !== null or $symLink !== null or $testName !== null) {
                    if ($hardLink and $this->SetFileName($infile, $hardLink, 'HardLink')) $rtnVal = 1;
                    if ($symLink and $this->SetFileName($infile, $symLink, 'SymLink')) $rtnVal = 1;
                    if ($testName and $this->SetFileName($infile, $testName, 'Test')) $rtnVal = 1;
                }
                return $rtnVal;
            } elseif ($newFileName !== null and strlen($newFileName)) {
                // can't simply rename file, so just set the output name if new FileName
                // --> in this case, must erase original copy
                if (self::IsRef($infile)) {
                    $outfile = $newFileName;
                    // can't delete original
                } elseif ($this->IsOverwriting($nvHash, $infile)) {
                    $outfile = self::GetNewFileName($infile, $newFileName);
                    $eraseIn = 1; // delete original
                }
            }
            // set new directory if specified
            if ($newDir !== null) {
                if ($outfile === null and !self::IsRef($infile)) $outfile = $infile;
                if ($outfile !== null) {
                    $outfile = self::GetNewFileName($outfile, $newDir);
                    if (!self::IsRef($infile)) $eraseIn = 1;
                }
            }
        }
        //
        // set up input file
        //
        if (self::IsRef($infile)) {
            $inRef = $infile;
            if (is_resource($inRef)) {
                fseek($inRef, 0, SEEK_SET); // make sure we are at the start of the file
            } elseif ($inRef instanceof RandomAccess) {
                $inRef->Seek(0);
                $raf = $inRef;
            }
            // (PHP strings have no UTF-8 flag, so there is nothing to convert to a
            //  character stream like Perl does with Encode::encode('utf8', $$inRef))
        } elseif ($infile !== null and Perl::str($infile) !== '') {
            // write to a temporary file if no output file given
            if ($outfile === null) $outfile = $tmpfile = "{$infile}_exiftool_tmp";
            $fh = null;
            if ($this->Open($fh, $infile)) {
                $fileExt = self::GetFileExtension($infile);
                $fileType = self::GetFileType($infile);
                $fileTypeList = self::GetFileTypeList($infile);
                $tiffType = $this->FILE_EXT = self::GetFileExtension($infile);
                $this->VPrint(0, "Rewriting $infile...\n");
                $inRef = $fh;
                $closeIn = 1;   // we must close the file since we opened it
            } else {
                $this->Error('Error opening file');
                return 0;
            }
        } elseif ($outfile === null) {
            $this->Error("WriteInfo(): Must specify infile or outfile\n");
            return 0;
        } else {
            // create file from scratch
            if (!($outType or self::IsRef($outfile))) $outType = self::GetFileExtension($outfile);
            if (self::CanCreate($outType)) {
                if (preg_match('/g/i', (string) $this->OPTIONS['WriteMode'])) {
                    $fileType = $tiffType = $outType;   // use output file type if no input file
                    $infile = "$fileType file";         // make bogus file name
                    $this->VPrint(0, "Creating $infile...\n");
                    $inRef = new ScalarRef('');         // set $inRef to reference to empty data
                } else {
                    $this->Error("Not creating new $outType file (disallowed by WriteMode)");
                    return 0;
                }
            } elseif ($outType) {
                $this->Error("Can't create $outType files");
                return 0;
            } else {
                $this->Error("Can't create file (unknown type)");
                return 0;
            }
        }
        if (!$fileTypeList) {
            if ($fileType) {
                $fileTypeList = [$fileType];
            } else {
                $fileTypeList = Vars::$fileTypes;
                $tiffType = 'TIFF';
            }
        }
        //
        // set up output file
        //
        if (self::IsRef($outfile)) {
            $outRef = $outfile;
            if (is_resource($outRef)) {
                $outPos = ftell($outRef);
                if ($outPos === false) $outPos = -1;
            } else {
                // initialize our output buffer if necessary
                if ($outRef->value === null) $outRef->value = '';
                $outPos = strlen($outRef->value);
            }
        } elseif ($outfile === null) {
            // editing in place, so write to memory first
            // (only when infile is a file ref or scalar ref)
            if ($raf) {
                $this->Error("Can't edit File::RandomAccess object in place");
                return 0;
            }
            $outBuff = new ScalarRef('');
            $outRef = $outBuff;
            $outPos = 0;
        } elseif ($this->Exists($outfile, 1)) {
            $this->Error("File already exists: $outfile");
        } elseif ($this->Open($fh2, $outfile, '>')) {
            $outRef = $fh2;
            $closeOut = 1;  // we must close $outRef
            $outPos = 0;
        } else {
            $tmp = $tmpfile ? ' temporary' : '';
            $this->Error("Error creating$tmp file: $outfile");
        }
        //
        // write the file
        //
        while (!($this->VALUE['Error'] ?? null)) {
            // create random access file object (disable seek test in case of straight copy)
            if (!$raf) $raf = new RandomAccess($inRef instanceof ScalarRef ? Perl::str($inRef->value) : $inRef, true);
            $raf->BinMode();
            if ($numNew == $numPseudo) {
                $rtnVal = 1;
                // just do a straight copy of the file (no "real" tags are being changed)
                $buff = null;
                while ($raf->Read($buff, 65536)) {
                    if (!self::Write($outRef, $buff)) {
                        $rtnVal = -1;
                        break;
                    }
                }
                break;
            } elseif (!self::IsRef($infile) and ($infile === '-' or preg_match('/\|$/', Perl::str($infile)))) {
                // patch for Windows command shell pipe
                $raf->TESTED = -1;  // force buffering
            } else {
                $raf->SeekTest();
            }
            // $raf->Debug() and warn "  RAF debugging enabled!\n";
            $inPos = $raf->Tell();
            $this->RAF = $raf;
            $dirInfo = [
                'RAF' => $raf,
                'OutFile' => $outRef,
            ];
            if (!$raf->Read($hdr, 1024)) $hdr = '';
            if (!$raf->Seek($inPos, 0)) $seekErr = 1;
            $wrongType = null;
            while (!$seekErr) {
                $type = array_shift($fileTypeList);
                // do quick test to see if this is the right file type
                $magic = Vars::$magicNumber[$type] ?? null;
                if ($magic and strlen($hdr) and !preg_match(self::Rx('^' . $magic, 's'), $hdr)) {
                    if ($fileTypeList) continue;
                    $wrongType = 1;
                    break;
                }
                // save file type in member variable
                $dirInfo['Parent'] = $this->FILE_TYPE = $this->PATH[0] = $type;
                // determine which directories we must write for this file type
                $this->InitWriteDirs($type);
                if ($type === 'JPEG' or $type === 'EXV') {
                    $rtnVal = $this->WriteJPEG($dirInfo);
                } elseif ($type === 'TIFF') {
                    // disallow writing of some TIFF-based RAW images:
                    if (preg_grep(self::Rx('^' . $tiffType . '$'), Vars::$noWriteFile['TIFF'])) {
                        $fileType = $tiffType;
                        $rtnVal = null;
                    } else {
                        if ($tiffType === 'FFF') {
                            // (see https://exiftool.org/forum/index.php?topic=10848.0)
                            $this->Error('Phocus may not properly update previews of edited FFF images', 1);
                        }
                        $dirInfo['Parent'] = $tiffType;
                        $rtnVal = $this->ProcessTIFF($dirInfo);
                    }
                } elseif (array_key_exists($type, self::WRITABLE_TYPE)) {
                    $module = $func = null;
                    if (is_array(self::WRITABLE_TYPE[$type])) {
                        $module = self::WRITABLE_TYPE[$type][0] ?: $type;
                        $func = self::WRITABLE_TYPE[$type][1];
                    } else {
                        $module = self::WRITABLE_TYPE[$type] ?: $type;
                    }
                    self::RequireModule("Image::ExifTool::$module");
                    $func = 'Image\\ExifTool\\' . self::ClassName($module) . '::' . ($func ?: "Process$type");
                    if (is_callable($func)) {
                        $rtnVal = $func($this, $dirInfo);
                    } else {
                        $rtnVal = null; // (PHP: writer not ported yet: we don't write this type of file)
                    }
                } elseif ($type === 'ORF' or $type === 'RAW') {
                    $rtnVal = $this->ProcessTIFF($dirInfo);
                } elseif ($type === 'EXIF') {
                    // go through WriteDirectory so block writes, etc are handled
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Exif::Main');
                    $buff = $this->WriteDirectory($dirInfo, $tagTablePtr, self::class . '::WriteTIFF');
                    if ($buff !== null) {
                        $rtnVal = self::Write($outRef, $buff) ? 1 : -1;
                    } else {
                        $rtnVal = 0;
                    }
                } else {
                    $rtnVal = null; // flag that we don't write this type of file
                }
                // all done unless we got the wrong type
                if ($rtnVal) break;
                if (!$fileTypeList) break;
                // seek back to original position in files for next try
                if (!$raf->Seek($inPos, 0)) {
                    $seekErr = 1;
                    break;
                }
                if (is_resource($outRef)) {
                    @fseek($outRef, 0, $outPos);    // (sic: Perl calls seek($outRef, 0, $outPos))
                } else {
                    $outRef->value = substr($outRef->value, 0, $outPos);
                }
            }
            // print file format errors
            if (!$rtnVal) {
                $err = null;
                if ($seekErr) {
                    $err = 'Error seeking in file';
                } elseif ($fileType and $rtnVal !== null) {
                    if ($this->VALUE['Error'] ?? null) {
                        // existing error message will do
                    } elseif ($fileType === 'RAW') {
                        $err = 'Writing this type of RAW file is not supported';
                    } else {
                        if ($wrongType) {
                            $t = $fileExt ?: ($fileType === 'TIFF' ? $tiffType : $fileType);
                            $err = "Not a valid $t";
                            // do a quick check to see what this file looks like
                            foreach (Vars::$fileTypes as $t) {
                                $magic = Vars::$magicNumber[$t] ?? null;
                                if (!$magic) continue;
                                if (!preg_match(self::Rx('^' . $magic, 's'), $hdr)) continue;
                                $err .= " (looks more like a $t)";
                                break;
                            }
                        } else {
                            $err = 'Format error in file';
                        }
                    }
                } elseif ($fileType) {
                    // get specific type of file from extension
                    if ($infile and is_string($infile) and self::GetFileType($infile)) $fileType = self::GetFileExtension($infile);
                    $err = "Writing of $fileType files is not yet supported";
                } else {
                    $err = 'Writing of this type of file is not supported';
                }
                if ($err) $this->Error($err);
                $rtnVal = 0;    // (in case it was undef)
            }
            // $raf->Close();  // only used to force debug output
            break;  // (didn't really want to loop)
        }
        // don't return success code if any error occurred
        if ($rtnVal > 0) {
            if ($outType and $type and Perl::str($outType) !== $type) {
                $types = self::GetFileTypeList($outType);
                if (!preg_grep(self::Rx('^' . $type . '$'), $types)) {
                    $this->Error("Can't create $outType file from $type");
                    $rtnVal = 0;
                }
            }
            if ($rtnVal > 0 and !self::Tell($outRef) and !($this->VALUE['Error'] ?? null)) {
                // don't write a file with zero length
                if ($hdr !== null and strlen($hdr)) {
                    if ($type === null) $type = '<unk>';
                    $this->Error("Can't delete all meta information from $type file");
                } else {
                    $this->Error('Nothing to write');
                }
            }
            if ($this->VALUE['Error'] ?? null) $rtnVal = 0;
        }

        // rewrite original file in place if required
        if ($outBuff !== null) {
            if ($rtnVal <= 0 or !$this->CHANGED) {
                // nothing changed, so no need to write $outBuff
            } elseif (is_resource($inRef)) {
                $len = strlen($outBuff->value);
                $size = null;
                if (!(fseek($inRef, 0, SEEK_END) === 0 and                  // seek to the end of file
                    ($size = ftell($inRef)) !== false and $size >= 0 and    // get the file size
                    fseek($inRef, 0, SEEK_SET) === 0 and                    // seek back to the start
                    self::Write($inRef, $outBuff->value) and                // write the new data
                    ($len >= $size or                                       // if necessary:
                    @ftruncate($inRef, $len))))                             //  shorten output file
                {
                    $rtnVal = -1;
                }
            } else {
                $inRef->value = $outBuff->value;    // replace original data
            }
            $outBuff->value = '';   // free memory but leave $outBuff defined
        }
        // close input file if we opened it
        if ($closeIn) {
            // errors on input file are significant if we edited the file in place
            if (!(fclose($inRef) or $outBuff === null) and $rtnVal) $rtnVal = -1;
            if ($rtnVal > 0) {
                // copy Mac OS resource fork if it exists
                if (PHP_OS_FAMILY === 'Darwin' and @filesize("$infile/..namedfork/rsrc")) {
                    if ($this->DEL_GROUP['RSRC'] ?? null) {
                        $this->VPrint(0, "Deleting Mac OS resource fork\n");
                        ++$this->CHANGED;
                    } else {
                        $this->VPrint(0, "Copying Mac OS resource fork\n");
                        $buf = $err = $src = $dst = null;
                        if ($this->Open($src, "$infile/..namedfork/rsrc")) {
                            if ($this->Open($dst, "$outfile/..namedfork/rsrc", '>')) {
                                while (($buf = fread($src, 65536)) !== false and $buf !== '') {
                                    if (!self::Write($dst, $buf)) {
                                        $err = 'copying';
                                        break;
                                    }
                                }
                                if (!fclose($dst) and !$err) $err = 'closing';
                            } else {
                                // (this is normal if the destination filesystem isn't Mac OS)
                                $this->Warn('Error creating Mac OS resource fork');
                            }
                            fclose($src);
                        } else {
                            $err = 'opening';
                        }
                        if ($err and $this->Error("Error $err Mac OS resource fork", 2)) $rtnVal = 0;
                    }
                }
                // erase input file if renaming while editing information in place
                if ($eraseIn and !$this->Unlink($infile)) $this->Warn('Error erasing original file');
            }
        }
        // close output file if we created it
        if ($closeOut) {
            // close file and set $rtnVal to -1 if there was an error
            if (!fclose($outRef) and $rtnVal) $rtnVal = -1;
            // erase the output file if we weren't successful
            if ($rtnVal <= 0) {
                $this->Unlink($outfile);
            // else rename temporary file if necessary
            } elseif ($tmpfile) {
                $this->CopyFileAttrs($infile, $tmpfile);    // copy attributes to new file
                if (!$this->Rename($tmpfile, $infile)) {
                    // some filesystems won't overwrite with 'rename', so try erasing original
                    if (!$this->Unlink($infile)) {
                        $this->Unlink($tmpfile);
                        $this->Error('Error renaming temporary file');
                        $rtnVal = 0;
                    } elseif (!$this->Rename($tmpfile, $infile)) {
                        $this->Error('Error renaming temporary file after deleting original');
                        $rtnVal = 0;
                    }
                }
                // the output file should now have the name of the original infile
                if ($rtnVal > 0) $outfile = $infile;
            }
        }
        // set filesystem attributes if requested (and if possible!)
        if ($rtnVal > 0 and ($closeOut or ($outBuff !== null and ($closeIn or is_resource($infile))))) {
            $target = $closeOut ? $outfile : $infile;
            // set file permissions if requested
            if ($this->SetSystemTags($target) > 0) ++$this->CHANGED;
            if ($closeIn) { // (no use setting file times unless the input file is closed)
                if ($setModDate and $this->SetFileModifyDate($target, $originalTime, null, 1) > 0) ++$this->CHANGED;
                // set FileCreateDate if requested (and if possible!)
                if ($setCreateDate and $this->SetFileModifyDate($target, $createTime, 'FileCreateDate', 1) > 0) ++$this->CHANGED;
                // create hard link if requested and no output filename specified (and if possible!)
                if ($hardLink !== null and $this->SetFileName($target, $hardLink, 'HardLink')) ++$this->CHANGED;
                if ($symLink !== null and $this->SetFileName($target, $symLink, 'SymLink')) ++$this->CHANGED;
                if ($testName !== null) $this->SetFileName($target, $testName, 'Test');
            }
        }
        // check for write error and set appropriate error message and return value
        if ($rtnVal < 0) {
            if (!($this->VALUE['Error'] ?? null)) $this->Error('Error writing output file');
            $rtnVal = 0;    // return 0 on failure
        } elseif ($rtnVal > 0) {
            if (!$this->CHANGED) ++$rtnVal;
        }
        // set things back to the way they were
        $this->RAF = $oldRaf;

        return $rtnVal;

        } finally {
            ini_set('precision', $prec);
        }
    }

    //------------------------------------------------------------------------------
    // Check to see if these are the same file
    // Inputs: 0) ExifTool ref, 1) first file name, 2) second file name
    // Returns: true if file names reference the same file
    public function IsSameFile($file, $file2)
    {
        if (strtolower($file) !== strtolower($file2)) return 0;  // (only looking for differences in case)
        $isSame = null;
        $tmp1 = "{$file}_ExifTool_tmp_" . getmypid();
        $tmp2 = "{$file2}_ExifTool_tmp_" . getmypid();
        // (PHP: no SIGINT handler while the temporary file exists)
        $fh = null;
        if ($this->Open($fh, $tmp1, '>')) {
            fclose($fh);
            if ($this->Exists($tmp2)) $isSame = 1;
            $this->Unlink($tmp1);
        }
        return $isSame;
    }

    //------------------------------------------------------------------------------
    // Is this a raw file type?
    // Inputs: 0) ExifTool ref
    // Returns: true if FileType is a type of RAW image
    public function IsRawType()
    {
        return self::RAW_TYPE[Perl::str($this->FileType)] ?? null;
    }

    //------------------------------------------------------------------------------
    // Copy file attributes from one file to another
    // Inputs: 0) ExifTool ref, 1) source file name, 2) destination file name
    // Notes: eventually add support for extended attributes?
    public function CopyFileAttrs($src, $dst): void
    {
        $stat = @stat($src);
        [$mode, $uid, $gid] = $stat ? [$stat[2], $stat[4], $stat[5]] : [null, null, null];
        // copy file attributes unless we already set them
        if ($mode !== null and $this->GetNewValue('FilePermissions') === null) {
            @chmod($dst, $mode & 07777);
        }
        $newUid = $this->GetNewValue('FileUserID');
        $newGid = $this->GetNewValue('FileGroupID');
        if ($uid !== null and $gid !== null and ($newUid === null or $newGid === null)) {
            if ($newGid !== null) $gid = $newGid;
            if ($newUid !== null) $uid = $newUid;
            self::Chown($uid, $gid, $dst);
        }
    }

    //------------------------------------------------------------------------------
    // Get new file path name
    // Inputs: 0) existing name (may contain directory),
    //         1) new file name, new directory, or new path (dir+name)
    // Returns: new file path name
    public static function GetNewFileName($oldName, $newName)
    {
        $oldName = Perl::str($oldName);
        $newName = Perl::str($newName);
        if (preg_match('{(.*/)(.*)}', $oldName, $m)) {
            [$dir, $name] = [$m[1], $m[2]];
        } else {
            [$dir, $name] = ['', $oldName];
        }
        if (preg_match('{/$}', $newName)) {
            $newName = "$newName$name"; // change dir only
        } elseif (!str_contains($newName, '/')) {
            $newName = "$dir$newName";  // change name only if newname doesn't specify dir
        }                               // else change dir and name
        return $newName;
    }

    //------------------------------------------------------------------------------
    // Get a tagInfo/tagID hash for subdirectories we need to add
    // Inputs: 0) ExifTool object reference, 1) parent tag table reference
    //         2) parent directory name (taken from GROUP0 of tag table if not defined)
    // Returns: Reference to Hash of subdirectory tagInfo references keyed by tagID
    //          (plus Reference to edit directory hash in list context)
    //          (PHP: list context is GetAddDirHashList)
    public function GetAddDirHash(TagTable $tagTablePtr, $parent = null): array
    {
        return $this->GetAddDirHashList($tagTablePtr, $parent)[0];
    }

    /** @return array{0: array, 1: array} the add and edit directory hashes */
    public function GetAddDirHashList(TagTable $tagTablePtr, $parent = null): array
    {
        if (!$parent) $parent = $tagTablePtr['GROUPS'][0];
        $addDirHash = [];
        $editDirHash = [];
        $addDirs = $this->ADD_DIRS;
        $editDirs = $this->EDIT_DIRS;
        foreach (self::TagTableKeys($tagTablePtr) as $tagID) {
            $infoArray = self::GetTagInfoList($tagTablePtr, $tagID);
            foreach ($infoArray as $tagInfo) {
                if (!$tagInfo->SubDirectory) continue;
                // get name for this sub directory
                // (take directory name from SubDirectory DirName if it exists,
                //  otherwise Group0 name of SubDirectory TagTable or tag Group1 name)
                $dirName = $tagInfo->SubDirectory['DirName'] ?? null;
                if (!$dirName) {
                    // use tag name for directory name and save for next time
                    $dirName = $tagInfo->Name;
                    $tagInfo->SubDirectory['DirName'] = $dirName;
                }
                // save this directory information if we are writing it
                if (($editDirs[$dirName] ?? null) and $editDirs[$dirName] === $parent) {
                    $editDirHash[$tagID] = $tagInfo;
                    if ($addDirs[$dirName] ?? null) $addDirHash[$tagID] = $tagInfo;
                }
            }
        }
        return [$addDirHash, $editDirHash];
    }

    //------------------------------------------------------------------------------
    // initialize ADD_DIRS and EDIT_DIRS hashes for all directories that need
    // to be created or will have tags changed in them
    // Inputs: 0) ExifTool object reference, 1) file type string (or map hash ref)
    //         2) preferred family 0 group for creating tags, 3) alternate preferred group
    // Notes:
    // - the ADD_DIRS and EDIT_DIRS keys are the directory names, and the values
    //   are the names of the parent directories (undefined for a top-level directory)
    // - also initializes FORCE_WRITE lookup
    public function InitWriteDirs($fileType, $preferredGroup = null, $altGroup = null): void
    {
        $this->EDIT_DIRS = [];
        $this->ADD_DIRS = [];
        $editDirs = &$this->EDIT_DIRS;
        $addDirs = &$this->ADD_DIRS;
        $fileDirs = is_string($fileType) ? (self::DIR_MAP[$fileType] ?? null) : null;
        if (!$fileDirs) {
            if (!(is_array($fileType) and !array_is_list($fileType))) return;
            $fileDirs = $fileType;
        }
        $tagInfoList = $this->GetNewTagInfoList();

        // save the preferred group
        $this->PreferredGroup = $preferredGroup;

        foreach ($tagInfoList as $tagInfo) {
            // cycle through all hashes in linked list
            for ($nvHash = $this->GetNewValueHash($tagInfo); $nvHash; $nvHash = $nvHash->Next) {
                // are we creating this tag? (otherwise just deleting or editing it)
                $isCreating = $nvHash->IsCreating;
                if ($preferredGroup) {
                    $g0 = $this->GetGroup($tagInfo, 0);
                    if ($isCreating) {
                        // if another group is taking priority, only create
                        // directory if specifically adding tags to this group
                        // or if this tag isn't being added to the priority group
                        if ($preferredGroup !== $g0 and
                            ($nvHash->CreateGroups[$preferredGroup] ?? null) and
                            (!$altGroup or $altGroup !== $g0)) $isCreating = 0;
                    } else {
                        // create this directory if any tag is preferred and has a value
                        // (unless group creation is disabled via the WriteMode option)
                        if ($nvHash->Value !== null and $preferredGroup === $g0 and
                            !$nvHash->EditOnly and preg_match('/g/', (string) $this->OPTIONS['WriteMode'])) $isCreating = 1;
                    }
                }
                // tag belongs to directory specified by WriteGroup, or by
                // the Group0 name if WriteGroup not defined
                $dirName = Perl::str($nvHash->WriteGroup);
                // remove MIE copy number(s) if they exist
                if (preg_match('/^MIE\d*(-[a-z]+)?\d*$/i', $dirName, $m)) {
                    $dirName = 'MIE' . (($m[1] ?? '') ?: '');
                }
                $dirNames = [];
                // allow a group name of '*' to force writing EXIF/IPTC/XMP/PNG (ForceWrite tag)
                if ($dirName === '*' and $nvHash->Value !== null) {
                    $val = $nvHash->Value[0];
                    if ($val) {
                        foreach (['EXIF', 'IPTC', 'XMP', 'PNG', 'FixBase'] as $g) {
                            if (!preg_match("/\\b($g|All)\\b/i", Perl::str($val))) continue;
                            $dirNames[] = $g;
                            if ($g === 'FixBase') $dirNames[] = 'EXIF';
                            $this->FORCE_WRITE[$g] = 1;
                        }
                    }
                    $dirName = array_shift($dirNames);
                } elseif ($dirName === 'QuickTime') {
                    // write to specific QuickTime group
                    $dirName = $this->GetGroup($tagInfo, 1);
                }
                while ($dirName) {
                    $parent = $fileDirs[$dirName] ?? null;
                    if (is_array($parent)) {
                        array_push($dirNames, ...array_reverse($parent));
                        $parent = array_pop($dirNames);
                    }
                    $editDirs[$dirName] = $parent;
                    if ($isCreating and $isCreating != 2) $addDirs[$dirName] = $parent;
                    $dirName = $parent ?: array_shift($dirNames);
                }
            }
        }
        if ($this->DEL_GROUP) {
            // add delete groups to list of edited groups
            foreach (array_keys($this->DEL_GROUP) as $dirName) {
                $dirName = (string) $dirName;
                if (str_starts_with($dirName, '-')) continue;   // ignore excluded groups
                // translate necessary group 0 names
                if (self::TRANSLATE_WRITE_GROUP[$dirName] ?? null) $dirName = self::TRANSLATE_WRITE_GROUP[$dirName];
                // convert XMP group 1 names
                if (str_starts_with($dirName, 'XMP-')) $dirName = 'XMP';
                $dirNames = [];
                while ($dirName) {
                    $parent = $fileDirs[$dirName] ?? null;
                    if (is_array($parent)) {
                        array_push($dirNames, ...array_reverse($parent));
                        $parent = array_pop($dirNames);
                    }
                    $editDirs[$dirName] = $parent;
                    $dirName = $parent ?: array_shift($dirNames);
                }
            }
        }
        // special case to edit JFIF to get resolutions if editing EXIF information
        if (($editDirs['IFD0'] ?? null) and ($fileDirs['JFIF'] ?? null)) {
            $editDirs['JFIF'] = 'IFD1';
            $editDirs['APP0'] = null;
        }

        if ($this->OPTIONS['Verbose']) {
            $out = $this->OPTIONS['TextOut'];
            $keys = array_map('strval', array_keys($editDirs));
            sort($keys, SORT_STRING);
            fwrite($out, '  Editing tags in: ' . implode('', array_map(static fn ($k) => "$k ", $keys)) . "\n");
            if (!($this->OPTIONS['Verbose'] > 1)) return;
            $keys = array_map('strval', array_keys($addDirs));
            sort($keys, SORT_STRING);
            fwrite($out, '  Creating tags in: ' . implode('', array_map(static fn ($k) => "$k ", $keys)) . "\n");
        }
    }

    //------------------------------------------------------------------------------
    // Write an image directory
    // Inputs: 0) ExifTool object reference, 1) source directory information reference
    //         2) tag table reference, 3) optional reference to writing procedure
    // Returns: New directory data or undefined on error (or empty string to delete directory)
    public function WriteDirectory(array &$dirInfo, ?TagTable $tagTablePtr, $writeProc = null)
    {
        $out = $nvHash = $delFlag = null;

        if (!$tagTablePtr) return null;
        if ($this->OPTIONS['Verbose']) $out = $this->OPTIONS['TextOut'];
        // set directory name from default group0 name if not done already
        $dirName = $dirInfo['DirName'] ?? null;
        $parent = ($dirInfo['Parent'] ?? null) ?: '';
        $dataPt = $dirInfo['DataPt'] ?? null;
        $grp0 = $tagTablePtr['GROUPS'][0];
        if (!$dirName) $dirName = $dirInfo['DirName'] = $grp0;
        if ($this->DEL_GROUP) {
            $delGroup = &$this->DEL_GROUP;
            // delete entire directory if specified
            $grp1 = $dirName;
            $delFlag = (($delGroup[$grp0] ?? null) ?: ($delGroup[$grp1] ?? null));
            if ((self::PERMANENT_DIR[$grp0] ?? null) and !(($dirInfo['TagInfo'] ?? null) and $dirInfo['TagInfo']->Deletable)) {
                $delFlag = null;
            }
            if ($delFlag) {
                $tagInfo = $dirInfo['TagInfo'] ?? null;
                if ($dirInfo['Permanent'] ?? null) {
                    $this->Warn("Not deleting permanent $dirName directory");
                    $grp1 = null;
                } elseif ((preg_match('/^(MakerNotes)$/', $grp0, $m) or preg_match('/^(IFD0|ExifIFD|MakerNotes)$/', $grp1, $m)) and
                    $this->IsRawType() and
                    // allow non-permanent MakerNote directories to be deleted (ie. NikonCapture)
                    (!$tagInfo or $tagInfo->Permanent === null or $tagInfo->Permanent) and
                    // allow MakerNotes to be deleted from ExifIFD of CR3 file
                    !($this->IsRawType() == 2 and $parent === 'ExifIFD'))
                {
                    $this->Warn("Can't delete {$m[1]} from {$this->FileType}", 1);
                    $grp1 = null;
                } elseif (!(self::BLOCK_EXIF_TYPES[Perl::str($this->FILE_TYPE)] ?? null)) {
                    // restrict delete logic to prevent entire tiff image from being killed
                    // (don't allow IFD0 to be deleted, and delete only ExifIFD if EXIF specified)
                    if ($this->FILE_TYPE === 'PSD') {
                        // don't delete Photoshop directories from PSD image
                        if ($grp0 === 'Photoshop') $grp1 = null;
                    } elseif (preg_match('/^(EPS|PS)$/', (string) $this->FILE_TYPE)) {
                        // allow anything to be deleted from PostScript files
                    } elseif ($grp1 === 'IFD0') {
                        $type = $this->TIFF_TYPE ?: $this->FILE_TYPE;
                        if ($delGroup['IFD0'] ?? null) $this->Warn("Can't delete IFD0 from $type", 1);
                        $grp1 = null;
                    } elseif ($grp0 === 'EXIF' and ($delGroup[$grp0] ?? null)) {
                        if (!(($delGroup[$grp1] ?? null) or $grp1 === 'ExifIFD')) $grp1 = null;
                    }
                }
                if ($grp1) {
                    if ($dataPt !== null or ($dirInfo['RAF'] ?? null)) {
                        ++$this->CHANGED;
                        if ($out) fwrite($out, "  Deleting $grp1\n");
                        if ($grp1 === 'ICC_Profile') $this->Warn('ICC_Profile deleted. Image colors may be affected');
                        // can no longer validate TIFF_END if deleting an entire IFD
                        if (str_contains($dirName, 'IFD')) $this->TIFF_END = null;
                    }
                    // don't add back into the wrong location
                    $right = $this->ADD_DIRS[$grp1] ?? null;
                    // (take care because EXIF directory name may be either EXIF or IFD0,
                    //  but IFD0 will be the one that appears in the directory map)
                    if (!$right and $grp1 === 'EXIF') $right = $this->ADD_DIRS['IFD0'] ?? null;
                    if ($delFlag == 2 and $right) {
                        // also check grandparent because some routines create 2 levels in 1
                        $right2 = ($this->ADD_DIRS[$right] ?? null) ?: '';
                        if (!$parent or $parent === $right or $parent === $right2) {
                            // prevent duplicate directories from being recreated at the same path
                            $path = implode('-', [...$this->PATH, $dirName]);
                            $this->Recreated ??= [];
                            if ($this->Recreated[$path] ?? null) {
                                $p = $parent ? " in $parent" : '';
                                $this->Warn("Not recreating duplicate $grp1$p", 1);
                                return '';
                            }
                            $this->Recreated[$path] = 1;
                            // empty the directory
                            // (the local $dataPt still refers to the old data, like in Perl)
                            $dirInfo['DataPt'] = '';
                            $dirInfo['DataLen']  = 0;
                            $dirInfo['DirStart'] = 0;
                            $dirInfo['DirLen']   = 0;
                            unset($dirInfo['RAF'], $dirInfo['Base'], $dirInfo['DataPos']);
                        } else {
                            $this->Warn("Not recreating $grp1 in $parent (should be in $right)", 1);
                            return '';
                        }
                    } else {
                        if (!($dirInfo['NoDelete'] ?? null)) return '';
                    }
                }
            }
            unset($delGroup);
        }
        // use default proc from tag table if no proc specified
        if (!$writeProc) $writeProc = $tagTablePtr['WRITE_PROC'];
        if (!$writeProc) return null;

        // are we rewriting a pre-existing directory?
        $isRewriting = (($dirInfo['DirLen'] ?? null) or ($dataPt !== null and strlen($dataPt)) or ($dirInfo['RAF'] ?? null));

        // copy or delete new directory as a block if specified
        $blockName = $dirName;
        if ($blockName === 'IFD0') $blockName = 'EXIF';
        $tagInfo = self::GetTagTable('Image::ExifTool::Extra')[$blockName] ?? ($dirInfo['TagInfo'] ?? null);
        while ($tagInfo instanceof TagInfo and ($nvHash = $this->NEW_VALUE[spl_object_id($tagInfo)] ?? null) and
            $this->IsOverwriting($nvHash) and !($nvHash->CreateOnly and $isRewriting))
        {
            // protect against writing EXIF to wrong file types, etc
            if ($blockName === 'EXIF') {
                if (!(self::BLOCK_EXIF_TYPES[Perl::str($this->FILE_TYPE)] ?? null)) {
                    $this->Warn("Can't write EXIF as a block to {$this->FILE_TYPE} file");
                    break;
                }
                // this can happen if we call WriteDirectory for an EXIF directory without going
                // through WriteTIFF as the WriteProc (which happens if conditionally replacing
                // the EXIF block and the condition fails), but we never want to do a block write
                // in this case because the EXIF block would end up with two TIFF headers
                if ($writeProc !== self::class . '::WriteTIFF') break;
            }
            if (!$this->IsOverwriting($nvHash, $dataPt !== null ? $dataPt : '')) break;
            $verb = 'Writing';
            $newVal = $this->GetNewValue($nvHash);
            if ($newVal !== null and strlen($newVal)) {
                // hack to add back TIFF header when writing MakerNoteCanon to CMT3 in CR3 images
                if ($tagInfo->Name === 'MakerNoteCanon') {
                    self::RequireModule('Image::ExifTool::Canon');
                    if ($tagInfo === self::GetTagTable('Image::ExifTool::Canon::uuid')['CMT3']) {
                        if ($newVal[0] === "\0") {
                            $hdr = "MM\0\x2a" . pack('N', 8);
                        } else {
                            $hdr = "II\x2a\0" . pack('V', 8);
                        }
                        $newVal = $hdr . $newVal;
                    }
                }
            } else {
                if (!($dataPt !== null or ($dirInfo['RAF'] ?? null))) return ''; // nothing to do if block never existed
                // don't allow MakerNotes to be removed from RAW files
                if ($blockName === 'MakerNotes' and $this->IsRawType() and
                    // but allow MakerNotes to be deleted from ExifIFD of CR3 image (shouldn't be there)
                    !($this->IsRawType() == 2 and $parent === 'ExifIFD'))
                {
                    $this->Warn("Can't delete MakerNotes from {$this->FileType}", 1);
                    return null;
                }
                $verb = 'Deleting';
                $newVal = '';
            }
            $dirInfo['BlockWrite'] = 1; // set flag indicating we did a block write
            if ($out) fwrite($out, "  $verb $blockName as a block\n");
            ++$this->CHANGED;
            return $newVal;
        }
        // guard against writing the same directory twice
        if ($dataPt !== null and isset($dirInfo['DirStart']) and isset($dirInfo['DataPos']) and
            !($dirInfo['NoRefTest'] ?? null))
        {
            $addr = Perl::str($dirInfo['DirStart'] + $dirInfo['DataPos'] + (($dirInfo['Base'] ?? null) ?: 0) + $this->BASE);
            // (Phase One P25 IIQ files have ICC_Profile duplicated in IFD0 and IFD1)
            if (($this->PROCESSED[$addr] ?? null) and ($dirName !== 'ICC_Profile' or $this->TIFF_TYPE !== 'IIQ')) {
                if (isset($dirInfo['DirLen']) and !$dirInfo['DirLen'] and $dirName !== $this->PROCESSED[$addr]) {
                    // it is hypothetically possible to have 2 different directories
                    // with the same address if one has a length of zero
                } elseif ($this->Error("$dirName pointer references previous {$this->PROCESSED[$addr]} directory", 2)) {
                    return null;
                } else {
                    $this->Warn("Deleting duplicate $dirName directory");
                    if ($out) fwrite($out, "  Deleting $dirName\n");
                    // delete the duplicate directory (don't recreate it when writing new
                    // tags to prevent propagating a duplicate IFD in cases like when the
                    // same ExifIFD exists in both IFD0 and IFD1)
                    return '';
                }
            } else {
                $this->PROCESSED[$addr] = $dirName;
            }
        }
        $oldDir = $this->DIR_NAME;
        $save = [$this->Compression, $this->SubfileType];
        $name = null;
        if ($out) {
            $name = ($dirName === 'MakerNotes' and ($dirInfo['TagInfo'] ?? null)) ?
                     $dirInfo['TagInfo']->Name : $dirName;
            if ($oldDir === null or $oldDir !== $name) {
                $verb = $isRewriting ? 'Rewriting' : 'Creating';
                fwrite($out, "  $verb $name\n");
            }
        }
        $saveOrder = self::GetByteOrder();
        $oldChanged = $this->CHANGED;
        $this->DIR_NAME = $dirName;
        $this->PATH[] = $dirName;
        $dirInfo['IsWriting'] = 1;
        if (is_callable($writeProc)) {
            $newData = $writeProc($this, $dirInfo, $tagTablePtr);
        } else {
            // (PHP: the write proc is not ported yet: an error if this directory must be
            //  edited, otherwise no change, like the procs which only rewrite edited directories)
            if (array_key_exists($dirName, $this->EDIT_DIRS ?? [])) {
                $this->Error('Not ported: ' . (is_string($writeProc) ? $writeProc : get_debug_type($writeProc)));
            }
            $newData = null;
        }
        array_pop($this->PATH);
        // nothing changed if error occurred or nothing was created
        if (!($newData !== null and (strlen($newData) or $isRewriting))) $this->CHANGED = $oldChanged;
        $this->DIR_NAME = $oldDir;
        [$this->Compression, $this->SubfileType] = $save;
        self::SetByteOrder($saveOrder);
        if ($out) {
            if ($newData !== null and !strlen($newData)) fwrite($out, "  Deleting $name\n");
            if ($this->CHANGED == $oldChanged and $this->OPTIONS['Verbose'] > 2) {
                fwrite($out, "{$this->INDENT}  [nothing changed in $name]\n");
            }
        }
        return $newData;
    }

    //------------------------------------------------------------------------------
    // get current position in output file (or end of file if a scalar reference)
    // Inputs: 0) file or scalar reference
    // Returns: Current position or -1 on error
    public static function Tell($outfile)
    {
        if (is_resource($outfile)) {
            $pos = ftell($outfile);
            return $pos === false ? -1 : $pos;
        } else {
            return strlen(Perl::str($outfile->value));
        }
    }

    //------------------------------------------------------------------------------
    // write to file or memory
    // Inputs: 0) file or scalar reference, 1-N) list of stuff to write
    // Returns: true on success
    public static function Write($outfile, ...$data)
    {
        if (is_resource($outfile)) {
            $str = implode('', $data);
            return fwrite($outfile, $str) === strlen($str) ? 1 : 0;
        } elseif ($outfile instanceof ScalarRef) {
            $outfile->value .= implode('', $data);
            return 1;
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Write trailer buffer to file (applying fixups if necessary)
    // Inputs: 0) ExifTool object ref, 1) trailer dirInfo ref, 2) output file ref
    // Returns: 1 on success
    public function WriteTrailerBuffer(array &$trailInfo, $outfile)
    {
        if ($this->DEL_GROUP['Trailer'] ?? null) {
            $this->VPrint(0, "  Deleting trailer ({$trailInfo['Offset']} bytes)\n");
            ++$this->CHANGED;
            return 1;
        }
        $pos = self::Tell($outfile);
        $trailPt = $trailInfo['OutFile'];
        // apply fixup if necessary (AFCP requires this)
        if ($trailInfo['Fixup'] ?? null) {
            if ($pos > 0) {
                // shift offsets to final AFCP location and write it out
                $trailInfo['Fixup']->Shift += $pos;
                $trailInfo['Fixup']->ApplyFixup($trailPt->value);
            } else {
                $this->Error("Can't get file position for trailer offset fixup", 1);
            }
        }
        return self::Write($outfile, $trailPt->value);
    }

    //------------------------------------------------------------------------------
    // Add trailers as a block
    // Inputs: 0) ExifTool object ref, 1) [optional] trailer data raf,
    //         1 or 2-N) trailer types to add (or none to add all)
    // Returns: new trailer ref, or undef
    // - increments CHANGED if trailer was added
    // (PHP: the trailer data is a ScalarRef)
    public function AddNewTrailers(...$types)
    {
        $trailPt = null;
        if ($types and $types[0] instanceof ScalarRef) $trailPt = array_shift($types);
        if ($types and !$types[0]) array_shift($types); // (in case undef data ref is passed)
        // add all possible trailers if none specified (currently only CanonVRD)
        if (!$types) $types = ['CanonVRD', 'CanonDR4'];
        // add trailers as a block (if not done already)
        $extra = self::GetTagTable('Image::ExifTool::Extra');
        foreach ($types as $type) {
            $tagInfo = $extra[$type];
            if (!($tagInfo instanceof TagInfo and ($this->NEW_VALUE[spl_object_id($tagInfo)] ?? null))) continue;
            if ($this->{"Did$type"}) continue;
            $val = $this->GetNewValue($type);
            if (!$val) continue;
            // DR4 record must be wrapped in VRD trailer package
            if ($type === 'CanonDR4') {
                if ($this->DidCanonVRD) continue;   // (only allow one VRD trailer)
                self::RequireModule('Image::ExifTool::CanonVRD');
                if (!method_exists(CanonVRD::class, 'WrapDR4')) {
                    $this->Error('Not ported: Image\ExifTool\CanonVRD::WrapDR4');
                    continue;
                }
                $val = CanonVRD::WrapDR4($val);
                $this->DidCanonVRD = 1;
            }
            $verb = $trailPt ? 'Writing' : 'Adding';
            $this->VPrint(0, "  $verb $type as a block\n");
            if ($trailPt) {
                $trailPt->value .= $val;
            } else {
                $trailPt = new ScalarRef($val);
            }
            $this->{"Did$type"} = 1;
            ++$this->CHANGED;
        }
        return $trailPt;
    }

    //------------------------------------------------------------------------------
    // Write segment, splitting up into multiple segments if necessary
    // Inputs: 0) file or scalar reference, 1) segment marker
    //         2) segment header, 3) segment data ref, 4) segment type
    // Returns: number of segments written, or 0 on error
    // Notes: Writes a single empty segment if data is empty
    // (PHP: takes the segment data itself)
    public static function WriteMultiSegment($outfile, $marker, $header, $dataPt, $type = null)
    {
        if (!$type) $type = '';
        $dataPt = Perl::str($dataPt);
        $len = strlen($dataPt);
        $hdr = "\xff" . chr($marker);
        $count = 0;
        $maxLen = self::MAX_SEGMENT_LEN - strlen($header);
        if ($type === 'ICC') $maxLen -= 2; // leave room for segment counters
        $num = Perl::int(($len + $maxLen - 1) / $maxLen);   // number of segments to write
        $n = 0;
        // write data, splitting into multiple segments if necessary
        // (each segment gets its own header)
        for (;;) {
            ++$count;
            $size = $len - $n;
            if ($size > $maxLen) {
                $size = $maxLen;
                // avoid starting an Extended EXIF segment with a valid TIFF header
                // (because we would interpret that as a separate EXIF segment)
                if ($type === 'EXIF' and $n + $maxLen <= $len - 4 and
                    preg_match('/^(MM\0\x2a|II\x2a\0)/', substr($dataPt, $n + $maxLen, 4))) --$size;
            }
            $buff = substr($dataPt, $n, $size);
            $n += $size;
            $size += strlen($header);
            if ($type === 'ICC') {
                $buff = pack('CC', $count, $num) . $buff;
                $size += 2;
            }
            // write the new segment with appropriate header
            $segHdr = $hdr . pack('n', $size + 2);
            if (!self::Write($outfile, $segHdr, $header, $buff)) return 0;
            if ($n >= $len) break;
        }
        return $count;
    }

    //------------------------------------------------------------------------------
    // Write XMP segment(s) to JPEG file
    // Inputs: 0) ExifTool object ref, 1) outfile ref, 2) XMP data ref,
    //         3) extended XMP data ref, 4) 32-char extended XMP GUID (or undef if no extended data)
    // Returns: true on success, false on write error
    // (PHP: takes the XMP and extended XMP data themselves)
    public function WriteMultiXMP($outfile, $dataPt, $extPt, $guid)
    {
        $success = 1;

        // write main XMP segment
        $size = strlen($dataPt) + strlen(Vars::$xmpAPP1hdr);
        if ($size > self::MAX_XMP_LEN) {
            $this->Error("XMP block too large for JPEG segment! ($size bytes)", 1);
            return 1;
        }
        $app1hdr = "\xff\xe1" . pack('n', $size + 2);
        if (!self::Write($outfile, $app1hdr, Vars::$xmpAPP1hdr, $dataPt)) $success = 0;
        // write extended XMP segment(s) if necessary
        if ($guid !== null) {
            $size = strlen($extPt);
            $maxLen = self::MAX_XMP_LEN - 75; // maximum size without 75-byte header
            for ($off = 0; $off < $size; $off += $maxLen) {
                // header(75) = signature(35) + guid(32) + size(4) + offset(4)
                $len = $size - $off;
                if ($len > $maxLen) $len = $maxLen;
                $app1hdr = "\xff\xe1" . pack('n', $len + 75 + 2);
                $this->VPrint(0, "Writing extended XMP segment ($len bytes)\n");
                if (!self::Write($outfile, $app1hdr, Vars::$xmpExtAPP1hdr, $guid, pack('N2', $size, $off),
                      substr($extPt, $off, $len))) $success = 0;
            }
        }
        return $success;
    }

    //------------------------------------------------------------------------------
    // WriteJPEG : Write JPEG image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid JPEG file, or -1 if
    //          an output file was specified and a write error occurred
    public function WriteJPEG(array &$dirInfo)
    {
        $outfile = $dirInfo['OutFile'];
        $raf = $dirInfo['RAF'];
        $ch = $s = $length = $err = $isEXV = $creatingEXV = null;
        $doneDir = [];
        $verbose = $this->OPTIONS['Verbose'];
        $out = $this->OPTIONS['TextOut'];
        $rtnVal = 0;
        $writeBuffer = $oldOutfile = null; // used to buffer writing until PreviewImage position is known

        // check to be sure this is a valid JPG or EXV file
        if (!($raf->Read($s, 2) == 2 and $s === "\xff\xd8")) {
            if ($s !== null and strlen($s)) {
                if (!($s === "\xff\x01" and $raf->Read($s, 5) == 5 and $s === 'Exiv2')) return 0;
            } else {
                if ($this->FILE_TYPE !== 'EXV') return 0;
                $s = 'Exiv2';
                $creatingEXV = 1;
            }
            if (!self::Write($outfile, "\xff\x01")) $err = 1;
            $isEXV = 1;
        }

        $this->PREVIEW_INFO = null;     // reset preview information
        $this->DEL_PREVIEW = null;      // reset flag to delete preview

        if (!self::Write($outfile, $s)) $err = 1;
        // figure out what segments we need to write for the tags we have set
        $addDirs = &$this->ADD_DIRS;
        $editDirs = &$this->EDIT_DIRS;
        $delGroup = &$this->DEL_GROUP;
        $addDirs ??= [];
        $editDirs ??= [];
        $delGroup ??= [];
        $path = &$this->PATH;
        $pn = count($path);
        $extra = self::GetTagTable('Image::ExifTool::Extra');

        // set input record separator to 0xff (the JPEG marker) to make reading quicker
        $oldRS = Perl::$RS;
        Perl::$RS = "\xff";
        try {
        //
        // pre-scan image to determine if any create-able segment already exists
        //
        $pos = $raf->Tell();
        $marker = null;
        $dirOrder = $dirCount = [];
        for (;;) {  // Prescan:
            // read up to next marker (JPEG markers begin with 0xff)
            if (!$raf->ReadLine($s)) break;
            // JPEG markers can be padded with unlimited 0xff's
            for (;;) {
                if (!$raf->Read($ch, 1)) break 2;   // (last Prescan)
                $marker = ord($ch);
                if ($marker != 0xff) break;
            }
            $dirName = null;
            // stop pre-scan at SOS (end of meta information) or EOI (end of image)
            if ($marker == 0xda or $marker == 0xd9) {
                $dirName = Vars::$jpegMarker[$marker];
                $dirOrder[] = $dirName;
                $dirCount[$dirName] = 1;
                break;
            }
            // handle SOF markers: SOF0-SOF15, except DHT(0xc4), JPGA(0xc8) and DAC(0xcc)
            if (($marker & 0xf0) == 0xc0 and ($marker == 0xc0 or $marker & 0x03)) {
                if (!$raf->Seek(7, 1)) break;
            // read data for all markers except stand-alone
            // markers 0x00, 0x01 and 0xd0-0xd7 (NULL, TEM, RST0-RST7)
            } elseif ($marker != 0x00 and $marker != 0x01 and ($marker < 0xd0 or $marker > 0xd7)) {
                // read record length word
                if ($raf->Read($s, 2) != 2) break;
                $len = Perl::unpack('n', $s)[0] ?? null;   // get data length
                if (!($len !== null and $len >= 2)) break;
                $len -= 2;  // subtract size of length word
                if (($marker & 0xf0) == 0xe0) {  // is this an APP segment?
                    $n = $len < 64 ? $len : 64;
                    if ($raf->Read($s, $n) != $n) break;
                    $len -= $n;
                    // Note: only necessary to recognize APP segments that we can create,
                    // or delete as a group (and the names below should match @delGroups)
                    if ($marker == 0xe0) {
                        if (preg_match('/^JFIF\0/', $s)) $dirName = 'JFIF';
                        if (preg_match('/^JFXX\0\x10/', $s)) $dirName = 'JFXX';
                        if (preg_match('/^(II|MM).{4}HEAPJPGM/s', $s)) $dirName = 'CIFF';
                    } elseif ($marker == 0xe1) {
                        if (preg_match('/^(.{0,4})Exif\0.(.{1,4})/is', $s, $m)) {
                            $dirName = 'IFD0';
                            [$junk, $bytes] = [$m[1], $m[2]];
                            // support multi-segment EXIF
                            if ($dirOrder and preg_match('/^(IFD0|ExtendedEXIF)$/', $dirOrder[count($dirOrder) - 1]) and
                                !strlen($junk) and !preg_match('/^(MM\0\x2a|II\x2a\0)/', $bytes))
                            {
                                $dirName = 'ExtendedEXIF';
                            }
                        }
                        if (preg_match(self::Rx('^' . Vars::$xmpAPP1hdr), $s)) $dirName = 'XMP';
                        if (preg_match(self::Rx('^' . Vars::$xmpExtAPP1hdr), $s)) $dirName = 'XMP';
                    } elseif ($marker == 0xe2) {
                        if (preg_match('/^ICC_PROFILE\0/', $s)) $dirName = 'ICC_Profile';
                        if (preg_match('/^FPXR\0/', $s)) $dirName = 'FlashPix';
                        if (preg_match('/^MPF\0/', $s)) $dirName = 'MPF';
                    } elseif ($marker == 0xe3) {
                        if (preg_match('/^(Meta|META|Exif)\0\0/', $s)) $dirName = 'Meta';
                    } elseif ($marker == 0xe5) {
                        if (preg_match('/^RMETA\0/', $s)) $dirName = 'RMETA';
                    } elseif ($marker == 0xea) {
                        if (preg_match('/^AROT\0\0/', $s)) $dirName = 'AROT';
                    } elseif ($marker == 0xeb) {
                        if (preg_match('/^JP/', $s)) $dirName = 'JUMBF';
                    } elseif ($marker == 0xec) {
                        if (preg_match('/^Ducky/', $s)) $dirName = 'Ducky';
                    } elseif ($marker == 0xed) {
                        if (preg_match(self::Rx('^' . Vars::$psAPP13hdr), $s)) $dirName = 'Photoshop';
                    } elseif ($marker == 0xee) {
                        if (preg_match('/^Adobe/', $s)) $dirName = 'Adobe';
                    }
                    // initialize doneDir as a flag that the directory exists
                    // (unless we are deleting it anyway)
                    if ($dirName !== null and !($delGroup[$dirName] ?? null)) $doneDir[$dirName] = 0;
                }
                if (!$raf->Seek($len, 1)) break;
            }
            if (!$dirName) $dirName = self::JpegMarkerName($marker);
            $dirCount[$dirName] = ($dirCount[$dirName] ?? 0) + 1;
            $dirOrder[] = $dirName;
        }
        if (!($marker and $marker == 0xda)) {
            if (!$isEXV) {
                $this->Error('Corrupted JPEG image');
                return 1;
            }
            if ($marker and $marker != 0xd9) {
                $this->Error('Corrupted EXV file');
                return 1;
            }
        }
        if (!$raf->Seek($pos, 0)) {
            $this->Error('Seek error');
            return 1;
        }
        //
        // re-write the image
        //
        $combinedSegData = $segPos = $firstSegPos = null;
        $extendedXMP = [];
        $iccChunk = [];
        $iccChunkCount = $iccChunksTotal = null;
        // read through each segment in the JPEG file
        for (;;) {  // Marker:

            // read up to next marker (JPEG markers begin with 0xff)
            $segJunk = null;
            if (!$raf->ReadLine($segJunk)) $segJunk = '';
            // remove the 0xff but write the rest of the junk up to this point
            // (this will handle the data after the first 7 bytes of SOF segments)
            Perl::chomp($segJunk);
            if (strlen($segJunk)) self::Write($outfile, $segJunk);
            // JPEG markers can be padded with unlimited 0xff's
            for (;;) {
                if ($raf->Read($ch, 1)) {
                    $marker = ord($ch);
                    if ($marker != 0xff) break;
                } elseif ($creatingEXV) {
                    // create EXV from scratch
                    $marker = 0xd9; // EOI
                    $dirOrder[] = 'EOI';
                    $dirCount['EOI'] = 1;
                    break;
                } else {
                    $this->Error('Format error');
                    return 1;
                }
            }
            // read the segment data
            $segData = null;
            // handle SOF markers: SOF0-SOF15, except DHT(0xc4), JPGA(0xc8) and DAC(0xcc)
            if (($marker & 0xf0) == 0xc0 and ($marker == 0xc0 or $marker & 0x03)) {
                if ($raf->Read($segData, 7) != 7) break;
            // read data for all markers except stand-alone
            // markers 0x00, 0x01 and 0xd0-0xd7 (NULL, TEM, EOI, RST0-RST7)
            } elseif ($marker != 0x00 and $marker != 0x01 and $marker != 0xd9 and
                ($marker < 0xd0 or $marker > 0xd7))
            {
                // read record length word
                if ($raf->Read($s, 2) != 2) break;
                $len = Perl::unpack('n', $s)[0] ?? null;   // get data length
                if (!($len !== null and $len >= 2)) break;
                $segPos = $raf->Tell();
                $len -= 2;  // subtract size of length word
                if ($raf->Read($segData, $len) != $len) break;
            }
            // initialize variables for this segment
            $hdr = "\xff" . chr($marker);   // segment header
            $markerName = self::JpegMarkerName($marker);
            $dirName = array_shift($dirOrder);  // get directory name
            //
            // create all segments that must come before this one
            // (nothing comes before SOI or after SOS)
            //
            while ($markerName !== 'SOI') {
                if (array_key_exists('JFIF', $addDirs) and !isset($doneDir['JFIF'])) {
                    $doneDir['JFIF'] = 1;
                    if (isset($doneDir['Adobe'])) {
                        // JFIF overrides Adobe APP14 colour components, so don't allow this
                        // (ref https://docs.oracle.com/javase/8/docs/api/javax/imageio/metadata/doc-files/jpeg_metadata.html)
                        $this->Warn('Not creating JFIF in JPEG with Adobe APP14');
                    } else {
                        if ($verbose) {
                            fwrite($out, "Creating APP0:\n");
                            fwrite($out, "  Creating JFIF with default values\n");
                        }
                        $jfif = "\x01\x02\x01\0\x48\0\x48\0\0";
                        self::SetByteOrder('MM');
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::JFIF::Main');
                        $subdirInfo = [
                            'DataPt'   => $jfif,
                            'DirStart' => 0,
                            'DirLen'   => strlen($jfif),
                            'Parent'   => 'JFIF',
                        ];
                        // must temporarily remove JFIF from DEL_GROUP so we can
                        // delete JFIF and add it back again in a single step
                        $delJFIF = $delGroup['JFIF'] ?? null;
                        unset($delGroup['JFIF']);
                        $path[$pn] = 'JFIF';
                        $newData = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                        if ($delJFIF !== null) $delGroup['JFIF'] = $delJFIF;
                        if ($newData !== null and strlen($newData)) {
                            $app0hdr = "\xff\xe0" . pack('n', strlen($newData) + 7);
                            if (!self::Write($outfile, $app0hdr, "JFIF\0", $newData)) $err = 1;
                        }
                    }
                }
                // don't create anything before APP0 or APP1 EXIF (containing IFD0)
                if ($markerName === 'APP0' or ($dirCount['IFD0'] ?? null) or ($dirCount['ExtendedEXIF'] ?? null)) break;
                // EXIF information must come immediately after APP0
                if (array_key_exists('IFD0', $addDirs) and !isset($doneDir['IFD0'])) {
                    $doneDir['IFD0'] = 1;
                    if ($verbose) fwrite($out, "Creating APP1:\n");
                    // write new EXIF data
                    $this->TIFF_TYPE = 'APP1';
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Exif::Main');
                    $subdirInfo = [
                        'DirName' => 'IFD0',
                        'Parent'  => 'APP1',
                    ];
                    $path[$pn] = 'APP1';
                    $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr, self::class . '::WriteTIFF');
                    if ($buff !== null and strlen($buff)) {
                        if (strlen($buff) + strlen(Vars::$exifAPP1hdr) > self::MAX_SEGMENT_LEN) {
                            if ($this->Options('NoMultiExif')) {
                                $this->Error('EXIF is too large for JPEG segment');
                            } else {
                                $this->Warn('Creating multi-segment EXIF', 1);
                            }
                        }
                        // switch to buffered output if required
                        if (($this->PREVIEW_INFO or $this->LeicaTrailer or $this->HiddenData) and !$oldOutfile) {
                            $writeBuffer = new ScalarRef('');
                            $oldOutfile = $outfile;
                            $outfile = $writeBuffer;
                            // must account for segment, EXIF and TIFF headers
                            foreach (['PREVIEW_INFO', 'LeicaTrailer', 'HiddenData'] as $k) {
                                if ($this->{$k}) $this->{$k}['Fixup']->Start += 18;
                            }
                        }
                        // write as multi-segment
                        $n = self::WriteMultiSegment($outfile, 0xe1, Vars::$exifAPP1hdr, $buff, 'EXIF');
                        if (!$n) {
                            $err = 1;
                        } elseif ($n > 1 and $oldOutfile) {
                            // (punt on this because updating the pointers would be a real pain)
                            $this->Error("Can't write multi-segment EXIF with external pointers");
                        }
                        ++$this->CHANGED;
                    }
                }
                // APP13 Photoshop segment next
                if ($dirCount['Photoshop'] ?? null) break;
                if (array_key_exists('Photoshop', $addDirs) and !isset($doneDir['Photoshop'])) {
                    $doneDir['Photoshop'] = 1;
                    if ($verbose) fwrite($out, "Creating APP13:\n");
                    // write new APP13 Photoshop record to memory
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::Photoshop::Main');
                    $subdirInfo = [
                        'Parent' => 'APP13',
                    ];
                    $path[$pn] = 'APP13';
                    $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                    if ($buff !== null and strlen($buff)) {
                        if (!self::WriteMultiSegment($outfile, 0xed, Vars::$psAPP13hdr, $buff)) $err = 1;
                        ++$this->CHANGED;
                    }
                }
                // then APP1 XMP segment
                if ($dirCount['XMP'] ?? null) break;
                if (array_key_exists('XMP', $addDirs) and !isset($doneDir['XMP'])) {
                    $doneDir['XMP'] = 1;
                    if ($verbose) fwrite($out, "Creating APP1:\n");
                    // write new XMP data
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::XMP::Main');
                    $subdirInfo = [
                        'Parent'      => 'APP1',
                        // specify MaxDataLen so XMP is split if required
                        'MaxDataLen'  => self::MAX_XMP_LEN - strlen(Vars::$xmpAPP1hdr),
                    ];
                    $path[$pn] = 'APP1';
                    $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                    if ($buff !== null and strlen($buff)) {
                        if (!$this->WriteMultiXMP($outfile, $buff, $subdirInfo['ExtendedXMP'] ?? null,
                                      $subdirInfo['ExtendedGUID'] ?? null)) $err = 1;
                    }
                }
                // then APP2 ICC_Profile segment
                if ($dirCount['ICC_Profile'] ?? null) break;
                if (array_key_exists('ICC_Profile', $addDirs) and !isset($doneDir['ICC_Profile'])) {
                    $doneDir['ICC_Profile'] = 1;
                    if (($delGroup['ICC_Profile'] ?? null) and $delGroup['ICC_Profile'] != 2) continue;
                    if ($verbose) fwrite($out, "Creating APP2:\n");
                    // write new ICC_Profile data
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::ICC_Profile::Main');
                    $subdirInfo = [
                        'Parent'   => 'APP2',
                    ];
                    $path[$pn] = 'APP2';
                    $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                    if ($buff !== null and strlen($buff)) {
                        if (!self::WriteMultiSegment($outfile, 0xe2, "ICC_PROFILE\0", $buff, 'ICC')) $err = 1;
                        ++$this->CHANGED;
                    }
                }
                // then APP12 Ducky segment
                if ($dirCount['Ducky'] ?? null) break;
                if (array_key_exists('Ducky', $addDirs) and !isset($doneDir['Ducky'])) {
                    $doneDir['Ducky'] = 1;
                    if ($verbose) fwrite($out, "Creating APP12 Ducky:\n");
                    // write new Ducky segment data
                    $tagTablePtr = self::GetTagTable('Image::ExifTool::APP12::Ducky');
                    $subdirInfo = [
                        'Parent'   => 'APP12',
                    ];
                    $path[$pn] = 'APP12';
                    $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                    if ($buff !== null and strlen($buff)) {
                        $size = strlen($buff) + 5;
                        if ($size <= self::MAX_SEGMENT_LEN) {
                            // write the new segment with appropriate header
                            $app12hdr = "\xff\xec" . pack('n', $size + 2);
                            if (!self::Write($outfile, $app12hdr, 'Ducky', $buff)) $err = 1;
                        } else {
                            $this->Warn("APP12 Ducky segment too large! ($size bytes)");
                        }
                    }
                }
                // then APP14 Adobe segment
                if ($dirCount['Adobe'] ?? null) break;
                if (array_key_exists('Adobe', $addDirs) and !isset($doneDir['Adobe'])) {
                    $doneDir['Adobe'] = 1;
                    $buff = $this->GetNewValue('Adobe');
                    if ($buff) {
                        if ($verbose) fwrite($out, "Creating APP14:\n  Creating Adobe segment\n");
                        $size = strlen($buff);
                        if ($size <= self::MAX_SEGMENT_LEN) {
                            // write the new segment with appropriate header
                            $app14hdr = "\xff\xee" . pack('n', $size + 2);
                            if (!self::Write($outfile, $app14hdr, $buff)) $err = 1;
                            ++$this->CHANGED;
                        } else {
                            $this->Warn("APP14 Adobe segment too large! ($size bytes)");
                        }
                    }
                }
                // finally, COM segment
                if ($dirCount['COM'] ?? null) break;
                if (array_key_exists('COM', $addDirs) and !isset($doneDir['COM'])) {
                    $doneDir['COM'] = 1;
                    if (($delGroup['File'] ?? null) and $delGroup['File'] != 2) continue;
                    $newComment = $this->GetNewValue('Comment');
                    if ($newComment !== null) {
                        if ($verbose) {
                            fwrite($out, "Creating COM:\n");
                            $this->VerboseValue('+ Comment', $newComment);
                        }
                        if (!self::WriteMultiSegment($outfile, 0xfe, '', $newComment)) $err = 1;
                        ++$this->CHANGED;
                    }
                }
                break;  // didn't want to loop anyway
            }
            $path[$pn] = $markerName;
            // decrement counter for this directory since we are about to process it
            $dirCount[(string) $dirName] = ($dirCount[(string) $dirName] ?? 0) - 1;
            //
            // rewrite existing segments
            //
            // handle SOF markers: SOF0-SOF15, except DHT(0xc4), JPGA(0xc8) and DAC(0xcc)
            if (($marker & 0xf0) == 0xc0 and ($marker == 0xc0 or $marker & 0x03)) {
                if ($verbose) fwrite($out, "JPEG $markerName:\n");
                if (!self::Write($outfile, $hdr, $segData)) $err = 1;
                continue;
            } elseif ($marker == 0xda) {            // SOS
                array_pop($path);
                if ($verbose) fwrite($out, "JPEG SOS\n");
                // write SOS segment
                $s = pack('n', strlen($segData) + 2);
                if (!self::Write($outfile, $hdr, $s, $segData)) $err = 1;
                $buff = $endPos = $trailInfo = null;
                $delPreview = $this->DEL_PREVIEW;
                if (!($delGroup['Trailer'] ?? null)) $trailInfo = $this->IdentifyTrailer($raf);
                $nvTrail = $this->GetNewValueHash($extra['Trailer']);
                if (!($oldOutfile or $delPreview or $trailInfo or ($delGroup['Trailer'] ?? null) or
                    $nvTrail or $this->HiddenData))
                {
                    // blindly copy the rest of the file
                    while ($raf->Read($buff, 65536)) {
                        if (!self::Write($outfile, $buff)) {
                            $err = 1;
                            break;
                        }
                    }
                    $rtnVal = 1;    // success unless we have a file write error
                    break;          // all done
                }
                // write the rest of the image (as quickly as possible) up to the EOI
                $endedWithFF = null;
                for (;;) {
                    $n = $raf->Read($buff, 65536);
                    if (!$n) break 2;   // (last Marker)
                    if (($endedWithFF and preg_match('/^\xd9/', $buff, $mm, PREG_OFFSET_CAPTURE)) or
                        preg_match('/\xff\xd9/', $buff, $mm, PREG_OFFSET_CAPTURE))
                    {
                        $rtnVal = 1;    // the JPEG is OK
                        // write up to the EOI
                        $pos = $mm[0][1] + strlen($mm[0][0]);   // (pos($buff))
                        if (!self::Write($outfile, substr($buff, 0, $pos))) $err = 1;
                        $buff = substr($buff, $pos);
                        break;
                    }
                    if ($n != 65536) {
                        $this->Error('JPEG EOI marker not found');
                        break 2;    // (last Marker)
                    }
                    if (!self::Write($outfile, $buff)) $err = 1;
                    $endedWithFF = substr($buff, 65535, 1) === "\xff" ? 1 : 0;
                }
                // remember position of last data copied
                $endPos = $this->TrailerStart = $raf->Tell() - strlen($buff);
                // write new trailer if specified
                if ($nvTrail) {
                    // access new value directly to avoid copying a potentially very large data block
                    if ($nvTrail->Value and $nvTrail->Value[0]) { // (note: "0" will also delete the trailer)
                        $this->VPrint(0, '  Writing new trailer');
                        if (!self::Write($outfile, $nvTrail->Value[0])) $err = 1;
                        ++$this->CHANGED;
                    } elseif ($raf->Seek(0, 2) and $raf->Tell() != $endPos) {
                        $this->VPrint(0, '  Deleting trailer (', $raf->Tell() - $endPos, ' bytes)');
                        ++$this->CHANGED;   // changed if there was previously a trailer
                    }
                    break;  // all done
                }
                // rewrite existing trailers into buffer
                if ($trailInfo) {
                    $raf->Seek(-strlen($buff), 1);      // seek back to just after EOI
                    $trailInfo['OutFile'] = new ScalarRef('');  // rewrite the trailer
                    $trailInfo['ScanForTrailer'] = 1;   // scan if necessary
                    if (!$this->ProcessTrailers($trailInfo)) $trailInfo = null;
                }
                if ($oldOutfile) {
                    $previewInfo = null;
                    // copy HiddenData if necessary
                    if ($this->HiddenData) {
                        $pad = null;
                        $hd = $this->HiddenData;
                        $hdOff = $hd['Offset'] + ($hd['Base'] ?? 0);
                        self::RequireModule('Image::ExifTool::Sony');
                        // read HiddenData, updating $hdOff with actual offset if necessary
                        $dataPt = Sony::ReadHiddenData($this, $hdOff, $hd['Size']);
                        if ($dataPt) {
                            // preserve padding to avoid invalidating MPF pointers (yuk!)
                            $padLen = $hdOff - $endPos;
                            if (!($padLen >= 0 and $raf->Seek($endPos, 0) and $raf->Read($pad, $padLen) == $padLen)) {
                                $this->Error('Error reading HiddenData padding', 1);
                                $pad = '';
                            }
                            $endPos += strlen($pad) + strlen($dataPt->value);   // update end position
                        } else {
                            $dataPt = new ScalarRef('');
                            $pad = '';
                        }
                        $fixup = $this->HiddenData['Fixup'];
                        // set MakerNote pointer and size (subtract 10 for segment and EXIF headers)
                        $fixup->SetMarkerPointers($outfile->value, 'HiddenData', strlen($outfile->value) + strlen($pad) - 10);
                        $writeBuffer->value .= $pad . $dataPt->value;   // keep padding for now
                    }
                    if ($this->LeicaTrailer) {
                        $trailLen = null;
                        if ($trailInfo) {
                            $trailLen = $trailInfo['DataPos'] - $endPos;
                        } else {
                            if (!$raf->Seek(0, 2)) $err = 1;
                            $trailLen = $raf->Tell() - $endPos;
                        }
                        $fixup = $this->LeicaTrailer['Fixup'];
                        $this->LeicaTrailer['TrailPos'] = $endPos;
                        $this->LeicaTrailer['TrailLen'] = $trailLen;
                        // get _absolute_ position of new Leica trailer
                        $absPos = self::Tell($oldOutfile) + strlen($outfile->value);
                        self::RequireModule('Image::ExifTool::Panasonic');
                        $dat = Panasonic::ProcessLeicaTrailer($this, $absPos);
                        // allow some junk before Leica trailer (just in case)
                        $junk = $this->LeicaTrailerPos - $endPos;
                        // set MakerNote pointer and size (subtract 10 for segment and EXIF headers)
                        $fixup->SetMarkerPointers($outfile->value, 'LeicaTrailer', strlen($outfile->value) - 10 + $junk);
                        // use this fixup to set the size too (sneaky)
                        $trailSize = $dat !== null ? strlen($dat) - $junk : ($this->LeicaTrailer['Size'] ?? null);
                        $fixup->Start -= 4;  $fixup->Shift += 4;
                        if ($trailSize !== null) $fixup->SetMarkerPointers($outfile->value, 'LeicaTrailer', $trailSize);
                        $fixup->Start += 4;  $fixup->Shift -= 4;
                        if ($dat !== null) {
                            if (!self::Write($outfile, $dat)) $err = 1; // write new Leica trailer
                            $delPreview = 1;                            // delete existing Leica trailer
                        }
                    }
                    // handle preview image last
                    if ($this->PREVIEW_INFO) {
                        // locate preview image and fix up preview offsets
                        $scanLen = preg_match('/^SONY/i', (string) $this->Make) ? 65536 : 1024;
                        if (strlen($buff) < $scanLen) { // make sure we have enough trailer to scan
                            $buf2 = null;
                            if ($raf->Read($buf2, $scanLen - strlen($buff))) $buff .= $buf2;
                        }
                        // get new preview image position, relative to EXIF base
                        $newPos = strlen($outfile->value) - 10; // (subtract 10 for segment and EXIF headers)
                        $junkLen = null;
                        // adjust position if image isn't at the start (eg. Olympus E-1/E-300)
                        if (preg_match('/(\xff\xd8\xff.|.\xd8\xff\xdb)(..)/s', $buff, $mm, PREG_OFFSET_CAPTURE)) {
                            [$jpegHdr, $segLen] = [$mm[1][0], $mm[2][0]];
                            $junkLen = $mm[0][1] + strlen($mm[0][0]) - 6;   // (pos($buff) - 6)
                            // Sony previewimage trailer has a 32 byte header
                            if (preg_match('/^SONY/i', (string) $this->Make) and $junkLen > 32) {
                                // with some newer Sony models, the makernotes preview pointer
                                // points to JPEG at end of EXIF inside MPImage preview (what a pain!)
                                if ($jpegHdr === "\xff\xd8\xff\xe1") {     // is the first segment EXIF?
                                    $segLen = Perl::unpack('n', $segLen)[0];  // the EXIF segment length
                                    // Sony PreviewImage starts with last 2 bytes of EXIF segment
                                    // (and first byte is usually "\0", not "\xff", so don't check this)
                                    if (strlen($buff) > $junkLen + $segLen + 6 and
                                        substr($buff, $junkLen + $segLen + 3, 3) === "\xd8\xff\xdb")
                                    {
                                        $junkLen += $segLen + 2;
                                        // (note: this will not copy the trailer after PreviewImage,
                                        //  which is a 14kB block full of zeros for the A77)
                                    }
                                }
                                $junkLen -= 32;
                            }
                            $newPos += $junkLen;
                        }
                        // fix up the preview offsets to point to the start of the new image
                        $previewInfo = $this->PREVIEW_INFO;
                        $this->PREVIEW_INFO = null;
                        $fixup = $previewInfo['Fixup'];
                        $newPos += ($previewInfo['BaseShift'] ?? null) ?: 0;
                        // adjust to absolute file offset if necessary (Samsung STMN)
                        if ($previewInfo['Absolute'] ?? null) $newPos += self::Tell($oldOutfile) + 10;
                        if ($previewInfo['Relative'] ?? null) {
                            // adjust for our base by looking at how far the pointer got shifted
                            $newPos -= ($fixup->GetMarkerPointers($outfile->value, 'PreviewImage') ?: 0);
                        } elseif ($previewInfo['ChangeBase'] ?? null) {
                            // Leica S2 uses relative offsets for the preview only (leica sucks)
                            $makerOffset = $fixup->GetMarkerPointers($outfile->value, 'LeicaTrailer');
                            if ($makerOffset) $newPos -= $makerOffset;
                        }
                        $fixup->SetMarkerPointers($outfile->value, 'PreviewImage', $newPos);
                        if (Perl::str($previewInfo['Data'] ?? null) !== 'LOAD_PREVIEW') {
                            // write any junk that existed before the preview image
                            if ($junkLen) $previewInfo['Junk'] = substr($buff, 0, $junkLen);
                        }
                    }
                    // clean up and write the buffered data
                    $outfile = $oldOutfile;
                    $oldOutfile = null;
                    if (!self::Write($outfile, $writeBuffer->value)) $err = 1;
                    $writeBuffer = null;
                    // write preview image
                    if ($previewInfo and Perl::str($previewInfo['Data'] ?? null) !== 'LOAD_PREVIEW') {
                        // write any junk that existed before the preview image
                        if (isset($previewInfo['Junk']) and !self::Write($outfile, $previewInfo['Junk'])) $err = 1;
                        // write the saved preview image
                        if (!self::Write($outfile, $previewInfo['Data'])) $err = 1;
                        unset($previewInfo['Data']);
                        // (don't increment CHANGED because we could be rewriting existing preview)
                        $delPreview = 1;    // remove old preview
                    }
                }
                // copy over preview image (or other data) if necessary
                if (!$delPreview) {
                    $extraLen = null;
                    if ($trailInfo) {
                        // copy everything up to start of first processed trailer
                        $extraLen = isset($trailInfo['DataPos']) ? ($trailInfo['DataPos'] - $endPos) : 0;
                    } else {
                        // copy everything up to end of file
                        if (!$raf->Seek(0, 2)) $err = 1;
                        $extraLen = $raf->Tell() - $endPos;
                    }
                    if ($extraLen > 0) {
                        if ($delGroup['Trailer'] ?? null) {
                            if ($verbose) fwrite($out, "  Deleting unknown trailer ($extraLen bytes)\n");
                            ++$this->CHANGED;
                        } else {
                            // copy over unknown trailer
                            if ($verbose) fwrite($out, "  Preserving unknown trailer ($extraLen bytes)\n");
                            if (!$raf->Seek($endPos, 0)) $err = 1;
                            if (!self::CopyBlock($raf, $outfile, $extraLen)) $err = 1;
                        }
                    }
                }
                // write trailer if necessary
                if ($trailInfo) {
                    if (!$this->WriteTrailerBuffer($trailInfo, $outfile)) $err = 1;
                    $trailInfo = null;
                }
                break;  // all done parsing file

            } elseif ($marker == 0xd9 and $isEXV) {
                // write EXV EOI (any trailer will be lost)
                if (!self::Write($outfile, "\xff\xd9")) $err = 1;
                $rtnVal = 1;
                break;

            } elseif ($marker == 0x00 or $marker == 0x01 or ($marker >= 0xd0 and $marker <= 0xd7)) {
                if ($verbose and $marker) fwrite($out, "JPEG $markerName:\n");
                // handle stand-alone markers 0x00, 0x01 and 0xd0-0xd7 (NULL, TEM, RST0-RST7)
                if (!self::Write($outfile, $hdr)) $err = 1;
                continue;
            }
            //
            // NOTE: A 'next' statement after this point will cause $$segDataPt
            //       not to be written if there is an output file, so in this case
            //       the $$self{CHANGED} flags must be updated
            //
            // (PHP: $segDataPt holds the segment data itself, null for "undef $segDataPt")
            $segDataPt = $segData;
            $length = strlen((string) $segData);
            if ($verbose) fwrite($out, "JPEG $markerName ($length bytes)\n");
            // group delete of APP segments
            if ($delGroup[(string) $dirName] ?? null) {
                if ($verbose) fwrite($out, "  Deleting $dirName segment\n");
                if ($dirName === 'ICC_Profile') $this->Warn('ICC_Profile deleted. Image colors may be affected');
                ++$this->CHANGED;
                continue;   // (next Marker)
            }
            $segType = $del = null;
            // rewrite this segment only if we are changing a tag which is contained in its
            // directory (or deleting '*', in which case we need to identify the segment type)
            while (array_key_exists($markerName, $editDirs) or ($delGroup['*'] ?? null)) {
                if ($marker == 0xe0) {              // APP0 (JFIF, CIFF)
                    if (preg_match('/^JFIF\0/', $segDataPt)) {
                        $segType = 'JFIF';
                        if ($delGroup['JFIF'] ?? null) {
                            $del = 1;
                            break;
                        }
                        if (!($editDirs['JFIF'] ?? null)) break;
                        self::SetByteOrder('MM');
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::JFIF::Main');
                        $subdirInfo = [
                            'DataPt'   => $segDataPt,
                            'DataPos'  => $segPos,
                            'DataLen'  => $length,
                            'DirStart' => 5,     // directory starts after identifier
                            'DirLen'   => $length - 5,
                            'Parent'   => $markerName,
                        ];
                        $newData = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                        if ($newData !== null and strlen($newData)) {
                            $segDataPt = "JFIF\0" . $newData;
                        }
                    } elseif (preg_match('/^JFXX\0\x10/', $segDataPt)) {
                        $segType = 'JFXX';
                        if ($delGroup['JFIF'] ?? null) $del = 1;
                    } elseif (preg_match('/^(II|MM).{4}HEAPJPGM/s', $segDataPt)) {
                        $segType = 'CIFF';
                        if ($delGroup['CIFF'] ?? null) {
                            $del = 1;
                            break;
                        }
                        if (!($editDirs['CIFF'] ?? null)) break;
                        $newData = new ScalarRef('');
                        $subdirInfo = [
                            'RAF' => new RandomAccess($segDataPt),
                            'OutFile' => $newData,
                        ];
                        self::RequireModule('Image::ExifTool::CanonRaw');
                        if (!method_exists(\Image\ExifTool\CanonRaw::class, 'WriteCRW')) {
                            $this->Error('Not ported: Image\ExifTool\CanonRaw::WriteCRW');
                        } elseif (\Image\ExifTool\CanonRaw::WriteCRW($this, $subdirInfo) > 0) {
                            if (strlen($newData->value)) {
                                $segDataPt = $newData->value;
                            } else {
                                $segDataPt = null;
                                $del = 1;   // delete this segment
                            }
                        }
                    }
                } elseif ($marker == 0xe1) {        // APP1 (EXIF, XMP)
                    // check for EXIF data
                    if (preg_match('/^(.{0,4})Exif\0./is', $segDataPt, $m)) {
                        $hdrLen = strlen(Vars::$exifAPP1hdr);
                        if (strlen($m[1])) {
                            $hdrLen += strlen($m[1]);
                            $this->Error('Unknown garbage at start of EXIF segment', 1);
                        } elseif (!str_starts_with($segDataPt, "Exif\0")) {
                            $this->Error('Incorrect EXIF segment identifier', 1);
                        }
                        $segType = 'EXIF';
                        if (!($editDirs['IFD0'] ?? null)) break;
                        // add this data to the combined data if it exists
                        if ($combinedSegData !== null) {
                            $combinedSegData .= substr($segDataPt, $hdrLen);
                            $segDataPt = $combinedSegData;
                            $segPos = $firstSegPos;
                            $length = strlen($combinedSegData); // update length
                        }
                        // peek ahead to see if the next segment is extended EXIF
                        if (($dirOrder[0] ?? null) === 'ExtendedEXIF') {
                            // initialize combined data if necessary
                            if ($combinedSegData === null) {
                                $combinedSegData = $segDataPt;
                                $firstSegPos = $segPos;
                                $this->Warn('File contains multi-segment EXIF', 1);
                            }
                            continue 2; // get the next segment to combine (next Marker)
                        }
                        if ($doneDir['IFD0'] ?? null) $this->Warn('Multiple APP1 EXIF records');
                        $doneDir['IFD0'] = 1;
                        // check del groups now so we can change byte order in one step
                        if (($delGroup['IFD0'] ?? null) or ($delGroup['EXIF'] ?? null)) {
                            unset($doneDir['IFD0']);    // delete so we will create a new one
                            $del = 1;
                            break;
                        }
                        // rewrite EXIF as if this were a TIFF file in memory
                        $subdirInfo = [
                            'DataPt'   => $segDataPt,
                            'DataPos'  => -$hdrLen, // (remember: relative to Base!)
                            'DirStart' => $hdrLen,
                            'Base'     => $segPos + $hdrLen,
                            'Parent'   => $markerName,
                            'DirName'  => 'IFD0',
                        ];
                        // write new EXIF data to memory
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::Exif::Main');
                        $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr, self::class . '::WriteTIFF');
                        if ($buff !== null) {
                            $segDataPt = $buff;     // (free the old buffer)
                        } else {
                            if (!$this->Options('IgnoreMinorErrors')) break 2;  // (last Marker)
                        }
                        // delete segment if IFD contains no entries
                        if (!strlen($segDataPt)) {
                            $del = 1;
                            break;
                        }
                        if (strlen($segDataPt) + strlen(Vars::$exifAPP1hdr) > self::MAX_SEGMENT_LEN) {
                            if ($this->Options('NoMultiExif')) {
                                $this->Error('EXIF is too large for JPEG segment');
                            } else {
                                $this->Warn('Writing multi-segment EXIF', 1);
                            }
                        }
                        // switch to buffered output if required
                        if (($this->PREVIEW_INFO or $this->LeicaTrailer or $this->HiddenData) and !$oldOutfile) {
                            $writeBuffer = new ScalarRef('');
                            $oldOutfile = $outfile;
                            $outfile = $writeBuffer;
                            // must account for segment, EXIF and TIFF headers
                            foreach (['PREVIEW_INFO', 'LeicaTrailer', 'HiddenData'] as $k) {
                                if ($this->{$k}) $this->{$k}['Fixup']->Start += 18;
                            }
                        }
                        // write as multi-segment
                        $n = self::WriteMultiSegment($outfile, $marker, Vars::$exifAPP1hdr, $segDataPt, 'EXIF');
                        if (!$n) {
                            $err = 1;
                        } elseif ($n > 1 and $oldOutfile) {
                            // (punt on this because updating the pointers would be a real pain)
                            $this->Error("Can't write multi-segment EXIF with external pointers");
                        }
                        $combinedSegData = null;
                        $segDataPt = null;
                        continue 2;     // (next Marker)
                    // check for XMP data
                    } elseif (preg_match(self::Rx('^(' . Vars::$xmpAPP1hdr . '|' . Vars::$xmpExtAPP1hdr . ')'), $segDataPt)) {
                        $segType = 'XMP';
                        if ($delGroup['XMP'] ?? null) {
                            $del = 1;
                            break;
                        }
                        $doneDir['XMP'] = ($doneDir['XMP'] ?? 0) + 1;
                        if (!($editDirs['XMP'] ?? null)) break;
                        if ($doneDir['XMP'] + ($dirCount['XMP'] ?? 0) > 1) {
                            // must assemble all XMP segments before writing
                            $guid = $extXMP = null;
                            if (preg_match(self::Rx('^' . Vars::$xmpExtAPP1hdr), $segDataPt)) {
                                // save extended XMP data
                                if (strlen($segDataPt) < 75) {
                                    $extendedXMP['Error'] = 'Truncated data';
                                } else {
                                    [$size, $off] = Perl::unpack('x67N2', $segDataPt);
                                    $guid = substr($segDataPt, 35, 32);
                                    if (preg_match('/[^A-Za-z0-9]/', $guid)) { // (technically, should be uppercase)
                                        $extendedXMP['Error'] = 'Invalid GUID';
                                    } else {
                                        // remember extended data for each GUID
                                        $extXMP = $extendedXMP[$guid] ?? null;
                                        if ($extXMP) {
                                            if ($size != $extXMP['Size']) $extendedXMP['Error'] = 'Inconsistent size';
                                        } else {
                                            $extendedXMP[$guid] = [];
                                        }
                                        $extendedXMP[$guid]['Size'] = $size;
                                        $extendedXMP[$guid][$off] = substr($segDataPt, 75);
                                    }
                                }
                            } else {
                                // save all main XMP segments (should normally be only one)
                                $extendedXMP['Main'] ??= [];
                                $extendedXMP['Main'][] = substr($segDataPt, strlen(Vars::$xmpAPP1hdr));
                            }
                            // continue processing only if we have read all the segments
                            if ($dirCount['XMP'] ?? null) continue 2;   // (next Marker)
                            // reconstruct an XMP super-segment
                            $segDataPt = Vars::$xmpAPP1hdr;
                            $goodGuid = '';
                            foreach ($extendedXMP['Main'] ?? [] as $main) {
                                // get the HasExtendedXMP GUID if it exists
                                if (preg_match('/:HasExtendedXMP\s*(=\s*[\'"]|>)(\w{32})/', $main, $mm)) {
                                    // warn of subsequent XMP blocks specifying a different
                                    // HasExtendedXMP (have never seen this)
                                    if ($goodGuid and $goodGuid !== $mm[2]) {
                                        $this->Warn('Multiple XMP segments specifying different extended XMP GUID');
                                    }
                                    $goodGuid = $mm[2]; // GUID for the standard extended XMP
                                }
                                $segDataPt .= $main;
                            }
                            // GUID of the extended XMP that we want to read
                            $readGuid = Perl::str(($this->OPTIONS['ExtendedXMP'] ?? null) ?: 0);
                            if ($readGuid === '1') $readGuid = $goodGuid;
                            $guids = array_map('strval', array_keys($extendedXMP));
                            sort($guids, SORT_STRING);
                            foreach ($guids as $guid) {
                                if (strlen($guid) != 32) continue;  // ignore other (internal) keys
                                if ($guid !== $readGuid and $readGuid !== '2') {
                                    $non = $guid === $goodGuid ? '' : 'non-';
                                    $this->Warn("Ignored {$non}standard extended XMP (GUID $guid)");
                                    continue;
                                }
                                if ($guid !== $goodGuid) {
                                    $this->Warn("Reading non-standard extended XMP (GUID $guid)");
                                }
                                $extXMP = $extendedXMP[$guid];
                                if (!is_array($extXMP)) continue;   // (just to be safe)
                                $size = $extXMP['Size'];
                                $offsets = [];
                                for ($off = 0; $off < $size; ) {
                                    if (!isset($extXMP[$off])) break;
                                    $offsets[] = $off;
                                    $off += strlen($extXMP[$off]);
                                }
                                if ($off == $size) {
                                    // add all XMP to super-segment
                                    foreach ($offsets as $o) $segDataPt .= $extXMP[$o];
                                } else {
                                    $this->Error("Incomplete extended XMP (GUID $guid)", 1);
                                }
                            }
                            if ($extendedXMP['Error'] ?? null) $this->Error("{$extendedXMP['Error']} in extended XMP", 1);
                        }
                        $start = strlen(Vars::$xmpAPP1hdr);
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::XMP::Main');
                        $subdirInfo = [
                            'DataPt'     => $segDataPt,
                            'DirStart'   => $start,
                            'Parent'     => $markerName,
                            // limit XMP size and create extended XMP if necessary
                            'MaxDataLen' => self::MAX_XMP_LEN - strlen(Vars::$xmpAPP1hdr),
                        ];
                        $newData = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                        if ($newData !== null) {
                            $extendedXMP = [];
                            if (strlen($newData)) {
                                // write multi-segment XMP (XMP plus extended XMP if necessary)
                                if (!$this->WriteMultiXMP($outfile, $newData, $subdirInfo['ExtendedXMP'] ?? null,
                                              $subdirInfo['ExtendedGUID'] ?? null)) $err = 1;
                                $segDataPt = null;  // free the old buffer
                                continue 2;         // (next Marker)
                            } else {
                                $segDataPt = '';    // delete the XMP
                            }
                        } else {
                            if ($verbose) fwrite($out, "    [XMP rewritten with no changes]\n");
                            if ($doneDir['XMP'] > 1) {
                                // re-write original multi-segment XMP
                                foreach ($extendedXMP['Main'] ?? [] as $dat) {     // main XMP
                                    if (!strlen($dat)) continue;
                                    $s = pack('n', strlen(Vars::$xmpAPP1hdr) + strlen($dat) + 2);
                                    if (!self::Write($outfile, $hdr, $s, Vars::$xmpAPP1hdr, $dat)) $err = 1;
                                }
                                $guids = array_map('strval', array_keys($extendedXMP));
                                sort($guids, SORT_STRING);
                                foreach ($guids as $guid) {                         // extended XMP
                                    if (strlen($guid) != 32) continue;
                                    $extXMP = $extendedXMP[$guid];
                                    if (!is_array($extXMP)) continue;
                                    $size = $extXMP['Size'];
                                    if (!$size) continue;
                                    for ($off = 0; isset($extXMP[$off]); $off += strlen($extXMP[$off])) {
                                        $s = pack('n', strlen(Vars::$xmpExtAPP1hdr) + strlen($extXMP[$off]) + 42);
                                        if (!self::Write($outfile, $hdr, $s, Vars::$xmpExtAPP1hdr, $guid,
                                              pack('N2', $size, $off), $extXMP[$off])) $err = 1;
                                    }
                                }
                                $segDataPt = null;  // free the old buffer
                                $extendedXMP = [];
                                continue 2;         // (next Marker)
                            }
                            // continue on to re-write original single-segment XMP
                        }
                        if (!strlen($segDataPt)) $del = 1;
                    } elseif (str_starts_with($segDataPt, 'http') or str_contains($segDataPt, '<exif:')) {
                        $this->Warn('Ignored APP1 XMP segment with non-standard header', 1);
                    }
                } elseif ($marker == 0xe2) {        // APP2 (ICC Profile, FPXR, MPF)
                    if (str_starts_with($segDataPt, "ICC_PROFILE\0") and $length >= 14) {
                        $segType = 'ICC_Profile';
                        if ($delGroup['ICC_Profile'] ?? null) {
                            $del = 1;
                            break;
                        }
                        // must concatenate blocks of profile
                        $chunkNum = self::Get8u($segDataPt, 12);
                        $chunksTot = self::Get8u($segDataPt, 13);
                        if ($iccChunksTotal !== null) {
                            // abort parsing ICC_Profile if the total chunk count is inconsistent
                            if ($chunksTot != $iccChunksTotal and $iccChunkCount !== null) {
                                // an error because the accumulated profile data will be lost
                                $this->Error('Inconsistent ICC_Profile chunk count', 1);
                                $iccChunkCount = null;  // abort ICC_Profile parsing
                                $chunkNum = null;       // avoid 2nd warning below
                                ++$this->CHANGED;       // we are deleting the bad chunks before this one
                            }
                        } else {
                            $iccChunkCount = 0;
                            $iccChunksTotal = $chunksTot;
                            if (!$chunksTot) $this->Warn('ICC_Profile chunk count is zero');
                        }
                        if ($iccChunkCount !== null) {
                            // save this chunk
                            if (isset($iccChunk[$chunkNum])) {
                                $this->Warn("Duplicate ICC_Profile chunk number $chunkNum");
                                $iccChunk[$chunkNum] .= substr($segDataPt, 14);
                            } else {
                                $iccChunk[$chunkNum] = substr($segDataPt, 14);
                            }
                            // continue accumulating chunks unless we have all of them
                            if (!(++$iccChunkCount >= $iccChunksTotal)) continue 2;    // (next Marker)
                            $iccChunkCount = null;  // prevent reprocessing
                            $doneDir['ICC_Profile'] = 1;
                            // combine the ICC_Profile chunks
                            $icc_profile = '';
                            ksort($iccChunk);
                            foreach ($iccChunk as $chunk) {
                                if ($chunk !== null) $icc_profile .= $chunk;
                            }
                            $iccChunk = [];         // free memory
                            $segDataPt = $icc_profile;
                            $length = strlen($icc_profile);
                            $tagTablePtr = self::GetTagTable('Image::ExifTool::ICC_Profile::Main');
                            $subdirInfo = [
                                'DataPt'   => $segDataPt,
                                'DataPos'  => $segPos + 14,
                                'DataLen'  => $length,
                                'DirStart' => 0,
                                'DirLen'   => $length,
                                'Parent'   => $markerName,
                            ];
                            $newData = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                            if ($newData !== null) {
                                $segDataPt = $newData;  // (free the old buffer)
                            }
                            if (!strlen($segDataPt)) {
                                $del = 1;
                                break;
                            }
                            // write as ICC multi-segment
                            if (!self::WriteMultiSegment($outfile, $marker, "ICC_PROFILE\0", $segDataPt, 'ICC')) $err = 1;
                            $segDataPt = null;
                            continue 2;     // (next Marker)
                        } elseif ($chunkNum !== null) {
                            $this->Warn('Invalid or extraneous ICC_Profile chunk(s)');
                            // fall through to preserve this extra profile...
                        }
                    } elseif (str_starts_with($segDataPt, "FPXR\0")) {
                        $segType = 'FPXR';
                        if ($delGroup['FlashPix'] ?? null) $del = 1;
                    } elseif (str_starts_with($segDataPt, "MPF\0")) {
                        $segType = 'MPF';
                        if ($delGroup['MPF'] ?? null) $del = 1;
                    }
                } elseif ($marker == 0xe3) {        // APP3 (Kodak Meta)
                    if (preg_match('/^(Meta|META|Exif)\0\0/', $segDataPt)) {
                        $segType = 'Kodak Meta';
                        if ($delGroup['Meta'] ?? null) {
                            $del = 1;
                            break;
                        }
                        if ($doneDir['Meta'] ?? null) $this->Warn('Multiple APP3 Meta segments');
                        $doneDir['Meta'] = 1;
                        if (!($editDirs['Meta'] ?? null)) break;
                        // rewrite Meta IFD as if this were a TIFF file in memory
                        $subdirInfo = [
                            'DataPt'   => $segDataPt,
                            'DataPos'  => -6, // (remember: relative to Base!)
                            'DirStart' => 6,
                            'Base'     => $segPos + 6,
                            'Parent'   => $markerName,
                            'DirName'  => 'Meta',
                        ];
                        // write new data to memory
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::Kodak::Meta');
                        $buff = $this->WriteDirectory($subdirInfo, $tagTablePtr, self::class . '::WriteTIFF');
                        if ($buff !== null) {
                            // update segment with new data
                            $segDataPt = substr($segDataPt, 0, 6) . $buff;
                        } else {
                            if (!$this->Options('IgnoreMinorErrors')) break 2;  // (last Marker)
                        }
                        // delete segment if IFD contains no entries
                        if (!(strlen($segDataPt) > 6)) $del = 1;
                    }
                } elseif ($marker == 0xe5) {        // APP5 (Ricoh RMETA)
                    if (str_starts_with($segDataPt, "RMETA\0")) {
                        $segType = 'Ricoh RMETA';
                        if ($delGroup['RMETA'] ?? null) $del = 1;
                    }
                } elseif ($marker == 0xe8 or $marker == 0xe9) { // APP8/9 (SEAL)
                    if (str_starts_with($segDataPt, "SEAL\0")) {
                        $segType = 'SEAL';
                        if ($delGroup['SEAL'] ?? null) $del = 1;
                    }
                } elseif ($marker == 0xea) {        // APP10 (AROT)
                    if (str_starts_with($segDataPt, "AROT\0\0")) {
                        $segType = 'AROT';
                        if ($delGroup['AROT'] ?? null) $del = 1;
                    }
                } elseif ($marker == 0xeb) {        // APP11 (JUMBF)
                    if (str_starts_with($segDataPt, 'JP')) {
                        $segType = 'JUMBF';
                        if ($delGroup['JUMBF'] ?? null) $del = 1;
                    }
                } elseif ($marker == 0xec) {        // APP12 (Ducky)
                    if (str_starts_with($segDataPt, 'Ducky')) {
                        $segType = 'Ducky';
                        if ($delGroup['Ducky'] ?? null) {
                            $del = 1;
                            break;
                        }
                        if ($doneDir['Ducky'] ?? null) $this->Warn('Multiple APP12 Ducky segments');
                        $doneDir['Ducky'] = 1;
                        if (!($editDirs['Ducky'] ?? null)) break;
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::APP12::Ducky');
                        $subdirInfo = [
                            'DataPt'   => $segDataPt,
                            'DataPos'  => $segPos,
                            'DataLen'  => $length,
                            'DirStart' => 5,     // directory starts after identifier
                            'DirLen'   => $length - 5,
                            'Parent'   => $markerName,
                        ];
                        $newData = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                        if ($newData !== null) {
                            // add header to new segment unless empty
                            if (strlen($newData)) $newData = 'Ducky' . $newData;
                            $segDataPt = $newData;  // (free the old buffer)
                        }
                        if (!strlen($segDataPt)) $del = 1;
                    }
                } elseif ($marker == 0xed) {        // APP13 (Photoshop)
                    if (preg_match(self::Rx('^' . Vars::$psAPP13hdr), $segDataPt)) {
                        $segType = 'Photoshop';
                        // add this data to the combined data if it exists
                        if ($combinedSegData !== null) {
                            $combinedSegData .= substr($segDataPt, strlen(Vars::$psAPP13hdr));
                            $segDataPt = $combinedSegData;
                            $length = strlen($combinedSegData); // update length
                        }
                        // peek ahead to see if the next segment is photoshop data too
                        if (($dirOrder[0] ?? null) === 'Photoshop') {
                            // initialize combined data if necessary
                            if ($combinedSegData === null) $combinedSegData = $segDataPt;
                            continue 2; // get the next segment to combine (next Marker)
                        }
                        if ($doneDir['Photoshop'] ?? null) {
                            $this->Warn('Multiple Photoshop records');
                            // only rewrite the first Photoshop segment when deleting this group
                            // (to remove multiples when deleting and adding back in one step)
                            if ($delGroup['Photoshop'] ?? null) {
                                $del = 1;
                                break;
                            }
                        }
                        $doneDir['Photoshop'] = 1;
                        // process APP13 Photoshop record
                        $tagTablePtr = self::GetTagTable('Image::ExifTool::Photoshop::Main');
                        $subdirInfo = [
                            'DataPt'   => $segDataPt,
                            'DataPos'  => $segPos,
                            'DataLen'  => $length,
                            'DirStart' => 14,     // directory starts after identifier
                            'DirLen'   => $length - 14,
                            'Parent'   => $markerName,
                        ];
                        $newData = $this->WriteDirectory($subdirInfo, $tagTablePtr);
                        if ($newData !== null) {
                            $segDataPt = $newData;  // (free the old buffer)
                        }
                        if (!strlen($segDataPt)) {
                            $del = 1;
                            break;
                        }
                        // write as multi-segment
                        if (!self::WriteMultiSegment($outfile, $marker, Vars::$psAPP13hdr, $segDataPt)) $err = 1;
                        $combinedSegData = null;
                        $segDataPt = null;
                        continue 2;     // (next Marker)
                    }
                } elseif ($marker == 0xee) {        // APP14 (Adobe)
                    if (str_starts_with($segDataPt, 'Adobe')) {
                        $segType = 'Adobe';
                        // delete it and replace it later if editing
                        if (($delGroup['Adobe'] ?? null) or ($editDirs['Adobe'] ?? null)) {
                            $del = 1;
                            $doneDir['Adobe'] = null;   // so we can add it back again above
                        }
                    }
                } elseif ($marker == 0xfe) {        // COM (JPEG comment)
                    $newComment = null;
                    if (!($doneDir['COM'] ?? null)) {
                        $doneDir['COM'] = 1;
                        if (!(($delGroup['File'] ?? null) and $delGroup['File'] != 2)) {
                            $tagInfo = $extra['Comment'];
                            $nvHash = $this->GetNewValueHash($tagInfo);
                            // allow for stupid software that adds NULL terminator
                            $val = preg_replace('/\0+$/', '', (string) $segData);
                            if ($this->IsOverwriting($nvHash, $val) or ($delGroup['File'] ?? null)) {
                                $newComment = $this->GetNewValue($nvHash);
                            } else {
                                unset($editDirs['COM']);    // we aren't editing COM after all
                                break;
                            }
                        }
                    }
                    $this->VerboseValue('- Comment', $segDataPt);
                    if ($newComment !== null) {
                        // write out the comments
                        $this->VerboseValue('+ Comment', $newComment);
                        if (!self::WriteMultiSegment($outfile, 0xfe, '', $newComment)) $err = 1;
                    } else {
                        if ($verbose) fwrite($out, "  Deleting COM segment\n");
                    }
                    ++$this->CHANGED;       // increment the changed flag
                    $segDataPt = null;      // don't write existing comment
                }
                break;  // didn't want to loop anyway
            }

            // delete necessary segments (including unknown segments if deleting all)
            if ($del or (($delGroup['*'] ?? null) and !$segType and $marker >= 0xe0 and $marker <= 0xef)) {
                if (!$segType) $segType = 'unknown';
                if ($verbose) fwrite($out, "  Deleting $markerName $segType segment\n");
                ++$this->CHANGED;
                continue;   // (next Marker)
            }
            // write out this segment if $segDataPt is still defined
            if ($segDataPt !== null) {
                // write the data for this record (the data could have been
                // modified, so recalculate the length word)
                $size = strlen($segDataPt);
                if ($size > self::MAX_SEGMENT_LEN) {
                    if (!$segType) $segType = 'Unknown';
                    $this->Error("$segType $markerName segment too large! ($size bytes)");
                    $err = 1;
                } else {
                    $s = pack('n', strlen($segDataPt) + 2);
                    if (!self::Write($outfile, $hdr, $s, $segDataPt)) $err = 1;
                }
                $segDataPt = null;  // free the buffer
            }
        }
        // make sure the ICC_Profile was complete
        if ($iccChunkCount !== null) $this->Error('Incomplete ICC_Profile record', 1);
        if (count($path) > $pn) array_pop($path);
        // if oldOutfile is still set, there was an error copying the JPEG
        if ($oldOutfile) return 0;
        if ($rtnVal) {
            // add any new trailers we are creating
            $trailPt = $this->AddNewTrailers();
            if ($trailPt and !self::Write($outfile, $trailPt->value)) $err = 1;
        }
        // set return value to -1 if we only had a write error
        if ($rtnVal and $err) $rtnVal = -1;
        if ($creatingEXV and $rtnVal > 0 and !$this->CHANGED) {
            $this->Error('Nothing written');
            $rtnVal = -1;
        }
        return $rtnVal;
        } finally {
            Perl::$RS = $oldRS;
        }
    }

    //------------------------------------------------------------------------------
    // Validate an image for writing
    // Inputs: 0) ExifTool object reference, 1) raw value reference
    // Returns: error string or undef on success
    // (PHP: takes the value itself)
    public function CheckImage($valPtr)
    {
        if (strlen(Perl::str($valPtr)) and !str_starts_with(Perl::str($valPtr), "\xff\xd8") and
            !$this->Options('IgnoreMinorErrors'))
        {
            return '[Minor] Not a valid image';
        }
        return null;
    }

    //------------------------------------------------------------------------------
    // check new value for binary data block
    // Inputs: 0) ExifTool object ref, 1) tagInfo hash ref, 2) raw value ref
    // Returns: error string or undef (and may modify value) on success
    // (PHP: CHECK_PROC of the binary data tables, so a static method; the value is passed by
    //  reference)
    public static function CheckBinaryData(ExifTool $self, TagInfo $tagInfo, &$valPtr)
    {
        $format = $tagInfo->Format;
        if (!$format) {
            $table = $tagInfo->Table;
            if ($table and $table['FORMAT']) {
                $format = $table['FORMAT'];
            } else {
                // use default 'int8u' unless specified
                $format = 'int8u';
            }
        }
        $count = null;
        if (preg_match('/(.*)\[(.*)\]/', $format, $m)) {
            $format = $m[1];
            $count = $m[2];
            // can't evaluate $count now because we don't know $size yet
            if (str_contains($count, '$size')) $count = -1;  // (-1 = any count allowed)
        }
        return self::CheckValue($valPtr, $format, $count);
    }

    //------------------------------------------------------------------------------
    // Rename a file (with patch for Windows Unicode file names, and other problem)
    // Inputs: 0) ExifTool ref, 1) old name, 2) new name
    // Returns: true on success
    // (PHP: no Windows wide-character routines, see EncodeFileName)
    public function Rename($old, $new)
    {
        $result = $try = null;

        $this->EncodeFileName($old);
        $this->EncodeFileName($new);
        for (;;) {
            $result = @rename($old, $new);
            if ($result or PHP_OS_FAMILY !== 'Windows') break;
            // keep trying for up to 0.5 seconds
            // (patch for Windows denial-of-service susceptibility)
            $try = ($try ?: 1) + 1;
            if ($try > 50) break;
            usleep(10000);  // sleep for 0.01 sec
        }
        return $result;
    }

    //------------------------------------------------------------------------------
    // Delete a file (with patch for Windows Unicode file names)
    // Inputs: 0) ExifTool ref, 1-N) names of files to delete
    // Returns: number of files deleted
    public function Unlink(...$files)
    {
        $result = 0;
        foreach ($files as $file) {
            $this->EncodeFileName($file);
            if (@unlink($file)) ++$result;
        }
        return $result;
    }

    //------------------------------------------------------------------------------
    // Set file times (Unix seconds since the epoch)
    // Inputs: 0) ExifTool ref, 1) file name or ref, 2) access time, 3) modification time,
    //         4) inode change or creation time (or undef for any time to avoid setting)
    //         5) flag to suppress warning
    // Returns: 1 on success, 0 on error
    // (PHP: touch() on the file name, also for a file handle; the Win32::API branch for
    //  Windows doesn't apply)
    public function SetFileTime($file, $atime = null, $mtime = null, $ctime = null, $noWarn = null)
    {
        $saveFile = null;

        // open file by name if necessary
        if (!self::IsRef($file)) {
            $fh = null;
            if (!$this->Open($fh, $file, '+<')) {
                $success = null;
                if ($atime !== null or $mtime !== null) {
                    [$a, $m] = $this->GetFileTime($file) + [null, null];
                    if ($atime === null) $atime = $a;
                    if ($mtime === null) $mtime = $m;
                    if ($atime !== null and $mtime !== null) $success = self::Utime($atime, $mtime, $file);
                }
                if (!$success) $this->Warn('Error updating file time');
                return $success;
            }
            $saveFile = $file;
            $file = $fh;
        }
        // other OS (or Windows fallback)
        if ($atime !== null and $mtime !== null) {
            $success = null;
            for (;;) {
                self::$evalWarning = null;
                // (this may fail on the first try if futimes is not implemented)
                $success = self::Utime($atime, $mtime, self::FilePath($file));
                if ($success or $saveFile === null) break;
                fclose($file);
                $file = $saveFile;
                $saveFile = null;
            }
            if (!$noWarn) {
                if (self::$evalWarning) {
                    $this->Warn(self::CleanWarning(self::$evalWarning));
                } elseif (!$success) {
                    $this->Warn('Error setting file time');
                }
            }
            return $success;
        }
        return 1; // (nothing to do)
    }

    //------------------------------------------------------------------------------
    // Copy data block from RAF to output file in max 64kB chunks
    // Inputs: 0) RAF ref, 1) outfile ref, 2) block size
    // Returns: 1 on success, 0 on read error, undef on write error
    public static function CopyBlock(RandomAccess $raf, $outfile, $size)
    {
        $buff = null;
        for (;;) {
            if (!($size > 0)) break;
            $n = $size > 65536 ? 65536 : $size;
            if ($raf->Read($buff, $n) != $n) return 0;
            if (!self::Write($outfile, $buff)) return null;
            $size -= $n;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Copy image data from one file to another
    // Inputs: 0) ExifTool object reference
    //         1) reference to list of image data [ position, size, pad bytes ]
    //         2) output file ref
    // Returns: true on success
    public function CopyImageData(array $imageDataBlocks, $outfile)
    {
        $raf = $this->RAF;
        $err = null;
        $num = count($imageDataBlocks);
        if ($num) $this->VPrint(0, "  Copying $num image data blocks\n");
        foreach ($imageDataBlocks as $dataBlock) {
            [$pos, $size, $pad] = $dataBlock + [null, null, null];
            if (!$raf->Seek($pos, 0)) {
                $err = 'read';
                break;
            }
            $result = self::CopyBlock($raf, $outfile, $size);
            if (!$result) $err = $result !== null ? 'read' : 'writ';
            // pad if necessary
            if ($pad and !self::Write($outfile, str_repeat("\0", $pad))) $err = 'writ';
            if ($err) break;
        }
        if ($err) {
            $this->Error("Error {$err}ing image data");
            return 0;
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Write to binary data block
    // Inputs: 0) ExifTool object ref, 1) source dirInfo ref, 2) tag table ref
    // Returns: Binary data block or undefined on error
    // (PHP: WRITE_PROC of the binary data tables, so a static method)
    public static function WriteBinaryData(?ExifTool $self = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$self) return 1;   // allow dummy access to autoload this package

        // get default format ('int8u' unless specified)
        $dataPt = $dirInfo['DataPt'] ?? null;
        if ($dataPt === null) return null;
        $dataPt = Perl::str($dataPt);
        $dataLen = strlen($dataPt);
        $defaultFormat = $tagTablePtr['FORMAT'] ?: 'int8u';
        $increment = self::FormatSize($defaultFormat);
        if (!$increment) {
            self::PerlWarn("Unknown format $defaultFormat\n");
            return null;
        }
        // extract data members first if necessary
        $varOffsets = [];
        if ($tagTablePtr['DATAMEMBER']) {
            $dirInfo['DataMember'] = $tagTablePtr['DATAMEMBER'];
            $dirInfo['VarFormatData'] = [];
            $self->ProcessBinaryData($dirInfo, $tagTablePtr);
            $varOffsets = $dirInfo['VarFormatData'];
            unset($dirInfo['DataMember'], $dirInfo['VarFormatData']);
        }
        $dirStart = ($dirInfo['DirStart'] ?? null) ?: 0;
        $dirLen = $dirInfo['DirLen'] ?? null;
        if ($dirLen === null or $dirLen > $dataLen - $dirStart) $dirLen = $dataLen - $dirStart;
        $newData = substr($dataPt, $dirStart, $dirLen);
        if (!$newData) return null;
        $dirName = $dirInfo['DirName'] ?? null;
        $varSize = 0;
        $varInfo = $varOffsets;
        // ($dataPt = \$newData: the new data is $newData below)
        $tagInfos = $self->GetNewTagInfoList($tagTablePtr);
        usort($tagInfos, static fn ($a, $b) => Perl::num($a->TagID) <=> Perl::num($b->TagID));
        foreach ($tagInfos as $tagInfo) {
            $tagID = $tagInfo->TagID;
            // evaluate conditional tags now if necessary
            $entryInfo = $tagTablePtr[$tagID];
            if ((is_array($entryInfo) and array_is_list($entryInfo)) or $tagInfo->Condition) {
                $writeInfo = $self->GetTagInfo($tagTablePtr, $tagID);
                if (!($writeInfo and $writeInfo === $tagInfo)) continue;
            }
            // add offsets for variable-sized tags if necessary
            while ($varInfo and $varInfo[0][0] < $tagID) {
                $varSize = $varInfo[0][1];  // get accumulated variable size
                array_shift($varInfo);
            }
            $count = 1;
            $format = $tagInfo->Format;
            $entry = Perl::int($tagID) * $increment + $varSize; // relative offset of this entry
            if ($format) {
                if (preg_match('/(.*)\[(.*)\]/', $format, $m)) {
                    $format = $m[1];
                    $count = $m[2];
                    $size = $dirLen; // used in eval
                    // evaluate count to allow count to be based on previous values
                    //### eval Format size ($size, $self) - NOTE: %val not supported for writing
                    $fc = $tagInfo->FormatCount;
                    if ($fc !== null) {
                        $evalErr = null;
                        self::EvalBegin();
                        try {
                            $count = $fc([], $size, $varSize, $self);
                        } catch (\Throwable $e) {
                            $evalErr = self::EvalError($e);
                        } finally {
                            self::EvalEnd();
                        }
                        if ($evalErr !== null) {
                            self::PerlWarn($evalErr);
                            continue;
                        }
                    }
                    $count = Perl::num($count);
                } elseif ($format === 'string') {
                    // string with no specified count runs to end of block
                    $count = ($dirLen > $entry) ? $dirLen - $entry : 0;
                }
            } else {
                $format = $defaultFormat;
            }
            // read/write using variable format if changed in Hook
            if ($varInfo and $varInfo[0][0] == $tagID) $format = $varInfo[0][2];
            $val = self::ReadValue($newData, $entry, $format, $count, $dirLen - $entry);
            if ($val === null) continue;
            $nvHash = $self->GetNewValueHash($tagInfo, $self->CUR_WRITE_GROUP);
            if (!($self->IsOverwriting($nvHash, $val) > 0)) continue;
            $newVal = $self->GetNewValue($nvHash);
            if ($newVal === null) continue;     // can't delete from a binary table
            // update DataMember with new value if necessary
            if ($tagInfo->DataMember) $self->{$tagInfo->DataMember} = $newVal;
            // only write masked bits if specified
            $mask = $tagInfo->Mask;
            if ($mask) {
                $mask = Perl::int(Perl::num($mask));
                $newVal = ((Perl::int(Perl::num($newVal)) << (int) $tagInfo->BitShift) & $mask) |
                          (Perl::int(Perl::num($val)) & ~$mask);
            }
            // set the size
            if ($tagInfo->DataTag and !$tagInfo->IsOffset) {
                if (Perl::num($newVal) != 0xfeedfeed) self::PerlWarn('Internal error');
                $data = $self->GetNewValue($tagInfo->DataTag);
                if ($data !== null) $newVal = strlen($data);
                $fmt = $tagInfo->Format ?: ($tagTablePtr['FORMAT'] ?: 'int32u');
                if (str_starts_with($fmt, 'int16') and $newVal > 0xffff) {
                    $self->Error("{$tagInfo->DataTag} is too large (64 KiB max. for this file)");
                }
            }
            $rtnVal = self::WriteValue($newVal, $format, $count, $newData, $entry);
            if ($rtnVal !== null) {
                $self->VerboseValue("- $dirName:{$tagInfo->Name}", $val);
                $self->VerboseValue("+ $dirName:{$tagInfo->Name}", $newVal);
                ++$self->CHANGED;
            } else {
                $self->Warn("Error packing {$tagInfo->Name} value");
            }
        }
        // add necessary fixups for any offsets
        if ($tagTablePtr['IS_OFFSET'] and ($dirInfo['Fixup'] ?? null)) {
            $varSize = 0;
            $varInfo = $varOffsets;
            $fixup = $dirInfo['Fixup'];
            foreach ($tagTablePtr['IS_OFFSET'] as $tagID) {
                $tagInfo = $self->GetTagInfo($tagTablePtr, $tagID);
                if (!$tagInfo) continue;
                while ($varInfo and $varInfo[0][0] < $tagID) {
                    $varSize = $varInfo[0][1];
                    array_shift($varInfo);
                }
                $entry = $tagID * $increment + $varSize;    // (no offset to dirStart for new dir data)
                if (!($entry <= $dirLen - 4)) continue;
                // (Ricoh has 16-bit preview image offsets, so can't just assume int32u)
                $format = $tagInfo->Format ?: ($tagTablePtr['FORMAT'] ?: 'int32u');
                $offset = self::ReadValue($newData, $entry, $format, 1, $dirLen - $entry);
                // ignore if offset is zero (eg. Ricoh DNG uses this to indicate no preview)
                if (!$offset) continue;
                $fixup->AddFixup($entry, $tagInfo->DataTag, $format);
                if (!($tagInfo->DataTag and $tagInfo->OffsetPair !== null)) continue;
                // NOTE: here we assume there are no var-sized tags between the
                // OffsetPair tags.  If this ever becomes possible we must recalculate
                // $varSize for the OffsetPair tag here!
                $entry = $tagInfo->OffsetPair * $increment + $varSize;
                $size = self::ReadValue($newData, $entry, $format, 1, $dirLen - $entry);
                if ($size === null) continue;
                if ($tagInfo->DataTag === 'HiddenData') {
                    $self->HiddenData = [
                        'Offset' => $offset,
                        'Size'   => $size,
                        'Fixup'  => new Fixup(),
                        'Base'   => $dirInfo['Base'] ?? null,
                    ];
                    continue;
                }
                // handle the preview image now if this is a JPEG file
                if (!($tagInfo->DataTag === 'PreviewImage' and $self->FILE_TYPE === 'JPEG')) continue;
                $previewInfo = &$self->PREVIEW_INFO;
                if (!$previewInfo) $previewInfo = [
                    'Fixup' => new Fixup(),
                ];
                // set flag indicating we are using short pointers
                if ($format !== 'int32u') $previewInfo['IsShort'] = 1;
                if ($tagInfo->IsOffset and Perl::str($tagInfo->IsOffset) === '3') $previewInfo['Absolute'] = 1;
                // get the value of the Composite::PreviewImage tag
                $previewInfo['Data'] = $self->GetNewValue(self::GetCompositeTagInfo('PreviewImage'));
                if ($previewInfo['Data'] === null) {
                    if ($offset >= 0 and $offset + $size <= ($dirInfo['DataLen'] ?? 0)) {
                        $previewInfo['Data'] = substr($dataPt, $offset, $size);
                    } else {
                        $previewInfo['Data'] = 'LOAD_PREVIEW'; // flag to load preview later
                    }
                }
                unset($previewInfo);
            }
        }
        // write any necessary SubDirectories
        if ($tagTablePtr['IS_SUBDIR']) {
            $varSize = 0;
            $varInfo = $varOffsets;
            foreach ($tagTablePtr['IS_SUBDIR'] as $tagID) {
                $tagInfo = $self->GetTagInfo($tagTablePtr, $tagID);
                if ($tagInfo === null) continue;
                while ($varInfo and $varInfo[0][0] < $tagID) {
                    $varSize = $varInfo[0][1];
                    array_shift($varInfo);
                }
                $entry = Perl::int($tagID) * $increment + $varSize;
                if ($entry >= $dirLen) break;
                // get value for Condition if necessary
                if (!$tagInfo) {
                    $more = $dirLen - $entry;
                    if ($more > 128) $more = 128;
                    $v = substr($newData, $entry, $more);
                    $tagInfo = $self->GetTagInfo($tagTablePtr, $tagID, $v);
                    if (!$tagInfo) continue;
                }
                $subdir = $tagInfo->SubDirectory;
                if (!$subdir) continue;
                $start = $subdir['Start'] ?? null;
                $len = null;
                if (!$start) {
                    $start = $entry;
                    $len = $dirLen - $start;
                } elseif ($start instanceof \Closure) {     // (Perl: $start =~ /\$/)
                    $count = 1;
                    $format = $tagInfo->Format ?: $defaultFormat;
                    if (preg_match('/(.*)\[(.*)\]/', $format, $m)) [$format, $count] = [$m[1], $m[2]];
                    $val = self::ReadValue($newData, $entry, $format, $count, $dirLen - $entry);
                    // ignore directories with a zero offset (ie. missing Nikon ShotInfo entries)
                    if (!$val) continue;
                    $dirStart = 0;
                    //### eval Start ($val, $dirStart)
                    try {
                        $start = $start(null, $val, $dirStart);
                    } catch (\Throwable) {
                        $start = null;
                    }
                    if ($start < $dirStart or $start > $dataLen) continue;
                    $len = $subdir['DirLen'] ?? null;
                    if (!($len and $len <= $dataLen - $start)) $len = $dataLen - $start;
                }
                $subdirInfo = [
                    'DataPt'   => $newData,
                    'DirStart' => $start,
                    'DirLen'   => $len,
                    'TagInfo'  => $tagInfo,
                ];
                $dat = $self->WriteDirectory($subdirInfo, self::GetTagTable($subdir['TagTable']));
                if ($dat !== null and strlen($dat)) $newData = substr_replace($newData, $dat, (int) $start, (int) $len);
            }
        }
        return $newData;
    }

    //------------------------------------------------------------------------------
    // Write TIFF as a directory
    // Inputs: 0) ExifTool object ref, 1) dirInfo ref, 2) tag table ref
    // Returns: New directory data or undefined on error
    // (PHP: a static method like the other write procs)
    public static function WriteTIFF(?ExifTool $self = null, ?array &$dirInfo = null, ?TagTable $tagTablePtr = null)
    {
        if (!$self) return 1;   // allow dummy access
        $buff = new ScalarRef('');
        $dirInfo['OutFile'] = $buff;
        if ($self->ProcessTIFF($dirInfo, $tagTablePtr) > 0) return $buff->value;
        return null;
    }

    //------------------------------------------------------------------------------
    // (PHP helpers)

    /** Perl `ref $x`: a file handle (stream resource), ScalarRef or RandomAccess, not a file name */
    private static function IsRef($x): bool
    {
        return is_resource($x) or is_object($x);
    }

    /** the file name of a file handle (Perl functions like chmod and utime also accept handles) */
    private static function FilePath($file)
    {
        return is_resource($file) ? (stream_get_meta_data($file)['uri'] ?? '') : $file;
    }

    /** Perl chown($uid, $gid, $file) (-1 = unchanged): true on success */
    private static function Chown($uid, $gid, $file): bool
    {
        $uid = Perl::int(Perl::num($uid));
        $gid = Perl::int(Perl::num($gid));
        return ($uid < 0 or @chown($file, $uid)) and ($gid < 0 or @chgrp($file, $gid));
    }

    /** Perl utime($atime, $mtime, $file) (times truncated to integer seconds): true on success */
    private static function Utime($atime, $mtime, $file): bool
    {
        if (!is_string($file) or $file === '') return false;
        $ok = @touch($file, Perl::int(Perl::num($mtime)), Perl::int(Perl::num($atime)));
        clearstatcache(true, $file);
        return $ok;
    }
}
