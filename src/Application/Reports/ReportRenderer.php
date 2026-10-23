<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Application\Ports\PdfRendererInterface;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;

/**
 * Turns a {@see Report} into JSON, CSV, HTML or PDF. The formats are views of
 * the same data: the table that CSV and PDF show comes from the report itself.
 */
final class ReportRenderer
{
    public function json(Report $report): string
    {
        return json_encode(['data' => $report->toArray()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * RFC 4180 CSV with a header row, UTF-8, CRLF line breaks. Cells that start
     * with a formula character are prefixed with an apostrophe so a spreadsheet
     * does not execute them.
     */
    public function csv(Report $report): string
    {
        $lines = [self::csvLine($report->tableHeader(), false)];

        foreach ($report->tableRows() as $row) {
            $lines[] = self::csvLine($row, true);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function html(Report $report): string
    {
        $title   = self::escape($report->title());
        $summary = '';

        foreach ($report->summary() as $label => $value) {
            $summary .= '<tr><th>' . self::escape($label) . '</th><td>' . self::escape($value) . '</td></tr>';
        }

        $head = implode('', array_map(static fn (string $cell): string => '<th>' . self::escape($cell) . '</th>', $report->tableHeader()));
        $body = '';

        foreach ($report->tableRows() as $row) {
            $body .= '<tr>' . implode('', array_map(static fn (string $cell): string => '<td>' . self::escape($cell) . '</td>', $row)) . '</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '<style>body{font-family:sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #999;padding:3px 6px;text-align:left}th{background:#eee}</style></head><body>'
            . '<h1>' . $title . '</h1><table class="summary">' . $summary . '</table><br>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';
    }

    /**
     * @throws UnsupportedOperationException When no renderer is configured.
     */
    public function pdf(Report $report, PdfRendererInterface $renderer): string
    {
        return $renderer->render($this->html($report));
    }

    /**
     * @param list<string> $cells
     */
    private static function csvLine(array $cells, bool $guard): string
    {
        return implode(',', array_map(static function (string $cell) use ($guard): string {
            if ($guard && $cell !== '' && str_contains('=+@', $cell[0]) || $guard && str_starts_with($cell, "\t")) {
                $cell = "'" . $cell;
            }

            return preg_match('/[",\r\n]/', $cell) === 1 ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
        }, $cells));
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
