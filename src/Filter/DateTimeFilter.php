<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Internal\CalendarExpression;
use Componenta\Cycle\Internal\SqlIdentifier;
use Cycle\Database\Query\SelectQuery;

final readonly class DateTimeFilter implements FilterInterface
{
    public function __construct(
        private string $column,
        private \DateTimeInterface $value,
        private string $dateTimeFormat = 'Y-m-d',
        private string $sqlFunction = 'DATE',
    ) {
        SqlIdentifier::assertColumn($this->column);

        CalendarExpression::assertFunction($this->sqlFunction);
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where(
            CalendarExpression::forQuery($query, $this->column, $this->sqlFunction),
            '=',
            CalendarExpression::value($this->value, $this->dateTimeFormat, $this->sqlFunction)
        );
    }
}
