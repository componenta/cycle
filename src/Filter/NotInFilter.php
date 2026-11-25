<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Internal\InValues;
use Componenta\Cycle\Internal\SqlIdentifier;
use Cycle\Database\Query\SelectQuery;

/**
 * NOT IN condition.
 */
final readonly class NotInFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     * @param array $values
     */
    public function __construct(
        private string $column,
        private array $values,
    ) {
        SqlIdentifier::assertColumn($this->column);

        if ($this->values === []) {
            throw new \InvalidArgumentException('Values cannot be empty array');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where(
            $this->column,
            'not in',
            InValues::parameter($this->values)
        );
    }
}
