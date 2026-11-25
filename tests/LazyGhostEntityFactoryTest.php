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

it('preserves already loaded relations during hydration', function (?object $related): void {
    $relations = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($relations, HydratedEntity::class);
    $factory->upgrade($relations, $entity, ['id' => 1, 'related' => $related]);
    expect($entity->id)->toBe(1);
    expect($entity->related)->toBe($related);
})->with(static function (): iterable {
    yield 'loaded object' => [new \stdClass()];
    yield 'loaded null' => [null];
});

it('preserves a pending relation during scalar rehydration', function (): void {
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

    expect($entity->id)->toBe(2);
    expect($entity->related)->toBe($related);
});

it('replaces a pending reference with a loaded relation', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, HydratedEntity::class);
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $reference]);
    $related = new \stdClass();

    $factory->upgrade($map, $entity, ['related' => $related]);

    expect($factory->extractRelations($map, $entity))->toBe(['related' => $related]);
    expect($entity->related)->toBe($related);
});

it('replaces an already loaded relation on a partly lazy entity', function (): void {
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
    expect($entity->related)->toBe($old);
    $factory->upgrade($map, $entity, ['related' => $replacementReference]);

    expect($entity->related)->toBe($replacement);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $replacement, 'other' => $otherReference]);
    expect($entity->other)->toBe($other);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $replacement, 'other' => $other]);
});

it('exposes current scalar values and pending references during eager resolution', function (): void {
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
            expect($entity->id)->toBe(2);
            // Resolving this relation reenters the entity's initializer.
            // The initializer must see the new reference, never the old one.
            expect($entity->other)->toBe($newOther);
            return $replacement;
        });
    $firstRelation->method('collect')->willReturnArgument(0);

    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $firstRelation, $otherRelation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, TwoRelationsEntity::class);
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $old, 'other' => $oldReference]);

    // Deliberately put the eager relation first.
    $factory->upgrade($map, $entity, ['related' => $replacementReference, 'other' => $newReference, 'id' => 2]);

    expect($entity->related)->toBe($replacement);
    expect($entity->other)->toBe($newOther);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $replacement, 'other' => $newOther]);
});

it('does not resolve unused references for initialized readonly relations', function (): void {
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->never())->method('resolve');
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, ReadonlyRelationEntity::class);
    $related = new \stdClass();
    $factory->upgrade($map, $entity, ['id' => 1, 'related' => $related]);

    $factory->upgrade($map, $entity, ['related' => $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class)]);

    expect($entity->related)->toBe($related);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $related]);
});

it('round trips dynamic scalar and relation fields on stdClass', function (): void {
    $related = new \stdClass();
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->once())->method('resolve')->with($reference, true)->willReturn($related);
    $relation->method('collect')->willReturnArgument(0);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, \stdClass::class);

    $factory->upgrade($map, $entity, ['id' => 3, 'label' => null, 'related' => $reference]);

    expect($entity->id)->toBe(3);
    expect($entity->related)->toBe($related);
    expect($factory->extractData($map, $entity))->toBe(['id' => 3, 'label' => null]);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $related]);
    expect($factory->extractAll($map, $entity))->toBe(['id' => 3, 'label' => null, 'related' => $related]);

    $entity->label = 'updated';
    expect($factory->extractData($map, $entity))->toBe(['id' => 3, 'label' => 'updated']);
});

it('extracts dynamic fields without initializing pending relations', function (): void {
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->never())->method('resolve');
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, DynamicHookEntity::class);
    $factory->upgrade($map, $entity, ['id' => 5, 'name' => 'stored', 'related' => $reference]);

    expect($factory->extractData($map, $entity))->toBe(['id' => 5, 'name' => 'stored']);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $reference]);

    $plain = new DynamicHookEntity();
    $plain->id = 6;
    $plain->name = 'another';
    $plain->extra = 'dynamic';
    $plain->related = null;
    expect($factory->extractData($map, $plain))->toBe(['id' => 6, 'name' => 'another', 'extra' => 'dynamic']);
    expect($factory->extractRelations($map, $plain))->toBe(['related' => null]);
});

