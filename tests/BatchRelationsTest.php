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

it('belongs to preserves custom naming and row specific transformation', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
    $db->execute('CREATE TABLE records (id INTEGER, owner_id INTEGER)');
    $db->execute('CREATE TABLE owners (id INTEGER, name TEXT)');
    $db->execute("INSERT INTO records VALUES (1, 7), (2, 7), (3, NULL)");
    $db->execute("INSERT INTO owners VALUES (7, 'Alice')");

    $logger = new RelationQueryLogger();
    $db->getDriver(DatabaseInterface::READ)->setLogger($logger);
    $fetcher = new CustomRelationNamingFetcher($db);
    expect($fetcher())->toBe([
        ['id' => 1, 'owner_id' => 7, 'custom_owner' => 'Alice'],
        ['id' => 2, 'owner_id' => 7, 'custom_owner' => 'Alice'],
        ['id' => 3, 'owner_id' => null, 'custom_owner' => null],
    ]);
    expect($fetcher->transformed)->toBe(2);
    expect($logger->queries)->toHaveCount(2, 'Repeated foreign keys must be queried only once.');
});

it('loads all relation types across large batches', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();

    $db->execute('CREATE TABLE records (id INTEGER, owner_id INTEGER)');
    $db->execute('CREATE TABLE owners (id INTEGER)');
    $db->execute('CREATE TABLE children (id INTEGER, record_id INTEGER)');
    $db->execute('CREATE TABLE tags (id INTEGER)');
    $db->execute('CREATE TABLE record_tags (record_id INTEGER, tag_id INTEGER)');

    $sequence = 'WITH RECURSIVE seq(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM seq WHERE i < 1101) ';
    $db->execute($sequence . 'INSERT INTO records SELECT i, i FROM seq');
    $db->execute($sequence . 'INSERT INTO owners SELECT i FROM seq');
    $db->execute($sequence . 'INSERT INTO children SELECT i, i FROM seq');
    $db->execute($sequence . 'INSERT INTO tags SELECT i FROM seq');
    $db->execute($sequence . 'INSERT INTO record_tags SELECT i, i FROM seq');
    $db->execute('INSERT INTO records VALUES (1102, NULL)');

    $logger = new RelationQueryLogger();
    $db->getDriver(DatabaseInterface::READ)->setLogger($logger);
    $rows = (new BatchRecordsFetcher($db))();

    expect($rows)->toHaveCount(1102);
    expect($logger->queries)->toHaveCount(10, 'One root query and three bounded queries per relation type.');
    foreach (array_slice($rows, 0, 1101) as $index => $row) {
        $id = $index + 1;
        expect($row)->toBe([
            'id' => $id,
            'ownerId' => $id,
            'owner' => ['id' => $id],
            'children' => [['id' => $id, 'record_id' => $id]],
            'tags' => [['id' => $id]],
        ]);
    }
    expect($rows[1101])->toBe([
        'id' => 1102, 'ownerId' => null, 'owner' => null, 'children' => [], 'tags' => [],
    ]);
});

it('no related queries are executed without any usable keys', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
    $db->execute('CREATE TABLE records (id INTEGER, owner_id INTEGER)');
    $db->execute('INSERT INTO records VALUES (NULL, NULL)');

    // Deliberately do not create the related tables: an unnecessary query
    // would fail as well as exceed the query budget.
    $logger = new RelationQueryLogger();
    $db->getDriver(DatabaseInterface::READ)->setLogger($logger);

    expect((new BatchRecordsFetcher($db))())->toBe([
        ['id' => null, 'ownerId' => null, 'owner' => null, 'children' => [], 'tags' => []],
    ]);
    expect($logger->queries)->toHaveCount(1);
});

