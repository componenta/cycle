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
        'integer', 'numeric-text', 'canonical-text' => 'BIGINT',
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
        'integer', 'numeric-text', 'canonical-text' => [0, 1, 2, PHP_INT_MIN, PHP_INT_MAX, 9007199254740992, 9007199254740993, -3, ...range(10, 45)],
        'binary' => [
            '', '123', '00123', "\0", "\0\0", 'a', "a\0", "a\0\0", 'a ', 'a  ',
            ...array_map(static fn (int $n): string => pack('N4', 0x018f00ff, 0x00807000, 0x80ff0000, $n), range(1, 40)),
        ],
        'text' => ['abc', 'ABC ', '00', '0', "x' --", "a\\b", 'Å', 'é', ...array_map(static fn (int $n): string => "key_$n", range(1, 40))],
        'latin1' => ['?', 'abc', 'é', ...array_map(static fn (int $n): string => "key_$n", range(1, 40))],
    };
    $keys = match ($kind) {
        'integer' => [...$stored, 999999999, null, 1],
        'numeric-text' => [...array_map('strval', $stored), '01', '-03', '99', null, '1'],
        'canonical-text' => [...array_map('strval', $stored), '99', null, '1'],
        'binary' => [...$stored, str_repeat("\0", 16), '000123', "a\0\0\0", null, $stored[0], $stored[1]],
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
    'canonical numeric text' => ['canonical-text', true],
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

it('does not let a permanent MySQL table change temporary table comparison rules', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'MySQL') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL temporary table shadowing.');
    }
    try {
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, seq INTEGER)');
        $db->execute('CREATE TEMPORARY TABLE cycle_test_lookup (lookup_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin, seq INTEGER)');
        $db->execute("INSERT INTO cycle_test_lookup VALUES ('abc', 1), ('ABC', 2)");
        $keys = ['abc', 'ABC', ...array_map(static fn (int $i): string => "missing_$i", range(1, 30))];
        $expected = [[1], [2], ...array_fill(0, 30, [])];
        expect((new RelationLookupOracleFetcher($db, false))($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TEMPORARY TABLE IF EXISTS cycle_test_lookup');
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
    }
});

it('preserves PostgreSQL domain-specific equality instead of coercing to its base type', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'Postgres') {
        \PHPUnit\Framework\Assert::markTestSkipped('PostgreSQL domain operator resolution.');
    }
    try {
        $db->execute('CREATE DOMAIN cycle_test_lookup_domain AS INTEGER');
        $db->execute("CREATE FUNCTION cycle_test_lookup_eq(cycle_test_lookup_domain, cycle_test_lookup_domain) RETURNS boolean LANGUAGE SQL IMMUTABLE AS 'SELECT abs($1::integer) = abs($2::integer)'");
        $db->execute('CREATE OPERATOR = (LEFTARG = cycle_test_lookup_domain, RIGHTARG = cycle_test_lookup_domain, FUNCTION = cycle_test_lookup_eq)');
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key cycle_test_lookup_domain, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 1), (-1, 2)');
        $keys = ['1', '-1', ...array_map('strval', range(10, 40))];
        $expected = [];
        foreach ($keys as $value) {
            $rows = $db->select('seq')->from('cycle_test_lookup')
                ->where('lookup_key', '=', new Parameter($value))->fetchAll();
            $seq = array_map(static fn (array $row): int => (int) $row['seq'], $rows);
            sort($seq);
            $expected[] = $seq;
        }
        expect($expected[0])->toBe([1, 2], 'Fixture must exercise the domain-specific operator.');
        expect((new RelationLookupOracleFetcher($db, false))($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
        $db->execute('DROP OPERATOR IF EXISTS = (cycle_test_lookup_domain, cycle_test_lookup_domain)');
        $db->execute('DROP FUNCTION IF EXISTS cycle_test_lookup_eq(cycle_test_lookup_domain, cycle_test_lookup_domain)');
        $db->execute('DROP DOMAIN IF EXISTS cycle_test_lookup_domain');
    }
});


