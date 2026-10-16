<?php

use Livewire\Livewire;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/** 01-07's third-party host deny-list. */
const THIRD_PARTY_DENY_LIST = '#fonts\.bunny\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net|cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com|cdn\.tailwindcss\.com#i';

afterEach(fn () => Livewire::flushState());

/**
 * Every href/src on <link> and <script> elements that is neither relative
 * nor on the APP_URL host (or the test request host).
 *
 * @return list<string>
 */
function thirdPartyResources(string $html): array
{
    preg_match_all('#<(?:link|script)\b[^>]*?\b(?:href|src)\s*=\s*"([^"]*)"#i', $html, $matches);

    $ownHosts = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), 'localhost']);

    return array_values(array_filter($matches[1], function (string $url) use ($ownHosts): bool {
        $host = parse_url(html_entity_decode($url), PHP_URL_HOST);

        return $host !== null && $host !== false && ! in_array($host, $ownHosts, true);
    }));
}

it('serves the admin login page with no third-party font, stylesheet or script', function () {
    $html = (string) get('/admin/login')->assertOk()->getContent();

    expect(preg_match(THIRD_PARTY_DENY_LIST, $html))->toBe(0)
        ->and(thirdPartyResources($html))->toBe([]);
});

it('serves the System health page to the admin with no third-party font, stylesheet or script', function () {
    $html = (string) actingAs(OpsFixtures::admin())->get('/admin/system-health')->assertOk()->getContent();

    expect(preg_match(THIRD_PARTY_DENY_LIST, $html))->toBe(0)
        ->and(thirdPartyResources($html))->toBe([]);
});

it('keeps daily logs for 14 days at permission 0640 and defaults the stack to daily', function () {
    expect(config('logging.channels.daily.driver'))->toBe('daily')
        ->and((int) config('logging.channels.daily.max_files'))->toBe(14)
        ->and(config('logging.channels.daily.permission'))->toBe(0640)
        ->and(config('logging.channels.single.permission'))->toBe(0640);

    $saved = [getenv('LOG_STACK'), $_ENV['LOG_STACK'] ?? null, $_SERVER['LOG_STACK'] ?? null];
    putenv('LOG_STACK');
    unset($_ENV['LOG_STACK'], $_SERVER['LOG_STACK']);

    try {
        $logging = require config_path('logging.php');
    } finally {
        if ($saved[0] !== false) {
            putenv('LOG_STACK='.$saved[0]);
        }
        if ($saved[1] !== null) {
            $_ENV['LOG_STACK'] = $saved[1];
        }
        if ($saved[2] !== null) {
            $_SERVER['LOG_STACK'] = $saved[2];
        }
    }

    expect($logging['channels']['stack']['channels'])->toContain('daily');
});
