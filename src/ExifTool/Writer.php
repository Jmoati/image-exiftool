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
}
