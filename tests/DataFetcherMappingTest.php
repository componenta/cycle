<?php

declare(strict_types=1);

namespace Componenta\Cycle\Tests;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Cycle\DataFetcher;
use Cycle\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;

final class DataFetcherMappingTest extends TestCase
{
    public function testMappingPreservesCustomHooksAndCasterOrder(): void
    {
        $caster = $this->createStub(CasterInterface::class);
        $caster->method('cast')->willReturnCallback(static fn(mixed $value): int => (int) $value);
        $provider = $this->createStub(CasterProviderInterface::class);
        $provider->method('provide')->willReturnMap([['integer', $caster]]);

        $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class), $provider);
        $query = (object) ['increment' => 2];
        $row = ['total_value' => '5', 'hidden_value' => 'hidden', 'first_name' => 'Alice'];

        self::assertSame([
            'amount' => 7,
            'customName' => 'ALICE',
            'rawTotal' => '5',
        ], $fetcher($row, $query));
    }

    public function testMappingUsesCurrentConfigurationAndCloneState(): void
    {
        $caster = $this->createStub(CasterInterface::class);
        $caster->method('cast')->willReturnCallback(static fn(mixed $value): int => (int) $value);
        $provider = $this->createStub(CasterProviderInterface::class);
        $provider->method('provide')->willReturnMap([['integer', $caster]]);
        $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class), $provider);
        $clone = clone $fetcher;
        $clone->renameAmount('newAmount');

        $row = ['total_value' => '5'];
        $query = (object) ['increment' => 1];
        self::assertSame(['amount' => 6, 'rawTotal' => '5'], $fetcher($row, $query));
        self::assertSame(['newAmount' => 6, 'rawTotal' => '5'], $clone($row, $query));
    }

    public function testDefaultMappingUsesCurrentFieldAndCasterConfiguration(): void
    {
        $caster = $this->createStub(CasterInterface::class);
        $caster->method('cast')->willReturnCallback(static fn(mixed $value): int => (int) $value);
        $provider = $this->createStub(CasterProviderInterface::class);
        $provider->method('provide')->willReturnMap([['integer', $caster]]);
        $fetcher = new PlainMappingFetcher($this->createStub(DatabaseInterface::class), $provider);
        $row = ['total_value' => '5', 'first_name' => 'Alice', 'hidden_value' => 'hidden'];

        self::assertSame(['amount' => '5', 'firstName' => 'Alice'], $fetcher($row));
        $fetcher->enableAmountCaster();
        self::assertSame(['amount' => 5, 'firstName' => 'Alice'], $fetcher($row));
    }

    public function testOverriddenNamingHookIsNotBypassedByDefaultMapping(): void
    {
        $fetcher = new CustomSnakeMappingFetcher($this->createStub(DatabaseInterface::class));
        self::assertSame(['custom_first_name' => 'Alice'], $fetcher(['first_name' => 'Alice']));
    }

    public function testMissingCasterProviderFailsThroughPublicFetch(): void
    {
        $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caster "integer" is registered for field "total_value", but CasterProvider is not provided');

        $fetcher(['total_value' => '5'], (object) ['increment' => 1]);
    }

    public function testUnknownCasterFailsThroughPublicFetch(): void
    {
        $provider = $this->createStub(CasterProviderInterface::class);
        $provider->method('provide')->willReturn(null);
        $fetcher = new MappingContractFetcher($this->createStub(DatabaseInterface::class), $provider);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Caster "integer" for field "total_value" cannot be resolved from CasterProvider');

        $fetcher(['total_value' => '5'], (object) ['increment' => 1]);
    }
}

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
