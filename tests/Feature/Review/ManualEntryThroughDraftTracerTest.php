<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    freezeLondon('2026-10-16 12:00');
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    $this->seed(CategorySeeder::class);
    $this->category = Category::where('slug', 'community')->firstOrFail();

    $this->form = [
        'title' => 'Autumn Quiz Night',
        'description' => 'Teams of up to six. Bring your own snacks.',
        'category_id' => $this->category->id,
        'venue_id' => Venue::factory()->create()->id,
        'start_date' => '2026-10-24',
        'start_time' => '19:30',
        'organiser_contact' => 'quiz@example.test',
    ];
});

it('publishes manual entry through an approved manual draft linked to the event', function () {
    actingAs($this->admin);

    livewire(CreateEvent::class)
        ->fillForm($this->form)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(EventResource::getUrl('index'));

    expect(Event::count())->toBe(1)
        ->and(EventOccurrence::count())->toBe(1)
        ->and(EventDraft::count())->toBe(1);

    $event = Event::sole();
    $draft = EventDraft::sole();

    expect($draft->origin)->toBe(EventOrigin::Manual)
        ->and($draft->kind)->toBe(DraftKind::New)
        ->and($draft->review_status)->toBe(ReviewStatus::Approved)
        ->and($draft->extraction_method)->toBe(ExtractionMethod::Manual)
        ->and($draft->event_id)->toBe($event->id)
        ->and($draft->reviewed_by)->toBe($this->admin->id)
        ->and($draft->category_id)->toBe($this->category->id)
        ->and($draft->category_reason)->toBe('Chosen by admin')
        ->and($draft->reference)->toMatch('/^WOA-[A-HJ-NP-Z2-9]{6}$/')
        ->and($event->approved_by)->toBe($this->admin->id);

    $draftRow = DB::selectOne("select reviewed_at at time zone 'UTC' as t from event_drafts where id = ?", [$draft->id]);
    $eventRow = DB::selectOne("select approved_at at time zone 'UTC' as t from events where id = ?", [$event->id]);

    expect(substr((string) $draftRow->t, 0, 19))->toBe('2026-10-16 11:00:00')
        ->and(substr((string) $eventRow->t, 0, 19))->toBe('2026-10-16 11:00:00');
});
