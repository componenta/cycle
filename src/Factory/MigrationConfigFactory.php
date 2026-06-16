<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\ConfigKey;
use Cycle\Migrations\Config\MigrationConfig;
use Psr\Container\ContainerInterface;

final class MigrationConfigFactory
{
    public function __invoke(ContainerInterface $container): MigrationConfig
    {
        /** @var array<string, mixed> $config */
        $config = $container->get('config')[ConfigKey::ROOT] ?? [];

        $migrations = $config[ConfigKey::MIGRATIONS] ?? [];

        return new MigrationConfig([
            'directory' => $migrations['directory'] ?? getcwd() . '/migrations',
            'namespace' => $migrations['namespace'] ?? 'Migration',
            'table' => $migrations['table'] ?? 'migrations',
            'safe' => $migrations['safe'] ?? true,
        ]);
    }
}