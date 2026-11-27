<?php

declare(strict_types=1);

namespace Componenta\Cycle\Benchmark;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\DI\ContainerFactory;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Injection\Parameter;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\BeforeMethods('prepare')]
#[Bench\AfterMethods('cleanup')]
#[Bench\ParamProviders('provideKeys')]
final class RelationDatabaseBench
{
    private const int ROWS = 1200;
    private DatabaseInterface $db;
    private DatabaseRelationFetcher $fetcher;

    public function provideKeys(): iterable
    {
        yield 'integer' => ['binary' => false, 'kind' => 'exact'];
        yield 'binary' => ['binary' => true, 'kind' => 'exact'];
        yield 'numeric-text' => ['binary' => false, 'kind' => 'numeric-text'];
        yield 'collated-text' => ['binary' => false, 'kind' => 'collated-text'];
        yield 'bound-projection' => ['binary' => false, 'kind' => 'bound-projection'];
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

        $binary = $params['binary'];
        $type = !$binary ? 'INTEGER' : match ($this->db->getType()) {
            'Postgres' => 'BYTEA',
            'MySQL' => 'BINARY(16)',
            'SQLServer' => 'VARBINARY(16)',
            default => 'BLOB',
        };
        $kind = $params['kind'];
        if ($kind === 'collated-text') {
            $type = match ($this->db->getType()) {
                'MySQL' => 'VARCHAR(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci',
                'SQLServer' => 'VARCHAR(32) COLLATE Latin1_General_100_CI_AS',
                'Postgres' => 'VARCHAR(32) COLLATE "C"',
                default => 'TEXT COLLATE NOCASE',
            };
        }
        $rootType = $kind === 'numeric-text' ? 'VARCHAR(32)' : $type;
        $this->db->execute("CREATE TABLE cycle_bench_roots (seq INTEGER PRIMARY KEY, id $rootType NOT NULL UNIQUE, owner_id $rootType NOT NULL)");
        $this->db->execute("CREATE TABLE cycle_bench_owners (id $type PRIMARY KEY, seq INTEGER NOT NULL)");
        $this->db->execute("CREATE TABLE cycle_bench_children (root_id $type NOT NULL, seq INTEGER NOT NULL)");
        $this->db->execute("CREATE TABLE cycle_bench_tags (id $type PRIMARY KEY, seq INTEGER NOT NULL)");
        $this->db->execute("CREATE TABLE cycle_bench_pivot (root_id $type NOT NULL, tag_id $type NOT NULL)");
        $this->db->execute('CREATE INDEX cycle_bench_children_root ON cycle_bench_children(root_id)');
        $this->db->execute('CREATE INDEX cycle_bench_pivot_root ON cycle_bench_pivot(root_id)');
        $key = static fn (int $i): Parameter => $binary
            ? new Parameter(pack('N4', 0x018f00ff, 0x00807000, 0x80ff0000, $i), \PDO::PARAM_LOB)
            : new Parameter($kind === 'collated-text' ? "key_$i" : $i);
        $caseInsensitive = $kind === 'collated-text' && $this->db->getType() !== 'Postgres';
        $rootKey = static fn (int $i): Parameter => match (true) {
            $kind === 'numeric-text' => new Parameter(sprintf('%08d', $i)),
            $caseInsensitive => new Parameter("KEY_$i"),
            default => $key($i),
        };

        for ($offset = 0; $offset < self::ROWS; $offset += 100) {
            $inserts = [];
            for ($i = $offset + 1; $i <= min(self::ROWS, $offset + 100); ++$i) {
                $id = $key($i);
                $seq = new Parameter($i);
                $owner = $rootKey(intdiv($i - 1, 3) + 1);
                $inserts['roots'][] = [$seq, $rootKey($i), $owner];
                $inserts['owners'][] = [$id, $seq];
                $inserts['children'][] = [$id, $seq];
                $inserts['tags'][] = [$id, $seq];
                $inserts['pivot'][] = [$id, $id];
            }
            foreach ($inserts as $table => $rows) {
                $values = implode(', ', array_fill(0, count($rows), '(' . implode(', ', array_fill(0, count($rows[0]), '?')) . ')'));
                $this->db->execute("INSERT INTO cycle_bench_$table VALUES $values", array_merge(...$rows));
            }
        }

        $this->fetcher = new DatabaseRelationFetcher($this->db, $binary, $kind === 'bound-projection');
        self::verify(($this->fetcher)(10), 10);
        self::verify(($this->fetcher)(1000), 1000);
        self::verify((new DatabaseRelationFetcher($this->db, $binary, $kind === 'bound-projection'))(1000), 1000);
    }

    public function cleanup(): void
    {
        foreach (['pivot', 'tags', 'children', 'owners', 'roots'] as $table) {
            $this->db->execute('DROP TABLE IF EXISTS cycle_bench_' . $table);
        }
    }

    private static function verify(array $rows, int $size): void
    {
        check(count($rows) === $size);
        foreach ($rows as $index => $row) {
            $seq = $index + 1;
            check((int) $row['seq'] === $seq
                && $row['owner'] === intdiv($seq - 1, 3) + 1
                && $row['children'] === [$seq]
                && $row['tags'] === [$seq]);
        }
    }

    #[Bench\Revs(10)]
    #[Bench\Warmup(1)]
    public function benchSmallPage(array $params): void
    {
        ($this->fetcher)(10);
    }

    #[Bench\Revs(2)]
    #[Bench\Warmup(1)]
    public function benchLargePage(array $params): void
    {
        ($this->fetcher)(1000);
    }

    #[Bench\Revs(2)]
    public function benchFreshFetcher(array $params): void
    {
        (new DatabaseRelationFetcher($this->db, $params['binary'], $params['kind'] === 'bound-projection'))(1000);
    }
}
