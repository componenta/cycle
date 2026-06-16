<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Cycle\Database\Database;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use Psr\Container\ContainerInterface;

final class DatabaseFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): DatabaseInterface
    {
        return $container->get(DatabaseProviderInterface::class)->database();
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory): object
    {
        // The provider returns an opaque Database subclass - virtual proxy
        // is the only viable strategy. Forward all DatabaseInterface calls
        // to the real instance, materialised on first observable access.
        return $proxyFactory->makeProxy(
            Database::class,
            fn(object $proxy): DatabaseInterface => $this->__invoke($container),
        );
    }
}
