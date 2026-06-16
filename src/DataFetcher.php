<?php

declare(strict_types=1);

namespace Componenta\Cycle;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Cycle\Filter\OrderFilter;
use Componenta\Cycle\Filter\PaginationFilter;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\RequiresTotalCountInterface;
use Componenta\Cycle\Query\SelectableInterface;
use Componenta\Cycle\Query\SortableInterface;
use Componenta\Stdlib\Paginator;
use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;
use Generator;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use WeakMap;

abstract class DataFetcher
{
    /**
     * Fields to exclude from results
     *
     * @var array<int, string>
     */
    protected array $excluded = [];

    /**
     * Enable automatic snake_case to camelCase conversion
     */
    protected bool $autoMapToCamelCase = true;

    /**
     * Explicit field name mapping
     *
     * @var array<string, string> [db_field => result_field]
     */
    protected array $fieldMapping = [];

    /**
     * Field casters
     *
     * @var array<string, string> [field_name => caster_name]
     */
    protected array $casters = [];

    /**
     * Resolved mapper methods
     *
     * @var array<string, string> [field_name => methodName]
     */
    private array $resolvedMappers = [];

    /**
     * Whether the child class overrides loadRelations()
     */
    private bool $usesRelations;

    /**
     * Cached table name
     */
    private ?string $tableName = null;

    /**
     * @var WeakMap<object, array<string, true>>|null
     */
    private ?WeakMap $requestedRelationsCache = null;

    public function __construct(
        protected readonly DatabaseInterface $db,
        protected readonly ?CasterProviderInterface $casterProvider = null,
    ) {
        $this->resolveMapperMethods();
        $this->usesRelations = new ReflectionMethod(static::class, 'loadRelations')
            ->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Resolve table name from class name
     *
     * @return string
     */
    private function resolveTableName(): string
    {
        $className = substr(strrchr(static::class, '\\'), 1);

        // Remove 'Fetcher' suffix if present
        if (str_ends_with($className, 'Fetcher')) {
            $className = substr($className, 0, -7);
        }

        // Convert PascalCase to snake_case
        $snakeCase = $this->camelToSnake($className);

        // Pluralize
        return $this->pluralize($snakeCase);
    }

    /**
     * Resolve all mapper methods once
     *
     * Scans class for map* methods and builds field name -> method name mapping
     */
    private function resolveMapperMethods(): void
    {
        $methods = get_class_methods($this);

        foreach ($methods as $methodName) {
            if (str_starts_with($methodName, 'map') && $methodName !== 'mapField') {
                $fieldName = lcfirst(substr($methodName, 3));
                $this->resolvedMappers[$this->camelToSnake($fieldName)] = $methodName;
            }
        }
    }

    /**
     * Fetch and transform single row
     *
     * @param array $row
     * @param object|null $query
     * @return array|object
     */
    protected function fetchRow(array $row, ?object $query): array|object
    {
        $data = $this->transformData($row, $query);

        foreach ($this->excluded as $excluded) {
            unset($data[$excluded]);
        }

        return $data;
    }

    /**
     * Transform raw database row
     *
     * Processing order per field:
     * 1. Apply explicit field mapping from $fieldMapping
     * 2. Apply automatic snake_case to camelCase conversion (if enabled)
     * 3. Apply caster (if registered)
     * 4. Apply map{FieldName} method (if exists)
     *
     * @param array $data
     * @param object|null $query
     * @return array
     */
    protected function transformData(array $data, ?object $query): array
    {
        $transformed = [];

        foreach ($data as $key => $value) {
            $mappedKey = $this->getMappedFieldName($key);
            $transformed[$mappedKey] = $this->mapField($key, $value, $data, $query);
        }

        return $transformed;
    }

    /**
     * Get mapped field name
     *
     * Priority:
     * 1. Explicit mapping from $fieldMapping
     * 2. Automatic snake_case to camelCase (if enabled)
     * 3. Original field name
     *
     * @param string $originalKey Original field name from database
     * @return string Mapped field name
     */
    protected function getMappedFieldName(string $originalKey): string
    {
        if (isset($this->fieldMapping[$originalKey])) {
            return $this->fieldMapping[$originalKey];
        }

        return $this->autoMapToCamelCase
            ? $this->snakeToCamel($originalKey)
            : $originalKey;
    }

    /**
     * Map individual field value
     *
     * Processing order:
     * 1. Apply caster (if registered)
     * 2. Apply map{FieldName} method (if exists)
     *
     * @param string $originalKey Original field name from database
     * @param mixed $value Field value
     * @param array $data Complete row data from database
     * @param object|null $query Query object
     * @return mixed
     * @throws RuntimeException If caster is registered but cannot be resolved
     */
    protected function mapField(string $originalKey, mixed $value, array $data, ?object $query): mixed
    {
        // Apply caster first
        if (isset($this->casters[$originalKey])) {
            if ($this->casterProvider === null) {
                throw new RuntimeException(
                    sprintf(
                        'Caster "%s" is registered for field "%s", but CasterProvider is not provided',
                        $this->casters[$originalKey],
                        $originalKey
                    )
                );
            }

            $caster = $this->casterProvider->provide($this->casters[$originalKey]);

            if ($caster === null) {
                throw new RuntimeException(
                    sprintf(
                        'Caster "%s" for field "%s" cannot be resolved from CasterProvider',
                        $this->casters[$originalKey],
                        $originalKey
                    )
                );
            }

            $value = $caster->cast($value);
        }

        // Then apply mapper method
        if (isset($this->resolvedMappers[$originalKey])) {
            return $this->{$this->resolvedMappers[$originalKey]}($value, $data, $query);
        }

        return $value;
    }

    /**
     * Convert snake_case to camelCase
     *
     * @param string $string
     * @return string
     */
    protected function snakeToCamel(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }

    /**
     * Convert camelCase to snake_case
     *
     * @param string $string
     * @return string
     */
    protected function camelToSnake(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }

    /**
     * Pluralize a singular word
     *
     * @param string $word
     * @return string
     */
    protected function pluralize(string $word): string
    {
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }

        return $word . 's';
    }

