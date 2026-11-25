<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

/** @internal Validates column names, not arbitrary SQL expressions. */
final class SqlIdentifier
{
    public static function assertColumn(string $column): void
    {
        if (preg_match('/\A[\p{L}_][\p{L}\p{N}_]*(?:\.[\p{L}_][\p{L}\p{N}_]*)?\z/u', $column) !== 1) {
            throw new \InvalidArgumentException('Expected a simple or table-qualified SQL column name.');
        }
    }
}
