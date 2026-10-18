<?php

namespace App\Filament\Resources\EventDrafts\Pages;

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Models\EventDraft;
use App\Review\DraftPublishability;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One draft's review page (REV-01): what blocks publishing, then everything
 * the queue knows about the draft, with the review actions in the header.
 * Read-only: decisions go through ReviewActions to App\Publishing\Actions.
 * It is also the "Approve & next" page (REV-10): each decision opens the
 * next pending draft, and the view's guarded keyboard handler calls
 * approveFromKeyboard() or mounts the other header actions.
 */
class ReviewEventDraft extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventDraftResource::class;

    protected string $view = 'filament.resources.event-drafts.pages.review-event-draft';

    /**
     * The review status the page last showed (see ReviewActions docblock).
     */
    public string $shownStatus = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->syncShownStatus();
    }

    public function draft(): EventDraft
    {
        $record = $this->getRecord();

        assert($record instanceof EventDraft);

        return $record;
    }

    public function shownStatus(): ReviewStatus
    {
        return ReviewStatus::tryFrom($this->shownStatus) ?? $this->draft()->review_status;
    }

    public function syncShownStatus(): void
    {
        $this->shownStatus = $this->draft()->refresh()->review_status->value;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->draft()->title;
    }

    public function getBreadcrumb(): string
    {
        return 'Review';
    }

    public const USE_APPLY = 'Use Apply changes for source updates.';

    public const USE_CONFIRM = 'Use Confirm cancellation for source cancellations.';

    /**
     * What stops Approve, shown only while a new-event draft is pending
     * (source changes have no Approve: Apply / Confirm / Ignore instead).
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $draft = $this->draft();

        if ($draft->review_status !== ReviewStatus::PendingReview || $draft->kind !== DraftKind::New) {
            return [];
        }

        return app(DraftPublishability::class)->blockers($draft);
    }

    /**
     * The keyboard "A" (D-10): a draft that can't be published gets a
     * visible warning listing what blocks it; otherwise the approve
     * confirmation opens and Enter publishes. ApproveDraft re-checks on the
     * server either way. Does nothing once the draft is no longer pending.
     * A source update or cancellation is never approved from the keyboard:
     * the admin is pointed at its own button (03-13).
     */
    public function approveFromKeyboard(): void
    {
        if ($this->shownStatus() !== ReviewStatus::PendingReview) {
            return;
        }

        $kind = $this->draft()->kind;

        if ($kind !== DraftKind::New) {
            Notification::make()->info()->title($kind === DraftKind::Update ? self::USE_APPLY : self::USE_CONFIRM)->send();

            return;
        }

        $blockers = app(DraftPublishability::class)->blockers($this->draft());

        if ($blockers !== []) {
            // Plain-text body: Filament escapes notification strings.
            Notification::make()->warning()->title("Can't approve yet")->body(implode(' ', $blockers))->send();

            return;
        }

        $this->mountAction('approve');
    }

    /**
     * The action the keyboard "R" opens: Reject for a new-event draft,
     * Ignore for a source update or cancellation (which have no Reject).
     */
    public function rejectActionName(): string
    {
        return $this->draft()->kind === DraftKind::New ? 'reject' : 'ignore';
    }

    /**
     * Where the keyboard "E" goes, or null when the draft is no longer
     * pending (decided drafts aren't editable).
     */
    public function editUrl(): ?string
    {
        return $this->shownStatus() === ReviewStatus::PendingReview
            ? EventDraftResource::getUrl('edit', ['record' => $this->draft()])
            : null;
    }

    public function infolist(Schema $schema): Schema
    {
        return EventDraftResource::infolist($schema->record($this->draft()));
    }

    protected function getHeaderActions(): array
    {
        return ReviewActions::forReviewPage();
    }
}
