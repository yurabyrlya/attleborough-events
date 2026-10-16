@extends('layouts.app')

@section('title', $event->title)

@php
    /**
     * Event page (EVT-01). Escaped echo only.
     *
     * @var \App\Models\Event $event
     * @var \App\Calendar\UpcomingDates $dates
     */
    $isCancelled = $event->status === \App\Enums\EventStatus::Cancelled;
@endphp

@section('content')
    <p class="mt-6"><a href="{{ route('calendar', absolute: false) }}" class="tap-target text-accent underline underline-offset-2">← All events</a></p>

    <h1 class="mt-2 text-2xl font-bold text-ink wrap-anywhere">{{ $event->title }}@if ($isCancelled) <strong class="badge ml-2 inline-block rounded-full border border-cancel px-2 align-middle text-sm font-bold text-cancel">Cancelled</strong>@endif</h1>

    <ul class="dates mt-4 flex flex-col gap-2">
        @foreach ($dates->occurrences as $occurrence)
            @php($timeLabel = \App\Support\LondonFormatter::cardTime($occurrence))
            <li class="flex flex-wrap items-center gap-x-3">
                <time datetime="{{ \App\Support\LondonFormatter::local($occurrence->starts_at)->toIso8601String() }}" class="font-medium text-ink">{{ $timeLabel }}</time>
                @if (\App\Calendar\Export\OccurrenceIcs::isCancelled($occurrence))
                    <strong class="text-cancel">Cancelled</strong>
                @else
                    <a href="{{ \App\Support\PublicUrl::icsPath($occurrence) }}" rel="nofollow" class="tap-target text-accent underline underline-offset-2">Add to calendar (.ics)<span class="sr-only"> for {{ $timeLabel }}</span></a>
                @endif
            </li>
        @endforeach
    </ul>

    <p class="mt-6 whitespace-pre-line text-ink wrap-anywhere">{{ $event->description }}</p>
@endsection
