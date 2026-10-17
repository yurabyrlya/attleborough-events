<?php

use App\Enums\RejectReason;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Review\ReviewQueue;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * D-09: soonest event first, ties by oldest created, then id. One sort key
 * behind the queue table, ReviewQueue::query() and next()/previous().
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    $at = fn (string $local) => CarbonImmutable::parse($local, 'Europe/London')->utc();

    $this->d24Evening = EventDraft::factory()->create(['title' => '24 Oct 19:30', 'start_date' => '2026-10-24', 'start_time' => '19:30:00', 'created_at' => $at('2026-10-01 09:00')]);
    $this->d24Midnight = EventDraft::factory()->create(['title' => '24 Oct 00:00', 'start_date' => '2026-10-24', 'start_time' => '00:00:00', 'created_at' => $at('2026-10-01 09:00')]);
    $this->d24NoTime = EventDraft::factory()->create(['title' => '24 Oct no time', 'start_date' => '2026-10-24', 'start_time' => null, 'created_at' => $at('2026-10-01 09:00')]);
    $this->noDate = EventDraft::factory()->create(['title' => 'No date', 'start_date' => null, 'start_time' => null, 'created_at' => $at('2026-09-01 09:00')]);
    $this->d23Later = EventDraft::factory()->create(['title' => '23 Oct later', 'start_date' => '2026-10-23', 'start_time' => '10:00:00', 'created_at' => $at('2026-10-05 09:00')]);
    $this->d23Earlier = EventDraft::factory()->create(['title' => '23 Oct earlier', 'start_date' => '2026-10-23', 'start_time' => '10:00:00', 'created_at' => $at('2026-10-02 09:00')]);

    $this->expected = [
        $this->d23Earlier->id,
        $this->d23Later->id,
        $this->d24NoTime->id,
        $this->d24Midnight->id,
        $this->d24Evening->id,
        $this->noDate->id,
    ];

    $this->queue = app(ReviewQueue::class);
});

/**
 * @return list<int>
 */
function walkForwards(ReviewQueue $queue, EventDraft $first): array
{
    $ids = [$first->id];
    $current = $first;

    while (($current = $queue->next($current)) !== null) {
        $ids[] = $current->id;
    }

    return $ids;
}

/**
 * @return list<int>
 */
function walkBackwards(ReviewQueue $queue, EventDraft $last): array
{
    $ids = [$last->id];
    $current = $last;

    while (($current = $queue->previous($current)) !== null) {
        $ids[] = $current->id;
    }

    return $ids;
}

it('orders soonest first, null time before 00:00, ties by created_at then id, no date last', function () {
    expect($this->queue->query()->pluck('id')->all())->toBe($this->expected);
});

it('lists only pending drafts', function () {
    EventDraft::factory()->rejected(RejectReason::Spam, CarbonImmutable::now())->create(['start_date' => '2026-10-21']);

    expect($this->queue->query()->pluck('id')->all())->toBe($this->expected);
});

it('breaks a full tie (same start and created_at) by id', function () {
    $twin = EventDraft::factory()->create([
        'start_date' => '2026-10-23',
        'start_time' => '10:00:00',
        'created_at' => $this->d23Earlier->created_at,
    ]);

    expect($this->queue->query()->pluck('id')->take(2)->all())->toBe([$this->d23Earlier->id, $twin->id]);
    expect($this->queue->next($this->d23Earlier)?->id)->toBe($twin->id);
});

it('walks next() from the first and previous() from the last in the same order', function () {
    expect(walkForwards($this->queue, $this->d23Earlier))->toBe($this->expected)
        ->and(walkBackwards($this->queue, $this->noDate))->toBe(array_reverse($this->expected));
});

it('puts a null-time draft before a 00:00 draft on the same date in the table and in a next() walk', function () {
    expect($this->queue->next($this->d24NoTime)?->id)->toBe($this->d24Midnight->id)
        ->and($this->queue->previous($this->d24Midnight)?->id)->toBe($this->d24NoTime->id);

    livewire(ListEventDrafts::class)
        ->assertCanSeeTableRecords([$this->d24NoTime, $this->d24Midnight], inOrder: true);
});

it('returns null after the last and before the first', function () {
    expect($this->queue->next($this->noDate))->toBeNull()
        ->and($this->queue->previous($this->d23Earlier))->toBeNull();
});

it('returns the following pending draft from a just-approved draft', function () {
    app(ApproveDraft::class)($this->d24NoTime, $this->admin);

    expect($this->queue->next($this->d24NoTime->refresh())?->id)->toBe($this->d24Midnight->id)
        ->and($this->queue->previous($this->d24NoTime)?->id)->toBe($this->d23Later->id);
});

it('gives the same id sequence in the table default order, query() and a next() walk', function () {
    $records = EventDraft::query()->findMany($this->expected)->sortBy(fn (EventDraft $d) => array_search($d->id, $this->expected, true))->values();

    livewire(ListEventDrafts::class)
        ->assertCanSeeTableRecords($records, inOrder: true);

    expect($this->queue->query()->pluck('id')->all())->toBe(walkForwards($this->queue, $this->queue->query()->firstOrFail()));
});
