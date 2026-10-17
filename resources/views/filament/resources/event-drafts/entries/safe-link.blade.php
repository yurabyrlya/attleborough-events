{{-- An organiser-supplied link (T-02-34): http/https only via SafeLinks, shown by host, never followed by search engines. --}}
@php($link = \App\Support\SafeLinks::http($getState()))

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($link !== null)
        <a href="{{ $link['url'] }}" target="_blank" rel="nofollow noopener noreferrer" class="text-sm text-primary-600 underline break-all dark:text-primary-400">{{ $link['host'] }}</a>
    @elseif (filled($getState()))
        <span class="text-sm break-all">{{ $getState() }}</span>
    @else
        <span class="text-sm text-gray-500 dark:text-gray-400">None</span>
    @endif
</x-dynamic-component>
