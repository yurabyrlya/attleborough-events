<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the fixed category list from product spec §7.2.
 * Categories ONLY: no demo events are ever seeded (ADM-02).
 */
class CategorySeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    public const CATEGORIES = [
        ['Family', 'heroicon-o-user-group'],
        ['Community', 'heroicon-o-home-modern'],
        ['Market', 'heroicon-o-shopping-bag'],
        ['Charity', 'heroicon-o-heart'],
        ['Music', 'heroicon-o-musical-note'],
        ['Sport', 'heroicon-o-trophy'],
        ['Food & Drink', 'heroicon-o-cake'],
        ['School', 'heroicon-o-academic-cap'],
        ['Arts & Culture', 'heroicon-o-paint-brush'],
        ['Other', 'heroicon-o-sparkles'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => [$name, $icon]) {
            Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'icon' => $icon, 'sort' => ($index + 1) * 10],
            );
        }
    }
}
