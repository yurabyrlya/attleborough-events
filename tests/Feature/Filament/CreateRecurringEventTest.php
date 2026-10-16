<?php

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Europe/London'));
    actingAs(User::factory()->create(['email' => 'admin@example.test']));

    $this->series = [
        'title' => 'Book Club',
        'description' => 'This month: anything with a lighthouse in it.',
        'category_id' => Category::factory()->create()->id,
        'venue_id' => Venue::factory()->create()->id,
        'start_date' => '2026-10-08',
        'start_time' => '19:30',
        'organiser_contact' => 'books@example.test',
        'repeats' => 'weekly',
    ];
});

it('creates a monthly last-Friday series ending on 31 Mar 2027 from the form', function () {
    livewire(CreateEvent::class)
        ->fillForm([
            ...$this->series,
            'start_date' => '2026-10-30',
            'repeats' => 'monthly_last',
            'repeat_ends' => 'until',
            'repeat_until' => '2027-03-31',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::sole()->recurrence_rule)->toBe('FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z');
});

it('creates a second-Thursday series that ends after 6 times', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'repeats' => 'monthly_nth', 'repeat_ends' => 'count', 'repeat_count' => 6])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::sole()->recurrence_rule)->toBe('FREQ=MONTHLY;BYDAY=2TH;COUNT=6');
});

it('rejects a monthly nth pattern once the start date moves to a fifth weekday', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'repeats' => 'monthly_nth'])
        ->fillForm(['start_date' => '2026-10-29'])
        ->call('create')
        ->assertHasFormErrors(['repeats']);

    expect(Event::count())->toBe(0);
});

it('rejects an end date for the series before its start date', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'repeat_ends' => 'until', 'repeat_until' => '2026-10-01'])
        ->call('create')
        ->assertHasFormErrors(['repeat_until']);
});

it('rejects a repeat count outside 1..200', function (int $count) {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'repeat_ends' => 'count', 'repeat_count' => $count])
        ->call('create')
        ->assertHasFormErrors(['repeat_count']);
})->with([0, 201]);

it('requires a date when the series ends on a date', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'repeat_ends' => 'until', 'repeat_until' => null])
        ->call('create')
        ->assertHasFormErrors(['repeat_until' => 'required']);
});

it('requires a number when the series ends after a number of times', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'repeat_ends' => 'count', 'repeat_count' => null])
        ->call('create')
        ->assertHasFormErrors(['repeat_count' => 'required']);
});

it('stores a 10:00 weekly start on a BST date as start_time 10:00:00', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'start_time' => '10:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DB::selectOne('select start_time::text as t from events')->t)->toBe('10:00:00');
});

it('hides the end date while the event repeats', function () {
    livewire(CreateEvent::class)
        ->fillForm(['start_date' => '2026-10-08', 'repeats' => 'none'])
        ->assertFormFieldVisible('end_date')
        ->fillForm(['repeats' => 'weekly'])
        ->assertFormFieldHidden('end_date');
});

it('previews the next 5 dates in UK format before saving', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'start_time' => '10:00'])
        ->assertSeeInOrder(['Thu 8 Oct, 10am', 'Thu 15 Oct, 10am', 'Thu 22 Oct, 10am', 'Thu 29 Oct, 10am', 'Thu 5 Nov, 10am'])
        ->assertDontSee('Thu 12 Nov, 10am');
});

it('previews all-day series as "all day"', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->series, 'all_day' => true, 'start_date' => '2026-10-25', 'repeats' => 'weekly'])
        ->assertSee('Sun 25 Oct, all day');
});
