<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\FeatureTestTrait;
use CognitioTechny\Contabil\Application\Defaults\StaticCurrentEntity;
use CognitioTechny\Contabil\Application\Ports\PdfRendererInterface;
use Config\Services;
use Tests\Support\DbLedgerTestCase;
use Tests\Support\FakeAuthorizer;

/**
 * Entries, periods, years and reports through the router, with a fake
 * authorizer and the package defaults for every other port.
 *
 * @internal
 */
final class LedgerApiTest extends DbLedgerTestCase
{
    use FeatureTestTrait;

    private const ALL = [
        'contabil.chart.view', 'contabil.entry.view', 'contabil.entry.create', 'contabil.entry.post', 'contabil.entry.reverse',
        'contabil.period.manage', 'contabil.reports.view', 'contabil.reports.export',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAs(self::ALL);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->resetServices();
    }

    /**
     * @param list<string> $permissions
     */
    private function actAs(array $permissions): FakeAuthorizer
    {
        $authorizer = new FakeAuthorizer($permissions);

        Services::injectMock('contabilAuthorizer', $authorizer);
        Services::injectMock('contabilCurrentEntity', new StaticCurrentEntity($this->entity));

        return $authorizer;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{int, array<string, mixed>}
     */
    private function send(string $method, string $path, array $body = []): array
    {
        $request = $this->withBodyFormat('json');
        $result  = $body === [] ? $request->call($method, 'contabil/' . $path) : $request->withBody(json_encode($body))->call($method, 'contabil/' . $path);

        $decoded = json_decode($result->getJSON() ?: '[]', true);

        return [$result->response()->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryBody(string $date = '2026-03-02', int $amount = 1000, array $extra = []): array
    {
        return $extra + [
            'accrual_date' => $date,
            'description'  => 'Sale',
            'lines'        => [
                ['account_id' => $this->ids['1.1.02'], 'side' => 'D', 'amount' => $amount],
                ['account_id' => $this->ids['4.1.01'], 'side' => 'C', 'amount' => $amount],
            ],
        ];
    }

    public function testDraftThenPostThenRead(): void
    {
        [$status, $created] = $this->send('post', 'entries', $this->entryBody());
        $this->assertSame(201, $status);
        $this->assertSame('DRAFT', $created['data']['status']);

        $id = $created['data']['id'];

        [$status, $posted] = $this->send('post', 'entries/' . $id . '/post');
        $this->assertSame(200, $status);
        $this->assertSame(1, $posted['data']['number']);
        $this->assertSame('POSTED', $posted['data']['status']);

        [$status, $shown] = $this->send('get', 'entries/' . $id);
        $this->assertSame(200, $status);
        $this->assertCount(2, $shown['data']['lines']);
        $this->assertSame([], $shown['data']['settlements']);
    }

    public function testCreateAndPostInOneCallRequiresThePostPermission(): void
    {
        $this->actAs(['contabil.entry.create']);

        [$status, $body] = $this->send('post', 'entries', $this->entryBody('2026-03-02', 1000, ['post' => true]));
        $this->assertSame(403, $status);
        $this->assertSame('AccessDeniedException', $body['error']['type']);

        $this->assertSame(201, $this->send('post', 'entries', $this->entryBody())[0], 'a draft needs only entry.create');
    }

    public function testRepeatedIdempotencyKeyAnswers200WithTheExistingEntry(): void
    {
        $body = $this->entryBody('2026-03-02', 1000, ['post' => true, 'idempotency_key' => 'erp:sale:1:1']);

        [$first, $one]  = $this->send('post', 'entries', $body);
        [$second, $two] = $this->send('post', 'entries', $body);

        $this->assertSame([201, 200], [$first, $second]);
        $this->assertSame($one['data']['id'], $two['data']['id']);

        [$status, $conflict] = $this->send('post', 'entries', $this->entryBody('2026-03-02', 999, ['post' => true, 'idempotency_key' => 'erp:sale:1:1']));
        $this->assertSame(409, $status);
        $this->assertSame('ConflictException', $conflict['error']['type']);
    }

    public function testBusinessRuleFailuresMapToStatuses(): void
    {
        $unbalanced                       = $this->entryBody();
        $unbalanced['lines'][1]['amount'] = 900;

        $this->assertSame(422, $this->send('post', 'entries', $unbalanced + ['post' => true])[0]);
        $this->assertSame(422, $this->send('post', 'entries', ['accrual_date' => '2026-03-02', 'description' => 'x', 'lines' => 'nope'])[0]);
        $this->assertSame(422, $this->send('post', 'entries', $this->entryBody('2026-02-30'))[0]);
        $this->assertSame(404, $this->send('get', 'entries/999')[0]);

        $period = $this->uc()->listPeriods()->execute($this->entity, 1)[2];
        $this->assertSame(200, $this->send('post', 'periods/' . $period->id . '/close')[0]);
        $this->assertSame(409, $this->send('post', 'entries', $this->entryBody('2026-03-10', 1000, ['post' => true]))[0]);
    }

    public function testFloatAmountsAreRefused(): void
    {
        $body                       = $this->entryBody();
        $body['lines'][0]['amount'] = 10.5;

        [$status, $error] = $this->send('post', 'entries', $body);

        $this->assertSame(422, $status);
        $this->assertStringContainsString('minor units', $error['error']['message']);
    }

    public function testUpdateAndDeleteDraftsOnly(): void
    {
        $id = $this->send('post', 'entries', $this->entryBody())[1]['data']['id'];

        [$status, $updated] = $this->send('put', 'entries/' . $id, $this->entryBody('2026-03-03', 2500));
        $this->assertSame(200, $status);
        $this->assertSame(2500, $updated['data']['total']);

        $this->assertSame(204, $this->send('delete', 'entries/' . $id)[0]);
        $this->assertSame(404, $this->send('get', 'entries/' . $id)[0]);

        $posted = $this->send('post', 'entries', $this->entryBody('2026-03-02', 1000, ['post' => true]))[1]['data']['id'];
        $this->assertSame(409, $this->send('put', 'entries/' . $posted, $this->entryBody())[0]);
        $this->assertSame(409, $this->send('delete', 'entries/' . $posted)[0]);
    }

    public function testReverseOnceWithAReason(): void
    {
        $id = $this->send('post', 'entries', $this->entryBody('2026-03-02', 1000, ['post' => true]))[1]['data']['id'];

        $this->assertSame(422, $this->send('post', 'entries/' . $id . '/reverse', [])[0], 'a reason is mandatory');

        [$status, $body] = $this->send('post', 'entries/' . $id . '/reverse', ['reason' => 'Wrong customer']);
        $this->assertSame(201, $status);
        $this->assertSame('REVERSED', $body['data']['original']['status']);
        $this->assertSame($id, $body['data']['reversal']['reversal_of_id']);

        $this->assertSame(409, $this->send('post', 'entries/' . $id . '/reverse', ['reason' => 'Again'])[0]);
        $this->assertSame(409, $this->send('post', 'entries/' . $body['data']['reversal']['id'] . '/reverse', ['reason' => 'Undo'])[0]);
    }

    public function testSettlementThroughTheApi(): void
    {
        $sale = $this->send('post', 'entries', $this->entryBody('2026-03-02', 1000, ['post' => true]))[1]['data']['id'];
        $pay  = $this->send('post', 'entries', [
            'accrual_date' => '2026-03-10', 'cash_date' => '2026-03-10', 'description' => 'Collection', 'post' => true,
            'lines'        => [
                ['account_id' => $this->ids['1.1.01'], 'side' => 'D', 'amount' => 1000],
                ['account_id' => $this->ids['1.1.02'], 'side' => 'C', 'amount' => 1000],
            ],
        ])[1]['data']['id'];

        [$status, $body] = $this->send('post', 'entries/' . $pay . '/settle', ['settled_entry_id' => $sale, 'amount' => 400]);
        $this->assertSame(201, $status);
        $this->assertSame(400, $body['data']['amount']);

        $this->assertSame(409, $this->send('post', 'entries/' . $pay . '/settle', ['settled_entry_id' => $sale, 'amount' => 700])[0]);
        $this->assertSame(422, $this->send('post', 'entries/' . $pay . '/settle', ['settled_entry_id' => $sale])[0]);
        $this->assertCount(1, $this->send('get', 'entries/' . $sale)[1]['data']['settlements']);
    }

    public function testBatchIsAtomicAndRetryable(): void
    {
        $good                      = $this->entryBody('2026-03-02', 100, ['idempotency_key' => 'b1']);
        $bad                       = $this->entryBody('2026-03-03', 100);
        $bad['lines'][1]['amount'] = 1;

        [$status, $body] = $this->send('post', 'entries/batch', ['entries' => [$good, $bad]]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('Entry 2 of the batch', $body['error']['message']);
        $this->assertSame([], $this->send('get', 'entries')[1]['data']);

        $ok = ['entries' => [$good, $this->entryBody('2026-03-04', 200, ['idempotency_key' => 'b2'])]];

        [$status, $first] = $this->send('post', 'entries/batch', $ok);
        $this->assertSame(201, $status);
        $this->assertSame([1, 2], array_column($first['data'], 'number'));

        [$status, $again] = $this->send('post', 'entries/batch', $ok);
        $this->assertSame(200, $status);
        $this->assertSame([false, false], array_column($again['data'], 'created'));
    }

    public function testListFilters(): void
    {
        $this->send('post', 'entries', $this->entryBody('2026-03-02', 100, ['post' => true, 'source_type' => 'invoice', 'source_id' => '7']));
        $this->send('post', 'entries', $this->entryBody('2026-03-03', 200));

        $this->assertCount(2, $this->send('get', 'entries')[1]['data']);
        $this->assertCount(1, $this->send('get', 'entries?status=DRAFT')[1]['data']);
        $this->assertCount(1, $this->send('get', 'entries?source_type=invoice&source_id=7')[1]['data']);
        $this->assertSame(422, $this->send('get', 'entries?status=weird')[0]);
        $this->assertCount(1, $this->send('get', 'entries?limit=1')[1]['data']);
    }

    public function testPeriodCloseAndReopenFlow(): void
    {
        $period = $this->uc()->listPeriods()->execute($this->entity, 1)[2];

        $draft = $this->send('post', 'entries', $this->entryBody('2026-03-02'))[1]['data']['id'];
        $this->assertSame(409, $this->send('post', 'periods/' . $period->id . '/close')[0], 'drafts block the closing');

        $this->send('post', 'entries/' . $draft . '/post');
        [$status, $closed] = $this->send('post', 'periods/' . $period->id . '/close');
        $this->assertSame([200, 'CLOSED'], [$status, $closed['data']['status']]);

        $this->assertSame(422, $this->send('post', 'periods/' . $period->id . '/reopen', [])[0]);
        [$status, $open] = $this->send('post', 'periods/' . $period->id . '/reopen', ['reason' => 'Late invoice']);
        $this->assertSame([200, 'OPEN'], [$status, $open['data']['status']]);
        $this->assertSame(404, $this->send('post', 'periods/9999/close')[0]);
    }

    public function testFiscalYearCloseAndReopen(): void
    {
        $this->send('post', 'entries', $this->entryBody('2026-03-02', 800, ['post' => true]));

        foreach (range(0, 10) as $index) {
            $this->send('post', 'periods/' . $this->uc()->listPeriods()->execute($this->entity, 1)[$index]->id . '/close');
        }

        $this->assertSame(422, $this->send('post', 'fiscal-years/1/close')[0], 'revenue needs a result account');

        [$status, $closed] = $this->send('post', 'fiscal-years/1/close', ['result_account_id' => $this->ids['3.2.01']]);
        $this->assertSame(200, $status);
        $this->assertSame('CLOSED', $closed['data']['status']);
        $this->assertSame('2026-12-31', $closed['data']['closing_entry']['accrual_date']);

        $this->assertSame(422, $this->send('post', 'fiscal-years/1/reopen', [])[0]);
        [$status, $reopened] = $this->send('post', 'fiscal-years/1/reopen', ['reason' => 'Audit']);
        $this->assertSame([200, 'OPEN'], [$status, $reopened['data']['status']]);
    }

    public function testOpeningBalancesThroughTheApi(): void
    {
        [$status, $none] = $this->send('get', 'fiscal-years/1/opening-balances');
        $this->assertSame([200, null], [$status, $none['data']['entry']]);

        $balances = [
            ['account_code' => '1.1.01', 'side' => 'D', 'amount' => 150000],
            ['account_id' => $this->ids['2.1.01'], 'side' => 'C', 'amount' => 40000],
        ];

        $this->assertSame(422, $this->send('post', 'fiscal-years/1/opening-balances', ['balances' => $balances])[0], 'unbalanced');
        $this->assertSame(422, $this->send('post', 'fiscal-years/1/opening-balances', [])[0], 'no balances');
        $this->assertSame(404, $this->send('post', 'fiscal-years/99/opening-balances', ['balances' => $balances])[0]);

        [$status, $posted] = $this->send('post', 'fiscal-years/1/opening-balances', ['balances' => $balances, 'balancing_account_id' => $this->ids['3.1.01']]);
        $this->assertSame(201, $status);
        $this->assertSame(['2026-01-01', 'POSTED', 'contabil.opening-balances'], [$posted['data']['entry']['accrual_date'], $posted['data']['entry']['status'], $posted['data']['entry']['source_type']]);
        $this->assertCount(3, $posted['data']['entry']['lines']);

        $this->assertSame(409, $this->send('post', 'fiscal-years/1/opening-balances', ['balances' => $balances, 'balancing_account_id' => $this->ids['3.1.01']])[0]);

        [$status, $replaced] = $this->send('post', 'fiscal-years/1/opening-balances', [
            'balances' => [['account_code' => '1.1.01', 'side' => 'D', 'amount' => 1000], ['account_code' => '3.1.01', 'side' => 'C', 'amount' => 1000]],
            'replace'  => true,
        ]);
        $this->assertSame(201, $status);
        $this->assertSame($replaced['data']['entry']['id'], $this->send('get', 'fiscal-years/1/opening-balances')[1]['data']['entry']['id']);
        $this->assertSame('REVERSED', $this->send('get', 'entries/' . $posted['data']['entry']['id'])[1]['data']['status']);

        $this->actAs(['contabil.period.manage']);
        $this->assertSame(403, $this->send('post', 'fiscal-years/1/opening-balances', ['balances' => $balances])[0], 'posting also needs contabil.entry.post');
    }

    public function testOpeningTheNextFiscalYear(): void
    {
        [$status, $opened] = $this->send('post', 'fiscal-years/1/open-next');
        $this->assertSame(201, $status);
        $this->assertSame(['2027', '2027-01-01', 'OPEN'], [$opened['data']['label'], $opened['data']['start_date'], $opened['data']['status']]);
        $this->assertCount(12, $opened['data']['periods']);

        $this->assertSame(409, $this->send('post', 'fiscal-years/1/open-next')[0], 'already open');
        $this->assertSame(201, $this->send('post', 'fiscal-years/' . $opened['data']['id'] . '/open-next', ['label' => 'FY28'])[0]);
        $this->assertSame(404, $this->send('post', 'fiscal-years/99/open-next')[0]);

        $this->send('post', 'entries', $this->entryBody('2027-02-01', 500, ['post' => true]));
        $this->assertSame(409, $this->send('post', 'fiscal-years/' . $opened['data']['id'] . '/opening-balances', ['balances' => [
            ['account_code' => '1.1.01', 'side' => 'D', 'amount' => 1], ['account_code' => '3.1.01', 'side' => 'C', 'amount' => 1],
        ]])[0], 'only the first year takes opening balances');
    }

    public function testReportsAsJson(): void
    {
        $this->send('post', 'entries', $this->entryBody('2026-03-02', 1000, ['post' => true]));

        [$status, $tb] = $this->send('get', 'reports/trial-balance?from=2026-03-01&to=2026-03-31');
        $this->assertSame(200, $status);
        $this->assertTrue($tb['data']['balanced']);
        $this->assertSame(1000, $tb['data']['totals']['debits']);

        [, $ledger] = $this->send('get', 'reports/ledger?account_id=' . $this->ids['1.1.02']);
        $this->assertSame(1000, $ledger['data']['sections'][0]['closing']);

        [, $range] = $this->send('get', 'reports/ledger?from_code=1.1.01&to_code=4.1.01');
        $this->assertCount(2, $range['data']['sections']);

        [, $journal] = $this->send('get', 'reports/journal');
        $this->assertCount(1, $journal['data']['entries']);

        [, $income] = $this->send('get', 'reports/income-statement?from=2026-03-01&to=2026-03-31&basis=accrual');
        $this->assertSame(1000, $income['data']['totals']['net_result']);

        [, $cash] = $this->send('get', 'reports/income-statement?from=2026-03-01&to=2026-03-31&basis=cash');
        $this->assertSame(0, $cash['data']['totals']['net_result'], 'nothing was settled');

        [, $sheet] = $this->send('get', 'reports/balance-sheet?as_of=2026-03-31');
        $this->assertTrue($sheet['data']['balanced']);

        [, $source] = $this->send('get', 'reports/by-source?source_type=x&source_id=1');
        $this->assertSame([], $source['data']['entries']);
    }

    public function testReportErrors(): void
    {
        $this->assertSame(422, $this->send('get', 'reports/trial-balance')[0], 'dates are required');
        $this->assertSame(422, $this->send('get', 'reports/income-statement?from=2026-03-01&to=2026-03-31&basis=weird')[0]);
        $this->assertSame(501, $this->send('get', 'reports/balance-sheet?as_of=2026-03-31&basis=cash')[0]);
        $this->assertSame(422, $this->send('get', 'reports/ledger')[0]);
        $this->assertSame(422, $this->send('get', 'reports/journal?cursor=garbage')[0]);
        $this->assertSame(422, $this->send('get', 'reports/trial-balance?from=2026-03-01&to=2026-03-31&format=xml')[0]);
    }

    public function testCsvExportMatchesTheJsonAndNeedsTheExportPermission(): void
    {
        $this->send('post', 'entries', $this->entryBody('2026-03-02', 1000, ['post' => true]));

        $csv = $this->call('get', 'contabil/reports/trial-balance?from=2026-03-01&to=2026-03-31&format=csv');

        $this->assertSame(200, $csv->response()->getStatusCode());
        $this->assertStringContainsString('text/csv', $csv->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="trial-balance.csv"', $csv->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('1.1.02,Receivables,0.00,0.00,10.00,0.00,10.00,0.00', $csv->response()->getBody());

        $this->actAs(['contabil.reports.view']);

        $this->assertSame(403, $this->send('get', 'reports/trial-balance?from=2026-03-01&to=2026-03-31&format=csv')[0]);
        $this->assertSame(200, $this->send('get', 'reports/trial-balance?from=2026-03-01&to=2026-03-31')[0]);
    }

    public function testPdfAnswers501UntilARendererIsProvided(): void
    {
        $this->assertSame(501, $this->send('get', 'reports/journal?format=pdf')[0]);

        Services::injectMock('contabilPdfRenderer', new class () implements PdfRendererInterface {
            public function render(string $html): string
            {
                return '%PDF-fake ' . strlen($html);
            }
        });

        $pdf = $this->call('get', 'contabil/reports/journal?format=pdf');

        $this->assertSame(200, $pdf->response()->getStatusCode());
        $this->assertStringContainsString('application/pdf', $pdf->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-fake', $pdf->response()->getBody());
    }

    public function testPermissionMatrix(): void
    {
        $entry = $this->send('post', 'entries', $this->entryBody('2026-03-02', 100, ['post' => true]))[1]['data']['id'];

        $this->actAs([]);

        foreach ([
            ['get', 'entries'], ['get', 'entries/' . $entry], ['post', 'entries/' . $entry . '/post'],
            ['post', 'entries/' . $entry . '/reverse'], ['post', 'entries/batch'], ['post', 'periods/1/close'],
            ['post', 'fiscal-years/1/close'], ['post', 'fiscal-years/1/open-next'], ['get', 'fiscal-years/1/opening-balances'],
            ['post', 'fiscal-years/1/opening-balances'], ['get', 'reports/journal'], ['get', 'reports/trial-balance?from=2026-03-01&to=2026-03-31'],
        ] as [$method, $path]) {
            $this->assertSame(403, $this->send($method, $path)[0], $method . ' ' . $path);
        }
    }
}
