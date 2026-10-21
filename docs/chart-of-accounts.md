# Chart of accounts and fiscal calendar

Phase 2 of the package: the accounts an entity posts to, and the fiscal years and periods it posts in. Nothing here depends on the entry engine (phase 3); an account "has movement" when any row of `acc_entry_lines` points to it.

## Account rules

| Rule | Behavior |
|---|---|
| Code | Unique per entity (`ConflictException` otherwise), at most 50 characters, no spaces. With `accountCodeMask` set it must match the mask for the account's level. |
| Leaf and group | Only a leaf (`accepts_entries = true`) takes entries. Giving a leaf a child turns it into a group, but only if it has no movement. A group with children cannot be turned back into a leaf. |
| Type and nature | Types are `ASSET`, `LIABILITY`, `EQUITY`, `REVENUE`, `EXPENSE`. Assets and expenses are `DEBIT` by nature; the rest are `CREDIT`. A contra account takes the opposite nature of its type (accumulated depreciation is an asset with `CREDIT` nature). `contra` is derived from the stored nature; there is no separate column. |
| Inheritance | A child takes its parent's type. Leave `type` empty to inherit it; a different type is rejected. |
| Cash account | `is_cash_account` is only valid on an asset leaf. A group is never a cash account. |
| Move | Under another parent or to the root. Rejected if it creates a cycle, if the new parent has another type, is inactive, or is a leaf with movement. The levels of the whole subtree follow. With a mask, the moved account's code (pass a new one if needed) and every descendant's code must still fit the mask at the new level. |
| Movement | An account with movement keeps its type and nature, cannot become a group and cannot be deleted. It can be renamed, recoded and deactivated. |
| Deactivate | A group is deactivated only after its children. Reactivating needs an active parent. Repeating either is harmless. |
| Delete | Only an account with no children and no movement. |

Violations of a rule about the data itself raise `ValidationException` (422); violations caused by the current state, such as a taken code or existing movement, raise `ConflictException` (409). See [errors.md](errors.md).

### Code mask

`accountCodeMask` (config) describes one segment per level, for example `9.9.99.999`: a level-3 code looks like `1.1.01`, and the chart is at most four levels deep. `9` is a digit, `A` a letter, `X` a letter or digit; the only other character is the separator. Without a mask any code is accepted at any depth.

## Fiscal years and periods

- Creating a year takes a start date that is the **first day of a month** and generates 12 monthly periods, without gaps, ending the last day of the 12th month. The label defaults to `2026`, or `2026/2027` when the year does not start in January.
- Years of one entity never overlap and labels are unique (`ConflictException`). Different entities have independent calendars.
- Period states: `OPEN` → `CLOSED` → `OPEN` (reopen) or `LOCKED` (final). A year closes only when no period is `OPEN`, and a closed year can be reopened. These transitions live in the domain (`Period`, `FiscalYear`); the use cases that apply them (`ClosePeriod`, `ReopenPeriod`, `CloseFiscalYear`) arrive with the entry engine in phase 3.

## Entities

A chart and a calendar belong to an entity. Create one with `php spark contabil:create-entity --name "Acme Ltd" [--tax-id ...] [--currency EUR] [--basis CASH]` or with the `CreateEntity` use case, and use the printed id in `defaultEntityId` or `--entity`.

## Import and export

`ImportChart` reads CSV or JSON rows; `ExportChart` writes the same layout, parents before children, so an export imports into an empty entity as it is.

| Column | Required | Notes |
|---|---|---|
| `code` | yes | |
| `name` | yes | |
| `type` | roots only | Children inherit it when empty. |
| `parent_code` | no | Code of an existing account or of another row. Rows may come in any order. |
| `contra`, `accepts_entries`, `is_cash_account` | no | `1`/`0`, `true`/`false`, `yes`/`no`. |
| `reference_code` | no | Free text, for example a code of an external standard. |

CSV has a header row (column order is free, a UTF-8 BOM is tolerated). JSON is a list of objects or `{"accounts": [...]}`.

The import is **all or nothing**: every row goes through the same rules as `CreateAccount`, errors are collected per row, and one bad row rolls back the whole import. The failure is a `ChartImportException` (422) whose `errors()` lists `{row, code, message}` with `row` counting data rows from 1; the API returns them in `error.details`. With `skip_existing` (API) or `skipExisting` (use case), a row whose code already exists is left alone instead of rejected, which makes an import repeatable.

## Optional seeds

```bash
php spark contabil:seed-chart --model generic --entity 1
php spark contabil:seed-chart --model professional-services --entity 1
```

| Model | For |
|---|---|
| `generic` | Any entity: five classes, current and non-current groups, a result account and contra accounts. |
| `professional-services` | Firms that hold client money: client funds sit in their own asset group (`1.2`, with the trust bank accounts flagged as cash) and liability group (`2.2`), never in equity. |

Seeding is idempotent: running it again creates nothing. The files in `resources/charts/` are plain JSON in the import layout, so a host can copy and adapt them. They are not a national or official chart; **an accountant must review a chart before it is called official.** If a code mask is configured, the seeded codes (`1`, `1.1`, `1.1.01`) must fit it or the seed is rejected row by row.

## API

All routes live under `apiRoute` (default `contabil`), answer JSON as `{"data": ...}` (errors as `{"error": {"type", "message", "details"?}}`) and work on the current entity from `CurrentEntityInterface`; `entity_id` in the query string is used only when that port returns `null`. The full contract is in [openapi.yaml](openapi.yaml).

| Route | Permission |
|---|---|
| `GET /accounts`, `/accounts/tree`, `/accounts/{id}`, `/accounts/export` | `contabil.chart.view` |
| `POST /accounts`, `PUT\|PATCH\|DELETE /accounts/{id}`, `POST /accounts/{id}/move\|deactivate\|reactivate`, `POST /accounts/import` | `contabil.chart.edit` |
| `GET /fiscal-years`, `GET /fiscal-years/{id}/periods` | `contabil.chart.view` |
| `POST /fiscal-years` | `contabil.period.manage` |

Out of the box the API answers 403 to everything: the default authorizer used by the HTTP layer denies all. Register the host adapter as described in [ports.md](ports.md#registering-adapters).
