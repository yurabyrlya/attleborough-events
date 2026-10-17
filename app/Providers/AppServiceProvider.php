<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->pinUrlsToAppUrl();
    }

    /**
     * CR-01 (T-01-70): no generated URL may come from the request Host
     * header, which an attacker controls and a shared cache would replay.
     *
     * - The URL generator root (url(), route(), redirects, Filament) is
     *   forced to config('app.url'); https is forced only when app.url is
     *   https, so the local http://localhost:8000 keeps working.
     * - Vite tags are root-relative (/build/assets/...): they carry no host
     *   at all, so they cannot be poisoned, and they still load when the
     *   owner opens the local app from a phone via the LAN IP.
     *
     * trustHosts() is deliberately not used: Laravel skips it when APP_ENV is
     * local or under tests, so in Phase 1 it would add nothing provable.
     */
    private function pinUrlsToAppUrl(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        Vite::createAssetPathsUsing(
            fn (string $path, ?bool $secure = null): string => '/'.ltrim($path, '/'),
        );
    }
}
