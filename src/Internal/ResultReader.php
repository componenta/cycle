<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Cycle\Database\Query\SelectQuery;
use Cycle\Database\StatementInterface;
use Generator;

/**
 * Owns the lifetime of a statement opened for a fetch operation.
 *
 * @internal
 */
final class ResultReader
{
    /** @return array{results: list<array|object>, rawRows: list<array>} */
    public static function read(
        iterable $rows,
        ?object $query,
        Closure $fetchRow,
        bool $collectRawRows,
    ): array {
        $results = [];
        $rawRows = [];
        $statement = $rows instanceof SelectQuery ? $rows->run() : null;
        if ($statement !== null) {
            $rows = self::rows($statement);
        }

        try {
            foreach ($rows as $row) {
                if ($collectRawRows) {
                    $rawRows[] = $row;
                }
                $results[] = $fetchRow($row, $query);
            }
        } finally {
            $statement?->close();
        }

        return ['results' => $results, 'rawRows' => $rawRows];
    }

    private static function rows(StatementInterface $statement): Generator
    {
        while (($row = $statement->fetch()) !== false) {
            yield $row;
        }
    }
}
