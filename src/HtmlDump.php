<?php

//------------------------------------------------------------------------------
// File:         HtmlDump.pm
//
// Description:  Dump information in hex to HTML page
//
// Revisions:    12/05/2005 - P. Harvey Created
//
// PHP port of Image::ExifTool::HtmlDump 1.42.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class HtmlDump implements \ArrayAccess
{
    public static $VERSION = '1.42';

    private static $bkgStart = 0;
    private static $bkgEnd = 0;
    private static $bkgSpan = [];

    private static $htmlHeader1 = <<<'_END_PART_1_'
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN"
 "http://www.w3.org/TR/1998/REC-html40-19980424/loose.dtd">
<html>
<head>
<title>
_END_PART_1_ . "\n";

    private static $htmlHeader2 = <<<'_END_PART_2_'
</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<style type="text/css">
<!--
/* character style ID's */
.D { color: #000000 } /* default color */
.V { color: #ff0000 } /* duplicate block 1 */
.W { color: #004400 } /* normal block 1 */
.X { color: #ff4488 } /* duplicate block 2 */
.Y { color: #448844 } /* normal block 2 */
.U { color: #cc8844 } /* unused data block */
.H { color: #0000ff } /* highlighted tag name */
.F { color: #aa00dd } /* actual offset differs */
.M { text-decoration: underline } /* maker notes data */
.tt { /* tooltip text */
    visibility: hidden;
    position: absolute;
    white-space: nowrap;
    top: 0;
    left: 0;
    font-family: Verdana, sans-serif;
    font-size: .7em;
    padding: 2px 4px;
    border: 1px solid gray;
    z-index: 3;
}
.tb { /* tooltip background */
    visibility: hidden;
    position: absolute;
    background: #ffffdd;
    zoom: 1;
    -moz-opacity: 0.8;
    -khtml-opacity: 0.8;
    -ms-filter: 'progid:DXImageTransform.Microsoft.Alpha(Opacity=80)';
    filter: alpha(opacity=80);
    opacity: 0.8;
    z-index: 2;
}
/* table styles */
table.dump {
  border-top: 1px solid gray;
  border-bottom: 1px solid gray;
}
table.dump td { padding: .2em .3em }
td.c2 {
  border-left: 1px solid gray;
  border-right: 1px solid gray;
}
pre   { margin: 0 }
table { font-size: .9em }
body  { color: black; background: white }
-->
</style>
<script language="JavaScript" type="text/JavaScript">
<!-- Begin
// tooltip positioning constants
var TMAR = 4;   // top/left margins
var BMAR = 16;  // bottom/right margins (scrollbars may overhang inner dimensions)
var XOFF = 10;  // x offset from cursor
var YOFF = 40;  // y offset
var YMIN = 10;  // minimum y offset
var YTOP = 20;  // y offset when above cursor
// common variables
var safari1 = navigator.userAgent.indexOf("Safari/312.6") >= 0;
var ie6 = navigator.userAgent.toLowerCase().indexOf('msie 6') >= 0;
var mspan = new Array;
var clicked = 0;
var hlist, tt, tb, firstOutEvt, lastInEvt;

function GetElementsByClass(classname, tagname) {
  var found = new Array();
  var list = document.getElementsByTagName(tagname);
  var len = list.length;
  for (var i=0, j=0; i<len; ++i) {
    var classes = list[i].className.split(' ');
    for (var k=0; k<classes.length; ++k) {
      if (classes[k] == classname) {
        found[j++] = list[i];
        break;
      }
    }
  }
  return found;
}

// click mouse
function doClick(e)
{
  if (!clicked) {
    firstOutEvt = lastInEvt = undefined;
    high(e, 2);
    if (hlist) clicked = 1;
  } else {
    clicked = 0;
    if (firstOutEvt) high(firstOutEvt, 0);
    if (lastInEvt) high(lastInEvt, 1);
  }
}

// move tooltip
function move(e)
{
  if (!tt) return;
  if (ie6 && (tt.style.top  == '' || tt.style.top  == 0) &&
             (tt.style.left == '' || tt.style.left == 0))
  {
    tt.style.width  = tt.offsetWidth  + 'px';
    tt.style.height = tt.offsetHeight + 'px';
  }
  var w, h;
  // browser inconsistencies make getting window size more complex than it should be,
  // and even then we don't know if it is smaller due to scrollbar width
  if (typeof(window.innerWidth) == 'number') {
    w = window.innerWidth;
    h = window.innerHeight;
  } else if (document.documentElement && document.documentElement.clientWidth) {
    w = document.documentElement.clientWidth;
    h = document.documentElement.clientHeight;
  } else {
    w = document.body.clientWidth;
    h = document.body.clientHeight;
  }
  var x = e.clientX + XOFF;
  var y = e.clientY + YOFF;
  if (safari1) { // patch for people still using OS X 10.3.9
    x -= document.body.scrollLeft + document.documentElement.scrollLeft;
    y -= document.body.scrollTop  + document.documentElement.scrollTop;
  }
  var mx = w - BMAR - tt.offsetWidth;
  var my = h - BMAR - tt.offsetHeight;
  if (y > my + YOFF - YMIN) y = e.clientY - YTOP - tt.offsetHeight;
  if (x > mx) x = mx;
  if (y > my) y = my;
  if (x < TMAR) x = TMAR;
  if (y < TMAR) y = TMAR;
  x += document.body.scrollLeft + document.documentElement.scrollLeft;
  y += document.body.scrollTop  + document.documentElement.scrollTop;
  tb.style.width  = tt.offsetWidth  + 'px';
  tb.style.height = tt.offsetHeight + 'px';
  tt.style.top  = tb.style.top  = y + 'px';
  tt.style.left = tb.style.left = x + 'px';
  tt.style.visibility = tb.style.visibility = 'visible';
}

// highlight/unhighlight text
function high(e,on) {
  if (on) {
    lastInEvt = e;
  } else {
    if (!firstOutEvt) firstOutEvt = e;
  }
  if (clicked) return;
  var targ;
  if (e.target) targ = e.target;
  else if (e.srcElement) targ = e.srcElement;
  if (targ.nodeType == 3) targ = targ.parentNode; // defeat Safari bug
  if (!targ.name) targ = targ.parentNode; // go up another level if necessary
  if (targ.name && document.getElementsByName) {
    // un-highlight current objects
    if (hlist) {
      for (var i=0; i<hlist.length; ++i) {
        for (var j=0; j<hlist[i].length; ++j) {
          hlist[i][j].style.background = 'transparent';
        }
      }
      hlist = null;
    }
    if (tt) {
      // hide old tooltip
      tt.style.visibility = tb.style.visibility = 'hidden';
      tt = null;
    }
    if (on) {
      if (targ.name.substring(0,1) == 't') {
        // show our tooltip (ID is different than name to avoid confusing IE)
        tt = document.getElementById('p' + targ.name.substring(1));
        if (tt) {
          tb = document.getElementById('tb');
          move(e);
        }
      }
      // highlight anchor elements with the same name
      hlist = new Array;
      hlist.push(document.getElementsByName(targ.name));
      // is this an IFD pointer?
      var pos = targ.className.indexOf('Offset_');
      if (pos > 0) {
        // add elements from this IFD to our highlight list
        hlist.push(document.getElementsByClassName(targ.className.substr(pos+7)));
      }
      // use class name to highlight span elements if necessary
      for (var i=0; i<mspan.length; ++i) {
        if (mspan[i] != targ.name) continue;
        // add these span elements to our highlight list
        hlist.push(GetElementsByClass(targ.name, 'span'));
        break;
      }
      for (var i=0; i<hlist.length; ++i) {
        for (var j=0; j<hlist[i].length; ++j) {
          hlist[i][j].style.background = on == 2 ? '#ffbbbb' : '#ffcc99';
        }
      }
    }
  }
}
_END_PART_2_ . "\n";

    private static $htmlHeader3 = <<<'_END_PART_3_'

// End --->
</script></head>
<body><noscript><b class=V>--&gt;
Enable JavaScript for active highlighting and information tool tips!
</b></noscript>
<table class=dump cellspacing=0 cellpadding=2>
<tr><td valign='top'><pre>
_END_PART_3_;

    private static $preMouse = '<pre onmouseover="high(event,1)" onmouseout="high(event,0)" onmousemove="move(event)" onmousedown="doClick(event)">';

    public $Block = [];
    public $TipNum = 0;
    public $Error = null;       // (set externally to print a specific error message)
    public $ERROR = null;       // (offending data name when Print() returns -1)
    private $Open = [];
    private $Closed = [];
    private $TipList = [];
    private $MSpanList = [];
    private $Cols = ['', '', '', ''];
    private $TmpCols = null;
    private $Limit = 0;
    private $A = 0;
    private $Cont = 0;
    private $Msg = 0;

    // (so that $et->HTML_DUMP['Error'] = ... works like $$et{HTML_DUMP}{Error})
    public function offsetExists(mixed $o): bool { return isset($this->$o); }
    public function offsetGet(mixed $o): mixed { return $this->$o; }
    public function offsetSet(mixed $o, mixed $v): void { $this->$o = $v; }
    public function offsetUnset(mixed $o): void { $this->$o = null; }

    //------------------------------------------------------------------------------
    // New - create new HtmlDump object
    public function __construct()
    {
        $this->Block = [];
        $this->TipNum = 0;
    }

    //------------------------------------------------------------------------------
    // Add information to dump
    // Inputs: 0) HTML dump object, 1) absolute offset in file, 2) data size,
    //         3) comment string, 4) tool tip (or SAME to use previous tip),
    //         5) bit flags (see below), 6) IFD name
    // Bits: 0x01 - print at start of line
    //       0x02 - print red address
    //       0x04 - maker notes data ('M'-class span)
    //       0x08 - limit block length
    //       0x10 - allow double references
    //       0x100 - (reserved)
    // Notes: Block will be shown in 'unused' color if comment string begins with '['
    public function Add($start, $size, $msg, $tip = null, $flag = null, $ifd = null): void
    {
        if (empty($this->Block[$start])) $this->Block[$start] = [];
        if ($tip and $tip === 'SAME') {
            $htip = '';
        } else {
            // use message as first line of tip, and make bold unless in brackets
            $htip = preg_match('/^[\[(]/', $msg) ? $msg : "<b>$msg</b>";
            if ($tip !== null) {
                $tip = str_replace("\n", '<br>', HTML::EscapeHTML($tip));   // HTML-ize tooltip text
                $htip .= '<br>' . $tip;
            }
            // add size if not already done
            if (!str_contains($htip, '<br>Size:')) $htip .= "<br>($size bytes)";
            ++$this->TipNum;
        }
        $this->Block[$start][] = [$size, $msg, $htip, $flag, $this->TipNum, $ifd];
    }

    //------------------------------------------------------------------------------
    // Print dump information to HTML page
    // Inputs: 0) HtmlDump object, 1) source file RAF reference,
    //         2) data (or null), 3) data position, 4) output file or ScalarRef,
    //         5) limit level (1-3), 6) title
    // Returns: non-zero if useful output was generated,
    //          or -1 on error loading data and "ERROR" is set to offending data name
    // Note: The "Error" member may be set externally to print a specific error
    //       message instead of doing the dump.
    public function Print($raf, $dataPt, $dataPos, $outfile, $level, $title = null)
    {
        $err = null;
        $rtnVal = null;
        $block = &$this->Block;
        $dataPos = $dataPos ?: 0;
        if (!is_resource($outfile) and !($outfile instanceof ScalarRef)) $outfile = STDOUT;
        $title = $title ?: 'HtmlDump';
        $level = $level ?: 0;
        $tell = $raf->Tell();
        if (!$raf->Seek(0, 2)) {
            $this->ERROR = 'Seek error';
            return -1;
        }
        $fileLen = $raf->Tell();
        $pos = 0;
        $dataEnd = $dataPos + ($dataPt !== null ? strlen($dataPt) : 0);
        // initialize member variables
        $this->Open = [];
        $this->Closed = [];
        $this->TipList = [];
        $this->MSpanList = [];
        $this->Cols = ['', '', '', ''];  // text columns
        // set dump size limits (limits are 4x smaller if bit 0x08 set in flags)
        if ($level <= 1) {
            $limit = 1024;
        } elseif ($level <= 2) {
            $limit = 16384;
        } else {
            $limit = 256 * 1024 * 1024; // never dump bigger than 256 MB
        }
        $this->Limit = $limit;
        // pre-initialize open/closed hashes for all columns
        for ($i = 0; $i < 4; ++$i) {
            $this->Open[$i] = ['ID' => [], 'Element' => []];
            $this->Closed[$i] = ['ID' => [], 'Element' => []];
        }
        self::$bkgStart = self::$bkgEnd = 0;
        self::$bkgSpan = [];
        $index = 0;  // initialize tooltip index
        $names = [];
        $wasUnused = null;
        $starts = [];
        // only do dump if we didn't have a serious error
        if (!$this->Error) {
            $starts = array_keys($block);
            sort($starts, SORT_NUMERIC);
        }
        $nstarts = count($starts);
        for ($i = 0; $i <= $nstarts; ++$i) {
            $start = $starts[$i] ?? null;
            $parmList = null;
            if ($start !== null) {
                $parmList = $block[$start];
            } elseif (self::$bkgEnd and $pos < self::$bkgEnd and $wasUnused === null) {
                $start = self::$bkgEnd;   // finish last bkg block
            } else {
                break;
            }
            if ($start > $fileLen) $start = $fileLen; // handle case of bad start offset
            $len = $start - $pos;
            if ($len > 0 and !$wasUnused) {
                // we have a unused bytes before this data block
                --$i;   // dump the data block next time around
                // split unused data into 2 blocks if it spans end of a bkg block
                $nextBkgEnd = null;
                if ($wasUnused === null and self::$bkgEnd) {
                    foreach (self::$bkgSpan as $bkg) {
                        if ($pos >= $bkg['End'] + $dataPos or $pos + $len <= $bkg['End'] + $dataPos) continue;
                        if (!($nextBkgEnd and $nextBkgEnd < $bkg['End'])) $nextBkgEnd = $bkg['End'];
                    }
                }
                if ($nextBkgEnd) {
                    $start = $pos;
                    $len = $nextBkgEnd + $dataPos - $pos;
                    $wasUnused = 0;
                } else {
                    $start = $pos;  // dump the unused bytes now
                    $wasUnused = 1; // avoid re-dumping unused bytes if we get a read error
                }
                $str = ($len > 1) ? "unused $len bytes" : 'pad byte';
                $parmList = [[$len, "[$str]", null, 0x108]];
            } else {
                $wasUnused = null;
            }
            $lastParm = $parmList ? count($parmList) - 1 : -1;
            foreach ($parmList ?? [] as $pi => $parms) {
                [$len, $msg, $tip, $flag, $tipNum, $ifd] = $parms + [null, null, null, null, null, null];
                if (!($len > 0)) continue;
                $flag = $flag ?? 0;
                // generate same name for all blocks indexed by this tooltip
                $name = null;
                if ($tipNum !== null) $name = $names[$tipNum] ?? null;
                $idx = $index;
                if ($name) {
                    // get index from existing ID
                    $idx = substr($name, 1);
                } else {
                    $name = "t$index";
                    if ($tipNum !== null) $names[$tipNum] = $name;
                    ++$index;
                }
                if ($flag & 0x14) {
                    $class = ($flag & 0x04) ? "$name M" : $name;
                    if ($ifd) $class .= " $ifd";
                    $bkg = [
                        'Class' => $class,
                        'Start' => $start - $dataPos,
                        'End'   => $start - $dataPos + $len,
                    ];
                    self::$bkgSpan[] = $bkg;
                    if (!(self::$bkgStart and self::$bkgStart < $bkg['Start'])) self::$bkgStart = $bkg['Start'];
                    if (!(self::$bkgEnd and self::$bkgEnd > $bkg['End'])) self::$bkgEnd = $bkg['End'];
                    $this->MSpanList[] = $name;
                    continue;
                }
                // loop until we read the value properly
                $end = 0;
                $buff = '';
                for ($try = 0; $try < 2; ++$try) {
                    $end = $start + $len;
                    // only load as much of the block as we are going to dump
                    // (read 32 more bytes than necessary just in case there
                    // is only one skipped line that we decide to print)
                    $size = ($len > $limit + 32) ? intdiv($limit, 2) + 16 : $len;
                    if ($start >= $dataPos and $end <= $dataEnd) {
                        $buff = substr($dataPt, $start - $dataPos, $size);
                        if ($len != $size) {
                            $buff .= substr($dataPt, $start - $dataPos + $len - $size, $size);
                        }
                    } else {
                        $buff = '';
                        if ($raf->Seek($start, 0) and $raf->Read($buff, $size) == $size) {
                            // read end of block
                            if ($len != $size) {
                                $buf2 = '';
                                if (!($raf->Seek($start + $len - $size, 0) and
                                      $raf->Read($buf2, $size) == $size))
                                {
                                    $err = $msg;
                                    // reset $len to the actual length of available data
                                    $len = $fileLen - $start;
                                    if ($tip) $tip .= "<br>Error: Only $len bytes available!";
                                    continue;
                                }
                                $buff .= $buf2;
                                unset($buf2);
                            }
                        } else {
                            $err = $msg;
                            $len = strlen($buff);
                            if ($tip) $tip .= "<br>Error: Only $len bytes available!";
                        }
                    }
                    break;
                }
                if ($tip) $this->TipList[$idx] = $tip;
                if (!strlen($buff)) continue;
                // set flag to continue this line if next block is contiguous
                if ($i + 1 < $nstarts and $pi === $lastParm and
                    ($end == $starts[$i + 1] or ($end < $starts[$i + 1] and $end >= $pos)))
                {
                    $nextFlag = $block[$starts[$i + 1]][0][3] ?? 0;
                    if (!($flag & 0x01 or $nextFlag & 0x01)) $flag |= 0x100;
                }
                $this->DumpTable($start - $dataPos, $buff, $msg, $name,
                                 $flag, $len, $pos - $dataPos, $ifd);
                $buff = '';
                if ($pos < $end) $pos = $end;
            }
        }
        $this->Open('', '');     // close all open elements
        $raf->Seek($tell, 0);

        // write output HTML file
        self::Write($outfile, self::$htmlHeader1, $title);
        if ($this->Cols[0]) {
            self::Write($outfile, self::$htmlHeader2);
            $mspan = $this->MSpanList;
            for ($i = 0; $i < count($mspan); ++$i) {
                self::Write($outfile, "mspan[$i] = \"$mspan[$i]\";\n");
            }
            self::Write($outfile, self::$htmlHeader3, $this->Cols[0]);
            self::Write($outfile, '</pre></td><td valign="top">',
                                  self::$preMouse, $this->Cols[1]);
            self::Write($outfile, '</pre></td><td class=c2 valign="top">',
                                  self::$preMouse, $this->Cols[2]);
            self::Write($outfile, '</pre></td><td valign="top">',
                                  self::$preMouse, $this->Cols[3]);
            self::Write($outfile, "</pre></td></tr></table>\n<div id=tb class=tb> </div>\n");
            $tips = $this->TipList;
            for ($i = 0; $i < ($tips ? max(array_keys($tips)) + 1 : 0); ++$i) {
                if (isset($tips[$i])) self::Write($outfile, "<div id=p$i class=tt>$tips[$i]</div>\n");
            }
            $this->TipList = [];
            $rtnVal = 1;
        } else {
            $e = $this->Error ?: 'No EXIF or TIFF information found in image';
            self::Write($outfile, "$title</title></head><body>\n$e\n");
            $rtnVal = 0;
        }
        self::Write($outfile, "</body></html>\n");
        for ($i = 0; $i < 4; ++$i) {
            $this->Cols[$i] = '';   // free memory
        }
        if ($err) {
            $this->ERROR = strtr($err, ['(' => '', ')' => '']);
            return -1;
        }
        return $rtnVal;
    }

    //------------------------------------------------------------------------------
    // Open or close a specified html element
    // Inputs: 0) HtmlDump object ref, 1) element id, 2) element string,
    //         3-N) list of column numbers (empty for all columns)
    // - element id may be '' to close all elements
    // - element string may be '' to close element by ID (or 0 to close without reopening)
    // - element id and string may both be 1 to reopen temporarily closed elements
    private function Open($id, $element, ...$colNums): void
    {
        $id = (string) $id;
        $element = (string) $element;
        $idTrue = ($id !== '' and $id !== '0');
        $elTrue = ($element !== '' and $element !== '0');
        // loop through specified columns
        if (!$colNums) $colNums = range(0, count($this->Open) - 1);
        foreach ($colNums as $col) {
            // get information about open elements in this column
            $opElem = &$this->Open[$col]['Element'];
            $opID = &$this->Open[$col]['ID'];
            if ($elTrue) {
                // next if already open
                if (!empty($opElem[$id]) and $opElem[$id] === $element) continue;
            } elseif ($idTrue and empty($opElem[$id])) {
                // next if already closed and nothing to reopen
                if (!($element === '' and $this->Closed[$col]['ID'])) continue;
            }
            $clID = &$this->Closed[$col]['ID'];
            $clElem = &$this->Closed[$col]['Element'];
            // get reference to output column list (use temp list if available)
            if ($this->TmpCols !== null) $cols = &$this->TmpCols; else $cols = &$this->Cols;
            // close everything down to this element if necessary
            if (!empty($opElem[$id]) or !$idTrue) {
                while ($opID) {
                    $tid = (string) array_pop($opID);
                    $e = preg_replace('/^<(\S+).*/s', '</$1>', $opElem[$tid]);
                    $cols[$col] .= $e;
                    if ($id === $tid or !$idTrue) {
                        unset($opElem[$tid]);
                        if ($idTrue) break;
                        continue;
                    }
                    // add this to the temporarily closed list
                    // (because we really didn't want to close it)
                    $clID[] = $tid;
                    $clElem[$tid] = $opElem[$tid];
                    unset($opElem[$tid]);
                }
                if (!$idTrue) {
                    // forget all temporarily closed elements
                    $clID = [];
                    $clElem = [];
                }
            } elseif (!empty($clElem[$id])) {
                // delete from the list of temporarily closed elements
                unset($clElem[$id]);
                $clID = array_values(array_filter($clID, fn($x) => (string) $x !== $id));
            }
            if ($element === '0') { // 0 = don't reopen temporarily closed elements
                unset($opElem, $opID, $clID, $clElem, $cols);
                continue;
            }

            // re-open temporarily closed elements
            while ($clID) {
                $tid = (string) array_pop($clID);
                $cols[$col] .= $clElem[$tid];
                $opID[] = $tid;
                $opElem[$tid] = $clElem[$tid];
                unset($clElem[$tid]);
            }
            // open specified element
            if ($elTrue and $element !== '1') {
                $cols[$col] .= $element;
                $opID[] = $id;
                $opElem[$id] = $element;
            }
            unset($opElem, $opID, $clID, $clElem, $cols);
        }
    }

    //------------------------------------------------------------------------------
    // Dump a block of data in HTML table form
    // Inputs: 0) HtmlDump object ref, 1) data position, 2) block data,
    //         3) message, 4) object name, 5) flag, 6) full block length (actual
    //         data may be shorter), 7) data end position, 8) IFD name
    private function DumpTable($pos, $blockPt, $msg = null, $name = null, $flag = null, $len = null, $endPos = null, $ifd = null): void
    {
        $len = $len ?? strlen($blockPt);
        $endPos = $endPos ?: 0;
        $flag = $flag ?? 0;
        $skipped = 0;
        $dblRef = null;
        $id = null;
        if (($endPos and $pos < $endPos) or $flag & 0x02) {
            // display double-reference addresses in red
            $f0 = '<span class=V>';
            if ($endPos and $pos < $endPos) $dblRef = 1;
        } else {
            $f0 = '';
        }
        $c = ['', '', '', ''];
        $this->TmpCols = &$c;
        if ($name) {
            if ($msg and $msg[0] === '[') {
                $id = 'U';
            } else {
                if ($this->A) {
                    $id = 'X';
                    $this->A = 0;
                } else {
                    $id = 'V';
                    $this->A = 1;
                }
                if (!$dblRef) $id = chr(ord($id) + 1);
            }
            $class = $ifd ? "'$id $ifd'" : $id;
            $name = "<a name=$name class=$class>";
            if ($msg) $msg = "$name$msg</a>";
        } else {
            $name = '';
        }
        // use base-relative offsets from now on
        $cols = 0;
        $p = $pos;
        if ($this->Cont) {
            $cols = $pos & 0x0f;
            $c[1] .= ($cols == 8) ? '  ' : ' ';
        } else {
            $addr = $pos < 0 ? sprintf('-%04x', -$pos) : sprintf('%5x', $pos);
            $this->Open('fgd', $f0, 0);
            $this->Open('fgd', '', 3);
            $c[0] .= $addr;
            if (!($flag & 0x01)) $p -= $pos & 0x0f;
            if ($p < $pos) {
                $this->Open('bkg', '', 1, 2); // don't underline white space
                $cols = $pos - $p;
                $n = 3 * $cols;
                if ($cols > 7) ++$n;
                $c[1] .= str_repeat(' ', $n);
                $c[2] .= str_repeat(' ', $cols);
                $p = $pos;
            }
        }
        // loop through each column of hex numbers
        for (;;) {
            $spanClass = [];
            $spanCont = [];
            $spanClose = null;
            if ($p >= self::$bkgStart and $p < self::$bkgEnd) {
                foreach (self::$bkgSpan as $bkg) {
                    if (!($p >= $bkg['Start'] and $p < $bkg['End'])) continue;
                    $spanClass[] = $bkg['Class'];
                    if ($p + 1 == $bkg['End']) {
                        $spanClose = 1;
                    } else {
                        $spanCont[] = $bkg['Class'];   // this span continues
                    }
                }
                $this->Open('bkg', $spanClass ? "<span class='" . implode(' ', $spanClass) . "'>" : '', 1, 2);
            } else {
                $this->Open('bkg', '', 1, 2);
            }
            $this->Open('a', $name, 1, 2);
            $ch = substr($blockPt, $p - $pos - $skipped, 1);
            $c[1] .= sprintf('%02x', $ch === '' ? 0 : ord($ch));
            // make the character HTML-friendly
            $ch = preg_replace('/[\x00-\x1f\x7f-\xff]/', '.', $ch);
            $ch = str_replace(['&', '>', '<'], ['&amp;', '&gt;', '&lt;'], $ch);
            $c[2] .= $ch;
            ++$p;
            ++$cols;
            // close necessary elements
            if ($spanClose) {
                $spanContStr = $spanCont ? "<span class='" . implode(' ', $spanCont) . "'>" : '';
                // close without reopening if closing anchor later
                $arg = ($p - $pos >= $len) ? 0 : $spanContStr;
                $this->Open('bkg', $arg, 1, 2);
            }
            if ($dblRef and $p >= $endPos) {
                $dblRef = 0;
                $id = chr(ord($id) + 1);
                $class = $ifd ? "'$id $ifd'" : $id;
                $name = preg_replace('/class=\w\b/', "class=$class", $name, 1);
                $f0 = '';
                $this->Open('fgd', $f0, 0);
            }
            if ($p - $pos >= $len) {
                $this->Open('a', '', 1, 2);     // close our anchor
                break;
            }
            if ($cols < 16) {
                $c[1] .= ($cols == 8 ? '  ' : ' ');
                continue;
            } elseif ($flag & 0x01 and $cols < $len) {
                $c[1] .= ' ';
                continue;   // put it all on one line
            }
            if (!$this->Msg) {
                $c[3] .= $msg;
                $msg = '';
            }
            foreach ($c as &$cc) $cc .= "\n";   // add CR to all lines
            unset($cc);
            $this->Msg = 0;
            // limit data length if specified
            if ($this->Limit) {
                $div = ($flag & 0x08) ? 4 : 1;
                $lim = intdiv($this->Limit, 2 * $div) - 16;
                if ($p - $pos > $lim and $len - $p + $pos > $lim) {
                    $n = ($len - $p + $pos - $lim) & ~0x0f;
                    if ($n > 16) { // (no use just cutting out one line)
                        $this->Open('bkg', '', 1, 2); // no underline
                        $note = sprintf('[snip %d lines]', intdiv($n, 16));
                        $note = str_repeat(' ', max(0, (int) (24 - strlen($note) / 2))) . $note;
                        $c[0] .= "  ...\n";
                        $c[1] .= $note . str_repeat(' ', max(0, 48 - strlen($note))) . "\n";
                        $c[2] .= "     [snip]     \n";
                        $c[3] .= "\n";
                        $p += $n;
                        $skipped += $len - strlen($blockPt);
                    }
                }
            }
            $c[0] .= ($p < 0 ? sprintf('-%04x', -$p) : sprintf('%5x', $p));
            $cols = 0;
        }
        if ($msg) {
            if ($this->Msg) $msg = " $msg";
            $c[3] .= $msg;
        }
        if ($flag & 0x100 and $cols < 16) {    // continue on same line?
            $this->Cont = 1;
            if ($msg) $this->Msg = 1;
        } else {
            foreach ($c as &$cc) $cc .= "\n";
            unset($cc);
            $this->Msg = 0;
            $this->Cont = 0;
        }
        // add temporary column data to our real columns
        $this->TmpCols = null;
        for ($i = 0; $i < 4; ++$i) {
            $this->Cols[$i] .= $c[$i];
        }
    }

    //------------------------------------------------------------------------------
    // Finish dumping of TIFF image data
    // Inputs: 0) HtmlDump object ref, 1) ExifTool object ref, 2) length of file
    // (this really belongs in Image::ExifTool::Exif, but is placed here so it
    //  is only compiled when needed)
    public function FinishTiffDump(ExifTool $et, $size): void
    {
        // list of all indirectly referenced TIFF data tags
        $offsetPair = [
            'StripOffsets'      => 'StripByteCounts',
            'TileOffsets'       => 'TileByteCounts',
            'FreeOffsets'       => 'FreeByteCounts',
            'ThumbnailOffset'   => 'ThumbnailLength',
            'PreviewImageStart' => 'PreviewImageLength',
            'JpgFromRawStart'   => 'JpgFromRawLength',
            'OtherImageStart'   => 'OtherImageLength',
            'PreviewJXLStart'   => 'PreviewJXLLength',
            'ImageOffset'       => 'ImageByteCount',
            'AlphaOffset'       => 'AlphaByteCount',
            'MPImageStart'      => 'MPImageLength',
            'IDCPreviewStart'   => 'IDCPreviewLength',
            'SamsungRawPointersOffset' => 'SamsungRawPointersLength',
        ];

        // add TIFF data to html dump
        foreach ($offsetPair as $tag => $countTag) {
            $info = $et->GetInfo($tag);
            if (!$info) continue;
            // Panasonic hack: StripOffsets is not valid for Panasonic RW2 files,
            // and StripRowBytes is not valid for some RAW images
            if ($tag === 'StripOffsets' and !empty($et->TAG_INFO[$tag]->PanasonicHack)) {
                // use RawDataOffset instead if available since it is valid in RW2
                $info2 = $et->GetInfo('RawDataOffset');
                if (!$info2) $info2 = $info;
                $keys = array_keys($info2);
                $offset = $info2[$keys[0]];
                $raf = $et->RAF;
                // ignore StripByteCounts and assume raw data runs to the end of file
                if (count($keys) == 1 and preg_match('/^\d+$/', Perl::str($offset)) and $raf) {
                    $pos = $raf->Tell();
                    $raf->Seek(0, 2);   // seek to end
                    $len = $raf->Tell() - $offset;
                    $raf->Seek($pos, 0);
                    if ($len > 0) {
                        $this->Add($offset, $len, '(Panasonic raw data)', "Size: $len bytes", 0x08);
                        continue;
                    }
                }
            }
            // loop through all offsets tags
            foreach ($info as $key => $offsets) {
                $key = (string) $key;
                $grp1 = $et->GetGroup($key, 1);
                $info2 = $et->GetInfo($countTag, ['Group1' => $grp1]);
                $key2 = $countTag;
                if (preg_match('/( .*)/', $key, $m)) $key2 .= $m[1]; // use same instance number as $tag
                if (empty($info2[$key2])) continue;
                $byteCounts = $info2[$key2];
                // ignore primary MPImage (this is the whole JPEG)
                if ($tag === 'MPImageStart' and Perl::str($offsets) === '0') continue;
                // (long lists may be SCALAR references)
                $offs = Perl::split(' ', Perl::str($offsets instanceof ScalarRef ? $offsets->value : $offsets));
                $bcs = Perl::split(' ', Perl::str($byteCounts instanceof ScalarRef ? $byteCounts->value : $byteCounts));
                $num = count($offs);
                $li = 0;
                $padBytes = 0;
                for ($i = 0; $offs and $bcs; ++$i) {
                    $offset = array_shift($offs);
                    $byteCount = array_shift($bcs);
                    $end = $offset + $byteCount;
                    if ($offs and $bcs) {
                        // show data as contiguous if only normal pad bytes between blocks
                        if ($end & 0x01 and $end + 1 == $offs[0]) {
                            $end += 1;
                            ++$padBytes;    // count them
                        }
                        if ($end == $offs[0]) {
                            // combine these two blocks
                            $bcs[0] += $offs[0] - $offset;
                            $offs[0] = $offset;
                            continue;
                        }
                    }
                    $msg = $et->GetGroup($key, 1) . ':' . $tag;
                    $msg = preg_replace('/(Offsets?|Start)$/', ' ', $msg);
                    if ($num > 1) {
                        if ($li != $i) $msg .= "$li-";
                        $msg .= "$i ";
                        $li = $i + 1;
                    }
                    $msg .= 'data';
                    $tip = "Size: $byteCount bytes";
                    if ($padBytes) $tip .= ", incl. $padBytes pad bytes";
                    $this->Add($offset, $byteCount, "($msg)", $tip, 0x08);
                }
            }
        }
        // find offset of last dumped information, and dump any unknown trailer
        $last = 0;
        foreach ($this->Block as $start => $infos) {
            foreach ($infos as $blockInfo) {
                $end = $start + $blockInfo[0];
                if ($last < $end) $last = $end;
            }
        }
        $diff = $size - $last;
        if ($diff > 0 and ($last or $et->Options('Unknown'))) {
            if ($diff > 1 or $size & 0x01) {
                $this->Add($last, $diff, '[unknown data]', "Size: $diff bytes", 0x08);
            } else {
                $this->Add($last, $diff, '[trailing pad byte]', null, 0x08);
            }
        }
    }

    //------------------------------------------------------------------------------
    // utility routine to write to file or memory
    // Inputs: 0) file or ScalarRef, 1-N) list of stuff to write
    // Returns: true on success
    private static function Write($outfile, ...$args): bool
    {
        if (is_resource($outfile)) {
            return fwrite($outfile, implode('', $args)) !== false;
        } elseif ($outfile instanceof ScalarRef) {
            $outfile->value .= implode('', $args);
            return true;
        }
        return false;
    }
}
