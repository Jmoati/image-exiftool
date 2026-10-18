# Porting rules (Perl ExifTool → PHP)

This repository is a **faithful, file-for-file port** of Image::ExifTool 13.59
(`~/exiftool`, git clone of github.com/exiftool/exiftool). It is not a rewrite:
keep ExifTool's structure, function names, argument order, variable names,
comments and control flow, so that upstream releases (every couple of weeks)
can be diffed and re-applied. Reading is done; **writing is being ported** (see
"Writing" below).

## Layout

| Perl | PHP |
|---|---|
| `lib/Image/ExifTool.pm` (package `Image::ExifTool`) | `src/ExifTool.php`, class `Image\ExifTool\ExifTool` |
| `lib/Image/ExifTool/Canon.pm` (package `Image::ExifTool::Canon`) | `src/Canon.php`, class `Image\ExifTool\Canon` |
| `XMP2.pl`, `XMPStruct.pl` (package `Image::ExifTool::XMP`) | same class `XMP` (traits in `src/XMP/*.php` if a file gets too big) |
| `Image::ExifTool::File::RandomAccess` | `src/File/RandomAccess.php` |
| `Image::ExifTool::7Z` | class `_7Z` (PHP class names can't start with a digit) |
| tag tables (`%Image::ExifTool::Canon::Main`…) | **generated** into `tables/Canon.php` by `tools/export-tables.pl` — never edit |
| Perl code inside tag tables | hand-written PHP in `translations/<Module>.txt`, inlined by the generator |
| `exiftool` script | `bin/exiftool` (+ `src/App/`) — options needed for parity tests |

Table names stay the Perl strings: `ExifTool::GetTagTable('Image::ExifTool::Canon::Main')`.

## Typing

No `declare(strict_types=1)` in ported code and no scalar type hints on values that come from the
data (Perl scalars are loosely typed: an `int $pos` receiving `"12"` or `4.0` from a computation
must not blow up). Type objects and arrays (`ExifTool $et`, `TagTable $tagTablePtr`,
`array &$dirInfo`), leave scalars untyped or `mixed`.

## Functions

- Perl sub `Foo` in package `Image::ExifTool::X` → `public static function Foo(...)` on class `X`,
  same name, same parameter order. Called as `X::Foo(...)` (or `self::Foo` inside `X`).
- Subs of package `Image::ExifTool` called as methods (`$et->Warn(...)`, first arg `$self`)
  → instance methods of `ExifTool`. Plain functions of that package (`Get16u`, `ReadValue`,
  `SetByteOrder`, `ConvertUnixTime`, `GetTagTable`, `AddTagToTable`, `IsInt`…) → **static**
  methods of `ExifTool`. A Perl call to an imported/unqualified ExifTool function (`Get16u($dataPt, 0)`)
  becomes `ExifTool::Get16u($dataPt, 0)`.
- Module functions taking `$et` first (`Image::ExifTool::Exif::ProcessExif($et, $dirInfo, $tagTablePtr)`)
  stay static: `Exif::ProcessExif(ExifTool $et, array &$dirInfo, TagTable $tagTablePtr)`.
- Trailing Perl hash-list arguments (`%parms`, `%opts`) → PHP variadic named arguments:
  `$et->HandleTag($tagTablePtr, $tag, $val, DataPt => $dataPt, Start => 4)` →
  `$et->HandleTag($tagTablePtr, $tag, $val, DataPt: $dataPt, Start: 4)` with signature
  `HandleTag(TagTable $tagTablePtr, $tag, $val, mixed ...$parms)` (`$parms` is then a string-keyed array).
- Output parameters (`\$rational`) → by-reference parameters.
- Code refs: `\&Image::ExifTool::Exif::ProcessExif` → `'Image\ExifTool\Exif::ProcessExif'` (string
  callable, loads lazily). Call them with `$proc($et, $dirInfo, $tagTablePtr)` (by-ref params work).
- `wantarray`-dependent subs: return what the callers actually use; if both are used, add a second
  method (`FooList`) or return an array and let scalar callers take the element Perl would give.
- Every `die`/`eval {}` → exceptions (`throw new \RuntimeException(...)`, `try/catch`).

## Data

| Perl | PHP |
|---|---|
| scalar / undef | `string\|int\|float\|null` |
| byte strings | PHP strings (byte-for-byte; never mb_* unless Perl uses character semantics) |
| `$$self{Model}`, `$$et{OPTIONS}{Unknown}` (ExifTool object) | `$self->Model`, `$et->OPTIONS['Unknown']` — dynamic properties; an unset one reads as `null` without warning and nested writes autovivify (`$et->VALUE[$tag] = $v`) |
| `$$tagInfo{Name}`, `$$tagInfo{Groups}{1}` | `$tagInfo->Name`, `$tagInfo->Groups[1]` (class `TagInfo`, same dynamic-property behaviour) |
| `$$tagTablePtr{$tagID}`, `$$tagTablePtr{GROUPS}{0}` | `$tagTablePtr[$tagID]`, `$tagTablePtr['GROUPS'][0]` (class `TagTable`, ArrayAccess, missing key = `null`, nested writes autovivify) |
| `keys %$tagTablePtr` / `TagTableKeys($t)` | `$tagTablePtr->keys()` / `ExifTool::TagTableKeys($t)` |
| any other hash ref (`\%dirInfo`, option hashes…) | PHP `array`; **`$dirInfo` is always passed by reference** (`array &$dirInfo`) |
| array ref | PHP list `array` (take `&` when the callee must modify the caller's array) |
| `DataPt => \$buff`, `$dataPt` | the string itself: `$$dataPt` → `$dataPt`, `length($$dataPt)` → `strlen($dataPt)`; use `string &$dataPt` only when the callee modifies the buffer for the caller |
| a stored value that is a scalar ref (binary data: `\$val`, `ref $v eq 'SCALAR'`) | `new ScalarRef($val)`, `$v instanceof ScalarRef`, `$$v` → `$v->value` |
| `ref $v eq 'ARRAY'` / `'HASH'` (values) | `is_array($v) && array_is_list($v)` / `is_array($v) && !array_is_list($v)` |
| hash keyed by a reference (`$$self{LIST_TAGS}{$tagInfo}`) | key `spl_object_id($tagInfo)` |
| `$a eq $b` (string compare) | `$a === $b` when both are surely strings, else `Perl::str($a) === Perl::str($b)` |
| `==`, `<`, arithmetic on values that may be non-numeric strings | `Perl::num($x)` first (PHP 8 throws `TypeError` on `"abc" * 1`) |
| `$a \|\| $b`, `$a // $b`, value of `$a && $b` | `$a ?: $b`, `$a ?? $b`, `$a ? $b : $a` (PHP `\|\|`/`&&` return bool) |
| `%` with possibly negative operands | `Perl::mod($a, $b)` (Perl takes the sign of the right operand) |
| `2 ** $n`, `$a / $b` | Perl always computes a float (`2**62` prints `4.61168601842739e+18`); PHP keeps integers: write `2.0 ** $n`, or `Perl::str()` a float result when its digits matter |
| bitwise ops (`>>`, `&`, `~`...) | Perl works on unsigned integers: `>>` of a negative value is a logical shift, `~0` is `0xFFFFFFFFFFFFFFFF`; mask first when the value may be negative (`($x & 0xFFFFFFFF) >> 8` for 32-bit data) |
| `defined $x` / `exists $h{k}` / `delete $h{k}` | `$x !== null` / `array_key_exists` or `isset` / `unset` |
| `local $x = ...` | save + `try { } finally { restore }` |

Perl truthiness matches PHP's for scalars (`""`, `"0"`, `0`, undef are false; `"0.0"` and `"00"` are
true in both). Beware arrays: an empty PHP array is false, a Perl array ref is always true.

## Built-ins

Use `Image\ExifTool\Perl` for everything whose PHP twin behaves differently:

- `sprintf` → **always** `Perl::sprintf($fmt, ...$args)` (Perl `%g`/`%e` exponent format,
  `%s` of floats with 15 significant digits, `%v`, `%*d`). Lists passed to sprintf are spread:
  `sprintf("%d %d", split(' ', $val))` → `Perl::sprintf('%d %d', ...Perl::split(' ', $val))`.
- stringification of a number → `Perl::str($x)` (`1e+15`, not `1.0E+15`). String interpolation
  `"$val mm"` is fine as long as `$val` is a string or an int; use `Perl::str()` for floats.
- `int()` → `Perl::int()`, `hex()`/`oct()` → `hexdec()`/`Perl::oct()`, `chr($n>255)` → `Perl::chr()`.
- `split` → `Perl::split($pattern, $str, $limit = 0)` (pattern is a PCRE with delimiters, or `' '` for
  awk mode); `join` → `implode`; `reverse` → `strrev` (scalar) / `array_reverse` (list).
- `unpack` / `pack` → `Perl::unpack($tmpl, $data)` / `Perl::pack($tmpl, ...$values)` with the
  **Perl** template syntax (returns a list; scalar-context `unpack` → `Perl::unpack(...)[0]`).
- `tr///` → `Perl::tr($str, $search, $replace, $flags)` (returns `[$newString, $count]`) or
  plain `strtr` when there are no ranges/flags; `uc`/`lc`/`ucfirst` → `strtoupper`/`strtolower`/`ucfirst`.
- `index`/`rindex` → `Perl::index`/`Perl::rindex` (return -1 when not found, like Perl).
- regexes: `$x =~ /re/i` → `preg_match('/re/i', $x, $m)`, `$1` → `$m[1]`; `/g` in list context →
  `preg_match_all`; `while ($x =~ /re/g)` / `pos()` → `preg_match` with `PREG_OFFSET_CAPTURE` and an
  explicit offset; `s///` → `preg_replace` (`/e` → `preg_replace_callback`); `\Q..\E` → `preg_quote`.
  Always add the `s` modifier where Perl uses it, and keep Perl's `$` (end or before final newline)
  semantics. Patterns on binary data must not use `u`.
- `sort` (default string sort) → `sort($a, SORT_STRING)`; `sort { $a <=> $b }` → `sort($a)` with numeric
  values or `usort`.

Write code of the tag tables (inverse conversions, WriteCheck...) runs where Perl turns any `-w`
warning into an error for the tag (writing "abc" to a numeric tag fails instead of writing 0):
use the warning-raising variants `Perl::numw()`, `strw()`, `hexw()`, `uv()`, `neg()`, `log()`,
`packw()` there (see the comments in `src/Perl.php`).

## Byte order and binary reads

`ExifTool::SetByteOrder('MM'|'II')`, `ExifTool::GetByteOrder()`, `ExifTool::ToggleByteOrder()`,
`ExifTool::Get8u/Get8s/Get16u/Get16s/Get32u/Get32s/Get64u/Get64s/GetFloat/GetDouble/GetRational64u...($dataPt, $pos)`,
`ExifTool::ReadValue($dataPt, $pos, $format, $count, $size, &$rational)`, `ExifTool::$formatSize`,
same semantics as Perl (global current byte order).

Files: `Image\ExifTool\File\RandomAccess` (`$raf->Read($buff, $len)` fills `$buff` by reference and
returns the byte count, `Seek($pos, $whence)`, `Tell()`, `ReadLine($buff)`, `Slurp()`…).

## Tag tables (generated)

`tools/export-tables.pl` loads the Perl modules, walks every tag table and writes
`tables/<Module>.php`. Data is copied as-is; tag info hashes become `TagInfo` objects when the table
is loaded. **Perl code found in tables is never translated by the generator**: each piece (string
expression or anonymous `sub`) gets an id (`sha1(context + source)`) and is looked up in
`translations/*.txt`; when missing, the generator emits a stub that throws `NotPorted`, which the
core reports like a failed Perl `eval` (a warning, the value is dropped).

Each context has a fixed closure signature. Perl variable names are kept:

| Context (where Perl evals it) | PHP closure |
|---|---|
| `Condition` (GetTagInfo) | `static function (ExifTool $self, $valPt, $format, $count)` — `$$valPt` → `$valPt` |
| `RawConv` string (FoundTag/HandleTag) | `static function ($val, ExifTool $self, $tag, ?TagInfo $tagInfo, &$priority, array &$grps, array $vals)` — Perl `@val` (Composite) → `$vals` |
| `ValueConv`, `PrintConv` strings, items of conversion arrays (GetValue) | `static function ($val, ExifTool $self, array &$vals, array $prt, array $raw, ?TagInfo $tagInfo, $tag)` — Perl `$val[0]` → `$vals[0]`; `$vals` by reference because Perl's PrintConv sees what the ValueConv did to `@val` (`ToFloat(@val)`) |
| anonymous `sub` used as `ValueConv`/`PrintConv`/`RawConv` | called as `$conv($val, $self)`: write `static function ($val, $et) { ... }` with the parameter names of the Perl `my (...) = @_` |
| anonymous `sub` in a PrintConv hash `OTHER` | called as `$other($val, $inv, $conv)` |
| `Hook` (ProcessBinaryData) | `static function (ExifTool $self, &$format, &$varSize, $size, $dataPt, $pos): void` |
| bracketed count of a `Format` (`int16u[$val{0}*2]`) | `static function (array $val, $size, $varSize, ExifTool $self)` — `$val{0}` → `$val[0]` |
| `SubDirectory` `Start` | `static function ($valuePtr = null, $val = null, $dirStart = null, $dataPt = null)` |
| `SubDirectory` `Base` | `static function ($start, $base = 0)` |
| `SubDirectory` `OffsetPt` | `static function ($valuePtr)` |
| `SubDirectory` `Validate` | `static function ($val = null, $dirData = null, $subdirStart = null, $size = null, $type = null)` |
| `IsOffset` (string) | `static function ($val, ExifTool $et)` |
| `ChangeBase` | `static function ($dirStart, $dataPos)` |
| `WrongBase` | `static function (ExifTool $self)` |
| `FixOffsets` | `static function (&$valuePtr, &$valEnd, $size, $tagID, $wFlag)` |

Callers always pass every positional argument of the signature (empty arrays / null when Perl had
nothing), as variables for by-reference parameters, e.g. `$conv($val, $self, $vals, $prt, $raw, $tagInfo, $tag)`.
Any `\Throwable` thrown by a closure (NotPorted, TypeError, DivisionByZeroError...) is a failed Perl eval.

The closure returns what the Perl expression evaluates to (make the implicit Perl result an explicit
`return`). Closures must be self-contained: refer to module code as `\Image\ExifTool\Canon::Foo()`,
to module data as `\Image\ExifTool\Canon::$bar`. An anonymous `sub` that closes over file lexicals
(`my %x` at file scope) receives them through `use ($x)`: the generator defines those variables
(dumped from Perl) in the generated file, under the Perl name.

Named code refs in tables (`\&Image::ExifTool::Canon::PrintLensID`) are emitted as string callables
`'Image\ExifTool\Canon::PrintLensID'`: the module port must provide that static method.

What else the generated data looks like:

- A slot that may hold Perl code (`Condition`, `RawConv`, `ValueConv`, `PrintConv`, `Hook`, `IsOffset`,
  SubDirectory `Start`/`Base`/`OffsetPt`/`Validate`/`ChangeBase`/`WrongBase`/`FixOffsets`) holds a
  `\Closure` — or a plain number/string when Perl had a constant. Test with `instanceof \Closure`.
- A conversion that was a Perl ARRAY ref is a `ConvList` (`$conv->list`); a lookup hash is a plain
  PHP array (which may itself have `OTHER` => callable, `BITMASK` => array, ...). So Perl
  `ref $conv eq 'ARRAY'` → `$conv instanceof ConvList`, `ref $conv eq 'HASH'` → `is_array($conv)`,
  `ref $conv eq 'CODE'` / a string expression → `is_callable($conv)` (Closure or string callable).
- `Format => 'int16u[$val{0}]'` keeps its Perl text and gets a sibling `FormatCount` closure
  computing the bracketed count (`Format => 'string[8]'` has none).
- A reference to a tag table inside data (`\%Image::ExifTool::Exif::Main`) is emitted as the table
  name: call `ExifTool::GetTagTable($name)`.
- Tag table entries become `TagInfo` objects (lists of them for conditional entries; a bare string is
  a tag name, as in Perl). XMP `Struct` field definitions are arrays, their fields arrays too.
- `tables/<Module>.php` returns `['load' => [...], 'tables' => [...]]`; `load` lists what the Perl
  module does when it is `require`d (`['AddCompositeTags', 'Image::ExifTool::Exif', 0]`).
  `ExifTool::GetTagTable()` loads the file of the table's module and runs its `load` actions once;
  a Perl `require Image::ExifTool::Canon;` becomes `ExifTool::RequireModule('Image::ExifTool::Canon')`.

### Module variables (generated)

`tables/vars/<Module>.php` holds class `Image\ExifTool\Vars\<Module>` with one public static property
per Perl package variable (`%Image::ExifTool::Canon::canonLensTypes` →
`Vars\Canon::$canonLensTypes`) and per file lexical closed over by the module's subs
(`my %coordConv` → `Vars\Canon::$coordConv`). Use them instead of copying data by hand. Values are
the state after the Perl module was loaded; runtime state of ExifTool.pm (`%allTables`,
`@tableOrder`, `%compositeID`, `@purgeTags`…) must start empty in the port. Anonymous subs inside
those variables are `null` (port them by hand in the module class).

### `translations/<Module>.txt` format

```
==== 3f2a9c1e PrintConv
static function (mixed $val, ExifTool $self, array $vals, array $prt, array $raw, ?TagInfo $tagInfo, ?string $tag) {
    return $self->ConvertDateTime($val);
}
```

`==== <id> <context>` starts an entry (ids come from `tables/_todo/<Module>.txt`, which lists every
untranslated piece with its Perl source); the body runs until the next `====` line. Lines starting
with `#` right after the header are comments. Write the namespace-relative names `ExifTool`, `TagInfo`,
`Perl`, `ScalarRef` unqualified (the generated file is in namespace `Image\ExifTool`). Run
`php tools/generate.php` (or `perl tools/export-tables.pl`) to regenerate, then `php -l tables/<Module>.php`.

## Verifying

`tools/parity.php` runs Perl ExifTool and the PHP port on the same files (`~/exiftool/t/images/*`
by default) with `-j -a -u -G1` (and `-n`) and reports, per file, missing / extra / different tags.
A module is done when its files match. Never "fix" a difference by special-casing the parity tool.

Known difference, kept on purpose: where Perl iterates a hash (`keys %h`) and the result depends
on the order, the port uses insertion order. The only visible case is `RestoreStruct` with
`-struct`: when an XMP structure that isn't a list gets several items (the points of a track in
`Geotag.gpx`, `Geotag.kml`, `Geotag.xml`), the item processed first goes into the structure and
the others give "Error 3 placing ... in structure or list". Perl's choice changes from one run to
the next (hash randomization), the port always keeps the first item of the file. The parity tool
fixes Perl's hash seed, so these 3 files differ with `-struct`.

