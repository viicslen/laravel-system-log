# Agent Instructions — viicslen/laravel-system-log

## Quick Start

```bash
composer install          # always run first — no composer.lock is committed
composer format           # fix code style
composer test             # run full test suite (64 tests, ~2.5 s)
```

Run `composer format && composer test` before every commit.

## Project Summary

A Laravel package (`viicslen/laravel-system-log`) that automatically logs Eloquent model activity
(created / updated / deleted events) using a **deferred, database-first** pattern. The heavy payload
is written synchronously to `system_logs` *after* the HTTP response is sent (via `defer()`), then a
lightweight queue job (`ProcessLogContext`) enriches the record with execution context (origin,
backtrace, user ID, URL). This keeps TTFB at zero and stays under the SQS 256 KB message limit.

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

## Project Status

**This package is in active use. Prefer clean design while protecting external interfaces.**

**External interfaces to protect (avoid breaking changes):**
- **Config file format** (`config/system-log.php`, env vars) — provide migration guidance when changing
- **Public API surface** — `HasSystemLogs` trait, `SystemLoggable` interface, `SystemLog` model, `LogStatus` enum

**Internal changes remain flexible:**
- `ModelActivityObserver`, `ExecutionContext`, `ProcessLogContext` internals
- Table structure (migration stub is user-published, document changes clearly)

## Terminology

- **loggable** — any Eloquent model using the `HasSystemLogs` trait
- **execution context** — the origin, backtrace, user ID, and URL captured at event time (`ExecutionContext` DTO)
- **deferred write** — the DB insert runs via `defer()` after the HTTP response is sent, not inline
- **database-first** — full JSON payload goes to the DB row; only the integer ID travels through the queue
- **enrichment** — the async step where `ProcessLogContext` updates a `Pending` log with execution context

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
tests/
  Pest.php                          — Bootstraps TestCase for Feature/ and Unit/
  TestCase.php                      — Base: extends Orchestra, registers provider, SQLite in-memory DB
  Feature/ServiceProviderTest.php   — Service provider boot, config publishing, container binding
  Feature/SystemLogFeatureTest.php  — End-to-end create/update/delete logging flow
  Unit/Concerns/HasSystemLogsTest.php    — Trait registration and systemLogs() relationship
  Unit/DTO/ExecutionContextTest.php      — ExecutionContext::capture() and captureTrace() logic
  Unit/Enums/LogStatusTest.php           — LogStatus enum values
  Unit/Jobs/ProcessLogContextTest.php    — ProcessLogContext job handle() and failed() paths
  Unit/Models/SystemLogTest.php          — SystemLog fillable, casts, scopes, relationship
  Unit/Observers/ModelActivityObserverTest.php — Observer event handling and job dispatch
AGENTS.md                           — Source of truth for all AI agent instructions (symlinked)
.github/workflows/ci.yml            — GitHub Actions CI (style check + tests on PHP 8.3/8.4)
.github/copilot-instructions.md     — Symlink → AGENTS.md (GitHub Copilot)
CLAUDE.md                           — Symlink → AGENTS.md (Claude Code)
GEMINI.md                           — Symlink → AGENTS.md (Gemini)
.junie/guidelines.md                — Symlink → AGENTS.md (JetBrains Junie)
```

## Build & Validation Commands

`composer.lock` is not committed — dependencies always resolve fresh.

```bash
# Install dependencies (required before anything else)
composer install

# Run full test suite
composer test
# equivalent: vendor/bin/pest

# Fix code style in place
composer format
# equivalent: vendor/bin/pint

