<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * Boolean column filter.
 */
final readonly class BooleanFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column,
        private bool $value,
    ) {}

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where(
            $this->column,
            '=',
            $this->value
        );
    }
}
