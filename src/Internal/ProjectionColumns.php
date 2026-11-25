<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Componenta\Cycle\Filter\Direction;
use InvalidArgumentException;

/**
 * Resolves caller-supplied projection and ordering fields to trusted columns.
 *
 * @internal
 */
final class ProjectionColumns
{
    /**
     * @param list<string>|null $requested
     * @param array<string, string>|null $allowed
     * @return list<string>
     */
    public static function select(?array $requested, ?array $allowed): array
    {
        if ($requested === null && $allowed === null) {
            return ['*'];
        }
        if ($requested === null) {
            if ($allowed === []) {
                throw new InvalidArgumentException('At least one selectable column is required.');
            }
            $requested = array_keys($allowed);
        }
        if ($requested === [] || !array_is_list($requested)) {
            throw new InvalidArgumentException('Selected columns must be a non-empty list.');
        }

        $columns = [];
        foreach ($requested as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Selected columns must be strings.');
            }
            $columns[] = self::resolve($column, $allowed);
        }
        return $columns;
    }

    /**
     * @param array<string, Direction> $requested
     * @param array<string, string>|null $allowed
     * @return array<string, Direction>
     */
    public static function order(array $requested, ?array $allowed): array
    {
        $order = [];
        foreach ($requested as $column => $direction) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Ordering requires named columns.');
            }
            $order[self::resolve($column, $allowed)] = $direction;
        }
        return $order;
    }

    /** @param array<string, string>|null $allowed */
    private static function resolve(string $column, ?array $allowed): string
    {
        if ($allowed !== null) {
            if (!isset($allowed[$column]) || !is_string($allowed[$column])) {
                throw new InvalidArgumentException('Column is not available for this query.');
            }
            $column = $allowed[$column];
        }

        SqlIdentifier::assertColumn($column);

        return $column;
    }
}
