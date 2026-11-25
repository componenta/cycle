<?php

declare(strict_types=1);

namespace Componenta\Cycle\Benchmark;

use Closure;
use RuntimeException;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = getenv('CYCLE_BENCH_SOURCE_ROOT');
$fetcherSource = getenv('CYCLE_BENCH_FETCHER_SOURCE');
if ($root !== false && $root !== '') {
    if ($fetcherSource !== false && $fetcherSource !== '') {
        throw new RuntimeException('Choose either a full source root or the historical DataFetcher file.');
    }
    $root = realpath($root);
    if ($root === false || !is_dir($root) || !is_file($root . '/DataFetcher.php')) {
        throw new RuntimeException('CYCLE_BENCH_SOURCE_ROOT must point to the package src directory.');
    }
    // Load every package class from the chosen revision, even if Composer's
    // current classmap was optimized. Never mix the two source trees.
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Componenta\\Cycle\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Missing class in comparison source: ' . $class);
        }
        require $file;
    }, prepend: true);
} elseif ($fetcherSource !== false && $fetcherSource !== '') {
    require $fetcherSource;
}

function check(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Benchmark preflight returned an unexpected result.');
    }
}

/**
 * Nine samples of repeated operations after warmup. Report time per operation,
 * not fixture creation time. No timing threshold is enforced in CI.
 *
 * @return array{median_ms: float, min_ms: float, max_ms: float}
 */
function measure(Closure $operation, int $iterations): array
{
    for ($warmup = 0; $warmup < 2; ++$warmup) {
        $operation();
    }

    $samples = [];
    for ($sample = 0; $sample < 9; ++$sample) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $operation();
        }
        $samples[] = (hrtime(true) - $start) / 1e6 / $iterations;
    }

    sort($samples);
    return [
        'median_ms' => round($samples[4], 4),
        'min_ms' => round($samples[0], 4),
        'max_ms' => round($samples[8], 4),
    ];
}
