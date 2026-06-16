<?php

declare(strict_types=1);

namespace Componenta\Cycle;

/**
 * Result container with items and total count.
 *
 * @template T
 */
final readonly class Result
{
    /**
     * @param list<T> $items
     * @param null|int<0, max> $count Total count (before pagination)
     */
    public function __construct(
        public array $items,
        public ?int $count,
    ) {}

    /**
     * Check if result is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Get number of items in current page.
     */
    public function size(): int
    {
        return count($this->items);
    }
}
