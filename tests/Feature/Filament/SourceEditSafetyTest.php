<?php

use App\Enums\RunStatus;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * WR-03: editing a live source never reuses the old feed's ETag /
 * Last-Modified (a 304 from the new address would hide the new feed), and
 * the item identity and default time zone are fixed once the source has
 * recorded feed items (every item would look new and every linked event
 * would be reported missing, or every floating time would move).
 */

const EDIT_SAFETY_OLD_URL = 'https://old.example.org/events.ics';

const EDIT_SAFETY_NEW_URL = 'https://new.example.org/events.ics';

function editSafetyIcs(string $title = 'Quiz Night'): string
{
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Edit safety test//EN',
        'BEGIN:VEVENT', 'UID:quiz@edit-safety.invalid', 'DTSTAMP:20261017T050000Z', "SUMMARY:{$title}",
        'DTSTART;TZID=Europe/London:20261024T193000', 'DTEND;TZID=Europe/London:20261024T213000',
        'LOCATION:Town Hall', 'END:VEVENT', 'END:VCALENDAR',
    ])."\r\n";
}

/**
 * Both addresses answer with their own body and validators, and 304 when a
 * request sends back an ETag they recognise (both use "v1", as unrelated
 * servers easily can).
 */
function editSafetyServe(?Closure $during = null): void
{
    ingestFeed(function (Request $request) use ($during) {
        if ($during !== null) {
            $during();
        }

        if ($request->header('If-None-Match') === ['"v1"'] || $request->header('If-Modified-Since') === ['Sat, 17 Oct 2026 04:00:00 GMT']) {
            return Http::response('', 304, ['ETag' => '"v1"']);
        }

        $title = str_starts_with($request->url(), EDIT_SAFETY_NEW_URL) ? 'Bonfire Night' : 'Quiz Night';

        return Http::response(editSafetyIcs($title), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'ETag' => '"v1"',
            'Last-Modified' => 'Sat, 17 Oct 2026 04:00:00 GMT',
        ]);
    });
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
    actingAs(ingestAdmin());

    $this->source = Source::factory()->ics()->active()->create(['name' => 'Town Hall', 'url' => EDIT_SAFETY_OLD_URL]);
});

it('clears the stored validators when the feed URL changes, so the new feed is read in full', function () {
    editSafetyServe();
    ingestLiveRun($this->source);
    expect($this->source->refresh()->etag)->toBe('"v1"');

    livewire(EditSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['url' => EDIT_SAFETY_NEW_URL])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->source->refresh()->url)->toBe(EDIT_SAFETY_NEW_URL)
        ->and($this->source->etag)->toBeNull()
        ->and($this->source->last_modified)->toBeNull();

    $this->travel(1)->hour();
    $run = ingestLiveRun($this->source);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(EventDraft::query()->where('title', 'Bonfire Night')->exists())->toBeTrue();
});

it('keeps the validators when a save changes nothing about how the feed is read', function () {
    editSafetyServe();
    ingestLiveRun($this->source);

    livewire(EditSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['name' => 'Town Hall Events', 'notes' => 'Checked with the clerk.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->source->refresh()->etag)->toBe('"v1"')
        ->and($this->source->last_modified)->toBe('Sat, 17 Oct 2026 04:00:00 GMT');
});

it('never stores the old address\'s validators from a run in flight when the URL was changed', function () {
    // The admin saves a new URL while this run is fetching the old one.
    editSafetyServe(fn () => DB::table('sources')->where('id', $this->source->id)->update(['url' => EDIT_SAFETY_NEW_URL, 'etag' => null, 'last_modified' => null]));

    $run = ingestLiveRun($this->source);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($this->source->refresh()->url)->toBe(EDIT_SAFETY_NEW_URL)
        ->and($this->source->etag)->toBeNull()
        ->and($this->source->last_modified)->toBeNull();
});

it('refuses an item identity change once the source has recorded feed items', function () {
    editSafetyServe();
    ingestLiveRun($this->source);
    expect(SourceItem::query()->count())->toBe(1);

    livewire(EditSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['parser_config.identity' => 'summary_start'])
        ->call('save')
        ->assertHasFormErrors(['parser_config.identity'])
        ->assertSee(SourceForm::IDENTITY_LOCKED);

    expect($this->source->refresh()->parser_config['identity'])->toBe('uid')
        ->and($this->source->etag)->toBe('"v1"');
});

it('refuses a default time zone change once the source has recorded feed items', function () {
    editSafetyServe();
    ingestLiveRun($this->source);

    livewire(EditSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['default_timezone' => 'Europe/Paris'])
        ->call('save')
        ->assertHasFormErrors(['default_timezone'])
        ->assertSee(SourceForm::TIMEZONE_LOCKED);

    expect($this->source->refresh()->default_timezone)->toBe('Europe/London');
});

it('allows identity and time zone changes on a source with no recorded items, and clears its validators', function () {
    $this->source->forceFill(['etag' => '"stale"', 'last_modified' => 'Fri, 16 Oct 2026 04:00:00 GMT'])->save();

    livewire(EditSource::class, ['record' => $this->source->getRouteKey()])
        ->fillForm(['parser_config.identity' => 'summary_start', 'default_timezone' => 'Europe/Paris'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->source->refresh()->parser_config['identity'])->toBe('summary_start')
        ->and($this->source->default_timezone)->toBe('Europe/Paris')
        ->and($this->source->etag)->toBeNull()
        ->and($this->source->last_modified)->toBeNull();
});
