<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;

/**
 * Smart date filter supporting ranges and specific dates.
 *
 * Accepts array from DateFilterCaster:
 * - ['from' => 'Y-m-d']              - from date (>=)
 * - ['to' => 'Y-m-d']                - to date (<=)
 * - ['from' => 'Y-m-d', 'to' => ...] - range
 * - ['dates' => ['Y-m-d', ...]]       - specific dates (IN)
 */
final readonly class DateFilter implements FilterInterface
{
    /**
     * @param array{from?: string, to?: string, dates?: list<string>} $date
     * @param non-empty-string $column
     */
    public function __construct(
        private array $date,
        private string $column = 'created_at',
    ) {}

    public function apply(SelectQuery $query): SelectQuery
    {
        if (isset($this->date['dates'])) {
            return $query->where(
                "DATE($this->column)",
                'in',
                new Parameter($this->date['dates']),
            );
        }

        if (isset($this->date['from'])) {
            $query = $query->where($this->column, '>=', $this->date['from'] . ' 00:00:00');
        }

        if (isset($this->date['to'])) {
            $query = $query->where($this->column, '<=', $this->date['to'] . ' 23:59:59');
        }

        return $query;
    }
}
