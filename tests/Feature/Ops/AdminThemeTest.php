<?php

/**
 * Filament's precompiled CSS (public/css/filament/filament/app.css) only ships
 * the utilities Filament itself uses, so Tailwind classes in custom admin views
 * silently do nothing. The admin panel must therefore use the Vite-built theme
 * that scans app/Filament and resources/views/filament (quick task 261017-e46).
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;

const ADMIN_THEME_PATH = 'resources/css/filament/admin/theme.css';

afterEach(fn () => Livewire::flushState());

it('registers the Vite-built admin theme on the admin panel', function () {
    expect(Filament::getPanel('admin')->getViteTheme())->toBe(ADMIN_THEME_PATH);
});

it('builds the admin theme through Vite and scans the admin classes and views', function () {
    $themeFile = resource_path('css/filament/admin/theme.css');

    expect(file_get_contents(base_path('vite.config.js')))->toContain("'".ADMIN_THEME_PATH."'")
        ->and($themeFile)->toBeFile()
        ->and(file_get_contents($themeFile))
        ->toContain("@source '../../../../app/Filament/**/*';")
        ->toContain("@source '../../../../resources/views/filament/**/*';");
});

it('lays out the System health sections with Tailwind grid classes, not inline styles', function () {
    $html = (string) actingAs(OpsFixtures::admin())->get('/admin/system-health')->assertOk()->getContent();

    expect($html)->toContain('class="grid gap-6"');
});
