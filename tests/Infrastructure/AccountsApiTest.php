<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\FeatureTestTrait;
use CognitioTechny\Contabil\Application\Defaults\StaticCurrentEntity;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeAuthorizer;

/**
 * The chart and calendar API, end to end through the router, with a fake
 * authorizer and the package defaults for every other port.
 *
 * @internal
 */
final class AccountsApiTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private const ALL = ['contabil.chart.view', 'contabil.chart.edit', 'contabil.period.manage'];

    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = service('contabil')->createEntity()->execute('Acme');
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
    private function actAs(array $permissions, ?int $entity = null): FakeAuthorizer
    {
        $authorizer = new FakeAuthorizer($permissions);

        Services::injectMock('contabilAuthorizer', $authorizer);
        Services::injectMock('contabilCurrentEntity', new StaticCurrentEntity($entity ?? $this->entity));

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

    public function testCreateAndReadAnAccount(): void
    {
        [$status, $body] = $this->send('post', 'accounts', ['code' => '1', 'name' => 'Assets', 'type' => 'asset']);

        $this->assertSame(201, $status);
        $this->assertSame('DEBIT', $body['data']['nature']);

        [$status, $body] = $this->send('get', 'accounts/' . $body['data']['id']);
        $this->assertSame(200, $status);
        $this->assertSame('Assets', $body['data']['name']);
    }

    public function testTreeMoveDeactivateAndReactivate(): void
    {
        $root  = $this->send('post', 'accounts', ['code' => '1', 'name' => 'Assets', 'type' => 'asset'])[1]['data'];
        $other = $this->send('post', 'accounts', ['code' => '2', 'name' => 'More assets', 'type' => 'asset'])[1]['data'];
        $child = $this->send('post', 'accounts', ['code' => '1.1', 'name' => 'Cash', 'parent_id' => $root['id']])[1]['data'];

        [, $tree] = $this->send('get', 'accounts/tree');
        $this->assertSame(['1', '2'], array_column($tree['data'], 'code'));
        $this->assertSame('1.1', $tree['data'][0]['children'][0]['code']);

        [$status, $moved] = $this->send('post', 'accounts/' . $child['id'] . '/move', ['parent_id' => $other['id']]);
        $this->assertSame(200, $status);
        $this->assertSame($other['id'], $moved['data']['parent_id']);

        [$status, $off] = $this->send('post', 'accounts/' . $child['id'] . '/deactivate');
        $this->assertFalse($off['data']['active']);

        [, $list] = $this->send('get', 'accounts');
        $this->assertSame(['1', '2'], array_column($list['data'], 'code'));

        [$status, $on] = $this->send('post', 'accounts/' . $child['id'] . '/reactivate');
        $this->assertTrue($on['data']['active']);
    }

    public function testUpdateAndDelete(): void
    {
        $account = $this->send('post', 'accounts', ['code' => '9', 'name' => 'Misc', 'type' => 'expense'])[1]['data'];

        [$status, $body] = $this->send('patch', 'accounts/' . $account['id'], ['name' => 'Miscellaneous']);
        $this->assertSame(200, $status);
        $this->assertSame('Miscellaneous', $body['data']['name']);

        [$status] = $this->send('delete', 'accounts/' . $account['id']);
        $this->assertSame(204, $status);

        [$status] = $this->send('get', 'accounts/' . $account['id']);
        $this->assertSame(404, $status);
    }

    public function testDomainErrorsMapToHttpStatuses(): void
    {
        $this->send('post', 'accounts', ['code' => '1', 'name' => 'Assets', 'type' => 'asset']);

        [$status, $body] = $this->send('post', 'accounts', ['code' => '1', 'name' => 'Again', 'type' => 'asset']);
        $this->assertSame(409, $status);
        $this->assertSame('ConflictException', $body['error']['type']);

        [$status] = $this->send('post', 'accounts', ['code' => '2', 'name' => 'No type']);
        $this->assertSame(422, $status);

        [$status] = $this->send('get', 'accounts/9999');
        $this->assertSame(404, $status);

        [$status] = $this->send('post', 'accounts/1/move', []);
        $this->assertSame(422, $status);
    }

    public function testImportReportsPerRowErrorsAndKeepsNothing(): void
    {
        [$status, $body] = $this->send('post', 'accounts/import', ['accounts' => [
            ['code' => '1', 'name' => 'Assets', 'type' => 'asset'],
            ['code' => '2', 'name' => 'Bad', 'type' => 'nope'],
        ]]);

        $this->assertSame(422, $status);
        $this->assertSame(2, $body['error']['details'][0]['row']);
        $this->assertSame([], $this->send('get', 'accounts')[1]['data']);
    }

    public function testImportAndExportCsv(): void
    {
        $csv    = "code,name,type,parent_code\n1,Assets,asset,\n1.1,Cash,,1\n";
        $result = $this->withBody($csv)->withHeaders(['Content-Type' => 'text/csv'])->call('post', 'contabil/accounts/import');

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertSame(['created' => 2, 'skipped' => 0], json_decode($result->getJSON(), true)['data']);

        $export = $this->call('get', 'contabil/accounts/export?format=csv');
        $this->assertStringContainsString('text/csv', $export->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('1.1,Cash,ASSET,1', $export->response()->getBody());

        $json = json_decode($this->call('get', 'contabil/accounts/export?format=json')->response()->getBody(), true);
        $this->assertSame(['1', '1.1'], array_column($json['accounts'], 'code'));
    }

    public function testFiscalYearAndPeriods(): void
    {
        [$status, $body] = $this->send('post', 'fiscal-years', ['start_date' => '2026-01-01']);
        $this->assertSame(201, $status);
        $this->assertCount(12, $body['data']['periods']);

        [, $periods] = $this->send('get', 'fiscal-years/' . $body['data']['id'] . '/periods');
        $this->assertSame('2026-01-01', $periods['data'][0]['start_date']);
        $this->assertSame('2026-12-31', $periods['data'][11]['end_date']);

        [$status] = $this->send('post', 'fiscal-years', ['start_date' => '2026-06-01']);
        $this->assertSame(409, $status);

        [$status] = $this->send('post', 'fiscal-years', ['start_date' => '2027-01-15']);
        $this->assertSame(422, $status);

        $this->assertCount(1, $this->send('get', 'fiscal-years')[1]['data']);
    }

    public function testPermissionsAreEnforcedPerAction(): void
    {
        $authorizer = $this->actAs(['contabil.chart.view']);

        [$status, $body] = $this->send('post', 'accounts', ['code' => '1', 'name' => 'Assets', 'type' => 'asset']);
        $this->assertSame(403, $status);
        $this->assertSame('AccessDeniedException', $body['error']['type']);

        $this->assertSame(200, $this->send('get', 'accounts')[0]);
        $this->assertSame(403, $this->send('post', 'fiscal-years', ['start_date' => '2026-01-01'])[0]);
        $this->assertSame(['contabil.chart.edit', $this->entity], $authorizer->checks[0]);
    }

    public function testDefaultAuthorizerKeepsTheApiClosed(): void
    {
        $this->resetServices();
        Services::injectMock('contabilCurrentEntity', new StaticCurrentEntity($this->entity));

        $this->assertSame(403, $this->send('get', 'accounts')[0]);
    }

    public function testEntityIdParameterIsAFallbackOnlyWhenNoEntityIsSelected(): void
    {
        Services::injectMock('contabilAuthorizer', new FakeAuthorizer(self::ALL));
        Services::injectMock('contabilCurrentEntity', new StaticCurrentEntity(null));

        $this->assertSame(422, $this->send('get', 'accounts')[0]);
        $this->assertSame(200, $this->send('get', 'accounts?entity_id=' . $this->entity)[0]);
        $this->assertSame(422, $this->send('get', 'accounts?entity_id=abc')[0]);
        $this->assertSame(404, $this->send('get', 'accounts?entity_id=999')[0]);
    }

    public function testAccountsOfAnotherEntityAreInvisible(): void
    {
        $account = $this->send('post', 'accounts', ['code' => '1', 'name' => 'Assets', 'type' => 'asset'])[1]['data'];
        $other   = service('contabil')->createEntity()->execute('Other');

        $this->actAs(self::ALL, $other);

        $this->assertSame(404, $this->send('get', 'accounts/' . $account['id'])[0]);
        $this->assertSame(404, $this->send('delete', 'accounts/' . $account['id'])[0]);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $result = $this->withBody('{nope')->withHeaders(['Content-Type' => 'application/json'])->call('post', 'contabil/accounts');

        $this->assertSame(422, $result->response()->getStatusCode());
    }
}
