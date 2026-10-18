<?php

namespace App\Providers;

use App\Ingestion\Http\DnsHostResolver;
use App\Ingestion\Http\HostResolver;
use App\Support\PrivacyDatabaseSessionHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
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
        // SRC-05: the fetcher's SSRF guard resolves hosts through real DNS.
        // tests/Pest.php replaces this with a fake in every suite.
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->pinUrlsToAppUrl();
        $this->configureRateLimiting();
        $this->keepIpAddressesOutOfSessions();
    }

    /**
     * D-04 (review CR-01): the "database" session driver stores no IP
     * address or user agent, so /privacy's promise that the IP is only held
     * in the rate-limit count stays true. Replacing the built-in driver
     * (rather than adding a new name) means an environment that still says
     * SESSION_DRIVER=database can't fall back to Laravel's handler. The
     * session payload itself is encrypted (config session.encrypt).
     */
    private function keepIpAddressesOutOfSessions(): void
    {
        Session::extend('database', fn (Application $app): PrivacyDatabaseSessionHandler => new PrivacyDatabaseSessionHandler(
            $app->make('db')->connection(config('session.connection')),
            (string) config('session.table'),
            (int) config('session.lifetime'),
            $app,
        ));
    }

    /**
     * SUB-04 (T-02-29): the 'submissions' limiter guards POST /submit only
     * (GET /submit is never throttled). Per request IP: review.submission
     * .per_hour and .per_day, using Laravel's atomic cache-backed limiter so
     * two simultaneous requests can't both take the last slot.
     *
     * Both limits key on the exact IP string (ip:{address}) with a window
     * suffix: ThrottleRequests hashes limiter name + key, so two limits with
     * an identical key would share (and double-hit) one counter.
     *
     * The IP lives only in the rate-limit cache, for at most a day (disclosed
     * on /privacy); nothing else stores it. Behind a proxy $request->ip() is
     * the proxy until trusted proxies are configured (STATE.md, Phase 5).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('submissions', fn (Request $request): array => [
            Limit::perHour((int) config('review.submission.per_hour'))->by('ip:'.$request->ip().':hour'),
            Limit::perDay((int) config('review.submission.per_day'))->by('ip:'.$request->ip().':day'),
        ]);
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
