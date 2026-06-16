<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * BETWEEN condition.
 */
final readonly class BetweenFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column,
        private mixed $from,
        private mixed $to,
    ) {}

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where(
            $this->column,
            'between',
            $this->from,
            $this->to
        );
    }
}
