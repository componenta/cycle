<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\Filter\BetweenFilter;
use Componenta\Cycle\Filter\BooleanFilter;
use Componenta\Cycle\Filter\ComparisonFilter;
use Componenta\Cycle\Filter\DateRangeFilter;
use Componenta\Cycle\Filter\Direction;
use Componenta\Cycle\Filter\InFilter;
use Componenta\Cycle\Filter\LikeFilter;
use Componenta\Cycle\Filter\LikeMode;
use Componenta\Cycle\Filter\LimitFilter;
use Componenta\Cycle\Filter\NotInFilter;
use Componenta\Cycle\Filter\NotNullFilter;
use Componenta\Cycle\Filter\NullFilter;
use Componenta\Cycle\Filter\OffsetFilter;
use Componenta\Cycle\Filter\OrderFilter;
use Componenta\Cycle\Filter\PaginationFilter;
use Componenta\Cycle\Filter\SoftDeleteFilter;
use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\SortableInterface;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;
use DateTimeImmutable;

function predicateFilterDatabase(): DatabaseInterface
{
    $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
    foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $settings['DB_' . $key] = $value;
        }
    }

    $composition = (new ConfigFactory())->create(new Environment($settings), new ConfigProvider());

    return (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
}

function predicateFilterQuery(DatabaseInterface $db): SelectQuery
{
    $boolean = match ($db->getType()) {
        'Postgres' => ['TRUE', 'FALSE', 'CAST(NULL AS BOOLEAN)'],
        'SQLServer' => ['CAST(1 AS BIT)', 'CAST(0 AS BIT)', 'CAST(NULL AS BIT)'],
        default => ['1', '0', 'NULL'],
    };
    [$yes, $no, $unknown] = $boolean;
    $dateType = match ($db->getType()) {
        'Postgres' => 'TIMESTAMP',
        'SQLServer' => 'DATETIME2',
        'MySQL' => 'DATETIME',
        default => 'TEXT',
    };

    return $db->select('id')->from(new Fragment(
        "(SELECT 1 AS id, 10 AS amount, 'alpha' AS label, $yes AS active, "
        . "CAST('2025-01-01 00:00:00' AS $dateType) AS created_at, CAST(NULL AS $dateType) AS deleted_at "
        . "UNION ALL SELECT 2, 20, 'alphabet', $no, CAST('2025-01-02 00:00:00' AS $dateType), CAST('2025-01-03 00:00:00' AS $dateType) "
        . "UNION ALL SELECT 3, 30, 'beta', $yes, CAST('2025-01-03 00:00:00' AS $dateType), NULL "
        . "UNION ALL SELECT 4, NULL, NULL, $unknown, CAST(NULL AS $dateType), NULL) AS records",
    ));
}

