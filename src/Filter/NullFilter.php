<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

final readonly class NullFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column,
    ) {}

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where($this->column, '=', null);
    }
}
