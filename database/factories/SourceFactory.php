<?php

namespace Database\Factories;

use App\Enums\ExtractionMethod;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only factory. Production sources are created by the admin through
 * the Filament source form, or seeded inactive as reference configuration
 * (D-05). Factories bypass Source's #[Fillable] list, so the states below
 * may set is_active and other pipeline-owned columns directly.
 *
 * The default is an inactive ICS source; scheduling, health and the other
 * defaulted columns come from the database.
 *
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'short_name' => Str::limit($name, 37, ''),
            'url' => 'https://example.org/calendar.ics',
            'extraction_method' => ExtractionMethod::Ics,
        ];
    }

    /**
     * An ICS feed keyed by the VEVENT UID.
     */
    public function ics(): static
    {
        return $this->state([
            'extraction_method' => ExtractionMethod::Ics,
            'parser_config' => ['identity' => 'uid'],
        ]);
    }

    /**
     * A live source: the admin clicked "Create drafts" (D-01).
     */
    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    /**
     * The organiser's own site: main attribution when sources overlap (D-25).
     */
    public function organiserSite(): static
    {
        return $this->state(['is_organiser_source' => true]);
    }

    /**
     * @param  string  $identity  uid | tec_post_id | summary_start
     */
    public function withIdentity(string $identity): static
    {
        return $this->state(['parser_config' => ['identity' => $identity]]);
    }
}
