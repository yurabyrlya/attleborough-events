<?php

use App\Ingestion\Http\RobotsRules;

/*
 * RFC 9309 group selection and rule matching (SRC-05 adjacency, empty and
 * ordering edges). Pure parsing: no network, no database.
 */

const RULES_BOT = 'AttleboroughEventsBot';

function rulesAllow(string $body, string $path, string $token = RULES_BOT): bool
{
    return RobotsRules::parse($body)->allows($path, $token);
}

it('applies the rules of the matching group', function (string $body, string $path, bool $allowed) {
    expect(rulesAllow($body, $path))->toBe($allowed);
})->with([
    'the * group is ignored when a group names the bot' => [
        "User-agent: *\nDisallow: /\n\nUser-agent: AttleboroughEventsBot\nDisallow: /private/\n",
        '/events.ics', true,
    ],
    'the bot group still applies its own rules' => [
        "User-agent: *\nDisallow: /\n\nUser-agent: AttleboroughEventsBot\nDisallow: /private/\n",
        '/private/x', false,
    ],
    'the * group applies when no group names the bot' => [
        "User-agent: OtherBot\nDisallow: /\n\nUser-agent: *\nDisallow: /private/\n",
        '/private/x', false,
    ],
    'another bot\'s group never applies' => [
        "User-agent: OtherBot\nDisallow: /\n",
        '/anything', true,
    ],
    'the product token matches case-insensitively' => [
        "User-agent: attleboroughEventsBOT\nDisallow: /\n",
        '/anything', false,
    ],
    'a versioned User-agent value still names the bot' => [
        "User-agent: AttleboroughEventsBot/1.0\nDisallow: /feeds/\n",
        '/feeds/a.ics', false,
    ],
    'consecutive User-agent lines share one group' => [
        "User-agent: OtherBot\nUser-agent: AttleboroughEventsBot\nDisallow: /x\n",
        '/x', false,
    ],
]);

it('merges every group that names the bot', function () {
    $body = "User-agent: AttleboroughEventsBot\nDisallow: /a\n\nUser-agent: *\nDisallow: /c\n\nUser-agent: AttleboroughEventsBot\nDisallow: /b\n";

    expect(rulesAllow($body, '/a'))->toBeFalse()
        ->and(rulesAllow($body, '/b'))->toBeFalse()
        ->and(rulesAllow($body, '/c'))->toBeTrue();
});

it('lets an Allow win over a Disallow of the same length', function () {
    expect(rulesAllow("User-agent: *\nAllow: /a\nDisallow: /a\n", '/a'))->toBeTrue()
        ->and(rulesAllow("User-agent: *\nDisallow: /a\nAllow: /a\n", '/a'))->toBeTrue();
});

it('lets the longest matching rule win', function () {
    $body = "User-agent: *\nDisallow: /a\nAllow: /a/b\n";

    expect(rulesAllow($body, '/a/b'))->toBeTrue()
        ->and(rulesAllow($body, '/a/b/c'))->toBeTrue()
        ->and(rulesAllow($body, '/a/c'))->toBeFalse()
        ->and(rulesAllow($body, '/a'))->toBeFalse();
});

it('governs the real Baptist Church rules', function () {
    $body = "User-agent: *\nCrawl-delay: 10\nAllow: /Admin/Resources/Folders/Calendar.ics\nDisallow: /Admin/\nDisallow: /User/\n";

    expect(rulesAllow($body, '/Calendar/Calendar.ics'))->toBeTrue()
        ->and(rulesAllow($body, '/calendar/rss.xml?days=150'))->toBeTrue()
        ->and(rulesAllow($body, '/Admin/Resources/Folders/Calendar.ics'))->toBeTrue()
        ->and(rulesAllow($body, '/Admin/secret'))->toBeFalse()
        ->and(rulesAllow($body, '/User/login'))->toBeFalse()
        // Paths are case-sensitive.
        ->and(rulesAllow($body, '/admin/secret'))->toBeTrue();
});

it('supports * wildcards and the $ end anchor', function (string $rule, string $path, bool $allowed) {
    expect(rulesAllow("User-agent: *\nDisallow: {$rule}\n", $path))->toBe($allowed);
})->with([
    '$ blocks the exact ending' => ['/*.ics$', '/x.ics', false],
    '$ does not block a query after it' => ['/*.ics$', '/x.ics?y=1', true],
    '* inside a path' => ['/feeds/*/private', '/feeds/2026/private/a', false],
    '* inside a path, no match' => ['/feeds/*/private', '/feeds/2026/public', true],
    'trailing * is a plain prefix' => ['/tmp*', '/tmpfile', false],
    '* matches nothing too' => ['/a*b', '/ab', false],
    'a query string is part of the path' => ['/search?q=', '/search?q=fair', false],
    '$ in the middle is literal' => ['/a$b', '/a$bc', false],
    'no wildcard is a prefix match' => ['/fish', '/fish.html', false],
    'prefix match is not a substring match' => ['/fish', '/Fish', true],
]);

it('normalises percent-encoding before comparing', function () {
    expect(rulesAllow("User-agent: *\nDisallow: /%7Ejo\n", '/~jo/page'))->toBeFalse()
        ->and(rulesAllow("User-agent: *\nDisallow: /~jo\n", '/%7ejo/page'))->toBeFalse()
        ->and(rulesAllow("User-agent: *\nDisallow: /caf%C3%A9\n", '/café'))->toBeFalse()
        ->and(rulesAllow("User-agent: *\nDisallow: /café\n", '/caf%c3%a9'))->toBeFalse()
        // An escaped reserved character stays escaped: %2F is not a "/".
        ->and(rulesAllow("User-agent: *\nDisallow: /a/b\n", '/a%2Fb'))->toBeTrue();
});

