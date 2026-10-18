<?php

namespace Tests\Browser;

use App\Enums\EventOrigin;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Enums\RunStatus;
use App\Ingestion\Http\HostResolver;
use App\Ingestion\RunLauncher;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Submissions\TurnstileVerifier;
use ArrayObject;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SourceSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Shared fixtures and selectors for the browser UAT flows
 * (docs/uat/browser-uat.md). Test data is prefixed "UAT".
 */
final class UatFixtures
{
    public const PASSWORD = 'uat-password';

    /** The open Filament dialog, for scoping its buttons and fields. */
    public const OPEN_MODAL = '.fi-modal-open';

    /** Feed URLs of the seeded ICS sources (SourceSeeder, D-05). */
    public const BAPTIST_FEED = 'https://www.attleborough.church/Calendar/Calendar.ics';

    public const TOWN_COUNCIL_FEED = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

    public const ACADEMY_FEED = 'https://www.attleboroughacademy.org/calendar/events.ics';

    /**
     * Captured fixture (tests/Fixtures/sources) served for each feed URL.
     *
     * @var array<string, string>
     */
    public const FEED_FIXTURES = [
        self::BAPTIST_FEED => 'attleborough-baptist-church/2026-10-17-ical.ics',
        self::TOWN_COUNCIL_FEED => 'attleborough-town-council/2026-10-17-ical.ics',
        self::ACADEMY_FEED => 'attleborough-academy/2026-10-17-ical.ics',
    ];

