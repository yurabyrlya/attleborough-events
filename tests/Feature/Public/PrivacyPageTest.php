<?php

use function Pest\Laravel\get;

/*
 * D-04 / D-12 / D-17: the interim privacy notice at its permanent URL says
 * what submissions collect, why, what is public, the spam checks, retention
 * and how to get in touch.
 */
it('explains what is collected, shown and kept, at /privacy', function () {
    $response = get('/privacy')->assertOk();

    foreach (['What we collect', 'Why', 'What we show publicly', 'Spam protection', 'How long we keep it', 'Your rights', 'Contact us'] as $heading) {
        $response->assertSee('<h2>'.$heading.'</h2>', false);
    }

    $response->assertSee('hello@whatsonattleborough.co.uk')
        ->assertSee('legitimate interest')
        ->assertSee('Show my contact publicly')
        ->assertSee('30 days')
        ->assertSee('90 days')
        ->assertSee('Turnstile')
        ->assertSee('24 hours')
        ->assertSee('is not stored with your submission')
        ->assertSee('href="https://www.cloudflare.com/turnstile-privacy-policy/"', false)
        ->assertSee('Last updated 17 October 2026')
        ->assertDontSee('no cookies')
        ->assertDontSee('<script', false);
});

it('is linked from the submission form', function () {
    get('/submit')->assertOk()->assertSee('href="/privacy"', false);
});
