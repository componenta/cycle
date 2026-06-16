<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

use Componenta\Cycle\FilterInterface;
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
        if ($this->columns === []) {
            throw new \InvalidArgumentException('At least one column required');
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

        // Case-insensitive regardless of the underlying DB collation:
        // MySQL with `_bin` / PostgreSQL / SQLite all compare LIKE
        // case-sensitively by default. Normalizing both sides via LOWER()
        // gives consistent user-facing behaviour across backends. The
        // trade-off is that column-side LOWER() prevents native index usage
        // - add a functional index `LOWER(column)` in hot-path queries.
        $lowerPattern = mb_strtolower($pattern);
        $columns = $this->columns;

        return $query->where(static function (SelectQuery $q) use ($columns, $lowerPattern) {
            foreach ($columns as $i => $column) {
                $expr = new \Cycle\Database\Injection\Expression("LOWER({$column})");
                if ($i === 0) $q->where($expr, 'like', $lowerPattern);
                else $q->orWhere($expr, 'like', $lowerPattern);
            }
        });
    }
}
