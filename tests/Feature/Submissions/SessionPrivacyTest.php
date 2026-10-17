<?php

use Illuminate\Support\Facades\DB;

/*
 * D-04 (review CR-01): /privacy says the IP address is only held in the
 * rate-limit count. Database sessions therefore keep no IP address or user
 * agent, and the payload (which holds the flashed form after a failed
 * /submit) is encrypted. phpunit.xml uses array sessions, so these tests
 * switch to the production driver and encryption setting.
 */
beforeEach(function () {
    config()->set('session.driver', 'database');
    config()->set('session.encrypt', true);
});

it('stores no IP address or user agent in the session row', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'PrivacyProbe/1.0')
        ->get('/submit')
        ->assertOk();

    $session = DB::table('sessions')->sole();

    expect($session->ip_address)->toBeNull()
        ->and($session->user_agent)->toBeNull()
        ->and(json_encode($session))->not->toContain('203.0.113.9')
        ->and(json_encode($session))->not->toContain('PrivacyProbe');
});

it('keeps the flashed form of a failed submission encrypted in the session row', function () {
    freezeLondon('2026-10-20 10:00');
    fakeTurnstilePass();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->post('/submit', validSubmission(['title' => '', 'organiser_email' => 'organiser@example.org']))
        ->assertSessionHasErrors('title');

    $session = DB::table('sessions')->sole();
    $payload = base64_decode($session->payload, true);

    expect($session->ip_address)->toBeNull()
        ->and($payload)->not->toContain('organiser@example.org')
        ->and($payload)->not->toContain('_old_input')
        ->and(decrypt($payload))->toContain('organiser@example.org');
});
