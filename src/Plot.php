<?php

//------------------------------------------------------------------------------
// File:         Plot.pm
//
// Description:  Plot tag values in SVG format
//
// PHP port of Image::ExifTool::Plot 1.05.
// Notes: the members of the Perl object hash are in the array $s (lower-case members are the
// user settings, capitalised ones are used internally).
//------------------------------------------------------------------------------

namespace Image\ExifTool;

class Plot
{
    public $s = [];

    private const DEFAULTS = [
        'size' => [800, 600], 'margin' => [60, 15, 15, 30], 'legend' => [0, 0], 'txtpad' => [10, 10],
        'linespacing' => 20,
        'cols' => ['red', 'green', 'blue', 'black', 'orange', 'gray', 'fuchsia', 'brown', 'turquoise', 'gold',
            'lime', 'violet', 'maroon', 'aqua', 'navy', 'pink', 'olive', 'indigo', 'silver', 'teal'],
        'marks' => ['circle', 'square', 'triangle', 'diamond', 'star', 'plus', 'pentagon', 'left', 'down', 'right'],
        'stroke' => 1, 'grid' => 'darkgray', 'text' => 'black', 'type' => 'line', 'style' => '',
        'xlabel' => '', 'ylabel' => '', 'title' => '', 'nbins' => 20,
        'Data' => [], 'Name' => [],
    ];

    private const MARKER = [
        'circle' => '<circle cx="4" cy="4" r="2.667"',
        'square' => '<path d="M1.667 1.667 l4.667 0 0 4.667 -4.667 0 z"',
        'triangle' => '<path d="M4 0.8 l2.667 5.333 -5.333 0 z"',
        'diamond' => '<path d="M4 1 l3 3 -3 3 -3 -3 z"',
        'star' => '<path d="M4 0.8 L5 2.625 7.043 3.011 5.617 4.525 5.881 6.589 4 5.7 2.119 6.589 2.383 4.525 0.957 3.011 3 2.625 z"',
        'plus' => '<path d="M2.75 1 l2.5 0 0 1.75 1.75 0 0 2.5 -1.75 0 0 1.75 -2.5 0 0 -1.75 -1.75 0 0 -2.5 1.75 0 z"',
        'pentagon' => '<path d="M4 1 L6.853 3.073 5.763 6.427 2.237 6.427 1.147 3.073 z"',
        'left' => '<path d="M0.8 4 l5.333 2.667 0 -5.333 z"',
        'down' => '<path d="M4 7.2 l2.667 -5.333 -5.333 0 z"',
        'right' => '<path d="M7.2 4 l-5.333 2.667 0 -5.333 z"',
    ];

    private const NG = [20, 15];    // optimal number grid lines in X and Y for a 800x600 plot
    private const WCH = 8;          // nominal width of a character (measured at 7.92)

    private static ?int $rand48 = null;

    public function __construct()
    {
        $this->s = self::DEFAULTS;
    }

    private static function s($v): string
    {
        return Perl::str($v);
    }

    /** Perl split (leading empty field kept, trailing empty fields removed) */
    private static function psplit(string $re, string $str): array
    {
        $a = preg_split($re, $str);
        while ($a && '' === end($a)) {
            array_pop($a);
        }

        return $a;
    }

    private static function r10($v): float
    {
        return (int) ($v * 10 + 0.5) / 10;
    }

