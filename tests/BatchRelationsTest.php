<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class BatchRelationsTest extends TestCase
{
    public function testLoadsAllRelationTypesAcrossLargeBatches(): void
    {
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

        $rows = (new BatchRecordsFetcher($db))();

        self::assertCount(1102, $rows);
        foreach (array_slice($rows, 0, 1101) as $index => $row) {
            $id = $index + 1;
            self::assertSame([
                'id' => $id,
                'ownerId' => $id,
                'owner' => ['id' => $id],
                'children' => [['id' => $id, 'record_id' => $id]],
                'tags' => [['id' => $id]],
            ], $row);
        }
        self::assertSame([
            'id' => 1102, 'ownerId' => null, 'owner' => null, 'children' => [], 'tags' => [],
        ], $rows[1101]);
    }
}

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
