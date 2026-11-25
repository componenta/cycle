<?php
declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\DataFetcher;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\RequiresTotalCountInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\Paginator;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;
use PHPUnit\Framework\Assert;

function dataFetcherPaginationDriverRows(DatabaseInterface $db, array $rows): array
{
    if ($db->getType() !== 'SQLServer') {
        return $rows;
    }

    return array_map(
        static fn (array $row): array => array_map(
            static fn (mixed $value): mixed => is_int($value) ? (string) $value : $value,
            $row,
        ),
        $rows,
    );
}

function dataFetcherPaginationDatabase(): DatabaseInterface
{
    $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
    foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $settings['DB_' . $key] = $value;
        }
    }
    $composition = (new ConfigFactory())->create(
        new Environment($settings),
        new \Componenta\Cycle\ConfigProvider(),
    );
    return (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
}

it('result transformations are preserved with and without total', function (int $offset, array $expected, bool $hasNext, bool $materialize): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'))->orderBy('id');
    $fetcher = new ResultTransformingPaginationFetcher($db, $source, $materialize);

    foreach ([new CountedPage(1, $offset), new Page(1, $offset), new CountedPage(1, $offset)] as $query) {
        $page = $fetcher($query);
        Assert::assertSame($expected, $page->results);
        Assert::assertSame($hasNext, $page->hasNextPage);
        Assert::assertSame($query instanceof CountedPage ? 2 : null, $page->totalCount);
    }
})->with(static function (): iterable {
    foreach ([false, true] as $materialize) {
        $prefix = $materialize ? 'materialized ' : 'query ';
        yield $prefix . 'first page' => [0, [['label' => 'item 1']], true, $materialize];
        yield $prefix . 'last full page' => [1, [['label' => 'item 2']], false, $materialize];
        yield $prefix . 'past the end' => [2, [], false, $materialize];
    }
});

it('nested fetches preserve outer pagination', function (bool $throw): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'))->orderBy('id');
    $fetcher = new ResultTransformingPaginationFetcher($db, $source, true);
    $outerQuery = new Page(1, 1);
    $failure = new \RuntimeException('nested result failed');
    $fetcher->beforeResults = static function (object $query) use ($fetcher, $outerQuery, $failure, $throw): void {
        if ($query !== $outerQuery) {
            if ($throw) {
                throw $failure;
            }
            return;
        }
        try {
            $nested = $fetcher(new Page(1, 0));
            expect($throw)->toBeFalse();
            Assert::assertSame([['label' => 'item 1']], $nested->results);
            expect($nested->hasNextPage)->toBeTrue();
        } catch (\RuntimeException $caught) {
            expect($throw)->toBeTrue();
            Assert::assertSame($failure, $caught);
        }
    };

    $page = $fetcher($outerQuery);
    Assert::assertSame([['label' => 'item 2']], $page->results);
    expect($page->hasNextPage)->toBeFalse();
    $fetcher->beforeResults = null;
    Assert::assertTrue($fetcher(new Page(1, 0))->hasNextPage);
    expect($fetcher($outerQuery)->hasNextPage)->toBeFalse();
})->with(static function (): iterable {
    yield 'successful nested call' => [false];
    yield 'failing nested call' => [true];
});

it('concurrent fetches keep their own pagination', function (): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'))->orderBy('id');
    $fetcher = new ResultTransformingPaginationFetcher($db, $source, true);
    $fetcher->beforeResults = static function (): void { \Fiber::suspend(); };
    $last = new \Fiber(static fn (): Paginator => $fetcher(new Page(1, 1)));
    $first = new \Fiber(static fn (): Paginator => $fetcher(new Page(1, 0)));

    $last->start();
    $first->start();
    $last->resume();
    $first->resume();

    Assert::assertSame([['label' => 'item 2']], $last->getReturn()->results);
    expect($last->getReturn()->hasNextPage)->toBeFalse();
    Assert::assertSame([['label' => 'item 1']], $first->getReturn()->results);
    expect($first->getReturn()->hasNextPage)->toBeTrue();
});

