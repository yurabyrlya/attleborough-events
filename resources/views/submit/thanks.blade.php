@extends('layouts.app')

@php
    /**
     * Confirmation after a submission (SUB-02, D-02). Reached only by the
     * POST-redirect-GET from /submit with the "submitted" flash; the
     * reference is escaped like every other value.
     *
     * @var string|null $reference
     */
@endphp

@section('title', 'Thanks for sending your event')

@section('content')
    <h1 class="mt-6 text-2xl font-bold text-ink">Thanks for sending your event</h1>

    <div role="status" class="mt-4 flex flex-col gap-3 text-ink">
        <p>Thanks! We'll check the details and add it to the calendar, usually within a few days.</p>
        @if ($reference !== null)
            <p class="font-semibold">Your reference: {{ $reference }}</p>
        @endif
    </div>

    <p class="mt-4 text-ink">We check every event by hand. If it's approved it will appear on the calendar. If you spot a mistake, email <a href="mailto:{{ config('site.contact_email') }}" class="text-accent underline underline-offset-2">{{ config('site.contact_email') }}</a> with your reference.</p>

    <p class="mt-6"><a href="{{ route('calendar', absolute: false) }}" class="tap-target text-accent underline underline-offset-2">Back to what's on</a></p>
@endsection
