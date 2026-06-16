<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

enum Direction: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';

    public function opposite(): self
    {
        return match ($this) {
            self::ASC => self::DESC,
            self::DESC => self::ASC,
        };
    }
}
