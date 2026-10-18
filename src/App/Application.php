<?php

namespace Image\ExifTool\App;

use Image\ExifTool\ExifTool;
use Image\ExifTool\File\RandomAccess;
use Image\ExifTool\Geolocation;
use Image\ExifTool\Import;
use Image\ExifTool\MWG;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\Vars\ExifTool as ExifToolVars;
use Image\ExifTool\XMP;

/**
 * Port of the `exiftool` command-line script (exiftool 13.59), reading and writing.
 *
 * File-scope variables of the script are properties, its subs are methods with the same names,
 * and the main script is run(). Not ported (the command stops with an error): -geotag, and
 * writing Geotag, Geotime or Geosync (Geotag.pm is not ported yet), -X, -h, -htmlDump, -listx,
 * -diff, -plot, -fileNUM, -fileOrder, -z, -use of modules other than those of ExifTool, and
 * -config files. -if and -p evaluate Perl code: -if supports a subset of Perl expressions (see
 * CompileCondition()), and advanced formatting expressions (${TAG;EXPR}) are not supported in
 * -p or in tag copies (-TAG<STR). The writers not ported yet (MIE, PNG...) give an error for
 * the file.
 *
 * From PHP: Application::json(['-g', $file]) returns what `exiftool -j -g FILE` prints, as an
 * array; Application::capture($args) returns [exit status, stdout, stderr] of any command.
 */
final class Application
{
    public const string VERSION = '13.59';

    // lookup for JSON characters that we escape specially
    private const array JSON_CHAR = ['"' => '\\"', '\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r'];

    // lookup for C-style escape sequences
    private const array ESC_C = ["\n" => '\n', "\r" => '\r', "\t" => '\t', '\\' => '\\\\'];
    private const array UNESC_C = ['a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r",
                                   't' => "\t", '0' => "\0", '\\' => '\\'];

    // options requiring additional arguments
    // (used only to skip over these arguments when reading -stay_open ARGFILE)
    // (arg is converted to lower case then tested again unless an entry was found with the same case)
    private const array OPT_ARGS = [
        '-tagsfromfile' => 1, '-addtagsfromfile' => 1, '-alltagsfromfile' => 1,
        '-@' => 1,
        '-api' => 1,
        '-c' => 1, '-coordformat' => 1,
        '-charset' => 0, // (optional arg; OK because arg cannot begin with "-")
        '-config' => 1,
        '-csvdelim' => 1,
        '-d' => 1, '-dateformat' => 1,
        '-D' => 0, // necessary to avoid matching lower-case equivalent
        '-diff' => 1,
        '-echo' => 1, '-echo#' => 1,
        '-efile' => 1, '-efile#' => 1, '-efile!' => 1, '-efile#!' => 1,
        '-ext' => 1, '--ext' => 1, '-ext+' => 1, '--ext+' => 1,
        '-extension' => 1, '--extension' => 1, '-extension+' => 1, '--extension+' => 1,
        '-fileorder' => 1, '-fileorder#' => 1,
        '-file#' => 1,
        '-geotag' => 1,
        '-globaltimeshift' => 1,
        '-i' => 1, '-ignore' => 1,
        '-if' => 1, '-if#' => 1,
        '-lang' => 0, // (optional arg; cannot begin with "-")
        '-listitem' => 1,
        '-o' => 1, '-out' => 1,
        '-p' => 1, '-printformat' => 1, '-p-' => 1, '-printformat-' => 1,
        '-P' => 0,
        '-password' => 1,
        '-require' => 1,
        '-sep' => 1, '-separator' => 1,
        '-srcfile' => 1,
        '-stay_open' => 1,
        '-use' => 1,
        '-userparam' => 1,
        '-w' => 1, '-w!' => 1, '-w+' => 1, '-w+!' => 1, '-w!+' => 1,
        '-textout' => 1, '-textout!' => 1, '-textout+' => 1, '-textout+!' => 1, '-textout!+' => 1,
        '-tagout' => 1, '-tagout!' => 1, '-tagout+' => 1, '-tagout+!' => 1, '-tagout!+' => 1,
        '-wext' => 1,
        '-wm' => 1, '-writemode' => 1,
        '-x' => 1, '-exclude' => 1,
        '-X' => 0,
    ];

    private const array UNESCAPE_CHAR = ['t' => "\t", 'n' => "\n", 'r' => "\r"];

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;
    /** @var resource */
    private $stdin;

    private array $ARGV = [];

    // declare all static file-scope variables
    private array $commonArgs = [];     // arguments common to all commands
    private array $csvExclude = [];     // list of tags excluded from CSV import
    private array $csvFiles = [];       // list of files when reading with CSV option (in ExifTool Charset)
    private array $csvTagList = [];     // @csvTags: order of tags for first file with CSV option (lower case)
    private array $delFiles = [];       // list of files to delete
    private array $dynamicFiles = [];   // list of -tagsFromFile files with dynamic names and -TAG<=FMT pairs
    private array $echo3 = [];          // stdout echo after processing is complete
    private array $echo4 = [];          // stderr echo after processing is complete
    private array $efile = [];          // files for writing list of error/fail/same file names
    private array $exclude = [];        // list of excluded tags
    private array $files = [];          // list of files and directories to scan
    private array $moreArgs = [];       // more arguments to process after -stay_open -@
    private array $newValues = [];      // list of new tag values to set
    private array $srcFmt = [];         // source file name format strings
    private array $tags = [];           // list of tags to extract
    private array $appended = [];       // list of files appended to
    private array $countLink = [];      // count hard and symbolic links made
    private array $created = [];        // list of files we created
    private array $csvTags = [];        // %csvTags: lookup for all found tags with CSV option (lower case keys)
    private array $database = [];       // CSV information for each file (keyed by file name)
    private array $filterExt = [];      // lookup for filtered extensions
    private array $ignore = [];         // directory names to ignore
    private array $outComma = [];       // flag that output text file needs a comma
    private array $outTrailer = [];     // trailer for output text file
    private array $preserveTimes = [];  // %preserveTime: preserved timestamps for files
    private array $seqFileDirs = [];    // %seqFileDir: file sequence number in each directory
    private array $setTags = [];        // hash of list references for tags to set from files
    private array $setTagsList = [];    // list of other tag lists for multiple -tagsFromFile from the same file
    private array $usedFileName = [];   // lookup for file names we already used in TestName feature
    private array $wext = [];           // -W extensions to write
    private array $wroteHEAD = [];      // list of output txt files to which we wrote HEAD
    private array $condition = [];      // conditional processing of files
    private array $printFmt = [];       // the contents of the print format file
    private array $requestTags = [];    // tags to request (for -p or -if option arguments)
    private array $compiledCond = [];   // (PHP) compiled -if conditions, keyed by Perl code
    private $allGroup;                  // show group name for all tags
    private $altEnc;                    // alternate character encoding if not UTF-8
    private $argFormat;                 // use exiftool argument-format output
    private $binaryOutput;              // flag for binary output (undef or 1, or 0 for binary JSON/PHP)
    private $binaryStdout;              // flag set if we output binary to stdout
    private $binSep;                    // separator used for list items in binary output
    private $binTerm;                   // terminator used for binary output
    private $comma;                     // flag set if we need a comma in JSON output
    private $count = 0;                 // count of files scanned when reading or deleting originals
    private $countBad = 0;              // count of files with errors
    private $countBadCr = 0;            // count files not created due to errors
    private $countBadWr = 0;            // count write errors
    private $countCopyWr = 0;           // count of files copied without being changed
    private $countDir = 0;              // count of directories scanned
    private $countFailed = 0;           // count files that failed condition
    private $countGoodCr = 0;           // count files created OK
    private $countGoodWr = 0;           // count files written OK
    private $countNewDir = 0;           // count of directories created
    private $countSameWr = 0;           // count files written OK but not changed
    private $critical;                  // flag for critical operations (disable CTRL-C)
    private $csv;                       // flag for CSV option (set to "CSV", or maybe "JSON" when writing)
    private $csvDelim = ',';            // delimiter for CSV files
    private $dbAdd;                     // flag to add CSV/JSON information to existing lists
    private $dbSaveCount = 0;           // save counter for last CSV/JSON file loaded
    private $deleteOrig;                // 0=restore original files, 1=delete originals, 2=delete w/o asking
    private $disableOutput;             // flag to disable normal output
    private $doSetFileName;             // flag set if FileName may be written
    private $escapeC;                   // C-style escape
    private $escapeHTML;                // flag to escape printed values for html
    private $executeID;                 // -execute ID number
    private $failCondition;             // flag to fail -if condition
    private $fastCondition;             // flag for fast -if condition
    private $fileHeader;                // header to print to output file (or console, once)
    private $fileTrailer = '';          // trailer for output file
    private $filtered;                  // flag indicating file was filtered by name
    private $filterFlag = 0;            // file filter flag (0x01=deny extensions, 0x02=allow extensions, 0x04=add ext)
    private $fixLen;                    // flag to fix description lengths when writing alternate languages
    private $forcePrint;                // string to use for missing tag values (undef to not print them)
    private $geoOnly;                   // flag to extract Geolocation tags only
    private $helped;                    // flag to avoid printing help if no tags specified
    private $ignoreHidden;              // flag to ignore hidden files
    private $interrupted;               // flag set if CTRL-C is pressed during a critical process
    private $isBinary;                  // true if value is a SCALAR ref
    private $isWriting = 0;             // flag set if we are writing tags
    private $joinLists;                 // flag set to join list values into a single string
    private $json = 0;                  // flag for JSON/PHP output format (1=JSON, 2=PHP)
    private $langOpt;                   // language option
    private $listDir;                   // treat a directory as a regular file
    private $listItem;                  // item number for extracting single item from a list
    private $listSep = ', ';            // list item separator (', ' by default)
    private ?ExifTool $mt = null;       // main ExifTool object
    private $multiFile;                 // non-zero if we are scanning multiple files
    private $noBinary;                  // flag set to ignore binary tags
    private $outFormat = 0;             // -1=Canon format, 0=same-line, 1=tag names, 2=values only
    private $outOpt;                    // output file or directory name
    private $overwriteOrig = 0;         // flag to overwrite original file (1=overwrite, 2=in place)
    private $pause;                     // pause before returning
    private $preserveTime;              // flag to preserve times of updated files (2=preserve FileCreateDate only)
    private $progress;                  // flag to calculate total files to process (0=calculate but don't display)
    private $progressCount;             // count of files processed
    private $progressIncr;              // increment for progress counter
    private $progressMax;               // total number of files to process
    private $progressNext;              // next progress count to output
    private $progStr = '';              // progress message string
    private $purge;                     // flag to purge memory
    private $quiet = 0;                 // flag to disable printing of informational messages / warnings
    private $rafStdin;                  // File::RandomAccess for stdin (if necessary to rewind)
    private $recurse;                   // recurse into subdirectories (2=also hidden directories)
    private $rtnVal;                    // command return value (0=success)
    private $rtnValPrev;                // previous command return value (0=success)
    private $saveCount = 0;             // count the number of times we will/did call SaveNewValues()
    private $scanWritable;              // flag to process only writable file types
    private $sectHeader;                // current section header for -p option
    private $sectTrailer = '';          // section trailer for -p option
    private $seqFileDir = 0;            // sequential file number used for %-C
    private $seqFileNum = 0;            // sequential file number used for %C
    private $setCharset;                // character set setting ('default' if not set and -csv -b used)
    private $showGroup;                 // number of group to show (may be zero or '')
    private $showTagID;                 // non-zero to show tag ID's
    private $stayOpenBuff = '';         // buffer for -stay_open file
    private $stayOpenFile;              // name of the current -stay_open argfile
    private $stayOpenFp;                // STAYOPEN file handle
    private $structOpt;                 // output structured XMP information (JSON output only)
    private $tabFormat = 0;             // non-zero for tab output format
    private $tagOut;                    // flag for separate text output file for each tag
    private $textOut;                   // extension for text output file (or undef for no output)
    private $textOut2;                  // complete file name for single text output file
    private $textOverwrite;             // flag to overwrite existing text output file (2=append, 3=over+append)
    private $tmpFile;                   // temporary file to delete on exit
    private $tmpText;                   // temporary text file
    private $validFile;                 // flag indicating we processed a valid file
    private $verbose;                   // verbose setting
    /** @var resource */
    private $vout;                      // verbose output file reference (STDOUT or STDERR by default)

    // flag to keep the input -@ argfile open:
    // 0 = normal behaviour
    // 1 = received "-stay_open true" and waiting for argfile to keep open
    // 2 = currently reading from STAYOPEN argfile
    // 3 = waiting for -@ to switch to a new STAYOPEN argfile
    private $stayOpen = 0;

    private $rtnValApp = 0;             // app return value (0=success)

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin
     */
    public function __construct($stdout = null, $stderr = null, $stdin = null)
    {
        // (the STD* constants only exist in the CLI SAPI)
        $this->stdout = $stdout ?? (\defined('STDOUT') ? \STDOUT : fopen('php://output', 'w'));
        $this->stderr = $stderr ?? (\defined('STDERR') ? \STDERR : fopen('php://stderr', 'w'));
        $this->stdin = $stdin ?? (\defined('STDIN') ? \STDIN : fopen('php://memory', 'r'));
    }

