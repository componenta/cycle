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
