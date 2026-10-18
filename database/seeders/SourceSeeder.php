<?php

namespace Database\Seeders;

use App\Enums\ExtractionMethod;
use App\Enums\IdentityStrategy;
use App\Ingestion\Pipeline\TitleRules;
use App\Models\Category;
use App\Models\Source;
use App\Review\VenueMatcher;
use Illuminate\Database\Seeder;

/**
 * The three ready ICS sources from docs/research/event-sources.md (D-05,
 * SRC-01), seeded **inactive** as reference configuration: the admin opens
 * each one in Sources, runs the test preview and clicks "Create drafts" to
 * turn it live (D-01). Owner clarification of "seeders seed reference data
 * only": inactive source records are configuration, not demo events.
 *
 * Create-only: firstOrCreate keyed on slug, never updated, so re-seeding
 * can't revert what the admin changed (name, title rules, activation...).
 * A seeded source the admin deleted comes back on the next seed. No runs,
 * items, drafts, events or users are created.
 *
 * Every value passes the Sources admin form's own rules (SourceForm:
 * lengths, the SsrfGuard URL policy, the option lists). The default venue
 * is set only when a venue with that exact name or alias is already known
 * (VenueMatcher::exact) and is otherwise left empty for the admin.
 *
 * Phase 4 extends this seeder with the verified non-ICS List 1 sources
 * (Heritage Group HTML, Attleborough Primary's embedded JS array, ...) once
 * their extractors exist.
 */
class SourceSeeder extends Seeder
{
    public const string BAPTIST_CHURCH = 'attleborough-baptist-church';

    public const string TOWN_COUNCIL = 'attleborough-town-council';

    public const string ACADEMY = 'attleborough-academy';

    public function __construct(private readonly VenueMatcher $venues) {}

    public function run(): void
    {
        foreach (self::sources() as $slug => $source) {
            $venueName = $source['venue'];
            $categorySlug = $source['category'];
            unset($source['venue'], $source['category']);

            Source::query()->firstOrCreate(['slug' => $slug], [
                ...$source,
                'slug' => $slug,
                'extraction_method' => ExtractionMethod::Ics,
                'default_timezone' => 'Europe/London',
                'location_mode' => 'local',
                'year_policy' => 'none',
                'check_frequency' => 'daily',
                'default_category_id' => $categorySlug !== null
                    ? Category::query()->where('slug', $categorySlug)->value('id')
                    : null,
                'default_venue_id' => $venueName !== null ? $this->venues->exact($venueName, null)?->id : null,
            ]);
        }
    }

