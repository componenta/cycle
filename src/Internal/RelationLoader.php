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
            self::joinPlan($table, $columns, $tableKey),
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
            self::joinPlan($table, $columns, $foreignKey),
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
            ? self::joinPlan($relatedTable, $columns, $pivotLocalKey, 'p', $pivotTable, $pivotForeignKey, $relatedKey)
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
     * @param array{table: string, columns: list<string>, key: string, keyTable: string, keyColumn: string, alias: string, tableName: string, pivotForeignKey: ?string, relatedKey: ?string}|null $sqlite
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
        $projection = self::groupProjection($base, $driver);
        $postgresSeed = $sqlite !== null && $driver->getType() === 'Postgres'
            ? $this->typedSeed($sqlite, $driver, $prefix)
            : null;
        $sqlServerSeed = $sqlite !== null && $driver->getType() === 'SQLServer' && count($ids) >= 16
            && self::uniformKeys($ids)
            ? $this->typedSeed($sqlite, $driver, $prefix, true)
            : null;
        $mysqlCast = $sqlite !== null && $driver->getType() === 'MySQL' && count($ids) >= 16
            ? $this->mysqlCast($sqlite, $driver, $prefix, $ids, $binary)
            : null;
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
                $query = $this->sqliteQuery($sqlite, $bindings);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                self::readGroups($driver, $sql, $parameters->getParameters(), $groups, $transform);
                continue;
            }
            if ($postgresSeed !== null) {
                $query = self::postgresQuery($base, $sqlite, $bindings, $postgresSeed);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                self::readGroups($driver, $sql, $parameters->getParameters(), $groups, $transform);
                continue;
            }

            if ($sqlServerSeed !== null || $mysqlCast !== null) {
                $query = self::serverKeyQuery($base, $sqlite, $bindings, $driver, $sqlServerSeed, $mysqlCast);
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
                    ->columns([new Fragment((string) $ordinal), ...$projection])
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
     * MySQL does not accept an unqualified star after a preceding SELECT
     * expression. Qualify the existing source rather than introducing an
     * alias that would invalidate a caller's qualified column names.
     */
    private static function groupProjection(SelectQuery $base, DriverInterface $driver): array
    {
        $columns = $base->getColumns();
        if ($driver->getType() !== 'MySQL' || !in_array('*', $columns, true)) {
            return $columns;
        }

        // Relation bases have exactly one named FROM source. A schema-
        // qualified name uses its final component as the table qualifier.
        $source = $base->getTables()[0];
        $parts = preg_split('/ AS /i', $source, 2);
        $qualifier = $parts[1] ?? null;
        if ($qualifier === null) {
            $separator = strrpos($source, '.');
            $qualifier = $separator === false
                ? ($base->getPrefix() ?? '') . $source
                : substr($source, $separator + 1);
        }
        $star = new Fragment($driver->getQueryCompiler()->quoteIdentifier($qualifier) . '.*');

        return array_map(static fn (mixed $column): mixed => $column === '*' ? $star : $column, $columns);
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
     * Simple projections can be joined to a native lookup-key relation.
     * Complex trusted projections use the portable compound-query path.
     *
     * @return array{table: string, columns: list<string>, key: string}|null
     */
    private static function joinPlan(
        string $table,
        array $columns,
        string $column,
        string $alias = 'r',
        ?string $keyTable = null,
        ?string $pivotForeignKey = null,
        ?string $relatedKey = null,
    ): ?array {
        if ($keyTable !== null && (!self::simpleIdentifier($pivotForeignKey ?? '') || !self::simpleIdentifier($relatedKey ?? ''))) {
            return null;
        }
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
        return [
            'table' => "$table AS r",
            'columns' => $qualified,
            'key' => "\"$alias\".\"$column\"",
            'keyTable' => $keyTable ?? $table,
            'keyColumn' => $column,
            'alias' => $alias,
            'tableName' => $table,
            'pivotForeignKey' => $pivotForeignKey,
            'relatedKey' => $relatedKey,
        ];
    }

    private static function simpleIdentifier(string $name): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) === 1;
    }

    /** @param array<int, Parameter> $bindings */
    private function sqliteQuery(array $plan, array $bindings): SelectQuery
    {
        $values = [];
        foreach ($bindings as $ordinal => $parameter) {
            $values[] = '(?, ' . $ordinal . ')';
        }
        $keys = new Fragment('(VALUES ' . implode(', ', $values) . ') AS "_componenta_keys"', ...array_values($bindings));

        // SQLite may otherwise scan an entire BLOB-key table before probing
        // a tiny VALUES relation. CROSS JOIN fixes the outer loop to the
        // requested keys; the lookup column's index remains usable.
        $query = $this->db->select([new Fragment('"_componenta_keys"."column2"'), ...$plan['columns']])
            ->from($keys);
        if ($plan['alias'] === 'p') {
            $query->join('CROSS', $plan['keyTable'], 'p')
                ->innerJoin($plan['tableName'], 'r')
                ->on('p.' . $plan['pivotForeignKey'], 'r.' . $plan['relatedKey']);
        } else {
            $query->join('CROSS', $plan['tableName'], 'r');
        }

        return $query->where(new Fragment($plan['key'] . ' = "_componenta_keys"."column1"'));
    }

    /**
     * PostgreSQL infers bound values from the actual lookup column, not
     * from the PHP representation. The empty scalar subquery supplies its
     * SQL type without reading any target rows.
     *
     * @param array{table: string, columns: list<string>, key: string, keyTable: string, keyColumn: string, alias: string, tableName: string, pivotForeignKey: ?string, relatedKey: ?string} $plan
     */
    private function typedSeed(array $plan, DriverInterface $driver, string $prefix, bool $ordinal = false): string
    {
        $columns = $ordinal
            ? [$plan['keyColumn'] . ' AS componenta_value', new Fragment('-1 AS componenta_ordinal')]
            : [$plan['keyColumn']];
        $seed = $this->db->select($columns)
            ->from($plan['keyTable'])
            ->where(new Fragment('1 = 0'));
        return $driver->getQueryCompiler()->compile(new QueryParameters(), $prefix, $seed);
    }

    /** @param array<int, Parameter> $bindings */
    private static function postgresQuery(SelectQuery $base, array $plan, array $bindings, string $seed): SelectQuery
    {
        // The first array slot is a typed SQL NULL. WITH ORDINALITY
        // associates every remaining value with its exact input position,
        // even when distinct inputs compare equal in the database.
        $placeholders = implode(', ', array_fill(0, count($bindings), '?'));
        $keys = new Fragment(
            'unnest(ARRAY[(' . $seed . '), ' . $placeholders
                . ']) WITH ORDINALITY AS "_componenta_keys"("value", "ordinal")',
            ...array_values($bindings),
        );
        $first = array_key_first($bindings);
        return (clone $base)
            ->from([$keys, $plan['table']])
            ->columns([new Fragment('"_componenta_keys"."ordinal" - 2 + ' . $first), ...$plan['columns']])
            ->where(new Fragment($plan['key'] . ' = "_componenta_keys"."value"'))
            ->where(new Fragment('"_componenta_keys"."ordinal" > 1'));
    }

    /** @param list<mixed> $ids */
    private static function uniformKeys(array $ids): bool
    {
        $type = gettype($ids[0]);
        if ($type !== 'integer' && $type !== 'string') {
            return false;
        }
        foreach ($ids as $id) {
            if (gettype($id) !== $type) {
                return false;
            }
        }
        return true;
    }

    /**
     * MySQL chooses a common type for derived columns. Do not let a
     * VARCHAR-derived key turn exact integer comparisons into floating
     * point comparisons, or let the connection collation replace the
     * target collation. Inspect only the actual target column, never
     * infer its type from PHP's representation of the root key.
     *
     * The probe is scoped to this execution (no stale schema cache).
     * Complex/unknown types and mixed representations retain direct
     * per-key comparisons. Explicit binary keys need no metadata probe.
     */
    private function mysqlCast(array $plan, DriverInterface $driver, string $prefix, array $ids, bool $binary): ?string
    {
        if ($binary) {
            return 'CAST(? AS BINARY)';
        }
        if (!self::uniformKeys($ids)) {
            return null;
        }
        $statement = $driver->query(
            'SELECT DATA_TYPE AS data_type, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name'
                . ' FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . ' AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [new Parameter($prefix . $plan['keyTable']), new Parameter($plan['keyColumn'])],
        );
        try {
            $column = $statement->fetch();
        } finally {
            $statement->close();
        }
        if ($column === false) {
            return null;
        }

        $type = strtolower($column['data_type']);
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
            foreach ($ids as $id) {
                if (!is_int($id) && (!is_string($id) || (string) (int) $id !== $id)) {
                    return null;
                }
            }
            return 'CAST(? AS SIGNED)';
        }
        if (!is_string($ids[0])) {
            return null;
        }
        if (in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true)) {
            return 'CAST(? AS BINARY)';
        }
        if (!in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
            return null;
        }
        $charset = $column['character_set_name'];
        $collation = $column['collation_name'];
        if (!is_string($charset) || !is_string($collation)
            || !self::simpleIdentifier($charset) || !self::simpleIdentifier($collation)) {
            return null;
        }
        return 'CAST(? AS CHAR CHARACTER SET ' . $charset . ') COLLATE ' . $collation;
    }

    /**
     * Join a relation of bound keys and ordinals rather than duplicating
     * the target query in each arm. SQL Server's empty typed seed supplies
     * column type and collation; the MySQL planner uses explicit casts.
     *
     * @param array<int, Parameter> $bindings
     */
    private static function serverKeyQuery(
        SelectQuery $base,
        array $plan,
        array $bindings,
        DriverInterface $driver,
        ?string $seed,
        ?string $cast,
    ): SelectQuery {
        $arms = [];
        if ($seed !== null) {
            $arms[] = $seed;
        }
        foreach ($bindings as $ordinal => $parameter) {
            $arms[] = 'SELECT ' . ($cast ?? '?') . ' AS componenta_value, '
                . $ordinal . ' AS componenta_ordinal';
        }
        $compiler = $driver->getQueryCompiler();
        $alias = $compiler->quoteIdentifier('_componenta_keys');
        $value = $alias . '.' . $compiler->quoteIdentifier('componenta_value');
        $ordinal = $alias . '.' . $compiler->quoteIdentifier('componenta_ordinal');
        $key = $compiler->quoteIdentifier($plan['alias'] . '.' . $plan['keyColumn']);
        $keys = new Fragment('(' . implode("\nUNION ALL\n", $arms) . ') AS ' . $alias, ...array_values($bindings));

        return (clone $base)
            ->from([$keys, $plan['table']])
            ->columns([new Fragment($ordinal), ...$plan['columns']])
            ->where(new Fragment($key . ' = ' . $value))
            ->where(new Fragment($ordinal . ' >= 0'));
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
