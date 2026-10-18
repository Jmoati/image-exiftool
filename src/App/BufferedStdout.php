<?php

namespace Image\ExifTool\App;

/**
 * STDOUT as Perl has it when it is not a terminal: block buffered (8192 bytes), while STDERR is not,
 * so that error messages come out before the earlier output still held in the buffer (exiftool
 * flushes only for -stay_open and -v0).
 */
final class BufferedStdout
{
    public static bool $autoflush = false;
    public $context;
    private string $buf = '';

    public static function open()
    {
        if (!\in_array('exifstdout', stream_get_wrappers(), true)) {
            stream_wrapper_register('exifstdout', self::class);
        }
        return fopen('exifstdout://out', 'w');
    }

    public function stream_open($path, $mode, $options, &$opened): bool
    {
        return true;
    }

    public function stream_write($data): int
    {
        $this->buf .= $data;
        if (self::$autoflush || \strlen($this->buf) >= 8192) {
            $this->stream_flush();
        }
        return \strlen($data);
    }

    public function stream_flush(): bool
    {
        if ($this->buf !== '') {
            $b = $this->buf;
            $this->buf = '';
            while ($b !== '' && ($n = fwrite(\STDOUT, $b)) > 0) {
                $b = substr($b, $n);
            }
        }
        return true;
    }

    public function stream_close(): void
    {
        $this->stream_flush();
    }
}
