@php
    /**
     * Dates list (EVT-01, EVT-06). A series shows "Upcoming dates", a one-off
     * "When". Cancelled dates say so as text and get no add links; every
     * other date offers the .ics download and a Google Calendar link.
     *
     * @var \App\Support\EventPageData $page
     */
@endphp
<section class="mt-6" aria-labelledby="dates-heading">
    <h2 id="dates-heading" class="text-lg font-semibold text-ink">{{ $page->isSeries ? 'Upcoming dates' : 'When' }}</h2>
    @if ($page->dates === [])
        <p class="mt-2 text-muted">No upcoming dates.</p>
    @else
        <ul class="dates mt-2 flex flex-col gap-2">
            @foreach ($page->dates as $date)
                <li class="flex flex-wrap items-center gap-x-3">
                    <time datetime="{{ $date['datetimeIso'] }}" class="font-medium text-ink">{{ $date['timeLabel'] }}</time>
                    @if ($date['isCancelled'])
                        <strong class="text-cancel">Cancelled</strong>
                    @else
                        <a href="{{ $date['icsPath'] }}" rel="nofollow" class="tap-target text-accent underline underline-offset-2">Add to calendar (.ics)<span class="sr-only"> for {{ $date['timeLabel'] }}</span></a>
                        <a href="{{ $date['googleUrl'] }}" rel="nofollow noopener" class="tap-target text-accent underline underline-offset-2">Google Calendar<span class="sr-only"> for {{ $date['timeLabel'] }}</span></a>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($page->remaining > 0)
            <p class="mt-2 text-muted">{{ $page->remaining === 1 ? '1 more date after these.' : $page->remaining.' more dates after these.' }}</p>
        @endif
    @endif
</section>
