<?php

namespace App\Filament\Resources\EventDrafts\Pages;

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Models\EventDraft;
use App\Review\DraftPublishability;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One draft's review page (REV-01): what blocks publishing, then everything
 * the queue knows about the draft, with the review actions in the header.
 * Read-only: decisions go through ReviewActions to App\Publishing\Actions.
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

    /**
     * What stops Approve, shown only while the draft is pending.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $draft = $this->draft();

        if ($draft->review_status !== ReviewStatus::PendingReview) {
            return [];
        }

        return app(DraftPublishability::class)->blockers($draft);
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
