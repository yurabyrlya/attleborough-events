@php
    /**
     * CAL-07 card. Renders only OccurrenceCardData fields, escaped. The
     * category icon tile is a decorative image placeholder (D-06); the
     * category name is shown as text. Element order is 01-06's: title (with
     * the "Cancelled" badge) before the time.
     *
     * @var \App\Models\EventOccurrence $occurrence
     */
    $card = \App\Support\OccurrenceCardData::fromOccurrence($occurrence);
@endphp
<li>
    <article class="flex gap-3 rounded-lg border border-line bg-bg p-4">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent">
            <x-category-icon :slug="$card->categorySlug" class="size-7" />
        </div>
        <div class="min-w-0 flex-1 wrap-anywhere [&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2">
            <h3 class="text-lg font-semibold text-ink wrap-anywhere"><a href="{{ $card->eventPath }}">{{ $card->title }}</a>@if ($card->isCancelled) <strong class="badge ml-2 inline-block rounded-full border border-cancel px-2 align-middle text-sm font-bold text-cancel">Cancelled</strong>@endif</h3>
            <p class="when mt-1 font-medium text-ink"><time datetime="{{ $card->datetimeIso }}">{{ $card->timeLabel }}</time></p>
            <p class="venue text-muted">{{ $card->venueName }}</p>
            <p class="category text-muted">{{ $card->categoryName }}</p>
            @if ($card->priceLabel !== null || $card->familyFriendly)
                <div class="mt-2 flex flex-wrap gap-2">
                    @if ($card->priceLabel !== null)
                        <p class="price rounded-full bg-accent-soft px-2 text-sm text-accent">{{ $card->priceLabel }}</p>
                    @endif
                    @if ($card->familyFriendly)
                        <p class="tag rounded-full bg-accent-soft px-2 text-sm text-accent">Family-friendly</p>
                    @endif
                </div>
            @endif
            @if ($card->excerpt !== null)
                <p class="excerpt mt-2 text-ink">{{ $card->excerpt }}</p>
            @endif
            @if ($card->organiserName !== null)
                <p class="organiser mt-2 text-sm text-muted">By {{ $card->organiserName }}</p>
            @endif
            @if ($card->via !== null)
                <p class="via text-sm text-muted">via {{ $card->via }}</p>
            @endif
            @if ($card->sourceUrl !== null)
                <p class="source text-sm text-muted">Source: <a href="{{ $card->sourceUrl }}" rel="nofollow noopener">{{ $card->sourceHost }}</a></p>
            @elseif ($card->contactText !== null)
                <p class="contact text-sm text-muted">Contact: @if ($card->contactHref !== null)<a href="{{ $card->contactHref }}">{{ $card->contactText }}</a>@else{{ $card->contactText }}@endif</p>
            @endif
        </div>
    </article>
</li>
