<?php

use App\Enums\EventOrigin;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

it('lets the admin create a one-off event in Filament that then appears on the public list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));

    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $this->seed(CategorySeeder::class);
    $venue = Venue::factory()->create(['name' => 'Connaught Hall']);
    $category = Category::where('slug', 'community')->firstOrFail();

    actingAs($admin);

    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Autumn Quiz Night',
            'description' => 'Teams of up to six. Bring your own snacks.',
            'category_id' => $category->id,
            'venue_id' => $venue->id,
            'start_date' => '2026-10-24',
            'start_time' => '19:30',
            'organiser_contact' => 'quiz@example.test',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::count())->toBe(1);
    $event = Event::sole();
    expect($event->origin)->toBe(EventOrigin::Manual)
        ->and($event->approved_by)->toBe($admin->id)
        ->and($event->approved_at)->not->toBeNull();

    expect(EventOccurrence::count())->toBe(1);
    $row = DB::selectOne("select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ?", [$event->id]);
    expect(substr((string) $row->s, 0, 19))->toBe('2026-10-24 18:30:00');

    get('/')
        ->assertOk()
        ->assertSee('Autumn Quiz Night')
        ->assertSee('Sat 24 Oct, 7:30pm')
        ->assertSee('Saturday 24 October')
        ->assertSee('Connaught Hall');
});

it('forbids any user other than the configured admin from the panel', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    actingAs($intruder)->get('/admin')->assertForbidden();
});

it('lets the configured admin into the panel', function () {
    $admin = User::factory()->create(['email' => 'admin@example.test']);

    actingAs($admin)->get('/admin')->assertOk();
});
