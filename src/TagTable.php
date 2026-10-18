<?php

declare(strict_types=1);

namespace Image\ExifTool;

/**
 * A tag table (%Image::ExifTool::Xxx::Main). Tag IDs and special keys (GROUPS,
 * PROCESS_PROC…) are read with $table[$key]; a missing key reads as null without being
 * created (so exists() keeps its Perl meaning). Initialize a missing member before
 * writing into it (`$table['VARS'] ??= []`).
 *
 * @implements \ArrayAccess<int|string, mixed>
 * @implements \IteratorAggregate<int|string, mixed>
 */
final class TagTable implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** keys that are not tag IDs (%specialTags in ExifTool.pm) */
    public const array SPECIAL = [
        'TABLE_NAME' => 1, 'SHORT_NAME' => 1, 'PROCESS_PROC' => 1, 'WRITE_PROC' => 1, 'CHECK_PROC' => 1,
        'GROUPS' => 1, 'FORMAT' => 1, 'FIRST_ENTRY' => 1, 'TAG_PREFIX' => 1, 'PRINT_CONV' => 1,
        'WRITABLE' => 1, 'TABLE_DESC' => 1, 'NOTES' => 1, 'IS_OFFSET' => 1, 'IS_SUBDIR' => 1,
        'EXTRACT_UNKNOWN' => 1, 'NAMESPACE' => 1, 'PREFERRED' => 1, 'SRC_TABLE' => 1, 'PRIORITY' => 1,
        'AVOID' => 1, 'WRITE_GROUP' => 1, 'LANG_INFO' => 1, 'VARS' => 1, 'DATAMEMBER' => 1,
        'SET_GROUP1' => 1, 'PERMANENT' => 1, 'INIT_TABLE' => 1,
    ];

    /** @var array<int|string, mixed> */
    public array $data = [];

    /** @param array<int|string, mixed> $data tag entries as generated (arrays become TagInfo objects) */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (\is_array($value) && !isset(self::SPECIAL[$key])) {
                // (an empty array is Perl's { }, a tag without attributes, not an empty list of
                // conditional tags)
                $value = [] !== $value && array_is_list($value)
                    ? array_map(static fn ($v) => \is_array($v) ? new TagInfo($v) : $v, $value)
                    : new TagInfo($value);
            }
            $this->data[$key] = $value;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[self::key($offset)]);
    }

    public function &offsetGet(mixed $offset): mixed
    {
        $offset = self::key($offset);
        if (\array_key_exists($offset, $this->data)) {
            return $this->data[$offset];
        }
        $null = null;

        return $null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->data[] = $value;
        } else {
            $this->data[self::key($offset)] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[self::key($offset)]);
    }

    /** a Perl hash key: a fractional tag ID (DATAMEMBER, IS_OFFSET lists) stringifies ('12.1') */
    private static function key(mixed $offset): mixed
    {
        return \is_float($offset) ? Perl::str($offset) : $offset;
    }

    /** Perl exists($$table{$key}) */
    public function exists(int|string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /** @return list<int|string> all keys, special ones included (Perl keys %$table) */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return \count($this->data);
    }
}
