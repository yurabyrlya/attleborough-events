<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-release" content="{{ basename(base_path()) }}">
    <title>@yield('title', "What's on") | {{ config('site.name') }}</title>
    <meta name="description" content="@yield('description', config('site.tagline'))">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#2E6B3F">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111614">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    {{-- Share metadata: a page that pushes into the "og" stack replaces these defaults. Absolute URLs come from config('app.url'), never the Host header. --}}
    @if (trim(app('view')->yieldPushContent('og')) !== '')
        @stack('og')
    @else
        <meta property="og:site_name" content="{{ config('site.name') }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ config('site.name') }}">
        <meta property="og:description" content="{{ config('site.tagline') }}">
        <meta property="og:image" content="{{ rtrim((string) config('app.url'), '/') }}/images/og-default.png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ config('site.name') }}: {{ config('site.tagline') }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    @vite('resources/css/app.css')
    @stack('head')
</head>
<body class="min-h-screen bg-bg text-ink">
    <a href="#main" class="tap-target sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-4 focus:font-semibold focus:text-ink">Skip to main content</a>

    <div class="mx-auto max-w-2xl px-4">
        <header class="pt-6">
            <x-brand.logo />
        </header>

        <main id="main" class="pb-10">
            @yield('content')
        </main>

        <footer class="border-t border-line py-6 text-sm text-muted">
            <p>{{ config('site.name') }}</p>
            <ul class="flex flex-wrap gap-x-4">
                <li><a href="{{ route('submit.create', absolute: false) }}" class="tap-target text-accent underline underline-offset-2">Add your event</a></li>
                <li><a href="{{ route('privacy', absolute: false) }}" class="tap-target text-accent underline underline-offset-2">Privacy</a></li>
            </ul>
            <p>Questions or corrections: <a href="mailto:{{ config('site.contact_email') }}" class="text-accent underline underline-offset-2">{{ config('site.contact_email') }}</a></p>
        </footer>
    </div>
</body>
</html>
