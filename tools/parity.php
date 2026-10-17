<?php

/*
 * Parity bench: runs Perl exiftool and the PHP port (bin/exiftool) on the same files with the
 * same options, compares their JSON output tag by tag.
 *
 *   php tools/parity.php [OPTIONS] [FILE...]      (default files: ~/exiftool/t/images/*)
 *
 *   --opts='-j -a -u -G1'  options of both tools (default; -j is added if missing)
 *   --no-n                 skip the second pass, which adds -n
 *   --file=TEXT            only the files whose path contains TEXT
 *   --diff                 list the tags that differ, file by file
 *   --fast                 one PHP process for all the files (-stay_open) instead of one per file
 *                          (faster, but like Perl, a file may then show tags of modules loaded
 *                          for previous files: Perl reference outputs always come from one
 *                          process per file)
 *   --timeout=SECONDS      per file and per tool (default 60)
 *   --no-cache             run Perl again (outputs are cached in var/parity-cache/)
 *   --exiftool=DIR         Perl ExifTool (default ~/exiftool)
 *   --php=COMMAND          the PHP tool (default: php bin/exiftool)
 *
 * Ignored: FileAccessDate (reading the file changes it), ExifToolVersion when it differs.
 * Perl runs with a fixed hash seed (PERL_HASH_SEED=0, PERL_PERTURB_KEYS=0): with -struct, some
 * XMP structures (Geotag.gpx/.kml/.xml, XISF.xisf...) are built in hash order, which otherwise
 * changes from one Perl run to the next.
 * Exit status: 0 when every tag of every file matches.
 */

$root = dirname(__DIR__);
$opt = getopt('', ['opts:', 'no-n', 'file:', 'diff', 'fast', 'timeout:', 'no-cache', 'exiftool:', 'php:'], $rest);
$files = array_slice($argv, $rest);
$etDir = rtrim($opt['exiftool'] ?? getenv('HOME') . '/exiftool', '/');
$opts = preg_split('/\s+/', trim($opt['opts'] ?? '-j -a -u -G1'), -1, PREG_SPLIT_NO_EMPTY);
if (!preg_grep('/^-(j|json)$/i', $opts)) {
    array_unshift($opts, '-j');
}
$passes = [$opts];
if (!isset($opt['no-n']) && !in_array('-n', $opts, true)) {
    $passes[] = [...$opts, '-n'];
}
$timeout = (float) ($opt['timeout'] ?? 60);
$cwd = getcwd();
if (!$files) {
    $cwd = $etDir;
    $files = array_map(fn ($f) => 't/images/' . basename($f), glob("$etDir/t/images/*"));
}
if (isset($opt['file'])) {
    $files = array_values(array_filter($files, fn ($f) => str_contains($f, $opt['file'])));
}
sort($files, SORT_NATURAL | SORT_FLAG_CASE);

$perlCmd = ['perl', "$etDir/exiftool"];
$perlEnv = ['PERL_HASH_SEED' => '0', 'PERL_PERTURB_KEYS' => '0'];   // deterministic reference
$phpCmd = isset($opt['php']) ? preg_split('/\s+/', trim($opt['php'])) : [PHP_BINARY, "$root/bin/exiftool"];
$cacheDir = "$root/var/parity-cache";
@mkdir($cacheDir, 0777, true);
$perlVersion = trim((string) shell_exec(implode(' ', array_map('escapeshellarg', $perlCmd)) . ' -ver'));
$php = isset($opt['fast']) ? new StayOpen($phpCmd, $cwd) : null;

$totals = [];
$allMatch = true;
printf("%-34s", 'File');
foreach ($passes as $p => $pOpts) {
    printf(' | %-38.38s', $p ? '+ -n' : implode(' ', $pOpts));
}
printf("\n%-34s%s\n", '', str_repeat(' |  Perl Match  Miss Extra  Diff        ', count($passes)));

