<?php

//------------------------------------------------------------------------------
// File:         Shift.pl
//
// Description:  ExifTool time shifting routines
//
// Revisions:    10/28/2005 - P. Harvey Created
//               03/13/2019 - PH Added single-argument form of ShiftTime()
//
// PHP port of Shift.pl (package Image::ExifTool), mixed into Image\ExifTool\ExifTool:
// GlobalTimeShift option, table code (Parrot) and writing (ApplyShift). DaysInMonth is
// in src/ExifTool.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool\ExifTool;

use Image\ExifTool\ConvList;
use Image\ExifTool\NewValue;
use Image\ExifTool\NotPorted;
use Image\ExifTool\Perl;

trait Shift
{
    //------------------------------------------------------------------------------
    // apply shift to value in new value hash
    // Inputs: 0) ExifTool ref, 1) shift type, 2) shift string, 3) raw date/time value,
    //         4) new value hash ref
    // Returns: error string or undef on success and updates value in new value hash
    public function ApplyShift($func, $shift, $val, NewValue $nvHash)
    {
        // get shift direction from first character in shift string
        $shift = Perl::str($shift);
        $pre = '+';
        if (preg_match('/^(\+|-)/', $shift, $m)) {
            $pre = $m[1];
            $shift = substr($shift, 1);
        }
        $dir = ($pre === '+') ? 1 : -1;
        $tagInfo = $nvHash->TagInfo;
        $tag = $tagInfo->Name;
        $nvHash->ShiftOffset ??= [];
        $shiftOffset = &$nvHash->ShiftOffset;

        // initialize handler for eval warnings
        self::EvalBegin();  // (local $SIG{'__WARN__'} = \&SetWarning)
        try {
            self::SetWarning(null);

            // shift is applied to ValueConv value, so we must ValueConv-Shift-ValueConvInv
            $err = null;
            foreach (['ValueConv', 'Shift', 'ValueConvInv'] as $type) {
                $evalErr = null;    // ($@)
                if ($type === 'Shift') {
                    //### eval ShiftXxx function
                    try {
                        $proc = "Shift$func";
                        $err = self::$proc($val, $shift, $dir, $shiftOffset);
                    } catch (\Throwable $e) {
                        $evalErr = self::EvalError($e);
                    }
                } elseif ($tagInfo->$type) {
                    $conv = $tagInfo->$type;
                    if ($conv instanceof ConvList or is_array($conv)) {
                        return "Can't handle $type for $tag in ApplyShift()";
                    }
                    if ($type === 'ValueConv' ? self::IsCodeRef($conv) : self::IsInvCodeRef($conv)) {
                        try {
                            $val = self::CallCode($conv, $val, $this);
                        } catch (NotPorted $e) {
                            $val = null;
                            $evalErr = self::EvalError($e);
                        }
                    } else {
                        //### eval ValueConv/ValueConvInv ($val, $self)
                        try {
                            if ($type === 'ValueConv') {
                                $vals = [];
                                $val = $conv($val, $this, $vals, [], [], $tagInfo, $tag);
                            } else {
                                $val = $conv($val, $this, null);
                            }
                        } catch (\Throwable $e) {
                            $val = null;
                            $evalErr = self::EvalError($e);
                        }
                    }
                } else {
                    continue;
                }
                // handle errors
                if ($err) return $err;
                if ($evalErr) self::SetWarning($evalErr);
                if (self::GetWarning()) return self::CleanWarning();
            }
        } finally {
            self::EvalEnd();
        }
        // update value in new value hash
        $nvHash->Value = [$val];
        return null;    // success
    }

    //------------------------------------------------------------------------------
    // Check date/time shift
    // Inputs: 0) shift type, 1) shift string (without sign)
    // Returns: updated shift string, or undef on error (and may update shift)
    public static function CheckShift($type, $shift)
    {
        $err = null;
        if ($type === 'Time') {
            if (!preg_match('/^(\+|-)/', Perl::str($shift), $m)) return 'No shift direction';
            $shift = substr($shift, 1);
            // do a test shift to validate the shift string
            $testTime = '2005:11:02 09:00:13.25-04:00';
            $err = self::ShiftTime($testTime, $shift, $m[1] === '+' ? 1 : -1);
        } else {
            $err = "Unknown shift type ($type)";
        }
        return $err;
    }

