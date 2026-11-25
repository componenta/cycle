<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\Injection\Expression;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\SubQuery;
use Cycle\Database\Query\SelectQuery;

/** @internal Validates column names, not arbitrary SQL expressions. */
final class SqlIdentifier
{
    public static function assertColumn(string $column): void
    {
        if (preg_match('/\A[\p{L}_][\p{L}\p{N}_]*(?:\.[\p{L}_][\p{L}\p{N}_]*)?\z/u', $column) !== 1) {
            throw new \InvalidArgumentException('Expected a simple or table-qualified SQL column name.');
        }
    }

    /**
     * The DBAL expression tokenizer recognizes ASCII identifiers only. For a
     * Unicode column, quote the complete name as an identifier, not as SQL.
     * Templates are internal constants, never request data.
     */
    public static function expression(
        SelectQuery $query,
        string $column,
        string $template,
        mixed ...$parameters,
    ): FragmentInterface {
        $prefix = $query->getPrefix() ?? '';
        $qualified = str_contains($column, '.');
        $derivedAlias = false;

        if ($prefix !== '' && $qualified) {
            [$qualifier, $name] = explode('.', $column, 2);
            // The DBAL compiler does not register structured FROM subquery
            // aliases in the identifier quoter. Never apply a table prefix
            // to an explicitly declared derived-table alias.
            foreach ($query->getTables() as $table) {
                if ($table instanceof SubQuery && $table->getTokens()['alias'] === $qualifier) {
                    $derivedAlias = true;
                    break;
                }
            }
        }

        if (!$derivedAlias && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $column) === 1) {
            return new Expression(sprintf($template, $column), ...$parameters);
        }

        self::assertColumn($column);
        $driver = $query->getDriver()
            ?? throw new \InvalidArgumentException('Quoted SQL expressions require a query with an associated driver.');
        if ($prefix !== '' && $qualified) {
            if (!$derivedAlias && !self::isQueryAlias($query, $qualifier, $prefix)) {
                $qualifier = $prefix . $qualifier;
            }
            $column = $qualifier . '.' . $name;
        }

        $quoted = $driver->getQueryCompiler()->quoteIdentifier($column);

        return new Fragment(sprintf($template, $quoted), ...$parameters);
    }

    private static function isQueryAlias(SelectQuery $query, string $qualifier, string $prefix): bool
    {
        $tokens = $query->getTokens();
        foreach ($tokens['join'] as $join) {
            if (($join['alias'] ?? null) === $qualifier) {
                return true;
            }
        }

        $tables = $tokens['from'];
        foreach ($tokens['join'] as $join) {
            $tables[] = $join['outer'];
        }
        foreach ($tables as $table) {
            if (!is_string($table)) {
                continue;
            }
            if (preg_match('/\A(.+) AS (.+)\z/i', $table, $matches) === 1) {
                if ($matches[2] === $qualifier && !str_contains($matches[1], '.')) {
                    return true;
                }
            } elseif ($prefix . $table === $qualifier) {
                return true;
            }
        }

        return false;
    }
}