it('publishes declared fields before dynamic assignments can initialize relations', function (string $class): void {
    $related = new \stdClass();
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $entity = null;

    $relation->expects($this->once())->method('resolve')->with($reference, true)
        ->willReturnCallback(static function () use (&$entity, $related): object {
            expect($entity->id)->toBe(42);
            expect($entity->label)->toBe('current');
            return $related;
        });
    $relation->method('collect')->willReturnArgument(0);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, $class);

    // ORM column order must not cause the relation resolver to observe
    // partially hydrated declared fields.
    $factory->upgrade($map, $entity, [
        'extra' => 'value',
        'id' => 42,
        'related' => $reference,
        'label' => 'current',
    ]);

    expect($entity->id)->toBe(42)
        ->and($entity->label)->toBe('current')
        ->and($entity->related)->toBe($related)
        ->and($factory->extractRelations($map, $entity))->toBe(['related' => $related]);

    if ($entity instanceof MagicHydrationEntity) {
        expect($entity->observed)->toBe(['extra' => ['value', 42, 'current', $related]]);
    } else {
        expect($entity->extra)->toBe('value');
    }
})->with([
    'dynamic property' => [DynamicHydrationEntity::class],
    'magic setter' => [MagicHydrationEntity::class],
]);

it('honors the current relation map when extracting fields', function (): void {
    $withRelation = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $withoutRelation = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $entity = new HydratedEntity();
    $entity->id = 1;
    $entity->related = null;
    $factory = new LazyGhostEntityFactory();
    expect($factory->extractData($withRelation, $entity))->toBe(['id' => 1]);
    expect($factory->extractData($withoutRelation, $entity))->toBe(['id' => 1, 'related' => null]);

    $entity->id = 2;
    $entity->related = new \stdClass();
    expect($factory->extractData($withRelation, $entity))->toBe(['id' => 2]);
    expect($factory->extractData($withoutRelation, $entity))->toBe(['id' => 2, 'related' => $entity->related]);

    $other = new CollectionEntity();
    $other->id = 3;
    $other->related = [];
    expect($factory->extractData($withRelation, $other))->toBe(['id' => 3]);
    expect($factory->extractData($withoutRelation, $other))->toBe(['id' => 3, 'related' => []]);
    expect($factory->extractData($withRelation, $entity))->toBe(['id' => 2]);
});

it('does not retain unused relation maps after extraction', function (): void {
    $factory = new LazyGhostEntityFactory();
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true);
    $reference = \WeakReference::create($map);
    $entity = new HydratedEntity();
    $entity->id = 1;
    $entity->related = null;

    expect($factory->extractData($map, $entity))->toBe(['id' => 1]);
    unset($map);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

it('initializes missing to-many collections when no reference is pending', function (): void {
    $relation = $this->createStub(\Cycle\ORM\Relation\HasMany::class);
    $relation->method('collect')->willReturn([]);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, CollectionEntity::class);

    $factory->upgrade($map, $entity, ['id' => 1]);

    expect($entity->related)->toBe([]);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => []]);
});

it('hydrates and extracts private properties declared on an ancestor', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, InheritedEntity::class);

    $factory->upgrade($map, $entity, ['id' => 7, 'label' => 'stored']);

    expect($entity->id())->toBe(7);
    expect($factory->extractData($map, $entity))->toBe(['label' => 'stored', 'id' => 7]);
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

    expect($entity->id)->toBe(1);
    expect(property_exists($entity, 'schema_only'))->toBeFalse();
});

it('extraction returns stored values rather than computed getter values', function (): void {
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), false);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, HookedEntity::class);

    $factory->upgrade($map, $entity, ['name' => 'stored']);

    expect($entity->name)->toBe('STORED');
    expect($factory->extractData($map, $entity))->toBe(['name' => 'stored']);
});

it('preserves pending relations during readonly hydration and extraction', function (): void {
    $related = new \stdClass();
    $reference = $this->createStub(\Cycle\ORM\Reference\ReferenceInterface::class);
    $relation = $this->createMock(ActiveRelationInterface::class);
    $relation->expects($this->once())->method('resolve')->with($reference, true)->willReturn($related);
    $relation->method('collect')->willReturnArgument(0);
    $map = lazyGhostEntityFactoryRelationMap($this->createStub(...), true, $relation);
    $factory = new LazyGhostEntityFactory();
    $entity = $factory->create($map, ReadonlyDefaultEntity::class);

    $factory->upgrade($map, $entity, ['id' => 7, 'related' => $reference]);

    expect($entity->id)->toBe(7);
    expect($factory->extractData($map, $entity))->toBe(['id' => 7, 'label' => 'default']);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $reference]);
    expect($entity->related)->toBe($related);
    expect($factory->extractRelations($map, $entity))->toBe(['related' => $related]);
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

#[\AllowDynamicProperties]
final class DynamicHydrationEntity
{
    public int $id;
    public string $label;
    public ?object $related;
}

final class MagicHydrationEntity
{
    public int $id;
    public string $label;
    public ?object $related;
    public array $observed = [];

    public function __set(string $name, mixed $value): void
    {
        $this->observed[$name] = [$value, $this->id, $this->label, $this->related];
    }
}
