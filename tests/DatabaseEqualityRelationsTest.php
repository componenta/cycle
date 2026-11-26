<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;

function relationEqualityDatabase(): DatabaseInterface
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

it('matches relation keys using the configured database comparison and retains projection aliases', function (bool $numeric): void {
    $db = relationEqualityDatabase();

    $type = $numeric ? 'INTEGER' : match ($db->getType()) {
        'MySQL' => 'VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'SQLServer' => 'VARCHAR(32) COLLATE Latin1_General_100_CI_AS',
        'Postgres' => 'VARCHAR(32) COLLATE "C"',
        default => 'TEXT COLLATE NOCASE',
    };
    $caseSensitive = !$numeric && $db->getType() === 'Postgres';
    $keys = $numeric ? ['01', '1', '2', '01'] : ['ABC', 'abc', "x' OR 1=1 --", 'ABC'];
    $ownerKey = $numeric ? 1 : 'abc';
    $secondKey = $numeric ? 1 : 'ABC';

    try {
        $db->execute('CREATE TABLE cycle_test_eq_roots (seq INTEGER, id VARCHAR(32), owner_id VARCHAR(32))');
        $db->execute("CREATE TABLE cycle_test_eq_owners (id $type, label VARCHAR(32))");
        $db->execute("CREATE TABLE cycle_test_eq_children (id INTEGER, root_id $type)");
        $db->execute('CREATE TABLE cycle_test_eq_tags (id INTEGER)');
        $db->execute("CREATE TABLE cycle_test_eq_pivot (root_id $type, tag_id INTEGER)");

        foreach ($keys as $index => $key) {
            $db->execute('INSERT INTO cycle_test_eq_roots VALUES (?, ?, ?)', [new Parameter($index + 1), $key, $key]);
        }
        $db->execute("INSERT INTO cycle_test_eq_owners VALUES (?, 'owner')", [new Parameter($ownerKey)]);
        if ($caseSensitive) {
            $db->execute("INSERT INTO cycle_test_eq_owners VALUES (?, 'upper')", [new Parameter($secondKey)]);
        }
        $db->execute('INSERT INTO cycle_test_eq_children VALUES (8, ?), (9, ?)', [
            new Parameter($ownerKey), new Parameter($secondKey),
        ]);
        $db->execute('INSERT INTO cycle_test_eq_tags VALUES (10), (11)');
        $db->execute('INSERT INTO cycle_test_eq_pivot VALUES (?, 10), (?, 11)', [
            new Parameter($ownerKey), new Parameter($secondKey),
        ]);

        $logger = new class extends \Psr\Log\AbstractLogger {
            public int $reads = 0;

            public function log($level, $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::INFO && str_starts_with(ltrim((string) $message), 'SELECT')) {
                    ++$this->reads;
                }
            }
        };
        $db->getDriver(DatabaseInterface::READ)->setLogger($logger);
        $fetcher = new DatabaseEqualityFetcher($db);
        $rows = $fetcher();
        expect($rows)->toHaveCount(4);
        expect($logger->reads)->toBe(4);
        expect($fetcher->ownerTransforms)->toBe(3);

        $first = $caseSensitive ? ['upper', [9], [11]] : ['owner', [8, 9], [10, 11]];
        $second = $caseSensitive ? ['owner', [8], [10]] : ['owner', [8, 9], [10, 11]];
        $expected = [$first, $second, [null, [], []], $first];
        foreach ($rows as $index => $row) {
            expect($row['id'])->toBe($keys[$index]);
            expect($row['owner'])->toBe($expected[$index][0]);
            expect($row['children'])->toEqualCanonicalizing($expected[$index][1]);
            expect($row['tags'])->toEqualCanonicalizing($expected[$index][2]);
        }
    } finally {
        foreach (['pivot', 'tags', 'children', 'owners', 'roots'] as $table) {
            $db->execute('DROP TABLE IF EXISTS cycle_test_eq_' . $table);
        }
    }
})->with([
    'numeric target and noncanonical text keys' => [true],
    'text target and database collation' => [false],
]);

final class DatabaseEqualityFetcher extends DataFetcher
{
    public int $ownerTransforms = 0;

    public function __invoke(): array
    {
        return $this->doFetch();
    }

    protected function getTableName(): string
    {
        return 'cycle_test_eq_roots';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('seq');
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        // Alias forces the portable path even on SQLite.
        $this->batchBelongsTo($results, $rows, 'owner_id', 'cycle_test_eq_owners',
            columns: ['label AS owner_label'], transform: function (array $row): string {
                ++$this->ownerTransforms;
                expect(array_keys($row))->toBe(['owner_label', 'id']);
                return $row['owner_label'];
            });
        $this->batchHasMany($results, $rows, 'children', 'cycle_test_eq_children', 'root_id',
            columns: ['id'], transform: static fn (array $row): int => (int) $row['id']);
        $this->batchBelongsToMany($results, $rows, 'tags', 'cycle_test_eq_tags', 'cycle_test_eq_pivot',
            'root_id', 'tag_id', columns: ['id'], transform: static fn (array $row): int => (int) $row['id']);
    }
}

it('bounds the real parameter count of grouped lookups with bound projection expressions', function (): void {
    $db = relationEqualityDatabase();
    try {
        $db->execute('CREATE TABLE cycle_test_bound_roots (id INTEGER, owner_id INTEGER)');
        $db->execute('CREATE TABLE cycle_test_bound_owners (id INTEGER)');
        for ($offset = 0; $offset < 501; $offset += 100) {
            $rootValues = [];
            $ownerValues = [];
            $rootParams = [];
            $ownerParams = [];
            for ($i = $offset; $i < min(501, $offset + 100); ++$i) {
                $id = new Parameter($i + 1);
                $rootValues[] = '(?, ?)';
                $ownerValues[] = '(?)';
                array_push($rootParams, $id, $id);
                $ownerParams[] = $id;
            }
            $db->execute('INSERT INTO cycle_test_bound_roots VALUES ' . implode(', ', $rootValues), $rootParams);
            $db->execute('INSERT INTO cycle_test_bound_owners VALUES ' . implode(', ', $ownerValues), $ownerParams);
        }
        $logger = new class extends \Psr\Log\AbstractLogger {
            public int $reads = 0;

            public function log($level, $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::INFO && str_starts_with(ltrim((string) $message), 'SELECT')) {
                    ++$this->reads;
                }
            }
        };
        $db->getDriver(DatabaseInterface::READ)->setLogger($logger);
        $rows = (new BoundRelationProjectionFetcher($db))();
        expect($rows)->toHaveCount(501);
        foreach ($rows as $index => $row) {
            expect((int) $row['id'])->toBe($index + 1);
            expect($row['owner'])->toBe('matched');
        }
        $expectedQueries = match ($db->getType()) {
            'SQLite' => 5,
            'SQLServer' => 4,
            default => 3,
        };
        expect($logger->reads)->toBe($expectedQueries);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_bound_owners');
        $db->execute('DROP TABLE IF EXISTS cycle_test_bound_roots');
    }
});

final class BoundRelationProjectionFetcher extends DataFetcher
{
    public function __invoke(): array
    {
        return $this->doFetch();
    }

    protected function getTableName(): string
    {
        return 'cycle_test_bound_roots';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('id');
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchBelongsTo(
            $results, $rows, 'owner_id', 'cycle_test_bound_owners',
            columns: [new Fragment('CASE WHEN ? = ? THEN ? ELSE ? END AS computed_value', 'same', 'same', 'matched', 'wrong')],
            transform: static fn (array $row): string => $row['computed_value'],
        );
    }
}
