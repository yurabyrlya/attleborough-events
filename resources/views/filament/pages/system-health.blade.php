<x-filament-panels::page>
    <div class="grid gap-6">
        @foreach ($this->checks() as $check)
            <x-filament::section :heading="$check->title">
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$check->state->color()">
                        {{ $check->state->label() }}
                    </x-filament::badge>
                </x-slot>

                <div class="grid gap-1 text-sm">
                    @if ($check->lastSeenAt !== null)
                        <p>Last seen {{ $check->lastSeenLondon() }} ({{ $check->relativeAge() }})</p>
                    @else
                        <p>No heartbeat recorded yet</p>
                    @endif

                    @if ($check->detail !== null)
                        <p>{{ $check->detail }}</p>
                    @endif

                    <p class="text-gray-500 dark:text-gray-400">{{ $check->expectation }}</p>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
