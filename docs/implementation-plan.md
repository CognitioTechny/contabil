# Accounting Module: Implementation Plan

Implementation plan for `cognitiotechny/contabil`: an **agnostic**, headless, double-entry accounting package that can be installed in any CodeIgniter 4 application. It follows `.agents/specs.md`; where an earlier draft diverged from it, the specs win.

> Status: **planning only.** All decisions in section 9 are closed. Tracked in issues #1 (epic) and #2–#6 (phases).

## 1. Goals and principles

Deliver a double-entry engine that any host installs as a Composer package and uses through interfaces (ports) and events. The package never depends on the host; the host depends on the package through its own adapter.

1. **Hexagonal (Ports & Adapters).**
   - `Domain`: plain PHP, no framework dependency (entities, value objects, double-entry rules, domain events).
   - `Application`: use cases and DTOs, depending only on interfaces.
   - `Infrastructure/CodeIgniter`: migrations, models, entities, repositories, services, API controllers, routes, `spark` commands.
2. **Headless.** No views, UI components or assets. Screens belong to the host and consume the API.
3. **No host references.** No host table, class, FK or `use`. Links to source documents are polymorphic and opaque: `source_type` + `source_id` + `idempotency_key`.
4. **Money is an integer in minor units (cents)**, through a `Money` value object. Never `float`. Conversion from/to `DECIMAL` is the host adapter's job.
5. **Real double entry:** N lines per journal entry, posted only when total debits = total credits.
6. **Immutable ledger:** a posted entry is never edited or deleted; corrections are reversals. A closed period accepts no entries.
7. **Multi-entity from v1** (`entity_id` everywhere).
8. **Cash or accrual basis, selectable by the user** (section 5.2).
9. **Configurable table prefix** (default `acc_`), built at migration time. Migrations ship with the package and are additive within a major version.
10. **Region-agnostic:** the core enforces no national standard or chart of accounts. Default charts are optional seeds.
11. **Database-agnostic:** CI4 `Forge`/`Migration` only; no vendor-specific features.
12. **Language:** all code, schema, PHPDoc and docs in English.
13. **SemVer**, CHANGELOG, and tests and CI inside the package.

## 2. Reconciliation with `specs.md`

| Topic | Earlier draft | `specs.md` | Decision |
|---|---|---|---|
| PHP | `^8.3` | 8.2+ | **`^8.2`** (confirmed); CI runs 8.2, 8.3, 8.4 |
| UI | Optional views, reference screens | None | **None.** Reference screens dropped |
| Standards | Country-specific seeds | No national standard | Seeds are optional, in `resources/charts/`, outside the core |
| Structure | Strict hexagonal | CI4 Models/Entities/Services | Hexagonal kept; CI4 Models/Entities/Services live under `Infrastructure/CodeIgniter` as adapters |
| Language | Portuguese identifiers | English | **English** everywhere |
| Reports | Ledger, Journal, Trial Balance, Income Statement, Balance Sheet | Trial balance + essential reports | Kept as read services; PDF behind an optional port |
| Out of scope | Tax, payroll | Tax, payroll, frontend | Kept (section 8) |

## 3. Architecture

```text
contabil/
├── composer.json            # cognitiotechny/contabil · php ^8.2 · psr/event-dispatcher
├── src/
│   ├── Domain/              # Account, JournalEntry, Line, FiscalYear, Period, Money, events
│   ├── Application/         # use cases, DTOs, Ports
│   └── Infrastructure/
│       └── CodeIgniter/     # Config, Database/Migrations, Models, Entities, Repositories,
│                            # Services, Controllers (API), Routes, Commands
├── config/                  # contabil.php
├── resources/charts/        # optional chart-of-accounts seeds
├── docs/                    # installation, ports, events, OpenAPI, integration guide
└── tests/                   # CodeIgniter/PHPUnit: unit, contract, database, feature
```

