<?php

namespace App\Filament\Resources\EventDrafts\Pages;

use App\Calendar\ManualRecurrence;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use App\Review\ReviewQueue;
use App\Review\VenueMatcher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Edit-and-approve (REV-03). Never writes the model itself: "Save" goes
 * through DraftWriter::update() (the draft stays pending, re-scored), and
 * "Save & approve" runs that same update and ApproveDraft in ONE
 * transaction, so a blocker rolls back the edits too and nothing is
 * published; the Livewire form keeps what the admin typed.
 *
 * When the draft has no venue the picker is pre-filled with VenueMatcher's
 * suggestion; it becomes the draft's venue only when the admin saves (D-05).
 * Approved and rejected drafts redirect to their review page; a save that
 * meets a draft decided in another tab changes nothing and says so.
 */
class EditEventDraft extends EditRecord
{
    protected static string $resource = EventDraftResource::class;

    public const PUBLISH_BLOCKED = "Can't publish yet";

    public const END_OF_QUEUE = "You've reached the end of the queue";

    /**
     * A draft that is no longer pending (approved or rejected, maybe in
     * another tab) is not editable: open its review page instead.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $draft = $this->draft($this->getRecord());

        if ($draft->review_status !== ReviewStatus::PendingReview) {
            $this->alreadyReviewedNotification()->body(null)->send();
            $this->redirect(EventDraftResource::getUrl('review', ['record' => $draft]));
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Edit draft: '.$this->draft($this->getRecord())->title;
    }

    public function getBreadcrumb(): string
    {
        return 'Edit';
    }

    /**
     * Dates as Y-m-d, Repeats/Ends from the stored rule (or the display-only
     * "Custom rule" when the form cannot represent it), and the suggested
     * venue pre-selected when none is confirmed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $draft = $this->draft($this->getRecord());

        $data['start_date'] = $draft->start_date?->format('Y-m-d');
        $data['end_date'] = $draft->end_date?->format('Y-m-d');

        $recurrence = DraftData::storedRecurrence($draft);
        $data = [...$data, ...($recurrence ?? new ManualRecurrence)->toFormState()];

        if ($recurrence === null) {
            $data['repeats'] = EventForm::CUSTOM_RULE_OPTION;
        }

        if ($draft->venue_id === null) {
            $data['venue_id'] = app(VenueMatcher::class)->suggest($draft->venue_name, $draft->venue_postcode)?->venueId;
        }

        return $data;
    }

    /**
     * Save, Save & approve, Cancel.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Save'),
            Action::make('saveAndApprove')
                ->label('Save & approve')
                ->color('success')
                ->action(fn () => $this->saveAndApprove()),
            $this->getCancelFormAction(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved';
    }

    /**
     * "Save": the draft stays pending with the edits stored and re-scored.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Halt when the draft was decided meanwhile
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $draft = $this->draft($record);

        try {
            $saved = app(DraftWriter::class)->update($draft, DraftData::fromReviewForm($data, $draft));
        } catch (InvalidReviewTransition|ModelNotFoundException) {
            $this->alreadyReviewedNotification()->send();

            throw new Halt;
        }

        $this->record = $saved;

        return $saved;
    }

    /**
     * "Save & approve": validate, then save the edits and publish through
     * ApproveDraft in one transaction, then open the next pending draft.
     * A blocker or a draft decided meanwhile rolls everything back.
     */
    public function saveAndApprove(): void
    {
        $this->authorizeAccess();

        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('Only the admin can approve drafts.');
        }

        $draft = $this->draft($this->getRecord());
        $data = $this->form->getState();

        try {
            DB::transaction(fn () => app(ApproveDraft::class)(
                app(DraftWriter::class)->update($draft, DraftData::fromReviewForm($data, $draft)),
                $actor,
            ));
        } catch (DraftNotPublishable $e) {
            // Plain-text body: Filament escapes notification strings.
            Notification::make()->title(self::PUBLISH_BLOCKED)->body(implode(' ', $e->blockers))->warning()->send();

            return;
        } catch (InvalidReviewTransition|ModelNotFoundException) {
            $this->alreadyReviewedNotification()->send();

            return;
        }

        Notification::make()->title('Published')->success()->send();

        $next = app(ReviewQueue::class)->next($draft);

        if ($next === null) {
            Notification::make()->title(self::END_OF_QUEUE)->info()->send();
            $this->redirect(EventDraftResource::getUrl('index'));

            return;
        }

        $this->redirect(EventDraftResource::getUrl('review', ['record' => $next]));
    }

    private function alreadyReviewedNotification(): Notification
    {
        return Notification::make()
            ->title(ReviewActions::ALREADY_REVIEWED)
            ->body('Refresh the page to see its current state.')
            ->danger();
    }

    private function draft(Model $record): EventDraft
    {
        if (! $record instanceof EventDraft) {
            throw new InvalidArgumentException('EditEventDraft expects an EventDraft record.');
        }

        return $record;
    }
}
