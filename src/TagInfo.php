<?php

declare(strict_types=1);

namespace Image\ExifTool;

/**
 * A tag information hash ($tagInfo). Keys are dynamic properties, like the Perl hash:
 * reading a missing one gives null without a warning and does not create it, so
 * property_exists() keeps the meaning of Perl's exists(). Initialize a missing
 * member before writing into it (`$tagInfo->Groups ??= []`).
 */
#[\AllowDynamicProperties]
final class TagInfo
{
    /** @param array<string, mixed> $info */
    public function __construct(array $info = [])
    {
        foreach ($info as $key => $value) {
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
