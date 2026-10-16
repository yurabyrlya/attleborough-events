<?php

namespace App\Http\Controllers;

use App\Calendar\PublicOccurrenceQuery;
use App\Models\EventOccurrence;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * Public, date-grouped list of upcoming occurrences (CAL-01).
 */
class CalendarController extends Controller
{
    public function __invoke(PublicOccurrenceQuery $query): View
    {
        $now = CarbonImmutable::now();
        $today = LondonFormatter::local($now)->startOfDay();

        $occurrences = $query->paginate($now);

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
        ]);
    }
}
