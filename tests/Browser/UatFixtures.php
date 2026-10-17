<?php

namespace Tests\Browser;

use App\Enums\EventOrigin;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Submissions\TurnstileVerifier;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use RuntimeException;

/**
 * Shared fixtures and selectors for the browser UAT flows
 * (docs/uat/browser-uat.md). Test data is prefixed "UAT".
 */
final class UatFixtures
{
    public const PASSWORD = 'uat-password';

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
