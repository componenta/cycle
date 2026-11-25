<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Parameter;

/**
 * Loads related rows in bounded queries and attaches them to projections.
 *
 * @internal
 */
final readonly class RelationLoader
{
    private const int BATCH_SIZE = 500;

    public function __construct(private DatabaseInterface $db) {}

    public function belongsTo(
        array &$results,
        array $rows,
        string $foreignKey,
        string $table,
        array $columns,
        string $key,
        string $tableKey = 'id',
        ?Closure $transform = null,
    ): void {
        if ($results === []) {
            return;
        }

        if ($rows === []) {
            foreach ($results as &$result) {
                $result[$key] = null;
            }
            return;
        }

        // Collect unique non-null foreign key values
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row[$foreignKey])) {
                $ids[] = $row[$foreignKey];
            }
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            foreach ($results as &$result) {
                $result[$key] = null;
            }
            return;
        }

        // Ensure tableKey is in columns for indexing
        $selectColumns = $columns;
        if ($columns !== ['*'] && !in_array($tableKey, $selectColumns, true)) {
            $selectColumns[] = $tableKey;
        }

        // Index by table key
        $indexed = [];
        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $relatedRows = $this->db
                ->select($selectColumns)
                ->from($table)
                ->where($tableKey, 'IN', new Parameter($chunk))
                ->fetchAll();

            foreach ($relatedRows as $row) {
                $indexed[$row[$tableKey]] = $row;
            }
        }

        foreach ($results as $i => &$result) {
            $fk = $rows[$i][$foreignKey] ?? null;
            $related = $fk !== null ? ($indexed[$fk] ?? null) : null;
            $result[$key] = $related !== null && $transform !== null
                ? $transform($related)
                : $related;
        }
    }

    public function hasMany(
        array &$results,
        array $rows,
        string $resultKey,
        string $table,
        string $foreignKey,
        string $localKey = 'id',
        array $columns = ['*'],
        ?Closure $transform = null,
    ): void {
        if ($results === []) {
            return;
        }

        if ($rows === []) {
            foreach ($results as &$result) {
                $result[$resultKey] = [];
            }
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_column($rows, $localKey),
            static fn (mixed $id): bool => $id !== null,
        )));

        if ($ids === []) {
            foreach ($results as &$result) {
                $result[$resultKey] = [];
            }
            return;
        }

        // Ensure foreign key is in columns for grouping
        $selectColumns = $columns;
        if ($columns !== ['*'] && !in_array($foreignKey, $selectColumns, true)) {
            $selectColumns[] = $foreignKey;
        }

        // Group by foreign key
        $grouped = [];
        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $relatedRows = $this->db
                ->select($selectColumns)
                ->from($table)
                ->where($foreignKey, 'IN', new Parameter($chunk))
                ->fetchAll();

            foreach ($relatedRows as $row) {
                $grouped[$row[$foreignKey]][] = $transform !== null ? $transform($row) : $row;
            }
        }

        foreach ($results as $i => &$result) {
            $localId = $rows[$i][$localKey] ?? null;
            $result[$resultKey] = $localId !== null ? ($grouped[$localId] ?? []) : [];
        }
    }

    public function belongsToMany(
        array &$results,
        array $rows,
        string $resultKey,
        string $relatedTable,
        string $pivotTable,
        string $pivotLocalKey,
        string $pivotForeignKey,
        string $localKey = 'id',
        string $relatedKey = 'id',
        array $columns = ['*'],
        ?Closure $transform = null,
    ): void {
        if ($results === []) {
            return;
        }

        if ($rows === []) {
            foreach ($results as &$result) {
                $result[$resultKey] = [];
            }
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_column($rows, $localKey),
            static fn (mixed $id): bool => $id !== null,
        )));

        if ($ids === []) {
            foreach ($results as &$result) {
                $result[$resultKey] = [];
            }
            return;
        }

        // PDO groups by the first result column and excludes that column
        // from the related row. No reserved alias can collide with r.* or
        // with an explicitly selected field.
        $selectColumns = [
            "p.$pivotLocalKey",
            ...($columns === ['*'] ? ['r.*'] : array_map(static fn(string $c) => "r.$c", $columns)),
        ];

        $grouped = [];
        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $groups = $this->db
                ->select($selectColumns)
                ->from("$relatedTable AS r")
                ->innerJoin($pivotTable, 'p')
                ->on("p.$pivotForeignKey", "r.$relatedKey")
                ->where("p.$pivotLocalKey", 'IN', new Parameter($chunk))
                ->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_ASSOC);

            foreach ($groups as $localId => $relatedRows) {
                if ($transform !== null) {
                    $relatedRows = array_map($transform, $relatedRows);
                }

                if (isset($grouped[$localId])) {
                    foreach ($relatedRows as $row) {
                        $grouped[$localId][] = $row;
                    }
                } else {
                    $grouped[$localId] = $relatedRows;
                }
            }
        }

        foreach ($results as $i => &$result) {
            $localId = $rows[$i][$localKey] ?? null;
            $result[$resultKey] = $localId !== null ? ($grouped[$localId] ?? []) : [];
        }
    }

}
