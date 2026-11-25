<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Typecast\EnumTypecast;
use PHPUnit\Framework\Assert;

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

function enumTypecastTypecast(): EnumTypecast
{
    $typecast = new EnumTypecast();
    $typecast->setRules(['status' => NumericStatus::class]);
    return $typecast;
}

it('casts integer values returned as strings by database drivers', function (): void {
    $typecast = enumTypecastTypecast();
    Assert::assertSame(['status' => NumericStatus::Enabled], $typecast->cast(['status' => '1']));
    Assert::assertSame(['status' => NumericStatus::Disabled], $typecast->cast(['status' => '0']));
    Assert::assertSame(['status' => NumericStatus::Negative], $typecast->cast(['status' => '-1']));
    Assert::assertSame(['status' => -1], $typecast->uncast(['status' => NumericStatus::Negative]));
    Assert::assertSame(['status' => null], $typecast->cast(['status' => null]));
});

it('string backed enum preserves its exact value after rule reconfiguration', function (): void {
    $typecast = enumTypecastTypecast();
    $typecast->setRules(['status' => StringStatus::class]);

    Assert::assertSame(['status' => StringStatus::Enabled], $typecast->cast(['status' => '1']));
    Assert::assertSame(['status' => '1'], $typecast->uncast(['status' => StringStatus::Enabled]));
});

it('rejects an enum of another type when casting', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    enumTypecastTypecast()->cast(['status' => OtherNumericStatus::Enabled]);
});

it('rejects an enum of another type when uncasting', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    enumTypecastTypecast()->uncast(['status' => OtherNumericStatus::Enabled]);
});

it('rejects integer overflow rather than coercing to a case', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    enumTypecastTypecast()->cast(['status' => (string) PHP_INT_MAX . '0']);
});
