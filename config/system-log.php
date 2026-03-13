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
