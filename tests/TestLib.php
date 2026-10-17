<?php

// The parts of ExifTool's t/TestLib.pm used to replay its reading tests: check() writes the tags
// like TestLib and compares them with the expected output t/<TESTNAME>_<NUM>.out (exactly: no
// nearEnough() tolerance).

require_once __DIR__.'/../vendor/autoload.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;

// the t/ directory of Perl ExifTool (first argument of the test scripts, default ~/exiftool)
function testDir(): string
{
    global $argv;

    return rtrim($argv[1] ?? getenv('HOME').'/exiftool', '/').'/t';
}

function formatValue($val): string
{
    if ($val instanceof ScalarRef) {
        $v = Perl::str($val->value);

        return str_starts_with($v, 'Binary data') ? "($v)" : '(Binary data '.strlen($v).' bytes)';
    }
    if (is_array($val)) {
        $items = [];
        if (array_is_list($val)) {
            foreach ($val as $v) {
                $items[] = formatValue($v);
            }

            return '['.implode(',', $items).']';
        }
        foreach (ExifTool::OrderedKeys($val) as $k) {
            $items[] = $k.'='.formatValue($val[$k]);
        }

        return '{'.implode(',', $items).'}';
    }
    if (null === $val) {
        return '';
    }
    // make sure there are no linefeeds in output, translate unknown characters, remove NULL chars
    $str = preg_replace('/[\x01-\x1f\x7f]/', '.', strtr(Perl::str($val), "\x0a\x0d", ';;'));

    return str_replace("\0", '', $str);
}

function check(ExifTool $et, array $info, string $testname, int $testnum): bool
{
    $out = '';
    foreach ($et->GetTagList($info, 'Group0') as $tag) {
        $groups = implode(', ', array_slice($et->GetGroup($tag), 0, 3));
        $tagID = preg_replace_callback('/[\0-\x1f\x7f-\xff]/', static fn ($m) => sprintf('\x%02x', ord($m[0])), Perl::str($et->GetTagID($tag)));
        $out .= "[$groups] $tagID - {$et->GetDescription($tag)}: ".formatValue($info[$tag])."\n";
    }
    $ok = $out === file_get_contents(testDir()."/{$testname}_$testnum.out");
    echo ($ok ? 'ok   ' : 'FAIL ')."$testname $testnum\n";

    return $ok;
}
