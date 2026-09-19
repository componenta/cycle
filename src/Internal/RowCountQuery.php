<?php
declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\Driver\CompilerInterface;
use Cycle\Database\Exception\BuilderException;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\SelectQuery;
use UnexpectedValueException;

/** @internal Counts the source result without changing its projection or grouping. */
final readonly class RowCountQuery implements FragmentInterface
{
    public function __construct(
        private SelectQuery $source,
        private bool $firstSelectOnly = false,
        private ?int $after = null,
    ) {}

    public function hasRowsAfter(int $offset): bool
    {
        return (new self($this->source, after: $offset))->count() !== 0;
    }

    public function count(): int
    {
        $driver = $this->source->getDriver()
            ?? throw new BuilderException('Unable to build query without associated driver');
        $parameters = new QueryParameters();
        $sql = $driver->getQueryCompiler()->compile($parameters, $this->source->getPrefix() ?? '', $this);
        $bindings = $parameters->getParameters();
        $columns = '';
        $tokens = $this->getTokens();
        // EXISTS may discard projected fields. Grouping and set operations need their result cardinality.
        $directExistence = $this->after !== null
            && !$tokens['distinct'] && $tokens['groupBy'] === [] && $tokens['having'] === []
            && $tokens['union'] === [] && $tokens['intersect'] === [] && $tokens['except'] === [];

        if (!$directExistence && in_array($driver->getType(), ['MySQL', 'SQLServer'], true)) {
            // Describe every output column, including stars and duplicate names, without reading rows.
            $metadataParameters = new QueryParameters();
            $metadataSql = $driver->getQueryCompiler()->compile(
                $metadataParameters,
                $this->source->getPrefix() ?? '',
                new self($this->source, firstSelectOnly: true),
            );
            if ($driver->getType() === 'SQLServer') {
                $metadataSql = preg_replace('/\\ASELECT(\\s+DISTINCT)?\\s+/i', '$0TOP (0) ', $metadataSql, 1, $replacements);
                if ($metadataSql === null || $metadataSql === '' || $replacements !== 1) {
                    throw new BuilderException('Cannot prepare a zero-row SQL Server query.');
                }
            } else {
                $metadataSql .= "\nLIMIT 0";
            }
            $metadata = $driver->query($metadataSql, $metadataParameters->getParameters());
            try {
                $names = [];
                for ($index = 0; $index < $metadata->columnCount(); ++$index) {
                    $names[] = 'c' . $index;
                }
                $columns = '(' . implode(', ', $names) . ')';
            } finally {
                $metadata->close();
            }
        }

        $aggregate = $driver->getType() === 'SQLServer' ? 'COUNT_BIG(*)' : 'COUNT(*)';
        $statement = $driver->query(
            $directExistence
                ? "SELECT CASE WHEN EXISTS (\n" . $sql . "\n) THEN 1 ELSE 0 END"
                : "SELECT " . $aggregate . " FROM (\n" . $sql . "\n) AS _componenta_count" . $columns,
            $bindings,
        );
        try {
            $count = $statement->fetchColumn();
            if (is_int($count)) {
                return $count;
            }
            if (is_string($count) && ctype_digit($count)) {
                return (int) $count;
            }
            throw new UnexpectedValueException('The count query did not return an integer.');
        } finally {
            $statement->close();
        }
    }

    public function getType(): int
    {
        return CompilerInterface::SELECT_QUERY;
    }

    /** @return array<array-key, mixed> */
    public function getTokens(): array
    {
        $tokens = array_replace($this->source->getTokens(), [
            'limit' => $this->after === null ? null : 1,
            'offset' => $this->after,
            'forUpdate' => false,
        ]);
        if ($this->after === null) {
            $tokens['orderBy'] = [];
        }
        if ($this->firstSelectOnly) {
            // Only the first arm defines the output width; TOP (0) must not leave a UNION arm running.
            $tokens['union'] = $tokens['intersect'] = $tokens['except'] = [];
        }
        return $tokens;
    }
}
