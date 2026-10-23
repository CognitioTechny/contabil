# Events

The package publishes domain events through the PSR-14 dispatcher registered as the `contabilEventDispatcher` service (see [ports.md](ports.md#eventdispatcherinterface)). Without a dispatcher they go nowhere (`NullEventDispatcher`).

Rules:

- Events are dispatched **after the transaction commits**, never inside it. A batch publishes its events once the whole batch is committed; a failed or rolled-back write publishes nothing.
- A listener that throws never undoes an accounting write. The exception is kept and available from `service('contabil')->eventFailures()` for logging.
- A retry that matched an idempotency key publishes nothing, since nothing happened.
- Events carry immutable snapshots; listeners must not assume the database still looks the same.

All classes live in `CognitioTechny\Contabil\Domain\Event`.

| Event | When | Properties |
|---|---|---|
| `EntryPosted` | An entry entered the ledger (including reversals, opening and closing entries) | `entry` (`JournalEntry`) |
| `EntryReversed` | An entry was cancelled | `original`, `reversal`, `reason` |
| `EntrySettled` | A settlement link was recorded | `entityId`, `settlement` |
| `PeriodClosed` | A period was closed (also the last period when a year closes) | `entityId`, `period`, `userId` |
| `PeriodReopened` | A closed period was opened again | `entityId`, `period`, `reason`, `userId` |
| `FiscalYearOpened` | The year after another was opened | `year`, `previous`, `userId` |
| `OpeningBalancesRecorded` | The opening balances of the first year were posted | `year`, `entry`, `replaced` (the reversed opening entry, or null), `userId` |
| `FiscalYearClosed` | A year was closed | `year`, `closingEntry` (null if none), `userId` |
| `FiscalYearReopened` | A year was reopened | `year`, `reversalOfClosing`, `reason`, `userId` |

Reversing an entry publishes `EntryPosted` for the reversal and then `EntryReversed`. Closing a year publishes `EntryPosted` (the closing entry), `PeriodClosed` and `FiscalYearClosed`.

## Listening

```php
// app/Config/Events.php
use CognitioTechny\Contabil\Domain\Event\EntryPosted;

// A PSR-14 provider of your choice; with a minimal one:
$listeners->listen(EntryPosted::class, static function (EntryPosted $event): void {
    audit_log('ledger.posted', [
        'entry'  => $event->entry->id,
        'number' => $event->entry->number,
        'hash'   => $event->entry->hash,   // keep it outside the database to anchor the hash chain
    ]);
});
```

## What a host usually does with them

- **Audit trail**: log `PeriodReopened` and `FiscalYearReopened` with their `reason` and `userId`; they are the only record of why a closed period was opened.
- **Hash anchor**: store `EntryPosted::$entry->hash` somewhere the database user cannot rewrite.
- **Privacy per record**: entries carry `source_type` and `source_id`, so the host can decide who may see an entry's source document.
- **Sync back**: mark the host document as booked using `idempotency_key` or `source_id` when `EntryPosted` arrives.
