<?php

// Split tables/_todo/*.txt (untranslated Perl code, deduplicated) into batches for translators:
// php tools/todo-batches.php [ENTRIES_PER_BATCH] -> tables/_todo/batches/NN-Module+Module.txt

declare(strict_types=1);

$size = (int) ($argv[1] ?? 220);
// modules another agent is translating right now: --exclude=Apple,PLIST
$exclude = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--exclude=')) {
        $exclude = explode(',', substr($arg, 10));
    }
}
$dir = __DIR__.'/../tables/_todo';
// modules translated first (most common files), the rest alphabetically
$priority = ['ExifTool', 'Exif', 'GPS', 'JPEG', 'JFIF', 'XMP', 'IPTC', 'ICC_Profile', 'Photoshop', 'MPF', 'PNG',
    'GIF', 'QuickTime', 'Apple', 'Canon', 'Nikon', 'Sony', 'Samsung', 'FujiFilm', 'Olympus', 'Panasonic',
    'Pentax', 'RIFF', 'Google', 'DJI', 'GoPro', 'MakerNotes', 'PrintIM', 'FlashPix', 'Jpeg2000', 'DNG'];
$files = glob("$dir/*.txt");
usort($files, function ($a, $b) use ($priority) {
    $pa = array_search(basename($a, '.txt'), $priority, true);
    $pb = array_search(basename($b, '.txt'), $priority, true);

    return [false === $pa ? 999 : $pa, $a] <=> [false === $pb ? 999 : $pb, $b];
});
$seen = [];
$byModule = [];
foreach ($files as $file) {
    $module = basename($file, '.txt');
    if (in_array($module, $exclude, true)) {
        continue;
    }
    foreach (preg_split('/^(?=====)/m', file_get_contents($file), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        if (!preg_match('/^==== (\w+)/', $entry, $m) || isset($seen[$m[1]])) {
            continue;
        }
        $seen[$m[1]] = true;
        $byModule[$module][] = rtrim($entry)."\n\n";
    }
}
@mkdir("$dir/batches");
array_map('unlink', glob("$dir/batches/*.txt"));
$n = 0;
$batch = [];
$mods = [];
$flush = function () use (&$batch, &$mods, &$n, $dir) {
    if (!$batch) {
        return;
    }
    $name = sprintf('%02d-%s', ++$n, implode('+', array_slice($mods, 0, 4)).(count($mods) > 4 ? '+more' : ''));
    file_put_contents("$dir/batches/$name.txt", implode('', $batch));
    echo "$name: ".count($batch)."\n";
    $batch = [];
    $mods = [];
};
foreach ($byModule as $module => $entries) {
    foreach ($entries as $entry) {
        if (count($batch) >= $size && end($mods) !== $module) {
            $flush();
        }
        $batch[] = $entry;
        if (end($mods) !== $module) {
            $mods[] = $module;
        }
    }
}
$flush();
echo count($seen)." entries\n";
