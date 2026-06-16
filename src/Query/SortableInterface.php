<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

use Componenta\Cycle\Filter\Direction;

/**
 * Sorting capability.
 *
 * @example ['created_at' => Direction::DESC, 'title' => Direction::ASC]
 */
interface SortableInterface
{
    /**
     * @var null|array<non-empty-string, Direction>
     */
    public ?array $orderBy { get; }
}
