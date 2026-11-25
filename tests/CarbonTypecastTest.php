<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Carbon\CarbonImmutable;
use Componenta\Cycle\Typecast\CarbonTypecast;
use PHPUnit\Framework\Assert;

it('casts an iso timestamp and restores its database value', function (): void {
    $typecast = new CarbonTypecast();
    $typecast->setRules(['created_at' => 'carbon']);

    $record = $typecast->cast(['created_at' => '2026-09-13T12:30:00+00:00']);

    Assert::assertInstanceOf(CarbonImmutable::class, $record['created_at']);
    Assert::assertSame('2026-09-13T12:30:00+00:00', $record['created_at']->format(DATE_ATOM));
    Assert::assertSame(['created_at' => '2026-09-13 12:30:00'], $typecast->uncast($record));
});
