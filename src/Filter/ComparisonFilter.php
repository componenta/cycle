<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * Universal comparison: =, !=, <, >, <=, >=
 */
final readonly class ComparisonFilter implements FilterInterface
{
    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>='];

    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column,
        private mixed $value,
        private string $operator = '=',
    ) {
        if (!in_array($this->operator, self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Operator must be one of: %s', implode(', ', self::ALLOWED_OPERATORS))
            );
        }

        if (!is_scalar($this->value)) {
            throw new \InvalidArgumentException('Value must be scalar');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where(
            $this->column,
            $this->operator,
            $this->value
        );
    }
}