    public static function admin(): User
    {
        return User::factory()->create([
            'email' => config('app.admin_email'),
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * The real category list (reference data), as on the live site.
     */
    public static function seedCategories(): void
    {
        (new CategorySeeder)->run();
    }

    public static function category(string $slug): Category
    {
        return Category::query()->where('slug', $slug)->firstOrFail();
    }

    public static function venue(): Venue
    {
        return Venue::query()->first() ?? Venue::factory()->create(['name' => 'UAT Town Hall']);
    }

    /**
     * Publish an event through the real write path (PublishEvent).
     *
     * @param  array<string, mixed>  $overrides  PublishableEventData arguments
     */
    public static function publish(User $admin, array $overrides = []): Event
    {
        return app(PublishEvent::class)(new PublishableEventData(...array_merge([
            'title' => 'UAT Event',
            'description' => 'A UAT test event.',
            'categoryId' => self::category('community')->id,
            'venueId' => self::venue()->id,
            'startDate' => '2026-10-30',
            'startTime' => '19:30:00',
            'organiserContact' => 'uat@example.test',
        ], $overrides)), $admin, EventOrigin::Manual);
    }

    /**
     * Make the public form's Turnstile check pass offline. No site key is
     * set in tests, so the widget and Cloudflare's script are not rendered;
     * this binds a verifier that accepts any token for the current test only
     * (the next test boots a fresh container).
     */
    public static function fakeTurnstile(): void
    {
        app()->instance(TurnstileVerifier::class, new class extends TurnstileVerifier
        {
            public function verify(?string $token, string $ip): array
            {
                return [];
            }
        });
    }

    /**
     * A pending submission draft written through the real write path
     * (DraftWriter::create(DraftData::fromSubmission(...))), exactly as the
     * public form stores one: scored, category assigned, venue matched.
     * Defaults: a known venue, Community, ten days after the frozen London
     * "today", 7pm to 9pm, free.
     *
     * @param  array<string, mixed>  $overrides  validated /submit form keys
     */
    public static function submissionDraft(array $overrides = []): EventDraft
    {
        return app(DraftWriter::class)->create(DraftData::fromSubmission(array_merge([
            'title' => 'UAT Submitted Event',
            'description' => 'A UAT test submission with enough detail to read well.',
            'category_choice' => (string) self::category('community')->id,
            'start_date' => CarbonImmutable::now('Europe/London')->addDays(10)->toDateString(),
            'start_time' => '19:00',
            'end_time' => '21:00',
            'venue_choice' => (string) self::venue()->id,
            'price_type' => 'free',
            'organiser_name' => 'UAT Organiser',
            'organiser_email' => 'organiser@example.test',
            'consent' => '1',
        ], $overrides)));
    }

    /**
     * "UAT Quiz Night": monthly on the last Friday from Fri 30 Oct 2026
     * until 31 Mar 2027, 7:30pm to 10pm (the F3 series).
     */
    public static function quizNight(User $admin): Event
    {
        return self::publish($admin, [
            'title' => 'UAT Quiz Night',
            'description' => 'Monthly pub quiz. Teams of up to six.',
            'endTime' => '22:00:00',
            'priceType' => PriceType::Paid,
            'priceText' => '£2 per person',
            'organiserName' => 'The Griffin',
            'organiserContact' => 'quiz@example.test',
            'repeats' => RepeatPattern::MonthlyLast,
            'repeatEnds' => RepeatEnds::OnDate,
            'repeatUntil' => '2027-03-31',
        ]);
    }

    /**
     * Run ingestion offline for this test (T-03-46). Every host resolves
     * through the fake HostResolver (no DNS), robots.txt allows everything,
     * each feed URL answers its captured fixture (or the body given in
     * $feeds), any other URL answers 404, Sleep is faked (crawl delays pass
     * instantly, moving the frozen clock) and raw bodies go to a fake disk.
     * Nothing leaves the machine: Http::preventStrayRequests() stays on.
     *
     * @param  array<string, string>  $feeds  feed URL => ICS body (overrides the fixture)
     */
    public static function fakeIngestion(array $feeds = []): void
    {
        Sleep::fake(syncWithCarbon: true);
        Storage::fake('local');
        app()->instance(HostResolver::class, ingestResolverFake());

        $bodies = array_map(fn (string $path): string => self::sourceFixture($path), self::FEED_FIXTURES);
        $state = new ArrayObject([...$bodies, ...$feeds]);
        app()->instance('uat.ingest.feeds', $state);

        Http::fake(function (Request $request) use ($state) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/robots.txt')) {
                return Http::response("User-agent: *\nDisallow:\n", 200, ['Content-Type' => 'text/plain']);
            }

            $body = $state[$request->url()] ?? null;

            return is_string($body)
                ? Http::response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8'])
                : Http::response('Not found', 404, ['Content-Type' => 'text/plain']);
        });
    }

    /**
     * Change what a feed URL answers from now on (fakeIngestion() first),
     * e.g. a copy of the fixture with one item moved or cancelled.
     */
    public static function serveFeed(string $url, string $body): void
    {
        if (! app()->bound('uat.ingest.feeds')) {
            throw new RuntimeException('Call UatFixtures::fakeIngestion() first.');
        }

        app('uat.ingest.feeds')[$url] = $body;
    }

    /**
     * The captured fixture served for a seeded feed URL.
     */
    public static function feedFixture(string $url): string
    {
        return self::sourceFixture(self::FEED_FIXTURES[$url] ?? throw new RuntimeException("No fixture for {$url}"));
    }

    /**
     * A seeded source (SourceSeeder, D-05) taken live the way the admin
     * does it (D-01): optional settings the admin would pick on the form
     * (default venue, default category), then a test run and "Create
     * drafts" through RunLauncher, each worked off the real database queue.
     * The activation run writes the drafts through the real pipeline.
     *
     * @param  array<string, mixed>  $settings  source columns the admin would set on the form
     */
    public static function ingestedSource(string $slug, array $settings = []): Source
    {
        app(SourceSeeder::class)->run();

        $source = Source::query()->where('slug', $slug)->firstOrFail();

        if ($settings !== []) {
            $source->forceFill($settings)->save();
        }

        $launcher = app(RunLauncher::class);
        self::expectRun($launcher->testRun($source));
        self::expectRun($launcher->createDrafts($source->refresh()));

        return $source->refresh();
    }

    /**
     * One "Run now" check of a live source, worked off the queue.
     */
    public static function runSource(Source $source): SourceRun
    {
        return self::expectRun(app(RunLauncher::class)->runNow($source->refresh()));
    }

    /**
     * Work the queued FetchSourceJob(s) on the real database queue (the
     * job is pinned to it), as `queue:work` would on the server.
     */
    public static function workQueue(): void
    {
        $guard = 10;

        while (DB::table('jobs')->exists() && $guard-- > 0) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--stop-when-empty' => true]);
        }
    }

