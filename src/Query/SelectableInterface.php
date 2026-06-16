<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

/**
 * Column selection for queries.
 */
interface SelectableInterface
{
    /**
     * @var list<non-empty-string>|null
     */
    public ?array $columns { get; }
}
