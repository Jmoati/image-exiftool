<?php

// Lint translation files: php tools/check-translations.php translations/Foo.txt [...]
// Each entry must be one PHP closure expression; reports the id of entries that don't compile.

declare(strict_types=1);

$files = array_slice($argv, 1) ?: glob(__DIR__.'/../translations/*.txt');
$entries = [];
foreach ($files as $file) {
    $id = null;
    foreach (file($file) as $n => $line) {
        if (preg_match('/^==== (\w+) (\S+)/', $line, $m)) {
            $id = $m[1];
            if (isset($entries[$id])) {
                echo "$file:".($n + 1).": duplicate id $id (also in {$entries[$id]['file']})\n";
            }
            $entries[$id] = ['file' => $file, 'line' => $n + 1, 'context' => $m[2], 'body' => ''];
            continue;
        }
        if (null !== $id && !('' === $entries[$id]['body'] && str_starts_with($line, '#'))) {
            $entries[$id]['body'] .= $line;
        }
    }
}
$bad = 0;
$tmp = tempnam(sys_get_temp_dir(), 'tr');
foreach ($entries as $id => $e) {
    $body = trim($e['body']);
    if ('' === $body) {
        echo "{$e['file']}:{$e['line']}: $id empty\n";
        ++$bad;
        continue;
    }
    if (!preg_match('/^(\(?static function|static fn)/', $body)) {
        echo "{$e['file']}:{$e['line']}: $id must be a static closure\n";
        ++$bad;
        continue;
    }
    file_put_contents($tmp, "<?php\nnamespace Image\\ExifTool;\n\$x = $body;\n");
    exec('php -l '.escapeshellarg($tmp).' 2>&1', $out, $status);
    if (0 !== $status) {
        $msg = preg_replace('/ in \S+ on line (\d+)/', ' (line $1)', implode(' ', array_filter($out, fn ($l) => !str_starts_with($l, 'Errors parsing'))));
        echo "{$e['file']}:{$e['line']}: $id {$e['context']}: $msg\n";
        ++$bad;
    }
    $out = [];
}
unlink($tmp);
echo count($entries).' entries, '.$bad." errors\n";
exit($bad ? 1 : 0);
