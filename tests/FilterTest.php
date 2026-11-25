<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Filter\Direction;
use Componenta\Cycle\Filter\LimitFilter;
use Componenta\Cycle\Filter\OrderFilter;
use Componenta\Cycle\Filter\PaginationFilter;
use Componenta\Cycle\Query\SortableInterface;

it('returns the opposite sort direction', function (): void {
    expect(Direction::ASC->opposite())->toBe(Direction::DESC);
    expect(Direction::DESC->opposite())->toBe(Direction::ASC);
});

it('limit filter rejects negative limit', function (): void {
    $this->expectException(\InvalidArgumentException::class);

    new LimitFilter(-1);
});

it('order filter factory returns null when no ordering requested', function (): void {
    $sortable = new class implements SortableInterface {
        public ?array $orderBy {
            get => null;
        }
    };

    expect(OrderFilter::fromSortable($sortable))->toBeNull();
});

it('pagination rejects zero limit', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    new PaginationFilter(0);
});

it('pagination rejects negative limit', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    new PaginationFilter(-1);
});

it('pagination rejects negative offset', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    new PaginationFilter(10, -1);
});
