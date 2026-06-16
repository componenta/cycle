<?php

declare(strict_types=1);

namespace Componenta\Cycle\Typecast;

use Carbon\CarbonImmutable;
use Cycle\ORM\Parser\CastableInterface;
use Cycle\ORM\Parser\UncastableInterface;

/**
 * Typecast for Carbon datetime handling.
 */
final class CarbonTypecast implements CastableInterface, UncastableInterface
{
    /** @var list<string> */
    private array $rules = [];

    public function setRules(array $rules): array
    {
        foreach ($rules as $column => $rule) {
            if ($rule === 'carbon') {
                $this->rules[] = $column;
                unset($rules[$column]);
            }
        }

        return $rules;
    }

    public function cast(array $data): array
    {
        foreach ($this->rules as $column) {
            if (isset($data[$column]) && !$data[$column] instanceof CarbonImmutable) {
                $data[$column] = CarbonImmutable::parse($data[$column]);
            }
        }

        return $data;
    }

    public function uncast(array $data): array
    {
        foreach ($this->rules as $column) {
            if (isset($data[$column]) && $data[$column] instanceof CarbonImmutable) {
                $data[$column] = $data[$column]->toDateTimeString();
            }
        }

        return $data;
    }
}
