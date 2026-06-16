<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * Date range filter with from/to boundaries.
 */
final readonly class DateRangeFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column,
        private ?\DateTimeInterface $from = null,
        private ?\DateTimeInterface $to = null,
        private string $format = 'Y-m-d H:i:s',
    ) {
        if ($this->from === null && $this->to === null) {
            throw new \InvalidArgumentException('At least one of from/to must be provided');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        if ($this->from !== null) {
            $query = $query->where($this->column, '>=', $this->from->format($this->format));
        }

        if ($this->to !== null) {
            $query = $query->where($this->column, '<=', $this->to->format($this->format));
        }

        return $query;
    }
}
