# DataFetcher benchmark

Run from the repository root after `composer install`:

```sh
composer bench
```

The script uses an in-memory SQLite database and deterministic data, verifies observable results before measurement, and reports the median, minimum and maximum time per operation over nine samples. The cases cover plain and custom row transformations, pagination with and without totals, and all three batched relation types.

For a source-only comparison with the pre-decomposition implementation, keep the same checkout, installed dependencies, PHP configuration and machine:

```sh
git show 3f73aaf3af7fb492c7ef866bc958176de57e0abf:src/DataFetcher.php > /tmp/cycle-fetcher-before.php
CYCLE_BENCH_FETCHER_SOURCE=/tmp/cycle-fetcher-before.php php benchmarks/data-fetcher.php
php benchmarks/data-fetcher.php
```

Compare medians rather than individual iterations. These timings are workload-specific, not universal performance guarantees.

## Refactoring measurement (25 November 2026)

The same runner, PHP 8.5.13 and installed dependency set were used for the baseline and the refactored implementation. See the [CI run](https://github.com/componenta/cycle/actions/runs/45531026121).

| Case | Baseline median, ms | Refactored median, ms |
| --- | ---: | ---: |
| Default mapping, 20,000 rows | 34.4492 | 28.5719 |
| Custom mapping, 20,000 rows | 36.1370 | 36.5475 |
| Page without total, 100 rows | 0.1447 | 0.1463 |
| Page with total, 100 rows | 0.1390 | 0.1415 |
| Three relation types, 1,000 rows | 4.3112 | 4.2285 |

These are individual controlled-run measurements, not statistical proof of a universal speedup. Normal test CI does not run benchmarks or depend on the historical baseline; benchmarking is opt-in through `composer bench`.
