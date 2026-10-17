<?php

use App\Models\EventDraft;
use App\Submissions\Honeypot;
use App\Submissions\TurnstileVerifier;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

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

/**
 * POST the form from /submit with an explicit timing token.
 *
 * @param  array<string, mixed>  $overrides
 */
function postWithToken(string $token, array $overrides = []): TestResponse
{
    return test()->from('/submit')->post('/submit', validSubmission([Honeypot::TOKEN => $token, ...$overrides]));
}

const SPAM_TOO_FAST = 'That was quick! Please check the details and send again.';
const SPAM_TOO_OLD = 'This form has been open a while. Please send it again.';
const SPAM_RELOAD = 'Please reload the page and try again.';
const SPAM_REUSED = 'This form was already sent. Reload the page to add another event.';

describe('honeypot', function () {
    beforeEach(fn () => fakeTurnstilePass());

    it('gives a filled honeypot a plain thank-you with no reference, creates nothing and logs only the reason', function () {
        Log::spy();

        $this->from('/submit')->post('/submit', validSubmission([
            Honeypot::FIELD => '01953 123456',
            'title' => 'Cheap Pills Bonanza',
            'organiser_email' => 'bot@spam.example',
        ]))
            ->assertRedirect(route('submit.thanks'))
            ->assertSessionHas('submitted', true)
            ->assertSessionMissing('reference')
            ->assertSessionHasNoErrors();

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();

        Log::shouldHaveReceived('info')->once()->with('submission refused', ['reason' => 'trap']);
        Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains(json_encode($context) ?: '', 'Cheap Pills')
            || str_contains(json_encode($context) ?: '', 'bot@spam.example'));

        get(route('submit.thanks'))->assertOk()->assertDontSee('Your reference');
    });

    it('gives the bot the silent thank-you even when its other fields are invalid', function () {
        $this->from('/submit')->post('/submit', validSubmission([
            Honeypot::FIELD => 'x',
            'title' => '',
            'start_date' => 'not a date',
        ]))
            ->assertRedirect(route('submit.thanks'))
            ->assertSessionHasNoErrors();

        expect(EventDraft::count())->toBe(0);
    });

    it('refuses a post without the honeypot field with a visible message', function () {
        $body = validSubmission();
        unset($body[Honeypot::FIELD]);

        $this->from('/submit')->post('/submit', $body)
            ->assertRedirect('/submit')
            ->assertSessionHasErrors(['form' => SPAM_RELOAD]);

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('renders the trap field hidden from people and the timing token', function () {
        get('/submit')->assertOk()
            ->assertSee('<div class="hp-field" aria-hidden="true">', false)
            ->assertSee('name="fax_number" type="text" tabindex="-1" autocomplete="off" value=""', false)
            ->assertSee('type="hidden" name="form_token"', false);
    });
});

describe('timing token', function () {
    beforeEach(fn () => fakeTurnstilePass());

    it('refuses a form sent 2 seconds after it was opened', function () {
        $token = Honeypot::mintToken();
        $this->travel(2)->seconds();

        postWithToken($token)->assertSessionHasErrors(['form' => SPAM_TOO_FAST]);

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('accepts a form sent 3 seconds after it was opened', function () {
        $token = Honeypot::mintToken();
        $this->travel(3)->seconds();

        postWithToken($token)->assertRedirect(route('submit.thanks'))->assertSessionHasNoErrors();

        expect(EventDraft::count())->toBe(1);
    });

    it('accepts a form sent 120 minutes after it was opened', function () {
        $token = Honeypot::mintToken();
        $this->travel(120)->minutes();

        postWithToken($token)->assertRedirect(route('submit.thanks'))->assertSessionHasNoErrors();

        expect(EventDraft::count())->toBe(1);
    });

    it('refuses a form sent 121 minutes after it was opened', function () {
        $token = Honeypot::mintToken();
        $this->travel(121)->minutes();

        postWithToken($token)->assertSessionHasErrors(['form' => SPAM_TOO_OLD]);

        expect(EventDraft::count())->toBe(0);
    });

    it('refuses a tampered or missing token', function (string $token) {
        postWithToken($token)->assertSessionHasErrors(['form' => SPAM_RELOAD]);

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();
    })->with([
        'not encrypted' => [(string) json_encode(['t' => 0, 'n' => 'abc'])],
        'altered' => [fn () => substr(mintTokenSecondsAgo(5), 0, -4).'AAAA'],
        'empty' => [''],
        'encrypted but not a token' => [fn () => encrypt('hello', false)],
    ]);

    it('refuses the same form sent twice and keeps one draft (SUB-01 idempotency)', function () {
        $token = mintTokenSecondsAgo(5);

        postWithToken($token)->assertRedirect(route('submit.thanks'));
        postWithToken($token)
            ->assertRedirect('/submit')
            ->assertSessionHasErrors(['form' => SPAM_REUSED]);

        expect(EventDraft::count())->toBe(1);
    });

    it('does not burn the token on a validation error, so the corrected form goes through', function () {
        $token = mintTokenSecondsAgo(5);

        postWithToken($token, ['title' => ''])->assertSessionHasErrors(['title']);
        expect(EventDraft::count())->toBe(0);

        postWithToken($token)->assertRedirect(route('submit.thanks'))->assertSessionHasNoErrors();
        expect(EventDraft::count())->toBe(1);
    });
});

describe('Turnstile in the request', function () {
    it('never calls Cloudflare when a field is invalid', function () {
        fakeTurnstilePass();

        $this->from('/submit')->post('/submit', validSubmission(['title' => '']))
            ->assertSessionHasErrors(['title']);

        Http::assertNothingSent();
    });

    it('refuses a missing Turnstile token without calling Cloudflare', function () {
        fakeTurnstilePass();

        $this->from('/submit')->post('/submit', validSubmission(['cf-turnstile-response' => '']))
            ->assertSessionHasErrors(['form' => ROBOT_MESSAGE]);

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('refuses everything when the secret is missing (fail closed)', function () {
        fakeTurnstilePass();
        config()->set('services.turnstile.secret', null);

        $this->from('/submit')->post('/submit', validSubmission())
            ->assertSessionHasErrors(['form' => ROBOT_MESSAGE]);

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('logs a Turnstile refusal with the reason only', function () {
        Log::spy();
        config()->set('services.turnstile.secret', '2x0000000000000000000000000000000AA');
        Http::fake([TurnstileVerifier::SITEVERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $this->from('/submit')->post('/submit', validSubmission());

        Log::shouldHaveReceived('info')->once()->with('submission refused', ['reason' => 'turnstile']);
    });
});

describe('the form page', function () {
    it('tells people without JavaScript how to email the details (D-18)', function () {
        get('/submit')->assertOk()
            ->assertSee('<noscript>', false)
            ->assertSee("Our spam check needs JavaScript. If you can't turn it on, email the details to", false)
            ->assertSee('href="mailto:hello@whatsonattleborough.co.uk"', false);
    });

    it('renders the widget only when a site key is set', function () {
        get('/submit')->assertOk()->assertDontSee('cf-turnstile', false);

        config()->set('services.turnstile.site_key', '1x00000000000000000000AA');

        get('/submit')->assertOk()
            ->assertSee('<div class="cf-turnstile" data-sitekey="1x00000000000000000000AA" data-action="submit"></div>', false);
    });

    it('links a form-level error from the error summary', function () {
        $token = Honeypot::mintToken();
        $this->travel(1)->seconds();
        fakeTurnstilePass();

        $html = (string) $this->followingRedirects()->from('/submit')
            ->post('/submit', validSubmission([Honeypot::TOKEN => $token]))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('href="#form-check"')
            ->toContain('id="form-check"')
            ->toContain('<span class="sr-only">Error:</span> '.e(SPAM_TOO_FAST));
    });
});

describe('resting mode', function () {
    beforeEach(function () {
        $this->app['env'] = 'production';
        // CSRF is only skipped automatically in the testing environment.
        $this->withoutMiddleware(PreventRequestForgery::class);
        config()->set('services.turnstile.site_key', '');
        config()->set('services.turnstile.secret', '');
    });

    it('replaces the form with the resting notice in production without Turnstile keys', function () {
        get('/submit')->assertOk()
            ->assertSeeText('The form is resting. Please email the details to hello@whatsonattleborough.co.uk')
            ->assertDontSee('Send for review')
            ->assertDontSee('cf-turnstile', false)
            ->assertDontSee('challenges.cloudflare.com', false);
    });

    it('creates nothing when the resting form is posted', function () {
        Http::fake();

        $this->from('/submit')->post('/submit', validSubmission())
            ->assertRedirect('/submit')
            ->assertSessionHasErrors(['form' => 'The form is resting. Please email the details to hello@whatsonattleborough.co.uk']);

        expect(EventDraft::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('is not resting in production once both keys are set', function () {
        config()->set('services.turnstile.site_key', '1x00000000000000000000AA');
        fakeTurnstilePass();

        get('/submit')->assertOk()->assertSee('Send for review');
        $this->from('/submit')->post('/submit', validSubmission())->assertRedirect(route('submit.thanks'));

        expect(EventDraft::count())->toBe(1);
    });
});
