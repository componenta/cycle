<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Carbon\CarbonImmutable;
use Componenta\Cycle\Typecast\CarbonTypecast;

it('casts an iso timestamp and restores its database value', function (): void {
    $typecast = new CarbonTypecast();
    $typecast->setRules(['created_at' => 'carbon']);

    $record = $typecast->cast(['created_at' => '2026-09-13T12:30:00+00:00']);

    expect($record['created_at'])->toBeInstanceOf(CarbonImmutable::class);
    expect($record['created_at']->format(DATE_ATOM))->toBe('2026-09-13T12:30:00+00:00');
    expect($typecast->uncast($record))->toBe(['created_at' => '2026-09-13 12:30:00']);
});
