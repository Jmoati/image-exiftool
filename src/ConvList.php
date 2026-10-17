<?php

declare(strict_types=1);

namespace Image\ExifTool;

/**
 * A conversion given as a Perl ARRAY ref (one conversion per item of a list value), to tell it
 * apart from a lookup hash, which is a PHP array too. Perl: ref $conv eq 'ARRAY'.
 */
final class ConvList
{
    /** @param list<mixed> $list conversions: \Closure, lookup array, 'REPEAT' or null */
    public function __construct(public array $list)
    {
    }
}
