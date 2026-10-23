<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Application\Entry\EntryData;
use Throwable;

/**
 * Posts one entry to an entity prepared by `contabil:independence-check`.
 *
 * The CI job starts many of these at the same time and then runs
 * `contabil:verify-integrity`, which fails on any gap in the numbering or any
 * break in the hash chain. It is the proof that concurrent postings stay
 * gap-free.
 *
 * The CI job copies this file to `app/Commands/ConcurrencyWorker.php`.
 */
class ConcurrencyWorker extends BaseCommand
{
    protected $group       = 'Contabil';
    protected $name        = 'contabil:concurrency-worker';
    protected $description = 'Posts one entry; started in parallel by the CI concurrency check.';
    protected $usage       = 'contabil:concurrency-worker <entity> <n>';

    public function run(array $params)
    {
        $entity = (int) ($params[0] ?? 0);
        $n      = (int) ($params[1] ?? 0);

        if ($entity < 1 || $n < 1) {
            CLI::error('Usage: ' . $this->usage);

            return EXIT_ERROR;
        }

        try {
            $uc  = service('contabil');
            $ids = [];

            foreach ($uc->listAccounts()->execute($entity) as $account) {
                $ids[$account->code] = (int) $account->id;
            }

            $uc->createEntry()->execute($entity, new EntryData(
                '2026-02-10',
                'Concurrent posting ' . $n,
                [
                    ['account_id' => $ids['1.1.02'], 'side' => 'D', 'amount' => $n],
                    ['account_id' => $ids['3.1.01'], 'side' => 'C', 'amount' => $n],
                ],
                null,
                null,
                null,
                'worker:' . $n,
            ), true);
        } catch (Throwable $e) {
            CLI::error('worker ' . $n . ' failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
