<?php

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
    | Database Connection
    |--------------------------------------------------------------------------
    | The database connection to use for the system_logs table. When null the
    | application default connection is used. Set to a dedicated connection
    | to isolate high-volume log writes from your OLTP workload.
    */
    'connection' => env('SYSTEM_LOG_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    */
    'table' => env('SYSTEM_LOG_TABLE', 'system_logs'),

    /*
    |--------------------------------------------------------------------------
    | SystemLog Model
    |--------------------------------------------------------------------------
    | The Eloquent model class used to represent a log entry. Override this to
    | extend the model with custom scopes, casts, or relationships.
    */
    'model' => ViicSlen\SystemLog\Models\SystemLog::class,

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
        'name'       => env('SYSTEM_LOG_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polymorphic ID Type
    |--------------------------------------------------------------------------
    | Controls the loggable_id column width in the migration stub.
    | 'string' uses varchar(36) — supports both integer PKs and UUIDs.
    | 'bigInteger' uses unsignedBigInteger — use only when all models use
    | auto-incrementing integer PKs (smaller index, faster joins).
    */
    'loggable_id_type' => env('SYSTEM_LOG_ID_TYPE', 'string'), // 'string' | 'bigInteger'

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
        'max_frames'    => 10,
        'skip_paths'    => ['vendor/', 'artisan'],
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