## Writing

The same rules apply to the write code. Layout:

| Perl | PHP |
|---|---|
| `Writer.pl`, new values (`SetNewValue`, `GetNewValue*`, `SetNewValuesFromFile`, `ConvInv`, `CheckValue`, `InverseDateTime`, `IsOverwriting`...) | trait `Image\ExifTool\ExifTool\NewValues` (`src/ExifTool/NewValues.php`) |
| `Writer.pl`, writing files (`WriteInfo`, `WriteDirectory`, `WriteJPEG`, `WriteBinaryData`, trailers, file operations...) | trait `Image\ExifTool\ExifTool\Writer` (`src/ExifTool/Writer.php`) |
| `Writer.pl` subs already ported for reading (`InsertTagValues`, `GetAllTags`, `Set16u`...) | stay in `src/ExifTool.php` |
| `WriteExif.pl`, `WriteXMP.pl`, `WriteIPTC.pl`, `WritePhotoshop.pl`, `WritePNG.pl`, `WriteQuickTime.pl`, `WriteRIFF.pl`, `WritePDF.pl`, `WritePostScript.pl`, `WriteCanonRaw.pl` | a trait of the module class: `src/<Module>/Write<Name>.php` (`src/Exif/WriteExif.php`...) |
| write subs of the other modules (`WriteMIEGroup`, `WriteRAF`...) | in their module class |
| `Fixup.pm` (objects) | class `Image\ExifTool\Fixup` (`src/Fixup.php`), dynamic properties like `TagInfo` |
| `TagLookup.pm` | class `Image\ExifTool\TagLookup` (`src/TagLookup.php`), data in `Vars\TagLookup` (generated) |
| `Geotag.pm` | class `Image\ExifTool\Geotag` (`src/Geotag.php`) |
| write side of `Shift.pl` (`ApplyShift`, `ShiftTime`...) | `src/ExifTool/Shift.php` |
| writing parts of the `exiftool` script | `src/App/Application.php` |