    /**
     * Build select query
     *
     * @param object|null $query
     * @return SelectQuery
     */
    protected function select(?object $query): SelectQuery
    {
        $select = $this->db
            ->select($this->getColumns($query))
            ->from($this->getTableName());

        return $this->apply($select, $query);
    }

    /**
     * Fetch and transform results
     *
     * Pipeline:
     * 1. Transform each row via fetchRow()
     * 2. Batch-load relations via loadRelations()
     * 3. Wrap in Paginator if applicable
     *
     * @param iterable $rows
     * @param int|null $count
     * @param object|null $query
     * @return iterable|Paginator
     */
    protected function fetchResults(iterable $rows, ?int $count, ?object $query): iterable|Paginator
    {
        $results = [];
        $rawRows = [];

        foreach ($rows as $row) {
            if ($this->usesRelations) {
                $rawRows[] = $row;
            }
            $results[] = $this->fetchRow($row, $query);
        }

        if ($this->usesRelations) {
            $this->loadRelations($results, $rawRows, $query);
        }

        if ($query instanceof PaginableInterface) {
            return new Paginator($results, $query->limit, $query->offset, $count);
        }

        return $results;
    }

    /**
     * Execute fetch operation
     *
     * @param object|null $query
     * @return mixed Returns iterable|Paginator by default, but can be overridden by child classes
     */
    protected function doFetch(?object $query = null): mixed
    {
        $select = $this->select($query);

        if (!$query instanceof PaginableInterface) {
            return $this->fetchResults($select, null, $query);
        }

        if (!$query instanceof RequiresTotalCountInterface) {
            return $this->fetchResultsWithoutTotalCount($select, $query);
        }

        // Joins (typically added by filters like Tag/Category) can cause
        // row duplication that `COUNT(*) OVER()` would see as extra rows.
        // MySQL and SQLite don't support `COUNT(DISTINCT x) OVER()` as a
        // portable one-liner, so we fall back to the two-query path whose
        // separate `COUNT(DISTINCT)` is semantically correct.
        if ($this->hasJoins($select)) {
            $count  = $this->countRows($select);
            $offset = $query->offset ?? 0;

            if ($offset > $count) {
                return new Paginator([], $query->limit, $query->offset, $count);
            }

            return $this->fetchResults($select, $count, $query);
        }

        return $this->fetchResultsWithWindowCount($select, $query);
    }

