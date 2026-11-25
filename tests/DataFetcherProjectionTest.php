<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\Cycle\Filter\Direction;
use Componenta\Cycle\Query\SelectableInterface;
use Componenta\Cycle\Query\SortableInterface;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseInterface;

final class ProjectionQuery implements SelectableInterface, SortableInterface
{
    public function __construct(public ?array $columns = null, public ?array $orderBy = null) {}
}

class ProjectionFetcher extends DataFetcher
{
    public function __invoke(ProjectionQuery $query): iterable
    {
        return $this->doFetch($query);
    }

    protected function getTableName(): string
    {
        return 'records';
    }
}

final class PublicProjectionFetcher extends ProjectionFetcher
{
    protected function selectableColumns(): ?array
    {
        return ['identifier' => 'id', 'title' => 'title'];
    }

    protected function sortableColumns(): ?array
    {
        return ['identifier' => 'id'];
    }
}

function dataFetcherProjectionDatabase(): DatabaseInterface
{
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
    $db->execute('CREATE TABLE records (id INTEGER, title TEXT, secret TEXT)');
    $db->execute("INSERT INTO records VALUES (1, 'one', 'hidden'), (2, 'two', 'hidden')");
    return $db;
}

it('defaults public projections to allowed fields and maps sorting aliases', function (): void {
    $fetch = new PublicProjectionFetcher(dataFetcherProjectionDatabase());

    expect($fetch(new ProjectionQuery(orderBy: ['identifier' => Direction::DESC])))->toBe([['id' => 2, 'title' => 'two'], ['id' => 1, 'title' => 'one']]);
    expect($fetch(new ProjectionQuery(['identifier'], ['identifier' => Direction::ASC])))->toBe([['id' => 1], ['id' => 2]]);
});

it('rejects unlisted public projection columns', function (): void {
    $fetch = new PublicProjectionFetcher(dataFetcherProjectionDatabase());
    $this->expectException(\InvalidArgumentException::class);
    $fetch(new ProjectionQuery(['secret']));
});

it('rejects unlisted public sort columns', function (): void {
    $fetch = new PublicProjectionFetcher(dataFetcherProjectionDatabase());
    $this->expectException(\InvalidArgumentException::class);
    $fetch(new ProjectionQuery(orderBy: ['secret' => Direction::ASC]));
});

it('rejects non string requested columns even if they can be coerced to strings', function (): void {
    $fetch = new PublicProjectionFetcher(dataFetcherProjectionDatabase());
    $this->expectException(\InvalidArgumentException::class);
    $fetch(new ProjectionQuery([new class implements \Stringable {
        public function __toString(): string { return 'title'; }
    }]));
});

it('trusted query rejects sql expressions as requested columns', function (): void {
    $fetch = new ProjectionFetcher(dataFetcherProjectionDatabase());
    $this->expectException(\InvalidArgumentException::class);
    $fetch(new ProjectionQuery(['LOWER(secret)']));
});
