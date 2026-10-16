{{-- D-07 calendar-pin icon. Path data mirrors resources/svg/calendar-pin.svg (BrandHeadTest checks parity). Decorative by default. --}}
<svg {{ $attributes->class(['size-6' => ! $attributes->has('class')])->merge(['aria-hidden' => 'true', 'focusable' => 'false']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M5.25 5.25h13.5a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-12a1.5 1.5 0 0 1 1.5-1.5Z"/>
    <path d="M3.75 9.75h16.5"/>
    <path d="M7.5 3v4.5"/>
    <path d="M16.5 3v4.5"/>
    <path d="M12 18.75s-2.25-2.25-2.25-4.5a2.25 2.25 0 1 1 4.5 0c0 2.25-2.25 4.5-2.25 4.5Z"/>
    <path d="M12.375 14.25a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>
</svg>
