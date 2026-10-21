<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Imports accounts from rows ({@see ChartFormat}). All or nothing: every row is
 * checked with the same rules as {@see CreateAccount}, errors are collected per
 * row, and a single bad row rolls the whole import back.
 *
 * Rows may come in any order; a row waits until its parent exists. A parent may
 * be an account already in the entity or an earlier row of the same import.
 */
final class ImportChart
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly CreateAccount $createAccount,
        private readonly AccountRepositoryInterface $accounts,
        private readonly TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param bool                       $skipExisting Leave accounts whose code already exists as they are,
     *                                                 instead of rejecting the row. This makes seeding repeatable.
     *
     * @throws ChartImportException When any row is rejected; nothing is kept.
     */
    public function execute(int $entityId, array $rows, bool $skipExisting = false): ImportResult
    {
        $this->entities->assertExists($entityId);

        return $this->transactions->run(function () use ($entityId, $rows, $skipExisting): ImportResult {
            $errors  = [];
            $created = 0;
            $skipped = 0;
            $failed  = [];
            $pending = [];

            foreach (array_values($rows) as $i => $row) {
                $pending[$i + 1] = $row;
            }

            $pendingCodes = [];

            foreach ($pending as $n => $row) {
                $code = self::text($row['code'] ?? null);

                if ($code !== null) {
                    $pendingCodes[$code][] = $n;
                }
            }

            do {
                $progress = false;

                foreach ($pending as $n => $row) {
                    $code       = self::text($row['code'] ?? null);
                    $parentCode = self::text($row['parent_code'] ?? null);
                    $parent     = null;

                    if ($parentCode !== null) {
                        $parent = $this->accounts->findByCode($entityId, $parentCode);

                        if ($parent === null && isset($failed[$parentCode])) {
                            $errors[$n] = ['row' => $n, 'code' => $code, 'message' => sprintf('The parent "%s" was rejected.', $parentCode)];
                        } elseif ($parent === null && $this->waitsFor($pendingCodes, $parentCode, $n)) {
                            continue;
                        } elseif ($parent === null) {
                            $errors[$n] = ['row' => $n, 'code' => $code, 'message' => sprintf('The parent "%s" does not exist.', $parentCode)];
                        }

                        if (isset($errors[$n])) {
                            unset($pending[$n]);
                            $this->forget($pendingCodes, $code, $n);
                            $failed[(string) $code] = true;
                            $progress               = true;

                            continue;
                        }
                    }

                    unset($pending[$n]);
                    $this->forget($pendingCodes, $code, $n);
                    $progress = true;

                    try {
                        if ($code === null) {
                            throw new ValidationException('The code is required.');
                        }

                        if ($skipExisting && $this->accounts->findByCode($entityId, $code) !== null) {
                            $skipped++;

                            continue;
                        }

                        $this->createAccount->create(
                            $entityId,
                            $code,
                            (string) ($row['name'] ?? ''),
                            self::text($row['type'] ?? null),
                            $parent?->id,
                            self::flag($row['contra'] ?? null, 'contra') ?? false,
                            self::flag($row['accepts_entries'] ?? null, 'accepts_entries'),
                            self::flag($row['is_cash_account'] ?? null, 'is_cash_account') ?? false,
                            self::text($row['reference_code'] ?? null),
                        );
                        $created++;
                    } catch (ContabilException $e) {
                        $errors[$n] = ['row' => $n, 'code' => $code, 'message' => $e->getMessage()];

                        if ($code !== null) {
                            $failed[$code] = true;
                        }
                    }
                }
            } while ($progress && $pending !== []);

            foreach ($pending as $n => $row) {
                $errors[$n] = ['row' => $n, 'code' => self::text($row['code'] ?? null), 'message' => 'The parent chain never reaches an existing account (cycle?).'];
            }

            if ($errors !== []) {
                ksort($errors);

                throw new ChartImportException(array_values($errors));
            }

            return new ImportResult($created, $skipped);
        });
    }

    /**
     * Whether a still-pending row other than `$self` will create `$code`.
     *
     * @param array<string, list<int>> $pendingCodes
     */
    private function waitsFor(array $pendingCodes, string $code, int $self): bool
    {
        return array_diff($pendingCodes[$code] ?? [], [$self]) !== [];
    }

    /**
     * @param array<string, list<int>> $pendingCodes
     */
    private function forget(array &$pendingCodes, ?string $code, int $n): void
    {
        if ($code !== null) {
            $pendingCodes[$code] = array_values(array_diff($pendingCodes[$code] ?? [], [$n]));
        }
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function flag(mixed $value, string $column): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        return match (strtolower($text)) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default                 => throw new ValidationException(sprintf('"%s" is not a valid value for %s; use 1 or 0.', $text, $column)),
        };
    }
}
