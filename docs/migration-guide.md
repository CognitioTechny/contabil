# Migrating legacy data

Moving an existing accounting history into the package. The package does not ship a converter, because every legacy schema differs; it ships the safe pieces (idempotent, atomic batches, integrity checks) and this procedure. Do it in a copy of the database first.

## Principles

1. **Never partially load.** A run either leaves the ledger exactly as the legacy system had it, or leaves nothing.
2. **Reconcile before trusting.** Compare count and sum per account between the legacy data and the package before you switch.
3. **Be repeatable.** Use idempotency keys so a failed run can be repeated without duplicates.
4. **Keep the history readable.** One legacy movement becomes one entry, tied back to it by `source_type` and `source_id`.

## Procedure

### 1. Prepare the structure

```bash
php spark contabil:install
php spark contabil:create-entity --name "Acme Ltd" --currency EUR
php spark contabil:seed-chart --model generic --entity 1     # or import your own chart
```

Import the legacy chart with `POST /accounts/import` (all-or-nothing, errors per row) or the `ImportChart` use case, mapping legacy codes to package codes and keeping the legacy code in `reference_code`. Create a fiscal year for every year you will load. If you do not load the full history, start from the balances instead: create only the first year you will keep and post the legacy closing balances of the year before as its opening balances (`contabil:opening-balances`, see [entries.md](entries.md#opening-the-books-opening-balances)).

### 2. Choose the period state

Load closed years while their periods are **open**, then close them at the end (`ManagePeriod::close`, or `ManageFiscalYear::close` per year). Loading into a closed period is refused on purpose.

### 3. Load in atomic batches

Convert each legacy movement to an `EntryData` (money through `Money::fromDecimal`, never a float) and post with `postBatch()`. Use a stable key: `legacy:{table}:{id}:1`.

```php
use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Domain\Money;

$uc      = service('contabil');
$dryRun  = in_array('--dry-run', $params, true);
$batch   = [];

foreach ($legacy->movements($cursor) as $row) {
    $cents   = Money::fromDecimal($row->amount)->minor;
    $batch[] = new EntryData(
        $row->date,
        $row->description,
        [
            ['account_id' => $map[$row->debit_account],  'side' => 'D', 'amount' => $cents],
            ['account_id' => $map[$row->credit_account], 'side' => 'C', 'amount' => $cents],
        ],
        $row->paid_on,                 // cash_date, when money moved with this movement
        'legacy',
        (string) $row->id,
        "legacy:movements:{$row->id}:1",
    );

    if (count($batch) === 500) {
        $uc->postBatch()->execute($entity, $batch);
        $batch = [];
    }
}
```

`--dry-run`: run the same loop inside a database transaction that you roll back at the end (`$db->transBegin()` … `$db->transRollback()`), and print the reconciliation below instead of committing. The batches join your outer transaction, so nothing is kept.

Link payments to what they paid with `settleEntry()` when the legacy data records it; that is what lets the cash basis report correctly.

### 4. Reconcile

Per account, and in total, compare the legacy figures with the package:

```php
$tb = $uc->trialBalance()->execute($entity, new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2026-12-31'));

foreach ($tb->rows as $row) {
    if ($row['is_group']) {
        continue;
    }

    $legacyDebits  = Money::fromDecimal($legacyTotals[$row['code']]['debits'])->minor;
    $legacyCredits = Money::fromDecimal($legacyTotals[$row['code']]['credits'])->minor;

    if ($row['debits'] !== $legacyDebits || $row['credits'] !== $legacyCredits) {
        throw new RuntimeException("Account {$row['code']} differs");
    }
}
```

Also compare the **count** of entries (`GET /entries?source_type=legacy` pages, or a `COUNT(*)` on `acc_entries`). **Abort on any divergence**: roll back (dry run) or restore the copy, fix the mapping, repeat. Because of the keys, repeating a committed run adds nothing; a partially committed batch cannot exist.

### 5. Verify and close

```bash
php spark contabil:recalculate-balances --entity 1
php spark contabil:verify-integrity --entity 1
```

Then close the loaded periods and years. For each year-end, the package posts the closing entry itself; if the legacy ledger already contains one, load it as a plain entry and close the year with no revenue or expense left (a year that nets to zero closes without a second entry).

### 6. Freeze the legacy side

Keep the legacy data read-only for a retention period, with the reconciliation output archived next to it.

## Things that bite

- **Rounding**: legacy ledgers sometimes keep three or four decimals. `Money::fromDecimal` refuses extra digits; round deliberately, in your converter, and reconcile the rounding difference to an account.
- **Unbalanced legacy movements**: the package will not accept them. Park them in a suspense account with an explicit balancing line and list them for the accountant.
- **Backdated numbering**: numbers are assigned at posting, in the order you load. If legacy document numbers matter, keep them in `source_id` or the description; do not expect the entry number to match.
- **Dates**: `accrual_date` is when the fact happened. Do not use the import date.
