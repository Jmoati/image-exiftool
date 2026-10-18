<?php

// Port of ExifTool's t/TestLib.pm (1.25): utility routines to replay the tests of t/*.t against
// their expected output t/<TESTNAME>_<NUM>.out. The files written by the tests go to var/t/
// (never into ~/exiftool/t), and are erased when the test passes.
//
// check() also prints "ok   <name> <num>" or "FAIL <name> <num>".

require_once __DIR__.'/../vendor/autoload.php';

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;

// the t/ directory of Perl ExifTool (first argument of the test scripts, default ~/exiftool)
function testDir(): string
{
    static $dir;
    global $argv;

    return $dir ??= (realpath(rtrim($argv[1] ?? getenv('HOME').'/exiftool', '/')) ?: '/nonexistent').'/t';
}

// directory for the files written by the tests: 't', relative to var/ which is the current
// directory of the tests (Perl writes them into its t/, and the expected outputs contain
// "Directory: t")
function testOutDir(): string
{
    return 't';
}

testDir();  // (resolve the argument before changing directory)
@mkdir(dirname(__DIR__).'/var/t', 0777, true);
chdir(dirname(__DIR__).'/var');

//------------------------------------------------------------------------------
// Compare 2 binary files
// Inputs: 0) file name 1, 1) file name 2
// Returns: 1 if files are identical
function binaryCompare(string $file1, string $file2): bool
{
    $fh1 = @fopen($file1, 'rb');
    if (!$fh1) {
        return false;
    }
    $fh2 = @fopen($file2, 'rb');
    if (!$fh2) {
        fclose($fh1);

        return false;
    }
    $success = true;
    while (!feof($fh1)) {
        $buf1 = (string) fread($fh1, 65536);
        $buf2 = (string) fread($fh2, 65536);
        if ($buf1 !== $buf2) {
            $success = false;
            break;
        }
    }
    if ($success && '' !== (string) fread($fh2, 65536)) {
        $success = false;
    }
    fclose($fh1);
    fclose($fh2);

    return $success;
}

//------------------------------------------------------------------------------
// Compare 2 files and return true and erase the 2nd file if they are the same
// Inputs: 0) file1, 1) file2, 2) test number, 3) flag to not erase test file
// Returns: true if files are the same
function testCompare(string $stdfile, string $testfile, $testnum, $keep = null): bool
{
    $success = false;
    $linenum = 0;
    $fh1 = @fopen($stdfile, 'rb');
    if ($fh1) {
        $fh2 = @fopen($testfile, 'rb');
        if ($fh2) {
            $success = true;
            $line1 = $line2 = null;
            $retry = 0;
            for (;;) {
                if (1 !== $retry) {
                    $line1 = fgets($fh1);
                    if (false === $line1) {
                        $line1 = null;
                    }
                }
                if (null === $line1) {
                    break;
                }
                ++$linenum;
                if (2 !== $retry) {
                    $line2 = fgets($fh2);
                    if (false === $line2) {
                        $line2 = null;
                    }
                }
                $retry = 0;
                if (null !== $line2) {
                    if ($line1 === $line2) {
                        continue;
                    }
                    if (nearEnough($line1, $line2)) {
                        continue;
                    }
                    // (ignore IPTCDigest warning if Digest::MD5 isn't available: PHP always has md5)
                    // FileCreateDate may be extracted on Linux if File::StatX is available
                    if (preg_match('/File\s?Creat.*Date/', $line1)) {
                        if (!preg_match('/File\s?Creat.*Date/', $line2)) {
                            $retry = 2;
                            continue;
                        }
                    } elseif (preg_match('/File\s?Creat.*Date/', $line2)) {
                        $retry = 1;
                        continue;
                    }
                }
                $success = false;
                break;
            }
            if ($success) {
                // make sure there is nothing left in file2
                $line2 = fgets($fh2);
                if (false !== $line2 && '' !== $line2 && '0' !== $line2) {
                    ++$linenum;
                    $success = false;
                }
            }
            if (!$success) {
                fwrite(STDERR, "\n  Test $testnum differs beginning at line $linenum:\n");
                $l1 = null === $line1 || false === $line1 ? '(null)' : rtrim($line1, "\n");
                $l2 = null === $line2 || false === $line2 ? '(null)' : rtrim($line2, "\n");
                fwrite(STDERR, "    Test gave: \"$l2\"\n");
                fwrite(STDERR, "    Should be: \"$l1\"\n");
            }
            fclose($fh2);
        }
        fclose($fh1);
    }
    // erase .failed file if test was successful
    if ($success && !$keep) {
        @unlink($testfile);
    }

    return $success;
}

