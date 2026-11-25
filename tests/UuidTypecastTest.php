<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Typecast\UuidTypecast;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidFactoryInterface;

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
            expect($uuid->toString())->toBe($text);
            expect($typecast->uncast(['id' => $uuid]))->toBe(['id' => $bytes]);
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
            expect($typecast->cast(['id' => $value])['id'])->toBe($expected);
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

it('rejects values other than strings and streams', function (): void {
    $typecast = new UuidTypecast(new UuidFactory());
    $typecast->setRules(['id' => 'uuid']);

    $this->expectException(\InvalidArgumentException::class);
    $typecast->cast(['id' => []]);
});

it('preserves absent, null and already converted UUID values', function (): void {
    $typecast = new UuidTypecast(new UuidFactory());
    $typecast->setRules(['id' => 'uuid']);
    $uuid = Uuid::fromString('018f0000-0000-7000-8000-000000000001');

    expect($typecast->cast([]))->toBe([]);
    expect($typecast->cast(['id' => null]))->toBe(['id' => null]);
    expect($typecast->cast(['id' => $uuid]))->toBe(['id' => $uuid]);
});
