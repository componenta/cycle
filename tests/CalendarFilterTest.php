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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarFilterTest extends TestCase
{
    #[DataProvider('filters')]
    public function testSqlServerAndOtherDialectsCalendarFilters(FilterInterface $filter, array $expected): void
    {
        $db = $this->database();
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
        self::assertSame($expected, array_map('intval', array_column($actual, 'id')));
    }

    public static function filters(): iterable
    {
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
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidCalendarDates(array $date): void
    {
        $query = $this->database()->select('id')->from('records');
        $this->expectException(InvalidArgumentException::class);
        (new DateFilter($date))->apply($query);
    }

    public static function invalidDates(): iterable
    {
        yield [['from' => '2025-02-29']];
        yield [['to' => '2025-01-32']];
        yield [['dates' => ['2024-02-29', '2025-02-29']]];
        yield [['dates' => ['bad' => '2025-02-05']]];
        yield [['from' => 123]];
    }

    private function database(): DatabaseInterface
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
}
