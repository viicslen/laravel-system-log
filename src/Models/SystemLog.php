<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use ViicSlen\SystemLog\Enums\LogStatus;

/**
 * @property int $id
 * @property string $loggable_type
 * @property string $loggable_id
 * @property string $event
 * @property array<string, mixed>|null $attributes Stored snapshot, or rebuilt by {@see self::replayedAttributes()} on `updated` rows written without one
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
    use MassPrunable;

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
     * @return Attribute<array<string, mixed>|null, never>
     */
    protected function attributes(): Attribute
    {
        return Attribute::get(fn (?string $value): ?array => $value !== null
            ? $this->fromJson($value)
            : $this->replayedAttributes()
        )->shouldCache();
    }

    /**
     * Best-effort post-update state of an `updated` row stored without a snapshot.
     *
     * Starts from the nearest earlier row of the same model that kept a full snapshot and replays
     * every later `modified` up to and including this row. With no snapshot left in retention, it
     * replays what is there, so columns no retained row touched are absent. Writes that bypass
     * model events (query-builder updates) are invisible here, so a replayed value can be stale
     * where the stored snapshot would not have been.
     *
     * @return array<string, mixed>|null
     */
    public function replayedAttributes(): ?array
    {
        if ($this->event !== 'updated') {
            return null;
        }

        $deltas = [$this->modified ?? []];
        $state = [];

        if ($this->exists) {
            $earlier = static::query()
                ->toBase()
                ->where('loggable_type', $this->loggable_type)
                ->where('loggable_id', $this->loggable_id)
                ->where($this->getKeyName(), '<', $this->getKey())
                ->orderByDesc($this->getKeyName())
                ->select(['attributes', 'modified'])
                ->cursor();

            foreach ($earlier as $row) {
                if ($row->attributes !== null) {
                    $state = $this->fromJson($row->attributes);

                    break;
                }

                $deltas[] = $row->modified !== null ? $this->fromJson($row->modified) : [];
            }
        }

        return array_replace($state, ...array_reverse($deltas));
    }

    /**
     * Mass-delete log rows older than the configured retention window in
     * batches (native `model:prune`). A non-positive retention value disables
     * pruning by returning a query that matches nothing.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('system-log.prune.retention_days', 0);

        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays($days));
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
        return config('system-log.database.table') ?? parent::getTable();
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
