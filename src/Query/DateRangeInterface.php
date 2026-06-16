<?php

declare(strict_types=1);

namespace Componenta\Cycle\Query;

/**
 * Date range filtering.
 */
interface DateRangeInterface
{
    public \DateTimeInterface|null $dateFrom { get; }

    public \DateTimeInterface|null $dateTo { get; }
}
