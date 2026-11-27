# Cycle benchmarks

Run from the repository root after installing development dependencies:

```sh
composer bench
```

The suite uses PHPBench with nine iterations, explicit revolutions and warmup for repeated paths. Setup and behavioral preflight are outside the measured operations. The data fetcher subjects cover default/custom transformations, fresh fetcher instances, ordinary/deep-offset pages with and without total counts, and all three batched relation types with indexed SQLite tables. The RelationDatabaseBench suite also runs on MySQL, PostgreSQL and SQL Server, verifying the full relation result before timing integer, binary, noncanonical numeric-text, collated-text and bound-projection scenarios for pages of 10 and 1,000 rows and fresh fetcher instances. Database subjects use five iterations; the other subjects use nine. ORM subjects cover extraction with and without relations, and hydration/extraction with fresh and reused entity factories. A cold subject starts with a fresh object instance or factory; it does not imply a cold operating-system or database cache.

Compare two source revisions on the same machine, using the same current benchmark suite and installed dependencies:

```sh
baseline=$(mktemp -d)
git archive 5a6065ad5f1ce7f47b2778a831008403ad27449e src | tar -x -C "$baseline"
CYCLE_BENCH_SOURCE_ROOT="$baseline/src" composer bench
composer bench
rm -rf "$baseline"
```

The `CYCLE_BENCH_SOURCE_ROOT` override loads every Componenta Cycle class from the selected source tree; it must be compatible with the current benchmark's public contracts. Do not compare absolute times from different runners. The following historical measurements used the former standalone nine-sample harness and are not directly comparable with PHPBench output.

## Refactoring measurement (25 November 2026)

The same runner, PHP 8.5.13 and installed dependency set were used for the baseline and the refactored implementation. See the [CI run](https://github.com/componenta/cycle/actions/runs/45531026121).

| Case | Baseline median, ms | Refactored median, ms |
| --- | ---: | ---: |
| Default mapping, 20,000 rows | 34.4492 | 28.5719 |
| Custom mapping, 20,000 rows | 36.1370 | 36.5475 |
| Page without total, 100 rows | 0.1447 | 0.1463 |
| Page with total, 100 rows | 0.1390 | 0.1415 |
| Three relation types, 1,000 rows | 4.3112 | 4.2285 |

These are individual controlled-run measurements, not statistical proof of a universal speedup. Those measurements are historical.

## Performance review (25 November 2026)

The complete package source at `19db08122391796313660e0d3d871245b7597693` was compared with `2f31a412ff081fbc59efe0021472c98f6eb9f467` on the same PHP 8.5.13 runner using the same installed dependencies. See the [comparison run](https://github.com/componenta/cycle/actions/runs/45532189092/job/138929968553). Each median is over nine samples.

| Case | Before, ms | After, ms | Change in time |
| --- | ---: | ---: | ---: |
| Default mapping, 20,000 rows | 23.0982 | 11.3648 | -50.8% |
| Custom mapping, 20,000 rows | 30.2427 | 30.0263 | -0.7% |
| Page without total, 100 rows | 0.1173 | 0.0920 | -21.6% |
| Page with total, 100 rows | 0.1113 | 0.0867 | -22.1% |
| Three relation types, 1,000 rows | 3.5819 | 3.2405 | -9.5% |
| Entity extraction with relations, 20,000 calls | 29.2542 | 14.9872 | -48.8% |
| Entity extraction without relations, 20,000 calls | 29.9940 | 16.3781 | -45.4% |
| Entity hydration and extraction, 10,000 entities | 46.0754 | 37.2850 | -19.1% |

These are controlled micro/integration benchmark measurements, not network or production latency guarantees. MySQL and SQL Server additionally have regression tests proving that counted pages with known projection widths use two SELECT statements instead of three; unknown widths retain metadata discovery. Normal CI executes correctness tests only.

## Correctness and portability recheck (25 November 2026)

Full source trees `3db46076af22101df6fbf3fe0ef1445b39796929` and `fc61fd517b13a1e2ee31774b0a55855acb5fe308` were compared on the same PHP 8.5 runner with the same installed dependencies. The baseline already includes the earlier performance optimizations. See the [CI measurement](https://github.com/componenta/cycle/actions/runs/45637674765/job/139287408864).

| Case | Before, ms | After, ms | Change in time |
| --- | ---: | ---: | ---: |
| Default mapping, 20,000 rows | 18.1749 | 18.2121 | 0.2% |
| Custom mapping, 20,000 rows | 52.0278 | 51.3076 | -1.4% |
| Page without total, 100 rows | 0.1763 | 0.1778 | 0.9% |
| Page with total, 100 rows | 0.1631 | 0.1657 | 1.6% |
| Three relation types, 1,000 rows | 5.3662 | 5.3023 | -1.2% |
| Entity extraction with relations, 20,000 calls | 25.2329 | 25.6912 | 1.8% |
| Entity extraction without relations, 20,000 calls | 27.6029 | 28.8486 | 4.5% |
| Entity hydration and extraction, 10,000 entities | 60.8321 | 60.1911 | -1.1% |

Default extraction now also checks cached dynamic-property metadata. The extra cost is visible in isolated extraction (about 23–62 ns per call in this run); combined hydration and extraction and the fetcher workloads remain close to the optimized baseline. These measurements are workload-specific; sequential samples on a shared runner do not establish statistical significance. The normal test workflow remains free of benchmarking steps.

`RelationDatabaseScaleBench` complements the 1,200-row fixture with 65,536 indexed lookup rows, sparse sets of 10 and 1,000 distinct integer or collated text keys, and full correctness preflight on both reused and fresh fetchers. It isolates relation lookup time from root-query pagination. Its fixture uses bounded geometric inserts outside the measured subjects.