//------------------------------------------------------------------------------
// Return true if two test lines are close enough
// Inputs: 0) line1, 1) line2
// Returns: true if lines are similar enough to pass test
function nearEnough(string $line1, string $line2): bool
{
    // of course, the version number will change...
    if (preg_match('/^(.*ExifTool.*)\b\d{1,2}\.\d{2}\b(.*)/s', $line1, $m) &&
        ($line2 === $m[1].ExifTool::$VERSION.ExifTool::$RELEASE.$m[2] ||
         $line2 === $m[1].ExifTool::$VERSION.$m[2])) {
        return true;
    }

    // allow different FileModifyDate, FileAccessDate, FileCreateDate/FileInodeChangeDate
    // and FilePermissions (note that FileInodeChangeDate is FileCreateDate on Windows,
    // and FileCreateDate may or may not be extracted on Linux)
    if (preg_match('/(File\s?(Modif.*Date|Access\s?Date|Inode\s?Change\s?Date|Permissions))/', $line1, $m) &&
        (preg_match(ExifTool::Rx($m[1]), $line2) ||
         preg_match('/File\s?Creat.*Date/', $line2) && 'Linux' !== PHP_OS_FAMILY)) {
        return true;
    }

    // (allow CurrentIPTCDigest to be zero if Digest::MD5 isn't installed: PHP always has md5)

    // analyze every token in the line, and allow rounding
    // or format differences in floating point numbers
    $toks1 = Perl::split('/\s+/', $line1);
    $toks2 = Perl::split('/\s+/', $line2);
    $lenChanged = false;
    for ($i = 0; ; ++$i) {
        if ($i >= count($toks1) && $i >= count($toks2)) {
            return true;    // all tokens were OK
        }
        $tok1 = $toks1[$i] ?? null;
        $tok2 = $toks2[$i] ?? null;
        if (null === $tok1 || null === $tok2) {
            break;
        }
        if ($tok1 === $tok2) {
            continue;
        }
        // can't compare any more if either line was truncated (ie. ends with '[...]' or '[snip]')
        if (preg_match('/\[(\.{3}|snip)\]$/', $tok1) || preg_match('/\[(\.{3}|snip)\]$/', $tok2)) {
            if (preg_match('/^[-+]?\d+\./', $tok1) || preg_match('/^[-+]?\d+\./', $tok2)) {
                return true;    // check for float
            }

            return $lenChanged;
        }
        $rxTime = '/^(\d{2}:\d{2}:\d{2}(?:\.\d+)?)(Z|[-+]\d{2}:\d{2})$/i';
        // (Time::Local is always available: the branch ignoring times without it doesn't apply)
        if (preg_match($rxTime, $tok1, $m)) {
            // account for different timezones
            $time = $m[1];  // remove timezone
            // timezone may be wrong if writing date/time value in a different timezone
            if (preg_match($rxTime, $tok2, $m2) && $time === $m2[1]) {
                continue;
            }
            // date/time may be wrong too if converting GMT value to local time
            if (!($i && preg_match('/^\d{4}:\d{2}:\d{2}$/', $toks1[$i - 1]) &&
                        preg_match('/^\d{4}:\d{2}:\d{2}$/', $toks2[$i - 1]))) {
                break;
            }
            $tok1 = $toks1[$i - 1].' '.$tok1;   // add date to give date/time value
            $tok2 = $toks2[$i - 1].' '.$tok2;
            if (!nearTime($tok1, $tok2, $line1, $line2)) {
                break;
            }
        } elseif (preg_match('/^\d{4}:\d{2}:\d{2}$/', $tok1) && preg_match('/^\d{4}:\d{2}:\d{2}$/', $tok2) &&
                 isset($toks1[$i + 1], $toks2[$i + 1]) &&
                 preg_match($rxTime, $toks1[$i + 1]) && preg_match($rxTime, $toks2[$i + 1])) {
            // date may be different if timezone shifted into next day
            ++$i;
            $tok1 .= ' '.$toks1[$i];    // add time to give date/time value
            $tok2 .= ' '.$toks2[$i];
            if (!nearTime($tok1, $tok2, $line1, $line2)) {
                break;
            }
        } elseif (($tok1 = preg_replace('/(\.#)#*(e[-+]\#+)?/', '$1', $tok1, -1, $n1)) !== null && $n1 ||
                  ($tok2 = preg_replace('/(\.#)#*(e[-+]\#+)?/', '$1', $tok2, -1, $n2)) !== null && $n2) {
            // handle floating point numbers filtered by ExifTool test 29
            $tok2 = preg_replace('/(\.#)#*(e[-+]\#+)?/', '$1', $tok2);
            if ($tok1 !== $tok2) {
                break;
            }
        } else {
            // check to see if both tokens are floating point numbers (with decimal points!)
            if (preg_match('/([^\d.]+)$/', $tok1, $m)) {   // remove trailing units
                $tok1 = substr($tok1, 0, -strlen($m[1]));
                if (!str_ends_with($tok2, $m[1])) {
                    break;
                }
                $tok2 = substr($tok2, 0, -strlen($m[1]));
            }
            if (preg_match('/^(\d+:\d+:)/', $tok1, $m)) {  // remove leading HH:MM:
                $tok1 = substr($tok1, strlen($m[1]));
                if (!str_starts_with($tok2, $m[1])) {
                    break;
                }
                $tok2 = substr($tok2, strlen($m[1]));
            }
            if (str_starts_with($tok1, "'")) {           // remove leading quote
                $tok1 = substr($tok1, 1);
                if (!str_starts_with($tok2, "'")) {
                    break;
                }
                $tok2 = substr($tok2, 1);
            }
            if (!(ExifTool::IsFloat($tok1) && ExifTool::IsFloat($tok2))) {
                break;
            }
            $f1 = Perl::num($tok1);
            $f2 = Perl::num($tok2);
            if (0 == $f1 || 0 == $f2) {
                break;
            }
            // numbers are bad if not the same to 5 significant figures
            if (abs(fdiv($f1 - $f2, $f1 + $f2)) > 1e-5) {
                // (but allow last digit to be different due to round-off errors)
                $int1 = preg_replace('/[^0-9]/', '', $tok1);
                $int2 = preg_replace('/[^0-9]/', '', $tok2);
                $dlen = strlen($int1) - strlen($int2);
                if ($dlen > 0) {
                    $int2 .= str_repeat('0', $dlen);
                } elseif ($dlen < 0) {
                    $int1 .= str_repeat('0', -$dlen);
                }
                if (abs(Perl::num($int1) - Perl::num($int2)) > 1.00001) {
                    break;
                }
            }
        }
        // set flag if length changed
        if (strlen($tok1) !== strlen($tok2)) {
            $lenChanged = true;
        }
    }

    return false;
}

