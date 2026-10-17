<?php

//------------------------------------------------------------------------------
// File:         ZISRAW.pm
//
// Description:  Read ZISRAW (CZI) meta information
//
// Revisions:    2020-08-07 - P. Harvey Created
//
// References:   1) https://www.zeiss.com/microscopy/us/products/microscope-software/zen/czi.html
//
// PHP port of Image::ExifTool::ZISRAW 1.01 (reading only). Tag tables are generated
// into tables/ZISRAW.php.
//------------------------------------------------------------------------------

namespace Image\ExifTool;

final class ZISRAW
{
    public static $VERSION = '1.01';

    //------------------------------------------------------------------------------
    // Shorten obscenely long CZI tag names
    // Inputs: Tag name
    // Returns: Shortened tag name
    public static function ShortenTagNames($_)
    {
        $_ = preg_replace('/^HardwareSetting/', '', $_, 1);
        $_ = preg_replace('/^DevicesDevice/', 'Device', $_, 1);
        $_ = preg_replace('/LightPathNode/', '', $_);
        $_ = preg_replace('/Successors/', '', $_);
        $_ = preg_replace('/ExperimentExperiment/', 'Experiment', $_);
        $_ = preg_replace('/ObjectivesObjective/', 'Objective', $_, 1);
        $_ = preg_replace('/ChannelsChannel/', 'Channel', $_, 1);
        $_ = preg_replace('/TubeLensesTubeLens/', 'TubeLens', $_, 1);
        $_ = preg_replace('/^ExperimentHardwareSettingsPoolHardwareSetting/', 'HardwareSetting', $_, 1);
        $_ = preg_replace('/SharpnessMeasureSetSharpnessMeasure/', 'Sharpness', $_, 1);
        $_ = preg_replace('/FocusSetupAutofocusSetup/', 'Autofocus', $_, 1);
        $_ = preg_replace('/TracksTrack/', 'Track', $_, 1);
        $_ = preg_replace('/ChannelRefsChannelRef/', 'ChannelRef', $_, 1);
        $_ = preg_replace('/ChangerChanger/', 'Changer', $_, 1);
        $_ = preg_replace('/ElementsChangerElement/', 'Changer', $_, 1);
        $_ = preg_replace('/ChangerElements/', 'Changer', $_, 1);
        $_ = preg_replace('/ContrastChangerContrast/', 'Contrast', $_, 1);
        $_ = preg_replace('/KeyFunctionsKeyFunction/', 'KeyFunction', $_, 1);
        $_ = preg_replace('/ManagerContrastManager(Contrast)?/', 'ManagerContrast', $_, 1);
        $_ = preg_replace('/ObjectiveChangerObjective/', 'ObjectiveChanger', $_, 1);
        $_ = preg_replace('/ManagerLightManager/', 'ManagerLight', $_, 1);
        $_ = preg_replace('/WavelengthAreasWavelengthArea/', 'WavelengthArea', $_, 1);
        $_ = preg_replace('/ReflectorChangerReflector/', 'ReflectorChanger', $_, 1);
        $_ = preg_replace('/^StageStageAxesStageAxis/', 'StageAxis', $_, 1);
        $_ = preg_replace('/ShutterChangerShutter/', 'ShutterChanger', $_, 1);
        $_ = preg_replace('/OnOffChangerOnOff/', 'OnOffChanger', $_, 1);
        $_ = preg_replace('/UnsharpMaskStateUnsharpMask/', 'UnsharpMask', $_, 1);
        $_ = preg_replace('/Acquisition/', 'Acq', $_, 1);
        $_ = preg_replace('/Continuous/', 'Cont', $_, 1);
        $_ = preg_replace('/Resolution/', 'Res', $_, 1);
        $_ = preg_replace('/Experiment/', 'Expt', $_);
        $_ = preg_replace('/Threshold/', 'Thresh', $_, 1);
        $_ = preg_replace('/Reference/', 'Ref', $_, 1);
        $_ = preg_replace('/Magnification/', 'Mag', $_, 1);
        $_ = preg_replace('/Original/', 'Orig', $_, 1);
        $_ = preg_replace('/FocusSetupFocusStrategySetup/', 'Focus', $_, 1);
        $_ = preg_replace('/ParametersParameter/', 'Parameter', $_, 1);
        $_ = preg_replace('/IntervalInfo/', 'Interval', $_, 1);
        $_ = preg_replace('/ExptBlocksAcqBlock/', 'AcqBlock', $_, 1);
        $_ = preg_replace('/MicroscopesMicroscope/', 'Microscope', $_, 1);
        $_ = preg_replace('/TimeSeriesInterval/', 'TimeSeries', $_, 1);
        $_ = preg_replace('/Interval(.*Interval)/', '${1}', $_, 1);
        $_ = preg_replace('/SingleTileRegionsSingleTileRegion/', 'SingleTileRegion', $_, 1);
        $_ = preg_replace('/AcquisitionMode/', '', $_, 1);
        $_ = preg_replace('/DetectorsDetector/', 'Detector', $_, 1);
        $_ = preg_replace('/Setup/', '', $_, 1);
        $_ = preg_replace('/Setting/', '', $_, 1);
        $_ = preg_replace('/TrackTrack/', 'Track', $_, 1);
        $_ = preg_replace('/AnalogOutMaximumsAnalogOutMaximum/', 'AnalogOutMaximum', $_, 1);
        $_ = preg_replace('/AnalogOutMinimumsAnalogOutMinimum/', 'AnalogOutMinimum', $_, 1);
        $_ = preg_replace('/DigitalOutLabelsDigitalOutLabelLabel/', 'DigitalOutLabelLabel', $_, 1);
        $_ = preg_replace('/(VivaTomeOpticalSectionInformation)+VivaTomeOpticalSectionInformation/', 'VivaTomeOpticalSectionInformation', $_, 1);
        $_ = preg_replace('/FocusDefiniteFocus/', 'FocusDefinite', $_, 1);
        $_ = preg_replace('/ChangerChanger/', 'Changer', $_, 1);
        $_ = preg_replace('/Calibration/', 'Cal', $_, 1);
        $_ = preg_replace('/LightSwitchChangerRLTLSwitch/', 'LightSwitchChangerRLTL', $_, 1);
        $_ = preg_replace('/Parameters/', '', $_, 1);
        $_ = preg_replace('/Fluorescence/', 'Fluor', $_, 1);
        $_ = preg_replace('/CameraGeometryCameraGeometry/', 'CameraGeometry', $_, 1);
        $_ = preg_replace('/CameraCamera/', 'Camera', $_, 1);
        $_ = preg_replace('/DetectorsCamera/', 'Camera', $_, 1);
        $_ = preg_replace('/FilterChangerLeftChangerEmissionFilter/', 'LeftChangerEmissionFilter', $_, 1);
        $_ = preg_replace('/SwitchingStatesSwitchingState/', 'SwitchingState', $_, 1);
        $_ = preg_replace('/Information/', 'Info', $_, 1);
        $_ = preg_replace('/SubDimensions?/', '', $_);
        $_ = preg_replace('/Setups?/', '', $_, 1);
        $_ = preg_replace('/Parameters?/', '', $_, 1);
        $_ = preg_replace('/Calculate/', 'Calc', $_, 1);
        $_ = preg_replace('/Visibility/', 'Vis', $_, 1);
        $_ = preg_replace('/Orientation/', 'Orient', $_, 1);
        $_ = preg_replace('/ListItems/', 'Items', $_, 1);
        $_ = preg_replace('/Increment/', 'Incr', $_, 1);
        $_ = preg_replace('/Parameter/', 'Param', $_, 1);
        $_ = preg_replace('/(ParfocalParcentralValues)+ParfocalParcentralValue/', 'Parcentral', $_, 1);
        $_ = preg_replace('/ParcentralParcentral/', 'Parcentral', $_, 1);
        $_ = preg_replace('/CorrFocusCorrection/', 'FocusCorr', $_, 1);
        $_ = preg_replace('/(ApoTomeDepthInfo)+Element/', 'ApoTomeDepth', $_, 1);
        $_ = preg_replace('/(ApoTomeClickStopInfo)+Element/', 'ApoTomeClickStop', $_, 1);
        $_ = preg_replace('/DepthDepth/', 'Depth', $_, 1);
        $_ = preg_replace('/(Devices?)+Device/', 'Device', $_, 1);
        $_ = preg_replace('/(BeamPathNode)+/', 'BeamPathNode', $_, 1);
        $_ = preg_replace('/BeamPathsBeamPath/', 'BeamPath', $_);
        $_ = preg_replace('/BeamPathBeamPath/', 'BeamPath', $_);
        $_ = preg_replace('/Configuration/', 'Config', $_, 1);
        $_ = preg_replace('/StageAxesStageAxis/', 'StageAxis', $_, 1);
        $_ = preg_replace('/RangesRange/', 'Range', $_, 1);
        $_ = preg_replace('/DataGridDatasGridData(Grid)?/', 'DataGrid', $_, 1);
        $_ = preg_replace('/DataMicroscopeDatasMicroscopeData(Microscope)?/', 'DataMicroscope', $_, 1);
        $_ = preg_replace('/DataWegaDatasWegaData/', 'DataWega', $_, 1);
        $_ = preg_replace('/ClickStopPositionsClickStopPosition/', 'ClickStopPosition', $_, 1);
        $_ = preg_replace('/LightSourcess?LightSource(Settings)?(LightSource)?/', 'LightSource', $_, 1);
        $_ = preg_replace('/FilterSetsFilterSet/', 'FilterSet', $_, 1);
        $_ = preg_replace('/EmissionFiltersEmissionFilter/', 'EmissionFilter', $_, 1);
        $_ = preg_replace('/ExcitationFiltersExcitationFilter/', 'ExcitationFilter', $_, 1);
        $_ = preg_replace('/FiltersFilter/', 'Filter', $_, 1);
        $_ = preg_replace('/DichroicsDichroic/', 'Dichronic', $_, 1);
        $_ = preg_replace('/WavelengthsWavelength/', 'Wavelength', $_, 1);
        $_ = preg_replace('/MultiTrackSetup/', 'MultiTrack', $_, 1);
        $_ = preg_replace('/TrackTrack/', 'Track', $_, 1);
        $_ = preg_replace('/DataGrabberSetup/', 'DataGrabber', $_, 1);
        $_ = preg_replace('/CameraFrameSetup/', 'CameraFrame', $_, 1);
        $_ = preg_replace('/TimeSeries(TimeSeries|Setups)/', 'TimeSeries', $_, 1);
        $_ = preg_replace('/FocusFocus/', 'Focus', $_, 1);
        $_ = preg_replace('/FocusAutofocus/', 'Autofocus', $_, 1);
        $_ = preg_replace('/Focus(Hardware|Software)(Autofocus)+/', 'Autofocus${1}', $_, 1);
        $_ = preg_replace('/AutofocusAutofocus/', 'Autofocus', $_, 1);
        return $_;
    }

