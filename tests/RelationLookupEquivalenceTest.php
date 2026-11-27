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
    public function __construct(
        DatabaseInterface $db,
        private readonly bool $binary,
        private readonly bool $aliased = false,
    ) {
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
            columns: $this->aliased ? ['seq AS match_seq'] : ['*'],
            transform: fn (array $row): int => (int) $row[$this->aliased ? 'match_seq' : 'seq']);
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
        $aliased = new RelationLookupOracleFetcher($db, false, true);
        expect($aliased([1, 2, 3, 1, null]))->toBe([[10], [20], [], [10], []]);
        // Exercise more than one batch when no exact numeric lookup applies.
        $db->execute('DROP TEMPORARY TABLE cycle_test_lookup');
        $db->execute('CREATE TEMPORARY TABLE cycle_test_lookup (lookup_key VARCHAR(64) CHARACTER SET latin1 COLLATE latin1_swedish_ci, seq INTEGER)');
        $db->execute("INSERT INTO cycle_test_lookup VALUES ('abc', 1), ('ABC', 2)");
        $keys = ['abc', 'ABC', ...array_map(static fn (int $i): string => "missing_$i", range(1, 501))];
        $expected = [[1, 2], [1, 2], ...array_fill(0, 501, [])];
        expect($fetcher($keys))->toBe($expected);
        expect($aliased($keys))->toBe($expected);
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


it('loads bound projection expressions from a MySQL temporary table across batches without repeating transforms', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'MySQL') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL temporary table reopening limitation.');
    }
    try {
        $db->execute('CREATE TEMPORARY TABLE cycle_test_lookup (lookup_key INTEGER, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 10), (2, 20), (501, 30)');
        $fetcher = new BoundTemporaryRelationFetcher($db);
        $keys = [...range(1, 501), 1, null];
        $expected = [[17], [27], ...array_fill(0, 498, []), [37], [17], []];
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher->transforms)->toBe(3);
        expect($fetcher([501, 2, null]))->toBe([[37], [27], []]);
        expect($fetcher->transforms)->toBe(5);
    } finally {
        $db->execute('DROP TEMPORARY TABLE IF EXISTS cycle_test_lookup');
    }
});

it('does not interpret a relation callback exception as a database retry', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'MySQL') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL-specific retry classification.');
    }
    try {
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key INTEGER, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 10), (2, 20)');
        $fetcher = new BoundTemporaryRelationFetcher($db);
        $cause = new \PDOException('Application callback failed.');
        $cause->errorInfo = ['HY000', 1137, 'Application callback failed.'];
        $failure = new \Cycle\Database\Exception\StatementException($cause, 'callback');
        $fetcher->failure = $failure;
        try {
            $fetcher([1, 2]);
            \PHPUnit\Framework\Assert::fail('The callback exception must be propagated.');
        } catch (\Cycle\Database\Exception\StatementException $caught) {
            expect($caught)->toBe($failure);
        }
        expect($fetcher->transforms)->toBe(1);
        $fetcher->failure = null;
        expect($fetcher([1, 2]))->toBe([[17], [27]]);
        expect($fetcher->transforms)->toBe(3);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
    }
});

final class BoundTemporaryRelationFetcher extends DataFetcher
{
    public int $transforms = 0;
    public ?\Throwable $failure = null;

    public function __invoke(array $keys): array
    {
        $rows = array_map(static fn (mixed $key): array => ['id' => $key], $keys);
        return array_column($this->fetchResults($rows, null, null), 'matches');
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchHasMany(
            $results, $rows, 'matches', 'cycle_test_lookup', 'lookup_key',
            columns: [new \Cycle\Database\Injection\Fragment('seq + ? AS computed', new Parameter(7))],
            transform: function (array $row): int {
                ++$this->transforms;
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return (int) $row['computed'];
            },
        );
    }
}

it('retains a shadowed PostgreSQL integer equality operator', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'Postgres') {
        \PHPUnit\Framework\Assert::markTestSkipped('PostgreSQL operator visibility.');
    }
    try {
        $db->execute('CREATE SCHEMA cycle_test_lookup_schema');
        $db->execute("CREATE FUNCTION cycle_test_lookup_schema.equal_int(INTEGER, INTEGER) RETURNS boolean LANGUAGE SQL IMMUTABLE AS 'SELECT abs($1) OPERATOR(pg_catalog.=) abs($2)'");
        $db->execute('CREATE OPERATOR cycle_test_lookup_schema.= (LEFTARG = INTEGER, RIGHTARG = INTEGER, FUNCTION = cycle_test_lookup_schema.equal_int)');
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key INTEGER, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (1, 1), (-1, 2)');
        $db->execute('SET search_path TO cycle_test_lookup_schema, pg_catalog, public');
        $keys = [1, -1, ...range(10, 40)];
        $fetcher = new RelationLookupOracleFetcher($db, false);
        $expected = [[1, 2], [1, 2], ...array_fill(0, 31, [])];
        $rows = $db->select('seq')->from('cycle_test_lookup')
            ->where('lookup_key', '=', new Parameter(1))->fetchAll();
        expect(array_map(static fn (array $row): int => (int) $row['seq'], $rows))
            ->toEqualCanonicalizing([1, 2]);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
    } finally {
        $db->execute('RESET search_path');
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
        $db->execute('DROP SCHEMA IF EXISTS cycle_test_lookup_schema CASCADE');
    }
});


