<?php

declare(strict_types=1);

namespace Componenta\Cycle;

use Cycle\Database\Query\SelectQuery;

/**
 * Interface for applying filter conditions to SELECT queries.
 */
interface FilterInterface
{
    /**
     * Apply condition to the query.
     */
    public function apply(SelectQuery $query): SelectQuery;
}
