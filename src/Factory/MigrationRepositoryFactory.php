<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Cycle\Migrations\Config\MigrationConfig;
use Cycle\Migrations\FileRepository;
use Psr\Container\ContainerInterface;

final class MigrationRepositoryFactory
{
    public function __invoke(ContainerInterface $container): FileRepository
    {
        return new FileRepository(
            $container->get(MigrationConfig::class),
        );
    }
}