it('matches SQL Server collated text against individual predicates including ANSI and Unicode', function (string $type, string $collation, bool $unicodeKeys): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'SQLServer') {
        \PHPUnit\Framework\Assert::markTestSkipped('SQL Server text comparison.');
    }
    try {
        $db->execute("CREATE TABLE cycle_test_lookup (lookup_key $type COLLATE $collation, seq INTEGER)");
        $db->execute('CREATE INDEX cycle_test_lookup_idx ON cycle_test_lookup(lookup_key)');
        $stored = ['abc', 'ABC', 'abc ', 'a', 'A', 'á', 'é', 'e', 'å', 'a-b', 'a b', '0', '00', '', ' ', 'x"y', "x\\y", "x'y"];
        foreach ($stored as $index => $value) {
            $db->execute('INSERT INTO cycle_test_lookup VALUES (?, ?)', [new Parameter($value), new Parameter($index + 1)]);
        }
        $keys = ['abc', 'ABC', 'Abc', 'abc  ', 'a', 'A', 'e', 'E', '0', '00', '', ' ', 'a-b', 'a b', 'x"y', "x\\y", "x'y", ...array_map('strval', range(10, 32))];
        if ($unicodeKeys) {
            array_push($keys, 'á', 'é', 'Å', 'å', '😀');
        }
        $expected = [];
        foreach ($keys as $key) {
            $rows = $db->select('seq')->from('cycle_test_lookup')->where('lookup_key', '=', new Parameter($key))->fetchAll();
            $values = array_map(static fn (array $row): int => (int) $row['seq'], $rows);
            sort($values);
            $expected[] = $values;
        }
        $fetcher = new RelationLookupOracleFetcher($db, false);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
    }
})->with([
    'ANSI case insensitive' => ['VARCHAR(64)', 'Latin1_General_100_CI_AS', false],
    'ANSI accent insensitive' => ['VARCHAR(64)', 'Latin1_General_100_CI_AI', false],
    'ANSI non-ASCII fallback' => ['VARCHAR(64)', 'Latin1_General_100_CI_AS', true],
    'Unicode case insensitive' => ['NVARCHAR(64)', 'Latin1_General_100_CI_AS', true],
    'Unicode binary' => ['NVARCHAR(64)', 'Latin1_General_100_BIN2', true],
    'SQL collation fallback' => ['VARCHAR(64)', 'SQL_Latin1_General_CP1_CI_AS', false],
]);


it('preserves MySQL in-range noncanonical integer strings and latin1 ASCII comparisons', function (bool $numeric): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'MySQL') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL derived key comparison.');
    }
    $type = $numeric ? 'INTEGER' : 'VARCHAR(64) CHARACTER SET latin1 COLLATE latin1_swedish_ci';
    $stored = $numeric ? [-2147483648, 2147483647, -3, 0, 1, 2, 17] : ['abc', 'ABC', 'abc ', 'e', 'é', 'å', '0', '00', '', ' ', 'x"y', "x\\y"];
    $keys = $numeric
        ? ['-02147483648', '0002147483647', '-003', '-0', '0000', '01', '1', '002', ...array_map('strval', range(10, 45))]
        : ['abc', 'AbC', 'ABC ', 'e', 'E', 'a', '0', '00', '', ' ', 'x"y', "x\\y", ...array_map('strval', range(10, 45))];
    try {
        $db->execute("CREATE TABLE cycle_test_lookup (lookup_key $type, seq INTEGER)");
        $db->execute('CREATE INDEX cycle_test_lookup_idx ON cycle_test_lookup(lookup_key)');
        foreach ($stored as $index => $value) {
            $db->execute('INSERT INTO cycle_test_lookup VALUES (?, ?)', [new Parameter($value), new Parameter($index + 1)]);
        }
        $expected = [];
        foreach ($keys as $key) {
            $rows = $db->select('seq')->from('cycle_test_lookup')->where('lookup_key', '=', new Parameter($key))->fetchAll();
            $values = array_map(static fn (array $row): int => (int) $row['seq'], $rows);
            sort($values);
            $expected[] = $values;
        }
        $fetcher = new RelationLookupOracleFetcher($db, false);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
        // One nondecimal or out-of-range string forces native predicates
        // for the complete numeric batch instead of truncating the key.
        if ($numeric) {
            $keys[] = '2147483648';
            $expected[] = [];
            expect($fetcher($keys))->toBe($expected);
        }
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
    }
})->with([true, false]);


