<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\Injection\Expression;
use Cycle\Database\Query\SelectQuery;
use DateTimeInterface;
use InvalidArgumentException;

/** @internal Translates supported calendar operations into DBAL expressions. */
final class CalendarExpression
{
    private const array FUNCTIONS = ['DATE', 'YEAR', 'MONTH', 'DAY'];

    public static function assertFunction(string $function): void
    {
        if (!in_array(strtoupper($function), self::FUNCTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('SQL function must be one of: %s', implode(', ', self::FUNCTIONS)),
            );
        }
    }

    public static function forQuery(SelectQuery $query, string $column, string $function): Expression
    {
        $function = strtoupper($function);
        self::assertFunction($function);
        $driver = $query->getDriver()?->getType();

        if ($function === 'DATE') {
            // SQL Server has no DATE() function. DATEFROMPARTS also handles
            // nullable values and leaves the column identifier to the DBAL.
            return new Expression($driver === 'SQLServer'
                ? "DATEFROMPARTS(YEAR($column),MONTH($column),DAY($column))"
                : "DATE($column)");
        }

        if ($driver === 'Postgres') {
            return new Expression("DATE_PART(?, $column)", strtolower($function));
        }

        if ($driver === 'SQLite') {
            $format = match ($function) {
                'YEAR' => '%Y',
                'MONTH' => '%m',
                'DAY' => '%d',
            };

            // STRFTIME returns TEXT; arithmetic produces a number so the
            // numeric comparison does not depend on text padding or affinity.
            return new Expression("STRFTIME(?, $column) + 0", $format);
        }

        return new Expression("$function($column)");
    }

    public static function value(DateTimeInterface $date, string $format, string $function): string|int
    {
        $value = $date->format($format);
        if (strtoupper($function) === 'DATE') {
            return $value;
        }

        if (preg_match('/\A[0-9]{1,4}\z/', $value) !== 1) {
            throw new InvalidArgumentException('YEAR, MONTH and DAY comparisons require a numeric date format.');
        }

        return (int) $value;
    }
}
