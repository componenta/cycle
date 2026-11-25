<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Result;

it('reports size and empty state', function (): void {
    $result = new Result(['a', 'b'], 10);

    expect($result->isEmpty())->toBeFalse();
    expect($result->size())->toBe(2);
    expect($result->count)->toBe(10);
});

it('reports an empty result', function (): void {
    $result = new Result([], null);

    expect($result->isEmpty())->toBeTrue();
    expect($result->size())->toBe(0);
    expect($result->count)->toBeNull();
});
