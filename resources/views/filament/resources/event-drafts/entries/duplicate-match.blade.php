{{--
    The suspected duplicate side by side with the draft (D-13, PIPE-05):
    date, time, venue and title for each, why it was flagged, and a link to
    the matched event or draft. Columns stack on narrow screens. Feed text is
    shown with escaped output only (T-03-36).
--}}
@php($match = \App\Filament\Resources\EventDrafts\Schemas\DiscoveredDraftDetails::duplicate($getRecord()))

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($match !== null)
        <div class="grid gap-4">
            <p class="text-sm font-medium">{{ $match['reason'] }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['draft' => 'This draft', 'match' => $match['matchHeading']] as $side => $heading)
                    <section class="rounded-lg border border-gray-200 p-4 dark:border-white/10" aria-label="{{ $heading }}">
                        <h3 class="mb-2 text-sm font-semibold break-words">{{ $heading }}</h3>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            @foreach ($match['rows'] as $row)
                                <dt class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt>
                                <dd class="break-words">{{ $row[$side] }}</dd>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>

            <p class="text-sm">
                <a href="{{ $match['linkUrl'] }}" class="inline-flex min-h-6 items-center text-primary-600 underline dark:text-primary-400">{{ $match['linkLabel'] }}</a>
            </p>

            @if ($getRecord()->review_status === \App\Enums\ReviewStatus::PendingReview)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if (\App\Filament\Resources\EventDrafts\Actions\ReviewActions::canMarkDuplicate($getRecord()))
                        Use "Mark as duplicate" or "Not a duplicate" at the top of the page.
                    @else
                        The other draft is not published yet: approve or reject it first, or use "Not a duplicate" at the top of the page.
                    @endif
                </p>
            @endif
        </div>
    @endif
</x-dynamic-component>
