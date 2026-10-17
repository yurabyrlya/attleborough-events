{{-- What the organiser sent (raw_payload). Escaped output only (T-02-33). --}}
@if ($payload === [])
    <p class="text-sm">{{ $emptyMessage }}</p>
@else
    <dl class="grid gap-3 text-sm">
        @foreach ($payload as $key => $value)
            <div>
                <dt class="font-medium break-words">{{ $key }}</dt>
                <dd class="whitespace-pre-line break-words text-gray-600 dark:text-gray-300">
                    @if (is_bool($value))
                        {{ $value ? 'Yes' : 'No' }}
                    @elseif ($value === null || $value === '')
                        (empty)
                    @elseif (is_scalar($value))
                        {{ $value }}
                    @else
                        {{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
