<?php

use App\Enums\EventOrigin;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\Events\EventResource;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * ADM-06: Filament global search finds drafts (title, reference, organiser,
 * typed venue) and published events (title, organiser). PostgreSQL search
 * is case-insensitive substring matching.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    $this->draft = EventDraft::factory()->freeTextVenue()->create([
        'title' => 'Quiz night',
        'organiser_name' => 'Attleborough Lions',
        'venue_name' => 'The Griffin',
        'start_date' => '2026-10-24',
        'start_time' => '19:30:00',
    ]);

    $this->event = app(PublishEvent::class)(new PublishableEventData(
        title: 'Quiz night',
        description: 'Teams of up to six.',
        categoryId: Category::factory()->create()->id,
        venueId: Venue::factory()->create()->id,
        startDate: '2026-10-30',
        startTime: '19:00:00',
        organiserName: 'Rotary Club',
        organiserContact: 'quiz@example.test',
    ), $this->admin, EventOrigin::Manual);

    EventDraft::factory()->create(['title' => 'Bonfire night', 'organiser_name' => 'Scouts']);
});

/**
 * @return list<string>
 */
function resultUrls(string $resource, string $search): array
{
    return $resource::getGlobalSearchResults($search)
        ->map(fn (GlobalSearchResult $result): string => $result->url)
        ->values()
        ->all();
}

it('finds a draft by title and links to its review page with status and start details', function () {
    $results = EventDraftResource::getGlobalSearchResults('quiz');

    expect($results)->toHaveCount(1);

    $result = $results->first();

    expect($result->url)->toBe(EventDraftResource::getUrl('review', ['record' => $this->draft]))
        ->and((string) $result->title)->toBe('Quiz night')
        ->and($result->details)->toBe(['Status' => 'Pending review', 'Starts' => 'Sat 24 Oct, 7:30pm']);
});

it('finds a draft by lower-cased reference, organiser name and typed venue', function () {
    $review = EventDraftResource::getUrl('review', ['record' => $this->draft]);

    expect(resultUrls(EventDraftResource::class, strtolower($this->draft->reference)))->toBe([$review])
        ->and(resultUrls(EventDraftResource::class, 'lions'))->toBe([$review])
        ->and(resultUrls(EventDraftResource::class, 'GRIFFIN'))->toBe([$review]);
});

it('finds a published event by title and by organiser, linking to its edit page', function () {
    $edit = EventResource::getUrl('edit', ['record' => $this->event]);

    expect(resultUrls(EventResource::class, 'quiz'))->toBe([$edit])
        ->and(resultUrls(EventResource::class, 'rotary'))->toBe([$edit]);
});

it('returns both the draft and the event for the same query in the panel search', function () {
    livewire(GlobalSearch::class)
        ->set('search', 'quiz')
        ->assertSee(EventDraftResource::getUrl('review', ['record' => $this->draft]))
        ->assertSee(EventResource::getUrl('edit', ['record' => $this->event]))
        ->assertDontSee('Bonfire night');
});

it('gives a non-admin no global search', function () {
    actingAs(User::factory()->create(['email' => 'someone@example.test']));

    expect(EventDraftResource::canGloballySearch())->toBeFalse()
        ->and(EventResource::canGloballySearch())->toBeFalse();

    $this->get(EventDraftResource::getUrl('index'))->assertForbidden();
});

it('keeps every event row reachable by search whatever its title case', function () {
    expect(Event::query()->count())->toBe(1)
        ->and(resultUrls(EventResource::class, 'QUIZ NIGHT'))->toHaveCount(1);
});
