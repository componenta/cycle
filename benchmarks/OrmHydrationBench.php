<?php

declare(strict_types=1);

namespace Componenta\Cycle\Benchmark;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigKey;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\Mapper\LazyGhostEntityFactory;
use Componenta\DI\ContainerFactory;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\RelationMap;
use Cycle\ORM\SchemaInterface;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(9)]
#[Bench\BeforeMethods('prepare')]
#[Bench\Revs(3)]
final class OrmHydrationBench
{
    private array $fields;
    private RelationMap $map;
    private RelationMap $emptyMap;
    private LazyGhostEntityFactory $factory;
    private object $entity;

    public function prepare(): void
    {
        $fields = [
            'id' => 1, 'first' => 'First', 'last' => 'Last',
            'email' => 'test@example.test', 'phone' => '555', 'city' => 'City',
            'region' => 'Region', 'country' => 'Country', 'address' => 'Address',
            'zip' => '12345', 'age' => 30, 'active' => true,
        ];

        $schema = [
            'record' => [
                SchemaInterface::ENTITY => HydrationRecord::class,
                SchemaInterface::DATABASE => 'default',
                SchemaInterface::TABLE => 'records',
                SchemaInterface::PRIMARY_KEY => 'id',
                SchemaInterface::COLUMNS => array_combine(array_keys($fields), array_keys($fields)),
                SchemaInterface::RELATIONS => [
                    'related' => [
                        Relation::TYPE => Relation::HAS_MANY,
                        Relation::TARGET => 'child',
                        Relation::SCHEMA => [
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'recordId',
                            Relation::CASCADE => false,
                        ],
                    ],
                ],
            ],
            'child' => [
                SchemaInterface::ENTITY => HydrationChild::class,
                SchemaInterface::DATABASE => 'default',
                SchemaInterface::TABLE => 'children',
                SchemaInterface::PRIMARY_KEY => 'id',
                SchemaInterface::COLUMNS => ['id' => 'id', 'recordId' => 'record_id'],
                SchemaInterface::RELATIONS => [],
            ],
        ];

        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
            static fn (): array => [ConfigKey::ROOT => [ConfigKey::SCHEMA => $schema]],
        );
        $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
        $orm = $container->get(ORMInterface::class);
        $map = RelationMap::build($orm, 'record');
        $emptyMap = RelationMap::build($orm, 'child');
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, HydrationRecord::class);
        $factory->upgrade($map, $entity, $fields + ['related' => []]);
        check($factory->extractData($map, $entity) === $fields);
        check($factory->extractRelations($map, $entity) === ['related' => []]);
        check($factory->extractData($emptyMap, $entity) === $fields + ['related' => []]);


        $this->fields = $fields;
        $this->map = $map;
        $this->emptyMap = $emptyMap;
        $this->factory = $factory;
        $this->entity = $entity;
    }

    #[Bench\Warmup(2)]
    public function benchExtractWithRelation20000(): void
    {
        for ($i = 0; $i < 20000; ++$i) {
            $this->factory->extractData($this->map, $this->entity);
        }
    }

    #[Bench\Warmup(2)]
    public function benchExtractWithoutRelation20000(): void
    {
        for ($i = 0; $i < 20000; ++$i) {
            $this->factory->extractData($this->emptyMap, $this->entity);
        }
    }

    #[Bench\Warmup(2)]
    public function benchHydrateAndExtract10000(): void
    {
        for ($i = 0; $i < 10000; ++$i) {
            $entity = $this->factory->create($this->map, HydrationRecord::class);
            $this->factory->upgrade($this->map, $entity, $this->fields + ['related' => []]);
            $this->factory->extractAll($this->map, $entity);
        }
    }

    public function benchColdHydrateAndExtract1000(): void
    {
        for ($i = 0; $i < 1000; ++$i) {
            $factory = new LazyGhostEntityFactory();
            $entity = $factory->create($this->map, HydrationRecord::class);
            $factory->upgrade($this->map, $entity, $this->fields + ['related' => []]);
            $factory->extractAll($this->map, $entity);
        }
    }
}
