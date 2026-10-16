<?php

namespace Database\Factories;

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only factory. Production events are created exclusively by
 * App\Publishing\Actions\PublishEvent; no seeder uses this factory.
 *
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->words(3, true));

        return [
            'title' => $title,
            // Random suffix: events.slug is UNIQUE (01-08) and factory titles can repeat.
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
            'description' => fake()->paragraph(),
            'category_id' => Category::factory(),
            'venue_id' => Venue::factory(),
            'family_friendly' => false,
            'price_type' => PriceType::Unknown,
            'price_text' => null,
            'organiser_name' => null,
            'organiser_contact' => fake()->safeEmail(),
            'source_url' => null,
            'source_note' => null,
            'timezone' => 'Europe/London',
            'all_day' => false,
            'start_date' => '2026-10-24',
            'start_time' => '19:30:00',
            'end_date' => null,
            'end_time' => null,
            'recurrence_rule' => null,
            'status' => EventStatus::Published,
            'origin' => EventOrigin::Manual,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ];
    }
}
