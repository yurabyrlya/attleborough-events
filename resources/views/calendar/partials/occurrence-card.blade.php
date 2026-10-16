@php
    /**
     * CAL-07 card. Renders only OccurrenceCardData fields, escaped.
     *
     * @var \App\Models\EventOccurrence $occurrence
     */
    $card = \App\Support\OccurrenceCardData::fromOccurrence($occurrence);
@endphp
<li>
    <article>
        <h3>{{ $card->title }}@if ($card->isCancelled) <strong class="badge">Cancelled</strong>@endif</h3>
        <p class="when"><time datetime="{{ $card->datetimeIso }}">{{ $card->timeLabel }}</time></p>
        <p class="venue">{{ $card->venueName }}</p>
        <p class="category">{{ $card->categoryName }}</p>
        @if ($card->priceLabel !== null)
            <p class="price">{{ $card->priceLabel }}</p>
        @endif
        @if ($card->familyFriendly)
            <p class="tag">Family-friendly</p>
        @endif
        @if ($card->excerpt !== null)
            <p class="excerpt">{{ $card->excerpt }}</p>
        @endif
        @if ($card->organiserName !== null)
            <p class="organiser">By {{ $card->organiserName }}</p>
        @endif
        @if ($card->sourceUrl !== null)
            <p class="source">Source: <a href="{{ $card->sourceUrl }}" rel="nofollow noopener">{{ $card->sourceHost }}</a></p>
        @elseif ($card->contactText !== null)
            <p class="contact">Contact: @if ($card->contactHref !== null)<a href="{{ $card->contactHref }}">{{ $card->contactText }}</a>@else{{ $card->contactText }}@endif</p>
        @endif
    </article>
</li>
