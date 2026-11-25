<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;

final class GlobalRecordFetcher extends DataFetcher
{
    public function __invoke(): iterable
    {
        return $this->doFetch();
    }
}

final class GlobalDataFetcherTest extends TestCase
{
    public function testTableNameIsDerivedFromAClassInTheGlobalNamespace(): void
    {
        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
        );
        $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
            ->get(DatabaseManager::class)->database();
        $db->execute('CREATE TABLE global_records (id INTEGER)');
        $db->execute('INSERT INTO global_records VALUES (1)');

        self::assertSame([['id' => 1]], (new GlobalRecordFetcher($db))());
    }
}
