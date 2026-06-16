# Componenta Cycle

Runtime integration between Componenta framework libraries and Cycle ORM/DBAL. The package provides repository base classes, data fetcher helpers, query filter contracts, typecasts, config provider factories, and database-oriented read models.

Discovery and console integration live in `componenta/cycle-app`.

## Installation

```bash
composer require componenta/cycle
```

Install `componenta/cycle-app` only when an application needs entity discovery, schema-related console commands, or framework cache integration.

## Related Packages

| Package | Why it matters here |
|---|---|
| `cycle/orm` and `cycle/database` | Provide the ORM/DBAL layer; this package adds Componenta integration around them. |
| `componenta/di` | Registers repositories, factories, and typecast services. |
| `componenta/cqrs` | Commonly uses repositories in command handlers and data fetchers in query handlers. |
| `componenta/paginator` | Represents paginated read-side results. |
| `componenta/cycle-app` | Discovers entities, builds ORM configuration, and wires console commands. |

## Repository Layer

`Repository` extends Cycle ORM repositories and gives application repositories a common framework base without forcing domain code to depend on the application runtime.

Use repositories for aggregate persistence and identity-based lookups. Keep business transitions in aggregates and handlers; repositories should not become policy or workflow services.

## Data Fetchers

`DataFetcher` is a read-side helper for list/detail projections built on Cycle DBAL. It provides:

- table name derivation with override support
- column selection through query interfaces
- pagination and optional total counts
- sort filters
- field mapping from `snake_case` to `camelCase`
- per-field casters
- mapper methods named as `map{FieldName}`
- batch relation helpers for belongs-to, has-many, and many-to-many reads

Fetcher methods transform database rows into arrays or projection objects. Relation loading is batched after rows are collected, so fetchers can avoid N+1 queries.

## Query Contracts

Read query objects can opt into behavior by implementing small contracts:

| Contract | Enables |
|---|---|
| `PaginableInterface` | Limit/offset pagination. |
| `RequiresTotalCountInterface` | Exact total count calculation. |
| `SortableInterface` | Ordered reads. |
| `SelectableInterface` | Explicit selected columns. |
| `SearchableInterface` | Full-text-like filtering through a fetcher-defined search filter. |
| `DateRangeInterface` | Date interval filtering. |
| `ListQueryInterface` | Common marker for list query objects. |

The fetcher applies only the behavior requested by the query object. Infinite-scroll reads can avoid total-count queries by omitting `RequiresTotalCountInterface`.

## Fetcher Extension Points

Application fetchers usually extend `DataFetcher` and customize it with protected properties and methods:

| API | Purpose |
|---|---|
| `$excluded` | Fields removed from the transformed result. |
| `$autoMapToCamelCase` | Enables automatic `snake_case` to `camelCase` result keys. |
| `$fieldMapping` | Explicit database-field to result-field map. |
| `$casters` | Field-to-caster map resolved through `CasterProviderInterface`. |
| `map{FieldName}()` | Per-field mapper method called after caster conversion. |
| `loadRelations()` | Optional relation-loading hook for batched belongs-to, has-many, and many-to-many reads. |

Standard filters include `PaginationFilter`, `OrderFilter`, `SearchFilter`, `DateRangeFilter`, `DateTimeFilter`, `ComparisonFilter`, `BetweenFilter`, `InFilter`, `NotInFilter`, `NullFilter`, `NotNullFilter`, `BooleanFilter`, and `SoftDeleteFilter`.

## Typecasts And Factories

The package includes framework typecasts and factory services used to configure Cycle ORM integration. These are runtime pieces and can be wired manually or through the Componenta DI config provider.

Built-in typecasts are `UuidTypecast`, `EnumTypecast`, and `CarbonTypecast`.

`ConfigProvider` registers Cycle DBAL/ORM factories for database configuration, database manager, ORM, schema, entity manager, migrations, and the default core factory. The main config keys live in `ConfigKey`.

## Performance Notes

`DataFetcher` caches mapper-method resolution per instance, caches requested relation sets in a `WeakMap`, and uses single-query window counts when it is safe. When joins may duplicate rows, it falls back to a separate distinct count to preserve paginator correctness.

## Boundaries

`componenta/cycle` contains runtime database behavior. Filesystem scanning, attribute discovery, schema console commands, and compile integration belong to `componenta/cycle-app`.
