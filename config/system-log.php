<?php

use ViicSlen\SystemLog\Models\SystemLog;

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable Logging
    |--------------------------------------------------------------------------
    | When disabled, the HasSystemLogs trait will not register the observer and
    | no log records will be created.
    */
    'enabled' => env('SYSTEM_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    | connection : The database connection to use for the system_logs table.
    |              When null the application default connection is used. Set to
    |              a dedicated connection to isolate high-volume log writes from
    |              your OLTP workload.
    |
    | table      : The table name used for log entries.
    |
    | model      : The Eloquent model class used to represent a log entry.
    |              Override this to extend the model with custom scopes, casts,
    |              or relationships.
    */
    'database' => [
        'connection' => env('SYSTEM_LOG_CONNECTION'),
        'table' => env('SYSTEM_LOG_TABLE', 'system_logs'),
        'model' => SystemLog::class,
        'foreign_key_type' => env('SYSTEM_LOG_ID_TYPE', 'string'), // 'string' | 'bigInteger'
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload
    |--------------------------------------------------------------------------
    | mode : `full` stores a full `attributes` snapshot on every row. `diff`
    | stores only `modified` / `original` on updates; past states are rebuilt
    | on read (HasSystemLogs::systemLogStateAt()).
    |
    | snapshot_created / snapshot_deleted : in `diff` mode, whether created /
    | deleted rows still keep a snapshot. `full` mode always keeps one.
    */
    'payload' => [
        'mode' => env('SYSTEM_LOG_PAYLOAD_MODE', 'full'),
        'snapshot_created' => true,
        'snapshot_deleted' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Columns
    |--------------------------------------------------------------------------
    | Columns never logged. An update that only changes these (plus the updated
    | timestamp) writes no row at all.
    */
    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Compaction
    |--------------------------------------------------------------------------
    | When enabled, `system-log:compact` merges a model's update rows older than
    | `after_days` into one `compacted` row per calendar month. A model with
    | compaction enabled is never pruned by age.
    */
    'compaction' => [
        'enabled' => false,
        'after_days' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    | Rows older than `retention_days` are deleted (in batches) by Laravel's
    | `model:prune` command. Set `enabled` to false, or the retention to 0 (or
    | negative), to disable pruning. Schedule pruning in the host app, e.g.:
    |
    |   Schedule::command('model:prune', [
    |       '--model' => [\ViicSlen\SystemLog\Models\SystemLog::class],
    |   ])->daily();
    */
    'prune' => [
        'enabled' => true,
        'retention_days' => env('SYSTEM_LOG_RETENTION_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Model Overrides
    |--------------------------------------------------------------------------
    | Keyed by model class; each entry overrides any of `payload`, `exclude`,
    | `attributes`, `compaction` and `prune` for that model, e.g.:
    |
    |   App\Models\Post::class => [
    |       'payload' => ['mode' => 'diff'],
    |       'exclude' => ['view_count'],
    |       'attributes' => ['columns' => ['body' => 'patch']],
    |       'compaction' => ['enabled' => true],
    |   ],
    */
    'models' => [],

    /*
    |--------------------------------------------------------------------------
    | Scope Bridge
    |--------------------------------------------------------------------------
    | The log row is written via defer() — after the HTTP response is sent or
    | the console command exits. By then, ambient context the write depends on
    | (e.g. an active multi-tenant database connection) may have been torn down,
    | sending the insert to the wrong database.
    |
    | Set this to a class implementing ViicSlen\SystemLog\Contracts\ScopeBridge
    | to snapshot that context synchronously and restore it around the deferred
    | write. When null, the write runs inline with no wrapping.
    */
    'scope' => env('SYSTEM_LOG_SCOPE'),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    | The ProcessLogContext job is dispatched to this queue/connection after
    | the deferred DB insert. Keep this queue lightweight — the job only
    | carries a log ID and the small ExecutionContext DTO.
    */
    'queue' => [
        'connection' => env('SYSTEM_LOG_QUEUE_CONNECTION', null), // null = app default
        'name' => env('SYSTEM_LOG_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backtrace Configuration
    |--------------------------------------------------------------------------
    | max_frames : maximum number of frames kept in the captured trace.
    |
    | skip_paths: glob patterns — frames whose file path matches any of
    | these are excluded from the trace.
    | Supports * (any sequence) and ? (single character).
    | Example: 'vendor/', '*\/bootstrap/app.php'
    |
    | include_paths: glob patterns that rescue frames from the skip list.
    | A frame is kept when its path matches skip_paths BUT
    | also matches at least one include_paths pattern.
    | Use this to surface first-party packages buried inside
    | vendor/, e.g. 'vendor/your-company/*'.
    | Empty by default (no rescues).
    */
    'backtrace' => [
        'max_frames' => 10,
        'skip_paths' => ['vendor/', 'artisan'],
        'include_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Long Values
    |--------------------------------------------------------------------------
    | max_value_length : string values longer than this many characters go
    | through a strategy before the row is written. Null disables strategies
    | and stores every value whole.
    |
    | strategy : the default for long values —
    |   truncate : cut to the limit with a "…[truncated]" marker (lossy)
    |   omit     : record that the column changed, but not its value
    |   json     : store only the top-level JSON keys that changed
    |   patch    : store a reverse patch (new → old), or the whole old value
    |              when the patch is not smaller
    |
    | columns / casts : per-column, or per cast type (e.g. 'array'), strategy.
    */
    'attributes' => [
        'max_value_length' => env('SYSTEM_LOG_MAX_VALUE_LENGTH', 1000),
        'strategy' => 'truncate',
        'columns' => [],
        'casts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitised Input Fields
    |--------------------------------------------------------------------------
    | These request keys are stripped from the metadata.input snapshot to
    | avoid persisting sensitive values in the log record.
    */
    'input_except' => [
        'password',
        'password_confirmation',
        'current_password',
        '_token',
        'credit_card',
        'cvv',
    ],

];
