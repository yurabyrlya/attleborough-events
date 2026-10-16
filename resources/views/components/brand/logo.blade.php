{{-- D-07 wordmark: calendar-pin icon + live, escaped site name. No raster logo. --}}
<a href="{{ route('calendar', absolute: false) }}" class="tap-target gap-2 text-xl font-bold text-accent">
    <x-brand.calendar-pin class="size-8 shrink-0" />
    <span>{{ config('site.name') }}</span>
</a>
<p class="text-sm text-muted">{{ config('site.tagline') }}</p>