    /**
     * Fast pagination for infinite-scroll style reads.
     *
     * The select has already been limited to limit+1 by getFilters(). The
     * extra row is used only to compute an exact next-page flag and is never
     * exposed to callers or relation loaders.
     */
    private function fetchResultsWithoutTotalCount(SelectQuery $select, PaginableInterface $query): Paginator
    {
        $results = [];
        $rawRows = [];
        $hasNextPage = false;

        foreach ($select as $row) {
            if (count($results) >= $query->limit) {
                $hasNextPage = true;
                break;
            }

            if ($this->usesRelations) {
                $rawRows[] = $row;
            }
            $results[] = $this->fetchRow($row, $query);
        }

        if ($this->usesRelations) {
            $this->loadRelations($results, $rawRows, $query);
        }

        return new Paginator($results, $query->limit, $query->offset, null, $hasNextPage);
    }

    /**
     * Single-query pagination: rides `COUNT(*) OVER()` along with the main
     * SELECT. The total is read from the first result row; the extra
     * column is stripped before downstream transformation so consumers
     * never see it. If the result is empty (offset beyond last page, or
     * a filter that matches nothing), a separate count query runs against
     * the pre-window clone to preserve pagination metadata.
     */
    private function fetchResultsWithWindowCount(SelectQuery $select, PaginableInterface $query): Paginator
    {
        $cleanSelect = clone $select;

        $select->columns([
            ...$select->getColumns(),
            new Fragment('COUNT(*) OVER() AS _row_count'),
        ]);

        $results = [];
        $rawRows = [];
        $count   = null;

        foreach ($select as $row) {
            if ($count === null) {
                $count = (int) ($row['_row_count'] ?? 0);
            }
            unset($row['_row_count']);

            if ($this->usesRelations) {
                $rawRows[] = $row;
            }
            $results[] = $this->fetchRow($row, $query);
        }

        // No rows means the window column yielded nothing. For a first-page request
        // (offset 0) the main SELECT empty means the filter truly matches
        // nothing, so count is 0 and no extra query is needed. For an overshoot
        // offset the underlying set may still have rows, so we run the
        // conventional count to keep the paginator metadata honest.
        if ($count === null) {
            $offset = $query->offset ?? 0;
            $count  = $offset > 0 ? $this->countRows($cleanSelect) : 0;
        }

        if ($this->usesRelations) {
            $this->loadRelations($results, $rawRows, $query);
        }

        return new Paginator($results, $query->limit, $query->offset, $count);
    }

    /**
     * Cached reflection: `joinTokens` is protected on `JoinTrait` inside
     * Cycle DBAL. `false` marks "unavailable" (reflection failed, typically
     * a Cycle upgrade that renamed the property) so we don't retry it on
     * every call.
     */
    private static ReflectionProperty|false|null $joinTokensProperty = null;

    /**
     * Best-effort detection of join clauses so single-query pagination
     * can avoid duplicate rows. On any reflection failure, meaning the Cycle
     * internals changed and the property is gone, we assume joins are
     * present (conservative: forces the two-query path) rather than
     * silently producing an over-counted window result.
     */
    private function hasJoins(SelectQuery $select): bool
    {
        if (self::$joinTokensProperty === null) {
            try {
                self::$joinTokensProperty = new ReflectionProperty(SelectQuery::class, 'joinTokens');
            } catch (\ReflectionException) {
                self::$joinTokensProperty = false;
            }
        }

        if (self::$joinTokensProperty === false) {
            return true;
        }

        try {
            return self::$joinTokensProperty->getValue($select) !== [];
        } catch (\ReflectionException) {
            return true;
        }
    }

    /**
     * Apply filters and modifications to select query
     *
     * @param SelectQuery $select
     * @param object|null $query
     * @return SelectQuery
     */
    protected function apply(SelectQuery $select, ?object $query): SelectQuery
    {
        if ($query !== null) {
            foreach ($this->getFilters($query) as $apply) {
                $select = $apply->apply($select);
            }
        }

        return $select;
    }

    /**
     * Count total rows for pagination
     *
     * @param SelectQuery $select
     * @return int
     */
    protected function countRows(SelectQuery $select): int
    {
        return (clone $select)
            ->limit(null)
            ->offset(null)
            ->distinct()
            ->count();
    }