it('cloned fetcher does not inherit an active pagination hint in the same fiber', function (): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2 AS id) AS items'))->orderBy('id');
    $single = $db->select('id')->from(new Fragment('(SELECT 9 AS id) AS single_item'))->limit(1)->offset(0);
    $fetcher = new ResultTransformingPaginationFetcher($db, $source, true);
    $query = new Page(1);

    $expected = dataFetcherPaginationDriverRows($db, [['id' => 9]]);
    $fetcher->beforeResults = static function (object $current) use ($fetcher, $single, $query, $expected): void {
        Assert::assertSame($query, $current);
        $cloned = clone $fetcher;
        $page = $cloned->fetchDirect($single, $query);
        Assert::assertSame($expected, $page->results);
        expect($page->hasNextPage)->toBeFalse();
    };

    $fiber = new \Fiber(static fn (): Paginator => $fetcher($query));
    $fiber->start();
    $page = $fiber->getReturn();

    Assert::assertSame([['label' => 'item 1']], $page->results);
    expect($page->hasNextPage)->toBeTrue();
});

it('total count counts distinct result rows', function (bool $join, int $limit, int $offset, array $expected): void {
    $db = dataFetcherPaginationDatabase();
    $select = $db->select('category')->from(new Fragment(
        "(SELECT 'one' AS category UNION ALL SELECT 'one' UNION ALL SELECT 'two') AS items",
    ))->distinct()->orderBy('category');
    if ($join) {
        $select->innerJoin($db->select('flag')->from(new Fragment('(SELECT 1 AS flag) AS marker')), 'marker')
            ->on('marker.flag', '=', 'marker.flag');
    }
    $fetcher = new PaginationFetcher($db, $select);

    $page = $fetcher(new CountedPage($limit, $offset));
    Assert::assertSame($expected, $page->results);
    Assert::assertSame(2, $page->totalCount);
})->with(static function (): iterable {
    yield 'all distinct rows' => [false, 10, 0, [['category' => 'one'], ['category' => 'two']]];
    yield 'with a join' => [true, 10, 0, [['category' => 'one'], ['category' => 'two']]];
    yield 'second page' => [false, 1, 1, [['category' => 'two']]];
    yield 'past the end' => [false, 1, 4, []];
});

it('total count preserves grouping having and bound parameters', function (): void {
    $db = dataFetcherPaginationDatabase();
    $select = $db->select('category', new Fragment('COUNT(*) AS quantity'))->from(new Fragment(
        "(SELECT 'one' AS category UNION ALL SELECT 'one' UNION ALL SELECT 'two') AS items",
    ))->where('category', '!=', 'excluded')->groupBy('category')->having(new Fragment('COUNT(*)'), '>', 1);
    $fetcher = new PaginationFetcher($db, $select);

    $page = $fetcher(new CountedPage());
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['category' => 'one', 'quantity' => 2]]), $page->results);
    Assert::assertSame(1, $page->totalCount);
    $select->having(new Fragment('COUNT(*)'), '>', 3);
    $empty = $fetcher(new CountedPage());
    Assert::assertSame([], $empty->results);
    Assert::assertSame(0, $empty->totalCount);
});

it('total count preserves all selected fields', function (): void {
    $db = dataFetcherPaginationDatabase();
    $fetcher = new PaginationFetcher($db, $db->select('id', '_row_count')
        ->from(new Fragment('(SELECT 1 AS id, 73 AS _row_count) AS items')));

    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 1, '_row_count' => 73]]), $fetcher(new Page())->results);
    $page = $fetcher(new CountedPage());
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 1, '_row_count' => 73]]), $page->results);
    Assert::assertSame(1, $page->totalCount);
});

it('total count accepts duplicate output column names', function (array $columns): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select($columns)->from(new Fragment('(SELECT 1 AS id) AS a, (SELECT 2 AS id) AS b'));
    // PDO does not guarantee which duplicate name survives associative fetching.
    $expected = $source->fetchAll();
    Assert::assertCount(1, $expected);
    Assert::assertSame(['id'], array_keys($expected[0]));
    Assert::assertContains($expected[0]['id'], [$db->getType() === 'SQLServer' ? '1' : 1, $db->getType() === 'SQLServer' ? '2' : 2]);
    $fetcher = new PaginationFetcher($db, $source);

    Assert::assertSame($expected, $fetcher(new Page())->results);
    $page = $fetcher(new CountedPage());
    Assert::assertSame($expected, $page->results);
    Assert::assertSame(1, $page->totalCount);
})->with(static function (): iterable {
    yield 'qualified names' => [['a.id', 'b.id']];
    yield 'stars' => [['a.*', 'b.*']];
    yield 'one SQL fragment' => [[new Fragment('a.id, b.id')]];
});

