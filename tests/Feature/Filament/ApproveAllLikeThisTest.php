<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Models\Category;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveAllLikeThis;
use Database\Seeders\CategorySeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * D-03 (EXT-01): "Approve all like this" approves every pending new draft
 * from the same source with the same normalised title and the same venue,
 * each through ApproveDraft after the Phase 2 D-21 bulk checks, and names
 * every skip. With the D-21 fallback description and the D-22 default venue
 * the Town Council's weekly Yoga items publish in one click.
 */

const LIKE_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const LIKE_YOGA = '[name removed] ~ Yoga';

/**
 * The text lines of every notification sent so far (title, then one line
 * per <p> in the body).
 *
 * @return list<string>
 */
function likeNotificationLines(): array
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

/**
 * The pending Town Council Yoga drafts in start order.
 *
 * @return Collection<int, EventDraft>
 */
function likeYogaDrafts(): Collection
{
    return EventDraft::query()
        ->whereNotNull('source_item_id')
        ->where('title', LIKE_YOGA)
        ->orderBy('start_date')
        ->get();
}

/**
 * A factory draft (test-only) attributed to $source, as the pipeline would
 * write it.
 *
 * @param  array<string, mixed>  $attributes
 */
function likeSourcedDraft(Source $source, array $attributes = []): EventDraft
{
    return EventDraft::factory()->create([
        'origin' => EventOrigin::Discovered,
        'extraction_method' => ExtractionMethod::Ics,
        'source_id' => $source->id,
        'title' => LIKE_YOGA,
        ...$attributes,
    ]);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->admin = ingestAdmin();
    actingAs($this->admin);

    $this->townHall = Venue::factory()->create(['name' => 'Town Hall']);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => LIKE_TC_URL,
        'default_venue_id' => $this->townHall->id,
        'default_category_id' => Category::query()->where('slug', 'community')->value('id'),
    ]);

    ingestFeed(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'));
    ingestLiveRun($this->tc);

    $this->yoga = likeYogaDrafts();
});

it('approves every Town Council Yoga draft at the Town Hall in one click and counts them', function () {
    expect($this->yoga)->toHaveCount(3)
        ->and($this->yoga->map(fn (EventDraft $d): string => $d->start_date?->format('Y-m-d').' '.substr((string) $d->start_time, 0, 5))->all())
        ->toBe(['2026-10-18 10:00', '2026-10-21 18:00', '2026-10-25 10:00'])
        ->and($this->yoga->pluck('venue_id')->unique()->all())->toBe([$this->townHall->id]);

    livewire(ListEventDrafts::class)
        ->callAction(TestAction::make('approveAllLikeThis')->table($this->yoga->first()))
        ->assertHasNoActionErrors();

    expect(likeNotificationLines())->toBe(['Approved 3 of 3.']);

    foreach ($this->yoga as $draft) {
        expect($draft->refresh()->review_status)->toBe(ReviewStatus::Approved)
            ->and($draft->event_id)->not->toBeNull();
    }

    get('/')->assertOk()->assertSee(LIKE_YOGA);
});

it('names each skipped draft by its reason and approves the rest', function () {
    [$first, $second, $third] = $this->yoga->all();

    $other = EventDraft::query()->whereNotNull('source_item_id')->where('title', '!=', LIKE_YOGA)->firstOrFail();
    $second->forceFill(['duplicate_of_draft_id' => $other->id, 'duplicate_reason' => 'fuzzy', 'duplicate_score' => 0.5])->save();
    $third->forceFill(['parse_errors' => ['Could not read the end time']])->save();

    livewire(ListEventDrafts::class)
        ->callAction(TestAction::make('approveAllLikeThis')->table($first));

    expect(likeNotificationLines())->toBe([
        'Approved 1 of 3.',
        '1 skipped: possible duplicate',
        '1 skipped: has parse errors',
    ])
        ->and($first->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($second->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($third->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('matches the title case- and space-insensitively but leaves other sources and other venues alone', function () {
    $otherSource = Source::factory()->ics()->active()->create(['name' => 'Attleborough Baptist Church']);
    $fromOtherSource = likeSourcedDraft($otherSource, ['venue_id' => $this->townHall->id]);
    $atOtherVenue = likeSourcedDraft($this->tc, ['venue_id' => Venue::factory()->create(['name' => 'Connaught Hall'])->id]);
    $sameButShouty = likeSourcedDraft($this->tc, ['title' => '  [NAME REMOVED]   ~ yoga ', 'venue_id' => $this->townHall->id, 'start_date' => '2026-11-01']);

    $result = app(ApproveAllLikeThis::class)($this->yoga->first(), $this->admin);

    expect($result)->toBe(['approved' => 4, 'total' => 4, 'skipped' => []])
        ->and($sameButShouty->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($fromOtherSource->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($atOtherVenue->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('works through the matches in queue order', function () {
    $matches = app(ApproveAllLikeThis::class)->matching($this->yoga->last());

    expect($matches->pluck('id')->all())->toBe($this->yoga->pluck('id')->all());
});

it('names the number of matching drafts in the confirmation', function () {
    livewire(ListEventDrafts::class)
        ->mountAction(TestAction::make('approveAllLikeThis')->table($this->yoga->first()))
        ->assertActionMounted(TestAction::make('approveAllLikeThis')->table($this->yoga->first()))
        ->assertMountedActionModalSee([
            'Approve all drafts like this?',
            '3 pending drafts from Attleborough Town Council titled "'.LIKE_YOGA.'" at Town Hall.',
            'Approve all',
            'Keep as is',
        ]);
});

it('offers the action only on pending new drafts that came from a source', function () {
    $manual = EventDraft::factory()->manual()->create();
    $approved = $this->yoga->first();
    app(ApproveAllLikeThis::class)($approved, $this->admin);

    livewire(ListEventDrafts::class)
        ->assertActionHidden(TestAction::make('approveAllLikeThis')->table($manual))
        ->set('activeTab', 'all')
        ->assertActionHidden(TestAction::make('approveAllLikeThis')->table($approved->refresh()));
});

it('refuses a non-admin before approving anything', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => app(ApproveAllLikeThis::class)($this->yoga->first(), $intruder))
        ->toThrow(AuthorizationException::class);

    expect($this->yoga->map(fn (EventDraft $d) => $d->refresh()->review_status)->unique()->all())
        ->toBe([ReviewStatus::PendingReview]);
});
