@php
    /** @var \App\Models\EventOccurrence $occurrence */
    $event = $occurrence->event;
    $localStart = \App\Support\LondonFormatter::local($occurrence->starts_at);
@endphp
<li>
    <article>
        <h3>{{ $event->title }}@if ($event->status === \App\Enums\EventStatus::Cancelled || $occurrence->status === \App\Enums\OccurrenceStatus::Cancelled) <strong class="badge">Cancelled</strong>@endif</h3>
        <p>
            <time datetime="{{ $localStart->toIso8601String() }}">{{ \App\Support\LondonFormatter::cardTime($occurrence) }}</time>
        </p>
        <p>{{ $event->venue->name }}</p>
    </article>
</li>