it('total counts aggregate result rows', function (bool $empty, int $offset, array $expected): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select(new Fragment('COUNT(*) AS quantity'))
        ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'));
    if ($empty) {
        $source->where('id', '>', 2);
    }
    $fetcher = new PaginationFetcher($db, $source);
    foreach ([new Page(1, $offset), new CountedPage(1, $offset)] as $query) {
        $page = $fetcher($query);
        Assert::assertSame(dataFetcherPaginationDriverRows($db, $expected), $page->results);
        Assert::assertSame($query instanceof CountedPage ? 1 : null, $page->totalCount);
        expect($page->hasNextPage)->toBeFalse();
    }
})->with(static function (): iterable {
    yield 'nonempty source' => [false, 0, [['quantity' => 2]]];
    yield 'empty source still has one aggregate row' => [true, 0, [['quantity' => 0]]];
    yield 'past aggregate result' => [false, 2, []];
});

it('total counts all union arms', function (): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')
        ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS first_items'))
        ->where('id', '>', 1)
        ->unionAll(new Fragment(
            'SELECT id FROM (SELECT 3 AS id UNION ALL SELECT 4) AS second_items WHERE id < 4',
        ))
        ->orderBy('id');
    $fetcher = new PaginationFetcher($db, $source);

    $first = $fetcher(new CountedPage(1));
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 2]]), $first->results);
    Assert::assertSame(2, $first->totalCount);
    $second = $fetcher(new CountedPage(1, 1));
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 3]]), $second->results);
    Assert::assertSame(2, $second->totalCount);
});

it('pagination does not evaluate fields outside the page', function (): void {
    $db = dataFetcherPaginationDatabase();
    $integerType = $db->getType() === 'MySQL' ? 'SIGNED' : 'BIGINT';
    $fetcher = new PaginationFetcher($db, $db->select(new Fragment(
        "abs(CASE WHEN id < 2 THEN id ELSE CAST('-9223372036854775808' AS {$integerType}) END) AS value",
    ))->from(new Fragment(
        '(SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) AS items',
    )));

    $page = $fetcher(new CountedPage(1));
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['value' => 1]]), $page->results);
    Assert::assertSame(4, $page->totalCount);
    $page = $fetcher(new Page(1));
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['value' => 1]]), $page->results);
    expect($page->totalCount)->toBeNull();
    expect($page->hasNextPage)->toBeTrue();
});

it('postgres set returning projection preserves next page and total', function (): void {
    $db = dataFetcherPaginationDatabase();
    if ($db->getType() !== 'Postgres') {
        Assert::markTestSkipped('PostgreSQL set-returning select expressions are dialect-specific.');
    }

    $source = $db->select(new Fragment('generate_series(1, id) AS value'))
        ->from(new Fragment('(SELECT 3 AS id) AS items'));
    $fetcher = new PaginationFetcher($db, $source);

    foreach ([new Page(1), new CountedPage(1)] as $query) {
        $first = $fetcher($query);
        Assert::assertSame(dataFetcherPaginationDriverRows($db, [['value' => 1]]), $first->results);
        expect($first->hasNextPage)->toBeTrue();
        Assert::assertSame($query instanceof CountedPage ? 3 : null, $first->totalCount);
    }
    Assert::assertFalse($fetcher(new Page(1, 2))->hasNextPage);
});

it('database manager preserves bound union parameters across repeated reads', function (): void {
    $db = dataFetcherPaginationDatabase();
    foreach ([[4, 2, false], [5, 3, true], [4, 2, false]] as [$upperBound, $total, $hasNext]) {
        $source = $db->select('id')
            ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS first_items'))
            ->where('id', '>', 1)
            ->unionAll(new Fragment(
                'SELECT id FROM (SELECT 3 AS id UNION ALL SELECT 4) AS second_items WHERE id < ?',
                $upperBound,
            ))->orderBy('id');
        Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 3]]), (clone $source)->limit(1)->offset(1)->fetchAll());
        $fetcher = new PaginationFetcher($db, $source);
        foreach ([new Page(1, 1), new CountedPage(1, 1)] as $query) {
            $page = $fetcher($query);
            Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 3]]), $page->results);
            Assert::assertSame($hasNext, $page->hasNextPage, sprintf('upperBound=%d, query=%s', $upperBound, $query::class));
            Assert::assertSame($query instanceof CountedPage ? $total : null, $page->totalCount);
        }
    }
});

