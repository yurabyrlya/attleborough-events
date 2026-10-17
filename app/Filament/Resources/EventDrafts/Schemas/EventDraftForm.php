<?php

namespace App\Filament\Resources\EventDrafts\Schemas;

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Models\EventDraft;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;

/**
 * The review edit form (REV-03): manual entry's six sections (D-07) with
 * the draft differences.
 *
 * - The organiser's "repeats" text is shown above the Repeats controls,
 *   never parsed (D-06); "No repeat rule needed" acknowledges a one-off
 *   despite the note (D-10).
 * - Repeats is read-only for a stored rule the form cannot represent, so
 *   a save cannot rewrite it (Pitfall 11); DraftData::fromReviewForm()
 *   keeps it whatever is submitted.
 * - The venue is optional on save (blockers still stop approval), and the
 *   organiser's typed venue is shown read-only beside the picker. "Create
 *   new venue" opens pre-filled from it; no venue is created without that
 *   click (D-05).
 *
 * Every organiser string reaches the page through escaped Callout and
 * TextEntry output.
 */
class EventDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                EventForm::whatSection(),
                EventForm::whenSection(),
                EventForm::repeatsSection([
                    Callout::make(fn (mixed $record): string => "Organiser says it repeats: '".self::repeatsText($record)."'")
                        ->description("Set the rule below, or tick 'No repeat rule needed' if it is a one-off.")
                        ->info()
                        ->visible(fn (mixed $record): bool => self::repeatsText($record) !== '')
                        ->columnSpanFull(),
                    Toggle::make('repeats_acknowledged')
                        ->label('No repeat rule needed')
                        ->helperText('Tick if this is a one-off despite the note')
                        ->visible(fn (mixed $record): bool => self::repeatsText($record) !== '')
                        ->columnSpanFull(),
                ])->disabled(fn (string $operation, mixed $record): bool => EventForm::hasUneditableRule($operation, $record)),
                EventForm::whereSection(
                    EventForm::venueSelect()
                        ->required(false)
                        // The only way a venue is created from a draft (D-05):
                        // the admin opens this, checks the organiser's text
                        // and submits. Nothing is created before that click.
                        ->createOptionAction(fn (Action $action): Action => $action
                            ->label('Create new venue')
                            ->modalHeading('Create new venue')
                            ->fillForm(fn (Select $component): array => self::newVenueDefaults($component->getRecord()))),
                    [
                        TextEntry::make('typed_venue')
                            ->label('Organiser typed venue')
                            ->state(fn (mixed $record): string => self::typedVenue($record))
                            ->visible(fn (mixed $record): bool => self::typedVenue($record) !== ''),
                    ],
                ),
                EventForm::priceSection(),
                EventForm::whoSection(),
            ]);
    }

    /**
     * The create-venue form pre-filled from the organiser's typed venue;
     * town defaults to Attleborough.
     *
     * @return array{name: ?string, address: ?string, postcode: ?string, town: string}
     */
    private static function newVenueDefaults(mixed $record): array
    {
        if (! $record instanceof EventDraft) {
            return ['name' => null, 'address' => null, 'postcode' => null, 'town' => 'Attleborough'];
        }

        $town = trim((string) $record->venue_town);

        return [
            'name' => $record->venue_name,
            'address' => $record->venue_address,
            'postcode' => $record->venue_postcode,
            'town' => $town !== '' ? $town : 'Attleborough',
        ];
    }

    private static function repeatsText(mixed $record): string
    {
        return $record instanceof EventDraft ? trim((string) $record->repeats_text) : '';
    }

    /**
     * The organiser's free-text venue, e.g. "The Old Barn, 1 Church Street,
     * NR17 2AA".
     */
    private static function typedVenue(mixed $record): string
    {
        if (! $record instanceof EventDraft) {
            return '';
        }

        $parts = array_map(
            fn (?string $part): string => trim((string) $part),
            [$record->venue_name, $record->venue_address, $record->venue_postcode, $record->venue_town],
        );

        return implode(', ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
