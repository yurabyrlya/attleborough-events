{{--
    Static-safe error layout (UI review fix 1). A sibling of layouts.app that
    can render while the database, routes or built assets are broken (500/503):
    no queries, no route()/url() helpers, no stacks, no scripts, no exception
    detail. The stylesheet is only included when a Vite manifest or hot file
    exists, so a mid-deploy 500 still renders as plain semantic HTML.
    Links are root-relative so the Host header never reaches the HTML (T-01-41).
--}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ config('site.name') }}</title>
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#2E6B3F">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111614">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @if (is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))
        @vite('resources/css/app.css')
    @endif
</head>
<body class="min-h-screen bg-bg text-ink">
    <a href="#main" class="tap-target sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-4 focus:font-semibold focus:text-ink">Skip to main content</a>

    <div class="mx-auto max-w-2xl px-4">
        <header class="pt-6">
            <a href="/" class="tap-target gap-2 text-xl font-bold text-accent">
                <x-brand.calendar-pin class="size-8 shrink-0" />
                <span>{{ config('site.name') }}</span>
            </a>
            <p class="text-sm text-muted">{{ config('site.tagline') }}</p>
        </header>

        <main id="main" class="pb-10">
            <h1 class="mt-8 text-2xl font-bold text-ink">@yield('heading')</h1>
            @yield('content')
            <p class="mt-6"><a href="/" class="tap-target text-accent underline underline-offset-2">See what's on</a></p>
        </main>

        <footer class="border-t border-line py-6 text-sm text-muted">
            <p>{{ config('site.name') }}</p>
            <p>Questions or corrections: <a href="mailto:{{ config('site.contact_email') }}" class="text-accent underline underline-offset-2">{{ config('site.contact_email') }}</a></p>
        </footer>
    </div>
</body>
</html>
