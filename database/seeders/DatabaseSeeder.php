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
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);
    }
}
