<?php

use App\Enums\PriceType;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    // A BST date: Filament must not shift wall-clock times.
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/London'));
    actingAs(User::factory()->create(['email' => 'admin@example.test']));

    $this->base = [
        'title' => 'Summer Fete',
        'description' => 'Stalls, games and a dog show.',
        'category_id' => Category::factory()->create()->id,
        'venue_id' => Venue::factory()->create()->id,
        'start_date' => '2026-07-11',
        'start_time' => '10:00',
        'organiser_contact' => 'fete@example.test',
    ];
});

it('stores a 10:00 TimePicker value on a BST date as start_time 10:00:00', function () {
    livewire(CreateEvent::class)
        ->fillForm($this->base)
        ->call('create')
        ->assertHasNoFormErrors();

    $row = DB::selectOne('select start_time::text as t from events');

    expect($row->t)->toBe('10:00:00');
});

it('rejects an end time equal to the start time on the same day', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->base, 'end_time' => '10:00'])
        ->call('create')
        ->assertHasFormErrors(['end_time']);

    expect(Event::count())->toBe(0);
});

it('rejects an end date before the start date', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->base, 'end_date' => '2026-07-10'])
        ->call('create')
        ->assertHasFormErrors(['end_date']);

    expect(Event::count())->toBe(0);
});

it('accepts an end time earlier than the start time as overnight', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->base, 'start_time' => '21:00', 'end_time' => '01:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::count())->toBe(1);
});

it('requires a source URL when there is no organiser contact', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->base, 'organiser_contact' => null, 'source_url' => null])
        ->call('create')
        ->assertHasFormErrors(['source_url']);
});

it('creates an all-day multi-day event with price, family-friendly and source note as one occurrence', function () {
    livewire(CreateEvent::class)
        ->fillForm([
            ...$this->base,
            'all_day' => true,
            'start_time' => null,
            'start_date' => '2026-10-31',
            'end_date' => '2026-11-01',
            'family_friendly' => true,
            'price_type' => PriceType::Free->value,
            'price_text' => 'Free entry',
            'organiser_name' => 'Attleborough Lions',
            'source_note' => 'Flyer at the town hall',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::sole();

    expect($event->all_day)->toBeTrue()
        ->and($event->start_time)->toBeNull()
        ->and($event->family_friendly)->toBeTrue()
        ->and($event->price_type)->toBe(PriceType::Free)
        ->and($event->price_text)->toBe('Free entry')
        ->and($event->organiser_name)->toBe('Attleborough Lions')
        ->and($event->source_note)->toBe('Flyer at the town hall')
        ->and(EventOccurrence::where('event_id', $event->id)->count())->toBe(1);
});
