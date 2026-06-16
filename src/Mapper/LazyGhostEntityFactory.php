<?php

declare(strict_types=1);

namespace Componenta\Cycle\Mapper;

use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Relation\ActiveRelationInterface;
use Cycle\ORM\Relation\HasMany;
use Cycle\ORM\Relation\ManyToMany;
use Cycle\ORM\Relation\Morphed\MorphedHasMany;
use Cycle\ORM\Relation\ShadowHasMany;
use Cycle\ORM\RelationMap;

/**
 * Entity factory using PHP 8.4 lazy ghost objects.
 *
 * Replaces Cycle ORM's ProxyEntityFactory which is incompatible with PHP 8.4:
 * - private(set) properties (isPublic() returns true, hydrator skips Closure::bind)
 * - final classes (proxy cannot extend)
 * - (array) cast (mangles private(set) property names, skips virtual properties)
 *
 * Uses ReflectionClass::newLazyGhost() for constructor-less instantiation,
 * ReflectionProperty::setRawValueWithoutLazyInitialization() for hydration,
 * and WeakMap for tracking pending relation references.
 */
class LazyGhostEntityFactory
{
    /**
     * Pending relation references per entity.
     *
     * @var \WeakMap<object, array<string, array{ref: ReferenceInterface, relation: ActiveRelationInterface}>>
     */
    protected \WeakMap $pendingRefs;

    /** @var array<class-string, \ReflectionClass> */
    protected array $reflectionCache = [];

    /**
     * Cached property lookups: false means "no usable property".
     *
     * @var array<class-string, array<string, \ReflectionProperty|false>>
     */
    protected array $propertyCache = [];

    /**
     * Cached list of extractable (non-static, non-virtual, non-relation) properties per class.
     *
     * @var array<class-string, list<\ReflectionProperty>>
     */
    protected array $extractableProperties = [];

    public function __construct()
    {
        $this->pendingRefs = new \WeakMap();
    }

    /**
     * Create an empty entity instance (without calling its constructor).
     *
     * The returned ghost resolves pending relation references on first property access.
     */
    public function create(RelationMap $relMap, string $sourceClass): object
    {
        $reflection = $this->getReflection($sourceClass);

        return $reflection->newLazyGhost($this->createInitializer($sourceClass, $relMap));
    }

    private function isToManyRelation(ActiveRelationInterface $relation): bool
    {
        return $relation instanceof ManyToMany
            || $relation instanceof HasMany
            || $relation instanceof MorphedHasMany
            || $relation instanceof ShadowHasMany;
    }

    /**
     * Hydrate an entity with column values and relation data.
     *
     * Two-phase approach is critical for BelongsTo relations:
     * Cycle ORM passes both the inner key (e.g. `role_id`) and the relation
     * (e.g. `role` as ReferenceInterface) in $data. Setting scalar properties before
     * registering relation refs can trigger the ghost initializer via the fallback path
     * (`@$entity->$prop = $value`), leaving relation properties uninitialized forever.
     */
    public function upgrade(RelationMap $relMap, object $entity, array $data): object
    {
        $reflection = $this->getReflection($entity::class);
        $relations = $relMap->getRelations();
        $isLazyUninitialized = $reflection->isUninitializedLazyObject($entity);

        $newPending = [];
        $scalarData = [];

        foreach ($data as $property => $value) {
            $relation = $relations[$property] ?? null;

            if ($relation !== null && $value instanceof ReferenceInterface) {
                if ($isLazyUninitialized) {
                    $newPending[$property] = ['ref' => $value, 'relation' => $relation];
                } else {
                    $resolved = $relation->collect($relation->resolve($value, true));
                    $prop = $this->getProperty($reflection, $property);

                    if ($prop !== null && !($prop->isReadOnly() && $prop->isInitialized($entity))) {
                        $prop->setValue($entity, $resolved);
                    }
                }
            } elseif ($relation === null) {
                $scalarData[$property] = $value;
            }
        }

        if ($newPending !== []) {
            $existing = $this->pendingRefs[$entity] ?? [];
            $this->pendingRefs[$entity] = $existing + $newPending;
        }

        // Phase 2: safe to touch the entity - all relation refs are registered.
        foreach ($scalarData as $property => $value) {
            $prop = $this->getProperty($reflection, $property);

            if ($prop !== null) {
                if ($prop->isReadOnly() && $prop->isInitialized($entity)) {
                    continue;
                }

                $prop->setRawValueWithoutLazyInitialization($entity, $value);
            } else {
                // Dynamic property or virtual - fallback (matches ClosureHydrator behavior).
                try {
                    @$entity->{$property} = $value;
                } catch (\Throwable) {
                }
            }
        }

        if ($isLazyUninitialized && $newPending === []) {
            $reflection->markLazyObjectAsInitialized($entity);
        }

        return $entity;
    }

