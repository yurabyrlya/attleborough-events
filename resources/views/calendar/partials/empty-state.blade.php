@php
    /**
     * D-05 empty states (British English, warm, plain).
     *
     * @var \App\Calendar\CalendarFilters $filters
     * @var string $context none|today|weekend|week|attributes|end-of-list
     */
@endphp
<div class="empty-state mt-6 rounded-lg bg-surface p-4 text-ink">
    @switch($context)
        @case('today')
            <p>Nothing on today. <a href="{{ route('calendar', $filters->queryWith(['when' => \App\Enums\DateFilter::Weekend->value]), absolute: false) }}" class="tap-target font-semibold text-accent underline decoration-2 underline-offset-4">Why not check the weekend?</a></p>
            @break
        @case('weekend')
            <p>Nothing on this weekend yet. <a href="{{ route('calendar', $filters->queryWith(['when' => null]), absolute: false) }}" class="tap-target font-semibold text-accent underline decoration-2 underline-offset-4">See everything coming up.</a></p>
            @break
        @case('week')
            <p>Nothing else on this week. <a href="{{ route('calendar', $filters->queryWith(['when' => null]), absolute: false) }}" class="tap-target font-semibold text-accent underline decoration-2 underline-offset-4">See everything coming up.</a></p>
            @break
        @case('attributes')
            <p>No events match these filters. Try removing one, or <a href="{{ route('calendar', absolute: false) }}" class="tap-target font-semibold text-accent underline decoration-2 underline-offset-4">see everything coming up.</a></p>
            @break
        @case('end-of-list')
            <p>You've reached the end of the list. <a href="{{ route('calendar', $filters->toQuery(), absolute: false) }}" class="tap-target font-semibold text-accent underline decoration-2 underline-offset-4">Back to the first page</a></p>
            @break
        @default
            <p>Nothing on yet. Check back soon.</p>
    @endswitch
</div>
