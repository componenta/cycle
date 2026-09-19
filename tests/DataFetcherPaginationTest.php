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
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DataFetcherPaginationTest extends TestCase
{
    #[DataProvider('transformedPages')]
    public function testResultTransformationsArePreservedWithAndWithoutTotal(int $offset, array $expected, bool $hasNext, bool $materialize): void
    {
        $db = $this->database();
        $source = $db->select('id')->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'))->orderBy('id');
        $fetcher = new ResultTransformingPaginationFetcher($db, $source, $materialize);

        foreach ([new CountedPage(1, $offset), new Page(1, $offset), new CountedPage(1, $offset)] as $query) {
            $page = $fetcher($query);
            self::assertSame($expected, $page->results);
            self::assertSame($hasNext, $page->hasNextPage);
            self::assertSame($query instanceof CountedPage ? 2 : null, $page->totalCount);
        }
    }

    public static function transformedPages(): iterable
    {
        foreach ([false, true] as $materialize) {
            $prefix = $materialize ? 'materialized ' : 'query ';
            yield $prefix . 'first page' => [0, [['label' => 'item 1']], true, $materialize];
            yield $prefix . 'last full page' => [1, [['label' => 'item 2']], false, $materialize];
            yield $prefix . 'past the end' => [2, [], false, $materialize];
        }
    }

    #[DataProvider('nestedResults')]
    public function testNestedFetchesPreserveOuterPagination(bool $throw): void
    {
        $db = $this->database();
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
                self::assertFalse($throw);
                self::assertSame([['label' => 'item 1']], $nested->results);
                self::assertTrue($nested->hasNextPage);
            } catch (\RuntimeException $caught) {
                self::assertTrue($throw);
                self::assertSame($failure, $caught);
            }
        };

        $page = $fetcher($outerQuery);
        self::assertSame([['label' => 'item 2']], $page->results);
        self::assertFalse($page->hasNextPage);
        $fetcher->beforeResults = null;
        self::assertTrue($fetcher(new Page(1, 0))->hasNextPage);
        self::assertFalse($fetcher($outerQuery)->hasNextPage);
    }

    public static function nestedResults(): iterable
    {
        yield 'successful nested call' => [false];
        yield 'failing nested call' => [true];
    }

    public function testConcurrentFetchesKeepTheirOwnPagination(): void
    {
        $db = $this->database();
        $source = $db->select('id')->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'))->orderBy('id');
        $fetcher = new ResultTransformingPaginationFetcher($db, $source, true);
        $fetcher->beforeResults = static function (): void { \Fiber::suspend(); };
        $last = new \Fiber(static fn (): Paginator => $fetcher(new Page(1, 1)));
        $first = new \Fiber(static fn (): Paginator => $fetcher(new Page(1, 0)));

        $last->start();
        $first->start();
        $last->resume();
        $first->resume();

        self::assertSame([['label' => 'item 2']], $last->getReturn()->results);
        self::assertFalse($last->getReturn()->hasNextPage);
        self::assertSame([['label' => 'item 1']], $first->getReturn()->results);
        self::assertTrue($first->getReturn()->hasNextPage);
    }

    #[DataProvider('distinctPages')]
    public function testTotalCountCountsDistinctResultRows(bool $join, int $limit, int $offset, array $expected): void
    {
        $db = $this->database();
        $select = $db->select('category')->from(new Fragment(
            "(SELECT 'one' AS category UNION ALL SELECT 'one' UNION ALL SELECT 'two') AS items",
        ))->distinct()->orderBy('category');
        if ($join) {
            $select->innerJoin($db->select('flag')->from(new Fragment('(SELECT 1 AS flag) AS marker')), 'marker')
                ->on('marker.flag', '=', 'marker.flag');
        }
        $fetcher = new PaginationFetcher($db, $select);

        $page = $fetcher(new CountedPage($limit, $offset));
        self::assertSame($expected, $page->results);
        self::assertSame(2, $page->totalCount);
    }

    public static function distinctPages(): iterable
    {
        yield 'all distinct rows' => [false, 10, 0, [['category' => 'one'], ['category' => 'two']]];
        yield 'with a join' => [true, 10, 0, [['category' => 'one'], ['category' => 'two']]];
        yield 'second page' => [false, 1, 1, [['category' => 'two']]];
        yield 'past the end' => [false, 1, 4, []];
    }

    public function testTotalCountPreservesGroupingHavingAndBoundParameters(): void
    {
        $db = $this->database();
        $select = $db->select('category', new Fragment('COUNT(*) AS quantity'))->from(new Fragment(
            "(SELECT 'one' AS category UNION ALL SELECT 'one' UNION ALL SELECT 'two') AS items",
        ))->where('category', '!=', 'excluded')->groupBy('category')->having(new Fragment('COUNT(*)'), '>', 1);
        $fetcher = new PaginationFetcher($db, $select);

        $page = $fetcher(new CountedPage());
        self::assertSame([['category' => 'one', 'quantity' => 2]], $page->results);
        self::assertSame(1, $page->totalCount);
        $select->having(new Fragment('COUNT(*)'), '>', 3);
        $empty = $fetcher(new CountedPage());
        self::assertSame([], $empty->results);
        self::assertSame(0, $empty->totalCount);
    }

    public function testTotalCountPreservesAllSelectedFields(): void
    {
        $db = $this->database();
        $fetcher = new PaginationFetcher($db, $db->select('id', '_row_count')
            ->from(new Fragment('(SELECT 1 AS id, 73 AS _row_count) AS items')));

        self::assertSame([['id' => 1, '_row_count' => 73]], $fetcher(new Page())->results);
        $page = $fetcher(new CountedPage());
        self::assertSame([['id' => 1, '_row_count' => 73]], $page->results);
        self::assertSame(1, $page->totalCount);
    }

    #[DataProvider('duplicateColumns')]
    public function testTotalCountAcceptsDuplicateOutputColumnNames(array $columns): void
    {
        $db = $this->database();
        $fetcher = new PaginationFetcher($db, $db->select($columns)
            ->from(new Fragment('(SELECT 1 AS id) AS a, (SELECT 2 AS id) AS b')));

        self::assertSame([['id' => 2]], $fetcher(new Page())->results);
        $page = $fetcher(new CountedPage());
        self::assertSame([['id' => 2]], $page->results);
        self::assertSame(1, $page->totalCount);
    }

    public static function duplicateColumns(): iterable
    {
        yield 'qualified names' => [['a.id', 'b.id']];
        yield 'stars' => [['a.*', 'b.*']];
        yield 'one SQL fragment' => [[new Fragment('a.id, b.id')]];
    }

    #[DataProvider('aggregatePages')]
    public function testTotalCountsAggregateResultRows(bool $empty, int $offset, array $expected): void
    {
        $db = $this->database();
        $source = $db->select(new Fragment('COUNT(*) AS quantity'))
            ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS items'));
        if ($empty) {
            $source->where('id', '>', 2);
        }
        $fetcher = new PaginationFetcher($db, $source);
        foreach ([new Page(1, $offset), new CountedPage(1, $offset)] as $query) {
            $page = $fetcher($query);
            self::assertSame($expected, $page->results);
            self::assertSame($query instanceof CountedPage ? 1 : null, $page->totalCount);
            self::assertFalse($page->hasNextPage);
        }
    }

    public static function aggregatePages(): iterable
    {
        yield 'nonempty source' => [false, 0, [['quantity' => 2]]];
        yield 'empty source still has one aggregate row' => [true, 0, [['quantity' => 0]]];
        yield 'past aggregate result' => [false, 2, []];
    }

    public function testTotalCountsAllUnionArms(): void
    {
        $db = $this->database();
        $source = $db->select('id')
            ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS first_items'))
            ->where('id', '>', 1)
            ->unionAll(new Fragment(
                'SELECT id FROM (SELECT 3 AS id UNION ALL SELECT 4) AS second_items WHERE id < 4',
            ))
            ->orderBy('id');
        $fetcher = new PaginationFetcher($db, $source);

        $first = $fetcher(new CountedPage(1));
        self::assertSame([['id' => 2]], $first->results);
        self::assertSame(2, $first->totalCount);
        $second = $fetcher(new CountedPage(1, 1));
        self::assertSame([['id' => 3]], $second->results);
        self::assertSame(2, $second->totalCount);
    }

    public function testPaginationDoesNotEvaluateFieldsOutsideThePage(): void
    {
        $db = $this->database();
        $integerType = $db->getType() === 'MySQL' ? 'SIGNED' : 'BIGINT';
        $fetcher = new PaginationFetcher($db, $db->select(new Fragment(
            "abs(CASE WHEN id < 2 THEN id ELSE CAST('-9223372036854775808' AS {$integerType}) END) AS value",
        ))->from(new Fragment(
            '(SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) AS items',
        )));

        $page = $fetcher(new CountedPage(1));
        self::assertSame([['value' => 1]], $page->results);
        self::assertSame(4, $page->totalCount);
        $page = $fetcher(new Page(1));
        self::assertSame([['value' => 1]], $page->results);
        self::assertNull($page->totalCount);
        self::assertTrue($page->hasNextPage);
    }

    public function testDatabaseManagerPreservesBoundUnionParametersAcrossRepeatedReads(): void
    {
        $db = $this->database();
        foreach ([[4, 2, false], [5, 3, true], [4, 2, false]] as [$upperBound, $total, $hasNext]) {
            $source = $db->select('id')
                ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS first_items'))
                ->where('id', '>', 1)
                ->unionAll(new Fragment(
                    'SELECT id FROM (SELECT 3 AS id UNION ALL SELECT 4) AS second_items WHERE id < ?',
                    $upperBound,
                ))->orderBy('id');
            self::assertSame([['id' => 3]], (clone $source)->limit(1)->offset(1)->fetchAll());
            $fetcher = new PaginationFetcher($db, $source);
            foreach ([new Page(1, 1), new CountedPage(1, 1)] as $query) {
                $page = $fetcher($query);
                self::assertSame([['id' => 3]], $page->results);
                self::assertSame($hasNext, $page->hasNextPage);
                self::assertSame($query instanceof CountedPage ? $total : null, $page->totalCount);
            }
        }
    }

    #[DataProvider('pageBoundaries')]
    public function testPageFlagsAgreeWithAndWithoutAnExposedTotal(int $limit, int $offset, array $expected, bool $hasNext, bool $customFetch = false): void
    {
        $db = $this->database();
        $source = $db->select('category')->from(new Fragment(
            "(SELECT 'a' AS category UNION ALL SELECT 'a' UNION ALL SELECT 'b' UNION ALL SELECT 'c') AS items",
        ))->distinct()->orderBy('category');
        $fetcher = $customFetch ? new CustomPaginationFetcher($db, $source) : new PaginationFetcher($db, $source);
        foreach ([new Page($limit, $offset), new CountedPage($limit, $offset)] as $query) {
            $page = $fetcher($query);
            self::assertSame($expected, $page->results);
            self::assertSame($hasNext, $page->hasNextPage);
            self::assertSame($query instanceof CountedPage ? 3 : null, $page->totalCount);
        }
    }

    public static function pageBoundaries(): iterable
    {
        yield 'first page' => [2, 0, [['category' => 'a'], ['category' => 'b']], true];
        yield 'partial last page' => [2, 2, [['category' => 'c']], false];
        yield 'full last page' => [3, 0, [['category' => 'a'], ['category' => 'b'], ['category' => 'c']], false];
        yield 'past the end' => [2, 5, [], false];
        yield 'large limit' => [PHP_INT_MAX, 1, [['category' => 'b'], ['category' => 'c']], false];
        yield 'large offset' => [2, PHP_INT_MAX, [], false];
        yield 'custom fetch with next page' => [2, 0, [['category' => 'a'], ['category' => 'b']], true, true];
        yield 'custom fetch with large limit' => [PHP_INT_MAX, 1, [['category' => 'b'], ['category' => 'c']], false, true];
        yield 'custom fetch with large offset' => [2, PHP_INT_MAX, [], false, true];
    }

    #[DataProvider('nextPageProbeCases')]
    public function testNextPageProbeStopsWhenTheNextPageIsKnown(bool $distinct): void
    {
        $db = $this->database();
        $integerType = $db->getType() === 'MySQL' ? 'SIGNED' : 'BIGINT';
        $expression = "abs(CASE WHEN id < 3 THEN id ELSE CAST('-9223372036854775808' AS {$integerType}) END)";
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

        self::assertSame([['value' => 1], ['value' => 2]], (clone $source)->limit(2)->fetchAll());

        $page = (new PaginationFetcher($db, $source))(new Page(1));

        self::assertSame([['value' => 1]], $page->results);
        self::assertTrue($page->hasNextPage);
        self::assertNull($page->totalCount);
    }

    public static function nextPageProbeCases(): iterable
    {
        yield 'distinct expression beyond the next row' => [true];
        yield 'filter expression beyond the next row' => [false];
    }

    public function testConfiguredMySQLPreservesParameterizedSelectUnions(): void
    {
        if (getenv('CYCLE_TEST_DB_DRIVER') !== 'mysql') {
            self::markTestSkipped('Set CYCLE_TEST_DB_DRIVER=mysql to test MySQL SELECT UNION syntax.');
        }
        $db = $this->database();
        $source = $db->select('id')
            ->from(new Fragment('(SELECT 1 AS id UNION ALL SELECT 2) AS first_items'))->where('id', '>', 1)
            ->unionAll($db->select('id')
                ->from(new Fragment('(SELECT 3 AS id UNION ALL SELECT 4) AS second_items'))->where('id', '<', 4))
            ->orderBy('id');
        self::assertSame([['id' => 2]], (clone $source)->limit(1)->offset(0)->fetchAll());
        $fetcher = new PaginationFetcher($db, $source);
        foreach ([new Page(1), new CountedPage(1)] as $query) {
            $page = $fetcher($query);
            self::assertSame([['id' => 2]], $page->results);
            self::assertTrue($page->hasNextPage);
            self::assertSame($query instanceof CountedPage ? 2 : null, $page->totalCount);
        }
    }

    private function database(): DatabaseInterface
    {
        $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
        foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA'] as $key) {
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
}

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
