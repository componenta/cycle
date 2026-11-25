<?php

declare(strict_types=1);

namespace Componenta\Cycle\Typecast;

use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;

final class EnumTypecast implements CastableInterface, UncastableInterface
{
    /** @var array<string, class-string<\BackedEnum>> */
    private array $rules = [];

    /** @var array<string, true> */
    private array $integerBacked = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $column => $rule) {
            if (is_string($rule) && enum_exists($rule) && is_subclass_of($rule, \BackedEnum::class)) {
                $this->rules[$column] = $rule;
                if ((new \ReflectionEnum($rule))->getBackingType()->getName() === 'int') {
                    $this->integerBacked[$column] = true;
                } else {
                    unset($this->integerBacked[$column]);
                }
                unset($rules[$column]);
            }
        }

        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->rules as $column => $enumClass) {
            if (!isset($data[$column])) {
                continue;
            }

            $value = $data[$column];
            if ($value instanceof \BackedEnum) {
                $this->assertEnumType($value, $enumClass);
                continue;
            }

            // PDO drivers may return integer-backed database values as strings.
            if (is_string($value) && isset($this->integerBacked[$column])) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer === false) {
                    throw new \InvalidArgumentException('Invalid integer enum value.');
                }
                $value = $integer;
            }

            $data[$column] = $enumClass::from($value);
        }

        return $data;
    }

    public function uncast(array $data): array
    {
        foreach ($this->rules as $column => $enumClass) {
            if (isset($data[$column]) && $data[$column] instanceof \BackedEnum) {
                $this->assertEnumType($data[$column], $enumClass);
                $data[$column] = $data[$column]->value;
            }
        }

        return $data;
    }

    /** @param class-string<\BackedEnum> $enumClass */
    private function assertEnumType(\BackedEnum $value, string $enumClass): void
    {
        if (!$value instanceof $enumClass) {
            throw new \InvalidArgumentException(sprintf('Expected enum %s, got %s.', $enumClass, $value::class));
        }
    }
}
