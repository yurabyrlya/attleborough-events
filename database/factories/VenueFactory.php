<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Hall',
            'address' => fake()->streetAddress(),
            'postcode' => 'NR17 2AB',
            'town' => 'Attleborough',
            'lat' => null,
            'lng' => null,
            'aliases' => [],
        ];
    }
}
