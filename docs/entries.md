# Entries, periods and closing

The entry engine is real double entry: one header, N lines, posted only when total debits equal total credits. A posted entry is never edited or deleted; a correction is a reversal plus a new entry. Money is an **integer in minor units** (cents), never a float; the API refuses `10.5` as an amount.

## States

| Status | Meaning |
|---|---|
| `DRAFT` | Editable, deletable, outside the ledger and every report. Has no number yet. |
| `POSTED` | In the ledger, immutable, numbered and sealed in the hash chain. |
| `REVERSED` | A posted entry that a reversal cancelled. Stays in the ledger next to its reversal. |

The only transitions are `DRAFT` to `POSTED` and `POSTED` to `REVERSED`. Nothing in the package, API or repository can change the content of a posted entry.

## Posting rules

Posting an entry checks, in this order, and rejects the whole thing (nothing written, not even a draft) when any fails:

1. At least two lines, every amount a positive integer, `side` `D` or `C`.
2. Total debits equal total credits (`ValidationException`, 422).
3. Every line is on an **active leaf account of the same entity** (a group, an inactive account or an account of another entity is a 422).
4. The `accrual_date` falls in an existing, **`OPEN` period** (`ValidationException` when the calendar has no period for it, `ConflictException`, 409, when the period is closed).
5. The description is present (at most 500 characters).

Then, inside one transaction: the entity's posting lock is taken, the next gap-free number of the entity and fiscal year is assigned, the entry is sealed in the hash chain, and the balance cache is updated. Events are dispatched only after the commit (see [events.md](events.md)).

## Dates and the two bases

