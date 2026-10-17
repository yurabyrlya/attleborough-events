<x-filament-panels::page>
    <div class="grid gap-6">
        @php($blockers = $this->blockers())

        @if ($blockers !== [])
            <x-filament::section heading="Before you can publish" icon="heroicon-o-exclamation-triangle" icon-color="warning">
                <ul class="list-disc space-y-1 ps-5 text-sm">
                    @foreach ($blockers as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{ $this->infolist }}
    </div>
</x-filament-panels::page>
