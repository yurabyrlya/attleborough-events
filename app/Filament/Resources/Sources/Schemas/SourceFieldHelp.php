<?php

namespace App\Filament\Resources\Sources\Schemas;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * The "?" helpers on the source form: a one-line tooltip on hover and, on
 * click, a dialog explaining why the field exists, how to set it and a
 * real example. Static text only (escaped in the view).
 */
class SourceFieldHelp
{
    /**
     * @var array<string, array{label: string, summary: string, why: string, how: list<string>, example: string}>
     */
    public const array TOPICS = [
        'name' => [
            'label' => 'Name',
            'summary' => 'The organisation or website the events come from.',
            'why' => 'Residents see it on every event from this source ("Listed by …"), and you see it in the review queue, run log and System health.',
            'how' => [
                'Use the name people know the organisation by, not the website address.',
                'Up to 120 characters. Renaming later is fine: the web address made from it stays the same.',
            ],
            'example' => '"Attleborough Baptist Church" or "Attleborough Town Council".',
        ],
        'short_name' => [
            'label' => 'Short name',
            'summary' => 'A shorter label for event cards ("via …").',
            'why' => 'Event cards have little room, so a long name gets cut off. The full name still shows on the event page.',
            'how' => [
                'Optional. Left empty, the first 40 characters of the name are used.',
                'Keep it recognisable on its own.',
            ],
            'example' => 'Name "Attleborough and District Heritage Group", short name "Heritage Group".',
        ],
        'url' => [
            'label' => 'Feed URL',
            'summary' => 'The calendar file (ICS) address the crawler downloads.',
            'why' => 'This is the only address the crawler requests for this source. Every check reads robots.txt first, waits between requests and refuses private network addresses.',
            'how' => [
                'Find the "Subscribe", "iCal", "Export" or "Add to calendar" link on the organiser\'s events page and copy its address.',
                'It must start with http:// or https://, with no username or password.',
                'Paste it in a browser: it should download a file starting with BEGIN:VCALENDAR, not show a web page.',
                'Save, then use "Test run" to see what it would import before anything is created.',
            ],
            'example' => 'Baptist Church: https://www.attleborough.church/Calendar/Calendar.ics. Town Council (The Events Calendar plugin): https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1',
        ],
        'events_page_url' => [
            'label' => 'Events page URL',
            'summary' => 'The page people visit to see these events.',
            'why' => 'Residents get a "Read more at …" link back to the organiser when an event has no page of its own. Linking back is part of our legal basis for listing the events.',
            'how' => [
                'Optional, but recommended.',
                'Use the human-readable listing page, not the feed address.',
            ],
            'example' => 'https://attleboroughtc.org.uk/events/ for the Town Council feed.',
        ],
        'is_organiser_source' => [
            'label' => "Organiser's own site",
            'summary' => 'Credit this source first when several list the same event.',
            'why' => 'An event can appear in several feeds, for example a church concert listed by the church and by the Town Council. The organiser\'s own site is the most reliable, so it is shown as "Listed by" and the others as "Also listed by".',
            'how' => [
                'Turn on only when the organisation runs the events itself.',
                'Leave off for listing sites and aggregators.',
            ],
            'example' => 'On for Attleborough Baptist Church (its own services and events). Off for a county "What\'s on" site.',
        ],
        'extraction_method' => [
            'label' => 'Extraction method',
            'summary' => 'How events are read from the source.',
            'why' => 'Different websites publish events differently. The method decides which reader turns the download into events.',
            'how' => [
                'Only "ICS feed" is available for now: calendar files, the most reliable kind of source.',
                'Web page and structured data readers arrive in a later phase.',
            ],
            'example' => 'ICS feed for any address ending in .ics or containing ical=1.',
        ],
        'adapter' => [
            'label' => 'Adapter',
            'summary' => 'Site-specific reading rules (not needed for ICS).',
            'why' => 'Some websites need custom rules to find their events. Calendar files follow a standard, so they never need one.',
            'how' => [
                'Nothing to set for ICS feeds; the field is disabled.',
            ],
            'example' => 'Left as "None" for all three calendar feeds.',
        ],
        'parser_config.identity' => [
            'label' => 'Item identity',
            'summary' => 'How the same event is recognised from one check to the next.',
            'why' => 'Each daily check must know whether an event is new, changed or already handled. If the identity changes between checks, every event looks new and you get duplicate drafts.',
            'how' => [
                'Event UID: the default. Use it when the feed gives each event a fixed ID.',
                'Events Calendar post id: for WordPress sites using The Events Calendar, whose IDs change when an event\'s time changes.',
                'Title and start time: for feeds whose IDs change on every download.',
                'Not sure? Open the feed address in a browser twice, a minute apart, and compare the "UID:" lines. If they differ, choose "Title and start time".',
            ],
            'example' => 'Baptist Church: Event UID. Town Council: Events Calendar post id. Attleborough Academy: Title and start time.',
        ],
        'default_timezone' => [
            'label' => 'Default time zone',
            'summary' => 'Used for times the feed gives without a time zone.',
            'why' => 'Most feeds say which time zone their times are in, but some just say "19:30". Without a zone the time could be read an hour out in summer.',
            'how' => [
                'Leave as Europe/London for local sources: it follows British Summer Time automatically.',
                'Only change it for a feed run from another country.',
            ],
            'example' => 'Europe/London, so a floating "19:30" in July is stored as 18:30 UTC and shown as 7:30pm.',
        ],
        'year_policy' => [
            'label' => 'Year policy',
            'summary' => 'What to do when dates leave out the year.',
            'why' => 'Web pages often say "Sat 14 Nov" with no year. Calendar files always include it, so ICS sources need no policy.',
            'how' => [
                'Keep "Dates include the year" for ICS feeds.',
                'More options arrive with the web page reader.',
            ],
            'example' => 'Dates include the year, for all calendar feeds.',
        ],
        'location_mode' => [
            'label' => 'Location',
            'summary' => 'Whether to keep every item or only those near Attleborough.',
            'why' => 'A local organisation\'s events are all relevant. A county-wide listing also has events in Norwich or King\'s Lynn that do not belong on this site.',
            'how' => [
                '"Everything it lists is in Attleborough" for local organisations.',
                '"Keep only items in the Attleborough area" for wider listings. Items are matched against the place names on the Rules screen (Besthorpe, Old Buckenham, Wymondham and so on).',
                'The area filter is applied once wider-area sources are added; the current calendar feeds are all local.',
            ],
            'example' => 'Town Council: everything is local. A Breckland-wide events feed: keep only the Attleborough area.',
        ],
        'default_category_id' => [
            'label' => 'Default category',
            'summary' => 'The category given to drafts from this source.',
            'why' => 'It saves picking a category for every draft when a source only lists one kind of event.',
            'how' => [
                'Choose one when nearly everything from the source is the same kind of event.',
                'Leave as "Work it out from the title" for mixed sources: the category mappings on the Rules screen are used.',
                'You can still change the category on each draft before approving.',
            ],
            'example' => 'Attleborough Academy: School. Town Council: work it out from the title (markets, talks and fairs).',
        ],
        'default_venue_id' => [
            'label' => 'Default venue',
            'summary' => 'The venue for items that name no venue, or name this one.',
            'why' => 'Many organisations hold everything in one building and leave the location out of their feed. Drafts then arrive with the venue already confirmed, so they can be approved straight away.',
            'how' => [
                'Choose a venue only if the organisation almost always uses that building.',
                'An item naming a different venue keeps that venue and is matched or suggested as usual.',
                'Add the venue under Venues first if it is not in the list.',
            ],
            'example' => 'If the Baptist Church holds everything at its own building, choose that venue: "Sunday Service" drafts then arrive with the right venue even when the feed leaves the location out.',
        ],
        'check_frequency' => [
            'label' => 'Check frequency',
            'summary' => 'How often the crawler checks this source.',
            'why' => 'Checking often catches changes and cancellations sooner. Checking less often is kinder to small websites that rarely change.',
            'how' => [
                'Daily suits most sources.',
                'Weekly for sources that update rarely, or ask for long gaps between requests in robots.txt.',
                '"Run now" on the source page checks immediately whatever the frequency.',
            ],
            'example' => 'Daily for the Town Council and Baptist Church. Weekly would suit a school calendar updated once a term.',
        ],
        'check_time' => [
            'label' => 'Check time',
            'summary' => 'The UK time the daily or weekly check starts.',
            'why' => 'Checking early in the morning means new and changed events are waiting in the review queue when you start the day, and the organisers\' websites are quiet.',
            'how' => [
                'UK time, adjusted for summer time automatically.',
                'Each source starts up to 19 minutes after this time so they do not all start at once.',
            ],
            'example' => '05:30, so a source might actually run at 05:42.',
        ],
        'supports_missing_detection' => [
            'label' => 'Missing-item detection',
            'summary' => 'Suggest cancelling an approved event that disappears from the feed.',
            'why' => 'Organisers often delete a cancelled event instead of marking it cancelled. Without this, the event would stay on our site.',
            'how' => [
                'Turn on only for feeds that always list every upcoming event.',
                'An event must be missing from two complete checks in a row. You then get a cancellation draft to confirm; nothing is cancelled automatically.',
                'Leave off for feeds that only show the next few events or change shape often: their events would look missing when they are not.',
            ],
            'example' => 'On for the Baptist Church and Town Council feeds. Off for the Academy: its events are recognised by title and start time, so a moved or renamed event would look missing.',
        ],
        'title_rules' => [
            'label' => 'Title rules',
            'summary' => 'Skip or keep feed items by words in their title.',
            'why' => 'Some feeds mix public events with private bookings or internal meetings. Rules stop those becoming drafts, so you do not have to reject them every day.',
            'how' => [
                '"Skip" drops any item whose title contains the word.',
                '"Keep only" means an item must contain one of these words. If there is any "Keep only" rule, everything else is dropped.',
                'Words match whole words, in any case. No patterns or wildcards.',
                'Use "Test run" to see what each rule would skip before going live.',
            ],
            'example' => 'Town Council: Skip "Private hire" and Skip "Council meeting", so hall bookings and committee meetings never reach the review queue.',
        ],
        'legal_basis' => [
            'label' => 'Legal basis',
            'summary' => 'Why we may list this source\'s events.',
            'why' => 'It records the reason the site is allowed to republish these event details, in case the organiser or anyone else asks.',
            'how' => [
                'Note the robots.txt result, what the site\'s terms say about reuse, and any permission given (with the date).',
                'Keep it factual and short.',
            ],
            'example' => '"Public ICS feed. robots.txt allows it (checked 17 Oct 2026). Terms say nothing about reuse. We list facts only, with a link back to the church\'s calendar."',
        ],
        'notes' => [
            'label' => 'Notes',
            'summary' => 'Anything useful for whoever looks after this source.',
            'why' => 'A place for quirks and contacts so the next person does not have to rediscover them.',
            'how' => [
                'Not shown to the public.',
                'Do not store personal email addresses or phone numbers here.',
            ],
            'example' => '"Feed UIDs change on every download, so identity is title and start time. Contact via the school office form."',
        ],
    ];

    /**
     * The "?" hint action for a form field or section.
     */
    public static function action(string $topic): Action
    {
        $help = self::TOPICS[$topic] ?? throw new InvalidArgumentException("No source form help for [{$topic}].");

        return Action::make('help_'.str_replace('.', '_', $topic))
            ->label("Help: {$help['label']}")
            ->iconButton()
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->tooltip($help['summary'])
            ->modalHeading($help['label'])
            ->modalDescription($help['summary'])
            ->modalContent(view('filament.resources.sources.field-help', ['help' => $help]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('lg');
    }
}
