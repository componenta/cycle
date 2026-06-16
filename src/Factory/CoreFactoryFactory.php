<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\ConfigKey;
use Psr\Container\ContainerInterface;
use Spiral\Core\Container;

final class CoreFactoryFactory
{
    public function __invoke(ContainerInterface $container): Container
    {
        $bindings = $container->get(ConfigKey::CONFIG)
            ->get(ConfigKey::ROOT)[ConfigKey::BINDINGS] ?? [];

        $coreFactory = new Container();

        if ($bindings !== []) {
            foreach ($bindings as $binding) {
                $coreFactory->bindSingleton($binding, static fn() => $container->get($binding));
            }
        }

        return $coreFactory;
    }
}