it('page flags agree with and without an exposed total', function (int $limit, int $offset, array $expected, bool $hasNext, bool $customFetch = false): void {
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('category')->from(new Fragment(
        "(SELECT 'a' AS category UNION ALL SELECT 'a' UNION ALL SELECT 'b' UNION ALL SELECT 'c') AS items",
    ))->distinct()->orderBy('category');
    $fetcher = $customFetch ? new CustomPaginationFetcher($db, $source) : new PaginationFetcher($db, $source);
    foreach ([new Page($limit, $offset), new CountedPage($limit, $offset)] as $query) {
        $page = $fetcher($query);
        Assert::assertSame($expected, $page->results);
        Assert::assertSame($hasNext, $page->hasNextPage);
        Assert::assertSame($query instanceof CountedPage ? 3 : null, $page->totalCount);
    }
})->with(static function (): iterable {
    yield 'first page' => [2, 0, [['category' => 'a'], ['category' => 'b']], true];
    yield 'partial last page' => [2, 2, [['category' => 'c']], false];
    yield 'full last page' => [3, 0, [['category' => 'a'], ['category' => 'b'], ['category' => 'c']], false];
    yield 'past the end' => [2, 5, [], false];
    yield 'large limit' => [PHP_INT_MAX, 1, [['category' => 'b'], ['category' => 'c']], false];
    yield 'large offset' => [2, PHP_INT_MAX, [], false];
    yield 'custom fetch with next page' => [2, 0, [['category' => 'a'], ['category' => 'b']], true, true];
    yield 'custom fetch with large limit' => [PHP_INT_MAX, 1, [['category' => 'b'], ['category' => 'c']], false, true];
    yield 'custom fetch with large offset' => [2, PHP_INT_MAX, [], false, true];
});

it('next page probe stops when the next page is known', function (bool $distinct): void {
    $db = dataFetcherPaginationDatabase();
    $integerType = $db->getType() === 'MySQL' ? 'SIGNED' : 'BIGINT';
    $expression = "abs(CASE WHEN id < 3 THEN id ELSE CAST('-9223372036854775808' AS {$integerType}) END)";
    if ($distinct && $db->getType() === 'Postgres') {
        // DISTINCT may require evaluating all projected rows before LIMIT.
        // PostgreSQL does not promise SQLite/MySQL's short-circuit behavior.
        Assert::markTestSkipped('PostgreSQL DISTINCT may evaluate the full projection before LIMIT.');
    }
    $source = $db->select(new Fragment($distinct ? $expression . ' AS value' : 'id AS value'))
        ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3) AS items'));
    if ($distinct) {
        $source->distinct();
    } else {
        // Bound the literal source so predicate pushdown cannot evaluate later UNION arms.
        $items = $db->select('id')->from(new Fragment(
            '(SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3) AS source_items',
        ))->limit(3)->orderBy('id');
        $source = $db->select(new Fragment('id AS value'))->from(new \Cycle\Database\Injection\SubQuery($items, 'items'));
        $source->where(new Fragment($expression), '>', 0);
    }

    $rawQuery = (clone $source)->limit(2);
    if ($distinct && $db->getType() === 'SQLServer') {
        // The bare DBAL's row-number fallback changes DISTINCT cardinality.
        // Use the projection-preserving ORDER BY required by SQL Server
        // before asserting that the optimizer can stop early.
        $rawQuery->orderBy(new Fragment('1'), null);
    }
    try {
        $raw = $rawQuery->fetchAll();
    } catch (\Cycle\Database\Exception\StatementException $e) {
        if ($distinct && $db->getType() === 'SQLServer' && str_contains($e->getMessage(), 'SQLSTATE[22003]')) {
            Assert::markTestSkipped('SQL Server evaluates the DISTINCT projection before the ordered page limit.');
        }
        throw $e;
    }
    // The bare DBAL may append its SQL Server fallback row-number column.
    // Verify the precondition without requiring DataFetcher's projection
    // preservation from the lower-level query builder.
    Assert::assertSame(
        array_column(dataFetcherPaginationDriverRows($db, [['value' => 1], ['value' => 2]]), 'value'),
        array_column($raw, 'value'),
    );

    $page = (new PaginationFetcher($db, $source))(new Page(1));

    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['value' => 1]]), $page->results);
    expect($page->hasNextPage)->toBeTrue();
    expect($page->totalCount)->toBeNull();
})->with(static function (): iterable {
    yield 'distinct expression beyond the next row' => [true];
    yield 'filter expression beyond the next row' => [false];
});