    // Set plot settings
    // Inputs: comma-separated options
    public function Settings($set): void
    {
        if (!$set) {
            return;
        }
        foreach (self::psplit('/,\s*/', Perl::str($set)) as $opt) {
            if (!preg_match('/^([a-z].*?)(=(.*))?$/si', $opt, $m)) {
                continue;
            }
            $name = strtolower($m[1]);
            $val = $m[3] ?? null;
            if (isset($this->s[$name]) && \is_array($this->s[$name])) {
                if (null === $val) {
                    continue;
                }
                $isNum = preg_match('/^\d+$/', Perl::str($this->s[$name][0]));
                // also allow numbers to also be separated by 'x'
                $vals = $isNum ? self::psplit('/\s*[x\s\/+]\s*/', $val) : self::psplit('/\s*[\s\/+]\s*/', $val);
                for ($i = 0; $vals; ++$i) {
                    $v = strtolower((string) array_shift($vals));
                    if ('' === $v) {
                        continue;
                    }
                    if ('marks' === $name) {
                        $vv = explode('-', $v);
                        if ($vv[0]) {
                            if (preg_match('/^n/', $vv[0])) {
                                $vv[0] = 'none';
                            } else {
                                $found = null;
                                foreach (self::DEFAULTS['marks'] as $mk) {
                                    if (@preg_match('/^'.$vv[0].'/', $mk)) {
                                        $found = $mk;
                                        break;
                                    }
                                }
                                $vv[0] = $found;
                                if (!$vv[0]) {
                                    $this->s['Warn'] = 'Invalid marker name';
                                    continue;
                                }
                            }
                        } else {
                            // cycle through default markers if none specified
                            $vv[0] = self::DEFAULTS['marks'][$i % \count(self::DEFAULTS['marks'])];
                        }
                        $v = implode('-', $vv);
                    }
                    $this->s[$name][$i] = $v;
                }
            } else {
                $val ??= 1;   // default to 1 if no "="
                // escape necessary XML characters, but allow numerical entities
                $val = Perl::str($val);
                $n = 0;
                $val = preg_replace_callback('/[&><]/', static fn ($mm) => ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'][$mm[0]], $val, -1, $n);
                if ($n) {
                    $val = preg_replace('/&amp;(#(\d+|x[0-9a-fA-F]+);)/', '&$1', $val, 1);
                }
                $this->s[$name] = '' === $val ? null : $val;
            }
        }
    }

    // Add points to SVG plot
    // Inputs: 0) tag value hash, 1) tag ID list
    public function AddPoints(array &$info, array $tags): void
    {
        $s = &$this->s;
        $num = [];
        $index = $mod = $tag = $name = null;
        $vals = [];
        $ee = $s['EE'] ?? null;
        $docNums = $s['DocNum'] ?? null;
        $xmin = $s['XMin'] ?? null;
        $xmax = $s['XMax'] ?? null;
        $s['type'] = $s['type'] ?: 'line';
        $scat = preg_match('/^s/', $s['type']) ? 1 : 0;
        $xname = $s['Name'][0] ?? null;   // (x-axis name if using scatter plot)
        $maxLines = (preg_match('/^h/', $s['type']) && empty($s['multi'])) ? 1 : 20;
        $numRe = '[+-]?(?=\.?\d)\d*\.?\d*(?:e[+-]?\d+)?';
        for (;;) {
            if ($vals) {
                $val = array_shift($vals);
                if (!preg_match("/^$numRe$/", (string) $val)) {
                    continue;
                }
            } else {
                $tag = array_shift($tags);
                if (null === $tag || false === $tag || '' === $tag) {
                    break;
                }
                // ignore non-floating-point values
                $val = $info[$tag] ?? null;
                preg_match('/^(\S+)/', $tag, $mm);
                $name = $mm[1] ?? '';   // remove index number
                if ($val instanceof ScalarRef) {
                    $val = $val->value;   // handle binary values
                } elseif (\is_array($val) && array_is_list($val)) {
                    $index = null !== $index ? $index + 1 : 0;
                    $val = $val[$index] ?? null;
                    if (null === $val) {
                        $index = $mod = null;
                        continue;
                    }
                    $name .= $mod ? '['.($index % $mod).']' : "[$index]";
                    array_unshift($tags, $tag);   // will continue with this tag later
                } elseif (\is_array($val) || \is_object($val)) {
                    continue;
                }
            }
            if (null === $val || !preg_match("/^$numRe([ ,;\\t\\n\\r]+|\$)/i", Perl::str($val), $mm)) {
                continue;
            }
            $val = Perl::str($val);
            if (!empty($mm[1])) {
                // split a string of numbers into separate plot points (eg. histogram tags)
                if (!empty($s['split'])) {
                    // make an array
                    $info[$tag] = self::psplit('/[ ,;\t\n\r][\n\r]? */', $val);
                    array_unshift($tags, $tag);
                    // split into lists of 'split' elements if split > 1
                    if ($s['split'] > 1) {
                        $mod = $s['split'];
                    }
                    continue;
                }
                $vals = self::psplit('/[ ,;\t\n\r][\n\r]? */', $val);
                $val = array_shift($vals);
            }
            $docNum = $docNums ? ($docNums[$tag] ?? 0) : 0;
            if ($docNum && !$ee) {
                continue;
            }
            if (empty($s['Data'][$name])) {
                if (\count($s['Name']) >= $maxLines + $scat) {
                    if (empty($s['MaxTags'])) {
                        if (preg_match('/^h/', $s['type']) && empty($s['multi'])) {
                            $s['Warn'] = 'Use the Multi setting to make a separate histogram for each dataset';
                        } else {
                            $s['Warn'] = 'Too many variables to plot all of them';
                        }
                        $s['MaxTags'] = 1;
                    }
                    continue;
                }
                $s['Name'][] = $name;
                $xname ??= $name;   // x-axis data for scatter plot
                if (!($scat && $name === $xname)) {
                    if (!isset($s['Max']) || $val > $s['Max']) {
                        $s['Max'] = $val;
                    }
                    if (!isset($s['Min']) || $val < $s['Min']) {
                        $s['Min'] = $val;
                    }
                }
                if (null === $xmin) {
                    $xmin = $xmax = $docNum;
                }
                $num[$name] = $xmax;
                if ($xmax >= $xmin) {
                    $s['Data'][$name][$xmax - $xmin] = $val;
                }
                continue;
            }
            if ($docNum && isset($num[$name]) && $num[$name] < $docNum) {
                $num[$name] = $docNum;   // keep documents synchronized if some tags are missing
            } else {
                if (!isset($num[$name])) {
                    $num[$name] = $xmax;
                }
                ++$num[$name];
            }
            if ($num[$name] >= $xmin) {
                $s['Data'][$name][$num[$name] - $xmin] = $val;
            }
            if (!($scat && $name === $xname)) {
                if ($val > $s['Max']) {
                    $s['Max'] = $val;
                }
                if ($val < $s['Min']) {
                    $s['Min'] = $val;
                }
            }
        }
        // start next file at x value so far
        foreach ($num as $n) {
            if ($xmax < $n) {
                $xmax = $n;
            }
        }
        $s['XMin'] = $xmin;
        $s['XMax'] = $xmax;
    }

    // Calculate a nice round number for grid spacing
    // Inputs: 0) nominal spacing (must be positive), 1) flag to increment to next number
    private static function GetGridSpacing($nom, $inc = null): string
    {
        $div = Perl::sprintf('%.3e', $nom);
        $num = (int) substr($div, 0, 1);
        $exp = preg_match('/e(.*)/', $div, $m) ? (int) $m[1] : 0;
        if ($inc) {
            // increment to next highest even number
            if ($num < 2) {
                $num = 2;
            } elseif ($num < 5) {
                $num = 5;
            } else {
                ++$exp;
                $num = 1;
            }
        } elseif ($num > 2) {
            // look for nearest factor to 1, 2 or 5 * 10^x
            if ($num < 8) {
                $num = 5;
            } else {
                ++$exp;
                $num = 1;
            }
        }

        return $exp >= 0 ? $num.str_repeat('0', $exp) : '.'.str_repeat('0', -$exp - 1).$num;
    }

    // Get plot range; adjusts min/max if necessary to make difference positive
    private static function GetRange(&$min, &$max)
    {
        if ($min >= $max) {
            $min = ($min + $max) / 2;
            if ($min) {
                $min -= 0.5;
            }
            $max = $min + 1;
        }

        return $max - $min;
    }

    // Perl rand/srand (drand48)
    private static function srand(int $seed): void
    {
        self::$rand48 = (($seed & 0xffffffff) << 16) + 0x330E;
    }

    private static function rand($n): float
    {
        // (48-bit multiplication done in two halves to avoid overflowing 64-bit integers)
        $x = self::$rand48;
        $a = 0x5DEECE66D;
        $lo = $a * ($x & 0xffffff);
        $hi = ($a * ($x >> 24)) & 0xffffff;
        self::$rand48 = ($lo + ($hi << 24) + 0xB) & 0xffffffffffff;

        return $n * (self::$rand48 / 281474976710656.0);
    }

    // Draw SVG plot
    // Inputs: output file reference
    public function Draw($fp): void
    {
        $s = &$this->s;
        $min = $s['Min'] ?? null;
        $max = $s['Max'] ?? null;
        $xmin = $s['XMin'] ?? null;
        $xmax = $s['XMax'] ?? null;
        $name = $s['Name'];
        $style = $s['style'] ?? null;
        $multiMulti = null;
        $out = '';
        if (null === $min || null === $xmin) {
            $s['Error'] = 'Nothing to plot';

            return;
        }
        $scat = preg_match('/^s/', $s['type']) ? 1 : 0;
        $hist = preg_match('/^h/', $s['type']) ? true : false;
        $multi = $s['multi'] ?? 0;
        preg_match_all('/\d+/', Perl::str($multi), $mm);
        $multiA = $mm[0];
        $names = $name;
        if ($scat) {
            array_shift($names);
        }
        $multi = (int) array_shift($multiA);
        if (!($multi > 0)) {
            $multi = 0;
        }
        if (!$style) {
            $style = $hist ? 'line+fill' : 'line';
        }
        if (!(preg_match('/\b[mpl]/', $style) || ($hist && preg_match('/\bf/', $style)))) {
            $s['Error'] = 'Invalid plot Style setting';

            return;
        }
        $numPlots = 0;
        if ($multi) {
            for ($n = 0; $n < \count($name) - $scat; ++$numPlots) {
                $n += ($multiA[$numPlots] ?? 0) ?: 1;
                if (($multiA[$numPlots] ?? 0) && $multiA[$numPlots] > 1) {
                    $multiMulti = 1;
                }
            }
        } else {
            $numPlots = 1;
        }
        $size = $s['size'];
        $sy = $size[1];
        if ($multi) {
            $sy *= (int) (($numPlots + $multi - 1) / $multi) / $multi;
            foreach ($size as &$z) {
                $z /= $multi;
            }
            unset($z);
        }
        $tmp = $s['title'] ?: 'Plot by ExifTool '.ExifTool::$VERSION;
        $w0 = self::s($s['size'][0]);
        $sys = self::s($sy);
        $o = <<<X
<?xml version="1.0" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 20010904//EN" "http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd">
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="$w0" height="$sys"
 preserveAspectRatio="xMidYMid meet" viewBox="0 0 $w0 $sys">
<title>$tmp</title>
X;
        $o = rtrim($o, "\n");
        $wch = self::WCH;
        // loop through all plots
        for ($plotNum = 0; $plotNum < $numPlots; ++$plotNum) {
            $col = $class = $markID = $og = [];
            $noLegend = $xAxis = $dx2 = $xdat = null;
            if ($numPlots > 1) {
                $o .= "\n<g transform='translate(".self::s(($plotNum % $multi) * $size[0]).','.self::s((int) ($plotNum / $multi) * $size[1]).")'>";
                if ($plotNum) {
                    [$s['XMin'], $s['XMax'], $s['title'], $s['xlabel'], $s['ylabel']] = $s['Save'];
                } else {
                    $s['Save'] = [$s['XMin'], $s['XMax'], $s['title'], $s['xlabel'], $s['ylabel']];
                    $s['SaveName'] = $s['Name'];
                }
                $name = $s['Name'] = [];
                if ($scat) {
                    $s['Name'][] = $s['SaveName'][0];
                }
                for ($k = 0; $k < (($multiA[$plotNum] ?? 0) ?: 1); ++$k) {
                    $s['Name'][] = array_shift($names);
                }
                $name = $s['Name'];
                $min = $max = null;
                for ($k = $scat; $k < \count($s['Name']); ++$k) {
                    foreach ($s['Data'][$s['Name'][$k]] ?? [] as $d) {
                        if (null === $d) {
                            continue;
                        }
                        if (null === $min) {
                            $min = $max = $d;
                            continue;
                        }
                        if ($min > $d) {
                            $min = $d;
                        }
                        if ($max < $d) {
                            $max = $d;
                        }
                    }
                }
            }
            $title = $s['title'];
            $xlabel = $s['xlabel'];
            $ylabel = $s['ylabel'];
            $marks = $s['marks'];
            $tpad = $s['txtpad'];
            $wid = $s['stroke'];
            $cols = &$s['cols'];
            $nameL = $name;
            $margin = $s['margin'];
            // set reasonable default titles and labels
            $xname = null;
            if ($scat) {
                $xname = array_shift($nameL);
            }
            if ($scat && null !== $title && !$title && 1 == \count($nameL) && !$multi) {
                $title = "{$nameL[0]} vs $xname";
            }
            if (($scat || $hist) && null !== $xlabel && !$xlabel) {
                $xlabel = $name[0];
                if ($hist) {
                    $noLegend = 1;
                }
            }
            if (null !== $ylabel && !$ylabel && 1 == \count($nameL) && !$multiMulti) {
                $ylabel = $hist ? 'Count' : $nameL[0];
                if (!$hist) {
                    $noLegend = 1;
                }
            }
            // make room for title/labels
            if ($title) {
                $margin[1] += $s['linespacing'] * 1.5;
            }
            if ($xlabel) {
                $margin[3] += $s['linespacing'];
            }
            if ($ylabel) {
                $margin[0] += $s['linespacing'];
            }
            // calculate optimal number of X/Y grid lines
            for ($i = 0; $i < 2; ++$i) {
                $og[$i] = self::NG[$i] * ($size[$i] - $margin[$i] - $margin[$i + 2]) /
                    (self::DEFAULTS['size'][$i] - self::DEFAULTS['margin'][$i] - self::DEFAULTS['margin'][$i + 2]);
                if ($og[$i] <= 0) {
                    $s['Error'] = 'Invalid plot size';
                    fwrite($fp, $o);

                    return;
                }
            }
            if ($scat) {
                $xdat = $s['Data'][$xname] ?? [];
                if (!(isset($s['xmin']) && isset($s['xmax']))) {
                    $set = null;
                    foreach ($xdat as $d) {
                        if (null === $d) {
                            continue;
                        }
                        if (!$set) {
                            $xmin = $xmax = $d;
                            $set = 1;
                            continue;
                        }
                        if ($xmin > $d) {
                            $xmin = $d;
                        }
                        if ($xmax < $d) {
                            $xmax = $d;
                        }
                    }
                    $dnx2 = ($xmax - $xmin) / ($og[0] * 2);
                    // leave a bit of a left/right margin, but don't pass 0
                    $xmin = ($xmin >= 0 && $xmin < $dnx2) ? 0 : $xmin - $dnx2;
                    $xmax = ($xmax <= 0 && -$xmax < $dnx2) ? 0 : $xmax + $dnx2;
                }
                if (isset($s['xmin'])) {
                    $xmin = $s['xmin'];
                }
                if (isset($s['xmax'])) {
                    $xmax = $s['xmax'];
                }
            } else {
                // shift x range to correspond with index in data list
                $xmax -= $xmin;
                $xmin = 0;
            }
            if ($hist) {
                if (!($s['nbins'] > 0)) {
                    $s['Error'] = 'Invalid number of histogram bins';
                    fwrite($fp, $o);

                    return;
                }
                $noLegend = 1;
                // y axis becomes histogram x axis after binning
                if (isset($s['xmin'])) {
                    $min = $s['xmin'];
                }
                if (isset($s['xmax'])) {
                    $max = $s['xmax'];
                }
            } else {
                // leave a bit of a margin above/below data when autoscaling but don't pass 0
                $dny2 = ($max - $min) / ($og[1] * 2);
                $min = ($min >= 0 && $min < $dny2) ? 0 : $min - $dny2;
                $max = ($max <= 0 && -$max < $dny2) ? 0 : $max + $dny2;
                // adjust to user-defined range if specified
                if (isset($s['ymin'])) {
                    $min = $s['ymin'];
                }
                if (isset($s['ymax'])) {
                    $max = $s['ymax'];
                }
            }
            // generate random colors if we need more
            while (\count($cols) < \count($name)) {
                if (empty($s['seeded'])) {
                    self::srand(141);
                    $s['seeded'] = 1;
                }
                $cols[] = Perl::sprintf('#%.2x%.2x%.2x', (int) self::rand(220), (int) self::rand(220), (int) self::rand(220));
            }
            $min = (float) $min;
            $max = (float) $max;
            $xmin = (float) $xmin;
            $xmax = (float) $xmax;
            $diff = self::GetRange($min, $max);
            $xdiff = self::GetRange($xmin, $xmax);
            // determine y grid spacing (nice even numbers)
            $dy = self::GetGridSpacing($diff / ($hist ? $s['nbins'] : $og[1]));
            // expand plot min/max to the nearest even multiple of our grid spacing
            $min = ($min > 0 ? (int) ($min / $dy) : (int) ($min / $dy - 0.9999)) * $dy;
            $max = ($max > 0 ? (int) ($max / $dy + 0.9999) : (int) ($max / $dy)) * $dy;
            // bin histogram
            if ($hist) {
                $dat = $s['Data'][$nameL[0]] ?? [];
                $nmax = (int) (($max - $min) / $dy + 0.5);
                $histA = $nmax > 0 ? array_fill(0, $nmax, 0) : [];
                foreach ($dat as $d) {
                    if (null === $d) {
                        continue;
                    }
                    $n = ($d - $min) / $dy;
                    if ($n < 0 || $n > $nmax + 0.00001) {
                        continue;
                    }
                    $n = (int) $n;
                    ++$histA[$n < $nmax ? $n : $nmax - 1];
                }
                [$xmin, $xmax, $min, $max] = [$min, $max, 0, 0];
                if (!empty($s['ymax'])) {
                    $max = $s['ymax'];
                } else {
                    foreach ($histA as $h) {
                        if ($max < $h) {
                            $max = $h;   // find max count
                        }
                    }
                }
                $min = (float) $min;
                $max = (float) $max;
                $diff = self::GetRange($min, $max);
                $dx = $dy;
                $dy = self::GetGridSpacing($diff / $og[1]);
                $max = ($max > 0 ? (int) ($max / $dy + 0.9999) : (int) ($max / $dy)) * $dy;
                $s['Data'][$nameL[0]] = $histA;
            } else {
                $dx = self::GetGridSpacing($xdiff / $og[0]);
            }
            if ($scat) {
                $xmin = ($xmin > 0 ? (int) ($xmin / $dx) : (int) ($xmin / $dx - 0.9999)) * $dx;
                $xmax = ($xmax > 0 ? (int) ($xmax / $dx + 0.9999) : (int) ($xmax / $dx)) * $dx;
            }
            $min = (float) $min;
            $max = (float) $max;
            $xmin = (float) $xmin;
            $xmax = (float) $xmax;
            $diff = self::GetRange($min, $max);
            $xdiff = self::GetRange($xmin, $xmax);
            // width/height of plot area
            $width = $size[0] - $margin[0] - $margin[2];
            $height = $size[1] - $margin[1] - $margin[3];
            $yscl = $height / $diff;
            $xscl = $width / $xdiff;
            $px0 = $margin[0] - $xmin * $xscl;
            $py0 = $margin[1] + $height + $min * $yscl;
            $clip = [$margin[0] - 6 * $wid, $margin[1] - 6 * $wid, $width + 12 * $wid, $height + 12 * $wid];
            $o .= "\n<!-- Definitions -->\n<defs>\n<clipPath id='plot-area'>";
            $o .= "<rect x='".self::s($clip[0])."' y='".self::s($clip[1])."' width='".self::s($clip[2])."' height='".self::s($clip[3])."'/></clipPath>";
            $nN = \count($nameL);
            if (preg_match('/\b[mp]/', $style)) { // 'm' for 'marker' or 'p' for 'point' (undocumented)
                for ($i = 0; $i < $nN; ++$i) {
                    $m = explode('-', ($marks[$i] ?? null) ?: self::DEFAULTS['marks'][$i % \count(self::DEFAULTS['marks'])]);
                    $fill = null;
                    if (!empty($m[1]) ? preg_match('/^f/', $m[1]) : preg_match('/\bf/', $style)) {
                        $fill = ($m[2] ?? null) ?: $cols[$i];
                    }
                    $mark = self::MARKER[$m[0]] ?? null;
                    if (!$mark) {
                        $markID[''] = '';   // skip 'none' or unrecognized marker name
                        continue;
                    }
                    if ($fill && 'none' !== $fill) {
                        $op = ($m[3] ?? null) ?: ('none' === $cols[$i] ? 50 : 20);
                        $mark .= " fill=\"$fill\" style=\"fill-opacity: $op%\"";
                    } else {
                        $mark .= ' fill="none"';
                    }
                    $mark .= " stroke='{$cols[$i]}'/>";
                    // don't re-define mark if it is the same as a previous one
                    if (!empty($markID[$mark])) {
                        $markID[$i] = $markID[$mark];
                        continue;
                    }
                    $markID[$mark] = $markID[$i] = "mark$i";
                    $o .= "\n<marker id='{$markID[$i]}' markerWidth='8' markerHeight='8' refX='4' refY='4'>\n$mark\n</marker>";
                }
                $o .= "\n</defs>\n<style>";
                for ($i = 0; $i < $nN; ++$i) {
                    if (($markID[$i] ?? '') !== "mark$i") {
                        continue;
                    }
                    $o .= "\n  path.mark$i { marker: url(#mark$i) }";
                }
            } else {
                $o .= "\n</defs>\n<style>";
            }
            $o .= "\n  text { fill: {$s['text']} }\n</style>";
            if (!empty($s['bkg'])) {
                $o .= "\n<rect x='0' y='0' width='".self::s($size[0])."' height='".self::s($size[1])."' fill='{$s['bkg']}'/>";
            }
            $o .= "\n<!-- X axis -->";
            $o .= "\n<g dominant-baseline='hanging' text-anchor='middle'>";
            $py = self::r10($margin[1] + $height + $tpad[1]);
            $px = self::r10($margin[0] + $width / 2);
            if ($title) {
                $o .= "\n<text x='".self::s($px)."' y='14' font-size='150%'>$title</text>";
            }
            if ($xlabel) {
                $y = $py + $s['linespacing'];
                $o .= "\n<text x='".self::s($px)."' y='".self::s($y)."'>$xlabel</text>";
            }
            if ($ylabel) {
                $y = self::s($margin[1] + $height / 2);
                $o .= "\n<text x='10' y='{$y}' transform='rotate(-90,10,$y)'>$ylabel</text>";
            }
            // make sure the X labels will fit
            $spc = $dx;
            for (;;) {
                // find longest label at current spacing
                $len = 0;
                $x0 = (int) ($xmax / $spc + 0.5) * $spc;   // get value of last x label
                for ($i = 0, $x = $x0; $i < 3; ++$i, $x -= $spc) {
                    $n = \strlen(Perl::sprintf('%g', $x));
                    if ($len < $n) {
                        $len = $n;
                    }
                }
                if ($spc >= ($len + 1) * $wch * $xdiff / $width) {
                    break;
                }
                // increase label spacing by one increment and try again
                $spc = $dx2 = self::GetGridSpacing($spc, 1);
            }
            $grid = '';
            $lastLen = 0;
            for ($x = (int) ($xmin / $dx - 1) * $dx; ; $x += $dx) {
                $px = self::r10($margin[0] + ($x - $xmin) * $width / $xdiff);
                if ($px < $margin[0] - 0.5) {
                    continue;
                }
                if ($px > $margin[0] + $width + 0.5) {
                    break;
                }
                $h = $height;
                if (!$dx2 || abs($x / $dx2 - (int) ($x / $dx2 + ($x > 0 ? 0.5 : -0.5))) < 0.01) {
                    $o .= "\n<text x='".self::s($px)."' y='".self::s($py)."'>".Perl::sprintf('%g', $x).'</text>';
                    $h += $tpad[1] / 2;
                }
                if (\strlen($grid) - $lastLen > 80) {
                    $grid .= "\n";
                    $lastLen = \strlen($grid);
                }
                $grid .= 'M'.self::s($px).' '.self::s($margin[1]).' v'.self::s($h).' ';
            }
            $o .= "\n<path stroke='{$s['grid']}' stroke-width='0.5' d='\n{$grid}'/>";
            $o .= "\n</g>\n<!-- Y axis -->\n<g dominant-baseline='middle' text-anchor='end'>";
            $px = self::r10($margin[0] - $tpad[0]);
            $grid = '';
            $lastLen = 0;
            $gx = $margin[0] - $tpad[0] / 2;
            $gw = $width + $tpad[0] / 2;
            for ($y = $min; ; $y += $dy) {
                $py = self::r10($margin[1] + $height - ($y - $min) * $yscl);
                if ($py < $margin[1] - 0.5) {
                    break;
                }
                if ($y < $dy / 2 && $y > -$dy / 2) {
                    $y = 0;   // (avoid round-off errors)
                }
                $o .= "\n<text x='".self::s($px)."' y='".self::s($py)."'>".Perl::sprintf('%g', $y).'</text>';
                if ($y < $dy / 2 && $y > -$dy / 2) {
                    $xAxis = 1;   // redraw x axis later
                }
                if (\strlen($grid) - $lastLen > 80) {
                    $grid .= "\n";
                    $lastLen = \strlen($grid);
                }
                $grid .= 'M'.self::s($gx).' '.self::s($py).' h'.self::s($gw).' ';
            }
            if ($xAxis && 0 != $min) {
                $py = $margin[1] + $height + $min * $yscl;
                $o .= "\n<path stroke='{$s['text']}' d='M".self::s($margin[0]).' '.self::s($py).' h'.self::s($width)."'/>";
            }
            $o .= "\n<path stroke='{$s['grid']}' stroke-width='0.5' d='\n{$grid}'/>";
            $o .= "\n</g>\n<!-- Plot box and legend -->\n<g dominant-baseline='middle' text-anchor='start'>";
            $o .= "\n<path stroke='{$s['text']}' fill='none' d='M".self::s($margin[0]).' '.self::s($margin[1]).' l0 '.self::s($height).' '.self::s($width).' 0 0 -'.self::s($height)." z'/>";
            for ($i = 0; $i < $nN && !$noLegend; ++$i) {
                $x = $size[0] - $margin[2] - 175 + $s['legend'][0];
                $y = $margin[1] + $s['legend'][1] + 15 + $s['linespacing'] * ($i + 0.5);
                $colr = $cols[$i];
                $mark = !empty($markID[$i]) ? " marker-end='url(#{$markID[$i]})' fill='none'" : '';
                $line = preg_match('/\bl/', $style) ? ' l-20 0' : Perl::sprintf(' m%.4g 0', -5 * $wid);
                $sw = (preg_match('/\bm/', $style) ? 1.5 : 2) * $wid;   // (wider for line-only style so colour is more visible)
                $o .= "\n<path$mark stroke-width='".self::s($sw)."' stroke='{$colr}' d='M".self::s($x).' '.self::s($y)." m-7 -1{$line}'/>";
                $o .= "\n<text x='".self::s($x)."' y='".self::s($y)."'>{$nameL[$i]}</text>";
            }
            // print the data
            foreach ($nameL as $k => $nm) {
                $col[$nm] = $cols[$k];
                $class[$nm] = !empty($markID[$k]) ? " class='{$markID[$k]}'" : '';
            }
            $fill = '';
            $xsclr = null;
            if ($scat) {
                [$i0, $i1] = [0, $xdat ? max(array_keys($xdat)) : -1];
            } elseif ($hist) {
                [$i0, $i1] = [0, \count($histA) - 1];
                $xscl = $width / \count($histA);
                $px0 = $margin[0];
                $xsclr = self::s((int) ($xscl * 100 + 0.5) / 100);
                if (preg_match('/\bf/', $style)) {
                    $m = explode('-', (string) $marks[0]);
                    $op = ($m[3] ?? null) ?: (preg_match('/\bl/', $style) ? 20 : 50);
                    $fill = " fill='{$cols[0]}'";
                    if ('none' !== $cols[0]) {
                        $fill .= " style='fill-opacity: $op%'";
                    }
                }
            } else {
                $i0 = (int) $xmin - 1;
                if ($i0 < 0) {
                    $i0 = 0;
                }
                $i1 = (int) $xmax + 1;
            }
            $o .= "\n</g>\n<!-- Datasets -->\n<g fill='none' clip-path='url(#plot-area)' stroke-linejoin='round' stroke-linecap='round' stroke-width='".self::s(1.5 * $wid)."'>";
            $doLines = preg_match('/\bl/', $style);
            foreach ($nameL as $nm) {
                $stroke = ($hist && !$doLines) ? 'none' : $col[$nm];
                $dat = $s['Data'][$nm] ?? [];
                $o .= "\n<!-- $nm -->";
                $o .= "\n<path{$class[$nm]}$fill stroke='{$stroke}' d='";
                if ($doLines) {
                    $o .= 'M';
                }
                $m = $doLines ? '' : ' M';
                for ($i = $i0; $i <= $i1; ++$i) {
                    if (!isset($dat[$i])) {
                        continue;
                    }
                    $y = self::r10($py0 - $dat[$i] * $yscl);
                    if ($scat) {
                        if (!isset($xdat[$i])) {
                            continue;
                        }
                        $x = self::r10($px0 + $xdat[$i] * $xscl);
                    } else {
                        $x = self::r10($px0 + $i * $xscl);
                        if ($hist) {
                            $o .= $m.($i % 5 ? ' ' : "\n").self::s($x).' '.self::s($y).' h'.$xsclr;
                            $m = ' L';   // (draw lines after the first point)
                            continue;
                        }
                    }
                    $o .= $m.($i % 10 ? ' ' : "\n").self::s($x).' '.self::s($y);
                }
                if ($hist && $fill) {
                    $o .= ' V'.self::s($margin[1] + $height).' H'.self::s($margin[0]).' z';
                }
                $o .= "'/>";
            }
            $o .= "\n</g>";
            if ($numPlots > 1) {
                $o .= "\n</g>";
            }
            unset($cols);
        }
        $o .= "</svg>\n";
        if (false === fwrite($fp, $o)) {
            $s['Error'] = 'Error writing output plot file';
        }
    }
}
