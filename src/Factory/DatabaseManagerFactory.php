<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\DatabaseManager;
use Psr\Container\ContainerInterface;

final class DatabaseManagerFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): DatabaseManager
    {
        return new DatabaseManager($container->get(DatabaseConfig::class));
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
    {
        return $proxyFactory->makeLazy(
            DatabaseManager::class,
            fn(object $instance) => $instance->__construct($container->get(DatabaseConfig::class)),
        );
    }
}
