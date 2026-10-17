<?php

use Illuminate\Support\Str;

/*
 * D-14 / RES-03 (threat T-02-07): source fixtures are raw third-party bytes
 * committed to a public repo. Personal emails and phone numbers must be
 * redacted to the placeholders below before commit, every fixture must be
 * accounted for in its source's manifest.json, and every P0/P1 source in
 * docs/research/event-sources.md must have fixtures for Phase 3's parsers.
 * Private individuals' names are redacted by reading (not checkable here).
 */

const SOURCE_FIXTURE_EMAIL_PLACEHOLDER = 'redacted@example.invalid';
const SOURCE_FIXTURE_PHONE_PLACEHOLDER = '01632 960000';

/**
 * Emails and UK phone numbers in $text other than the redaction placeholders.
 *
 * @return list<string>
 */
function sourceFixturePersonalData(string $text): array
{
    $found = [];

    // An ICS UID (RFC 5545) is an identifier that is often email-shaped
    // ("E5936475@UK.endis.com"); it is structure, not a contact address.
    // RFC 5545 continuation lines (leading space or tab) belong to the UID too.
    $text = (string) preg_replace('/^UID[;:].*(?:\r?\n[ \t].*)*/mi', '', $text);

    preg_match_all('/(?<![\w.+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?![\w-])/', $text, $emails);

    foreach ($emails[0] as $email) {
        $lastLabel = strtolower(substr((string) strrchr($email, '.'), 1));

        if (in_array($lastLabel, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'css', 'js'], true)) {
            continue;
        }

        if (strtolower($email) !== SOURCE_FIXTURE_EMAIL_PLACEHOLDER) {
            $found[] = $email;
        }
    }

    preg_match_all('/(?<![\w.+\-])(?:\+44\s?(?:\(0\)\s?)?\d{2,4}|\(?0\d{2,4}\)?)[\s-]?\d{3,4}[\s-]?\d{3,4}(?!\w|\.\d)/', $text, $phones);

    foreach ($phones[0] as $phone) {
        if (preg_replace('/\D/', '', $phone) !== preg_replace('/\D/', '', SOURCE_FIXTURE_PHONE_PLACEHOLDER)) {
            $found[] = $phone;
        }
    }

    return $found;
}

/**
 * Fixture files per source slug, as paths relative to the slug directory.
 *
 * @return array<string, list<string>>
 */
function sourceFixtureFiles(): array
{
    $files = [];

    foreach (glob(base_path('tests/Fixtures/sources/*'), GLOB_ONLYDIR) as $dir) {
        $slug = basename($dir);
        $files[$slug] = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($dir) + 1);

            if ($relative !== 'manifest.json') {
                $files[$slug][] = $relative;
            }
        }

        sort($files[$slug]);
    }

    return $files;
}

/**
 * @return list<string>
 */
function sourceFixtureManifestFiles(string $slug): array
{
    $path = base_path("tests/Fixtures/sources/{$slug}/manifest.json");

    if (! is_file($path)) {
        return [];
    }

    $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_map(fn (array $entry): string => (string) $entry['file'], $manifest['files'] ?? []));
}

it('flags personal emails and UK phone numbers', function (string $text) {
    expect(sourceFixturePersonalData($text))->not->toBeEmpty();
})->with([
    'personal email' => 'jane.smith@gmail.com',
    'email in an ICS description' => "UID:E1@UK.endis.com\r\nDESCRIPTION:Contact jane.smith@gmail.com",
    'mobile' => '07700 900123',
    'international mobile' => '+44 7700 900123',
    'bracketed area code' => '(01953) 456789',
    'number ending a sentence' => 'Call 01953 452123.',
    'number inside prose' => 'Ring Jo on 07700 900123. All welcome',
]);

it('does not flag placeholders or structural values', function (string $text) {
    expect(sourceFixturePersonalData($text))->toBeEmpty();
})->with([
    'retina asset name' => 'logo@2x.png',
    'decimal coordinate' => '51.5712345678',
    'ICS timestamp' => '20261019T090000Z',
    'epoch milliseconds' => '1729339200000',
    'hex hash' => 'a1b2c3d4e5f60123456789',
    'ICS UID' => "BEGIN:VEVENT\r\nUID:E5936475@UK.endis.com\r\nEND:VEVENT",
    'email placeholder' => SOURCE_FIXTURE_EMAIL_PLACEHOLDER,
    'phone placeholder' => SOURCE_FIXTURE_PHONE_PLACEHOLDER,
]);

it('keeps committed source fixtures free of personal emails and phone numbers', function () {
    $findings = [];

    foreach (sourceFixtureFiles() as $slug => $files) {
        foreach ($files as $file) {
            $matches = sourceFixturePersonalData((string) file_get_contents(base_path("tests/Fixtures/sources/{$slug}/{$file}")));

            if ($matches !== []) {
                $findings["{$slug}/{$file}"] = array_values(array_unique($matches));
            }
        }
    }

    expect($findings)->toBe([]);
});

it('lists every fixture file in its source manifest', function () {
    $unlisted = [];

    foreach (sourceFixtureFiles() as $slug => $files) {
        foreach (array_diff($files, sourceFixtureManifestFiles($slug)) as $file) {
            $unlisted[] = "{$slug}/{$file}";
        }
    }

    expect($unlisted)->toBe([]);
});

it('has fixtures for every P0 and P1 source in List 1 unless it is openly marked pending', function () {
    $doc = (string) file_get_contents(base_path('docs/research/event-sources.md'));
    preg_match('/^## List 1: Crawlable\R(.*?)(?=^## |\z)/ms', $doc, $list1);
    preg_match('/^\*\*Pending \(not in the index until captured\):\*\*(.*)$/m', $doc, $pendingNote);
    preg_match_all('/^### (.+?) \((P0|P1)([^)]*)\)\s*$/m', $list1[1] ?? '', $headings, PREG_SET_ORDER);

    $missing = [];
    $undeclared = [];

    foreach ($headings as [, $name, , $flags]) {
        $slug = Str::slug($name);

        if (str_contains($flags, 'fixtures pending')) {
            if (! str_contains($pendingNote[1] ?? '', "`{$slug}`")) {
                $undeclared[] = $slug;
            }

            continue;
        }

        if (sourceFixtureManifestFiles($slug) === []) {
            $missing[] = $slug;
        }
    }

    expect($headings)->not->toBeEmpty()
        ->and($missing)->toBe([])
        ->and($undeclared)->toBe([]);
});
