@extends('layouts.app')

@section('title', $filters->hasAny() ? $filters->summary() : "What's on")

@push('head')
    <link rel="canonical" href="{{ rtrim((string) config('app.url'), '/') }}/">
    @if ($filters->hasQueryString)
        <meta name="robots" content="noindex, follow">
    @endif
@endpush

@section('content')
    <h1 class="mt-6 text-2xl font-bold text-ink">What's on</h1>

    @include('calendar.partials.filter-chips', ['filters' => $filters, 'categories' => $categories])

    @if ($filters->hasAny())
        <p class="filter-summary mt-4 text-muted">Showing: {{ $filters->summary() }}</p>
    @endif

    @forelse ($groups as $group)
        <section class="mt-6" aria-labelledby="day-{{ $group['date']->format('Y-m-d') }}">
            <h2 id="day-{{ $group['date']->format('Y-m-d') }}" class="border-l-4 border-accent pl-2 font-semibold text-ink">
                <time datetime="{{ $group['date']->format('Y-m-d') }}">{{ $group['heading'] }}</time>
            </h2>
            <ul class="mt-3 flex flex-col gap-3">
                @foreach ($group['occurrences'] as $occurrence)
                    @include('calendar.partials.occurrence-card', ['occurrence' => $occurrence])
                @endforeach
            </ul>
        </section>
    @empty
        @include('calendar.partials.empty-state', ['filters' => $filters, 'context' => $emptyContext])
    @endforelse

    @if ($occurrences->hasMorePages())
        <nav aria-label="More events" class="mt-6">
            <a href="{{ $occurrences->nextPageUrl() }}" rel="next" class="tap-target w-full justify-center rounded-lg border border-accent font-semibold text-accent">More events</a>
        </nav>
    @endif
@endsection
