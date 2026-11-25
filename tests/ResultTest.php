<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Result;
use PHPUnit\Framework\Assert;

it('reports size and empty state', function (): void {
    $result = new Result(['a', 'b'], 10);

    expect($result->isEmpty())->toBeFalse();
    Assert::assertSame(2, $result->size());
    Assert::assertSame(10, $result->count);
});

it('empty result', function (): void {
    $result = new Result([], null);

    expect($result->isEmpty())->toBeTrue();
    Assert::assertSame(0, $result->size());
    expect($result->count)->toBeNull();
});
