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
use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\ORMInterface;
use PHPUnit\Framework\TestCase;

final class ConfigIntegrationTest extends TestCase {
    private function container(array $additionalConfig = []): \Componenta\Config\ContainerValue {
        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
            static fn (): array => [ConfigKey::ROOT => [ConfigKey::MIGRATIONS => ['namespace' => 'ExampleMigrations'], ...$additionalConfig]],
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

    public function testCreatesDefaultTypecastsWithoutApplicationClasses(): void
    {
        $container = $this->container();
        $orm = $container->get(ORMInterface::class);
        $database = $container->get(DatabaseManager::class)->database();
        $schema = new \Cycle\ORM\Schema([
            'record' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['created' => 'carbon']],
        ]);

        $handler = $orm->getFactory()->typecast($schema, $database, 'record');
        self::assertInstanceOf(CastableInterface::class, $handler);
        self::assertInstanceOf(\Carbon\CarbonImmutable::class, $handler->cast(['created' => '2026-11-25 12:00:00'])['created']);
    }

    public function testApplicationCanRegisterAdditionalTypecastHandlers(): void
    {
        $container = $this->container([ConfigKey::TYPECAST_HANDLERS => [ExampleTypecast::class]]);
        $orm = $container->get(ORMInterface::class);
        $database = $container->get(DatabaseManager::class)->database();
        $schema = new \Cycle\ORM\Schema([
            'record' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['name' => 'example']],
        ]);

        $handler = $orm->getFactory()->typecast($schema, $database, 'record');
        self::assertInstanceOf(CastableInterface::class, $handler);
        self::assertSame(['name' => 'EXAMPLE'], $handler->cast(['name' => 'example']));
    }

    public function testUuidRulesDoNotLeakAcrossRoles(): void
    {
        $container = $this->container();
        $factory = $container->get(ORMInterface::class)->getFactory();
        $database = $container->get(DatabaseManager::class)->database();
        $schema = new \Cycle\ORM\Schema([
            'first' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['id' => 'uuid']],
            'second' => [SchemaInterface::ENTITY => ExampleTypecast::class, SchemaInterface::TYPECAST => ['id' => 'int']],
        ]);

        $first = $factory->typecast($schema, $database, 'first');
        $second = $factory->typecast($schema, $database, 'second');

        self::assertSame(['id' => 7], $second->cast(['id' => '7']));
        self::assertSame(
            '018f0000-0000-7000-8000-000000000001',
            $first->cast(['id' => '018f0000-0000-7000-8000-000000000001'])['id']->toString(),
        );
    }
}

final class ExampleTypecast implements CastableInterface
{
    private array $columns = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $name => $rule) {
            if ($rule === 'example') {
                $this->columns[] = $name;
                unset($rules[$name]);
            }
        }
        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->columns as $name) {
            if (isset($data[$name])) {
                $data[$name] = strtoupper($data[$name]);
            }
        }
        return $data;
    }
}
