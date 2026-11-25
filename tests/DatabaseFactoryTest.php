<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Factory\DatabaseFactory;
use Componenta\DI\ProxyFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

it('lazy factory supports alternative database implementations', function (): void {
    $database = $this->createStub(DatabaseInterface::class);
    $database->method('getName')->willReturn('custom');
    $provider = $this->createStub(DatabaseProviderInterface::class);
    $provider->method('database')->willReturn($database);
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturn($provider);

    $factory = new DatabaseFactory();
    Assert::assertSame('custom', $factory->lazy($container, new ProxyFactory())->getName());
});
