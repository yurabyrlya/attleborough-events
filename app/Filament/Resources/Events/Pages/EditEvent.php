<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\StatusActions;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * Edits go through the single publishing choke point (ADM-03): this page never
 * writes the model itself, and has no delete action. Status changes go through
 * StatusActions -> ChangeEventStatus.
 *
 * WR-09: an event whose stored recurrence_rule the form cannot represent
 * still opens, read-only, with a persistent warning and no Save action, so
 * the header status actions stay reachable. A forced save is refused here
 * (warning + Halt) and again by UpdatePublishedEvent.
 */
class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * False when the stored rule cannot be edited here. Display only: the
     * save refusal re-checks the record server-side.
     */
    #[Locked]
    public bool $recurrenceEditable = true;

    /**
     * Hydrate Repeats/Ends from the stored recurrence_rule.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $event = $this->event($this->getRecord());
        $this->recurrenceEditable = UpdatePublishedEvent::canEdit($event);

        if (! $this->recurrenceEditable) {
            $this->unsupportedRuleWarning()->persistent()->send();
        }

        return PublishableEventData::fromEvent($event);
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->disabled(fn (): bool => ! $this->recurrenceEditable);
    }

    /**
     * Only Cancel when the stored rule cannot be edited here.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return $this->recurrenceEditable ? parent::getFormActions() : [$this->getCancelFormAction()];
    }

    /**
     * Refuses a forced save before validation (Livewire calls can be forged
     * even when the button is hidden).
     *
     * @throws Halt
     */
    protected function beforeValidate(): void
    {
        $this->refuseUnsupportedRule($this->event($this->getRecord()));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->refuseUnsupportedRule($this->event($record));

        /** @var User $editor */
        $editor = auth()->user();

        return app(UpdatePublishedEvent::class)($this->event($record), PublishableEventData::fromForm($data), $editor);
    }

    /**
     * Status actions only (hide / show / cancel / reinstate); no delete.
     */
    protected function getHeaderActions(): array
    {
        return StatusActions::forEvent();
    }

    /**
     * @throws Halt when the stored rule cannot be edited here
     */
    private function refuseUnsupportedRule(Event $event): void
    {
        if (UpdatePublishedEvent::canEdit($event)) {
            return;
        }

        $this->unsupportedRuleWarning()->send();

        throw new Halt;
    }

    private function unsupportedRuleWarning(): Notification
    {
        return Notification::make()
            ->title(UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED)
            ->warning();
    }

    private function event(Model $record): Event
    {
        if (! $record instanceof Event) {
            throw new InvalidArgumentException('EditEvent expects an Event record.');
        }

        return $record;
    }
}
