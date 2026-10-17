@php
    /**
     * One active-filter chip (UI review fix 2): a plain anchor whose href is
     * the current URL with only this filter removed. The accessible name
     * "Remove filter: Music" contains the visible label (WCAG 2.5.3); the
     * cross icon is decorative. Not a toggle, so no aria-current.
     *
     * @var string $href
     * @var string $label
     */
@endphp
<a href="{{ $href }}" class="tap-target justify-center gap-1 rounded-full border border-accent px-4 text-ink hover:bg-accent-soft"><span class="sr-only">Remove filter: </span>{{ $label }}<svg aria-hidden="true" focusable="false" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18 18 6M6 6l12 12"/></svg></a>
