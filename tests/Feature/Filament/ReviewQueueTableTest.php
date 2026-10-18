<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\QualityBand;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\RejectDraft;
use Carbon\CarbonImmutable;
use Filament\Notifications\NotificationMailbox;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * The review queue table (REV-09): tabs, filters, columns, table search and
 * the empty state.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);
});

it('opens on the Pending tab and shows only pending drafts', function () {
    $pending = EventDraft::factory()->create();
    $rejected = EventDraft::factory()->rejected(RejectReason::Spam, CarbonImmutable::now())->create();
    $approved = EventDraft::factory()->create();
    app(ApproveDraft::class)($approved, $this->admin);

    livewire(ListEventDrafts::class)
        ->assertSet('activeTab', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$rejected, $approved])
        ->set('activeTab', 'rejected')
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$pending, $approved])
        ->set('activeTab', 'approved')
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending, $rejected])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$pending, $rejected, $approved]);
});

it('labels the tabs in order with their counts', function () {
    EventDraft::factory()->count(2)->create();
    EventDraft::factory()->rejected(RejectReason::Spam, CarbonImmutable::now())->create();

    $tabs = livewire(ListEventDrafts::class)->instance()->getTabs();

    expect(array_keys($tabs))->toBe(['pending', 'rejected', 'approved', 'all'])
        ->and(collect($tabs)->map(fn ($tab) => $tab->getLabel())->values()->all())->toBe(['Pending', 'Rejected', 'Approved', 'All'])
        ->and(collect($tabs)->map(fn ($tab) => $tab->getBadge())->values()->all())->toBe(['2', '1', '0', '3']);
});

it('filters by kind', function () {
    $new = EventDraft::factory()->create();
    $update = EventDraft::factory()->create(['kind' => DraftKind::Update]);

    livewire(ListEventDrafts::class)
        ->filterTable('kind', DraftKind::New->value)
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$update]);
});

it('filters by origin', function () {
    $manual = EventDraft::factory()->manual()->create();
    $submission = EventDraft::factory()->submission()->create();

    livewire(ListEventDrafts::class)
        ->filterTable('origin', 'submission')
        ->assertCanSeeTableRecords([$submission])
        ->assertCanNotSeeTableRecords([$manual]);
});

it('filters by source on the sources table and shows the source name or the origin', function () {
    $baptist = Source::factory()->ics()->active()->create(['name' => 'Attleborough Baptist Church']);
    $council = Source::factory()->ics()->active()->create(['name' => 'Attleborough Town Council']);
    $fromBaptist = EventDraft::factory()->create(['origin' => EventOrigin::Discovered, 'extraction_method' => ExtractionMethod::Ics, 'source_id' => $baptist->id]);
    $fromCouncil = EventDraft::factory()->create(['origin' => EventOrigin::Discovered, 'extraction_method' => ExtractionMethod::Ics, 'source_id' => $council->id]);
    $submission = EventDraft::factory()->submission()->create();
    $manual = EventDraft::factory()->manual()->create();

    $filters = livewire(ListEventDrafts::class)->instance()->getTable()->getFilters();

    expect($filters['source_id']->getLabel())->toBe('Source')
        ->and($filters['origin']->getLabel())->toBe('Origin');

    livewire(ListEventDrafts::class)
        ->assertTableColumnStateSet('source', 'Attleborough Baptist Church', $fromBaptist)
        ->assertTableColumnStateSet('source', 'Public submission', $submission)
        ->assertTableColumnStateSet('source', 'Manual entry', $manual)
        ->filterTable('source_id', $baptist->id)
        ->assertCanSeeTableRecords([$fromBaptist])
        ->assertCanNotSeeTableRecords([$fromCouncil, $submission, $manual])
        ->resetTableFilters()
        ->filterTable('origin', EventOrigin::Submission->value)
        ->assertCanSeeTableRecords([$submission])
        ->assertCanNotSeeTableRecords([$fromBaptist, $fromCouncil, $manual]);
});

it('shows an Urgent badge only on updates and cancellations due within 48 hours (D-09)', function () {
    $now = CarbonImmutable::now();
    $urgentUpdate = EventDraft::factory()->create(['kind' => DraftKind::Update, 'relevant_starts_at' => $now->addHours(3)]);
    $urgentCancellation = EventDraft::factory()->create(['kind' => DraftKind::Cancellation, 'relevant_starts_at' => $now->addHours(47)]);
    $laterUpdate = EventDraft::factory()->create(['kind' => DraftKind::Update, 'relevant_starts_at' => $now->addHours(72)]);
    $soonNew = EventDraft::factory()->create(['relevant_starts_at' => $now->addHour()]);

    livewire(ListEventDrafts::class)
        ->assertTableColumnStateSet('kind', ['Update', 'Urgent'], $urgentUpdate)
        ->assertTableColumnStateSet('kind', ['Cancellation', 'Urgent'], $urgentCancellation)
        ->assertTableColumnStateSet('kind', ['Update'], $laterUpdate)
        ->assertTableColumnStateSet('kind', ['New event'], $soonNew)
        ->assertCanSeeTableRecords([$urgentUpdate, $urgentCancellation, $laterUpdate, $soonNew], inOrder: true);
});

it('filters by quality band', function () {
    $high = EventDraft::factory()->create();
    $low = EventDraft::factory()->scored(QualityBand::Low, 20)->create();

    livewire(ListEventDrafts::class)
        ->filterTable('quality_band', QualityBand::Low->value)
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$high]);
});

