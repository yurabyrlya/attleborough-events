<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A live run row for $source with the given status, written the way the
 * system writers do (forceCreate: nothing on a run is admin-editable).
 *
 * @param  array<string, mixed>  $extra
 */
function ingestionRun(Source $source, RunStatus $status, array $extra = []): SourceRun
{
    return $source->runs()->forceCreate(array_merge([
        'mode' => RunMode::Live,
        'trigger' => RunTrigger::Manual,
        'status' => $status,
        'queued_at' => now()->utc(),
    ], $extra));
}

function ingestionItem(Source $source, string $externalId = 'uid-1@example.org'): SourceItem
{
    return $source->items()->create([
        'external_id' => $externalId,
        'raw_fragment' => "BEGIN:VEVENT\r\nUID:{$externalId}\r\nEND:VEVENT",
        'raw_hash' => hash('sha256', $externalId),
        'content_hash' => hash('sha256', $externalId.'-content'),
        'payload' => ['title' => 'Coffee morning'],
    ]);
}

/**
 * A draft linked to $item, as DraftWriter::createFromSource will write it.
 *
 * @param  array<string, mixed>  $extra
 */
function ingestionDraft(SourceItem $item, array $extra = []): EventDraft
{
    return EventDraft::factory()->create(array_merge([
        'origin' => EventOrigin::Discovered,
        'extraction_method' => ExtractionMethod::Ics,
        'source_id' => $item->source_id,
        'source_item_id' => $item->id,
    ], $extra));
}

it('saves an ICS source with the safe defaults from the database', function () {
    $source = Source::factory()->ics()->create()->fresh();

    expect($source->location_mode)->toBe('local')
        ->and($source->default_timezone)->toBe('Europe/London')
        ->and($source->year_policy)->toBe('none')
        ->and($source->health)->toBe('healthy')
        ->and($source->consecutive_failures)->toBe(0)
        ->and($source->check_frequency)->toBe('daily')
        ->and($source->check_time)->toBe('05:30:00')
        ->and($source->is_active)->toBeFalse()
        ->and($source->is_organiser_source)->toBeFalse()
        ->and($source->supports_missing_detection)->toBeFalse()
        ->and($source->title_rules)->toBe([])
        ->and($source->parser_config)->toBe(['identity' => 'uid'])
        ->and($source->extraction_method)->toBe(ExtractionMethod::Ics);

    expect(Source::factory()->ics()->active()->create()->fresh()->is_active)->toBeTrue()
        ->and(Source::factory()->organiserSite()->create()->fresh()->is_organiser_source)->toBeTrue()
        ->and(Source::factory()->withIdentity('tec_post_id')->create()->fresh()->parser_config)
        ->toBe(['identity' => 'tec_post_id']);
});

it('defaults parser_config to the uid identity in the database', function () {
    $id = DB::table('sources')->insertGetId([
        'name' => 'Bare source',
        'slug' => 'bare-source',
        'short_name' => 'Bare',
        'url' => 'https://example.org/calendar.ics',
        'extraction_method' => 'ics',
    ]);

    expect(json_decode((string) DB::table('sources')->where('id', $id)->value('parser_config'), true))
        ->toBe(['identity' => 'uid']);
});

it('refuses a non-ics extraction method at the database', function () {
    DB::table('sources')->insert([
        'name' => 'HTML source',
        'slug' => 'html-source',
        'short_name' => 'HTML',
        'url' => 'https://example.org/',
        'extraction_method' => 'html',
    ]);
})->throws(QueryException::class, 'sources_extraction_method_check');

it('refuses a second queued or running run for the same source', function (RunStatus $first, RunStatus $second) {
    $source = Source::factory()->ics()->create();
    ingestionRun($source, $first);

    expect(fn () => ingestionRun($source, $second))
        ->toThrow(UniqueConstraintViolationException::class, 'source_runs_one_active');
})->with([
    'queued then queued' => [RunStatus::Queued, RunStatus::Queued],
    'queued then running' => [RunStatus::Queued, RunStatus::Running],
    'running then queued' => [RunStatus::Running, RunStatus::Queued],
]);

it('allows finished runs beside an active run and active runs for other sources', function () {
    $source = Source::factory()->ics()->create();
    ingestionRun($source, RunStatus::Running);
    ingestionRun($source, RunStatus::Succeeded);
    ingestionRun($source, RunStatus::Failed, ['failure_kind' => 'network']);
    ingestionRun(Source::factory()->ics()->create(), RunStatus::Queued);

    expect(SourceRun::query()->count())->toBe(4)
        ->and($source->runs()->count())->toBe(3);
});

