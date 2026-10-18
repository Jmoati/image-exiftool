<?php

declare(strict_types=1);

namespace Image\ExifTool;

/**
 * A new value hash ($nvHash of Writer.pl: one per tag info and write group, chained by Next).
 * Keys are dynamic properties, like the Perl hash: reading a missing one gives null without a
 * warning and does not create it.
 */
#[\AllowDynamicProperties]
final class NewValue
{
    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function &__get(string $name): mixed
    {
        $null = null;

        return $null;
    }

    public function __isset(string $name): bool
    {
        return false;
    }
}
