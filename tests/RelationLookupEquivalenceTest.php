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

it('large relation batches match individual database predicates with cold and reused fetchers', function (string $kind, bool $prefixed): void {
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

    $prefix = $prefixed ? 'cycle_pref_' : '';
    $lookup = $db->withPrefix($prefix, false);
    $driver = $db->getType();
    $binary = $kind === 'binary';
    $type = match ($kind) {
        'integer', 'numeric-text' => 'BIGINT',
        'binary' => match ($driver) {
            'Postgres' => 'BYTEA',
            'MySQL' => 'VARBINARY(32)',
            'SQLServer' => 'VARBINARY(32)',
            default => 'BLOB',
        },
        'text' => match ($driver) {
            'MySQL' => 'VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'SQLServer' => 'NVARCHAR(64) COLLATE Latin1_General_100_CI_AS',
            'Postgres' => 'VARCHAR(64) COLLATE "C"',
            default => 'TEXT COLLATE NOCASE',
        },
        'latin1' => $driver === 'MySQL'
            ? 'VARCHAR(64) CHARACTER SET latin1 COLLATE latin1_swedish_ci'
            : 'VARCHAR(64)',
    };

    $stored = match ($kind) {
        'integer', 'numeric-text' => [1, 2, 9007199254740992, 9007199254740993, -3, ...range(10, 45)],
        'binary' => array_map(static fn (int $n): string => pack('N4', 0x018f00ff, 0x00807000, 0x80ff0000, $n), range(1, 40)),
        'text' => ['abc', 'ABC ', '00', '0', "x' --", "a\\b", 'Å', 'é', ...array_map(static fn (int $n): string => "key_$n", range(1, 40))],
        'latin1' => ['?', 'abc', 'é', ...array_map(static fn (int $n): string => "key_$n", range(1, 40))],
    };
    $keys = match ($kind) {
        'integer' => [...$stored, 999999999, null, 1],
        'numeric-text' => [...array_map('strval', $stored), '01', '-03', '99', null, '1'],
        'binary' => [...$stored, str_repeat("\0", 16), null, $stored[0]],
        'text' => [...$stored, 'ABC', 'Abc', 'å', 'e', 'missing', null, 'abc'],
        'latin1' => [...$stored, 'ABC', 'missing', null, 'abc'],
    };
    $parameter = static fn (mixed $value): Parameter => new Parameter(
        $value, $binary ? \PDO::PARAM_LOB : Parameter::DETECT_TYPE,
    );

    $table = $prefix . 'cycle_test_lookup';
    try {
        $db->execute("CREATE TABLE $table (lookup_key $type NOT NULL, seq INTEGER NOT NULL)");
        $db->execute("CREATE INDEX {$table}_key ON $table(lookup_key)");
        foreach ($stored as $index => $value) {
            $db->execute("INSERT INTO $table VALUES (?, ?)", [$parameter($value), new Parameter($index + 1)]);
        }
        $expected = [];
        foreach ($keys as $value) {
            if ($value === null) {
                $expected[] = [];
                continue;
            }
            $rows = $lookup->select('seq')->from('cycle_test_lookup')
                ->where('lookup_key', '=', $parameter($value))->fetchAll();
            $seq = array_map(static fn (array $row): int => (int) $row['seq'], $rows);
            sort($seq);
            $expected[] = $seq;
        }

        $fetcher = new RelationLookupOracleFetcher($lookup, $binary);
        foreach ([$fetcher, $fetcher, new RelationLookupOracleFetcher($lookup, $binary)] as $candidate) {
            expect($candidate($keys))->toBe($expected);
        }
    } finally {
        $db->execute("DROP TABLE IF EXISTS $table");
    }
})->with([
    'wide integers' => ['integer', false],
    'noncanonical numeric text' => ['numeric-text', false],
    'text collation and escaping' => ['text', false],
    'non-Unicode target charset' => ['latin1', false],
    'binary with prefix' => ['binary', true],
    'text with prefix' => ['text', true],
]);

final class RelationLookupOracleFetcher extends DataFetcher
{
    public function __construct(DatabaseInterface $db, private readonly bool $binary)
    {
        parent::__construct($db);
    }

    public function __invoke(array $keys): array
    {
        $rows = array_map(static fn (mixed $key): array => ['id' => $key], $keys);
        return array_column($this->fetchResults($rows, null, null), 'matches');
    }

    protected function isBinaryRelationKey(string $column): bool
    {
        return $this->binary;
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchHasMany($results, $rows, 'matches', 'cycle_test_lookup', 'lookup_key',
            transform: static fn (array $row): int => (int) $row['seq']);
        foreach ($results as &$row) {
            sort($row['matches']);
        }
    }
}
