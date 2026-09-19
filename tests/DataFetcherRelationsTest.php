<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class DataFetcherRelationsTest extends TestCase
{
    public function testRepeatedFetchesReadTheCurrentRelationSelection(): void
    {
        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
        );
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        $fetcher = new RelatedRowsFetcher($container->get(DatabaseManager::class)->database());
        $query = (object) ['with' => 'tags'];

        self::assertSame([['id' => 1, 'relations' => ['tags']]], $fetcher($query));
        $query->with = ['category'];
        self::assertSame([['id' => 1, 'relations' => ['category']]], $fetcher($query));
        $query->with = null;
        self::assertSame([['id' => 1, 'relations' => []]], $fetcher($query));
    }
}

final class RelatedRowsFetcher extends DataFetcher
{
    public function __invoke(object $query): iterable
    {
        return $this->doFetch($query);
    }

    protected function select(?object $query): SelectQuery
    {
        return $this->db->select('id')->from(new Fragment('(SELECT 1 AS id) AS items'));
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        foreach ($results as &$row) {
            $row['relations'] = array_values(array_filter(
                ['tags', 'category'],
                fn (string $relation): bool => $this->wantsRelation($query, $relation),
            ));
        }
    }
}