PSR-4 autoload `CognitioTechny\Contabil\`. `codeigniter4/framework` is `require-dev`/`suggest`; Domain and Application do not require it.

### Ports (each with a default implementation and a reusable contract test)

`CurrentUserInterface`, `AuthorizerInterface`, `CurrentEntityInterface`, `ClockInterface`, `EventDispatcherInterface` (PSR-14), `PdfRendererInterface` (optional), `StorageInterface` (optional).

### Host compatibility requirements

The design must work in hosts that have these traits, without naming any of them in code:

- CodeIgniter 4.7+, PHP 8.3, MySQL, an authentication provider of their own, string-based permissions.
- Legacy money stored as `DECIMAL(15,2)`: the decimal↔cents converter lives in the host.
- Opaque user and source identifiers (int or string): no foreign keys.
- Per-module activation: routes can be switched off by config (`contabil.routes.enabled`) and `AuthorizerInterface` accepts the `contabil.*` permission strings.
- Auditing and per-record privacy belong to the host. The package exposes domain events and `user_id` on every entry so the host can implement them.

## 4. Data schema (configurable prefix, default `acc_`)

| Table | Purpose | Main columns |
|---|---|---|
| `acc_entities` | Accounted entity | id, name, tax_id, currency, default_basis (`ACCRUAL`/`CASH`), config JSON |
| `acc_fiscal_years` | Accounting year | id, entity_id, label, start_date, end_date, status (`OPEN`/`CLOSED`) |
| `acc_periods` | Month in a year | id, fiscal_year_id, start_date, end_date, status (`OPEN`/`CLOSED`/`LOCKED`) |
| `acc_accounts` | Hierarchical chart | id, entity_id, code, name, type, nature, level, parent_id, accepts_entries, is_cash_account, active, reference_code (nullable); UNIQUE(entity_id, code) |
| `acc_entries` | Journal entry header | id, entity_id, number, accrual_date, cash_date (nullable), recorded_at, description, status (`DRAFT`/`POSTED`/`REVERSED`), reversal_of_id, source_type, source_id, idempotency_key, user_id, hash, previous_hash |
| `acc_entry_lines` | Debit/credit lines | id, entry_id, account_id, side (`D`/`C`), amount (BIGINT > 0), line_description, position |
| `acc_settlements` | Links a settlement entry to the entry it settles | id, settlement_entry_id, settled_entry_id, amount, date |
| `acc_balances` | Account × period cache, recomputable | account_id, period_id, opening_balance, total_debits, total_credits, closing_balance |
| `acc_posting_rules` | Optional source-event → accounts map for hosts | id, entity_id, event, condition JSON, debit_account_id, credit_account_id, active |
| `acc_schema_version` | Package schema version | version, applied_at |

Schema rules:

- All FKs are internal to the package.
- `amount` is an integer, with `CHECK (amount > 0)` where supported; the sign comes from `side`.
- No soft delete on posted entries; accounts can only be deactivated.
- Indexes: `(entity_id, accrual_date)`, `(entity_id, cash_date)`, `(account_id, entry_id)` on lines, `(source_type, source_id)`, UNIQUE `(entity_id, idempotency_key)`.
- `acc_balances` is a cache; the lines are the source of truth.
- The General Ledger has **no table of its own**: it is a query over lines.

## 5. Phases

Each phase becomes an issue in this repository (section 10). Order is strict: 1 → 2 → 3 → 4 → 5.

### 5.1 Phase 1: Package core

Composer skeleton, ports with default implementations, `config/contabil.php`, schema and migrations, commands `contabil:install`, `contabil:migrate`, `contabil:status`, a domain exception hierarchy (mapped to HTTP only in the API layer), and the architecture test (section 7).

Acceptance:
- `composer install` works in the package alone.
- Migrations go up and down on SQLite and MySQL.
- No `use` or FK pointing to host code.
- The architecture test fails if Domain/Application import `CodeIgniter\*`.
- `docs/ports.md` describes each contract with an example.

### 5.2 Accounting basis: cash or accrual (user-selectable)

The ledger is always double entry and always recorded on the accrual date. The basis is a **recognition and reporting choice**, not a different ledger.

- **Where it is chosen:** `acc_entities.default_basis` (per entity) and a `basis` parameter on every report and on the API (`?basis=accrual|cash`), which overrides the default.
- **Dates on an entry:** `accrual_date` (when the fact happened; required) and `cash_date` (when money moved; null until it does).
- **Settlements:** an entry that pays or collects an earlier one is linked to it through `acc_settlements` (partial settlements allowed). An entry that moves cash directly (debit expense, credit a cash account) has `cash_date` = its own date and needs no link.
- **Cash accounts:** accounts flagged `is_cash_account` (cash, bank) define what counts as cash movement.
- **Accrual reports:** use `accrual_date` and all posted entries.
- **Cash reports:** revenue and expense are recognized on the settlement date, in proportion to the settled amount of the original entry; direct cash entries count on their own date.
- **Unaffected by basis:** the General Ledger, Journal and Trial Balance always reflect the raw ledger. Basis only changes the Income Statement and, optionally, the Balance Sheet (see open decisions).
- **Period closing** is always on `accrual_date`; settling in a later period is allowed.

Acceptance: a reference dataset (credit sale/purchase, partial and full settlement, direct cash entry, reversal) yields hand-checked Income Statements under both bases; switching the basis never changes the Trial Balance.

### 5.3 Phase 2: Chart of accounts and calendar

- **Account invariants:** unique code per entity with a configurable mask per level; only leaf (analytic) accounts accept entries; nature derives from type, with contra accounts; a child inherits its parent's type; moving cannot create a cycle; an account with movement cannot be deleted or change type/nature, only deactivated; a leaf becomes a group only with no movement.
- **Fiscal year and periods:** creating a year generates 12 monthly periods; `OPEN` → `CLOSED` with controlled reopening; no overlap within an entity.
- **Use cases:** `CreateAccount`, `UpdateAccount`, `MoveAccount`, `DeactivateAccount`, `ReactivateAccount`, `GetAccountTree`, `ImportChart` (CSV/JSON, all-or-nothing, per-row errors), `ExportChart`, `CreateFiscalYear`, `ListPeriods`.
- **Optional seeds:** a generic chart and a chart for professional-services firms that hold client funds segregated from the entity's own equity; idempotent (`contabil:seed-chart --model=`). An accountant must review any chart before it is called "official".
- **API:** `/accounts`, `/accounts/{id}/move`, `/accounts/{id}/deactivate`, `/accounts/tree`, `/accounts/import`, `/accounts/export`, `/fiscal-years`, `/fiscal-years/{id}/periods`. Permissions: `contabil.chart.view`, `contabil.chart.edit`, `contabil.period.manage`. Contract in `docs/openapi.yaml`.

Acceptance: invariants covered by database-free unit tests; seeding an empty entity yields a valid tree; creating a year yields 12 gap-free periods; the API works with a fake `AuthorizerInterface` in an empty project.

### 5.4 Phase 3: Entry engine

- **Rules:** at least 2 lines and total debits = total credits (in cents); lines on leaf, active accounts of the same entity; `accrual_date` in an `OPEN` period; description required; gap-free sequential numbering per entity and fiscal year, assigned at posting and safe under concurrency; states `DRAFT` → `POSTED` → `REVERSED`; reversal creates an inverted entry with `reversal_of_id` and a mandatory reason, once per original; correction = reversal + new entry; idempotency by `idempotency_key`; atomic batches; per-entity hash chain with `contabil:verify-integrity`; settlements recorded through `acc_settlements`.
- **Periods and year:** `ClosePeriod` requires no pending drafts; `ReopenPeriod` needs permission and emits an audit event; `CloseFiscalYear` posts a result-closing entry to a configurable result account, reversible while the next year is open.
- **Balances:** cache in `acc_balances` updated in the same transaction; `contabil:recalculate-balances`.
- **PSR-14 events:** `EntryPosted`, `EntryReversed`, `EntrySettled`, `PeriodClosed`, `PeriodReopened`, `FiscalYearClosed`.
- **API:** `/entries` (draft CRUD, `/post`, `/reverse`, `/batch`), `/periods/{id}/close|reopen`, `/fiscal-years/{id}/close`. Errors: unbalanced 422, closed period 409, repeated idempotency key 200 with the existing entry. All writes transactional.

Acceptance:
- Invalid cases are rejected with no side effects.
- A posted entry cannot be changed by any path.
- A reversal cancels the original's effect exactly; a second reversal is rejected.
- 50 concurrent postings produce contiguous numbering.
- Resending an idempotency key does not duplicate.
- `recalculate-balances` reproduces `acc_balances`.
- `verify-integrity` detects a manual `UPDATE`.
- Domain and Application are tested without a database or CodeIgniter.

Risks: gap-free numbering under concurrency (counter row with `SELECT … FOR UPDATE` vs sequence table; decide for MySQL and SQLite); year closing and reopening may need its own sub-issue.

### 5.5 Phase 4: Books and statements

Per entity and period, posted entries only, as immutable DTOs independent of format. Every report accepts `basis`, except those marked "raw".

| Report | Key rule |
|---|---|
| General Ledger (raw) | One account or a range; running balance; group accounts aggregate children; cursor pagination |
| Journal (raw) | Chronological/numeric order with lines |
| Trial Balance (raw) | Opening, debits, credits, closing; total debits = total credits; flags cache divergence |
| Income Statement | Structure derived from the chart, no hard-coded coefficients; honors `basis` |
| Balance Sheet | Assets = Liabilities + Equity; contra accounts signed correctly; basis handling per open decision |
| Statement by source | Entries by `source_type` + `source_id` |

Formats: JSON, CSV and PDF (PDF through `PdfRendererInterface`; the library stays in `suggest`). API under `/reports/*` with `contabil.reports.view` and `contabil.reports.export`. Sign convention and zero-rounding documented.

Acceptance: a reference dataset (30+ entries, with a reversal and a contra account) is checked by hand and versioned as tests; Trial Balance and Balance Sheet always balance; the last running balance of the Ledger equals the Trial Balance closing balance; a group account equals the sum of its children; a 10k-entry paginated Ledger meets the target (to be set, e.g. under 2 s); CSV/PDF match the JSON; reports never write and work on a read replica.

### 5.6 Phase 5: Distribution and independence proof

Any host adapter lives **outside** this repository. The package delivers:

- A ports contract test any host can run against its adapter.
- An integration guide: implementing the ports, using `acc_posting_rules`, `idempotency_key` (e.g. `{system}:{table}:{id}:{version}`), reversal + re-post instead of editing, a pending queue when no rule matches, and centralized money conversion.
- A legacy data migration guide (reconciliation by count and sum per account, `--dry-run`, abort on divergence).
- CI (GitHub Actions): PHP 8.2/8.3/8.4 × SQLite and MySQL, running the CodeIgniter-bundled PHPUnit, plus the CodeIgniter coding standard.
- **Independence job (release gate):** creates an empty CI4 app, installs the package via Composer, runs `contabil:install`, uses default ports, creates a chart, posts, reverses, closes a period and emits the Ledger and Trial Balance.
- Docs in `docs/`: installation, configuration, ports, events, posting rules, OpenAPI, migration, FAQ, versioning and breaking-change policy.
- `README` (3 commands), `CHANGELOG`, `CONTRIBUTING`, tag `v1.0.0`.

## 6. Target installation

```bash
composer require cognitiotechny/contabil
php spark contabil:install      # publishes config and runs migrations
# register the host adapter (ports) in configuration
```

## 7. Testing strategy

Tests use the PHPUnit and test support **already shipped with CodeIgniter 4** (`CIUnitTestCase`, `DatabaseTestTrait`, `FeatureTestTrait`, the framework's `phpunit.xml.dist` conventions). No other test framework or architecture tool is added.

| Layer | Test type | Base class / trait | Database |
|---|---|---|---|
| Domain, Application | Unit | `CIUnitTestCase` (or plain `TestCase`) | None |
| Ports | Contract (reusable by hosts) | `CIUnitTestCase` | None |
| Infrastructure | Database | `CIUnitTestCase` + `DatabaseTestTrait` | SQLite in memory (CI also MySQL) |
| API | Feature | `FeatureTestTrait` | SQLite in memory |
| Architecture | Dependency rule | `CIUnitTestCase` | None |
| Whole package | Independence (empty app) | CI job, runs the same PHPUnit | SQLite |

The architecture rule is a plain PHPUnit test that scans `src/Domain` and `src/Application` and fails on any `use` of `CodeIgniter\*` or any host namespace, so no extra dependency is needed.

## 8. Out of scope (v1)

Tax management, payroll and HR, frontend UI, country-specific filings, bank reconciliation, depreciation and fixed assets, multi-currency, consolidation across entities. The architecture must not prevent any of them.

The module is a bookkeeping tool, not an accounting opinion. Charts of accounts and statement templates need accountant validation before official use.

## 9. Open decisions

- [x] Minimum PHP: `^8.2`.
- [x] Language: English.
- [x] Cash and accrual basis both available, chosen by the user (section 5.2).
- [x] Tests: PHPUnit bundled with CodeIgniter.
- [x] **Balance Sheet under cash basis:** accrual only in v1.
- [x] **Partial settlements:** proportional recognition (settled ÷ original, rounded in cents, residual on the final settlement).
- [x] **Gap-free numbering:** counter row locked inside the posting transaction (`SELECT ... FOR UPDATE` on MySQL; immediate write transaction on SQLite).
- [x] **License:** MIT.
- [x] **Seeds:** optional charts shipped in the package (`resources/charts/`).
- [x] **Static analysis:** CodeIgniter coding standard only in v1; PHPStan can be proposed later.

## 10. Suggested backlog (issues to create in this repository)

1. `[Epic]` Agnostic accounting module: architecture, scope and roadmap (hub for the phases below)
2. `[Phase 1]` Package core: Composer, ports, configuration and schema
3. `[Phase 2]` Chart of accounts and fiscal calendar
4. `[Phase 3]` Entry engine: double entry, reversal, settlement, closing and balances
5. `[Phase 4]` Books and statements, with cash/accrual basis
6. `[Phase 5]` Distribution, CI and independence proof

Each issue repeats the matching section of this document (scope, tasks, acceptance criteria, risks).

## 11. Definition of done for the epic

- [ ] A new, empty CodeIgniter 4 app installs the package, migrates, creates a chart, posts, reverses, closes a period and emits reports under both bases, verified in CI.
- [ ] The architecture test stops Domain/Application from importing `CodeIgniter\*`.
- [ ] Installation, ports, events and API docs ship with the package.
- [ ] Release `v1.0.0` with CHANGELOG.
