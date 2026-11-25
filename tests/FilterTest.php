<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Filter\Direction;
use Componenta\Cycle\Filter\LimitFilter;
use Componenta\Cycle\Filter\OrderFilter;
use Componenta\Cycle\Filter\PaginationFilter;
use Componenta\Cycle\Query\SortableInterface;
use PHPUnit\Framework\Assert;

it('direction opposite', function (): void {
    Assert::assertSame(Direction::DESC, Direction::ASC->opposite());
    Assert::assertSame(Direction::ASC, Direction::DESC->opposite());
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