it('preserves the storage type of integer keys in all batched relations', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();

    // No declared affinity: SQLite must compare bound integer values as
    // integers, not text. Zero and negative keys are both valid identifiers.
    $db->execute('CREATE TABLE records (id, owner_id)');
    $db->execute('CREATE TABLE owners (id)');
    $db->execute('CREATE TABLE children (id, record_id)');
    $db->execute('CREATE TABLE tags (id)');
    $db->execute('CREATE TABLE record_tags (record_id, tag_id)');
    $db->execute('INSERT INTO records VALUES (-1, 7), (0, 7), (1, NULL)');
    $db->execute('INSERT INTO owners VALUES (7)');
    $db->execute('INSERT INTO children VALUES (8, -1), (9, 0)');
    $db->execute('INSERT INTO tags VALUES (10), (11)');
    $db->execute('INSERT INTO record_tags VALUES (-1, 10), (0, 11)');

    $logger = new RelationQueryLogger();
    $db->getDriver(DatabaseInterface::READ)->setLogger($logger);

    expect((new BatchRecordsFetcher($db))())->toBe([
        [
            'id' => -1, 'ownerId' => 7,
            'owner' => ['id' => 7],
            'children' => [['id' => 8, 'record_id' => -1]],
            'tags' => [['id' => 10]],
        ],
        [
            'id' => 0, 'ownerId' => 7,
            'owner' => ['id' => 7],
            'children' => [['id' => 9, 'record_id' => 0]],
            'tags' => [['id' => 11]],
        ],
        [
            'id' => 1, 'ownerId' => null,
            'owner' => null,
            'children' => [],
            'tags' => [],
        ],
    ]);
    expect($logger->queries)->toHaveCount(4);
});

it('does not merge distinct integer and text relation keys in SQLite', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();

    // SQLite's affinity-free columns distinguish the integer 1 from the text '1'.
    $db->execute('CREATE TABLE records (id, owner_id)');
    $db->execute('CREATE TABLE owners (id, label TEXT)');
    $db->execute('CREATE TABLE children (id, record_id)');
    $db->execute('CREATE TABLE tags (id)');
    $db->execute('CREATE TABLE record_tags (record_id, tag_id)');
    $db->execute("INSERT INTO records VALUES (1, 7), ('1', '7')");
    $db->execute("INSERT INTO owners VALUES (7, 'numeric'), ('7', 'text')");
    $db->execute("INSERT INTO children VALUES (8, 1), (9, '1')");
    $db->execute('INSERT INTO tags VALUES (10), (11)');
    $db->execute("INSERT INTO record_tags VALUES (1, 10), ('1', 11)");

    $logger = new RelationQueryLogger();
    $db->getDriver(DatabaseInterface::READ)->setLogger($logger);

    expect((new BatchRecordsFetcher($db))())->toBe([
        [
            'id' => 1, 'ownerId' => 7,
            'owner' => ['id' => 7, 'label' => 'numeric'],
            'children' => [['id' => 8, 'record_id' => 1]],
            'tags' => [['id' => 10]],
        ],
        [
            'id' => '1', 'ownerId' => '7',
            'owner' => ['id' => '7', 'label' => 'text'],
            'children' => [['id' => 9, 'record_id' => '1']],
            'tags' => [['id' => 11]],
        ],
    ]);
    // At most two bounded batches per relation, never a query per row.
    expect(count($logger->queries))->toBeLessThanOrEqual(7);
});

final class BatchRecordsFetcher extends DataFetcher
{
    public function __invoke(): array
    {
        return $this->doFetch();
    }

    protected function getTableName(): string
    {
        return 'records';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('id');
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchBelongsTo($results, $rows, 'owner_id', 'owners');
        $this->batchHasMany($results, $rows, 'children', 'children', 'record_id');
        $this->batchBelongsToMany($results, $rows, 'tags', 'tags', 'record_tags', 'record_id', 'tag_id');
    }
}


final class CustomRelationNamingFetcher extends DataFetcher
{
    protected bool $autoMapToCamelCase = false;
    public int $transformed = 0;

    public function __invoke(): array
    {
        return $this->doFetch();
    }

    protected function getTableName(): string
    {
        return 'records';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('id');
    }

    protected function snakeToCamel(string $string): string
    {
        return 'custom_' . $string;
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchBelongsTo(
            $results, $rows, 'owner_id', 'owners', ['name'],
            transform: function (array $row): string {
                ++$this->transformed;
                return $row['name'];
            },
        );
    }
}


/** Records successful SQL reads, without affecting their execution. */
final class RelationQueryLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $queries = [];

    public function log($level, $message, array $context = []): void
    {
        if ($level === \Psr\Log\LogLevel::INFO && str_starts_with(ltrim((string) $message), 'SELECT')) {
            $this->queries[] = (string) $message;
        }
    }
}
