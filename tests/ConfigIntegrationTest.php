<?php
declare(strict_types=1);
namespace Componenta\Cycle\Tests;
use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigKey;
use Componenta\Cycle\ConfigProvider;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use Cycle\Migrations\Config\MigrationConfig;
use Cycle\ORM\SchemaInterface;
use PHPUnit\Framework\TestCase;

final class ConfigIntegrationTest extends TestCase {
    private function container(): \Componenta\Config\ContainerValue {
        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
            static fn (): array => [ConfigKey::ROOT => [ConfigKey::MIGRATIONS => ['namespace' => 'ExampleMigrations']]],
        );
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    }
    public function testCreatesDatabaseFromTheConfiguredEnvironment(): void {
        $database = $this->container()->get(DatabaseManager::class)->database();
        self::assertSame('default', $database->getName());
        self::assertSame(1, (int) $database->query('SELECT 1 AS value')->fetch()['value']);
    }
    public function testCreatesSchemaAndMigrationSettingsFromRuntimeConfig(): void {
        $container = $this->container();
        self::assertSame([], iterator_to_array($container->get(SchemaInterface::class)->getRoles()));
        self::assertSame('ExampleMigrations', $container->get(MigrationConfig::class)->getNamespace());
    }
}
