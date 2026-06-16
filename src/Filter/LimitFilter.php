<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * LIMIT clause.
 */
final readonly class LimitFilter implements FilterInterface
{
    public function __construct(
        private int $limit,
    ) {
        if ($this->limit < 0) {
            throw new \InvalidArgumentException('Limit must be non-negative integer');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->limit($this->limit);
    }
}
