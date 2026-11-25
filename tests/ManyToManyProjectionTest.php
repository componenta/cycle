<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Query\SelectQuery;
use PHPUnit\Framework\Assert;
use Psr\Log\AbstractLogger;

function manyToManyProjectionDatabase(): DatabaseInterface
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

it('pivot key never overwrites related projection', function (array $columns, bool $transform): void {
    $db = manyToManyProjectionDatabase();
    try {
        $db->execute('CREATE TABLE cycle_test_mtm_roots (id INTEGER)');
        $db->execute('CREATE TABLE cycle_test_mtm_tags (id INTEGER, _pivot_local_key VARCHAR(32), root_id VARCHAR(32))');
        $db->execute('CREATE TABLE cycle_test_mtm_pivot (root_id INTEGER, tag_id INTEGER)');
        $db->execute('INSERT INTO cycle_test_mtm_roots VALUES (1), (2), (3)');
        $db->execute("INSERT INTO cycle_test_mtm_tags VALUES (7, 'first', 'original-a'), (8, 'second', 'original-b')");
        $db->execute('INSERT INTO cycle_test_mtm_pivot VALUES (1, 7), (2, 7), (2, 8)');

        $logger = new class extends AbstractLogger {
            public int $reads = 0;

            public function log($level, $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::INFO && str_starts_with(ltrim((string) $message), 'SELECT')) {
                    ++$this->reads;
                }
            }
        };
        $db->getDriver(DatabaseInterface::READ)->setLogger($logger);
        $fetcher = new PivotProjectionFetcher($db, $columns, $transform);
        $rows = $fetcher();
        Assert::assertSame(2, $logger->reads);

        // SQL does not specify the order of related rows in this helper.
        $id = static fn (int $value): int|string => $db->getType() === 'SQLServer' ? (string) $value : $value;
        $first = ['id' => $id(7), '_pivot_local_key' => 'first', 'root_id' => 'original-a'];
        $second = ['id' => $id(8), '_pivot_local_key' => 'second', 'root_id' => 'original-b'];
        if ($transform) {
            $first = ['value' => 'first:original-a'];
            $second = ['value' => 'second:original-b'];
        }

        Assert::assertCount(3, $rows);
        Assert::assertSame(['id' => $id(1), 'tags' => [$first]], $rows[0]);
        Assert::assertSame($id(2), $rows[1]['id']);
        Assert::assertEqualsCanonicalizing([$first, $second], $rows[1]['tags']);
        Assert::assertSame(['id' => $id(3), 'tags' => []], $rows[2]);
        Assert::assertSame($transform ? 3 : 0, $fetcher->transformCalls);
    } finally {
        foreach (['cycle_test_mtm_pivot', 'cycle_test_mtm_tags', 'cycle_test_mtm_roots'] as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
})->with(static function (): iterable {
    yield 'wildcard' => [['*'], false];
    yield 'explicit conflicting names' => [['id', '_pivot_local_key', 'root_id'], false];
    yield 'transform sees original columns' => [['*'], true];
});

final class PivotProjectionFetcher extends DataFetcher
{
    public int $transformCalls = 0;

    public function __construct(DatabaseInterface $db, private readonly array $columns, private readonly bool $transform)
    {
        parent::__construct($db);
    }

    public function __invoke(): array
    {
        return $this->doFetch();
    }

    protected function getTableName(): string
    {
        return 'cycle_test_mtm_roots';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('id');
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchBelongsToMany(
            $results,
            $rows,
            'tags',
            'cycle_test_mtm_tags',
            'cycle_test_mtm_pivot',
            'root_id',
            'tag_id',
            columns: $this->columns,
            transform: $this->transform ? function (array $row): array {
                ++$this->transformCalls;
                return ['value' => $row['_pivot_local_key'] . ':' . $row['root_id']];
            } : null,
        );
    }
}
