<?php

// Check the PHP translations of the write code of the tag tables against Perl, part 2:
// evaluate the closures of the generated tables on the cases written by
// tools/check-write-code.pl (with Perl's results) and compare.
//
// Usage: php tools/check-write-code.php [CASES_FILE] [-v] [--id=ID,...] [--max=N]
//   CASES_FILE defaults to var/write-code-cases.jsonl; -v prints every differing case
//   (else the first --max per piece, default 3)
//
// Compared: the return value, the warning (CleanWarning'd, "\n" kept), the error (die), the
// $val left by WriteCheck/DelCheck, the %opts left by WriteAlso, the Warning tags added
// with $self->Warn() and the ExifTool members set by write hooks. A piece whose closure
// calls a function that is not ported yet is reported as pending, not as a difference.

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\NotPorted;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\TagInfo;

$file = __DIR__.'/../var/write-code-cases.jsonl';
$verbose = false;
$only = [];
$max = 3;
foreach (array_slice($argv, 1) as $arg) {
    if ('-v' === $arg) {
        $verbose = true;
    } elseif (str_starts_with($arg, '--id=')) {
        $only = array_flip(explode(',', substr($arg, 5)));
    } elseif (str_starts_with($arg, '--max=')) {
        $max = (int) substr($arg, 6);
    } else {
        $file = $arg;
    }
}

// the closures of the generated tables, by id
$closures = [];
foreach (glob(__DIR__.'/../tables/*.php') as $tableFile) {
    if ('_index.php' === basename($tableFile)) {
        continue;
    }
    $closures += (static function (string $__file): array {
        include $__file;
        $out = [];
        foreach (get_defined_vars() as $k => $v) {
            if (str_starts_with($k, 'f_') && $v instanceof \Closure) {
                $out[substr($k, 2)] = $v;
            }
        }

        return $out;
    })($tableFile);
}

// Perl::Enc of tools/check-write-code.pl
function enc(mixed $v): mixed
{
    if (null === $v) {
        return null;
    }
    if ($v instanceof ScalarRef) {
        return ['s' => enc($v->value)];
    }
    if (is_array($v)) {
        if ([] === $v || array_is_list($v)) {
            return ['a' => array_map('enc', $v)];
        }
        $h = [];
        foreach ($v as $k => $x) {
            $h[(string) $k] = enc($x);
        }
        ksort($h, SORT_STRING);

        return ['h' => $h];
    }
    if (is_object($v)) {
        return ['r' => get_class($v)];
    }
    $s = Perl::str($v);
    if (preg_match('/^[\x20-\x7e]*\z/', $s)) {
        return $s;
    }

    return ['x' => bin2hex($s)];
}

function dec(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if (isset($v['x'])) {
        return hex2bin($v['x']);
    }
    if (isset($v['u'])) {
        return hex2bin($v['u']);
    }
    if (array_key_exists('s', $v)) {
        return new ScalarRef(dec($v['s']));
    }
    if (isset($v['a'])) {
        return array_map('dec', $v['a']);
    }
    if (isset($v['h'])) {
        return array_map('dec', $v['h']);
    }

    return $v;
}

function canon(mixed $v): mixed
{
    if (is_array($v)) {
        // (a Perl character string: the same bytes in PHP)
        if (isset($v['u'])) {
            return ['x' => $v['u']];
        }
        if (isset($v['h']) && is_array($v['h'])) {
            ksort($v['h'], SORT_STRING);
        }
        foreach ($v as $k => $x) {
            $v[$k] = canon($x);
        }
    }

    return $v;
}

function tagInfoOf(?array $at): TagInfo
{
    if ($at && isset($at['table'])) {
        $table = ExifTool::GetTagTable($at['table']);
        $info = $table[dec($at['tagID'])] ?? null;
        if (is_array($info)) {
            $info = $info[$at['idx'] ?? 0] ?? null;
        }
        if ($info instanceof TagInfo) {
            return $info;
        }
    }

    return new TagInfo(['Name' => 'Test']);
}

// the closures run like in an eval site: Perl warn()s (ExifTool::PerlWarn) set $evalWarning
(new \ReflectionProperty(ExifTool::class, 'evalDepth'))->setValue(null, 1);

