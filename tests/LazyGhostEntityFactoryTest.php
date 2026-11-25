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
use PHPUnit\Framework\Assert;

final class HydratedEntity {
    public int $id;
    public ?object $related;
}

function lazyGhostEntityFactoryRelationMap(\Closure $createStub, bool $withRelation, ?ActiveRelationInterface $relation = null, ?ActiveRelationInterface $second = null): RelationMap
{
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
    $factory = $createStub(FactoryInterface::class);
    $primary = $relation ?? $createStub(ActiveRelationInterface::class);
    $factory->method('relation')->willReturnCallback(
        static fn (ORMInterface $orm, SchemaInterface $schema, string $role, string $name): ActiveRelationInterface =>
            $name === 'other' ? $second : $primary,
    );
    $orm = $createStub(ORMInterface::class);
    $orm->method('getFactory')->willReturn($factory);
    $orm->method('getSchema')->willReturn($schema);
    return RelationMap::build($orm, 'record');
}

it('hydration preserves already loaded relations', function (?object $related): void {
    $relations = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($relations, HydratedEntity::class);
    $factory->upgrade($relations, $entity, ['id' => 1, 'related' => $related]);
    Assert::assertSame(1, $entity->id);
    Assert::assertSame($related, $entity->related);
})->with(static function (): iterable {
    yield 'loaded object' => [new \stdClass()];
    yield 'loaded null' => [null];
});

it('scalar rehydration preserves a pending relation', function (): void {
    $related = new \stdClass();
    $relation = $this->createStub(ActiveRelationInterface::class);
    $relation->method('resolve')->willReturn($related);
    $relation->method('collect')->willReturnArgument(0);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, HydratedEntity::class);
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $reference]);

    $factory->upgrade($map, $entity, ['id' => 2]);

    Assert::assertSame(2, $entity->id);
    Assert::assertSame($related, $entity->related);
});

it('loaded relation replaces a pending reference', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, HydratedEntity::class);
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $reference]);
    $related = new \stdClass();

    $factory->upgrade($map, $entity, ['related' => $related]);

    Assert::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
    Assert::assertSame($related, $entity->related);
});

it('new reference replaces an already loaded property on a partly lazy entity', function (): void {
    $old = new \stdClass();
    $replacement = new \stdClass();
    $other = new \stdClass();
    $replacementReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $otherReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);

    $firstRelation = $this->createMock(ActiveRelationInterface::class);
    $firstRelation->expects($this->once())->method('resolve')->with($replacementReference, true)->willReturn($replacement);
    $firstRelation->method('collect')->willReturnArgument(0);
    $otherRelation = $this->createMock(ActiveRelationInterface::class);
    $otherRelation->expects($this->once())->method('resolve')->with($otherReference, true)->willReturn($other);
    $otherRelation->method('collect')->willReturnArgument(0);

    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $firstRelation, $otherRelation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, TwoRelationsEntity::class);

    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $old, 'other' => $otherReference]);
    Assert::assertSame($old, $entity->related);
    $factory->upgrade($map, $entity, ['related' => $replacementReference]);

    Assert::assertSame($replacement, $entity->related);
    Assert::assertSame(['related' => $replacement, 'other' => $otherReference], $factory->extractRelations($map, $entity));
    Assert::assertSame($other, $entity->other);
    Assert::assertSame(['related' => $replacement, 'other' => $other], $factory->extractRelations($map, $entity));
});

it('eager relation resolution sees the latest scalar and pending relation state', function (): void {
    $old = new \stdClass();
    $replacement = new \stdClass();
    $newOther = new \stdClass();
    $oldReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $newReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $replacementReference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);

    $otherRelation = $this->createMock(ActiveRelationInterface::class);
    $otherRelation->expects($this->once())->method('resolve')->with($newReference, true)->willReturn($newOther);
    $otherRelation->method('collect')->willReturnArgument(0);

    $entity = null;
    $firstRelation = $this->createMock(ActiveRelationInterface::class);
    $firstRelation->expects($this->once())->method('resolve')->with($replacementReference, true)
        ->willReturnCallback(static function () use (&$entity, $newOther, $replacement): object {
            Assert::assertSame(2, $entity->id);
            // Resolving this relation reenters the entity's initializer.
            // The initializer must see the new reference, never the old one.
            Assert::assertSame($newOther, $entity->other);
            return $replacement;
        });
    $firstRelation->method('collect')->willReturnArgument(0);

    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $firstRelation, $otherRelation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, TwoRelationsEntity::class);
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $old, 'other' => $oldReference]);

    // Deliberately put the eager relation first.
    $factory->upgrade($map, $entity, ['related' => $replacementReference, 'other' => $newReference, 'id' => 2]);

    Assert::assertSame($replacement, $entity->related);
    Assert::assertSame($newOther, $entity->other);
    Assert::assertSame(['related' => $replacement, 'other' => $newOther], $factory->extractRelations($map, $entity));
});

it('initialized readonly relation does not resolve an unused reference', function (): void {
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->never())->method('resolve');
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, ReadonlyRelationEntity::class);
    $related = new \stdClass();
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $related]);

    $factory->upgrade($map, $entity, ['related' => $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class)]);

    Assert::assertSame($related, $entity->related);
    Assert::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
});

