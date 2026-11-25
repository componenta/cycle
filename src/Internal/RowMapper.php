<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Closure;
use Componenta\Caster\CasterProviderInterface;
use RuntimeException;
use TypeError;

/**
 * Maps rows with the default field policy and resolves registered casters.
 *
 * Dispatch of overridable protected hooks stays in DataFetcher.
 *
 * @internal
 */
final class RowMapper
{
    private const int MAX_CACHED_NAMES = 128;
    private const int MAX_CACHED_NAME_BYTES = 128;

    /** @var array<string, string> Pure default conversions, bounded for persistent workers. */
    private array $names = [];

    public function __construct(private readonly ?CasterProviderInterface $casterProvider) {}

    /**
     * @param list<string> $methods Methods visible in the fetcher's scope.
     * @return array<string, string>
     */
    public static function discoverMethods(array $methods, Closure $camelToSnake): array
    {
        $resolved = [];
        foreach ($methods as $method) {
            if (str_starts_with($method, 'map') && $method !== 'mapField') {
                $resolved[$camelToSnake(lcfirst(substr($method, 3)))] = $method;
            }
        }
        return $resolved;
    }

    /**
     * Map an ordinary row without allocating callbacks or calling extension
     * hooks. Only used when the default hooks are active and no casters or
     * custom mapper methods are configured.
     */
    public function transform(array $data, array $fieldMapping, bool $autoMap): array
    {
        $transformed = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new TypeError('Database field names must be strings.');
            }
            $mappedKey = $fieldMapping[$key] ?? ($autoMap ? ($this->names[$key] ?? $this->rememberName($key)) : $key);
            if (!is_string($mappedKey)) {
                throw new TypeError('Mapped database field names must be strings.');
            }
            $transformed[$mappedKey] = $value;
        }
        return $transformed;
    }

    private function rememberName(string $key): string
    {
        $name = self::snakeToCamel($key);
        if (count($this->names) < self::MAX_CACHED_NAMES && strlen($key) <= self::MAX_CACHED_NAME_BYTES) {
            $this->names[$key] = $name;
        }

        return $name;
    }

    public static function snakeToCamel(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
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
}