    /**
     * Load batch relations after all rows have been transformed.
     *
     * Override in child classes to load related data efficiently using batch helpers.
     * Called once per fetch operation with all rows, eliminating N+1 queries.
     *
     * @param array<int, array|object> $results Transformed result rows (by reference)
     * @param array<int, array>        $rows    Raw database rows (parallel index to $results)
     * @param object|null              $query   Query object
     */
    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        // No-op by default; override in child classes.
    }

    /**
     * Check if a specific relation is requested via $query->with.
     *
     * Expects $query->with to be a comma-separated string (e.g. "tags,category").
     */
    protected function wantsRelation(?object $query, string $name): bool
    {
        return isset($this->requestedRelations($query)[$name]);
    }

    /**
     * Returns requested relation names as a set parsed from $query->with.
     *
     * @return array<string, true>
     */
    protected function requestedRelations(?object $query): array
    {
        if ($query === null || !property_exists($query, 'with')) {
            return [];
        }

        $this->requestedRelationsCache ??= new WeakMap();

        if (isset($this->requestedRelationsCache[$query])) {
            return $this->requestedRelationsCache[$query];
        }

        $with = $query->with;

        if ($with === null || $with === '') {
            return $this->requestedRelationsCache[$query] = [];
        }

        $relations = is_array($with)
            ? $with
            : explode(',', (string) $with);

        $set = [];
        foreach ($relations as $relation) {
            $relation = trim((string) $relation);
            if ($relation !== '') {
                $set[$relation] = true;
            }
        }

        return $this->requestedRelationsCache[$query] = $set;
    }

    /**
     * Batch load belongsTo relation (N->1).
     *
     * Collects unique foreign key values from raw rows, executes a single
     * WHERE IN query, and attaches the related data to each result.
     *
     * @param array<int, array>  $results    Transformed results (by reference)
     * @param array<int, array>  $rows       Raw database rows
     * @param string             $foreignKey Column name in raw rows (e.g. 'user_id')
     * @param string             $table      Related table name (e.g. 'users')
     * @param array              $columns    Columns to select from related table
     * @param string|null        $resultKey  Key in result array (default: foreignKey without '_id' suffix)
     * @param string             $tableKey   Primary key column in related table
     * @param Closure|null       $transform  Transform function for each related row
     */
    protected function batchBelongsTo(
        array &$results,
        array $rows,
        string $foreignKey,
        string $table,
        array $columns = ['*'],
        ?string $resultKey = null,
        string $tableKey = 'id',
        ?Closure $transform = null,
    ): void {
        $key = $resultKey ?? $this->snakeToCamel(
            str_ends_with($foreignKey, '_id')
                ? substr($foreignKey, 0, -3)
                : $foreignKey
        );

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

        $relatedRows = $this->db
            ->select($selectColumns)
            ->from($table)
            ->where($tableKey, 'IN', new Parameter($ids))
            ->fetchAll();

        // Index by table key
        $indexed = [];
        foreach ($relatedRows as $row) {
            $indexed[$row[$tableKey]] = $row;
        }

        foreach ($results as $i => &$result) {
            $fk = $rows[$i][$foreignKey] ?? null;
            $related = $fk !== null ? ($indexed[$fk] ?? null) : null;
            $result[$key] = $related !== null && $transform !== null
                ? $transform($related)
                : $related;
        }
    }

    /**
     * Batch load hasMany relation (1->N).
     *
     * Collects unique local key values, executes a single WHERE IN query
     * on the related table, groups results, and attaches arrays to each result.
     *
     * @param array<int, array>  $results    Transformed results (by reference)
     * @param array<int, array>  $rows       Raw database rows
     * @param string             $resultKey  Key in result array to attach relation
     * @param string             $table      Related table name
     * @param string             $foreignKey FK column in related table (e.g. 'post_id')
     * @param string             $localKey   Local column in raw rows (default: 'id')
     * @param array              $columns    Columns to select from related table
     * @param Closure|null       $transform  Transform function for each related row
     */
    protected function batchHasMany(
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

        $ids = array_values(array_unique(array_column($rows, $localKey)));

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

        $relatedRows = $this->db
            ->select($selectColumns)
            ->from($table)
            ->where($foreignKey, 'IN', new Parameter($ids))
            ->fetchAll();

        // Group by foreign key
        $grouped = [];
        foreach ($relatedRows as $row) {
            $grouped[$row[$foreignKey]][] = $transform !== null ? $transform($row) : $row;
        }

        foreach ($results as $i => &$result) {
            $localId = $rows[$i][$localKey] ?? null;
            $result[$resultKey] = $localId !== null ? ($grouped[$localId] ?? []) : [];
        }
    }

    /**
     * Batch load belongsToMany relation (N->N via pivot table).
     *
     * Executes a single JOIN query between the related table and pivot table,
     * groups results by the local key, and attaches arrays to each result.
     *
     * @param array<int, array>  $results         Transformed results (by reference)
     * @param array<int, array>  $rows            Raw database rows
     * @param string             $resultKey       Key in result array to attach relation
     * @param string             $relatedTable    Related entity table (e.g. 'tags')
     * @param string             $pivotTable      Pivot table name (e.g. 'post_tags')
     * @param string             $pivotLocalKey   FK in pivot pointing to local entity (e.g. 'post_id')
     * @param string             $pivotForeignKey FK in pivot pointing to related entity (e.g. 'tag_id')
     * @param string             $localKey        Local PK column in raw rows (default: 'id')
     * @param string             $relatedKey      Related PK column (default: 'id')
     * @param array              $columns         Columns to select from related table
     * @param Closure|null       $transform       Transform function for each related row
     */
    protected function batchBelongsToMany(
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

        $ids = array_values(array_unique(array_column($rows, $localKey)));

        if ($ids === []) {
            foreach ($results as &$result) {
                $result[$resultKey] = [];
            }
            return;
        }

        // Build select columns with table alias prefix
        $selectColumns = $columns === ['*']
            ? ['r.*']
            : array_map(static fn(string $c) => "r.$c", $columns);
        $pivotLocalKeyAlias = '_pivot_local_key';
        $selectColumns[] = "p.$pivotLocalKey AS $pivotLocalKeyAlias";

        $relatedRows = $this->db
            ->select($selectColumns)
            ->from("$relatedTable AS r")
            ->innerJoin($pivotTable, 'p')
            ->on("p.$pivotForeignKey", "r.$relatedKey")
            ->where("p.$pivotLocalKey", 'IN', new Parameter($ids))
            ->fetchAll();

        // Group by local key
        $grouped = [];
        foreach ($relatedRows as $row) {
            $localId = $row[$pivotLocalKeyAlias];
            unset($row[$pivotLocalKeyAlias]);
            $grouped[$localId][] = $transform !== null ? $transform($row) : $row;
        }

        foreach ($results as $i => &$result) {
            $localId = $rows[$i][$localKey] ?? null;
            $result[$resultKey] = $localId !== null ? ($grouped[$localId] ?? []) : [];
        }
    }

    /**
     * Get apply strategies for query
     *
     * @param object|null $query
     * @return Generator<int, FilterInterface>
     */
    protected function getFilters(?object $query): iterable
    {
        if ($query instanceof PaginableInterface) {
            $limit = $query instanceof RequiresTotalCountInterface
                ? $query->limit
                : $query->limit + 1;

            yield new PaginationFilter($limit, $query->offset);
        }

        if ($query instanceof SortableInterface) {
            $filter = OrderFilter::fromSortable($query);
            if ($filter !== null) {
                yield $filter;
            }
        }
    }

    /**
     * Get columns to select
     *
     * @param object|null $query
     * @return array
     */
    protected function getColumns(?object $query): array
    {
        if ($query instanceof SelectableInterface && $query->columns !== null) {
            return $query->columns;
        }

        return ['*'];
    }

    /**
     * Get database table name
     *
     * Default implementation derives table name from class name:
     * - ListingFetcher -> listings
     * - UserFetcher -> users
     * - ProductCategoryFetcher -> product_categories
     *
     * @return string
     */
    protected function getTableName(): string
    {
        if ($this->tableName === null) {
            $this->tableName = $this->resolveTableName();
        }

        return $this->tableName;
    }
}