    /**
     * Extract all non-relation property values.
     *
     * @return array<string, mixed>
     */
    public function extractData(RelationMap $relMap, object $entity): array
    {
        $result = [];

        foreach ($this->getExtractableProperties($entity::class, $relMap) as $prop) {
            if (!$prop->isInitialized($entity)) {
                continue;
            }

            $result[$prop->getName()] = $prop->getValue($entity);
        }

        return $result;
    }

    /**
     * Extract relation values.
     *
     * For pending (unresolved) references, returns the ReferenceInterface
     * without triggering resolution (preserves laziness for ORM internals).
     *
     * @return array<string, mixed>
     */
    public function extractRelations(RelationMap $relMap, object $entity): array
    {
        $result = [];
        $pending = $this->pendingRefs[$entity] ?? [];
        $reflection = $this->getReflection($entity::class);

        foreach (array_keys($relMap->getRelations()) as $name) {
            if (isset($pending[$name])) {
                $result[$name] = $pending[$name]['ref'];
                continue;
            }

            $prop = $this->getProperty($reflection, $name);

            if ($prop !== null && $prop->isInitialized($entity)) {
                $result[$name] = $prop->getValue($entity);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function extractAll(RelationMap $relMap, object $entity): array
    {
        return $this->extractData($relMap, $entity) + $this->extractRelations($relMap, $entity);
    }

    /**
     * Fired on first access to any uninitialized property: resolves all pending
     * relation references, fills any remaining ToMany relation property with an
     * empty collection (Cycle does not pass values for unloaded ToMany relations,
     * leaving the typed property otherwise uninitialized), and clears the WeakMap
     * entry.
     */
    protected function createInitializer(string $class, RelationMap $relMap): callable
    {
        return function (object $entity) use ($class, $relMap): void {
            $refs = $this->pendingRefs[$entity] ?? [];
            $reflection = $this->getReflection($class);

            foreach ($refs as $name => $info) {
                $resolved = $info['relation']->collect(
                    $info['relation']->resolve($info['ref'], true),
                );

                $prop = $this->getProperty($reflection, $name);
                $prop?->setRawValueWithoutLazyInitialization($entity, $resolved);
            }

            foreach ($relMap->getRelations() as $name => $relation) {
                if (isset($refs[$name]) || !$this->isToManyRelation($relation)) {
                    continue;
                }

                $prop = $this->getProperty($reflection, $name);

                if ($prop === null || $prop->isInitialized($entity)) {
                    continue;
                }

                $prop->setRawValueWithoutLazyInitialization($entity, $relation->collect([]));
            }

            unset($this->pendingRefs[$entity]);
        };
    }

    protected function getReflection(string $class): \ReflectionClass
    {
        return $this->reflectionCache[$class] ??= new \ReflectionClass($class);
    }

    /**
     * Returns null for non-existent, static, or virtual (hook-only) properties.
     */
    protected function getProperty(\ReflectionClass $reflection, string $name): ?\ReflectionProperty
    {
        $className = $reflection->getName();

        if (!\array_key_exists($name, $this->propertyCache[$className] ?? [])) {
            $this->propertyCache[$className][$name] = $this->resolveProperty($reflection, $name);
        }

        $cached = $this->propertyCache[$className][$name];

        return $cached === false ? null : $cached;
    }

    /**
     * Keyed by class name only - the RelationMap is stable after ORM boot.
     *
     * @return list<\ReflectionProperty>
     */
    protected function getExtractableProperties(string $class, RelationMap $relMap): array
    {
        if (!isset($this->extractableProperties[$class])) {
            $reflection = $this->getReflection($class);
            $relations = $relMap->getRelations();
            $properties = [];

            foreach ($reflection->getProperties() as $prop) {
                if ($prop->isStatic() || $prop->isVirtual() || isset($relations[$prop->getName()])) {
                    continue;
                }

                $properties[] = $prop;
            }

            $this->extractableProperties[$class] = $properties;
        }

        return $this->extractableProperties[$class];
    }

    protected function resolveProperty(\ReflectionClass $reflection, string $name): \ReflectionProperty|false
    {
        if (!$reflection->hasProperty($name)) {
            return false;
        }

        $prop = $reflection->getProperty($name);

        if ($prop->isStatic() || $prop->isVirtual()) {
            return false;
        }

        return $prop;
    }
}