    /**
     * Slug => attributes. 'venue' is the exact venue name to look up for the
     * default venue; 'category' a category slug (CategorySeeder runs first).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sources(): array
    {
        return [
            self::BAPTIST_CHURCH => [
                'name' => 'Attleborough Baptist Church',
                'short_name' => 'Baptist Church',
                'url' => 'https://www.attleborough.church/Calendar/Calendar.ics',
                'events_page_url' => 'https://www.attleborough.church/Calendar/Month.aspx',
                'parser_config' => ['identity' => IdentityStrategy::Uid->value],
                'supports_missing_detection' => true,
                'is_organiser_source' => true,
                'title_rules' => [],
                'category' => null,
                'venue' => 'Attleborough Baptist Church',
                'legal_basis' => 'own_feed_published_for_syndication. The church offers this ICS feed itself: the month view\'s "Export > ICAL" control gives this URL "to access your calendar from other applications". '
                    .'robots.txt (checked 2026-10-17) allows /Calendar/ with Crawl-delay: 10, which the crawler honours. '
                    .'The terms page (/User/TermsAndConditions.aspx) is robots-disallowed for us; the owner read it in a browser on 2026-10-17 and found no prohibition of automated use or republishing. Re-check it once a year.',
                'notes' => 'Hubb platform (Endis Ltd). Times are UTC (Z) with no VTIMEZONE; UIDs (E{id}@UK.endis.com) are stable; no RRULE, so each service is its own item (about 13 a month: Sunday Service, Evening Bible School, Warm Welcome, Bible Study & Prayer, monthly Women\'s Creative Club). '
                    .'Most items are worship services: add skip rules in the preview if you want only community events. '
                    .'Fallback feed with the same ids: https://www.attleborough.church/calendar/rss.xml?days=150 (dated RSS, Phase 4). '
                    .'Research: docs/research/event-sources.md (P0).',
            ],
            self::TOWN_COUNCIL => [
                'name' => 'Attleborough Town Council',
                'short_name' => 'Town Council',
                'url' => 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1',
                'events_page_url' => 'https://attleboroughtc.org.uk/events/',
                'parser_config' => ['identity' => IdentityStrategy::TecPostId->value],
                'supports_missing_detection' => true,
                'is_organiser_source' => false,
                'title_rules' => [
                    ['mode' => TitleRules::MODE_SKIP, 'keyword' => 'Private hire'],
                    ['mode' => TitleRules::MODE_SKIP, 'keyword' => 'Council meeting'],
                ],
                'category' => null,
                'venue' => null,
                'legal_basis' => 'factual_extract_only (owner decision D-26). robots.txt (checked 2026-10-17) allows everything except /wp-admin/, with no crawl delay, and every page advertises the iCal feed (rel="alternate" type="text/calendar"). '
                    .'The terms of use (https://attleboroughtc.org.uk/terms-of-use) say nothing about automated access but carry a standard no-republishing clause, so we take facts only (title, date, time, venue) with attribution and a link back, never descriptions beyond a short excerpt, images or organiser contacts. '
                    .'The courtesy email to the clerk (docs/research/town-council-feed-request.md) asks for an explicit yes.',
                'notes' => 'WordPress + The Events Calendar 6.18. VTIMEZONE Europe/London; UIDs are {post id}-{start}-{end}@attleboroughtc.org.uk, stable per occurrence; recurring sessions are expanded into one item per date (no RRULE); the export lists about 30 upcoming items. '
                    .'Covers the Town Hall programme (community groups, WI, cafés, yoga, police drop-ins), council meetings, Remembrance services, ATFC home games and private hires: the skip rules drop "Private hire" and "Council meeting"; research also saw "Private Function" items. '
                    .'The feed names private organisers with emails and phones: these never reach public pages (D-17). '
                    .'Its LiteSpeed bot check is IP-based (a corporate network was challenged, a home network was not), so a datacenter IP may be blocked: ask the clerk to allowlist, never bypass. '
                    .'TEC REST (/wp-json/tribe/events/v1/events) is the Phase 4 adapter. Research: docs/research/event-sources.md (P0).',
            ],
            self::ACADEMY => [
                'name' => 'Attleborough Academy',
                'short_name' => 'Attleborough Academy',
                'url' => 'https://www.attleboroughacademy.org/calendar/events.ics',
                'events_page_url' => 'https://www.attleboroughacademy.org/100/calendar',
                'parser_config' => ['identity' => IdentityStrategy::SummaryStart->value],
                'supports_missing_detection' => false,
                'is_organiser_source' => true,
                'title_rules' => [],
                'category' => 'school',
                'venue' => 'Attleborough Academy',
                'legal_basis' => 'permission_granted: written permission held by the owner (D-24, 2026-10-17; not committed because the repository is public). Without it the terms of use (https://www.attleboroughacademy.org/3/terms-of-use) forbid compiling a database and republishing. '
                    .'robots.txt (checked 2026-10-17) allows /calendar/ with Crawl-delay: 3, which the crawler honours.',
                'notes' => 'Cleverbox reactCMS; the calendar page advertises the feed as webcal://. VTIMEZONE Europe/London; timed items use TZID, all-day and multi-day items use VALUE=DATE with an exclusive end; no RRULE. Content-Type has a doubled ";;". '
                    .'UIDs change on every fetch (row number + request time), so items are matched on title and start time, and missing-item detection is off (an edited title or time would look like a removed item). '
                    .'Most of the ~6 items a month are school-internal (term dates, INSET, mocks, parents\' evenings); public ones include open evenings, presentation evenings and charity days. Consider skip rules in the preview. '
                    .'Research: docs/research/event-sources.md (P1).',
            ],
        ];
    }
}
