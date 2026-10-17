<x-filament-panels::page>
    <form wire:submit.prevent="mountAction('save')">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
