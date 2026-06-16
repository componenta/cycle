<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * OFFSET clause.
 */
final readonly class OffsetFilter implements FilterInterface
{
    public function __construct(
        private int $offset,
    ) {
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Offset must be non-negative integer');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->offset($this->offset);
    }
}
