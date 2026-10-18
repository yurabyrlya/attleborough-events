<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Production-safe: reference data only. No users (the admin is created
     * with `php artisan make:filament-user`) and no demo events (ADM-02).
     * The rules seeder (starter keyword rules, geofence defaults) needs the
     * categories, and only creates what is missing. The source seeder adds
     * the three ready ICS sources, inactive (D-05: inactive source records
     * are reference configuration, not demo data), also create-only.
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);
        $this->call(RulesSeeder::class);
        $this->call(SourceSeeder::class);
    }
}
