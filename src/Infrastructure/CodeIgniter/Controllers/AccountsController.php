<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CognitioTechny\Contabil\Application\Chart\AccountNode;
use CognitioTechny\Contabil\Application\Chart\ChartFormat;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Chart of accounts API. Reads need `contabil.chart.view`, writes need
 * `contabil.chart.edit`.
 */
class AccountsController extends ApiController
{
    private const VIEW = 'contabil.chart.view';
    private const EDIT = 'contabil.chart.edit';

    public function index(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array => array_map(
            static fn (Account $account): array => $account->toArray(),
            $this->useCases()->listAccounts()->execute($entity, $this->flag('include_inactive')),
        ));
    }

    public function tree(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array => array_map(
            static fn (AccountNode $node): array => $node->toArray(),
            $this->useCases()->getAccountTree()->execute($entity, $this->flag('include_inactive')),
        ));
    }

    public function show(string $id): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array => $this->useCases()->getAccount()->execute($entity, (int) $id)->toArray());
    }

    public function create(): ResponseInterface
    {
        return $this->handle(self::EDIT, function (int $entity): array {
            $body = $this->jsonBody();

            return $this->useCases()->createAccount()->execute(
                $entity,
                (string) ($body['code'] ?? ''),
                (string) ($body['name'] ?? ''),
                isset($body['type']) ? (string) $body['type'] : null,
                isset($body['parent_id']) ? (int) $body['parent_id'] : null,
                (bool) ($body['contra'] ?? false),
                array_key_exists('accepts_entries', $body) ? (bool) $body['accepts_entries'] : null,
                (bool) ($body['is_cash_account'] ?? false),
                isset($body['reference_code']) ? (string) $body['reference_code'] : null,
            )->toArray();
        }, 201);
    }

    public function update(string $id): ResponseInterface
    {
        return $this->handle(self::EDIT, fn (int $entity): array => $this->useCases()->updateAccount()->execute($entity, (int) $id, $this->jsonBody())->toArray());
    }

    public function delete(string $id): ResponseInterface
    {
        return $this->handle(self::EDIT, function (int $entity) use ($id): ResponseInterface {
            $this->useCases()->deleteAccount()->execute($entity, (int) $id);

            return $this->response->setStatusCode(204);
        });
    }

    public function move(string $id): ResponseInterface
    {
        return $this->handle(self::EDIT, function (int $entity) use ($id): array {
            $body = $this->jsonBody();

            if (! array_key_exists('parent_id', $body)) {
                throw new ValidationException('parent_id is required; use null to move the account to the root.');
            }

            return $this->useCases()->moveAccount()->execute(
                $entity,
                (int) $id,
                $body['parent_id'] === null ? null : (int) $body['parent_id'],
                isset($body['code']) ? (string) $body['code'] : null,
            )->toArray();
        });
    }

    public function deactivate(string $id): ResponseInterface
    {
        return $this->handle(self::EDIT, fn (int $entity): array => $this->useCases()->deactivateAccount()->execute($entity, (int) $id)->toArray());
    }

    public function reactivate(string $id): ResponseInterface
    {
        return $this->handle(self::EDIT, fn (int $entity): array => $this->useCases()->reactivateAccount()->execute($entity, (int) $id)->toArray());
    }

    /**
     * Imports a chart from the request body (JSON, or CSV with `?format=csv`
     * or a `text/csv` content type), or from a multipart `file` upload.
     */
    public function import(): ResponseInterface
    {
        return $this->handle(self::EDIT, function (int $entity): array {
            $content = (string) $this->request->getBody();
            $format  = $this->request->getGet('format');
            $type    = strtolower($this->request->getHeaderLine('Content-Type'));
            $file    = $this->request->getFile('file');

            if ($file !== null && $file->isValid()) {
                $content = (string) file_get_contents($file->getTempName());
                $format ??= strtolower($file->getClientExtension());
            }

            $format ??= str_contains($type, 'csv') ? ChartFormat::CSV : ChartFormat::JSON;

            return $this->useCases()->importChart()->execute(
                $entity,
                ChartFormat::parse($content, (string) $format),
                $this->flag('skip_existing'),
            )->toArray();
        });
    }

    public function export(): ResponseInterface
    {
        return $this->handle(self::VIEW, function (int $entity): ResponseInterface {
            $format = ChartFormat::normalize((string) ($this->request->getGet('format') ?? ChartFormat::CSV));
            $body   = $this->useCases()->exportChart()->execute($entity, $format, true);

            return $this->response
                ->setContentType($format === ChartFormat::CSV ? 'text/csv' : 'application/json')
                ->setHeader('Content-Disposition', 'attachment; filename="chart-of-accounts.' . $format . '"')
                ->setBody($body);
        });
    }

    private function flag(string $name): bool
    {
        return filter_var($this->request->getGet($name), FILTER_VALIDATE_BOOLEAN);
    }
}