//------------------------------------------------------------------------------
// Check two time strings to see if they are the same
// Inputs: 0) time1, 1) time2, 2) line1, 3) line2
// Returns: true on success
function nearTime(string $tok1, string $tok2, string $line1, string $line2): bool
{
    $t1 = ExifTool::GetUnixTime($tok1, 'local');
    if (!$t1) {
        return false;
    }
    $t2 = ExifTool::GetUnixTime($tok2, 'local');
    if (!$t2) {
        return false;
    }
    $td = $t2 - $t1;
    // (allow for round-off errors in fractional seconds; the MirBSD leap-second patch doesn't apply)
    return abs($td) <= 0.0001;
}

//------------------------------------------------------------------------------
// Format value for printing
// Inputs: 0) value
// Returns: string for printing
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

//------------------------------------------------------------------------------
// Compare extracted information against a standard output file
// Inputs: 0) [optional] ExifTool object reference
//         1) tag hash reference, 2) test name, 3) test number
//         4) test number for comparison file (if different than this test)
//         5) top group number to test (2 by default)
// Returns: 1 if check passed
function check(...$args): bool
{
    $exifTool = ($args[0] ?? null) instanceof ExifTool ? array_shift($args) : null;
    [$info, $testname, $testnum, $stdnum, $topGroup] = $args + [null, null, null, null, null];
    if (!$info) {
        echo "FAIL $testname $testnum\n";

        return false;
    }
    if (null === $stdnum) {
        $stdnum = $testnum;
    }
    $testfile = testOutDir()."/{$testname}_$testnum.failed";
    $stdfile = testDir()."/{$testname}_$stdnum.out";

    // get a list of found tags
    if ($exifTool) {
        if ($exifTool->NO_SORT) {
            $tags = $exifTool->GetFoundTags();
        } else {
            // sort tags by group to make it a bit prettier
            $tags = $exifTool->GetTagList($info, 'Group0');
        }
    } else {
        $tags = array_map('strval', array_keys($info));
        sort($tags, SORT_STRING);
    }
    //
    // Write information to file (with filename "TESTNAME_#.failed")
    // (use one type of linefeed so this test works across platforms)
    //
    $out = '';
    foreach ($tags as $tag) {
        $val = formatValue($info[$tag] ?? null);
        if ($exifTool) {
            $groups = implode(', ', array_slice($exifTool->GetGroup($tag), 0, ($topGroup ?: 2) + 1));
            $tagID = preg_replace_callback('/[\0-\x1f\x7f-\xff]/', static fn ($m) => sprintf('\x%02x', ord($m[0])), Perl::str($exifTool->GetTagID($tag)));
            $desc = $exifTool->GetDescription($tag);
            $out .= "[$groups] $tagID - $desc: $val\x0a";
        } else {
            $out .= "$tag: $val\x0a";
        }
    }
    if (false === file_put_contents($testfile, $out)) {
        echo "FAIL $testname $testnum\n";

        return false;
    }
    //
    // Compare the output file to the output from the standard test (TESTNAME_#.out)
    //
    $ok = testCompare($stdfile, $testfile, $testnum);
    echo ($ok ? 'ok   ' : 'FAIL ')."$testname $testnum\n";

    return $ok;
}

