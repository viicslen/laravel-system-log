<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ViicSlen\SystemLog\Enums\LogStatus;

/**
 * @property int $id
 * @property string $loggable_type
 * @property string $loggable_id
 * @property string $event
 * @property array<string, mixed>|null $attributes
 * @property array<string, mixed>|null $modified
 * @property array<string, mixed>|null $original
 * @property string|null $origin
 * @property string|null $trace
 * @property int|null $user_id
 * @property array<string, mixed>|null $metadata
 * @property LogStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> pending()
 * @method static Builder<static> complete()
 * @method static Builder<static> failed()
 * @method static Builder<static> forModel(\Illuminate\Database\Eloquent\Model $model)
 */
class SystemLog extends Model
{
    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'event',
        'attributes',
        'modified',
        'original',
        'origin',
        'trace',
        'user_id',
        'metadata',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'modified' => 'array',
            'original' => 'array',
            'metadata' => 'array',
            'status' => LogStatus::class,
        ];
    }

    /**
     * Route to the configured connection so log writes can use a dedicated
     * database server without touching the application's primary connection.
     */
    public function getConnectionName(): ?string
    {
        return config('system-log.database.connection') ?? parent::getConnectionName();
    }

    /**
     * Allow the table name to be overridden via config without re-publishing
     * the migration (useful when multi-tenancy prefixes table names).
     */
    public function getTable(): string
    {
        return config('system-log.database.table', parent::getTable());
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** @param Builder<static> $query
     * @return Builder<static> */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', LogStatus::Pending);
    }

    /** @param Builder<static> $query
     * @return Builder<static> */
    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('status', LogStatus::Complete);
    }

    /** @param Builder<static> $query
     * @return Builder<static> */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', LogStatus::Failed);
    }

    /** @param Builder<static> $query
     * @return Builder<static> */
    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query
            ->where('loggable_type', $model->getMorphClass())
            ->where('loggable_id', (string) $model->getKey());
    }
}
