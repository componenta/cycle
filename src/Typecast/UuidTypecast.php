<?php

declare(strict_types=1);

namespace Componenta\Cycle\Typecast;

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactoryInterface;
use Componenta\Identity\UuidInterface;
use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;

/**
 * Typecast for UUID handling (binary storage).
 */
final class UuidTypecast implements CastableInterface, UncastableInterface
{
    /** @var list<string> */
    private array $rules = [];

    public function __construct(private readonly UuidFactoryInterface $factory)
    {
    }

    public function setRules(array $rules): array
    {
        foreach ($rules as $column => $rule) {
            if ($rule === 'uuid') {
                $this->rules[] = $column;
                unset($rules[$column]);
            }
        }

        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->rules as $column) {
            if (isset($data[$column]) && !$data[$column] instanceof UuidInterface) {
                $value = $data[$column];

                // Handle binary UUID (16 bytes)
                if (is_string($value) && strlen($value) === 16) {
                    $data[$column] = $this->factory->fromBytes($value);
                } else {
                    $data[$column] = Uuid::fromString((string) $value);
                }
            }
        }

        return $data;
    }

    public function uncast(array $data): array
    {
        foreach ($this->rules as $column) {
            if (isset($data[$column]) && $data[$column] instanceof UuidInterface) {
                // Store as binary for efficiency
                $uuid = $data[$column];
                $data[$column] = $uuid instanceof RfcUuidInterface
                    ? $uuid->bytes
                    : Uuid::fromString($uuid->toString())->bytes;
            }
        }

        return $data;
    }
}
