<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ViicSlen\SystemLog\Concerns\HasSystemLogs;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Support\Compactor;
use ViicSlen\SystemLog\Support\History;
use ViicSlen\SystemLog\Support\PayloadEncoder;

covers(History::class, Compactor::class, SystemLog::class);

class HistoryModel extends Model
{
    use HasSystemLogs;

    protected $table = 'test_models';

    protected $guarded = [];

    protected $casts = ['data' => 'array'];
}

class SoftHistoryModel extends Model
{
    use HasSystemLogs;
    use SoftDeletes;

    protected $table = 'soft_test_models';

    protected $guarded = [];
}

class CustomTableLog extends SystemLog
{
    public function getTable(): string
    {
        return 'custom_logs';
    }
}

function body(int $variant): string
{
    return implode("\n", array_map(fn (int $i): string => $i === 7 ? "line {$i} v{$variant}" : "line {$i}", range(1, 30)));
}

function logged(callable $write): void
{
    $write();
    app(DeferredCallbackCollection::class)->invoke();
}

/** Raw attributes the history should reproduce: everything but the excluded column. */
function comparable(?array $attributes): ?array
{
    return $attributes === null ? null : array_diff_key($attributes, ['views' => true]);
}

beforeEach(function () {
    Queue::fake();

    config([
        'system-log.models' => [HistoryModel::class => [
            'payload' => ['mode' => 'diff'],
            'exclude' => ['views'],
            'attributes' => ['max_value_length' => 20, 'columns' => ['body' => 'patch', 'data' => 'json']],
            'compaction' => ['enabled' => true, 'after_days' => 60],
        ]],
    ]);

    HistoryModel::flushEventListeners();
    HistoryModel::bootHasSystemLogs();

    Schema::create('soft_test_models', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(fn () => Carbon::setTestNow());

it('writes no row when only excluded columns change', function () {
    $model = HistoryModel::create(['name' => 'a']);
    app(DeferredCallbackCollection::class)->invoke();

    logged(fn () => $model->update(['views' => 5]));

    expect(SystemLog::query()->where('event', 'updated')->count())->toBe(0);
});

it('stores diff rows and rebuilds every past state exactly', function () {
    $states = [];
    $model = null;

    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a', 'body' => body(0), 'data' => ['x' => 1, 'long' => str_repeat('l', 30)]]);
    });

    $edits = [
        ['name' => 'b'],
        ['body' => body(1)],
        ['data' => ['x' => 2, 'long' => str_repeat('l', 30), 'y' => true]],
        ['body' => body(2), 'name' => 'c', 'views' => 9],
    ];

    foreach ($edits as $i => $edit) {
        Carbon::setTestNow(Carbon::parse('2026-01-02 10:00:00')->addDays($i));
        logged(fn () => $model->update($edit));
        $states[] = $model->fresh()->getAttributes();
    }

    $updates = SystemLog::query()->where('event', 'updated')->orderBy('id')->get();

    expect($updates)->toHaveCount(4)
        ->and($updates->every(fn (SystemLog $log) => $log->storedAttributes() === null))->toBeTrue()
        ->and($updates[1]->original['body']['$sl'])->toBe('patch');

    foreach ($updates as $i => $log) {
        expect(comparable($model->systemLogStateAt($log)))->toBe(comparable($states[$i]))
            ->and(comparable($log->attributes))->toBe(comparable($states[$i]));
    }

    expect($model->systemLogStateAt(Carbon::parse('2025-12-31')))->toBeNull();
});

it('compacts old months into one row each without moving any month boundary', function () {
    $model = null;
    $atMonthEnd = [];

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'n0', 'body' => body(0), 'data' => ['x' => 0, 'pad' => str_repeat('p', 30)]]);
    });
    $created = $model->fresh()->getAttributes();

    $writes = [
        '2026-01-05' => ['name' => 'n1', 'body' => body(1)],
        '2026-01-10' => ['data' => ['x' => 1, 'pad' => str_repeat('p', 30)]],
        '2026-01-20' => ['body' => body(2), 'name' => 'n0'],
        '2026-02-03' => ['data' => ['x' => 2, 'pad' => str_repeat('p', 30), 'z' => 1]],
        '2026-02-25' => ['body' => body(3)],
        '2026-05-20' => ['name' => 'recent'],
    ];

    foreach ($writes as $day => $edit) {
        Carbon::setTestNow($day.' 12:00:00');
        logged(fn () => $model->update($edit));
        $atMonthEnd[substr($day, 0, 7)] = $model->fresh()->getAttributes();
    }

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    $rows = SystemLog::query()->orderBy('created_at')->get();

    expect($rows->pluck('event')->all())->toBe(['created', 'compacted', 'compacted', 'updated'])
        ->and($rows[1]->metadata)->toMatchArray(['period' => '2026-01', 'changes' => 3])
        ->and($rows[2]->metadata)->toMatchArray(['period' => '2026-02', 'changes' => 2])
        // `name` went n0 → n1 → n0 inside January, so it did not change overall.
        ->and($rows[1]->modified)->not->toHaveKey('name')
        ->and($rows[1]->original['body']['$sl'])->toBe('patch');

    expect(comparable($model->systemLogStateAt(Carbon::parse('2026-01-31 23:59:59'))))->toBe(comparable($atMonthEnd['2026-01']))
        ->and(comparable($model->systemLogStateAt(Carbon::parse('2026-02-28 23:59:59'))))->toBe(comparable($atMonthEnd['2026-02']))
        ->and(comparable($model->systemLogStateAt(Carbon::parse('2026-01-02'))))->toBe(comparable($created));

    // A second run finds nothing left to merge.
    Artisan::call('system-log:compact');

    expect(SystemLog::count())->toBe(4);
});

