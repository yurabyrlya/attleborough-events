<?php

namespace App\Filament\Resources\EventDrafts\Schemas;

use App\Calendar\ManualRecurrence;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\Events\EventResource;
use App\Models\EventDraft;
use App\Review\VenueMatcher;
use App\Support\LondonFormatter;
use App\Support\SafeLinks;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The review page's draft detail (REV-01): kind, status, reference, quality
 * (band first, then "{n}/100", then the reasons), every extracted field,
 * the recurrence summary and the organiser's repeats text, source and
 * extraction method, category with its reason, parse errors and the
 * duplicate warning.
 *
 * Every entry is escaped text: organiser text is never rendered as HTML
 * (T-02-33), and organiser links go through SafeLinks (T-02-34). A venue
 * suggestion is shown, never applied: approval needs a venue the admin
 * picked or created (D-05, D-21).
 */
class EventDraftInfolist
{
    private const WRAP = ['class' => 'break-words'];

    private const WRAP_TEXT = ['class' => 'whitespace-pre-line break-words'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::summary(),
                self::what(),
                self::when(),
                self::where(),
                self::who(),
                self::source(),
                self::checks(),
                self::review(),
            ]);
    }

    private static function summary(): Section
    {
        return Section::make('Summary')
            ->columns(3)
            ->schema([
                TextEntry::make('kind')->label('Kind'),
                TextEntry::make('review_status')->label('Review status')->badge(),
                TextEntry::make('reference')->label('Reference')->copyable(),
                // Band first, number second (D-22); the band is text, not
                // colour alone.
                TextEntry::make('quality_band')
                    ->label('Quality')
                    ->badge()
                    ->placeholder('Not scored'),
                TextEntry::make('quality_score')
                    ->label('Score')
                    ->formatStateUsing(fn (int $state): string => "{$state}/100")
                    ->placeholder('Not scored'),
                TextEntry::make('created_at')
                    ->label('Received')
                    ->dateTime('D j M Y, g:ia')
                    ->timezone(LondonFormatter::TZ),
                TextEntry::make('quality_reasons')
                    ->label('What to check')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Nothing to fix')
                    ->extraAttributes(self::WRAP)
                    ->columnSpanFull(),
            ]);
    }

    private static function what(): Section
    {
        return Section::make('What')
            ->columns(2)
            ->schema([
                TextEntry::make('title')
                    ->extraAttributes(self::WRAP)
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->placeholder('No description')
                    ->extraAttributes(self::WRAP_TEXT)
                    ->columnSpanFull(),
                TextEntry::make('category.name')
                    ->label('Category')
                    ->placeholder('No category'),
                TextEntry::make('category_reason')
                    ->label('Why this category')
                    ->placeholder('No reason recorded'),
                // A text marker, not colour alone (D-22).
                TextEntry::make('category_check')
                    ->hiddenLabel()
                    ->state('Check category')
                    ->badge()
                    ->color('warning')
                    ->visible(fn (EventDraft $record): bool => $record->category_flagged),
                TextEntry::make('family_friendly')
                    ->label('Family friendly')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextEntry::make('price')
                    ->label('Price')
                    ->state(fn (EventDraft $record): string => trim($record->price_type->getLabel().($record->price_text !== null ? ': '.$record->price_text : ''))),
            ]);
    }

    private static function when(): Section
    {
        return Section::make('When')
            ->columns(2)
            ->schema([
                Callout::make('The organiser says it repeats')
                    // Plain string only: Callout escapes the description.
                    ->description(fn (EventDraft $record): string => "Organiser says it repeats: '".(string) $record->repeats_text."'")
                    ->warning()
                    ->visible(fn (EventDraft $record): bool => filled($record->repeats_text))
                    ->columnSpanFull(),
                TextEntry::make('starts')
                    ->label('Starts')
                    ->state(fn (EventDraft $record): string => LondonFormatter::wallClock($record->start_date, $record->start_time, $record->all_day)),
                TextEntry::make('ends')
                    ->label('Ends')
                    ->state(fn (EventDraft $record): string => self::ends($record)),
                TextEntry::make('recurrence')
                    ->label('Repeats')
                    ->state(fn (EventDraft $record): string => self::recurrence($record)),
                TextEntry::make('repeats_acknowledged')
                    ->label('No repeat rule needed')
                    ->state('Yes')
                    ->visible(fn (EventDraft $record): bool => $record->repeats_acknowledged),
            ]);
    }

    private static function where(): Section
    {
        return Section::make('Where')
            ->schema([
                TextEntry::make('venue.name')
                    ->label('Venue')
                    ->placeholder('Not confirmed'),
                TextEntry::make('venue_typed')
                    ->hiddenLabel()
                    ->state(fn (EventDraft $record): ?string => self::typedVenue($record))
                    ->visible(fn (EventDraft $record): bool => self::typedVenue($record) !== null)
                    ->extraAttributes(self::WRAP),
                // D-05: a suggestion only; never counts as confirmed.
                TextEntry::make('venue_suggestion')
                    ->hiddenLabel()
                    ->state(fn (EventDraft $record): ?string => self::suggestion($record))
                    ->visible(fn (EventDraft $record): bool => self::suggestion($record) !== null),
            ]);
    }

    private static function who(): Section
    {
        return Section::make('Who')
            ->columns(2)
            ->schema([
                TextEntry::make('organiser_name')
                    ->label('Organiser')
                    ->placeholder('Not given')
                    ->extraAttributes(self::WRAP),
                TextEntry::make('organiser_contact')
                    ->label('Contact')
                    ->placeholder('Not given')
                    ->url(fn (EventDraft $record): ?string => SafeLinks::contactHref($record->organiser_contact))
                    ->extraAttributes(self::WRAP),
                ViewEntry::make('organiser_website')
                    ->label('Website')
                    ->view('filament.resources.event-drafts.entries.safe-link'),
                TextEntry::make('contact_public')
                    ->label('Contact shown publicly')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
            ]);
    }

    private static function source(): Section
    {
        return Section::make('Source')
            ->columns(2)
            ->schema([
                TextEntry::make('origin')->label('Source'),
                TextEntry::make('extraction_method')->label('Extraction method'),
                ViewEntry::make('source_url')
                    ->label('Source link')
                    ->view('filament.resources.event-drafts.entries.safe-link'),
                TextEntry::make('source_note')
                    ->label('Source note')
                    ->placeholder('None')
                    ->extraAttributes(self::WRAP_TEXT),
                TextEntry::make('raw_content')
                    ->label('Raw content')
                    ->state(fn (EventDraft $record): string => empty($record->raw_payload)
                        ? ReviewActions::noRawContentMessage($record)
                        : 'Kept: use Raw content at the top of the page'),
            ]);
    }

    private static function checks(): Section
    {
        return Section::make('Checks')
            ->columns(2)
            ->schema([
                TextEntry::make('parse_errors')
                    ->label('Parse errors')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('None')
                    ->extraAttributes(self::WRAP),
                TextEntry::make('duplicate')
                    ->label('Duplicate check')
                    ->state(fn (EventDraft $record): string => $record->duplicateOf !== null
                        ? 'Possible duplicate of '.$record->duplicateOf->title
                        : 'No duplicate found')
                    // Links whatever the event's status: hidden and cancelled
                    // events are still reachable on their admin page.
                    ->url(fn (EventDraft $record): ?string => $record->duplicate_of_event_id !== null
                        ? EventResource::getUrl('edit', ['record' => $record->duplicate_of_event_id])
                        : null)
                    ->extraAttributes(self::WRAP),
            ]);
    }

    private static function review(): Section
    {
        return Section::make('Review')
            ->columns(2)
            ->visible(fn (EventDraft $record): bool => $record->review_status !== ReviewStatus::PendingReview || filled($record->review_notes))
            ->schema([
                TextEntry::make('reviewer.name')
                    ->label('Reviewed by')
                    ->placeholder('Not recorded'),
                TextEntry::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('D j M Y, g:ia')
                    ->timezone(LondonFormatter::TZ)
                    ->placeholder('Not recorded'),
                TextEntry::make('reject_reason')
                    ->label('Reason')
                    ->placeholder('No reason')
                    ->visible(fn (EventDraft $record): bool => $record->review_status === ReviewStatus::Rejected),
                TextEntry::make('review_notes')
                    ->label('Note')
                    ->placeholder('No note')
                    ->extraAttributes(self::WRAP_TEXT),
                TextEntry::make('event.title')
                    ->label('Published event')
                    ->url(fn (EventDraft $record): ?string => $record->event_id !== null
                        ? EventResource::getUrl('edit', ['record' => $record->event_id])
                        : null)
                    ->visible(fn (EventDraft $record): bool => $record->event_id !== null)
                    ->extraAttributes(self::WRAP),
            ]);
    }

    private static function ends(EventDraft $record): string
    {
        if ($record->all_day) {
            return $record->end_date !== null && ! $record->end_date->equalTo($record->start_date)
                ? LondonFormatter::wallClock($record->end_date, null, true)
                : 'Same day';
        }

        if ($record->end_time === null) {
            return 'No end time';
        }

        return LondonFormatter::wallClock($record->end_date ?? $record->start_date, $record->end_time, false);
    }

    /**
     * "One-off", the ManualRecurrence label, or a plain notice when the
     * stored rule does not round-trip (same check as the events table).
     */
    private static function recurrence(EventDraft $record): string
    {
        if ($record->recurrence_rule === null) {
            return 'One-off';
        }

        $start = $record->start_date === null
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', $record->start_date->format('Y-m-d'), $record->timezone);

        if (! $start instanceof CarbonImmutable) {
            return "Repeat rule can't be shown";
        }

        return ManualRecurrence::tryFromStoredRule($record->recurrence_rule, $start, $record->all_day)?->label($start)
            ?? "Repeat rule can't be shown";
    }

    private static function typedVenue(EventDraft $record): ?string
    {
        $parts = array_filter(
            [$record->venue_name, $record->venue_address, $record->venue_postcode, $record->venue_town],
            fn (?string $part): bool => $part !== null && trim($part) !== '',
        );

        return $parts === [] ? null : 'Organiser typed: '.implode(', ', $parts);
    }

    private static function suggestion(EventDraft $record): ?string
    {
        if ($record->venue_id !== null) {
            return null;
        }

        $suggestion = app(VenueMatcher::class)->suggest($record->venue_name, $record->venue_postcode);

        if ($suggestion === null) {
            return null;
        }

        $matchedBy = match ($suggestion->matchedBy) {
            'similar' => 'similar name',
            default => $suggestion->matchedBy,
        };

        return "Suggested: {$suggestion->venueName}, matched by {$matchedBy}";
    }
}
