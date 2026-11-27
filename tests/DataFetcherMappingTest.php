<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Cycle\DataFetcher;
use Cycle\Database\DatabaseInterface;

it('mapping preserves custom hooks and caster order', function (): void {
    $caster = $this->createStub(CasterInterface::class);
    $caster->method('cast')->willReturnCallback(static fn(mixed $value): int => (int) $value);
    $provider = $this->createStub(CasterProviderInterface::class);
    $provider->method('provide')->willReturnMap([['integer', $caster]]);

    $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class), $provider);
    $query = (object) ['increment' => 2];
    $row = ['total_value' => '5', 'hidden_value' => 'hidden', 'first_name' => 'Alice'];

    expect($fetcher($row, $query))->toBe([
        'amount' => 7,
        'customName' => 'ALICE',
        'rawTotal' => '5',
    ]);
});

it('mapping uses current configuration and clone state', function (): void {
    $caster = $this->createStub(CasterInterface::class);
    $caster->method('cast')->willReturnCallback(static fn(mixed $value): int => (int) $value);
    $provider = $this->createStub(CasterProviderInterface::class);
    $provider->method('provide')->willReturnMap([['integer', $caster]]);
    $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class), $provider);
    $clone = clone $fetcher;
    $clone->renameAmount('newAmount');

    $row = ['total_value' => '5'];
    $query = (object) ['increment' => 1];
    expect($fetcher($row, $query))->toBe(['amount' => 6, 'rawTotal' => '5']);
    expect($clone($row, $query))->toBe(['newAmount' => 6, 'rawTotal' => '5']);
});

it('default mapping uses current field and caster configuration', function (): void {
    $caster = $this->createStub(CasterInterface::class);
    $caster->method('cast')->willReturnCallback(static fn(mixed $value): int => (int) $value);
    $provider = $this->createStub(CasterProviderInterface::class);
    $provider->method('provide')->willReturnMap([['integer', $caster]]);
    $fetcher = new PlainMappingFetcher($this->createStub(DatabaseInterface::class), $provider);
    $row = ['total_value' => '5', 'first_name' => 'Alice', 'hidden_value' => 'hidden'];

    expect($fetcher($row))->toBe(['amount' => '5', 'firstName' => 'Alice']);
    $fetcher->enableAmountCaster();
    expect($fetcher($row))->toBe(['amount' => 5, 'firstName' => 'Alice']);
});

it('cached default names preserve mutable policy and varied projections', function (): void {
    $fetcher = new MutableDefaultMappingFetcher($this->createStub(DatabaseInterface::class));

    expect($fetcher(['first_name' => 'Alice']))->toBe(['firstName' => 'Alice']);
    $fetcher->rename('first_name', 'displayName');
    expect($fetcher(['first_name' => 'Alice']))->toBe(['displayName' => 'Alice']);
    $fetcher->resetMapping();
    $fetcher->autoMap(false);
    expect($fetcher(['first_name' => 'Alice']))->toBe(['first_name' => 'Alice']);
    $fetcher->autoMap(true);

    // More distinct names than the bounded cache can retain, including
    // names longer than its admission limit.
    $row = [];
    $expected = [];
    for ($i = 0; $i < 300; ++$i) {
        $row['field_' . $i . '_value'] = $i;
        $expected['field' . $i . 'Value'] = $i;
    }
    $longName = str_repeat('x', 200);
    $row[$longName . '_value'] = 'long';
    $expected[$longName . 'Value'] = 'long';
    expect($fetcher($row))->toBe($expected);
    expect($fetcher($row))->toBe($expected);

    // Multiple names can map to one output key: preserve row order and
    // the original last-write-wins semantics.
    expect($fetcher(['first_name' => 'first', 'firstName' => 'second']))->toBe(['firstName' => 'second']);
    expect($fetcher(['first_name' => 'Alice']))->toBe(['firstName' => 'Alice']);
});

it('honors overridden naming hooks during default mapping', function (): void {
    $fetcher = new CustomSnakeMappingFetcher($this->createStub(DatabaseInterface::class));
    expect($fetcher(['first_name' => 'Alice']))->toBe(['custom_first_name' => 'Alice']);
});

it('missing caster provider fails through public fetch', function (): void {
    $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class));
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Caster "integer" is registered for field "total_value", but CasterProvider is not provided');

    $fetcher(['total_value' => '5'], (object) ['increment' => 1]);
});

