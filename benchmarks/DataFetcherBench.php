<?php

declare(strict_types=1);

namespace Componenta\Cycle\Benchmark;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Cycle\ConfigProvider;
use Componenta\Cycle\DataFetcher;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\RequiresTotalCountInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\Paginator;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Query\SelectQuery;

use Cycle\Database\DatabaseInterface;
use PhpBench\Attributes as Bench;

class RowsFetcher extends DataFetcher
{
    protected array $fieldMapping = ['amount' => 'total'];
    protected array $excluded = ['email'];

    public function __invoke(array $rows): iterable
    {
        return $this->fetchResults($rows, null, null);
    }
}

final class CustomRowsFetcher extends RowsFetcher
{
    protected function mapAmount(mixed $value, array $row, ?object $query): int
    {
        return (int) $value;
    }
}

class Page implements PaginableInterface
{
    public function __construct(public int $limit = 100, public int $offset = 500) {}
}

final class CountedPage extends Page implements RequiresTotalCountInterface {}

class PageFetcher extends DataFetcher
{
    protected function getTableName(): string
    {
        return 'records';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('id');
    }

    public function __invoke(Page $page): Paginator
    {
        return $this->doFetch($page);
    }
}

final class RelatedPageFetcher extends PageFetcher
{
    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchBelongsTo($results, $rows, 'owner_id', 'owners');
        $this->batchHasMany($results, $rows, 'children', 'children', 'record_id');
        $this->batchBelongsToMany($results, $rows, 'tags', 'tags', 'record_tags', 'record_id', 'tag_id');
    }
}

#[Bench\Iterations(9)]
#[Bench\BeforeMethods('prepare')]
final class DataFetcherBench
{
    private DatabaseInterface $db;
    private array $rows;
    private RowsFetcher $plain;
    private CustomRowsFetcher $custom;
    private PageFetcher $page;
    private RelatedPageFetcher $related;
    private Page $withoutTotal;
    private CountedPage $withTotal;
    private Page $relationPage;
    private Page $deepPage;

    public function prepare(): void
    {
        $composition = (new ConfigFactory())->create(
            new Environment(['DB_DRIVER' => 'sqlite', 'DB_NAME' => ':memory:']),
            new ConfigProvider(),
        );
        $db = (new ContainerFactory())->create($composition->config, $composition->dependencies)
            ->get(DatabaseManager::class)->database();

        $db->execute('CREATE TABLE records (id INTEGER PRIMARY KEY, owner_id INTEGER, name TEXT)');
        $db->execute('CREATE TABLE owners (id INTEGER PRIMARY KEY)');
        $db->execute('CREATE TABLE children (id INTEGER PRIMARY KEY, record_id INTEGER)');
        $db->execute('CREATE TABLE tags (id INTEGER PRIMARY KEY)');
        $db->execute('CREATE TABLE record_tags (record_id INTEGER, tag_id INTEGER)');
        $sequence = 'WITH RECURSIVE seq(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM seq WHERE i < 20000) ';
        $db->execute($sequence . "INSERT INTO records SELECT i, i, 'record' FROM seq");
        $db->execute($sequence . 'INSERT INTO owners SELECT i FROM seq');
        $db->execute($sequence . 'INSERT INTO children SELECT i, i FROM seq');
        $db->execute($sequence . 'INSERT INTO tags SELECT i FROM seq');
        $db->execute($sequence . 'INSERT INTO record_tags SELECT i, i FROM seq');
        $db->execute('CREATE INDEX children_record ON children(record_id)');
        $db->execute('CREATE INDEX record_tags_record ON record_tags(record_id)');

        $rows = [];
        for ($i = 1; $i <= 20000; ++$i) {
            $rows[] = [
                'id' => $i,
                'first_name' => 'First',
                'last_name' => 'Last',
                'phone' => '555',
                'email' => 'test@example.test',
                'created_at' => '2026-11-25',
                'updated_at' => '2026-11-25',
                'amount' => '100',
            ];
        }

        $plain = new RowsFetcher($db);
        $custom = new CustomRowsFetcher($db);
        $page = new PageFetcher($db);
        $related = new RelatedPageFetcher($db);
        $withoutTotal = new Page();
        $withTotal = new CountedPage();
        $relationPage = new Page(1000, 0);

        $first = $plain($rows);
        check(count($first) === 20000 && $first[0]['total'] === '100' && !isset($first[0]['email']));
        unset($first);
        $first = $custom($rows);
        check(count($first) === 20000 && $first[0]['total'] === 100);
        unset($first);
        $first = $page($withoutTotal);
        check(count($first->results) === 100 && $first->results[0]['id'] === 501 && $first->hasNextPage);
        check($first->totalCount === null);
        $first = $page($withTotal);
        check($first->totalCount === 20000 && $first->results[0]['id'] === 501);
        $first = $related($relationPage);
        check(count($first->results) === 1000
            && $first->results[999]['owner']['id'] === 1000
            && $first->results[999]['children'][0]['record_id'] === 1000
            && $first->results[999]['tags'][0]['id'] === 1000);
        unset($first);


        $this->db = $db;
        $this->rows = $rows;
        $this->plain = $plain;
        $this->custom = $custom;
        $this->page = $page;
        $this->related = $related;
        $this->withoutTotal = $withoutTotal;
        $this->withTotal = $withTotal;
        $this->relationPage = $relationPage;
        $this->deepPage = new Page(100, 19000);
        $deep = $page($this->deepPage);
        check($deep->results[0]['id'] === 19001 && $deep->hasNextPage);
    }

    #[Bench\Revs(8)]
    #[Bench\Warmup(2)]
    public function benchDefaultMapping20000(): void
    {
        ($this->plain)($this->rows);
    }

    #[Bench\Revs(8)]
    public function benchColdDefaultMapping20000(): void
    {
        (new RowsFetcher($this->db))($this->rows);
    }

    #[Bench\Revs(8)]
    #[Bench\Warmup(2)]
    public function benchCustomMapping20000(): void
    {
        ($this->custom)($this->rows);
    }

    #[Bench\Revs(100)]
    #[Bench\Warmup(2)]
    public function benchPageWithoutTotal100(): void
    {
        ($this->page)($this->withoutTotal);
    }

    #[Bench\Revs(100)]
    #[Bench\Warmup(2)]
    public function benchPageWithTotal100(): void
    {
        ($this->page)($this->withTotal);
    }

    #[Bench\Revs(50)]
    #[Bench\Warmup(2)]
    public function benchDeepOffsetPage100(): void
    {
        ($this->page)($this->deepPage);
    }

    #[Bench\Revs(10)]
    #[Bench\Warmup(2)]
    public function benchRelationsThreeTypes1000(): void
    {
        ($this->related)($this->relationPage);
    }

    #[Bench\Revs(10)]
    public function benchColdRelationsThreeTypes1000(): void
    {
        (new RelatedPageFetcher($this->db))($this->relationPage);
    }
}
