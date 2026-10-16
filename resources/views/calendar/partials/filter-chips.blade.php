@php
    /** @var \App\Calendar\CalendarFilters $filters */
@endphp
<nav aria-label="Filter events" class="filter-chips">
    <div role="group" aria-labelledby="chips-when">
        <span id="chips-when">When</span>
        <a href="{{ route('calendar', $filters->queryWith(['when' => null])) }}"@if ($filters->when === null) aria-current="true"@endif>All upcoming</a>
        @foreach (\App\Enums\DateFilter::cases() as $case)
            <a href="{{ route('calendar', $filters->queryWith(['when' => $case->value])) }}"@if ($filters->when === $case) aria-current="true"@endif>{{ $case->label() }}</a>
        @endforeach
    </div>
</nav>
