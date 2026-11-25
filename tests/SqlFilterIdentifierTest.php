<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Filter\{
    BetweenFilter, BooleanFilter, ComparisonFilter, DateFilter, DateRangeFilter,
    DateTimeBetweenFilter, DateTimeFilter, Direction, InFilter, LikeFilter,
    NotInFilter, NotNullFilter, NullFilter, OrderFilter, SearchFilter, SoftDeleteFilter
};

it('filters reject expressions as column names', function (string $filter, array $arguments): void {
    $this->expectException(\InvalidArgumentException::class);
    new $filter(...$arguments);
})->with(static function (): iterable {
    $column = 'LOWER(secret)';
    $date = new \DateTimeImmutable('2026-11-25');
    yield [BetweenFilter::class, [$column, 1, 2]];
    yield [BooleanFilter::class, [$column, true]];
    yield [ComparisonFilter::class, [$column, 1]];
    yield [DateFilter::class, [['dates' => ['2026-11-25']], $column]];
    yield [DateRangeFilter::class, [$column, $date]];
    yield [DateTimeBetweenFilter::class, [$column, $date, $date]];
    yield [DateTimeFilter::class, [$column, $date]];
    yield [InFilter::class, [$column, [1]]];
    yield [LikeFilter::class, [$column, 'example']];
    yield [NotInFilter::class, [$column, [1]]];
    yield [NotNullFilter::class, [$column]];
    yield [NullFilter::class, [$column]];
    yield [OrderFilter::class, [[$column => Direction::ASC]]];
    yield [SearchFilter::class, [[$column], 'example']];
    yield [SoftDeleteFilter::class, [$column]];
});
