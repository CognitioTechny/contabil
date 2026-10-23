# Versioning and breaking changes

The package follows [Semantic Versioning](https://semver.org). The version in a release tag (`v1.2.3`) applies to the PHP API, the HTTP API and the database schema together.

## What counts as public

Public, and covered by the policy:

- The classes and interfaces under `Application\Ports`, `Application\Defaults`, `Domain`, and the use cases and report builders in `Application` (constructor signatures excluded for classes only the package wires itself, see below).
- `service('contabil')` and the methods of `UseCases`.
- The domain events and their properties.
- The HTTP API in [openapi.yaml](openapi.yaml): paths, parameters, response shapes, status codes and permission strings.
- The `contabil:*` commands and their options and exit codes.
- The configuration properties of `Config\Contabil`.
- The database schema: tables, columns, indexes and constraints that [schema.md](schema.md) documents.
- The contract tests in `Testing\Contract`.

Not public: anything under `Infrastructure` other than the above, the test support in `tests/`, and the internal collaborators the package wires itself (`EntryPoster`, `EntryRecorder`, `EntryReverser`, the `Db*` repositories). They can change in any release.

## What each kind of release may contain

| Release | May contain |
|---|---|
| Patch (1.0.x) | Bug fixes, documentation, performance, with no change to behaviour that was documented. |
| Minor (1.x.0) | Backward-compatible additions: new use cases, report fields, endpoints, optional parameters, events, commands, configuration properties with defaults, **additive** migrations. |
| Major (x.0.0) | Anything that breaks the above: removing or renaming a public class, method, route, field, permission or column; changing a documented behaviour; raising the minimum PHP or CodeIgniter version. |

## Schema policy

Within a major version the schema only grows: new tables, new nullable or defaulted columns, new indexes. Nothing is dropped, renamed or made stricter. Every migration that changes the schema raises `SchemaVersion::CURRENT` and records it in `acc_schema_version`, and every `down()` is tested. Hosts upgrade with `composer update` and `php spark contabil:migrate`.

## Adding a field to a response

Clients must ignore fields they do not know. New fields in JSON responses, new keys in a report and new properties on an event are minor changes.

## Deprecation

A feature to be removed is first marked `@deprecated` in the code and listed under **Deprecated** in the [CHANGELOG](../CHANGELOG.md) for at least one minor release, with the replacement named. It is removed only in the next major version.

## Supported versions

Fixes land in the latest release of the latest major. Whether an older major is maintained is announced with the release of the next one.

## Platform support

The minimum PHP and CodeIgniter versions are part of the contract: raising them is a major change. CI covers PHP 8.2, 8.3 and 8.4, SQLite and MySQL.

## Reporting a breaking change you did not expect

Open an issue with the version you upgraded from and to. If the behaviour was documented, it is a bug and gets fixed in a patch release.
