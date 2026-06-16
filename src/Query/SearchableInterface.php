<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

/**
 * Text search capability.
 */
interface SearchableInterface
{
    /**
     * @var non-empty-string|null
     */
    public string|null $search { get; }
}
