<?php

//------------------------------------------------------------------------------
// File:         Fixup.pm
//
// Description:  Utility to handle pointer fixups
//
// Revisions:    01/19/2005 - P. Harvey Created
//               04/11/2005 - P. Harvey Allow fixups to be tagged with a marker,
//                            and add new marker-related routines
//               06/21/2006 - P. Harvey Patch to work with negative offsets
//               07/07/2006 - P. Harvey Added support for 16-bit pointers
//               02/19/2013 - P. Harvey Added IsEmpty()
//
// Data Members:
//
//   Start     - Position in data where a zero pointer points to.
//   Shift     - Amount to shift offsets (relative to Start).
//   Fixups    - List of Fixup object references to to shift relative to this Fixup.
//   Pointers  - Hash of references to fixup pointer arrays, keyed by ByteOrder
//               string (with "2" added if pointer is 16-bit [default is 32-bit],
//               plus "_$marker" suffix if tagged with a marker name).
//
// Procedure:
//
//            1. Create a Fixup object for each data block containing pointers
//            2. Call AddFixup with the offset of each pointer in the block
//               - pointer is assumed int32u with the current byte order
//               - may also be called with a fixup reference for contained blocks
//            3. Add the necessary pointer offset to $$fixup{Shift}
//            4. Add data size to $$fixup{Start} if data is added before the block
//               - automatically also shifts pointers by this amount
//            5. Call ApplyFixup to apply the fixup to all pointers
//               - resets Shift and Start to 0 after applying fixup
//
// PHP port of Image::ExifTool::Fixup 1.06. The data members are properties ($fixup->Start,
// $fixup->Pointers['MM_PreviewImage']...); the data of ApplyFixup and SetMarkerPointers is
// passed by reference, GetMarkerPointers returns the first pointer (Perl scalar context) and
// GetMarkerPointersList all of them.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

#[\AllowDynamicProperties]
class Fixup
{
    public static $VERSION = '1.06';

    public $Start = 0;
    public $Shift = 0;
    /** @var list<Fixup>|null */
    public $Fixups = null;
    /** @var array<string, list<int>>|null */
    public $Pointers = null;

    /** a missing member reads as undef, like the Perl hash */
    public function &__get(string $name): mixed
    {
        $null = null;
        return $null;
    }

    public function __isset(string $name): bool
    {
        return false;
    }

    //------------------------------------------------------------------------------
    // New - create new Fixup object
    // Inputs: 0) reference to Fixup object or Fixup class name
    public function __construct()
    {
        // initialize required members
        $this->Start = 0;
        $this->Shift = 0;
    }

    //------------------------------------------------------------------------------
    // Clone this object
    // Inputs: 0) reference to Fixup object or Fixup class name
    // Returns: reference to new Fixup object
    public function Clone(): Fixup
    {
        $clone = new Fixup();
        $clone->Start = $this->Start;
        $clone->Shift = $this->Shift;
        $phash = $this->Pointers;
        if ($phash) {
            $clone->Pointers = [];
            foreach ($phash as $byteOrder => $pointers) {
                $clone->Pointers[$byteOrder] = $pointers;   // (PHP arrays are copied)
            }
        }
        if ($this->Fixups) {
            $clone->Fixups = [];
            foreach ($this->Fixups as $subFixup) {
                $clone->Fixups[] = $subFixup->Clone();
            }
        }
        return $clone;
    }

    //------------------------------------------------------------------------------
    // Add fixup pointer or another fixup object below this one
    // Inputs: 0) Fixup object reference
    //         1) Scalar for pointer offset, or reference to Fixup object
    //         2) Optional marker name for the pointer
    //         3) Optional pointer format ('int16u' or 'int32u', defaults to 'int32u')
    // Notes: Byte ordering must be set properly for the pointer being added (must keep
    // track of the byte order of each offset since MakerNotes may have different byte order!)
    public function AddFixup($pointer, $marker = null, $format = null): void
    {
        if ($pointer instanceof Fixup) {
            $this->Fixups ??= [];
            $this->Fixups[] = $pointer;
        } else {
            $byteOrder = ExifTool::GetByteOrder();
            if ($format !== null) {
                if ($format === 'int16u') {
                    $byteOrder .= '2';
                } elseif ($format !== 'int32u') {
                    ExifTool::PerlWarn("Bad Fixup pointer format $format\n");
                }
            }
            if ($marker !== null) $byteOrder .= "_$marker";
            $this->Pointers ??= [];
            $this->Pointers[$byteOrder] ??= [];
            $this->Pointers[$byteOrder][] = $pointer;
        }
    }

