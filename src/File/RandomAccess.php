<?php

declare(strict_types=1);

namespace Image\ExifTool\File;

use Image\ExifTool\Perl;

/**
 * Port of File::RandomAccess: random access reads on a file handle or an in-memory string,
 * buffering the data when the handle can't seek (pipes).
 *
 * Members keep their Perl names ($raf->FILE_PT, $raf->TESTED…); other modules store their own
 * members on it ($$raf{PSEnd}), hence the dynamic properties.
 */
#[\AllowDynamicProperties]
final class RandomAccess
{
    private const int CHUNK_SIZE = 8192;
    private const int SLURP_CHUNKS = 16;

    /** @var resource|null */
    public $FILE_PT;
    public string $BUFF_PT = '';
    public int $BASE = 0;
    public int $POS = 0;
    public int $LEN = 0;
    /** 0=untested, 1=passed, -1=failed (requires buffering) */
    public int $TESTED = 0;
    public ?bool $NoBuffer = null;
    public ?string $ERROR = null;
    /** @var array<int, int>|null */
    public ?array $DEBUG = null;

    /**
     * @param resource|string $filePt file handle, or the data itself (Perl: \$buff)
     */
    public function __construct(mixed $filePt, bool $isRandom = false)
    {
        if (\is_string($filePt)) {
            $this->FILE_PT = null;
            $this->BUFF_PT = $filePt;
            $this->LEN = \strlen($filePt);
            $this->TESTED = -1;
        } else {
            $this->FILE_PT = $filePt;
            if (!$isRandom) {
                $this->SeekTest();
            }
        }
    }

    public function &__get(string $name): mixed
    {
        $this->{$name} = null;

        return $this->{$name};
    }

    public function __isset(string $name): bool
    {
        return false;
    }

    public function Debug(): void
    {
        $this->DEBUG = [];
    }

    /** Perform seek test and turn on buffering if necessary */
    public function SeekTest(): int
    {
        if (!$this->TESTED) {
            $meta = stream_get_meta_data($this->FILE_PT);
            $this->TESTED = $meta['seekable'] ? 1 : -1;
        }

        return 1 === $this->TESTED ? 1 : 0;
    }

    public function Tell(): int
    {
        if ($this->TESTED < 0) {
            return $this->POS + $this->BASE;
        }
        $pos = ftell($this->FILE_PT);

        return false === $pos ? -1 : $pos;
    }

    /** @return int 1 on success, 0 on failure */
    public function Seek(int|float $num, int $whence = 0): int
    {
        $num = (int) $num;
        if ($this->TESTED < 0) {
            if (0 === $whence) {
                $newPos = $num - $this->BASE;
            } elseif (1 === $whence) {
                $newPos = $num + $this->POS;
            } elseif ($this->NoBuffer && $this->FILE_PT) {
                $newPos = -1;
            } else {
                $this->Slurp();
                $newPos = $num + $this->LEN;
            }
            if ($newPos >= 0 && (!$this->NoBuffer || $newPos >= $this->POS)) {
                $this->POS = $newPos;

                return 1;
            }

            return 0;
        }

        return 0 === fseek($this->FILE_PT, $num, $whence) ? 1 : 0;
    }

    /**
     * Read from the file: fills $buff with the data and returns the number of bytes read.
     */
    public function Read(mixed &$buff, int|float $len): int
    {
        $len = (int) $len;
        if ($len <= 0) {
            $buff = '';

            return 0;
        }
        if ($this->TESTED < 0) {
            if ($this->NoBuffer && !$this->Purge()) {
                return 0;
            }
            $newPos = $this->POS + $len;
            $num = $newPos - $this->LEN;
            if ($num > 0 && $this->FILE_PT) {
                $num = (($num - 1) | (self::CHUNK_SIZE - 1)) + 1;
                $chunk = $this->readFile($num);
                if (false === $chunk) {
                    $this->ERROR = 'read error';
                } elseif ('' !== $chunk) {
                    $this->BUFF_PT .= $chunk;
                    $this->LEN += \strlen($chunk);
                }
            }
            $num = $this->LEN - $this->POS;
            if ($len <= $num) {
                $rtnVal = $len;
            } elseif ($num <= 0) {
                $buff = '';

                return 0;
            } else {
                $rtnVal = $num;
            }
            $buff = substr($this->BUFF_PT, $this->POS, $rtnVal);
            $this->POS += $rtnVal;
        } else {
            $data = $this->readFile($len);
            if (false === $data) {
                $this->ERROR = 'read error';
                $data = '';
            }
            $buff = $data;
            $rtnVal = \strlen($data);
        }
        if (null !== $this->DEBUG) {
            $pos = $this->Tell() - $rtnVal;
            if (!isset($this->DEBUG[$pos]) || $this->DEBUG[$pos] <= $rtnVal) {
                $this->DEBUG[$pos] = $rtnVal;
            }
        }

        return $rtnVal;
    }