it('never prunes a compacting model by age while pruning the rest', function () {
    config(['system-log.prune.retention_days' => 30]);

    Carbon::setTestNow('2025-01-01');
    logged(fn () => HistoryModel::create(['name' => 'kept']));
    SystemLog::create(['loggable_type' => 'Other', 'loggable_id' => '1', 'event' => 'created']);
    Carbon::setTestNow();

    (new SystemLog)->pruneAll();

    expect(SystemLog::pluck('loggable_type')->all())->toBe([(new HistoryModel)->getMorphClass()]);
});

it('honours a per-model retention and an off switch', function () {
    config(['system-log.models' => [HistoryModel::class => ['prune' => ['enabled' => false]]]]);

    Carbon::setTestNow('2025-01-01');
    logged(fn () => HistoryModel::create(['name' => 'kept']));
    Carbon::setTestNow();

    (new SystemLog)->pruneAll();

    expect(SystemLog::count())->toBe(1);
});

it('walks same-second rows in change order, not insert order', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    $model = HistoryModel::create(['name' => 'a']);
    app(DeferredCallbackCollection::class)->invoke();
    logged(fn () => $model->update(['name' => 'b']));
    logged(fn () => $model->update(['name' => 'c']));

    [$created, $first, $second] = SystemLog::query()->orderBy('id')->get()->all();
    $first->forceFill(['changed_at_micros' => $second->changed_at_micros + 1])->saveQuietly();

    $walked = collect(History::walk($model->getMorphClass(), $model->getKey()))->map(fn (array $step): int => $step[0]->id);

    expect($walked->all())->toBe([$first->id, $second->id, $created->id]);
});

it('shows each row its own change even after a write that skipped model events', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a', 'email' => 'one@x']);
    });

    Carbon::setTestNow('2026-01-02 10:00:00');
    logged(fn () => $model->update(['name' => 'b']));
    $named = SystemLog::query()->where('event', 'updated')->sole();

    HistoryModel::query()->whereKey($model->getKey())->update(['name' => 'quiet', 'email' => 'two@x']);

    expect($model->systemLogStateAt($named))->toMatchArray(['name' => 'b', 'email' => 'two@x']);
});

it('re-anchors on a stored snapshot instead of carrying the live value back past it', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a', 'email' => 'one@x']);
    });

    HistoryModel::query()->whereKey($model->getKey())->update(['email' => 'quiet@x']);

    expect($model->systemLogStateAt(Carbon::parse('2026-01-01 10:00:00')))->toMatchArray(['email' => 'one@x']);
});

it('has no state after a hard delete and the deleted snapshot before it', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a']);
    });

    Carbon::setTestNow('2026-01-02 10:00:00');
    logged(fn () => $model->update(['name' => 'b']));

    Carbon::setTestNow('2026-01-03 10:00:00');
    logged(fn () => $model->delete());

    $type = $model->getMorphClass();

    expect(History::stateAt($type, $model->getKey(), Carbon::parse('2026-01-04')))->toBeNull()
        ->and(History::stateAt($type, $model->getKey(), Carbon::parse('2026-01-02 12:00:00')))->toMatchArray(['name' => 'b'])
        ->and(History::stateAt($type, $model->getKey(), Carbon::parse('2026-01-01 12:00:00')))->toMatchArray(['name' => 'a']);
});

