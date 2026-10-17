<?php

namespace Image\ExifTool\App;

use Image\ExifTool\ExifTool;
use Image\ExifTool\Perl;
use Image\ExifTool\ScalarRef;
use Image\ExifTool\Vars\ExifTool as ExifToolVars;
use Image\ExifTool\XMP;

/**
 * Port of the `exiftool` command-line script (exiftool 13.59), reading only.
 *
 * File-scope variables of the script are properties, its subs are methods with the same names,
 * and the main script is run(). Not ported (the command stops with an error): writing
 * (TAG=VALUE, -tagsFromFile, -o, -csv=/-j= import, -geotag...), -X, -h, -htmlDump, -list*,
 * -if and -p (they evaluate Perl expressions), -diff, -plot, -w/-W, -efile, -fileNUM,
 * -fileOrder, -srcfile, -use, -z and -config files.
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
    private array $csvFiles = [];       // list of files when reading with CSV option (in ExifTool Charset)
    private array $csvTagList = [];     // @csvTags: order of tags for first file with CSV option (lower case)
    private array $echo3 = [];          // stdout echo after processing is complete
    private array $echo4 = [];          // stderr echo after processing is complete
    private array $exclude = [];        // list of excluded tags
    private array $files = [];          // list of files and directories to scan
    private array $moreArgs = [];       // more arguments to process after -stay_open -@
    private array $tags = [];           // list of tags to extract
    private array $csvTags = [];        // %csvTags: lookup for all found tags with CSV option (lower case keys)
    private array $database = [];       // CSV information for each file (keyed by file name)
    private array $filterExt = [];      // lookup for filtered extensions
    private array $ignore = [];         // directory names to ignore
    private $allGroup;                  // show group name for all tags
    private $altEnc;                    // alternate character encoding if not UTF-8
    private $argFormat;                 // use exiftool argument-format output
    private $binaryOutput;              // flag for binary output (undef or 1, or 0 for binary JSON/PHP)
    private $binaryStdout;              // flag set if we output binary to stdout
    private $binSep;                    // separator used for list items in binary output
    private $binTerm;                   // terminator used for binary output
    private $comma;                     // flag set if we need a comma in JSON output
    private $count = 0;                 // count of files scanned
    private $countBad = 0;              // count of files with errors
    private $countDir = 0;              // count of directories scanned
    private $csv;                       // flag for CSV option (set to "CSV")
    private $csvDelim = ',';            // delimiter for CSV files
    private $disableOutput;             // flag to disable normal output
    private $escapeC;                   // C-style escape
    private $escapeHTML;                // flag to escape printed values for html
    private $executeID;                 // -execute ID number
    private $fileHeader;                // header to print to output file (or console, once)
    private $fileTrailer = '';          // trailer for output file
    private $filtered;                  // flag indicating file was filtered by name
    private $filterFlag = 0;            // file filter flag (0x01=deny extensions, 0x02=allow extensions, 0x04=add ext)
    private $fixLen;                    // flag to fix description lengths when writing alternate languages
    private $forcePrint;                // string to use for missing tag values (undef to not print them)
    private $helped;                    // flag to avoid printing help if no tags specified
    private $ignoreHidden;              // flag to ignore hidden files
    private $isBinary;                  // true if value is a SCALAR ref
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
    private $pause;                     // pause before returning
    private $progress;                  // flag to calculate total files to process (0=calculate but don't display)
    private $progressCount;             // count of files processed
    private $progressIncr;              // increment for progress counter
    private $progressMax;               // total number of files to process
    private $progressNext;              // next progress count to output
    private $progStr = '';              // progress message string
    private $purge;                     // flag to purge memory
    private $quiet = 0;                 // flag to disable printing of informational messages / warnings
    private $recurse;                   // recurse into subdirectories (2=also hidden directories)
    private $rtnVal;                    // command return value (0=success)
    private $rtnValPrev;                // previous command return value (0=success)
    private $setCharset;                // character set setting ('default' if not set and -csv -b used)
    private $showGroup;                 // number of group to show (may be zero or '')
    private $showTagID;                 // non-zero to show tag ID's
    private $stayOpenBuff = '';         // buffer for -stay_open file
    private $stayOpenFile;              // name of the current -stay_open argfile
    private $stayOpenFp;                // STAYOPEN file handle
    private $structOpt;                 // output structured XMP information (JSON output only)
    private $tabFormat = 0;             // non-zero for tab output format
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

        return (new self())->run($argv);
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
            // (not done: @commonArgs, @moreArgs, $binaryStdout, $helped, $mt, $pause,
            //  $rtnValApp, $rtnValPrev, $stayOpen, $stayOpenBuff, $stayOpenFile)
            $this->csvFiles = $this->csvTagList = $this->echo3 = $this->echo4 = $this->exclude = [];
            $this->files = $this->tags = $this->csvTags = $this->database = $this->filterExt = $this->ignore = [];
            $this->allGroup = $this->altEnc = $this->argFormat = $this->binaryOutput = $this->binSep = null;
            $this->binTerm = $this->comma = $this->csv = $this->disableOutput = $this->escapeC = null;
            $this->escapeHTML = $this->executeID = $this->fileHeader = $this->filtered = $this->fixLen = null;
            $this->forcePrint = $this->ignoreHidden = $this->isBinary = $this->joinLists = $this->langOpt = null;
            $this->listDir = $this->listItem = $this->multiFile = $this->noBinary = $this->progress = null;
            $this->progressCount = $this->progressIncr = $this->progressMax = $this->progressNext = null;
            $this->purge = $this->recurse = $this->setCharset = $this->showGroup = $this->showTagID = null;
            $this->structOpt = $this->validFile = $this->verbose = null;

            $this->count = 0;
            $this->countBad = 0;
            $this->countDir = 0;
            $this->csvDelim = ',';
            $this->fileTrailer = '';
            $this->filterFlag = 0;
            $this->json = 0;
            $this->listSep = ', ';
            $this->outFormat = 0;
            $this->progStr = '';
            $this->quiet = 0;
            $this->rtnVal = 0;
            $this->tabFormat = 0;
            $this->vout = $this->stdout;

            // define local variables used only in this command loop
            $endOfOpts = null;  // flag set if "--" option encountered
            $escapeXML = null;  // flag to escape printed values for xml
            $sortOpt = null;    // sort option is used

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
                        // (MWG module not loaded: -use MWG and "mwg:" tags are not supported)
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
                    if (preg_match('/^list([wfrdx]|wf|g(\d*)|geo)?$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
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
                    if (preg_match('/^(all|add)?tagsfromfile(=.*)?$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
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
                        // (importing a CSV/JSON database is writing)
                        $badCmd = $this->NotPorted($arg);
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
                    if (preg_match('/^diff$/i', $arg) || preg_match('/^delete_original(!?)$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
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
                    if (preg_match('/^efile(\d+)?(!)?$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
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
                    if ('geotag' === $a || 'h' === $arg || 'htmlformat' === $a) {
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
                    if (preg_match('/^if(\d*)$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
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
                    if (preg_match('/^o(ut)?$/i', $arg) || 'overwrite_original' === $a || 'overwrite_original_in_place' === $a
                        || 'plot' === $a || preg_match('/^p(-?)$/', $arg) || preg_match('/^printformat(-?)$/i', $arg)) {
                        $badCmd = $this->NotPorted($arg);
                        continue;
                    }
                    if ('P' === $arg || 'preserve' === $a) {
                        continue;   // (preserves the date/time of written files only)
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
                        $badCmd = $this->NotPorted($arg);
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
                        // (ListSplit is only used when writing)
                        continue;
                    }
                    if (preg_match('/^(-)?sort$/i', $arg, $m)) {
                        $sortOpt = empty($m[1]) ? 1 : 0;
                        continue;
                    }
                    if ('srcfile' === $a) {
                        $badCmd = $this->NotPorted($arg);
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
                        $badCmd = $this->NotPorted($arg);
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
                    if (preg_match('/^(w|textout|tagout)([!+]*)$/i', $arg) || preg_match('/^(-?)(wext|tagoutext)$/i', $arg)
                        || 'wm' === $a || 'writemode' === $a) {
                        $badCmd = $this->NotPorted($arg);
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
                        $this->exclude[] = $tag;
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
                        continue;
                    }
                    if (1 == \strlen($arg) && '*' !== $arg) {
                        $this->Error("Unknown option -$arg\n");
                        $badCmd = true;
                        continue;
                    }
                    if (preg_match('/^[^<]+(<?)=(.*)/s', $arg) || preg_match('/(<|>)/', $arg)) {
                        // (assigning or copying tag values)
                        $this->Error("Sorry, writing is not supported by the PHP port of exiftool (-$arg)\n");
                        $badCmd = true;
                        continue;
                    }
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
                } else {
                    if (!$pass) {
                        // defer to next pass so the filename charset is available
                        $nextPass[] = $arg;
                        continue;
                    }
                    // (no wildcard expansion: the shell does it, except on Windows)
                    $this->files[] = $arg;
                }
            }

            // set "OK" UserParam based on result of last command
            $mt->Options('UserParam', 'OK=' . ($this->rtnValPrev ? '' : '1'));

            // print help
            if (!($this->tags || $this->files)) {
                if (!$this->helped) {
                    // (no perldoc: show the short syntax message)
                    fwrite($this->stdout, "Syntax:  exiftool [OPTIONS] FILE\n\n");
                    fwrite($this->stdout, "Consult the exiftool documentation for a full list of options.\n");
                }
                continue;
            }

            if ($this->csv && 'CSV' === $this->csv) {
                $this->json = 0;  // (not compatible)
                if ($this->binaryOutput) {
                    $this->binaryOutput = 0;
                    if (null === $this->setCharset) {
                        $this->setCharset = 'default';
                    }
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
                $this->Error("No file specified\n");
                continue;
            }

            // set Verbose option
            if ($this->verbose) {
                if (!($this->tags || $this->exclude)) {
                    $this->disableOutput = 1;
                }
                $this->binaryOutput = null;    // disable conflicting option
                $mt->Options('Verbose', $this->verbose);
            } // (-v0 auto-flushes the output in Perl: PHP streams aren't buffered)

            if (\in_array('*', $this->exclude, true) || \in_array('*:*', $this->exclude, true)) {
                $this->Error("All tags excluded -- nothing to do.\n");
                continue;
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
                if (!$this->binaryStdout) {
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

            // process all specified files
            $this->ProcessFiles($mt);

            if ($this->filtered && !$this->validFile) {
                $this->Error("No file with specified extension\n");
            }

            // print file trailer if necessary
            if ($this->fileTrailer && !$this->fileHeader) {
                fwrite($this->stdout, $this->fileTrailer);
            }
            // print CSV output file if necessary
            if ($this->csv) {
                $this->PrintCSV();
            }

            if ((!$this->binaryStdout || $this->verbose) && !$this->quiet) {
                // print summary
                $tot = $this->count + $this->countBad;
                if ($this->countDir || $tot > 1) {
                    $o = ($this->json || $this->csv) ? $this->stderr : $this->vout;
                    if ($this->countDir) {
                        fwrite($o, Perl::sprintf("%5d directories scanned\n", $this->countDir));
                    }
                    if ($tot > 1 || $this->countDir) {
                        fwrite($o, Perl::sprintf("%5d image files read\n", $this->count));
                    }
                    if ($this->countBad) {
                        fwrite($o, Perl::sprintf("%5d files could not be read\n", $this->countBad));
                    }
                }
            }

            // set error status if we had any errors
            if ($this->countBad) {
                $this->rtnVal = 1;
            }

            if ($this->purge) {
                ExifTool::Purge(0);   // do final purging
            }
        } // end "Command" loop ........................................................

        if ($this->stayOpen >= 2) {
            $this->CloseStayOpen();
        }

        return $this->Exit($this->rtnValApp);   // all done
    }

    //------------------------------------------------------------------------------
    // Get image information from EXIF data in file
    // Inputs: 0) ExifTool object reference, 1) file name
    private function GetImageInfo(ExifTool $et, $orig): void
    {
        $info = null;

        if (!\strlen($orig)) {
            $this->Warn("Error: Zero-length file name - \"\"\n");
            ++$this->countBad;

            return;
        }
        // (-srcfile, -fileNUM, -z and -if are not ported)
        $file = $orig;
        $pipe = $file;

        // extract information from this file
        if (!('-' === $file || $et->Exists($file) || $info)) {
            $this->Warn("Error: File not found - $file\n");
            $this->FileNotFound($file);
            ++$this->countBad;

            return;
        }
        // print file/progress message
        $o = null;
        if (!($this->binaryOutput || $this->csv)) {
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
        // request specified tags
        $oldDups = $et->Options('Duplicates');
        $foundTags = $this->tags;
        // extract the information
        // (STDIN is only buffered by Perl's Infile() for -if and -tagsFromFile, not ported)
        $info = $et->ImageInfo($pipe, $foundTags);
        $foundTags = $et->IO_TAG_LIST;  // (Perl updates \@foundTags in place)
        $et->Options('Duplicates', $oldDups);

        // print warnings to stderr if using binary output
        // (because we are likely ignoring them and piping stdout to file)
        // or if there is none of the requested information available
        if ($this->binaryOutput || !$info) {
            $errs = $et->GetInfo('Warning', 'Error');
            if ($this->PrintErrors($et, $errs, $file)) {
                $this->rtnVal = 1;
            }
        } elseif ($et->GetValue('Error') || ($et->Validate && $et->GetValue('Warning'))) {
            $this->rtnVal = 1;
        }

        // (no -w output files: everything goes to stdout)
        $fp = $this->stdout;

        // print the results for this file
        if (!$this->disableOutput) {
            $line = null;
            $noDups = [];
            $csvInfo = [];
            $csvFile = null;
            $bra = $ket = $sep = $quote = $ind = null;
            if ($fp) {
                // print file header (only once)
                if ($this->fileHeader) {
                    fwrite($fp, $this->fileHeader);
                    $this->fileHeader = null;
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
                $group = null;
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
                            $val = self::join($this->binSep ?? "\n", $val);
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

                // write binary output (-W is not ported)
                if ($this->binaryOutput) {
                    fwrite($fp, Perl::str($val));
                    if (null !== $this->binTerm) {
                        fwrite($fp, $this->binTerm);
                    }
                    continue;
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
        ++$this->count;
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
                // read this file if it is a supported type
                if (!ExifTool::GetFileType($file)) {
                    continue;
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
    // Print list of tags
    // Inputs: 0) message, 1-N) list of tag names
    private function PrintTagList($msg, ...$list): void
    {
        if (!$this->quiet) {
            fwrite($this->stdout, "$msg:\n");
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
