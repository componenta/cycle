<?php

declare(strict_types=1);

namespace Componenta\Cycle\Typecast;

use Componenta\Identity\RfcUuidInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidFactoryInterface;
use Componenta\Identity\UuidInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;

/**
 * Typecast for UUID handling (binary storage).
 */
final class UuidTypecast implements CastableInterface, UncastableInterface
{
    /** @var list<string> */
    private array $rules = [];

    public function __construct(
        private readonly UuidFactoryInterface $factory,
        private readonly bool $binaryLob = false,
    ) {}

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

                // PDO PostgreSQL may return bytea as a stream.
                if (is_resource($value) && get_resource_type($value) === 'stream') {
                    $value = stream_get_contents($value, 37);
                }
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('A database UUID must be a string or readable stream.');
                }

                if (strlen($value) === 16) {
                    $data[$column] = $this->factory->fromBytes($value);
                } elseif (strlen($value) === 34 && str_starts_with($value, '\\x')) {
                    $hex = substr($value, 2);
                    if (!ctype_xdigit($hex)) {
                        throw new \InvalidArgumentException('Invalid hexadecimal database UUID.');
                    }
                    $data[$column] = $this->factory->fromBytes(hex2bin($hex));
                } else {
                    $data[$column] = Uuid::fromString($value);
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
                $bytes = $uuid instanceof RfcUuidInterface
                    ? $uuid->bytes
                    : Uuid::fromString($uuid->toString())->bytes;
                $data[$column] = $this->binaryLob
                    ? new Parameter($bytes, \PDO::PARAM_LOB)
                    : $bytes;
            }
        }

        return $data;
    }
}
