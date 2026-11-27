<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\SelectQuery;

/**
 * Exact lookup-key optimization for explicitly verified, non-collated
 * equality types. The planner owns eligibility; this class never guesses
 * database equality from a PHP value.
 *
 * @internal
 */
final class ExactRelationLookup
{
    /** @param list<mixed> $ids @param array{int, int}|null $bounds */
    public static function integerKeys(array $ids, ?array $bounds): bool
    {
        if ($bounds === null) {
            return false;
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < $bounds[0] || $id > $bounds[1]) {
                return false;
            }
        }
        return true;
    }

    /** @param list<mixed> $ids */
    public static function binaryKeys(array $ids, ?string $fixedType): bool
    {
        $width = null;
        if ($fixedType !== null) {
            if (preg_match('/\Abinary\((\d+)\)\z/i', $fixedType, $match) !== 1) {
                return false;
            }
            $width = (int) $match[1];
        }
        foreach ($ids as $id) {
            if (!is_string($id) || ($width !== null && strlen($id) !== $width)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compile one indexed lookup and consume its first column as a lookup
     * key. Hex is injective for arbitrary binary data, including streams,
     * empty keys, NUL bytes and decimal-looking strings.
     *
     * @param array<int, Parameter> $bindings
     * @return array{string, array, array<int|string, int>}
     */
    public static function compile(
        SelectQuery $base,
        array $plan,
        array $bindings,
        DriverInterface $driver,
        string $prefix,
        string $kind,
    ): array {
        $compiler = $driver->getQueryCompiler();
        $column = $plan['alias'] . '.' . $plan['keyColumn'];
        $quoted = $compiler->quoteIdentifier($column);
        $binary = $kind === 'mysql-binary' || $kind === 'pg-bytea';
        $group = match ($kind) {
            'mysql-binary' => 'LOWER(HEX(' . $quoted . '))',
            'pg-bytea' => "pg_catalog.encode($quoted, 'hex')",
            default => $quoted,
        };
        $query = (clone $base)
            ->from($plan['table'])
            ->columns([new Fragment($group), ...$plan['columns']]);
        $ordinals = [];
        $values = [];
        foreach ($bindings as $ordinal => $parameter) {
            $id = $parameter->getValue();
            $key = $binary ? bin2hex($id) : $id;
            $ordinals[$key] = $ordinal;
            if (str_starts_with($kind, 'pg-')) {
                // The PostgreSQL array parser consumes one escaping layer
                // before bytea sees its hex input. Values remain bound.
                $values[] = $binary ? '"\\\\x' . $key . '"' : (string) $id;
            }
        }

        if (str_starts_with($kind, 'pg-')) {
            $type = match ($kind) {
                'pg-int2' => 'int2',
                'pg-int4' => 'int4',
                'pg-int8' => 'int8',
                'pg-bytea' => 'bytea',
            };
            $query->where(new Fragment(
                "$quoted = ANY(CAST(? AS pg_catalog.$type" . '[]))',
                new Parameter('{' . implode(',', $values) . '}'),
            ));
        } else {
            $query->where($column, 'IN', new Parameter(array_values($bindings)));
        }
        $parameters = new QueryParameters();
        $sql = $compiler->compile($parameters, $prefix, $query);

        return [$sql, $parameters->getParameters(), $ordinals];
    }
}
