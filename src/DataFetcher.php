<?php

declare(strict_types=1);

namespace Componenta\Cycle;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Cycle\Filter\OrderFilter;
use Componenta\Cycle\Filter\PaginationFilter;
use Componenta\Cycle\Internal\PaginationExecutor;
use Componenta\Cycle\Internal\PaginationOrder;
use Componenta\Cycle\Internal\ProjectionColumns;
use Componenta\Cycle\Internal\ResultReader;
use Componenta\Cycle\Internal\RelationLoader;
use Componenta\Cycle\Internal\RowMapper;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\SelectableInterface;
use Componenta\Cycle\Query\SortableInterface;
use Componenta\Stdlib\Paginator;
use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\SelectQuery;
use Generator;
use ReflectionMethod;
use RuntimeException;

abstract class DataFetcher
{
    private ?RelationLoader $relationLoader = null;

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

    private readonly RowMapper $rowMapper;

    /**
     * Whether the child class overrides loadRelations()
     */
    private bool $usesRelations;

    /**
     * Cached table name
     */
    private ?string $tableName = null;

    private readonly PaginationExecutor $paginationExecutor;
    private readonly bool $overridesFetchResults;

    public function __construct(
        protected readonly DatabaseInterface $db,
        protected readonly ?CasterProviderInterface $casterProvider = null,
    ) {
        $this->rowMapper = new RowMapper(
            $casterProvider,
            get_class_methods($this),
            $this->camelToSnake(...),
        );
        $this->paginationExecutor = new PaginationExecutor();
        $this->overridesFetchResults = new ReflectionMethod(static::class, 'fetchResults')
            ->getDeclaringClass()->getName() !== self::class;
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
        $separator = strrpos(static::class, '\\');
        $className = $separator === false ? static::class : substr(static::class, $separator + 1);

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
        return $this->rowMapper->transform(
            $data,
            $query,
            $this->getMappedFieldName(...),
            $this->mapField(...),
        );
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
        if (isset($this->casters[$originalKey])) {
            $value = $this->rowMapper->cast($originalKey, $this->casters[$originalKey], $value);
        }

        $mapper = $this->rowMapper->mapperFor($originalKey);

        return $mapper === null ? $value : $this->{$mapper}($value, $data, $query);
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
        if ($rows instanceof SelectQuery) {
            $rows = PaginationOrder::forPage($rows);
        }

        $hint = $this->paginationExecutor->hint($rows, $count, $query, $this);
        ['results' => $results, 'rawRows' => $rawRows] = ResultReader::read(
            $rows,
            $query,
            $this->fetchRow(...),
            $this->usesRelations,
        );

        if ($this->usesRelations) {
            $this->loadRelations($results, $rawRows, $query);
        }

        return $this->paginationExecutor->result($results, $query, $count, $hint);
    }

    /**
     * Execute fetch operation
     *
     * @param object|null $query
     * @return mixed Returns iterable|Paginator by default, but can be overridden by child classes
     */
    protected function doFetch(?object $query = null): mixed
    {
        return $this->paginationExecutor->execute(
            $this->select($query),
            $query,
            $this->fetchResults(...),
            $this->countRows(...),
            $this->overridesFetchResults ? $this : null,
        );
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
        return $this->paginationExecutor->count($select);
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

        $with = $query->with;

        if ($with === null || $with === '') {
            return [];
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

        return $set;
    }

    /**
     * Batch load belongsTo relation (N->1).
     *
     * Collects unique foreign key values from raw rows, executes bounded
     * WHERE IN queries, and attaches the related data to each result.
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

        $this->relationLoader()->belongsTo($results, $rows, $foreignKey, $table, $columns, $key, $tableKey, $transform);
    }

    /**
     * Batch load hasMany relation (1->N).
     *
     * Collects unique local key values, executes bounded WHERE IN queries
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
        $this->relationLoader()->hasMany($results, $rows, $resultKey, $table, $foreignKey, $localKey, $columns, $transform);
    }

    /**
     * Batch load belongsToMany relation (N->N via pivot table).
     *
     * Executes bounded JOIN queries between the related table and pivot table,
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
        $this->relationLoader()->belongsToMany(
            $results, $rows, $resultKey, $relatedTable, $pivotTable, $pivotLocalKey,
            $pivotForeignKey, $localKey, $relatedKey, $columns, $transform,
        );
    }

    private function relationLoader(): RelationLoader
    {
        return $this->relationLoader ??= new RelationLoader($this->db);
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
            yield new PaginationFilter($query->limit, $query->offset);
        }

        if ($query instanceof SortableInterface && $query->orderBy !== null) {
            yield new OrderFilter(ProjectionColumns::order($query->orderBy, $this->sortableColumns()));
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
        $allowed = $this->selectableColumns();

        return ProjectionColumns::select(
            $query instanceof SelectableInterface ? $query->columns : null,
            $allowed,
        );
    }

    /**
     * Public field names mapped to trusted database columns. Null retains the
     * infrastructure-only contract for trusted query DTOs.
     *
     * @return array<string, string>|null
     */
    protected function selectableColumns(): ?array
    {
        return null;
    }

    /** @return array<string, string>|null */
    protected function sortableColumns(): ?array
    {
        return null;
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