it('refuses a failure_kind on a run that did not fail', function () {
    $source = Source::factory()->ics()->create();

    ingestionRun($source, RunStatus::Succeeded, ['failure_kind' => 'network']);
})->throws(QueryException::class, 'source_runs_failure_kind_only_when_failed');

it('casts run mode, trigger and status to enums', function () {
    $run = ingestionRun(Source::factory()->ics()->create(), RunStatus::Queued)->fresh();

    expect($run->mode)->toBe(RunMode::Live)
        ->and($run->trigger)->toBe(RunTrigger::Manual)
        ->and($run->status)->toBe(RunStatus::Queued)
        ->and($run->status->isActive())->toBeTrue()
        ->and(RunStatus::Succeeded->isActive())->toBeFalse()
        ->and($run->items_found)->toBe(0)
        ->and($run->is_complete)->toBeFalse();
});

it('links a source, a run, an item and a pending ICS draft', function () {
    $source = Source::factory()->ics()->active()->create();
    $run = ingestionRun($source, RunStatus::Running);
    $item = ingestionItem($source);
    $item->forceFill(['first_seen_run_id' => $run->id, 'last_seen_run_id' => $run->id])->save();
    $draft = ingestionDraft($item);
    $item->forceFill(['current_draft_id' => $draft->id])->save();

    $draft = $draft->fresh();
    $item = $item->fresh();
    expect($draft->extraction_method)->toBe(ExtractionMethod::Ics)
        ->and($draft->source->is($source))->toBeTrue()
        ->and($draft->sourceItem->is($item))->toBeTrue()
        ->and($draft->excluded_dates)->toBe([])
        ->and($item->currentDraft->is($draft))->toBeTrue()
        ->and($item->firstSeenRun->is($run))->toBeTrue()
        ->and($item->status)->toBe('active')
        ->and($item->missing_runs)->toBe(0)
        ->and($item->parse_errors)->toBe([])
        ->and($source->items()->count())->toBe(1);
});

it('refuses a second item with the same external id for one source', function () {
    $source = Source::factory()->ics()->create();
    ingestionItem($source, 'same@example.org');
    ingestionItem(Source::factory()->ics()->create(), 'same@example.org');

    expect(fn () => ingestionItem($source, 'same@example.org'))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second pending draft for the same source item', function () {
    $item = ingestionItem(Source::factory()->ics()->create());
    ingestionDraft($item);

    expect(fn () => ingestionDraft($item))
        ->toThrow(UniqueConstraintViolationException::class, 'event_drafts_one_pending_per_item');
});

it('allows a rejected draft beside the pending draft for one item', function () {
    $item = ingestionItem(Source::factory()->ics()->create());
    ingestionDraft($item);
    ingestionDraft($item, [
        'review_status' => ReviewStatus::Rejected,
        'reject_reason' => RejectReason::Other,
        'reviewed_at' => now()->utc(),
    ]);

    expect(EventDraft::query()->where('source_item_id', $item->id)->count())->toBe(2);
});

it('clears the item link in the database when its draft is deleted', function () {
    $item = ingestionItem(Source::factory()->ics()->create());
    $draft = ingestionDraft($item);
    $item->forceFill(['current_draft_id' => $draft->id])->save();

    // MassPrunable deletes with a query, so no model events: the FK must act.
    EventDraft::query()->whereKey($draft->id)->delete();

    expect($item->fresh()->current_draft_id)->toBeNull();
});

it('defaults excluded_dates to an empty JSON array on events and drafts', function () {
    $event = Event::factory()->create();
    $draft = EventDraft::factory()->create();

    expect(DB::table('events')->where('id', $event->id)->value('excluded_dates'))->toBe('[]')
        ->and(DB::table('event_drafts')->where('id', $draft->id)->value('excluded_dates'))->toBe('[]');
});

it('accepts the ignored reject reason on a rejected draft', function () {
    $draft = EventDraft::factory()->create();

    DB::table('event_drafts')->where('id', $draft->id)->update([
        'review_status' => 'rejected',
        'reject_reason' => 'ignored',
        'reviewed_at' => now()->utc(),
    ]);

    expect(DB::table('event_drafts')->where('id', $draft->id)->value('reject_reason'))->toBe('ignored');
});

it('refuses an unknown duplicate reason on a draft', function () {
    $draft = EventDraft::factory()->create();

    DB::table('event_drafts')->where('id', $draft->id)->update(['duplicate_reason' => 'guess']);
})->throws(QueryException::class, 'event_drafts_duplicate_reason_check');

it('labels the ICS extraction method', function () {
    expect(ExtractionMethod::Ics->getLabel())->toBe('ICS feed');
});
