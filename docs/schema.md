# Database schema

Table names below use the default `acc_` prefix. Table, index and foreign key names are all built from the configured prefix at migration time.

Money columns are `BIGINT` in minor units. `user_id`, `source_type` and `source_id` are opaque host identifiers and have **no** foreign key; every other foreign key points to another package table.

| Table | Purpose |
|---|---|
| `acc_entities` | Accounted entity; `default_basis` is `ACCRUAL` or `CASH`. |
| `acc_fiscal_years` | Fiscal year per entity (`OPEN`/`CLOSED`). |
| `acc_periods` | Periods of a year (`OPEN`/`CLOSED`/`LOCKED`), unique by `(fiscal_year_id, start_date)`. |
| `acc_accounts` | Hierarchical chart; unique `(entity_id, code)`; `is_cash_account` marks cash and bank accounts. |
| `acc_entries` | Journal entry header with `accrual_date`, `cash_date`, `idempotency_key`, `hash`, `previous_hash`. |
| `acc_entry_lines` | Lines: `amount` > 0 plus `side` `D`/`C`; the General Ledger is a query over this table. |
| `acc_settlements` | Links a settlement entry to the entry it settles (partial settlements allowed). |
| `acc_balances` | Account × period cache (opening, debits, credits, closing; debit positive); recomputable with `contabil:recalculate-balances`, never the source of truth. |
| `acc_posting_rules` | Optional map from a host source event to a debit/credit account pair. |
| `acc_sequences` | Gap-free entry counter per entity and fiscal year, locked inside the posting transaction. |
| `acc_schema_version` | One row per applied schema version. |

`acc_entries` also carries `fiscal_year_id` and `number` (both null until posting, so the number is unique per `(entity_id, fiscal_year_id, number)`), `chain_position` (the entry's place in the entity's hash chain, set when posted, unique per entity), and `reversal_of_id` / `reversal_reason` for reversals.

## Indexes

- `acc_entries`: `(entity_id, accrual_date)`, `(entity_id, cash_date)`, `(source_type, source_id)`, UNIQUE `(entity_id, idempotency_key)`, UNIQUE `(entity_id, fiscal_year_id, number)`, UNIQUE `(entity_id, chain_position)`.
- `acc_entry_lines`: `(account_id, entry_id)`, `(entry_id, position)`.
- `acc_accounts`: UNIQUE `(entity_id, code)`.

## CHECK constraints

`amount > 0`, `side IN ('D','C')` and the status/basis value lists are added as `CHECK` constraints on MySQL/MariaDB (8.0.16+/10.2.1+) and PostgreSQL. SQLite cannot add them after table creation, and older MySQL ignores them; the domain layer validates the same rules, so a skipped constraint is never fatal.

## Versioning and migration policy

- Migrations ship with the package and run in their own namespace (`CognitioTechny\Contabil\Infrastructure\CodeIgniter`); the host's own migrations are untouched.
- Within a major version the schema only grows: new tables, nullable or defaulted columns, new indexes. Nothing is dropped or renamed before the next major version.
- Every migration that changes the schema bumps `SchemaVersion::CURRENT` and records it in `acc_schema_version`.
- Every `down()` is tested, on SQLite in CI and on MySQL via the `database.tests.*` environment variables in `phpunit.xml.dist`.