it('allows everything for an empty body, an empty Disallow or a group without rules', function (string $body) {
    expect(rulesAllow($body, '/'))->toBeTrue()
        ->and(rulesAllow($body, '/any/path.ics'))->toBeTrue();
})->with([
    'empty body' => '',
    'whitespace and comments only' => "\n# nothing here\n   \n",
    'empty Disallow value' => "User-agent: *\nDisallow:\n",
    'group with no rules' => "User-agent: AttleboroughEventsBot\n",
    'HTML served as robots.txt' => "<!DOCTYPE html>\n<html><body>Not found</body></html>",
]);

it('gives the same answers whatever the order of rules and groups', function () {
    $lines = [
        'Disallow: /a',
        'Allow: /a/b',
        'Disallow: /a/b/c',
        'Allow: /x',
        'Disallow: /x',
        'Disallow: /*.ics$',
        'Allow: /public/*.ics$',
        'Disallow: /%7Ejo',
    ];
    $paths = ['/a', '/a/b', '/a/b/c', '/a/b/d', '/x', '/feed.ics', '/public/feed.ics', '/public/feed.ics?v=1', '/~jo/x', '/other'];

    $answers = function (array $rules) use ($paths): array {
        $body = "User-agent: *\n".implode("\n", $rules)."\n";

        return array_map(fn (string $path): bool => rulesAllow($body, $path), $paths);
    };

    $expected = $answers($lines);

    expect($answers(array_reverse($lines)))->toBe($expected);

    mt_srand(9309);

    foreach (range(1, 10) as $ignored) {
        $shuffled = $lines;
        shuffle($shuffled);

        expect($answers($shuffled))->toBe($expected);
    }

    // Group order: the bot's groups are merged wherever they appear.
    $first = "User-agent: AttleboroughEventsBot\nDisallow: /a\n\nUser-agent: *\nDisallow: /\n\nUser-agent: AttleboroughEventsBot\nAllow: /a/b\n";
    $second = "User-agent: AttleboroughEventsBot\nAllow: /a/b\n\nUser-agent: AttleboroughEventsBot\nDisallow: /a\n\nUser-agent: *\nDisallow: /\n";

    foreach ($paths as $path) {
        expect(rulesAllow($first, $path))->toBe(rulesAllow($second, $path));
    }
});

it('always allows /robots.txt', function () {
    expect(rulesAllow("User-agent: *\nDisallow: /\n", '/robots.txt'))->toBeTrue()
        ->and(rulesAllow("User-agent: *\nDisallow: /\n", '/other'))->toBeFalse();
});

it('reads Crawl-delay for the selected group and clamps it', function (string $value, ?int $expected) {
    expect(RobotsRules::parse("User-agent: *\nCrawl-delay: {$value}\nDisallow: /x\n")->crawlDelay(RULES_BOT))->toBe($expected);
})->with([
    'ten seconds' => ['10', 10],
    'Attleborough Primary 12000' => ['12000', 12000],
    'not a number is ignored' => ['abc', null],
    'negative is ignored' => ['-5', null],
    'clamped to a day' => ['999999', 86400],
    'absurdly long clamped to a day' => ['99999999999999999999', 86400],
    'fractions round up' => ['2.5', 3],
    'zero' => ['0', 0],
]);

it('takes Crawl-delay only from the selected groups', function () {
    $body = "User-agent: *\nCrawl-delay: 30\n\nUser-agent: AttleboroughEventsBot\nCrawl-delay: 7\nDisallow: /x\n";

    expect(RobotsRules::parse($body)->crawlDelay(RULES_BOT))->toBe(7)
        ->and(RobotsRules::parse("User-agent: *\nDisallow: /x\n")->crawlDelay(RULES_BOT))->toBeNull();
});

it('parses at most 512 KiB of the file', function () {
    // A rule ending just inside the first 512 KiB, then padding past
    // 600 KiB and a rule beyond the cap.
    $inside = "Disallow: /inside\n";
    $head = "User-agent: *\n";
    $body = $head.str_repeat('#', RobotsRules::MAX_BYTES - strlen($head) - strlen($inside) - 1)."\n".$inside;

    expect(strlen($body))->toBe(RobotsRules::MAX_BYTES);

    $body .= str_repeat("# more\n", 14000)."Disallow: /beyond\n";

    expect(strlen($body))->toBeGreaterThan(600 * 1024);

    $rules = RobotsRules::parse($body);

    expect($rules->allows('/inside', RULES_BOT))->toBeFalse()
        ->and($rules->allows('/beyond', RULES_BOT))->toBeTrue();
});

it('handles CR, CRLF and LF line endings, comments and a BOM', function () {
    $body = "\xEF\xBB\xBFUser-agent: * # everyone\r\nDisallow: /a # not a\rDisallow: /b\nallow: /b/ok\n";

    expect(rulesAllow($body, '/a'))->toBeFalse()
        ->and(rulesAllow($body, '/b'))->toBeFalse()
        ->and(rulesAllow($body, '/b/ok'))->toBeTrue()
        ->and(rulesAllow($body, '/c'))->toBeTrue();
});

it('ignores rules that appear before any User-agent line', function () {
    expect(rulesAllow("Disallow: /\nUser-agent: OtherBot\nDisallow: /\n", '/x'))->toBeTrue();
});

it('matches long wildcard patterns without catastrophic backtracking', function () {
    $pattern = '/'.str_repeat('a*', 40).'b';
    $path = '/'.str_repeat('a', 4000);

    $started = hrtime(true);
    $allowed = rulesAllow("User-agent: *\nDisallow: {$pattern}\n", $path);
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    expect($allowed)->toBeTrue()
        ->and($elapsedMs)->toBeLessThan(2000);
});
