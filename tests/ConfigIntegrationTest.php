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
    private function container(array $additionalConfig = [], array $environment = []): \Componenta\Config\ContainerValue {
        $composition = (new ConfigFactory())->create(
            new Environment(array_replace(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'], $environment)),
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

    public function testApplicationFactoryCanReplaceUuidHandlerAndRegisterAdditionalRoleLocalHandlers(): void
    {
        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
            new ApplicationTypecastConfigProvider(),
        );
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        $factory = $container->get(ORMInterface::class)->getFactory();
        $db = $container->get(DatabaseManager::class)->database();
        $schema = new \Cycle\ORM\Schema([
            'first' => [
                SchemaInterface::ENTITY => \stdClass::class,
                SchemaInterface::TYPECAST => ['id' => 'uuid', 'name' => 'application'],
            ],
            'second' => [
                SchemaInterface::ENTITY => ExampleTypecast::class,
                SchemaInterface::TYPECAST => ['other' => 'uuid', 'label' => 'application'],
            ],
        ]);

        $first = $factory->typecast($schema, $db, 'first');
        $second = $factory->typecast($schema, $db, 'second');

        self::assertSame(
            ['id' => 'custom:one', 'name' => 'first:alice', 'other' => 'untouched'],
            $first->cast(['id' => 'one', 'name' => 'alice', 'other' => 'untouched']),
        );
        self::assertSame(
            ['id' => 'untouched', 'other' => 'custom:two', 'label' => 'second:bob'],
            $second->cast(['id' => 'untouched', 'other' => 'two', 'label' => 'bob']),
        );
    }

    public function testPostgresUuidTypecastRoundTripsBinaryDataWithZeroBytes(): void
    {
        if (getenv('CYCLE_TEST_DB_DRIVER') !== 'postgres') {
            self::markTestSkipped('This test requires a configured PostgreSQL database.');
        }
        $env = ['DB_DRIVER' => 'postgres'];
        foreach (['NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA'] as $key) {
            $value = getenv('CYCLE_TEST_DB_' . $key);
            if ($value !== false) {
                $env['DB_' . $key] = $value;
            }
        }
        $container = $this->container(environment: $env);
        $database = $container->get(DatabaseManager::class)->database();
        $factory = $container->get(ORMInterface::class)->getFactory();
        $schema = new \Cycle\ORM\Schema([
            'record' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['id' => 'uuid']],
        ]);

        $handler = $factory->typecast($schema, $database, 'record');
        $uuid = \Componenta\Identity\Uuid::fromString('018f0000-0000-7000-8000-000000000001');
        $data = $handler->uncast(['id' => $uuid]);
        $statement = $database->query('SELECT CAST(? AS BYTEA) AS id', [$data['id']]);
        try {
            $row = $statement->fetch();
        } finally {
            $statement->close();
        }

        self::assertSame($uuid->toString(), $handler->cast($row)['id']->toString());
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


final class ApplicationTypecastConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            \Componenta\Cycle\Typecast\UuidTypecast::class => static fn (\Psr\Container\ContainerInterface $c): ApplicationUuidHandler => new ApplicationUuidHandler(),
            ApplicationRoleHandler::class => static fn (\Psr\Container\ContainerInterface $c, array $context): ApplicationRoleHandler =>
                new ApplicationRoleHandler($context['role']),
        ];
    }

    protected function getConfig(): array
    {
        return [
            ConfigKey::ROOT => [
                ConfigKey::TYPECAST_HANDLERS => [ApplicationRoleHandler::class],
                ConfigKey::BINDINGS => [ApplicationRoleHandler::class],
            ],
        ];
    }
}

final class ApplicationUuidHandler implements CastableInterface
{
    private array $columns = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $column => $rule) {
            if ($rule === 'uuid') {
                $this->columns[] = $column;
                unset($rules[$column]);
            }
        }
        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->columns as $column) {
            if (isset($data[$column])) {
                $data[$column] = 'custom:' . $data[$column];
            }
        }
        return $data;
    }
}

final class ApplicationRoleHandler implements CastableInterface
{
    private array $columns = [];

    public function __construct(private readonly string $role) {}

    public function setRules(array $rules): array
    {
        foreach ($rules as $column => $rule) {
            if ($rule === 'application') {
                $this->columns[] = $column;
                unset($rules[$column]);
            }
        }
        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->columns as $column) {
            if (isset($data[$column])) {
                $data[$column] = $this->role . ':' . $data[$column];
            }
        }
        return $data;
    }
}