it('finds the state between two changes made within the same second', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a']);
    });

    Carbon::setTestNow('2026-01-01 10:00:01.100000');
    logged(fn () => $model->update(['name' => 'b']));

    Carbon::setTestNow('2026-01-01 10:00:01.900000');
    logged(fn () => $model->update(['name' => 'c']));

    expect($model->systemLogStateAt(Carbon::parse('2026-01-01 10:00:01.500000')))->toMatchArray(['name' => 'b']);
});

it('rebuilds a long history in batches without changing it', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'n0']);
    });

    foreach (range(1, 1200) as $i) {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00')->addSeconds($i));
        logged(fn () => $model->update(['name' => "n{$i}"]));
    }

    $names = collect(History::walk($model->getMorphClass(), $model->getKey()))->map(fn (array $step) => $step[1]['name'] ?? null);

    expect($names)->toHaveCount(1201)
        ->and($names->first())->toBe('n1200')
        ->and($names->last())->toBe('n0');
});

it('records when a compacted run began, since its older rows are deleted', function () {
    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'n0']);
    });

    foreach (['2026-01-05 08:00:00' => 'n1', '2026-01-20 08:00:00' => 'n2'] as $at => $name) {
        Carbon::setTestNow($at);
        logged(fn () => $model->update(['name' => $name]));
    }

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    expect(SystemLog::query()->where('event', 'compacted')->sole()->metadata)
        ->toMatchArray(['period' => '2026-01', 'from' => '2026-01-05 08:00:00', 'changes' => 2]);
});

it('compacts past a model it has to skip for changing too recently', function () {
    $models = [];

    foreach (['busy', 'idle'] as $name) {
        Carbon::setTestNow('2026-01-01 09:00:00');
        logged(function () use (&$models, $name) {
            $models[$name] = HistoryModel::create(['name' => $name]);
        });

        foreach (['2026-01-05', '2026-01-06'] as $day) {
            Carbon::setTestNow("{$day} 08:00:00");
            logged(fn () => $models[$name]->update(['email' => "{$name}-{$day}"]));
        }
    }

    Carbon::setTestNow('2026-06-15 12:00:00');
    HistoryModel::query()->whereKey($models['busy']->getKey())->update(['updated_at' => now()]);

    Artisan::call('system-log:compact', ['--chunk' => 1]);

    $events = fn (HistoryModel $model) => SystemLog::query()->forModel($model)->where('event', '!=', 'created')->pluck('event')->all();

    expect($events($models['busy']))->toBe(['updated', 'updated'])
        ->and($events($models['idle']))->toBe(['compacted']);
});

it('keeps a run snapshot through compaction, so a quiet change does not leak into earlier months', function () {
    config(['system-log.models' => [HistoryModel::class => ['payload' => ['mode' => 'full'], 'compaction' => ['enabled' => true, 'after_days' => 60]]]]);

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a', 'email' => 'one@x']);
    });

    Carbon::setTestNow('2026-01-10 09:00:00');
    logged(fn () => $model->update(['name' => 'b']));

    HistoryModel::query()->whereKey($model->getKey())->update(['email' => 'two@x']);

    Carbon::setTestNow('2026-02-10 09:00:00');
    logged(fn () => $model->update(['name' => 'c']));

    $before = $model->systemLogStateAt(Carbon::parse('2026-01-31'));

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    expect(SystemLog::query()->where('event', 'compacted')->count())->toBe(2)
        ->and($before)->toMatchArray(['name' => 'b', 'email' => 'one@x'])
        ->and($model->systemLogStateAt(Carbon::parse('2026-01-31')))->toMatchArray(['name' => 'b', 'email' => 'one@x']);
});

it('logs nothing for a change its transaction rolled back', function () {
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a']);
    });

    rescue(fn () => DB::transaction(function () use ($model) {
        $model->update(['name' => 'rolled back']);

        throw new RuntimeException('roll back');
    }), report: false);
    app(DeferredCallbackCollection::class)->invoke();

    logged(fn () => DB::transaction(fn () => $model->fresh()->update(['name' => 'kept'])));

    expect(SystemLog::query()->where('event', 'updated')->get()->pluck('modified.name')->all())->toBe(['kept']);
});

