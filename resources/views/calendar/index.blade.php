@extends('layouts.app')

@section('title', "What's on")

@push('head')
    <link rel="canonical" href="{{ rtrim((string) config('app.url'), '/') }}/">
    @if ($filters->hasQueryString)
        <meta name="robots" content="noindex, follow">
    @endif
@endpush

@section('content')
    <h1>What's on in Attleborough</h1>

    @include('calendar.partials.filter-chips', ['filters' => $filters, 'categories' => $categories])

    @forelse ($groups as $group)
        <section aria-labelledby="day-{{ $group['date']->format('Y-m-d') }}">
            <h2 id="day-{{ $group['date']->format('Y-m-d') }}">
                <time datetime="{{ $group['date']->format('Y-m-d') }}">{{ $group['heading'] }}</time>
            </h2>
            <ul>
                @foreach ($group['occurrences'] as $occurrence)
                    @include('calendar.partials.occurrence-card', ['occurrence' => $occurrence])
                @endforeach
            </ul>
        </section>
    @empty
        <p>Nothing on yet. Check back soon.</p>
    @endforelse

    @if ($occurrences->hasMorePages())
        <nav aria-label="More events">
            <a href="{{ $occurrences->nextPageUrl() }}" rel="next">More events</a>
        </nav>
    @endif
@endsection
