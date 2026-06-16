<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testReportsSizeAndEmptyState(): void
    {
        $result = new Result(['a', 'b'], 10);

        self::assertFalse($result->isEmpty());
        self::assertSame(2, $result->size());
        self::assertSame(10, $result->count);
    }

    public function testEmptyResult(): void
    {
        $result = new Result([], null);

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->size());
        self::assertNull($result->count);
    }
}
