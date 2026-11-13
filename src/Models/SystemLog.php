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
use ViicSlen\SystemLog\Support\Compactor;
use ViicSlen\SystemLog\Support\History;
use ViicSlen\SystemLog\Support\LogOptions;

/**
 * @property int $id
 * @property string $loggable_type
 * @property string $loggable_id
 * @property string $event
 * @property array<string, mixed>|null $attributes Stored snapshot, or rebuilt by {@see self::rebuiltAttributes()} on update rows written without one
 * @property array<string, mixed>|null $modified
 * @property array<string, mixed>|null $original
 * @property string|null $origin
 * @property string|null $trace
 * @property int|null $user_id
 * @property array<string, mixed>|null $metadata
 * @property LogStatus $status
 * @property Carbon|null $created_at
 * @property int|null $changed_at_micros Microseconds since the epoch at the change; null on rows written before the column existed
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
            'changed_at_micros' => 'integer',
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
            : $this->rebuiltAttributes()
        )->shouldCache();
    }

    /**
     * The snapshot stored on this row, without rebuilding one.
     *
     * @return array<string, mixed>|null
     */
    public function storedAttributes(): ?array
    {
        $raw = $this->getAttributes()['attributes'] ?? null;

        return $raw === null ? null : $this->fromJson($raw);
    }

    /**
     * The model's attributes right after this row, rebuilt from the live row by {@see History}
     * for an update stored without a snapshot. Null for other rows.
     *
     * @return array<string, mixed>|null
     */
    public function rebuiltAttributes(): ?array
    {
        if (! $this->exists || ! in_array($this->event, ['updated', Compactor::EVENT], true)) {
            return null;
        }

        return History::stateAt($this->loggable_type, $this->loggable_id, $this);
    }

    /**
     * Mass-delete log rows older than their model's retention window in
     * batches (native `model:prune`). A model with pruning off, a non-positive
     * retention, or compaction on is never pruned by age.
     *
     * One equality branch per stored `loggable_type`, so each is an exact range on the
     * (`loggable_type`, `created_at`) index however many kept rows of other types are older.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoffs = [];

        foreach (static::query()->distinct()->pluck('loggable_type') as $type) {
            $options = LogOptions::for(LogOptions::modelClass($type));

            if ($options->prune && ! $options->compaction) {
                $cutoffs[$type] = now()->subDays($options->retentionDays);
            }
        }

        if ($cutoffs === []) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where(function (Builder $query) use ($cutoffs): void {
            foreach ($cutoffs as $type => $cutoff) {
                $query->orWhere(fn (Builder $query) => $query->where('loggable_type', $type)->where('created_at', '<', $cutoff));
            }
        });
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
