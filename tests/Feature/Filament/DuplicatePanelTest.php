<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Filament\Resources\Events\EventResource;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Database\Seeders\CategorySeeder;
use Filament\Actions\Action;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * D-13, REV-05, PIPE-05, D-03, D-15 (03-13): a flagged draft shows its
 * suspected match side by side with the reason and a link, and the admin
 * marks it a duplicate (publishing nothing) or "Not a duplicate"
 * (remembered). Matches against other pending drafts show too (03-11
 * deferred item 3). The review page has "Approve all like this"; the edit
 * page keeps "Save & approve" for new-event drafts only. Feed text is
 * escaped everywhere (T-03-36). Offline.
 */

const DPANEL_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const DPANEL_YOGA = '[name removed] ~ Yoga';

/** A hand-entered published event at the Town Hall, Sun 25 Oct 2026. */
function dpanelPublish(string $title, Venue $venue): Event
{
    return app(PublishEvent::class)(new PublishableEventData(
        title: $title,
        description: 'A gentle class for all levels.',
        categoryId: (int) Category::query()->orderBy('id')->value('id'),
        venueId: $venue->id,
        startDate: '2026-10-25',
        startTime: '10:00',
        organiserContact: 'yoga@example.test',
    ), ingestAdmin(), EventOrigin::Manual);
}

function dpanelYogaDraft(string $date): EventDraft
{
    return EventDraft::query()
        ->where('kind', DraftKind::New)
        ->where('title', DPANEL_YOGA)
        ->where('start_date', $date)
        ->sole();
}

/**
 * Notification titles and <p> body lines sent so far.
 *
 * @return list<string>
 */
function dpanelNotificationLines(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull())
        ->flatMap(fn (array $notification): array => [
            (string) ($notification['title'] ?? ''),
            ...preg_split('/<\/?p>/', (string) ($notification['body'] ?? ''), flags: PREG_SPLIT_NO_EMPTY),
        ])
        ->filter()
        ->values()
        ->all();
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->admin = ingestAdmin();
    actingAs($this->admin);

    $this->townHall = Venue::factory()->create(['name' => 'Town Hall']);
    $this->yoga = dpanelPublish('Yoga with [name removed]', $this->townHall);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => DPANEL_TC_URL,
        'default_venue_id' => $this->townHall->id,
        'default_category_id' => Category::query()->where('slug', 'community')->value('id'),
    ]);

    ingestFeed(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'));
    ingestLiveRun($this->tc);

    $this->flagged = dpanelYogaDraft('2026-10-25');
    expect($this->flagged->duplicate_of_event_id)->toBe($this->yoga->id)
        ->and($this->flagged->duplicate_reason)->toBe('fuzzy');

    $this->page = fn (EventDraft $draft) => livewire(ReviewEventDraft::class, ['record' => $draft->id]);
});

it('shows the suspected event side by side with the reason and a link to it', function () {
    ($this->page)($this->flagged)
        ->assertOk()
        ->assertSee('Possible duplicate')
        ->assertSee(['This draft', 'Published event'])
        ->assertSeeInOrder([
            'This draft', 'Date', 'Sun 25 Oct', 'Time', '10am', 'Venue', 'Town Hall', 'Title', DPANEL_YOGA,
            'Published event', 'Date', 'Sun 25 Oct', 'Time', '10am', 'Venue', 'Town Hall', 'Title', 'Yoga with [name removed]',
        ])
        ->assertSee('Same date, similar venue and title (similarity 0.78)')
        ->assertSee(EventResource::getUrl('edit', ['record' => $this->yoga]))
        ->assertActionVisible('markDuplicate')
        ->assertActionVisible('notDuplicate');
});

it('marks the draft a duplicate: nothing is published and the next draft opens', function () {
    $events = Event::query()->count();

    ($this->page)($this->flagged)
        ->assertActionExists('markDuplicate', fn (Action $action): bool => $action->getModalDescription() === 'Mark this draft as a duplicate of Yoga with [name removed]? Nothing new is published.')
        ->callAction('markDuplicate')
        ->assertNotified('Marked as duplicate')
        ->assertRedirect();

    expect($this->flagged->refresh()->review_status)->toBe(ReviewStatus::Duplicate)
        ->and($this->flagged->event_id)->toBeNull()
        ->and(Event::query()->count())->toBe($events);
});

it('records Not a duplicate: the warning goes and the draft stays pending', function () {
    ($this->page)($this->flagged)
        ->callAction('notDuplicate')
        ->assertNotified('Noted: not a duplicate')
        ->assertActionHidden('markDuplicate')
        ->assertActionHidden('notDuplicate')
        ->assertDontSee('Same date, similar venue and title');

    expect($this->flagged->refresh()->duplicate_of_event_id)->toBeNull()
        ->and($this->flagged->review_status)->toBe(ReviewStatus::PendingReview);
});

it('names a URL match as the same web address', function () {
    $this->flagged->forceFill(['duplicate_reason' => 'url', 'duplicate_score' => 1])->save();

    ($this->page)($this->flagged)->assertSee('Same web address')->assertDontSee('similarity');
});

