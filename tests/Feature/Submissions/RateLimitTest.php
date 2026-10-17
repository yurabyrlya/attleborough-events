<?php

use App\Models\EventDraft;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
 * SUB-04 (rate-limit layer): POST /submit is limited per IP to
 * review.submission.per_hour (5) and per_day (10) by the 'submissions'
 * limiter. GET /submit is never throttled. Over the limit the visitor gets
 * the branded 429 page with the email route.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    fakeTurnstilePass();
});

function postSubmitFrom(string $ip): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post('/submit', []);
}

it('lets five POSTs from one IP through and answers the sixth with the branded 429 page', function () {
    foreach (range(1, 5) as $attempt) {
        expect(postSubmitFrom('203.0.113.5')->getStatusCode())->not->toBe(429, "attempt {$attempt}");
    }

    postSubmitFrom('203.0.113.5')
        ->assertStatus(429)
        ->assertSee('Too many submissions from here today')
        ->assertSee('hello@whatsonattleborough.co.uk');
});

it('keys the limit on the exact IP, so another address is unaffected', function () {
    foreach (range(1, 6) as $attempt) {
        postSubmitFrom('203.0.113.5');
    }

    expect(postSubmitFrom('203.0.113.5')->getStatusCode())->toBe(429)
        ->and(postSubmitFrom('203.0.113.6')->getStatusCode())->not->toBe(429)
        ->and(postSubmitFrom('203.0.113.50')->getStatusCode())->not->toBe(429);
});

it('never throttles GET /submit', function () {
    foreach (range(1, 20) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get('/submit')->assertOk();
    }
});

it('resets the hourly limit after an hour but still applies the daily limit of ten', function () {
    foreach (range(1, 5) as $attempt) {
        postSubmitFrom('203.0.113.5');
    }
    expect(postSubmitFrom('203.0.113.5')->getStatusCode())->toBe(429);

    $this->travel(61)->minutes();

    foreach (range(6, 10) as $attempt) {
        expect(postSubmitFrom('203.0.113.5')->getStatusCode())->not->toBe(429, "attempt {$attempt}");
    }

    $this->travel(61)->minutes();

    postSubmitFrom('203.0.113.5')->assertStatus(429)->assertSee('Too many submissions from here today');
});

it('counts a valid submission towards the limit and still creates the draft', function () {
    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post('/submit', validSubmission(['title' => "Craft Fair {$attempt}"]))
            ->assertRedirect();
    }

    expect(EventDraft::count())->toBe(5);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post('/submit', validSubmission(['title' => 'Craft Fair 6']))
        ->assertStatus(429);

    expect(EventDraft::count())->toBe(5);
});

it('stores no IP address with the draft', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])
        ->post('/submit', validSubmission())
        ->assertRedirect();

    $draft = EventDraft::query()->sole();

    expect(collect(Schema::getColumnListing('event_drafts'))->filter(
        fn (string $column): bool => preg_match('/(^|_)ip(_|$)|remote_addr/', $column) === 1,
    )->values()->all())->toBe([])
        ->and(json_encode($draft->getAttributes()))->not->toContain('203.0.113.8');
});
