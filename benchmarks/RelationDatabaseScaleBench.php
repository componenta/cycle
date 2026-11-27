<?php

declare(strict_types=1);

namespace Componenta\Cycle\Benchmark;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use PhpBench\Attributes as Bench;

/**
 * Selective relation lookups against a substantially larger indexed table.
 * Synthetic root rows isolate the relation-loading cost from pagination.
 */
#[Bench\Iterations(3)]
#[Bench\BeforeMethods('prepare')]
#[Bench\AfterMethods('cleanup')]
#[Bench\ParamProviders('provideKeys')]
final class RelationDatabaseScaleBench
{
    private const int ROWS = 65536;
    private DatabaseInterface $db;
    private ScaleRelationFetcher $fetcher;
    private array $small;
    private array $large;

    public function provideKeys(): iterable
    {
        yield 'integer' => ['text' => false];
        yield 'collated-text' => ['text' => true];
    }

    public function prepare(array $params): void
    {
        $settings = ['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:'];
        foreach (['DRIVER', 'NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA', 'TRUST_SERVER_CERTIFICATE'] as $name) {
            $value = getenv('CYCLE_TEST_DB_' . $name);
            if ($value !== false) {
                $settings['DB_' . $name] = $value;
            }
        }
        $composition = (new ConfigFactory())->create(new Environment($settings), new ConfigProvider());
        $this->db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
            ->get(DatabaseManager::class)->database();
        $this->cleanup();
        $driver = $this->db->getType();
        $text = $params['text'];
        $type = !$text ? 'INTEGER' : match ($driver) {
            'MySQL' => 'VARCHAR(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci',
            'SQLServer' => 'VARCHAR(32) COLLATE Latin1_General_100_CI_AS',
            'Postgres' => 'VARCHAR(32) COLLATE "C"',
            default => 'TEXT COLLATE NOCASE',
        };
        $this->db->execute("CREATE TABLE cycle_bench_scale (seq INTEGER NOT NULL, lookup_key $type NOT NULL)");
        $key = static fn (string $seq): string => !$text ? $seq
            : match ($driver) {
                'SQLite', 'Postgres' => "'key_' || ($seq)",
                default => "CONCAT('key_', $seq)",
            };
        $this->db->execute('INSERT INTO cycle_bench_scale SELECT 0, ' . $key('0'));
        // Grow the table geometrically without a driver-specific series
        // function or a large number of network round trips.
        for ($count = 1; $count < self::ROWS; $count *= 2) {
            $next = "seq + $count";
            $this->db->execute('INSERT INTO cycle_bench_scale SELECT ' . $next . ', ' . $key($next) . ' FROM cycle_bench_scale');
        }
        $this->db->execute('CREATE INDEX cycle_bench_scale_key ON cycle_bench_scale (lookup_key)');
        $statement = $this->db->query('SELECT COUNT(*) FROM cycle_bench_scale');
        try {
            check((int) $statement->fetchColumn() === self::ROWS);
        } finally {
            $statement->close();
        }

        $keyPrefix = $driver === 'Postgres' ? 'key_' : 'KEY_';
        $indices = array_map(static fn (int $i): int => ($i * 61 + 137) % self::ROWS, range(0, 999));
        $this->large = $text ? array_map(static fn (int $i): string => $keyPrefix . $i, $indices) : $indices;
        $this->small = array_slice($this->large, 0, 10);
        $this->fetcher = new ScaleRelationFetcher($this->db);
        self::verify(($this->fetcher)($this->small), array_slice($indices, 0, 10));
        self::verify(($this->fetcher)($this->large), $indices);
        self::verify((new ScaleRelationFetcher($this->db))($this->large), $indices);
    }

    public function cleanup(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS cycle_bench_scale');
    }

    private static function verify(array $rows, array $indices): void
    {
        check(count($rows) === count($indices));
        foreach ($rows as $index => $row) {
            check($row['matches'] === [$indices[$index]]);
        }
    }

    #[Bench\Revs(3)]
    #[Bench\Warmup(1)]
    public function benchSparseSmallPage(): void
    {
        ($this->fetcher)($this->small);
    }

    #[Bench\Revs(2)]
    #[Bench\Warmup(1)]
    public function benchSparseLargePage(): void
    {
        ($this->fetcher)($this->large);
    }
}

final class ScaleRelationFetcher extends DataFetcher
{
    public function __invoke(array $keys): array
    {
        $rows = array_map(static fn (mixed $key): array => ['id' => $key], $keys);
        return $this->fetchResults($rows, null, null);
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchHasMany(
            $results, $rows, 'matches', 'cycle_bench_scale', 'lookup_key',
            columns: ['seq'], transform: static fn (array $row): int => (int) $row['seq'],
        );
    }
}
