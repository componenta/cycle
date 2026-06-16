<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Injection\Parameter;
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
        if ($this->values === []) {
            throw new \InvalidArgumentException('Values cannot be empty array');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        return $query->where(
            $this->column,
            'not in',
            new Parameter($this->values)
        );
    }
}