it('returns the correct rows for each public predicate filter', function (FilterInterface $filter, array $expected): void {
    $query = predicateFilterQuery(predicateFilterDatabase())->orderBy('id');

    $rows = $filter->apply($query)->fetchAll();

    expect(array_map('intval', array_column($rows, 'id')))->toBe($expected);
})->with(static function (): iterable {
    yield 'inclusive between' => [new BetweenFilter('amount', 10, 20), [1, 2]];
    yield 'equal' => [new ComparisonFilter('amount', 20), [2]];
    yield 'not equal' => [new ComparisonFilter('amount', 20, '!='), [1, 3]];
    yield 'alternative not equal' => [new ComparisonFilter('amount', 20, '<>'), [1, 3]];
    yield 'less' => [new ComparisonFilter('amount', 20, '<'), [1]];
    yield 'less or equal' => [new ComparisonFilter('amount', 20, '<='), [1, 2]];
    yield 'greater' => [new ComparisonFilter('amount', 20, '>'), [3]];
    yield 'greater or equal' => [new ComparisonFilter('amount', 20, '>='), [2, 3]];
    yield 'boolean true' => [new BooleanFilter('active', true), [1, 3]];
    yield 'boolean false' => [new BooleanFilter('active', false), [2]];
    yield 'in' => [new InFilter('amount', [10, 30]), [1, 3]];
    yield 'not in' => [new NotInFilter('amount', [10, 30]), [2]];
    yield 'in with explicit parameter' => [new InFilter('amount', [new \Cycle\Database\Injection\Parameter(10, \PDO::PARAM_INT), 30]), [1, 3]];
    yield 'in with null' => [new InFilter('amount', [10, null]), [1]];
    yield 'not in with null' => [new NotInFilter('amount', [10, null]), []];
    yield 'in with mixed bound expression' => [
        new InFilter('amount', [10, new Fragment('CAST(? AS DECIMAL(10,0)) + CAST(? AS DECIMAL(10,0))', 15, 15)]),
        [1, 3],
    ];
    yield 'not in with mixed bound expression' => [
        new NotInFilter('amount', [10, new Fragment('CAST(? AS DECIMAL(10,0)) + CAST(? AS DECIMAL(10,0))', 15, 15)]),
        [2],
    ];
    yield 'null' => [new NullFilter('amount'), [4]];
    yield 'not null' => [new NotNullFilter('amount'), [1, 2, 3]];
    yield 'active records' => [new SoftDeleteFilter('deleted_at'), [1, 3, 4]];
    yield 'deleted records' => [new SoftDeleteFilter('deleted_at', false), [2]];
    yield 'like exact' => [new LikeFilter('label', 'alpha', LikeMode::Exact), [1]];
    yield 'like prefix' => [new LikeFilter('label', 'alpha', LikeMode::StartsWith), [1, 2]];
    yield 'like suffix' => [new LikeFilter('label', 'beta', LikeMode::EndsWith), [3]];
    yield 'like contains' => [new LikeFilter('label', 'pha'), [1, 2]];
    yield 'bound search text' => [new LikeFilter('label', "' OR 1=1 --", LikeMode::Exact), []];
    yield 'date range' => [
        new DateRangeFilter('created_at', new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-02')),
        [1, 2],
    ];
    yield 'date range from' => [new DateRangeFilter('created_at', new DateTimeImmutable('2025-01-02')), [2, 3]];
    yield 'date range to' => [new DateRangeFilter('created_at', to: new DateTimeImmutable('2025-01-02')), [1, 2]];
});

it('applies pagination and ordering filters to real query results', function (array $filters, array $expected): void {
    $query = predicateFilterQuery(predicateFilterDatabase());
    foreach ($filters as $filter) {
        $query = $filter->apply($query);
    }

    expect(array_map('intval', array_column($query->fetchAll(), 'id')))->toBe($expected);
})->with(static function (): iterable {
    $asc = new OrderFilter(['id' => Direction::ASC]);
    $desc = new OrderFilter(['id' => Direction::DESC]);
    yield 'ascending' => [[$asc], [1, 2, 3, 4]];
    yield 'descending' => [[$desc], [4, 3, 2, 1]];
    yield 'limit' => [[$asc, new LimitFilter(2)], [1, 2]];
    yield 'offset' => [[$asc, new OffsetFilter(2)], [3, 4]];
    yield 'pagination' => [[$desc, new PaginationFilter(2, 1)], [3, 2]];
    yield 'past the end' => [[$asc, new PaginationFilter(2, 4)], []];
    yield 'pagination factory' => [[$asc, PaginationFilter::fromPaginable(new class implements PaginableInterface {
        public int $limit = 2;
        public int $offset = 1;
    })], [2, 3]];
    yield 'ordering factory' => [[OrderFilter::fromSortable(new class implements SortableInterface {
        public ?array $orderBy = ['id' => Direction::DESC];
    })], [4, 3, 2, 1]];
});

it('rejects unsupported inputs at the filter constructor boundary', function (string $filter, array $arguments): void {
    expect(fn () => new $filter(...$arguments))->toThrow(\InvalidArgumentException::class);
})->with([
    'invalid comparison operator' => [ComparisonFilter::class, ['amount', 10, 'OR']],
    'non-scalar comparison value' => [ComparisonFilter::class, ['amount', [10]]],
    'empty in' => [InFilter::class, ['amount', []]],
    'empty not in' => [NotInFilter::class, ['amount', []]],
    'empty pattern' => [LikeFilter::class, ['label', '']],
    'no date boundaries' => [DateRangeFilter::class, ['created_at']],
    'negative offset' => [OffsetFilter::class, [-1]],
    'invalid sort direction' => [OrderFilter::class, [['id' => 'DESC']]],
    'unnamed sort column' => [OrderFilter::class, [[Direction::ASC]]],
]);
