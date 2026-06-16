<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * Soft delete filter.
 *
 * true  = only active (deletedAt IS NULL)
 * false = only trashed (deletedAt IS NOT NULL)
 */
final readonly class SoftDeleteFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column = 'deletedAt',
        private bool $onlyActive = true,
    ) {}

    public function apply(SelectQuery $query): SelectQuery
    {
        return $this->onlyActive
            ? $query->where($this->column, '=', null)
            : $query->where($this->column, '!=', null);
    }
}
