<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigKey;
use Componenta\Cycle\ConfigProvider;
use Componenta\DI\ContainerFactory;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidInterface;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use PHPUnit\Framework\Assert;

it('binary uuid survives orm insert lookup update and delete', function (): void {
    $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
    foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $settings['DB_' . $key] = $value;
        }
    }
    $schema = [
        'binary_record' => [
            SchemaInterface::ENTITY => BinaryRecord::class,
            SchemaInterface::DATABASE => 'default',
            SchemaInterface::TABLE => 'cycle_test_binary_records',
            SchemaInterface::PRIMARY_KEY => 'id',
            SchemaInterface::COLUMNS => ['id' => 'id', 'label' => 'label'],
            SchemaInterface::RELATIONS => [],
            SchemaInterface::TYPECAST => ['id' => 'uuid'],
        ],
    ];
    $composition = (new ConfigFactory())->create(
        new Environment($settings),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::ROOT => [ConfigKey::SCHEMA => $schema]],
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $db = $container->get(DatabaseManager::class)->database();
    $type = match ($db->getType()) {
        'Postgres' => 'BYTEA',
        'MySQL' => 'BINARY(16)',
        'SQLServer' => 'VARBINARY(16)',
        default => 'BLOB',
    };

    $db->execute("CREATE TABLE cycle_test_binary_records (id $type NOT NULL PRIMARY KEY, label VARCHAR(64) NOT NULL)");
    try {
        $orm = $container->get(ORMInterface::class);
        $em = $container->get(EntityManagerInterface::class);
        $uuid = Uuid::fromString('018f00ff-0080-7000-80ff-000000000001');
        $other = Uuid::fromString('018f00ff-0080-7000-80ff-000000000002');
        $em->persist(new BinaryRecord($uuid, 'first'));
        $em->persist(new BinaryRecord($other, 'second'));
        $em->run();
        $em->clean(true);

        // Cycle's query criteria accept database values. Use the same
        // role mapper to encode the primary key as the write commands.
        $mapper = $orm->getMapper(BinaryRecord::class);
        $repository = $orm->getRepository(BinaryRecord::class);
        $key = $mapper->uncast(['id' => $uuid])['id'];
        $otherKey = $mapper->uncast(['id' => $other])['id'];
        $record = $repository->findByPK($key);
        Assert::assertInstanceOf(BinaryRecord::class, $record);
        Assert::assertSame($uuid->toString(), $record->id->toString());
        Assert::assertSame('first', $record->label);
        Assert::assertSame('second', $repository->findByPK($otherKey)->label);

        $record->label = 'updated';
        $em->persist($record)->run();
        $em->clean(true);
        $record = $repository->findByPK($key);
        Assert::assertInstanceOf(BinaryRecord::class, $record);
        Assert::assertSame('updated', $record->label);
        Assert::assertSame($uuid->toString(), $record->id->toString());
        Assert::assertSame('second', $repository->findByPK($otherKey)->label);

        $em->delete($record)->run();
        $em->clean(true);
        expect($repository->findByPK($key))->toBeNull();
        Assert::assertSame('second', $repository->findByPK($otherKey)->label);
        Assert::assertSame(1, (int) $db->select('id')->from('cycle_test_binary_records')->count());
    } finally {
        $db->execute('DROP TABLE cycle_test_binary_records');
    }
});

final class BinaryRecord
{
    public function __construct(
        public readonly UuidInterface $id,
        public string $label,
    ) {}
}
