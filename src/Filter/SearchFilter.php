<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
use Componenta\Cycle\Internal\SqlIdentifier;
use Cycle\Database\Injection\Fragment;
use Cycle\Database\Query\SelectQuery;

/**
 * Search across multiple columns (OR condition).
 */
final readonly class SearchFilter implements FilterInterface
{
    /**
     * @param list<non-empty-string> $columns
     */
    public function __construct(
        private array $columns,
        private string $value,
        private LikeMode $mode = LikeMode::Contains,
    ) {
        if ($this->columns === [] || !array_is_list($this->columns)) {
            throw new \InvalidArgumentException('At least one column required');
        }

        foreach ($this->columns as $column) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException('Search columns must be strings.');
            }
            SqlIdentifier::assertColumn($column);
        }

        if ($this->value === '') {
            throw new \InvalidArgumentException('Value cannot be empty string');
        }
    }

    public function apply(SelectQuery $query): SelectQuery
    {
        $pattern = match ($this->mode) {
            LikeMode::Contains => "%$this->value%",
            LikeMode::StartsWith => "$this->value%",
            LikeMode::EndsWith => "%$this->value",
            LikeMode::Exact => $this->value,
        };

        // Apply the database's own case folding to both operands. PHP and
        // SQL may implement different Unicode case mappings; SQLite's
        // built-in LOWER, for example, is ASCII-only.
        $columns = $this->columns;

        return $query->where(static function (SelectQuery $q) use ($columns, $pattern): void {
            foreach ($columns as $i => $column) {
                $expr = SqlIdentifier::expression($q, $column, 'LOWER(%s)');
                $value = new Fragment('LOWER(?)', $pattern);

                if ($i === 0) {
                    $q->where($expr, 'like', $value);
                } else {
                    $q->orWhere($expr, 'like', $value);
                }
            }
        });
    }
}
