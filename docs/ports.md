# Ports

The package never reaches into the host. Everything it needs from the outside goes through the interfaces in `CognitioTechny\Contabil\Application\Ports`. The host implements them in its own adapter; the package ships a default for each, so it also runs in an empty project.

General rules for every port:

- Implementations must not throw for ordinary answers (unknown permission, no user, no entity). Only the exceptions documented below are expected.
- Identifiers (`user`, `entity`) are opaque `int|string|null`. The package stores them but never joins them to a host table.
- No port call happens inside a database transaction unless stated, so a slow adapter cannot hold a lock.

| Port | Required | Default | Contract test |
|---|---|---|---|
| `CurrentUserInterface` | yes | `StaticCurrentUser` (no user) | `CurrentUserContractTest` |
| `AuthorizerInterface` | yes | `AllowAllAuthorizer` (console and tests only) | `AuthorizerContractTest` |
| `CurrentEntityInterface` | yes | `StaticCurrentEntity` (from `defaultEntityId`) | `CurrentEntityContractTest` |
| `ClockInterface` | yes | `SystemClock` (UTC) | `ClockContractTest` |
| `EventDispatcherInterface` | yes | `NullEventDispatcher` | `EventDispatcherContractTest` |
| `PdfRendererInterface` | no | `NullPdfRenderer` (throws) | `PdfRendererContractTest` |
| `StorageInterface` | no | `InMemoryStorage` | `StorageContractTest` |

Defaults live in `CognitioTechny\Contabil\Application\Defaults`.

## CurrentUserInterface

```php
public function id(): int|string|null;
```

Who is acting. Stored as `user_id` on entries. Return `null` for console, queue workers and anonymous requests.

## AuthorizerInterface

```php
public function can(string $permission, int|string|null $entityId = null): bool;
```

Answers whether the current user holds a `contabil.*` permission string (`contabil.chart.view`, `contabil.chart.edit`, `contabil.period.manage`, `contabil.reports.view`, `contabil.reports.export`, ...). Must be side-effect free and answer `false`, not throw, for unknown permissions. `$entityId` is `null` for package-wide checks.

`AllowAllAuthorizer` grants everything. A host that exposes the API must replace it.

## CurrentEntityInterface

```php
public function id(): int|string|null;
```

The accounted entity of the current request. When it returns `null` the caller must receive the entity explicitly or fail with `ValidationException`.

## ClockInterface

```php
public function now(): DateTimeImmutable;
```

Source of "now" (used for `recorded_at`). Must be UTC and must not move backwards within a request. Use `FixedClock` in tests.

## EventDispatcherInterface

Exactly PSR-14 (`Psr\EventDispatcher\EventDispatcherInterface`), so any PSR-14 dispatcher fits. `dispatch(object $event): object` must return the event it received. Events are dispatched **after** the posting transaction commits; a listener failure never rolls back an accounting write.

## PdfRendererInterface (optional)

```php
public function render(string $html): string;
```

Returns PDF bytes. Without a real implementation it throws `UnsupportedOperationException` (HTTP 501 in the API). The package bundles no PDF library.

## StorageInterface (optional)

```php
public function put(string $path, string $contents): void;
public function get(string $path): string;      // NotFoundException when missing
public function exists(string $path): bool;
public function delete(string $path): void;     // missing file is not an error
```

Paths are relative, use `/` and never start with `/`.

## Writing an adapter

```php
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;

final class HostAuthorizer implements AuthorizerInterface
{
    public function __construct(private HostAuth $auth)
    {
    }

    public function can(string $permission, int|string|null $entityId = null): bool
    {
        return $this->auth->user()?->can($permission) ?? false;
    }
}
```

## Running the contract tests against your adapter

The contract tests ship in `CognitioTechny\Contabil\Testing\Contract` and run on the PHPUnit bundled with CodeIgniter. Extend the one for your port and return your adapter:

```php
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;
use CognitioTechny\Contabil\Testing\Contract\AuthorizerContractTest;

final class HostAuthorizerTest extends AuthorizerContractTest
{
    protected function createSubject(): AuthorizerInterface
    {
        return new HostAuthorizer(new FakeAuth());
    }
}
```
