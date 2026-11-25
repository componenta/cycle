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
use PHPUnit\Framework\TestCase;

final class BinaryRelationLoadingTest extends TestCase
{
    public function testPostgresByteaKeysLoadAllThreeRelationTypes(): void
    {
        if (getenv('CYCLE_TEST_DB_DRIVER') !== 'postgres') {
            self::markTestSkipped('PostgreSQL bytea integration test.');
        }

        $settings = ['DB_DRIVER' => 'postgres'];
        foreach (['NAME', 'HOST', 'PORT', 'USER', 'PASSWORD', 'SCHEMA'] as $key) {
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
        $lob = static fn (string $value): Parameter => new Parameter($value, \PDO::PARAM_LOB);

        try {
            $db->execute('CREATE TABLE cycle_test_binary_roots (id BYTEA PRIMARY KEY, owner_id BYTEA, seq INTEGER)');
            $db->execute('CREATE TABLE cycle_test_binary_owners (id BYTEA PRIMARY KEY, label VARCHAR(32))');
            $db->execute('CREATE TABLE cycle_test_binary_children (parent_id BYTEA, label VARCHAR(32))');
            $db->execute('CREATE TABLE cycle_test_binary_tags (id BYTEA PRIMARY KEY, label VARCHAR(32))');
            $db->execute('CREATE TABLE cycle_test_binary_pivot (root_id BYTEA, tag_id BYTEA)');
            $db->execute('INSERT INTO cycle_test_binary_roots VALUES (?, ?, 1), (?, ?, 2)',
                [$lob($root), $lob($owner), $lob($other), null]);
            $db->execute('INSERT INTO cycle_test_binary_roots VALUES (?, ?, 3)', [$lob($repeated), $lob($owner)]);
            $db->execute("INSERT INTO cycle_test_binary_owners VALUES (?, 'owner')", [$lob($owner)]);
            $db->execute("INSERT INTO cycle_test_binary_children VALUES (?, 'child')", [$lob($root)]);
            $db->execute("INSERT INTO cycle_test_binary_tags VALUES (?, 'tag')", [$lob($tag)]);
            $db->execute('INSERT INTO cycle_test_binary_pivot VALUES (?, ?)', [$lob($root), $lob($tag)]);

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

            $rows = (new BinaryRelationsFetcher($db))();

            self::assertSame([
                ['id' => bin2hex($root), 'ownerId' => bin2hex($owner), 'seq' => 1,
                    'owner' => 'owner', 'children' => ['child'], 'tags' => ['tag']],
                ['id' => bin2hex($other), 'ownerId' => null, 'seq' => 2,
                    'owner' => null, 'children' => [], 'tags' => []],
                ['id' => bin2hex($repeated), 'ownerId' => bin2hex($owner), 'seq' => 3,
                    'owner' => 'owner', 'children' => [], 'tags' => []],
            ], $rows);
            self::assertSame(4, $logger->reads);
        } finally {
            foreach (['cycle_test_binary_pivot', 'cycle_test_binary_tags', 'cycle_test_binary_children',
                'cycle_test_binary_owners', 'cycle_test_binary_roots'] as $table) {
                $db->execute('DROP TABLE IF EXISTS ' . $table);
            }
        }
    }
}

final class BinaryRelationsFetcher extends DataFetcher
{
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

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $label = static fn (array $row): string => $row['label'];
        $this->batchBelongsTo($results, $rows, 'owner_id', 'cycle_test_binary_owners',
            columns: ['label'], transform: static function (array $row): string {
                $key = $row['id'];
                if (is_resource($key)) {
                    TestCase::assertSame(0, ftell($key));
                    TestCase::assertSame(16, strlen(stream_get_contents($key)));
                    // The same database owner is transformed for two root rows.
                    // Closing a callback's stream must not invalidate the next row.
                    fclose($key);
                }
                return $row['label'];
            });
        $this->batchHasMany($results, $rows, 'children', 'cycle_test_binary_children', 'parent_id',
            columns: ['label'], transform: static function (array $row): string {
                $key = $row['parent_id'];
                if (is_resource($key)) {
                    TestCase::assertSame(0, ftell($key));
                    TestCase::assertSame(16, strlen(stream_get_contents($key)));
                }
                return $row['label'];
            });
        $this->batchBelongsToMany($results, $rows, 'tags', 'cycle_test_binary_tags',
            'cycle_test_binary_pivot', 'root_id', 'tag_id', columns: ['label'], transform: $label);
    }
}