$stats = ['cases' => 0, 'same' => 0, 'diff' => 0, 'pending' => 0, 'missing' => 0];
$pieces = [];
$fh = fopen($file, 'r') or exit("$file: can't open\n");
while (false !== ($line = fgets($fh))) {
    $case = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $id = $case['id'];
    if ($only && !isset($only[$id])) {
        continue;
    }
    $ctx = $case['ctx'];
    $in = $case['in'];
    $p = &$pieces[$id];
    $p ??= ['ctx' => $ctx, 'where' => dec($in['where'] ?? ''), 'cases' => 0, 'diff' => 0, 'pending' => 0, 'shown' => 0, 'notices' => []];
    ++$p['cases'];
    ++$stats['cases'];
    $f = $closures[$id] ?? null;
    if (!$f) {
        ++$stats['missing'];
        $p['missing'] = true;
        unset($p);
        continue;
    }

    // run the closure like its caller does
    $self = new ExifTool();
    $state = $in['state'] ?? [];
    foreach ($state['opts'] ?? [] as $k => $v) {
        $self->Options($k, $v);
    }
    foreach ($state['self'] ?? [] as $k => $v) {
        $self->{$k} = $v;
    }
    ExifTool::SetByteOrder($state['order'] ?? 'MM');
    $tagInfo = tagInfoOf($in['at'] ?? null);
    $val = dec($in['val'] ?? null);
    $wantGroup = $in['wantGroup'] ?? null;
    $opts = ['Type' => 'ValueConv', 'Protected' => 2, 'AddValue' => null, 'DelValue' => null,
        'Shift' => null, 'Replace' => null, 'CreateGroups' => null, 'SetTags' => null];
    $notices = [];
    ExifTool::$evalWarning = null;
    set_error_handler(static function (int $no, string $str) use (&$notices): bool {
        if ($no & (E_USER_WARNING | E_USER_NOTICE)) {
            ExifTool::$evalWarning = $str;
        } else {
            $notices[] = $str;
        }

        return true;
    });
    $ret = $die = null;
    try {
        switch ($ctx) {
            case 'PrintConvInv':
            case 'ValueConvInv':
                $ret = $f($val, $self, $wantGroup);
                break;
            case 'RawConvInv':
                $ret = $f($val, $self, $tagInfo);
                break;
            case 'WriteCheck':
                $ret = $f($self, $tagInfo, $val);
                break;
            case 'DelCheck':
                $ret = $f($self, $tagInfo, $wantGroup, $val);
                break;
            case 'WriteCondition':
                $ret = $f($self);
                break;
            case 'WriteAlso':
                $ret = $f($val, $opts, $self);
                break;
            case 'code:OTHER':
                $conv = $tagInfo->PrintConv;
                if (!is_array($conv) || !isset($conv['OTHER'])) {
                    $conv = [];
                }
                $ret = $f($val, $in['inv'], $conv);
                break;
            case 'code':
                if (!empty($in['proc'])) {
                    $dirInfo = [];
                    $ret = $f($self, $dirInfo, null);
                } else {
                    $ret = $f($val, $self);
                }
                break;
            default:
                $ret = $f($val, $self);
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if ($e instanceof NotPorted) {
            $die = 'not ported';
        } elseif ($e instanceof \DivisionByZeroError) {
            $die = str_starts_with($msg, 'Modulo') ? 'Illegal modulus zero' : 'Illegal division by zero';
        } elseif ($e instanceof \Error && preg_match('/^Call to undefined (method|function) (\S+)\(\)/', $msg, $m)) {
            $die = "PENDING $m[2]";
        } elseif ($e instanceof \Error && preg_match('/^Class "(\S+)" not found/', $msg, $m)) {
            $die = "PENDING class $m[1]";
        } else {
            $die = $msg;
        }
    } finally {
        restore_error_handler();
    }
    $warn = ExifTool::$evalWarning;
    $mine = [
        'ret' => enc($ret),
        'warn' => enc(null === $warn ? null : ("\n" === $warn ? "\n" : ExifTool::CleanWarning($warn))),
        'die' => enc(null === $die ? null : ExifTool::CleanWarning($die)),
    ];
    $perl = ['ret' => $case['ret'], 'warn' => $case['warn'], 'die' => $case['die']];
    if ('WriteCheck' === $ctx || 'DelCheck' === $ctx) {
        $mine['val'] = enc($val);
        $perl['val'] = $case['val'];
    }
    if ('WriteAlso' === $ctx) {
        $mine['opts'] = enc($opts);
        $perl['opts'] = $case['opts'];
    }
    $selfWarn = [];
    $keys = array_filter(array_keys((array) $self->VALUE), static fn ($k) => preg_match('/^Warning\b/', (string) $k));
    sort($keys, SORT_STRING);
    foreach ($keys as $k) {
        $selfWarn[] = $self->VALUE[$k];
    }
    $mine['selfWarn'] = enc($selfWarn);
    $perl['selfWarn'] = $case['selfWarn'];
    $perlKeys = dec($case['selfKeys'] ?? null) ?: [];
    if ($perlKeys) {
        $mineKeys = [];
        foreach (array_keys($perlKeys) as $k) {
            $mineKeys[$k] = $self->{$k};
        }
        $mine['selfKeys'] = enc($mineKeys);
        $perl['selfKeys'] = $case['selfKeys'];
    }
    if ($notices) {
        $p['notices'][$notices[0]] = true;
    }
    if (null !== $die && str_starts_with($die, 'PENDING ')) {
        ++$stats['pending'];
        ++$p['pending'];
        $p['pendingFn'][substr($die, 8)] = true;
        unset($p);
        continue;
    }
    if (json_encode(canon($mine)) === json_encode(canon($perl))) {
        ++$stats['same'];
    } else {
        ++$stats['diff'];
        ++$p['diff'];
        if ($verbose || $p['shown'] < $max) {
            ++$p['shown'];
            $p['examples'][] = ['in' => $in['val'] ?? null, 'state' => $state, 'inv' => $in['inv'] ?? null,
                'perl' => $perl, 'php' => $mine];
        }
    }
    unset($p);
}

$byStatus = ['diff' => 0, 'pending' => 0, 'missing' => 0, 'ok' => 0];
ksort($pieces);
foreach ($pieces as $id => $p) {
    if (!empty($p['missing'])) {
        ++$byStatus['missing'];
        echo "MISSING $id {$p['ctx']} {$p['where']}: no closure in tables/\n";
        continue;
    }
    if ($p['diff']) {
        ++$byStatus['diff'];
        echo "DIFF $id {$p['ctx']} {$p['where']}: {$p['diff']}/{$p['cases']} cases differ\n";
        foreach ($p['examples'] ?? [] as $ex) {
            echo '    in: ', json_encode($ex['in']), $ex['state'] ? ' state: '.json_encode($ex['state']) : '',
                null !== $ex['inv'] ? ' inv: '.json_encode($ex['inv']) : '', "\n";
            foreach ($ex['perl'] as $k => $v) {
                if (json_encode(canon($v)) !== json_encode(canon($ex['php'][$k] ?? null))) {
                    echo "      $k: perl ", json_encode($v), "\n      $k: php  ", json_encode($ex['php'][$k] ?? null), "\n";
                }
            }
        }
    } elseif ($p['pending']) {
        ++$byStatus['pending'];
    } else {
        ++$byStatus['ok'];
    }
    if ($p['pending']) {
        echo "PENDING $id {$p['ctx']} {$p['where']}: {$p['pending']}/{$p['cases']} cases call ",
            implode(', ', array_keys($p['pendingFn'])), "\n";
    }
    if ($p['notices']) {
        echo "NOTICE $id {$p['ctx']} {$p['where']}: ", implode(' | ', array_slice(array_keys($p['notices']), 0, 3)), "\n";
    }
}
printf("%d pieces: %d ok, %d with differences, %d pending (only), %d missing\n", count($pieces),
    $byStatus['ok'], $byStatus['diff'], $byStatus['pending'], $byStatus['missing']);
printf("%d cases: %d same, %d different, %d pending (function not ported yet), %d without closure\n",
    $stats['cases'], $stats['same'], $stats['diff'], $stats['pending'], $stats['missing']);