    /**
     * The review page of a draft.
     */
    public static function reviewPath(EventDraft $draft): string
    {
        return '/admin/event-drafts/'.$draft->getRouteKey().'/review';
    }

    /**
     * Wait until no Filament dialog is open.
     */
    public static function waitForModalClosed(mixed $page): mixed
    {
        return self::waitUntil($page, "document.querySelector('".self::OPEN_MODAL."') === null");
    }

    /**
     * Close any toast notification and wait until it has gone. Toasts sit
     * over the top right of the page, where Filament puts header actions,
     * so a click there would wait for a toast that hasn't faded yet.
     */
    public static function dismissNotifications(mixed $page): mixed
    {
        $page->script("() => document.querySelectorAll('.fi-no-notification-close-btn').forEach((button) => button.click())");

        return self::waitUntil($page, "document.querySelector('.fi-no-notification-close-btn') === null");
    }

    /**
     * Wait until the page's visible text contains $text. For text that a
     * Livewire poll or event brings in, where waitForText's default 5 s
     * may be shorter than a 3 s poll plus a render on a slow runner.
     */
    public static function waitForPageText(mixed $page, string $text, float $timeoutSeconds = 15.0): mixed
    {
        return self::waitUntil($page, 'document.body.innerText.includes('.self::quote($text).')', $timeoutSeconds);
    }

    /**
     * Work the queue for $run and fail loudly unless it succeeded.
     */
    private static function expectRun(SourceRun $run): SourceRun
    {
        self::workQueue();
        $run->refresh();

        if ($run->status !== RunStatus::Succeeded) {
            throw new RuntimeException("Run {$run->id} ended {$run->status->value}: ".($run->error_message ?? 'no message'));
        }

        return $run;
    }

    private static function sourceFixture(string $path): string
    {
        $file = base_path('tests/Fixtures/sources/'.$path);

        if (! is_file($file)) {
            throw new RuntimeException("Missing source fixture {$path}");
        }

        return (string) file_get_contents($file);
    }

    /**
     * Let pending Livewire model updates flush, then re-fill any input they
     * reset. Machine-speed fills can land just before a queued wire:model
     * effect writes the stale server value back into the input.
     *
     * @param  array<string, string>  $valuesById  input id => expected value
     */
    public static function settleFields(mixed $page, array $valuesById, int $attempts = 5): mixed
    {
        $attempts = max(1, $attempts);
        $stale = $valuesById;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $page->wait(0.2);

            $stale = array_filter(
                $valuesById,
                fn (string $value, string $id): bool => $page->script('() => document.getElementById('.json_encode($id).')?.value') !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($stale === []) {
                return $page;
            }

            if ($attempt === $attempts) {
                break;
            }

            foreach ($stale as $id => $value) {
                $page->fill('[id="'.$id.'"]', $value);
            }
        }

        throw new RuntimeException('Livewire kept resetting: '.implode(', ', array_keys($stale)));
    }

    /**
     * Wait until a JavaScript expression in the page is truthy (for state
     * that is not visible text, e.g. <select> options after a Livewire
     * round trip).
     */
    public static function waitUntil(mixed $page, string $expression, float $timeoutSeconds = 5.0): mixed
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (! $page->script('() => Boolean('.$expression.')')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out waiting for: {$expression}");
            }

            $page->wait(0.1);
        }

        return $page;
    }

    /**
     * Playwright role selector with an exact, case-sensitive accessible name.
     */
    public static function role(string $role, string $name): string
    {
        return sprintf('internal:role=%s[name=%ss]', $role, self::quote($name));
    }

    /**
     * Playwright role selector matching a case-insensitive substring of the
     * accessible name, for controls whose name also holds a count badge
     * (the "Review queue 2" sidebar link, the "Rejected 0" table tab).
     */
    public static function roleContaining(string $role, string $name): string
    {
        return sprintf('internal:role=%s[name=%si]', $role, self::quote($name));
    }

    /**
     * Playwright label selector (case-insensitive substring, so a required
     * field's "Title*" matches "Title").
     */
    public static function label(string $text): string
    {
        return sprintf('internal:label=%si', self::quote($text));
    }

    private static function quote(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
