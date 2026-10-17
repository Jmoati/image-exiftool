<?php

//------------------------------------------------------------------------------
// File:         LIF.pm
//
// Description:  Read LIF (Leica Image File) files
//
// Revisions:    2021-06-21 - P. Harvey Created
//
// PHP port of Image::ExifTool::LIF 1.02 (reading only). Tag tables are generated
// into tables/LIF.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class LIF
{
    public static $VERSION = '1.02';

    //------------------------------------------------------------------------------
    // Shorten obscenely long LIF tag names
    // Inputs: Tag name
    // Returns: Shortened tag name
    public static function ShortenTagNames($_)
    {
        $_ = preg_replace('/DescriptionDimensionsDimensionDescription/', 'Dimensions', $_, 1);
        $_ = preg_replace('/DescriptionChannelsChannelDescription/', 'Channel', $_, 1);
        $_ = preg_replace('/ShutterListShutter/', 'Shutter', $_, 1);
        $_ = preg_replace('/SettingDefinition/', 'Setting', $_, 1);
        $_ = preg_replace('/AdditionalZPositionListAdditionalZPosition/', 'AdditionalZPosition', $_, 1);
        $_ = preg_replace('/LMSDataContainerHeader/', '', $_);
        $_ = preg_replace('/FilterWheelWheel/', 'FilterWheel', $_, 1);
        $_ = preg_replace('/FilterWheelFilter/', 'FilterWheel', $_, 1);
        $_ = preg_replace('/DetectorListDetector/', 'Detector', $_, 1);
        $_ = preg_replace('/OnlineDyeSeparationOnlineDyeSeparation/', 'OnlineDyeSeparation', $_, 1);
        $_ = preg_replace('/AotfListAotf/', 'Aotf', $_, 1);
        $_ = preg_replace('/SettingAotfLaserLineSetting/', 'SettingAotfLaser', $_, 1);
        $_ = preg_replace('/DataROISetROISet/', 'DataROISet', $_, 1);
        $_ = preg_replace('/AdditionalZPosition/', 'AddZPos', $_, 1);
        $_ = preg_replace('/FRAPplusBlock_FRAPBlock_FRAP_PrePost_Info/', 'FRAP_', $_, 1);
        $_ = preg_replace('/FRAPplusBlock_FRAPBlock_FRAP_(Master)?/', 'FRAP_', $_, 1);
        $_ = preg_replace('/LDM_Block_SequentialLDM_Block_Sequential_/', 'LDM_', $_, 1);
        $_ = preg_replace('/ATLConfocalSetting/', 'ATLConfocal', $_, 1);
        $_ = preg_replace('/LaserArrayLaser/', 'Laser', $_, 1);
        $_ = preg_replace('/LDM_Master/', 'LDM_', $_, 1);
        $_ = preg_replace('/(List)?ATLConfocal/', 'ATL_', $_, 1);
        $_ = preg_replace('/Separation/', 'Sep', $_, 1);
        $_ = preg_replace('/BleachPointsElement/', 'BleachPoint', $_, 1);
        $_ = preg_replace('/BeamPositionBeamPosition/', 'BeamPosition', $_, 1);
        $_ = preg_replace('/DataROISetPossible(ROI)?/', 'DataROISet', $_, 1);
        $_ = preg_replace('/RoiElementChildrenElementDataROISingle(Roi)?/', 'Roi', $_, 1);
        $_ = preg_replace('/InfoLaserLineSettingArrayLaserLineSetting/', 'LastLineSetting', $_, 1);
        $_ = preg_replace('/FilterWheelWheelNameFilterName/', 'FilterWheelFilterName', $_, 1);
        $_ = preg_replace('/LUT_ListLut/', 'Lut', $_, 1);
        $_ = preg_replace('/ROI_ListRoiRoidata/', 'ROI_', $_, 1);
        $_ = preg_replace('/LaserLineSettingArrayLaserLineSetting/', 'LaserLineSetting', $_, 1);
        return $_;
    }

    //------------------------------------------------------------------------------
    // Extract metadata from a LIF image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid LIF file
    public static function ProcessLIF(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = null;

        // verify this is a valid LIF file
        if (!($raf->Read($buff, 15) == 15 and preg_match('/^\x70\0{3}.{4}\x2a.{4}<\0/s', $buff))) return 0;

        $et->SetFileType();
        ExifTool::SetByteOrder('II');

        $size = ExifTool::Get32u($buff, 4);         // XML chunk size
        $len = ExifTool::Get32u($buff, 9) * 2;      // XML data length

        if ($size < $len) {
            $et->Error('Corrupted LIF XML block');
            return 1;
        }
        if ($size > 100000000) {
            $et->Error('LIF XML block too large');
            return 1;
        }

        if (!($raf->Seek(-2, 1) and $raf->Read($buff, $len) == $len)) {
            $et->Error('Truncated LIF XML block');
            return 1;
        }

        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::LIF::Main');

        // convert from UTF16 to UTF8
        $xml = $et->Decode($buff, 'UTF16', 'II', 'UTF8');

        $subdirInfo = ['DataPt' => $xml];   // (Perl %dirInfo)

        $et->XmpIgnoreProps = ['LMSDataContainerHeader', 'Element', 'Children', 'Data', 'Image', 'Attachment'];
        $et->ShortenXmpTags = 'Image\ExifTool\LIF::ShortenTagNames';

        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        return 1;
    }
}
