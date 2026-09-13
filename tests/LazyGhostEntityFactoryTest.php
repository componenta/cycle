<?php
declare(strict_types=1);
namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Mapper\LazyGhostEntityFactory;
use Cycle\ORM\FactoryInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Relation\ActiveRelationInterface;
use Cycle\ORM\RelationMap;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HydratedEntity {
    public int $id;
    public ?object $related;
}

final class LazyGhostEntityFactoryTest extends TestCase {
    private function relationMap(bool $withRelation, ?ActiveRelationInterface $relation = null): RelationMap {
        $schema = new Schema([
            'record' => [
                SchemaInterface::ENTITY => HydratedEntity::class,
                SchemaInterface::RELATIONS => $withRelation ? [
                    'related' => [
                        Relation::TYPE => Relation::BELONGS_TO,
                        Relation::TARGET => 'target',
                        Relation::SCHEMA => [],
                    ],
                ] : [],
            ],
            'target' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::RELATIONS => []],
        ]);
        $factory = $this->createStub(FactoryInterface::class);
        $factory->method('relation')->willReturn($relation ?? $this->createStub(ActiveRelationInterface::class));
        $orm = $this->createStub(ORMInterface::class);
        $orm->method('getFactory')->willReturn($factory);
        $orm->method('getSchema')->willReturn($schema);
        return RelationMap::build($orm, 'record');
    }

    public static function loadedRelations(): iterable {
        yield 'loaded object' => [new \stdClass()];
        yield 'loaded null' => [null];
    }

    #[DataProvider('loadedRelations')]
    public function testHydrationPreservesAlreadyLoadedRelations(?object $related): void {
        $relations = $this->relationMap(true);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($relations, HydratedEntity::class);
        $factory->upgrade($relations, $entity, ['id' => 1, 'related' => $related]);
        self::assertSame(1, $entity->id);
        self::assertSame($related, $entity->related);
    }

    public function testScalarRehydrationPreservesAPendingRelation(): void {
        $related = new \stdClass();
        $relation = $this->createStub(ActiveRelationInterface::class);
        $relation->method('resolve')->willReturn($related);
        $relation->method('collect')->willReturnArgument(0);
        $map = $this->relationMap(true, $relation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, HydratedEntity::class);
        $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
        $factory->upgrade($map, $entity, ['id' => 1, 'related' => $reference]);

        $factory->upgrade($map, $entity, ['id' => 2]);

        self::assertSame(2, $entity->id);
        self::assertSame($related, $entity->related);
    }

    public function testLoadedRelationReplacesAPendingReference(): void {
        $map = $this->relationMap(true);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, HydratedEntity::class);
        $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
        $factory->upgrade($map, $entity, ['id' => 1, 'related' => $reference]);
        $related = new \stdClass();

        $factory->upgrade($map, $entity, ['related' => $related]);

        self::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
        self::assertSame($related, $entity->related);
    }

    public function testPropertyCacheHonorsTheCurrentRelationMap(): void {
        $withRelation = $this->relationMap(true);
        $withoutRelation = $this->relationMap(false);
        $entity = new HydratedEntity();
        $entity->id = 1;
        $entity->related = null;
        $factory = new LazyGhostEntityFactory();
        self::assertSame(['id' => 1], $factory->extractData($withRelation, $entity));
        self::assertSame(
            (new LazyGhostEntityFactory())->extractData($withoutRelation, $entity),
            $factory->extractData($withoutRelation, $entity),
        );
    }
}
