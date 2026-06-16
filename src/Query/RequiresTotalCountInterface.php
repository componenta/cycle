<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

/**
 * Marker for paginated queries that require an exact total row count.
 *
 * Plain PaginableInterface queries fetch a slice plus one extra row to build
 * next-page metadata without forcing COUNT(*) over the full result set.
 */
interface RequiresTotalCountInterface
{
}
