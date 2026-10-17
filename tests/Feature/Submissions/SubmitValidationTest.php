<?php

use App\Models\Category;
use App\Models\EventDraft;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * SUB-01 / SUB-03: every required field is enforced before anything is
 * written, "today" is the London date, lengths count characters, dates and
 * times are strict, blanks are stored as null and submitted text is stored
 * verbatim but only ever shown escaped. SUB-02: the thanks page is PRG.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    fakeTurnstilePass();
    $this->seed(CategorySeeder::class);
});

/**
 * POST the form from /submit (so a validation failure redirects back there).
 *
 * @param  array<string, mixed>  $overrides
 */
function submitForm(array $overrides = []): TestResponse
{
    return test()->from('/submit')->post('/submit', validSubmission($overrides));
}

it('refuses a submission without a date, keeps what was typed and links the error', function () {
    submitForm(['title' => 'Quiz Night', 'start_date' => ''])
        ->assertRedirect('/submit')
        ->assertSessionHasErrors(['start_date' => 'Add the date of the event.']);

    expect(EventDraft::count())->toBe(0);

    $html = (string) $this->followingRedirects()->from('/submit')
        ->post('/submit', validSubmission(['title' => 'Quiz Night', 'start_date' => '']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Please check these')
        ->toContain('href="#start_date"')
        ->toContain('value="Quiz Night"')
        ->toMatch('/<input id="start_date"[^>]*aria-invalid="true"/')
        ->toMatch('/<input id="start_date"[^>]*aria-describedby="start_date-error"/')
        ->toContain('<span class="sr-only">Error:</span> Add the date of the event.');

    expect(EventDraft::count())->toBe(0);
});

dataset('missing venue', [
    'no venue chosen' => [['venue_choice' => ''], 'venue_choice', "Choose a venue, or pick 'Somewhere else' and type the address."],
    'somewhere else without a name' => [['venue_choice' => 'other', 'venue_address' => '1 High Street'], 'venue_name', 'Add the name of the place.'],
    'somewhere else without an address' => [['venue_choice' => 'other', 'venue_name' => 'The Barn'], 'venue_address', 'Add the address of the place.'],
    'a venue that does not exist' => [['venue_choice' => '999999'], 'venue_choice', "Choose a venue, or pick 'Somewhere else' and type the address."],
    'a venue id that is not a number' => [['venue_choice' => '1 OR 1=1'], 'venue_choice', "Choose a venue, or pick 'Somewhere else' and type the address."],
]);

it('refuses a submission without a usable venue', function (array $overrides, string $field, string $message) {
    submitForm($overrides)
        ->assertRedirect('/submit')
        ->assertSessionHasErrors([$field => $message]);

    expect(EventDraft::count())->toBe(0);
})->with('missing venue');

dataset('missing required fields', [
    'title' => ['title', 'Add the name of the event.'],
    'description' => ['description', 'Add a short description so people know what to expect.'],
    'category' => ['category_choice', "Choose a category, or pick 'Not sure'."],
    'start time' => ['start_time', "Add a start time, or tick 'All day'."],
    'price' => ['price_type', "Say whether it's free or paid, or pick 'Not sure'."],
    'organiser name' => ['organiser_name', 'Add your name or the name of your group.'],
    'consent' => ['consent', 'Please tick the box to say we can use these details.'],
]);

it('refuses a submission missing a required field', function (string $field, string $message) {
    submitForm([$field => ''])
        ->assertRedirect('/submit')
        ->assertSessionHasErrors([$field => $message]);

    expect(EventDraft::count())->toBe(0);
})->with('missing required fields');

it('needs an email address or a website', function () {
    submitForm(['organiser_email' => '', 'organiser_website' => ''])
        ->assertSessionHasErrors(['organiser_email' => 'Add an email address or a website so we can check the details.']);

    expect(EventDraft::count())->toBe(0);

    submitForm(['organiser_email' => '', 'organiser_website' => 'https://makers.example.org'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('submit.thanks'));

    expect(EventDraft::sole()->organiser_contact)->toBeNull()
        ->and(EventDraft::sole()->organiser_website)->toBe('https://makers.example.org');
});

it('treats a whitespace-only title as missing', function () {
    submitForm(['title' => "   \t  "])
        ->assertSessionHasErrors(['title' => 'Add the name of the event.']);

    expect(EventDraft::count())->toBe(0);
});

dataset('boundaries', [
    'title of 120 characters' => [['title' => str_repeat('a', 120)], true],
    'title of 121 characters' => [['title' => str_repeat('a', 121)], false],
    'title of 120 accented letters' => [['title' => str_repeat('é', 120)], true],
    'title of 121 accented letters' => [['title' => str_repeat('é', 121)], false],
    'description of 5000 characters' => [['description' => str_repeat('b', 5000)], true],
    'description of 5001 characters' => [['description' => str_repeat('b', 5001)], false],
    'start date today' => [['start_date' => '2026-10-20'], true],
    'start date yesterday' => [['start_date' => '2026-10-19'], false],
    'start date 12 months ahead' => [['start_date' => '2027-10-20'], true],
    'start date 12 months and a day ahead' => [['start_date' => '2027-10-21'], false],
    'end date before the start' => [['start_date' => '2026-11-14', 'end_date' => '2026-11-13'], false],
    'end date on the start' => [['start_date' => '2026-11-14', 'end_date' => '2026-11-14'], true],
    'end time equal to the start time on a later end date' => [['start_date' => '2026-11-14', 'end_date' => '2026-11-15', 'start_time' => '19:00', 'end_time' => '19:00'], true],
    'end time earlier than the start time (overnight)' => [['start_time' => '19:00', 'end_time' => '01:00'], true],
    'repeats text of 200 characters' => [['repeats_text' => str_repeat('c', 200)], true],
    'repeats text of 201 characters' => [['repeats_text' => str_repeat('c', 201)], false],
    'price text of 60 characters' => [['price_text' => str_repeat('d', 60)], true],
    'price text of 61 characters' => [['price_text' => str_repeat('d', 61)], false],
]);

it('accepts or refuses values at the limits', function (array $overrides, bool $accepted) {
    $response = submitForm($overrides);

    if ($accepted) {
        $response->assertSessionHasNoErrors()->assertRedirect(route('submit.thanks'));
        expect(EventDraft::count())->toBe(1);
    } else {
        $response->assertRedirect('/submit')->assertSessionHasErrors();
        expect(EventDraft::count())->toBe(0);
    }
})->with('boundaries');

it('refuses a same-day end time equal to the start time with the event form wording', function (array $overrides) {
    submitForm(['start_date' => '2026-11-14', 'start_time' => '19:00', 'end_time' => '19:00', ...$overrides])
        ->assertRedirect('/submit')
        ->assertSessionHasErrors(['end_time' => 'The end time must be different from the start time.']);

    expect(EventDraft::count())->toBe(0);
})->with([
    'no end date' => [['end_date' => '']],
    'end date on the start date' => [['end_date' => '2026-11-14']],
]);

it('stores a 120-character accented title in full', function () {
    submitForm(['title' => str_repeat('é', 120)])->assertSessionHasNoErrors();

    expect(EventDraft::sole()->title)->toBe(str_repeat('é', 120));
});

it('uses the London date for today just after midnight in summer', function () {
    freezeLondon('2026-07-01 00:30');

    submitForm(['start_date' => '2026-06-30'])
        ->assertSessionHasErrors(['start_date' => "The date can't be in the past."]);

    expect(EventDraft::count())->toBe(0);

    submitForm(['start_date' => '2026-07-01'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('submit.thanks'));

    expect(EventDraft::sole()->start_date->toDateString())->toBe('2026-07-01');
});

it('says how far ahead events can be listed', function () {
    submitForm(['start_date' => '2027-10-21'])
        ->assertSessionHasErrors(['start_date' => 'We list events up to 12 months ahead.']);
});

dataset('malformed dates and times', [
    'impossible date in the past' => [['start_date' => '2026-02-30'], 'start_date'],
    'impossible date ahead' => [['start_date' => '2027-02-30'], 'start_date'],
    'day-first date' => [['start_date' => '14/11/2026'], 'start_date'],
    'date with a time' => [['start_date' => '2026-11-14 10:00'], 'start_date'],
    'impossible end date' => [['end_date' => '2027-02-30'], 'end_date'],
    'single-digit hour' => [['start_time' => '9:00'], 'start_time'],
    'hour 24' => [['start_time' => '24:00'], 'start_time'],
    'time with seconds' => [['start_time' => '19:30:00'], 'start_time'],
    'time in words' => [['start_time' => '7pm'], 'start_time'],
    'malformed end time' => [['end_time' => '25:61'], 'end_time'],
]);

it('refuses dates and times that are not strict Y-m-d and H:i', function (array $overrides, string $field) {
    submitForm($overrides)->assertSessionHasErrors($field);

    expect(EventDraft::count())->toBe(0);
})->with('malformed dates and times');

it('says to enter a real date for an impossible one', function () {
    submitForm(['start_date' => '2027-02-30'])
        ->assertSessionHasErrors(['start_date' => 'Enter a real date, for example 2026-11-14.']);
});

it('stores the London wall-clock schedule exactly as typed', function () {
    submitForm([
        'start_date' => '2026-10-25',
        'start_time' => '01:30',
        'end_date' => '2026-10-25',
        'end_time' => '23:45',
    ])->assertSessionHasNoErrors();

    $row = DB::selectOne('select start_date::text as sd, start_time::text as st, end_date::text as ed, end_time::text as et, timezone from event_drafts');

    expect($row->sd)->toBe('2026-10-25')
        ->and($row->st)->toBe('01:30:00')
        ->and($row->ed)->toBe('2026-10-25')
        ->and($row->et)->toBe('23:45:00')
        ->and($row->timezone)->toBe('Europe/London');
});

it('does not need a time for an all-day event and drops any times sent', function () {
    submitForm(['all_day' => '1', 'start_time' => '', 'end_time' => '17:00'])
        ->assertSessionHasNoErrors();

    $draft = EventDraft::sole();

    expect($draft->all_day)->toBeTrue()
        ->and($draft->start_time)->toBeNull()
        ->and($draft->end_time)->toBeNull();
});

it('stores blank optional fields as null, never an empty string', function () {
    submitForm([
        'end_date' => '',
        'end_time' => '   ',
        'repeats_text' => '  ',
        'price_text' => '',
        'organiser_website' => '',
        'source_url' => '',
        'venue_name' => 'Typed before picking a known venue',
    ])->assertSessionHasNoErrors();

    $row = DB::selectOne('select end_date, end_time, repeats_text, price_text, organiser_website, source_url, venue_name, venue_address, venue_postcode from event_drafts');

    foreach ((array) $row as $column => $value) {
        expect($value)->toBeNull("{$column} should be null");
    }
});

it('stores Not sure as no submitted category, with the Other fallback left to review', function () {
    submitForm(['category_choice' => 'not_sure'])->assertSessionHasNoErrors();

    expect(EventDraft::sole()->submitted_category_id)->toBeNull();
});

it('refuses a category that does not exist', function () {
    submitForm(['category_choice' => '999999'])
        ->assertSessionHasErrors(['category_choice' => "Choose a category, or pick 'Not sure'."]);

    expect(EventDraft::count())->toBe(0);
});

it('stores the contact choice and the repeats text as sent', function () {
    submitForm([
        'contact_public' => '1',
        'repeats_text' => 'Every Tuesday until December',
        'family_friendly' => '1',
        'price_type' => 'paid',
        'price_text' => '£5, children free',
    ])->assertSessionHasNoErrors();

    $draft = EventDraft::sole();

    expect($draft->contact_public)->toBeTrue()
        ->and($draft->repeats_text)->toBe('Every Tuesday until December')
        ->and($draft->recurrence_rule)->toBeNull()
        ->and($draft->family_friendly)->toBeTrue()
        ->and($draft->price_type->value)->toBe('paid')
        ->and($draft->price_text)->toBe('£5, children free');
});

dataset('bad links and postcodes', [
    'javascript website' => [['organiser_website' => 'javascript:alert(1)'], 'organiser_website'],
    'ftp original link' => [['source_url' => 'ftp://example.org/event'], 'source_url'],
    'website without a scheme' => [['organiser_website' => 'makers.example.org'], 'organiser_website'],
    'not an email' => [['organiser_email' => 'makers at example'], 'organiser_email'],
    'not a postcode' => [['venue_choice' => 'other', 'venue_name' => 'Barn', 'venue_address' => '1 Lane', 'venue_postcode' => 'ATTLEBOROUGH'], 'venue_postcode'],
    'bad price type' => [['price_type' => 'donation'], 'price_type'],
]);

it('refuses unsafe links, bad emails, postcodes and price types', function (array $overrides, string $field) {
    submitForm($overrides)->assertSessionHasErrors($field);

    expect(EventDraft::count())->toBe(0);
})->with('bad links and postcodes');

it('accepts a lower-case postcode and stores it in capitals', function () {
    submitForm([
        'venue_choice' => 'other',
        'venue_name' => 'The Old Barn',
        'venue_address' => 'Church Street',
        'venue_postcode' => 'nr17 2ah',
    ])->assertSessionHasNoErrors();

    expect(EventDraft::sole()->venue_postcode)->toBe('NR17 2AH');
});

it('stores HTML in a title verbatim and only ever shows it escaped', function () {
    $script = '<script>alert(1)</script>';

    $html = (string) $this->followingRedirects()->from('/submit')
        ->post('/submit', validSubmission(['title' => $script, 'start_date' => '']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain($script)
        ->toContain('value="&lt;script&gt;alert(1)&lt;/script&gt;"');

    $thanks = (string) $this->followingRedirects()
        ->post('/submit', validSubmission(['title' => $script]))
        ->assertOk()
        ->getContent();

    expect($thanks)->not->toContain($script)
        ->and(EventDraft::sole()->title)->toBe($script)
        ->and(EventDraft::sole()->raw_payload['title'])->toBe($script);
});

it('gives two submissions at the same instant different references', function () {
    submitForm(['title' => 'First'])->assertSessionHasNoErrors();
    submitForm(['title' => 'Second'])->assertSessionHasNoErrors();

    $references = EventDraft::query()->pluck('reference');

    expect($references)->toHaveCount(2)
        ->and($references->unique())->toHaveCount(2);
});

it('creates nothing when the thanks page is refreshed', function () {
    submitForm()->assertRedirect(route('submit.thanks'));

    get('/submit/thanks')->assertOk()->assertSee('Your reference:');
    get('/submit/thanks')->assertRedirect(route('submit.create'));

    expect(EventDraft::count())->toBe(1);
});

it('sends a visitor to the form when there is nothing to thank them for', function () {
    get('/submit/thanks')->assertRedirect(route('submit.create'));

    expect(EventDraft::count())->toBe(0)
        ->and(Category::count())->toBeGreaterThan(0);
});