    /** fread() until $len bytes or end of file (fread may return less on streams) */
    private function readFile(int $len): string|false
    {
        $data = '';
        while ($len > 0 && !feof($this->FILE_PT)) {
            $chunk = fread($this->FILE_PT, min($len, 0x4000000));
            if (false === $chunk) {
                return '' === $data ? false : $data;
            }
            if ('' === $chunk) {
                break;
            }
            $data .= $chunk;
            $len -= \strlen($chunk);
        }

        return $data;
    }

    /**
     * Read a line (up to and including Perl::$RS) into $buff; returns its length.
     */
    public function ReadLine(mixed &$buff): int
    {
        $rs = Perl::$RS;
        if ($this->TESTED < 0) {
            if ($this->NoBuffer && !$this->Purge()) {
                return 0;
            }
            $pos = $this->POS;
            if ($this->FILE_PT) {
                while ($this->LEN <= $pos) {
                    $chunk = $this->readFile(self::CHUNK_SIZE);
                    if (false === $chunk || '' === $chunk) {
                        return 0;
                    }
                    $this->BUFF_PT .= $chunk;
                    $this->LEN += \strlen($chunk);
                }
                for (;;) {
                    $found = null === $rs ? false : strpos($this->BUFF_PT, $rs, $pos);
                    if (false !== $found) {
                        $pos = $found + \strlen((string) $rs);
                        break;
                    }
                    $pos = $this->LEN;
                    $chunk = $this->readFile(self::CHUNK_SIZE);
                    if (false === $chunk || '' === $chunk) {
                        break;
                    }
                    $this->BUFF_PT .= $chunk;
                    $this->LEN += \strlen($chunk);
                }
            } else {
                $found = null === $rs || $pos > $this->LEN ? false : strpos($this->BUFF_PT, $rs, $pos);
                if (false === $found) {
                    $pos = $this->LEN;
                    if ($this->POS > $pos) {
                        $this->POS = $pos;
                    }
                } else {
                    $pos = $found + \strlen((string) $rs);
                }
            }
            $len = $pos - $this->POS;
            $buff = substr($this->BUFF_PT, $this->POS, $len);
            $this->POS = $pos;

            return $len;
        }
        if (null === $rs) {
            $line = stream_get_contents($this->FILE_PT);
        } elseif ("\n" === $rs) {
            $line = fgets($this->FILE_PT);
        } else {
            $line = stream_get_line($this->FILE_PT, PHP_INT_MAX, $rs);
            if (false !== $line && !feof($this->FILE_PT)) {
                $line .= $rs;
            } elseif (false !== $line) {
                // stream_get_line drops the delimiter: re-add it when the file has it
                $here = ftell($this->FILE_PT);
                if (false !== $here && $here >= \strlen($rs)) {
                    fseek($this->FILE_PT, $here - \strlen($rs));
                    if (fread($this->FILE_PT, \strlen($rs)) === $rs) {
                        $line .= $rs;
                    }
                }
            }
        }
        if (false === $line || '' === $line) {
            $buff = '';

            return 0;
        }
        $buff = $line;

        return \strlen($line);
    }

    /** Read whole file into buffer (non-seekable streams only) */
    public function Slurp(): void
    {
        if (!$this->FILE_PT) {
            return;
        }
        while (true) {
            $chunk = $this->readFile(self::CHUNK_SIZE * self::SLURP_CHUNKS);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $this->BUFF_PT .= $chunk;
            $this->LEN += \strlen($chunk);
        }
    }

    /** Purge the buffer of data before the current position (NoBuffer mode) */
    public function Purge(): int
    {
        if ($this->FILE_PT) {
            $skip = $this->POS - $this->LEN;
            while ($skip > 0) {
                $chunk = $this->readFile(min($skip, 65536));
                if (false === $chunk || '' === $chunk) {
                    return 0;
                }
                $skip -= \strlen($chunk);
                $this->BASE += \strlen($chunk);
                $this->POS -= \strlen($chunk);
            }
        }
        if ($this->POS > 0 && $this->POS <= $this->LEN) {
            $this->BUFF_PT = substr($this->BUFF_PT, $this->POS);
            $this->BASE += $this->POS;
            $this->LEN -= $this->POS;
            $this->POS = 0;
        }

        return 1;
    }

    public function BinMode(): int
    {
        return 1;
    }

    public function Close(): void
    {
        if (\is_resource($this->FILE_PT)) {
            fclose($this->FILE_PT);
        }
        $this->FILE_PT = null;
        $this->BUFF_PT = '';
        $this->LEN = $this->POS = $this->BASE = 0;
    }
}
