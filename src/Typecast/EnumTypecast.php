<?php

declare(strict_types=1);

namespace Componenta\Cycle\Typecast;

use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;

final class EnumTypecast implements CastableInterface, UncastableInterface
{
    /** @var array<string, class-string<\BackedEnum>> */
    private array $rules = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $column => $rule) {
            if (is_string($rule) && enum_exists($rule) && is_subclass_of($rule, \BackedEnum::class)) {
                $this->rules[$column] = $rule;
                unset($rules[$column]);
            }
        }

        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->rules as $column => $enumClass) {
            if (isset($data[$column]) && !$data[$column] instanceof \BackedEnum) {
                $data[$column] = $enumClass::from($data[$column]);
            }
        }

        return $data;
    }

    public function uncast(array $data): array
    {
        foreach ($this->rules as $column => $enumClass) {
            if (isset($data[$column]) && $data[$column] instanceof \BackedEnum) {
                $data[$column] = $data[$column]->value;
            }
        }

        return $data;
    }
}