foreach ($files as $file) {
    $row = sprintf('%-34s', strlen($file) > 34 ? '...' . substr($file, -31) : $file);
    $details = '';
    foreach ($passes as $p => $pOpts) {
        $args = [...$pOpts, $file];

        // Perl output (cached)
        $path = $cwd . '/' . $file;
        $st = @stat($file[0] === '/' ? $file : $path) ?: [];
        $key = sha1(json_encode([$file, $cwd, $st['size'] ?? 0, $st['mtime'] ?? 0, $st['ctime'] ?? 0, $pOpts, $perlVersion, $perlEnv]));
        $cached = "$cacheDir/$key.json";
        if (!isset($opt['no-cache']) && is_file($cached)) {
            $perlOut = file_get_contents($cached);
        } else {
            [, $perlOut, , $failure] = runProcess([...$perlCmd, ...$args], $cwd, $timeout, $perlEnv);
            if ($failure) {
                $row .= sprintf(' | %-38s', "perl $failure");
                continue;
            }
            file_put_contents($cached, $perlOut);
        }

        // PHP output
        [$status, $phpOut, $phpErr, $failure] = $php ? $php->run($args, $timeout) : runProcess([...$phpCmd, ...$args], $cwd, $timeout);
        $perlTags = flatten(decodeJson($perlOut), $pOpts);
        $phpTags = null === $failure ? flatten(decodeJson($phpOut), $pOpts) : null;
        if (null === $failure && (null === $phpTags || 255 === $status || $status < 0)) {
            $failure = 'crash';
        }
        [$n, $lines] = compareTags($perlTags ?? [], $failure ? [] : $phpTags);
        $perlCount = $n['match'] + $n['miss'] + $n['diff'];   // (without the ignored tags)
        $t = &$totals[$p];
        $t['perl'] = ($t['perl'] ?? 0) + $perlCount;
        // PHP warnings/notices (they show porting bugs even when the tags match)
        preg_match_all('/^(?:PHP )?(?:Warning|Notice|Deprecated): .* on line \d+$/m', $phpErr, $mm);
        if ($mm[0]) {
            $t['notices'] = ($t['notices'] ?? 0) + 1;
            if (isset($opt['diff'])) {
                $details .= '    [php] ' . implode("\n    [php] ", array_slice(array_unique($mm[0]), 0, 5)) . "\n";
            }
        }
        if ($failure) {
            $t[$failure] = ($t[$failure] ?? 0) + 1;
            $allMatch = false;
            $msg = preg_match('/^.*(Fatal error|Uncaught).*$/m', $phpErr, $mm) ? $mm[0] : substr(trim($phpErr), 0, 300);
            $msg = trim(preg_replace('/\s+/', ' ', $msg));
            $row .= sprintf(' | %5d %-32s', $perlCount, strtoupper($failure));
            $details .= "    [$failure] " . ($msg ?: '(no message)') . "\n";
            continue;
        }
        foreach ($n as $k => $v) {
            $t[$k] = ($t[$k] ?? 0) + $v;
        }
        $t['files'] = ($t['files'] ?? 0) + (0 === $n['miss'] + $n['extra'] + $n['diff'] ? 1 : 0);
        if ($n['miss'] + $n['extra'] + $n['diff']) {
            $allMatch = false;
        }
        $row .= sprintf(' | %5d %5d %5d %5d %5d        ', $perlCount, $n['match'], $n['miss'], $n['extra'], $n['diff']);
        if (isset($opt['diff']) && $lines) {
            $details .= ($p ? "    with -n:\n" : '') . implode('', $lines);
        }
    }
    echo rtrim($row), "\n";
    if (isset($opt['diff']) || preg_match('/^    \[(crash|timeout)\]/m', $details)) {
        echo $details;
    }
}

echo "\n";
unset($t);
$grand = [0, 0];
foreach ($passes as $p => $pOpts) {
    $t = $totals[$p] ?? [];
    $perlTags = $t['perl'] ?? 0;
    $match = $t['match'] ?? 0;
    $grand[0] += $match;
    $grand[1] += $perlTags;
    printf("%-24s %d/%d files identical, %d crashes, %d timeouts, %d with PHP notices; tags: %d/%d match (%.1f%%), %d missing, %d extra, %d different\n",
        implode(' ', $pOpts), $t['files'] ?? 0, count($files), $t['crash'] ?? 0, $t['timeout'] ?? 0, $t['notices'] ?? 0,
        $match, $perlTags, $perlTags ? 100 * $match / $perlTags : 0, $t['miss'] ?? 0, $t['extra'] ?? 0, $t['diff'] ?? 0);
}
printf("SCORE: %d/%d Perl tags matched (%.2f%%)\n", $grand[0], $grand[1], $grand[1] ? 100 * $grand[0] / $grand[1] : 0);
exit($allMatch ? 0 : 1);

// JSON with numbers kept as written ("14.0" is not "14"): a number becomes "\0<number>"
function decodeJson(string $json): ?array
{
    if ('' === trim($json)) {
        return [];
    }
    $json = preg_replace_callback('/"(?:[^"\\\\]++|\\\\.)*+"|(-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)/s',
        static fn ($m) => isset($m[1]) ? '"\\u0000' . $m[1] . '"' : $m[0], $json);

    return json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
}

// one "GROUP:Tag" => value map for the file (-g output is nested by group)
function flatten(?array $data, array $opts): ?array
{
    if (null === $data || !array_is_list($data)) {
        return null;
    }
    $nested = false;
    foreach ($opts as $o) {
        if (preg_match('/^-(g|groupHeadings)[\d:]*$/', $o)) {
            $nested = true;
        } elseif (preg_match('/^-(G|groupNames)[\d:]*$/', $o)) {
            $nested = false;
        }
    }
    $tags = [];
    foreach ($data as $entry) {
        foreach ($entry as $key => $val) {
            if ($nested && is_array($val) && 'SourceFile' !== $key) {
                foreach ($val as $k => $v) {
                    $tags["$key:$k"] = $v;
                }
            } else {
                $tags[$key] = $val;
            }
        }
    }

    return $tags;
}

