<?php

declare(strict_types=1);

namespace Componenta\Cycle\Benchmark;

use Componenta\Cycle\DataFetcher;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\RequiresTotalCountInterface;
use Componenta\Stdlib\Paginator;
use Cycle\Database\Query\SelectQuery;

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

final class HydrationRecord
{
    public int $id;
    public string $first;
    public string $last;
    public string $email;
    public string $phone;
    public string $city;
    public string $region;
    public string $country;
    public string $address;
    public string $zip;
    public int $age;
    public bool $active;
    public array $related;
}

final class HydrationChild
{
    public int $id;
    public int $recordId;
}

/** Numeric and binary keys share the same indexed workload. */
final class DatabaseRelationFetcher extends DataFetcher
{
    public function __construct(\Cycle\Database\DatabaseInterface $db, private readonly bool $binary)
    {
        parent::__construct($db);
    }

    public function __invoke(int $size): array
    {
        return $this->doFetch(new Page($size, 0))->results;
    }

    protected function getTableName(): string
    {
        return 'cycle_bench_roots';
    }

    protected function select(?object $query): SelectQuery
    {
        return parent::select($query)->orderBy('seq');
    }

    protected function isBinaryRelationKey(string $column): bool
    {
        return $this->binary && in_array($column, ['id', 'owner_id'], true);
    }

    protected function loadRelations(array &$results, array $rows, ?object $query): void
    {
        $this->batchBelongsTo($results, $rows, 'owner_id', 'cycle_bench_owners',
            transform: static fn (array $row): int => (int) $row['seq']);
        $this->batchHasMany($results, $rows, 'children', 'cycle_bench_children', 'root_id',
            transform: static fn (array $row): int => (int) $row['seq']);
        $this->batchBelongsToMany($results, $rows, 'tags', 'cycle_bench_tags', 'cycle_bench_pivot',
            'root_id', 'tag_id', transform: static fn (array $row): int => (int) $row['seq']);
    }
}
