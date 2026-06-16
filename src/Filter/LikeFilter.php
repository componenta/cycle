<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Cycle\Database\Query\SelectQuery;

/**
 * LIKE condition with pattern wrapping.
 */
final readonly class LikeFilter implements FilterInterface
{
    /**
     * @param non-empty-string $column
     */
    public function __construct(
        private string $column,
        private string $value,
        private LikeMode $mode = LikeMode::Contains,
    ) {
        if ($this->value === '') {
            throw new \InvalidArgumentException('Value cannot be empty string');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        $pattern = match ($this->mode) {
            LikeMode::Contains => "%{$this->value}%",
            LikeMode::StartsWith => "{$this->value}%",
            LikeMode::EndsWith => "%{$this->value}",
            LikeMode::Exact => $this->value,
        };

        return $query->where(
            $this->column,
            'like',
            $pattern
        );
    }
}
