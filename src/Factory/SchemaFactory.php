<?php

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\ConfigKey;
use Cycle\ORM\Schema;
use Componenta\Config\ContainerValue;

final class SchemaFactory
{
    public function __invoke(ContainerValue $container): Schema
    {
        $config = $container->config;
        return new Schema($config->array(ConfigKey::ROOT, [])[ConfigKey::SCHEMA] ?? []);
    }
}
