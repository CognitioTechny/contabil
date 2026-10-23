# FAQ

**Does it work without a UI?** It has none, on purpose. It is a headless engine: use the JSON API or call `service('contabil')`. Screens belong to the host.

**Which databases?** Anything CodeIgniter's `Forge` supports; CI runs SQLite and MySQL on PHP 8.2, 8.3 and 8.4. The code uses no vendor-specific SQL. MySQL (InnoDB) or PostgreSQL are the right choice for concurrent writers; SQLite serializes writers.

**Why integers instead of decimals?** Floating point cannot represent most cents exactly, and `DECIMAL` returns strings that drift when mixed with floats. Every amount is an integer in minor units; convert at the edge with `Money::fromDecimal()` / `Money::format()`.

**Is there more than one currency?** One currency per entity. Multi-currency and consolidation across entities are out of scope for v1, and the schema does not prevent adding them later.

**Why can't I edit or delete a posted entry?** Because an auditable ledger cannot be rewritten. Reverse it (with a reason) and post the right one. Drafts can be edited and deleted freely.

**Can I reverse a reversal?** No; post a new entry. This keeps the history linear: original, reversal, replacement.

**How do I correct an entry in a closed period?** Reverse it with a `date` in an open period, post the replacement there, or reopen the period (`contabil.period.manage` and a reason, audited by `PeriodReopened`).

**Do entries get numbers at creation?** No, at posting, gap-free per entity and fiscal year. A failed posting does not use a number.

**What is the difference between cash and accrual here?** The ledger is the same. The basis only changes when the Income Statement recognizes revenue and expense: on the accrual date, or when cash moves (directly, or through settlements). The Trial Balance, Ledger and Journal never change. See [reports.md](reports.md#cash-and-accrual-basis).

**Why is the Balance Sheet accrual only?** A cash-basis balance sheet needs decisions (what counts as an asset on a cash basis) that depend on local rules. v1 keeps it accrual and rejects `basis=cash` explicitly.

**Is the generic chart "official"?** No. The seeds are starting points, not a national or professional standard. An accountant must review a chart before it is called official, and the same holds for the statements.

**Does it handle tax, payroll or bank reconciliation?** No. Out of scope for v1: tax, payroll and HR, frontend, country-specific filings, bank reconciliation, depreciation and fixed assets, multi-currency, consolidation.

**What happens when two requests post at the same time?** They are serialized per entity by a lock taken inside the posting transaction, so numbering and the hash chain stay consistent. CI starts 50 concurrent postings and fails on any gap.

**What if an event listener fails?** The accounting write stays; the exception is kept and available from `service('contabil')->eventFailures()`.

**Can I turn the HTTP API off?** Yes: `$routesEnabled = false`. The use cases and commands keep working.

**Can I change the table prefix?** Choose it before the first migration (`tablePrefix`, default `acc_`). Changing it afterwards means renaming tables by hand.

**How do I know nobody edited the database directly?** Run `contabil:verify-integrity`. It catches edits, deletions and reordering. Anchor the latest hash outside the database to also catch a rewritten tail ([entries.md](entries.md#hash-chain-and-integrity)).

**How do I test my adapters?** Extend the contract tests in `CognitioTechny\Contabil\Testing\Contract` ([ports.md](ports.md)), and run the independence check ([integration-guide.md](integration-guide.md#9-proving-it-works)).