it('configured my sql preserves parameterized select unions', function (): void {
    if (getenv('CYCLE_TEST_DB_DRIVER') !== 'mysql') {
        Assert::markTestSkipped('Set CYCLE_TEST_DB_DRIVER=mysql to test MySQL SELECT UNION syntax.');
    }
    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')
        ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS first_items'))->where('id', '>', 1)
        ->unionAll($db->select('id')
            ->from(new Fragment('(SELECT 3 AS id UNION ALL SELECT 4) AS second_items'))->where('id', '<', 4))
        ->orderBy('id');
    Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 2]]), (clone $source)->limit(1)->offset(0)->fetchAll());
    $fetcher = new PaginationFetcher($db, $source);
    foreach ([new Page(1), new CountedPage(1)] as $query) {
        $page = $fetcher($query);
        Assert::assertSame(dataFetcherPaginationDriverRows($db, [['id' => 2]]), $page->results);
        expect($page->hasNextPage)->toBeTrue();
        Assert::assertSame($query instanceof CountedPage ? 2 : null, $page->totalCount);
    }
});

it('sql server and other dialects use only necessary pagination queries', function (): void {
    $db = dataFetcherPaginationDatabase();
    $driver = $db->getDriver(DatabaseInterface::READ);
    $db->query('SELECT 1')->close();
    $logger = new class extends \Psr\Log\AbstractLogger {
        /** @var list<string> */
        public array $queries = [];

        public function log($level, $message, array $context = []): void
        {
            if ($level === \Psr\Log\LogLevel::INFO && str_starts_with(ltrim((string) $message), 'SELECT')) {
                $this->queries[] = (string) $message;
            }
        }
    };
    $driver->setLogger($logger);
    $expectedId = $db->getType() === 'SQLServer' ? '1' : 1;
    $logger->queries = [];

    try {
        $source = $db->select('id', 'label AS category')->from(new Fragment(
            "(SELECT 1 AS id, 'a' AS label UNION ALL SELECT 2, 'b') AS items",
        ))->orderBy('id');
        $fetcher = new PaginationFetcher($db, $source);

        $page = $fetcher(new CountedPage(1));
        Assert::assertSame([['id' => $expectedId, 'category' => 'a']], $page->results);
        Assert::assertSame(2, $page->totalCount);
        expect($page->hasNextPage)->toBeTrue();
        Assert::assertCount(2, $logger->queries, 'Known projection width must not require metadata SQL.');

        $logger->queries = [];
        $page = $fetcher(new Page(1));
        Assert::assertSame([['id' => $expectedId, 'category' => 'a']], $page->results);
        expect($page->hasNextPage)->toBeTrue();
        Assert::assertCount(2, $logger->queries, 'An existence probe must not request metadata SQL.');

        foreach (['*', new Fragment('id, label')] as $projection) {
            $logger->queries = [];
            $source = $db->select($projection)->from(new Fragment(
                "(SELECT 1 AS id, 'a' AS label UNION ALL SELECT 2, 'b') AS items",
            ))->orderBy('id');
            $page = (new PaginationFetcher($db, $source))(new CountedPage(1));

            Assert::assertSame([['id' => $expectedId, 'label' => 'a']], $page->results);
            Assert::assertSame(2, $page->totalCount);
            Assert::assertCount(
                in_array($db->getType(), ['MySQL', 'SQLServer'], true) ? 3 : 2,
                $logger->queries,
                'Unknown projection widths must retain the metadata fallback on relevant dialects.',
            );
        }
    } finally {
        $driver->setLogger(new \Psr\Log\NullLogger());
    }
});

it('sql server unordered distinct pages keep the original projection and cardinality', function (): void {
    if (!in_array(getenv('CYCLE_TEST_DB_DRIVER'), ['sqlserver', 'mssql'], true)) {
        Assert::markTestSkipped('Set CYCLE_TEST_DB_DRIVER=sqlserver to test unordered SQL Server pagination.');
    }

    $db = dataFetcherPaginationDatabase();
    $source = $db->select('category')->from(new Fragment(
        "(SELECT 'one' AS category UNION ALL SELECT 'one' UNION ALL SELECT 'two') AS items",
    ))->distinct();
    $fetcher = new PaginationFetcher($db, $source);

    foreach ([new Page(1), new CountedPage(1)] as $query) {
        $first = $fetcher($query);
        Assert::assertSame([['category' => 'one']], $first->results);
        expect($first->hasNextPage)->toBeTrue();
        Assert::assertSame($query instanceof CountedPage ? 2 : null, $first->totalCount);
    }

    $last = $fetcher(new Page(1, 1));
    Assert::assertSame([['category' => 'two']], $last->results);
    expect($last->hasNextPage)->toBeFalse();
});

