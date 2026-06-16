<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use Psr\Container\ContainerInterface;

final class EntityManagerFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): EntityManager
    {
        return new EntityManager(
            $container->get(ORMInterface::class),
        );
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory): object
    {
        return $proxyFactory->makeLazy(
            EntityManager::class,
            fn(object $instance) => $instance->__construct($container->get(ORMInterface::class)),
        );
    }
}
