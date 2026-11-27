<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\CompilerInterface;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\SelectQuery;
use PDO;

/** Builds bounded, parameterized SQL for native database equality. @internal */
final readonly class RelationQueryPlanner
{
    private const int BATCH_SIZE = 500;

    public function __construct(private DatabaseInterface $db) {}

    /**
     * Each returned statement projects the original lookup ordinal first.
     *
     * @param list<mixed> $ids
     * @return iterable<array{string, array, ?array}>
     */
    public function queries(
        SelectQuery $base,
        string $column,
        array $ids,
        bool $binary,
        ?array $plan,
    ): iterable {
        if ($ids === []) {
            return;
        }
        $driver = $base->getDriver() ?? throw new \LogicException('A relation query must have a database driver.');
        $compiler = $driver->getQueryCompiler();
        $prefix = $base->getPrefix() ?? '';
        $projection = self::groupProjection($base, $driver);
        $postgresPlan = $plan !== null && $driver->getType() === 'Postgres' && count($ids) >= 16
            ? $this->postgresPlan($plan, $driver, $prefix, $ids, $binary)
            : null;
        $sqlServerEligible = $plan !== null && $driver->getType() === 'SQLServer'
            && count($ids) >= 16 && self::uniformKeys($ids);
        $sqlServerPlan = $sqlServerEligible
            ? $this->sqlServerPlan($plan, $driver, $prefix, $ids, $binary)
            : null;
        $sqlServerSeed = $sqlServerEligible && $sqlServerPlan === null
            ? $this->typedSeed($plan, $driver, $prefix, true)
            : null;
        $mysqlPlan = $plan !== null && $driver->getType() === 'MySQL' && count($ids) >= 16
            ? $this->mysqlPlan($plan, $driver, $prefix, $ids, $binary)
            : null;
        // SQLite's conservative limit also supports builds with the older
        // 999-variable default; reserve room below SQL Server's 2100 limit.
        $maxParameters = match ($driver->getType()) {
            'SQLite' => 999,
            'SQLServer' => 2000,
            default => 65535,
        };
        $template = null;
        $templateChecked = false;
        foreach (array_chunk($ids, self::BATCH_SIZE, true) as $chunk) {
            $bindings = array_map(
                static fn (mixed $id): Parameter => new Parameter($id, $binary ? PDO::PARAM_LOB : Parameter::DETECT_TYPE),
                $chunk,
            );
            if ($plan !== null && $driver->getType() === 'SQLite') {
                $query = $this->sqliteQuery($plan, $bindings);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                yield [$sql, $parameters->getParameters(), null];
                continue;
            }
            // Only exact, type-verified equality can use returned lookup keys.
            $exact = $postgresPlan['exact'] ?? $mysqlPlan['exact']
                ?? ($sqlServerPlan === 'sqlserver-integer' ? $sqlServerPlan : null);
            if ($exact !== null) {
                yield ExactRelationLookup::compile($base, $plan, $bindings, $driver, $prefix, $exact);
                continue;
            }
            if ($postgresPlan !== null) {
                $query = self::postgresQuery($base, $plan, $bindings, $postgresPlan['seed']);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                yield [$sql, $parameters->getParameters(), null];
                continue;
            }

            if ($sqlServerPlan === 'sqlserver-json-binary') {
                $query = self::sqlServerBinaryQuery($base, $plan, $bindings, $driver);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                yield [$sql, $parameters->getParameters(), null];
                continue;
            }

            if ($sqlServerSeed !== null || $mysqlPlan !== null) {
                $query = self::serverKeyQuery($base, $plan, $bindings, $driver, $sqlServerSeed, $mysqlPlan);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                yield [$sql, $parameters->getParameters(), null];
                continue;
            }

            if ($plan !== null && $driver->getType() === 'MySQL' && count($bindings) > 1) {
                $query = self::mysqlPredicateQuery($base, $plan, $bindings, $driver);
                $parameters = new QueryParameters();
                $sql = $compiler->compile($parameters, $prefix, $query);
                yield [$sql, $parameters->getParameters(), null];
                continue;
            }

            // Each comparison is typed by its actual target column. A
            // heterogeneous VALUES table would coerce or reject keys on
            // PostgreSQL/SQL Server. Compile standalone arms: SQLite rejects
            // the parentheses DBAL adds around nested SELECT fragments.
            $arms = [];
            $queryBindings = [];
            if (!$templateChecked) {
                $template = self::simpleArmTemplate(
                    $base, $projection, $column, $bindings[array_key_first($bindings)], $compiler, $prefix,
                );
                $templateChecked = true;
            }
            foreach ($bindings as $ordinal => $parameter) {
                if ($template !== null) {
                    $sql = $template[0] . $ordinal . $template[1];
                    $armBindings = [$parameter];
                } else {
                    $arm = (clone $base)
                        ->columns([new Fragment((string) $ordinal), ...$projection])
                        ->where($column, '=', $parameter);
                    $parameters = new QueryParameters();
                    $sql = $compiler->compile($parameters, $prefix, $arm);
                    $armBindings = $parameters->getParameters();
                }
                // Trusted projections may themselves contain parameters;
                // count the real compiled bindings, not only lookup keys.
                if ($arms !== [] && count($queryBindings) + count($armBindings) > $maxParameters) {
                    yield [implode("\nUNION ALL\n", $arms), $queryBindings, null];
                    $arms = [];
                    $queryBindings = [];
                }
                $arms[] = $sql;
                array_push($queryBindings, ...$armBindings);
            }
            yield [implode("\nUNION ALL\n", $arms), $queryBindings, null];
        }
    }

    /**
     * The bundled DBAL compilers emit the same scalar predicate for each
     * non-null key. Reuse that SQL shape only for plain projections and
     * the exact known compiler implementations. Custom fragments and
     * custom compilers can have their own binding/compilation behavior.
     *
     * @return array{string, string}|null
     */
    private static function simpleArmTemplate(
        SelectQuery $base,
        array $projection,
        string $column,
        Parameter $first,
        CompilerInterface $compiler,
        string $prefix,
    ): ?array {
        if (!in_array($compiler::class, [
            \Cycle\Database\Driver\SQLite\SQLiteCompiler::class,
            \Cycle\Database\Driver\MySQL\MySQLCompiler::class,
            \Cycle\Database\Driver\Postgres\PostgresCompiler::class,
            \Cycle\Database\Driver\SQLServer\SQLServerCompiler::class,
        ], true)) {
            return null;
        }
        foreach ($base->getColumns() as $field) {
            if (!is_string($field)) {
                return null;
            }
        }
        $arm = (clone $base)
            ->columns([new Fragment('0'), ...$projection])
            ->where($column, '=', $first);
        $parameters = new QueryParameters();
        $sql = $compiler->compile($parameters, $prefix, $arm);
        if ($parameters->getParameters() !== [$first]
            || preg_match('/\A(SELECT\s+)0(?=\s*,)/i', $sql, $match) !== 1) {
            return null;
        }

        // Substitute only the internally generated first SELECT literal.
        // Neither identifiers nor user data enter this substitution.
        return [$match[1], substr($sql, strlen($match[0]))];
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
     * Simple projections can be joined to a native lookup-key relation.
     * Complex trusted projections use the portable compound-query path.
     *
     * @return array{table: string, columns: list<string>, key: string, keyTable: string, keyColumn: string, alias: string, tableName: string, pivotForeignKey: ?string, relatedKey: ?string}|null
     */
    public static function joinPlan(
        string $table,
        array $columns,
        string $column,
        string $alias = 'r',
        ?string $keyTable = null,
        ?string $pivotForeignKey = null,
        ?string $relatedKey = null,
    ): ?array {
        if ($keyTable !== null && (!self::simpleIdentifier($keyTable) || !self::simpleIdentifier($pivotForeignKey ?? '') || !self::simpleIdentifier($relatedKey ?? ''))) {
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
            } elseif (is_string($field) && preg_match(
                '/\A([A-Za-z_][A-Za-z0-9_]*)[ \t]+AS[ \t]+([A-Za-z_][A-Za-z0-9_]*)\z/i',
                $field,
                $aliasParts,
            ) === 1) {
                $qualified[] = 'r.' . $aliasParts[1] . ' AS ' . $aliasParts[2];
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

    /**
     * An array constructor can erase a domain type, changing operator
     * resolution. Only optimize supported built-in scalar types, including
     * when a temporary table shadows the original source. Inspect the type
     * of the actual scalar subquery, not a cached catalog table name.
     */
    private function postgresPlan(array $plan, DriverInterface $driver, string $prefix, array $ids, bool $binary): ?array
    {
        $seed = $this->typedSeed($plan, $driver, $prefix);
        $statement = $driver->query(
            'SELECT t.typname, EXISTS (SELECT 1 FROM pg_catalog.pg_operator o'
                . ' JOIN pg_catalog.pg_namespace ns ON ns.oid = o.oprnamespace'
                . " WHERE ns.nspname = 'pg_catalog' AND o.oprname = '='"
                . ' AND o.oprleft = t.oid AND o.oprright = t.oid'
                . ' AND pg_catalog.pg_operator_is_visible(o.oid)) AS native_equality'
                . ' FROM pg_catalog.pg_type t'
                . ' JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace'
                . " WHERE n.nspname = 'pg_catalog' AND t.oid = pg_catalog.pg_typeof((" . $seed . '))::oid',
        );
        try {
            $row = $statement->fetch();
        } finally {
            $statement->close();
        }

        if ($row !== false) {
            $row = array_change_key_case($row, CASE_LOWER);
        }
        if ($row === false || !in_array($row['typname'], [
            'int2', 'int4', 'int8', 'text', 'varchar', 'bpchar', 'bytea', 'uuid',
        ], true)) {
            return null;
        }
        $type = $row['typname'];
        $native = in_array($row['native_equality'], [true, 1, '1', 't'], true);
        $exact = null;
        if (in_array($type, ['int2', 'int4', 'int8', 'bytea'], true)) {
            // A shadowed equality operator cannot be reduced to PHP identity.
            if (!$native) {
                return null;
            }
            if ($type === 'bytea' && $binary) {
                $exact = 'pg-bytea';
            } elseif (!$binary && ExactRelationLookup::integerKeys($ids, match ($type) {
                'int2' => [-32768, 32767],
                'int4' => [-2147483648, 2147483647],
                'int8' => [PHP_INT_MIN, PHP_INT_MAX],
                default => null,
            })) {
                $exact = 'pg-' . $type;
            }
        }

        return ['seed' => $seed, 'exact' => $exact];
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
     * Preserve the original scalar comparison for every requested key.
     * MySQL cannot reopen a temporary table in multiple UNION arms; the
     * derived relation here contains only generated ordinals. Each key
     * retains its own bound parameter and comparison with the real column.
     *
     * @param array<int, Parameter> $bindings
     */
    private static function mysqlPredicateQuery(
        SelectQuery $base,
        array $plan,
        array $bindings,
        DriverInterface $driver,
    ): SelectQuery {
        $compiler = $driver->getQueryCompiler();
        $alias = $compiler->quoteIdentifier('_componenta_keys');
        $ordinal = $alias . '.' . $compiler->quoteIdentifier('componenta_ordinal');
        $key = $compiler->quoteIdentifier($plan['alias'] . '.' . $plan['keyColumn']);
        $arms = [];
        $conditions = [];
        foreach ($bindings as $index => $parameter) {
            $arms[] = 'SELECT ' . $index . ' AS componenta_ordinal';
            $conditions[] = '(' . $ordinal . ' = ' . $index . ' AND ' . $key . ' = ?)';
        }
        $keys = new Fragment('(' . implode("\nUNION ALL\n", $arms) . ') AS ' . $alias);

        return (clone $base)
            ->from([$keys, $plan['table']])
            ->columns([new Fragment($ordinal), ...$plan['columns']])
            ->where(new Fragment(implode(' OR ', $conditions), ...array_values($bindings)));
    }

    /**
     * MySQL chooses a common type for derived columns. Inspect the real
     * lookup column before selecting a cast. Never use a string-derived
     * comparison for integer keys, or replace the target collation.
     *
     * Capability and column metadata are execution-scoped, not cached
     * across requests or reconnections.
     *
     * @return array{exact: string}|array{cast: string, json: ?string}|null
     */
    private function mysqlPlan(array $plan, DriverInterface $driver, string $prefix, array $ids, bool $binary): ?array
    {
        if (!self::uniformKeys($ids)) {
            return null;
        }
        // SHOW resolves temporary tables on this connection as well as
        // permanent ones. INFORMATION_SCHEMA.COLUMNS does not see them.
        $table = $driver->getQueryCompiler()->quoteIdentifier($prefix . $plan['keyTable']);
        $statement = $driver->query('SHOW FULL COLUMNS FROM ' . $table);
        $column = null;
        try {
            while (($row = $statement->fetch()) !== false) {
                $row = array_change_key_case($row, CASE_LOWER);
                if (strcasecmp($row['field'], $plan['keyColumn']) === 0) {
                    $column = $row;
                    break;
                }
            }
        } finally {
            $statement->close();
        }
        if ($column === null || preg_match('/\A([a-z]+)/i', $column['type'], $matches) !== 1) {
            return null;
        }
        $type = strtolower($matches[1]);

        if (in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true)
            && is_string($ids[0])) {
            // BINARY(n) can introduce padding; use exact grouping only
            // for values already at the declared width (or variable types).
            if ($binary && ExactRelationLookup::binaryKeys($ids, $type === 'binary' ? $column['type'] : null)) {
                return ['exact' => 'mysql-binary'];
            }
            foreach ($ids as $id) {
                if (strlen($id) > 256) {
                    return ['cast' => 'CAST(? AS BINARY)', 'json' => null];
                }
            }
            return ['cast' => 'CAST(? AS BINARY)', 'json' => self::mysqlJsonSupported($driver) ? 'binary' : null];
        }
        if ($binary) {
            // An explicit LOB binding must not become a text parameter.
            return null;
        }
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)
            && is_int($ids[0])) {
            $unsigned = str_contains(strtolower($column['type']), 'unsigned');
            $bounds = match ($type) {
                'tinyint' => $unsigned ? [0, 255] : [-128, 127],
                'smallint' => $unsigned ? [0, 65535] : [-32768, 32767],
                'mediumint' => $unsigned ? [0, 16777215] : [-8388608, 8388607],
                'int' => $unsigned ? [0, 4294967295] : [-2147483648, 2147483647],
                'bigint' => [$unsigned ? 0 : PHP_INT_MIN, PHP_INT_MAX],
            };
            if (ExactRelationLookup::integerKeys($ids, $bounds)) {
                return ['exact' => 'mysql-integer'];
            }
            // Keep direct predicates for values outside the declared range.
            return null;
        }
        if (!is_string($ids[0]) || !in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
            return null;
        }
        $collation = $column['collation'];
        // A conversion to a narrower charset can replace input characters
        // with '?'. Retain the original comparison in that case.
        if (!is_string($collation) || !str_starts_with($collation, 'utf8mb4_') || !self::simpleIdentifier($collation)) {
            return null;
        }
        foreach ($ids as $id) {
            if (preg_match('//u', $id) !== 1) {
                return null;
            }
        }
        return ['cast' => 'CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE ' . $collation, 'json' => null];
    }

    private static function mysqlJsonSupported(DriverInterface $driver): bool
    {
        $statement = $driver->query('SELECT VERSION() AS server_version');
        try {
            $row = $statement->fetch();
        } finally {
            $statement->close();
        }
        $version = $row === false ? null : array_change_key_case($row, CASE_LOWER)['server_version'];

        return is_string($version) && stripos($version, 'MariaDB') === false
            && preg_match('/\A(\d+\.\d+\.\d+)/', $version, $matches) === 1
            && version_compare($matches[1], '8.0.4', '>=');
    }

    /**
     * Only ordinary tables with a built-in system type qualify. A view,
     * synonym, alias type, unavailable metadata or older compatibility
     * level retains the native comparison path. Do not infer types from
     * the representation returned by PDO_SQLSRV.
     */
    private function sqlServerPlan(
        array $plan,
        DriverInterface $driver,
        string $prefix,
        array $ids,
        bool $binary,
    ): ?string {
        if ($binary) {
            foreach ($ids as $id) {
                if (!is_string($id) || strlen($id) > 32) {
                    return null;
                }
            }
        } elseif (!ExactRelationLookup::integerKeys($ids, [PHP_INT_MIN, PHP_INT_MAX], true)) {
            return null;
        }

        $table = $driver->getQueryCompiler()->quoteIdentifier($prefix . $plan['keyTable']);
        $statement = $driver->query(
            'SELECT t.name AS type_name, d.compatibility_level'
                . ' FROM sys.columns c JOIN sys.types t ON t.user_type_id = c.user_type_id'
                . ' CROSS JOIN sys.databases d'
                . " WHERE c.object_id = OBJECT_ID(?, 'U') AND c.name = ?"
                . ' AND c.user_type_id = c.system_type_id AND d.database_id = DB_ID()',
            [new Parameter($table), new Parameter($plan['keyColumn'])],
        );
        try {
            $row = $statement->fetch();
        } finally {
            $statement->close();
        }
        if ($row === false) {
            return null;
        }

        $row = array_change_key_case($row, CASE_LOWER);
        $type = $row['type_name'];
        if ($binary) {
            // Binary comparison can treat differently sized values as
            // equal. Retain SQL-side ordinals instead of grouping by bytes.
            return in_array($type, ['binary', 'varbinary'], true)
                && (int) $row['compatibility_level'] >= 130
                ? 'sqlserver-json-binary'
                : null;
        }

        return ExactRelationLookup::integerKeys($ids, match ($type) {
            'tinyint' => [0, 255],
            'smallint' => [-32768, 32767],
            'int' => [-2147483648, 2147483647],
            'bigint' => [PHP_INT_MIN, PHP_INT_MAX],
            default => null,
        }, true) ? 'sqlserver-integer' : null;
    }

    /** @param array<int, Parameter> $bindings */
    private static function sqlServerBinaryQuery(
        SelectQuery $base,
        array $plan,
        array $bindings,
        DriverInterface $driver,
    ): SelectQuery {
        $compiler = $driver->getQueryCompiler();
        $column = $compiler->quoteIdentifier($plan['alias'] . '.' . $plan['keyColumn']);
        $values = array_map(
            static fn (Parameter $parameter): string => bin2hex($parameter->getValue()),
            array_values($bindings),
        );
        $keys = new Fragment(
            'OPENJSON(CAST(? AS nvarchar(max))) AS [_componenta_keys]',
            json_encode($values, JSON_THROW_ON_ERROR),
        );
        $ordinal = 'CONVERT(int, [_componenta_keys].[key]) + ' . array_key_first($bindings);
        return (clone $base)
            ->from([$keys, $plan['table']])
            ->columns([new Fragment($ordinal), ...$plan['columns']])
            ->where(new Fragment($column . ' = CONVERT(varbinary(max), [_componenta_keys].[value], 2)'));
    }

    /**
     * SQL Server uses an empty typed SELECT and constant arms. Modern MySQL uses one JSON
     * parameter for integers and short binary keys; the older-server and
     * text paths use a derived relation of individual bound values.
     *
     * @param array<int, Parameter> $bindings
     * @param array{cast: string, json: ?string}|null $mysql
     */
    private static function serverKeyQuery(
        SelectQuery $base,
        array $plan,
        array $bindings,
        DriverInterface $driver,
        ?string $seed,
        ?array $mysql,
    ): SelectQuery {
        $compiler = $driver->getQueryCompiler();
        $alias = $compiler->quoteIdentifier('_componenta_keys');
        $value = $alias . '.' . $compiler->quoteIdentifier('componenta_value');
        $ordinal = $alias . '.' . $compiler->quoteIdentifier('componenta_ordinal');
        $key = $compiler->quoteIdentifier($plan['alias'] . '.' . $plan['keyColumn']);

        if ($seed !== null) {
            // A scalar subquery in a 500-row VALUES constructor can exhaust
            // SQL Server's query planner for binary keys. A single empty
            // typed SELECT followed by constant arms avoids that expansion.
            $arms = [$seed];
            foreach ($bindings as $index => $parameter) {
                $arms[] = 'SELECT ? AS componenta_value, ' . $index . ' AS componenta_ordinal';
            }
            $keys = new Fragment(
                '(' . implode("\nUNION ALL\n", $arms) . ') AS ' . $alias,
                ...array_values($bindings),
            );
            $ordinalFilter = new Fragment($ordinal . ' >= 0');
        } elseif ($mysql['json'] !== null) {
            $binary = $mysql['json'] === 'binary';
            $values = array_map(
                static fn (Parameter $parameter): int|string => $binary
                    ? bin2hex($parameter->getValue())
                    : $parameter->getValue(),
                array_values($bindings),
            );
            $type = $binary ? 'VARCHAR(512)' : 'BIGINT';
            $keys = new Fragment(
                "JSON_TABLE(?, '$[*]' COLUMNS (componenta_ordinal FOR ORDINALITY,"
                    . " componenta_value $type PATH '$' ERROR ON ERROR)) AS " . $alias,
                json_encode($values, JSON_THROW_ON_ERROR),
            );
            $ordinal = $ordinal . ' - 1 + ' . array_key_first($bindings);
            if ($binary) {
                $value = 'UNHEX(' . $value . ')';
            }
            $ordinalFilter = null;
        } else {
            $arms = [];
            foreach ($bindings as $index => $parameter) {
                $arms[] = 'SELECT ' . $mysql['cast'] . ' AS componenta_value, '
                    . $index . ' AS componenta_ordinal';
            }
            $keys = new Fragment(
                '(' . implode("\nUNION ALL\n", $arms) . ') AS ' . $alias,
                ...array_values($bindings),
            );
            $ordinalFilter = null;
        }

        $query = (clone $base)
            ->from([$keys, $plan['table']])
            ->columns([new Fragment($ordinal), ...$plan['columns']])
            ->where(new Fragment($key . ' = ' . $value));
        if ($ordinalFilter !== null) {
            $query->where($ordinalFilter);
        }

        return $query;
    }
}
