<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use InvalidArgumentException;
use PDO;

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
        bool $binaryKey = false,
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

        [$ids, , $hasRepeatedKeys] = $this->collectIds($rows, $foreignKey, $binaryKey);

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
                $id = $row[$tableKey];
                $indexed[is_resource($id) ? self::streamKey($id) : $id] = $row;
            }
        }

        $streamRows = [];
        if ($hasRepeatedKeys && $transform !== null) {
            // Inspect each distinct related row once, not once per parent.
            foreach ($indexed as $id => $row) {
                foreach ($row as $value) {
                    if (is_resource($value) && get_resource_type($value) === 'stream') {
                        $streamRows[$id] = true;
                        break;
                    }
                }
            }
        }

        foreach ($results as $i => &$result) {
            $fk = $rows[$i][$foreignKey] ?? null;
            if (is_resource($fk)) {
                $fk = self::streamKey($fk);
            }
            $related = $fk !== null ? ($indexed[$fk] ?? null) : null;
            if ($related !== null && $transform !== null) {
                // Shared rows need independent streams for each callback.
                if (isset($streamRows[$fk])) {
                    $related = self::copyStreams($related);
                }
                $related = $transform($related);
            }
            $result[$key] = $related;
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
        bool $binaryKey = false,
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

        [$ids] = $this->collectIds($rows, $localKey, $binaryKey);

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
                $id = $row[$foreignKey];
                $grouped[is_resource($id) ? self::streamKey($id) : $id][] = $transform !== null ? $transform($row) : $row;
            }
        }

        foreach ($results as $i => &$result) {
            $localId = $rows[$i][$localKey] ?? null;
            if (is_resource($localId)) {
                $localId = self::streamKey($localId);
            }
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
        bool $binaryKey = false,
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

        [$ids, $binary] = $this->collectIds($rows, $localKey, $binaryKey);

        if ($ids === []) {
            foreach ($results as &$result) {
                $result[$resultKey] = [];
            }
            return;
        }

        // PDO groups by the first result column and excludes that column
        // from the related row. No reserved alias can collide with r.* or
        // with an explicitly selected field.
        $groupKey = "p.$pivotLocalKey";
        $hexGroupKey = $binary && $this->db->getType() === 'Postgres';
        if ($hexGroupKey) {
            // PDO cannot group by a bytea stream's contents. Return the
            // grouping column as hex text without touching related fields.
            $quoted = $this->db->getDriver(DatabaseInterface::READ)->getQueryCompiler()->quoteIdentifier($groupKey);
            $groupKey = new Fragment("encode($quoted, 'hex')");
        }

        $selectColumns = [
            $groupKey,
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
            if (is_resource($localId)) {
                $localId = self::streamKey($localId);
            }
            if ($hexGroupKey && $localId !== null) {
                $localId = bin2hex($localId);
            }
            $result[$resultKey] = $localId !== null ? ($grouped[$localId] ?? []) : [];
        }
    }

    /**
     * @return array{0: list<mixed>, 1: bool, 2: bool}
     */
    private function collectIds(array $rows, string $column, bool $binary): array
    {
        $ids = [];
        foreach (array_column($rows, $column) as $id) {
            if ($id === null) {
                continue;
            }
            if (is_resource($id)) {
                $id = self::streamKey($id);
                $binary = true;
            } elseif (gettype($id) === 'resource (closed)') {
                throw new InvalidArgumentException('A relation key stream was closed during row transformation.');
            }
            $ids[] = $id;
        }
        $count = count($ids);
        $ids = array_values(array_unique($ids));
        $hasRepeatedKeys = count($ids) !== $count;

        // DBAL flattens IN lists; raw values default to PDO::PARAM_STR.
        // Preserve each non-string value's type as well as explicit binary keys.
        if ($binary) {
            $ids = array_map(static function (mixed $id): Parameter {
                if (!is_string($id)) {
                    throw new InvalidArgumentException('A binary relation key must be a string or a seekable stream.');
                }
                return new Parameter($id, PDO::PARAM_LOB);
            }, $ids);
        } else {
            $ids = array_map(
                static fn (mixed $id): string|Parameter => is_string($id) ? $id : new Parameter($id),
                $ids,
            );
        }

        return [$ids, $binary, $hasRepeatedKeys];
    }

    /** @param array<string, mixed> $row */
    private static function copyStreams(array $row): array
    {
        foreach ($row as $column => $value) {
            if (!is_resource($value) || get_resource_type($value) !== 'stream') {
                continue;
            }
            $position = ftell($value);
            if ($position === false || !stream_get_meta_data($value)['seekable']) {
                throw new InvalidArgumentException('A shared relation stream must be seekable.');
            }

            // Large values spill to disk instead of duplicating PHP strings.
            $copy = fopen('php://temp', 'w+b');
            if ($copy === false) {
                throw new \RuntimeException('Unable to create a temporary relation stream.');
            }

            try {
                try {
                    if (fseek($value, 0) !== 0 || stream_copy_to_stream($value, $copy) === false
                        || fseek($copy, $position) !== 0
                    ) {
                        throw new \RuntimeException('Unable to copy a shared relation stream.');
                    }
                } finally {
                    if (fseek($value, $position) !== 0) {
                        throw new \RuntimeException('Unable to restore a shared relation stream position.');
                    }
                }
            } catch (\Throwable $error) {
                fclose($copy);
                throw $error;
            }

            $row[$column] = $copy;
        }

        return $row;
    }

    /**
     * Read the complete stream, even after the row mapper consumed it.
     * Restore the cursor so the related-row transform sees the original value.
     *
     * @param resource $stream
     */
    private static function streamKey($stream): string
    {
        if (get_resource_type($stream) !== 'stream' || !stream_get_meta_data($stream)['seekable']) {
            throw new InvalidArgumentException('A binary relation key must be a seekable stream.');
        }

        $position = ftell($stream);
        if ($position === false) {
            throw new InvalidArgumentException('Unable to determine the binary relation key stream position.');
        }

        try {
            $bytes = stream_get_contents($stream, -1, 0);
            if ($bytes === false) {
                throw new InvalidArgumentException('Unable to read the binary relation key.');
            }
            return $bytes;
        } finally {
            if (fseek($stream, $position) !== 0) {
                throw new InvalidArgumentException('Unable to restore the binary relation key stream position.');
            }
        }
    }

}