it('filters by possible duplicate', function () {
    $duplicate = EventDraft::factory()->duplicateOf(Event::factory()->create())->create();
    $clean = EventDraft::factory()->create();

    livewire(ListEventDrafts::class)
        ->filterTable('duplicate', true)
        ->assertCanSeeTableRecords([$duplicate])
        ->assertCanNotSeeTableRecords([$clean])
        ->filterTable('duplicate', false)
        ->assertCanSeeTableRecords([$clean])
        ->assertCanNotSeeTableRecords([$duplicate]);
});

it('filters by an inclusive event-date range and drops dateless drafts only when a range is set', function () {
    $before = EventDraft::factory()->create(['start_date' => '2026-10-23']);
    $first = EventDraft::factory()->create(['start_date' => '2026-10-24']);
    $last = EventDraft::factory()->create(['start_date' => '2026-10-31']);
    $after = EventDraft::factory()->create(['start_date' => '2026-11-01']);
    $dateless = EventDraft::factory()->create(['start_date' => null]);

    livewire(ListEventDrafts::class)
        ->assertCanSeeTableRecords([$before, $first, $last, $after, $dateless])
        ->filterTable('event_date', ['from' => '2026-10-24', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$first, $last])
        ->assertCanNotSeeTableRecords([$before, $after, $dateless]);
});

it('finds drafts by reference, organiser and typed venue in the table search', function () {
    $byReference = EventDraft::factory()->create(['reference' => 'WOA-7Q2K9X']);
    $byOrganiser = EventDraft::factory()->create(['organiser_name' => 'Attleborough Players']);
    $byVenue = EventDraft::factory()->freeTextVenue()->create(['venue_name' => 'Queens Square Bandstand']);
    $other = EventDraft::factory()->create(['title' => 'Unrelated', 'organiser_name' => 'Someone']);

    livewire(ListEventDrafts::class)
        ->searchTable('woa-7q2k9x')
        ->assertCanSeeTableRecords([$byReference])
        ->assertCanNotSeeTableRecords([$byOrganiser, $byVenue, $other])
        ->searchTable('players')
        ->assertCanSeeTableRecords([$byOrganiser])
        ->assertCanNotSeeTableRecords([$byReference, $other])
        ->searchTable('bandstand')
        ->assertCanSeeTableRecords([$byVenue])
        ->assertCanNotSeeTableRecords([$byReference, $other]);
});

it('shows the columns in order', function () {
    $columns = livewire(ListEventDrafts::class)->instance()->getTable()->getVisibleColumns();

    expect(collect($columns)->map(fn ($column) => $column->getLabel())->values()->all())
        ->toBe(['Quality', 'Score', 'Title', 'When', 'Venue', 'Category', 'Source', 'Kind', 'Received']);
});

it('renders band, score, wall-clock time, venue, category, source, kind and received time', function () {
    $venue = Venue::factory()->create(['name' => 'Connaught Hall']);
    $other = Category::factory()->create(['name' => 'Other']);

    $confirmed = EventDraft::factory()->submission()->scored(QualityBand::Medium, 72)->create([
        'venue_id' => $venue->id,
        'start_date' => '2026-10-24',
        'start_time' => '19:30:00',
        'created_at' => CarbonImmutable::parse('2026-10-19 21:15', 'Europe/London')->utc(),
    ]);
    $flagged = EventDraft::factory()->freeTextVenue()->create([
        'venue_name' => 'The Old Barn',
        'category_id' => $other->id,
        'category_flagged' => true,
    ]);

    livewire(ListEventDrafts::class)
        ->assertTableColumnFormattedStateSet('quality_score', '72/100', $confirmed)
        ->assertTableColumnStateSet('venue', 'Connaught Hall', $confirmed)
        ->assertTableColumnStateSet('venue', 'The Old Barn (not confirmed)', $flagged)
        ->assertTableColumnStateSet('category', 'Other (check)', $flagged)
        ->assertSee('Medium')
        ->assertSee('72/100')
        ->assertSee('Sat 24 Oct, 7:30pm')
        ->assertSee('Public submission')
        ->assertSee('New event')
        ->assertSee('Mon 19 Oct 2026, 21:15');
});

it('shows the empty state on an empty Pending tab', function () {
    EventDraft::factory()->rejected(RejectReason::Spam, CarbonImmutable::now())->create();

    livewire(ListEventDrafts::class)
        ->assertSee('Nothing waiting for review')
        ->assertSee('New submissions appear here. Manual entries are published straight away.');
});

it('escapes a script tag in a draft title', function () {
    EventDraft::factory()->create(['title' => '<script>alert(1)</script>']);

    livewire(ListEventDrafts::class)
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('still reports a selected draft as already reviewed after it left the Pending tab', function () {
    $raced = EventDraft::factory()->create();
    $clean = EventDraft::factory()->create();

    $component = livewire(ListEventDrafts::class)->assertSet('activeTab', 'pending');

    // Rejected in another tab after the queue was loaded and selected.
    app(RejectDraft::class)($raced, $this->admin);

    $component->callTableBulkAction('approveSelected', [$raced, $clean]);

    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();
    $body = collect($mailbox->pull())->pluck('body')->implode(' ');

    expect($body)->toContain('1 skipped: already reviewed')
        ->and($clean->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($raced->refresh()->review_status)->toBe(ReviewStatus::Rejected);
});
