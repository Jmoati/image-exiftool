# Porting rules (Perl ExifTool → PHP)

This repository is a **faithful, file-for-file port** of Image::ExifTool 13.59
(`~/exiftool`, git clone of github.com/exiftool/exiftool). It is not a rewrite:
keep ExifTool's structure, function names, argument order, variable names,
comments and control flow, so that upstream releases (every couple of weeks)
can be diffed and re-applied. Scope for now: **reading** metadata. Writing
(`Writer.pl`, `Write*.pl`, `*Inv` conversions) is out of scope.

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
| `Condition` (GetTagInfo) | `static function (ExifTool $self, ?string $valPt, ?string $format, ?int $count)` — `$$valPt` → `$valPt` |
| `RawConv` string (FoundTag/HandleTag) | `static function (mixed $val, ExifTool $self, ?string $tag, ?TagInfo $tagInfo, mixed &$priority, array &$grps, array $vals)` — Perl `@val` (Composite) → `$vals` |
| `ValueConv`, `PrintConv` strings, items of conversion arrays (GetValue) | `static function (mixed $val, ExifTool $self, array $vals, array $prt, array $raw, ?TagInfo $tagInfo, ?string $tag)` — Perl `$val[0]` → `$vals[0]` |
| anonymous `sub` used as `ValueConv`/`PrintConv`/`RawConv` | called as `$conv($val, $self)`: write `static function ($val, $et) { ... }` with the parameter names of the Perl `my (...) = @_` |
| anonymous `sub` in a PrintConv hash `OTHER` | called as `$other($val, $inv, $conv)` |
| `Hook` (ProcessBinaryData) | `static function (ExifTool $self, ?string &$format, int\|float &$varSize, int $size, string $dataPt, int $pos): void` |
| bracketed count of a `Format` (`int16u[$val{0}*2]`) | `static function (array $val, int $size, int\|float $varSize, ExifTool $self)` — `$val{0}` → `$val[0]` |
| `SubDirectory` `Start` | `static function (?int $valuePtr = null, mixed $val = null, ?int $dirStart = null, ?string $dataPt = null)` |
| `SubDirectory` `Base` | `static function (int $start, int $base = 0)` |
| `SubDirectory` `OffsetPt` | `static function (int $valuePtr)` |
| `SubDirectory` `Validate` | `static function (mixed $val = null, ?string $dirData = null, ?int $subdirStart = null, ?int $size = null, mixed $type = null)` |
| `IsOffset` (string) | `static function (mixed $val, ExifTool $et)` |
| `ChangeBase` | `static function (int $dirStart, int $dataPos)` |
| `WrongBase` | `static function (ExifTool $self)` |

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
