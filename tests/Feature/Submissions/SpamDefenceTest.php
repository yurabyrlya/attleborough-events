<?php

use App\Models\EventDraft;
use App\Submissions\TurnstileVerifier;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * SUB-04 (D-17, D-18): the honeypot, the single-use timing token and
 * Cloudflare Turnstile stand in front of /submit. Everything stays offline:
 * siteverify is faked and Http::preventStrayRequests() stays on. SUB-03: a
 * person always sees why a submission was refused; only a filled honeypot
 * gets the silent thank-you.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    $this->seed(CategorySeeder::class);
});

const ROBOT_MESSAGE = "We couldn't confirm you're not a robot. Please try again, or email hello@whatsonattleborough.co.uk";

it('creates a draft when the honeypot, timing token and Turnstile all pass', function () {
    fakeTurnstilePass();

    $this->from('/submit')->post('/submit', validSubmission(['cf-turnstile-response' => 'tok']))
        ->assertRedirect(route('submit.thanks'))
        ->assertSessionHasNoErrors();

    expect(EventDraft::count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === TurnstileVerifier::SITEVERIFY_URL
        && $request['secret'] === '1x0000000000000000000000000000000AA'
        && $request['response'] === 'tok'
        && $request['remoteip'] === '127.0.0.1'
        && is_string($request['idempotency_key']));
});

it('creates nothing when Turnstile says no', function () {
    config()->set('services.turnstile.secret', '2x0000000000000000000000000000000AA');
    Http::fake([TurnstileVerifier::SITEVERIFY_URL => Http::response([
        'success' => false,
        'error-codes' => ['invalid-input-response'],
    ])]);

    $this->from('/submit')->post('/submit', validSubmission())
        ->assertRedirect('/submit')
        ->assertSessionHasErrors(['form' => ROBOT_MESSAGE]);

    expect(EventDraft::count())->toBe(0);
});
