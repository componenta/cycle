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
    private function relationMap(bool $withRelation, ?ActiveRelationInterface $relation = null, ?ActiveRelationInterface $second = null): RelationMap {
        $schema = new Schema([
            'record' => [
                SchemaInterface::ENTITY => HydratedEntity::class,
                SchemaInterface::RELATIONS => $withRelation ? [
                    'related' => [
                        Relation::TYPE => Relation::BELONGS_TO,
                        Relation::TARGET => 'target',
                        Relation::SCHEMA => [],
                    ],
                ] + ($second === null ? [] : [
                    'other' => [
                        Relation::TYPE => Relation::BELONGS_TO,
                        Relation::TARGET => 'target',
                        Relation::SCHEMA => [],
                    ],
                ]) : [],
            ],
            'target' => [SchemaInterface::ENTITY => \stdClass::class, SchemaInterface::RELATIONS => []],
        ]);
        $factory = $this->createStub(FactoryInterface::class);
        $primary = $relation ?? $this->createStub(ActiveRelationInterface::class);
        $factory->method('relation')->willReturnCallback(
            static fn (ORMInterface $orm, SchemaInterface $schema, string $role, string $name): ActiveRelationInterface =>
                $name === 'other' ? $second : $primary,
        );
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

    public function testNewReferenceReplacesAnAlreadyLoadedPropertyOnAPartlyLazyEntity(): void
    {
        $old = new \stdClass();
        $replacement = new \stdClass();
        $other = new \stdClass();
        $replacementReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
        $otherReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);

        $firstRelation = $this->createMock(ActiveRelationInterface::class);
        $firstRelation->expects(self::once())->method('resolve')->with($replacementReference, true)->willReturn($replacement);
        $firstRelation->method('collect')->willReturnArgument(0);
        $otherRelation = $this->createMock(ActiveRelationInterface::class);
        $otherRelation->expects(self::once())->method('resolve')->with($otherReference, true)->willReturn($other);
        $otherRelation->method('collect')->willReturnArgument(0);

        $map = $this->relationMap(true, $firstRelation, $otherRelation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, TwoRelationsEntity::class);

        $factory->upgrade($map, $entity, ['id' => 1, 'related' => $old, 'other' => $otherReference]);
        self::assertSame($old, $entity->related);
        $factory->upgrade($map, $entity, ['related' => $replacementReference]);

        self::assertSame($replacement, $entity->related);
        self::assertSame(['related' => $replacement, 'other' => $otherReference], $factory->extractRelations($map, $entity));
        self::assertSame($other, $entity->other);
        self::assertSame(['related' => $replacement, 'other' => $other], $factory->extractRelations($map, $entity));
    }

    public function testInitializedReadonlyRelationDoesNotResolveAnUnusedReference(): void
    {
        $relation = $this->createMock(ActiveRelationInterface::class);
        $relation->expects(self::never())->method('resolve');
        $map = $this->relationMap(true, $relation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, ReadonlyRelationEntity::class);
        $related = new \stdClass();
        $factory->upgrade($map, $entity, ['id' => 1, 'related' => $related]);

        $factory->upgrade($map, $entity, ['related' => $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class)]);

        self::assertSame($related, $entity->related);
        self::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
    }

    public function testStdClassRoundTripsDynamicScalarAndRelationFields(): void
    {
        $related = new \stdClass();
        $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
        $relation = $this->createMock(ActiveRelationInterface::class);
        $relation->expects(self::once())->method('resolve')->with($reference, true)->willReturn($related);
        $relation->method('collect')->willReturnArgument(0);
        $map = $this->relationMap(true, $relation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, \stdClass::class);

        $factory->upgrade($map, $entity, ['id' => 3, 'label' => null, 'related' => $reference]);

        self::assertSame(3, $entity->id);
        self::assertSame($related, $entity->related);
        self::assertSame(['id' => 3, 'label' => null], $factory->extractData($map, $entity));
        self::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
        self::assertSame(['id' => 3, 'label' => null, 'related' => $related], $factory->extractAll($map, $entity));

        $entity->label = 'updated';
        self::assertSame(['id' => 3, 'label' => 'updated'], $factory->extractData($map, $entity));
    }

    public function testDynamicExtractionPreservesRawFieldsAndDoesNotInitializePendingRelations(): void
    {
        $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
        $relation = $this->createMock(ActiveRelationInterface::class);
        $relation->expects(self::never())->method('resolve');
        $map = $this->relationMap(true, $relation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, DynamicHookEntity::class);
        $factory->upgrade($map, $entity, ['id' => 5, 'name' => 'stored', 'related' => $reference]);

        self::assertSame(['id' => 5, 'name' => 'stored'], $factory->extractData($map, $entity));
        self::assertSame(['related' => $reference], $factory->extractRelations($map, $entity));

        $plain = new DynamicHookEntity();
        $plain->id = 6;
        $plain->name = 'another';
        $plain->extra = 'dynamic';
        $plain->related = null;
        self::assertSame(['id' => 6, 'name' => 'another', 'extra' => 'dynamic'], $factory->extractData($map, $plain));
        self::assertSame(['related' => null], $factory->extractRelations($map, $plain));
    }

    public function testPropertyCacheHonorsTheCurrentRelationMap(): void {
        $withRelation = $this->relationMap(true);
        $withoutRelation = $this->relationMap(false);
        $entity = new HydratedEntity();
        $entity->id = 1;
        $entity->related = null;
        $factory = new LazyGhostEntityFactory();
        self::assertSame(['id' => 1], $factory->extractData($withRelation, $entity));
        self::assertSame(['id' => 1, 'related' => null], $factory->extractData($withoutRelation, $entity));

        $entity->id = 2;
        $entity->related = new \stdClass();
        self::assertSame(['id' => 2], $factory->extractData($withRelation, $entity));
        self::assertSame(['id' => 2, 'related' => $entity->related], $factory->extractData($withoutRelation, $entity));

        $other = new CollectionEntity();
        $other->id = 3;
        $other->related = [];
        self::assertSame(['id' => 3], $factory->extractData($withRelation, $other));
        self::assertSame(['id' => 3, 'related' => []], $factory->extractData($withoutRelation, $other));
        self::assertSame(['id' => 2], $factory->extractData($withRelation, $entity));
    }

    public function testExtractionDoesNotKeepAnUnusedRelationMapAlive(): void
    {
        $factory = new LazyGhostEntityFactory();
        $map = $this->relationMap(true);
        $reference = \WeakReference::create($map);
        $entity = new HydratedEntity();
        $entity->id = 1;
        $entity->related = null;

        self::assertSame(['id' => 1], $factory->extractData($map, $entity));
        unset($map);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function testMissingToManyCollectionIsInitializedWhenNoReferenceIsPending(): void
    {
        $relation = $this->createStub(\Cycle\ORM\Relation\HasMany::class);
        $relation->method('collect')->willReturn([]);
        $map = $this->relationMap(true, $relation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, CollectionEntity::class);

        $factory->upgrade($map, $entity, ['id' => 1]);

        self::assertSame([], $entity->related);
        self::assertSame(['related' => []], $factory->extractRelations($map, $entity));
    }

    public function testHydratesAndExtractsPrivatePropertiesDeclaredOnAnAncestor(): void
    {
        $map = $this->relationMap(false);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, InheritedEntity::class);

        $factory->upgrade($map, $entity, ['id' => 7, 'label' => 'stored']);

        self::assertSame(7, $entity->id());
        self::assertSame(['label' => 'stored', 'id' => 7], $factory->extractData($map, $entity));
    }

    public function testPropagatesErrorsFromExplicitDynamicPropertyHandling(): void
    {
        $map = $this->relationMap(false);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, RejectingEntity::class);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unexpected field.');

        $factory->upgrade($map, $entity, ['id' => 1, 'unknown' => 2]);
    }

    public function testIgnoresSchemaOnlyFieldsWithoutCreatingDynamicProperties(): void
    {
        $map = $this->relationMap(false);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, HydratedEntity::class);

        $factory->upgrade($map, $entity, ['id' => 1, 'schema_only' => 2]);

        self::assertSame(1, $entity->id);
        self::assertFalse(property_exists($entity, 'schema_only'));
    }

    public function testExtractionReturnsStoredValuesRatherThanComputedGetterValues(): void
    {
        $map = $this->relationMap(false);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, HookedEntity::class);

        $factory->upgrade($map, $entity, ['name' => 'stored']);

        self::assertSame('STORED', $entity->name);
        self::assertSame(['name' => 'stored'], $factory->extractData($map, $entity));
    }

    public function testReadonlyHydrationAndExtractionDoNotResolvePendingRelations(): void
    {
        $related = new \stdClass();
        $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
        $relation = $this->createMock(ActiveRelationInterface::class);
        $relation->expects(self::once())->method('resolve')->with($reference, true)->willReturn($related);
        $relation->method('collect')->willReturnArgument(0);
        $map = $this->relationMap(true, $relation);
        $factory = new LazyGhostEntityFactory();
        $entity = $factory->create($map, ReadonlyDefaultEntity::class);

        $factory->upgrade($map, $entity, ['id' => 7, 'related' => $reference]);

        self::assertSame(7, $entity->id);
        self::assertSame(['id' => 7, 'label' => 'default'], $factory->extractData($map, $entity));
        self::assertSame(['related' => $reference], $factory->extractRelations($map, $entity));
        self::assertSame($related, $entity->related);
        self::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
    }
}

final class CollectionEntity
{
    public int $id;
    public array $related;
}


class BaseInheritedEntity
{
    private int $id;

    public function id(): int
    {
        return $this->id;
    }
}

final class InheritedEntity extends BaseInheritedEntity
{
    public string $label;
}


final class RejectingEntity
{
    public int $id;

    public function __set(string $name, mixed $value): void
    {
        throw new \DomainException('Unexpected field.');
    }
}


final class HookedEntity
{
    public string $name {
        get => strtoupper($this->name);
    }
}


final class ReadonlyDefaultEntity
{
    public readonly int $id;
    public string $label = 'default';
    public string $uninitialized;
    public ?object $related = null;
}


final class TwoRelationsEntity
{
    public int $id;
    public ?object $related;
    public ?object $other;
}

final class ReadonlyRelationEntity
{
    public int $id;
    public readonly ?object $related;
}


#[\AllowDynamicProperties]
final class DynamicHookEntity
{
    public int $id;
    public string $name {
        get => strtoupper($this->name);
    }
    public string $computed {
        get => throw new \LogicException('Virtual properties must not be evaluated by extraction.');
    }
    public ?object $related;
}