    /**
     * bin/exiftool: $argv as PHP gives it (script name first); returns the exit status.
     */
    public static function main(array $argv): int
    {
        array_shift($argv);
        // (Perl config files can't be loaded: drop the leading -config options)
        while ($argv && strtolower($argv[0]) === '-config') {
            array_shift($argv);
            $config = array_shift($argv);
            if ($config !== null && $config !== '') {
                fwrite(\STDERR, "Warning: Config files are not supported by the PHP port - $config\n");
            }
        }
        $app = new self();
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGINT, static fn () => $app->SigInt());   // do cleanup on Ctrl-C
        }

        return $app->run($argv);
    }

    /**
     * Runs exiftool with these arguments in this process.
     *
     * @param list<string>  $args
     * @param resource|null $stdin input for "-" and "-@ -"
     *
     * @return array{int, string, string} exit status, stdout, stderr
     */
    public static function capture(array $args, $stdin = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = (new self($out, $err, $stdin))->run($args);
        rewind($out);
        rewind($err);

        return [$status, stream_get_contents($out), stream_get_contents($err)];
    }

    /**
     * What `exiftool -j ARGS` prints, decoded: one array per file. For example
     * Application::json(['-g', '-c', '%+.6f', $file]) is the structure of `exiftool -j -g -c %+.6f FILE`.
     *
     * @param list<string> $args options, tag names and files, as on the command line
     */
    public static function json(array $args): array
    {
        [, $out] = self::capture(['-j', ...$args]);

        return '' === $out ? [] : json_decode($out, true, 512, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The main script: processes the command line, returns the exit status.
     *
     * @param list<string> $argv arguments, without the script name
     */
    public function run(array $argv): int
    {
        $this->ARGV = array_values(array_map('strval', $argv));

        // isolate arguments common to all commands
        if (preg_grep('/^-common_args$/i', $this->ARGV)) {
            $newArgs = [];
            $common = $end = false;
            foreach ($this->ARGV as $arg) {
                if (preg_match('/^-common_args$/i', $arg) && !$end) {
                    $common = true;
                } elseif ($common) {
                    $this->commonArgs[] = $arg;
                } else {
                    if ('--' === $arg) {
                        $end = true;
                    }
                    $newArgs[] = $arg;
                }
            }
            if ($common) {
                $this->ARGV = $newArgs;
            }
        }

        //..............................................................................
        // loop over sets of command-line arguments separated by "-execute"
        for (;;) { // Command

            if ($this->echo3) {
                $str = implode("\n", $this->echo3) . "\n";
                $str = str_ireplace('${status}', Perl::str($this->rtnVal), $str);
                fwrite($this->stdout, $str);
            }
            if ($this->echo4) {
                $str = implode("\n", $this->echo4) . "\n";
                $str = str_ireplace('${status}', Perl::str($this->rtnVal), $str);
                fwrite($this->stderr, $str);
            }

            // (Perl closes $rafStdin here: the PHP port keeps $this->stdin open for "-@ -")
            $this->rafStdin = null;

            // save our previous return codes
            $this->rtnValPrev = $this->rtnVal;
            if ($this->rtnVal) {
                $this->rtnValApp = $this->rtnVal;
            }

            // exit Command loop now if we are all done processing commands
            if (!($this->ARGV || null === $this->rtnVal || $this->stayOpen >= 2 || $this->commonArgs)) {
                break;
            }

            $this->binaryStdout = 0;

            // flush console and print "{ready}" message if -stay_open is in effect
            if ($this->stayOpen >= 2) {
                if ($this->quiet && null === $this->executeID) {
                    // flush output if possible
                    fflush($this->stderr);
                    fflush($this->stdout);
                } else {
                    fflush($this->stderr);
                    $id = $this->executeID ?? '';
                    fwrite($this->stdout, "{ready$id}\n");
                    fflush($this->stdout);
                }
            }

            // initialize necessary static file-scope variables
            // (not done: @commonArgs, @moreArgs, $critical, $binaryStdout, $helped,
            //  $interrupted, $mt, $pause, $rtnValApp, $rtnValPrev, $stayOpen, $stayOpenBuff, $stayOpenFile)
            $this->condition = $this->csvExclude = $this->csvFiles = $this->csvTagList = $this->delFiles = [];
            $this->dynamicFiles = $this->echo3 = $this->echo4 = $this->efile = $this->exclude = $this->files = [];
            $this->newValues = $this->requestTags = $this->srcFmt = $this->tags = $this->appended = [];
            $this->countLink = $this->created = $this->csvTags = $this->database = $this->filterExt = [];
            $this->ignore = $this->outComma = $this->outTrailer = $this->preserveTimes = $this->printFmt = [];
            $this->seqFileDirs = $this->setTags = $this->setTagsList = $this->usedFileName = $this->wext = [];
            $this->wroteHEAD = $this->compiledCond = [];
            $this->allGroup = $this->altEnc = $this->argFormat = $this->binaryOutput = $this->binSep = null;
            $this->binTerm = $this->comma = $this->csv = $this->dbAdd = $this->deleteOrig = null;
            $this->disableOutput = $this->doSetFileName = $this->escapeC = $this->escapeHTML = null;
            $this->executeID = $this->failCondition = $this->fastCondition = $this->fileHeader = null;
            $this->filtered = $this->fixLen = $this->forcePrint = $this->geoOnly = $this->ignoreHidden = null;
            $this->isBinary = $this->joinLists = $this->langOpt = $this->listDir = $this->listItem = null;
            $this->multiFile = $this->noBinary = $this->outOpt = $this->preserveTime = $this->progress = null;
            $this->progressCount = $this->progressIncr = $this->progressMax = $this->progressNext = null;
            $this->purge = $this->recurse = $this->scanWritable = $this->sectHeader = $this->setCharset = null;
            $this->showGroup = $this->showTagID = $this->structOpt = $this->tagOut = $this->textOut = null;
            $this->textOut2 = $this->textOverwrite = $this->tmpFile = $this->tmpText = $this->validFile = null;
            $this->verbose = null;

            $this->count = 0;
            $this->countBad = 0;
            $this->countBadCr = 0;
            $this->countBadWr = 0;
            $this->countCopyWr = 0;
            $this->countDir = 0;
            $this->countFailed = 0;
            $this->countGoodCr = 0;
            $this->countGoodWr = 0;
            $this->countNewDir = 0;
            $this->countSameWr = 0;
            $this->csvDelim = ',';
            $this->dbSaveCount = 0;
            $this->fileTrailer = '';
            $this->filterFlag = 0;
            $this->isWriting = 0;
            $this->json = 0;
            $this->listSep = ', ';
            $this->outFormat = 0;
            $this->overwriteOrig = 0;
            $this->progStr = '';
            $this->quiet = 0;
            $this->rtnVal = 0;
            $this->saveCount = 0;
            $this->sectTrailer = '';
            $this->seqFileDir = 0;
            $this->seqFileNum = 0;
            $this->tabFormat = 0;
            $this->vout = $this->stdout;

            // define local variables used only in this command loop
            $addGeotime = null;     // automatically added geotime argument
            $endOfOpts = null;      // flag set if "--" option encountered
            $escapeXML = null;      // flag to escape printed values for xml
            $setTagsFile = null;    // filename for last TagsFromFile option
            $sortOpt = null;        // sort option is used
            $srcStdin = null;       // one of the source files is STDIN
            $tagsFrom = '';         // tags on command line come from 'CSV' or 'File'
            $useMWG = null;         // flag set if we are using any MWG tag

            $argsLeft = null;
            $nextPass = [];
            $badCmd = false;
            $pass = 0;

            $this->mt = $mt = new ExifTool();   // create ExifTool object
            $mt->Options('TextOut', $this->vout);

            // don't extract duplicates by default (no UserDefined::Options: config files aren't loaded)
            $mt->Options('Duplicates', 0);

            // default is to join lists if the List option was set to zero in the config file
            if (null !== $mt->Options('List') && !$mt->Options('List')) {
                $this->joinLists = 1;
            }

            if (self::VERSION !== ExifTool::$VERSION) {
                $this->Warn('Application version ' . self::VERSION . ' does not match Image::ExifTool library version ' . ExifTool::$VERSION . "\n");
            }

            // parse command-line options in 2 passes...
            // pass 1: set all of our ExifTool options
            // pass 2: print all of our help and informational output (-list, -ver, etc)
            for (;;) {

                // execute the command now if no more arguments or -execute is used
                if (!$this->ARGV || (preg_match('/^(-|\xe2\x88\x92)execute(\d+)?$/i', $this->ARGV[0], $m) && !$endOfOpts)) {
                    if ($this->ARGV) {
                        $this->executeID = $m[2] ?? null;   // save -execute number for "{ready}" response
                        $this->helped = 1;                  // don't show help if we used -execute
                        if ($badCmd) {
                            array_shift($this->ARGV);
                            $this->rtnVal = 1;
                            continue 2;
                        }
                    } elseif ($this->stayOpen >= 2) {
                        $this->ReadStayOpen($this->ARGV);   // read more arguments from -stay_open file
                        continue;
                    } elseif ($badCmd) {
                        $this->commonArgs = [];     // all done.  Flush common arguments
                        $this->rtnVal = 1;
                        continue 2;
                    }
                    if (0 == $pass) {
                        // insert common arguments now if not done already
                        if ($this->commonArgs && null === $argsLeft) {
                            // count the number of arguments remaining for subsequent commands
                            $argsLeft = \count($this->ARGV) + \count($this->moreArgs);
                            array_unshift($this->ARGV, ...$this->commonArgs);
                            // all done with commonArgs if this is the end of the command
                            if (!$argsLeft) {
                                $this->commonArgs = [];
                            }
                            continue;
                        }
                        // check if we have more arguments now than we did before we processed
                        // the common arguments.  If so, then we have an infinite processing loop
                        if (null !== $argsLeft && $argsLeft < \count($this->ARGV) + \count($this->moreArgs)) {
                            $this->Warn("Ignoring -common_args from {$this->ARGV[0]} onwards to avoid infinite recursion\n");
                            while ($argsLeft < \count($this->ARGV) + \count($this->moreArgs)) {
                                if ($this->ARGV) {
                                    array_shift($this->ARGV);
                                    continue;
                                }
                                array_shift($this->moreArgs);
                            }
                        }
                        // require MWG module if used in any argument
                        // (note: doesn't cover the -p option because these tags will be parsed on the 2nd pass)
                        if (!$useMWG && preg_grep('/^([--_0-9A-Z]+:)*1?mwg:/i', [...$this->tags, ...$this->requestTags])) {
                            $useMWG = 1;
                        }
                        if ($useMWG) {
                            ExifTool::RequireModule('Image::ExifTool::MWG');
                            MWG::Load();
                        }
                        // update necessary variables for 2nd pass
                        if (null !== $this->forcePrint) {
                            if (null === $mt->Options('MissingTagValue')) {
                                $mt->Options('MissingTagValue', '-');
                            }
                            $this->forcePrint = $mt->Options('MissingTagValue');
                        }
                    }
                    if ($nextPass) {
                        // process arguments which were deferred to the next pass
                        array_unshift($this->ARGV, ...$nextPass);
                        $nextPass = [];
                        $endOfOpts = null;
                        ++$pass;
                        continue;
                    }
                    if ($this->ARGV) {
                        array_shift($this->ARGV);   // remove -execute from argument list
                    }
                    break;  // process the command now
                }
                $arg = array_shift($this->ARGV);
                if ($badCmd) {
                    continue;   // flush remaining arguments if aborting this command
                }

                // allow funny dashes (nroff dash bug for cut-n-paste from pod)
                if (!$endOfOpts && preg_match('/^(-|\xe2\x88\x92)/', $arg, $m)) {
                    $arg = substr($arg, \strlen($m[1]));
                    $arg = preg_replace('/^\xe2\x88\x92/', '-', $arg);  // translate double-dash too
                    if ('-' === $arg) {
                        if (!$pass) {
                            $nextPass[] = '--';
                        }
                        $endOfOpts = 1;
                        continue;
                    }
                    $a = strtolower($arg);
                    if (preg_match('/^list([wfrdx]|wf|g(\d*)|geo)?$/i', $arg, $m)) {
                        if (!$pass) {
                            $nextPass[] = "-$arg";
                        }
                        $type = strtolower($m[1] ?? '');
                        if (!$type || 'w' === $type || 'x' === $type) {
                            $group = null;
                            if ($this->ARGV && preg_match('/^(-|\xe2\x88\x92)(.+):(all|\*)$/i', (string) $this->ARGV[0], $mm)) {
                                if (0 == $pass) {
                                    if ('mwg' === strtolower($mm[2])) {
                                        $useMWG = 1;
                                    }
                                    $nextPass[] = array_shift($this->ARGV);
                                    continue;
                                }
                                $group = $mm[2];
                                array_shift($this->ARGV);
                                if (preg_match('/IFD/i', $group)) {
                                    $this->Warn("Can't list tags for specific IFD\n");
                                    $this->helped = 1;
                                    continue;
                                }
                                if (preg_match('/^(all|\*)$/', $group)) {
                                    $group = null;
                                }
                            } elseif (!$pass) {
                                continue;
                            }
                            $this->helped = 1;
                            if ('x' === $type) {
                                $badCmd = $this->NotPorted($arg);   // (TagInfoXML module not ported)
                                continue;
                            }
                            $wr = ('w' === $type);
                            $msg = ($wr ? 'Writable' : 'Available') . ($group ? " $group" : '') . ' tags';
                            $this->PrintTagList($msg, ...($wr ? ExifTool::GetWritableTags($group) : ExifTool::GetAllTags($group)));
                            // also print shortcuts if listing all tags
                            if ($group || $wr) {
                                continue;
                            }
                            $tagList = ExifTool::GetShortcuts();
                            if ($tagList) {
                                $this->PrintTagList('Command-line shortcuts', ...$tagList);
                            }
                            continue;
                        }
                        if (!$pass) {
                            continue;
                        }
                        $this->helped = 1;
                        if ('wf' === $type) {
                            $wf = array_values(array_filter(ExifTool::GetFileTypeList(), static fn ($t) => ExifTool::CanWrite($t)));
                            $this->PrintTagList('Writable file extensions', ...$wf);
                        } elseif ('f' === $type) {
                            $this->PrintTagList('Supported file extensions', ...ExifTool::GetFileTypeList());
                        } elseif ('r' === $type) {
                            $this->PrintTagList('Recognized file extensions', ...ExifTool::GetFileTypeList(null, 0));
                        } elseif ('d' === $type) {
                            $this->PrintTagList('Deletable groups', ...ExifTool::GetDeleteGroups());
                        } elseif ('geo' === $type) {
                            Geolocation::Load();
                            if (!$this->quiet) {
                                fwrite($this->stdout, "Geolocation database:\n");
                            }
                            $isAlt = $mt->Options('GeolocAltNames') ? ',AltNames' : '';
                            if ($isAlt && !Geolocation::ReadAltNames()) {
                                $isAlt = '';
                            }
                            fwrite($this->stdout, "City,Region,Subregion,CountryCode,Country,TimeZone,FeatureCode,Population,Latitude,Longitude$isAlt\n");
                            if ($sortOpt) {
                                Geolocation::SortDatabase('City');
                            }
                            $minPop = $mt->Options('GeolocMinPop');
                            $feature = $mt->Options('GeolocFeature') ?: '';
                            $neg = preg_match('/^-/', $feature);
                            $feature = preg_replace('/^-/', '', $feature, 1);
                            $fcodes = [];
                            foreach (Perl::split('/\s*,\s*/', $feature) as $fc) {
                                $fcodes[strtolower($fc)] = 1;
                            }
                            $isUTF8 = [0, 1, 2, 4];   // items that need converting from UTF8
                            if ($isAlt) {
                                $isUTF8[] = 10;
                            }
                            for ($i = 0; ; ++$i) {
                                $entry = Geolocation::GetEntry($i, $this->langOpt, 1);
                                if (!$entry) {
                                    break;
                                }
                                $entry = \array_slice($entry, 0, 10);  // remove everything after latitude (eg. feature type)
                                if ($minPop && Perl::num($entry[7]) < Perl::num($minPop)) {
                                    continue;
                                }
                                if ($fcodes && ($neg ? isset($fcodes[strtolower($entry[6])]) : !isset($fcodes[strtolower($entry[6])]))) {
                                    continue;
                                }
                                if ($isAlt) {
                                    $entry[] = Geolocation::GetAltNames($i, 1);
                                }
                                foreach ($isUTF8 as $k) {
                                    $entry[$k] = null !== $entry[$k] ? $mt->Decode($entry[$k], 'UTF8') : '';
                                }
                                if ($isAlt && !$entry[10]) {
                                    array_pop($entry);
                                }
                                fwrite($this->stdout, implode(',', $entry)."\n");
                            }
                        } else { // 'g(\d*)'
                            // list all groups in specified family
                            $family = ($m[2] ?? '') ?: 0;
                            $this->PrintTagList("Groups in family $family", ...ExifTool::GetAllGroups($mt, $family));
                        }
                        continue;
                    }
                    if ('ver' === $a) {
                        if (!$pass) {
                            $nextPass[] = '-ver';
                            continue;
                        }
                        $libVer = ExifTool::$VERSION;
                        $str = $libVer === self::VERSION ? '' : " [Warning: Library version is $libVer]";
                        if ($this->verbose) {
                            fwrite($this->stdout, 'ExifTool version ' . self::VERSION . $str . ExifTool::$RELEASE . "\n");
                            fwrite($this->stdout, 'PHP version ' . \PHP_VERSION . "\n");
                            fwrite($this->stdout, 'Platform: ' . strtolower(\PHP_OS) . "\n");
                        } else {
                            fwrite($this->stdout, self::VERSION . $str . ExifTool::$RELEASE . "\n");
                        }
                        $this->helped = 1;
                        continue;
                    }
                    if (preg_match('/^(all|add)?tagsfromfile(=.*)?$/i', $arg, $m)) {
                        $setTagsFile = !empty($m[2]) ? substr($m[2], 1) : ($this->ARGV ? array_shift($this->ARGV) : '');
                        if ('' === $setTagsFile) {
                            $this->Error("File must be specified for -tagsFromFile option\n");
                            $badCmd = true;
                            continue;
                        }
                        // create necessary lists, etc for this new -tagsFromFile file
                        $this->AddSetTagsFile($setTagsFile, ['Replace' => (!empty($m[1]) && 'add' === strtolower($m[1])) ? 0 : 1]);
                        $tagsFrom = 'File';
                        continue;
                    }
                    if ('@' === $a) {
                        $argFile = array_shift($this->ARGV);
                        if (!$argFile) {
                            $this->Error("Expecting filename for -@ option\n");
                            $badCmd = true;
                            continue;
                        }
                        // switch to new ARGFILE if using chained -stay_open options
                        if (1 == $this->stayOpen) {
                            // defer remaining arguments until we close this argfile
                            $this->moreArgs = $this->ARGV;
                            $this->ARGV = [];
                        } elseif (3 == $this->stayOpen) {
                            if ($this->stayOpenFile && '-' !== $this->stayOpenFile && $argFile === $this->stayOpenFile) {
                                // don't allow user to switch to the same -stay_open argfile
                                // because it will result in endless recursion
                                $this->stayOpen = 2;
                                $this->Warn("Ignoring request to switch to the same -stay_open ARGFILE ($argFile)\n");
                                continue;
                            }
                            $this->CloseStayOpen();
                            $this->stayOpen = 1;  // switch to this -stay_open file
                        }
                        $fp = $this->OpenArgFile($argFile);
                        if (!$fp && !str_starts_with($argFile, '/')) {
                            $fp = $this->OpenArgFile(\dirname(__DIR__, 2) . "/bin/$argFile");
                        }
                        if (!$fp) {
                            $this->Error("Error opening arg file $argFile\n");
                            $badCmd = true;
                            continue;
                        }
                        if (1 == $this->stayOpen) {
                            $this->stayOpenFp = $fp;
                            $this->stayOpenFile = $argFile;   // remember the name of the file we have open
                            $this->stayOpenBuff = '';         // initialize buffer for reading this file
                            $this->stayOpen = 2;
                            $this->helped = 1;
                            $this->ReadStayOpen($this->ARGV);
                            continue;
                        }
                        $newArgs = [];
                        $didBOM = false;
                        while (false !== ($line = fgets($fp))) {
                            // filter Byte Order Mark if it exists from start of UTF-8 text file
                            if (!$didBOM) {
                                $line = preg_replace('/^\xef\xbb\xbf/', '', $line);
                                $didBOM = true;
                            }
                            $line = $this->FilterArgfileLine($line);
                            if (null !== $line) {
                                $newArgs[] = $line;
                            }
                        }
                        if ($fp !== $this->stdin) {
                            fclose($fp);
                        }
                        array_unshift($this->ARGV, ...$newArgs);
                        continue;
                    }
                    if (preg_match('/^(-?)(a|duplicates)$/i', $arg, $m)) {
                        $mt->Options('Duplicates', $m[1] ? 0 : 1);
                        continue;
                    }
                    if ('api' === $a) {
                        $opt = array_shift($this->ARGV);
                        if (null !== $opt && \strlen($opt)) {
                            $val = 1;
                            if (false !== ($pos = strpos($opt, '='))) {
                                $val = substr($opt, $pos + 1);
                                $opt = substr($opt, 0, $pos);
                            }
                            // empty string means an undefined value unless ^= is used
                            if (str_ends_with($opt, '^')) {
                                $opt = substr($opt, 0, -1);
                            } elseif (!\strlen((string) $val)) {
                                $val = null;
                            }
                            $mt->Options($opt, $val);
                        } else {
                            if (!$pass) {
                                $nextPass[] = '-api';
                                if (null !== $opt) {
                                    $nextPass[] = $opt;
                                }
                                continue;
                            }
                            fwrite($this->stdout, "Available API Options:\n");
                            foreach (ExifTool::AvailableOptions() as $o) {
                                if (empty($o[3])) {
                                    fwrite($this->stdout, Perl::sprintf("  %-17s - %s\n", $o[0], $o[2]));
                                }
                            }
                            $this->helped = 1;
                        }
                        continue;
                    }
                    if (preg_match('/^arg(s|format)$/i', $arg)) {
                        $this->argFormat = 1;
                        continue;
                    }
                    if (preg_match('/^(-?)b(inary)?$/i', $arg, $m)) {
                        [$this->binaryOutput, $this->noBinary] = $m[1] ? [null, 1] : [1, null];
                        $mt->Options('Binary', $this->binaryOutput);
                        $mt->Options('NoPDFList', $this->binaryOutput);
                        continue;
                    }
                    if (preg_match('/^c(oordFormat)?$/i', $arg)) {
                        $fmt = array_shift($this->ARGV);
                        if (!$fmt) {
                            $this->Error("Expecting coordinate format for -c option\n");
                            $badCmd = true;
                            continue;
                        }
                        $mt->Options('CoordFormat', $fmt);
                        continue;
                    }
                    if ('charset' === $a) {
                        $charset = ($this->ARGV && !preg_match('/^(-|\xe2\x88\x92)/', $this->ARGV[0])) ? array_shift($this->ARGV) : null;
                        if (!$charset) {
                            if (!$pass) {
                                $nextPass[] = '-charset';
                                if (null !== $charset) {
                                    $nextPass[] = $charset;
                                }
                                continue;
                            }
                            $charsets = array_values(array_unique(ExifToolVars::$charsetName));
                            sort($charsets, \SORT_STRING);
                            $this->PrintTagList('Available character sets', ...$charsets);
                            $this->helped = 1;
                        } elseif (!preg_match('/^(\w+)=/', $charset, $m) || 'exiftool' === strtolower($m[1])) {
                            if ($m) {
                                $charset = substr($charset, \strlen($m[0]));
                            }
                            // (Options warns about an invalid character set)
                            $mt->Options('Charset', $charset);
                            $this->setCharset = $mt->Options('Charset');
                        } else {
                            // set internal encoding of specified metadata type
                            $type = ['id3' => 'ID3', 'iptc' => 'IPTC', 'exif' => 'EXIF', 'filename' => 'FileName',
                                     'photoshop' => 'Photoshop', 'quicktime' => 'QuickTime', 'riff' => 'RIFF'][strtolower($m[1])] ?? null;
                            if (!$type) {
                                $this->Warn("Unknown type for -charset option: $m[1]\n");
                                continue;
                            }
                            $mt->Options("Charset$type", substr($charset, \strlen($m[0])));
                        }
                        continue;
                    }
                    if (preg_match('/^config$/i', $arg)) {
                        $this->Warn("Ignored -config option (not first on command line)\n");
                        array_shift($this->ARGV);
                        continue;
                    }
                    if (preg_match('/^(csv|j(son)?)(\+?=.*)?$/i', $arg, $m)) {
                        $dbFile = $m[3] ?? '';
                        $dbType = 'csv' === strtolower($m[1]) ? 'CSV' : 'JSON';
                        if (!$dbFile) {
                            if ('CSV' === $dbType) {
                                $this->csv = $dbType;
                            } else {
                                $this->json = 1;
                                $mt->Options('Duplicates', 1);
                            }
                            continue;
                        }
                        // must process on 2nd pass so -f and -charset options are available
                        if (!$pass) {
                            if ($this->tags) {
                                $this->Warn("Tag arguments should come after the -$m[1]= option\n");
                            }
                            $nextPass[] = "-$arg";
                            $this->newValues[] = ['SaveCount' => ++$this->saveCount]; // marker to save new values now
                            $this->dbSaveCount = $this->saveCount;
                            $tagsFrom = 'CSV';
                            continue;
                        }
                        preg_match('/^(\+?=)/', $dbFile, $mm);
                        $dbFile = substr($dbFile, \strlen($mm[1]));
                        if ('+=' === $mm[1]) {
                            $this->dbAdd = 2;
                        }
                        if ($srcStdin) {
                            $this->vout = $this->stderr;
                        }
                        if ($this->verbose) {
                            fwrite($this->vout, "Reading $dbType file $dbFile\n");
                        }
                        $fh = null;
                        if ($mt->Open($fh, $dbFile)) {
                            if ('CSV' === $dbType) {
                                $msg = Import::ReadCSV($fh, $this->database, $this->forcePrint, $this->csvDelim);
                            } else {
                                $chset = $mt->Options('Charset');
                                $msg = Import::ReadJSON($fh, $this->database, $this->forcePrint, $chset);
                            }
                            fclose($fh);
                        } else {
                            $msg = "Error opening $dbType file '{$dbFile}'";
                        }
                        if ($msg) {
                            $this->Warn("$msg\n");
                        }
                        $this->isWriting = 1;
                        $this->csv = $dbType;
                        continue;
                    }
                    if (preg_match('/^csvdelim$/i', $arg)) {
                        $this->csvDelim = array_shift($this->ARGV);
                        if (null === $this->csvDelim) {
                            $this->Error("Expecting argument for -csvDelim option\n");
                            $badCmd = true;
                            continue;
                        }
                        if (str_contains($this->csvDelim, '"')) {
                            $this->Error("CSV delimiter can not contain a double quote\n");
                            $badCmd = true;
                            continue;
                        }
                        $unescape = ['t' => "\t", 'n' => "\n", 'r' => "\r", '\\' => '\\'];
                        $this->csvDelim = preg_replace_callback('/\\\\(.)/s', static fn ($m) => $unescape[$m[1]] ?? "\\$m[1]", $this->csvDelim);
                        $mt->Options('CSVDelim', $this->csvDelim);
                        continue;
                    }
                    if ('d' === $arg || 'dateformat' === $a) {
                        $fmt = array_shift($this->ARGV);
                        if (!$fmt) {
                            $this->Error("Expecting date format for -d option\n");
                            $badCmd = true;
                            continue;
                        }
                        $mt->Options('DateFormat', $fmt);
                        continue;
                    }
                    if ('D' === $arg || 'decimal' === $a) {
                        $this->showTagID = 'D';
                        continue;
                    }
                    if (preg_match('/^diff$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if (preg_match('/^delete_original(!?)$/i', $arg, $m)) {
                        $this->deleteOrig = $m[1] ? 2 : 1;
                        continue;
                    }
                    if (preg_match('/^list_dir$/i', $arg)) {
                        $this->listDir = 1;
                        continue;
                    }
                    if ('e' === $arg || '-composite' === $a) {
                        $mt->Options('Composite', 0);
                        continue;
                    }
                    if ('-e' === $arg || 'composite' === $a) {
                        $mt->Options('Composite', 1);
                        continue;
                    }
                    // (-eh is undocumented)
                    if ('E' === $arg || 'escapehtml' === $a || 'eh' === $a) {
                        $this->escapeHTML = 1;
                        continue;
                    }
                    if ('ec' === $a || 'escapec' === $a) {
                        $this->escapeC = 1;
                        continue;
                    }
                    if ('ex' === $a || 'escapexml' === $a) {
                        $escapeXML = 1;
                        continue;
                    }
                    if (preg_match('/^echo(\d)?$/i', $arg, $m)) {
                        $n = ($m[1] ?? '') ?: 1;
                        $earg = array_shift($this->ARGV);
                        if (null === $earg) {
                            continue;
                        }
                        if ($n > 4) {
                            $this->Warn("Invalid -echo number\n");
                            continue;
                        }
                        if ($n > 2) {
                            if (3 == $n) {
                                $this->echo3[] = $earg;
                            } else {
                                $this->echo4[] = $earg;
                            }
                        } else {
                            fwrite(2 == $n ? $this->stderr : $this->stdout, "$earg\n");
                        }
                        $this->helped = 1;
                        continue;
                    }
                    if (preg_match('/^(ee|extractembedded)(\d*)$/i', $arg, $m)) {
                        $mt->Options('ExtractEmbedded', $m[2] ?: 1);
                        $mt->Options('Duplicates', 1);
                        continue;
                    }
                    if (preg_match('/^efile(\d+)?(!)?$/i', $arg, $m)) {
                        $earg = array_shift($this->ARGV);
                        if (null === $earg) {
                            $this->Error("Expecting file name for -$arg option\n");
                            $badCmd = true;
                            continue;
                        }
                        $n = $m[1] ?? '';
                        if (!$n || (int) $n & 0x01) {
                            $this->efile[0] = $earg;    // error
                        }
                        if ($n && (int) $n & 0x02) {
                            $this->efile[1] = $earg;    // unchanged
                        }
                        if ($n && (int) $n & 0x04) {
                            $this->efile[2] = $earg;    // failed -if condition
                        }
                        if ($n && (int) $n & 0x08) {
                            $this->efile[3] = $earg;    // updated
                        }
                        if ($n && (int) $n & 0x016) {
                            $this->efile[4] = $earg;    // created
                        }
                        if (!empty($m[2])) {
                            @unlink($earg);
                        }
                        continue;
                    }
                    // (-execute handled at top of loop)
                    if (preg_match('/^-?ext(ension)?(\+)?$/i', $arg, $m)) {
                        $ext = array_shift($this->ARGV);
                        if (null === $ext) {
                            $this->Error("Expecting extension for -ext option\n");
                            $badCmd = true;
                            continue;
                        }
                        $flag = str_starts_with($arg, '-') ? 0 : (empty($m[2]) ? 1 : 2);
                        $this->filterFlag |= (0x01 << $flag);
                        $ext = preg_replace('/^\./', '', $ext);  // remove leading '.' if it exists
                        $this->filterExt[strtoupper($ext)] = $flag ? 1 : 0;
                        continue;
                    }
                    if ('f' === $arg || 'forceprint' === $a) {
                        $this->forcePrint = 1;
                        continue;
                    }
                    if (preg_match('/^F([-+]?\d*)$/', $arg, $m) || preg_match('/^fixbase([-+]?\d*)$/i', $arg, $m)) {
                        $mt->Options('FixBase', $m[1]);
                        continue;
                    }
                    if (preg_match('/^fast(\d*)$/i', $arg, $m)) {
                        $mt->Options('FastScan', \strlen($m[1]) ? $m[1] : 1);
                        continue;
                    }
                    if (preg_match('/^(file\d+)$/i', $arg) || preg_match('/^fileorder(\d*)$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if ('globaltimeshift' === $a) {
                        $mt->Options('GlobalTimeShift', array_shift($this->ARGV));
                        continue;
                    }
                    if (preg_match('/^(g)(roupHeadings|roupNames)?([\d:]*)$/i', $arg, $m)) {
                        $this->showGroup = ($m[3] ?? '') ?: 0;
                        $this->allGroup = !empty($m[2]) ? 'roupnames' === strtolower($m[2]) : 'G' === $m[1];
                        if (preg_match('/\b5\b/', (string) $this->showGroup)) {
                            $mt->Options('SavePath', 1);
                        }
                        if (preg_match('/\b6\b/', (string) $this->showGroup)) {
                            $mt->Options('SaveFormat', 1);
                        }
                        continue;
                    }
                    if ('geotag' === $a) {
                        $trkfile = array_shift($this->ARGV);
                        if (!$pass) {
                            // defer to next pass so the filename charset is available
                            array_push($nextPass, '-geotag', $trkfile);
                            continue;
                        }
                        if (!self::GeotagPorted()) {
                            $badCmd = $this->NotPorted($arg);
                            continue;
                        }
                        if (!$trkfile) {
                            $this->Error("Expecting file name for -geotag option\n");
                            $badCmd = true;
                            continue;
                        }
                        // allow wildcards in filename
                        if (self::HasWildcards($trkfile)) {
                            // (PHP glob() for File::Glob::bsd_glob)
                            $trks = glob($trkfile) ?: [];
                            if (!$trks) {
                                $this->Error("No matching file found for -geotag option\n");
                                $badCmd = true;
                                continue;
                            }
                            while (\count($trks) > 1) {
                                $this->newValues[] = 'geotag=' . array_shift($trks);
                            }
                            $trkfile = array_pop($trks);
                        }
                        $arg = "geotag=$trkfile";
                        // (fall through!)
                    }
                    if ('h' === $arg || 'htmlformat' === $a) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if ('H' === $arg || 'hex' === $a) {
                        $this->showTagID = 'H';
                        continue;
                    }
                    if (preg_match('/^htmldump([-+]?\d+)?$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if (preg_match('/^i(gnore)?$/i', $arg)) {
                        $dir = array_shift($this->ARGV);
                        if (null === $dir) {
                            $this->Error("Expecting directory name for -i option\n");
                            $badCmd = true;
                            continue;
                        }
                        $this->ignore[$dir] = 1;
                        if ('HIDDEN' === $dir) {
                            $this->ignoreHidden = 1;
                        }
                        continue;
                    }
                    if (preg_match('/^if(\d*)$/i', $arg, $m)) {
                        $cond = array_shift($this->ARGV);
                        $fast = \strlen($m[1]) ? $m[1] : null;
                        if (null === $cond) {
                            $this->Error("Expecting expression for -if option\n");
                            $badCmd = true;
                            continue;
                        }
                        // use lowest -fast setting if multiple conditions
                        if (!$this->condition || null === $fast || (null !== $this->fastCondition && $this->fastCondition > $fast)) {
                            $this->fastCondition = $fast;
                        }
                        // prevent processing file unnecessarily for simple case of failed '$ok' or 'not $ok'
                        if (preg_match('/^\s*(not\s*)\$ok\s*$/i', $cond) && !$this->rtnValPrev) {
                            $this->failCondition = 1;
                        }
                        // add to list of requested tags
                        preg_match_all('/\$\{?((?:[-_0-9A-Z]+:)*[-_0-9A-Z?*]+)/i', $cond, $mm);
                        array_push($this->requestTags, ...$mm[1]);
                        // (PHP: refuse now the Perl code which can't be translated)
                        try {
                            $this->CompileCondition($mt->InsertTagValues($cond, [], new \ArrayObject()));
                        } catch (\DomainException $e) {
                            $this->Error("Sorry, this -if expression is not supported by the PHP port of exiftool ({$e->getMessage()}): $cond\n");
                            $badCmd = true;
                            continue;
                        } catch (\RuntimeException) {
                            // (Perl syntax errors fail the condition for each file, like Perl)
                        }
                        $this->condition[] = $cond;
                        continue;
                    }
                    if (preg_match('/^(k|pause)$/i', $arg)) {
                        $this->pause = 1;
                        continue;
                    }
                    if ('l' === $arg || 'long' === $a) {
                        --$this->outFormat;
                        continue;
                    }
                    if ('L' === $arg || 'latin' === $a) {
                        $mt->Options('Charset', 'Latin');
                        continue;
                    }
                    if ('lang' === $a) {
                        $this->langOpt = ($this->ARGV && !preg_match('/^(-|\xe2\x88\x92)/', $this->ARGV[0])) ? array_shift($this->ARGV) : null;
                        if ($this->langOpt) {
                            // make lower case and use underline as a separator (eg. 'en_ca')
                            $this->langOpt = strtr($this->langOpt, '-ABCDEFGHIJKLMNOPQRSTUVWXYZ', '_abcdefghijklmnopqrstuvwxyz');
                            $mt->Options('Lang', $this->langOpt);
                            if ($this->langOpt === $mt->Options('Lang')) {
                                continue;
                            }
                        } elseif (!$pass) {
                            $nextPass[] = '-lang';
                            if (null !== $this->langOpt) {
                                $nextPass[] = $this->langOpt;
                            }
                            continue;
                        }
                        $langs = $this->quiet ? '' : "Available languages:\n";
                        foreach (ExifToolVars::$langs as $lang) {
                            $langs .= "  $lang - " . ExifToolVars::$langName[$lang] . "\n";
                        }
                        $langs = strtr($langs, '_', '-');  // display dashes instead of underlines in language codes
                        $langs = $mt->Decode($langs, 'UTF8');
                        if ($this->langOpt) {
                            $this->Error("Invalid or unsupported language '{$this->langOpt}'.\n$langs");
                            $badCmd = true;
                            continue;
                        }
                        fwrite($this->stdout, $langs);
                        $this->helped = 1;
                        continue;
                    }
                    if ('listitem' === $a) {
                        $li = array_shift($this->ARGV);
                        if (!(null !== $li && ExifTool::IsInt($li))) {
                            $this->Warn("Expecting integer for -listItem option\n");
                            continue;
                        }
                        $mt->Options('ListItem', $li);
                        $this->listItem = $li;
                        continue;
                    }
                    if (preg_match('/^(m|ignoreminorerrors)$/i', $arg)) {
                        $mt->Options('IgnoreMinorErrors', 1);
                        continue;
                    }
                    if (preg_match('/^(n|-printconv)$/i', $arg)) {
                        $mt->Options('PrintConv', 0);
                        continue;
                    }
                    if (preg_match('/^(-n|printconv)$/i', $arg)) {
                        $mt->Options('PrintConv', 1);
                        continue;
                    }
                    if ('nop' === $a) {
                        $this->helped = 1;  // (undocumented) no operation, added in 11.25
                        continue;
                    }
                    if (preg_match('/^o(ut)?$/i', $arg)) {
                        $this->outOpt = array_shift($this->ARGV);
                        if (null === $this->outOpt) {
                            $this->Error("Expected output file or directory name for -o option\n");
                            $badCmd = true;
                            continue;
                        }
                        self::CleanFilename($this->outOpt);
                        // verbose messages go to STDERR of output is to console
                        // (Perl tests $vout here, a GLOB reference which never matches /^-(\.\w+)?$/)
                        continue;
                    }
                    if ('overwrite_original' === $a) {
                        $this->overwriteOrig = 1;
                        continue;
                    }
                    if ('overwrite_original_in_place' === $a) {
                        $this->overwriteOrig = 2;
                        continue;
                    }
                    if ('plot' === $a) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if (preg_match('/^p(-?)$/', $arg, $m) || preg_match('/^printformat(-?)$/i', $arg, $m)) {
                        $fmt = array_shift($this->ARGV);
                        if ($pass) {
                            $this->LoadPrintFormat($fmt, $m[1] ?: $this->binaryOutput);
                            // (MWG module not loaded: "mwg:" tags are not supported)
                        } else {
                            // defer to next pass so the filename charset is available
                            $nextPass[] = "-$arg";
                            $nextPass[] = $fmt;
                        }
                        continue;
                    }
                    if ('P' === $arg || 'preserve' === $a) {
                        $this->preserveTime = 1;
                        continue;
                    }
                    if ('password' === $a) {
                        $mt->Options('Password', array_shift($this->ARGV));
                        continue;
                    }
                    if (preg_match('/^progress(\d*)(:.*)?$/i', $arg, $m)) {
                        $this->progressIncr = ($m[1] ?? '') ?: 1;
                        $this->progressNext = 0; // start showing progress at the first file
                        if (empty($m[2])) {
                            $this->progress = 1;
                            if (null === $this->verbose) {
                                $this->verbose = 0;
                            }
                        } // (else: the console window title is not set by the PHP port)
                        $this->progressCount = 0;
                        continue;
                    }
                    if (preg_match('/^purge(\d*)$/i', $arg, $m)) {
                        $this->purge = $m[1] ?: 1;
                        ExifTool::Purge($this->purge);  // (undocumented) added in 13.53
                        continue;
                    }
                    if (preg_match('/^q(uiet)?$/i', $arg)) {
                        ++$this->quiet;
                        continue;
                    }
                    if (preg_match('/^r(ecurse)?(\.?)$/i', $arg, $m)) {
                        $this->recurse = empty($m[2]) ? 1 : 2;
                        continue;
                    }
                    if ('require' === $a) { // (undocumented) added in version 8.65
                        $ver = array_shift($this->ARGV);
                        if (!(null !== $ver && ExifTool::IsFloat($ver))) {
                            $this->Error("Expecting version number for -require option\n");
                            $badCmd = true;
                            continue;
                        }
                        if (!(Perl::num(ExifTool::$VERSION) >= Perl::num($ver))) {
                            $this->Error("Requires ExifTool version $ver or later\n");
                            $badCmd = true;
                        }
                        continue;
                    }
                    if ('restore_original' === $a) {
                        $this->deleteOrig = 0;
                        continue;
                    }
                    if ('S' === $arg || 'veryshort' === $a) {
                        $this->outFormat += 2;
                        continue;
                    }
                    if (preg_match('/^s(hort)?(\d*)$/i', $arg, $m)) {
                        $this->outFormat = '' === ($m[2] ?? '') ? $this->outFormat + 1 : $m[2];
                        continue;
                    }
                    if ('scanforxmp' === $a) {
                        $mt->Options('ScanForXMP', 1);
                        continue;
                    }
                    if (preg_match('/^sep(arator)?$/i', $arg)) {
                        $sep = $this->listSep = array_shift($this->ARGV);
                        if (null === $this->listSep) {
                            $this->Error("Expecting list item separator for -sep option\n");
                            $badCmd = true;
                            continue;
                        }
                        // translate escape sequences
                        $sep = preg_replace_callback('/\\\\(.)/s', static fn ($m) => self::UNESCAPE_CHAR[$m[1]] ?? $m[1], $sep);
                        if (null !== $this->binSep) {
                            $this->binTerm = $sep;
                        } else {
                            $this->binSep = $sep;
                        }
                        $mt->Options('ListSep', $this->listSep);
                        $this->joinLists = 1;
                        // also split when writing values
                        $listSplit = preg_replace('/[^A-Za-z0-9_]/', '\\\\$0', $this->listSep);  // (quotemeta)
                        // a space in the string matches zero or more whitespace characters
                        $listSplit = preg_replace('/(\\\\ )+/', '\\s*', $listSplit);
                        // but a single space alone matches one or more whitespace characters
                        if ('\\s*' === $listSplit) {
                            $listSplit = '\\s+';
                        }
                        $mt->Options('ListSplit', $listSplit);
                        continue;
                    }
                    if (preg_match('/^(-)?sort$/i', $arg, $m)) {
                        $sortOpt = empty($m[1]) ? 1 : 0;
                        continue;
                    }
                    if ('srcfile' === $a) {
                        if (!$this->ARGV) {
                            $this->Warn("Expecting FMT for -srcfile option\n");
                            continue;
                        }
                        $this->srcFmt[] = array_shift($this->ARGV);
                        continue;
                    }
                    if ('stay_open' === $a) {
                        $sarg = array_shift($this->ARGV);
                        if (null === $sarg) {
                            $this->Warn("Expecting argument for -stay_open option\n");
                            continue;
                        }
                        if (preg_match('/^(1|true)$/i', $sarg)) {
                            if (!$this->stayOpen) {
                                $this->stayOpen = 1;
                            } elseif (2 == $this->stayOpen) {
                                $this->stayOpen = 3;  // chained -stay_open options
                            } else {
                                $this->Warn("-stay_open already active\n");
                            }
                        } elseif (preg_match('/^(0|false)$/i', $sarg)) {
                            if ($this->stayOpen >= 2) {
                                // close -stay_open argfile and process arguments up to this point
                                $this->CloseStayOpen();
                                array_push($this->ARGV, ...$this->moreArgs);
                                $this->moreArgs = [];
                            } elseif (!$this->stayOpen) {
                                $this->Warn("-stay_open wasn't active\n");
                            }
                            $this->stayOpen = 0;
                        } else {
                            $this->Warn("Invalid argument for -stay_open\n");
                        }
                        continue;
                    }
                    if (preg_match('/^(-)?struct$/i', $arg, $m)) {
                        $mt->Options('Struct', empty($m[1]) ? 1 : 0);
                        continue;
                    }
                    if (preg_match('/^t(ab)?$/', $arg)) {
                        $this->tabFormat = 1;
                        continue;
                    }
                    if ('T' === $arg || 'table' === $a) {
                        $this->tabFormat = $this->forcePrint = 1;
                        $this->outFormat += 2;
                        ++$this->quiet;
                        continue;
                    }
                    if (preg_match('/^(u)(nknown(2)?)?$/i', $arg, $m)) {
                        $inc = (!empty($m[3]) || (empty($m[2]) && 'U' === $m[1])) ? 2 : 1;
                        $mt->Options('Unknown', $mt->Options('Unknown') + $inc);
                        continue;
                    }
                    if ('use' === $a) {
                        $module = array_shift($this->ARGV);
                        if (!$module) {
                            $this->Error("Expecting module name for -use option\n");
                            $badCmd = true;
                            continue;
                        }
                        if ('mwg' === strtolower($module)) {
                            $useMWG = 1;
                            continue;
                        }
                        if (preg_match('/[^\w:]/', $module)) {
                            $this->Error("Invalid module name: $module\n");
                            $badCmd = true;
                            continue;
                        }
                        // (only the modules of ExifTool can be loaded: no Perl modules or files)
                        if (!ExifTool::RequireModule(str_starts_with($module, 'Image::ExifTool::') ? $module : "Image::ExifTool::$module")) {
                            $this->Error("Error using module $module\n");
                            $badCmd = true;
                        }
                        continue;
                    }
                    if ('userparam' === $a) {
                        $opt = array_shift($this->ARGV);
                        if (null === $opt) {
                            $this->Error("Expected parameter for -userParam option\n");
                            $badCmd = true;
                            continue;
                        }
                        if (!str_contains($opt, '=')) {
                            $opt .= '=1';
                        }
                        $mt->Options('UserParam', $opt);
                        continue;
                    }
                    if (preg_match('/^v(erbose)?(\d*)$/i', $arg, $m)) {
                        $this->verbose = '' === $m[2] ? ($this->verbose ?: 0) + 1 : $m[2];
                        continue;
                    }
                    if (preg_match('/^(w|textout|tagout)([!+]*)$/i', $arg, $m)) {
                        // (note: all logic ignores $textOut of 0 or '')
                        $this->textOut = array_shift($this->ARGV);
                        if (!$this->textOut) {
                            // (Perl: $textOut = shift || Warn(...), and Warn returns '')
                            $this->Warn("Expecting argument for -$arg option\n");
                            $this->textOut = '';
                        }
                        [, $t1, $t2] = $m;
                        $this->textOverwrite = 0;
                        if (str_contains($t2, '!')) {
                            $this->textOverwrite += 1;  // overwrite
                        }
                        if (str_contains($t2, '+')) {
                            $this->textOverwrite += 2;  // append
                        }
                        if ('W' !== $t1 && 'tagout' !== strtolower($t1)) {
                            $this->tagOut = null;
                        } elseif ($this->textOverwrite >= 2 && !preg_match('/%[-+]?\d*[.:]?\d*[lu]?[tgso]/', $this->textOut)) {
                            $this->tagOut = 0;  // append tags to one file
                        } else {
                            $this->tagOut = 1;  // separate file for each tag
                        }
                        continue;
                    }
                    if (preg_match('/^(-?)(wext|tagoutext)$/i', $arg, $m)) {
                        $ext = array_shift($this->ARGV);
                        if (null === $ext) {
                            $this->Error("Expecting extension for -wext option\n");
                            $badCmd = true;
                            continue;
                        }
                        $flag = 1;
                        if ($m[1]) {
                            $this->wext['*'] = 1;
                            $flag = -1;
                        }
                        $ext = preg_replace('/^\./', '', $ext);
                        $this->wext[strtolower($ext)] = $flag;
                        continue;
                    }
                    if ('wm' === $a || 'writemode' === $a) {
                        $wm = array_shift($this->ARGV);
                        if (null === $wm) {
                            $this->Error("Expecting argument for -$arg option\n");
                            $badCmd = true;
                            continue;
                        }
                        if (!preg_match('/^[wcg]*$/i', $wm)) {
                            $this->Error("Invalid argument for -$arg option\n");
                            $badCmd = true;
                            continue;
                        }
                        $mt->Options('WriteMode', $wm);
                        continue;
                    }
                    if ('x' === $arg || 'exclude' === $a) {
                        $tag = array_shift($this->ARGV);
                        if (null === $tag) {
                            $this->Error("Expecting tag name for -x option\n");
                            $badCmd = true;
                            continue;
                        }
                        $tag = preg_replace('/\ball\b/i', '*', $tag);   // replace 'all' with '*' in tag names
                        if (!$tagsFrom) {
                            $this->exclude[] = $tag;
                        } elseif ('CSV' === $tagsFrom) {
                            $this->csvExclude[] = $tag;
                        } else {
                            $this->setTags[$setTagsFile][] = "-$tag";
                        }
                        continue;
                    }
                    if ('X' === $arg || 'xmlformat' === $a) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if ('php' === $a) {
                        $this->json = 2;
                        $mt->Options('Duplicates', 1);
                        continue;
                    }
                    if (preg_match('/^z(ip)?$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if ('' === $arg) {
                        $this->files[] = '-';   // read STDIN
                        $srcStdin = 1;
                        continue;
                    }
                    if (1 == \strlen($arg) && '*' !== $arg) {
                        $this->Error("Unknown option -$arg\n");
                        $badCmd = true;
                        continue;
                    }
                    if (preg_match('/^[^<]+(<?)=(.*)/s', $arg, $m)) {
                        $val = $m[2];
                        if ($m[1] && \strlen($val) && ('@' === $val || null === $this->FilenameSPrintf($val))) {
                            // save count of new values before a dynamic value
                            $this->newValues[] = ['SaveCount' => ++$this->saveCount];
                        }
                        $this->newValues[] = $arg;
                        if (preg_match('/^([-_0-9A-Z]+:)*1?mwg:/i', $arg)) {
                            $useMWG = 1;
                        } elseif (preg_match('/^([-_0-9A-Z]+:)*(filename|directory|testname)\b/i', $arg)) {
                            $this->doSetFileName = 1;
                        } elseif (preg_match('/^([-_0-9A-Z]+:)*(geotag|geotime|geosync|geolocate)\b/i', $arg, $m)) {
                            if ('geolocate' !== strtolower($m[2]) && !self::GeotagPorted()) {
                                $badCmd = $this->NotPorted($arg);
                                continue;
                            }
                            if ('geotime' === strtolower($m[2])) {
                                $addGeotime = '';
                            } else {
                                // add geotag/geosync/geolocate commands first
                                array_unshift($this->newValues, array_pop($this->newValues));
                                if ('geotag' === strtolower($m[2]) && (null === $addGeotime || $addGeotime) && \strlen($val)) {
                                    $addGeotime = ($m[1] ?: '') . 'Geotime<${DateTimeOriginal#;$_=$self->GetValue(\'SubSecDateTimeOriginal\',\'ValueConv\') || $_}';
                                }
                            }
                        }
                    } else {
                        // (Perl tests $1 below: the dash of this option, unless set by this /(<|>)/ match)
                        $redir = '-';
                        // assume '-tagsFromFile @' if tags are being redirected
                        // and not from CSV and -tagsFromFile hasn't already been specified
                        if (!$setTagsFile && 'CSV' !== $tagsFrom && preg_match('/(<|>)/', $arg, $m)) {
                            $redir = $m[1];
                            $this->AddSetTagsFile($setTagsFile = '@');
                            $tagsFrom = 'File';
                        }
                        if ('CSV' === $tagsFrom) {
                            if (str_starts_with($arg, '-')) {
                                $this->csvExclude[] = substr($arg, 1);
                            } else {
                                $this->tags[] = $arg;
                            }
                        } elseif ($setTagsFile) {
                            $this->setTags[$setTagsFile][] = $arg;
                            if ('>' === $redir) {
                                if (preg_match('/^(.*>\s*)?([-_0-9A-Z]+:)*1?mwg:/si', $arg)) {
                                    $useMWG = 1;
                                }
                                if (preg_match('/\b(filename|directory|testname)#?$/i', $arg)) {
                                    $this->doSetFileName = 1;
                                } elseif (preg_match('/\bgeotime#?$/i', $arg)) {
                                    if (!self::GeotagPorted()) {
                                        $badCmd = $this->NotPorted($arg);
                                        continue;
                                    }
                                    $addGeotime = '';
                                }
                            } else {
                                if (preg_match('/^([^<]+<\s*(.*\$\{?)?)?([-_0-9A-Z]+:)*1?mwg:/si', $arg)) {
                                    $useMWG = 1;
                                }
                                if (preg_match('/^([-_0-9A-Z]+:)*(filename|directory|testname)\b/i', $arg)) {
                                    $this->doSetFileName = 1;
                                } elseif (preg_match('/^([-_0-9A-Z]+:)*geotime\b/i', $arg)) {
                                    if (!self::GeotagPorted()) {
                                        $badCmd = $this->NotPorted($arg);
                                        continue;
                                    }
                                    $addGeotime = '';
                                }
                            }
                        } else {
                            $isExclude = str_starts_with($arg, '-');
                            if ($isExclude) {
                                $arg = substr($arg, 1);
                            }
                            if (!preg_match('/^([-_0-9A-Z*]+:)*([-_0-9A-Z*?]+)#?$/i', $arg)) {
                                $this->Warn("Invalid TAG name: \"$arg\"\n");
                            }
                            if ($isExclude) {
                                $this->exclude[] = $arg;    // (push everything for backward compatibility)
                            } else {
                                $this->tags[] = $arg;
                            }
                        }
                    }
                } else {
                    if (!$pass) {
                        // defer to next pass so the filename charset is available
                        $nextPass[] = $arg;
                        continue;
                    }
                    // (no wildcard expansion: the shell does it, except on Windows)
                    $this->files[] = $arg;
                    if ('-' === $arg) {
                        $srcStdin = 1;
                    }
                }
            }

            // set "OK" UserParam based on result of last command
            $mt->Options('UserParam', 'OK=' . ($this->rtnValPrev ? '' : '1'));

            // set verbose output to STDERR if output could be to console
            if ($srcStdin && ($this->isWriting || $this->newValues)) {
                $this->vout = $this->stderr;
            }
            if ($this->vout === $this->stderr) {
                $mt->Options('TextOut', $this->vout);
            }

            // change default EXIF string encoding if MWG used
            if ($useMWG && null === $mt->Options('CharsetEXIF')) {
                $mt->Options('CharsetEXIF', 'UTF8');
            }

            // allow geolocation without input file if set to a position
            if (!$this->files && !$this->outOpt && !$this->newValues) {
                $loc = $mt->Options('Geolocation');
                // use undocumented feature to input JSON file directly from command line
                if ($loc && '1' !== Perl::str($loc)) {
                    $this->files[] = '@JSON:{}';
                    $this->geoOnly = 1;
                }
            }

            // print help
            if (!(($this->tags && !$this->outOpt) || $this->files || $this->newValues || $this->geoOnly)) {
                // (no "No matching files" error: the shell expands the wildcards)
                if ($this->outOpt) {
                    $this->Error("Nothing to write\n");
                    continue;
                }
                if (!$this->helped) {
                    // (no perldoc: show the short syntax message)
                    fwrite($this->stdout, "Syntax:  exiftool [OPTIONS] FILE\n\n");
                    fwrite($this->stdout, "Consult the exiftool documentation for a full list of options.\n");
                }
                continue;
            }

            // do sanity check on -delete_original and -restore_original
            if (null !== $this->deleteOrig && ($this->newValues || $this->tags)) {
                if (!$this->newValues) {
                    $verb = $this->deleteOrig ? 'deleting' : 'restoring from';
                    $this->Error("Can't specify tags when $verb originals\n");
                } elseif ($this->deleteOrig) {
                    $this->Error("Can't use -delete_original when writing.\n");
                    $this->Error("Maybe you meant -overwrite_original ?\n");
                } else {
                    $this->Error("It makes no sense to use -restore_original when writing\n");
                }
                continue;
            }

            if ($this->overwriteOrig > 1 && $this->outOpt) {
                $this->Error("Can't overwrite in place when -o option is used\n");
                continue;
            }

            // (-diff, -htmlDump, -X and -plot are not ported)
            if ($this->tagOut && ($this->csv || $this->json || $this->printFmt || $this->tabFormat)) {
                $this->Error("Sorry, -W may not be combined with -csv, -htmlDump, -j, -p, -t or -X\n");
                continue;
            }

            if ($this->csv && 'CSV' === $this->csv && !$this->isWriting) {
                $this->json = 0;  // (not compatible)
                if ($this->textOut) {
                    $this->textOut2 = $this->textOut;
                    $this->textOut = null;
                }
                if ($this->binaryOutput) {
                    $this->binaryOutput = 0;
                    if (null === $this->setCharset) {
                        $this->setCharset = 'default';
                    }
                }
                if ($this->printFmt) {
                    $this->Warn("The -csv option has no effect when -p is used\n");
                    $this->csv = null;
                }
            }
            if ($this->textOut2) {
                if ($this->textOverwrite > 1) {
                    $this->Error("Can not append to multi-file output format\n");
                    $this->textOut2 = null;
                    continue;
                }
                if (!$this->textOverwrite && $mt->Exists($this->textOut2, 1)) {
                    $this->Error("Output file {$this->textOut2} already exists\n");
                    $this->textOut2 = null;
                    continue;
                }
                // make sure we can write the output text file before processing all input files
                $this->CreateDirectory($this->textOut2);  // create directory if necessary
                $fh = null;
                if ($mt->Open($fh, $this->textOut2, '>')) {
                    fclose($fh);
                    unlink($this->textOut2);    // (this was just a test)
                } else {
                    $this->Error("Error creating {$this->textOut2}\n");
                    $this->textOut2 = null;
                    continue;
                }
            }

            if ($this->escapeHTML || $this->json) {
                // must be UTF8 for HTML conversion and JSON output
                if ($this->json) {
                    $mt->Options('Charset', 'UTF8');
                }
                // use Escape option to do our HTML escaping
                if ($this->escapeHTML) {
                    $mt->Options('Escape', 'HTML');
                }
            } elseif ($escapeXML) {
                $mt->Options('Escape', 'XML');
            }

            // set sort option
            if ($sortOpt) {
                // (note that -csv sorts alphabetically by default anyway if more than 1 file)
                $sort = ($this->outFormat > 0 || $this->json || $this->csv) ? 'Tag' : 'Descr';
                $mt->Options('Sort', $sort);
                $mt->Options('Sort2', $sort);
            }

            // set $structOpt in case set by API option
            if ($mt->Options('Struct') && !$this->structOpt) {
                $this->structOpt = $mt->Options('Struct');
            }

            // set up for JSON and PHP output formats
            if ($this->json) {
                if (1 == $this->json) { // JSON
                    $this->fileHeader = '[';
                    $this->fileTrailer = "]\n";
                } else { // PHP
                    $this->fileHeader = 'Array(';
                    $this->fileTrailer = ");\n";
                }
                // allow binary output in a text-mode file when -php/-json and -b used together
                if ($this->binaryOutput) {
                    $this->binaryOutput = 0;
                }
                if (!$this->joinLists) {
                    $mt->Options('List', 1);
                }
                if ($this->tabFormat && !$this->showTagID) {
                    $this->showTagID = 'D';
                }
            } elseif ($this->structOpt) {
                $mt->Options('List', 1);
            } else {
                $this->joinLists = 1;     // join lists for all other unstructured output formats
            }

            if ($this->argFormat) {
                $this->outFormat = 3;
                if (null !== $this->showGroup) {
                    $this->allGroup = 1;
                }
            }

            // change to forward slashes if necessary in all filenames (like CleanFilename)
            if (ExifTool::IsPC()) {
                $this->files = str_replace('\\', '/', $this->files);
            }

            // can't do anything if no file specified
            if (!$this->files) {
                if (!$this->outOpt) {
                    $this->Error("No file specified\n");
                    continue;
                }
                $this->files[] = '';    // create file from nothing
            }

            // set Verbose option
            if ($this->verbose) {
                if (!($this->tags || $this->exclude || $this->tagOut)) {
                    $this->disableOutput = 1;
                }
                if (!$this->tagOut) {
                    $this->binaryOutput = null;    // disable conflicting option
                    $mt->Options('Verbose', $this->verbose);
                }
            } // (-v0 auto-flushes the output in Perl: PHP streams aren't buffered)

            // validate all tags we're writing
            $needSave = 1;
            if ($this->newValues) {
                // assume -geotime value if -geotag specified without -geotime
                if ($addGeotime) {
                    if (!($setTagsFile && '@' === $setTagsFile)) {
                        $this->AddSetTagsFile($setTagsFile = '@');
                    }
                    $this->setTags[$setTagsFile][] = $addGeotime;
                    if ($this->verbose) {
                        fwrite($this->vout, "Using default \"-$addGeotime\"\n");
                    }
                }
                $setTagsIndex = [];
                // add/delete option lookup
                $addDelOpt = ['+' => 'AddValue', '-' => 'DelValue', "\xe2\x88\x92" => 'DelValue'];
                $this->saveCount = 0;
                foreach ($this->newValues as $nv) {
                    if (\is_array($nv)) {
                        // save new values now if we stored a "SaveCount" marker
                        if ($nv['SaveCount']) {
                            $this->saveCount = $mt->SaveNewValues();
                            $needSave = 0;
                            // insert marker to load values from CSV file now if this was the CSV file
                            if ($nv['SaveCount'] == $this->dbSaveCount) {
                                $this->dynamicFiles[] = new ScalarRef(null);   // (Perl \$csv)
                            }
                        }
                        continue;
                    }
                    if (!preg_match('/(.*?)=(.*)/s', $nv, $m)) {
                        continue;
                    }
                    [, $tag, $newVal] = $m;
                    $tag = preg_replace('/\ball\b/i', '*', $tag);    // replace 'all' with '*' in tag names
                    $tag = preg_replace('/\^([-+]*)$/', '$1', $tag, 1, $n);
                    if (!$n && '' === $newVal) {
                        $newVal = null;     // undefined to delete tag
                    }
                    if (preg_match('/^(All)?TagsFromFile$/i', $tag)) {
                        if (null === $newVal) {
                            $this->Error("Need file name for -tagsFromFile\n");
                            continue 2;
                        }
                        ++$this->isWriting;
                        if ('@' === $newVal || null === $this->FilenameSPrintf($newVal)
                            // can't set tags yet if we are using tags from other files with the -fileNUM option
                            || preg_grep('/\bfile\d+:/i', array_filter($this->setTags[$newVal] ?? [], 'is_string')))
                        {
                            $this->dynamicFiles[] = $newVal;
                            continue;   // set tags from dynamic file later
                        }
                        if (!($mt->Exists($newVal) || '-' === $newVal)) {
                            $this->Error("File '{$newVal}' does not exist for -tagsFromFile option\n");
                            continue 2;
                        }
                        $setTags = $this->setTags[$newVal] ?? [];
                        // do we have multiple -tagsFromFile options with this file?
                        if (!empty($this->setTagsList[$newVal])) {
                            // use the tags set in the i-th occurrence
                            $i = $setTagsIndex[$newVal] ?? 0;
                            $setTagsIndex[$newVal] = $i + 1;
                            if (!empty($this->setTagsList[$newVal][$i])) {
                                $setTags = $this->setTagsList[$newVal][$i];
                            }
                        }
                        // set specified tags from this file
                        if (!$this->DoSetFromFile($mt, $newVal, $setTags)) {
                            $this->rtnVal = 1;
                            continue 2;
                        }
                        $needSave = 1;
                        continue;
                    }
                    $opts = ['Shift' => 0];  // shift values if possible instead of adding/deleting
                    // allow writing of 'Unsafe' tags unless specified by wildcard
                    if (!preg_match('/[?*]/', $tag)) {
                        $opts['Protected'] = 1;
                    }

                    $tag = preg_replace('/</', '', $tag, 1, $n);
                    if ($n && null !== $newVal) {
                        if (null !== $this->FilenameSPrintf($newVal)) {
                            if (!$this->SlurpFile($newVal, $newVal)) {
                                continue;   // read file data into $newVal
                            }
                        } else {
                            if (preg_match('/([-+]|\xe2\x88\x92)$/', $tag, $mm)) {
                                $tag = substr($tag, 0, -\strlen($mm[1]));
                                $opts[$addDelOpt[$mm[1]]] = 1;
                            }
                            // verify that this tag can be written
                            $result = ExifTool::IsWritable($tag);
                            if ($result) {
                                $opts['ProtectSaved'] = $this->saveCount;   // protect new values set after this
                                // add to list of dynamic tag values
                                $this->dynamicFiles[] = [$tag, $newVal, $opts];
                                ++$this->isWriting;
                            } elseif (null !== $result) {
                                $this->Warn("Tag '{$tag}' is not writable\n");
                            } else {
                                $this->Warn("Tag '{$tag}' does not exist\n");
                            }
                            continue;
                        }
                    }
                    if (preg_match('/([-+]|\xe2\x88\x92)$/', $tag, $mm)) {
                        $tag = substr($tag, 0, -\strlen($mm[1]));
                        $opts[$addDelOpt[$mm[1]]] = 1;  // set AddValue or DelValue option
                        // set $newVal to '' if deleting nothing
                        if ('-' === $mm[1] && null === $newVal) {
                            $newVal = '';
                        }
                    }
                    if ($this->escapeC && null !== $newVal) {
                        $newVal = preg_replace_callback('/\\\\(x([0-9a-fA-F]{2})|.)/s',
                            static fn ($m) => ($m[2] ?? '') !== '' ? \chr(hexdec($m[2])) : (self::UNESC_C[$m[1]] ?? $m[1]), $newVal);
                    }
                    [$rtn, $wrn] = $mt->SetNewValueList($tag, $newVal, ...$opts) + [0, null];
                    $needSave = 1;
                    if ($rtn) {
                        ++$this->isWriting;
                    }
                    if ($wrn) {
                        $this->Warning($mt, $wrn);
                    }
                }
                // exclude specified tags
                foreach ($this->exclude as $ex) {
                    $mt->SetNewValue($ex, null, Replace: 2);
                    $needSave = 1;
                }
                if (!($this->isWriting || $this->outOpt || $this->tags)) {
                    $this->Error("Nothing to do.\n");
                    continue;
                }
            } elseif (preg_grep('/^(\*:)?\*$/', $this->exclude)) {
                $this->Error("All tags excluded -- nothing to do.\n");
                continue;
            }
            if ($this->isWriting) {
                // (-diff and -plot are not ported)
                if ($this->tags && !$this->outOpt && !$this->csv) {
                    [$tg, $s] = \count($this->tags) > 1 ? ["{$this->tags[0]} ...", 's'] : [$this->tags[0], ''];
                    $this->Warn("Ignored superfluous tag name$s or invalid option$s: -$tg\n");
                }
            }
            // save current state of new values if setting values from target file
            // or if we may be translating to a different format
            if ($this->outOpt || ($this->dynamicFiles && $needSave)) {
                $mt->SaveNewValues();
            }

            if (\count($this->files) > 1) {
                $this->multiFile = 1;
            }
            if ($this->exclude) {
                $mt->Options('Exclude', $this->exclude);
            }

            if ($this->binaryOutput) {
                $this->outFormat = 99;    // shortest possible output format
                $mt->Options('PrintConv', 0);
                if (!($this->textOut || $this->binaryStdout)) {
                    $this->binaryStdout = 1;
                    $mt->Options('TextOut', $this->vout = $this->stderr);
                }
                // disable conflicting options
                $this->showGroup = null;
            }

            // sort by groups to look nicer depending on options
            if (null !== $this->showGroup && !($this->tags && ($this->allGroup || $this->csv)) && ($sortOpt || null === $sortOpt)) {
                $mt->Options('Sort', "Group{$this->showGroup}");
            }

            if ($this->textOut) {
                self::CleanFilename($this->textOut);  // make all forward slashes
                // add '.' before output extension if necessary
                if (!preg_match('/[.%]/', $this->textOut) && null === $this->tagOut) {
                    $this->textOut = ".{$this->textOut}";
                }
            }

            // determine if we should scan for only writable files
            if ($this->outOpt) {
                $type = ExifTool::GetFileType($this->outOpt);
                if ($type) {
                    // (must test original file name because we can write .webp but not other RIFF types)
                    $canWrite = ExifTool::CanWrite($this->outOpt);
                    if (!$canWrite) {
                        if ('' === $canWrite) {
                            $type = ExifTool::GetFileExtension($this->outOpt) ?? strtoupper($this->outOpt);
                        }
                        $this->Error("Can't write $type files\n");
                        continue;
                    }
                    if (!ExifTool::CanCreate($type)) {
                        $this->scanWritable = $type;
                    }
                } else {
                    $this->scanWritable = 1;
                }
                $this->isWriting = 1;     // set writing flag
            } elseif ($this->isWriting || null !== $this->deleteOrig) {
                $this->scanWritable = 1;
            }

            // initialize alternate encoding flag
            $this->altEnc = $mt->Options('Charset');
            if ('UTF8' === $this->altEnc) {
                $this->altEnc = null;
            }

            // set flag to fix description lengths if necessary
            if (!$this->altEnc && 'en' !== $mt->Options('Lang')) {
                // (no Unicode::GCString: count characters, not columns)
                $this->fixLen = 1;
            }

            // (-fileOrder is not ported)
            if (null !== $this->progress) {
                // expand FILE argument to count the number of files to process
                $allFiles = [];
                $this->ProcessFiles($mt, $allFiles);
                $this->files = $allFiles;
            }
            // set file count for progress message
            if (null !== $this->progress) {
                $this->progressMax = \count($this->files);
            }

            // store duplicate database information under absolute path and with standardized case
            $dbKeys = array_map('strval', array_keys($this->database));
            foreach ($dbKeys as $key) {
                $db = $this->database[$key];
                if (str_contains($key, '\\')) {
                    $key = str_replace('\\', '/', $key);    // allow for backslashes in SourceFile
                    $this->database[$key] = $db;
                }
                if (empty($this->database[strtolower($key)])) {
                    $this->database[strtolower($key)] = $db;    // duplicate entry with lower case
                }
                // (punt on using ConvertFileName here, so $absPath may be a mix of encodings)
                $absPath = self::AbsPath($key);
                if (null !== $absPath) {
                    if (empty($this->database[$absPath])) {
                        $this->database[$absPath] = $db;
                    }
                    if ($this->verbose && $this->verbose > 1) {
                        fwrite($this->vout, "Imported entry for '{$key}' (full path: '{$absPath}')\n");
                    }
                    if (empty($this->database[strtolower($absPath)])) {
                        $this->database[strtolower($absPath)] = $db;
                    }
                } elseif ($this->verbose && $this->verbose > 1) {
                    fwrite($this->vout, "Imported entry for '{$key}' (no full path)\n");
                }
            }

            // process all specified files
            $this->ProcessFiles($mt);

            if ($this->filtered && !$this->validFile) {
                $this->Error("No file with specified extension\n");
            }

            // print folder/file trailer if necessary
            if ($this->textOut) {
                foreach ($this->outTrailer as $file => $trailer) {
                    if (!$trailer) {
                        continue;
                    }
                    $fh = null;
                    if ($mt->Open($fh, $file, '>>')) {
                        fwrite($fh, $trailer);
                        fclose($fh);
                    } else {
                        $this->Error("Error appending to $file\n");
                    }
                }
            } else {
                if ($this->sectTrailer) {
                    fwrite($this->stdout, $this->sectTrailer);
                }
                if ($this->fileTrailer && !$this->fileHeader) {
                    fwrite($this->stdout, $this->fileTrailer);
                }
                // print CSV output file if necessary (-plot is not ported)
                $fp = $err = null;
                if ($this->textOut2) {
                    if (!$mt->Open($fp, $this->textOut2, '>')) {
                        $this->Error("Error creating {$this->textOut2}\n");
                        $err = 1;
                    }
                }
                if (!$err && $this->csv && !$this->isWriting) {
                    $this->PrintCSV($fp);
                }
                if ($fp) {
                    if (!fclose($fp)) {
                        $err = 1;
                    }
                    if ($err) {
                        $mt->Unlink($this->textOut2);
                    } else {
                        $this->created[$this->textOut2] = 1;
                    }
                }
            }

            $totWr = $this->countGoodWr + $this->countBadWr + $this->countSameWr + $this->countCopyWr +
                     $this->countGoodCr + $this->countBadCr;

            if (null !== $this->deleteOrig) {

                // print summary and delete requested files
                if (!$this->quiet) {
                    if ($this->countDir) {
                        fwrite($this->stdout, Perl::sprintf("%5d directories scanned\n", $this->countDir));
                    }
                    if ($this->countNewDir) {
                        fwrite($this->stdout, Perl::sprintf("%5d directories created\n", $this->countNewDir));
                    }
                    if ($this->countFailed) {
                        fwrite($this->stdout, Perl::sprintf("%5d files failed condition\n", $this->countFailed));
                    }
                    fwrite($this->stdout, Perl::sprintf("%5d image files found\n", $this->count));
                }
                if ($this->delFiles) {
                    // verify deletion unless "-delete_original!" was specified
                    if (1 == $this->deleteOrig) {
                        fwrite($this->stdout, Perl::sprintf('%5d originals will be deleted!  Are you sure [y/n]? ', \count($this->delFiles)));
                        $response = fgets($this->stdin);
                        if (!preg_match('/^(y|yes)\s*$/i', (string) $response)) {
                            $this->Warn("Originals not deleted.\n");
                            continue;
                        }
                    }
                    $this->countGoodWr = $mt->Unlink(...$this->delFiles);
                    $this->countBad = \count($this->delFiles) - $this->countGoodWr;
                }
                if ($this->quiet) {
                    // no more messages
                } elseif ($this->count && !$this->countGoodWr && !$this->countBad) {
                    fwrite($this->stdout, Perl::sprintf("%5d original files found\n", $this->countGoodWr)); // (this will be 0)
                } elseif ($this->deleteOrig) {
                    if ($this->count) {
                        fwrite($this->stdout, Perl::sprintf("%5d original files deleted\n", $this->countGoodWr));
                    }
                    if ($this->countBad) {
                        fwrite($this->stdout, Perl::sprintf("%5d originals not deleted due to errors\n", $this->countBad));
                    }
                } else {
                    if ($this->count) {
                        fwrite($this->stdout, Perl::sprintf("%5d image files restored from original\n", $this->countGoodWr));
                    }
                    if ($this->countBad) {
                        fwrite($this->stdout, Perl::sprintf("%5d files not restored due to errors\n", $this->countBad));
                    }
                }

            } elseif ((!$this->binaryStdout || $this->verbose) && !$this->quiet) {

                // print summary
                $tot = $this->count + $this->countBad;
                if ($this->countDir || $totWr || $this->countFailed || $tot > 1 || $this->textOut || $this->countLink) {
                    $o = (($this->json || $this->printFmt || $this->csv) && !$this->textOut) ? $this->stderr : $this->vout;
                    if ($this->countDir) {
                        fwrite($o, Perl::sprintf("%5d directories scanned\n", $this->countDir));
                    }
                    if ($this->countNewDir) {
                        fwrite($o, Perl::sprintf("%5d directories created\n", $this->countNewDir));
                    }
                    if ($this->countFailed) {
                        fwrite($o, Perl::sprintf("%5d files failed condition\n", $this->countFailed));
                    }
                    if ($this->countGoodCr) {
                        fwrite($o, Perl::sprintf("%5d image files created\n", $this->countGoodCr));
                    }
                    if ($totWr - $this->countGoodCr - $this->countBadCr - $this->countCopyWr) {
                        fwrite($o, Perl::sprintf("%5d image files updated\n", $this->countGoodWr));
                    }
                    if ($this->countSameWr) {
                        fwrite($o, Perl::sprintf("%5d image files unchanged\n", $this->countSameWr));
                    }
                    if ($this->countCopyWr) {
                        fwrite($o, Perl::sprintf("%5d image files %s\n", $this->countCopyWr, $this->overwriteOrig ? 'moved' : 'copied'));
                    }
                    if ($this->countBadWr) {
                        fwrite($o, Perl::sprintf("%5d files weren't updated due to errors\n", $this->countBadWr));
                    }
                    if ($this->countBadCr) {
                        fwrite($o, Perl::sprintf("%5d files weren't created due to errors\n", $this->countBadCr));
                    }
                    if (($tot + $this->countFailed) > 1 || ($this->countDir && !$totWr)) {
                        fwrite($o, Perl::sprintf("%5d image files read\n", $this->count));
                    }
                    if ($this->countBad) {
                        fwrite($o, Perl::sprintf("%5d files could not be read\n", $this->countBad));
                    }
                    if ($this->textOut || $this->textOut2) {
                        fwrite($o, Perl::sprintf("%5d output files created\n", \count($this->created)));
                    }
                    if ($this->appended) {
                        fwrite($o, Perl::sprintf("%5d output files appended\n", \count($this->appended)));
                    }
                    if (!empty($this->countLink['Hard']) || !empty($this->countLink['BadHard'])) {
                        fwrite($o, Perl::sprintf("%5d hard links created\n", $this->countLink['Hard'] ?? 0));
                    }
                    if (!empty($this->countLink['BadHard'])) {
                        fwrite($o, Perl::sprintf("%5d hard links could not be created\n", $this->countLink['BadHard']));
                    }
                    if (!empty($this->countLink['Sym']) || !empty($this->countLink['BadSym'])) {
                        fwrite($o, Perl::sprintf("%5d symbolic links created\n", $this->countLink['Sym'] ?? 0));
                    }
                    if (!empty($this->countLink['BadSym'])) {
                        fwrite($o, Perl::sprintf("%5d symbolic links could not be created\n", $this->countLink['BadSym']));
                    }
                }
            }

            // set error status if we had any errors or if all files failed the "-if" condition
            if ($this->countBadWr || $this->countBadCr || $this->countBad) {
                $this->rtnVal = 1;
            } elseif ($this->countFailed && !($this->count || $totWr) && !$this->rtnVal) {
                $this->rtnVal = 2;
            }

            if ($this->purge) {
                ExifTool::Purge(0);   // do final purging
            }

            // clean up after each command
            $this->Cleanup();
        } // end "Command" loop ........................................................

        if ($this->stayOpen >= 2) {
            $this->CloseStayOpen();
        }

        $this->Cleanup();   // (Perl END block)

        return $this->Exit($this->rtnValApp);   // all done
    }

    //------------------------------------------------------------------------------
    // Get image information from EXIF data in file (or write file if writing)
    // Inputs: 0) ExifTool object reference, 1) file name
    private function GetImageInfo(ExifTool $et, $orig): void
    {
        $foundTags = [];
        $info = $file = null;

        // (-progress:TITLE: the console window title is not set by the PHP port)
        if (!(\strlen($orig) || $this->outOpt)) {
            $this->Warn("Error: Zero-length file name - \"\"\n");
            ++$this->countBad;

            return;
        }
        // determine the name of the source file based on the original input file name
        if ($this->srcFmt) {
            $first = null;
            foreach ($this->srcFmt as $fmt) {
                $file = '@' === $fmt ? $orig : $this->FilenameSPrintf($fmt, $orig);
                // use this file if it exists
                if (null !== $file && $et->Exists($file)) {
                    $first = null;
                    break;
                }
                if ($this->verbose) {
                    fwrite($this->vout, "Source file $file does not exist\n");
                }
                $first ??= $file;
            }
            if (null !== $first) {
                $file = $first;
            }
            [$d, $f] = ExifTool::SplitFileName($orig);
            $et->Options('UserParam', "OriginalDirectory#=$d");
            $et->Options('UserParam', "OriginalFileName#=$f");
        } else {
            $file = $orig;
        }
        // (-fileNUM and -z are not ported)

        $pipe = $file;
        // evaluate -if expression for conditional processing
        if ($this->condition) {
            $result = $evalWarning = null;
            if (!$this->failCondition) {
                $condInfo = new \ArrayObject();    // (Perl %info)
                // extract information and build expression for evaluation
                $opts = ['Duplicates' => 1, 'RequestTags' => $this->requestTags, 'Verbose' => 0, 'HtmlDump' => 0];
                if (null !== $this->fastCondition) {
                    $opts['FastScan'] = $this->fastCondition;
                }
                // return all tags but explicitly mention tags on command line so
                // requested images will generate the appropriate warnings
                if ($this->tags) {
                    $foundTags = ['*', ...$this->tags];
                }
                $info = $et->ImageInfo($this->Infile($pipe, $this->isWriting), $foundTags, $opts);
                $foundTags = $et->IO_TAG_LIST;
                foreach ($this->condition as $condition) {
                    $cond = $et->InsertTagValues($condition, $foundTags, $condInfo);
                    //### eval "-if" condition (%info, $self)
                    try {
                        $result = $this->CompileCondition($cond)($condInfo);
                    } catch (\RuntimeException|\DomainException $e) {
                        $evalWarning = $e->getMessage();
                    }
                    if ($evalWarning) {
                        // fail condition if warning is issued
                        $result = null;
                        if ($this->verbose) {
                            $this->Warn("Condition: $evalWarning - $file\n");
                        }
                    }
                    if (!$result) {
                        break;
                    }
                }
                if ($this->fastCondition) {
                    $foundTags = [];    // ignore if we didn't get all tags
                }
            }
            if ($result) {
                // discard $info for non-existent file
                if (!('-' === $file || $et->Exists($file))) {
                    $info = null;
                }
            } else {
                if ($this->verbose) {
                    $this->Progress($this->vout, "-------- $file (failed condition)");
                }
                $this->EFile($file, 2);
                ++$this->countFailed;

                return;
            }
            // can't make use of $info if verbose because we must reprocess
            // the file anyway to generate the verbose output
            // (also if writing just to avoid double-incrementing FileSequence)
            if ($this->isWriting || $this->verbose || null !== $this->fastCondition) {
                $info = null;
                --$et->FILE_SEQUENCE;
            }
        } elseif (preg_match('/^(\@JSON:)(.*)/', $file, $m)) {
            // read JSON file from command line
            $file = preg_replace('/^(\@JSON:)(.*)/', '$1', $file, 1);
            $info = $et->ImageInfo(new ScalarRef($m[2]), $foundTags);
            $foundTags = $et->IO_TAG_LIST;
            if ($this->geoOnly) {
                foreach (array_keys($info) as $key) {
                    if (!str_starts_with($key, 'Geolocation')) {
                        unset($info[$key]);
                    }
                }
                $file = ' ';
            }
        }
        if (null !== $this->deleteOrig) {
            if (null !== $this->verbose) {
                $this->Progress($this->vout, "======== $file");
            }
            ++$this->count;
            $original = "{$file}_original";
            if (!$et->Exists($original)) {
                return;
            }
            if ($this->deleteOrig) {
                if ($this->verbose) {
                    fwrite($this->vout, "Scheduled for deletion: $original\n");
                }
                $this->delFiles[] = $original;
            } elseif ($et->Rename($original, $file)) {
                if ($this->verbose) {
                    fwrite($this->vout, "Restored from $original\n");
                }
                $this->EFile($file, 3);
                ++$this->countGoodWr;
            } else {
                $this->Warn("Error renaming $original\n");
                $this->EFile($file);
                ++$this->countBad;
            }

            return;
        }
        ++$this->seqFileNum;    // increment our file counter
        [$dir] = ExifTool::SplitFileName($orig);
        $this->seqFileDir = $this->seqFileDirs[$dir] = ($this->seqFileDirs[$dir] ?? 0) + 1;

        $lineCount = 0;
        $fp = $outfile = $append = null;
        // (-diff and -plot are not ported)
        if ($this->textOut && ($this->verbose || $et->Options('PrintCSV')) && !$this->tagOut) {
            [$fp, $outfile, $append] = $this->OpenOutputFile($orig) + [null, null, null];
            if (!$fp) {
                $this->EFile($file);
                ++$this->countBad;

                return;
            }
            // delete file if we exit prematurely (unless appending)
            if (!$append) {
                $this->tmpText = $outfile;
            }
            $et->Options('TextOut', $fp);
        }

        if ($this->isWriting) {
            if (null !== $this->verbose) {
                $this->Progress($this->vout, "======== $file");
            }
            $this->SetImageInfo($et, $file, $orig);
            $info = $et->GetInfo('Warning', 'Error');
            $this->PrintErrors($et, $info, $file);
            // close output text file if necessary
            if (null !== $outfile) {
                $this->tmpText = null;
                fclose($fp);
                $et->Options('TextOut', $this->vout);
                if (!empty($info['Error'])) {
                    $et->Unlink($outfile);  // erase bad file
                } elseif ($append) {
                    if (empty($this->created[$outfile])) {
                        $this->appended[$outfile] = 1;
                    }
                } else {
                    $this->created[$outfile] = 1;
                }
            }

            return;
        }

        // extract information from this file
        // (Perl $info is a hash ref: true even when empty)
        if (!('-' === $file || $et->Exists($file) || null !== $info)) {
            $this->Warn("Error: File not found - $file\n");
            $this->FileNotFound($file);
            if (null !== $outfile) {
                fclose($fp);
                $this->tmpText = null;
                $et->Unlink($outfile);
            }
            $this->EFile($file);
            ++$this->countBad;

            return;
        }
        // print file/progress message
        $o = null;
        if (!($this->binaryOutput || $this->textOut || $this->printFmt || $this->csv)) {
            if (!$this->json) {
                if (($this->multiFile && !$this->quiet) || $this->progress) {
                    $o = $this->stdout;
                }
            }
        }
        if ($this->progress && !$o) {
            $o = $this->stderr;
        }
        if ($o) {
            $this->Progress($o, "======== $file");
        }
        if (null !== $info) {
            // get the information we wanted
            if ($this->tags && !$this->printFmt) {
                $foundTags = $this->tags;
                $info = $et->GetInfo($foundTags);
                $foundTags = $et->IO_TAG_LIST;
            }
        } else {
            // request specified tags unless using print format option
            $oldDups = $et->Options('Duplicates');
            if ($this->printFmt) {
                $et->Options('Duplicates', 1);
                $et->Options('RequestTags', $this->requestTags);
                // (SetTags is only available from advanced formatting expressions: not supported)
            } else {
                $foundTags = $this->tags;
            }
            // extract the information
            $info = $et->ImageInfo($this->Infile($pipe), $foundTags);
            $foundTags = $et->IO_TAG_LIST;  // (Perl updates \@foundTags in place)
            $et->Options('Duplicates', $oldDups);
        }

        // all done now if we already wrote output text file (eg. verbose option)
        if ($fp) {
            if (null !== $outfile) {
                $et->Options('TextOut', $this->stdout);
                $this->tmpText = null;
                if (!empty($info['Error'])) {
                    fclose($fp);
                    $et->Unlink($outfile);  // erase bad file
                } else {
                    ++$lineCount;   // output text file (likely) is not empty
                }
            }
            if (!empty($info['Error'])) {
                $this->Warn('Error: ' . Perl::str($info['Error']) . " - $file\n");
                $this->EFile($file);
                ++$this->countBad;

                return;
            }
        }

        // print warnings to stderr if using binary output
        // (because we are likely ignoring them and piping stdout to file)
        // or if there is none of the requested information available
        if ($this->binaryOutput || !$info) {
            $errs = $et->GetInfo('Warning', 'Error');
            if ($this->PrintErrors($et, $errs, $file)) {
                $this->EFile($file);
                $this->rtnVal = 1;
            }
        } elseif ($et->GetValue('Error') || ($et->Validate && $et->GetValue('Warning'))) {
            $this->rtnVal = 1;
        }

        // open output file (or stdout if no output file) if not done already
        if (!(null !== $outfile || $this->tagOut)) {
            [$fp, $outfile, $append] = $this->OpenOutputFile($orig) + [null, null, null];
            if (!$fp) {
                $this->EFile($file);
                ++$this->countBad;

                return;
            }
            if (null !== $outfile && !$append) {
                $this->tmpText = $outfile;
            }
        }

        // (-diff is not ported)
        // restore state of comma flag for this file if appending
        if ($append && ($this->textOverwrite & 0x02)) {
            $this->comma = $this->outComma[$outfile] ?? null;
        }

        // print the results for this file
        if ($this->printFmt) {
            // output using print format file (-p) option
            $doc = [];
            $grp = $cache = null;
            $this->fileTrailer = '';
            // repeat for each embedded document if necessary (only if -ee used)
            if ($et->Options('ExtractEmbedded')) {
                // (cache tag keys if there are sub-documents)
                if ($lastDoc = $et->DOC_COUNT) {
                    $cache = [];
                }
            } else {
                $lastDoc = 0;
            }
            for ($doc[0] = 0; $doc[0] <= $lastDoc;) {
                $docStr = implode('-', $doc);
                $skipBody = $opt = null;
                foreach (['HEAD', 'SECT', 'IF', 'BODY', 'ENDS', 'TAIL'] as $type) {
                    $prf = $this->printFmt[$type] ?? null;
                    if (!$prf) {
                        continue;
                    }
                    if ('HEAD' === $type && null !== $outfile) {
                        if (!empty($this->wroteHEAD[$outfile])) {
                            continue;
                        }
                        $this->wroteHEAD[$outfile] = 1;
                    }
                    if ('BODY' === $type && $skipBody) {
                        continue;
                    }
                    // silence "IF" warnings and warnings for subdocuments > 1
                    if ('IF' === $type || (($doc[0] > 1 || \count($doc) > 1) && !$et->OPTIONS['IgnoreMinorErrors'])) {
                        $opt = 'Silent';
                    } else {
                        $opt = 'Warn';
                    }
                    if ($lastDoc) {
                        if ($docStr) {
                            if ('HEAD' === $type || 'TAIL' === $type) {
                                continue;   // only repeat SECT/IF/BODY/ENDS
                            }
                            $grp = "Doc$docStr";
                        } else {
                            $grp = 'Main';
                        }
                    }
                    $lines = [];
                    foreach ($prf as $fmt) {
                        $line = $et->InsertTagValues($fmt, $foundTags, $opt, $grp, $cache);
                        if ('IF' === $type) {
                            if (null === $line) {
                                $skipBody = 1;
                            }
                        } elseif (null !== $line) {
                            $lines[] = $line;
                        }
                    }
                    $lineCount += \count($lines);
                    if ('SECT' === $type) {
                        $thisHeader = implode('', $lines);
                        if ($this->sectHeader && $this->sectHeader !== $thisHeader) {
                            if ($this->sectTrailer) {
                                fwrite($fp, $this->sectTrailer);
                            }
                            $this->sectHeader = null;
                        }
                        $this->sectTrailer = '';
                        if (!$this->sectHeader) {
                            fwrite($fp, $this->sectHeader = $thisHeader);
                        }
                    } elseif ('ENDS' === $type) {
                        if (null !== $this->sectHeader) {
                            $this->sectTrailer .= implode('', $lines);
                        }
                    } elseif ('TAIL' === $type) {
                        $this->fileTrailer .= implode('', $lines);
                    } elseif ($lines) {
                        fwrite($fp, implode('', $lines));
                    }
                }
                // find next available doc-subdoc
                $doc[] = 1;
                while (\count($doc) > 1) {
                    $nextDoc = implode('-', $doc);
                    if (!empty($et->HAS_DOC[$nextDoc])) {
                        break;
                    }
                    array_pop($doc);
                    ++$doc[\count($doc) - 1];
                }
            }
            if (null === $outfile) {
                unset($this->printFmt['HEAD']);  // print header only once per output file
            }
            $errs = $et->GetInfo('Warning', 'Error');
            if ($this->PrintErrors($et, $errs, $file)) {
                $this->EFile($file);
            }
        } elseif (!$this->disableOutput) {
            $line = null;
            $noDups = [];
            $csvInfo = [];
            $csvFile = null;
            $bra = $ket = $sep = $quote = $ind = null;
            if ($fp) {
                // print file header (only once)
                if ($this->fileHeader) {
                    if (!(null !== $outfile && (!empty($this->created[$outfile]) || !empty($this->appended[$outfile])))) {
                        fwrite($fp, $this->fileHeader);
                    }
                    if (!$this->textOut) {
                        $this->fileHeader = null;
                    }
                }
                if ($this->json) {
                    // set delimiters for JSON or PHP output
                    [$bra, $ket, $sep] = 1 == $this->json ? ['{', '}', ':'] : ['Array(', ')', ' =>'];
                    if ('JSONQ' === ($et->OPTIONS['StructFormat'] ?? null)) {
                        $quote = 1;
                    }
                    if ($this->comma) {
                        fwrite($fp, ",\n");
                    }
                    fwrite($fp, "$bra\n  \"SourceFile\"$sep " . $this->EscapeJSON($this->MyConvertFileName($et, $file), 1));
                    $this->comma = 1;
                    $ind = (null !== $this->showGroup && !$this->allGroup) ? '    ' : '  ';
                } elseif ($this->csv) {
                    $csvFile = $this->MyConvertFileName($et, $file);
                    $this->csvFiles[] = $csvFile;
                }
            }
            // suppress duplicates manually in JSON output
            $noDupsFlag = $this->json;
            $printConv = $et->Options('PrintConv');
            $lastGroup = '';
            $i = -1;
            foreach ($foundTags as $tag) { // TAG:
                ++$i;   // keep track on index in @foundTags
                $tagName = ExifTool::GetTagName($tag);
                $group = $valList = null;
                // get the value for this tag
                $val = $info[$tag] ?? null;
                // set flag if this is binary data
                $this->isBinary = ($val instanceof ScalarRef && null !== $this->binaryOutput);
                if (\is_array($val) || \is_object($val)) {
                    // happens with -j or -php when combined with -b:
                    if (null !== $this->binaryOutput && !$this->binaryOutput && (($et->TAG_INFO[$tag] ?? null)?->Protected)) {
                        // avoid extracting Unsafe binary tags (eg. data blocks) [insider information]
                        $lcTag = preg_replace('/ .*/', '', strtolower($tag));
                        if (!(($et->REQ_TAG_LOOKUP[$lcTag] ?? null) || ($et->OPTIONS['RequestAll'] ?? 0) > 2)) {
                            continue;
                        }
                    }
                    $val = $this->ConvertBinary($val); // convert SCALAR references
                    if (null === $val) {
                        continue;
                    }
                    if ($this->structOpt && \is_array($val)) {
                        // serialize structure if necessary
                        if (!$this->json) {
                            $val = XMP::SerializeStruct($et, $val);
                        }
                    } elseif (\is_array($val) && array_is_list($val)) {
                        if (null !== $this->listItem) {
                            // take only the specified item
                            $li = (int) $this->listItem;
                            $val = $val[$li < 0 ? \count($val) + $li : $li] ?? null;
                        // join arrays of simple values (with newlines for binary output)
                        } elseif ($this->binaryOutput) {
                            if ($this->tagOut) {
                                $valList = $val;
                                $val = array_shift($valList);
                            } else {
                                $val = self::join($this->binSep ?? "\n", $val);
                            }
                        } elseif ($this->joinLists) {
                            $val = self::join($this->listSep, $val);
                        }
                    }
                }
                if (null === $val) {
                    // ignore tags that weren't found unless necessary
                    if ($this->binaryOutput) {
                        continue;
                    }
                    if (null !== $this->forcePrint) {
                        $val = $this->forcePrint; // forced to print all tag values
                    } elseif (!$this->csv) {
                        continue;
                    }
                }
                if (null !== $this->showGroup) {
                    $group = $et->GetGroup($tag, $this->showGroup);
                    // look ahead to see if this tag may suppress a priority tag in
                    // the same group, and if so suppress this tag instead
                    // (note that the tag key may look like "TAG #(1)" when the "#" feature is used)
                    if ($noDupsFlag && preg_match('/^(.*?) ?\(/', $tag, $m) && isset($info[$m[1]])
                        && (string) $group === (string) $et->GetGroup($m[1], $this->showGroup)) {
                        continue;
                    }
                    if (!$group && ($this->json || $this->csv)) {
                        if (!preg_match('/\b4\b/', (string) $this->showGroup)) {
                            $group = 'Unknown';
                        } elseif ($this->json && !$this->allGroup) {
                            $group = 'Copy0';
                        }
                    }
                    if ($fp && !($this->allGroup || $this->csv)) {
                        if ($lastGroup !== (string) $group) {
                            if ($this->json) {
                                if ($lastGroup) {
                                    fwrite($fp, "\n  $ket");
                                }
                                if ($lastGroup || $this->comma) {
                                    fwrite($fp, ',');
                                }
                                fwrite($fp, "\n  \"$group\"$sep $bra");
                                $this->comma = null;
                                $noDups = [];  // allow duplicate names in different groups
                            } else {
                                fwrite($fp, "---- $group ----\n");
                            }
                            $lastGroup = (string) $group;
                        }
                        $group = null;   // undefine so we don't print it below
                    }
                } elseif ($noDupsFlag) {
                    // don't allow duplicates, but avoid suppressing the priority tag
                    if (preg_match('/^(.*?) ?\(/', $tag, $m) && isset($info[$m[1]])) {
                        continue;
                    }
                }

                ++$lineCount;   // we are printing something meaningful

                // loop through list values when -b -W used
                for (;;) {
                    if ($this->tagOut) {
                        // determine suggested extension for output file
                        $ext = $this->SuggestedExtension($et, $val, $tagName);
                        if ($this->wext && (($this->wext[$ext] ?? null) ?: ($this->wext['*'] ?? null) ?: -1) < 0) {
                            if ($this->verbose && $this->verbose > 1) {
                                fwrite($this->vout, "Not writing $ext output file for $tagName\n");
                            }
                            continue 2;
                        }
                        $groups = $et->GetGroup($tag);
                        if (null !== $outfile) {
                            fclose($fp);    // (shouldn't happen)
                            $this->tmpText = null;
                        }
                        $org = $et->GetValue('OriginalRawFileName') ?: $et->GetValue('OriginalFileName');
                        [$fp, $outfile, $append] = $this->OpenOutputFile($orig, $tagName, $groups, $ext, $org) + [null, null, null];
                        if (!$fp) {
                            ++$this->countBad;
                            continue 2;
                        }
                        if (!$append) {
                            $this->tmpText = $outfile;
                        }
                    }
                    // write binary output
                    if ($this->binaryOutput) {
                        fwrite($fp, Perl::str($val));
                        if (null !== $this->binTerm) {
                            fwrite($fp, $this->binTerm);
                        }
                        if ($this->tagOut) {
                            if ($append) {
                                if (empty($this->created[$outfile])) {
                                    $this->appended[$outfile] = 1;
                                }
                            } else {
                                $this->created[$outfile] = 1;
                            }
                            fclose($fp);
                            $this->tmpText = null;
                            if ($this->verbose) {
                                fwrite($this->vout, "Wrote $tagName to $outfile\n");
                            }
                            $outfile = $fp = null;
                            if (!$valList) {
                                continue 2;
                            }
                            $val = array_shift($valList);
                            continue;   // loop over values of List tag
                        }
                        continue 2;
                    }
                    break;
                }
                // save information for CSV output
                if ($this->csv) {
                    $tn = $tagName;
                    if (str_contains($tag, '#')) {
                        $tn .= '#';  // add ValueConv "#" suffix if used
                    }
                    $gt = $group ? "$group:$tn" : $tn;
                    // (tag-name case may be different if some tags don't exist
                    // in a file, so all logic must use lower-case tag names)
                    $lcTag = strtolower($gt);
                    // override existing entry only if top priority
                    if (isset($csvInfo[$lcTag]) && str_contains($tag, '(')) {
                        continue;
                    }
                    $csvInfo[$lcTag] = $val;
                    if (isset($this->csvTags[$lcTag])) {
                        // overwrite with actual extracted tag name
                        // (note: can't check "if defined $val" here because -f may be used)
                        if (isset($info[$tag])) {
                            $this->csvTags[$lcTag] = $gt;
                        }
                        continue;
                    }
                    // must check for "Unknown" group (for tags that don't exist)
                    if ($group && isset($this->csvTagList[$i]) && preg_match('/^(.*):' . preg_quote($tn, '/') . '$/i', $this->csvTagList[$i], $m)) {
                        if ('Unknown' === $group) {
                            continue;   // nothing more to do if we don't know tag group
                        }
                        if ('unknown' === $m[1]) {
                            // replace unknown entry in CSV tag lookup and list
                            unset($this->csvTags[$this->csvTagList[$i]]);
                            $this->csvTags[$lcTag] = null !== $val ? $gt : '';
                            $this->csvTagList[$i] = $lcTag;
                            continue;
                        }
                    }
                    // (don't save unextracted tag name unless -f was used)
                    $this->csvTags[$lcTag] = null !== $val ? $gt : '';
                    if (1 == \count($this->csvFiles)) {
                        $this->csvTagList[] = $lcTag; // save order of tags for first file
                    } elseif ($this->csvTagList) {
                        $this->csvTagList = [];
                    }
                    continue;
                }

                // get description if we need it (use tag name if $outFormat > 0)
                $desc = $this->outFormat > 0 ? $tagName : $et->GetDescription($tag);

                if ($this->json) {
                    // JSON or PHP output format
                    $tok = $this->allGroup ? "$group:$tagName" : $tagName;
                    if (isset($noDups[$tok])) {
                        continue;
                    }
                    $noDups[$tok] = 1;
                    if ($this->comma) {
                        fwrite($fp, ',');
                    }
                    fwrite($fp, "\n$ind\"$tok\"$sep ");
                    if ($this->showTagID || $this->outFormat < 0) {
                        $val = ['val' => $val];
                        if ($this->showTagID) {
                            [$id, $lang] = self::GetTagIDList($et, $tag);
                            if ('H' === $this->showTagID && preg_match('/^\d+$/', (string) $id)) {
                                $id = Perl::sprintf('0x%.4x', $id);
                            }
                            if ($lang) {
                                $val['lang'] = $lang;
                            }
                            $val['id'] = $id;
                        }
                        if ($this->tabFormat) {
                            $val['table'] = $et->GetTableName($tag);
                            $index = $et->GetTagIndex($tag);
                            if (null !== $index) {
                                $val['index'] = $index;
                            }
                        }
                        if ($this->outFormat < 0) {
                            $val['desc'] = $desc;
                            if ($printConv) {
                                $num = $et->GetValue($tag, 'ValueConv');
                                if (null !== $num && !$this->IsEqual($num, $val['val'], 1)) {
                                    $val['num'] = $num;
                                }
                            }
                            $ex = $et->TAG_EXTRA[$tag] ?? [];
                            if (isset($ex['G6'])) {
                                $val['fmt'] = $ex['G6'];
                            }
                            if (isset($ex['BinVal'])) {
                                $max = (Perl::num($et->OPTIONS['LimitLongValues'] ?? 0) - 5) / 3;
                                if ($max >= 0 && \strlen($ex['BinVal']) > (int) $max) {
                                    $max = (int) $max;
                                    $val['hex'] = implode(' ', [...str_split(bin2hex(substr($ex['BinVal'], 0, $max)), 2), '[...]']);
                                } else {
                                    $val['hex'] = implode(' ', str_split(bin2hex($ex['BinVal']), 2));
                                }
                            }
                            if (isset($ex['Rational']) && ($et->OPTIONS['SaveBin'] ?? null)) {
                                $val['rat'] = $ex['Rational'];
                            }
                        }
                    }
                    $this->FormatJSON($fp, $val, $ind, $quote);
                    $this->comma = 1;
                    continue;
                }
                $id = null;
                if ($this->showTagID) {
                    $id = self::GetTagIDList($et, $tag)[0];
                    if (preg_match('/^(\d+)(\.\d+)?$/', (string) $id, $m)) { // only print numeric ID's
                        if ('H' === $this->showTagID) {
                            $id = Perl::sprintf('0x%.4x', $m[1]);
                        }
                    } else {
                        $id = '-';
                    }
                }

                $val = Perl::str($val);
                if ($this->escapeC) {
                    $val = preg_replace_callback('/[\0-\x1f\\\\\x7f]/', static fn ($m) => self::ESC_C[$m[0]] ?? Perl::sprintf('\x%.2x', \ord($m[0])), $val);
                } else {
                    // translate unprintable chars in value and remove trailing spaces
                    $val = strtr($val, "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f", str_repeat('.', 32));
                    $val = str_replace("\0", '', $val);
                    $val = preg_replace('/\s+$/', '', $val);
                }

                $buff = '';
                if ($this->tabFormat) {
                    if (null !== $group) {
                        $buff = "$group\t";
                    }
                    if ($this->showTagID) {
                        $buff .= "$id\t";
                    }
                    if ($this->outFormat <= 1) {
                        $buff .= "$desc\t$val\n";
                    } elseif (null !== $line) {
                        $line .= "\t$val";
                    } else {
                        $line = $val;
                    }
                } elseif ($this->outFormat < 0) {    // long format
                    if (null !== $group) {
                        $buff = "[$group] ";
                    }
                    if ($this->showTagID) {
                        $buff .= "$id ";
                    }
                    $buff .= "$desc\n      $val\n";
                } elseif (0 == $this->outFormat || 1 == $this->outFormat) {
                    $len = 0;
                    if (null !== $group) {
                        $buff = Perl::sprintf('%-15s ', "[$group]");
                        $len = 16;
                    }
                    if ($this->showTagID) {
                        $wid = ('D' === $this->showTagID) ? 5 : 6;
                        $len += $wid + 1;
                        if (($wid = $len - \strlen($buff) - 1) < 1) {
                            $wid = 1;
                        }
                        $buff .= Perl::sprintf("%{$wid}s ", $id);
                    }
                    $wid = 32 - (\strlen($buff) - $len);
                    // pad description to a constant length
                    // (get actual character length when using alternate languages
                    // because these descriptions may contain UTF8-encoded characters)
                    $padLen = $wid - $this->LengthUTF8($desc);
                    if ($padLen < 0) {
                        $padLen = 0;
                    }
                    $buff .= $desc . str_repeat(' ', $padLen) . ": $val\n";
                } elseif (2 == $this->outFormat) {
                    if (null !== $group) {
                        $buff = "[$group] ";
                    }
                    if ($this->showTagID) {
                        $buff .= "$id ";
                    }
                    $buff .= "$tagName: $val\n";
                } elseif ($this->argFormat) {
                    $buff = '-';
                    if (null !== $group) {
                        $buff .= "$group:";
                    }
                    if (str_contains($tag, '#')) {
                        $tagName .= '#';  // add '#' suffix if used
                    }
                    $buff .= "$tagName=$val\n";
                } else {
                    if (null !== $group) {
                        $buff = "$group ";
                    }
                    if ($this->showTagID) {
                        $buff .= "$id ";
                    }
                    $buff .= "$val\n";
                }
                fwrite($fp, $buff);
                if ($this->tagOut) {
                    if ($append) {
                        if (empty($this->created[$outfile])) {
                            $this->appended[$outfile] = 1;
                        }
                    } else {
                        $this->created[$outfile] = 1;
                    }
                    fclose($fp);
                    $this->tmpText = null;
                    if ($this->verbose) {
                        fwrite($this->vout, "Wrote $tagName to $outfile\n");
                    }
                    $outfile = $fp = null;
                }
            }
            if (null !== $csvFile) {
                $this->database[$csvFile] = $csvInfo;
            }
            if ($fp) {
                if ($this->json) {
                    if ($lastGroup) {
                        fwrite($fp, "\n  $ket");
                    }
                    fwrite($fp, "\n$ket");
                    $this->comma = 1;
                } elseif ($this->tabFormat && $this->outFormat > 1) {
                    if (null !== $line) {
                        fwrite($fp, "$line\n");
                    }
                }
            }
        }
        if (null !== $outfile) {
            if ($this->textOverwrite & 0x02) {
                // save state of this file if we may be appending
                $this->outComma[$outfile] = $this->comma;
                $this->outTrailer[$outfile] = '';
                if ($this->sectTrailer) {
                    $this->outTrailer[$outfile] .= $this->sectTrailer;
                    $this->sectTrailer = '';
                }
                if ($this->fileTrailer) {
                    $this->outTrailer[$outfile] .= $this->fileTrailer;
                }
            } else {
                // write section and file trailers before closing the file
                if ($this->sectTrailer) {
                    fwrite($fp, $this->sectTrailer);
                    $this->sectTrailer = '';
                }
                if ($this->fileTrailer) {
                    fwrite($fp, $this->fileTrailer);
                }
            }
            fclose($fp);
            $this->tmpText = null;
            if ($lineCount) {
                if ($append) {
                    if (empty($this->created[$outfile])) {
                        $this->appended[$outfile] = 1;
                    }
                } else {
                    $this->created[$outfile] = 1;
                }
            } elseif (!$append) {
                $et->Unlink($outfile);  // don't keep empty output files
            }
            $this->comma = null;
        }
        ++$this->count;
    }

    //------------------------------------------------------------------------------
    // Set information in file
    // Inputs: 0) ExifTool object reference, 1) source file name
    //         2) original source file name ('' to create from scratch)
    // Returns: true on success
    private function SetImageInfo(ExifTool $et, $file, $orig)
    {
        $outfile = $restored = $isTemporary = $isStdout = $outType = $tagsFromSrc = null;
        $hardLink = $symLink = $testName = $sameFile = null;
        $infile = $file;    // save infile in case we change it again

        // clean up old temporary file if necessary
        if (null !== $this->tmpFile) {
            $et->Unlink($this->tmpFile);
            $this->tmpFile = null;
        }
        // clear any existing errors or warnings since we check these on return
        unset($et->VALUE['Error'], $et->VALUE['Warning']);

        // first, try to determine our output file name so we can return quickly
        // if it already exists (note: this test must be delayed until after we
        // set tags from dynamic files if writing FileName or Directory)
        if (null !== $this->outOpt) {
            if (preg_match('/^-(\.\w+)?$/', $this->outOpt, $m)) {
                // allow output file type to be specified with "-o -.EXT"
                if (isset($m[1])) {
                    $outType = ExifTool::GetFileType($this->outOpt);
                }
                $outfile = '-';
                $isStdout = 1;
            } else {
                $outfile = $this->FilenameSPrintf($this->outOpt, $orig);
                if ('' === $outfile) {
                    $this->Warn("Error: Can't create file with zero-length name from $orig\n");
                    $this->EFile($infile);
                    ++$this->countBadCr;

                    return 0;
                }
            }
            if (!$isStdout && (($et->IsDirectory($outfile) && !$this->listDir) || str_ends_with((string) $outfile, '/'))) {
                if (!str_ends_with($outfile, '/')) {
                    $outfile .= '/';
                }
                $name = preg_replace('{^.*/}s', '', $file);    // remove directory name
                $outfile .= $name;
            } else {
                $srcType = ExifTool::GetFileType($file) ?: '';
                $outType ??= ExifTool::GetFileType($outfile);
                if ($outType && ($srcType !== $outType || 'ICC' === $outType) && '-' !== $file) {
                    if (!ExifTool::CanCreate($outType)) {
                        $what = $srcType ? 'other types' : 'scratch';
                        $this->WarnOnce("Error: Can't create $outType files from $what\n");
                        $this->EFile($infile);
                        ++$this->countBadCr;

                        return 0;
                    }
                    if ('' !== $file) {
                        // restore previous new values unless done already
                        if (!$restored) {
                            $et->RestoreNewValues();
                        }
                        $restored = 1;
                        // translate to this type by setting specified tags from file
                        $setTags = $this->tags;
                        foreach ($this->exclude as $ex) {
                            $setTags[] = "-$ex";
                        }
                        // force some tags to be copied for certain file types
                        $forceCopy = ['ICC' => 'ICC_Profile', 'VRD' => 'CanonVRD', 'DR4' => 'CanonDR4'];
                        if (isset($forceCopy[$outType])) {
                            $setTags[] = $forceCopy[$outType];
                        }
                        // assume "-tagsFromFile @" unless -tagsFromFile already specified
                        // (%setTags won't be empty if -tagsFromFile used)
                        if (!$this->setTags || ($setTags && !isset($this->setTags['@']))) {
                            if (!$this->DoSetFromFile($et, $file, $setTags)) {
                                return 0;
                            }
                        } elseif ($setTags) {
                            // add orphaned tags to existing "-tagsFromFile @" for this file only
                            array_push($setTags, ...$this->setTags['@']);
                            $tagsFromSrc = $setTags;
                        }
                        // all done with source file -- create from meta information alone
                        $file = '';
                    }
                }
            }
            if (!$isStdout) {
                $outfile = $this->NextUnusedFilename($outfile);
                if ($et->Exists($outfile, 1) && !$this->doSetFileName) {
                    $this->Warn("Error: '{$outfile}' already exists - $infile\n");
                    $this->EFile($infile);
                    ++$this->countBadWr;

                    return 0;
                }
            }
        } elseif ('-' === $file) {
            $isStdout = 1;
        }
        // set tags from destination file if required
        if ($this->dynamicFiles) {
            // restore previous values if necessary
            if (!$restored) {
                $et->RestoreNewValues();
            }
            $setTagsIndex = [];
            foreach ($this->dynamicFiles as $dyFile) {
                if (\is_string($dyFile)) {
                    if ('@' === $dyFile) {
                        $fromFile = $orig;
                        $setTags = $tagsFromSrc ?? $this->setTags[$dyFile] ?? [];
                    } else {
                        $fromFile = $this->FilenameSPrintf($dyFile, $orig);
                        if (null === $fromFile) {
                            $this->EFile($infile);
                            ++$this->countBadWr;

                            return 0;
                        }
                        $setTags = $this->setTags[$dyFile] ?? [];
                    }
                    // do we have multiple -tagsFromFile options with this file?
                    if (!empty($this->setTagsList[$dyFile])) {
                        // use the tags set in the i-th occurrence
                        $i = $setTagsIndex[$dyFile] ?? 0;
                        $setTagsIndex[$dyFile] = $i + 1;
                        if (!empty($this->setTagsList[$dyFile][$i])) {
                            $setTags = $this->setTagsList[$dyFile][$i];
                        }
                    }
                    // set new values values from file
                    if (!$this->DoSetFromFile($et, $fromFile, $setTags)) {
                        return 0;
                    }
                } elseif (\is_array($dyFile)) {
                    // a dynamic file containing a simple tag value
                    $fname = $this->FilenameSPrintf($dyFile[1], $orig);
                    $buff = $rtn = $wrn = null;
                    $opts = $dyFile[2];
                    if (null !== $fname && $this->SlurpFile($fname, $buff)) {
                        if ($this->verbose) {
                            fwrite($this->vout, "Reading $dyFile[0] from $fname\n");
                        }
                        [$rtn, $wrn] = $et->SetNewValueList($dyFile[0], $buff, ...$opts) + [0, null];
                        if ($wrn) {
                            $this->Warn("$wrn\n");
                        }
                    }
                    // remove this tag if we couldn't set it properly
                    if (!$rtn) {
                        $et->SetNewValue($dyFile[0], null, Replace: 2, ProtectSaved: $opts['ProtectSaved'] ?? null);
                    }
                    continue;
                } else {
                    // set new values from CSV or JSON database
                    $found = $csvEtPrt = $csvEtVal = null;
                    // force UTF-8 if the database was JSON
                    $old = $et->Options('Charset');
                    if ('JSON' === $this->csv) {
                        $et->Options('Charset', 'UTF8');
                    }
                    // read tags for SourceFile '*' plus the specific file
                    foreach (['*', $this->MyConvertFileName($et, $file)] as $f) {
                        $csvInfo = $this->database[$f] ?? null;
                        if (!$csvInfo) {
                            if ('*' === $f) {
                                continue;
                            }
                            // check absolute path and case-insensitive name
                            // (punt on using ConvertFileName here, so $absPath may be a mix of encodings)
                            $absPath = self::AbsPath($f);
                            if (null !== $absPath && !empty($this->database[$absPath])) {
                                $csvInfo = $this->database[$absPath];
                            } elseif (!empty($this->database[strtolower($f)])) {
                                $csvInfo = $this->database[strtolower($f)];
                            } elseif (null !== $absPath && !empty($this->database[strtolower($absPath)])) {
                                $csvInfo = $this->database[strtolower($absPath)];
                            } else {
                                continue;
                            }
                        }
                        $found = 1;
                        if ($this->verbose) {
                            fwrite($this->vout, "Setting new values from {$this->csv} database\n");
                            if ($this->tags) {
                                fwrite($this->vout, 'Including tags: ' . implode(' ', $this->tags) . "\n");
                            }
                            if ($this->csvExclude) {
                                fwrite($this->vout, 'Excluding tags: ' . implode(' ', $this->csvExclude) . "\n");
                            }
                        }
                        if ($this->tags) {
                            // prepare a dummy ExifTool object to hold appropriate tags from the database
                            $csvEtPrt ??= new ExifTool();
                            foreach (ExifTool::OrderedKeys($csvInfo) as $csvTag) {
                                if (preg_match('/^([-_0-9A-Z]+:)*(SourceFile|Directory|FileName)$/i', $csvTag)) {
                                    continue;
                                }
                                $grps = explode(':', $csvTag);
                                $name = array_pop($grps);
                                while (\count($grps) < 2) {
                                    array_unshift($grps, 'All');
                                }
                                if (str_ends_with($name, '#')) {
                                    $name = substr($name, 0, -1);
                                    // handle ValueConv tags separately
                                    $csvEtVal ??= new ExifTool();
                                    $csvEtVal->FoundTag($name, $csvInfo[$csvTag], ...$grps);
                                } else {
                                    $csvEtPrt->FoundTag($name, $csvInfo[$csvTag], ...$grps);
                                }
                            }
                            continue;
                        }
                        $exclTags = [];
                        foreach ($this->csvExclude as $ex) {
                            $ex = preg_replace('/[^-0-9a-zA-Z_:#?*]/', '', $ex);    // remove illegal characters
                            $ex = preg_replace('/(^|:)(all:)+/i', '$1', $ex);       // remove 'all' group names
                            $ex = preg_replace('/(^|:)all(#?)$/i', '$1*$2', $ex);   // convert 'all' tag name to '*'
                            $ex = str_replace(['?', '*'], ['.', '.*'], $ex);        // convert wildcards for regex
                            $exclTags[] = $ex;
                        }
                        // run through tags in database order
                        foreach (ExifTool::OrderedKeys($csvInfo) as $csvTag) {
                            // don't write SourceFile, Directory or FileName
                            if (preg_match('/^([-_0-9A-Z]+:)*(SourceFile|Directory|FileName)$/i', $csvTag)) {
                                continue;
                            }
                            if ($exclTags) {
                                $excluded = null;
                                foreach ($exclTags as $exclTag) { // ExclMatch:
                                    if (str_contains($exclTag, ':')) {
                                        if (!str_contains($csvTag, ':')) {
                                            continue;   // db entry must also specify group
                                        }
                                        $csvGrps = explode(':', $csvTag);
                                        $exclGrps = explode(':', $exclTag);
                                        $exclName = array_pop($exclGrps);
                                        if (!preg_match("/^$exclName$/i", array_pop($csvGrps))) {
                                            continue;   // tag name must match
                                        }
                                        foreach ($exclGrps as $exclGrp) {
                                            // each specified group name must match db entry
                                            if (!preg_grep("/^$exclGrp$/i", $csvGrps)) {
                                                continue 2;
                                            }
                                        }
                                        $excluded = 1;
                                        break;
                                    }
                                    // no group specified, so match by tag name only
                                    if (preg_match("/^([-_0-9A-Z]+:)*$exclTag$/i", $csvTag)) {
                                        $excluded = 1;
                                        break;
                                    }
                                }
                                if ($excluded) {
                                    continue;
                                }
                            }
                            [, $wrn] = $et->SetNewValueList($csvTag, $csvInfo[$csvTag],
                                Protected: 1, AddValue: $this->dbAdd, ProtectSaved: $this->dbSaveCount) + [0, null];
                            if ($wrn && $this->verbose) {
                                $this->Warn("$wrn\n");
                            }
                        }
                    }
                    // set specified tags now
                    if ($csvEtPrt) {
                        $excl = array_map(static fn ($t) => "-$t", $this->csvExclude);  // add back leading dashes
                        $opts = ['AddValue' => $this->dbAdd, 'Replace' => 0];
                        $et->SetNewValuesFromFile($csvEtPrt, $opts, ...$this->tags, ...$excl);
                        if ($csvEtVal) {
                            $opts['Type'] = 'ValueConv';
                            $et->SetNewValuesFromFile($csvEtVal, $opts, ...$this->tags, ...$excl);
                        }
                    }
                    if ('JSON' === $this->csv) {
                        $et->Options('Charset', $old);
                    }
                    if (!$found) {
                        $this->Warn("No SourceFile '{$file}' in imported {$this->csv} database\n");
                        $absPath = self::AbsPath($file);
                        if (null !== $absPath && $absPath !== $file) {
                            $this->Warn("(full path: '{$absPath}')\n");
                        }

                        return 0;
                    }
                }
            }
        }
        if ($isStdout) {
            // write to STDOUT
            $outfile = $this->stdout;
            if (!$this->binaryStdout) {
                $this->binaryStdout = 1;
            }
        } else {
            // get name of hard link if we are creating one
            $hardLink = $et->GetNewValues('HardLink');
            $symLink = $et->GetNewValues('SymLink');
            $testName = $et->GetNewValues('TestName');
            if (null !== $hardLink) {
                $hardLink = $this->FilenameSPrintf($hardLink, $orig);
            }
            if (null !== $symLink) {
                $symLink = $this->FilenameSPrintf($symLink, $orig);
            }
            // determine what our output file name should be
            $newFileName = $et->GetNewValues('FileName');
            $newDir = $et->GetNewValues('Directory');
            if (null !== $newFileName && !\strlen($newFileName)) {
                $this->Warning($et, "New file name is empty - $infile");
                $newFileName = null;
            }
            if (null !== $testName) {
                $err = null;
                if (null !== $newFileName || null !== $newDir) {
                    $err = "You shouldn't write FileName or Directory with TestFile";
                }
                if (null !== $outfile) {
                    $err = "The -o option shouldn't be used with TestFile";
                }
                if ($err) {
                    $this->Warn("Error: $err - $infile\n");
                    $this->EFile($infile);
                    ++$this->countBadWr;

                    return 0;
                }
                $testName = $this->FilenameSPrintf($testName, $orig);
                if ('' !== $file) {
                    $testName = ExifTool::GetNewFileName($file, $testName);
                }
            }
            if (null !== $newFileName || null !== $newDir || ($this->doSetFileName && null !== $outfile)) {
                if ($newFileName) {
                    $newFileName = $this->FilenameSPrintf($newFileName, $orig);
                    if (null !== $outfile) {
                        if ('' !== $file) {
                            $outfile = ExifTool::GetNewFileName($file, $outfile);
                        }
                        $outfile = ExifTool::GetNewFileName($outfile, $newFileName);
                    } elseif ('' !== $file) {
                        $outfile = ExifTool::GetNewFileName($file, $newFileName);
                    }
                }
                if ($newDir) {
                    $newDir = $this->FilenameSPrintf($newDir, $orig);
                    $outfile = ExifTool::GetNewFileName($outfile ?? $file, $newDir);
                }
                $outfile = $this->NextUnusedFilename($outfile, $infile);
                if ($et->Exists($outfile, 1)) {
                    if ($infile === $outfile) {
                        $outfile = null;    // not changing the file name after all
                    // (allow for case-insensitive filesystems)
                    } elseif ($et->IsSameFile($infile, $outfile)) {
                        $sameFile = $outfile;   // same file, but the name has a different case
                    } else {
                        $this->Warn("Error: '{$outfile}' already exists - $infile\n");
                        $this->EFile($infile);
                        ++$this->countBadWr;

                        return 0;
                    }
                }
            }
            if (null !== $outfile) {
                if (null !== $this->verbose) {
                    fwrite($this->vout, "'{$infile}' --> '{$outfile}'\n");
                }
                // create output directory if necessary
                $this->CreateDirectory($outfile);
                // set temporary file (automatically erased on abnormal exit)
                if (null !== $this->outOpt) {
                    $this->tmpFile = $outfile;
                }
            }
            if (null === $this->tmpFile) {
                // count the number of tags and pseudo-tags we are writing
                [$numSet, $numPseudo] = $et->CountNewValuesList();
                if ($numSet != $numPseudo && $et->IsDirectory($file)) {
                    if (null !== $this->verbose) {
                        fwrite($this->vout, "Can't write real tags to a directory - $infile\n");
                    }
                    $numSet = $numPseudo;
                }
                if ($et->Exists($file)) {
                    if (!$numSet) {
                        // no need to write if no tags set
                        if (null !== $this->verbose) {
                            fwrite($this->vout, "Nothing changed in $file\n");
                        }
                        $this->EFile($infile, 1);
                        ++$this->countSameWr;

                        return 1;
                    }
                } elseif (ExifTool::CanCreate($file)) {
                    if ($numSet == $numPseudo) {
                        // no need to write if no real tags
                        $this->Warn("Error: Nothing to write - $file\n");
                        $this->EFile($infile, 1);
                        ++$this->countBadWr;

                        return 0;
                    }
                    if (null === $outfile) {
                        // create file from scratch
                        $outfile = $file;
                        $file = '';
                    }
                } else {
                    // file doesn't exist, and we can't create it
                    $this->Warn("Error: File not found - $file\n");
                    $this->EFile($infile);
                    $this->FileNotFound($file);
                    ++$this->countBadWr;

                    return 0;
                }
                // quickly rename file and/or set file date if this is all we are doing
                if ($numSet == $numPseudo) {
                    [$r0, $r1, $r2, $r3] = [0, 0, 0, 0];
                    if (null !== $outfile) {
                        $r0 = $et->SetFileName($file, $outfile);
                        if ($r0 > 0) {
                            $file = $et->NewName;   // continue with new name if changed
                        }
                    }
                    if (!($r0 < 0)) {
                        $r1 = $et->SetFileModifyDate($file, null, 'FileCreateDate');
                        $r2 = $et->SetFileModifyDate($file);
                        $r3 = $et->SetSystemTags($file);
                    }
                    if ($r0 > 0 || $r1 > 0 || $r2 > 0 || $r3 > 0) {
                        $this->EFile($infile, 3);
                        ++$this->countGoodWr;
                    } elseif ($r0 < 0 || $r1 < 0 || $r2 < 0 || $r3 < 0) {
                        $this->EFile($infile);
                        ++$this->countBadWr;

                        return 0;
                    } else {
                        $this->EFile($infile, 1);
                        ++$this->countSameWr;
                    }
                    if (null !== $hardLink || null !== $symLink || null !== $testName) {
                        $this->DoHardLink($et, $file, $hardLink, $symLink, $testName);
                    }

                    return 1;
                }
                if (null === $outfile || null !== $sameFile) {
                    // write to a truly temporary file
                    $outfile = "{$file}_exiftool_tmp";
                    if ($et->Exists($outfile)) {
                        $this->Warn("Error: Temporary file already exists: $outfile\n");
                        $this->EFile($infile);
                        ++$this->countBadWr;

                        return 0;
                    }
                    $isTemporary = 1;
                }
                // new output file is temporary until we know it has been written properly
                $this->tmpFile = $outfile;
            }
        }
        // rewrite the file
        $success = $et->WriteInfo($this->Infile($file), $outfile, $outType);

        // create hard link if specified
        if ($success && (null !== $hardLink || null !== $symLink || null !== $testName)) {
            $src = $outfile ?? $file;
            $this->DoHardLink($et, $src, $hardLink, $symLink, $testName);
        }

        // get file time if preserving it
        $aTime = $mTime = $cTime = $doPreserve = null;
        if ('' !== $file) {
            $doPreserve = $this->preserveTime;
        }
        if ($doPreserve && $success) {
            [$aTime, $mTime, $cTime] = $et->GetFileTime($file) + [null, null, null];
            // don't override date/time values written by the user
            if (!empty($et->WRITTEN['FileCreateDate'])) {
                $cTime = null;
            }
            if (!empty($et->WRITTEN['FileModifyDate']) || 2 == $doPreserve) {
                if (null !== $cTime) {
                    $aTime = $mTime = null;     // only preserve FileCreateDate
                } else {
                    $doPreserve = null;         // (nothing to preserve)
                }
            }
        }

        if (1 == $success) {
            // preserve the original file times
            if (null !== $this->tmpFile) {
                if ($et->Exists($file)) {
                    if ($doPreserve) {
                        $et->SetFileTime($this->tmpFile, $aTime, $mTime, $cTime);
                    }
                    if ($isTemporary) {
                        // preserve original file attributes if possible
                        $et->CopyFileAttrs($file, $outfile);
                        // move original out of the way
                        $original = "{$file}_original";
                        if (!$this->overwriteOrig && !$et->Exists($original)) {
                            // rename the file and check again to be sure the file doesn't exist
                            // (in case, say, the filesystem truncated the file extension)
                            if (!$et->Rename($file, $original) || $et->Exists($file)) {
                                $this->Error("Error renaming $file\n");

                                return 0;
                            }
                        }
                        $dstFile = $sameFile ?? $file;
                        if ($this->overwriteOrig > 1) {
                            // copy temporary file over top of original to preserve attributes
                            $err = null;
                            $newFile = $this->tmpFile;
                            $newFh = $origFh = null;
                            if (!$et->Open($newFh, $newFile)) {
                                $this->Error("Error opening $newFile\n");

                                return 0;
                            }

                            //..........................................................
                            // temporarily disable CTRL-C during this critical operation
                            $this->critical = 1;
                            $this->tmpFile = null;  // handle deletion of temporary file ourself
                            if ($et->Open($origFh, $file, '+<')) {
                                while ('' !== ($buff = (string) fread($newFh, 65536))) {
                                    if (false === fwrite($origFh, $buff)) {
                                        $err = 1;
                                    }
                                }
                                fclose($newFh);
                                // Handle files being shorter than the original
                                if (!ftruncate($origFh, (int) ftell($origFh))) {
                                    $err = 1;
                                }
                                if (!fclose($origFh)) {
                                    $err = 1;
                                }
                                if ($err) {
                                    $this->Warn("Couldn't overwrite in place - $file\n");
                                    if (!($et->Rename($newFile, $file) || ($et->Unlink($file) && $et->Rename($newFile, $file)))) {
                                        $this->Error("Error renaming $newFile to $file\n");
                                        $this->critical = null;
                                        if ($this->interrupted) {
                                            $this->SigInt();
                                        }

                                        return 0;
                                    }
                                } else {
                                    $et->SetFileModifyDate($file, $cTime, 'FileCreateDate', 1);
                                    $et->SetFileModifyDate($file, $mTime, 'FileModifyDate', 1);
                                    $et->Unlink($newFile);
                                    if ($doPreserve) {
                                        $et->SetFileTime($file, $aTime, $mTime, $cTime);
                                        // save time to set it later again to patch OS X 10.6 bug
                                        $this->preserveTimes[$file] = [$aTime, $mTime, $cTime];
                                    }
                                }
                                $this->EFile($infile, 3);
                                ++$this->countGoodWr;
                            } else {
                                fclose($newFh);
                                $this->Warn("Error opening $file for writing\n");
                                $this->EFile($infile);
                                $et->Unlink($newFile);
                                ++$this->countBadWr;
                            }
                            $this->critical = null;         // end critical section
                            if ($this->interrupted) {
                                $this->SigInt();            // issue delayed SIGINT if necessary
                            }
                            //..........................................................

                        // simply rename temporary file to replace original
                        // (if we didn't already rename it to add "_original")
                        } elseif ($et->Rename($this->tmpFile, $dstFile)) {
                            $this->EFile($infile, 3);
                            ++$this->countGoodWr;
                        } else {
                            $newFile = $this->tmpFile;
                            $this->tmpFile = null;  // (avoid deleting file if we get interrupted)
                            // unlink may fail if already renamed or no permission
                            if (!$et->Unlink($file)) {
                                $this->Warn("Error renaming temporary file to $dstFile\n");
                                $this->EFile($infile);
                                $et->Unlink($newFile);
                                ++$this->countBadWr;
                            // try renaming again now that the target has been deleted
                            } elseif (!$et->Rename($newFile, $dstFile)) {
                                $this->Warn("Error renaming temporary file to $dstFile\n");
                                $this->EFile($infile);
                                // (don't delete tmp file now because it is all we have left)
                                ++$this->countBadWr;
                            } else {
                                $this->EFile($infile, 3);
                                ++$this->countGoodWr;
                            }
                        }
                    } elseif ($this->overwriteOrig) {
                        // erase original file
                        $this->EFile($infile, 3);
                        if (!$et->Unlink($file)) {
                            $this->Warn("Error erasing original $file\n");
                        }
                        ++$this->countGoodWr;
                    } else {
                        $this->EFile($infile, 4);
                        ++$this->countGoodCr;
                    }
                } else {
                    // this file was created from scratch, not edited
                    $this->EFile($infile, 4);
                    ++$this->countGoodCr;
                }
            } else {
                $this->EFile($infile, 3);
                ++$this->countGoodWr;
            }
        } elseif ($success) {
            $this->EFile($infile, 1);
            if ($isTemporary) {
                // just erase the temporary file since no changes were made
                $et->Unlink($this->tmpFile);
                ++$this->countSameWr;
            } else {
                if ($doPreserve) {
                    $et->SetFileTime($outfile, $aTime, $mTime, $cTime);
                }
                if ($this->overwriteOrig) {
                    if (!$et->Unlink($file)) {
                        $this->Warn("Error erasing original $file\n");
                    }
                }
                ++$this->countCopyWr;
            }
            if (null !== $this->verbose) {
                fwrite($this->vout, "Nothing changed in $file\n");
            }
        } else {
            $this->EFile($infile);
            if (null !== $this->tmpFile) {
                $et->Unlink($this->tmpFile);
            }
            ++$this->countBadWr;
        }
        $this->tmpFile = null;

        return $success;
    }

    //------------------------------------------------------------------------------
    // Make hard link and handle TestName if specified
    // Inputs: 0) ExifTool ref, 1) source file name, 2) HardLink name,
    //         3) SymLink name, 4) TestFile name
    private function DoHardLink(ExifTool $et, $src, $hardLink, $symLink, $testName): void
    {
        if (null !== $hardLink) {
            $hardLink = $this->NextUnusedFilename($hardLink);
            if ($et->SetFileName($src, $hardLink, 'Link') > 0) {
                $this->countLink['Hard'] = ($this->countLink['Hard'] ?? 0) + 1;
            } else {
                $this->countLink['BadHard'] = ($this->countLink['BadHard'] ?? 0) + 1;
            }
        }
        if (null !== $symLink) {
            $symLink = $this->NextUnusedFilename($symLink);
            if ($et->SetFileName($src, $symLink, 'SymLink') > 0) {
                $this->countLink['Sym'] = ($this->countLink['Sym'] ?? 0) + 1;
            } else {
                $this->countLink['BadSym'] = ($this->countLink['BadSym'] ?? 0) + 1;
            }
        }
        if (null !== $testName) {
            $testName = $this->NextUnusedFilename($testName, $src);
            if (!empty($this->usedFileName[$testName])) {
                $et->Warn("File '{$testName}' would exist");
            } elseif (1 == $et->SetFileName($src, $testName, 'Test', $this->usedFileName[$testName] ?? null)) {
                $this->usedFileName[$testName] = 1;
                $this->usedFileName[$src] = 0;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Escape string for JSON or PHP
    // Inputs: 0) string, 1) flag to force numbers to be quoted too
    // Returns: Escaped string (quoted if necessary)
    private function EscapeJSON($str, $quote = null): string
    {
        $str = Perl::str($str);
        if (!$quote) {
            // JSON boolean (true or false)
            if (preg_match('/^(true|false)$/i', $str) && $this->json < 2) {
                return strtolower($str);
            }
            // JSON/PHP number (see json.org for numerical format)
            // (these big numbers caused problems for some JSON parsers, so be more conservative)
            if (preg_match('/^-?(\d|[1-9]\d{1,14})(\.\d{1,16})?(e[-+]?\d{1,3})?$/i', $str)) {
                return $str;
            }
        }
        // encode JSON string in base64 if necessary
        // (ponytail: Image::ExifTool::XMP::EncodeBase64($str, 1), from WriteXMP.pl, is base64_encode)
        if ($this->json < 2 && null !== $this->binaryOutput && ExifTool::IsUTF8($str) < 0) {
            return '"base64:' . base64_encode($str) . '"';
        }
        // escape special characters
        $str = strtr($str, self::JSON_CHAR);
        if ($this->json < 2) { // JSON
            $str = str_replace("\0", '', $str);   // remove all nulls
            // escape other control characters with \u
            $str = preg_replace_callback('/[\0-\x1f\x7f]/', static fn ($m) => Perl::sprintf('\\u%.4X', \ord($m[0])), $str);
            // JSON strings must be valid UTF8
            if (!$this->altEnc && self::HasXMP()) {
                XMP::FixUTF8($str);
            }
        } else { // PHP
            if (!$this->isBinary) {
                $str = preg_replace('/\0+$/', '', $str);  // remove trailing nulls unless binary
            }
            // must escape "$" too for PHP
            $str = str_replace('$', '\\$', $str);
            // escape other control characters with \x
            $str = preg_replace_callback('/[\0-\x1f\x7f]/', static fn ($m) => Perl::sprintf('\\x%.2X', \ord($m[0])), $str);
        }

        return '"' . $str . '"';    // return the quoted string
    }

    //------------------------------------------------------------------------------
    // Print JSON or PHP value
    // Inputs: 0) file reference, 1) value, 2) indentation, 3) true to quote numbers
    private function FormatJSON($fp, $val, $ind, $quote = null): void
    {
        $comma = null;
        if (!\is_array($val) && !\is_object($val)) {
            fwrite($fp, $this->EscapeJSON($val, $quote));
        } elseif (\is_array($val) && array_is_list($val)) {
            if ($this->joinLists && !(isset($val[0]) && (\is_array($val[0]) || \is_object($val[0])))) {
                fwrite($fp, $this->EscapeJSON(self::join($this->listSep, $val), $quote));
            } else {
                [$bra, $ket] = 1 == $this->json ? ['[', ']'] : ['Array(', ')'];
                fwrite($fp, $bra);
                foreach ($val as $v) {
                    if ($comma) {
                        fwrite($fp, ',');
                    }
                    $this->FormatJSON($fp, $v, $ind, $quote);
                    $comma = 1;
                }
                fwrite($fp, $ket);
            }
        } elseif (\is_array($val)) {
            [$bra, $ket, $sep] = 1 == $this->json ? ['{', '}', ':'] : ['Array(', ')', ' =>'];
            fwrite($fp, $bra);
            foreach (ExifTool::OrderedKeys($val) as $key) {
                if ($comma) {
                    fwrite($fp, ',');
                }
                $k = $this->EscapeJSON($key, 1);
                fwrite($fp, "\n$ind  $k$sep ");
                // hack to force decimal id's to be printed as strings with -H
                if ($this->showTagID && 'id' === $key && 'H' === $this->showTagID && preg_match('/^\d+\.\d+$/', Perl::str($val[$key]))) {
                    fwrite($fp, '"' . Perl::str($val[$key]) . '"');
                } else {
                    $this->FormatJSON($fp, $val[$key], "$ind  ", $quote);
                }
                $comma = 1;
            }
            fwrite($fp, "\n$ind$ket");
        } else {
            // (note: SCALAR reference should have already been converted)
            fwrite($fp, '"<err>"');
        }
    }

    //------------------------------------------------------------------------------
    // Format value for CSV file
    // Inputs: value
    // Returns: value quoted if necessary
    private function FormatCSV($val): string
    {
        $val = Perl::str($val);
        // check for valid encoding if the Charset option was used
        if ($this->setCharset && (preg_match('/[^\x09\x0a\x0d\x20-\x7e\x80-\xff]/', $val)
            || ('UTF8' === $this->setCharset && ExifTool::IsUTF8($val) < 0))) {
            $val = 'base64:' . base64_encode($val);
        }
        // currently, there is a chance that the value may contain NULL characters unless
        // the -b option is used to encode as Base64.  It is unclear whether or not this
        // is valid CSV, but some readers may not like it.  (If this becomes a problem,
        // in the future values may need to be truncated at the first NULL character.)
        $val = str_replace('"', '""', $val, $n);
        if ($n || preg_match('/(^\s+|\s+$)/', $val) || preg_match('/[\n\r]|' . preg_quote($this->csvDelim, '/') . '/', $val)) {
            $val = "\"$val\"";
        }

        return $val;
    }

    //------------------------------------------------------------------------------
    // Print accumulated CSV information
    // Inputs: 0) file ref
    private function PrintCSV($fp = null): void
    {
        $fp ??= $this->stdout;
        $tags = [];

        if (!$this->csvTagList) {
            $this->csvTagList = array_map('strval', array_keys($this->csvTags));
            sort($this->csvTagList, \SORT_STRING);
        }
        // make a list of tags actually found
        foreach ($this->csvTagList as $lcTag) {
            if ($this->csvTags[$lcTag] ?? null) {
                $tags[] = $this->FormatCSV($this->csvTags[$lcTag]);
            }
        }
        fwrite($fp, implode($this->csvDelim, ['SourceFile', ...$tags]) . "\n");
        $empty = $this->forcePrint ?? '';
        foreach ($this->csvFiles as $file) {
            $vals = [$this->FormatCSV($file)]; // start with full file name
            $csvInfo = $this->database[$file] ?? [];
            foreach ($this->csvTagList as $lcTag) {
                if (!($this->csvTags[$lcTag] ?? null)) {
                    continue;
                }
                $val = $csvInfo[$lcTag] ?? null;
                if (null === $val) {
                    $vals[] = $empty;
                    continue;
                }
                $vals[] = $this->FormatCSV($val);
            }
            fwrite($fp, implode($this->csvDelim, $vals) . "\n");
        }
    }

    //------------------------------------------------------------------------------
    // Convert binary data (SCALAR references) for printing
    // Inputs: 0) object reference
    // Returns: converted object, or undef if we don't want binary objects
    private function ConvertBinary($obj)
    {
        if (\is_array($obj)) {
            foreach ($obj as $key => $val) {
                if (!\is_array($val) && !\is_object($val)) {
                    continue;
                }
                $obj[$key] = $this->ConvertBinary($val);
                if (null === $obj[$key]) {
                    return null;
                }
            }
        } elseif ($obj instanceof ScalarRef) {
            if ($this->noBinary) {
                return null;
            }
            // (binaryOutput flag is set to 0 for binary mode of PHP/JSON output formats)
            if (null !== $this->binaryOutput) {
                $obj = Perl::str($obj->value);
                // encode in base64 if necessary (0xf7 allows for up to 21-bit UTF-8 code space)
                if (1 == $this->json && (preg_match('/[^\x09\x0a\x0d\x20-\x7e\x80-\xf7]/', $obj) || ExifTool::IsUTF8($obj) < 0)) {
                    $obj = 'base64:' . base64_encode($obj);
                }
            } else {
                $bOpt = ', use -b option to extract';
                $data = Perl::str($obj->value);
                if (preg_match('/^Binary data \d+ bytes$/', $data)) {
                    $obj = "($data$bOpt)";
                } else {
                    $obj = '(Binary data ' . \strlen($data) . " bytes$bOpt)";
                }
            }
        }

        return $obj;
    }

    //------------------------------------------------------------------------------
    // Compare ValueConv and PrintConv values of a tag to see if they are equal
    // Inputs: 0) value1, 1) value2, 2) flag to return true for any scalar references
    // Returns: true if they are equal
    private function IsEqual($a, $b, $trueScalar = null): int
    {
        // (scalar values are not print-converted)
        if ($a === $b || (!\is_array($a) && !\is_array($b) && Perl::str($a) === Perl::str($b))) {
            return 1;
        }
        if ($a instanceof ScalarRef) {
            if ($trueScalar) {
                return 1;
            }
            if ($b instanceof ScalarRef && Perl::str($a->value) === Perl::str($b->value)) {
                return 1;
            }

            return 0;
        }
        if (\is_array($a) && !array_is_list($a) && \is_array($b) && !array_is_list($b)) {
            if (\count($a) != \count($b)) {
                return 0;
            }
            foreach ($a as $key => $v) {
                if (!$this->IsEqual($v, $b[$key] ?? null, $trueScalar)) {
                    return 0;
                }
            }
        } else {
            if (!\is_array($a) || !array_is_list($a) || !\is_array($b) || !array_is_list($b) || \count($a) != \count($b)) {
                return 0;
            }
            for ($i = 0; $i < \count($a); ++$i) {
                if (!$this->IsEqual($a[$i], $b[$i], $trueScalar)) {
                    return 0;
                }
            }
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Get character length of a UTF-8 string
    // Inputs: 0) string
    // Returns: number of characters (not bytes) in the UTF-8 string
    private function LengthUTF8($str): int
    {
        $str = Perl::str($str);
        if (!$this->fixLen) {
            return \strlen($str);
        }

        return mb_strlen($str, 'UTF-8');
    }

    //------------------------------------------------------------------------------
    // Add tag list for copying tags from specified file
    // Inputs: 0) set tags file name (or FMT), 1) options for SetNewValuesFromFile()
    // Returns: nothing
    // Notes: Uses global variables: %setTags, %setTagsList, @newValues, $saveCount
    private function AddSetTagsFile($setFile, ?array $opts = null): void
    {
        if (isset($this->setTags[$setFile])) {
            // move these tags aside and make a new list for the next invocation of this file
            $this->setTagsList[$setFile][] = $this->setTags[$setFile];
        }
        $this->setTags[$setFile] = [];  // create list for tags to copy from this file
        // insert marker to save new values now (necessary even if this is not a dynamic
        // file in case the same file is source'd multiple times in a single command)
        $this->newValues[] = ['SaveCount' => ++$this->saveCount];
        $this->newValues[] = "TagsFromFile=$setFile";
        // add option to protect the tags which are assigned after this
        // (this is the mechanism by which the command-line order-of-operations is preserved)
        $opts ??= [];
        $opts['ProtectSaved'] = $this->saveCount;
        $this->setTags[$setFile][] = $opts;
    }

    //------------------------------------------------------------------------------
    // Get input file name or reference for calls to the ExifTool API
    // Inputs: 0) file name ('-' for STDIN), 1) flag to buffer STDIN
    // Returns: file name, or RAF reference for buffering STDIN
    private function Infile($file, $bufferStdin = null)
    {
        if ('-' === $file && ($bufferStdin || $this->rafStdin)) {
            if ($this->rafStdin) {
                $this->rafStdin->Seek(0);   // rewind
            } else {
                $this->rafStdin = new RandomAccess($this->stdin);
                $this->rafStdin->BinMode();
            }

            return $this->rafStdin;
        }

        return $file;
    }

    //------------------------------------------------------------------------------
    // Issue warning to stderr, adding leading "Warning: " and trailing newline
    // if the warning isn't suppressed by the API NoWarning option
    // Inputs: 0) ExifTool ref, 1) warning string
    private function Warning(ExifTool $et, $str): void
    {
        $noWarn = $et->Options('NoWarning');
        if (null === $noWarn || !@preg_match(ExifTool::Rx($noWarn), Perl::str($str))) {
            $this->Warn("Warning: $str\n");
        }
    }

    //------------------------------------------------------------------------------
    // Set new values from file
    // Inputs: 0) ExifTool ref, 1) filename, 2) reference to list of values to set
    // Returns: 0 on error (and increments $countBadWr)
    private function DoSetFromFile(ExifTool $et, $file, array $setTags)
    {
        if ($this->verbose) {
            fwrite($this->vout, "Setting new values from $file\n");
        }
        $info = $et->SetNewValuesFromFile($this->Infile($file, 1), ...$setTags);
        $numSet = \count($info);
        if (!empty($info['Error'])) {
            // delete all error and warning tags
            $warns = preg_grep('/^(Error|Warning)\b/', array_map('strval', array_keys($info)));
            $numSet -= \count($warns);
            // issue a warning only for the main error
            $err = $info['Error'];
            foreach ($warns as $w) {
                unset($info[$w]);
            }
            $noWarn = $et->Options('NoWarning');
            if (!(null !== $noWarn && @preg_match(ExifTool::Rx($noWarn), Perl::str($err)))) {
                $info['Warning'] = $err;
            }
        } elseif (!empty($info['Warning'])) {
            $warns = 1;
            while (!empty($info["Warning ($warns)"])) {
                ++$warns;
            }
            $numSet -= $warns;
        }
        if ($this->PrintErrors($et, $info, $file)) {
            $this->EFile($file);
            ++$this->countBadWr;

            return 0;
        }
        if (!$numSet) {
            $this->Warning($et, "No writable tags set from $file");
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Translate backslashes to forward slashes in filename if necessary
    // Inputs: 0) Filename
    // Returns: nothing, but changes filename if necessary
    private static function CleanFilename(&$file): void
    {
        if (ExifTool::IsPC()) {
            $file = str_replace('\\', '/', $file);
        }
    }

    //------------------------------------------------------------------------------
    // Does path name contain wildcards
    // Inputs: 0) path name
    // Returns: true if path contains wildcards
    private static function HasWildcards($path): bool
    {
        // if this is a Windows path with the long path prefix, then wildcards are not supported
        if (\PHP_OS_FAMILY === 'Windows' && preg_match('{^[\\\\/]{2}\?[\\\\/]}', $path)) {
            return false;
        }

        return (bool) preg_match('/[*?]/', $path);
    }

    //------------------------------------------------------------------------------
    // Process files in our @files list
    // Inputs: 0) ExifTool ref, 1) list ref to just return full file names
    private function ProcessFiles(ExifTool $et, ?array &$list = null): void
    {
        foreach ($this->files as $file) {
            if (null !== $this->progressMax) {
                if (null === $this->progressNext) {
                    $this->progressNext = $this->progressCount + $this->progressIncr;
                    $this->progressNext -= $this->progressNext % $this->progressIncr; // (show even multiples)
                    if ($this->progressNext > $this->progressMax) {
                        $this->progressNext = $this->progressMax;
                    }
                }
                ++$this->progressCount;
                if ($this->progress) {
                    if ($this->progressCount >= $this->progressNext) {
                        $this->progStr = " [{$this->progressCount}/{$this->progressMax}]";
                    } else {
                        $this->progStr = null; // don't update progress yet
                    }
                }
            }
            if ($et->IsDirectory($file) && !$this->listDir) {
                $this->multiFile = $this->validFile = 1;
                $this->ScanDir($et, $file, $list);
            } elseif ($this->filterFlag && !$this->AcceptFile($file)) {
                if ($et->Exists($file)) {
                    $this->filtered = 1;
                    if ($this->verbose) {
                        $this->Progress($this->vout, "-------- $file (wrong extension)");
                    }
                } else {
                    $this->Error("Error: File not found - $file\n");
                    $this->FileNotFound($file);
                }
            } else {
                $this->validFile = 1;
                if (null !== $list) {
                    $list[] = $file;
                } else {
                    $this->GetImageInfo($et, $file);
                    if ($this->purge) {
                        ExifTool::Purge($this->purge);
                    }
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Scan directory for image files
    // Inputs: 0) ExifTool ref, 1) directory name, 2) list ref to return file names
    private function ScanDir(ExifTool $et, $dir, ?array &$list = null): void
    {
        $enc = $et->Options('CharsetFileName');
        // recode as UTF-8 if necessary
        if ($enc && 'UTF8' !== $enc) {
            $dir = $et->Decode($dir, $enc, null, 'UTF8');
            $et->Options('CharsetFileName', 'UTF8');    // now using UTF8
        }
        if (isset($this->ignore[$dir])) {
            return;
        }
        // use standard routines to read directory
        $dh = @opendir($dir);
        if (!$dh) {
            $this->Warn("Error opening directory $dir\n");

            return;
        }
        $fileList = [];
        while (false !== ($file = readdir($dh))) {
            $fileList[] = $file;
        }
        closedir($dh);
        if (!str_ends_with($dir, '/')) {
            $dir .= '/';   // make sure directory name ends with '/'
        }
        foreach ($fileList as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }
            $path = "$dir$file";
            if ($et->IsDirectory($path)) {
                if (!$this->recurse) {
                    continue;
                }
                // ignore directories starting with "." by default
                if (str_starts_with($file, '.') && 1 == $this->recurse) {
                    continue;
                }
                if (isset($this->ignore[$file]) || (isset($this->ignore['SYMLINKS']) && is_link($path))) {
                    continue;
                }
                $this->ScanDir($et, $path, $list);
                continue;
            }
            if ($this->ignoreHidden && str_starts_with($file, '.')) {
                continue;   // ignore hidden files if specified
            }
            // apply rules from -ext options
            $accepted = null;
            if ($this->filterFlag) {
                $accepted = $this->AcceptFile($file);
                if (!$accepted) {
                    continue;
                }
                // must be specifically accepted to bypass selection logic
                $accepted &= 0x01;
            }
            if (!$accepted) {
                // read/write this file if it is a supported type
                if ($this->scanWritable) {
                    if ('1' === Perl::str($this->scanWritable)) {
                        if (!ExifTool::CanWrite($file)) {
                            continue;
                        }
                    } else {
                        $type = ExifTool::GetFileType($file);
                        if (!(null !== $type && $type === $this->scanWritable)) {
                            continue;
                        }
                    }
                } elseif (!ExifTool::GetFileType($file)) {
                    continue;   // (-z is not ported)
                }
            }
            if (null !== $list) {
                $list[] = $path;
            } else {
                $this->GetImageInfo($et, $path);
                if ($this->purge) {
                    ExifTool::Purge($this->purge);
                }
            }
        }
        ++$this->countDir;
        $et->Options('CharsetFileName', $enc);  // restore original setting
    }

    //------------------------------------------------------------------------------
    // Handle missing file on the command line
    // Inputs: 0) file name
    private function FileNotFound($file): void
    {
        if (preg_match('/^(DIR|FILE)$/', $file)) {
            $type = ['DIR' => 'directory', 'FILE' => 'file'][$file];
            $this->Warn("You were meant to enter any valid $type name, not \"$file\" literally.\n");
        }
    }

    //------------------------------------------------------------------------------
    // Patch for OS X 10.6 to preserve file modify date
    // (this probably isn't a 100% fix, but it may solve a majority of the cases)
    private function PreserveTime(): void
    {
        foreach ($this->preserveTimes as $file => $times) {
            $this->mt->SetFileTime((string) $file, ...$times);
        }
        $this->preserveTimes = [];
    }

    //------------------------------------------------------------------------------
    // Return absolute path for a file
    // Inputs: 0) file name
    // Returns: absolute path string, or undef if path could not be determined
    // Note: Warnings should be suppressed when calling this routine
    private static function AbsPath($file)
    {
        $path = null;
        if (null !== $file) {
            if ('*' === $file) {
                return null;    // (CSV SourceFile may be '*' -- no absolute path for that)
            }
            // (Cwd::abs_path: like realpath(), but the last path component may not exist)
            $path = realpath($file);
            if (false === $path) {
                $dir = realpath(\dirname($file));
                $path = false === $dir ? null : rtrim($dir, '/') . '/' . basename($file);
            }
            if (null !== $path) {
                self::CleanFilename($path);    // use forward slashes
            }
        }

        return $path;
    }

    //------------------------------------------------------------------------------
    // Convert file name to ExifTool Charset
    // Inputs: 0) ExifTool ref, 1) file name in CharsetFileName
    // Returns: file name in ExifTool Charset
    private function MyConvertFileName(ExifTool $et, $file)
    {
        return $et->ConvertFileName($file);
    }

    //------------------------------------------------------------------------------
    // Filter files based on extension
    // Inputs: 0) file name
    // Returns: 0 = rejected, 1 = specifically accepted, 2 = accepted by default
    // Notes: This routine should only be called if $filterFlag is set
    private function AcceptFile($file)
    {
        $ext = preg_match('/^.*\.(.+)$/s', $file, $m) ? strtoupper($m[1]) : '';
        if (isset($this->filterExt[$ext])) {
            return $this->filterExt[$ext];
        }
        if (isset($this->filterExt['*'])) {
            return $this->filterExt['*'];
        }
        if ($this->filterFlag & 0x02) {
            return 0;   // reject if accepting specific extensions
        }

        return 2;   // accept by default
    }

    //------------------------------------------------------------------------------
    // Slurp file into buffer
    // Inputs: 0) file name, 1) buffer reference
    // Returns: 1 on success
    private function SlurpFile($file, &$buffPt)
    {
        $fh = null;
        if (!$this->mt->Open($fh, $file)) {
            $this->Warn("Error opening file $file\n");

            return 0;
        }
        $buffPt = stream_get_contents($fh);
        fclose($fh);
        if (false === $buffPt) {
            $buffPt = null;
            $this->Warn("Error reading $file\n");

            return 0;
        }

        return 1;
    }

    //------------------------------------------------------------------------------
    // Filter argfile line
    // Inputs: 0) line of argfile
    // Returns: filtered line or undef to ignore
    private function FilterArgfileLine($arg): ?string
    {
        if (str_starts_with($arg, '#')) {             // comment lines begin with '#'
            if (!str_starts_with($arg, '#[CSTR]')) {
                return null;
            }
            $arg = substr($arg, 7);
            $arg = preg_replace('/[\x0d\x0a]+$/', '', $arg);  // remove trailing newline
            // escape double quotes, dollar signs and ampersands if they aren't already
            // escaped by an odd number of backslashes, and escape a single backslash
            // if it occurs at the end of the string
            $arg = preg_replace_callback('/\\\\(.)|(["$@]|\\\\$)/s', static fn ($m) => '\\' . (($m[2] ?? '') !== '' ? $m[2] : $m[1]), $arg);
            // un-escape characters in C string
            $esc = ['a' => "\x07", 'b' => "\x08", 'f' => "\f", 'n' => "\n",
                    'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\'];
            $arg = preg_replace_callback('/\\\\(.)/s', static fn ($m) => $esc[$m[1]] ?? '\\' . $m[1], $arg);
        } else {
            $arg = preg_replace('/^\s+/', '', $arg);           // remove leading white space
            $arg = preg_replace('/[\x0d\x0a]+$/', '', $arg);   // remove trailing newline
            // remove white space before, and single space after '=', '+=', '-=' or '<='
            $arg = preg_replace('/^(-[-_0-9A-Z:]+#?)\s*([-+<]?=) ?/i', '$1$2', $arg);
            if ('' === $arg) {
                return null;
            }
        }

        return $arg;
    }

    //------------------------------------------------------------------------------
    // Read arguments from -stay_open argfile
    // Inputs: 0) argument list ref
    // Notes: blocks until -execute, -stay_open or -@ option is available
    //        (or until there was an error reading from the file)
    private function ReadStayOpen(array &$args): void
    {
        $newArgs = [];
        $processArgs = $optArgs = null;
        $lastOpt = '';
        $unparsed = \strlen($this->stayOpenBuff);
        for (;;) {
            if ($unparsed) {
                // parse data already read from argfile
                $result = $unparsed;
                $unparsed = null;
            } else {
                // read more data from argfile
                // - this read may block (which is good) if reading from a pipe
                $data = fread($this->stayOpenFp, 65536);
                $result = false === $data ? null : \strlen($data);
                $this->stayOpenBuff .= (string) $data;
            }
            if ($result) {
                $pos = 0;
                while (false !== ($nl = strpos($this->stayOpenBuff, "\n", $pos))) {
                    $len = $nl + 1 - $pos;
                    $arg = substr($this->stayOpenBuff, $pos, $len);
                    $pos += $len;
                    $arg = $this->FilterArgfileLine($arg);
                    if (null === $arg) {
                        continue;
                    }
                    $newArgs[] = $arg;
                    if ($optArgs) {
                        // this is an argument for the last option
                        $optArgs = null;
                        if (!('-stay_open' === $lastOpt || '-@' === $lastOpt)) {
                            continue;
                        }
                    } else {
                        $lastOpt = strtolower($arg);
                        $optArgs = self::OPT_ARGS[$arg] ?? null;
                        if (null === $optArgs) {
                            $optArgs = self::OPT_ARGS[$lastOpt] ?? null;
                            // handle options with trailing numbers
                            if (null === $optArgs && preg_match('/^(.*?)\d+(!?)$/', $lastOpt, $m)) {
                                $optArgs = self::OPT_ARGS["$m[1]#$m[2]"] ?? null;
                            }
                        }
                        if (!preg_match('/^-execute\d*$/', $lastOpt)) {
                            continue;
                        }
                    }
                    $processArgs = 1;
                    break;  // process arguments up to this point
                }
                if (!$pos) {
                    continue;   // nothing to do if we didn't read any arguments
                }
                // keep unprocessed data in buffer
                $this->stayOpenBuff = substr($this->stayOpenBuff, $pos);
                if ($processArgs) {
                    // process new arguments after -execute or -stay_open option
                    array_unshift($args, ...$newArgs);
                    break;
                }
            } elseif (0 === $result) {
                // read didn't block (eg. when reading from a file),
                // so wait for a short time (1/100 sec) then try again
                usleep(10000);
            } else {
                $this->Warn("Error reading from ARGFILE\n");
                $this->CloseStayOpen();
                $this->stayOpen = 0;
                break;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Add new entry to -efile output file
    // Inputs: 0) file name, 1) -efile option number (0=error, 1=same, 2=failed, 3=updated, 4=created)
    private function EFile($entry, $num = 0): void
    {
        $efile = $this->efile[$num ?: 0] ?? null;
        if (null !== $efile && \strlen($entry) && '-' !== $entry) {
            $err = null;
            $this->CreateDirectory($efile);
            $fh = null;
            if ($this->mt->Open($fh, $efile, '>>')) {
                if (false === fwrite($fh, "$entry\n")) {
                    $this->Warn("Error writing to $efile\n");
                    $err = 1;
                }
                fclose($fh);
            } else {
                $this->Warn("Error opening '{$efile}' for append\n");
                $err = 1;
            }
            if ($err) {
                foreach ($this->efile as $k => $e) {
                    if (null !== $e && $e === $efile) {
                        $this->efile[$k] = null;
                    }
                }
            }
        }
    }

    //------------------------------------------------------------------------------
    // Print progress message if it is time for it
    // Inputs: 0) file ref, 1) message
    private function Progress($file, $msg): void
    {
        if (null !== $this->progStr) {
            fwrite($file, $msg . $this->progStr . "\n");
            if (null !== $this->progressMax) {
                $this->progressNext = null;
            }
        }
    }

    //------------------------------------------------------------------------------
    // Add print format entry
    // Inputs: 0) expression string
    private function AddPrintFormat($expr): void
    {
        if (str_starts_with($expr, '#')) {
            if (!preg_match('/^#\[(HEAD|SECT|IF|BODY|ENDS|TAIL)\]/', $expr, $m)) {
                return;     // ignore comments
            }
            $expr = substr($expr, \strlen($m[0]));
            $type = $m[1];
        } else {
            $type = 'BODY';
        }
        $this->printFmt[$type][] = $expr;
        // add to list of requested tags
        preg_match_all('/\$\{?((?:[-_0-9A-Z]+:)*[-_0-9A-Z?*]+)/i', $expr, $mm);
        array_push($this->requestTags, ...$mm[1]);
        // (SetTags is only available from advanced formatting expressions, which are not supported)
    }

    //------------------------------------------------------------------------------
    // Get suggested file extension based on tag value for binary output
    // Inputs: 0) ExifTool ref, 1) data ref, 2) tag name
    // Returns: file extension (lower case), or 'dat' if unknown
    private function SuggestedExtension(ExifTool $et, $valPt, $tag): string
    {
        $valPt = Perl::str($valPt);
        if (!$this->binaryOutput) {
            $ext = 'txt';
        } elseif (preg_match('/^\xff\xd8\xff/', $valPt)) {
            $ext = 'jpg';
        } elseif (preg_match('/^(\0\0\0\x0cjP(  |\x1a\x1a)\x0d\x0a\x87\x0a|\xff\x4f\xff\x51\0)/', $valPt)) {
            $ext = 'jp2';
        } elseif (preg_match('/^(\x89P|\x8aM|\x8bJ)NG\r\n\x1a\n/', $valPt)) {
            $ext = 'png';
        } elseif (preg_match('/^GIF8[79]a/', $valPt)) {
            $ext = 'gif';
        } elseif (preg_match('/^<\?xpacket/', $valPt) || 'XMP' === $tag) {
            $ext = 'xmp';
        } elseif (preg_match('/^<\?xml/', $valPt) || 'XML' === $tag) {
            $ext = 'xml';
        } elseif (preg_match('/^RIFF....WAVE/s', $valPt)) {
            $ext = 'wav';
        } elseif ('OriginalRawImage' === $tag && null !== ($ext = $et->GetValue('OriginalRawFileName'))) {
            $ext = preg_replace('/^.*\./s', '', Perl::str($ext), 1);
            $ext = $ext ? strtolower($ext) : 'raw';
        } elseif ('EXIF' === $tag) {
            $ext = 'exif';
        } elseif ('ICC_Profile' === $tag) {
            $ext = 'icc';
        } elseif (preg_match('/^(MM\0\x2a|II\x2a\0)/', $valPt)) {
            $ext = 'tiff';
        } elseif (preg_match('/^.{4}ftyp(3gp|mp4|f4v|qt  )/s', $valPt, $m)) {
            $ext = ['qt  ' => 'mov'][$m[1]] ?? $m[1];
        } elseif (preg_match('/^<(!DOCTYPE )?html/i', $valPt)) {
            $ext = 'html';
        } elseif (preg_match('/^[\n\r]*\{[\n\r]*\\\\rtf/', $valPt)) {
            $ext = 'rtf';
        } elseif (!preg_match('/^.{0,4096}\0/s', $valPt)) {
            $ext = 'txt';
        } elseif (preg_match('/^BM.{15}\0/s', $valPt)) {
            $ext = 'bmp';
        } elseif (preg_match('/^CANON OPTIONAL DATA\0/', $valPt)) {
            $ext = 'vrd';
        } elseif (preg_match('/^IIII\x04\0\x04\0/', $valPt)) {
            $ext = 'dr4';
        } elseif (preg_match('/^(.{10}|.{522})(\x11\x01|\x00\x11)/s', $valPt)) {
            $ext = 'pict';
        } elseif (preg_match('/^\xff\x0a|\0\0\0\x0cJXL \x0d\x0a......ftypjxl/s', $valPt)) {
            $ext = 'jxl';
        } elseif (preg_match('/^.{4}jumb\0.{3}jumdc2pa/s', $valPt)) {
            $ext = 'c2pa';
        } elseif ('JUMBF' === $tag) {
            $ext = 'jumbf';
        } else {
            $ext = 'dat';
        }

        return $ext;
    }

    //------------------------------------------------------------------------------
    // Load print format file
    // Inputs: 0) file name, 1) flag to avoid adding newline to input argument
    // - saves lines of file to %printFmt list
    // - adds tag names to @tags list
    private function LoadPrintFormat($arg, $noNL = null): void
    {
        if (null === $arg) {
            $this->Error("Must specify file or expression for -p option\n");
        } elseif (!str_contains($arg, "\n") && is_file($arg) && false !== ($fp = @fopen($arg, 'r'))) {
            while (false !== ($line = fgets($fp))) {
                $this->AddPrintFormat($line);
            }
            fclose($fp);
        } else {
            if (!$noNL) {
                $arg .= "\n";
            }
            $this->AddPrintFormat($arg);
        }
    }

    //------------------------------------------------------------------------------
    // A sort of sprintf for filenames
    // Inputs: 0) format string (%d=dir, %f=file name, %e=ext),
    //         1) source filename or undef to test format string
    //         2-4) [%t %g %s %o only] tag name, ref to array of group names,
    //              suggested extension, original raw file name
    // Returns: new filename or undef on error (or if no file and fmt contains token)
    private function FilenameSPrintf($fmt, $file = null, ...$extra)
    {
        $fmt = Perl::str($fmt);
        // return format string straight away if no tokens
        if (!preg_match('/%[-+]?\d*[.:]?\d*[lu]?[dDfFeEtgso]/', $fmt)) {
            return $fmt;
        }
        if (null === $file) {
            return null;
        }
        self::CleanFilename($file);     // make sure we are using forward slashes
        // split filename into directory, file, extension
        if (!preg_match('/^(.*?)([^\/]*?)(\.[^.\/]*)?$/', $file, $m)) {
            $this->Warn("Error: Bad pattern match for file $file\n");

            return null;
        }
        $part = ['d' => $m[1], 'f' => $m[2], 'E' => $m[3] ?? ''];
        if ('' !== $part['E']) {
            $part['e'] = substr($part['E'], 1);
        } else {
            $part['e'] = $part['E'] = '';
        }
        $part['F'] = $part['f'] . $part['E'];
        $part['D'] = preg_replace('{/+$}', '', $part['d']);
        [$part['t'], $part['g'], $part['s'], $part['o']] = $extra + [null, null, null, null];
        if ($part['o']) {
            $part['o'] = preg_replace('{^.*[/\\\\]}s', '', $part['o']);    // remove directory if it exists
        }
        [$filename, $pos] = ['', 0];
        // (Perl: while ($fmt =~ /.../g), the %g code below moving pos($fmt))
        while (preg_match('/(%([-+]?)(\d*)([.:]?)(\d*)([lu]?)([dDfFeEtgso]))/', $fmt, $mm, \PREG_OFFSET_CAPTURE, $pos)) {
            $end = $mm[0][1] + \strlen($mm[0][0]);
            $filename .= substr($fmt, $pos, $end - $pos - \strlen($mm[1][0]));
            $pos = $end;
            [$sign, $wid, $dot, $skip, $mod, $code] = [$mm[2][0], $mm[3][0], $mm[4][0], (int) $mm[5][0], $mm[6][0], $mm[7][0]];
            $path = [];
            if ('d' === strtolower($code) && ':' === $dot) {
                // field width applies to directory levels instead of characters
                $path = Perl::split('{/}', $part[$code]);
                $len = \count($path);
            } else {
                if ('g' === $code) {
                    $groups = $part['g'] ?: [];
                    if (!\is_array($groups)) {
                        // (a second %g: Perl dies here)
                        throw new \RuntimeException("Can't use string (\"$groups\") as an ARRAY ref while \"strict refs\" in use");
                    }
                    preg_match('/\G(\d?)/', $fmt, $gm, 0, $pos);    // look for %g1, %g2, etc
                    $part['g'] = $groups[$gm[1] ?: 0] ?? null;
                    $pos += \strlen($gm[0]);
                }
                $part[$code] ??= '';
                $len = \strlen($part[$code]);
            }
            if (!($skip < $len)) {
                continue;
            }
            if ('' === $wid || $wid + $skip > $len) {
                $wid = $len - $skip;
            }
            if ('-' === $sign) {
                $skip = $len - $wid - $skip;
            }
            if ($path) {
                $p = implode('/', \array_slice($path, $skip, (int) $wid));
                if ('D' !== $code) {
                    $p .= '/';
                }
            } else {
                $p = substr($part[$code], $skip, (int) $wid);
            }
            if ($mod) {
                $p = 'u' === $mod ? strtoupper($p) : strtolower($p);
            }
            $filename .= $p;
        }
        $filename .= substr($fmt, $pos);    // add rest of file name
        // remove double slashes (except at beginning to allow Windows UNC paths)
        return preg_replace('{(?!^)//}', '/', $filename);
    }

    //------------------------------------------------------------------------------
    // Convert number to alphabetical index: a, b, c, ... z, aa, ab ...
    // Inputs: 0) number
    // Returns: alphabetical index string
    private static function Num2Alpha($num): string
    {
        $alpha = \chr(97 + ($num % 26));
        while ($num >= 26) {
            $num = (int) ($num / 26) - 1;
            $alpha = \chr(97 + ($num % 26)) . $alpha;
        }

        return $alpha;
    }

    //------------------------------------------------------------------------------
    // Expand '%c' and '%C' codes if filename to get next unused file name
    // Inputs: 0) file name format string, 1) filename ok to use even if it exists
    // Returns: new file name
    private function NextUnusedFilename($fmt, $okfile = null)
    {
        if (!preg_match('/%[-+]?\d*[.:]?\d*[lun]?[cC]/', (string) $fmt)) {
            return $fmt;
        }
        $sep = ['-' => '-', '+' => '_'];
        [$copy, $alpha] = [0, 'a'];
        $lastFile = null;
        for (;;) {
            [$filename, $pos] = ['', 0];
            while (preg_match('/(%([-+]?)(\d*)([.:]?)(\d*)([lun]?)([cC]))/', $fmt, $mm, \PREG_OFFSET_CAPTURE, $pos)) {
                $end = $mm[0][1] + \strlen($mm[0][0]);
                $filename .= substr($fmt, $pos, $end - $pos - \strlen($mm[1][0]));
                $pos = $end;
                [$sign, $wid, $dec, $wid2, $mod, $tok] = [$mm[2][0], (int) $mm[3][0], $mm[4][0], (int) $mm[5][0], $mm[6][0], $mm[7][0]];
                $seq = null;
                if ('C' === $tok) {
                    // increment sequence number for %C on collision if ':' is used
                    if ($copy && ':' === $dec) {
                        '-' === $sign ? ++$this->seqFileDir : ++$this->seqFileNum;
                    }
                    $seq = $wid + ('-' === $sign ? $this->seqFileDir : $this->seqFileNum) - 1;
                    $wid = $wid2;
                } else {
                    if (!($dec || $copy)) {
                        continue;
                    }
                    if ($wid < $wid2) {
                        $wid = $wid2;
                    }
                    // add dash or underline separator if '-' or '+' specified
                    if ($sign) {
                        $filename .= $sep[$sign];
                    }
                }
                if ($mod && 'n' !== $mod) {
                    $a = 'C' === $tok ? self::Num2Alpha($seq) : $alpha;
                    $str = ($wid && $wid > \strlen($a)) ? str_repeat('a', $wid - \strlen($a)) : '';
                    $str .= $a;
                    if ('u' === $mod) {
                        $str = strtoupper($str);
                    }
                    $filename .= $str;
                } else {
                    $c = 'C' === $tok ? $seq : $copy;
                    $num = $c + ($mod ? 1 : 0);
                    $filename .= $wid ? Perl::sprintf("%.{$wid}d", $num) : $num;
                }
            }
            $filename .= substr($fmt, $pos);    // add rest of file name
            // return now with filename unless file exists
            if (!(($this->mt->Exists($filename, 1) && !isset($this->usedFileName[$filename])) || !empty($this->usedFileName[$filename]))) {
                return $filename;
            }
            if (null !== $okfile) {
                if ($filename === $okfile) {
                    return $filename;
                }
                [$fn, $ok] = [self::AbsPath($filename), self::AbsPath($okfile)];
                if (null !== $fn && null !== $ok && $fn === $ok) {
                    return $okfile;
                }
            }
            if (null !== $lastFile && $lastFile === $filename) {
                return $filename;
            }
            $lastFile = $filename;
            ++$copy;
            $alpha = str_increment($alpha);
        }
    }

    //------------------------------------------------------------------------------
    // Create directory for specified file
    // Inputs: 0) complete file name including path
    // Returns: true if a directory was created
    private function CreateDirectory($file)
    {
        $err = $this->mt->CreateDirectory($file);
        if (null !== $err) {
            if ($err) {
                $this->Error("$err\n");

                return 0;
            }
            if ($this->verbose) {
                $dir = preg_replace('{/[^/]*$}', '', $file, 1);
                fwrite($this->vout, "Created directory $dir\n");
            }
            ++$this->countNewDir;

            return 1;
        }

        return 0;
    }

    //------------------------------------------------------------------------------
    // Open output text file
    // Inputs: 0) file name format string, 1-N) extra arguments for FilenameSPrintf
    // Returns: 0) file reference (or undef on error), 1) file name if opened, 2) append flag
    // Notes: returns reference to STDOUT and no file name if no textOut file needed
    private function OpenOutputFile($file, ...$args): array
    {
        $fp = $outfile = $append = null;
        if ($this->textOut) {
            $outfile = $file;
            self::CleanFilename($outfile);
            if (preg_match('/%[-+]?\d*[.:]?\d*[lun]?[dDfFeEtgsocC]/', $this->textOut) || null !== $this->tagOut) {
                // make filename from printf-like $textOut
                $outfile = $this->FilenameSPrintf($this->textOut, $file, ...$args);
                if (null === $outfile) {
                    return [];
                }
                $outfile = $this->NextUnusedFilename($outfile);
                $this->CreateDirectory($outfile);  // create directory if necessary
            } else {
                $outfile = preg_replace('/\.[^.\/]*$/', '', $outfile, 1);  // remove extension if it exists
                $outfile .= $this->textOut;
            }
            $mode = '>';
            if ($this->mt->Exists($outfile, 1)) {
                if (!$this->textOverwrite) {
                    $this->Warn("Output file $outfile already exists for $file\n");

                    return [];
                }
                if (2 == $this->textOverwrite || (3 == $this->textOverwrite && !empty($this->created[$outfile]))) {
                    $mode = '>>';
                    $append = 1;
                }
            }
            if (!$this->mt->Open($fp, $outfile, $mode)) {
                $what = '>' === $mode ? 'creating' : 'appending to';
                $this->Error("Error $what $outfile\n");

                return [];
            }
        } else {
            $fp = $this->stdout;
        }

        return [$fp, $outfile, $append];
    }

    //------------------------------------------------------------------------------
    // (PHP) Translate the Perl code of an -if condition (after InsertTagValues) to a PHP closure
    // Inputs: 0) Perl code
    // Returns: closure taking the \ArrayObject of tag values (Perl %info) and returning the result
    // Notes: Supported: $info{'TAG'} (from $TAG or ${GROUP:TAG}), numbers, quoted strings,
    //        eq ne lt gt le ge == != < > <= >=, =~ and !~ with a m// pattern, ! not && and || or,
    //        defined and parentheses.  Throws \DomainException for any other Perl code, and
    //        \RuntimeException for a Perl syntax error.  The closure throws \RuntimeException
    //        where Perl issues a warning (which fails the condition)
    private function CompileCondition($code): \Closure
    {
        if (isset($this->compiledCond[$code])) {
            return $this->compiledCond[$code];
        }
        $toks = self::TokenizeCondition($code);
        $pos = 0;
        [$fn] = self::ParseCondition($toks, $pos, 0);
        if ($pos < \count($toks)) {
            throw new \DomainException('unexpected ' . (\is_string($toks[$pos][1]) ? "'{$toks[$pos][1]}'" : $toks[$pos][0]));
        }

        return $this->compiledCond[$code] = $fn;
    }

    // split condition code into tokens: ['var', NAME], ['val', VALUE], ['str', PARTS],
    // ['re', PARTS, FLAGS] or ['op', OPERATOR] (PARTS: strings and ['var', NAME] to interpolate)
    private static function TokenizeCondition($code): array
    {
        $toks = [];
        $len = \strlen($code);
        for ($p = 0; ;) {
            if (preg_match('/\G\s+/', $code, $m, 0, $p)) {
                $p += \strlen($m[0]);
            }
            if ($p >= $len) {
                break;
            }
            $prev = $toks ? $toks[\count($toks) - 1] : null;
            if ($prev && 'op' === $prev[0] && ('=~' === $prev[1] || '!~' === $prev[1])) {
                if (!preg_match('/\G(?:m\s*([^\w\s])|\/)/', $code, $m, 0, $p)) {
                    throw new \DomainException("only m// patterns may follow $prev[1]");
                }
                $open = $m[1] ?? '/';
                $p += \strlen($m[0]);
                [$parts, $p] = self::ScanQuoted($code, $p, $open, 're');
                preg_match('/\G[a-zA-Z]*/', $code, $m, 0, $p);
                $p += \strlen($m[0]);
                if (preg_match('/[^imsxgo]/', $m[0])) {
                    throw new \DomainException("pattern modifiers '$m[0]'");
                }
                $toks[] = ['re', $parts, str_replace(['g', 'o'], '', $m[0])];
            } elseif (preg_match('/\G\$info\{\'([^\']*)\'\}/', $code, $m, 0, $p)) {
                $toks[] = ['var', $m[1]];
                $p += \strlen($m[0]);
            } elseif (preg_match('/\G' . (!$prev || ('op' === $prev[0] && ')' !== $prev[1]) ? '-?' : '') .
                '(?:(?:0|[1-9]\d*)(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?(?![\w.])/', $code, $m, 0, $p)) {
                $toks[] = ['val', 0 + $m[0]];   // (a leading "-" is the negation of the number)
                $p += \strlen($m[0]);
            } elseif ("'" === $code[$p] || '"' === $code[$p]) {
                $q = $code[$p];
                [$parts, $p] = self::ScanQuoted($code, $p + 1, $q, $q);
                $toks[] = "'" === $q ? ['val', $parts[0]] : ['str', $parts];
            } elseif (preg_match('/\G(?:=~|!~|==|!=|<=|>=|&&|\|\||[<>!()]|(?:eq|ne|lt|gt|le|ge|and|or|not|defined)\b)/', $code, $m, 0, $p)) {
                $toks[] = ['op', $m[0]];
                $p += \strlen($m[0]);
            } else {
                throw new \DomainException("'" . substr($code, $p, 12) . "'");
            }
        }

        return $toks;
    }

    // scan a quoted string or a pattern up to its closing delimiter
    // Inputs: 0) code, 1) position after the opening delimiter, 2) opening delimiter,
    //         3) 're' for a pattern (escapes are kept), or the opening quote of a string
    // Returns: [list of parts, position after the closing delimiter]
    private static function ScanQuoted($code, $p, $open, $kind = 're'): array
    {
        $close = strtr($open, '([{<', ')]}>');
        $parts = [];
        $str = '';
        $depth = 0;
        $len = \strlen($code);
        for (; ;) {
            if ($p >= $len) {
                throw new \RuntimeException('re' === $kind ? 'Search pattern not terminated' : "Can't find string terminator $open$open anywhere before EOF");
            }
            $c = $code[$p];
            if ('\\' === $c && $p + 1 < $len) {
                $n = $code[$p + 1];
                $p += 2;
                if ('re' === $kind) {
                    $str .= $c . $n;    // (escapes are interpreted by the regex engine)
                } elseif ("'" === $kind) {
                    $str .= ('\\' === $n || "'" === $n) ? $n : $c . $n;
                } elseif (isset(self::DQ_ESC[$n])) {
                    $str .= self::DQ_ESC[$n];
                } else {
                    throw new \DomainException("escape \\$n in string");
                }
                continue;
            }
            if ($c === $close && !$depth) {
                break;
            }
            if ($open !== $close) {
                if ($c === $open) {
                    ++$depth;
                } elseif ($c === $close) {
                    --$depth;
                }
            }
            if ("'" !== $kind && ('$' === $c || '@' === $c)) {
                if (preg_match('/\G\$info\{\'([^\']*)\'\}/', $code, $m, 0, $p)) {
                    if ('' !== $str) {
                        $parts[] = $str;
                    }
                    $parts[] = ['var', $m[1]];
                    $str = '';
                    $p += \strlen($m[0]);
                    continue;
                }
                // ($ before a non-identifier is an end-of-line anchor in a pattern)
                if ('@' === $c ? preg_match('/\G@[\w:{$]/', $code, $m, 0, $p) : ('re' !== $kind || preg_match('/\G\$[\w{:]/', $code, $m, 0, $p))) {
                    throw new \DomainException("interpolation of '" . substr($code, $p, 12) . "'");
                }
            }
            $str .= $c;
            ++$p;
        }
        if ('' !== $str || !$parts) {
            $parts[] = $str;
        }

        return [$parts, $p + 1];
    }

    // escapes supported in double-quoted strings
    private const array DQ_ESC = ['\\' => '\\', '"' => '"', '$' => '$', '@' => '@', 'n' => "\n", 't' => "\t", 'r' => "\r"];

    // binary operators by precedence level (lowest first), and the Perl names of the operators
    private const array COND_LEVELS = [['or'], ['and'], ['not'], ['||'], ['&&'], ['==', '!=', 'eq', 'ne'],
        ['<', '>', '<=', '>=', 'lt', 'gt', 'le', 'ge'], ['defined'], ['=~', '!~'], ['!']];
    private const array COND_OPNAME = ['eq' => 'string eq', 'ne' => 'string ne', 'lt' => 'string lt',
        'gt' => 'string gt', 'le' => 'string le', 'ge' => 'string ge', '==' => 'numeric eq (==)',
        '!=' => 'numeric ne (!=)', '<' => 'numeric lt (<)', '>' => 'numeric gt (>)',
        '<=' => 'numeric le (<=)', '>=' => 'numeric ge (>=)'];

    // parse tokens from $pos at the given precedence level
    // Returns: [closure, name of variable if the expression is a plain variable]
    private static function ParseCondition(array $toks, int &$pos, int $level): array
    {
        $isOp = static function ($ops) use ($toks, &$pos) {
            return isset($toks[$pos]) && 'op' === $toks[$pos][0] && \in_array($toks[$pos][1], $ops, true);
        };
        if ($level >= \count(self::COND_LEVELS)) {
            // term
            $tok = $toks[$pos++] ?? throw new \RuntimeException('syntax error');
            switch ($tok[0]) {
                case 'var':
                    $name = $tok[1];

                    return [static fn (\ArrayObject $info) => $info[$name] ?? null, $name];
                case 'val':
                    $v = $tok[1];

                    return [static fn () => $v, null];
                case 'str':
                    $parts = $tok[1];

                    return [static fn (\ArrayObject $info) => self::Interpolate($parts, $info, 'concatenation (.) or string'), null];
                case 'op':
                    if ('(' === $tok[1]) {
                        [$fn] = self::ParseCondition($toks, $pos, 0);
                        if (!$isOp([')'])) {
                            throw new \RuntimeException('syntax error');
                        }
                        ++$pos;

                        return [$fn, null];
                    }
            }
            throw new \DomainException('re' === $tok[0] ? 'pattern without =~' : "unexpected '$tok[1]'");
        }
        $ops = self::COND_LEVELS[$level];
        if (\in_array($ops[0], ['not', 'defined', '!'], true)) {
            // unary operators
            if (!$isOp($ops)) {
                return self::ParseCondition($toks, $pos, $level + 1);
            }
            $op = $toks[$pos++][1];
            [$fn, $name] = self::ParseCondition($toks, $pos, $level);
            if ('defined' === $op) {
                return [static fn (\ArrayObject $info) => null !== $fn($info), null];
            }

            return [static fn (\ArrayObject $info) => !self::Truth($fn($info)), null];
        }
        [$fn, $name] = self::ParseCondition($toks, $pos, $level + 1);
        while ($isOp($ops)) {
            $op = $toks[$pos++][1];
            if ('=~' === $op || '!~' === $op) {
                $tok = $toks[$pos++] ?? null;
                if ('re' !== ($tok[0] ?? null)) {
                    throw new \DomainException("$op without a pattern");
                }
                [, $parts, $flags] = $tok;
                $not = '!~' === $op;
                $lhs = $fn;
                $lname = $name;
                $fn = static function (\ArrayObject $info) use ($lhs, $lname, $parts, $flags, $not) {
                    $re = self::Interpolate($parts, $info, 'regexp compilation');
                    $re = '/' . preg_replace_callback('/\\\\.|\//s', static fn ($m) => '/' === $m[0] ? '\\/' : $m[0], $re) . "/$flags";
                    $val = self::CondArg($lhs($info), $lname, 'pattern match (m//)');
                    $ok = @preg_match($re, Perl::str($val));
                    if (false === $ok) {
                        throw new \RuntimeException('Invalid regular expression ' . $re);
                    }

                    return $not xor $ok;
                };
            } else {
                [$rhs, $rname] = self::ParseCondition($toks, $pos, $level + 1);
                $lhs = $fn;
                $lname = $name;
                $fn = match ($op) {
                    'and', '&&' => static fn (\ArrayObject $info) => self::Truth($l = $lhs($info)) ? $rhs($info) : $l,
                    'or', '||' => static fn (\ArrayObject $info) => self::Truth($l = $lhs($info)) ? $l : $rhs($info),
                    default => static function (\ArrayObject $info) use ($lhs, $lname, $rhs, $rname, $op) {
                        $what = self::COND_OPNAME[$op];
                        $a = self::CondArg($lhs($info), $lname, $what);
                        $b = self::CondArg($rhs($info), $rname, $what);
                        if (str_starts_with($what, 'string')) {
                            $cmp = strcmp(Perl::str($a), Perl::str($b));
                        } else {
                            $cmp = Perl::num($a) <=> Perl::num($b);
                            if (is_nan((float) Perl::num($a)) || is_nan((float) Perl::num($b))) {
                                return '!=' === $op;
                            }
                        }

                        return match ($op) {
                            'eq', '==' => 0 === $cmp, 'ne', '!=' => 0 !== $cmp,
                            'lt', '<' => $cmp < 0, 'gt', '>' => $cmp > 0,
                            'le', '<=' => $cmp <= 0, 'ge', '>=' => $cmp >= 0,
                        };
                    },
                };
            }
            $name = null;
            if ($level >= 5 && $level <= 6) {
                break;  // (comparison operators are non-associative)
            }
        }

        return [$fn, $name];
    }

    // check an operand like Perl: warn for an undefined value, or a non-numeric string in a numeric operation
    private static function CondArg($v, $name, $what)
    {
        if (null === $v) {
            throw new \RuntimeException('Use of uninitialized value' . (null !== $name ? " \$info{\"$name\"}" : '') . " in $what");
        }
        if (\is_string($v) && str_starts_with($what, 'numeric') && !is_numeric($v) && !preg_match('/^\s*[-+]?(inf(inity)?|nan)\s*$/i', $v)) {
            throw new \RuntimeException("Argument \"$v\" isn't numeric in $what");
        }

        return $v;
    }

    // interpolate variables into a string or pattern
    private static function Interpolate(array $parts, \ArrayObject $info, $what): string
    {
        $str = '';
        foreach ($parts as $part) {
            $str .= \is_array($part) ? Perl::str(self::CondArg($info[$part[1]] ?? null, $part[1], $what)) : $part;
        }

        return $str;
    }

    // Perl truth value (a reference is always true)
    private static function Truth($v): bool
    {
        return \is_array($v) || \is_object($v) || (bool) $v;
    }

    //------------------------------------------------------------------------------
    // Print list of tags
    // Inputs: 0) message, 1-N) list of tag names
    private function PrintTagList($msg, ...$list): void
    {
        if (!$this->quiet) {
            fwrite($this->stdout, "$msg:\n");
        }
        if (($this->outFormat < 0 || $this->verbose) && preg_match('/file extensions$/', $msg) && $list) {
            foreach ($list as $tag) {
                fwrite($this->stdout, Perl::sprintf("  %-11s %s\n", $tag, ExifTool::GetFileType($tag, 1)));
            }

            return;
        }
        $len = 0;
        $pad = $this->quiet ? '' : '  ';
        foreach ($list as $tag) {
            $taglen = \strlen($tag);
            if ($len + $taglen > 77) {
                fwrite($this->stdout, "\n");
                $len = 0;
                $pad = $this->quiet ? '' : '  ';
            }
            fwrite($this->stdout, $pad . $tag);
            $len += $taglen + 1;
            $pad = ' ';
        }
        if (!$list) {
            fwrite($this->stdout, $pad . '[empty list]');
        }
        fwrite($this->stdout, "\n");
    }

    //------------------------------------------------------------------------------
    // Print warnings and errors from info hash
    // Inputs: 0) ExifTool object ref, 1) info hash, 2) file name
    // Returns: true if there was an Error
    private function PrintErrors(ExifTool $et, $info, $file)
    {
        foreach (['Warning', 'Error'] as $tag) {
            if (empty($info[$tag])) {
                continue;
            }
            $keys = [$tag];
            if ($et->Options('Duplicates')) {
                $more = preg_grep("/^$tag /", array_map('strval', array_keys($info)));
                sort($more, \SORT_STRING);
                array_push($keys, ...$more);
            }
            foreach ($keys as $key) {
                $this->Warn("$tag: " . Perl::str($info[$key]) . " - $file\n");
            }
        }

        return $info['Error'] ?? null;
    }

    // my warning and error routines (NEVER say "die"!)
    private function Warn($msg): void
    {
        if ($this->quiet < 2 || str_starts_with($msg, 'Error')) {
            fwrite($this->stderr, $msg);
        }
    }

    private function Error($msg): void
    {
        $this->Warn($msg);
        $this->rtnVal = 1;
    }

    // (Perl's Warn() returns false unless a __WARN__ handler is set, which is never the case
    //  where WarnOnce() is used, so %warnedOnce stays empty and the warning is issued every time)
    private function WarnOnce($msg): void
    {
        $this->Warn($msg);
    }

    // define signal handlers and cleanup routine
    private function SigInt(): void
    {
        if ($this->critical) {
            $this->interrupted = 1;

            return;
        }
        $this->Cleanup();
        exit(1);
    }

    private function Cleanup(): void
    {
        if (null !== $this->tmpFile) {
            $this->mt?->Unlink($this->tmpFile);
        }
        if (null !== $this->tmpText) {
            $this->mt?->Unlink($this->tmpText);
        }
        $this->tmpFile = $this->tmpText = null;
        if ($this->preserveTimes) {
            $this->PreserveTime();
        }
        // (no console window title to reset)
    }

    // exit routine
    private function Exit($status): int
    {
        if ($this->pause) {
            fwrite($this->stderr, "-- press RETURN --\n");
            fgets($this->stdin);
        }

        return (int) $status;
    }

    //------------------------------------------------------------------------------
    // Helpers of the PHP port (no Perl equivalent)

    /** an option of the Perl script that this port doesn't have: error, and abort the command */
    private function NotPorted($opt): bool
    {
        $this->Error("Sorry, the -$opt option is not supported by the PHP port of exiftool\n");

        return true;
    }

    /** Geotag.pm is not ported yet: -geotag, -geosync and -geotime are refused until it is */
    private static function GeotagPorted(): bool
    {
        return class_exists('Image\ExifTool\Geotag');
    }

    /** Perl: open ARGFILE ("-" is STDIN) */
    private function OpenArgFile($argFile)
    {
        if ('-' === $argFile) {
            return $this->stdin;
        }

        return is_file($argFile) ? @fopen($argFile, 'r') : false;
    }

    /** Perl: close STAYOPEN */
    private function CloseStayOpen(): void
    {
        if ($this->stayOpenFp && $this->stayOpenFp !== $this->stdin) {
            fclose($this->stayOpenFp);
        }
        $this->stayOpenFp = null;
    }

    /** Perl join: list items are stringified like Perl does */
    private static function join($sep, array $list): string
    {
        return implode(Perl::str($sep), array_map(Perl::str(...), $list));
    }

    /** Perl: my ($id, $lang) = $et->GetTagID($tag) */
    private static function GetTagIDList(ExifTool $et, $tag): array
    {
        $tagInfo = $et->TAG_INFO[$tag] ?? null;

        return [$et->GetTagID($tag), $tagInfo && null !== $tagInfo->TagID ? $tagInfo->LangCode : null];
    }

    // ponytail: XMP::FixUTF8 is skipped until the XMP module is ported (invalid UTF-8 then goes out as is)
    private static function HasXMP(): bool
    {
        static $has;

        return $has ??= class_exists(XMP::class);
    }
}
