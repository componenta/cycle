<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

/**
 * Pagination: limit + offset.
 */
interface PaginableInterface
{
    /**
     * @var int<1, max>
     */
    public int $limit { get; }

    /**
     * @var int<0, max>
     */
    public int $offset { get; }
}
