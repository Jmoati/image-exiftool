# image-exiftool

Pure PHP port of Phil Harvey's [ExifTool](https://exiftool.org/) (Perl `Image::ExifTool` 13.59):
read metadata from images, videos, audio and documents without Perl.

Work in progress, reading only. See [PORTING.md](PORTING.md) for how the port is organized.

- `src/` hand-ported Perl modules (one PHP class per Perl module, same function names)
- `tables/` tag tables and module data, generated from the Perl library by
  `perl tools/export-tables.pl ~/exiftool/lib`
- `translations/` PHP translations of the Perl code embedded in the tag tables
- `bin/exiftool` command line, a subset of the Perl `exiftool` options

## License

ExifTool is Copyright 2003-2026 Phil Harvey. This port is free software; you can redistribute it
and/or modify it under the same terms as Perl itself (either the Perl Artistic License or GPL).
