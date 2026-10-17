# image-exiftool

Pure PHP port of Phil Harvey's [ExifTool](https://exiftool.org/) (Perl `Image::ExifTool` 13.59):
read metadata from images, raw files, videos, audio and documents without Perl.

Reading only. On the 194 test files of the ExifTool distribution the port produces the same output
as the Perl tool for every tag (`exiftool -j -a -u -G1`, with and without `-n`), and with the options
used by `jmoati/exiftool` (`-charset UTF-8 -filesize# -all -c %+.6f -q -j -g -fast`).

## Usage

Command line (a subset of the Perl `exiftool` options, same output):

```bash
bin/exiftool -j -g photo.heic
bin/exiftool -s -G1 -n IMG_0001.CR3
bin/exiftool -stay_open True -@ -        # same protocol as the Perl tool
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
- `tables/` tag tables and module data, generated from the Perl library by
  `perl tools/export-tables.pl ~/exiftool/lib`
- `translations/` PHP translations of the Perl code embedded in the tag tables
- `tools/parity.php` compares the output with Perl ExifTool, `tests/` checks the Perl built-in
  emulation and the command line

Not ported: writing, `-lang` translations, geolocation, `-listx`, the HTML dump.

## License

ExifTool is Copyright 2003-2026 Phil Harvey. This port is free software; you can redistribute it
and/or modify it under the same terms as Perl itself (either the Perl Artistic License or GPL).
