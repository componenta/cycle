<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Typecast\EnumTypecast;
use PHPUnit\Framework\TestCase;

enum NumericStatus: int
{
    case Disabled = 0;
    case Enabled = 1;
    case Negative = -1;
}

enum OtherNumericStatus: int
{
    case Enabled = 1;
}

enum StringStatus: string
{
    case Enabled = '1';
}

final class EnumTypecastTest extends TestCase
{
    private function typecast(): EnumTypecast
    {
        $typecast = new EnumTypecast();
        $typecast->setRules(['status' => NumericStatus::class]);
        return $typecast;
    }

    public function testCastsIntegerValuesReturnedAsStringsByDatabaseDrivers(): void
    {
        $typecast = $this->typecast();
        self::assertSame(['status' => NumericStatus::Enabled], $typecast->cast(['status' => '1']));
        self::assertSame(['status' => NumericStatus::Disabled], $typecast->cast(['status' => '0']));
        self::assertSame(['status' => NumericStatus::Negative], $typecast->cast(['status' => '-1']));
        self::assertSame(['status' => -1], $typecast->uncast(['status' => NumericStatus::Negative]));
        self::assertSame(['status' => null], $typecast->cast(['status' => null]));
    }

    public function testStringBackedEnumPreservesItsExactValueAfterRuleReconfiguration(): void
    {
        $typecast = $this->typecast();
        $typecast->setRules(['status' => StringStatus::class]);

        self::assertSame(['status' => StringStatus::Enabled], $typecast->cast(['status' => '1']));
        self::assertSame(['status' => '1'], $typecast->uncast(['status' => StringStatus::Enabled]));
    }

    public function testRejectsAnEnumOfAnotherTypeWhenCasting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->typecast()->cast(['status' => OtherNumericStatus::Enabled]);
    }

    public function testRejectsAnEnumOfAnotherTypeWhenUncasting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->typecast()->uncast(['status' => OtherNumericStatus::Enabled]);
    }

    public function testRejectsIntegerOverflowRatherThanCoercingToACase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->typecast()->cast(['status' => (string) PHP_INT_MAX . '0']);
    }
}
