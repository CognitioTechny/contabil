<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use JsonException;

/**
 * Reads and writes the chart interchange formats, CSV and JSON.
 *
 * A row has the columns `code`, `name`, `type`, `parent_code`, `contra`,
 * `accepts_entries`, `is_cash_account` and `reference_code`. Only `code` and
 * `name` are required; a child may leave `type` empty to inherit it.
 */
final class ChartFormat
{
    public const CSV     = 'csv';
    public const JSON    = 'json';
    public const COLUMNS = ['code', 'name', 'type', 'parent_code', 'contra', 'accepts_entries', 'is_cash_account', 'reference_code'];

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException When the content is not valid in the format.
     */
    public static function parse(string $content, string $format): array
    {
        return match (self::normalize($format)) {
            self::JSON => self::parseJson($content),
            default    => self::parseCsv($content),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows As returned by {@see ExportChart::rows()}.
     */
    public static function render(array $rows, string $format): string
    {
        if (self::normalize($format) === self::JSON) {
            return json_encode(['accounts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        $stream = fopen('php://temp', 'r+b');
        fputcsv($stream, self::COLUMNS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(
                static fn (string $column): string => is_bool($row[$column] ?? null) ? ($row[$column] ? '1' : '0') : (string) ($row[$column] ?? ''),
                self::COLUMNS,
            ), ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public static function normalize(string $format): string
    {
        $format = strtolower(trim($format));

        if (! in_array($format, [self::CSV, self::JSON], true)) {
            throw new ValidationException('The format must be "csv" or "json".');
        }

        return $format;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseJson(string $content): array
    {
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ValidationException('The JSON is not valid: ' . $e->getMessage(), 0, $e);
        }

        $rows = is_array($data) && array_key_exists('accounts', $data) ? $data['accounts'] : $data;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new ValidationException('The JSON must be a list of accounts, or an object with an "accounts" list.');
        }

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                throw new ValidationException(sprintf('Row %d is not an object.', $i + 1));
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $stream  = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $rows   = [];

        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue; // blank line
            }

            if ($header === null) {
                $header = array_map(static fn (?string $cell): string => strtolower(trim((string) $cell)), $cells);

                if (! in_array('code', $header, true) || ! in_array('name', $header, true)) {
                    fclose($stream);

                    throw new ValidationException('The CSV header must contain at least the columns "code" and "name".');
                }

                continue;
            }

            $row = [];

            foreach ($header as $position => $column) {
                if ($column !== '') {
                    $row[$column] = $cells[$position] ?? null;
                }
            }

            $rows[] = $row;
        }

        fclose($stream);

        if ($header === null) {
            throw new ValidationException('The CSV is empty.');
        }

        return $rows;
    }
}
