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
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;

it('binary keys load all three relation types', function (bool $explicitBinary, int $additionalKeys): void {
    $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
    foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $key) {
        $value = getenv('CYCLE_TEST_DB_' . $key);
        if ($value !== false) {
            $settings['DB_' . $key] = $value;
        }
    }
    $composition = (new ConfigFactory())->create(new Environment($settings), new ConfigProvider());
    $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
        ->get(DatabaseManager::class)->database();

    $root = hex2bin('018f00ff0080700080ff000000000001');
    $other = hex2bin('018f00ff0080700080ff000000000002');
    $owner = hex2bin('018f00ff0080700080ff000000000003');
    $tag = hex2bin('018f00ff0080700080ff000000000004');
    $repeated = hex2bin('018f00ff0080700080ff000000000005');
    $type = match ($db->getType()) {
        'Postgres' => 'BYTEA',
        'MySQL' => 'BINARY(16)',
        'SQLServer' => 'VARBINARY(16)',
        default => 'BLOB',
    };
    $binary = static fn (string $value): string|Parameter => $explicitBinary || in_array($db->getType(), ['Postgres', 'SQLServer'], true)
        ? new Parameter($value, \PDO::PARAM_LOB)
        : $value;
    $seq = static fn (int $value): int|string => $db->getType() === 'SQLServer' ? (string) $value : $value;

    try {
        $db->execute("CREATE TABLE cycle_test_binary_roots (id $type PRIMARY KEY, owner_id $type, seq INTEGER)");
        $db->execute("CREATE TABLE cycle_test_binary_owners (id $type PRIMARY KEY, label VARCHAR(32))");
        $db->execute("CREATE TABLE cycle_test_binary_children (parent_id $type, label VARCHAR(32))");
        $db->execute("CREATE TABLE cycle_test_binary_tags (id $type PRIMARY KEY, label VARCHAR(32))");
        $db->execute("CREATE TABLE cycle_test_binary_pivot (root_id $type, tag_id $type)");
        $db->execute('INSERT INTO cycle_test_binary_roots VALUES (?, ?, 1), (?, NULL, 2)',
            [$binary($root), $binary($owner), $binary($other)]);
        $db->execute('INSERT INTO cycle_test_binary_roots VALUES (?, ?, 3)', [$binary($repeated), $binary($owner)]);
        $db->execute("INSERT INTO cycle_test_binary_owners VALUES (?, 'owner')", [$binary($owner)]);
        $db->execute("INSERT INTO cycle_test_binary_children VALUES (?, 'child')", [$binary($root)]);
        $db->execute("INSERT INTO cycle_test_binary_tags VALUES (?, 'tag')", [$binary($tag)]);
        $db->execute('INSERT INTO cycle_test_binary_pivot VALUES (?, ?)', [$binary($root), $binary($tag)]);

        if ($db->getType() === 'SQLite') {
            $statement = $db->query('SELECT DISTINCT typeof(id) FROM cycle_test_binary_roots');
            try {
                expect($statement->fetchColumn())->toBe($explicitBinary ? 'blob' : 'text');
            } finally {
                $statement->close();
            }
        }

        $expected = [
            ['id' => bin2hex($root), 'ownerId' => bin2hex($owner), 'seq' => $seq(1),
                'owner' => 'owner', 'children' => ['child'], 'tags' => ['tag']],
            ['id' => bin2hex($other), 'ownerId' => null, 'seq' => $seq(2),
                'owner' => null, 'children' => [], 'tags' => []],
            ['id' => bin2hex($repeated), 'ownerId' => bin2hex($owner), 'seq' => $seq(3),
                'owner' => 'owner', 'children' => [], 'tags' => []],
        ];

        // Exercise the second batch for each relation, including a
        // binary identifier whose bytes are all valid printable text.
        for ($offset = 0; $offset < $additionalKeys; $offset += 100) {
            $inserts = [
                'roots' => ['values' => [], 'params' => []],
                'owners' => ['values' => [], 'params' => []],
                'children' => ['values' => [], 'params' => []],
                'tags' => ['values' => [], 'params' => []],
                'pivot' => ['values' => [], 'params' => []],
            ];
            for ($i = $offset; $i < min($additionalKeys, $offset + 100); ++$i) {
                $id = $i === 0 ? '0123456789abcdef' : pack('N4', 0x018f00ff, 0x00807000, 0x80ff0000, 100 + $i);
                $number = $i + 4;
                foreach ([
                    'roots' => ['(?, ?, ?)', [$binary($id), $binary($id), $number]],
                    'owners' => ["(?, 'owner')", [$binary($id)]],
                    'children' => ["(?, 'child')", [$binary($id)]],
                    'tags' => ["(?, 'tag')", [$binary($id)]],
                    'pivot' => ['(?, ?)', [$binary($id), $binary($id)]],
                ] as $table => [$tuple, $params]) {
                    $inserts[$table]['values'][] = $tuple;
                    array_push($inserts[$table]['params'], ...$params);
                }
                $expected[] = [
                    'id' => bin2hex($id), 'ownerId' => bin2hex($id), 'seq' => $seq($number),
                    'owner' => 'owner', 'children' => ['child'], 'tags' => ['tag'],
                ];
            }
            foreach ($inserts as $table => $insert) {
                $db->execute(
                    'INSERT INTO cycle_test_binary_' . $table . ' VALUES ' . implode(', ', $insert['values']),
                    $insert['params'],
                );
            }
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

        $rows = (new BinaryRelationsFetcher($db, $explicitBinary))();

        expect($rows)->toBe($expected);
        // MySQL inspects the target column and server version once per relation.
        $metadataReads = $additionalKeys > 0 && $db->getType() === 'MySQL' ? 3 : 0;
        expect($logger->reads)->toBe(($additionalKeys === 0 ? 4 : 7) + $metadataReads);
    } finally {
        foreach (['cycle_test_binary_pivot', 'cycle_test_binary_tags', 'cycle_test_binary_children',
            'cycle_test_binary_owners', 'cycle_test_binary_roots'] as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
})->with(static function (): iterable {
    yield 'driver default and automatic bytea detection' => [false, 0];
    yield 'explicit binary binding across multiple batches' => [true, 500];
});

final class BinaryRelationsFetcher extends DataFetcher
{
    public function __construct(DatabaseInterface $db, private readonly bool $explicitBinary)
    {
        parent::__construct($db);
    }

    public function __invoke(): array
    {
        return $this->doFetch();
    }

    protected function getTableName(): string
    {
        return 'cycle_test_binary_roots';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('seq');
    }

    protected function mapId(mixed $value): string
    {
        // Deliberately consume the PDO stream as an application mapper would.
        return bin2hex(is_resource($value) ? stream_get_contents($value) : $value);
    }

    protected function mapOwnerId(mixed $value): ?string
    {
        return $value === null ? null : $this->mapId($value);
    }

    protected function isBinaryRelationKey(string $column): bool
    {
        // PostgreSQL continues to exercise automatic stream detection.
        // SQL Server returns binary keys as strings and needs explicit binding.
        return ($this->explicitBinary || $this->db->getType() === 'SQLServer')
            && in_array($column, ['id', 'owner_id'], true);
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $label = static fn (array $row): string => $row['label'];
        $postgres = $this->db->getType() === 'Postgres';
        $this->batchBelongsTo($results, $rows, 'owner_id', 'cycle_test_binary_owners',
            columns: ['label'], transform: static function (array $row) use ($postgres): string {
                $key = $row['id'];
                if ($postgres) {
                    expect(is_resource($key))->toBeTrue('Each invocation receives a live PostgreSQL bytea stream.');
                    expect(ftell($key))->toBe(0);
                    expect(strlen(stream_get_contents($key)))->toBe(16);
                    // Closing a callback's stream must not invalidate the next row.
                    fclose($key);
                } else {
                    expect($key)->toBeString();
                    expect(strlen($key))->toBe(16);
                }
                return $row['label'];
            });
        $this->batchHasMany($results, $rows, 'children', 'cycle_test_binary_children', 'parent_id',
            columns: ['label'], transform: static function (array $row): string {
                $key = $row['parent_id'];
                if (is_resource($key)) {
                    expect(ftell($key))->toBe(0);
                    expect(strlen(stream_get_contents($key)))->toBe(16);
                }
                return $row['label'];
            });
        $this->batchBelongsToMany($results, $rows, 'tags', 'cycle_test_binary_tags',
            'cycle_test_binary_pivot', 'root_id', 'tag_id', columns: ['label'], transform: $label);
    }
}
