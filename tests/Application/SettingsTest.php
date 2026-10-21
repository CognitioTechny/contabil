<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use CognitioTechny\Contabil\Application\Configuration\Settings;
use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;

/**
 * @internal
 */
final class SettingsTest extends CIUnitTestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame('acc_', $settings->tablePrefix);
        $this->assertSame('contabil', $settings->apiRoute);
        $this->assertSame(Settings::BASIS_ACCRUAL, $settings->defaultBasis);
        $this->assertTrue($settings->routesEnabled);
        $this->assertTrue($settings->commandsEnabled);
    }

    public function testTableNamesCarryThePrefix(): void
    {
        $settings = new Settings(tablePrefix: 'ledger_');

        $this->assertSame('ledger_entries', $settings->tables->name('entries'));
        $this->assertSame('ledger_fk_entries_entity', $settings->tables->key('fk', 'entries', 'entity'));
        $this->assertContains('ledger_schema_version', $settings->tables->all());
    }

    public function testFromArrayIgnoresUnknownKeys(): void
    {
        $settings = Settings::fromArray(['defaultBasis' => 'CASH', 'unknown' => 1]);

        $this->assertSame('CASH', $settings->defaultBasis);
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidValuesAreRejected(array $values): void
    {
        $this->expectException(ConfigurationException::class);

        Settings::fromArray($values);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'prefix with a dash' => [['tablePrefix' => 'a-b_']];
        yield 'prefix starting with a digit' => [['tablePrefix' => '1acc_']];
        yield 'prefix too long' => [['tablePrefix' => str_repeat('a', 17)]];
        yield 'lowercase currency' => [['currency' => 'usd']];
        yield 'unknown basis' => [['defaultBasis' => 'HYBRID']];
        yield 'empty route' => [['apiRoute' => '/']];
        yield 'malformed code mask' => [['accountCodeMask' => '9.Z']];
    }

    public function testCodeMaskIsOptionalAndParsed(): void
    {
        $this->assertFalse((new Settings())->codeMask()->isEnforced());
        $this->assertSame(3, (new Settings(accountCodeMask: '9.99.999'))->codeMask()->maxLevel());
    }

    public function testUnknownLogicalTableIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new Settings())->tables->name('users');
    }
}
