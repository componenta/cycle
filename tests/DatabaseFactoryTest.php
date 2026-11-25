<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Factory\DatabaseFactory;
use Componenta\DI\ProxyFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class DatabaseFactoryTest extends TestCase
{
    public function testLazyFactorySupportsAlternativeDatabaseImplementations(): void
    {
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('getName')->willReturn('custom');
        $provider = $this->createStub(DatabaseProviderInterface::class);
        $provider->method('database')->willReturn($database);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($provider);

        $factory = new DatabaseFactory();
        self::assertSame('custom', $factory->lazy($container, new ProxyFactory())->getName());
    }
}
