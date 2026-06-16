<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Injection\Expression;
use Cycle\Database\Query\SelectQuery;

final readonly class DateTimeFilter implements FilterInterface
{
    private const array ALLOWED_FUNCTIONS = ['DATE', 'YEAR', 'MONTH', 'DAY'];

    public function __construct(
        private string $column,
        private \DateTimeInterface $value,
        private string $dateTimeFormat = 'Y-m-d',
        private string $sqlFunction = 'DATE',
    ) {
        if (!in_array(strtoupper($this->sqlFunction), self::ALLOWED_FUNCTIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf('SQL function must be one of: %s', implode(', ', self::ALLOWED_FUNCTIONS))
            );
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        $func = strtoupper($this->sqlFunction);

        return $query->where(
            new Expression("{$func}({$this->column})"),
            '=',
            $this->value->format($this->dateTimeFormat)
        );
    }
}
