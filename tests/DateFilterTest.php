<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\Filter\DateFilter;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;

it('end date includes fractional seconds but excludes the following day', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
        new ConfigProvider(),
    );
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
    $query = $db->select('id')->from(new Fragment(
        "(SELECT 1 AS id, '2026-11-25 23:59:59.999999' AS created_at UNION ALL "
        . "SELECT 2, '2026-11-26 00:00:00' UNION ALL SELECT 3, '2026-11-25 00:00:00') AS items",
    ))->orderBy('id');

    $result = (new DateFilter(['from' => '2026-11-25', 'to' => '2026-11-25']))->apply($query)->fetchAll();

    expect($result)->toBe([['id' => 1], ['id' => 3]]);
});