    //------------------------------------------------------------------------------
    // split times into corresponding components: YYYY mm dd HH MM SS tzh tzm
    // Inputs: 0) date/time or shift string 1) reference to list for returned components
    //         2) optional reference to list of time components (if shift string)
    // Returns: true on success
    // Returned components are 0-Y, 1-M, 2-D, 3-hr, 4-min, 5-sec, 6-tzhr, 7-tzmin
    // (PHP: lists are dense, with null for the undefined entries of the Perl arrays)
    public static function SplitTime($val, array &$vals, ?array $time = null)
    {
        $val = Perl::str($val);
        // insert zeros if missing in shift string
        if ($time !== null) {
            $val = preg_replace('/(^|[-+:\s]):/', '${1}0:', $val);
            $val = preg_replace('/:([:\s]|$)/', ':0$1', $val);
        }
        // change dashes to colons in date (for XMP dates)
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $val)) {
            $val = preg_replace('/^(\d{4})-(\d{2})-(\d{2})/', '$1:$2:$3', $val, 1);
            $val = strtr($val, 'T', ' ');   // change 'T' separator to ' '
        }
        // add space before timezone to split it into a separate word
        $val = preg_replace('/(\+|-)/', ' $1', $val, 1);
        $words = Perl::split(' ', $val);
        $err = 1;
        $v = [];
        for (;;) {
            $word = array_shift($words);
            if ($word === null) break;
            // split word into separate numbers (allow decimal points but no signs)
            preg_match_all('/(?=\d|\.\d)\d*(?:\.\d*)?/', $word, $mm);
            $vs = $mm[0];   // (@vals)
            if (!$vs) break;
            if (preg_match('/^(\+|-)/', $word, $m)) {
                // this is the timezone
                if (isset($v[6]) or count($vs) > 2) { $err = 1; break; }
                $sign = ($m[1] !== '-') ? 1 : -1;
                // apply sign to both minutes and seconds
                $v[6] = $sign * Perl::num(array_shift($vs));
                $v[7] = $sign * Perl::num(array_shift($vs) ?: 0);
            } elseif (($words and preg_match('/^\d+/', $words[0])) or // there is a time word to follow
                ($time === null and preg_match('/^\d{3}/', $vs[0])) or // first value is year (3 or more digits)
                ($time !== null and !isset($time[3]) and !isset($v[0]))) // we don't have a time
            {
                // this is a date (must come first)
                if ($v or count($vs) > 3) { $err = 1; break; }
                if ($time === null and count($vs) != 3) { $err = 1; break; }
                $v[2] = array_pop($vs);     // take day first if only one specified
                $v[1] = array_pop($vs) ?: 0;
                $v[0] = array_pop($vs) ?: 0;
            } else {
                // this is a time (can't come after timezone)
                if (isset($v[3]) or isset($v[6]) or count($vs) > 3) { $err = 1; break; }
                if ($time === null and count($vs) != 3 and count($vs) != 2) { $err = 1; break; }
                $v[3] = array_shift($vs);   // take hour first if only one specified
                $v[4] = array_shift($vs) ?: 0;
                $v[5] = array_shift($vs) ?: 0;
            }
            $err = 0;
        }
        if ($err or !$v) return 0;
        if ($time !== null) {
            // zero any required shift entries which aren't yet defined
            if (isset($time[0]) and !isset($v[0])) $v[0] = $v[1] = $v[2] = 0;
            if (isset($time[3]) and !isset($v[3])) $v[3] = $v[4] = $v[5] = 0;
            if (isset($time[6]) and !isset($v[6])) $v[6] = $v[7] = 0;
        }
        // return split time components
        $vals = [];
        for ($i = 0, $n = max(array_keys($v)) + 1; $i < $n; ++$i) $vals[] = $v[$i] ?? null;
        return 1;
    }

    //------------------------------------------------------------------------------
    // shift date/time by components
    // Inputs: 0) split date/time list ref, 1) split shift list ref,
    //         2) shift direction, 3) reference to output list of shifted components
    //         4) number of decimal points in seconds
    //         5) reference to return time difference due to rounding
    // Returns: error string or undef on success
    public static function ShiftComponents(array $time, array $shift, $dir, array &$toTime, $dec, &$rndPt = null)
    {
        // min/max for Y, M, D, h, m, s
        $min = [0, 1, 1, 0, 0, 0];
        $max = [10000, 12, 28, 24, 60, 60];
        //
        // apply the shift
        //
        $c = 0;
        for ($i = 0; $i < count($time); ++$i) {
            $v = Perl::num($time[$i] ?? null) + $dir * Perl::num($shift[$i] ?? null) + $c;
            // handle fractional values by propagating remainders downwards
            if ($v != Perl::int($v) and $i < 5) {
                $iv = Perl::int($v);
                $c = ($v - $iv) * $max[$i + 1];
                $v = $iv;
            } else {
                $c = 0;
            }
            $toTime[$i] = $v;
        }
        // round off seconds to the required number of decimal points
        $sec = $toTime[5] ?? null;
        if ($sec !== null and $sec != Perl::int($sec)) {
            $mult = 10 ** $dec;
            $rndSec = Perl::int($sec * $mult + 0.5 * ($sec <=> 0)) / $mult;
            $rndPt = $sec - $rndSec;    // (callers always pass $rndPt)
            $toTime[5] = $rndSec;
        }
        //
        // handle overflows, starting with least significant number first (seconds)
        //
        $c = 0;
        for ($i = 5; $i >= 0; $i--) {
            if (!isset($time[$i])) {
                $c = 0;
                continue;
            }
            // apply shift and adjust for previous overflow
            $v = $toTime[$i] + $c;
            $c = 0; // set carry to zero
            // adjust for over/underflow
            [$mn, $mx] = [$min[$i], $max[$i]];
            if ($v < $mn) {
                if ($i == 2) {  // 2 = day of month
                    do {
                        // add number of days in previous month
                        --$c;
                        $mon = $toTime[$i - 1] + $c;
                        $v += self::DaysInMonth($mon, $toTime[$i - 2]);
                    } while ($v < 1);
                } else {
                    $fc = ($v - $mn) / $mx;
                    // carry ($c) must be largest integer equal to or less than $fc
                    $c = Perl::int($fc);
                    if ($c > $fc) --$c;
                    $v -= $c * $mx;
                }
            } elseif ($v >= $mx + $mn) {
                if ($i == 2) {
                    for (;;) {
                        // test against number of days in current month
                        $mon = $toTime[$i - 1] + $c;
                        $days = self::DaysInMonth($mon, $toTime[$i - 2]);
                        if ($v <= $days) break;
                        $v -= $days;
                        ++$c;
                        if ($v <= 28) break;
                    }
                } else {
                    $fc = ($v - $mx - $mn) / $mx;
                    // carry ($c) must be smallest integer greater than $fc
                    $c = Perl::int($fc);
                    if ($c <= $fc) ++$c;
                    $v -= $c * $mx;
                }
            }
            $toTime[$i] = $v;   // save the new value
        }
        // handle overflows in timezone
        if (isset($toTime[6])) {
            $m = $toTime[6] * 60 + $toTime[7];
            $m += 0.5 * ($m <=> 0);     // avoid round-off errors
            $toTime[6] = Perl::int($m / 60);
            $toTime[7] = Perl::int($m - $toTime[6] * 60);
        }
        return null;    // success
    }

    //------------------------------------------------------------------------------
    // Shift an integer or floating-point number
    // Inputs: 0) date/time string, 1) shift string, 2) shift direction (+1 or -1)
    //         3) (unused)
    // Returns: undef and updates input value
    public static function ShiftNumber(&$val, $shift, $dir, $unused = null)
    {
        $val = Perl::num($val) + Perl::num($shift) * $dir;  // return shifted value
        return null;    // success!
    }

    //------------------------------------------------------------------------------
    // Shift date/time string
    // Inputs: 0) date/time string, 1) shift string, 2) shift direction (+1 or -1),
    //            or 0 or undef to take shift direction from sign of shift,
    //         3) reference to ShiftOffset hash (with Date, DateTime, Time, Timezone keys)
    //   or    0) shift string (and operates on $_)
    // Returns: error string or undef on success and date/time string is updated
    // (PHP: the single-argument form, which works on $_, has no PHP equivalent: the value
    //  is always the first argument, updated in place)
    public static function ShiftTime(&$dateTime, $shift = null, $dir = null, ?array &$shiftOffset = null)
    {
        $time = $shiftArr = $toTime = [];   // (@time, @shift, @toTime)
        $mode = $needShiftOffset = $dec = null;
        $val = Perl::str($dateTime);
        $shift = Perl::str($shift);

        if (!$dir) {
            $dir = 1;
            if (preg_match('/^(\+|-)/', $shift, $m)) {
                $shift = substr($shift, 1);
                if ($m[1] === '-') $dir = -1;
            }
        }
        //
        // figure out what we are dealing with (time, date or date/time)
        //
        if (!self::SplitTime($val, $time)) return "Invalid time string ($val)";
        if (isset($time[0])) {
            if (Perl::str($time[0]) === '0000') return "Can't shift from year 0000";
            $mode = isset($time[3]) ? 'DateTime' : 'Date';
        } elseif (isset($time[3])) {
            $mode = 'Time';
        } else {
            $mode = '';
        }
        // get number of digits after the seconds decimal point
        if (isset($time[5]) and preg_match('/\.(\d+)/', Perl::str($time[5]), $m)) {
            $dec = strlen($m[1]);
        } else {
            $dec = 0;
        }
        if ($shiftOffset !== null) {
            if (!isset($shiftOffset[$mode])) $needShiftOffset = 1;
            if (isset($time[6]) and !isset($shiftOffset['Timezone'])) $needShiftOffset = 1;
        } else {
            $needShiftOffset = 1;
        }
        if ($needShiftOffset) {
            //
            // apply date/time shift the hard way
            //
            if (!self::SplitTime($shift, $shiftArr, $time)) return "Invalid shift string ($shift)";

            // change 'Z' timezone to '+00:00' only if necessary
            if (count($shiftArr) > 6 and count($time) <= 6 and preg_match('/Z$/', $val)) {
                $val = preg_replace('/Z$/', '+00:00', $val, 1);
                $time = array_pad($time, 6, null);
                $time[6] = $time[7] = 0;
            }
            $rndDiff = null;
            $err = self::ShiftComponents($time, $shiftArr, $dir, $toTime, $dec, $rndDiff);
            if ($err) return $err;
            //
            // calculate and save the shift offsets for next time
            //
            if ($shiftOffset !== null) {
                if (isset($time[0]) or isset($time[3])) {
                    $tm1 = [0, 0, 0, 1, 0, 2000];
                    $tm2 = [0, 0, 0, 1, 0, 2000];
                    if (isset($time[0])) {
                        [$tm1[3], $tm1[4], $tm1[5]] = [$time[2], $time[1], $time[0]];
                        [$tm2[3], $tm2[4], $tm2[5]] = [$toTime[2], $toTime[1], $toTime[0]];
                        $tm1[4] = Perl::num($tm1[4]) - 1;   // month should start from 0
                        $tm2[4] = Perl::num($tm2[4]) - 1;
                    }
                    $diff = 0;
                    if (isset($time[3])) {
                        [$tm1[0], $tm1[1], $tm1[2]] = [Perl::num($time[5]), $time[4], $time[3]];
                        [$tm2[0], $tm2[1], $tm2[2]] = [$toTime[5], $toTime[4], $toTime[3]];
                        // handle fractional seconds separately
                        $diff = $tm2[0] - Perl::int($tm2[0]) - ($tm1[0] - Perl::int($tm1[0]));
                        if ($rndDiff !== null) $diff += $rndDiff;   // un-do rounding
                        $tm1[0] = Perl::int($tm1[0]);
                        $tm2[0] = Perl::int($tm2[0]);
                    }
                    // not a problem if we failed here since we'll just try again next time,
                    // so don't return error message
                    try {
                        $diff += self::TimeLocal_timegm(...$tm2) - self::TimeLocal_timegm(...$tm1);
                        $mode = isset($time[0]) ? (isset($time[3]) ? 'DateTime' : 'Date') : 'Time';
                        $shiftOffset[$mode] = $diff;
                    } catch (\Throwable) {
                    }
                }
                if (isset($time[6])) {
                    $shiftOffset['Timezone'] = ($toTime[6] - $time[6]) * 60 + $toTime[7] - $time[7];
                }
            }

        } else {
            //
            // apply shift from previously calculated offsets
            //
            if (!empty($shiftOffset['Timezone']) and count($time) <= 6 and preg_match('/Z$/', $val)) {
                // change 'Z' timezone to '+00:00' only if necessary
                $val = preg_replace('/Z$/', '+00:00', $val, 1);
                $time = array_pad($time, 6, null);
                $time[6] = $time[7] = 0;
            }
            // apply the previous date/time shift if necessary
            if ($mode) {
                $tm = [0, 0, 0, 1, 0, 2000];
                if (isset($time[0])) {
                    [$tm[3], $tm[4], $tm[5]] = [$time[2], $time[1], $time[0]];
                    $tm[4] = Perl::num($tm[4]) - 1;     // month should start from 0
                }
                if (isset($time[3])) [$tm[0], $tm[1], $tm[2]] = [$time[5], $time[4], $time[3]];
                // save fractional seconds
                $frac = Perl::num($tm[0]) - Perl::int($tm[0]);
                $tm[0] = Perl::int($tm[0]);
                try {
                    $t = self::TimeLocal_timegm(...$tm) + $frac;    // (Perl: my $tm)
                } catch (\Throwable $e) {
                    return self::CleanWarning($e->getMessage());
                }
                $t += $shiftOffset[$mode];  // apply the shift
                // save fractional seconds in shifted time
                $frac = $t - Perl::int($t);
                if ($frac) {
                    $t = Perl::int($t);
                    // must account for any rounding that could occur
                    if ($frac + 0.5 * 10 ** (-$dec) >= 1) {
                        ++$t;
                        $frac = 0;
                    }
                }
                $tm = self::PerlGmtime($t);
                $toTime = array_reverse(array_slice($tm, 0, 6));
                $toTime[0] += 1900;
                ++$toTime[1];
                $toTime[5] += $frac;    // add the fractional seconds back in
            }
            // apply the previous timezone shift if necessary
            if (isset($time[6])) {
                $m = $time[6] * 60 + $time[7];
                $m += $shiftOffset['Timezone'] ?? 0;
                $m += 0.5 * ($m <=> 0);     // avoid round-off errors
                $toTime = array_pad($toTime, 6, null);
                $toTime[6] = Perl::int($m / 60);
                $toTime[7] = Perl::int($m - $toTime[6] * 60);
            }
        }
        //
        // insert shifted time components back into original string
        //
        $p = 0;     // (pos($val))
        for ($i = 0; $i < count($toTime); ++$i) {
            if (!(isset($time[$i]) and isset($toTime[$i]))) continue;
            if ($i != 6) {  // not timezone hours
                if (!preg_match('/((?=\d|\.\d)\d*(\.\d*)?)/', $val, $m, PREG_OFFSET_CAPTURE, $p)) break;
                $p = $m[0][1] + strlen($m[0][0]);
                if (Perl::num($toTime[$i]) == Perl::num($time[$i])) continue;
                $v = $m[1][0];          // value
                $d = $m[2][0] ?? null;  // decimal part of value
                $s = '';                // no sign
            } else {
                if (Perl::num($time[$i]) == Perl::num($toTime[$i]) and Perl::num($time[$i + 1]) == Perl::num($toTime[$i + 1])) break;
                if (!preg_match('/((?:\+|-)(?=\d|\.\d)\d*(\.\d*)?)/', $val, $m, PREG_OFFSET_CAPTURE, $p)) break;
                $p = $m[0][1] + strlen($m[0][0]);
                $v = $m[1][0];
                $d = $m[2][0] ?? null;
                if ($toTime[6] >= 0 and $toTime[7] >= 0) {
                    $s = '+';
                } else {
                    $s = '-';
                    $toTime[6] = -$toTime[6];
                    $toTime[7] = -$toTime[7];
                }
            }
            $nv = $toTime[$i];
            $pos = $p;
            $len = strlen($v);
            $sig = $len - strlen($s);
            $dec = $d ? strlen($d) - 1 : 0;
            $newNum = Perl::sprintf($dec ? "$s%0$sig.{$dec}f" : "$s%0{$sig}d", $nv);
            $val = substr_replace($val, $newNum, $pos - $len, $len);
            $p = $pos + strlen($newNum) - $len;
        }
        $dateTime = $val;   // return shifted value
        return null;        // success!
    }
}