it('uses the native PostgreSQL array expansion even with an application overload', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'Postgres') {
        \PHPUnit\Framework\Assert::markTestSkipped('PostgreSQL array function resolution.');
    }
    try {
        $db->execute('CREATE FUNCTION public.unnest(VARCHAR[]) RETURNS SETOF VARCHAR LANGUAGE SQL IMMUTABLE AS \'SELECT NULL::VARCHAR WHERE FALSE\'');
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key VARCHAR(64), seq INTEGER)');
        $db->execute("INSERT INTO cycle_test_lookup VALUES ('abc', 1), ('ABC', 2)");
        $fetcher = new RelationLookupOracleFetcher($db, false);
        $keys = ['abc', 'ABC', ...array_map(static fn (int $i): string => "missing_$i", range(1, 40))];
        $expected = [[1], [2], ...array_fill(0, 40, [])];
        expect($fetcher(['abc', 'ABC']))->toBe([[1], [2]]);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
        $db->execute('DROP FUNCTION IF EXISTS public.unnest(VARCHAR[])');
    }
});

it('retains PostgreSQL nondeterministic ICU collation in batched comparisons', function (): void {
    $db = relationEqualityDatabase();
    if ($db->getType() !== 'Postgres') {
        \PHPUnit\Framework\Assert::markTestSkipped('PostgreSQL ICU collation.');
    }
    try {
        $db->execute("CREATE COLLATION cycle_test_lookup_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key TEXT COLLATE cycle_test_lookup_ci, seq INTEGER)');
        $db->execute("INSERT INTO cycle_test_lookup VALUES ('abc', 1), ('ABC', 2), ('é', 3), ('e', 4)");
        $keys = ['abc', 'ABC', 'Abc', 'é', "e\u{0301}", 'E', 'e', ...array_map('strval', range(10, 40))];
        $expected = [];
        foreach ($keys as $key) {
            $matches = $db->select('seq')->from('cycle_test_lookup')
                ->where('lookup_key', '=', new Parameter($key))->fetchAll();
            $values = array_map(static fn (array $row): int => (int) $row['seq'], $matches);
            sort($values);
            $expected[] = $values;
        }
        expect($expected[0])->toBe([1, 2]);
        expect($expected[4])->toBe([3]);
        $fetcher = new RelationLookupOracleFetcher($db, false);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
        $db->execute('DROP COLLATION IF EXISTS cycle_test_lookup_ci');
    }
});

it('groups MySQL zerofill integer keys with stringified PDO results', function (): void {
    if (getenv('CYCLE_TEST_DB_DRIVER') !== 'mysql') {
        \PHPUnit\Framework\Assert::markTestSkipped('MySQL zerofill representation.');
    }
    $db = new \Cycle\Database\Database('zerofill-test', '', \Cycle\Database\Driver\MySQL\MySQLDriver::create(
        new \Cycle\Database\Config\MySQLDriverConfig(
            new \Cycle\Database\Config\MySQL\TcpConnectionConfig(
                database: getenv('CYCLE_TEST_DB_NAME') ?: 'cycle_tests',
                host: getenv('CYCLE_TEST_DB_HOST') ?: '127.0.0.1',
                port: (int) (getenv('CYCLE_TEST_DB_PORT') ?: 3306),
                user: getenv('CYCLE_TEST_DB_USER') ?: 'root',
                password: getenv('CYCLE_TEST_DB_PASSWORD') ?: '',
                options: [\PDO::ATTR_STRINGIFY_FETCHES => true, \PDO::ATTR_EMULATE_PREPARES => false],
            ),
            queryCache: false,
        ),
    ));
    try {
        $db->execute('CREATE TABLE cycle_test_lookup (lookup_key INT(10) ZEROFILL, seq INTEGER)');
        $db->execute('INSERT INTO cycle_test_lookup VALUES (0, 10), (1, 11), (2, 12), (4294967295, 13)');
        $keys = [0, 1, 2, 4294967295, ...range(10, 40)];
        $expected = [[10], [11], [12], [13], ...array_fill(0, 31, [])];
        $fetcher = new RelationLookupOracleFetcher($db, false);
        expect($fetcher($keys))->toBe($expected);
        expect($fetcher($keys))->toBe($expected);
    } finally {
        $db->execute('DROP TABLE IF EXISTS cycle_test_lookup');
        $db->getDriver()->disconnect();
    }
});
