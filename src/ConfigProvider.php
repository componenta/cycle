<?php

declare(strict_types=1);

namespace Componenta\Cycle;

use Componenta\Cycle\Mapper\LazyGhostEntityFactory;
use Componenta\Cycle\Factory\CoreFactoryFactory;
use Componenta\Cycle\Factory\DatabaseConfigFactory;
use Componenta\Cycle\Factory\DatabaseFactory;
use Componenta\Cycle\Factory\DatabaseManagerFactory;
use Componenta\Cycle\Factory\EntityManagerFactory;
use Componenta\Cycle\Factory\MigrationConfigFactory;
use Componenta\Cycle\Factory\MigrationRepositoryFactory;
use Componenta\Cycle\Factory\MigratorFactory;
use Componenta\Cycle\Factory\ORMFactory;
use Componenta\Cycle\Factory\SchemaFactory;
use Componenta\Cycle\Typecast\UuidTypecast;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\Migrations\Config\MigrationConfig;
use Cycle\Migrations\FileRepository;
use Cycle\Migrations\Migrator;
use Cycle\Migrations\RepositoryInterface;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use Spiral\Core\FactoryInterface as CoreFactory;

class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            // Database
            DatabaseConfig::class => DatabaseConfigFactory::class,
            DatabaseManager::class => DatabaseManagerFactory::class,
            DatabaseInterface::class => DatabaseFactory::class,

            // ORM
            ORM::class => ORMFactory::class,
            EntityManager::class => EntityManagerFactory::class,
            CoreFactory::class => CoreFactoryFactory::class,
            SchemaInterface::class => SchemaFactory::class,

            // Migrations
            MigrationConfig::class => MigrationConfigFactory::class,
            FileRepository::class => MigrationRepositoryFactory::class,
            Migrator::class => MigratorFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            DatabaseProviderInterface::class => DatabaseManager::class,
            ORMInterface::class => ORM::class,
            EntityManagerInterface::class => EntityManager::class,
            RepositoryInterface::class => FileRepository::class,
        ];
    }

    protected function getConfig(): array
    {
        return [
            ConfigKey::ROOT => [
                ConfigKey::BINDINGS => [
                    UuidTypecast::class,
                    ORMInterface::class,
                    EntityManagerInterface::class,
                    LazyGhostEntityFactory::class,
                ],

                ConfigKey::ENTITIES => [
                    'directories' => [getcwd() . '/app/Entity'],
                ],

                ConfigKey::MIGRATIONS => [
                    'directory' => getcwd() . '/app/Cycle/Migrations',
                    'namespace' => 'App\Cycle\Migrations',
                    'table' => 'migrations',
                    'safe' => true,
                ],
            ],
        ];
    }
}
