<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Chart;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Chart\ChartFormat;
use CognitioTechny\Contabil\Application\Chart\ChartImportException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use Tests\Support\ChartKit;

/**
 * @internal
 */
final class ChartImportExportTest extends CIUnitTestCase
{
    private const CSV = <<<'CSV'
        code,name,type,parent_code,contra,accepts_entries,is_cash_account,reference_code
        1.1,Current assets,,1,,,,
        1,Assets,asset,,,,,
        1.1.1,Bank,,1.1,,,1,B-1
        1.9,Accumulated depreciation,,1,1,,,
        2,Liabilities,liability,,,,,
        CSV;

    public function testImportsRowsInAnyOrderParentsAfterChildren(): void
    {
        $kit    = new ChartKit();
        $result = $kit->import->execute(1, ChartFormat::parse(self::CSV, 'csv'));

        $this->assertSame(5, $result->created);
        $this->assertSame(0, $result->skipped);

        $bank = $kit->store->findByCode(1, '1.1.1');
        $this->assertTrue($bank->isCashAccount);
        $this->assertSame('B-1', $bank->referenceCode);
        $this->assertSame(3, $bank->level);
        $this->assertSame('CREDIT', $kit->store->findByCode(1, '1.9')->nature->value);
        $this->assertFalse($kit->store->findByCode(1, '1')->acceptsEntries);
    }

    public function testOneBadRowRollsBackEverythingAndReportsEveryError(): void
    {
        $kit  = new ChartKit();
        $rows = ChartFormat::parse(<<<'CSV'
            code,name,type,parent_code
            1,Assets,asset,
            1.1,Cash,,1
            2,,liability,
            3,Orphan,asset,99
            4,Typo,assets,
            1,Duplicate,asset,
            CSV, 'csv');

        try {
            $kit->import->execute(1, $rows);
            $this->fail('The import must be rejected.');
        } catch (ChartImportException $e) {
            $errors = array_column($e->errors(), null, 'row');

            $this->assertSame([3, 4, 5, 6], array_keys($errors));
            $this->assertStringContainsString('name', $errors[3]['message']);
            $this->assertStringContainsString('does not exist', $errors[4]['message']);
            $this->assertStringContainsString('Unknown account type', $errors[5]['message']);
            $this->assertStringContainsString('already in use', $errors[6]['message']);
            $this->assertSame('1', $errors[6]['code']);
        }

        $this->assertSame([], $kit->store->all(1), 'Nothing is kept when a row fails.');
    }

    public function testRowsUnderARejectedParentAreRejectedToo(): void
    {
        $kit = new ChartKit();

        try {
            $kit->import->execute(1, [
                ['code' => '1', 'name' => 'Assets', 'type' => 'nope'],
                ['code' => '1.1', 'name' => 'Cash', 'parent_code' => '1'],
            ]);
            $this->fail('The import must be rejected.');
        } catch (ChartImportException $e) {
            $this->assertCount(2, $e->errors());
            $this->assertStringContainsString('was rejected', $e->errors()[1]['message']);
        }
    }

    public function testParentCycleIsReportedNotLooped(): void
    {
        $kit = new ChartKit();

        try {
            $kit->import->execute(1, [
                ['code' => 'a', 'name' => 'A', 'type' => 'asset', 'parent_code' => 'b'],
                ['code' => 'b', 'name' => 'B', 'type' => 'asset', 'parent_code' => 'a'],
            ]);
            $this->fail('A cycle must be rejected.');
        } catch (ChartImportException $e) {
            $this->assertCount(2, $e->errors());
            $this->assertStringContainsString('cycle', $e->errors()[0]['message']);
        }
    }

    public function testRowsMayHangUnderExistingAccounts(): void
    {
        $kit  = new ChartKit();
        $root = $kit->create->execute(1, '1', 'Assets', 'asset');

        $kit->import->execute(1, [['code' => '1.1', 'name' => 'Cash', 'parent_code' => '1']]);

        $this->assertSame($root->id, $kit->store->findByCode(1, '1.1')->parentId);
    }

