<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use PHPUnit\Framework\Assert;

final class GlobalRecordFetcher extends DataFetcher
{
    public function __invoke(): iterable
    {
        return $this->doFetch();
    }
}

it('table name is derived from a class in the global namespace', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
    $db->execute('CREATE TABLE global_records (id INTEGER)');
    $db->execute('INSERT INTO global_records VALUES (1)');

    Assert::assertSame([['id' => 1]], (new GlobalRecordFetcher($db))());
});
