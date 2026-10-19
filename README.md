# image-exiftool

Pure PHP port of Phil Harvey's [ExifTool](https://exiftool.org/) (Perl `Image::ExifTool` 13.59):
read and write metadata in images, raw files, videos, audio and documents without Perl.

On the 194 test files of the ExifTool distribution the port produces the same output
as the Perl tool for every tag (`exiftool -j -a -u -G1`, with and without `-n`), and with the options
used by `jmoati/exiftool` (`-charset UTF-8 -filesize# -all -c %+.6f -q -j -g -fast`). Writing
(`tools/write-parity.php`) gives byte-identical files on ~1000 write cases, and the command line
(`-X`, `-h`, `-htmlDump`, `-diff`, `-plot`, `-geotag`...) matches on ~1160 more. Config files
(`-config`) are PHP files instead of Perl; `-use` of Perl modules is not supported.

## Usage

Command line (the Perl `exiftool` options, same output):

```bash
bin/exiftool -j -g photo.heic
bin/exiftool -s -G1 -n IMG_0001.CR3
bin/exiftool -stay_open True -@ -        # same protocol as the Perl tool
bin/exiftool -api geolocation photo.jpg  # nearest city from the GPS position (Geolocation* tags)
bin/exiftool -api geolocation=48.8566,2.3522,num=3   # no file needed for a position or a city
bin/exiftool -lang fr photo.jpg          # descriptions and values in French (18 languages)
```

From PHP, without spawning a process:

```php
use Image\ExifTool\App\Application;
use Image\ExifTool\ExifTool;

$data = Application::json(['-g', '-fast', 'photo.heic']);   // the `exiftool -j -g` structure

$et = new ExifTool();                                         // or the Image::ExifTool API
$info = $et->ImageInfo('photo.heic');
```

## Layout

- `src/` hand-ported Perl modules, one PHP class per module, same function names (see
  [PORTING.md](PORTING.md))
- `tables/` tag tables, module data and language translations, generated from the Perl library
  by `perl tools/export-tables.pl ~/exiftool/lib`
- `translations/` PHP translations of the Perl code embedded in the tag tables
- `tools/parity.php` compares the output with Perl ExifTool, `tests/` checks the Perl built-in
  emulation and the command line, and replays ExifTool's `t/Geolocation.t` and `t/Lang.t`

Not ported: writing (including the `Geolocate` tag), translations of geolocation names (ExifTool
ships them only with its alternate geolocation databases), `-listx`, the HTML dump.

## License

ExifTool is Copyright 2003-2026 Phil Harvey. This port is free software; you can redistribute it
and/or modify it under the same terms as Perl itself (either the Perl Artistic License or GPL).
The geolocation database `src/Geolocation.dat` comes with ExifTool and is based on data from
[geonames.org](https://www.geonames.org/) with a Creative Commons license.
