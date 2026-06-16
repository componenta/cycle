<?php

declare(strict_types=1);

namespace Componenta\Cycle\Scope;

use Cycle\ORM\Select\QueryBuilder;
use Cycle\ORM\Select\ScopeInterface;

/**
 * Scope that filters only active records.
 */
final readonly class ActiveScope implements ScopeInterface
{
    public function __construct(
        private string $column = 'is_active',
        private bool|int $activeValue = true,
    ) {}

    public function apply(QueryBuilder $query): void
    {
        $query->where($this->column, '=', $this->activeValue);
    }
}
