<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Exception\StatementException;
use Cycle\Database\Query\SelectQuery;
use InvalidArgumentException;
use PDO;

/**
 * Loads related rows in bounded queries and attaches them to projections.
 *
 * @internal
 */
final readonly class RelationLoader
{
    private RelationQueryPlanner $planner;

    public function __construct(private DatabaseInterface $db)
    {
        $this->planner = new RelationQueryPlanner($db);
    }

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

        [$ids, $ordinals, $binary, $references] = self::lookupKeys($rows, $foreignKey, $binaryKey);
        $columns = self::includeKey($columns, $tableKey);
        $groups = $this->match(
            $this->db->select($columns)->from($table),
            $tableKey,
            $ids,
            $binary,
            RelationQueryPlanner::joinPlan($table, $columns, $tableKey),
        );

        foreach ($results as $index => &$result) {
            $ordinal = $ordinals[$index] ?? null;
            $matches = $ordinal === null ? [] : ($groups[$ordinal] ?? []);
            // A belongs-to key identifies at most one row; preserve the
            // historical last-row behavior if the target is not unique.
            $related = $matches === [] ? null : $matches[array_key_last($matches)];
            if ($related !== null && $transform !== null) {
                if ($references[$ordinal] > 1) {
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

        [$ids, $ordinals, $binary] = self::lookupKeys($rows, $localKey, $binaryKey);
        $columns = self::includeKey($columns, $foreignKey);
        $groups = $this->match(
            $this->db->select($columns)->from($table),
            $foreignKey,
            $ids,
            $binary,
            RelationQueryPlanner::joinPlan($table, $columns, $foreignKey),
            $transform,
        );
        self::attachMany($results, $resultKey, $ordinals, $groups);
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

        [$ids, $ordinals, $binary] = self::lookupKeys($rows, $localKey, $binaryKey);
        $projection = $columns === ['*'] ? ['r.*'] : array_map(static fn(string $c) => "r.$c", $columns);
        $base = $this->db->select($projection)
            ->from("$relatedTable AS r")
            ->innerJoin($pivotTable, 'p')
            ->on("p.$pivotForeignKey", "r.$relatedKey");

        $plan = RelationQueryPlanner::joinPlan(
            $relatedTable, $columns, $pivotLocalKey, 'p', $pivotTable, $pivotForeignKey, $relatedKey,
        );
        $groups = $this->match($base, "p.$pivotLocalKey", $ids, $binary, $plan, $transform);
        self::attachMany($results, $resultKey, $ordinals, $groups);
    }

    /**
     * Resolve each distinct PHP input to a stable ordinal. Never infer SQL
     * equality from a PHP array key (collations, affinity and type coercion
     * differ). Preserve nulls, exact bytes and the type of every parameter.
     *
     * @return array{list<mixed>, array<array-key, int>, bool, array<int, int>}
     */
    private static function lookupKeys(array $rows, string $column, bool $binary): array
    {
        $ids = [];
        $ordinals = [];
        $references = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $id = $row[$column] ?? null;
            if ($id === null) {
                continue;
            }
            if (is_resource($id)) {
                $id = self::streamKey($id);
                $binary = true;
            } elseif (gettype($id) === 'resource (closed)') {
                throw new InvalidArgumentException('A relation key stream was closed during row transformation.');
            }
            if (!is_scalar($id)) {
                throw new InvalidArgumentException('A relation key must be a scalar or a seekable stream.');
            }

            // Serialization of a float depends on serialize_precision and
            // may collapse distinct database parameters into one ordinal.
            $identity = match (true) {
                is_int($id) => $id,
                is_string($id) => 's:' . $id,
                is_float($id) => 'f:' . pack('E', $id),
                default => $id ? 'b:1' : 'b:0',
            };
            if (!isset($seen[$identity])) {
                $seen[$identity] = count($ids);
                $ids[] = $id;
            }
            $ordinal = $seen[$identity];
            $ordinals[$index] = $ordinal;
            $references[$ordinal] = ($references[$ordinal] ?? 0) + 1;
        }

        if ($binary) {
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    throw new InvalidArgumentException('A binary relation key must be a string or a seekable stream.');
                }
            }
        }

        return [$ids, $ordinals, $binary, $references];
    }

    /**
     * Group rows by the requested key ordinal. PDO consumes the first
     * projected column, so it cannot collide with a related field name.
     */
    private function match(
        SelectQuery $base,
        string $column,
        array $ids,
        bool $binary,
        ?array $plan,
        ?Closure $transform = null,
    ): array {
        if ($ids === []) {
            return [];
        }
        $driver = $base->getDriver() ?? throw new \LogicException('A relation query must have a database driver.');
        $groups = [];
        $individual = false;
        foreach ($this->planner->queries($base, $column, $ids, $binary, $plan) as $batch) {
            self::readGroups($driver, $batch, $groups, $transform, $individual);
        }
        return $groups;
    }

    /**
     * Keep raw rows scoped to one batch. In particular, a transformed
     * batch must not remain alive while the next statement is being read.
     *
     * @param array{0: string, 1: array, 2: ?array, 3?: list<array{string, array}>} $batch
     */
    private static function readGroups(
        DriverInterface $driver,
        array $batch,
        array &$groups,
        ?Closure $transform,
        bool &$individual,
    ): void {
        $matches = self::readBatch($driver, $batch, $individual);
        $ordinals = $batch[2];
        foreach ($matches as $ordinal => $rows) {
            if ($ordinals !== null) {
                $ordinal = $ordinals[$ordinal] ?? throw new \UnexpectedValueException('An exact relation lookup returned an unrequested key.');
            }
            $groups[$ordinal] = $transform === null ? $rows : array_map($transform, $rows);
        }
    }

    /**
     * MySQL rejects compound SELECTs that reopen a temporary table. Retry
     * only its documented server error 1137 and only if this batch contains
     * multiple independent SELECT arms. Keep this decision execution-local.
     * No application callback runs until all rows in the batch are read.
     *
     * @param array{0: string, 1: array, 2: ?array, 3?: list<array{string, array}>} $batch
     */
    private static function readBatch(DriverInterface $driver, array $batch, bool &$individual): array
    {
        [$sql, $bindings] = $batch;
        $arms = $batch[3] ?? [];
        if (!$individual || count($arms) < 2) {
            try {
                return self::fetchGroups($driver, $sql, $bindings);
            } catch (StatementException $exception) {
                $cause = $exception->getPrevious();
                if (count($arms) < 2 || $driver->getType() !== 'MySQL'
                    || !$cause instanceof \PDOException
                    || (int) ($cause->errorInfo[1] ?? 0) !== 1137) {
                    throw $exception;
                }
                $individual = true;
            }
        }

        $matches = [];
        foreach ($arms as [$armSql, $armBindings]) {
            foreach (self::fetchGroups($driver, $armSql, $armBindings) as $ordinal => $rows) {
                $matches[$ordinal] = $rows;
            }
        }

        return $matches;
    }

    /** Close every cursor before invoking application transforms. */
    private static function fetchGroups(DriverInterface $driver, string $sql, array $bindings): array
    {
        $statement = $driver->query($sql, $bindings);
        try {
            return $statement->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        } finally {
            $statement->close();
        }
    }

    private static function includeKey(array $columns, string $key): array
    {
        if ($columns !== ['*'] && !in_array($key, $columns, true)) {
            $columns[] = $key;
        }
        return $columns;
    }

    private static function attachMany(
        array &$results,
        string $key,
        array $ordinals,
        array $groups,
    ): void {
        foreach ($results as $index => &$result) {
            $ordinal = $ordinals[$index] ?? null;
            $result[$key] = $ordinal === null ? [] : ($groups[$ordinal] ?? []);
        }
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
