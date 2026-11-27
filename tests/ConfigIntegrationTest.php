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
use PHPUnit\Framework\Assert;

function configIntegrationContainer(array $additionalConfig = [], array $environment = []): \Componenta\Config\ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment(array_replace(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'], $environment)),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::ROOT => [ConfigKey::MIGRATIONS => ['namespace' => 'ExampleMigrations'], ...$additionalConfig]],
    );
    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

it('creates database from the configured environment', function (): void {
    $database = configIntegrationContainer()->get(DatabaseManager::class)->database();
    expect($database->getName())->toBe('default');
    expect((int) $database->query('SELECT 1 AS value')->fetch()['value'])->toBe(1);
});

it('creates schema and migration settings from runtime config', function (): void {
    $container = configIntegrationContainer();
    expect(iterator_to_array($container->get(SchemaInterface::class)->getRoles()))->toBe([]);
    expect($container->get(MigrationConfig::class)->getNamespace())->toBe('ExampleMigrations');
});

it('creates default typecasts without application classes', function (): void {
    $container = configIntegrationContainer();
    $orm = $container->get(ORMInterface::class);
    $database = $container->get(DatabaseManager::class)->database();
    $schema = new \Cycle\ORM\Schema([
        'record' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['created' => 'carbon']],
    ]);

    $handler = $orm->getFactory()->typecast($schema, $database, 'record');
    expect($handler)->toBeInstanceOf(CastableInterface::class);
    expect($handler->cast(['created' => '2026-11-25 12:00:00'])['created'])->toBeInstanceOf(\Carbon\CarbonImmutable::class);
});

it('application can register additional typecast handlers', function (): void {
    $container = configIntegrationContainer([ConfigKey::TYPECAST_HANDLERS => [ExampleTypecast::class]]);
    $orm = $container->get(ORMInterface::class);
    $database = $container->get(DatabaseManager::class)->database();
    $schema = new \Cycle\ORM\Schema([
        'record' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['name' => 'example']],
    ]);

    $handler = $orm->getFactory()->typecast($schema, $database, 'record');
    expect($handler)->toBeInstanceOf(CastableInterface::class);
    expect($handler->cast(['name' => 'example']))->toBe(['name' => 'EXAMPLE']);
});

it('uuid rules do not leak across roles', function (): void {
    $container = configIntegrationContainer();
    $factory = $container->get(ORMInterface::class)->getFactory();
    $database = $container->get(DatabaseManager::class)->database();
    $schema = new \Cycle\ORM\Schema([
        'first' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::TYPECAST => ['id' => 'uuid']],
        'second' => [SchemaInterface::ENTITY => ExampleTypecast::class, SchemaInterface::TYPECAST => ['id' => 'int']],
    ]);

    $first = $factory->typecast($schema, $database, 'first');
    $second = $factory->typecast($schema, $database, 'second');

    expect($second->cast(['id' => '7']))->toBe(['id' => 7]);
    expect($first->cast(['id' => '018f0000-0000-7000-8000-000000000001'])['id']->toString())->toBe('018f0000-0000-7000-8000-000000000001');
});

it('application factory can replace uuid handler and register additional role local handlers', function (): void {
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

    expect($first->cast(['id' => 'one', 'name' => 'alice', 'other' => 'untouched']))->toBe(['id' => 'custom:one', 'name' => 'first:alice', 'other' => 'untouched']);
    expect($second->cast(['id' => 'untouched', 'other' => 'two', 'label' => 'bob']))->toBe(['id' => 'untouched', 'other' => 'custom:two', 'label' => 'second:bob']);
});

it('postgres uuid typecast round trips binary data with zero bytes', function (): void {
    if (getenv('CYCLE_TEST_DB_DRIVER') !== 'postgres') {
        Assert::markTestSkipped('This test requires a configured PostgreSQL database.');
    }
    $env = ['DB_DRIVER' => 'postgres'];
    foreach (['NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $env['DB_' . $key] = $value;
        }
    }
    $container = configIntegrationContainer(environment: $env);
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

    Assert::assertSame($uuid->toString(), $handler->cast($row)['id']->toString());
});

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


it('uses a MySQL connection that round trips full UTF-8 text through the default factory', function (): void {
    if (getenv('CYCLE_TEST_DB_DRIVER') !== 'mysql') {
        Assert::markTestSkipped('MySQL connection encoding integration.');
    }
    $env = ['DB_DRIVER' => 'mysql'];
    foreach (['NAME', 'HOST', 'PORT', 'USER', 'PASSWORD'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $env['DB_' . $key] = $value;
        }
    }
    $database = configIntegrationContainer(environment: $env)->get(DatabaseManager::class)->database();
    $value = 'Русский текст, café, 😀, 𐐀';
    try {
        $database->execute('CREATE TABLE cycle_test_connection_utf8 (value VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin)');
        $database->execute('INSERT INTO cycle_test_connection_utf8 (value) VALUES (?)', [new \Cycle\Database\Injection\Parameter($value)]);
        $statement = $database->query('SELECT value FROM cycle_test_connection_utf8');
        try {
            expect($statement->fetchColumn())->toBe($value);
        } finally {
            $statement->close();
        }
        $assertEncoding = static function () use ($database): void {
            $statement = $database->query('SELECT @@character_set_client AS client_charset, @@character_set_connection AS connection_charset, @@character_set_results AS result_charset');
            try {
                $row = $statement->fetch();
                expect($row['client_charset'])->toBe('utf8mb4');
                expect($row['connection_charset'])->toBe('utf8mb4');
                expect($row['result_charset'])->toBe('utf8mb4');
            } finally {
                $statement->close();
            }
        };
        $assertEncoding();

        // Force a different session encoding, then ensure that the driver
        // really reconnects and restores the configured encoding.
        $database->execute('SET NAMES latin1');
        $driver = $database->getDriver();
        $driver->disconnect();
        expect($driver->isConnected())->toBeFalse();
        $assertEncoding();
        expect($driver->isConnected())->toBeTrue();

        $second = 'Повторное подключение, 🚀, 𐐷';
        $database->execute('INSERT INTO cycle_test_connection_utf8 (value) VALUES (?)', [new \Cycle\Database\Injection\Parameter($second)]);
        $statement = $database->query('SELECT value FROM cycle_test_connection_utf8 WHERE value = ?', [new \Cycle\Database\Injection\Parameter($second)]);
        try {
            expect($statement->fetchColumn())->toBe($second);
        } finally {
            $statement->close();
        }
    } finally {
        $database->execute('DROP TABLE IF EXISTS cycle_test_connection_utf8');
    }
});
