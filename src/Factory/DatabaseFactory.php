<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Cycle\Database\Database;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Psr\Container\ContainerInterface;

final class DatabaseFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): DatabaseInterface
    {
        return $container->get(DatabaseProviderInterface::class)->database();
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
    {
        $provider = $container->get(DatabaseProviderInterface::class);

        // The native manager guarantees a Database instance. An arbitrary
        // provider only promises DatabaseInterface and may return another class.
        if (!$provider instanceof DatabaseManager) {
            return $provider->database();
        }

        return $proxyFactory->makeProxy(
            Database::class,
            static fn(object $proxy): DatabaseInterface => $provider->database(),
        );
    }
}
