<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;

/** @internal Prevents DBAL's SQL Server ROW_NUMBER projection fallback. */
final class PaginationOrder
{
    public static function forPage(SelectQuery $query): SelectQuery
    {
        $tokens = $query->getTokens();
        if ($query->getDriver()?->getType() !== 'SQLServer'
            || ($tokens['limit'] === null && $tokens['offset'] === null)
            || $tokens['orderBy'] !== []
        ) {
            return $query;
        }

        return (clone $query)->orderBy(self::fallback($tokens), null);
    }

    /** @param array<array-key, mixed> $tokens */
    public static function fallback(array $tokens): Fragment
    {
        // DISTINCT and set queries require ordering by an output expression.
        if ($tokens['distinct'] || $tokens['union'] !== [] || $tokens['intersect'] !== [] || $tokens['except'] !== []) {
            return new Fragment('1');
        }

        // Without a user-supplied order there is no ordering guarantee.
        // A constant avoids sorting/evaluating arbitrary projected values.
        return new Fragment('(SELECT NULL)');
    }
}
