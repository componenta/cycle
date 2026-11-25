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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchFilterTest extends TestCase
{
    #[DataProvider('searches')]
    public function testSqlServerAndOtherDialectsSearchPreservesGroupingAndBoundPatterns(
        string $value,
        LikeMode $mode,
        array $expected,
    ): void {
        $db = $this->database();
        $query = $db->select('id')->from(new Fragment(
            "(SELECT 1 AS id, 'Alpha' AS first_name, 'Beta' AS last_name "
            . "UNION ALL SELECT 2, 'Beta', 'ALPHA' "
            . "UNION ALL SELECT 3, 'Alpha', 'Beta' "
            . "UNION ALL SELECT 4, 'alphabet', 'Other') AS items",
        ))->where('id', '!=', 3)->orderBy('id');

        $rows = (new SearchFilter(['first_name', 'last_name'], $value, $mode))->apply($query)->fetchAll();

        self::assertSame($expected, array_map('intval', array_column($rows, 'id')));
    }

    public static function searches(): iterable
    {
        yield 'exact case-insensitive' => ['ALPHA', LikeMode::Exact, [1, 2]];
        yield 'contains' => ['LPH', LikeMode::Contains, [1, 2, 4]];
        yield 'prefix' => ['ALP', LikeMode::StartsWith, [1, 2, 4]];
        yield 'suffix' => ['PHA', LikeMode::EndsWith, [1, 2]];
        yield 'bound value' => ["' OR 1=1 --", LikeMode::Exact, []];
        yield 'SQL LIKE wildcards' => ['ALPH%', LikeMode::Exact, [1, 2, 4]];
    }

    public function testSqliteMatchesIdenticalNonAsciiValuesWithoutApplyingPhpCaseFolding(): void
    {
        $db = $this->database(false);
        $query = $db->select('id')->from(new Fragment(
            '(SELECT 1 AS id, ? AS name UNION ALL SELECT 2, ?) AS items',
            'Ångström',
            'ångström',
        ))->orderBy('id');

        $rows = (new SearchFilter(['name'], 'Ångström', LikeMode::Exact))->apply($query)->fetchAll();
        self::assertSame([['id' => 1]], $rows);
    }

    private function database(bool $useEnvironment = true): DatabaseInterface
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
}
