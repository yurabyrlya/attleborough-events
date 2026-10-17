@php
    /**
     * Dates list (EVT-01, EVT-06). A series shows "Upcoming dates", a one-off
     * "When". Each date sits on its own line, with an indented row under it
     * so the add links clearly belong to that date (UI review fix 3):
     * "Apple / Outlook (.ics)" and "Google Calendar", each with a screen-reader
     * " for {date}" suffix. Cancelled dates say "Cancelled" as text in that
     * row and get no add links.
     *
     * @var \App\Support\EventPageData $page
     */
@endphp
<section class="mt-6" aria-labelledby="dates-heading">
    <h2 id="dates-heading" class="text-lg font-semibold text-ink">{{ $page->isSeries ? 'Upcoming dates' : 'When' }}</h2>
    @if ($page->dates === [])
        <p class="mt-2 text-muted">No upcoming dates.</p>
    @else
        <ul class="dates mt-2 flex flex-col gap-3">
            @foreach ($page->dates as $date)
                <li class="flex flex-col">
                    <time datetime="{{ $date['datetimeIso'] }}" class="font-medium text-ink">{{ $date['timeLabel'] }}</time>
                    <div class="flex flex-wrap items-center gap-x-4 pl-4">
                        @if ($date['isCancelled'])
                            <strong class="text-cancel">Cancelled</strong>
                        @else
                            <a href="{{ $date['icsPath'] }}" rel="nofollow" class="tap-target text-accent underline underline-offset-2">Apple / Outlook (.ics)<span class="sr-only"> for {{ $date['timeLabel'] }}</span></a>
                            <a href="{{ $date['googleUrl'] }}" rel="nofollow noopener" class="tap-target text-accent underline underline-offset-2">Google Calendar<span class="sr-only"> for {{ $date['timeLabel'] }}</span></a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($page->remaining > 0)
            <p class="mt-2 text-muted">{{ $page->remaining === 1 ? '1 more date after these.' : $page->remaining.' more dates after these.' }}</p>
        @endif
    @endif
</section>
