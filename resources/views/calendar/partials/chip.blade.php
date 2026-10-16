@php
    /**
     * One filter chip (CAL-06): a plain anchor. The filled style is keyed to
     * aria-current so the look cannot drift from the semantic state, and the
     * active chip also shows a check icon so state never relies on colour.
     *
     * @var string $href
     * @var string $label
     * @var bool $active
     */
@endphp
<a href="{{ $href }}" class="tap-target justify-center gap-1 rounded-full border border-line px-4 text-ink hover:bg-accent-soft aria-[current=true]:border-accent aria-[current=true]:bg-accent aria-[current=true]:font-semibold aria-[current=true]:text-accent-ink"@if ($active) aria-current="true"@endif>@if ($active)<svg aria-hidden="true" focusable="false" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.75 6 6 9-13.5"/></svg>@endif{{ $label }}</a>