Data:

- `$$self{NEW_VALUE}{$tagInfo}` → `$self->NEW_VALUE[spl_object_id($tagInfo)]`. A new value hash
  (`$nvHash`) is an object of class `Image\ExifTool\NewValue` (`src/NewValue.php`, dynamic
  properties like `TagInfo`: `$$nvHash{Value}` → `$nvHash->Value`, `$$nvHash{Next}` →
  `$nvHash->Next`), because Perl shares and mutates it through references.
- Output files: Perl writes to a file handle or to a scalar ref (`\$buff`). In PHP `$outfile` is a
  stream resource or a `ScalarRef` (its `->value` is the buffer), and `ExifTool::Write($outfile,
  ...$data)` appends to either. `WriteInfo($src, $dst)` takes, like Perl, a file name, a
  `ScalarRef` (data in memory) or a resource, and `$dst = null` to overwrite `$src`.
- A directory rebuilt in memory is a string, returned like in Perl (`undef` → `null` for "no
  change", `''` to delete it).
- dirInfo members holding a Perl scalar ref that the callee writes into are `ScalarRef`s
  (`OutFile` of a trailer or of WriteTIFF, `HeaderPtr` of DoProcessTIFF); `ExtendedXMP` (data
  returned by the XMP writer) is a string. `PREVIEW_INFO`, `HiddenData` and `LeicaTrailer` are
  arrays whose `Fixup` is a `Fixup` object.
- The write procs of Writer.pl named by the tables (`WriteBinaryData`, `WriteTIFF`,
  `CheckBinaryData`, `DummyWriteProc`) are static methods of `ExifTool` taking `$self` first, like
  the module ones: call them as `ExifTool::WriteBinaryData($et, $dirInfo, $tagTablePtr)`, and pass
  `\&Image::ExifTool::WriteTIFF` to `WriteDirectory()` as the string
  `'Image\ExifTool\ExifTool::WriteTIFF'` (it is compared with it, like Perl compares the code refs).
- Until every writer is ported, `WriteDirectory()` gives a "Not ported: <proc>" error when the
  write proc of a directory being edited (in `EDIT_DIRS`) doesn't exist yet, and leaves the other
  directories unchanged (like the procs which only rewrite edited directories); the trailers whose
  writer isn't ported (MIE) give a minor "Not ported" error.

Code in the tag tables (translated in `translations/<Module>.txt`, see above) has these contexts
(from the `#### eval` comments of Writer.pl and WriteExif.pl):

| Context | PHP closure |
|---|---|
| `PrintConvInv`, `ValueConvInv` string | `static function ($val, ExifTool $self, $wantGroup)` |
| `RawConvInv` string | `static function ($val, ExifTool $self, ?TagInfo $tagInfo)` |
| `WriteCheck` | `static function (ExifTool $self, TagInfo $tagInfo, $val)` (called with `$val` by reference: declare `&$val` if the expression changes it) |
| `DelCheck` | `static function (ExifTool $self, TagInfo $tagInfo, $wantGroup)` (called with SetNewValue's `$val` by reference as 4th argument: add `&$val = null` if the expression sets it) |
| `WriteCondition` | `static function (ExifTool $self)` |
| `WriteAlso` (each value of the hash) | `static function ($val, array $opts)` (called with `$opts` by reference, then `$self`: declare `array &$opts` if the expression changes `%opts`, add `?ExifTool $self = null` if it uses `$self`) |
| anonymous `sub` used as an inverse conversion | `static function ($val, $et)`, called as `$conv($val, $self)` |

A Perl expression that warns or dies in these contexts is reported by the caller (`$evalWarning`):
throw, or `trigger_error()` like in the reading code. An expression that sets `$@` to "\n" (an empty
warning) makes the tag be ignored without error: keep that behaviour.

`WRITE_PROC`, `CHECK_PROC` (tables), `WriteProc`, `CheckProc` (SubDirectory) and `WriteHook` are
generated as callables: the module port must provide the static method they name.

New values (`src/ExifTool/NewValues.php`):

- wantarray-dependent subs give their scalar-context value; the list-context twins are
  `SetNewValueList()` (`[numSet, error]`), `GetNewValueList()`, `CountNewValuesList()`
  (`[num, pseudo]`), `ReverseLookupList()` (`[value, multi]`), `EncodeBitsList()` (`[value, error]`).
  `TagLookup::FindTagInfo()` gives the list (all callers use it).
- SetNewValue options are named arguments (`$et->SetNewValue('XMP:Title', 'x', Replace: 1)`, or
  `...$opts` for a hash); the internal `CreateGroups` and `SetTags` options are `\ArrayObject`s
  shared and filled in place like the Perl hash refs, so `$nvHash->CreateGroups` is one too
  (`$nvHash->CreateGroups[$grp] ?? null` works). A structure value is an associative array, a
  list of values a list, binary data a `ScalarRef`; an empty structure (Perl `{ }`) is
  `XMP::EMPTY_STRUCT`, and an empty tag information hash a `new TagInfo()`, because an empty
  PHP array is a list.
- XMP structure definitions (`Struct` of a tagInfo or of a field) are arrays that `CheckStruct`,
  `AddNewStruct`, `SetPropertyPath` and `RegisterNamespace` change in place (new lang-alt and
  wildcard fields, cached `PropertyPath`): pass them by reference (`$tagInfo->Struct` itself). A
  field generated with a null `Struct` is a circular reference to the structure containing it.
- Perl's dummy call `&$writeProc()` (to autoload a write package) is `ExifTool::DummyWriteCall()`:
  it returns what the write proc returns when called without arguments, so a write proc must
  accept a call without arguments (`?ExifTool $et = null, ?array &$dirInfo = null...`, then
  `if (!$et) return 1;` like Perl's `$et or return 1`); one not ported yet, or whose signature
  requires the arguments, counts as 1 (all Perl write procs give 1 except `WriteKodakIFD`).
- Code a write package runs at file scope when Perl loads it (only `WriteQuickTime.pl`: the
  Preferred/Writable flags of the QuickTime ItemList and UserData tags) runs once, at the start
  of the subs Perl would autoload it for (`QuickTime::LoadWriteQuickTime()`), never in the
  generator (loading the package there would change the translation ids).
- A `CHECK_PROC` is called as `$checkProc($self, $tagInfo, $val, $convType)` with `$val` by
  reference: `static function CheckXxx(ExifTool $et, TagInfo $tagInfo, &$valPtr, $convType = null)`.
- Until everything is ported, a closure not translated, a CHECK_PROC or named code ref not
  ported, or a translated sub calling a method not ported yet, gives a "not ported (...)" error
  for the tag (like a failed eval) instead of stopping SetNewValue.
- `tests/new-values.php` compares the new values with Perl (`tests/new-values.pl`): results,
  warnings, verbose output and the new value hashes left in the object; `--images` also copies
  all the tags of every test image.

### Verifying the writing

- `tests/<name>.php` replays the write tests of ExifTool's `t/<Name>.t` against their expected
  output (`tests/TestLib.php` has `check()`, `writeInfo()`, `writeCheck()`, `testVerbose()`,
  `testCompare()` with its `nearEnough()` tolerance). Write the test files under `var/` (never in
  `~/exiftool/t`), and never write into `~/exiftool` or the user's own files.
- `tools/write-parity.php` writes the same changes with Perl and with the port and compares the
  output files byte for byte (then their tags, when the bytes differ). A writer is done when its
  `t/*.t` tests pass and its write-parity cases give identical files. Its Perl side runs with
  global warnings on (`$^W = 1`), like the exiftool script: a warning in the code of the tag
  tables is then an error for the tag, and the port always behaves that way.
- A test replay (`tests/writer.php` is the model) runs with `var/` as current directory and writes
  its files as `t/<Name>_<num>_failed.<ext>` (the expected outputs contain `Directory: t`); the
  tests needing writers not ported yet are in its `$skip` list (`--all` runs them anyway): remove
  them from the list as the writers get ported.
- A write-parity case is one line `[source file of t/images, [exiftool arguments]]` in the section
  of its format in `tools/write-parity.php` (`-TAG=VALUE`, `-TAG<=FILE`, `-o out.ext`, no `-o` to
  edit in place, `-v3`...); `php tools/write-parity.php --case=TEXT -v` runs some of them and shows
  what both tools printed. `MSG` means the same files but different results/warnings, `DIFF`
  different files.

