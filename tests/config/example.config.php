<?php

// PHP counterpart of part of ~/exiftool/config_files/example.config (see "Config files" in PORTING.md):
// the file returns what the Perl file assigns to %Image::ExifTool::UserDefined and its siblings.
// Conversions are closures with the signature of the generated tables (7 parameters for the
// string expressions of Perl, $vals replaces @val).

return [
    'Shortcuts' => [
        'MyShortcut' => ['exif:createdate', 'exposuretime', 'aperture'],
        'MyAlias' => 'FocalLengthIn35mmFormat',
    ],
    'UserDefined' => [
        'Image::ExifTool::Exif::Main' => [
            0xd000 => ['Name' => 'NewEXIFTag', 'Writable' => 'int16u', 'WriteGroup' => 'IFD0'],
        ],
        'Image::ExifTool::GPS::Main' => [
            0xd000 => ['Name' => 'GPSPitch', 'Writable' => 'rational64s'],
            0xd001 => ['Name' => 'GPSRoll', 'Writable' => 'rational64s'],
        ],
        'Image::ExifTool::IPTC::ApplicationRecord' => [
            160 => ['Name' => 'NewIPTCTag', 'Format' => 'string[0,16]'],
        ],
        'Image::ExifTool::XMP::xmp' => [
            'NewXMPxmpTag' => ['Groups' => [2 => 'Author']],
        ],
        'Image::ExifTool::XMP::Main' => [
            'xxx' => ['SubDirectory' => ['TagTable' => 'Image::ExifTool::UserDefined::xxx']],
        ],
        'Image::ExifTool::Composite' => [
            'FileExtension' => [
                'Require' => 'FileName',
                'ValueConv' => static function ($val, $self, array &$vals, array $prt, array $raw, $tagInfo, $tag) {
                    return preg_match('/\.([^.]*)$/', $val, $m) ? $m[1] : null;
                },
            ],
            'CircleOfConfusion' => [
                'Require' => 'ScaleFactor35efl',
                'Groups' => [2 => 'Camera'],
                'ValueConv' => static function ($val, $self, array &$vals, array $prt, array $raw, $tagInfo, $tag) {
                    return sqrt(24 * 24 + 36 * 36) / ($val * 1750);
                },
                'PrintConv' => static function ($val, $self, array &$vals, array $prt, array $raw, $tagInfo, $tag) {
                    return sprintf('%.3f mm', $val);
                },
            ],
        ],
    ],
    // %Image::ExifTool::UserDefined::xxx
    'Tables' => [
        'Image::ExifTool::UserDefined::xxx' => [
            'GROUPS' => [0 => 'XMP', 1 => 'XMP-xxx', 2 => 'Image'],
            'NAMESPACE' => ['xxx' => 'http://ns.myname.com/xxx/1.0/'],
            'WRITABLE' => 'string',
            'NewXMPxxxTag1' => ['Writable' => 'lang-alt'],
            'NewXMPxxxTag2' => ['Groups' => [2 => 'Author']],
            'NewXMPxxxTag3' => ['List' => 'Bag'],
        ],
    ],
    'Options' => ['Duplicates' => 1, 'Composite' => 1],
    'Lenses' => ['Sigma AF 10-20mm F4-5.6 EX DC', 'Tokina AF193-2 19-35mm f/3.5-4.5'],
];
