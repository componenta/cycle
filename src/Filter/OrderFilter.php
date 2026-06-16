<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Query\SortableInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * Column ordering filter.
 */
final readonly class OrderFilter implements FilterInterface
{
    /**
     * @param array<non-empty-string, Direction> $orderBy
     */
    public function __construct(
        private array $orderBy,
    ) {}

    public function apply(SelectQuery $query): SelectQuery
    {
        foreach ($this->orderBy as $column => $direction) {
            $query = $query->orderBy($column, $direction->value);
        }

        return $query;
    }

    public static function fromSortable(SortableInterface $sortable): ?self
    {
        if ($sortable->orderBy === null) {
            return null;
        }

        return new self($sortable->orderBy);
    }
}
