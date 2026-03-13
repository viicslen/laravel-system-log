# Copilot Instructions — viicslen/laravel-system-log

## Repository Summary

A Laravel package (`viicslen/laravel-system-log`) that automatically logs Eloquent model activity
(created / updated / deleted events) using a **deferred, database-first** pattern. The heavy payload
is written synchronously to the `system_logs` table *after* the HTTP response is sent (via `defer()`),
then a lightweight queue job (`ProcessLogContext`) enriches the record with execution context
(origin, backtrace, user ID, URL). This keeps TTFB at zero and stays under the SQS 256 KB message limit.

## Project Profile

| Item                | Value                                   |
|---------------------|-----------------------------------------|
| Type                | Laravel package (not a full app)        |
| Language            | PHP ≥ 8.2                               |
| Framework           | Laravel 11 / illuminate/contracts ^11.0 |
| Test runner         | Pest 3 via `orchestra/testbench` 9      |
| Linter/formatter    | Laravel Pint 1                          |
| Package helper      | spatie/laravel-package-tools ^1.14      |
| PHP runtime (local) | PHP 8.4                                 |
| Composer            | 2.x                                     |

## Repository Layout

```
composer.json                       — Package manifest; defines autoload, scripts, deps
config/system-log.php               — Published config (enabled, database, queue, backtrace, input_except)
database/migrations/
  create_system_logs_table.php.stub — Stub migration published to host app
src/
  SystemLogServiceProvider.php      — Boot: registers config, migration, compiles backtrace glob patterns
  Concerns/HasSystemLogs.php        — Trait: add to any Eloquent model to enable logging
  Contracts/SystemLoggable.php      — Interface satisfied by models using the trait
  DTO/ExecutionContext.php           — Immutable snapshot of request context (origin/trace/user/url)
  Enums/LogStatus.php               — Pending | Complete | Failed
  Jobs/ProcessLogContext.php        — Queue job: enriches a pending log record with context
  Models/SystemLog.php              — Eloquent model for the system_logs table
  Observers/ModelActivityObserver.php — Handles created/updated/deleted, writes DB + dispatches job
tests/                              — (empty) Create test files here using Pest + TestCase from testbench
.github/copilot-instructions.md     — This file
```

There is **no `tests/` directory yet** — the test runner will exit with an error if one does not exist.
Always create `tests/` and at least one test file before running `composer test`.

## Build & Validation Commands

Always run `composer install` first — there is no `composer.lock` committed, so dependencies are
resolved fresh.

```bash
# 1. Install all dependencies (required before any other command)
composer install

# 2. Run tests  (tests/ directory MUST exist with at least one .php file)
composer test
# equivalent: vendor/bin/pest

# 3. Format / lint (auto-fixes in place)
composer format
# equivalent: vendor/bin/pint

# 4. Lint check only (exits non-zero if any file needs formatting — use in CI)
composer format -- --test
```

### Validated command sequence (clean environment)

```bash
composer install   # resolves ~132 packages; takes ~30-60 s on first run
composer format    # fix code style before tests
composer test      # run Pest suite
```

**Known issues:**
- `composer test` fails with exit code 2 when `tests/` does not exist. Create the directory
  and add at least one test file (e.g. `tests/Pest.php` bootstrapping testbench).
- `composer format -- --test` exits 1 if any file needs reformatting. Always run
  `composer format` (without `--test`) to fix before committing.
- Running `composer format -- --test` in CI without fixing first will fail. The files
  `config/system-log.php`, `src/Contracts/SystemLoggable.php`, `src/Concerns/HasSystemLogs.php`,
  `src/SystemLogServiceProvider.php`, and `src/DTO/ExecutionContext.php` have been fixed;
  keep them formatted.

## Architecture & Key Patterns

- **Deferred write**: `ModelActivityObserver::log()` calls `defer()` to avoid adding latency to
  TTFB. The closure captures all values by value so it remains safe after GC.
- **Database-first**: The full JSON payload (`attributes`/`modified`/`original`) is written to the
  DB row first; only the integer log ID is sent to the queue job.
- **Backtrace compilation**: `SystemLogServiceProvider::packageBooted()` compiles glob patterns
  from `config('system-log.backtrace.skip_paths')` into PCRE patterns stored back in config as
  `compiled_patterns` / `compiled_include_patterns`. Tests that skip the service provider use
  the raw glob fallback path in `ExecutionContext::captureTrace()`.
- **Configurable model/table/connection**: `SystemLog::getConnectionName()` and `getTable()` read
  from config, making it safe to override without touching the migration stub.
- **Container binding**: `SystemLogServiceProvider` binds `SystemLog::class` to the class name
  from config so consumers can resolve it via the container.

## Configuration Reference (`config/system-log.php`)

| Key | Default | Notes |
|-----|---------|-------|
| `enabled` | `true` | Set `SYSTEM_LOG_ENABLED=false` to disable observer registration |
| `database.connection` | `null` | Dedicated DB connection, or `null` for app default |
| `database.table` | `system_logs` | Override via `SYSTEM_LOG_TABLE` |
| `database.model` | `SystemLog::class` | Override to extend the model |
| `database.foreign_key_type` | `string` | `string` = varchar(36) UUIDs; `bigInteger` = unsignedBigInteger |
| `queue.connection` | `null` | Queue connection for `ProcessLogContext` job |
| `queue.name` | `default` | Queue name |
| `backtrace.max_frames` | `10` | Max stack frames kept |
| `backtrace.skip_paths` | `['vendor/', 'artisan']` | Glob patterns to exclude from trace |
| `backtrace.include_paths` | `[]` | Glob patterns to rescue frames from skip list |
| `input_except` | `['password', …]` | Request keys stripped from metadata |

## LogStatus Enum

```php
LogStatus::Pending  // row created; context enrichment not yet complete
LogStatus::Complete // job ran successfully
LogStatus::Failed   // job exhausted retries (tries=3, backoff=10s)
```

## CI / Checks

There are no GitHub Actions workflows yet. Replicate CI locally with:

```bash
composer install
composer format -- --test   # must exit 0 (no style violations)
composer test               # must exit 0 (all tests pass)
```

Trust these instructions. Only search the codebase if the information here is incomplete or appears incorrect.
