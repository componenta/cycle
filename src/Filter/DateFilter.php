<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Internal\CalendarExpression;
use Componenta\Cycle\Internal\SqlIdentifier;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Query\SelectQuery;

/**
 * Smart date filter supporting ranges and specific dates.
 *
 * Accepts array from DateFilterCaster:
 * - ['from' => 'Y-m-d']              - from date (>=)
 * - ['to' => 'Y-m-d']                - through the end date (exclusive next midnight)
 * - ['from' => 'Y-m-d', 'to' => ...] - range
 * - ['dates' => ['Y-m-d', ...]]       - specific dates (IN)
 */
final readonly class DateFilter implements FilterInterface
{
    /**
     * @param array{from?: string, to?: string, dates?: list<string>} $date
     * @param non-empty-string $column
     */
    public function __construct(
        private array $date,
        private string $column = 'created_at',
    ) {
        SqlIdentifier::assertColumn($this->column);
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        if (isset($this->date['dates'])) {
            if (!is_array($this->date['dates']) || !array_is_list($this->date['dates'])) {
                throw new \InvalidArgumentException('Specific dates must be a list of dates.');
            }
            if ($this->date['dates'] === []) {
                return $query->where(new Fragment('1 = 0'));
            }
            foreach ($this->date['dates'] as $date) {
                self::parseDate($date);
            }
            return $query->where(
                CalendarExpression::forQuery($query, $this->column, 'DATE'),
                'in',
                new Parameter($this->date['dates']),
            );
        }

        $from = isset($this->date['from']) ? self::parseDate($this->date['from']) : null;
        $to = isset($this->date['to']) ? self::parseDate($this->date['to']) : null;
        // SQLite compares ISO date/datetime text lexically. A date-only
        // boundary includes midnight even when a stored value has no time
        // part. Preserve native datetime boundaries on typed SQL dialects.
        $format = $query->getDriver()?->getType() === 'SQLite' ? 'Y-m-d' : 'Y-m-d H:i:s';

        if ($from !== null) {
            $query = $query->where($this->column, '>=', $from->format($format));
        }

        if ($to !== null) {
            $query = $query->where($this->column, '<', $to->modify('+1 day')->format($format));
        }

        return $query;
    }

    private static function parseDate(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid calendar date; expected Y-m-d.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid calendar date; expected Y-m-d.');
        }

        return $date;
    }
}
