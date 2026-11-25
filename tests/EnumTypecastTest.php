<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Typecast\EnumTypecast;

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
    expect($typecast->cast(['status' => '1']))->toBe(['status' => NumericStatus::Enabled]);
    expect($typecast->cast(['status' => '0']))->toBe(['status' => NumericStatus::Disabled]);
    expect($typecast->cast(['status' => '-1']))->toBe(['status' => NumericStatus::Negative]);
    expect($typecast->uncast(['status' => NumericStatus::Negative]))->toBe(['status' => -1]);
    expect($typecast->cast(['status' => null]))->toBe(['status' => null]);
});

it('string backed enum preserves its exact value after rule reconfiguration', function (): void {
    $typecast = enumTypecastTypecast();
    $typecast->setRules(['status' => StringStatus::class]);

    expect($typecast->cast(['status' => '1']))->toBe(['status' => StringStatus::Enabled]);
    expect($typecast->uncast(['status' => StringStatus::Enabled]))->toBe(['status' => '1']);
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
