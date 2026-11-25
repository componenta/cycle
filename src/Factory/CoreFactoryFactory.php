<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\DI\FactoryInterface;
use Cycle\Database\DatabaseInterface;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Parser\TypecastInterface;
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
                if (is_a($binding, TypecastInterface::class, true)) {
                    // Each ORM role receives a new rule-bearing handler.
                    $coreFactory->bind($binding, static function (
                        ?DatabaseInterface $database = null,
                        ?SchemaInterface $schema = null,
                        ?string $role = null,
                    ) use ($container, $binding): object {
                        $context = array_filter(
                            ['database' => $database, 'schema' => $schema, 'role' => $role],
                            static fn (mixed $value): bool => $value !== null,
                        );
                        return $container->get(FactoryInterface::class)->make($binding, $context);
                    });
                } else {
                    $coreFactory->bindSingleton($binding, static fn() => $container->get($binding));
                }
            }
        }

        return $coreFactory;
    }
}