it('sql server unordered pages do not expose technical columns', function (): void {
    if (!in_array(getenv('CYCLE_TEST_DB_DRIVER'), ['sqlserver', 'mssql'], true)) {
        Assert::markTestSkipped('This scenario requires SQL Server.');
    }

    $db = dataFetcherPaginationDatabase();
    $source = $db->select('id')->from(new Fragment('(SELECT 7 AS id UNION ALL SELECT 8) AS items'));
    $fetcher = new PaginationFetcher($db, $source);

    foreach ([new Page(2), new CountedPage(2)] as $query) {
        $page = $fetcher($query);
        Assert::assertCount(2, $page->results);
        foreach ($page->results as $row) {
            Assert::assertSame(['id'], array_keys($row));
        }
        Assert::assertEqualsCanonicalizing([7, 8], array_column($page->results, 'id'));
        expect($page->hasNextPage)->toBeFalse();
        Assert::assertSame($query instanceof CountedPage ? 2 : null, $page->totalCount);
    }
});

it('direct fetch rejects invalid pagination before reading rows', function (?int $count, int $limit, int $offset): void {
    $fetcher = new DirectRowsPaginationFetcher($this->createStub(DatabaseInterface::class));
    $rows = (static function (): \Generator {
        throw new \LogicException('Invalid pagination must not consume rows.');
        yield [];
    })();

    $this->expectException(\InvalidArgumentException::class);
    $fetcher($rows, $count, new Page($limit, $offset));
})->with(static function (): iterable {
    foreach ([null, 1] as $count) {
        yield [$count, 0, 0];
        yield [$count, -1, 0];
        yield [$count, 1, -1];
    }
});

class Page implements PaginableInterface
{
    public function __construct(public int $limit = 10, public int $offset = 0) {}
}
final class CountedPage extends Page implements RequiresTotalCountInterface {}

final class PaginationFetcher extends DataFetcher
{
    protected bool $autoMapToCamelCase = false;
    public function __construct(DatabaseInterface $db, private SelectQuery $source) { parent::__construct($db); }
    public function __invoke(PaginableInterface $query): Paginator { return $this->doFetch($query); }
    protected function select(?object $query): SelectQuery { return $this->apply(clone $this->source, $query); }
}

final class CustomPaginationFetcher extends DataFetcher
{
    protected bool $autoMapToCamelCase = false;
    public function __construct(DatabaseInterface $db, private SelectQuery $source) { parent::__construct($db); }
    public function __invoke(PaginableInterface $query): Paginator
    {
        $select = $this->apply(clone $this->source, $query);
        $count = $query instanceof RequiresTotalCountInterface ? $this->countRows($select) : null;
        return $this->fetchResults($select, $count, $query);
    }
}

final class ResultTransformingPaginationFetcher extends DataFetcher
{
    public ?\Closure $beforeResults = null;

    public function __construct(DatabaseInterface $db, private SelectQuery $source, private bool $materialize = false) { parent::__construct($db); }
    public function __invoke(PaginableInterface $query): Paginator { return $this->doFetch($query); }
    protected function select(?object $query): SelectQuery { return $this->apply(clone $this->source, $query); }

    public function fetchDirect(SelectQuery $select, PaginableInterface $query): Paginator
    {
        return parent::fetchResults($select, null, $query);
    }

    protected function fetchResults(iterable $rows, ?int $count, ?object $query): iterable|Paginator
    {
        if ($this->materialize) { $rows = iterator_to_array($rows); }
        ($this->beforeResults)?->__invoke($query);
        $page = parent::fetchResults($rows, $count, $query);
        return new Paginator(
            array_map(static fn (array $row): array => ['label' => 'item ' . $row['id']], $page->results),
            $page->limit,
            $page->offset,
            $page->totalCount,
            $page->hasNextPage,
        );
    }
}


final class DirectRowsPaginationFetcher extends DataFetcher
{
    public function __invoke(iterable $rows, ?int $count, PaginableInterface $query): Paginator
    {
        return $this->fetchResults($rows, $count, $query);
    }
}
