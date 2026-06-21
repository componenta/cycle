<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Migrations\Config\MigrationConfig;
use Cycle\Migrations\Migrator;
use Cycle\Migrations\RepositoryInterface;
use Psr\Container\ContainerInterface;

final class MigratorFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): Migrator
    {
        return new Migrator(
            $container->get(MigrationConfig::class),
            $container->get(DatabaseManager::class),
            $container->get(RepositoryInterface::class),
        );
    }

    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
    {
        return $proxyFactory->makeLazy(
            Migrator::class,
            fn(object $instance) => $instance->__construct(
                $container->get(MigrationConfig::class),
                $container->get(DatabaseManager::class),
                $container->get(RepositoryInterface::class),
            ),
        );
    }
}
