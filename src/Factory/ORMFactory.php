<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\Mapper\LazyGhostMapper;
use App\Cycle\Typecast\EmailTypecast;
use App\Cycle\Typecast\PhoneTypecast;
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

        $factory = new Factory(
            dbal: $container->get(DatabaseManager::class),
            factory: $container->get(CoreFactory::class),
            defaultCollectionFactory: $collectionFactory
        )->withDefaultSchemaClasses([
            SchemaInterface::MAPPER => LazyGhostMapper::class,
            SchemaInterface::TYPECAST_HANDLER => [
                UuidTypecast::class,
                EmailTypecast::class,
                PhoneTypecast::class,
                EnumTypecast::class,
                Typecast::class,
            ],
        ]);


        return new ORM(
            factory: $factory,
            schema: $container->get(SchemaInterface::class),
        );
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory): object
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