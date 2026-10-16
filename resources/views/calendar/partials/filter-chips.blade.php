@php
    /**
     * Server-rendered filter chips (CAL-06): plain anchors only, each href
     * keeps the other active filters and drops page. 01-07 owns the look.
     *
     * @var \App\Calendar\CalendarFilters $filters
     * @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories
     */
@endphp
<nav aria-label="Filter events" class="filter-chips">
    <div role="group" aria-labelledby="chips-when">
        <span id="chips-when">When</span>
        <a href="{{ route('calendar', $filters->queryWith(['when' => null]), absolute: false) }}"@if ($filters->when === null) aria-current="true"@endif>All upcoming</a>
        @foreach (\App\Enums\DateFilter::cases() as $case)
            <a href="{{ route('calendar', $filters->queryWith(['when' => $case->value]), absolute: false) }}"@if ($filters->when === $case) aria-current="true"@endif>{{ $case->label() }}</a>
        @endforeach
    </div>
    <div role="group" aria-labelledby="chips-category">
        <span id="chips-category">Category</span>
        <a href="{{ route('calendar', $filters->queryWith(['category' => null]), absolute: false) }}"@if ($filters->category === null) aria-current="true"@endif>All categories</a>
        @foreach ($categories as $category)
            <a href="{{ route('calendar', $filters->queryWith(['category' => $category->slug]), absolute: false) }}"@if ($filters->category?->is($category)) aria-current="true"@endif>{{ $category->name }}</a>
        @endforeach
    </div>
    <div role="group" aria-labelledby="chips-only">
        <span id="chips-only">Show only</span>
        <a href="{{ route('calendar', $filters->queryWith(['free' => $filters->free ? null : '1']), absolute: false) }}"@if ($filters->free) aria-current="true"@endif>Free</a>
        <a href="{{ route('calendar', $filters->queryWith(['family' => $filters->family ? null : '1']), absolute: false) }}"@if ($filters->family) aria-current="true"@endif>Family-friendly</a>
    </div>
</nav>
<style>
    .filter-chips [role="group"] { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-block: 0.5rem; }
    .filter-chips a { display: inline-flex; align-items: center; min-height: 24px; padding: 0.25rem 0.75rem; }
</style>
