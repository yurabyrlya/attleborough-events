<?php

use App\Submissions\TurnstileVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * SUB-04 / D-17: the in-house siteverify client fails closed. Every case is
 * offline: Http::fake answers for SITEVERIFY_URL and
 * Http::preventStrayRequests() blocks anything else.
 */
beforeEach(function () {
    config()->set('services.turnstile.secret', '1x0000000000000000000000000000000AA');
    config()->set('services.turnstile.hostnames', []);
});

/**
 * Fake one siteverify answer.
 *
 * @param  array<string, mixed>  $body
 */
function fakeSiteverify(array $body): void
{
    Http::fake([TurnstileVerifier::SITEVERIFY_URL => Http::response($body)]);
}

it('passes a successful answer for the submit action and sends secret, response, remoteip and an idempotency key', function () {
    fakeSiteverify(['success' => true, 'action' => 'submit', 'hostname' => 'whatsonattleborough.co.uk', 'error-codes' => []]);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe([]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === TurnstileVerifier::SITEVERIFY_URL
        && $request->isForm()
        && $request['secret'] === '1x0000000000000000000000000000000AA'
        && $request['response'] === 'tok'
        && $request['remoteip'] === '203.0.113.7'
        && preg_match('/^[0-9a-f-]{36}$/', (string) $request['idempotency_key']) === 1);
});

it('refuses success:false with Cloudflare\'s error codes', function () {
    fakeSiteverify(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['timeout-or-duplicate']);
});

it('refuses success:false without error codes', function () {
    fakeSiteverify(['success' => false]);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['invalid-input-response']);
});

it('refuses a truthy but non-boolean success', function () {
    fakeSiteverify(['success' => 'true', 'action' => 'submit']);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->not->toBe([]);
});

it('refuses an action that is not exactly submit', function (?string $action) {
    fakeSiteverify(['success' => true, 'action' => $action, 'hostname' => 'whatsonattleborough.co.uk']);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['action-mismatch']);
})->with(['login' => ['login'], 'different case' => ['Submit'], 'missing' => [null]]);

it('refuses a hostname outside the configured list', function () {
    config()->set('services.turnstile.hostnames', ['whatsonattleborough.co.uk']);
    fakeSiteverify(['success' => true, 'action' => 'submit', 'hostname' => 'evil.example']);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['hostname-mismatch']);
});

it('accepts a hostname in the configured list', function () {
    config()->set('services.turnstile.hostnames', ['whatsonattleborough.co.uk']);
    fakeSiteverify(['success' => true, 'action' => 'submit', 'hostname' => 'whatsonattleborough.co.uk']);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe([]);
});

it('skips the hostname check when no hostnames are configured', function () {
    fakeSiteverify(['success' => true, 'action' => 'submit', 'hostname' => 'localhost']);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe([]);
});

it('fails closed when Cloudflare cannot be reached', function () {
    Http::fake([TurnstileVerifier::SITEVERIFY_URL => Http::failedConnection()]);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['internal-error']);
});

it('fails closed on a server error or a non-JSON answer', function (mixed $response) {
    Http::fake([TurnstileVerifier::SITEVERIFY_URL => $response]);

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['internal-error']);
})->with([
    'server error' => [fn () => Http::response(['success' => true, 'action' => 'submit'], 500)],
    'not JSON' => [fn () => Http::response('<html>oops</html>', 200)],
]);

it('fails closed without calling Cloudflare when the secret is missing', function (?string $secret) {
    config()->set('services.turnstile.secret', $secret);
    Http::fake();

    expect(app(TurnstileVerifier::class)->verify('tok', '203.0.113.7'))->toBe(['missing-input-secret']);

    Http::assertNothingSent();
})->with(['null' => [null], 'empty' => ['']]);

it('refuses an empty token without calling Cloudflare', function (?string $token) {
    Http::fake();

    expect(app(TurnstileVerifier::class)->verify($token, '203.0.113.7'))->toBe(['missing-input-response']);

    Http::assertNothingSent();
})->with(['null' => [null], 'empty' => ['']]);

it('refuses a 2049-character token without calling Cloudflare', function () {
    Http::fake();

    expect(app(TurnstileVerifier::class)->verify(str_repeat('a', 2049), '203.0.113.7'))->toBe(['missing-input-response']);

    Http::assertNothingSent();
});

it('sends a 2048-character token to Cloudflare', function () {
    fakeSiteverify(['success' => true, 'action' => 'submit']);

    expect(app(TurnstileVerifier::class)->verify(str_repeat('a', 2048), '203.0.113.7'))->toBe([]);

    Http::assertSentCount(1);
});

it('accepts Cloudflare\'s testing-key answer outside production, which carries no action', function () {
    fakeSiteverify(['success' => true, 'hostname' => 'example.com', 'error-codes' => [], 'metadata' => ['result_with_testing_key' => true]]);
    config()->set('services.turnstile.hostnames', ['whatsonattleborough.co.uk']);

    expect(app(TurnstileVerifier::class)->verify('XXXX.DUMMY.TOKEN.XXXX', '127.0.0.1'))->toBe([]);
});

it('refuses a testing-key answer in production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    fakeSiteverify(['success' => true, 'hostname' => 'example.com', 'error-codes' => [], 'metadata' => ['result_with_testing_key' => true]]);

    expect(app(TurnstileVerifier::class)->verify('XXXX.DUMMY.TOKEN.XXXX', '203.0.113.7'))->toBe(['action-mismatch']);
});
