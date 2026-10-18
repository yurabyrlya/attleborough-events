{{--
    An update draft's changes (REV-06, D-07): one row per changed field,
    what the public calendar shows now, what the source said when it was
    last accepted, and what it says now. Every value is feed text or derived
    from it, so output is escaped only (T-03-36).
--}}
@php($rows = \App\Filament\Resources\EventDrafts\Schemas\DiscoveredDraftDetails::diffRows($getRecord()))

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($rows === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">No field changes recorded.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[32rem] table-auto text-start text-sm">
                <caption class="sr-only">Changes from the source, field by field</caption>
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th scope="col" class="px-3 py-2 text-start font-semibold">Field</th>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">Published now</th>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">Source before</th>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">Source now</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-gray-100 align-top last:border-b-0 dark:border-white/5">
                            <th scope="row" class="px-3 py-2 text-start font-medium">{{ $row['label'] }}</th>
                            <td class="px-3 py-2 break-words">{{ $row['published'] }}</td>
                            <td class="px-3 py-2 break-words">{{ $row['before'] }}</td>
                            <td class="px-3 py-2 font-semibold break-words">{{ $row['now'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-dynamic-component>
