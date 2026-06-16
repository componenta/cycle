<?php

declare(strict_types=1);

namespace Componenta\Cycle\Mapper;

use Cycle\ORM\Mapper\DatabaseMapper;
use Cycle\ORM\Mapper\Traits\SingleTableTrait;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;

/**
 * Mapper using PHP 8.4 lazy ghost objects instead of Cycle ORM proxy classes.
 *
 * Structurally identical to Cycle\ORM\Mapper\Mapper but delegates entity
 * creation and hydration to LazyGhostEntityFactory instead of ProxyEntityFactory.
 *
 * Supports single-table inheritance via SingleTableTrait.
 */
class LazyGhostMapper extends DatabaseMapper
{
    use SingleTableTrait;

    /** @var class-string */
    protected string $entity;

    protected array $children = [];

    protected LazyGhostEntityFactory $entityFactory;

    public function __construct(
        ORMInterface $orm,
        string $role,
        LazyGhostEntityFactory $entityFactory = new LazyGhostEntityFactory()
    ) {
        parent::__construct($orm, $role);

        $this->schema = $orm->getSchema();
        $this->entity = $this->schema->define($role, SchemaInterface::ENTITY);
        $this->children = $this->schema->define($role, SchemaInterface::CHILDREN) ?? [];
        $this->discriminator = $this->schema->define($role, SchemaInterface::DISCRIMINATOR)
            ?? $this->discriminator;

        $this->entityFactory = $entityFactory;
    }

    public function init(array $data, ?string $role = null): object
    {
        $class = $this->resolveClass($data, $role);

        return $this->entityFactory->create($this->relationMap, $class);
    }

    public function hydrate(object $entity, array $data): object
    {
        $this->entityFactory->upgrade($this->relationMap, $entity, $data);

        return $entity;
    }

    public function extract(object $entity): array
    {
        return $this->entityFactory->extractAll($this->relationMap, $entity);
    }

    public function fetchFields(object $entity): array
    {
        $values = \array_intersect_key(
            $this->entityFactory->extractData($this->relationMap, $entity),
            $this->columns + $this->parentColumns,
        );

        return $values + $this->getDiscriminatorValues($entity);
    }

    public function fetchRelations(object $entity): array
    {
        return $this->entityFactory->extractRelations($this->relationMap, $entity);
    }
}
