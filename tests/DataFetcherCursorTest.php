<?php
declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\DataFetcher;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\RequiresTotalCountInterface;
use Componenta\Stdlib\Paginator;
use Cycle\Database\Config\MySQL\TcpConnectionConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Database;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\MySQL\MySQLDriver;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;
use PHPUnit\Framework\Assert;
use RuntimeException;

function dataFetcherCursorDatabase(bool $queryCache): DatabaseInterface
{
    if (getenv('CYCLE_TEST_DB_DRIVER') !== 'mysql') {
        Assert::markTestSkipped('Set CYCLE_TEST_DB_DRIVER=mysql and CYCLE_TEST_DB_* to test unbuffered MySQL reads.');
    }
    return new Database('cursor-test', '', MySQLDriver::create(new MySQLDriverConfig(
        new TcpConnectionConfig(
            database: getenv('CYCLE_TEST_DB_NAME') ?: 'information_schema',
            host: getenv('CYCLE_TEST_DB_HOST') ?: '127.0.0.1',
            port: getenv('CYCLE_TEST_DB_PORT') ?: 3306,
            user: getenv('CYCLE_TEST_DB_USER') ?: 'root',
            password: getenv('CYCLE_TEST_DB_PASSWORD') ?: '',
            options: [\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => false],
        ),
        queryCache: $queryCache,
    )));
}

it('query caching preserves unbuffered pages and their relations', function (): void {
    foreach ([false, true] as $queryCache) {
        $db = dataFetcherCursorDatabase($queryCache);
        $fetcher = new CursorFetcher($db);
        try {
            $first = $fetcher(new CursorPage());
            $second = $fetcher(new CursorPage());
            expect($first->results)->toBe([['id' => 1, 'relation' => 7]]);
            expect($second->results)->toBe([['id' => 1, 'relation' => 7]]);
        } finally {
            $db->getDriver()->disconnect();
        }
    }
});

it('row failure preserves the exception and connection', function (?object $query): void {
    $db = dataFetcherCursorDatabase(true);
    $failure = new RuntimeException('row conversion failed');
    $fetcher = new FailingCursorFetcher($db, $failure);
    try {
        try {
            $fetcher($query);
            Assert::fail('The row conversion must fail.');
        } catch (RuntimeException $caught) {
            expect($caught)->toBe($failure);
        }
        expect((new CursorFetcher($db))(new CursorPage())->results)->toBe([['id' => 1, 'relation' => 7]]);
    } finally {
        $db->getDriver()->disconnect();
    }
})->with(static function (): iterable {
    yield 'page without total' => [new CursorPage()];
    yield 'page with total' => [new CountedCursorPage()];
    yield 'unpaginated' => [null];
});

class CursorPage implements PaginableInterface
{
    public int $limit = 1;
    public int $offset = 0;
}

final class CountedCursorPage extends CursorPage implements RequiresTotalCountInterface {}

class CursorFetcher extends DataFetcher
{
    public function __invoke(PaginableInterface $query): Paginator
    {
        return $this->doFetch($query);
    }

    protected function select(?object $query): SelectQuery
    {
        return $this->apply($this->db->select('id')->from(new Fragment(
            '(SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) AS items',
        )), $query);
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $statement = $this->db->query('SELECT 7 AS relation_id');
        try {
            $relation = $statement->fetchColumn();
        } finally {
            $statement->close();
        }
        foreach ($results as &$row) {
            $row['relation'] = $relation;
        }
    }
}

final class FailingCursorFetcher extends DataFetcher
{
    public function __construct(DatabaseInterface $db, private RuntimeException $failure)
    {
        parent::__construct($db);
    }

    public function __invoke(?object $query): mixed
    {
        return $this->doFetch($query);
    }

    protected function select(?object $query): SelectQuery
    {
        return $this->apply($this->db->select('id')->from(new Fragment(
            '(SELECT 1 AS id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) AS failing_items',
        )), $query);
    }

    protected function fetchRow(array $row, ?object $query): array|object
    {
        throw $this->failure;
    }
}