    public function testSkipExistingMakesTheImportRepeatable(): void
    {
        $kit  = new ChartKit();
        $rows = ChartFormat::parse(self::CSV, 'csv');

        $first  = $kit->import->execute(1, $rows, true);
        $second = $kit->import->execute(1, $rows, true);

        $this->assertSame(5, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(5, $second->skipped);
        $this->assertCount(5, $kit->store->all(1));
    }

    public function testMaskErrorsAreReportedPerRow(): void
    {
        $kit = new ChartKit('9.9');

        try {
            $kit->import->execute(1, [
                ['code' => '1', 'name' => 'Assets', 'type' => 'asset'],
                ['code' => 'x', 'name' => 'Bad', 'type' => 'asset'],
            ]);
            $this->fail('The import must be rejected.');
        } catch (ChartImportException $e) {
            $this->assertSame([2], array_column($e->errors(), 'row'));
            $this->assertStringContainsString('mask', $e->errors()[0]['message']);
        }
    }

    public function testBadBooleanIsARowError(): void
    {
        $kit = new ChartKit();

        $this->expectException(ChartImportException::class);
        $kit->import->execute(1, [['code' => '1', 'name' => 'A', 'type' => 'asset', 'contra' => 'maybe']]);
    }

    public function testExportRoundTripsThroughCsvAndJson(): void
    {
        $source = new ChartKit();
        $source->import->execute(1, ChartFormat::parse(self::CSV, 'csv'));

        foreach (['csv', 'json'] as $format) {
            $exported = $source->export->execute(1, $format);
            $copy     = new ChartKit();
            $copy->import->execute(1, ChartFormat::parse($exported, $format));

            $this->assertSame(
                $this->comparable($source->store->all(1)),
                $this->comparable($copy->store->all(1)),
                $format . ' export must reproduce the chart.',
            );
        }
    }

    public function testExportListsParentsBeforeChildren(): void
    {
        $kit = new ChartKit();
        $kit->import->execute(1, ChartFormat::parse(self::CSV, 'csv'));

        $this->assertSame(['1', '1.1', '1.1.1', '1.9', '2'], array_column($kit->export->rows(1), 'code'));
    }

    public function testJsonAcceptsAListOrAnAccountsObject(): void
    {
        $row = ['code' => '1', 'name' => 'A', 'type' => 'asset'];

        $this->assertSame([$row], ChartFormat::parse(json_encode([$row]), 'json'));
        $this->assertSame([$row], ChartFormat::parse(json_encode(['accounts' => [$row]]), 'json'));
    }

    public function testMalformedInputIsAValidationError(): void
    {
        foreach ([['{', 'json'], ['"text"', 'json'], ['[1]', 'json'], ['', 'csv'], ['a,b' . "\n" . '1,2', 'csv'], ['x', 'xml']] as [$content, $format]) {
            try {
                ChartFormat::parse($content, $format);
                $this->fail('Malformed ' . $format . ' accepted: ' . $content);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCsvToleratesBomBlankLinesAndQuotes(): void
    {
        $rows = ChartFormat::parse("\xEF\xBB\xBFCode,Name\n\n\"1\",\"Assets, total\"\n", 'csv');

        $this->assertSame([['code' => '1', 'name' => 'Assets, total']], $rows);
    }

    /**
     * The chart as comparable rows: ids differ between stores, so the parent is
     * named by its code.
     *
     * @param list<\CognitioTechny\Contabil\Domain\Chart\Account> $accounts
     *
     * @return list<array<string, mixed>>
     */
    private function comparable(array $accounts): array
    {
        $codes = [];

        foreach ($accounts as $account) {
            $codes[$account->id] = $account->code;
        }

        return array_map(static function ($account) use ($codes): array {
            $row = $account->toArray();
            unset($row['id']);
            $row['parent_id'] = $account->parentId === null ? null : $codes[$account->parentId];

            return $row;
        }, $accounts);
    }
}
