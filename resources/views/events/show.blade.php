@extends('layouts.app')

@php
    /**
     * Event page (EVT-01, EVT-02). Renders only EventPageData fields, through
     * escaped echo. Every optional element renders only when its value is
     * set. The head carries one Event JSON-LD script per listed date and the
     * first-party share module; the body ends with the share block.
     *
     * @var \App\Support\EventPageData $page
     */
@endphp

@section('title', $page->pageTitle)
@section('description', $page->metaDescription)

@push('head')
    <link rel="canonical" href="{{ $page->canonicalUrl }}">
    {{-- Event JSON-LD (EVT-03): an HtmlString from App\Seo\EventJsonLd, one script per listed date. --}}
    {{ $page->jsonLd }}
    {{-- The only script on the page: the first-party share module (EVT-07). Never loaded on /. --}}
    @vite('resources/js/share.js')
@endpush

@push('og')
    <meta property="og:site_name" content="{{ config('site.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $page->ogTitle }}">
    <meta property="og:description" content="{{ $page->metaDescription }}">
    <meta property="og:url" content="{{ $page->canonicalUrl }}">
    <meta property="og:image" content="{{ $page->ogImageUrl }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ config('site.name') }}: {{ config('site.tagline') }}">
    <meta name="twitter:card" content="summary_large_image">
@endpush

@section('content')
    <p class="mt-6"><a href="{{ route('calendar', absolute: false) }}" class="tap-target text-accent underline underline-offset-2">← All events</a></p>

    <h1 class="mt-2 text-2xl font-bold text-ink wrap-anywhere">{{ $page->title }}@if ($page->isCancelled) <strong class="badge ml-2 inline-block rounded-full border border-cancel px-2 align-middle text-sm font-bold text-cancel">Cancelled</strong>@endif</h1>

    @if ($page->isCancelled)
        <p class="mt-2 font-semibold text-cancel">This event has been cancelled.</p>
    @endif

    <div class="facts mt-3 flex flex-wrap items-center gap-2">
        <p class="category inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 text-sm text-accent"><x-category-icon :slug="$page->categorySlug" class="size-4" />{{ $page->categoryName }}</p>
        @if ($page->priceLabel !== null)
            <p class="price rounded-full bg-accent-soft px-2 text-sm text-accent">{{ $page->priceLabel }}</p>
        @endif
        @if ($page->familyFriendly)
            <p class="tag rounded-full bg-accent-soft px-2 text-sm text-accent">Family-friendly</p>
        @endif
    </div>

    @include('events.partials.dates', ['page' => $page])

    <section class="mt-6" aria-labelledby="where-heading">
        <h2 id="where-heading" class="text-lg font-semibold text-ink">Where</h2>
        <p class="venue mt-2 font-medium text-ink wrap-anywhere">{{ $page->venueName }}</p>
        @foreach ($page->venueLines as $line)
            <p class="text-muted wrap-anywhere">{{ $line }}</p>
        @endforeach
    </section>

    <section class="mt-6" aria-labelledby="about-heading">
        <h2 id="about-heading" class="text-lg font-semibold text-ink">About this event</h2>
        <p class="description mt-2 whitespace-pre-line text-ink wrap-anywhere">{{ $page->description }}</p>
    </section>

    <section class="mt-6 [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2" aria-labelledby="who-heading">
        <h2 id="who-heading" class="text-lg font-semibold text-ink">Who's running it</h2>
        @if ($page->organiserName !== null)
            <p class="organiser mt-2 text-ink wrap-anywhere">Organised by {{ $page->organiserName }}</p>
        @endif
        @if ($page->contactText !== null)
            <p class="contact mt-1 text-ink wrap-anywhere">Contact: @if ($page->contactHref !== null)<a href="{{ $page->contactHref }}">{{ $page->contactText }}</a>@else{{ $page->contactText }}@endif</p>
        @endif
        @if ($page->sourceNote !== null)
            <p class="source mt-1 text-muted wrap-anywhere">Source: {{ $page->sourceNote }}</p>
        @endif
        @if ($page->sourceUrl !== null)
            <p class="source-link mt-1 text-muted wrap-anywhere">More information: <a href="{{ $page->sourceUrl }}" rel="nofollow noopener">{{ $page->sourceHost }}</a></p>
        @endif
        @if ($page->sourceNote === null && $page->sourceUrl === null)
            <p class="listed-by mt-1 text-muted">Listed by What's On Attleborough.</p>
        @endif
    </section>

    @include('events.partials.share', ['page' => $page])
@endsection