    //------------------------------------------------------------------------------
    // fix up pointer offsets
    // Inputs: 0) Fixup object reference, 1) data reference
    // Outputs: Collapses fixup hierarchy into linear lists of fixup pointers
    public function ApplyFixup(&$dataPt): void
    {
        $start = $this->Start;
        $shift = $this->Shift + $start;    // make shift relative to start

        // fix up pointers in this fixup
        if ($this->Pointers and ($start or $shift)) {
            $saveOrder = ExifTool::GetByteOrder(); // save original byte ordering
            foreach ($this->Pointers as $byteOrder => &$ptrs) {
                ExifTool::SetByteOrder(substr($byteOrder, 0, 2));
                // apply the fixup offset shift (must get as signed integer
                // to avoid overflow in case it was negative before)
                [$get, $set] = preg_match('/^(II2|MM2)/', $byteOrder) ?
                               ['Get16s', 'Set16u'] : ['Get32s', 'Set32u'];
                foreach ($ptrs as &$ptr) {
                    $ptr += $start;         // update pointer to new start location
                    if (!$shift) continue;
                    ExifTool::$set(ExifTool::$get($dataPt, $ptr) + $shift, $dataPt, $ptr);
                }
                unset($ptr);
            }
            unset($ptrs);
            ExifTool::SetByteOrder($saveOrder);   // restore original byte ordering
        }
        // recurse into contained fixups
        if ($this->Fixups) {
            // create our pointer hash if it doesn't exist
            $this->Pointers ??= [];
            // loop through all contained fixups
            foreach ($this->Fixups as $subFixup) {
                // adjust the subfixup start and shift
                $subFixup->Start += $start;
                $subFixup->Shift += $shift - $start;
                // recursively apply contained fixups
                $subFixup->ApplyFixup($dataPt);
                $shash = $subFixup->Pointers;
                if (!$shash) continue;
                // add all pointers to our collapsed lists
                foreach ($shash as $byteOrder => $pointers) {
                    $this->Pointers[$byteOrder] ??= [];
                    array_push($this->Pointers[$byteOrder], ...$pointers);
                }
                $subFixup->Pointers = null;
            }
            $this->Fixups = null;   // remove our contained fixups
        }
        // reset our Start/Shift for the collapsed fixup
        $this->Start = $this->Shift = 0;
    }

    //------------------------------------------------------------------------------
    // Is this Fixup empty?
    // Inputs: 0) Fixup object ref
    // Returns: True if there are no offsets to fix
    public function IsEmpty()
    {
        if ($this->Pointers) {
            foreach ($this->Pointers as $pointers) {
                if (!is_array($pointers)) continue;
                if ($pointers) return 0;
            }
        }
        return 1;
    }

    //------------------------------------------------------------------------------
    // Does specified marker exist?
    // Inputs: 0) Fixup object reference, 1) marker name
    // Returns: True if fixup contains specified marker name
    public function HasMarker($marker)
    {
        $phash = $this->Pointers;
        if (!$phash) return 0;
        if (preg_grep("/_{$marker}\$/", array_map('strval', array_keys($phash)))) return 1;
        if (!$this->Fixups) return 0;
        foreach ($this->Fixups as $subFixup) {
            if ($subFixup->HasMarker($marker)) return 1;
        }
        return 0;
    }

    //------------------------------------------------------------------------------
    // Set all marker pointers to specified value
    // Inputs: 0) Fixup object reference, 1) data reference
    //         2) marker name, 3) pointer value, 4) offset to start of data
    public function SetMarkerPointers(&$dataPt, $marker, $value, $startOffset = null): void
    {
        $start = $this->Start + ($startOffset ?: 0);

        if ($this->Pointers) {
            $saveOrder = ExifTool::GetByteOrder(); // save original byte ordering
            foreach ($this->Pointers as $byteOrder => $pointers) {
                if (!preg_match("/^(II|MM)(2?)_{$marker}\$/", (string) $byteOrder, $m)) continue;
                ExifTool::SetByteOrder($m[1]);
                $set = $m[2] ? 'Set16u' : 'Set32u';
                foreach ($pointers as $ptr) {
                    ExifTool::$set($value, $dataPt, $ptr + $start);
                }
            }
            ExifTool::SetByteOrder($saveOrder);   // restore original byte ordering
        }
        if ($this->Fixups) {
            foreach ($this->Fixups as $subFixup) {
                $subFixup->SetMarkerPointers($dataPt, $marker, $value, $start);
            }
        }
    }

    //------------------------------------------------------------------------------
    // Get pointer values for specified marker
    // Inputs: 0) Fixup object reference, 1) data reference,
    //         2) marker name, 3) offset to start of data
    // Returns: List of marker pointers in list context, or first marker pointer otherwise
    //          (PHP: list context is GetMarkerPointersList)
    public function GetMarkerPointers($dataPt, $marker, $startOffset = null)
    {
        return $this->GetMarkerPointersList($dataPt, $marker, $startOffset)[0] ?? null;
    }

    public function GetMarkerPointersList($dataPt, $marker, $startOffset = null): array
    {
        $start = $this->Start + ($startOffset ?: 0);
        $pointers = [];

        if ($this->Pointers) {
            $saveOrder = ExifTool::GetByteOrder();
            foreach ($this->Pointers as $byteOrder => $ptrs) {
                if (!preg_match("/_{$marker}\$/", (string) $byteOrder)) continue;
                ExifTool::SetByteOrder(substr($byteOrder, 0, 2));
                $get = preg_match('/^(II2|MM2)/', $byteOrder) ? 'Get16u' : 'Get32u';
                foreach ($ptrs as $ptr) {
                    $pointers[] = ExifTool::$get($dataPt, $ptr + $start);
                }
            }
            ExifTool::SetByteOrder($saveOrder);   // restore original byte ordering
        }
        if ($this->Fixups) {
            foreach ($this->Fixups as $subFixup) {
                array_push($pointers, ...$subFixup->GetMarkerPointersList($dataPt, $marker, $start));
            }
        }
        return $pointers;
    }

    //------------------------------------------------------------------------------
    // Dump fixup to console for debugging
    // Inputs: 0) Fixup object reference, 1) optional initial indent string
    public function Dump($indent = null): void
    {
        $indent = $indent ?: '';
        echo Perl::sprintf("{$indent}Fixup start=0x%x shift=0x%x\n", $this->Start, $this->Shift);
        $phash = $this->Pointers;
        if ($phash) {
            $keys = array_map('strval', array_keys($phash));
            sort($keys, SORT_STRING);
            foreach ($keys as $byteOrder) {
                echo "$indent  $byteOrder: ", implode(' ', $phash[$byteOrder]), "\n";
            }
        }
        if ($this->Fixups) {
            foreach ($this->Fixups as $subFixup) {
                $subFixup->Dump($indent . '  ');
            }
        }
    }
}
