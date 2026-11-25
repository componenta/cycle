<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Filter\Direction;
use Componenta\Cycle\Filter\LimitFilter;
use Componenta\Cycle\Filter\OrderFilter;
use Componenta\Cycle\Filter\PaginationFilter;
use Componenta\Cycle\Query\SortableInterface;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    public function testDirectionOpposite(): void
    {
        self::assertSame(Direction::DESC, Direction::ASC->opposite());
        self::assertSame(Direction::ASC, Direction::DESC->opposite());
    }

    public function testLimitFilterRejectsNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LimitFilter(-1);
    }

    public function testOrderFilterFactoryReturnsNullWhenNoOrderingRequested(): void
    {
        $sortable = new class implements SortableInterface {
            public ?array $orderBy {
                get => null;
            }
        };

        self::assertNull(OrderFilter::fromSortable($sortable));
    }

    public function testPaginationRejectsZeroLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginationFilter(0);
    }

    public function testPaginationRejectsNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginationFilter(-1);
    }

    public function testPaginationRejectsNegativeOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginationFilter(10, -1);
    }
}
