<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Componenta\Caster\CasterProviderInterface;
use RuntimeException;

/**
 * Row transformation and caster resolution.
 *
 * Mapper calls are dispatched by DataFetcher so protected extension methods
 * retain their original visibility and late binding.
 *
 * @internal
 */
final readonly class RowMapper
{
    /** @var array<string, string> */
    private array $methods;

    /** @param list<string> $methods Methods visible in the fetcher's scope. */
    public function __construct(
        private ?CasterProviderInterface $casterProvider,
        array $methods,
        Closure $camelToSnake,
    ) {
        $resolved = [];
        foreach ($methods as $method) {
            if (str_starts_with($method, 'map') && $method !== 'mapField') {
                $resolved[$camelToSnake(lcfirst(substr($method, 3)))] = $method;
            }
        }
        $this->methods = $resolved;
    }

    public function transform(array $data, ?object $query, Closure $fieldName, Closure $fieldValue): array
    {
        $transformed = [];
        foreach ($data as $key => $value) {
            $mappedKey = $fieldName($key);
            $transformed[$mappedKey] = $fieldValue($key, $value, $data, $query);
        }

        return $transformed;
    }

    public function cast(string $field, string $casterName, mixed $value): mixed
    {
        if ($this->casterProvider === null) {
            throw new RuntimeException(sprintf(
                'Caster "%s" is registered for field "%s", but CasterProvider is not provided',
                $casterName,
                $field,
            ));
        }

        $caster = $this->casterProvider->provide($casterName);
        if ($caster === null) {
            throw new RuntimeException(sprintf(
                'Caster "%s" for field "%s" cannot be resolved from CasterProvider',
                $casterName,
                $field,
            ));
        }

        return $caster->cast($value);
    }

    public function mapperFor(string $field): ?string
    {
        return $this->methods[$field] ?? null;
    }
}
