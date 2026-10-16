<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarFilters;
use App\Calendar\DateWindow;
use App\Calendar\PublicOccurrenceQuery;
use App\Models\Category;
use App\Models\EventOccurrence;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public, date-grouped and filterable list of upcoming occurrences
 * (CAL-01..07). Plain server-rendered Blade: every filter is a link.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, PublicOccurrenceQuery $query): View
    {
        $now = now()->toImmutable();
        $today = LondonFormatter::local($now)->startOfDay();

        $filters = CalendarFilters::fromRequest($request);
        $occurrences = $query->paginate($filters, $now);

        // Group under the London date of max(starts_at, clamp): the window
        // start when a date filter is active (the Saturday for This weekend),
        // otherwise today. An ongoing occurrence therefore shows under the
        // window's first heading. Grouping never re-sorts the query order.
        $clamp = $filters->when !== null
            ? DateWindow::for($filters->when, $now)->start->startOfDay()
            : $today;

        /** @var array<string, array{date: CarbonImmutable, heading: string, occurrences: list<EventOccurrence>}> $groups */
        $groups = [];

        foreach ($occurrences->items() as $occurrence) {
            /** @var EventOccurrence $occurrence */
            $start = LondonFormatter::local($occurrence->starts_at);
            $date = ($start->lessThan($clamp) ? $clamp : $start)->startOfDay();
            $key = $date->format('Y-m-d');

            $groups[$key] ??= [
                'date' => $date,
                'heading' => LondonFormatter::heading($date, $now),
                'occurrences' => [],
            ];
            $groups[$key]['occurrences'][] = $occurrence;
        }

        return view('calendar.index', [
            'groups' => $groups,
            'occurrences' => $occurrences,
            'filters' => $filters,
            'emptyContext' => $this->emptyContext($filters, $occurrences->currentPage()),
            'categories' => Category::query()->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    /**
     * Which D-05 empty-state message applies when the page has no items:
     * none, today, weekend, week, attributes or end-of-list.
     */
    private function emptyContext(CalendarFilters $filters, int $page): string
    {
        return match (true) {
            $page > 1 => 'end-of-list',
            $filters->hasAttributeFilters() => 'attributes',
            $filters->when !== null => $filters->when->value,
            default => 'none',
        };
    }
}
