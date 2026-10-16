@php
    /**
     * Server-rendered filter chips (CAL-06): plain anchors only, each href
     * keeps the other active filters and drops page. Hrefs are root-relative
     * so the Host header never reaches the HTML (T-01-41).
     *
     * @var \App\Calendar\CalendarFilters $filters
     * @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories
     */
@endphp
<nav aria-label="Filter events" class="filter-chips mt-4 flex flex-col gap-4">
    <div role="group" aria-labelledby="chips-when" class="flex flex-wrap items-center gap-2">
        <span id="chips-when" class="w-full text-sm font-semibold text-muted">When</span>
        @include('calendar.partials.chip', ['href' => route('calendar', $filters->queryWith(['when' => null]), absolute: false), 'label' => 'All upcoming', 'active' => $filters->when === null])
        @foreach (\App\Enums\DateFilter::cases() as $case)
            @include('calendar.partials.chip', ['href' => route('calendar', $filters->queryWith(['when' => $case->value]), absolute: false), 'label' => $case->label(), 'active' => $filters->when === $case])
        @endforeach
    </div>
    <div role="group" aria-labelledby="chips-category" class="flex flex-wrap items-center gap-2">
        <span id="chips-category" class="w-full text-sm font-semibold text-muted">Category</span>
        @include('calendar.partials.chip', ['href' => route('calendar', $filters->queryWith(['category' => null]), absolute: false), 'label' => 'All categories', 'active' => $filters->category === null])
        @foreach ($categories as $category)
            @include('calendar.partials.chip', ['href' => route('calendar', $filters->queryWith(['category' => $category->slug]), absolute: false), 'label' => $category->name, 'active' => (bool) $filters->category?->is($category)])
        @endforeach
    </div>
    <div role="group" aria-labelledby="chips-only" class="flex flex-wrap items-center gap-2">
        <span id="chips-only" class="w-full text-sm font-semibold text-muted">Show only</span>
        @include('calendar.partials.chip', ['href' => route('calendar', $filters->queryWith(['free' => $filters->free ? null : '1']), absolute: false), 'label' => 'Free', 'active' => $filters->free])
        @include('calendar.partials.chip', ['href' => route('calendar', $filters->queryWith(['family' => $filters->family ? null : '1']), absolute: false), 'label' => 'Family-friendly', 'active' => $filters->family])
    </div>
</nav>
