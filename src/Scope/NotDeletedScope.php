<?php

declare(strict_types=1);

namespace Componenta\Cycle\Scope;

use Cycle\ORM\Select\QueryBuilder;
use Cycle\ORM\Select\ScopeInterface;

/**
 * Scope for soft-deleted records.
 */
final readonly class NotDeletedScope implements ScopeInterface
{
    public function __construct(
        private string $column = 'deleted_at',
    ) {}

    public function apply(QueryBuilder $query): void
    {
        $query->where($this->column, '=', null);
    }
}
