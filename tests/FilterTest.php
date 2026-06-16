<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Filter\Direction;
use Componenta\Cycle\Filter\LimitFilter;
use Componenta\Cycle\Filter\OrderFilter;
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
}