it('undoes a soft delete for the rows before it', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = SoftHistoryModel::create(['name' => 'a']);
    });

    Carbon::setTestNow('2026-01-02 10:00:00');
    logged(fn () => $model->update(['name' => 'b']));

    Carbon::setTestNow('2026-01-03 10:00:00');
    logged(fn () => $model->delete());

    $type = $model->getMorphClass();

    expect(History::stateAt($type, $model->getKey(), Carbon::parse('2026-01-04'))['deleted_at'])->not->toBeNull()
        ->and(History::stateAt($type, $model->getKey(), Carbon::parse('2026-01-02 12:00:00')))->toMatchArray(['name' => 'b', 'deleted_at' => null]);
});

it('drops legacy cast and truncated old values instead of putting them in the raw state', function () {
    expect(PayloadEncoder::undo(
        ['data' => '{"x":2}', 'price' => '2.00', 'body' => 'new', 'name' => 'b'],
        ['data' => ['x' => 1], 'price' => ['value' => '1.00'], 'body' => 'old…[truncated]', 'name' => 'a'],
    ))->toBe(['name' => 'a']);
});

it('compacts every stored type when compaction is enabled globally', function () {
    config(['system-log.models' => [], 'system-log.compaction' => ['enabled' => true, 'after_days' => 60]]);

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a']);
    });

    foreach (['2026-01-05', '2026-01-06'] as $day) {
        Carbon::setTestNow("{$day} 08:00:00");
        logged(fn () => $model->update(['name' => $day]));
    }

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    expect(SystemLog::query()->where('event', 'compacted')->count())->toBe(1);
});

it('re-anchors on a soft delete snapshot', function () {
    config(['system-log.models' => [SoftHistoryModel::class => ['payload' => ['mode' => 'diff']]]]);

    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = SoftHistoryModel::create(['name' => 'a', 'email' => 'one@x']);
    });

    Carbon::setTestNow('2026-01-02 10:00:00');
    logged(fn () => $model->update(['name' => 'b']));

    Carbon::setTestNow('2026-01-03 10:00:00');
    logged(fn () => $model->delete());

    SoftHistoryModel::withTrashed()->whereKey($model->getKey())->update(['email' => 'two@x']);

    expect(History::stateAt($model->getMorphClass(), $model->getKey(), Carbon::parse('2026-01-02 12:00:00')))
        ->toMatchArray(['name' => 'b', 'email' => 'one@x', 'deleted_at' => null]);
});

it('keeps the month-start value a full-mode snapshot pinned when a later diff row closes the run', function () {
    $full = ['payload' => ['mode' => 'full'], 'compaction' => ['enabled' => true, 'after_days' => 60]];
    $diff = ['payload' => ['mode' => 'diff'], 'compaction' => ['enabled' => true, 'after_days' => 60]];

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a', 'email' => 'one@x']);
    });
    SystemLog::query()->where('event', 'created')->delete();

    config(['system-log.models' => [HistoryModel::class => $full]]);
    Carbon::setTestNow('2026-01-05 09:00:00');
    logged(fn () => $model->update(['name' => 'b']));

    HistoryModel::query()->whereKey($model->getKey())->update(['email' => 'five@x']);

    config(['system-log.models' => [HistoryModel::class => $diff]]);
    Carbon::setTestNow('2026-01-20 09:00:00');
    logged(fn () => $model->fresh()->update(['name' => 'c']));

    $before = $model->systemLogStateAt(Carbon::parse('2026-01-03'));

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    expect(SystemLog::query()->where('event', 'compacted')->count())->toBe(1)
        ->and($before)->toMatchArray(['name' => 'a', 'email' => 'one@x'])
        ->and($model->systemLogStateAt(Carbon::parse('2026-01-03')))->toMatchArray(['name' => 'a', 'email' => 'one@x']);
});