it('keeps floating point keys distinct when PHP serialization precision is reduced', function (): void {
    $db = relationEqualityDatabase();
    $previous = ini_set('serialize_precision', '2');
    try {
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key DECIMAL(10, 2), seq INTEGER)');
        $db->execute("INSERT INTO cycle_test_lookup VALUES (1.21, 1), (1.22, 2), (1.23, 3)");
        $fetcher = new RelationLookupOracleFetcher($db, false);
        $keys = [1.21, 1.22, 1.23, 1.21, null, 1.24];
        $expected = [];
        foreach ($keys as $key) {
            if ($key === null) {
                $expected[] = [];
                continue;
            }
            $rows = $db->select('seq')->from('cycle_test_lookup')
                ->where('lookup_key', '=', new Parameter($key))->fetchAll();
            $expected[] = array_map(static fn (array $row): int => (int) $row['seq'], $rows);
        }
        expect($expected)->toBe([[1], [2], [3], [1], [], []]);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
    } finally {
        if ($previous !== false) {
            ini_set('serialize_precision', $previous);
        }
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
    }
});

it('reused relation fetchers follow a replacement schema and fresh comparison rules', function (): void {
    $db = relationEqualityDatabase();
    $fetcher = new RelationLookupOracleFetcher($db, false);
    $keys = ['1', '01', ...array_map('strval', range(10, 40))];
    $textType = match ($db->getType()) {
        'MySQL' => 'VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
        'SQLServer' => 'VARCHAR(64) COLLATE Latin1_General_100_BIN2',
        'Postgres' => 'VARCHAR(64) COLLATE "C"',
        default => 'TEXT COLLATE BINARY',
    };
    try {
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key BIGINT, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 1)');
        $expected = [[1], [1], ...array_fill(0, 31, [])];
        expect($fetcher($keys))->toBe($expected);
        $db->execute('DROP TABLE cycle_test_lookup');
        $db->execute("CREATE TABLE cycle_test_lookup (lookup_key $textType, seq INTEGER)");
        $db->execute("INSERT INTO cycle_test_lookup VALUES ('1', 1), ('01', 2)");
        $expected = [[1], [2], ...array_fill(0, 31, [])];
        expect($fetcher($keys))->toBe($expected);
        expect((new RelationLookupOracleFetcher($db, false))($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
    }
});

it('loads a small batch from a MySQL temporary relation table', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'MySQL') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL temporary table reopening limitation.');
    }
    try {
        $db->execute('CREATE TEMPORARY TABLE cycle_test_lookup (lookup_key INTEGER, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 10), (2, 20)');
        $fetcher = new RelationLookupOracleFetcher($db, false);
        expect($fetcher([1, 2, 3, 1, null]))->toBe([[10], [20], [], [10], []]);
    } finally {
        $db->execute('DROP TEMPORARY TABLE IF EXISTS cycle_test_lookup');
    }
});

it('does not require the default PDO column name case for MySQL metadata', function (): void {
    if (getenv('CYCLE_TEST_DB_DRIVER') !== 'mysql') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL metadata case handling.');
    }
    $db = new \Cycle\Database\Database('case-test', '', \Cycle\Database\Driver\MySQL\MySQLDriver::create(
        new \Cycle\Database\Config\MySQLDriverConfig(
            new \Cycle\Database\Config\MySQL\TcpConnectionConfig(
                database: getenv('CYCLE_TEST_DB_NAME') ?: 'cycle_tests',
                host: getenv('CYCLE_TEST_DB_HOST') ?: '127.0.0.1',
                port: (int) (getenv('CYCLE_TEST_DB_PORT') ?: 3306),
                user: getenv('CYCLE_TEST_DB_USER') ?: 'root',
                password: getenv('CYCLE_TEST_DB_PASSWORD') ?: '',
                options: [\PDO::ATTR_CASE => \PDO::CASE_LOWER],
            ),
            queryCache: false,
        ),
    ));
    try {
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key INTEGER, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 10), (2, 20)');
        $keys = range(1, 32);
        expect((new RelationLookupOracleFetcher($db, false))($keys))->toBe([[10], [20], ...array_fill(0, 30, [])]);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
        $db->getDriver()->disconnect();
    }
});