    //------------------------------------------------------------------------------
    // Extract metadata from a ZISRAW (CZI) image
    // Inputs: 0) ExifTool object reference, 1) dirInfo reference
    // Returns: 1 on success, 0 if this wasn't a valid CZI file
    public static function ProcessCZI(ExifTool $et, array &$dirInfo)
    {
        $raf = $dirInfo['RAF'];
        $buff = $tagTablePtr = null;

        // verify this is a valid CZI file
        if ($raf->Read($buff, 100) != 100) return 0;
        if (!preg_match('/^ZISRAWFILE\0{6}/', $buff)) return 0;
        $et->SetFileType();
        ExifTool::SetByteOrder('II');
        $subdirInfo = [     // (Perl %dirInfo)
            'DataPt' => $buff,
            'DirStart' => 0,
            'DirLen' => strlen($buff),
        ];
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::ZISRAW::Main');
        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        // read the metadata section
        $pos = ExifTool::Get64u($buff, 92);
        if (!$pos) return 1;
        if (!$raf->Seek($pos, 0)) {
            $et->Warn('Error seeking to metadata');
            return 0;
        }
        if ($raf->Read($buff, 288) != 288) {
            $et->Warn('Error reading metadata header');
            return 0;
        }
        if (!preg_match('/^ZISRAWMETADATA\0\0/', $buff)) {
            $et->Warn('Invalid metadata header');
            return 0;
        }
        $len = ExifTool::Get32u($buff, 32);
        if (!($len < 200000000)) {
            $et->Warn('Metadata section too large. Ignoring');
            return 0;
        }
        if (!$raf->Read($buff, $len)) {
            $et->Warn('Error reading XML metadata');
            return 0;
        }
        $et->FoundTag('XML', $buff);    // extract as a block
        $tagTablePtr = ExifTool::GetTagTable('Image::ExifTool::XMP::XML');
        $subdirInfo['DataPt'] = $buff;  // (Perl DataPt references $buff)
        $subdirInfo['DirLen'] = strlen($buff);
        // shorten tag names somewhat by removing 'ImageDocumentMetadata' prefix from all
        $et->XmpIgnoreProps = ['ImageDocument', 'Metadata', 'Information'];
        $et->ShortenXmpTags = 'Image\ExifTool\ZISRAW::ShortenTagNames';

        $et->ProcessDirectory($subdirInfo, $tagTablePtr);

        return 1;
    }
}
