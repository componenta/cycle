<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\Filter\LikeMode;
use Componenta\Cycle\Filter\SearchFilter;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;

function searchFilterDatabase(bool $useEnvironment = true): DatabaseInterface
{
    $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
    if ($useEnvironment) {
        foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $key) {
            $value = getenv('CYCLE_TEST_DB_' . $key);
            if ($value !== false) {
                $settings['DB_' . $key] = $value;
            }
        }
    }
    $composition = (new ConfigFactory())->create(new Environment($settings), new ConfigProvider());
    return (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
}

it('preserves grouped search conditions and bound patterns on supported databases', function (
    string $value,
    LikeMode $mode,
    array $expected,
): void {
    $db = searchFilterDatabase();
    $query = $db->select('id')->from(new Fragment(
        "(SELECT 1 AS id, 'Alpha' AS first_name, 'Beta' AS last_name "
        . "UNION ALL SELECT 2, 'Beta', 'ALPHA' "
        . "UNION ALL SELECT 3, 'Alpha', 'Beta' "
        . "UNION ALL SELECT 4, 'alphabet', 'Other') AS items",
    ))->where('id', '!=', 3)->orderBy('id');

    $rows = (new SearchFilter(['first_name', 'last_name'], $value, $mode))->apply($query)->fetchAll();

    expect(array_map('intval', array_column($rows, 'id')))->toBe($expected);
})->with(static function (): iterable {
    yield 'exact case-insensitive' => ['ALPHA', LikeMode::Exact, [1, 2]];
    yield 'contains' => ['LPH', LikeMode::Contains, [1, 2, 4]];
    yield 'prefix' => ['ALP', LikeMode::StartsWith, [1, 2, 4]];
    yield 'suffix' => ['PHA', LikeMode::EndsWith, [1, 2]];
    yield 'bound value' => ["' OR 1=1 --", LikeMode::Exact, []];
    yield 'SQL LIKE wildcards' => ['ALPH%', LikeMode::Exact, [1, 2, 4]];
});

it('matches identical non-ASCII values on SQLite without PHP case folding', function (): void {
    $db = searchFilterDatabase(false);
    $query = $db->select('id')->from(new Fragment(
        '(SELECT 1 AS id, ? AS name UNION ALL SELECT 2, ?) AS items',
        'Ångström',
        'ångström',
    ))->orderBy('id');

    $rows = (new SearchFilter(['name'], 'Ångström', LikeMode::Exact))->apply($query)->fetchAll();
    expect($rows)->toBe([['id' => 1]]);
});

it('quotes Unicode column names on every supported database', function (): void {
    $db = searchFilterDatabase();
    $quoted = $db->getDriver()->getQueryCompiler()->quoteIdentifier('имя_поля');
    foreach (['имя_поля', 'items.имя_поля'] as $column) {
        $query = $db->select('id')->from(new Fragment(
            "(SELECT 1 AS id, 'Alpha' AS $quoted UNION ALL SELECT 2, 'Beta') AS items",
        ));
        $rows = (new SearchFilter([$column], 'ALPHA', LikeMode::Exact))->apply($query)->fetchAll();
        expect(array_map('intval', array_column($rows, 'id')))->toBe([1]);
    }
});

it('preserves configured table prefixes and aliases in Unicode expressions', function (): void {
    $db = searchFilterDatabase(false)->withPrefix('tenant_');
    $db->execute('CREATE TABLE tenant_records (id INTEGER, "имя_поля" TEXT)');
    $db->execute("INSERT INTO tenant_records VALUES (1, 'Alpha'), (2, 'Beta')");
    foreach ([
        ['records', 'records.имя_поля'],
        ['records', 'tenant_records.имя_поля'],
        ['records AS r', 'r.имя_поля'],
    ] as [$table, $column]) {
        $query = $db->select('id')->from($table);
        $rows = (new SearchFilter([$column], 'ALPHA', LikeMode::Exact))->apply($query)->fetchAll();
        expect($rows)->toBe([['id' => 1]]);
    }
});

it('does not prefix structured subquery aliases', function (): void {
    $db = searchFilterDatabase(false)->withPrefix('tenant_');
    $db->execute('CREATE TABLE tenant_records (id INTEGER, name TEXT, "имя_поля" TEXT, "дата" TEXT)');
    $db->execute("INSERT INTO tenant_records VALUES (1, 'Alpha', 'Alpha', '2025-02-05'), (2, 'Beta', 'Beta', '2025-02-06')");

    foreach (['items.name', 'items.имя_поля'] as $column) {
        $source = $db->select('id', 'name', 'имя_поля', 'дата')->from('records');
        $query = $db->select('id')->from(new \Cycle\Database\Injection\SubQuery($source, 'items'));
        $rows = (new SearchFilter([$column], 'ALPHA', LikeMode::Exact))->apply($query)->fetchAll();
        expect($rows)->toBe([['id' => 1]]);
    }

    $source = $db->select('id', 'дата')->from('records');
    $query = $db->select('id')->from(new \Cycle\Database\Injection\SubQuery($source, 'items'));
    $filter = new \Componenta\Cycle\Filter\DateTimeFilter('items.дата', new \DateTimeImmutable('2025-02-05'));
    expect($filter->apply($query)->fetchAll())->toBe([['id' => 1]]);
});
