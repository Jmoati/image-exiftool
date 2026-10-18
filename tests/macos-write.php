<?php

// System-tag write parity: runs the same operations with Perl exiftool and bin/exiftool on two
// copies of a file under var/macos/ (never elsewhere), then compares output and read-back.
// Run: php tests/macos-write.php [~/exiftool]
$root = dirname(__DIR__);
$perl = ($argv[1] ?? getenv('HOME').'/exiftool');
$base = "$root/var/macos";
$src = "$perl/t/images/Canon.jpg";
$read = ['-FileCreateDate', '-FileModifyDate', '-XAttrQuarantine', '-XAttrMDItemWhereFroms', '-MDItemFSLabel',
    '-MDItemFinderComment', '-FilePermissions', '-MDItemFSCreationDate', '-FileName', '-Directory'];
$cases = [
    'create+perm' => ['-FileCreateDate=2010:05:06 07:08:09', '-FilePermissions#=0640'],
    'label+comment' => ['-MDItemFSLabel=3', '-MDItemFinderComment=hi "x" \\ y'],
    'label0+delcomment' => ['-MDItemFSLabel=0', '-MDItemFinderComment='],
    'fscreation tz' => ['-MDItemFSCreationDate=2001:02:03 04:05:06+02:00'],
    'create shift' => ['-FileCreateDate+=1:0:0'],
    'moddate' => ['-FileModifyDate=2012:01:02 03:04:05'],
    'moddate shift' => ['-FileModifyDate-=1:0:0'],
    'rename' => ['-FileName=renamed.jpg'],
    'newdir' => ['-Directory=sub'],
    'hardlink' => ['-HardLink=hl.jpg'],
    'symlink' => ['-SymLink=sl.jpg'],
    'testname' => ['-TestName=tn.jpg'],
    'rename+tag' => ['-FileName=r2.jpg', '-Comment=x'],
    'hardlink+tag' => ['-HardLink=hl2.jpg', '-Comment=x'],
    'quarantine del' => ['-XAttrQuarantine='],
    'wherefrom del' => ['-XAttrMDItemWhereFroms='],
];
$fail = 0;
foreach ($cases as $name => $args) {
    $out = [];
    foreach (['perl' => ['perl', "$perl/exiftool"], 'php' => ['php', "$root/bin/exiftool"]] as $k => $cmd) {
        $d = "$base/case-$k";
        exec('rm -rf '.escapeshellarg($d));
        mkdir("$d/sub", 0755, true);
        copy($src, "$d/f.jpg");
        exec('xattr -w com.apple.quarantine "0081;00000000;x;" '.escapeshellarg("$d/f.jpg"));
        $c = array_merge($cmd, ['-overwrite_original'], $args, ['f.jpg']);
        $r = shell_exec('cd '.escapeshellarg($d).' && '.implode(' ', array_map('escapeshellarg', $c)).' 2>&1');
        $files = shell_exec('cd '.escapeshellarg($d).' && ls -lT -i . sub 2>&1 | grep -v "^total" | awk \'{print $1,$2,$3,$NF}\'');
        $rb = '';
        foreach (glob("$d/{,sub/}*.jpg", GLOB_BRACE) as $f) {
            $rb .= basename($f).': '.shell_exec(implode(' ', array_map('escapeshellarg', array_merge(['perl', "$perl/exiftool"], $read, [$f]))).' 2>&1');
        }
        $out[$k] = preg_replace(['/\b\d{6,}\b/', '/'.date('Y:m:d').' \d\d:\d\d:\d\d/'], ['N', 'NOW'], str_replace($d, 'D', $r.$files.$rb));
    }
    $ok = $out['perl'] === $out['php'];
    echo ($ok ? 'ok   ' : 'FAIL ')."$name\n";
    if (!$ok) {
        $fail++;
        file_put_contents("$base/a.txt", $out['perl']);
        file_put_contents("$base/b.txt", $out['php']);
        echo shell_exec('diff '.escapeshellarg("$base/a.txt").' '.escapeshellarg("$base/b.txt"));
    }
}
exec('rm -rf '.escapeshellarg("$base/case-perl").' '.escapeshellarg("$base/case-php"));
exit($fail ? 1 : 0);
