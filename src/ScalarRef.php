<?php

declare(strict_types=1);

namespace Image\ExifTool;

/**
 * A Perl scalar reference stored as a value (ExifTool keeps binary data as \$data).
 */
final class ScalarRef
{
    public function __construct(public mixed $value)
    {
    }
}
