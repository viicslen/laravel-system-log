<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use ViicSlen\SystemLog\Concerns\HasSystemLogs;
use ViicSlen\SystemLog\Models\SystemLog;
use ViicSlen\SystemLog\Support\Compactor;
use ViicSlen\SystemLog\Support\History;

covers(History::class, Compactor::class, SystemLog::class);

class HistoryModel extends Model
{
    use HasSystemLogs;

    protected $table = 'test_models';

    protected $guarded = [];

    protected $casts = ['data' => 'array'];
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
