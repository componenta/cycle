<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Query\PaginableInterface;
use Cycle\Database\Query\SelectQuery;

final readonly class PaginationFilter implements FilterInterface
{
    public function __construct(
        private int $limit = 10,
        private int $offset = 0,
    ) {
        if ($this->limit < 1) {
            throw new \InvalidArgumentException('Pagination limit must be positive.');
        }
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Pagination offset must be non-negative.');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query
            ->limit($this->limit)
            ->offset($this->offset);
    }

    public static function fromPaginable(PaginableInterface $paginable): self
    {
        return new self($paginable->limit, $paginable->offset);
    }
}