- `accrual_date` (required): when the fact happened. Periods and closing always use it.
- `cash_date` (optional): when money moved **with this entry**. Leave it empty on an entry that creates a receivable or payable and let a later settlement carry the cash. See [reports.md](reports.md#cash-and-accrual-basis).

## Numbering

Numbers are contiguous per entity and fiscal year, starting at 1, and assigned at posting, not at draft creation. The counter row lives in `acc_sequences` and is changed inside the posting transaction: if the posting fails or rolls back, the counter rolls back too, so a failure never leaves a hole. Concurrent postings are serialized per entity by a write lock taken at the start of the posting (`UPDATE` on the entity row, which locks the row on MySQL and the database on SQLite). The CI job `independence` starts 50 postings at once and fails if the numbers are not contiguous.

## Hash chain and integrity

Every posted entry stores `hash` and `previous_hash`. The hash is SHA-256 over everything that never changes after posting (identity, dates, description, source, user, lines) plus the previous hash; the status is left out, so `POSTED` becoming `REVERSED` keeps it valid. The chain runs per entity in posting order (`chain_position`).

```bash
php spark contabil:verify-integrity [--entity <id>]
```

reports an edited entry or line, a deleted entry, a broken link, a gap in the numbering and an unbalanced entry, and exits non-zero. A chain proves **internal consistency**: someone with full database access who rewrites the last entry and recomputes its hash cannot be caught from the database alone. To close that gap, copy the latest `hash` from the `EntryPosted` event to storage the database user cannot write to.

## Drafts

`POST /entries` creates a draft (or posts straight away with `"post": true`). `PUT|PATCH /entries/{id}` replaces a draft's content, `DELETE /entries/{id}` removes it, `POST /entries/{id}/post` posts it. Closing a period or a year needs every draft dated in it posted or deleted first.

## Idempotency

Send an `idempotency_key` (unique per entity) when a retry must not duplicate, for example `{system}:{table}:{id}:{version}`:

- Same key, same content: the stored entry is returned (HTTP 200, `created = false`), nothing is written and no event is published. If it is still a draft and `post` is set, it is posted.
- Same key, different content: `ConflictException` (409). The key is never silently reused.

## Reversal and correction

`POST /entries/{id}/reverse` with a mandatory `reason` posts a new entry with the same lines on the opposite sides, linked through `reversal_of_id`, and marks the original `REVERSED`.

- Allowed once per entry; a second attempt is a 409.
- A reversal cannot itself be reversed. To undo a reversal, post a new entry.
- An entry with active settlements cannot be reversed until the settlement entries are reversed first.
- The reversal is dated like the original by default. If that period is closed the request fails and nothing changes; pass `date` to post the reversal in an open period.
- To correct an entry: reverse it, then post the right one (give both the same `source_type` and `source_id` and a statement by source shows the whole story).

## Settlements

A settlement links a payment or collection entry to the earlier entry it settles, which is how the cash basis knows when revenue and expense happened. `POST /entries/{id}/settle` with `settled_entry_id` and `amount`:

- Both entries must be `POSTED` and neither can be a reversal.
- The settled entry must not have a `cash_date` of its own.
- Partial settlements are fine, up to the settled entry's total (its sum of debits). The settlement entry cannot apply more than its own total either.
- Reversing a settlement entry voids its settlement links.

## Batches

`POST /entries/batch` creates and posts up to 500 entries in one transaction. If any fails none is kept and the error names the entry (`Entry 2 of the batch: ...`) while keeping its exception type. With idempotency keys on each entry the whole batch can be retried safely.

## Periods

Creating a fiscal year generates 12 monthly periods ([chart-of-accounts.md](chart-of-accounts.md)).

- `POST /periods/{id}/close`: refused while drafts are dated in the period. A closed period accepts no entries.
- `POST /periods/{id}/reopen`: needs the `contabil.period.manage` permission and a `reason`, and emits `PeriodReopened`, which is the audit record. A period of a closed year cannot be reopened before the year is.

## Opening the books (opening balances)

An entity that already existed before it started using the package brings its balances in with one **opening entry** in its first fiscal year. `POST /fiscal-years/{id}/opening-balances`, the `OpeningBalances` use case (`service('contabil')->openingBalances()`) or `php spark contabil:opening-balances --file balances.csv`:

```json
{
  "balances": [
    {"account_code": "1.1.01", "side": "D", "amount": 1500000},
    {"account_code": "1.1.02", "side": "D", "amount": 320000},
    {"account_id": 12,         "side": "C", "amount": 410000}
  ],
  "balancing_account_id": 31
}
```

1. The year must be the entity's **first** fiscal year, still open, with its first period open. Later years need no opening entry (see below).
2. Each balance names an asset, liability or equity leaf account (`account_id` or `account_code`), once. Revenue and expense accounts start a year at zero, so they take no opening balance. Zero amounts are skipped.
3. Debits must equal credits. Otherwise the request is a 422 that states the difference, unless `balancing_account_id` names an active equity account that takes it (a suspense or "opening adjustments" account for the accountant to clear).
4. The balances are posted as one entry dated the **first day of the year**, `source_type` `contabil.opening-balances` and `source_id` the year id. It is an ordinary posted entry: numbered, in the hash chain and the balance cache, immutable. Being a movement of the first day, it shows in the trial balance of the first month as debits and credits, and as the opening column from the second month on.
5. Posted once. A second call is a 409 unless `"replace": true`, which reverses the entry in force and posts the new balances in the same transaction. Reversing the opening entry with `POST /entries/{id}/reverse` also lets you post it again.

`GET /fiscal-years/{id}/opening-balances` returns the opening entry in force (`null` when none). Posting needs `contabil.period.manage` and `contabil.entry.post`. Events: `EntryPosted` and `OpeningBalancesRecorded` (after `EntryPosted` and `EntryReversed` of the replaced entry, when replacing).

The CSV file of `contabil:opening-balances` has the header `account_code,side,amount` with the amount as a decimal (`1500.00`); a `.json` file holds the same list as the API, amounts in minor units.

## Opening a fiscal year

`POST /fiscal-years/{id}/open-next` (body: optional `label`) creates the year that starts the day after year `{id}` ends, with its twelve monthly periods, and emits `FiscalYearOpened`. It is a 409 when that year already exists or would overlap another.

- The previous year does **not** need to be closed first. Usually the next year opens on its first day, takes entries, and the previous year is closed weeks later, once its adjustments are in.
- **No entry is posted.** Balances in the ledger are cumulative, so asset, liability and equity accounts start the new year with their closing balance of the previous one, and every report reads it from there (the opening column of the trial balance, the opening balance of the general ledger). Revenue and expense accounts start from zero once the previous year is closed, because the closing entry moves their result to equity.
- The very first year of an entity is created with `POST /fiscal-years` (`start_date`), and its opening balances posted as above.

From the command line, `php spark contabil:open-year --start 2026-01-01` creates the first year and `php spark contabil:open-year` opens the year after the latest one.

## Closing a fiscal year

`POST /fiscal-years/{id}/close` (body: optional `result_account_id`):

1. Every period except the last must be closed, and no draft may be dated in the year.
2. The revenue and expense accounts are zeroed by one entry dated the last day of the year; the net result goes to the **result account**, an active equity account that takes entries. Set `resultAccountCode` in the configuration or pass `result_account_id`. A profit credits it, a loss debits it.
3. The last period and the year are closed. A year whose accounts already net to zero closes without an entry.

From the command line: `php spark contabil:close-year --year 2026 --result-account 3.2.01 [--close-periods] [--open-next]`; `--close-periods` closes the open periods of the year first and `--open-next` opens the next year afterwards when it does not exist yet.

`POST /fiscal-years/{id}/reopen` (body: `reason`) reverses the closing entry, opens the last period and the year again. It is refused while the **next** fiscal year is closed, because that year's opening balances depend on this one. The other periods stay closed; reopen them one by one if needed.

## Balance cache

`acc_balances` keeps opening balance, debits, credits and closing balance per account and period (debit positive). It is updated in the posting transaction, and a backdated entry shifts the later periods of that account. It is a cache: the lines are the source of truth.

```bash
php spark contabil:recalculate-balances [--entity <id>]
```

rebuilds it from the lines; the result is identical to the incremental one (a test posts backdated entries and compares). The trial balance reports `divergences` when the cache disagrees with the ledger.

## Permissions

| Permission | For |
|---|---|
| `contabil.entry.view` | list and read entries |
| `contabil.entry.create` | create, change and delete drafts |
| `contabil.entry.post` | post, create-and-post, batches, settlements |
| `contabil.entry.reverse` | reverse |
| `contabil.period.manage` | create and open fiscal years, close and reopen periods and years, post opening balances (with `contabil.entry.post`) |

Creating an entry with `"post": true` needs both `contabil.entry.create` and `contabil.entry.post`.
