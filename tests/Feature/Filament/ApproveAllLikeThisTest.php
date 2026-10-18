<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveAllLikeThis;
use Database\Seeders\CategorySeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
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

it('skips a match whose description may still hold a phone number and approves the rest (review CR-01)', function () {
    [$first, $second, $third] = $this->yoga->all();

    $second->forceFill(['description' => 'Gentle yoga. Box office (01953) 452300'])->save();

    livewire(ListEventDrafts::class)
        ->callAction(TestAction::make('approveAllLikeThis')->table($first));

    expect(likeNotificationLines())->toBe([
        'Approved 2 of 3.',
        '1 skipped: description may contain a phone number or email',
    ])
        ->and($second->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($second->event_id)->toBeNull()
        ->and($third->refresh()->review_status)->toBe(ReviewStatus::Approved);
});

it('skips a match whose edited description is over 200 characters and approves the rest (VER-01)', function () {
    [$first, $second, $third] = $this->yoga->all();

    $second->forceFill(['description' => str_repeat('Gentle yoga. ', 16)])->save();

    livewire(ListEventDrafts::class)
        ->callAction(TestAction::make('approveAllLikeThis')->table($first));

    expect(likeNotificationLines())->toBe([
        'Approved 2 of 3.',
        '1 skipped: description longer than 200 characters',
    ])
        ->and($second->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($third->refresh()->review_status)->toBe(ReviewStatus::Approved);
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

/**
 * The exception PostgreSQL's deadlock (40P01) reaches Laravel as.
 */
function likeDeadlock(): QueryException
{
    $pdo = new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
    $pdo->errorInfo = ['40P01', 7, 'ERROR:  deadlock detected'];

    return new QueryException('pgsql', 'insert into "events"', [], $pdo);
}

/**
 * Publishing the $nth event from now on throws $exception (a stand-in for
 * a deadlock victim or a documented refusal deep inside the action).
 */
function likeFailNthPublish(int $nth, Throwable $exception): void
{
    $calls = 0;
    Event::creating(function () use (&$calls, $nth, $exception): void {
        if (++$calls === $nth) {
            throw $exception;
        }
    });
}

describe('failures part way through (review WR-08)', function () {
    it('skips a deadlock victim, approves the rest and names it in the summary', function () {
        likeFailNthPublish(2, likeDeadlock());

        livewire(ListEventDrafts::class)
            ->callAction(TestAction::make('approveAllLikeThis')->table($this->yoga->first()))
            ->assertHasNoActionErrors();

        [$first, $second, $third] = $this->yoga->all();

        expect(likeNotificationLines())->toBe([
            'Approved 2 of 3.',
            '1 skipped: another change was being saved at the same moment, so try again',
        ])
            ->and($first->refresh()->review_status)->toBe(ReviewStatus::Approved)
            ->and($second->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
            ->and($second->event_id)->toBeNull()
            ->and($third->refresh()->review_status)->toBe(ReviewStatus::Approved);
    });

    it('counts a documented refusal as not ready to publish and carries on', function () {
        likeFailNthPublish(1, new InvalidArgumentException('The feed item belongs to another source.'));

        $result = app(ApproveAllLikeThis::class)($this->yoga->first(), $this->admin);

        expect($result)->toBe(['approved' => 2, 'total' => 3, 'skipped' => ['blocked' => 1]])
            ->and($this->yoga->first()->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
    });

    it('still fails loudly on a database error that is not a concurrency conflict', function () {
        $pdo = new PDOException('SQLSTATE[23502]: Not null violation');
        $pdo->errorInfo = ['23502', 7, 'ERROR:  null value'];
        likeFailNthPublish(1, new QueryException('pgsql', 'insert into "events"', [], $pdo));

        expect(fn () => app(ApproveAllLikeThis::class)($this->yoga->first(), $this->admin))->toThrow(QueryException::class);
    });

    it('skips a deadlock victim in bulk approve and approves the rest', function () {
        likeFailNthPublish(1, likeDeadlock());

        livewire(ListEventDrafts::class)
            ->callTableBulkAction('approveSelected', $this->yoga->all());

        expect(likeNotificationLines())->toContain('Approved 2 of 3. The rest were skipped:')
            ->toContain('1 skipped: another change was being saved at the same moment, so try again')
            ->and($this->yoga->first()->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
    });

    it('tells the admin to try again when a single approval is a deadlock victim, instead of a 500', function () {
        likeFailNthPublish(1, likeDeadlock());
        $draft = $this->yoga->first();

        livewire(ReviewEventDraft::class, ['record' => $draft->id])
            ->callAction('approve')
            ->assertHasNoActionErrors();

        expect(likeNotificationLines())->toBe([ReviewActions::BUSY_TITLE, ReviewActions::BUSY_BODY])
            ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
            ->and($draft->event_id)->toBeNull();
    });

    it('shows a documented refusal from a single review action as a message, instead of a 500', function () {
        likeFailNthPublish(1, new InvalidArgumentException('The feed item belongs to another source.'));
        $draft = $this->yoga->first();

        livewire(ReviewEventDraft::class, ['record' => $draft->id])
            ->callAction('approve')
            ->assertHasNoActionErrors();

        expect(likeNotificationLines())->toBe(["Can't publish yet", 'The feed item belongs to another source.'])
            ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
    });
});
