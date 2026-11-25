<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Cycle\Typecast\UuidTypecast;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactory;
use Componenta\Identity\UuidFactoryInterface;
use PHPUnit\Framework\TestCase;

final class UuidTypecastTest extends TestCase
{
    public function testAcceptsBinaryTextHexAndStreamRepresentations(): void
    {
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
                self::assertSame($text, $uuid->toString());
                self::assertSame(['id' => $bytes], $typecast->uncast(['id' => $uuid]));
            }
        } finally {
            fclose($stream);
        }
    }

    public function testUsesTheInjectedFactoryForEveryDatabaseRepresentation(): void
    {
        $text = '018f0000-0000-7000-8000-000000000001';
        $expected = Uuid::fromString($text);
        $factory = $this->createMock(UuidFactoryInterface::class);
        $factory->expects(self::exactly(4))->method('fromBytes')->with($expected->bytes)->willReturn($expected);

        $typecast = new UuidTypecast($factory);
        $typecast->setRules(['id' => 'uuid']);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        try {
            foreach ([$expected->bytes, $text, '\\x' . bin2hex($expected->bytes), $stream] as $value) {
                self::assertSame($expected, $typecast->cast(['id' => $value])['id']);
            }
        } finally {
            fclose($stream);
        }
    }

    public function testRejectsTrailingPayloadInUuidStream(): void
    {
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
    }

    public function testRejectsNonStringAndNonStreamValues(): void
    {
        $typecast = new UuidTypecast(new UuidFactory());
        $typecast->setRules(['id' => 'uuid']);

        $this->expectException(\InvalidArgumentException::class);
        $typecast->cast(['id' => []]);
    }

    public function testPreservesAbsentNullAndAlreadyCastedValues(): void
    {
        $typecast = new UuidTypecast(new UuidFactory());
        $typecast->setRules(['id' => 'uuid']);
        $uuid = Uuid::fromString('018f0000-0000-7000-8000-000000000001');

        self::assertSame([], $typecast->cast([]));
        self::assertSame(['id' => null], $typecast->cast(['id' => null]));
        self::assertSame(['id' => $uuid], $typecast->cast(['id' => $uuid]));
    }
}
