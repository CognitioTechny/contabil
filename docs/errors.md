# Errors

Every exception the package throws on purpose derives from `CognitioTechny\Contabil\Domain\Exception\ContabilException`. The domain and application layers know nothing about HTTP; the API layer maps them with `Infrastructure\CodeIgniter\Http\ExceptionStatusMap`.

| Exception | Meaning | HTTP |
|---|---|---|
| `ValidationException` | Input or state violates a business rule (for example an unbalanced entry) | 422 |
| `NotFoundException` | Referenced record does not exist in the current entity | 404 |
| `ConflictException` | State conflict (for example a closed period) | 409 |
| `AccessDeniedException` | The current user lacks the permission | 403 |
| `UnsupportedOperationException` | Optional port without an implementation | 501 |
| `ConfigurationException` | Invalid package configuration | 500 |
| any other `ContabilException` or `Throwable` | Unexpected | 500 |

A rejected chart import raises `ChartImportException`, a `ValidationException` (422) that also carries one `{row, code, message}` entry per bad row; the API returns them in `error.details`. See [chart-of-accounts.md](chart-of-accounts.md#import-and-export).
