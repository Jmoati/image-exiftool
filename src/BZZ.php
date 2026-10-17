<?php

//------------------------------------------------------------------------------
// File:         BZZ.pm
//
// Description:  Utility to decode BZZ compressed data
//
// Revisions:    09/22/2008 - P. Harvey Created
//
// References:   1) http://djvu.sourceforge.net/
//               2) http://www.djvu.org/
//
// Notes:        This code based on ZPCodec and BSByteStream of DjVuLibre 3.5.21
//               (see NOTES documentation below for license/copyright details)
//
// PHP port of Image::ExifTool::BZZ 1.01. The ZP-coder tables (@default_ztable_*) are
// generated into tables/vars/BZZ.php. Perl "use integer" arithmetic = PHP int arithmetic.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class BZZ
{
    public static $VERSION = '1.01';

    // constants
    public const FREQMAX = 4;
    public const CTXIDS = 3;
    public const MAXBLOCK = 4096;

    // (members of the Perl BZZ object hash)
    public $ffzt, $p, $m, $up, $dn, $ctx, $DataPt, $Pos, $DataLen, $a, $buffer, $fence,
        $blocksize, $code, $byte, $delay, $scount, $size;

    //------------------------------------------------------------------------------
    // New - create new BZZ object
    // Inputs: 0) reference to BZZ object or BZZ class name
    // Returns: blessed BZZ object ref
    public static function new()
    {
        return new self();
    }

    //------------------------------------------------------------------------------
    // Initialize BZZ object
    // Inputs: 0) BZZ object ref, 1) data ref, 2) true for DjVu compatibility
    public function Init($dataPt, $djvucompat = null)
    {
        // Create machine independent ffz table
        $ffzt = [];
        for ($i = 0; $i < 256; $i++) {
            $ffzt[$i] = 0;
            for ($j = $i; $j & 0x80; $j <<= 1) {
                $ffzt[$i] += 1;
            }
        }
        $this->ffzt = $ffzt;
        // Initialize table
        $this->p = Vars\BZZ::$default_ztable_p;
        $this->m = Vars\BZZ::$default_ztable_m;
        $this->up = Vars\BZZ::$default_ztable_up;
        $this->dn = Vars\BZZ::$default_ztable_dn;
        // Patch table (and lose DjVu compatibility)
        if (!$djvucompat) {
            [$p, $m] = [$this->p, $this->m];
            for ($j = 0; $j < 256; $j++) {
                $a = (0x10000 - $p[$j]) & 0xffff;
                while ($a >= 0x8000) { $a = ($a << 1) & 0xffff; }
                if ($m[$j] > 0 && $a + $p[$j] >= 0x8000 && $a >= $m[$j]) {
                    $this->dn[$j] = Vars\BZZ::$default_ztable_dn[Vars\BZZ::$default_ztable_dn[$j]];
                }
            }
        }
        $this->ctx = array_fill(0, 300, 0);
        $this->DataPt = $dataPt;
        $this->Pos = 0;
        $this->DataLen = strlen($dataPt);
        $this->a = 0;
        $this->buffer = 0;
        $this->fence = 0;
        $this->blocksize = 0;
        // Read first 16 bits of code
        if (strlen($dataPt) >= 2) {
            $this->code = Perl::unpack('n', $dataPt)[0];
            $this->Pos += 2;
        } elseif (strlen($dataPt) >= 1) {
            $this->code = (ord($dataPt[0]) << 8) | 0xff;
            $this->Pos++;
        } else {
            $this->code = 0xffff;
        }
        $this->byte = $this->code & 0xff;
        // Preload buffer
        $this->delay = 25;
        $this->scount = 0;
        // Compute initial fence
        $this->fence = $this->code >= 0x8000 ? 0x7fff : $this->code;
    }

    //------------------------------------------------------------------------------
    // Decode data block
    // Inputs: 0) optional BZZ object ref, 1) optional data ref
    // Returns: decoded data or undefined on error
    // Notes: If called without a data ref, an input BZZ object ref must be given and
    //        the BZZ object must have been initialized by a previous call to Init()
    public static function Decode($self, $dataPt = null)
    {
        // Decode input stream
        if (!$self instanceof self) {
            $dataPt = $self;
            $self = self::new();
        }
        if ($dataPt !== null) {
            $self->Init($dataPt, 1);
        } else {
            $dataPt = $self->DataPt;
            if ($dataPt === null) return null;
        }
        // Decode block size
        $n = 1;
        $m = (1 << 24);
        while ($n < $m) {
            $b = $self->decode_sub(0x8000 + ($self->a >> 1));
            $n = ($n << 1) | $b;
        }
        $self->size = $n - $m;

        if (!$self->size) return '';
        if ($self->size > self::MAXBLOCK * 1024) return null;
        // Allocate
        if ($self->blocksize < $self->size) {
            $self->blocksize = $self->size;
        }
        // Decode Estimation Speed
        $fshift = 0;
        if ($self->decode_sub(0x8000 + ($self->a >> 1))) {
            $fshift += 1;
            if ($self->decode_sub(0x8000 + ($self->a >> 1))) $fshift += 1;
        }
        // Prepare Quasi MTF
        $mtf = range(0, 255);
        $freq = array_fill(0, self::FREQMAX, 0);
        $fadd = 4;
        // Decode
        $mtfno = 3;
        $markerpos = -1;
        $cx = &$self->ctx;
        $dat = [];
        for ($i = 0; $i < $self->size; $i++) {      // byte:
            // dummy loop avoids use of "goto" statement
            for (;;) {                              // dummy:
                $ctxid = self::CTXIDS - 1;
                if ($ctxid > $mtfno) $ctxid = $mtfno;
                $cp = 0;
                for ($imtf = 0; $imtf < 2; ++$imtf) {
                    if ($self->decoder($cx[$cp + $ctxid])) {
                        $mtfno = $imtf;
                        $dat[$i] = $mtf[$mtfno];
                        // (a "goto" here could give a segfault due to a Perl bug)
                        break 2; // do rotation
                    }
                    $cp += self::CTXIDS;
                }
                for ($bits = 1; $bits < 8; ++$bits, $imtf <<= 1) {
                    if ($self->decoder($cx[$cp])) {
                        $n = 1;
                        $m = (1 << $bits);
                        while ($n < $m) {
                            $b = $self->decoder($cx[$cp + $n]);
                            $n = ($n << 1) | $b;
                        }
                        $mtfno = $imtf + $n - $m;
                        $dat[$i] = $mtf[$mtfno];
                        break 2; // do rotation
                    }
                    $cp += $imtf;
                }
                $mtfno = 256;
                $dat[$i] = 0;
                $markerpos = $i;
                continue 2;  // no rotation necessary
            }
            // Rotate mtf according to empirical frequencies (new!)
            // Adjust frequencies for overflow
            $fadd = $fadd + ($fadd >> $fshift);
            if ($fadd > 0x10000000) {
                $fadd >>= 24;
                foreach ($freq as &$f) $f >>= 24;
                unset($f);
            }
            // Relocate new char according to new freq
            $fc = $fadd;
            if ($mtfno < self::FREQMAX) $fc += $freq[$mtfno];
            for ($k = $mtfno; $k >= self::FREQMAX; $k--) {
                $mtf[$k] = $mtf[$k - 1];
            }
            for (; $k > 0 && $fc >= $freq[$k - 1]; $k--) {
                $mtf[$k] = $mtf[$k - 1];
                $freq[$k] = $freq[$k - 1];
            }
            $mtf[$k] = $dat[$i];
            $freq[$k] = $fc;
            // when "goto" was used, Perl 5.8.6 could segfault here
            // unless "next" was explicitly stated
        }
        unset($cx);
        //
        // Reconstruct the string
        //
        if ($markerpos < 1 || $markerpos >= $self->size) return null;
        // Allocate pointers
        // Prepare count buffer
        $count = array_fill(0, 256, 0);
        $posn = [];
        // Fill count buffer
        for ($i = 0; $i < $markerpos; $i++) {
            $c = $dat[$i];
            $posn[$i] = ($c << 24) | ($count[$c]++ & 0xffffff);
        }
        $posn[$i++] = 0; // (initialize marker entry just to be safe)
        for ( ; $i < $self->size; $i++) {
            $c = $dat[$i];
            $posn[$i] = ($c << 24) | ($count[$c]++ & 0xffffff);
        }
        // Compute sorted char positions
        $last = 1;
        for ($i = 0; $i < 256; $i++) {
            $tmp = $count[$i];
            $count[$i] = $last;
            $last += $tmp;
        }
        // Undo the sort transform
        $i = 0;
        $last = $self->size - 1;
        while ($last > 0) {
            $n = $posn[$i];
            $c = $n >> 24;
            $dat[--$last] = $c;
            $i = $count[$c] + ($n & 0xffffff);
        }
        // Final check and return decoded data
        if ($i != $markerpos) return null;
        array_pop($dat);   // (last byte isn't real)
        return pack('C*', ...$dat);
    }

    //------------------------------------------------------------------------------
    // Inputs: 0) BZZ object ref, 1) ctx
    // Returns: decoded bit
    public function decoder(&$ctx)
    {
        $z = $this->a + $this->p[$ctx];
        if ($z <= $this->fence) {
            $this->a = $z;
            return ($ctx & 1);
        }
        // must pass $_[1] so subroutine can modify value (darned C++ pass-by-reference!)
        return $this->decode_sub($z, $ctx);
    }

    //------------------------------------------------------------------------------
    // Inputs: 0) BZZ object ref, 1) z, 2) ctx (or undef)
    // Returns: decoded bit
    public function decode_sub($z, &$ctx = null)
    {
        // ensure that we have at least 16 bits of encoded data available
        if ($this->scount < 16) {
            // preload byte by byte until we have at least 24 bits
            while ($this->scount <= 24) {
                if ($this->Pos < $this->DataLen) {
                    $this->byte = ord($this->DataPt[$this->Pos]);
                    ++$this->Pos;
                } else {
                    $this->byte = 0xff;
                    if (--$this->delay < 1) {
                        // setting size to zero forces error return from Decode()
                        $this->size = 0;
                        return 0;
                    }
                }
                $this->buffer = ($this->buffer << 8) | $this->byte;
                $this->scount += 8;
            }
        }
        // Save bit
        $a = $this->a;
        if ($ctx !== null) {
            $bit = ($ctx & 1);
            // Avoid interval reversion
            $d = 0x6000 + (($z + $a) >> 2);
            if ($z > $d) $z = $d;
        } else {
            $bit = 0;
        }
        // Test MPS/LPS
        if ($z > ($code = $this->code)) {
            $bit ^= 1;
            // LPS branch
            $z = 0x10000 - $z;
            $a += $z;
            $code += $z;
            // LPS adaptation
            if ($ctx !== null) $ctx = $this->dn[$ctx];
            // LPS renormalization
            $sft = $a >= 0xff00 ? $this->ffzt[$a & 0xff] + 8 : $this->ffzt[($a >> 8) & 0xff];
            $this->scount -= $sft;
            $this->a = ($a << $sft) & 0xffff;
            $code = (($code << $sft) & 0xffff) | (($this->buffer >> $this->scount) & ((1 << $sft) - 1));
        } else {
            // MPS adaptation
            if ($ctx !== null and $a >= $this->m[$ctx]) $ctx = $this->up[$ctx];
            // MPS renormalization
            $this->scount -= 1;
            $this->a = ($z << 1) & 0xffff;
            $code = (($code << 1) & 0xffff) | (($this->buffer >> $this->scount) & 1);
        }
        // Adjust fence and save new code
        $this->fence = $code >= 0x8000 ? 0x7fff : $code;
        $this->code = $code;
        return $bit;
    }
}
