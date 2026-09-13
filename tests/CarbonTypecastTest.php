<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Carbon\CarbonImmutable;
use Componenta\Cycle\Typecast\CarbonTypecast;
use PHPUnit\Framework\TestCase;

final class CarbonTypecastTest extends TestCase
{
    public function testCastsAnIsoTimestampAndRestoresItsDatabaseValue(): void
    {
        $typecast = new CarbonTypecast();
        $typecast->setRules(['created_at' => 'carbon']);

        $record = $typecast->cast(['created_at' => '2026-09-13T12:30:00+00:00']);

        self::assertInstanceOf(CarbonImmutable::class, $record['created_at']);
        self::assertSame('2026-09-13T12:30:00+00:00', $record['created_at']->format(DATE_ATOM));
        self::assertSame(['created_at' => '2026-09-13 12:30:00'], $typecast->uncast($record));
    }
}
