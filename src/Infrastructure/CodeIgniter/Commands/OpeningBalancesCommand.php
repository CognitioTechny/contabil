<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Domain\Money;
use JsonException;

/**
 * Posts the opening balances of an entity's first fiscal year from a file.
 *
 * A `.json` file holds the same list the API takes (`account_code` or
 * `account_id`, `side`, `amount` in minor units). Any other file is CSV with
 * the header `account_code,side,amount`, the amount as a decimal (`1500.00`).
 */
class OpeningBalancesCommand extends ContabilCommand
{
    protected $name        = 'contabil:opening-balances';
    protected $description = 'Posts the opening balances of the first fiscal year from a CSV or JSON file.';
    protected $usage       = 'contabil:opening-balances --file <path> [--entity <id>] [--year <label|id>] [--balancing-account <code>] [--replace]';
    protected $options     = [
        '--file'              => 'CSV (account_code,side,amount as decimal) or JSON (list, amount in minor units).',
        '--entity'            => 'Entity id; defaults to the configured defaultEntityId.',
        '--year'              => 'Year by label or id; defaults to the first fiscal year of the entity.',
        '--balancing-account' => 'Code of an equity account that takes any difference between debits and credits.',
        '--replace'           => 'Reverses opening balances already posted and posts these instead.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        try {
            $entity = $this->requiredEntity($params);
            $file   = (string) $this->option($params, 'file');

            if ($file === '' || ! is_file($file) || ! is_readable($file)) {
                throw new ValidationException('Pass a readable file with --file <path>.');
            }

            $reference = $this->option($params, 'year');
            $years     = service('contabil')->listFiscalYears()->execute($entity);
            $year      = $reference !== null ? $this->fiscalYear($entity, $reference) : ($years[0] ?? throw new ValidationException('The entity has no fiscal year; run contabil:open-year --start first.'));
            $balancing = $this->option($params, 'balancing-account');

            $entry = service('contabil')->openingBalances()->record(
                $entity,
                (int) $year->id,
                self::read($file),
                $balancing === null ? null : $this->accountId($entity, $balancing),
                null,
                array_key_exists('replace', $params),
            );
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            'Opening balances of fiscal year %s posted as entry %d (%d lines, %s on each side).',
            $year->label,
            (int) $entry->number,
            count($entry->lines),
            Money::format($entry->totalDebits()),
        ), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function read(string $file): array
    {
        $content = (string) file_get_contents($file);

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
            try {
                $rows = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ValidationException('The file is not valid JSON.');
            }

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new ValidationException('The JSON file must hold a list of balances.');
            }

            return $rows;
        }

        $lines  = preg_split('/\R/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '')) ?: [];
        $header = array_map(static fn (string $h): string => strtolower(trim($h)), str_getcsv((string) array_shift($lines), ',', '"', ''));

        if (array_diff(['account_code', 'side', 'amount'], $header) !== []) {
            throw new ValidationException('The CSV header must have account_code, side and amount.');
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, ',', '"', '');

            if (count($values) !== count($header)) {
                throw new ValidationException(sprintf('CSV line %d has %d columns; the header has %d.', $index + 2, count($values), count($header)));
            }

            $row = array_combine($header, array_map('trim', $values));

            try {
                $amount = Money::fromDecimal($row['amount'])->minor;
            } catch (ValidationException $e) {
                throw new ValidationException(sprintf('CSV line %d: %s', $index + 2, $e->getMessage()), 0, $e);
            }

            $rows[] = ['account_code' => $row['account_code'], 'side' => $row['side'], 'amount' => $amount];
        }

        return $rows;
    }
}
