<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-release" content="{{ basename(base_path()) }}">
    <title>@yield('title', "What's on") | {{ config('site.name') }}</title>
    @vite('resources/css/app.css')
    @stack('head')
    <meta name="description" content="{{ config('site.tagline') }}">
</head>
<body>
    <header>
        <a href="{{ route('calendar') }}">{{ config('site.name') }}</a>
        <p>{{ config('site.tagline') }}</p>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer>
        <p>Contact: <a href="mailto:{{ config('site.contact_email') }}">{{ config('site.contact_email') }}</a></p>
    </footer>
</body>
</html>
