<?php

//------------------------------------------------------------------------------
// File:         NikonCustom.pm
//
// Description:  Read and write Nikon Custom settings
//
// Revisions:    2009/11/25 - P. Harvey Created
//
// References:   1) Warren Hatch private communication (D3 and Z9 with SB-800 and SB-900)
//               2) Anonymous contribution 2011/05/25 (D700, D7000)
//              JD) Jens Duttke private communication
//
// PHP port of Image::ExifTool::NikonCustom 1.29. The module has no code: its tag tables are
// generated into tables/NikonCustom.php, %buttonsZ8 and %buttonsZ9 into Vars\NikonCustom.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class NikonCustom
{
    public static $VERSION = '1.29';
}
