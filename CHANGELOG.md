# Changelog

## Unreleased

### Added

- Phase 2 chart of accounts and fiscal calendar: account invariants (unique codes with an optional per-level mask, leaf and group, nature from type with contra accounts, inherited type, cycle-free moves, movement protection), fiscal years that generate 12 gap-free monthly periods, the use cases `CreateAccount`, `UpdateAccount`, `MoveAccount`, `DeactivateAccount`, `ReactivateAccount`, `DeleteAccount`, `GetAccountTree`, `ImportChart` (all or nothing, per-row errors), `ExportChart`, `CreateFiscalYear`, `ListPeriods` and `CreateEntity`, the optional `generic` and `professional-services` seeds, `contabil:seed-chart` and `contabil:create-entity`, the `/accounts` and `/fiscal-years` API with `docs/openapi.yaml`, the `accountCodeMask` setting, and the `contabil*` services through which hosts register their port adapters.
- Phase 1 package core: Composer skeleton, ports with default implementations and reusable contract tests, configuration, versioned schema migrations, `contabil:install`, `contabil:migrate` and `contabil:status`, the domain exception hierarchy with its HTTP mapping, and the architecture test.
