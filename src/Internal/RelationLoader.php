<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\QueryParameters;
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

        [$ids, $ordinals, $binary, $references] = self::lookupKeys($rows, $foreignKey, $binaryKey);
        $columns = self::includeKey($columns, $tableKey);
        $groups = $this->match(
            $this->db->select($columns)->from($table),
            $tableKey,
            $ids,
            $binary,
            self::sqlitePlan($table, $columns, $tableKey),
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
            self::sqlitePlan($table, $columns, $foreignKey),
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

        $sqlite = self::simpleIdentifier($pivotTable) && self::simpleIdentifier($pivotLocalKey)
            ? self::sqlitePlan($relatedTable, $columns, $pivotLocalKey, 'p')
            : null;
        $groups = $this->match($base, "p.$pivotLocalKey", $ids, $binary, $sqlite, $transform);
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

            $identity = is_int($id) ? $id : (is_string($id) ? 's:' . $id : serialize($id));
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
     * Tag every matched row with the ordinal of its requested key, rather
     * than indexing by the returned column value. PDO consumes the first
     * column as the group key and never exposes it to projection callbacks.
     *
     * @param list<mixed> $ids
     * @param array{table: string, columns: list<string>, key: string}|null $sqlite
     * @return array<int, list<array>>
     */
    private function match(
        SelectQuery $base,
        string $column,
        array $ids,
        bool $binary,
        ?array $sqlite,
        ?Closure $transform = null,
    ): array
    {
        $groups = [];
        if ($ids === []) {
            return $groups;
        }
        $driver = $base->getDriver() ?? throw new \LogicException('A relation query must have a database driver.');
        $compiler = $driver->getQueryCompiler();
        $prefix = $base->getPrefix() ?? '';
        // SQLite's conservative limit also supports builds with the older
        // 999-variable default; reserve room below SQL Server's 2100 limit.
        $maxParameters = match ($driver->getType()) {
            'SQLite' => 999,
            'SQLServer' => 2000,
            default => 65535,
        };
        foreach (array_chunk($ids, self::BATCH_SIZE, true) as $chunk) {
            $bindings = array_map(
                static fn (mixed $id): Parameter => new Parameter($id, $binary ? PDO::PARAM_LOB : Parameter::DETECT_TYPE),
                $chunk,
            );
            if ($sqlite !== null && $driver->getType() === 'SQLite') {
                $query = self::sqliteQuery($base, $sqlite, $bindings);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                self::readGroups($driver, $sql, $parameters->getParameters(), $groups, $transform);
                continue;
            }

            // Each comparison is typed by its actual target column. A
            // heterogeneous VALUES table would coerce or reject keys on
            // PostgreSQL/SQL Server. Compile standalone arms: SQLite rejects
            // the parentheses DBAL adds around nested SELECT fragments.
            $arms = [];
            $queryBindings = [];
            foreach ($bindings as $ordinal => $parameter) {
                $arm = (clone $base)
                    ->columns([new Fragment((string) $ordinal), ...$base->getColumns()])
                    ->where($column, '=', $parameter);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $arm);
                $armBindings = $parameters->getParameters();
                // Trusted projections may themselves contain parameters;
                // count the real compiled bindings, not only lookup keys.
                if ($arms !== [] && count($queryBindings) + count($armBindings) > $maxParameters) {
                    self::readGroups($driver, implode("\nUNION ALL\n", $arms), $queryBindings, $groups, $transform);
                    $arms = [];
                    $queryBindings = [];
                }
                $arms[] = $sql;
                array_push($queryBindings, ...$armBindings);
            }
            self::readGroups($driver, implode("\nUNION ALL\n", $arms), $queryBindings, $groups, $transform);
        }

        return $groups;
    }


    /**
     * Close the statement before invoking application transforms. Transform
     * and release each batch rather than accumulating all raw to-many rows.
     */
    private static function readGroups(
        DriverInterface $driver,
        string $sql,
        array $bindings,
        array &$groups,
        ?Closure $transform,
    ): void {
        $statement = $driver->query($sql, $bindings);
        try {
            $matches = $statement->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
        } finally {
            $statement->close();
        }

        foreach ($matches as $ordinal => $rows) {
            $groups[$ordinal] = $transform === null ? $rows : array_map($transform, $rows);
        }
    }

    /**
     * SQLite VALUES retains the individual storage class of each bound key.
     * Join once for ordinary projections instead of planning one SELECT per
     * key. Complex trusted projections use the portable compound-query path.
     *
     * @return array{table: string, columns: list<string>, key: string}|null
     */
    private static function sqlitePlan(string $table, array $columns, string $column, string $alias = 'r'): ?array
    {
        if (!self::simpleIdentifier($table) || !self::simpleIdentifier($column) || !array_is_list($columns)) {
            return null;
        }
        $qualified = [];
        foreach ($columns as $field) {
            if ($field === '*') {
                $qualified[] = 'r.*';
            } elseif (is_string($field) && self::simpleIdentifier($field)) {
                $qualified[] = 'r.' . $field;
            } else {
                return null;
            }
        }
        return ['table' => "$table AS r", 'columns' => $qualified, 'key' => "\"$alias\".\"$column\""];
    }

    private static function simpleIdentifier(string $name): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) === 1;
    }

    /** @param array<int, Parameter> $bindings */
    private static function sqliteQuery(SelectQuery $base, array $plan, array $bindings): SelectQuery
    {
        $values = [];
        foreach ($bindings as $ordinal => $parameter) {
            $values[] = '(?, ' . $ordinal . ')';
        }
        $keys = new Fragment('(VALUES ' . implode(', ', $values) . ') AS "_componenta_keys"', ...array_values($bindings));

        return (clone $base)
            ->from([$plan['table'], $keys])
            ->columns([new Fragment('"_componenta_keys"."column2"'), ...$plan['columns']])
            ->where(new Fragment($plan['key'] . ' = "_componenta_keys"."column1"'));
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
