<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CognitioTechny\Contabil\Application\Reports\Report;
use CognitioTechny\Contabil\Application\Support\DateInput;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Books and statements. Every report needs `contabil.reports.view`; CSV and PDF
 * (`?format=csv|pdf`) also need `contabil.reports.export`. JSON is the default.
 *
 * Reports only read, so they are safe to point at a read replica.
 */
class ReportsController extends ApiController
{
    private const VIEW   = 'contabil.reports.view';
    private const EXPORT = 'contabil.reports.export';

    public function ledger(): ResponseInterface
    {
        return $this->handle(self::VIEW, function (int $entity): array|ResponseInterface {
            $builder = $this->useCases()->generalLedger();
            $from    = $this->date('from');
            $to      = $this->date('to');

            if ($this->param('from_code') !== null || $this->param('to_code') !== null) {
                $report = $builder->forRange($entity, (string) $this->param('from_code'), (string) $this->param('to_code'), $from, $to);
            } else {
                $account = $this->param('account_id');

                if ($account === null) {
                    throw new ValidationException('Pass account_id, or from_code and to_code.');
                }

                $report = $builder->forAccount($entity, (int) $account, $from, $to, $this->param('cursor'), (int) ($this->param('limit') ?? 100));
            }

            return $this->deliver($report, $entity);
        });
    }

    public function journal(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array|ResponseInterface => $this->deliver(
            $this->useCases()->entryListing()->journal($entity, $this->date('from'), $this->date('to'), $this->param('cursor'), (int) ($this->param('limit') ?? 100)),
            $entity,
        ));
    }

    public function trialBalance(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array|ResponseInterface => $this->deliver(
            $this->useCases()->trialBalance()->execute($entity, $this->requiredDate('from'), $this->requiredDate('to'), $this->flag('include_zero')),
            $entity,
        ));
    }

    public function incomeStatement(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array|ResponseInterface => $this->deliver(
            $this->useCases()->statements()->incomeStatement($entity, $this->requiredDate('from'), $this->requiredDate('to'), $this->param('basis'), $this->flag('include_zero')),
            $entity,
        ));
    }

    public function balanceSheet(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array|ResponseInterface => $this->deliver(
            $this->useCases()->statements()->balanceSheet($entity, $this->requiredDate('as_of'), $this->param('basis'), $this->flag('include_zero')),
            $entity,
        ));
    }

    public function bySource(): ResponseInterface
    {
        return $this->handle(self::VIEW, fn (int $entity): array|ResponseInterface => $this->deliver(
            $this->useCases()->entryListing()->bySource($entity, (string) $this->param('source_type'), (string) $this->param('source_id')),
            $entity,
        ));
    }

    /**
     * The report in the requested format: an array for the JSON envelope, or a
     * ready response for a download.
     *
     * @return array<string, mixed>|ResponseInterface
     */
    private function deliver(Report $report, int $entityId): array|ResponseInterface
    {
        $format = strtolower($this->param('format') ?? 'json');

        if ($format === 'json') {
            return $report->toArray();
        }

        if (! in_array($format, ['csv', 'pdf'], true)) {
            throw new ValidationException('The format must be json, csv or pdf.');
        }

        $this->also(self::EXPORT, $entityId);

        $renderer = $this->useCases()->reportRenderer();

        if ($format === 'csv') {
            return $this->download($renderer->csv($report), 'text/csv', $report->slug() . '.csv');
        }

        return $this->download($renderer->pdf($report, service('contabilPdfRenderer')), 'application/pdf', $report->slug() . '.pdf');
    }

    private function download(string $body, string $type, string $filename): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setContentType($type)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($body);
    }

    private function param(string $name): ?string
    {
        $value = $this->request->getGet($name);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function flag(string $name): bool
    {
        return in_array(strtolower((string) $this->request->getGet($name)), ['1', 'true', 'yes'], true);
    }

    private function date(string $name): ?DateTimeImmutable
    {
        return DateInput::parseOptional($this->param($name), $name);
    }

    private function requiredDate(string $name): DateTimeImmutable
    {
        return DateInput::parse($this->param($name), $name);
    }
}