it('shows a match against a pending draft with its reference link and no Mark as duplicate', function () {
    $other = EventDraft::factory()->create(['title' => 'Community Yoga', 'start_date' => '2026-10-18', 'start_time' => '10:00:00', 'venue_id' => $this->townHall->id]);
    $draft = dpanelYogaDraft('2026-10-18');
    $draft->forceFill(['duplicate_of_draft_id' => $other->id, 'duplicate_reason' => 'fuzzy', 'duplicate_score' => 0.456])->save();

    ($this->page)($draft)
        ->assertSee(['Pending draft', 'Community Yoga', $other->reference])
        ->assertSee('Same date, similar venue and title (similarity 0.46)')
        ->assertSee(EventDraftResource::getUrl('review', ['record' => $other]))
        ->assertSee('Possible duplicate of Community Yoga')
        ->assertDontSee('No duplicate found')
        ->assertActionHidden('markDuplicate')
        ->assertActionVisible('notDuplicate')
        ->callAction('notDuplicate')
        ->assertNotified('Noted: not a duplicate');

    expect($draft->refresh()->duplicate_of_draft_id)->toBeNull();
});

it('lists drafts flagged against an event or another draft under the Possible duplicate filter', function () {
    $other = EventDraft::factory()->create(['title' => 'Community Yoga']);
    $draft = dpanelYogaDraft('2026-10-18');
    $draft->forceFill(['duplicate_of_draft_id' => $other->id, 'duplicate_reason' => 'fuzzy', 'duplicate_score' => 0.5])->save();
    $clean = dpanelYogaDraft('2026-10-21');

    livewire(ListEventDrafts::class)
        ->filterTable('duplicate', true)
        ->assertCanSeeTableRecords([$this->flagged, $draft])
        ->assertCanNotSeeTableRecords([$clean, $other])
        ->filterTable('duplicate', false)
        ->searchTable('Community Yoga')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$this->flagged, $draft]);
});

it('escapes a script tag from a feed in the duplicate panel and the diff', function () {
    $script = '<script>alert(1)</script>';
    $this->flagged->forceFill(['title' => $script])->save();

    ($this->page)($this->flagged)
        ->assertSee($script)
        ->assertDontSee($script, escape: false);

    $update = EventDraft::factory()->create([
        'kind' => DraftKind::Update,
        'event_id' => $this->yoga->id,
        'title' => 'Yoga with [name removed]',
        'diff' => ['title' => ['published' => 'Yoga with [name removed]', 'before' => 'Yoga', 'now' => $script]],
    ]);

    ($this->page)($update)
        ->assertSee(['Published now', $script])
        ->assertDontSee($script, escape: false);
});

it('offers Approve all like this on the review page and counts the result like the table', function () {
    $first = dpanelYogaDraft('2026-10-18');

    ($this->page)($first)
        ->assertActionVisible('approveAllLikeThis')
        ->callAction('approveAllLikeThis')
        ->assertRedirect();

    expect(dpanelNotificationLines())->toBe(['Approved 2 of 3.', '1 skipped: possible duplicate'])
        ->and($first->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($this->flagged->refresh()->review_status)->toBe(ReviewStatus::PendingReview);

    ($this->page)($this->flagged)->assertActionVisible('approveAllLikeThis');

    $manual = EventDraft::factory()->create();
    ($this->page)($manual)->assertActionHidden('approveAllLikeThis');
});

it('lets the admin edit the excerpt of a discovered draft and publishes it with Save & approve', function () {
    $draft = dpanelYogaDraft('2026-10-18');

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertSee('Save & approve')
        ->fillForm(['description' => 'Gentle yoga in the Town Hall. Mats provided.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh()->description)->toBe('Gentle yoga in the Town Hall. Mats provided.')
        ->and($draft->review_status)->toBe(ReviewStatus::PendingReview);

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => 'Gentle yoga. Bring a mat.'])
        ->call('saveAndApprove')
        ->assertNotified('Published');

    expect(Event::query()->findOrFail($draft->refresh()->event_id)->description)->toBe('Gentle yoga. Bring a mat.');
});

it('offers Save but not Save & approve on an update draft, and a venue picked there lets Apply succeed', function () {
    $published = ingestApprove(dpanelYogaDraft('2026-10-21'));

    $barn = Venue::factory()->create(['name' => 'Old Barn']);
    $update = EventDraft::factory()->create([
        'kind' => DraftKind::Update,
        'event_id' => $published->id,
        'source_id' => $this->tc->id,
        'title' => $published->title,
        'description' => $published->description,
        'category_id' => $published->category_id,
        'start_date' => '2026-10-21',
        'start_time' => '18:00:00',
        'venue_id' => null,
        'venue_name' => 'Old Barn',
        'diff' => ['venue' => ['published' => 'Town Hall', 'before' => 'Town Hall', 'now' => 'Old Barn, Church Lane']],
    ]);

    livewire(EditEventDraft::class, ['record' => $update->id])
        ->assertSee('Save')
        ->assertDontSee('Save & approve')
        ->fillForm(['venue_id' => $barn->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($update->refresh()->venue_id)->toBe($barn->id)
        ->and($update->diff)->toHaveKey('venue');

    ($this->page)($update)
        ->callAction('applyChanges')
        ->assertNotified('Changes applied');

    expect($published->refresh()->venue_id)->toBe($barn->id);
});