it('std class round trips dynamic scalar and relation fields', function (): void {
    $related = new \stdClass();
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->once())->method('resolve')->with($reference, true)->willReturn($related);
    $relation->method('collect')->willReturnArgument(0);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, \stdClass::class);

    $factory->upgrade($map, $entity, ['id' => 3, 'label' => null, 'related' => $reference]);

    Assert::assertSame(3, $entity->id);
    Assert::assertSame($related, $entity->related);
    Assert::assertSame(['id' => 3, 'label' => null], $factory->extractData($map, $entity));
    Assert::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
    Assert::assertSame(['id' => 3, 'label' => null, 'related' => $related], $factory->extractAll($map, $entity));

    $entity->label = 'updated';
    Assert::assertSame(['id' => 3, 'label' => 'updated'], $factory->extractData($map, $entity));
});

it('dynamic extraction preserves raw fields and does not initialize pending relations', function (): void {
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->never())->method('resolve');
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, DynamicHookEntity::class);
    $factory->upgrade($map, $entity, ['id' => 5, 'name' => 'stored', 'related' => $reference]);

    Assert::assertSame(['id' => 5, 'name' => 'stored'], $factory->extractData($map, $entity));
    Assert::assertSame(['related' => $reference], $factory->extractRelations($map, $entity));

    $plain = new DynamicHookEntity();
    $plain->id = 6;
    $plain->name = 'another';
    $plain->extra = 'dynamic';
    $plain->related = null;
    Assert::assertSame(['id' => 6, 'name' => 'another', 'extra' => 'dynamic'], $factory->extractData($map, $plain));
    Assert::assertSame(['related' => null], $factory->extractRelations($map, $plain));
});

it('property cache honors the current relation map', function (): void {
    $withRelation = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $withoutRelation = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $entity = new HydratedEntity();
    $entity->id = 1;
    $entity->related = null;
    $factory = new LazyGhostEntityFactory();
    Assert::assertSame(['id' => 1], $factory->extractData($withRelation, $entity));
    Assert::assertSame(['id' => 1, 'related' => null], $factory->extractData($withoutRelation, $entity));

    $entity->id = 2;
    $entity->related = new \stdClass();
    Assert::assertSame(['id' => 2], $factory->extractData($withRelation, $entity));
    Assert::assertSame(['id' => 2, 'related' => $entity->related], $factory->extractData($withoutRelation, $entity));

    $other = new CollectionEntity();
    $other->id = 3;
    $other->related = [];
    Assert::assertSame(['id' => 3], $factory->extractData($withRelation, $other));
    Assert::assertSame(['id' => 3, 'related' => []], $factory->extractData($withoutRelation, $other));
    Assert::assertSame(['id' => 2], $factory->extractData($withRelation, $entity));
});

it('extraction does not keep an unused relation map alive', function (): void {
    $factory = new LazyGhostEntityFactory();
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $reference = \WeakReference::create($map);
    $entity = new HydratedEntity();
    $entity->id = 1;
    $entity->related = null;

    Assert::assertSame(['id' => 1], $factory->extractData($map, $entity));
    unset($map);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

it('missing to many collection is initialized when no reference is pending', function (): void {
    $relation = $this->createStub(\Cycle\ORM\Relation\HasMany::class);
    $relation->method('collect')->willReturn([]);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, CollectionEntity::class);

    $factory->upgrade($map, $entity, ['id' => 1]);

    Assert::assertSame([], $entity->related);
    Assert::assertSame(['related' => []], $factory->extractRelations($map, $entity));
});

it('hydrates and extracts private properties declared on an ancestor', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, InheritedEntity::class);

    $factory->upgrade($map, $entity, ['id' => 7, 'label' => 'stored']);

    Assert::assertSame(7, $entity->id());
    Assert::assertSame(['label' => 'stored', 'id' => 7], $factory->extractData($map, $entity));
});

it('propagates errors from explicit dynamic property handling', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, RejectingEntity::class);
    $this->expectException(\DomainException::class);
    $this->expectExceptionMessage('Unexpected field.');

    $factory->upgrade($map, $entity, ['id' => 1, 'unknown' => 2]);
});

it('ignores schema only fields without creating dynamic properties', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, HydratedEntity::class);

    $factory->upgrade($map, $entity, ['id' => 1, 'schema_only' => 2]);

    Assert::assertSame(1, $entity->id);
    Assert::assertFalse(property_exists($entity, 'schema_only'));
});

it('extraction returns stored values rather than computed getter values', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, HookedEntity::class);

    $factory->upgrade($map, $entity, ['name' => 'stored']);

    Assert::assertSame('STORED', $entity->name);
    Assert::assertSame(['name' => 'stored'], $factory->extractData($map, $entity));
});

it('readonly hydration and extraction do not resolve pending relations', function (): void {
    $related = new \stdClass();
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->once())->method('resolve')->with($reference, true)->willReturn($related);
    $relation->method('collect')->willReturnArgument(0);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, ReadonlyDefaultEntity::class);

    $factory->upgrade($map, $entity, ['id' => 7, 'related' => $reference]);

    Assert::assertSame(7, $entity->id);
    Assert::assertSame(['id' => 7, 'label' => 'default'], $factory->extractData($map, $entity));
    Assert::assertSame(['related' => $reference], $factory->extractRelations($map, $entity));
    Assert::assertSame($related, $entity->related);
    Assert::assertSame(['related' => $related], $factory->extractRelations($map, $entity));
});

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