/** @return array{array{match: int, miss: int, extra: int, diff: int}, list<string>} */
function compareTags(array $perl, array $php): array
{
    $n = ['match' => 0, 'miss' => 0, 'extra' => 0, 'diff' => 0];
    $lines = [];
    foreach ($perl as $key => $val) {
        $name = substr(strrchr(":$key", ':'), 1);
        if ('FileAccessDate' === $name) {
            continue;
        }
        if (!array_key_exists($key, $php)) {
            ++$n['miss'];
            $lines[] = "    - $key: " . show($val) . "\n";
        } elseif ($php[$key] === $val || 'ExifToolVersion' === $name) {
            ++$n['match'];
        } else {
            ++$n['diff'];
            $lines[] = "    ~ $key: perl " . show($val) . "\n" . str_repeat(' ', strlen($key) + 8) . 'php  ' . show($php[$key]) . "\n";
        }
    }
    foreach ($php as $key => $val) {
        if (!array_key_exists($key, $perl) && 'FileAccessDate' !== substr(strrchr(":$key", ':'), 1)) {
            ++$n['extra'];
            $lines[] = "    + $key: " . show($val) . "\n";
        }
    }

    return [$n, $lines];
}

function show($val): string
{
    $s = is_string($val) && str_starts_with($val, "\0") ? substr($val, 1)
        : json_encode(unmark($val), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
}

function unmark($val)
{
    if (is_array($val)) {
        return array_map(unmark(...), $val);
    }

    return is_string($val) && str_starts_with($val, "\0") ? 0 + substr($val, 1) : $val;
}

/** @return array{?int, string, string, ?string} exit status, stdout, stderr, failure ('timeout' or null) */
function runProcess(array $cmd, string $cwd, float $timeout, array $env = []): array
{
    $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd, $env + getenv());
    fclose($pipes[0]);
    [$out, $err, $failure] = readUntil($pipes, $timeout, static fn () => false);   // (until 'eof')
    if ('timeout' === $failure) {
        proc_terminate($proc, 9);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    return 'timeout' === $failure ? [null, $out, $err, 'timeout'] : [$status, $out, $err, null];
}

/** @return array{string, string, ?string} stdout, stderr, failure ('timeout', 'eof' or null when $done) */
function readUntil(array $pipes, float $timeout, callable $done): array
{
    $out = $err = '';
    $deadline = microtime(true) + $timeout;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    while (!$done($out, $err)) {
        $read = array_filter([$pipes[1], $pipes[2]], static fn ($p) => !feof($p));
        if (!$read) {
            return [$out, $err, 'eof'];
        }
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return [$out, $err, 'timeout'];
        }
        $w = $e = null;
        if (stream_select($read, $w, $e, 0, (int) min(500000, $left * 1e6)) > 0) {
            foreach ($read as $p) {
                $data = (string) fread($p, 1 << 16);
                $p === $pipes[1] ? $out .= $data : $err .= $data;
            }
        }
    }

    return [$out, $err, null];
}

/** an exiftool kept open with -stay_open, restarted after a crash or a timeout */
final class StayOpen
{
    private $proc;
    private array $pipes = [];
    private int $n = 0;

    public function __construct(private array $cmd, private string $cwd)
    {
    }

    /** @return array{?int, string, string, ?string} exit status, stdout, stderr, failure ('crash', 'timeout' or null) */
    public function run(array $args, float $timeout): array
    {
        if (!$this->proc || !proc_get_status($this->proc)['running']) {
            $this->proc = proc_open([...$this->cmd, '-stay_open', 'True', '-@', '-'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->pipes, $this->cwd);
        }
        $ready = '{ready' . ++$this->n . '}';
        $lines = array_map(self::argument(...), [...$args, '-echo4', $ready . '${status}', "-execute$this->n"]);
        fwrite($this->pipes[0], implode("\n", $lines) . "\n");
        [$out, $err, $failure] = readUntil($this->pipes, $timeout,
            static fn ($out, $err) => str_contains($out, "$ready\n") && preg_match('/' . preg_quote($ready, '/') . '\d+\n/', $err));
        if ($failure) {
            proc_terminate($this->proc, 9);
            proc_close($this->proc);
            $this->proc = null;

            return [null, $out, $err, 'timeout' === $failure ? 'timeout' : 'crash'];
        }
        preg_match('/' . preg_quote($ready, '/') . '(\d+)\n/', $err, $m);

        return [(int) $m[1], strstr($out, "$ready\n", true), strstr($err, $ready, true), null];
    }

    // one line of the -@ argfile (C string when it can't be given as is)
    private static function argument(string $arg): string
    {
        return preg_match('/^[#\s]|[\r\n]/', $arg) ? '#[CSTR]' . strtr($arg, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) : $arg;
    }
}
