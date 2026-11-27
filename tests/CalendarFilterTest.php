<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\Filter\DateFilter;
use Componenta\Cycle\Filter\DateTimeBetweenFilter;
use Componenta\Cycle\Filter\DateTimeFilter;
use Componenta\Cycle\FilterInterface;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;
use DateTimeImmutable;
use InvalidArgumentException;

function calendarFilterDatabase(): DatabaseInterface
{
    $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
    foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $settings['DB_' . $key] = $value;
        }
    }

    $composition = (new ConfigFactory())->create(new Environment($settings), new ConfigProvider());

    return (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();
}

it('applies calendar filters on every supported database', function (FilterInterface $filter, array $expected): void {
    $db = calendarFilterDatabase();
    $type = match ($db->getType()) {
        'SQLServer' => 'DATETIME2',
        'Postgres' => 'TIMESTAMP',
        'MySQL' => 'DATETIME',
        default => 'TEXT',
    };

    $query = $db->select('id')->from(new Fragment(
        "(SELECT 1 AS id, CAST('2024-02-29 12:00:00' AS $type) AS created_at "
        . "UNION ALL SELECT 2, CAST('2025-01-05 12:00:00' AS $type) "
        . "UNION ALL SELECT 3, CAST('2025-02-05 12:00:00' AS $type) "
        . "UNION ALL SELECT 4, CAST(NULL AS $type)) AS items",
    ))->orderBy('id');

    $actual = $filter->apply($query)->fetchAll();
    expect(array_map('intval', array_column($actual, 'id')))->toBe($expected);
})->with(static function (): iterable {
    $date = new DateTimeImmutable('2025-02-05');
    yield 'date equality' => [new DateTimeFilter('created_at', $date), [3]];
    yield 'year' => [new DateTimeFilter('created_at', $date, 'Y', 'year'), [2, 3]];
    yield 'month padded' => [new DateTimeFilter('created_at', $date, 'm', 'MONTH'), [1, 3]];
    yield 'month unpadded' => [new DateTimeFilter('created_at', $date, 'n', 'MONTH'), [1, 3]];
    yield 'day' => [new DateTimeFilter('created_at', $date, 'j', 'DAY'), [2, 3]];
    yield 'month range' => [new DateTimeBetweenFilter('created_at', new DateTimeImmutable('2025-01-01'), $date, 'm', 'MONTH'), [1, 2, 3]];
    yield 'date range' => [new DateTimeBetweenFilter('created_at', new DateTimeImmutable('2025-01-01'), $date), [2, 3]];
    yield 'specific dates' => [new DateFilter(['dates' => ['2024-02-29', '2025-02-05']], 'created_at'), [1, 3]];
    yield 'empty dates' => [new DateFilter(['dates' => []]), []];
    yield 'calendar range' => [new DateFilter(['from' => '2025-01-05', 'to' => '2025-02-05']), [2, 3]];
});

it('quotes Unicode calendar columns on every supported database', function (): void {
    $db = calendarFilterDatabase();
    $type = match ($db->getType()) {
        'SQLServer' => 'DATETIME2',
        'Postgres' => 'TIMESTAMP',
        'MySQL' => 'DATETIME',
        default => 'TEXT',
    };
    $quoted = $db->getDriver()->getQueryCompiler()->quoteIdentifier('дата_создания');
    $date = new DateTimeImmutable('2025-02-05');
    foreach ([
        new DateTimeFilter('items.дата_создания', $date),
        new DateTimeFilter('items.дата_создания', $date, 'm', 'MONTH'),
        new DateFilter(['dates' => ['2025-02-05']], 'дата_создания'),
    ] as $filter) {
        $query = $db->select('id')->from(new Fragment(
            "(SELECT 1 AS id, CAST('2025-02-05 12:00:00' AS $type) AS $quoted "
            . "UNION ALL SELECT 2, CAST('2025-01-01 12:00:00' AS $type)) AS items",
        ));
        expect(array_map('intval', array_column($filter->apply($query)->fetchAll(), 'id')))->toBe([1]);
    }
});

it('date range includes date only values on both boundaries', function (array $bounds, array $expected): void {
    $db = calendarFilterDatabase();
    $type = $db->getType() === 'SQLite' ? 'TEXT' : 'DATE';
    $query = $db->select('id')->from(new Fragment(
        "(SELECT 1 AS id, CAST('2025-02-04' AS $type) AS created_at "
        . "UNION ALL SELECT 2, CAST('2025-02-05' AS $type) "
        . "UNION ALL SELECT 3, CAST('2025-02-06' AS $type) "
        . "UNION ALL SELECT 4, CAST(NULL AS $type)) AS items",
    ))->orderBy('id');

    expect(array_map('intval', array_column(
        (new DateFilter($bounds))->apply($query)->fetchAll(),
        'id',
    )))->toBe($expected);
})->with(static function (): iterable {
    yield 'from' => [['from' => '2025-02-05'], [2, 3]];
    yield 'to' => [['to' => '2025-02-05'], [1, 2]];
    yield 'single day' => [['from' => '2025-02-05', 'to' => '2025-02-05'], [2]];
});

it('rejects invalid calendar dates', function (array $date): void {
    $query = calendarFilterDatabase()->select('id')->from('records');
    $this->expectException(InvalidArgumentException::class);
    (new DateFilter($date))->apply($query);
})->with(static function (): iterable {
    yield [['from' => '2025-02-29']];
    yield [['to' => '2025-01-32']];
    yield [['dates' => ['2024-02-29', '2025-02-29']]];
    yield [['dates' => ['bad' => '2025-02-05']]];
    yield [['from' => 123]];
});


it('includes the complete last four-digit calendar date without overflowing the SQL date range', function (bool $withLowerBound): void {
    $db = calendarFilterDatabase();
    $type = match ($db->getType()) {
        'SQLServer' => 'DATETIME2(7)',
        'Postgres' => 'TIMESTAMP(6)',
        'MySQL' => 'DATETIME(6)',
        default => 'TEXT',
    };
    $bounds = ['to' => '9999-12-31'];
    if ($withLowerBound) {
        $bounds['from'] = '9999-12-31';
    }
    $query = $db->select('id')->from(new Fragment(
        "(SELECT 1 AS id, CAST('9999-12-30 23:59:59' AS $type) AS created_at "
        . "UNION ALL SELECT 2, CAST('9999-12-31 00:00:00' AS $type) "
        . "UNION ALL SELECT 3, CAST('9999-12-31 23:59:59.999999' AS $type) "
        . "UNION ALL SELECT 4, CAST(NULL AS $type)) AS items",
    ))->orderBy('id');
    $rows = (new DateFilter($bounds))->apply($query)->fetchAll();
    expect(array_map('intval', array_column($rows, 'id')))
        ->toBe($withLowerBound ? [2, 3] : [1, 2, 3]);
})->with([true, false]);
