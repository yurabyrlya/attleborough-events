@extends('layouts.app')

@php
    /**
     * Interim privacy notice (D-04, D-12, D-17) at the permanent /privacy URL;
     * a fuller notice replaces this text at the same address later. Static:
     * no queries, no script.
     */
    $contact = config('site.contact_email');
@endphp

@section('title', 'Privacy')
@section('description', "How What's On Attleborough uses the details you send when you add an event.")

@section('content')
    <article class="[&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2 [&_h2]:mt-8 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-ink [&_p]:mt-2 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-6 text-ink">
        <h1 class="mt-6 text-2xl font-bold text-ink">Privacy</h1>
        <p>This notice explains what {{ config('site.name') }} does with the details you send when you add an event, and what we show on the calendar.</p>

        <h2>What we collect</h2>
        <p>When you use the "Add your event" form we keep what you type:</p>
        <ul>
            <li>the event details: name, date and time, venue, description, category, price, whether it repeats and any link you add</li>
            <li>your name or the name of your group</li>
            <li>your email address and website, if you give them</li>
            <li>whether you ticked "Show my contact publicly", and when you agreed to this notice</li>
        </ul>

        <h2>Why</h2>
        <p>We use these details to check your event and put it on the calendar, and to contact you if something needs checking. We do this because we have a legitimate interest in running an accurate local events calendar that you asked to be part of.</p>

        <h2>What we show publicly</h2>
        <p>Nothing appears on the site until we've checked it. If we approve your event, the calendar shows the event details, your name or group name, and your website if you gave one.</p>
        <p>Your email address is shown only if you ticked "Show my contact publicly". If you didn't, only we can see it.</p>

        <h2>Spam protection</h2>
        <p>The form uses Cloudflare Turnstile to check that a person, not a bot, is sending it. Turnstile processes technical data about your browser and connection to do this. See <a href="https://www.cloudflare.com/turnstile-privacy-policy/" rel="noopener">Cloudflare's Turnstile privacy policy</a>.</p>
        <p>To stop the form being flooded, we count how many times it is sent from each internet (IP) address. The IP address is held in that count for up to 24 hours and is not stored with your submission.</p>
        <p>The site also sets a session cookie so the form can be sent securely.</p>

        <h2>How long we keep it</h2>
        <ul>
            <li>If we approve your event, your submission is kept as the record behind the published event.</li>
            <li>If we turn it down as spam, it is deleted after 30 days.</li>
            <li>If we turn it down for any other reason, it is deleted after 90 days.</li>
        </ul>

        <h2>Your rights</h2>
        <p>You can ask us for a copy of the details we hold about you, ask us to correct them, or ask us to remove them or your event. Email us with your reference if you have one.</p>
        <p>If you're unhappy with how we've used your details, you can complain to the Information Commissioner's Office (ICO).</p>

        <h2>Contact us</h2>
        <p>Email <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>

        <p class="text-sm text-muted">Last updated 17 October 2026</p>
    </article>
@endsection
