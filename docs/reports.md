# Books and statements

Reports are read services over the ledger: they only read, never write, and work on a read replica. They list **posted and reversed entries only**; drafts never appear. Each report is an immutable object (`Report`) that renders as JSON, CSV or PDF from the same data.

| Report | Route | Basis |
|---|---|---|
| General Ledger | `GET /reports/ledger` | raw |
| Journal | `GET /reports/journal` | raw |
| Trial Balance | `GET /reports/trial-balance` | raw |
| Income Statement | `GET /reports/income-statement` | honours `basis` |
| Balance Sheet | `GET /reports/balance-sheet` | accrual only |
| Statement by source | `GET /reports/by-source` | raw |

*Raw* reports show the ledger exactly as posted; passing `basis` to them changes nothing, and switching the basis never changes the Trial Balance.

Permissions: `contabil.reports.view` for everything, and `contabil.reports.export` as well for `format=csv|pdf`.

## Cash and accrual basis

The ledger is always double entry, recorded on the accrual date. The basis is how the Income Statement recognizes revenue and expense:

- **Accrual** (default `ACCRUAL`): by `accrual_date`, all posted entries.
- **Cash**: revenue and expense count when cash moves.
  - An entry with a `cash_date` counts **in full on that date** (a cash sale, rent paid on the spot).
  - Any other entry counts **through its settlements**: each settlement recognizes `line amount × settled so far ÷ entry total`, rounded half away from zero, less what earlier settlements already recognized. The last settlement therefore carries the rounding residual and the full amount is always recognized. Example: a sale of 7 revenue and 3 tax settled in two payments of 5 recognizes 4 and then 3.
  - An entry never settled is not recognized; settlements of a reversed settlement entry are void.

The basis comes from `?basis=accrual|cash`, else the entity's `default_basis`. The Balance Sheet is accrual only in this version; `basis=cash` answers 501.

## Sign convention and rounding

- All amounts in JSON are **integers in minor units**. CSV and PDF show decimal strings with two decimals (`1055.00`); zero is always `0.00`, never `-0.00`.
- **Trial Balance**: signed with debit positive (`opening`, `closing`); the CSV splits them into debit and credit columns. Total debits equal total credits, and the opening and closing columns each balance.
- **General Ledger**: the running balance is in the account's own nature, so a positive number is on the account's normal side (debit for assets and expenses, credit for liabilities, equity and revenue). A group account rolls up its descendant leaf accounts.
- **Income Statement and Balance Sheet**: every amount is the account's **contribution to its section**, positive when it increases the section total. A contra account (for example sales returns under revenue) is therefore negative inside its parent. A group is the sum of its children. Sections come from the chart itself; nothing is hard-coded, so any chart produces a valid statement.
- **Balance Sheet** `current_result` is revenue less expense not yet closed to equity, so assets always equal liabilities plus equity; after a fiscal year is closed it moves into equity and `current_result` is zero.
- Rounding happens in exactly one place, the proportional recognition of the cash basis. Everything else is integer addition.

## General Ledger

`GET /reports/ledger?account_id=…&from=…&to=…&limit=100&cursor=…`

One account, paginated in ledger order (accrual date, entry, line) with a running balance that starts at the opening balance before `from`. `next_cursor` is `null` on the last page; pages chain without gaps or repeats and carry the running balance across. A code range, `from_code` and `to_code`, gives one section per leaf account and is not paginated (capped at 5,000 rows; narrow the range beyond that).

## Journal

`GET /reports/journal?from=…&to=…&limit=100&cursor=…` lists entries by accrual date then number, with their lines and account names.

## Trial Balance

`GET /reports/trial-balance?from=…&to=…[&include_zero=1]`

`opening` is everything before `from`. `balanced` is true when debits equal credits. `divergences` lists accounts whose balance cache differs from the ledger at `to`; it is `null` when `to` is not the last day of a period, because the cache cannot answer for part of a period. Run `contabil:recalculate-balances` to repair a divergence.

## Income Statement

`GET /reports/income-statement?from=…&to=…&basis=accrual|cash` returns `revenue` and `expenses` trees plus `totals`: `revenue`, `expenses`, `net_result`. Zero branches are hidden unless `include_zero=1`.

## Balance Sheet

`GET /reports/balance-sheet?as_of=…` returns `assets`, `liabilities`, `equity` trees and `totals` including `current_result`, `liabilities_and_equity` and `difference` (always 0 when the ledger balances).

## Statement by source

`GET /reports/by-source?source_type=invoice&source_id=1042` lists every entry that carries that source, reversals included, in posting order.

## Formats

- `json` (default): `{"data": …}` with minor units.
- `csv`: RFC 4180, UTF-8, CRLF, decimal amounts, header row. Cells that start with `=`, `+` or `@` are prefixed with an apostrophe so a spreadsheet does not run them.
- `pdf`: the report as an HTML table passed to `PdfRendererInterface`. The package bundles no PDF library; until the host registers one (`contabilPdfRenderer` service) the answer is 501. See [ports.md](ports.md).

CSV and PDF are built from the same table, so their numbers match the JSON.

## Size

A ledger of 10,000 entries paginates in well under the 2-second target: the first page, a page deep in the ledger and the trial balance are each timed in `ReportsDatabaseTest`. Ledger queries use the `(account_id, entry_id)` index.
