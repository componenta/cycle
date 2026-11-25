<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Typecast\UuidTypecast;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidFactoryInterface;
use PHPUnit\Framework\Assert;

it('accepts binary text hex and stream representations', function (): void {
    $typecast = new UuidTypecast(new UuidFactory());
    $typecast->setRules(['id' => 'uuid']);
    $text = '018f0000-0000-7000-8000-000000000001';
    $bytes = hex2bin(str_replace('-', '', $text));
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);
    try {
        foreach ([$bytes, $text, '\\x' . bin2hex($bytes), $stream] as $value) {
            $uuid = $typecast->cast(['id' => $value])['id'];
            Assert::assertSame($text, $uuid->toString());
            Assert::assertSame(['id' => $bytes], $typecast->uncast(['id' => $uuid]));
        }
    } finally {
        fclose($stream);
    }
});

it('uses the injected factory for every database representation', function (): void {
    $text = '018f0000-0000-7000-8000-000000000001';
    $expected = Uuid::fromString($text);
    $factory = $this->createMock(UuidFactoryInterface::class);
    $factory->expects($this->exactly(4))->method('fromBytes')->with($expected->bytes)->willReturn($expected);

    $typecast = new UuidTypecast($factory);
    $typecast->setRules(['id' => 'uuid']);

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $text);
    rewind($stream);
    try {
        foreach ([$expected->bytes, $text, '\\x' . bin2hex($expected->bytes), $stream] as $value) {
            Assert::assertSame($expected, $typecast->cast(['id' => $value])['id']);
        }
    } finally {
        fclose($stream);
    }
});

it('rejects trailing payload in uuid stream', function (): void {
    $typecast = new UuidTypecast(new UuidFactory());
    $typecast->setRules(['id' => 'uuid']);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "018f0000-0000-7000-8000-000000000001\ntrailing");
    rewind($stream);
    try {
        $this->expectException(\InvalidArgumentException::class);
        $typecast->cast(['id' => $stream]);
    } finally {
        fclose($stream);
    }
});

it('rejects non string and non stream values', function (): void {
    $typecast = new UuidTypecast(new UuidFactory());
    $typecast->setRules(['id' => 'uuid']);

    $this->expectException(\InvalidArgumentException::class);
    $typecast->cast(['id' => []]);
});

it('preserves absent null and already casted values', function (): void {
    $typecast = new UuidTypecast(new UuidFactory());
    $typecast->setRules(['id' => 'uuid']);
    $uuid = Uuid::fromString('018f0000-0000-7000-8000-000000000001');

    Assert::assertSame([], $typecast->cast([]));
    Assert::assertSame(['id' => null], $typecast->cast(['id' => null]));
    Assert::assertSame(['id' => $uuid], $typecast->cast(['id' => $uuid]));
});
