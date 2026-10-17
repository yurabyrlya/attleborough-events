@php
    /**
     * Server-rendered filter chips (CAL-06): plain anchors only, each href
     * keeps the other active filters and drops page. Hrefs are root-relative
     * so the Host header never reaches the HTML (T-01-41).
     *
     * The When chips are always visible. Category and Show only sit inside a
     * native <details> ("More filters") that is always closed by default, at
     * every width, so the first event stays above the fold on a phone (UI
     * review fix 2). Active Category / Show only filters appear outside it
     * under "Filtering by" as removable chips, and the summary shows their
     * count. It is plain HTML, so the page still loads no script.
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
    @if ($filters->hasAttributeFilters())
        <div role="group" aria-labelledby="chips-active" class="flex flex-wrap items-center gap-2">
            <span id="chips-active" class="text-sm font-semibold text-muted">Filtering by</span>
            @if ($filters->category !== null)
                @include('calendar.partials.remove-chip', ['href' => route('calendar', $filters->queryWith(['category' => null]), absolute: false), 'label' => $filters->category->name])
            @endif
            @if ($filters->free)
                @include('calendar.partials.remove-chip', ['href' => route('calendar', $filters->queryWith(['free' => null]), absolute: false), 'label' => 'Free'])
            @endif
            @if ($filters->family)
                @include('calendar.partials.remove-chip', ['href' => route('calendar', $filters->queryWith(['family' => null]), absolute: false), 'label' => 'Family-friendly'])
            @endif
        </div>
    @endif
    <details class="group">
        <summary class="tap-target cursor-pointer list-none items-center gap-1 rounded-full border border-line px-4 text-ink hover:bg-accent-soft [&::-webkit-details-marker]:hidden">More filters @if ($filters->attributeFilterCount() > 0)({{ $filters->attributeFilterCount() }})@endif<svg aria-hidden="true" focusable="false" class="size-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg></summary>
        <div class="mt-3 flex flex-col gap-4">
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
        </div>
    </details>
</nav>
