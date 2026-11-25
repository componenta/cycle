<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Internal\SqlIdentifier;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Injection\ParameterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * IN condition.
 */
final readonly class InFilter implements FilterInterface
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
            'in',
            new Parameter(array_map(
                static fn (mixed $value): ParameterInterface|FragmentInterface =>
                    $value instanceof ParameterInterface || $value instanceof FragmentInterface
                        ? $value
                        : new Parameter($value),
                $this->values,
            ))
        );
    }
}
