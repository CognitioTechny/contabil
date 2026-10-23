# Integration guide

How a host application (an ERP, a case-management system, a billing module) connects to the package without the package knowing anything about it. The host owns the adapter; the package owns the ledger. Nothing in this repository names a host table or class.

## 1. Implement the ports

Write the adapters described in [ports.md](ports.md): who is acting, who may do what, which entity is current. Run the shipped contract tests against them. Everything else is optional.

| You provide | Package gives you |
|---|---|
| `AuthorizerInterface` over your permission system, answering `contabil.*` strings | per-action permission checks on the API |
| `CurrentUserInterface` | `user_id` on every entry |
| `CurrentEntityInterface` | the entity every request works on |
| A PSR-14 dispatcher | the events in [events.md](events.md) for audit and privacy |

## 2. Money

Legacy systems often keep `DECIMAL(15,2)`. The package never sees a decimal: convert in **one place** in your adapter.

```php
use CognitioTechny\Contabil\Domain\Money;

$cents = Money::fromDecimal('1234.56')->minor;   // 123456, parsed from the string, never via float
$text  = Money::format($cents);                  // '1234.56'
```

`Money::fromDecimal` rejects more decimal places than the scale and anything that is not a plain number. Do not call `(int) ($amount * 100)`: it is wrong for values like `0.29`.

## 3. Link entries to your documents

Every entry has `source_type`, `source_id` and `idempotency_key`, all opaque strings; there is no foreign key to your tables.

```text
source_type      invoice
source_id        1042
idempotency_key  erp:invoices:1042:1        {system}:{table}:{id}:{version}
```

- The key makes "post this invoice" safe to retry: the second call returns the first entry and writes nothing.
- A key reused with different content is a conflict, which catches a changed document that was not versioned.
- `GET /reports/by-source?source_type=invoice&source_id=1042` returns every entry of that document, reversals included.

## 4. Never edit: reverse and re-post

When the host document changes after it was booked:

1. Reverse the posted entry with a reason (`POST /entries/{id}/reverse`).
2. Post the new version with the next version number in the key (`erp:invoices:1042:2`) and the same `source_type`/`source_id`.

If the original's period is closed, pass a `date` in an open period for the reversal. If the original has settlements (payments), reverse those first.

## 5. Decide who books what

Two ways to map events to accounts:

- **Explicit**: the host builds the lines (`createEntry()` / `postBatch()`). Best for multi-line entries.
- **Posting rules**: register account pairs per event and call `postingRules()->post()` ([posting-rules.md](posting-rules.md)). When no rule matches it returns `null`; keep that event in a **pending queue** of your own, show it to an accountant, and retry after the rule is added.

## 6. Cash and accrual

Post a sale on credit with no `cash_date`; post the receipt as its own entry with its `cash_date`, then link them with `POST /entries/{receipt}/settle`. The cash-basis Income Statement now recognizes the revenue on the receipt date, in proportion to what was received. For a sale paid on the spot, post one entry with the `cash_date` set and no settlement ([reports.md](reports.md#cash-and-accrual-basis)).

## 7. Batches and imports

`postBatch()` is atomic: all or none, up to 500 entries, retryable thanks to the keys. For larger volumes, send several batches; each is its own transaction.

## 8. Audit and privacy

- Subscribe to `PeriodReopened` and `FiscalYearReopened` ([events.md](events.md)): they carry the reason and the user.
- Store the `hash` of each `EntryPosted` outside the database to anchor the chain.
- Per-record privacy belongs to the host: filter entries by `source_type`/`source_id` in your own controllers, or expose only the reports.

## 9. Proving it works

Run the contract tests against your adapters, then the same smoke test CI runs on an empty application: [`tests/Independence/IndependenceCheck.php`](../tests/Independence/IndependenceCheck.php) creates a chart, posts, reverses, closes a period and reads the ledger and trial balance. Copy it to `app/Commands`, point it at a scratch database and run `php spark contabil:independence-check`.

Moving existing data in: [migration-guide.md](migration-guide.md).
