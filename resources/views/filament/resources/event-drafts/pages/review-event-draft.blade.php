<x-filament-panels::page>
    {{--
        "Approve & next" keyboard shortcuts (REV-10, D-10). One guarded
        handler instead of Filament's key bindings, which fire inside text
        inputs. Keys are ignored while typing (input, textarea, select,
        contenteditable, combobox), while any dialog is open, with
        Ctrl/Cmd/Alt held, on key repeat, and when the toggle is off
        (WCAG 2.1.4; remembered in localStorage "woa-shortcuts").
    --}}
    <div
        class="grid gap-6"
        x-data="{
            enabled: localStorage.getItem('woa-shortcuts') !== 'off',
            editUrl: @js($this->editUrl()),
            rejectAction: @js($this->rejectActionName()),
            save() {
                localStorage.setItem('woa-shortcuts', this.enabled ? 'on' : 'off');
            },
            dialogOpen() {
                return ($wire.mountedActions ?? []).length > 0
                    || document.querySelector('.fi-modal-open, [aria-modal=true]:not([style*=\'display: none\'])') !== null;
            },
            typing(target) {
                return target instanceof Element
                    && target.closest('input, textarea, select, [contenteditable]:not([contenteditable=false]), [role=combobox], [role=textbox]') !== null;
            },
            onKey(event) {
                if (! this.enabled || event.ctrlKey || event.metaKey || event.altKey || event.repeat || event.isComposing) return;
                if (this.typing(event.target) || this.dialogOpen()) return;

                const key = event.key.length === 1 ? event.key.toLowerCase() : event.key;
                const actions = { r: this.rejectAction, s: 'skip', j: 'skip', k: 'previous', '?': 'help' };

                if (key === 'a') {
                    event.preventDefault();
                    $wire.approveFromKeyboard();
                } else if (key === 'e') {
                    if (! this.editUrl) return;
                    event.preventDefault();
                    window.location.assign(this.editUrl);
                } else if (actions[key]) {
                    event.preventDefault();
                    $wire.mountAction(actions[key]);
                }
            },
        }"
        x-on:keydown.window="onKey($event)"
    >
        <label class="inline-flex items-center gap-3 text-sm">
            <input
                id="woa-shortcuts-toggle"
                type="checkbox"
                class="fi-checkbox-input"
                x-model="enabled"
                x-on:change="save()"
            />
            <span>Keyboard shortcuts (A approve · E edit · R {{ $this->rejectActionName() === 'reject' ? 'reject' : 'ignore' }} · S/J next · K previous · ? help)</span>
        </label>

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
