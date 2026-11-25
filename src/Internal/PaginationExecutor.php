<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Componenta\Cycle\Query\PaginableInterface;
use Componenta\Cycle\Query\RequiresTotalCountInterface;
use Componenta\Stdlib\Paginator;
use Cycle\Database\Query\SelectQuery;
use Fiber;
use WeakMap;

/**
 * Executes pagination without coupling result transformation to SQL counting.
 *
 * @internal
 */
final class PaginationExecutor
{
    /**
     * Only fetchResults overrides that may materialize a query need an ambient
     * hint. Isolate that compatibility context by both fetcher and fiber.
     *
     * @var WeakMap<object, WeakMap<object, array{query: PaginableInterface, hasNextPage: bool}>>|null
     */
    private ?WeakMap $contexts = null;

    public function execute(
        SelectQuery $select,
        ?object $query,
        Closure $fetchResults,
        Closure $countRows,
        ?object $contextOwner,
    ): mixed {
        $select = PaginationOrder::forPage($select);

        if (!$query instanceof PaginableInterface) {
            return $fetchResults($select, null, $query);
        }
        self::assertValidPage($query);
        if ($query instanceof RequiresTotalCountInterface) {
            return $fetchResults($select, $countRows($select), $query);
        }
        if ($contextOwner === null) {
            // The base fetchResults receives the original SelectQuery and can
            // compute its hint directly, with no stored execution context.
            return $fetchResults($select, null, $query);
        }

        return $this->withHint($contextOwner, $select, $query, $fetchResults);
    }

    public function count(SelectQuery $select): int
    {
        return (new RowCountQuery($select))->count();
    }

    public function hint(iterable $rows, ?int $count, ?object $query, object $owner): ?bool
    {
        if (!$query instanceof PaginableInterface) {
            return null;
        }
        self::assertValidPage($query);
        if ($count !== null) {
            return null;
        }

        $context = $this->contexts[$owner][Fiber::getCurrent() ?? $owner] ?? null;
        if ($context !== null && $context['query'] === $query) {
            return $context['hasNextPage'];
        }

        return $rows instanceof SelectQuery ? $this->hasNextPage($rows, $query) : null;
    }

    public function result(array $results, ?object $query, ?int $count, ?bool $hint): iterable|Paginator
    {
        return $query instanceof PaginableInterface
            ? new Paginator($results, $query->limit, $query->offset, $count, $hint)
            : $results;
    }

    private static function assertValidPage(PaginableInterface $query): void
    {
        if ($query->limit < 1) {
            throw new \InvalidArgumentException('Pagination limit must be positive.');
        }
        if ($query->offset < 0) {
            throw new \InvalidArgumentException('Pagination offset must be non-negative.');
        }
    }

    private function hasNextPage(SelectQuery $select, PaginableInterface $query): bool
    {
        if ($query->limit > 0 && $query->offset > PHP_INT_MAX - $query->limit) {
            return false;
        }

        return (new RowCountQuery($select))->hasRowsAfter($query->offset + $query->limit);
    }

    private function withHint(
        object $owner,
        SelectQuery $select,
        PaginableInterface $query,
        Closure $fetchResults,
    ): mixed {
        $hint = $this->hasNextPage($select, $query);
        $owners = $this->contexts ??= new WeakMap();
        $contexts = $owners[$owner] ??= new WeakMap();
        $key = Fiber::getCurrent() ?? $owner;
        $previous = $contexts[$key] ?? null;
        $contexts[$key] = ['query' => $query, 'hasNextPage' => $hint];

        try {
            return $fetchResults($select, null, $query);
        } finally {
            if ($previous === null) {
                unset($contexts[$key]);
            } else {
                $contexts[$key] = $previous;
            }

            if (count($contexts) === 0) {
                unset($owners[$owner]);
            }
        }
    }
}