//------------------------------------------------------------------------------
// Test writing feature by writing specified information to JPEG file
// Inputs: 0) list reference to lists of SetNewValue arguments
//         1) test name, 2) test number, 3) optional source file name,
//         4) true to only check tags which were written (or list ref for tags to check)
//         5) flag set if nothing is expected to change in the output file
//         6) true to ignore warnings
// Returns: 1 if check passed
// (PHP: each SetNewValue argument list is spread, string keys become the named options:
//  ['DateTimeOriginal', '1:2', 'Shift' => 1])
function writeCheck(array $writeInfo, string $testname, $testnum, $srcfile = null, $onlyWritten = null, $same = null, $ignore = null): bool
{
    if (!$srcfile) {
        $srcfile = testDir()."/images/$testname.jpg";
    }
    $ext = preg_match('/\.(.+?)$/', $srcfile, $m) ? $m[1] : '';
    $testfile = testOutDir()."/{$testname}_{$testnum}_failed.$ext";
    $exifTool = new ExifTool();
    $tags = [];
    if (is_array($onlyWritten)) {
        $tags = $onlyWritten;
        $onlyWritten = null;
    }
    foreach ($writeInfo as $args) {
        $exifTool->SetNewValue(...$args);
        if ($onlyWritten) {
            $tags[] = $args[0];
        }
    }
    @unlink($testfile);
    $ok = writeInfo($exifTool, $srcfile, $testfile, $same, $ignore);
    $info = $exifTool->ImageInfo($testfile, ['Duplicates' => 1, 'Unknown' => 1], ...$tags);
    $rtnVal = check($exifTool, $info, $testname, $testnum);
    if (!($ok && $rtnVal)) {
        return false;
    }
    @unlink($testfile);

    return true;
}