it('unknown caster fails through public fetch', function (): void {
    $provider = $this->createStub(CasterProviderInterface::class);
    $provider->method('provide')->willReturn(null);
    $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class), $provider);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Caster "integer" for field "total_value" cannot be resolved from CasterProvider');

    $fetcher(['total_value' => '5'], (object) ['increment' => 1]);
});

final class MappingContractFetcher extends DataFetcher
{
    protected array $fieldMapping = ['total_value' => 'amount'];
    protected array $casters = ['total_value' => 'integer'];
    protected array $excluded = ['hiddenValue'];

    public function __invoke(array $row, object $query): array
    {
        return $this->fetchResults([$row], null, $query)[0];
    }

    public function renameAmount(string $name): void
    {
        $this->fieldMapping['total_value'] = $name;
    }

    protected function getMappedFieldName(string $originalKey): string
    {
        return $originalKey === 'first_name' ? 'customName' : parent::getMappedFieldName($originalKey);
    }

    protected function mapField(string $originalKey, mixed $value, array $data, ?object $query): mixed
    {
        return $originalKey === 'first_name'
            ? strtoupper($value)
            : parent::mapField($originalKey, $value, $data, $query);
    }

    protected function mapTotalValue(int $value, array $data, ?object $query): int
    {
        if ($data['total_value'] !== '5') {
            throw new \LogicException('The mapper must receive the original row.');
        }

        return $value + $query->increment;
    }

    protected function transformData(array $data, ?object $query): array
    {
        return parent::transformData($data, $query) + ['rawTotal' => $data['total_value']];
    }
}


class PlainMappingFetcher extends DataFetcher
{
    protected array $fieldMapping = ['total_value' => 'amount'];
    protected array $excluded = ['hiddenValue'];

    public function __invoke(array $row): array
    {
        return $this->fetchResults([$row], null, null)[0];
    }

    public function enableAmountCaster(): void
    {
        $this->casters['total_value'] = 'integer';
    }
}

final class CustomSnakeMappingFetcher extends PlainMappingFetcher
{
    protected function snakeToCamel(string $string): string
    {
        return 'custom_' . $string;
    }
}


final class MutableDefaultMappingFetcher extends DataFetcher
{
    public function __invoke(array $row): array
    {
        return $this->fetchResults([$row], null, null)[0];
    }

    public function rename(string $from, string $to): void
    {
        $this->fieldMapping[$from] = $to;
    }

    public function resetMapping(): void
    {
        $this->fieldMapping = [];
    }

    public function autoMap(bool $enabled): void
    {
        $this->autoMapToCamelCase = $enabled;
    }
}

it('reuses only default conversions without caching virtual naming decisions', function (): void {
    $fetcher = new StatefulNamingFetcher($this->createStub(DatabaseInterface::class));
    expect($fetcher->beforeParent)->toBe('initialName');
    expect($fetcher(['first_name' => 'Alice', 'other_name' => 1]))->toBe([
        'firstName_1' => 'ALICE', 'otherName_2' => 1,
    ]);
    expect($fetcher(['first_name' => 'Bob']))->toBe(['firstName_3' => 'BOB']);
    $fetcher->rename('first_name', 'displayName');
    expect($fetcher(['first_name' => 'Eve']))->toBe(['displayName' => 'EVE']);
    $fetcher->rename('first_name', null);
    expect($fetcher(['first_name' => 'Sam']))->toBe(['firstName_4' => 'SAM']);
});

final class StatefulNamingFetcher extends DataFetcher
{
    public string $beforeParent;
    private int $calls = 0;

    public function __construct(DatabaseInterface $db)
    {
        $this->beforeParent = parent::snakeToCamel('initial_name');
        parent::__construct($db);
    }

    public function __invoke(array $row): array
    {
        return $this->fetchResults([$row], null, null)[0];
    }

    public function rename(string $from, ?string $to): void
    {
        if ($to === null) {
            unset($this->fieldMapping[$from]);
        } else {
            $this->fieldMapping[$from] = $to;
        }
    }

    protected function snakeToCamel(string $string): string
    {
        return parent::snakeToCamel($string) . '_' . ++$this->calls;
    }

    protected function mapFirstName(string $value): string
    {
        return strtoupper($value);
    }
}
