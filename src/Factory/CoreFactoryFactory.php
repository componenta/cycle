<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\ConfigKey;
use Componenta\Config\ContainerValue;
use Spiral\Core\Container;

final class CoreFactoryFactory
{
    public function __invoke(ContainerValue $container): Container
    {
        $bindings = $container->config
            ->array(ConfigKey::ROOT, [])[ConfigKey::BINDINGS] ?? [];

        $coreFactory = new Container();

        if ($bindings !== []) {
            foreach ($bindings as $binding) {
                $coreFactory->bindSingleton($binding, static fn() => $container->get($binding));
            }
        }

        return $coreFactory;
    }
}
