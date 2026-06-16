<?php

declare(strict_types=1);

namespace Componenta\Cycle\Filter;

enum LikeMode: string
{
    case Contains = 'contains';
    case StartsWith = 'starts';
    case EndsWith = 'ends';
    case Exact = 'exact';
}
