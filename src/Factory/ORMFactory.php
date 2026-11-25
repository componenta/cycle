<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Config\Config;
use Componenta\Cycle\ConfigKey;
use Componenta\Cycle\Mapper\LazyGhostMapper;
use Componenta\Cycle\Typecast\CarbonTypecast;
use Componenta\Cycle\Typecast\EnumTypecast;
use Componenta\Cycle\Typecast\UuidTypecast;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Collection\ArrayCollectionFactory;
use Cycle\ORM\Collection\CollectionFactoryInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Parser\TypecastInterface;
use Cycle\ORM\SchemaInterface;
use Psr\Container\ContainerInterface;
use Spiral\Core\FactoryInterface as CoreFactory;

final class ORMFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): ORM
    {
        $collectionFactory = $container->has(CollectionFactoryInterface::class)
            ? $container->get(CollectionFactoryInterface::class)
            : new ArrayCollectionFactory();

        $additionalTypecasts = $container->has(Config::class)
            ? ($container->get(Config::class)->array(ConfigKey::ROOT, [])[ConfigKey::TYPECAST_HANDLERS] ?? [])
            : [];
        if (!is_array($additionalTypecasts) || !array_is_list($additionalTypecasts)) {
            throw new \InvalidArgumentException('Cycle typecast handlers must be a list of class names.');
        }
        foreach ($additionalTypecasts as $handler) {
            if (!is_string($handler) || !is_subclass_of($handler, TypecastInterface::class)) {
                throw new \InvalidArgumentException('Invalid Cycle typecast handler class.');
            }
        }

        $factory = new Factory(
            dbal: $container->get(DatabaseManager::class),
            factory: $container->get(CoreFactory::class),
            defaultCollectionFactory: $collectionFactory
        )->withDefaultSchemaClasses([
            SchemaInterface::MAPPER => LazyGhostMapper::class,
            SchemaInterface::TYPECAST_HANDLER => [
                UuidTypecast::class,
                ...$additionalTypecasts,
                CarbonTypecast::class,
                EnumTypecast::class,
                Typecast::class,
            ],
        ]);


        return new ORM(
            factory: $factory,
            schema: $container->get(SchemaInterface::class),
        );
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
    {
        // ORM construction builds a Factory + schema before instantiation;
        // delegate the entire body to first observable access via virtual
        // proxy so the heavy graph (DatabaseManager + CoreFactory + Schema)
        // never materialises on bootstrap of read-only public requests.
        return $proxyFactory->makeProxy(
            ORM::class,
            fn(object $proxy): ORM => $this->__invoke($container),
        );
    }
}