# Lint check only — exits non-zero if any file needs formatting (use in CI)
composer format -- --test
```

**Known issue:** `composer format -- --test` exits 1 when files need reformatting. Always run
`composer format` (without `--test`) to auto-fix before committing.

## Testing

- **Base class**: `tests/TestCase.php` extends `Orchestra\Testbench\TestCase`, registers
  `SystemLogServiceProvider`, uses SQLite in-memory via `RefreshDatabase`.
- **Bootstrap**: `tests/Pest.php` applies `TestCase` to `Feature/` and `Unit/`.
- **Database**: `defineDatabaseMigrations()` runs the stub migration and creates a `test_models`
  table used by in-test model stubs.
- **Queue**: `system-log.queue.connection = sync` in tests — jobs run synchronously and can be
  asserted immediately after the triggering model event.
- **Suite**: 64 tests / 126 assertions; runs in ~2.5 s.

Place new tests in `tests/Feature/` for end-to-end flows, `tests/Unit/{Subdirectory}/` for isolated
class tests. Always use the existing `TestCase` base — do not bootstrap the framework separately.

## Architecture & Key Patterns

- **Deferred write**: `ModelActivityObserver::log()` uses `defer()` — the closure captures all
  values by value and runs after the HTTP response is sent, so TTFB is unaffected.
- **Database-first**: full JSON payload (`attributes`/`modified`/`original`) is persisted to the DB
  row first; only the integer log ID enters the queue, staying well under SQS's 256 KB limit.
- **Backtrace compilation**: `SystemLogServiceProvider::packageBooted()` compiles glob patterns from
  `backtrace.skip_paths` into PCRE once at boot, stored in config as `compiled_patterns` /
  `compiled_include_patterns`. Unit tests that skip the provider exercise the raw-glob fallback path
  in `ExecutionContext::captureTrace()`.
- **Configurable model/table/connection**: `SystemLog::getConnectionName()` and `getTable()` read
  from config — safe to override without re-publishing the migration stub.
- **Container binding**: the service provider binds `SystemLog::class` to the config-specified model
  class so consumers can resolve it via the container.

## Code Quality

### Use `declare(strict_types=1)`

Every PHP file in `src/` and `tests/` must declare strict types. Do not omit it.

### No `config()` reads outside the service provider boot or class methods

Read config values inside methods, not at class construction time or in static initializers.
This ensures the package respects config overrides set in `defineEnvironment()` during tests.

### Observer skips no-op updates

`ModelActivityObserver::updated()` calls `$model->getChanges()` and returns early when empty.
Do not remove this guard — touch-only saves (e.g. timestamp bumps) must not produce log entries.

### Existing job carries minimal payload

`ProcessLogContext` holds only `$logId` (int) and `$context` (ExecutionContext DTO). Never add
large payloads to this job — the entire point is to stay under queue message size limits.

## Configuration Reference (`config/system-log.php`)

| Key                         | Default                  | Notes                                                           |
|-----------------------------|--------------------------|-----------------------------------------------------------------|
| `enabled`                   | `true`                   | Set `SYSTEM_LOG_ENABLED=false` to disable observer registration |
| `database.connection`       | `null`                   | Dedicated DB connection, or `null` for app default              |
| `database.table`            | `system_logs`            | Override via `SYSTEM_LOG_TABLE`                                 |
| `database.model`            | `SystemLog::class`       | Override to extend the model                                    |
| `database.foreign_key_type` | `string`                 | `string` = varchar(36) UUIDs; `bigInteger` = unsignedBigInteger |
| `queue.connection`          | `null`                   | Queue connection for `ProcessLogContext` job                    |
| `queue.name`                | `default`                | Queue name                                                      |
| `backtrace.max_frames`      | `10`                     | Max stack frames kept                                           |
| `backtrace.skip_paths`      | `['vendor/', 'artisan']` | Glob patterns to exclude from trace                             |
| `backtrace.include_paths`   | `[]`                     | Glob patterns to rescue frames from skip list                   |
| `input_except`              | `['password', …]`        | Request keys stripped from metadata                             |

## LogStatus Enum

```php
LogStatus::Pending  // row created; context enrichment not yet complete
LogStatus::Complete // job ran successfully
LogStatus::Failed   // job exhausted retries (tries=3, backoff=10s)
```

## CI / Checks

GitHub Actions workflows are defined in `.github/workflows/ci.yml` and run on every push and pull
request to `main` / `master`. The workflow has two jobs:

| Job          | Description                                      |
|--------------|--------------------------------------------------|
| `style`      | Runs `composer format -- --test` on PHP 8.3      |
| `tests`      | Runs `composer test` on PHP 8.3 and 8.4 (matrix) |

Replicate CI locally:

```bash
composer install
composer format -- --test   # must exit 0 (no style violations)
composer test               # must exit 0 (all tests pass)
```

Trust these instructions. Only search the codebase if the information here is incomplete or appears incorrect.
