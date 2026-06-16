<?php

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\ConfigKey;
use Cycle\ORM\Schema;
use Psr\Container\ContainerInterface;

final class SchemaFactory
{
    public function __invoke(ContainerInterface $container): Schema
    {
        $config = $container->get(ConfigKey::CONFIG);
        return new Schema($config[ConfigKey::ROOT][ConfigKey::SCHEMA] ?? []);
    }
}