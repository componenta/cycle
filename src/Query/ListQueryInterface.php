<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

/**
 * Standard list query: pagination + sorting + search.
 */
interface ListQueryInterface extends PaginableInterface, SortableInterface, SearchableInterface
{
}
