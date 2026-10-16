<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarFilters;
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

        /** @var array<string, array{date: CarbonImmutable, heading: string, occurrences: list<EventOccurrence>}> $groups */
        $groups = [];

        foreach ($occurrences->items() as $occurrence) {
            /** @var EventOccurrence $occurrence */
            $start = LondonFormatter::local($occurrence->starts_at);
            // An ongoing occurrence that started before today is grouped under today.
            $date = ($start->lessThan($today) ? $today : $start)->startOfDay();
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
            'categories' => Category::query()->orderBy('sort')->orderBy('name')->get(),
        ]);
    }
}