//------------------------------------------------------------------------------
// Call Image::ExifTool::WriteInfo with error checking
// Inputs: 0) ExifTool ref, 1) src file, 2) dst file, 3) true if nothing should change
//         4) true to ignore warnings
// Return: true on success
function writeInfo(ExifTool $exifTool, $src, $dst = null, $same = null, $ignore = null): bool
{
    // erase temporary file created by WriteInfo() if no destination file is given
    // (may be left over from previous crashed tests)
    if (null === $dst && is_string($src)) {
        @unlink("{$src}_exiftool_tmp");
    }
    $result = $exifTool->WriteInfo($src, $dst);
    $err = '';
    if ($result != ($same ? 2 : 1)) {
        $err .= "  Error: WriteInfo() returned $result\n";
    }
    $info = $exifTool->GetInfo('Warning', 'Error');
    $keys = array_map('strval', array_keys($info));
    sort($keys, SORT_STRING);
    foreach ($keys as $key) {
        if ($ignore && str_starts_with($key, 'Warning')) {
            continue;
        }
        $tag = ExifTool::GetTagName($key);
        $err .= "  $tag: ".Perl::str($info[$key])."\n";
    }
    if ('' === $err) {
        return true;
    }
    fwrite(STDERR, "\n$err");

    return false;
}

//------------------------------------------------------------------------------
// Test verbose output
// Inputs: 0) test name, 1) test number, 2) Input file, 3) verbose level
// Returns: true if test passed
function testVerbose(string $testname, $testnum, $infile, $verbose): bool
{
    $testfile = testOutDir()."/{$testname}_$testnum";
    // capture verbose output by redirecting STDOUT
    $tmp = @fopen("$testfile.tmp", 'wb');
    if (!$tmp) {
        return false;
    }
    (new ExifTool())->ImageInfo($infile, ['Verbose' => $verbose, 'TextOut' => $tmp]);
    fclose($tmp);
    // re-write output file to change newlines to be same as standard test file
    $lines = preg_split('/\r?\n/', (string) file_get_contents("$testfile.tmp"));
    if ('' === end($lines)) {
        array_pop($lines);
    }
    file_put_contents("$testfile.failed", $lines ? implode("\x0a", $lines)."\x0a" : '');
    @unlink("$testfile.tmp");
    $ok = testCompare(testDir()."/{$testname}_$testnum.out", "$testfile.failed", $testnum);
    echo ($ok ? 'ok   ' : 'FAIL ')."$testname $testnum\n";

    return $ok;
}

//------------------------------------------------------------------------------
// Run a test of a replay script unless it is in $skip (test number => what it needs), in which
// case it is skipped unless --all is given; $test returns true when it passed (check() prints
// the result, set $print for a test that doesn't call it). testsDone() prints the summary.
function runTest(string $testname, int $testnum, callable $test, array $skip = [], bool $print = false): void
{
    global $argv, $testsFailed, $testsSkipped;
    if (isset($skip[$testnum]) && !in_array('--all', $argv, true)) {
        echo "skip $testname $testnum (needs {$skip[$testnum]})\n";
        $testsSkipped = ($testsSkipped ?? 0) + 1;

        return;
    }
    try {
        $ok = $test();
    } catch (Throwable $e) {
        fwrite(STDERR, "  $testname $testnum: ".get_class($e).': '.$e->getMessage()."\n");
        $ok = false;
        $print = true;
    }
    if ($print) {
        echo ($ok ? 'ok   ' : 'FAIL ')."$testname $testnum\n";
    }
    if (!$ok) {
        $testsFailed = ($testsFailed ?? 0) + 1;
    }
}

// print the summary of the tests run by runTest(), return the exit status
function testsDone(): int
{
    global $testsFailed, $testsSkipped;
    echo $testsFailed ? "$testsFailed failed" : 'all passed', $testsSkipped ? " ($testsSkipped skipped)" : '', "\n";

    return $testsFailed ? 1 : 0;
}

// copy a test image of ~/exiftool/t/images to var/t/images and return its name relative to var/
// (for the read tests: their expected outputs contain "Directory: t/images")
function testImage(string $name): string
{
    $file = "t/images/$name";
    @mkdir('t/images', 0777, true);
    copy(testDir()."/images/$name", $file);

    return $file;
}