it('compacts in the configured log model, never touching rows of the base table', function () {
    Schema::create('custom_logs', function (Blueprint $table) {
        $table->id();
        $table->string('loggable_type');
        $table->string('loggable_id');
        $table->string('event');
        foreach (['attributes', 'modified', 'original', 'metadata'] as $column) {
            $table->json($column)->nullable();
        }
        $table->string('origin')->nullable();
        $table->text('trace')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('status');
        $table->unsignedBigInteger('changed_at_micros')->nullable();
        $table->timestamps();
    });
    config(['system-log.database.model' => CustomTableLog::class]);

    foreach (range(1, 5) as $i) {
        SystemLog::query()->insert(['loggable_type' => 'decoy', 'loggable_id' => (string) $i, 'event' => 'updated', 'status' => 'complete', 'created_at' => '2026-01-01 00:00:00']);
    }

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a']);
    });

    foreach (['2026-01-05', '2026-01-06', '2026-01-07'] as $day) {
        Carbon::setTestNow("{$day} 08:00:00");
        logged(fn () => $model->update(['name' => $day]));
    }

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    expect(SystemLog::query()->count())->toBe(5)
        ->and(CustomTableLog::query()->pluck('event')->all())->toBe(['created', 'compacted']);
});

it('shows a patched value as unknown, not as a wrong one, after a save from a stale instance', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'a', 'body' => body(0)]);
    });

    $stale = $model->fresh();

    Carbon::setTestNow('2026-01-02 10:00:00');
    logged(fn () => $model->update(['body' => body(1)]));

    Carbon::setTestNow('2026-01-03 10:00:00');
    logged(fn () => $stale->update(['body' => body(2)]));

    $first = SystemLog::query()->where('event', 'updated')->orderBy('id')->first();

    expect($model->systemLogStateAt($first))->not->toHaveKey('body')
        ->and($model->systemLogStateAt(Carbon::parse('2026-01-03 12:00:00'))['body'])->toBe(body(2));
});

it('pins a run end to its own stored values, so a change still deferred does not leak into it', function () {
    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'p10', 'email' => 'one@x']);
    });

    Carbon::setTestNow('2026-01-05 09:00:00');
    logged(fn () => $model->update(['name' => 'p20']));

    Carbon::setTestNow('2026-01-10 09:00:00');
    logged(fn () => $model->update(['email' => 'two@x']));

    Carbon::setTestNow('2026-05-01 09:00:00');
    $model->update(['name' => 'p30']);

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');
    app(DeferredCallbackCollection::class)->invoke();

    expect($model->systemLogStateAt(Carbon::parse('2026-01-31')))->toMatchArray(['name' => 'p20', 'email' => 'two@x']);
});

it('pins a run end to its newest stored evidence, a snapshot included', function () {
    config(['system-log.models' => [HistoryModel::class => ['payload' => ['mode' => 'full'], 'compaction' => ['enabled' => true, 'after_days' => 60]]]]);

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model) {
        $model = HistoryModel::create(['name' => 'p10', 'email' => 'one@x']);
    });

    Carbon::setTestNow('2026-01-05 09:00:00');
    logged(fn () => $model->update(['name' => 'p20']));

    HistoryModel::query()->whereKey($model->getKey())->update(['name' => 'p25']);

    Carbon::setTestNow('2026-01-10 09:00:00');
    logged(fn () => $model->fresh()->update(['email' => 'two@x']));

    $before = $model->systemLogStateAt(Carbon::parse('2026-01-31'));

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');

    expect($before)->toMatchArray(['name' => 'p25'])
        ->and($model->systemLogStateAt(Carbon::parse('2026-01-31')))->toMatchArray(['name' => 'p25', 'email' => 'two@x']);
});

it('pins each json key of a run end to its newest stored change', function () {
    $pad = str_repeat('p', 30);

    Carbon::setTestNow('2026-01-01 09:00:00');
    logged(function () use (&$model, $pad) {
        $model = HistoryModel::create(['name' => 'a', 'data' => ['x' => 0, 'y' => 0, 'pad' => $pad]]);
    });

    Carbon::setTestNow('2026-01-05 09:00:00');
    logged(fn () => $model->update(['data' => ['x' => 1, 'y' => 0, 'pad' => $pad]]));

    Carbon::setTestNow('2026-01-10 09:00:00');
    logged(fn () => $model->update(['data' => ['x' => 1, 'y' => 1, 'pad' => $pad]]));

    Carbon::setTestNow('2026-05-01 09:00:00');
    $model->update(['data' => ['x' => 2, 'y' => 1, 'z' => 5, 'pad' => $pad]]);

    Carbon::setTestNow('2026-06-15 12:00:00');
    Artisan::call('system-log:compact');
    app(DeferredCallbackCollection::class)->invoke();

    expect(json_decode($model->systemLogStateAt(Carbon::parse('2026-01-31'))['data'], true))->toBe(['x' => 1, 'y' => 1, 'pad' => $pad]);
});
